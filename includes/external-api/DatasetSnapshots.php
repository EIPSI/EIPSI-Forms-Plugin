<?php
namespace EIPSI\ExternalApi;
use EIPSI\StudyEngine\Management\ScientificDatasetOwner as Science;
/** Derived export lifecycle. Scientific reads always delegate to the API-2A/Management owner. */
final class DatasetSnapshots {
    private $s;private $storage;private $clock;private $observer;private $operationStarted;
    public function __construct(?callable $clock=null,?callable $observer=null){$this->observer=$observer;$this->operationStarted=microtime(true);$this->s=new Store();$this->storage=new ExportStorage($this->s);$this->clock=$clock??static function(){return time();};}
    private function now(): int {return (int)call_user_func($this->clock);}
    private static function science(): void {require_once dirname(__DIR__).'/study-engine/management.php';}
    private function audit(string $event,array $r,string $result='OK'): void {$this->s->audit($event,$r['key_id'],0,Authenticator::requestId(),$this->now(),array('export_id'=>$r['id'],'study_ref'=>$r['study_id'],'returned_count'=>(int)$r['row_count'],'bytes'=>(int)$r['byte_count'],'result'=>$result,'duration_ms'=>(int)round((microtime(true)-$this->operationStarted)*1000)),$event==='EXPORT_STARTED');}
    private function reached(string $point): void {if($this->observer)call_user_func($this->observer,$point);}
    public function create(ExternalPrincipal $p,string $code,array $body): array {
        \EIPSI_Rate_Limiter::consume('external_export_create',$p->id(),6,60,$this->now());
        self::science();$v2=($body['contract']??null)===\EIPSI\Dataset\CanonicalStudyDataset::CONTRACT;$dataset=null;if($v2){try{$dataset=\EIPSI\Dataset\SnapshotV2::spec($body);}catch(\EIPSI\Dataset\Conflict $e){throw new ApiError('eipsi_external_invalid_filters',400);}$body['include_partials']=$dataset['filters']['include_partials'];}
        if(!$v2&&(array_diff(array_keys($body),array('format','include_partials'))||!isset($body['format'])||!in_array($body['format'],array('json','csv'),true)||isset($body['include_partials'])&&!is_bool($body['include_partials'])||array_key_exists('include_partials',$body)&&$body['include_partials']===null))throw new ApiError('eipsi_external_invalid_filters',400);
        if(!preg_match('/^[A-Za-z][A-Za-z0-9_.:-]{0,127}$/D',$code))throw new ApiError('eipsi_external_study_unavailable',404);
        self::science();$s=$this->s;
        try{return $this->storage->exclusive(function()use($p,$code,$body,$s,$dataset){$s->rows('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');return Science::external($p,$code,function($owner,$fresh)use($body,$code,$s,$dataset){
            $now=$this->now();$settings=OperationsSettings::get();$n=$s->row($s->prepare("SELECT COUNT(*) active,COALESCE(SUM(state='PREPARING'),0) preparing FROM ".$s->table('exports')." WHERE key_id=%s AND state IN ('PREPARING','READY') AND expires_at>%d",$fresh->id(),$now));
            if((int)$n['active']>=$settings['active_per_key']||(int)$n['preparing']>=$settings['preparing_per_key'])throw new ApiError('eipsi_external_export_quota',429);
            $global=$s->row($s->prepare("SELECT COUNT(*) n FROM ".$s->table('exports')." WHERE state='PREPARING' AND expires_at>%d",$now));if((int)$global['n']>=$settings['global_preparing'])throw new ApiError('eipsi_external_export_quota',429);
            $id=bin2hex(random_bytes(16));$asof=$fresh->readViewAt();if($asof===null)throw new \LogicException('Snapshot read view missing');
            $s->rows($s->prepare('INSERT INTO '.$s->table('exports')." (id,key_id,study_id,study_code,format,include_partials,state,as_of,created_at,expires_at,operations_json,last_progress_at) VALUES (%s,%s,%s,%s,%s,%d,'PREPARING',%s,%d,%d,%s,%d)",$id,$fresh->id(),$owner->study,$code,$body['format'],!empty($body['include_partials'])?1:0,$asof,$now,$now+$settings['export_ttl'],json_encode(array('revision'=>$settings['revision'],'export_bytes'=>$settings['export_bytes'])+($dataset?array('dataset'=>$dataset):array())),$now));
            $seq=0;$sourceBytes=0;$capacity=$s->row('SELECT COALESCE(SUM(storage_bytes),0) bytes FROM '.$s->table('exports'));$started=microtime(true);foreach($owner->sourceManifest()as$source){$this->reached('source_captured');if(microtime(true)-$started>10)throw new ApiError('eipsi_external_export_too_large',422);$refs=$source['references'];if($dataset)$refs['__canonical_annexes']=\EIPSI\Dataset\Source::capture($owner,$source['execution_id'],$dataset['annexes']);$references=json_encode($refs,JSON_THROW_ON_ERROR);$sourceBytes+=strlen($references);if($sourceBytes>$settings['export_bytes'])throw new ApiError('eipsi_external_export_too_large',422);if($sourceBytes+(int)$capacity['bytes']>$settings['aggregate_bytes'])throw new ApiError('eipsi_external_export_capacity',503);$s->rows($s->prepare('INSERT INTO '.$s->table('export_sources').' (export_id,sequence,execution_id,references_json) VALUES (%s,%d,%s,%s)',$id,$seq++,$source['execution_id'],$references));}
            $this->storage->update($id,array('source_count'=>$seq,'storage_bytes'=>$sourceBytes));$r=$this->storage->get($id);$this->audit('EXPORT_CREATED',$r);return $this->summary($r);
        });});}catch(\EIPSI\StudyEngine\Management\DatasetLimit $e){throw new ApiError('eipsi_external_export_too_large',422);}
    }
    private function summary(array $r): array {
        $base=rest_url(Rest::NS.'/exports/'.$r['id']);$out=array('export_id'=>$r['id'],'status'=>$r['state'],'study_code'=>$r['study_code'],'dataset_contract'=>(\EIPSI\Dataset\SnapshotV2::isV2($r)?'EIPSI_STUDY_DATA_V2':'EIPSI_STUDY_DATA_V1'),'format'=>$r['format'],'include_partials'=>(bool)$r['include_partials'],'as_of'=>$r['as_of'],'created_at'=>gmdate('Y-m-d\TH:i:s\Z',(int)$r['created_at']),'expires_at'=>gmdate('Y-m-d\TH:i:s\Z',(int)$r['expires_at']),'status_url'=>$base);
        if($r['state']==='READY')$out+=array('row_count'=>(int)$r['row_count'],'byte_count'=>(int)$r['byte_count'],'download_url'=>$base.'/download','dictionary_url'=>$base.'/dictionary','manifest_url'=>$base.'/manifest');
        if($r['state']==='FAILED'){$out['error_code']=$r['error_code']?:'eipsi_external_export_failed';if(\EIPSI\Dataset\SnapshotV2::isV2($r)){$diagnostic=json_decode($r['manifest_json']??'null',true);if(isset($diagnostic['conflicts']))$out['conflicts']=$diagnostic['conflicts'];}}return $out;
    }
    /** Lock order: enabled -> key -> export. No ownership/grant enumeration. */
    private function authorized(ExternalPrincipal $p,string $id,callable $fn){
        ExportStorage::id($id);
        return (new ExternalApiPolicy(null,$this->clock))->run($p,'read:data',null,function($fresh)use($id,$fn){
            $r=$this->storage->get($id,true);$ctx=$fresh->context();
            // Resource absence, another owner and a removed grant are deliberately indistinguishable.
            if(!$r||$r['key_id']!==$fresh->id()||!$this->s->studyExists($r['study_id'])||($ctx['grant_mode']!=='ALL'&&($ctx['grant_mode']!=='SELECTED'||!in_array($r['study_id'],$ctx['studies'],true))))throw new ApiError('eipsi_external_export_unavailable',404);
            if((int)$r['expires_at']<=$this->now()&&$r['state']!=='EXPIRED'){$this->storage->update($id,array('state'=>'EXPIRED'));$r['state']='EXPIRED';$this->audit('EXPORT_EXPIRED',$r);}
            return $fn($r,$fresh);
        });
    }
    public function status(ExternalPrincipal $p,string $id): array {
        \EIPSI_Rate_Limiter::consume('external_export_status',$p->id(),90,60,$this->now());return $this->authorized($p,$id,function($r){return $this->summary($r);});
    }
    private static function ready(array $r): void {
        $errors=array('PREPARING'=>array('eipsi_external_export_preparing',409),'EXPIRED'=>array('eipsi_external_export_expired',410),'REVOKED'=>array('eipsi_external_snapshot_invalidated',410),'FAILED'=>array('eipsi_external_export_failed',409));
        if($r['state']!=='READY'){$e=$errors[$r['state']]??array('eipsi_external_export_unavailable',404);throw new ApiError($e[0],$e[1]);}
    }
    public function manifest(ExternalPrincipal $p,string $id): array {\EIPSI_Rate_Limiter::consume('external_export_pages',$p->id(),60,60,$this->now());return $this->authorized($p,$id,function($r){self::ready($r);return json_decode($r['manifest_json'],true,512,JSON_THROW_ON_ERROR);});}
    public function dictionary(ExternalPrincipal $p,string $id): array {
        \EIPSI_Rate_Limiter::consume('external_export_pages',$p->id(),60,60,$this->now());return $this->authorized($p,$id,function($r){self::ready($r);$manifest=json_decode($r['manifest_json'],true,512,JSON_THROW_ON_ERROR);$chunks=iterator_to_array($this->storage->chunks($r['id'],'dictionary'));if(count($chunks)!==1||!hash_equals($manifest['dictionary_sha256'],hash('sha256',$chunks[0]['payload'])))throw new ApiError('eipsi_external_export_failed',503);$items=json_decode($chunks[0]['payload'],true,512,JSON_THROW_ON_ERROR);return array('contract'=>(\EIPSI\Dataset\SnapshotV2::isV2($r)?'EIPSI_EXTERNAL_DICTIONARY_V2':'EIPSI_EXTERNAL_DICTIONARY_V1'),'dataset_contract'=>(\EIPSI\Dataset\SnapshotV2::isV2($r)?'EIPSI_STUDY_DATA_V2':'EIPSI_STUDY_DATA_V1'),'export_id'=>$r['id'],'study_code'=>$r['study_code'],'as_of'=>$r['as_of'],'items'=>$items);});
    }
    public function data(ExternalPrincipal $p,string $id,array $params): array {
        \EIPSI_Rate_Limiter::consume('external_export_pages',$p->id(),60,60,$this->now());
        if(array_diff(array_keys($params),array('limit','cursor')))throw new ApiError('eipsi_external_invalid_filters',400);
        $limit=array_key_exists('limit',$params)?$params['limit']:500;if(!is_scalar($limit)||!preg_match('/^[1-9][0-9]{0,3}$/D',(string)$limit)||(int)$limit>1000)throw new ApiError('eipsi_external_invalid_filters',400);$limit=(int)$limit;
        return $this->authorized($p,$id,function($r,$fresh)use($params,$limit){self::ready($r);$pos=array_key_exists('cursor',$params)?SnapshotCursor::decode($params['cursor'],$fresh->id(),$r['id'],$this->now()):array(0,0);$items=array();$next=null;
            $s=$this->s;$seq=max(1,$pos[0]);$offset=$pos[1];$bytes=0;$manifest=json_decode($r['manifest_json'],true,512,JSON_THROW_ON_ERROR);$expectedChunks=array_column($manifest['row_chunks'],null,'sequence');
            while(count($items)<=$limit){$expected=$expectedChunks[$seq]??null;if(!$expected)break;$c=$s->row($s->prepare("SELECT * FROM ".$s->table('export_chunks')." WHERE export_id=%s AND kind='rows' AND sequence=%d",$r['id'],$seq));
                if(!$c||!hash_equals($expected['sha256'],hash('sha256',$c['payload']))||(int)$c['byte_count']!==$expected['byte_count'])throw new ApiError('eipsi_external_export_failed',503);$rows=json_decode($c['payload'],true,512,JSON_THROW_ON_ERROR);

                foreach($rows as$i=>$row){if((int)$c['sequence']===$seq&&$i<$offset)continue;if(count($items)===$limit||$bytes+strlen(json_encode($row))>4194304){$next=array((int)$c['sequence'],$i);break 2;}if(\EIPSI\Dataset\SnapshotV2::isV2($r))$row=array_replace(array_fill_keys($manifest['columns'],null),$row);$items[]=$row;$bytes+=strlen(json_encode($row));}
                $seq=(int)$c['sequence']+1;$offset=0;
            }
            return array('contract'=>(\EIPSI\Dataset\SnapshotV2::isV2($r)?'EIPSI_EXTERNAL_DATA_V2':'EIPSI_EXTERNAL_DATA_V1'),'dataset_contract'=>(\EIPSI\Dataset\SnapshotV2::isV2($r)?'EIPSI_STUDY_DATA_V2':'EIPSI_STUDY_DATA_V1'),'export_id'=>$r['id'],'study_code'=>$r['study_code'],'as_of'=>$r['as_of'],'items'=>$items,'next_cursor'=>$next===null?null:SnapshotCursor::encode($fresh->id(),$r['id'],$next,(int)$r['expires_at']));
        });
    }
    public function download(ExternalPrincipal $p,string $id,?string $range=null): ExportDownload {
        \EIPSI_Rate_Limiter::consume('external_export_download',$p->id(),20,60,$this->now());
        $r=$this->authorized($p,$id,function($r){self::ready($r);$this->storage->update($r['id'],array('download_until'=>$this->now()+300));return $r;});
        $free=@disk_free_space(sys_get_temp_dir());if($free!==false&&$free<(int)$r['byte_count']+67108864)throw new ApiError('eipsi_external_export_capacity',503);
        $stream=fopen('php://temp/maxmemory:1048576','w+b');if($stream===false)throw new ApiError('eipsi_external_export_failed',503);$started=microtime(true);
        try {
            $manifest=json_decode($r['manifest_json'],true,512,JSON_THROW_ON_ERROR);$hash=hash_init('sha256');$total=0;$count=0;
            foreach($this->storage->chunks($id,'download')as$c){if(microtime(true)-$started>60)throw new ApiError('eipsi_external_export_failed',503);$expected=$manifest['chunks'][$count]??null;
                if(!$expected||$expected['sequence']!==(int)$c['sequence']||!hash_equals($expected['sha256'],$c['sha256']))throw new ApiError('eipsi_external_export_failed',503);
                if(fwrite($stream,$c['payload'])!==strlen($c['payload']))throw new ApiError('eipsi_external_export_capacity',503);hash_update($hash,$c['payload']);$total+=strlen($c['payload']);$count++;
            }
            $sha=hash_final($hash);if($count!==$manifest['chunk_count']||$total!==$manifest['byte_count']||!hash_equals($manifest['sha256'],$sha))throw new ApiError('eipsi_external_export_failed',503);
            $download=new ExportDownload($stream,$total,$sha,(\EIPSI\Dataset\SnapshotV2::isV2($r)&&$r['format']==='json'?'json_bundle':$r['format']),$range);$this->reached('download_copied');
            // Recheck privacy/auth after copying, then the private handle outlives DB cleanup safely.
            // A request admitted before TTL may finish; subsequent requests must pass TTL again.
            $this->authorized($p,$id,function($current)use($r){if($current['state']==='REVOKED'||$current['state']==='FAILED')self::ready($current);$this->audit('EXPORT_DOWNLOAD',$r);});
            return $download;
        }catch(\Throwable $e){if(is_resource($stream))fclose($stream);$this->audit('EXPORT_DOWNLOAD_FAILED',$r,'FAILED');throw $e;}
    }
    /** Claim one bounded step. A crash leaves no partial DB chunk transaction visible. */
    public function work(string $id): bool {
        $this->operationStarted=microtime(true);self::science();$id=ExportStorage::id($id);
        return $this->storage->exclusive(function()use($id){
            $r=$this->storage->get($id);if(!$r||$r['state']!=='PREPARING'||(int)$r['expires_at']<=$this->now()||(int)$r['lease_until']>$this->now())return false;
            $p=new ExternalPrincipal(array('id'=>$r['key_id']),Authenticator::requestId(),$this->now());$token=bin2hex(random_bytes(16));
            try{
                $r=$this->authorized($p,$id,function($r)use($token){if($r['state']!=='PREPARING'||(int)$r['lease_until']>$this->now())return null;$this->storage->update($r['id'],array('lease_token'=>$token,'lease_until'=>$this->now()+120));$this->audit('EXPORT_STARTED',$r);return $r;});if(!$r)return false;$this->reached('claimed');
                $source=$this->s->row($this->s->prepare('SELECT * FROM '.$this->s->table('export_sources').' WHERE export_id=%s AND sequence=%d',$id,(int)$r['next_source']));
                if(!$source){$this->reached('before_finalize');$manifest=$this->finalize($r);$this->reached('before_ready');$this->commit($p,$id,$token,function($current)use($manifest){$json=json_encode($manifest,JSON_THROW_ON_ERROR);$this->storage->reserve($current['id'],strlen($json));$this->storage->update($current['id'],array('state'=>'READY','manifest_json'=>$json,'lease_token'=>null,'lease_until'=>0,'byte_count'=>$manifest['byte_count']));OperationsMetrics::record('maintenance',array('generated_bytes'=>$manifest['byte_count']),$this->now());$this->audit('EXPORT_READY',array_replace($current,array('byte_count'=>$manifest['byte_count'])));});return true;}
                if(\EIPSI\Dataset\SnapshotV2::isV2($r)){
                    $spec=json_decode($r['operations_json'],true)['dataset'];$bundle=\EIPSI\Dataset\Source::project(Science::snapshotWorker($r['study_id']),json_decode($source['references_json'],true,512,JSON_THROW_ON_ERROR),$spec['filters'],$r['study_code']);
                    $this->commit($p,$id,$token,function($current)use($bundle){$chunks=\EIPSI\Dataset\SnapshotV2::stage($this->storage,$current,(int)$current['next_source'],$bundle);$this->storage->update($current['id'],array('next_source'=>(int)$current['next_source']+1,'chunk_count'=>$chunks,'row_count'=>(int)$current['row_count']+count($bundle['data']),'lease_token'=>null,'lease_until'=>0,'failures'=>0,'last_progress_at'=>$this->now()));});return true;
                }
                $owner=Science::snapshotWorker($r['study_id']);$result=$owner->materializeSource(json_decode($source['references_json'],true,512,JSON_THROW_ON_ERROR),(bool)$r['include_partials']);
                $rows=array_map(array(StudyDataExport::class,'dates'),$result['items']);
                $this->commit($p,$id,$token,function($current)use($rows,$result){
                    $seq=(int)$current['chunk_count'];if($seq===0){$this->storage->chunk($current['id'],'download',0,$current['format']==='csv'?$this->csv(array_merge(array('study_code'),Science::COLUMNS)): '',0);$seq=1;}
                    $batch=array();$size=2;foreach($rows as$row){$n=strlen(json_encode($row,JSON_THROW_ON_ERROR))+1;if($n>ExportStorage::CHUNK_BYTES/2)throw new ApiError('eipsi_external_export_too_large',422);if($size+$n>ExportStorage::CHUNK_BYTES/2){$this->writeRows($current,$seq++,$batch);$batch=array();$size=2;}$batch[]=$row;$size+=$n;}if($batch)$this->writeRows($current,$seq++,$batch);
                    $dict=json_encode($result['dictionary'],JSON_THROW_ON_ERROR);$this->storage->chunk($current['id'],'dictpart',(int)$current['next_source'],$dict,count($result['dictionary']));
                    $this->storage->update($current['id'],array('next_source'=>(int)$current['next_source']+1,'chunk_count'=>$seq,'row_count'=>(int)$current['row_count']+count($rows),'lease_token'=>null,'lease_until'=>0,'failures'=>0,'last_progress_at'=>$this->now()));
                });return true;
            }catch(\Throwable $e){
                $this->s->transaction(function()use($id,$token,$e){$r=$this->storage->get($id,true);if(!$r||$r['state']!=='PREPARING'||($r['lease_token']!==null&&$r['lease_token']!==$token))return;
                    $auth=$e instanceof ApiError&&in_array($e->getCode(),array(401,403,404),true);$changed=$e instanceof \EIPSI\StudyEngine\Persistence\IntegrityFailure;$tooLarge=$e instanceof \EIPSI\StudyEngine\Management\DatasetLimit||$e instanceof ApiError&&$e->apiCode==='eipsi_external_export_too_large';$failures=(int)$r['failures']+1;$terminal=$auth||$changed||$tooLarge||$e instanceof \EIPSI\Dataset\Conflict||$failures>=3;
                    $state=$auth?'REVOKED':($terminal?'FAILED':'PREPARING');$code=$e instanceof \EIPSI\Dataset\Conflict?'eipsi_external_dataset_conflict':($tooLarge?'eipsi_external_export_too_large':($changed?'eipsi_external_snapshot_invalidated':'eipsi_external_export_failed'));$reason=Operations::failureReason($e,$failures);$updates=array('state'=>$state,'error_code'=>$code,'failure_reason'=>$reason,'failures'=>$failures,'lease_token'=>null,'lease_until'=>0);if($e instanceof \EIPSI\Dataset\Conflict)$updates['manifest_json']=json_encode(array('contract'=>\EIPSI\Dataset\CanonicalStudyDataset::CONTRACT,'conflicts'=>array(array('code'=>$e->getMessage(),'details'=>$e->details))),JSON_THROW_ON_ERROR);$this->storage->update($id,$updates);if($terminal)OperationsMetrics::record('maintenance',array('worker_failures'=>1),$this->now());if($terminal)$this->audit($auth?'EXPORT_REVOKED':'EXPORT_FAILED',$r,$code);
                });return false;
            }
        });
    }
    private function commit(ExternalPrincipal $p,string $id,string $token,callable $fn): void {
        $this->authorized($p,$id,function($r)use($token,$fn){if($r['state']!=='PREPARING'||$r['lease_token']!==$token||(int)$r['lease_until']<=$this->now())throw new ApiError('eipsi_external_snapshot_invalidated',409);$fn($r);});
    }
    private function csv(array $values): string {$f=fopen('php://temp','w+b');try{\EIPSI_Csv_Serializer::row($f,$values);rewind($f);return stream_get_contents($f);}finally{fclose($f);}}
    private function writeRows(array $r,int $seq,array $rows): void {
        $json=json_encode($rows,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$this->storage->chunk($r['id'],'rows',$seq,$json,count($rows));$data='';
        foreach($rows as$row){if($r['format']==='json'){$data.=json_encode($row,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";continue;}$values=array($r['study_code']);foreach(Science::COLUMNS as$key){$v=$row[$key];$values[]=is_array($v)?json_encode($v,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):(is_bool($v)?($v?'true':'false'):$v);}$data.=$this->csv($values);}
        $this->storage->chunk($r['id'],'download',$seq,$data,count($rows));$this->reached('chunk_written');
    }
    private function finalize(array $r): array {
        if(\EIPSI\Dataset\SnapshotV2::isV2($r))return \EIPSI\Dataset\SnapshotV2::finish($this->storage,$r);
        if((int)$r['next_source']!==(int)$r['source_count'])throw new \RuntimeException('EXPORT_SOURCE_GAP');
        if((int)$r['chunk_count']===0)$this->s->transaction(function()use($r){$this->storage->chunk($r['id'],'download',0,$r['format']==='csv'?$this->csv(array_merge(array('study_code'),Science::COLUMNS)):'',0);});
        $hash=hash_init('sha256');$bytes=0;$chunks=array();foreach($this->storage->chunks($r['id'],'download')as$c){hash_update($hash,$c['payload']);$bytes+=(int)$c['byte_count'];$chunks[]=array('sequence'=>(int)$c['sequence'],'row_count'=>(int)$c['row_count'],'byte_count'=>(int)$c['byte_count'],'sha256'=>$c['sha256'],'dataset_contract'=>(\EIPSI\Dataset\SnapshotV2::isV2($r)?'EIPSI_STUDY_DATA_V2':'EIPSI_STUDY_DATA_V1'),'export_id'=>$r['id']);if(count($chunks)>20000)throw new ApiError('eipsi_external_export_too_large',422);}
        $rowChunks=array();foreach($this->storage->chunks($r['id'],'rows')as$c)$rowChunks[]=array('sequence'=>(int)$c['sequence'],'row_count'=>(int)$c['row_count'],'byte_count'=>(int)$c['byte_count'],'sha256'=>$c['sha256']);
        $dictionary=array();foreach($this->storage->chunks($r['id'],'dictpart')as$c)foreach(json_decode($c['payload'],true,512,JSON_THROW_ON_ERROR)as$item){$key=hash('sha256',json_encode($item,JSON_THROW_ON_ERROR));$dictionary[$key]=$item;if(count($dictionary)>10000)throw new ApiError('eipsi_external_export_too_large',422);}
        $dict=json_encode(array_values($dictionary),JSON_THROW_ON_ERROR);$dictionaryHash=hash('sha256',$dict);$this->s->transaction(function()use($r,$dict,$dictionary){$this->storage->chunk($r['id'],'dictionary',0,$dict,count($dictionary));});
        return $this->summary(array_replace($r,array('state'=>'READY','byte_count'=>$bytes)))+array('filters'=>array('include_partials'=>(bool)$r['include_partials']),'chunk_count'=>count($chunks),'sha256'=>hash_final($hash),'chunks'=>$chunks,'row_chunks'=>$rowChunks,'dictionary_sha256'=>$dictionaryHash,'dictionary_contract'=>'EIPSI_EXTERNAL_DICTIONARY_V1');
    }
    public function tick(): void { (new Operations())->maintenance(); }
    public function processPending(int $seconds,int $steps): int {
        $s=$this->s;$rows=$s->rows($s->prepare("SELECT id FROM ".$s->table('exports')." WHERE state='PREPARING' AND expires_at>%d AND lease_until<=%d ORDER BY created_at LIMIT 16",$this->now(),$this->now()));
        $started=microtime(true);$done=0;do{$progress=false;foreach($rows as$r){$progress=$this->work($r['id'])||$progress;$done++;if(microtime(true)-$started>=$seconds||$done>=$steps)break;}}while($rows&&$progress&&microtime(true)-$started<$seconds&&$done<$steps);return $done;
    }
}
