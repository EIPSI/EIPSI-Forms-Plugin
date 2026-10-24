<?php
namespace EIPSI\StudyEngine\Management;
use EIPSI\StudyEngine\Persistence as P;
use EIPSI\StudyEngine\Privacy\Policy;
require_once dirname(__DIR__).'/privacy/Policy.php';
/** One scientific row implementation for Management and the external read boundary. */
final class DatasetLimit extends \DomainException {}
final class ScientificDatasetOwner extends ScientificReadQuery {
    const COLUMNS=Exports::COLUMNS;
    const MAX_DEFINITIONS_BYTES=4194304;
    const MAX_EXECUTION_BYTES=2097152;
    const MAX_ROWS=10000;
    const MAX_CSV_BYTES=8388608;
    private $snapshotDefinitions=array();private $bounded=false;private $nativeByExecution=array();private $withdrawals=array();private $instances=array();
    private function __construct(string $study,bool $bounded=false,?Query $q=null){
        if($q){$this->db=$q->db;$this->study=$q->study;$this->versions=$q->versions;$this->nodes=$q->nodes;return;}
        $this->bounded=$bounded;
        if($bounded){global $wpdb;$db=new P\Database($wpdb);$size=$db->row($db->prepare('SELECT COUNT(*) n,COALESCE(SUM(OCTET_LENGTH(protocol_json)+OCTET_LENGTH(metadata_json)),0) bytes FROM '.$db->table('study_versions').' WHERE study_id=%s',$study));if((int)$size['n']>128||(int)$size['bytes']>self::MAX_DEFINITIONS_BYTES)throw new DatasetLimit('EXPORT_LIMIT_EXCEEDED');}
        parent::__construct($study);
    }
    public static function forAdmin(Query $q): self {
        if(!\EIPSI_Admin_Authorization_Policy::allows('responses.view'))throw new \DomainException('BUILDER_FORBIDDEN');
        return new self($q->study,false,$q);
    }
    public static function external(\EIPSI\ExternalApi\ExternalPrincipal $p,string $code,callable $read){
        $policy=new \EIPSI\ExternalApi\ExternalApiPolicy();
        return $policy->run($p,'read:data',null,function($fresh)use($code,$read){
            $s=new \EIPSI\ExternalApi\Store();$grant=$fresh->context()['grant_mode'];$where=$grant==='ALL'?'1=1':($grant==='SELECTED'?$s->prepare('EXISTS (SELECT 1 FROM '.$s->table('grants').' g WHERE g.key_id=%s AND g.study_id=v.study_id)',$fresh->id()):'1=0');
            global $wpdb;$table='`'.$wpdb->prefix.'eipsi_engine_study_versions`';
            $r=$s->row($s->prepare("SELECT v.study_id FROM $table v WHERE $where AND v.version_number=(SELECT MIN(f.version_number) FROM $table f WHERE f.study_id=v.study_id) AND BINARY JSON_UNQUOTE(JSON_EXTRACT(v.protocol_json,'$.study.public_id'))=BINARY %s LIMIT 1",$code));
            if(!$r)throw new \EIPSI\ExternalApi\ApiError('eipsi_external_study_unavailable',404);
            return $read(new self($r['study_id'],true),$fresh);
        });
    }
    /** Snapshot workers are not users; this narrow boundary never grants admin capabilities. */
    public static function snapshotWorker(string $study): self { return new self($study,true); }
    public static function sourceHash(array $row): string {
        // Administrative archive/pause/lifecycle metadata does not change a pinned scientific version.
        if(isset($row['protocol_hash'],$row['protocol_json']))$row=array_intersect_key($row,array_flip(array('id','study_id','definition_id','version_number','protocol_json','protocol_hash','graph_hash','compiler_contract_version')));
        ksort($row,SORT_STRING);return hash('sha256',json_encode($row,JSON_THROW_ON_ERROR)); }
    private function sourceRows(string $table,array $ids): array {
        if(!$ids)return array();$db=$this->db;
        $where='id IN ('.implode(',',array_map(function($id)use($db){return $db->prepare('%s',$id);},$ids)).')';
        return $this->boundedSourceRows($table,$where);
    }
    private function boundedSourceRows(string $table,string $where): array {
        $blobColumns=array('study_executions'=>array('checkpoint_json','create_result_json','seed_ciphertext'),'study_versions'=>array('protocol_json','metadata_json'),'native_executions'=>array('binding_json','final_json','partial_json','metadata_json'),'native_versions'=>array('definition_json'),'block_executions'=>array('snapshot_json','occurrence_json','provenance_json'));
        if(!isset($blobColumns[$table]))throw new \LogicException('Invalid source table');
        $db=$this->db;$sum=implode('+',array_map(static function($c){return 'COALESCE(OCTET_LENGTH('.$c.'),0)';},$blobColumns[$table]));
        $size=$db->row('SELECT COUNT(*) n,COALESCE(SUM('.$sum.'),0) bytes FROM '.$db->table($table).' WHERE '.$where);
        if((int)$size['n']>10000||(int)$size['bytes']>16777216)throw new DatasetLimit('EXPORT_SOURCE_LIMIT');
        return $db->rows('SELECT * FROM '.$db->table($table).' WHERE '.$where.' ORDER BY id');
    }
    /** Must be consumed inside the short REPEATABLE READ creation transaction. */
    public function sourceManifest(): \Generator {
        $db=$this->db;$after='';$count=0;
        do {
            $hints=$db->rows('SELECT e.id FROM '.$db->table('study_executions').' e WHERE '.$this->scope(array(),true).$db->prepare(' AND e.id>%s ORDER BY e.id LIMIT 10',$after));$executions=$this->sourceRows('study_executions',array_column($hints,'id'));
            if(!$executions)break;
            $ids=implode(',',array_map(function($e)use($db){return $db->prepare('%s',$e['id']);},$executions));
            $groups=array();foreach(array('block_executions'=>'execution_id','native_executions'=>'management_execution')as$table=>$field){
                if($table==='native_executions'&&!$this->nativeExists())continue;
                foreach($this->boundedSourceRows($table,$field.' IN ('.$ids.')')as$r)$groups[$r[$field]][$table][$r['id']]=$r;
            }
            $nativeIds=array();foreach($groups as$g)foreach($g['native_executions']??array()as$n)$nativeIds[$n['version_id']]=true;
            $definitions=array_column($this->sourceRows('native_versions',array_keys($nativeIds)),null,'id');
            foreach($executions as$e){
                if(++$count>100000)throw new DatasetLimit('EXPORT_SOURCE_LIMIT');
                if(($this->versions[$e['study_version_id']]['state']??'DRAFT')==='DRAFT')throw new P\IntegrityFailure('DATA_ON_DRAFT_VERSION');
                $refs=array('study_executions'=>array($e['id']=>self::sourceHash($e)),'study_versions'=>array($e['study_version_id']=>self::sourceHash($this->versions[$e['study_version_id']])), 'native_versions'=>array());
                foreach($groups[$e['id']]??array()as$table=>$records)foreach($records as$id=>$r){$refs[$table][$id]=self::sourceHash($r);if($table==='native_executions'){$v=$definitions[$r['version_id']]??null;if(!$v)throw new P\IntegrityFailure('SNAPSHOT_DEFINITION_MISSING');$refs['native_versions'][$v['id']]=self::sourceHash($v);}}
                yield array('execution_id'=>$e['id'],'references'=>$refs);$after=$e['id'];
            }
        }while(count($executions)===10);
    }
    /** Only manifest-listed sources enter this projection. Later inserts are never consulted. */
    public function materializeSource(array $refs,bool $partials): array {
        $records=array();
        foreach($refs as$table=>$hashes){
            if(!in_array($table,array('study_versions','study_executions','block_executions','native_executions','native_versions'),true))throw new P\IntegrityFailure('SNAPSHOT_INVALID_SOURCE');
            $records[$table]=array_column($this->sourceRows($table,array_keys($hashes)),null,'id');
            foreach($hashes as$id=>$hash)if(!isset($records[$table][$id])||!hash_equals($hash,self::sourceHash($records[$table][$id])))throw new P\IntegrityFailure('SNAPSHOT_SOURCE_CHANGED');
        }
        $e=array_values($records['study_executions'])[0];$version=$records['study_versions'][$e['study_version_id']];
        $this->versions=array($version['id']=>$version);$this->nodes=array($version['id']=>array_column(P\Database::json($version['protocol_json'])['nodes'],null,'id'));
        $this->snapshotDefinitions=$records['native_versions']??array();$this->executionCache=array($e['id']=>$e);$this->nativeCache=array();$this->nativeByExecution=array($e['id']=>array());$this->blockCache=$records['block_executions']??array();$this->withdrawals=array($e['id']=>null);
        $this->instances=array($e['id']=>P\BlockExecutionRepository::decodeRows(array_values($this->blockCache),$e['id'],$e['study_version_id']));
        foreach($records['native_executions']??array()as$n){$v=$records['native_versions'][$n['version_id']];$n['definition_json']=$v['definition_json'];$n['definition_hash']=$v['definition_hash'];$this->nativeCache[$n['id']]=$n;$this->nativeByExecution[$e['id']][]=$n;}
        $rows=$this->executionRows($e,array('include_partials'=>$partials));
        usort($rows,static function($a,$b){return strcmp(self::rowPosition($a),self::rowPosition($b));});
        return array('items'=>$rows,'dictionary'=>$this->dictionaryForRows($rows));
    }
    private function nativeIds(string $execution,bool $partials): array {
        if(array_key_exists($execution,$this->nativeByExecution))return array_values(array_filter($this->nativeByExecution[$execution],static function($n)use($partials){return in_array($n['state'],array('COMPLETED','LOGICAL_EXIT'),true)||($partials&&$n['state']==='PARTIAL_SAVED');}));
        return $this->db->rows($this->db->prepare('SELECT id FROM '.$this->db->table('native_executions')." WHERE management_execution=%s AND state IN ('COMPLETED','LOGICAL_EXIT'".($partials?",'PARTIAL_SAVED'":'').') ORDER BY id',$execution));
    }
    /** Batch hydration: queries depend on batches/attempts, never on response count. */
    private function prime(array $executions): void {
        $this->executionCache=array_column($executions,null,'id');$this->nativeCache=array();$this->blockCache=array();$this->nativeByExecution=array();$this->withdrawals=array();$this->instances=array();
        if(!$executions)return;$db=$this->db;$ids=implode(',',array_map(function($e)use($db){return $db->prepare('%s',$e['id']);},$executions));
        foreach($executions as $e){$this->nativeByExecution[$e['id']]=array();$this->withdrawals[$e['id']]=null;}
        foreach($db->rows('SELECT execution_id,data_policy FROM '.$db->table('withdrawal_records').' WHERE execution_id IN ('.$ids.')')as$w)$this->withdrawals[$w['execution_id']]=$w;
        if($this->nativeExists()){
        $sizes=$db->row('SELECT COUNT(*) n,COALESCE(SUM(OCTET_LENGTH(n.binding_json)+COALESCE(OCTET_LENGTH(n.final_json),0)+COALESCE(OCTET_LENGTH(n.partial_json),0)+COALESCE(OCTET_LENGTH(n.metadata_json),0)+OCTET_LENGTH(v.definition_json)),0) bytes FROM '.$db->table('native_executions').' n JOIN '.$db->table('native_versions').' v ON v.id=n.version_id WHERE n.management_execution IN ('.$ids.')');
        if((int)$sizes['n']>200||(int)$sizes['bytes']>self::MAX_EXECUTION_BYTES)throw new DatasetLimit('EXPORT_LIMIT_EXCEEDED');
        foreach($db->rows('SELECT n.*,v.definition_json,v.definition_hash FROM '.$db->table('native_executions').' n JOIN '.$db->table('native_versions').' v ON v.id=n.version_id WHERE n.management_execution IN ('.$ids.') ORDER BY n.id')as$n){$this->nativeCache[$n['id']]=$n;$this->nativeByExecution[$n['management_execution']][]=$n;}
        }
        // Binding validation requires only the source opportunity projections, never process events.
        $blockSize=$db->row('SELECT COUNT(*) n,COALESCE(SUM(OCTET_LENGTH(snapshot_json)),0) bytes FROM '.$db->table('block_executions').' WHERE execution_id IN ('.$ids.')');if((int)$blockSize['n']>10000||(int)$blockSize['bytes']>self::MAX_EXECUTION_BYTES)throw new DatasetLimit('EXPORT_LIMIT_EXCEEDED');
        $by=array();foreach($db->rows('SELECT * FROM '.$db->table('block_executions').' WHERE execution_id IN ('.$ids.') ORDER BY id')as$b){$this->blockCache[$b['id']]=$b;$by[$b['execution_id']][]=$b;}
        foreach($executions as$e)$this->instances[$e['id']]=P\BlockExecutionRepository::decodeRows($by[$e['id']]??array(),$e['id'],$e['study_version_id']);
        if(count($this->blockCache)>10000)throw new DatasetLimit('EXPORT_LIMIT_EXCEEDED');
    }
    public static function rowPosition(array $row): string {return $row['attempt_id']."\0".$row['response_key'];}
    /** Live keyset over execution ID and immutable attempt/response identifiers. No dataset materialization. */
    public function page(bool $partials,int $limit,array $position=array('execution'=>'','row'=>null)): array {
        $out=array();$bytes=0;$scanned=0;$last=$position;$db=$this->db;
        while($scanned<100){
            $w=$this->scope(array(),true).$db->prepare(' AND e.id'.($last['row']===null?'>':'>=').'%s',$last['execution']);
            $hints=$db->rows('SELECT e.id,OCTET_LENGTH(e.checkpoint_json) bytes FROM '.$db->table('study_executions').' e WHERE '.$w.' ORDER BY e.id LIMIT 20');
            if(!$hints)return array('items'=>$out,'position'=>null);
            $sum=0;foreach($hints as$h)$sum+=(int)$h['bytes'];if($sum>self::MAX_EXECUTION_BYTES)throw new DatasetLimit('EXPORT_LIMIT_EXCEEDED');
            $ids=implode(',',array_map(function($h)use($db){return $db->prepare('%s',$h['id']);},$hints));
            $executions=$db->rows('SELECT e.* FROM '.$db->table('study_executions').' e WHERE '.$w.' AND e.id IN ('.$ids.') ORDER BY e.id');$this->prime($executions);
            foreach($executions as$e){
                if(($this->versions[$e['study_version_id']]['state']??'DRAFT')==='DRAFT')throw new P\IntegrityFailure('DATA_ON_DRAFT_VERSION');
                $rows=$this->executionRows($e,array('include_partials'=>$partials));if(count($rows)>self::MAX_ROWS)throw new DatasetLimit('EXPORT_LIMIT_EXCEEDED');
                usort($rows,static function($a,$b){return strcmp(self::rowPosition($a),self::rowPosition($b));});
                foreach($rows as$row){$key=self::rowPosition($row);if($e['id']===$last['execution']&&$last['row']!==null&&strcmp($key,$last['row'])<=0)continue;
                    if(count($out)===$limit)return array('items'=>$out,'position'=>$last);
                    $bytes+=strlen(json_encode($row,JSON_THROW_ON_ERROR));if($bytes>self::MAX_CSV_BYTES)throw new DatasetLimit('EXPORT_LIMIT_EXCEEDED');
                    $out[]=$row;$last=array('execution'=>$e['id'],'row'=>$key);
                }
                $last=array('execution'=>$e['id'],'row'=>null);$scanned++;
            }
            if(count($hints)<20)return array('items'=>$out,'position'=>null);
        }
        return array('items'=>$out,'position'=>$last);
    }
    public function full(bool $partials): array {
        $out=array();$position=array('execution'=>'','row'=>null);$bytes=0;$pages=0;
        do{if(++$pages>100)throw new DatasetLimit('EXPORT_LIMIT_EXCEEDED');$page=$this->page($partials,1000,$position);foreach($page['items']as$row){$bytes+=strlen(json_encode($row,JSON_THROW_ON_ERROR));if(count($out)>=self::MAX_ROWS||$bytes>self::MAX_CSV_BYTES)throw new DatasetLimit('EXPORT_LIMIT_EXCEEDED');$out[]=$row;}$position=$page['position'];}while($position!==null);return $out;
    }
    public function historicalDictionary(bool $partials): array {
        $rows=$this->full($partials);return $this->dictionaryForRows($rows);
    }
    public function dictionaryForRows(array $rows): array {
        $out=array();$native=array();
        foreach($rows as$row){$key=implode('|',array($row['study_version_id'],$row['native_version_id']??'', $row['assessment_id'],$row['response_key'],$row['definition_hash']));if(isset($out[$key]))continue;
            if($row['native_version_id']!==null){$id=$row['native_version_id'];if(!isset($native[$id])){$r=$this->snapshotDefinitions[$id]??$this->db->row($this->db->prepare('SELECT definition_json,definition_hash FROM '.$this->db->table('native_versions').' WHERE id=%s',$id));if(!$r||!hash_equals($row['definition_hash'],$r['definition_hash']))throw new P\IntegrityFailure('DICTIONARY_VERSION_MISSING');$native[$id]=self::dictionary(P\Database::json($r['definition_json']));}$d=$native[$id][$row['response_key']];}
            else{$node=$this->nodes[$row['study_version_id']][$row['item_id']];$d=array('options'=>$node['options'],'validation'=>$node['validation']);}
            $options=array();foreach($d['options']??array()as$o)$options[]=array('id'=>$o['id'],'label'=>strip_tags($o['label_html']??$o['label']??''),'value'=>$o['value']??$o['label']??null);
            $out[$key]=array_intersect_key($row,array_flip(array('study_version_id','assessment_id','native_version_id','definition_hash','item_id','response_key','variable_name','label','type')))+array('options'=>$options,'nullable'=>true,'validation'=>$d['validation']??array());
        }return array_values($out);
    }
    public function executionRows(array $e,array $f=array()): array {
        if($e['study_id']!==$this->study)throw new P\IntegrityFailure('DATASET_STUDY_MISMATCH');
        if(!Policy::usable($e))return array();
        $withdrawal=array_key_exists($e['id'],$this->withdrawals)?$this->withdrawals[$e['id']]:$this->db->row($this->db->prepare('SELECT data_policy FROM '.$this->db->table('withdrawal_records').' WHERE execution_id=%s',$e['id']));
        if(!Policy::usable($e,$withdrawal))return array();
        $base=array('study_id'=>$e['study_id'],'cycle'=>(int)$e['study_cycle'],'study_version_id'=>$e['study_version_id'],'participation_id'=>$e['id']);$db=$this->db;
        $out=array();if($this->nativeExists()){$natives=$this->nativeIds($e['id'],!empty($f['include_partials']));
            foreach($natives as $n){$a=$this->attempt($n['id'],false);if(!empty($f['opportunity'])&&$a['opportunity_id']!==$f['opportunity'])continue;$dictionary=$a['dictionary'];if(!empty($f['assessment'])){$r=$this->nativeRecord($n['id']);if(P\Database::json($r['definition_json'])['id']!==$f['assessment'])continue;}
                foreach($a['rows'] as $row){$d=$dictionary[$row['response_key']]??array();$values=$base+array('opportunity_id'=>$a['opportunity_id'],'occurrence'=>$a['occurrence'],'assessment_id'=>$a['assessment_id'],'native_version_id'=>$a['version_id'],'attempt_id'=>$a['id'],'attempt_state'=>$a['state'],'saved_at'=>$a['saved_at'],'item_id'=>$row['item_id']??null,'response_key'=>$row['response_key'],'variable_name'=>$row['variable_name'],'label'=>$d['label']??null,'type'=>$d['type']??null,'response_status'=>$row['response_status']??'NOT_RECORDED','value'=>$row['value'],'selected_option_id'=>$row['selected_option_id']??null,'definition_hash'=>$a['definition_hash'],'data_policy'=>$e['data_policy']);$out[]=array_replace(array_fill_keys(self::COLUMNS,null),$values);}
            }
        }
        // Domain-native Study responses (without a Native Assessment binding).
        $v=$this->versions[$e['study_version_id']];$d=P\Database::json($v['protocol_json']);$nodes=array_column($d['nodes'],null,'id');$s=P\Database::json($e['checkpoint_json'])['state'];if(empty($s['responses']))return $out;$s['instances']=$this->instances[$e['id']]??(new P\BlockExecutionRepository($db))->load($e['id'],$e['study_version_id']);
        foreach($s['responses']??array() as $block=>$responses){$b=$s['instances'][$block]??null;if(!$b||isset($nodes[$b['definition_id']]['metadata']['native_version_id']))continue;if(!empty($f['opportunity'])&&$block!==$f['opportunity'])continue;if(!empty($f['assessment'])&&$b['definition_id']!==$f['assessment'])continue;if($b['state']!=='COMPLETED'&&empty($f['include_partials']))continue;
            foreach($responses as $qid=>$answer){$q=$nodes[$qid]??null;if(!$q)throw new P\IntegrityFailure('EXPORT_QUESTION_MISSING');$content=json_decode($q['content']['snapshot']??'',true);$dict=array('study_version_id'=>$e['study_version_id'],'native_version_id'=>null,'definition_hash'=>$q['content']['sha256'],'response_key'=>$q['response_key'],'item_id'=>$qid,'variable_name'=>$q['response_key'],'label'=>$content['label']??$qid,'type'=>$q['type'],'options'=>$q['options'],'validation'=>$q['validation']);$out[]=array_replace(array_fill_keys(self::COLUMNS,null),$base+array('opportunity_id'=>$block,'occurrence'=>$b['occurrence_key'],'assessment_id'=>$b['definition_id'],'attempt_id'=>$block,'attempt_state'=>$b['state'],'saved_at'=>$b['completed_at'],'item_id'=>$qid,'response_key'=>$q['response_key'],'variable_name'=>$q['response_key'],'label'=>$dict['label'],'type'=>$q['type'],'response_status'=>$answer['value']===null?'NO_VALUE_RECORDED':'VALUE_RECORDED','value'=>$answer['value'],'definition_hash'=>$dict['definition_hash'],'data_policy'=>$e['data_policy']));}
        }return $out;

    }
}
