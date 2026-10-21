<?php
namespace EIPSI\StudyEngine\Persistence;
use EIPSI\StudyEngine\Contracts\Canonical;
use EIPSI\StudyEngine\Domain\OccurrenceKey;

final class BlockExecutionRepository {
    private $db;
    public function __construct(Database $db){$this->db=$db;}
    public function load(string $execution,string $version): array {
        return self::decodeRows($this->db->rows($this->db->prepare('SELECT * FROM '.$this->db->table('block_executions').' WHERE execution_id=%s',$execution)),$execution,$version);
    }
    /** Same integrity checks for batched administrative scientific reads. */
    public static function decodeRows(array $rows,string $execution,string $version): array {
        $out=array();foreach($rows as $row){$i=Database::json($row['snapshot_json']);if(($i['id']??null)!==$row['id']||$i['study_execution_id']!==$execution||$i['state']!==$row['state']||(int)$row['revision']!==$i['revision']||Canonical::json($i['occurrence_key'])!==$row['occurrence_json']||!hash_equals($row['occurrence_hash'],hash('sha256',$row['occurrence_json'])))throw new IntegrityFailure('BLOCK_SNAPSHOT_MISMATCH');foreach(array('definition_id'=>'definition_id','parent_execution_id'=>'parent_execution_id','schedule_revision'=>'schedule_revision') as $field=>$column)if((string)$i[$field]!== (string)$row[$column])throw new IntegrityFailure('BLOCK_PROJECTION_MISMATCH');
            if($row['study_version_id']!==$version||$row['terminal_reason']!==($i['provenance']['reason']??null)||$row['provenance_json']!==($i['provenance']===null?null:Canonical::json($i['provenance'])))throw new IntegrityFailure('BLOCK_PROJECTION_MISMATCH');
            foreach(array('available_at','due_at','started_at','completed_at') as $field)if(Database::date($i[$field])!==$row[$field])throw new IntegrityFailure('BLOCK_TIME_MISMATCH');
            $out[$i['id']]=$i;}return $out;
    }
    public function persist(array $old,array $new,string $versionId): void {
        $this->db->requireTransaction();$ids=array_keys($new);sort($ids,SORT_STRING);
        foreach($ids as $id){$i=$new[$id];if(isset($old[$id])&&Canonical::json($old[$id])===Canonical::json($i))continue;
            $key=(new OccurrenceKey($i['occurrence_key']))->key();$row=array('id'=>$id,'execution_id'=>$i['study_execution_id'],'study_version_id'=>$versionId,'definition_id'=>$i['definition_id'],'parent_execution_id'=>$i['parent_execution_id'],'occurrence_json'=>$key,'occurrence_hash'=>hash('sha256',$key),'state'=>$i['state'],'schedule_revision'=>$i['schedule_revision'],'revision'=>$i['revision'],'terminal_reason'=>$i['provenance']['reason']??null,'provenance_json'=>$i['provenance']===null?null:Canonical::json($i['provenance']),'snapshot_json'=>Canonical::json($i),'updated_at'=>Database::now());
            foreach(array('available_at','due_at','started_at','completed_at') as $k)$row[$k]=Database::date($i[$k]);
            if(!isset($old[$id])){$row['created_at']=Database::now();$this->db->insert('block_executions',$row);}else{
                if($old[$id]['occurrence_key']!==$i['occurrence_key']||$old[$id]['definition_id']!==$i['definition_id'])throw new IntegrityFailure('OCCURRENCE_IDENTITY_CHANGED');
                unset($row['id']);$this->db->update('block_executions',$row,$this->db->prepare('id=%s AND execution_id=%s',$id,$i['study_execution_id']));
            }
        }
        if(array_diff_key($old,$new))throw new IntegrityFailure('BLOCK_HISTORY_REMOVED');
    }
}
