<?php
namespace EIPSI\StudyEngine\Persistence;
use EIPSI\StudyEngine\Contracts\Canonical;
use EIPSI\StudyEngine\Contracts\Check;
use EIPSI\StudyEngine\Engine\Simulator;

final class NotificationIntentRepository {
    public const STATES=array('PENDING','CLAIMED','SENT','CANCELLED','FAILED_RETRYABLE','FAILED_FINAL');
    private $db;
    public function __construct(Database $db){$this->db=$db;}
    public function list(string $execution): array {return $this->db->rows($this->db->prepare('SELECT * FROM '.$this->db->table('notification_intents').' WHERE execution_id=%s ORDER BY id',$execution));}
    public static function logicalCancellation(array $snapshot,?array $instance): bool {
        $cancelled=$snapshot['logical_trajectory']['cancelled_blocks']??array();$seen=array();while($instance){if(isset($cancelled[$instance['definition_id']]))return true;$id=$instance['parent_execution_id'];if($id===null||isset($seen[$id]))break;$seen[$id]=true;$instance=$snapshot['instances'][$id]??null;}return false;
    }
    public function enqueue(string $execution,array $spec,array $snapshot): string {
        Check::fields($spec,array('key','block_execution_id','trigger_at','channel','purpose'));
        if($spec['channel']==='EMAIL'&&in_array($spec['purpose'],array('ACCESS','WITHDRAWAL_OPTIONS'),true))throw new \DomainException('EMAIL_TYPE_REMOVED');
        Check::id($spec['key'],'notification_key');Check::id($spec['purpose'],'purpose');Check::timestamp($spec['trigger_at'],'trigger_at',false);
        if(!in_array($spec['channel'],array('EMAIL','IN_APP'),true))throw new \DomainException('INVALID_NOTIFICATION_CHANNEL');
        $target=$spec['block_execution_id'];$i=null;
        if($target!==null){Check::id($target,'block_execution_id');$i=$snapshot['instances'][$target]??null;if(!$i)throw new \DomainException('NOTIFICATION_TARGET_MISMATCH');}
        $authentication=$target===null&&$spec['purpose']==='VERIFY_PARTICIPANT';
        if(($i&&self::logicalCancellation($snapshot,$i))||($snapshot['notifications_cancelled']&&!$authentication)||($snapshot['execution']['state']==='WITHDRAWN')||(!$authentication&&in_array($snapshot['execution']['state'],array('COMPLETED','TERMINATED_BY_RULE'),true))||($i&&Simulator::terminal($i['state'])))throw new \DomainException('NOTIFICATION_TARGET_CLOSED');
        $revision=$authentication?0:($i['schedule_revision']??$snapshot['schedule_revision']);$key=hash('sha256',Canonical::json(array('key'=>$spec['key'],'target'=>$target,'channel'=>$spec['channel'],'purpose'=>$spec['purpose'],'revision'=>$revision)));
        $hash=hash('sha256',Canonical::json($spec));$existing=$this->db->row($this->db->prepare('SELECT * FROM '.$this->db->table('notification_intents').' WHERE execution_id=%s AND logical_key=%s',$execution,$key));
        if($existing){if(!hash_equals($existing['request_hash'],$hash))throw new PersistenceConflict('NOTIFICATION_KEY_REUSED');return $existing['id'];}
        $id='NI_'.substr(hash('sha256',$execution.'|'.$key),0,32);
        $this->db->insert('notification_intents',array('id'=>$id,'execution_id'=>$execution,'block_execution_id'=>$target,'logical_key'=>$key,'trigger_at'=>Database::date($spec['trigger_at']),'schedule_revision'=>$revision,'channel'=>$spec['channel'],'state'=>'PENDING','purpose'=>$spec['purpose'],'capability_scope_json'=>null,'request_hash'=>$hash,'cancel_reason'=>null,'created_at'=>Database::now(),'updated_at'=>Database::now()));return $id;
    }
    public function invalidate(string $execution,array $snapshot): void {
        foreach($this->list($execution) as $row){if(!in_array($row['state'],array('PENDING','CLAIMED','FAILED_RETRYABLE'),true))continue;
            $i=$row['block_execution_id']===null?null:($snapshot['instances'][$row['block_execution_id']]??null);$reason=null;if($row['channel']==='EMAIL'&&in_array($row['purpose'],array('ACCESS','WITHDRAWAL_OPTIONS'),true))$reason='EMAIL_TYPE_REMOVED';
            $authentication=$row['block_execution_id']===null&&$row['purpose']==='VERIFY_PARTICIPANT';
            if($snapshot['execution']['state']==='WITHDRAWN'||(!$authentication&&($snapshot['notifications_cancelled']||in_array($snapshot['execution']['state'],array('COMPLETED','TERMINATED_BY_RULE'),true))))$reason='EXECUTION_CLOSED';
            elseif($i&&self::logicalCancellation($snapshot,$i))$reason='BLOCK_CANCELLED_BY_CONDITION';
            elseif($row['block_execution_id']!==null&&(!$i||Simulator::terminal($i['state'])))$reason='TARGET_CLOSED';
            elseif(!$authentication&&(int)$row['schedule_revision']!==($i['schedule_revision']??$snapshot['schedule_revision']))$reason='SCHEDULE_REVISION_CHANGED';
            if($reason)$this->db->update('notification_intents',array('state'=>'CANCELLED','cancel_reason'=>$reason,'updated_at'=>Database::now()),$this->db->prepare('id=%s AND state IN (%s,%s,%s)',$row['id'],'PENDING','CLAIMED','FAILED_RETRYABLE'));
        }
    }
}
