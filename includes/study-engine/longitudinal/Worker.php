<?php
namespace EIPSI\StudyEngine\Longitudinal;
require_once dirname(__DIR__).'/configuration/KeyringResolver.php';
use EIPSI\StudyEngine\Persistence as P;
use EIPSI\StudyEngine\Orchestration\ExecutionOwner;
use EIPSI\StudyEngine\Runtime as R;

/** Dispatch adapter inside the existing S2 queue worker, not another lease owner. */
final class Worker {
    private $db;private $vault;private $caps;private $clock;private $observer;
    public function __construct(P\Database $db,P\SeedVault $vault,Capability $caps,?Clock $clock=null,?P\TransitionObserver $observer=null){$this->db=$db;$this->vault=$vault;$this->caps=$caps;$this->clock=$clock??new Clock();$this->observer=$observer;new Identity($db,$this->clock);}
    public static function configured(): self {
        global $wpdb;$db=new P\Database($wpdb);$c=\EIPSI\StudyEngine\Configuration\KeyringResolver::binary('seed');$keys=$c['keys'];
        return new self($db,new P\SeedVault($keys,$c['current']),Capability::configured($db));
    }
    public static function dispatch($job): array {try{return self::configured()->execute($job);}catch(\Throwable $e){error_log('[EIPSI STUDY EMAIL] job='.(int)$job->id.' result=RETRYABLE');return array('success'=>false,'error'=>'Study Engine delivery failed');}}
    public static function tick(): void {try{require_once dirname(__DIR__).'/privacy.php';global $wpdb;(new \EIPSI\StudyEngine\Privacy\CleanupAdapter(new P\Database($wpdb)))->batch();$worker=self::configured();$worker->advanceBatch();$worker->processBatch();update_option('eipsi_management_worker_success',gmdate('c'),false);}catch(\Throwable $e){error_log('[EIPSI STUDY EMAIL] operation=catchup result=RETRYABLE');}}
    public function processBatch(int $limit=25): array {
        global $wpdb;$limit=max(1,min(100,$limit));$stats=array('processed'=>0,'completed'=>0,'failed'=>0,'retried'=>0,'persistence_failed'=>0);
        if(\EIPSI_Notification_Nudge_Queue_Service::recover_stale_processing($limit)===false)throw new P\IntegrityFailure('QUEUE_RECOVERY_FAILED');
        // Reflect terminal lease/retry outcomes; science and eligible offers are never reconstructed here.
        $this->db->transactional(function()use($wpdb){$this->db->query('UPDATE '.$this->db->table('notification_intents').' i JOIN `'.$wpdb->prefix."survey_nudge_jobs` j ON j.id=i.queue_job_id SET i.state='FAILED_FINAL' WHERE j.status='failed' AND i.state IN ('PENDING','CLAIMED','FAILED_RETRYABLE')");$this->db->query('UPDATE '.$this->db->table('access_capabilities').' c JOIN '.$this->db->table('notification_intents')." i ON i.id=c.intent_id SET c.revoked_at=".$this->db->prepare('%s',$this->clock->mysql())." WHERE i.state='FAILED_FINAL' AND c.revoked_at IS NULL AND c.consumed_at IS NULL");});
        $jobs=$wpdb->get_results($wpdb->prepare('SELECT * FROM `'.$wpdb->prefix."survey_nudge_jobs` WHERE job_type='study_engine_notification' AND status='pending' AND scheduled_at<=%s AND retries<5 ORDER BY scheduled_at,id LIMIT %d",current_time('mysql'),$limit));
        if($jobs===null||$wpdb->last_error)throw new P\IntegrityFailure('QUEUE_READ_FAILED');
        foreach($jobs as $job){$r=\EIPSI_Notification_Nudge_Worker_Service::process_job($job);foreach($stats as $k=>$v)$stats[$k]+=$r[$k];}return $stats;
    }
    public function advanceBatch(int $limit=25): int {
        $rows=$this->db->rows($this->db->prepare('SELECT e.id,s.enrollment_state,s.lifecycle_revision FROM '.$this->db->table('study_executions').' e JOIN '.$this->db->table('public_studies')." s ON s.study_id=e.study_id WHERE e.participant_id IS NOT NULL AND e.finalized_at IS NULL AND e.state IN ('ACTIVE','WAITING') AND s.enrollment_mode='EMAIL_LONGITUDINAL' AND s.enrollment_state='OPEN' ORDER BY e.updated_at,e.id LIMIT %d",max(1,min(100,$limit))));$count=0;
        foreach($rows as $row){$owner=$this->owner();$view=$owner->view($row['id']);if(isset($view['snapshot'])&&$this->catchUp($owner,$row['id'],$view,$row))$count++;}return $count;
    }
    /** Resume must refresh a fenced checkpoint even when its clock already equals now. */
    private function catchUp(ExecutionOwner $owner,string $execution,array $view,?array $study=null): bool {
        if(($view['study_finalized_at']??null)!==null||!in_array($view['snapshot']['execution']['state'],array('ACTIVE','WAITING'),true))return false;
        $at=$this->clock->now();$clock=$view['snapshot']['clock'];if($clock>$at)return false;
        $study=$study??R\PausePolicy::study($this->db,$view['snapshot']['execution']['study_id']);
        if($clock===$at&&(R\PausePolicy::paused($study)||(int)($study['lifecycle_revision']??0)<=1))return false;
        $id='TICK_'.substr(hash('sha256',$execution.'|'.$at.'|'.($study['lifecycle_revision']??0)),0,32);
        // A same-time replay has a newer expected_revision; reuse its durable receipt by skipping it.
        if($clock===$at&&(new P\CommandRepository($this->db))->find($execution,$id))return false;
        $result=$owner->dispatch($execution,array('id'=>$id,'kind'=>'ADVANCE_TIME','expected_revision'=>$view['revision'],'data'=>array('at'=>$at)));
        return isset($result['revision']);
    }
    private function owner(): ExecutionOwner {return new ExecutionOwner($this->db,$this->vault,null,null,function(){return $this->clock->now();});}
    public function execute($job): array {
        $payload=json_decode($job->payload,true);$id=$payload['intent_id']??null;
        if(!is_string($id))return $this->executeOwned($job);
        $execution=$this->db->row($this->db->prepare('SELECT e.study_id FROM '.$this->db->table('study_executions').' e JOIN '.$this->db->table('notification_intents').' i ON i.execution_id=e.id WHERE i.id=%s',$id));
        if(!$execution)return $this->executeOwned($job);
        return \EIPSI\StudyEngine\Runtime\PublicStudyRepository::deliveryLock($this->db,$execution['study_id'],function()use($job){return $this->executeOwned($job);});
    }
    private function executeOwned($job): array {
        global $wpdb;$payload=json_decode($job->payload,true);if(!is_array($payload)||array_keys($payload)!==array('intent_id')||!is_string($payload['intent_id']))return array('success'=>false,'error'=>'Invalid payload JSON');
        // Verify ownership granted by S2, including connection-bound lock, before any capability/send.
        $lock='eipsi_job_'.md5(DB_NAME.':'.$wpdb->prefix.':'.intval($job->id));$claim=$this->db->row($this->db->prepare('SELECT status FROM `'.$wpdb->prefix.'survey_nudge_jobs` WHERE id=%d',$job->id));$connection=$this->db->row('SELECT CONNECTION_ID() AS id')['id'];$owner=$this->db->row($this->db->prepare('SELECT IS_USED_LOCK(%s) AS id',$lock));
        if(!$claim||$claim['status']!=='processing'||(string)($owner['id']??'')!==(string)$connection)return array('success'=>false,'error'=>'Study Engine claim required');
        $intent=$this->intent($payload['intent_id']);if(!$intent||$intent['queue_job_id']!=(string)$job->id)return array('success'=>false,'error'=>'Invalid payload JSON');
        $owner=$this->owner();$view=$owner->view($intent['execution_id']);if(!isset($view['snapshot']))return array('success'=>false,'error'=>'Study Engine unavailable');
        $this->catchUp($owner,$intent['execution_id'],$view);
        $prepared=$owner->admit(function()use($owner,$intent,$job){
            $view=$owner->view($intent['execution_id']);if(!isset($view['snapshot']))throw new P\IntegrityFailure('EXECUTION_LOAD_FAILED');$current=$this->intent($intent['id'],true);
            if(in_array($current['state'],array('SENT','CANCELLED','FAILED_FINAL'),true))return array('skip'=>true);
            $context=$this->eligible($current,$view['snapshot']);
            if(isset($context['defer']))return array('defer'=>true);
            if(isset($context['cancel'])){$this->cancel($current,$context['cancel']);return array('skip'=>true);}
            $this->db->update('notification_intents',array('state'=>'CLAIMED','updated_at'=>$this->clock->mysql()),$this->db->prepare('id=%s',$current['id']));$this->caps->revokeIntent($current['id']);
            $c=$context['config'];$cap=$this->caps->mint($context['participant'],$context['study'],$context['execution'],$current['purpose'],$current['block_execution_id'],$current['id'],$c['capability_hours']);
            $withdrawal=$this->caps->mint($context['participant'],$context['study'],$context['execution'],'WITHDRAWAL_OPTIONS',null,$current['id'],$c['capability_hours']);
            $block=$context['block'];$zone=$context['execution']['timezone'];$d=$context['definition'];$template=InitialScreen::v2($c)&&$current['purpose']==='VERIFY_PARTICIPANT'&&$block!==null?$c['availability_template']:($current['purpose']==='ASSESSMENT_AVAILABLE'?$c['availability_template']:($current['purpose']==='ASSESSMENT_REMINDER'?$c['reminder_template']:$current['purpose']));
            $assessmentName=$block['definition_id']??'';if(InitialScreen::v2($c)&&$block!==null)foreach($d['nodes'] as $node)if($node['id']===$block['definition_id']){$assessmentName=$node['metadata']['label']??$assessmentName;break;}
            $message=(new EmailTemplate())->render($template,array('study_name'=>$d['protocol_metadata']['study_name']??$d['study']['logical_id'],'assessment_name'=>$assessmentName,'available_time'=>EmailTemplate::local($block['available_at']??null,$zone,\EIPSI\StudyEngine\Runtime\DateFormat::read($d)),'due_time'=>EmailTemplate::local($block['due_at']??null,$zone,\EIPSI\StudyEngine\Runtime\DateFormat::read($d)),'direct_link'=>Capability::url($context['study']['public_id'],$cap),'withdrawal_link'=>Capability::url($context['study']['public_id'],$withdrawal)),EmailTemplate::configured($c['initial_screen']['templates']??array()),\EIPSI\I18n\ContentDefaults::language($d));
            return array('to'=>$context['participant']['email'],'message'=>$message);
        });
        if(isset($prepared['skip']))return array('success'=>true,'message'=>'Study Engine intent resolved');if(isset($prepared['defer']))return array('success'=>false,'defer_until'=>get_date_from_gmt($this->clock->later(300)),'error'=>'Study Engine paused');
        if($this->observer)$this->observer->reached('before_delivery_revalidation');
        // Re-read immediately before the external side effect, not only at claim time.
        $allowed=$owner->admit(function()use($owner,$intent){$view=$owner->view($intent['execution_id']);if(!isset($view['snapshot']))throw new P\IntegrityFailure('EXECUTION_LOAD_FAILED');$row=$this->intent($intent['id'],true);if($row['state']!=='CLAIMED')return array('allowed'=>false);$c=$this->eligible($row,$view['snapshot']);if(isset($c['cancel'])){$this->cancel($row,$c['cancel']);return array('allowed'=>false);}return array('allowed'=>!isset($c['defer']),'defer'=>isset($c['defer']));});
        if(!$allowed['allowed']){$this->db->transactional(function()use($intent){$this->caps->revokeIntent($intent['id']);});if(!empty($allowed['defer']))return array('success'=>false,'defer_until'=>get_date_from_gmt($this->clock->later(300)),'error'=>'Study Engine paused');return array('success'=>true,'message'=>'Study Engine intent suppressed');}
        $execution=(new P\StudyExecutionRepository($this->db))->find($intent['execution_id']);
        error_log('[EIPSI STUDY EMAIL] participant='.$execution['participant_id'].' execution='.$execution['id'].' intent='.$intent['id'].' template='.$intent['purpose'].' attempt='.((int)$job->retries+1).' status=ATTEMPT');
        require_once dirname(__DIR__).'/management/Audit.php';\EIPSI\StudyEngine\Management\Audit::mail($intent['id'],'STARTED',array('job_id'=>(int)$job->id,'hash'=>hash('sha256',$prepared['message']['html'])));
        $sent=\EIPSI_Notification_Email_Delivery_Service::send_study_engine($prepared['to'],$prepared['message']['subject'],array('html'=>$prepared['message']['html'],'from_name'=>$prepared['message']['from_name'],'from_email'=>$prepared['message']['from_email']),$intent['id']);
        \EIPSI\StudyEngine\Management\Audit::mail($intent['id'],$sent?'ACCEPTED':'RETRYABLE',array('job_id'=>(int)$job->id));
        if($this->observer)$this->observer->reached('after_delivery_before_sent');
        $this->db->transactional(function()use($intent,$sent){$row=$this->intent($intent['id'],true);if($row['state']==='CLAIMED')$this->db->update('notification_intents',array('state'=>$sent?'SENT':'FAILED_RETRYABLE','updated_at'=>$this->clock->mysql()),$this->db->prepare('id=%s AND state=%s',$intent['id'],'CLAIMED'));if(!$sent)$this->caps->revokeIntent($intent['id']);});
        return $sent?array('success'=>true,'message'=>'Study Engine intent sent'):array('success'=>false,'error'=>'Study Engine delivery failed');
    }
    private function intent(string $id,bool $lock=false): ?array {return $this->db->row($this->db->prepare('SELECT * FROM '.$this->db->table('notification_intents').' WHERE id=%s'.($lock?' FOR UPDATE':''),$id));}
    private function eligible(array $intent,array $s): array {
        if(in_array($intent['purpose'],array('ACCESS','WITHDRAWAL_OPTIONS'),true))return array('cancel'=>'EMAIL_TYPE_REMOVED');
        $execution=(new P\StudyExecutionRepository($this->db))->find($intent['execution_id']);$p=(new Identity($this->db,$this->clock))->participant($execution['participant_id'],true);$study=$this->db->row($this->db->prepare('SELECT * FROM '.$this->db->table('public_studies').' WHERE study_id=%s',$execution['study_id']));
        if($execution['finalized_at']!==null||!$p||!$study||!(bool)$p['active']||$p['privacy_state']!=='ACTIVE'||$execution['state']==='WITHDRAWN')return array('cancel'=>'PARTICIPANT_INACTIVE');if($study['enrollment_state']==='FINALIZED')return array('cancel'=>'STUDY_FINALIZED');
        if(R\PausePolicy::paused($study)){if($intent['purpose']==='ASSESSMENT_AVAILABLE'||($intent['block_execution_id']!==null&&!isset(R\PausePolicy::openBlocks($this->db,$execution,(new P\StudyVersionRepository($this->db))->compiled((new P\StudyVersionRepository($this->db))->find($execution['study_version_id']))->definition(),true)[$intent['block_execution_id']])))return array('defer'=>true);}
        $versions=new P\StudyVersionRepository($this->db);$definition=InitialScreen::effectiveDefinition($this->db,$versions->compiled($versions->find($execution['study_version_id']))->definition());$config=Configuration::read($definition);$block=$intent['block_execution_id']===null?null:($s['instances'][$intent['block_execution_id']]??null);
        $confirmation=EmailConfirmation::read($this->db,$intent['execution_id']);$confirmIntent=$confirmation!==null&&$confirmation['intent_id']===$intent['id'];
        if($confirmIntent){
            if($confirmation['state']!=='PENDING'||$confirmation['previous_email']!==$p['email']||$confirmation['access_revision']!==(int)$p['access_revision'])return array('cancel'=>'EMAIL_CONFIRMATION_SUPERSEDED');
            $p['email']=$confirmation['email'];
        }elseif(!($intent['purpose']==='VERIFY_PARTICIPANT'&&($confirmation['state']??null)!=='PENDING')&&EmailConfirmation::needs($this->db,$config,$s,$definition))return array('defer'=>true);
        $auth=$block===null&&$intent['purpose']==='VERIFY_PARTICIPANT';
        $verification=$block!==null&&$intent['purpose']==='VERIFY_PARTICIPANT';
        if($verification&&$p['verification_state']==='VERIFIED')return array('cancel'=>'ALREADY_VERIFIED');
        if(!$auth){
            if(P\NotificationIntentRepository::logicalCancellation($s,$block))return array('cancel'=>'BLOCK_CANCELLED_BY_CONDITION');
            if(!$verification&&((!InitialScreen::v2($config)&&$config['verification_policy']==='VERIFIED_ONLY'&&$p['verification_state']!=='VERIFIED')))return array('cancel'=>'COMMUNICATION_POLICY');
            if(!in_array($execution['state'],array('ACTIVE','WAITING'),true)||!$block||\EIPSI\StudyEngine\Engine\Simulator::terminal($block['state']))return array('cancel'=>'TARGET_CLOSED');
            if((int)$intent['schedule_revision']!==$block['schedule_revision'])return array('cancel'=>'SCHEDULE_REVISION_CHANGED');
            if(($s['barrier']['target_execution_id']??$block['id'])!==$block['id'])return array('cancel'=>'WAIT_SUPERSEDED');
            if($block['due_at']!==null&&$block['due_at']<=$this->clock->now())return array('cancel'=>'WINDOW_CLOSED');
            if(!$verification&&!$config['email_enabled'])return array('defer'=>true);
            // A live opportunity can be scheduled or dependency-blocked without being closed.
            if($block['available_at']===null||$block['available_at']>$this->clock->now()||!in_array($block['state'],array('AVAILABLE','IN_PROGRESS'),true))return array('defer'=>true);
            $graph=$versions->compiled($versions->find($execution['study_version_id']));
            if(!(new \EIPSI\StudyEngine\Engine\TraversalResolver())->eligible($graph,$block,$s['instances'],$s['random_decisions']))return array('defer'=>true);
            if($intent['purpose']==='ASSESSMENT_AVAILABLE'&&InitialScreen::suppressInitialAvailability($config,$s,$definition,$block))return array('cancel'=>'INITIAL_DIRECT_ACCESS');
            $present=$s['first_access_at']??$s['execution']['first_access_at'];
            foreach(array_reverse($s['events']) as $event)if(($event['type']??null)==='COMMAND_ACCEPTED'&&in_array($event['reason']??null,array('SUBMIT_PAGE','SUBMIT_ASSESSMENT'),true)){$present=max($present??'',$event['simulated_at']);break;}
            if($intent['purpose']==='ASSESSMENT_AVAILABLE'&&$config['immediate_availability_policy']==='SUPPRESS_WHILE_PRESENT'&&$present!==null&&strtotime($this->clock->now())-strtotime($present)<60)return array('cancel'=>'PARTICIPANT_PRESENT_POLICY');
        }
        if($intent['trigger_at']>$this->clock->mysql())return array('defer'=>true);
        return array('participant'=>$p,'study'=>$study,'execution'=>$execution,'definition'=>$definition,'config'=>$config,'block'=>$block);
    }
    private function cancel(array $intent,string $reason): void {$this->db->update('notification_intents',array('state'=>'CANCELLED','cancel_reason'=>$reason,'updated_at'=>$this->clock->mysql()),$this->db->prepare('id=%s',$intent['id']));$this->caps->revokeIntent($intent['id']);}
}
