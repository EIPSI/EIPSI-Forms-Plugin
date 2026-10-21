<?php
namespace EIPSI\StudyEngine\Persistence;
use EIPSI\StudyEngine\Contracts\Canonical;
use EIPSI\StudyEngine\Contracts\RandomDecision;
use EIPSI\StudyEngine\Engine\CompiledStudy;

final class RandomDecisionRepository {
    private $db;
    public function __construct(Database $db){$this->db=$db;}
    public function load(string $execution): array {$out=array();foreach($this->db->rows($this->db->prepare('SELECT * FROM '.$this->db->table('random_decisions').' WHERE execution_id=%s',$execution)) as $row){$d=Database::json($row['snapshot_json']);if(($d['id']??null)!==$row['id']||$d['study_execution_id']!==$execution||Canonical::json($d['selection'])!==$row['selection_json'])throw new IntegrityFailure('RANDOM_SNAPSHOT_MISMATCH');foreach(array('block_execution_id','owner_id','segment_id','scope','algorithm_version','seed_ref') as $field)if($row[$field]!==$d[$field])throw new IntegrityFailure('RANDOM_PROJECTION_MISMATCH');if($row['scope_owner']!==($d['block_execution_id']??$execution)||$row['candidates_json']!==Canonical::json($d['eligible_candidates'])||$row['created_at']!==Database::date($d['decided_at']))throw new IntegrityFailure('RANDOM_PROJECTION_MISMATCH');$out[$d['id']]=$d;}return $out;}
    public function persist(array $old,array $new,CompiledStudy $graph,TransitionObserver $observer): void {
        foreach($new as $id=>$d){if(isset($old[$id])){if(Canonical::json($old[$id])!==Canonical::json($d))throw new IntegrityFailure('RANDOM_DECISION_IMMUTABLE');continue;}
            $contract=$d;unset($contract['owner_id'],$contract['source']);$contract['strategy_id']=$d['owner_id'];new RandomDecision($contract);
            if(!in_array($d['source'],array('EIPSI_RANDOM_V1','LOGICAL_FORCED_OUTCOME'),true))throw new IntegrityFailure('PRODUCTION_REQUIRES_SERVER_RANDOM');
            $this->db->insert('random_decisions',array('id'=>$id,'execution_id'=>$d['study_execution_id'],'block_execution_id'=>$d['block_execution_id'],'owner_id'=>$d['owner_id'],'segment_id'=>$d['segment_id'],'scope'=>$d['scope'],'scope_owner'=>$d['block_execution_id']??$d['study_execution_id'],'candidates_json'=>Canonical::json($d['eligible_candidates']),'selection_json'=>Canonical::json($d['selection']),'weights_json'=>Canonical::json(array_intersect_key($graph->node($d['owner_id'])['randomization']['weights'],array_flip($d['eligible_candidates']))),'algorithm_version'=>$d['algorithm_version'],'seed_ref'=>$d['seed_ref'],'snapshot_json'=>Canonical::json($d),'created_at'=>Database::date($d['decided_at'])));$observer->reached('after_random_decision');
        }
        if(array_diff_key($old,$new))throw new IntegrityFailure('RANDOM_HISTORY_REMOVED');
    }
}
