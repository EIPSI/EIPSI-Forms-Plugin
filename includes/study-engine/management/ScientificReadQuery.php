<?php
namespace EIPSI\StudyEngine\Management;
use EIPSI\StudyEngine\Persistence as P;
use EIPSI\StudyEngine\Contracts\Canonical;
/** Internal scientific reads. Constructed only by the admin Query or the explicit dataset boundary. */
abstract class ScientificReadQuery {
    public $db;public $study;protected $versions=array();protected $nodes=array();protected $nativeAvailable=null;protected $executionCache=array();protected $nativeCache=array();protected $blockCache=array();
    protected function __construct(string $study){\EIPSI\StudyEngine\Contracts\Check::id($study,'study');global $wpdb;$this->db=new P\Database($wpdb);$this->study=$study;
        $rows=$this->db->rows($this->db->prepare('SELECT * FROM '.$this->db->table('study_versions').' WHERE study_id=%s ORDER BY version_number',$study));if(!$rows)throw new \DomainException('MANAGEMENT_STUDY_NOT_FOUND');
        foreach($rows as $r){$d=P\Database::json($r['protocol_json']);if(!hash_equals($r['protocol_hash'],Canonical::protocolHash($d)))throw new P\IntegrityFailure('MANAGEMENT_PROTOCOL_HASH_MISMATCH');$this->versions[$r['id']]=$r;foreach($d['nodes'] as $n)$this->nodes[$r['id']][$n['id']]=$n;}
    }
    public function scope(array $f=array(),bool $analytical=false): string {
        $f=Query::filters($f);$w=$this->db->prepare('e.study_id=%s',$this->study);
        foreach(array('version'=>'study_version_id','cycle'=>'study_cycle','execution'=>'id','state'=>'state') as $key=>$column)if(isset($f[$key])&&$f[$key]!=='')$w.=$this->db->prepare(' AND e.'.$column.'=%s',$f[$key]);
        if(!empty($f['from']))$w.=$this->db->prepare(' AND e.created_at>=%s',$f['from'].' 00:00:00');if(!empty($f['to']))$w.=$this->db->prepare(' AND e.created_at<=%s',$f['to'].' 23:59:59');
        if(!empty($f['search'])){global $wpdb;$w.=$this->db->prepare(' AND e.id LIKE %s','%'.$wpdb->esc_like($f['search']).'%');}
        if($analytical){$w.=" AND e.data_policy<>'DO_NOT_USE' AND NOT EXISTS (SELECT 1 FROM ".$this->db->table('withdrawal_records')." w WHERE w.execution_id=e.id AND w.data_policy='DO_NOT_USE')";if(($f['include_retained']??true)===false)$w.=" AND e.data_policy<>'RETAIN_FOR_ANALYSIS'";}
        return $w;
    }
    public function participation(string $id): array {
        $r=$this->executionCache[$id]??$this->db->row('SELECT * FROM '.$this->db->table('study_executions').' e WHERE '.$this->scope(array('execution'=>$id)));if(!$r)throw new \DomainException('MANAGEMENT_PARTICIPATION_NOT_FOUND');return $r;
    }
    public function nativeExists(): bool {if($this->nativeAvailable!==null)return $this->nativeAvailable;global $wpdb;$table=$wpdb->prefix.'eipsi_engine_native_executions';if(!$this->db->row($this->db->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$table)))return $this->nativeAvailable=false;$columns=$this->db->rows($this->db->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND COLUMN_NAME IN ('management_execution','management_opportunity')",$table));if(count($columns)!==2)throw new P\IntegrityFailure('MANAGEMENT_NATIVE_INDEX_REQUIRED');return $this->nativeAvailable=true;}
    public static function redact($v){if(!is_array($v))return $v;$out=array();foreach($v as $k=>$x){if($k==='cookies_enabled'&&(is_bool($x)||$x===null)){$out[$k]=$x;continue;}if(is_string($k)&&preg_match('/seed|cipher|token|csrf|cookie|bearer|email|capability|password/i',$k))continue;$out[$k]=self::redact($x);}return $out;}
    public function nativeRecord(string $id): array {
        require_once dirname(__DIR__,2).'/assessment-editor/Definition.php';
        \EIPSI\StudyEngine\Contracts\Check::id($id,'attempt');$n=$this->nativeCache[$id]??$this->db->row($this->db->prepare('SELECT n.*,v.definition_json,v.definition_hash FROM '.$this->db->table('native_executions').' n JOIN '.$this->db->table('native_versions').' v ON v.id=n.version_id JOIN '.$this->db->table('study_executions').' e ON e.id=n.management_execution WHERE '.$this->scope(array(),true).' AND n.id=%s',$id));if(!$n)throw new \DomainException('MANAGEMENT_ATTEMPT_NOT_FOUND');
        $e=$this->participation($n['management_execution']);$binding=P\Database::json($n['binding_json']);$b=$this->blockCache[$n['management_opportunity']]??$this->db->row($this->db->prepare('SELECT * FROM '.$this->db->table('block_executions').' WHERE id=%s AND execution_id=%s',$n['management_opportunity'],$e['id']));
        if(!$b||$b['execution_id']!==$e['id']||($binding['study_version_id']??null)!==$e['study_version_id']||($binding['participant_id']??null)!==($e['participant_id']??$e['anonymous_identity_id'])||($this->nodes[$e['study_version_id']][$b['definition_id']]['metadata']['native_version_id']??null)!==$n['version_id']||!hash_equals($n['definition_hash'],hash('sha256',\EIPSI\AssessmentEditor\Definition::json(P\Database::json($n['definition_json'])))))throw new P\IntegrityFailure('MANAGEMENT_BINDING_MISMATCH');$n['source_occurrence_json']=$b['occurrence_json'];return $n;
    }
    public static function dictionary(array $d): array {
        $out=array();$walk=function($items)use(&$walk,&$out){foreach($items as $e){if($e['response_key'])foreach($e['type']==='matrix'?$e['properties']['matrix_rows']:array($e) as $i=>$r)$out[$r['response_key']]=array('item_id'=>$r['id'],'variable_name'=>$r['variable_name']??$e['properties']['variable_name'],'label'=>strip_tags($r['label_html']??$r['label']??$e['properties']['label_html']??$e['properties']['label']),'type'=>$e['type'],'options'=>$e['options'],'validation'=>array_replace(array_intersect_key($e['properties'],array_flip(array('required','min','max','step','integer_only','min_length','max_length','min_selected','max_selected'))),array_key_exists('required',$r)?array('required'=>$r['required']):array()));$walk($e['children']);}};$walk($d['elements']);return $out;
    }
    public function attempt(string $id,bool $audit=true): array {
        $n=$this->nativeRecord($id);$out=self::projectAttempt($n);if($audit)Audit::record($this->study,'RESPONSES_VIEW',$n['management_execution'],$id);return $out;
    }
    protected static function projectAttempt(array $n): array {
        $d=P\Database::json($n['definition_json']);$final=$n['final_json']===null?array():P\Database::json($n['final_json']);$session=$n['partial_json']===null?array():P\Database::json($n['partial_json']);$meta=$n['metadata_json']===null?array():P\Database::json($n['metadata_json']);
        $dictionary=self::dictionary($d);$rows=$final['rows']??array();
        if($n['final_json']!==null){$by=array_column($rows,null,'response_key');$excluded=array();$walk=function($items,$reason=null)use(&$walk,&$excluded,$meta){foreach($items as $e){$current=$reason;if($e['response_key'])foreach($e['type']==='matrix'?$e['properties']['matrix_rows']:array($e) as $r)if($current)$excluded[$r['response_key']]=$current;$random=$meta['randomization']['blocks'][$e['id']]??null;foreach($e['children'] as $child){$next=$current;if($e['type']==='randomization'){if($random&&!in_array($child['id'],$random['order'],true))$next='NOT_SELECTED_BY_RANDOMIZATION';elseif(!$random)$next='RANDOMIZATION_UNRESOLVED';}$walk(array($child),$next);}}};$walk($d['elements']);$binding=P\Database::json($n['binding_json']);$rows=array();foreach($dictionary as $key=>$item){$r=$by[$key]??array('assessment_id'=>$d['id'],'item_id'=>$item['item_id'],'response_key'=>$key,'variable_name'=>$item['variable_name'],'value'=>null,'selected_option_id'=>null);$r['response_status']=isset($by[$key])?($r['value']===null?'NO_VALUE_RECORDED':'VALUE_RECORDED'):($excluded[$key]??(in_array($item['item_id'],$binding['logical_skipped_ids']??array(),true)?'SKIPPED_BY_LOGIC':'NOT_RECORDED'));$rows[]=$r;}}
        $out=array('id'=>$n['id'],'version_id'=>$n['version_id'],'execution_id'=>$n['management_execution'],'opportunity_id'=>$n['management_opportunity'],'state'=>$n['state'],'revision'=>(int)$n['revision'],'created_at'=>(int)$n['created_at'],'completed_at'=>$n['completed_at'],'saved_at'=>$final['saved_at']??$n['completed_at'],'definition_hash'=>$n['definition_hash'],'title'=>$d['title'],'assessment_id'=>$d['id'],'occurrence'=>P\Database::json($n['source_occurrence_json']),'dictionary'=>$dictionary,'rows'=>$rows,'session_answers'=>$session['answers']??array(),'metadata'=>self::redact($meta),'source_attempt'=>$final['source_execution_id']??null,'meaning'=>$n['state']==='ACTIVE'?'Sesión persistida; no equivale a un parcial guardado para análisis.':'Registro confirmado por servidor.');
        return $out;
    }
}
