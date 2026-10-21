<?php
namespace EIPSI\StudyEngine\Longitudinal;
use EIPSI\StudyEngine\Persistence as P;
use EIPSI\StudyEngine\Engine\CompiledStudy;
use EIPSI\StudyEngine\Contracts\Canonical;

/** Called inside ExecutionOwner commit: durable intents, never SMTP or reconstruction of rules. */
final class NotificationScheduler {
    private $db;
    public function __construct(P\Database $db){$this->db=$db;}
    public function synchronize(array $s,CompiledStudy $graph,array $subject,string $version): void {
        $this->db->requireTransaction();$config=Configuration::read($graph->definition());if($subject['kind']!=='PARTICIPANT')throw new P\IntegrityFailure('PARTICIPANT_REQUIRED');
        $study=\EIPSI\StudyEngine\Runtime\PausePolicy::study($this->db,$s['execution']['study_id']);$allowed=null;if(\EIPSI\StudyEngine\Runtime\PausePolicy::paused($study)){$e=(new P\StudyExecutionRepository($this->db))->find($s['execution']['id']);$allowed=\EIPSI\StudyEngine\Runtime\PausePolicy::openBlocks($this->db,$e,$graph->definition(),true);}
        $identity=new Identity($this->db);$p=$identity->participant($subject['id'],true);if(!$p)throw new P\IntegrityFailure('PARTICIPANT_REQUIRED');
        if(isset($config['initial_screen'])&&(bool)$p['active']&&$p['privacy_state']==='ACTIVE'&&$p['verification_state']!=='VERIFIED'&&!$s['notifications_cancelled']&&in_array($s['execution']['state'],array('ACTIVE','WAITING'),true)&&InitialScreen::required($config,$s,$graph->definition())){
            foreach($s['instances'] as $i)if(($allowed===null||isset($allowed[$i['id']]))&&!P\NotificationIntentRepository::logicalCancellation($s,$i)&&$graph->node($i['definition_id'])['completion_boundary']==='ASSESSMENT'&&in_array($i['state'],array('SCHEDULED','AVAILABLE','IN_PROGRESS'),true)&&$i['available_at']!==null&&($i['due_at']===null||$i['available_at']<$i['due_at']))$this->enqueue($s['execution']['id'],array('key'=>'VERIFY_OPPORTUNITY','block_execution_id'=>$i['id'],'trigger_at'=>$i['available_at'],'channel'=>'EMAIL','purpose'=>'VERIFY_PARTICIPANT'),$s);
        }
        $repo=new P\NotificationIntentRepository($this->db);$exec=$s['execution']['id'];
        if(!(bool)$p['active']||$p['privacy_state']!=='ACTIVE'||!$config['email_enabled']||$s['notifications_cancelled']||in_array($s['execution']['state'],array('COMPLETED','WITHDRAWN','TERMINATED_BY_RULE'),true)){
            // Required identity verification is independent of optional scientific notices.
            $preserveVerification=isset($config['initial_screen'])&&(bool)$p['active']&&$p['privacy_state']==='ACTIVE'&&!$s['notifications_cancelled']&&in_array($s['execution']['state'],array('ACTIVE','WAITING'),true);
            $this->cancel($exec,'PARTICIPANT_OR_EXECUTION_CLOSED',$preserveVerification);return;
        }
        $barrier=$s['barrier']['target_execution_id']??null;
        foreach($repo->list($exec) as $row)if($row['block_execution_id']!==null&&$barrier!==null&&$row['block_execution_id']!==$barrier&&in_array($row['state'],array('PENDING','CLAIMED','FAILED_RETRYABLE'),true))$this->db->update('notification_intents',array('state'=>'CANCELLED','cancel_reason'=>'WAIT_SUPERSEDED'),$this->db->prepare('id=%s',$row['id']));
        if($p['verification_state']!=='VERIFIED'&&(InitialScreen::v2($config)?InitialScreen::required($config,$s,$graph->definition()):$config['verification_policy']==='VERIFIED_ONLY'))return;
        foreach($s['instances'] as $i){
            if($allowed!==null&&!isset($allowed[$i['id']]))continue;
            if(P\NotificationIntentRepository::logicalCancellation($s,$i)||$graph->node($i['definition_id'])['completion_boundary']!=='ASSESSMENT'||!in_array($i['state'],array('SCHEDULED','AVAILABLE','IN_PROGRESS'),true)||$i['available_at']===null||($barrier!==null&&$i['id']!==$barrier))continue;
            $available=$i['available_at'];$due=$i['due_at'];if($due!==null&&$available>=$due)continue;
            if($allowed===null)$this->enqueue($exec,array('key'=>$config['availability_template'],'block_execution_id'=>$i['id'],'trigger_at'=>$available,'channel'=>'EMAIL','purpose'=>'ASSESSMENT_AVAILABLE'),$s);
            $node=$graph->node($i['definition_id']);$scheduled=$node['metadata']['scheduled_interaction']??null;
            if($scheduled===null&&$i['parent_execution_id']!==null){$parent=$s['instances'][$i['parent_execution_id']]??null;if($parent)$scheduled=$graph->node($parent['definition_id'])['metadata']['scheduled_interaction']??null;}
            $offsets=$scheduled!==null?$scheduled['reminders']:array_map(static function($minutes){return $minutes*60;},$config['reminder_offsets']);
            foreach($offsets as $seconds){$at=gmdate('Y-m-d\TH:i:s\Z',strtotime($available)+$seconds);if($due!==null&&$at>=$due)continue;
                $this->enqueue($exec,array('key'=>$scheduled!==null?'REMINDER_SECONDS_'.$seconds:'REMINDER_'.($seconds/60),'block_execution_id'=>$i['id'],'trigger_at'=>$at,'channel'=>'EMAIL','purpose'=>'ASSESSMENT_REMINDER'),$s);
            }
        }
    }
    public function enqueue(string $execution,array $spec,array $snapshot): string {
        $this->db->requireTransaction();global $wpdb;
        $engine=$this->db->row($this->db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$wpdb->prefix.'survey_nudge_jobs'));
        if(!$engine||strcasecmp($engine['ENGINE'],'InnoDB')!==0)throw new P\IntegrityFailure('QUEUE_TRANSACTION_REQUIRED');
        $repo=new P\NotificationIntentRepository($this->db);$id=$repo->enqueue($execution,$spec,$snapshot);$row=$this->db->row($this->db->prepare('SELECT * FROM '.$this->db->table('notification_intents').' WHERE id=%s FOR UPDATE',$id));
        if($row['queue_job_id']===null&&$row['state']==='PENDING'){
            // Queue keeps its established site-local clock/lease contract; intent time stays UTC.
            $scheduled=get_date_from_gmt($row['trigger_at'],'Y-m-d H:i:s');
            $job=\EIPSI_Notification_Nudge_Queue_Service::enqueue_study_engine($this->db,$id,$scheduled);
            if($job===false)throw new P\IntegrityFailure('NOTIFICATION_QUEUE_WRITE_FAILED');
            $this->db->update('notification_intents',array('queue_job_id'=>$job),$this->db->prepare('id=%s',$id));
        }return $id;
    }
    public function cancel(string $execution,string $reason,bool $preserveVerification=false): void {
        $this->db->update('notification_intents',array('state'=>'CANCELLED','cancel_reason'=>$reason,'updated_at'=>P\Database::now()),$this->db->prepare("execution_id=%s AND block_execution_id IS NOT NULL AND state IN ('PENDING','CLAIMED','FAILED_RETRYABLE')".($preserveVerification?" AND purpose<>'VERIFY_PARTICIPANT'":""),$execution));
        $this->db->query($this->db->prepare('UPDATE '.$this->db->table('access_capabilities').' SET revoked_at=%s WHERE execution_id=%s AND consumed_at IS NULL AND revoked_at IS NULL AND intent_id IN (SELECT id FROM '.$this->db->table('notification_intents')." WHERE execution_id=%s AND state='CANCELLED')",P\Database::now(),$execution,$execution));
    }
    /** Auth/verification is possible even after scientific completion; separate from assessment offers. */
    public function authentication(string $execution,string $purpose,string $key,array $snapshot): string {
        if($purpose!=='VERIFY_PARTICIPANT')throw new \DomainException('INVALID_AUTHENTICATION_INTENT');
        // Purpose-aware outbox policy allows access to final state without changing science.
        return $this->enqueue($execution,array('key'=>$key,'block_execution_id'=>null,'trigger_at'=>$snapshot['clock'],'channel'=>'EMAIL','purpose'=>$purpose),$snapshot);
    }
}
