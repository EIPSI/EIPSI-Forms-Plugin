<?php
namespace EIPSI\StudyEngine\Runtime;
use EIPSI\StudyEngine\Persistence as P;
use EIPSI\StudyEngine\Content\ResponseAdapter;
use EIPSI\StudyEngine\Orchestration\ExecutionOwner;
use EIPSI\StudyEngine\Contracts\Check;

final class AccessDenied extends \DomainException {}
/** Transport-neutral access adapter. Only this authenticated boundary exposes the owner. */
final class CrossSectionalRuntime {
    private $db;
    private $vault;
    private $observer;
    public function __construct(P\Database $db,P\SeedVault $vault,?P\TransitionObserver $observer=null){$this->db=$db;$this->vault=$vault;$this->observer=$observer;}
    public function handle(string $public,?string $token=null,?array $request=null): array {
        $studies=new PublicStudyRepository($this->db);$study=$studies->find($public);if(!$study)throw new AccessDenied('STUDY_UNAVAILABLE');if($study['enrollment_state']==='FINALIZED')throw new AccessDenied('STUDY_FINALIZED');
        $accesses=new AnonymousAccess($this->db);$new=false;
        if($token!==null&&$token!==''){$access=$accesses->recover($public,$token);if(!$access)throw new AccessDenied('ACCESS_REQUIRED');}
        else{PausePolicy::admission($study);if($request!==null)throw new AccessDenied('ACCESS_REQUIRED');$token=AnonymousAccess::token();$access=$accesses->reserve($public,$token);$new=true;}
        if($request!==null){
            Check::fields($request,array('action','command_id','expected_revision','csrf'),array('execution_id','block_execution_id','responses','metadata'));
            if(!is_string($request['csrf'])||!hash_equals(AnonymousAccess::csrf($public,$token),$request['csrf']))throw new AccessDenied('CSRF_REJECTED');
            Check::id($request['command_id'],'command');Check::integer($request['expected_revision'],'revision');
        }
        $metadata=$this->metadata($request['metadata']??array());$owner=new ExecutionOwner($this->db,$this->vault,$this->observer,new LocalResponseSink($metadata));
        if($access['execution_id']===null){PausePolicy::admission($study);
            $created=$owner->create($access['study_version_id'],array('kind'=>'ANONYMOUS','id'=>$access['id']),'ADMISSION_'.$access['id'],array('created_at'=>str_replace(' ','T',$access['created_at']).'Z'));
            if(!isset($created['execution_id']))throw new P\IntegrityFailure('EXECUTION_CREATION_FAILED');$accesses->link($access,$created['execution_id']);$access['execution_id']=$created['execution_id'];
        }
        $owner=new ExecutionOwner($this->db,$this->vault,new AccessContext($this->db,$access,$token,$this->observer),new LocalResponseSink($metadata));
        $execution=(new P\StudyExecutionRepository($this->db))->find($access['execution_id']);
        if(!$execution||$execution['anonymous_identity_id']!==$access['id']||$execution['study_version_id']!==$access['study_version_id']||$execution['study_id']!==$study['study_id'])throw new AccessDenied('ACCESS_BINDING_INVALID');
        $v=(new P\StudyVersionRepository($this->db))->find($access['study_version_id']);if(!$v)throw new P\IntegrityFailure('VERSION_UNAVAILABLE');
        $compiled=(new P\StudyVersionRepository($this->db))->compiled($v);$definition=$compiled->definition();if(PausePolicy::paused($study)&&!PausePolicy::portal($this->db,$study,$access))throw new StudyPaused();
        if(($definition['protocol_metadata']['content_adapter']??null)!=='P1E_V1')throw new P\IntegrityFailure('CONTENT_ADAPTER_REQUIRED');
        $view=$owner->view($access['execution_id']);if(!isset($view['snapshot']))throw new P\IntegrityFailure('EXECUTION_LOAD_FAILED');
        $receipt=null;
        if($request!==null){
            if(($request['execution_id']??$access['execution_id'])!==$access['execution_id'])throw new AccessDenied('ACCESS_BINDING_INVALID');
            $map=array('partial'=>'SAVE_PAGE','next'=>'NEXT_PAGE','back'=>'PREVIOUS_PAGE','submit'=>'SUBMIT_PAGE','refresh'=>'ADVANCE_TIME');
            if(!isset($map[$request['action']]))throw new \DomainException('UNKNOWN_RUNTIME_ACTION');
            $kind=$map[$request['action']];$block=$request['block_execution_id']??null;
            $data=array();
            if($kind!=='ADVANCE_TIME'){
                if(!is_string($block)||!isset($view['snapshot']['instances'][$block])||$compiled->node($view['snapshot']['instances'][$block]['definition_id'])['completion_boundary']!=='ASSESSMENT')throw new AccessDenied('INVALID_ASSESSMENT');
                $offered=$compiled->descendants($view['snapshot']['instances'][$block]['definition_id']);
                $data=array('block_execution_id'=>$block);
                if($kind!=='PREVIOUS_PAGE')$data['responses']=(new ResponseAdapter())->decode($definition,$request['responses']??array(),$offered);
                elseif(!empty($request['responses']))throw new \DomainException('BACK_HAS_NO_RESPONSES');
            }elseif(!empty($request['responses'])||$block!==null)throw new \DomainException('REFRESH_HAS_NO_TARGET');
            $command=array('id'=>$request['command_id'],'kind'=>$kind,'expected_revision'=>$request['expected_revision'],'data'=>$data);
            $prior=(new P\CommandRepository($this->db))->find($access['execution_id'],$command['id']);
            if(!$prior&&$kind!=='ADVANCE_TIME'&&($view['next_action']['kind']!=='OPEN_ASSESSMENT'||$view['next_action']['payload']['assessment_execution_id']!==$block))throw new AccessDenied('INVALID_ASSESSMENT');
            // Owner validates current page, eligible coverage, state/window and expected revision under lock.
            $receipt=$owner->dispatch($access['execution_id'],$command,array(),null,true);
            if(($receipt['next_action']['kind']??null)==='OPERATIONAL_ERROR')throw new P\IntegrityFailure('TRANSITION_FAILED');
            $view=$owner->view($access['execution_id']);if(!isset($view['snapshot']))throw new P\IntegrityFailure('EXECUTION_LOAD_FAILED');
        }
        return array('token'=>$token,'set_cookie'=>$new,'access'=>$access,'study'=>$study,'version_id'=>$access['study_version_id'],'definition'=>$definition,'view'=>$view,'csrf'=>AnonymousAccess::csrf($public,$token),'receipt'=>$receipt);
    }
    private function metadata(array $input): array {
        $out=array();foreach(array('device','browser','os','fingerprint') as $key)if(isset($input[$key])&&is_string($input[$key]))$out[$key]=substr(sanitize_text_field($input[$key]),0,$key==='fingerprint'?255:100);
        foreach(array('screen_width','screen_height') as $key)if(isset($input[$key])&&filter_var($input[$key],FILTER_VALIDATE_INT)!==false)$out[$key]=max(0,min(20000,(int)$input[$key]));
        $ua=substr($_SERVER['HTTP_USER_AGENT']??'',0,512);$out['user_agent']=$ua;
        $out['device']=$out['device']??(preg_match('/Mobile|Android|iPhone/i',$ua)?'mobile':'desktop');
        $out['browser']=$out['browser']??(preg_match('/Firefox/i',$ua)?'Firefox':(preg_match('/Chrome/i',$ua)?'Chrome':(preg_match('/Safari/i',$ua)?'Safari':'unknown')));
        $out['os']=$out['os']??(preg_match('/Windows/i',$ua)?'Windows':(preg_match('/Android/i',$ua)?'Android':(preg_match('/Linux/i',$ua)?'Linux':(preg_match('/Mac|iPhone/i',$ua)?'Apple':'unknown'))));return $out;
    }
}
