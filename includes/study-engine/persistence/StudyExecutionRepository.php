<?php
namespace EIPSI\StudyEngine\Persistence;
use EIPSI\StudyEngine\Contracts\Check;

/** @internal Writes require the owner's transaction; no public arbitrary state setter. */
final class StudyExecutionRepository {
    private $db;
    public function __construct(Database $db){$this->db=$db;}
    public function find(string $id,bool $lock=false): ?array {Check::id($id,'execution_id');if($lock)$this->db->requireTransaction();return $this->db->row($this->db->prepare('SELECT * FROM '.$this->db->table('study_executions').' WHERE id=%s'.($lock?' FOR UPDATE':''),$id));}
    public function request(string $key): ?array {return $this->db->row($this->db->prepare('SELECT * FROM '.$this->db->table('study_executions').' WHERE create_key=%s',$key));}
    public function create(array $row): void {$this->db->insert('study_executions',$row);}
    public function commitTransition(string $id,int $expected,array $row): void {
        if((int)$row['revision']<$expected||(int)$row['revision']>$expected+1)throw new \LogicException('INVALID_OWNER_REVISION');
        $n=$this->db->update('study_executions',$row,$this->db->prepare('id=%s AND revision=%d',$id,$expected));
        if($n===0&&(int)$row['revision']===$expected){
            $current=$this->find($id,true);
            if($current&&(int)$current['revision']===$expected&&hash_equals($current['checkpoint_hash'],$row['checkpoint_hash']))return;
        }
        if($n!==1)throw new PersistenceConflict('REVISION_CONFLICT');
    }
}
