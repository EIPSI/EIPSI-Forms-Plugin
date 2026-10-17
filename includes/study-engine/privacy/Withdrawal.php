<?php
namespace EIPSI\StudyEngine\Privacy;
use EIPSI\StudyEngine\Persistence as P;
use EIPSI\StudyEngine\Contracts\Canonical;
/** Same-transaction privacy consequences, invoked only by ExecutionOwner's product command. */
final class Withdrawal {
    public static function apply(P\Database $db,array $row,string $mode): void {
        $db->requireTransaction();global $wpdb;$id=$row['id'];$now=P\Database::now();
        $queue=$db->row($db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$wpdb->prefix.'survey_nudge_jobs'));
        if(!$queue||strcasecmp($queue['ENGINE'],'InnoDB')!==0)throw new P\IntegrityFailure('QUEUE_TRANSACTION_REQUIRED');
        $db->query($db->prepare("UPDATE ".$db->table('management_exports')." SET state='INVALIDATED',payload_json=NULL,plan_json='[]',manifest_json=NULL,error_code='DATA_POLICY_CHANGED' WHERE study_id=%s",$row['study_id']));
        $db->update('study_executions',array('data_policy'=>$mode),$db->prepare('id=%s',$id));
        $db->update('notification_intents',array('state'=>'CANCELLED','cancel_reason'=>'VOLUNTARY_WITHDRAWAL','updated_at'=>$now),$db->prepare("execution_id=%s AND state IN ('PENDING','CLAIMED','FAILED_RETRYABLE')",$id));
        $db->query($db->prepare('UPDATE `'.$wpdb->prefix.'survey_nudge_jobs` j JOIN '.$db->table('notification_intents')." i ON i.queue_job_id=j.id SET j.status='cancelled',j.error='VOLUNTARY_WITHDRAWAL' WHERE i.execution_id=%s AND j.status IN ('pending','processing')",$id));
        if($row['participant_id']!==null){
            $p=(new \EIPSI\StudyEngine\Longitudinal\Identity($db))->participant($row['participant_id'],true);if(!$p||$p['execution_id']!==$id)throw new P\IntegrityFailure('PRIVACY_PARTICIPANT_BINDING');
            $rev=(int)$p['access_revision']+1;
            $db->update('participants',array('privacy_state'=>$mode==='DO_NOT_USE'?'WITHDRAWN_DO_NOT_USE':'WITHDRAWN_RETAIN','access_revision'=>$rev,'updated_at'=>$now),$db->prepare('id=%s',$p['id']));
            $db->update('participant_sessions',array('revoked_at'=>$now),$db->prepare("execution_id=%s AND kind<>'WITHDRAWAL_OPTIONS' AND revoked_at IS NULL",$id));
            $db->update('participant_sessions',array('access_revision'=>$rev),$db->prepare("execution_id=%s AND kind='WITHDRAWAL_OPTIONS' AND revoked_at IS NULL",$id));
            $db->update('access_capabilities',array('revoked_at'=>$now),$db->prepare("execution_id=%s AND NOT(purpose='WITHDRAWAL_OPTIONS' AND consumed_at IS NOT NULL) AND revoked_at IS NULL",$id));
        }else $db->update('anonymous_access',array('revoked_at'=>$now),$db->prepare('execution_id=%s',$id));
        $w=(new P\WithdrawalRepository($db))->find($id);$snapshot=P\Database::json($w['snapshot_json']);
        $coverage=Coverage::report(array('participant'=>$mode==='DO_NOT_USE'?'PENDING':'RETAINED','scientific_data'=>$mode==='DO_NOT_USE'?'PENDING':'RETAINED','scientific_access'=>'REVOKED','pending_notifications'=>'CANCELLED'));
        $snapshot['coverage']=array('source'=>'WORDPRESS_LOCAL_RELATIONAL','complete'=>false,'not_covered'=>$coverage['not_covered']);
        $db->update('withdrawal_records',array('coverage_json'=>Canonical::json($coverage),'snapshot_json'=>Canonical::json($snapshot),'cleanup_error'=>null,'cleanup_revision'=>(int)$w['cleanup_revision']+1),$db->prepare('execution_id=%s',$id));
    }
}
