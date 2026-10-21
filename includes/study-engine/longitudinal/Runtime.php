<?php
namespace EIPSI\StudyEngine\Longitudinal;
use EIPSI\StudyEngine\Persistence as P;
use EIPSI\StudyEngine\Runtime as R;
use EIPSI\StudyEngine\Content\ResponseAdapter;
use EIPSI\StudyEngine\Orchestration\ExecutionOwner;
use EIPSI\StudyEngine\Contracts\Check;
use EIPSI\StudyEngine\Contracts\Canonical;

/** Longitudinal adapter: possession gates access; NextAction owns all scientific routing. */
final class Runtime {
    private $db;private $vault;private $caps;private $clock;private $identity;private $observer;
    public function __construct(P\Database $db,P\SeedVault $vault,Capability $caps,?Clock $clock=null,?P\TransitionObserver $observer=null){$this->db=$db;$this->vault=$vault;$this->caps=$caps;$this->clock=$clock??new Clock();$this->identity=new Identity($db,$this->clock);$this->observer=$observer;}
    private function study(string $public,bool $lock=false): array {$s=(new R\PublicStudyRepository($this->db))->find($public,$lock);if(!$s||$s['enrollment_mode']!=='EMAIL_LONGITUDINAL')throw new R\AccessDenied('STUDY_UNAVAILABLE');if($s['enrollment_state']==='FINALIZED')throw new R\AccessDenied('STUDY_FINALIZED');return $s;}
    private function owner(?array $session=null,?string $token=null,string $public='',array $metadata=array()): ExecutionOwner {
        $observer=$session===null?$this->observer:new SessionGuard($this->identity,$public,$token,$session,$this->observer);
        return new ExecutionOwner($this->db,$this->vault,$observer,new R\LocalResponseSink($metadata),function(){return $this->clock->now();});
    }
    private function definition(string $version): array {$repo=new P\StudyVersionRepository($this->db);$v=$repo->find($version);if(!$v)throw new P\IntegrityFailure('PINNED_VERSION_UNAVAILABLE');$d=$repo->compiled($v)->definition();Configuration::read($d);return $d;}
    public function handle(string $public,?string $token=null,?array $request=null): array {
        $study=$this->study($public);$token=$token??Identity::token();$session=in_array($request['action']??null,array('withdrawal_lookup','withdraw'),true)?null:$this->identity->recover($public,$token);
        if($request!==null){
            if(!isset($request['csrf'],$request['command_id'],$request['action'])||!is_string($request['csrf'])||!hash_equals(Identity::csrf($public,$token),$request['csrf']))throw new R\AccessDenied('CSRF_REJECTED');Check::id($request['command_id'],'command');
            if($session&&$session['kind']==='WITHDRAWAL_OPTIONS'&&in_array($request['action'],array('enroll','resend'),true))throw new R\AccessDenied('INVALID_SESSION_PURPOSE');
            if(in_array($request['action'],array('enroll','resend'),true)){
                Check::fields($request,array('action','command_id','csrf','email'),array('expected_revision','metadata','timezone','membership'));if(!is_string($request['email']))throw new \DomainException('INVALID_EMAIL');
                return $this->enroll($study,$token,$request,$session);
            }
            if(in_array($request['action'],array('withdrawal_lookup','withdraw'),true))return $this->withdrawByEmail($study,$token,$request);
            if(!$session)throw new R\AccessDenied('ACCESS_REQUIRED');
            if($request['action']==='confirm_email'){
                if(!in_array($session['kind'],array('AUTHENTICATED','PROVISIONAL'),true))throw new R\AccessDenied('INVALID_SESSION_PURPOSE');
                Check::fields($request,array('action','command_id','csrf','email','expected_revision'),array('metadata','confirm_email_change'));Check::integer($request['expected_revision'],'revision');
                return $this->confirmEmail($study,$token,$session,$request);
            }
            if($request['action']==='logout'){
                Check::fields($request,array('action','command_id','csrf'),array('expected_revision','execution_id','metadata'));$this->identity->logout($public,$token);return $this->entry($study,Identity::token(),null);
            }
            if($session['kind']==='WITHDRAWAL_OPTIONS')throw new R\AccessDenied('INVALID_SESSION_PURPOSE');

        }
        if(!$session)return $this->entry($study,$token,null);
        if($session['kind']==='WITHDRAWAL_OPTIONS')return $this->shell($study,$token,$session,$session['kind']);
        if(!in_array($session['kind'],array('AUTHENTICATED','PROVISIONAL'),true))throw new R\AccessDenied('ACCESS_REQUIRED');
        if(R\PausePolicy::paused($study)&&!R\PausePolicy::portal($this->db,$study,$session))throw new R\StudyPaused();
        $metadata=$request['metadata']??array();if(!is_array($metadata))throw new \DomainException('INVALID_METADATA');
        $metadata=$this->metadata($metadata);$owner=$this->owner($session,$token,$public,$metadata);$view=$owner->view($session['execution_id']);$this->loaded($view);
        if($view['snapshot']['execution']['first_access_at']===null){
            $r=$owner->dispatch($session['execution_id'],array('id'=>'FIRST_ACCESS_'.$session['execution_id'],'kind'=>'FIRST_ACCESS','expected_revision'=>$view['revision'],'data'=>array('at'=>max($view['snapshot']['clock'],$this->clock->now()))));$this->loaded($r,false);$view=$owner->view($session['execution_id']);$this->loaded($view);
        }
        $definition=$this->definition($session['study_version_id']);if($request===null)$view=$this->current($owner,$session['execution_id'],$view);
        $config=Configuration::read($definition);$participant=$this->identity->participant($session['participant_id']);
        if(EmailConfirmation::needs($this->db,$config,$view['snapshot'],$definition))return $this->confirmationFrame($study,$token,$session,$definition,$view,$participant);
        if($participant['verification_state']!=='VERIFIED'&&InitialScreen::required($config,$view['snapshot'],$definition)&&($view['next_action']['kind']==='OPEN_ASSESSMENT'||(InitialScreen::v2($config)&&!InitialScreen::firstCompleted($view['snapshot'],$definition))))return $this->verification($study,$token,$session,$definition,$view);
        $compiled=(new P\StudyVersionRepository($this->db))->compiled((new P\StudyVersionRepository($this->db))->find($session['study_version_id']));$receipt=null;
        if($request!==null){
            Check::fields($request,array('action','command_id','csrf','expected_revision'),array('execution_id','block_execution_id','responses','metadata','timezone','confirm_timezone'));
            Check::integer($request['expected_revision'],'revision');if(($request['execution_id']??$session['execution_id'])!==$session['execution_id'])throw new R\AccessDenied('ACCESS_BINDING_INVALID');
            $map=array('partial'=>'SAVE_PAGE','next'=>'NEXT_PAGE','back'=>'PREVIOUS_PAGE','submit'=>'SUBMIT_PAGE','refresh'=>'ADVANCE_TIME','timezone'=>'CHANGE_TIMEZONE');if(!isset($map[$request['action']]))throw new \DomainException('UNKNOWN_RUNTIME_ACTION');
            $kind=$map[$request['action']];$data=array('at'=>max($this->clock->now(),$view['snapshot']['clock']));$block=$request['block_execution_id']??null;
            if($kind==='CHANGE_TIMEZONE'){
                if(($request['confirm_timezone']??null)!=='1'||!isset($request['timezone']))throw new \DomainException('TIMEZONE_CONFIRMATION_REQUIRED');Check::timezone($request['timezone'],'timezone');$data['timezone']=$request['timezone'];$data['timezone_source']='PARTICIPANT_LOCAL';
            }elseif($kind!=='ADVANCE_TIME'){
                if(!is_string($block)||!isset($view['snapshot']['instances'][$block])||$compiled->node($view['snapshot']['instances'][$block]['definition_id'])['completion_boundary']!=='ASSESSMENT')throw new R\AccessDenied('INVALID_ASSESSMENT');
                $data['block_execution_id']=$block;if($kind!=='PREVIOUS_PAGE')$data['responses']=(new ResponseAdapter())->decode($definition,$request['responses']??array(),$compiled->descendants($view['snapshot']['instances'][$block]['definition_id']));elseif(!empty($request['responses']))throw new \DomainException('BACK_HAS_NO_RESPONSES');
            }elseif($block!==null||!empty($request['responses']))throw new \DomainException('REFRESH_HAS_NO_TARGET');
            $prior=(new P\CommandRepository($this->db))->find($session['execution_id'],$request['command_id']);
            if(!$prior&&!in_array($kind,array('ADVANCE_TIME','CHANGE_TIMEZONE'),true)&&($view['next_action']['kind']!=='OPEN_ASSESSMENT'||$view['next_action']['payload']['assessment_execution_id']!==$block))throw new R\AccessDenied('INVALID_ASSESSMENT');
            // at is transport-independent receipt data. Preserve timestamp on a lost-response retry.
            unset($data['at']);$receipt=$owner->dispatch($session['execution_id'],array('id'=>$request['command_id'],'kind'=>$kind,'data'=>$data,'expected_revision'=>$request['expected_revision']),array(),null,true);$this->loaded($receipt,false);
            $view=$owner->view($session['execution_id']);$this->loaded($view);
        }else $view=$this->current($owner,$session['execution_id'],$view);
        if(EmailConfirmation::needs($this->db,$config,$view['snapshot'],$definition))return $this->confirmationFrame($study,$token,$session,$definition,$view,$participant);
        if($participant['verification_state']!=='VERIFIED'&&InitialScreen::required($config,$view['snapshot'],$definition)&&($view['next_action']['kind']==='OPEN_ASSESSMENT'||(InitialScreen::v2($config)&&!InitialScreen::firstCompleted($view['snapshot'],$definition))))return $this->verification($study,$token,$session,$definition,$view);
        return array('token'=>$token,'set_cookie'=>false,'access'=>$session,'study'=>array_merge($study,array('allow_restart'=>0)),'version_id'=>$session['study_version_id'],'definition'=>$definition,'view'=>$view,'csrf'=>Identity::csrf($public,$token),'receipt'=>$receipt,'withdrawal_email'=>$participant['email'],'email_verified'=>$participant['verification_state']==='VERIFIED','status_message'=>InitialScreen::v2($config)&&$view['next_action']['kind']!=='OPEN_ASSESSMENT'?($participant['verification_state']==='VERIFIED'?R\PublicMessages::text($definition,'email_verified'):R\PublicMessages::text($definition,'email_future_notices')) :null,'longitudinal'=>true);
    }
    private function current(ExecutionOwner $owner,string $id,array $view): array {
        if($view['snapshot']['clock']<$this->clock->now()&&in_array($view['snapshot']['execution']['state'],array('ACTIVE','WAITING'),true)){
            $r=$owner->dispatch($id,array('id'=>'CLOCK_'.substr(hash('sha256',$id.'|'.$this->clock->now()),0,32),'kind'=>'ADVANCE_TIME','expected_revision'=>$view['revision'],'data'=>array('at'=>$this->clock->now())));$this->loaded($r,false);$view=$owner->view($id);$this->loaded($view);
        }return $view;
    }
    private function loaded(array $value,bool $snapshot=true): void {if(($value['next_action']['kind']??null)==='OPERATIONAL_ERROR'||($snapshot&&!isset($value['snapshot'])))throw new P\IntegrityFailure('EXECUTION_LOAD_FAILED');}
    private function enroll(array $study,string $token,array $request,?array $session): array {
        $email=Identity::email($request['email']);$public=$study['public_id'];$key=hash('sha256',$public.'|'.($study['study_cycle']??1).'|'.$request['command_id'].'|'.$token);$hash=hash('sha256',Canonical::json(array('email'=>$email,'action'=>$request['action'],'timezone'=>$request['timezone']??null,'membership'=>$request['membership']??null)));
        $owner=$this->owner();$result=$owner->admit(function($create)use($study,$public,$token,$request,$session,$email,$key,$hash,$owner){
            // Logical Study lock also serializes admission across a V1 -> V2 activation.
            $study=$this->study($public,true);$receipt=$this->db->row($this->db->prepare('SELECT * FROM '.$this->db->table('enrollment_receipts').' WHERE id=%s',$key));
            if($receipt){if(!hash_equals($receipt['request_hash'],$hash))throw new P\PersistenceConflict('ENROLLMENT_KEY_REUSED');return P\Database::json($receipt['response_json']);}
            $p=$this->identity->byEmail($study['study_id'],$email,true);$new=$p===null;
            if(R\PausePolicy::paused($study)){if($p===null||empty($p['execution_id']))return array('generic'=>true);$e=(new P\StudyExecutionRepository($this->db))->find($p['execution_id']);if(!$e||!R\PausePolicy::continuation($this->db,$e,$this->definition($e['study_version_id'])))return array('generic'=>true);}
            $entryDefinition=$this->definition($study['active_version_id']);$entryConfig=Configuration::read($entryDefinition);
            $membership=$entryConfig['initial_screen']['authentication']['membership_enabled']??false;
            if(isset($request['membership'])&&(!is_string($request['membership'])||!in_array($request['membership'],array('0','1'),true)))throw new \DomainException('INVALID_MEMBERSHIP');
            if($new&&$membership&&($request['membership']??'0')==='1')return array('generic'=>true,'message'=>R\PublicMessages::text($entryDefinition,'email_not_found',array('PERTENENCIA'=>$entryConfig['initial_screen']['authentication']['membership_label']??'Ya participo')));
            if($new&&$study['enrollment_state']!=='OPEN')return array('generic'=>true);
            if($new){
                $p=$this->identity->create($study['study_id'],$email);$definition=$this->definition($study['active_version_id']);$config=Configuration::read($definition);$context=array('created_at'=>$this->clock->now());
                if(isset($request['timezone'])){Check::timezone($request['timezone'],'timezone');$context['timezone']=$request['timezone'];$context['timezone_source']='PARTICIPANT_LOCAL';}else{$context['timezone_source']='STUDY_FALLBACK';}
                $created=$create($study['active_version_id'],array('kind'=>'PARTICIPANT','id'=>$p['id']),'ENROLL_'.$p['id'],$context);if(!isset($created['execution_id']))throw new P\IntegrityFailure('ENROLLMENT_CREATION_FAILED');
                $p['execution_id']=$created['execution_id'];$this->db->update('participants',array('execution_id'=>$p['execution_id']),$this->db->prepare('id=%s',$p['id']));
                $sessionToken=$session===null?$token:hash_hmac('sha256','NEW_PARTICIPANT|'.$request['command_id'],$token);
                $this->identity->issue($p,$study,$p['execution_id'],$study['active_version_id'],$config,$sessionToken);
                $view=$owner->view($p['execution_id']);$this->loaded($view);
                $verificationAnchor=false;foreach($definition['nodes'] as $node)if(($node['schedule']['anchor']??null)==='PARTICIPANT_VERIFIED')$verificationAnchor=true;
                if(!isset($config['initial_screen'])||(!InitialScreen::v2($config)&&$verificationAnchor)||(InitialScreen::v2($config)&&$config['initial_screen']['authentication']['verification']!=='NEVER'&&$view['next_action']['kind']!=='OPEN_ASSESSMENT'))(new NotificationScheduler($this->db))->authentication($p['execution_id'],'VERIFY_PARTICIPANT','VERIFY_INITIAL',$view['snapshot']);
                $result=array('generic'=>false,'execution_id'=>$p['execution_id'],'token_rotated'=>$session!==null);
            }else{
                if($request['action']==='enroll'&&(bool)$p['active']&&$p['privacy_state']==='ACTIVE'&&$p['verification_state']==='VERIFIED'&&$p['execution_id']!==null){
                    $execution=(new P\StudyExecutionRepository($this->db))->find($p['execution_id']);if(!$execution||$execution['finalized_at']!==null)throw new R\AccessDenied('ACCESS_REQUIRED');$definition=$this->definition($execution['study_version_id']);
                    $sessionToken=hash_hmac('sha256','NEW_PARTICIPANT|'.$request['command_id'],$token);
                    $this->identity->issue($p,$study,$p['execution_id'],$execution['study_version_id'],Configuration::read($definition),$sessionToken,true);
                    $result=array('generic'=>false,'execution_id'=>$p['execution_id'],'token_rotated'=>true);
                }else{
                    $result=array('generic'=>true);
                    if($membership&&$request['action']==='enroll'&&($request['membership']??'0')!=='1')$result['message']=R\PublicMessages::text($entryDefinition,'email_registered');
                    if((bool)$p['active']&&$p['privacy_state']==='ACTIVE'&&$p['execution_id']!==null&&$study['enrollment_state']!=='FINALIZED'){
                        $view=$owner->view($p['execution_id']);$this->loaded($view);
                        if($view['snapshot']['execution']['state']!=='WITHDRAWN'){
                            $purpose='VERIFY_PARTICIPANT';
                            $intent=(new NotificationScheduler($this->db))->authentication($p['execution_id'],$purpose,'VERIFY_ENTRY_'.substr($key,0,32),$view['snapshot']);$result['notice_keys']=isset($result['message'])?array('email_registered','email_validation_sent'):array('email_validation_sent');
                            // Resend invalidates previous unconsumed verification credentials, never history/session.
                            if($request['action']==='resend'){
                                $this->db->update('access_capabilities',array('revoked_at'=>$this->clock->mysql()),$this->db->prepare('participant_id=%s AND purpose=%s AND (intent_id IS NULL OR intent_id<>%s) AND consumed_at IS NULL AND revoked_at IS NULL',$p['id'],$purpose,$intent));
                                $this->db->update('notification_intents',array('state'=>'CANCELLED','cancel_reason'=>'RESEND_SUPERSEDED','updated_at'=>$this->clock->mysql()),$this->db->prepare("execution_id=%s AND block_execution_id IS NULL AND purpose=%s AND id<>%s AND state IN ('PENDING','CLAIMED','FAILED_RETRYABLE')",$p['execution_id'],$purpose,$intent));
                            }
                        }
                    }
                }
            }
            $this->db->insert('enrollment_receipts',array('id'=>$key,'public_study_id'=>$public,'request_hash'=>$hash,'participant_id'=>$p['id'],'execution_id'=>$p['execution_id'],'response_json'=>Canonical::json($result),'created_at'=>$this->clock->mysql()));if($this->observer)$this->observer->reached('before_enrollment_commit');return $result;
        });
        if($result['generic']){$frame=$this->entry($study,$token,$result['message']??R\PublicMessages::text($this->definition($study['active_version_id']),'email_validation_requested'));if(isset($result['notice_keys']))$frame['notice_keys']=$result['notice_keys'];return $frame;}
        $sessionToken=($result['token_rotated']??false)?hash_hmac('sha256','NEW_PARTICIPANT|'.$request['command_id'],$token):$token;
        $recovered=$this->identity->recover($public,$sessionToken);if(!$recovered||$recovered['execution_id']!==$result['execution_id'])return $this->entry($study,$token,R\PublicMessages::text($this->definition($study['active_version_id']),'email_validation_requested'));
        $frame=$this->handle($public,$sessionToken,null);$frame['set_cookie']=true;return $frame;
    }
    /** A visit to the study landing page never restores a participant from the browser cookie. */
    public function initialFrame(string $public,?string $token=null): array {
        $existing=is_string($token)&&preg_match('/^[a-f0-9]{64}$/D',$token);$frame=$this->entry($this->study($public),$existing?$token:Identity::token(),null);$frame['set_cookie']=!$existing;return $frame;
    }
    /** Email identifies the withdrawal target; no browser identity or posted participant ID is accepted. */
    private function withdrawByEmail(array $study,string $token,array $request): array {
        require_once dirname(__DIR__).'/privacy.php';
        $lookup=$request['action']==='withdrawal_lookup';
        Check::fields($request,array('action','command_id','csrf','email'),$lookup?array('metadata'):array('mode','expected_revision','confirmation_text','confirm_privacy','metadata'));
        if(!is_string($request['email']))throw new \DomainException('INVALID_EMAIL');$email=Identity::email($request['email']);
        $p=$this->identity->byEmail($study['study_id'],$email);
        if(!$p||$p['execution_id']===null)throw new \DomainException('WITHDRAWAL_EMAIL_NOT_FOUND');
        $execution=(new P\StudyExecutionRepository($this->db))->find($p['execution_id']);
        if(!$execution||$execution['finalized_at']!==null)throw new \DomainException('WITHDRAWAL_EMAIL_NOT_FOUND');
        $outcome=\EIPSI\StudyEngine\Privacy\Policy::outcome($this->db,$p['execution_id']);
        if((!(bool)$p['active']||$p['privacy_state']!=='ACTIVE')&&$outcome===null)throw new \DomainException('WITHDRAWAL_EMAIL_NOT_FOUND');
        $frame=$this->entry($study,$token,null);
        if($lookup){$frame['withdrawal_check']=array('revision'=>(int)$execution['revision']);return $frame;}
        if(!isset($request['mode'],$request['expected_revision']))throw new \DomainException('WITHDRAWAL_MODE');
        Check::integer($request['expected_revision'],'revision');
        if(!in_array($request['mode'],array('RETAIN_FOR_ANALYSIS','DO_NOT_USE'),true))throw new \DomainException('WITHDRAWAL_MODE');
        $withdrawal=Configuration::read($frame['definition'])['initial_screen']['withdrawal']??InitialScreen::defaults()['withdrawal'];
        if(!is_string($request['confirmation_text']??null)||!hash_equals(InitialScreen::withdrawalPhrase($withdrawal,$request['mode']),$request['confirmation_text']))throw new \DomainException('WITHDRAWAL_CONFIRMATION_REQUIRED');
        $access=\EIPSI\StudyEngine\Privacy\AccessContext::email($this->db,$study['public_id'],$email,$this->clock);
        $this->owner()->withdrawExecution($p['execution_id'],$request['mode'],$request['command_id'],$request['expected_revision'],$access);
        $frame['withdrawal']=\EIPSI\StudyEngine\Privacy\Policy::outcome($this->db,$p['execution_id']);return $frame;
    }
    public function redeem(string $public,string $capability,?string $existing=null,?string $expectedPurpose=null): array {
        $study=$this->study($public);$preview=$this->caps->find($public,$capability);$owner=$this->owner();$token=Identity::token();
        $result=$owner->admit(function()use($public,$capability,$existing,$expectedPurpose,$study,$owner,$token,$preview){
            // Same lock order as scientific mutations: execution -> credential -> participant.
            $execution=(new P\StudyExecutionRepository($this->db))->find($preview['execution_id'],true);$cap=$this->caps->find($public,$capability,true);$p=$this->identity->participant($cap['participant_id'],true);
            if($execution&&$p&&$cap['purpose']==='WITHDRAWAL_OPTIONS'&&$cap['consumed_at']!==null&&$p['privacy_state']!=='ACTIVE'){
                $s=$this->identity->recover($public,$existing,true);
                if(!$s||$s['kind']!=='WITHDRAWAL_OPTIONS'||$s['participant_id']!==$p['id']||$s['execution_id']!==$execution['id']||$execution['participant_id']!==$p['id']||$execution['study_id']!==$study['study_id']||$execution['study_version_id']!==$cap['study_version_id'])throw new R\AccessDenied('ACCESS_REQUIRED');
                return array('token'=>$existing,'purpose'=>'WITHDRAWAL_OPTIONS');
            }
            if(!$execution||!$p||!(bool)$p['active']||$p['privacy_state']!=='ACTIVE'||(int)$p['access_revision']!==(int)$cap['access_revision']||$execution['participant_id']!==$p['id']||$p['execution_id']!==$execution['id']||$execution['study_version_id']!==$cap['study_version_id']||$execution['study_id']!==$study['study_id']||($expectedPurpose!==null&&$expectedPurpose!==$cap['purpose']))throw new R\AccessDenied('ACCESS_REQUIRED');
            if($cap['consumed_at']!==null){$session=$this->identity->recover($public,$existing,true);$shell=$cap['purpose']==='WITHDRAWAL_OPTIONS';if(!$session||$session['participant_id']!==$p['id']||($shell&&$session['kind']!==$cap['purpose'])||(!$shell&&!in_array($session['kind'],array('AUTHENTICATED','PROVISIONAL'),true)))throw new R\AccessDenied('ACCESS_REQUIRED');return array('token'=>$existing,'purpose'=>$cap['purpose']);}
            $verifiedNow=false;$this->caps->consume($cap);$p=EmailConfirmation::accept($this->db,$cap,$study,$p,$this->clock);$definition=$this->definition($execution['study_version_id']);$config=Configuration::read($definition);$view=$owner->view($execution['id']);$this->loaded($view);
            if(($cap['purpose']==='VERIFY_PARTICIPANT'||(isset($config['initial_screen'])&&in_array($cap['purpose'],array('ASSESSMENT_AVAILABLE','ASSESSMENT_REMINDER'),true)))&&$p['verification_state']!=='VERIFIED'){
                $r=$owner->dispatch($execution['id'],array('id'=>'VERIFY_'.$cap['id'],'kind'=>'VERIFY_PARTICIPANT','expected_revision'=>$view['revision'],'data'=>array('at'=>max($view['snapshot']['clock'],$this->clock->now()))));$this->loaded($r,false);
                $verifiedNow=true;$p['verification_state']='VERIFIED';$p['verified_at']=$this->clock->mysql();$this->db->update('participants',array('verification_state'=>'VERIFIED','verified_at'=>$p['verified_at'],'updated_at'=>$this->clock->mysql()),$this->db->prepare('id=%s',$p['id']));
                $view=$owner->view($execution['id']);$this->loaded($view);(new NotificationScheduler($this->db))->synchronize($view['snapshot'],(new P\StudyVersionRepository($this->db))->compiled((new P\StudyVersionRepository($this->db))->find($execution['study_version_id'])),array('kind'=>'PARTICIPANT','id'=>$p['id']),$execution['study_version_id']);
            }
            $session=$this->identity->issue($p,$study,$execution['id'],$execution['study_version_id'],$config,$token,true);
            if($cap['purpose']==='WITHDRAWAL_OPTIONS')$this->db->update('participant_sessions',array('kind'=>$cap['purpose']),$this->db->prepare('id=%s',$session['id']));
            if($this->observer)$this->observer->reached('before_capability_commit');return array('token'=>$token,'purpose'=>$cap['purpose'],'verified_now'=>$verifiedNow);
        });
        $frame=$this->handle($public,$result['token'],null);$frame['set_cookie']=true;
        if(isset($frame['definition'])&&InitialScreen::v2(Configuration::read($frame['definition']))&&!isset($frame['shell'])){
            $frame['notice_keys']=!empty($result['verified_now'])?array('email_verified'):array();
            $frame['status_message']=R\PublicMessages::text($frame['definition'],'email_verified').' '.R\PublicMessages::text($frame['definition'],'email_future_notices');
            if($preview['block_execution_id']!==null){$b=$this->db->row($this->db->prepare('SELECT state,due_at FROM '.$this->db->table('block_executions').' WHERE id=%s',$preview['block_execution_id']));if($b&&($b['state']==='EXPIRED'||($b['due_at']!==null&&$b['due_at']<=$this->clock->mysql()))){$frame['notice_keys'][]='assessment_expired';$frame['status_message']=($frame['view']['next_action']['kind']??null)==='OPEN_ASSESSMENT'?R\PublicMessages::text($frame['definition'],'email_expired_next'):R\PublicMessages::text($frame['definition'],'email_expired_wait');}}
        }return $frame;
    }
    private function confirmEmail(array $study,string $token,array $session,array $request): array {
        if(!is_string($request['email']))throw new \DomainException('INVALID_EMAIL');
        $owner=$this->owner($session,$token,$study['public_id']);
        $owner->admit(function()use($study,$token,$session,$request,$owner){
            $row=(new P\StudyExecutionRepository($this->db))->find($session['execution_id'],true);
            $current=$this->identity->recover($study['public_id'],$token,true);if(!$current||$current['execution_id']!==$session['execution_id']||!in_array($current['kind'],array('PROVISIONAL','AUTHENTICATED'),true))throw new R\AccessDenied('ACCESS_REQUIRED');
            $view=$owner->view($session['execution_id']);$this->loaded($view);$definition=$this->definition($session['study_version_id']);$config=Configuration::read($definition);
            if(!InitialScreen::reviewRequired($config)||!InitialScreen::firstCompleted($view['snapshot'],$definition))throw new \DomainException('EMAIL_CONFIRMATION_NOT_REQUIRED');
            if($request['expected_revision']!==$view['revision'])throw new P\PersistenceConflict('EMAIL_CONFIRMATION_REVISION');
            $participant=$this->identity->participant($current['participant_id'],true);if(InitialScreen::v2($config)&&Identity::email($request['email'])!==$participant['email']&&($request['confirm_email_change']??null)!=='1')throw new \DomainException('EMAIL_CHANGE_CONFIRMATION_REQUIRED');EmailConfirmation::request($this->db,$study,$participant,$view['snapshot'],$request['email'],$request['command_id'],$this->clock,InitialScreen::reviewLink($config)?null:$current);return array('accepted'=>true);
        });$frame=$this->handle($study['public_id'],$token);if(isset($frame['definition'])&&!InitialScreen::reviewLink(Configuration::read($frame['definition']))){$frame['notice_keys']=array('review_done');$frame['status_message']=R\PublicMessages::text($frame['definition'],'review_done').' '.R\PublicMessages::text($frame['definition'],'email_future_notices');}return $frame;
    }
    private function confirmationFrame(array $study,string $token,array $session,array $definition,array $view,array $participant): array {
        $state=EmailConfirmation::read($this->db,$session['execution_id']);$view['next_action']=array('kind'=>'EMAIL_CONFIRMATION_REQUIRED');
        return array('token'=>$token,'set_cookie'=>false,'study'=>$study,'access'=>$session,'definition'=>$definition,'csrf'=>Identity::csrf($study['public_id'],$token),'email_confirmation'=>array('email'=>$state['email']??$participant['email'],'pending'=>($state['state']??null)==='PENDING','requires_link'=>InitialScreen::reviewLink(Configuration::read($definition))),'longitudinal'=>true,'view'=>$view);
    }
    private function verification(array $study,string $token,array $session,array $definition,array $view): array {
        return array('token'=>$token,'set_cookie'=>false,'study'=>$study,'access'=>$session,'definition'=>$definition,'csrf'=>Identity::csrf($study['public_id'],$token),'entry'=>true,'verification_required'=>true,'email'=>$this->identity->participant($session['participant_id'])['email'],'message'=>InitialScreen::v2(Configuration::read($definition))?($view['next_action']['kind']==='OPEN_ASSESSMENT'?R\PublicMessages::text($definition,'email_first_link'):R\PublicMessages::text($definition,'email_validation_sent')):'Confirmá tu email con el enlace recibido para comenzar esta evaluación.','longitudinal'=>true,'view'=>array('next_action'=>array('kind'=>'EMAIL_VERIFICATION_REQUIRED'),'revision'=>null));
    }
    private function entry(array $study,string $token,?string $message): array {return array('token'=>$token,'set_cookie'=>true,'study'=>$study,'csrf'=>Identity::csrf($study['public_id'],$token),'entry'=>true,'definition'=>$this->definition($study['active_version_id']),'message'=>$message,'longitudinal'=>true,'view'=>array('next_action'=>array('kind'=>'EMAIL_ENTRY'),'revision'=>null));}
    private function shell(array $study,string $token,array $session,string $purpose): array {
        require_once dirname(__DIR__).'/privacy.php';$e=(new P\StudyExecutionRepository($this->db))->find($session['execution_id']);
        return array('token'=>$token,'set_cookie'=>false,'study'=>$study,'access'=>$session,'csrf'=>Identity::csrf($study['public_id'],$token),'shell'=>$purpose,'definition'=>$this->definition($session['study_version_id']),'withdrawal'=>\EIPSI\StudyEngine\Privacy\Policy::outcome($this->db,$session['execution_id']),'longitudinal'=>true,'view'=>array('next_action'=>array('kind'=>$purpose),'revision'=>(int)$e['revision']));
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
