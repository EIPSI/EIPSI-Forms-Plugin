<?php
namespace EIPSI\StudyEngine\Privacy;
use EIPSI\StudyEngine\Persistence as P;
use EIPSI\StudyEngine\Contracts\Canonical;
/** Explicit analytical adapter: stable IDs, one locked materialization boundary, no transport secrets. */
final class ExportAdapter {
    private $db;private $observer;
    public function __construct(P\Database $db,?P\TransitionObserver $observer=null){$this->db=$db;$this->observer=$observer??new P\TransitionObserver();}
    public function dataset(string $study,bool $includeRetained=true): array {
        \EIPSI\StudyEngine\Contracts\Check::id($study,'study');
        return $this->db->transactional(function()use($study,$includeRetained){
            // Same lock order as withdrawal. Materialized files existing before B2 cannot be recalled.
            $executions=$this->db->rows($this->db->prepare('SELECT e.*,w.data_policy AS withdrawal_policy,w.mode AS withdrawal_mode FROM '.$this->db->table('study_executions').' e LEFT JOIN '.$this->db->table('withdrawal_records').' w ON w.execution_id=e.id WHERE e.study_id=%s ORDER BY e.id FOR UPDATE',$study));
            $this->observer->reached('export_locked');$versions=array();$manifest=array();$rows=array();$allowed=array();
            foreach($executions as $e)if(Policy::usable($e,array('data_policy'=>$e['withdrawal_policy']),$includeRetained))$allowed[$e['id']]=$e;
            if(!$allowed)return array('contract'=>'P1G_EXPORT_V1','rows'=>array(),'manifest'=>array(),'source'=>'wordpress_db','consistency'=>'LOCKED_MATERIALIZATION');
            $ids=implode(',',array_map(function($v){return $this->db->prepare('%s',$v);},array_keys($allowed)));
            $blocks=array();foreach($this->db->rows('SELECT * FROM '.$this->db->table('block_executions').' WHERE execution_id IN ('.$ids.') ORDER BY id') as $b)$blocks[$b['execution_id']][$b['id']]=P\Database::json($b['snapshot_json']);
            $events=array();foreach($this->db->rows('SELECT * FROM '.$this->db->table('execution_events').' WHERE execution_id IN ('.$ids.') ORDER BY execution_id,sequence') as $v)$events[$v['execution_id']][]=P\Database::json($v['snapshot_json']);
            $random=array();foreach($this->db->rows('SELECT * FROM '.$this->db->table('random_decisions').' WHERE execution_id IN ('.$ids.') ORDER BY id') as $v)$random[$v['execution_id']][$v['id']]=P\Database::json($v['snapshot_json']);
            $vids=implode(',',array_map(function($v){return $this->db->prepare('%s',$v);},array_unique(array_column($allowed,'study_version_id'))));
            foreach($this->db->rows('SELECT * FROM '.$this->db->table('study_versions').' WHERE id IN ('.$vids.')') as $v){
                $g=(new P\StudyVersionRepository($this->db))->compiled($v);$versions[$v['id']]=array($v,$g);$dictionary=array();
                foreach($g->definition()['nodes'] as $n)if($n['kind']==='QUESTION'){$content=json_decode($n['content']['snapshot']??'',true);$dictionary[]=array('question_id'=>$n['id'],'response_key'=>$n['response_key'],'label'=>$content['label']??$n['content']['snapshot'],'options'=>$n['options'],'semantic_role'=>$n['semantic_role'],'content_hash'=>$n['content']['sha256'],'parent_block_id'=>$n['parent_id']);}
                $manifest[$v['id']]=array('study_version_id'=>$v['id'],'definition_id'=>$v['definition_id'],'version_number'=>(int)$v['version_number'],'protocol_hash'=>$v['protocol_hash'],'state'=>$v['state'],'questions'=>$dictionary,'blocks'=>array_values(array_map(static function($n){return array('id'=>$n['id'],'kind'=>$n['kind'],'parent_id'=>$n['parent_id']);},array_filter($g->definition()['nodes'],static function($n){return $n['kind']!=='QUESTION';}))));
            }
            foreach($allowed as $id=>$e){
                if(!isset($versions[$e['study_version_id']]))throw new P\IntegrityFailure('EXPORT_VERSION_UNAVAILABLE');list($v,$g)=$versions[$e['study_version_id']];$c=P\Database::json($e['checkpoint_json']);
                $c['state']['instances']=$blocks[$id]??array();$c['state']['events']=$events[$id]??array();$c['state']['decisions']=$random[$id]??array();
                if(!hash_equals($e['checkpoint_hash'],hash('sha256',Canonical::json($c))))throw new P\IntegrityFailure('EXPORT_CHECKPOINT_HASH_MISMATCH');
                $s=$c['state'];$decisions=array();foreach($random[$id]??array() as $d){unset($d['seed_ref']);$decisions[]=$d;}
                foreach($blocks[$id]??array() as $b){$n=$g->node($b['definition_id']);if($n['completion_boundary']!=='ASSESSMENT')continue;
                    if(isset($n['metadata']['native_version_id'])){
                        $nativeRows=$this->db->rows($this->db->prepare('SELECT * FROM '.$this->db->table('native_executions')." WHERE version_id=%s AND state IN ('COMPLETED','PARTIAL_SAVED','LOGICAL_EXIT') AND JSON_UNQUOTE(JSON_EXTRACT(binding_json,'$.execution_id'))=%s AND JSON_UNQUOTE(JSON_EXTRACT(binding_json,'$.block_execution_id'))=%s ORDER BY created_at,id",$n['metadata']['native_version_id'],$id,$b['id']));
                        foreach($nativeRows as $record){$binding=json_decode($record['binding_json'],true);if(($binding['study_version_id']??null)!==$v['id']||($binding['participant_id']??null)!==($e['participant_id']??$e['anonymous_identity_id']))throw new P\IntegrityFailure('NATIVE_EXPORT_BINDING_MISMATCH');$final=json_decode($record['final_json'],true);foreach($final['rows']??array() as $answer)$rows[]=array_merge($answer,array('study_id'=>$study,'study_public_id'=>$g->definition()['study']['public_id'],'study_version_id'=>$v['id'],'version_number'=>(int)$v['version_number'],'protocol_hash'=>$v['protocol_hash'],'participant_id'=>$binding['participant_id'],'execution_id'=>$id,'execution_state'=>$e['state'],'study_finalized_at'=>$e['finalized_at'],'study_cycle'=>(int)$e['study_cycle'],'data_policy'=>$e['data_policy'],'withdrawal_mode'=>$e['withdrawal_mode'],'block_execution_id'=>$b['id'],'native_execution_id'=>$record['id'],'native_version_id'=>$record['version_id'],'occurrence_key'=>$b['occurrence_key'],'response_status'=>$record['state'],'available_at'=>$b['available_at'],'due_at'=>$b['due_at'],'completed_at'=>$b['completed_at'],'randomization'=>$decisions));}
                    }
                    foreach($g->descendants($n['id']) as $qid){$q=$g->node($qid);if($q['kind']!=='QUESTION')continue;$answer=$s['responses'][$b['id']][$qid]??null;$covered=$s['coverage'][$b['id']][$qid]??null;
                        $status=$answer!==null?($b['state']==='COMPLETED'?'ANSWERED':'PARTIAL_ANSWER'):(in_array($b['state'],array('EXPIRED','SKIPPED_BY_RULE','NOT_SCHEDULED','WITHDRAWN','TERMINATED_BY_RULE'),true)?$b['state']:($covered!==null?$covered['state']:($b['state']==='SCHEDULED'?'NOT_YET_AVAILABLE':($q['required']?'MISSING_ELIGIBLE':'OPTIONAL_MISSING'))));
                        $native=json_decode($q['content']['snapshot']??'',true)['native_element']??null;
                        $rows[]=array('variable_name'=>$native['properties']['variable_name']??$q['response_key'],'study_id'=>$study,'study_public_id'=>$g->definition()['study']['public_id'],'study_version_id'=>$v['id'],'version_number'=>(int)$v['version_number'],'protocol_hash'=>$v['protocol_hash'],'participant_id'=>$e['participant_id']??$e['anonymous_identity_id'],'execution_id'=>$id,'execution_state'=>$e['state'],'study_finalized_at'=>$e['finalized_at'],'study_cycle'=>(int)$e['study_cycle'],'data_policy'=>$e['data_policy'],'withdrawal_mode'=>$e['withdrawal_mode'],'assessment_id'=>$n['id'],'block_execution_id'=>$b['id'],'occurrence_key'=>$b['occurrence_key'],'question_id'=>$qid,'response_key'=>$q['response_key'],'semantic_role'=>$q['semantic_role'],'content_hash'=>$q['content']['sha256'],'value'=>$answer['value']??null,'response_status'=>$status,'block_state'=>$b['state'],'available_at'=>$b['available_at'],'due_at'=>$b['due_at'],'completed_at'=>$b['completed_at'],'provenance'=>$covered??$b['provenance'],'randomization'=>$decisions);
                    }
                }
            }
            $this->observer->reached('export_before_materialize');return array('contract'=>'P1G_EXPORT_V1','rows'=>$rows,'manifest'=>$manifest,'source'=>'wordpress_db','consistency'=>'LOCKED_MATERIALIZATION');
        });
    }
}
