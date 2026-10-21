<?php
namespace EIPSI\StudyEngine\Persistence;
use EIPSI\StudyEngine\Contracts\Canonical;
final class CommandRepository {
    private $db;
    public function __construct(Database $db){$this->db=$db;}
    public function find(string $execution,string $command): ?array {return $this->db->row($this->db->prepare('SELECT * FROM '.$this->db->table('execution_commands').' WHERE execution_id=%s AND command_id=%s',$execution,$command));}
    public function result(array $row,string $hash): array {
        if(!hash_equals($row['request_hash'],$hash))throw new PersistenceConflict('IDEMPOTENCY_KEY_REUSED');
        if($row['result_json']===null)throw new IntegrityFailure('INCOMPLETE_COMMAND_COMMIT');
        $result=Database::json($row['result_json']);
        try{
            \EIPSI\StudyEngine\Contracts\Check::fields($result,array('revision','next_action','event_count'));
            \EIPSI\StudyEngine\Contracts\Check::integer($result['event_count'],'event_count');
            new \EIPSI\StudyEngine\Contracts\NextAction($result['next_action']);
            if($result['revision']!==(int)$row['committed_revision'])throw new \DomainException('INVALID_COMMITTED_REVISION');
        }catch(\Throwable $e){throw new IntegrityFailure('COMMAND_RESULT_CORRUPTED');}
        return $result;
    }
    public function claim(string $execution,array $command,string $hash,int $revision): void {$this->db->insert('execution_commands',array('execution_id'=>$execution,'command_id'=>$command['id'],'command_type'=>$command['kind'],'request_hash'=>$hash,'expected_revision'=>$revision,'result_json'=>null,'committed_revision'=>null,'created_at'=>Database::now(),'committed_at'=>null));}
    public function finish(string $execution,string $command,array $result): void {$this->db->update('execution_commands',array('result_json'=>Canonical::json($result),'committed_revision'=>$result['revision'],'committed_at'=>Database::now()),$this->db->prepare('execution_id=%s AND command_id=%s',$execution,$command));}
}
