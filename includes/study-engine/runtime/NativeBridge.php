<?php
namespace EIPSI\StudyEngine\Runtime;
require_once dirname(__DIR__).'/configuration/KeyringResolver.php';
use EIPSI\StudyEngine\Persistence as P;
use EIPSI\AssessmentEditor\Runtime as N;
require_once dirname(__DIR__,2).'/assessment-editor/runtime/Owner.php';

/** The Study owns opportunity timing; Native Owner owns questions, responses and recovery. */
final class NativeBridge {
    public static function offer(array $frame): ?array {
        if(($frame['view']['next_action']['kind']??null)!=='OPEN_ASSESSMENT')return null;
        $id=$frame['view']['next_action']['payload']['assessment_execution_id'];$instance=$frame['view']['snapshot']['instances'][$id];$node=null;
        foreach($frame['definition']['nodes'] as $n)if($n['id']===$instance['definition_id'])$node=$n;
        $version=$node['metadata']['native_version_id']??null;if($version===null)return null;
        $execution=$frame['view']['snapshot']['execution']['id'];global $wpdb;$db=new P\Database($wpdb);$parent=(new P\StudyExecutionRepository($db))->find($execution);
        $binding=array('kind'=>$parent['participant_id']===null?'STUDY_ANONYMOUS':'STUDY_SESSION','public_id'=>$frame['study']['public_id'],'execution_id'=>$execution,'study_version_id'=>$parent['study_version_id'],'participant_id'=>$parent['participant_id']??$parent['anonymous_identity_id'],'block_execution_id'=>$id);
        return (new N\Owner())->studyAssignment($version,$binding);
    }
    /** Reuse the assigned assessment's own final screen, without a Study completion screen. */
    public static function completedOffer(array $frame): ?array {
        if(!isset($frame['access']['execution_id']))return null;
        if(!array_filter($frame['definition']['nodes'],static function($n){return isset($n['metadata']['native_version_id']);}))return null;
        $store=new N\Store();
        $row=$store->db->row($store->prepare("SELECT id FROM ".$store->table('executions')." WHERE JSON_UNQUOTE(JSON_EXTRACT(binding_json,'$.execution_id'))=%s AND JSON_UNQUOTE(JSON_EXTRACT(binding_json,'$.kind'))='STUDY_ANONYMOUS' AND state IN ('COMPLETED','LOGICAL_EXIT') ORDER BY created_at DESC,id DESC LIMIT 1",$frame['access']['execution_id']));
        return $row?array('url'=>add_query_arg('eipsi_native',$row['id'],home_url('/'))):null;
    }
    /** A correction may return to this completed assessment, never another participant's content. */
    public static function completionFrame(array $frame,string $id): array {
        if(empty($frame['longitudinal'])||!isset($frame['access']))return $frame;
        \EIPSI\StudyEngine\Contracts\Check::id($id,'native_completion');$owner=new N\Owner();$row=$owner->store->find('executions',$id);$binding=$row?json_decode($row['binding_json'],true):array();
        if(($binding['execution_id']??null)!==$frame['access']['execution_id']||($binding['participant_id']??null)!==$frame['access']['participant_id']||($binding['public_id']??null)!==$frame['study']['public_id']||($row['state']??null)!=='COMPLETED')return $frame;
        $state=\EIPSI\StudyEngine\Longitudinal\EmailConfirmation::read($owner->store->db,$frame['access']['execution_id']);
        if(isset($frame['email_confirmation'])&&($state['state']??null)!=='CONFIRMED'){$frame['completion_native_id']=$id;return $frame;}
        if(!in_array($state['state']??null,array('PENDING','CONFIRMED'),true))return $frame;
        $native=N\EffectiveAssessmentProjection::frame($owner->view($id));$c=$native['definition']['flow']['completion'];$frame['assessment_completion_html']=($c['title']!==''?'<h2>'.esc_html($c['title']).'</h2>':'').\EIPSI\StudyEngine\Longitudinal\InitialScreen::html($c['message_html']).($c['image_url']!==''?'<img src="'.esc_url($c['image_url']).'" alt="">':'');return $frame;
    }
    public static function progress(string $native): void {self::complete($native,true);}
    public static function complete(string $native,bool $startOnly=false): void {
        $store=new N\Store();$row=$store->find('executions',$native);if(!$row||($startOnly?($row['state']!=='ACTIVE'||$row['partial_json']===null):$row['state']!=='COMPLETED'))return;
        $binding=json_decode($row['binding_json'],true);if(!isset($binding['block_execution_id']))return;
        $config=\EIPSI\StudyEngine\Configuration\KeyringResolver::binary('seed',true);$keys=$config['keys'];
        $guard=new class($binding) extends P\TransitionObserver {private $binding;public function __construct(array $binding){$this->binding=$binding;}public function reached(string $point): void {if(in_array($point,array('read_authorization','before_receipt','before_commit'),true))N\Authorization::check(new N\Store(),$this->binding);}};
        $owner=new \EIPSI\StudyEngine\Orchestration\ExecutionOwner($store->db,new P\SeedVault($keys,$config['current']),$guard);
        $view=$owner->view($binding['execution_id']);$block=$view['snapshot']['instances'][$binding['block_execution_id']]??null;if(!$block||$block['state']==='COMPLETED'||($startOnly&&$block['state']==='IN_PROGRESS'))return;
        if(!in_array($block['state'],array('AVAILABLE','IN_PROGRESS'),true))throw new \RuntimeException('Study opportunity closed',409);
        $result=$owner->dispatch($binding['execution_id'],array('id'=>($startOnly?'NATIVE_START_':'NATIVE_COMPLETE_').$native,'kind'=>$startOnly?'OPEN_ASSESSMENT':'SUBMIT_ASSESSMENT','expected_revision'=>$view['revision'],'data'=>array('block_execution_id'=>$binding['block_execution_id'],'responses'=>array(),'at'=>max($view['snapshot']['clock'],gmdate('Y-m-d\TH:i:s\Z',(int)($startOnly?$row['partial_started_at']:$row['completed_at']))))));
        if(!isset($result['revision']))throw new P\IntegrityFailure('STUDY_COMPLETION_RETRY_REQUIRED');
    }
}
