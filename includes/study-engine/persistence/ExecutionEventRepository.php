<?php
namespace EIPSI\StudyEngine\Persistence;
use EIPSI\StudyEngine\Contracts\Canonical;

final class ExecutionEventRepository {
    private $db;
    public function __construct(Database $db){$this->db=$db;}
    public function load(string $execution): array {
        $out=array();foreach($this->db->rows($this->db->prepare('SELECT * FROM '.$this->db->table('execution_events').' WHERE execution_id=%s ORDER BY sequence',$execution)) as $row){$e=Database::json($row['snapshot_json']);if(($e['sequence']??null)!==count($out)+1||(int)$row['sequence']!==$e['sequence']||$row['event_type']!==$e['type']||$e['execution_id']!==$execution)throw new IntegrityFailure('EVENT_SEQUENCE_CORRUPTED');foreach(array('block_execution_id','rule_id','decision_id','old_state','new_state','reason') as $field)if($row[$field]!==$e[$field])throw new IntegrityFailure('EVENT_PROJECTION_MISMATCH');if($row['domain_at']!==Database::date($e['simulated_at']))throw new IntegrityFailure('EVENT_TIME_MISMATCH');$out[]=$e;}return $out;
    }
    public function append(string $execution,int $last,array $events,?string $command): void {
        $this->db->requireTransaction();foreach($events as $e){if($e['sequence']!==++$last||$e['execution_id']!==$execution)throw new IntegrityFailure('EVENT_SEQUENCE_INVALID');
            $this->db->insert('execution_events',array('execution_id'=>$execution,'sequence'=>$e['sequence'],'event_type'=>$e['type'],'server_at'=>Database::now(),'domain_at'=>Database::date($e['simulated_at']),'command_id'=>$command,'block_execution_id'=>$e['block_execution_id'],'rule_id'=>$e['rule_id'],'decision_id'=>$e['decision_id'],'old_state'=>$e['old_state'],'new_state'=>$e['new_state'],'reason'=>$e['reason'],'snapshot_json'=>Canonical::json($e)));
        }
    }
}
