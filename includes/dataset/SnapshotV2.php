<?php
namespace EIPSI\Dataset;
use EIPSI\ExternalApi\ExportStorage;
use EIPSI\StudyEngine\Contracts\Canonical as C;
/** V2 rendering over API-2B sources/chunks. No independent scientific pivot. */
final class SnapshotV2 {
    public static function spec(array $body): array {
        if(array_diff(array_keys($body),array('contract','format','include_partials','filters','annexes'))||!in_array($body['format']??null,array('json','csv','xlsx'),true)||isset($body['filters'])&&!is_array($body['filters'])||isset($body['annexes'])&&!is_array($body['annexes']))throw new Conflict('DATASET_FILTER_INVALID');
        $f=$body['filters']??array();if(array_key_exists('include_partials',$body)){if(!is_bool($body['include_partials']))throw new Conflict('DATASET_FILTER_INVALID');if(isset($f['include_partials'])&&$f['include_partials']!==$body['include_partials'])throw new Conflict('DATASET_FILTER_INVALID');$f['include_partials']=$body['include_partials'];}
        $selected=Transport::selected($body['annexes']??array());if(in_array('identities',$selected,true))throw new Conflict('DATASET_IDENTITIES_FORBIDDEN');
        return array('contract'=>CanonicalStudyDataset::CONTRACT,'filters'=>CanonicalStudyDataset::filters($f),'annexes'=>$selected);
    }
    public static function isV2(array $r): bool {return (json_decode($r['operations_json']??'[]',true)['dataset']['contract']??null)===CanonicalStudyDataset::CONTRACT;}
    public static function stage(ExportStorage $storage,array $r,int $sequence,array $bundle): int {
        $selected=json_decode($r['operations_json'],true)['dataset']['annexes'];foreach(array_unique(array_merge(array('data','dictionary','warnings'),$selected))as$key){$items=$bundle[$key]??array();$kind=$key==='data'?'rows':($key==='dictionary'?'dictpart':self::kind($key));$batch=array();$bytes=2;$part=0;$base=$key==='data'?(int)$r['chunk_count']+1:$sequence*10000;
            foreach($items as$row){$size=strlen(C::json($row))+1;if($size>ExportStorage::CHUNK_BYTES/2)throw new Conflict('DATASET_RECORD_LIMIT');if($bytes+$size>ExportStorage::CHUNK_BYTES/2){$storage->chunk($r['id'],$kind,$base+$part++,C::json($batch),count($batch));$batch=array();$bytes=2;}$batch[]=$row;$bytes+=$size;}
            if($batch||$key==='data')$storage->chunk($r['id'],$kind,$base+$part,C::json($batch),count($batch));
            if($key==='data')$rowCount=(int)$r['chunk_count']+$part+1;
        }
        return $rowCount;
    }
    private static function kind(string $key): string {return array('response_status'=>'statuses','bindings'=>'bindings','process_metadata'=>'process','randomization'=>'random','audit'=>'audit','identities'=>'identities','warnings'=>'warnings')[$key];}
    public static function rows(ExportStorage $storage,string $id,string $kind): \Generator {foreach($storage->chunks($id,$kind)as$chunk)foreach(json_decode($chunk['payload'],true,512,JSON_THROW_ON_ERROR)as$row)yield $row;}
    public static function finish(ExportStorage $storage,array $r): array {
        $spec=json_decode($r['operations_json'],true)['dataset'];$dictionary=array();$dictionaryBytes=0;foreach(self::rows($storage,$r['id'],'dictpart')as$variant){$encoded=C::json($variant);$key=hash('sha256',$encoded);if(isset($dictionary[$key]))continue;$dictionaryBytes+=strlen($encoded)+1;if($dictionaryBytes>ExportStorage::CHUNK_BYTES-2)throw new Conflict('DATASET_DICTIONARY_LIMIT');$dictionary[$key]=$variant;}$schema=Source::schema(array_values($dictionary));
        $dictionaryPayload=C::json($schema['dictionary']);if(strlen($dictionaryPayload)>ExportStorage::CHUNK_BYTES)throw new Conflict('DATASET_DICTIONARY_LIMIT');$storage->chunk($r['id'],'dictionary',0,$dictionaryPayload,count($schema['dictionary']));
        $manifest=array('contract'=>CanonicalStudyDataset::CONTRACT,'study_id'=>$r['study_id'],'study_code'=>$r['study_code'],'as_of'=>$r['as_of'],'generated_at'=>gmdate('Y-m-d H:i:s',(int)$r['created_at']),'timezone'=>'UTC','filters'=>$spec['filters'],'include_partials'=>$spec['filters']['include_partials'],'selected_annexes'=>$spec['annexes'],'rows'=>(int)$r['row_count'],'variables'=>count($schema['columns'])-count(CanonicalStudyDataset::CONTEXT),'columns'=>$schema['columns'],'warnings'=>$schema['warnings'],'conflicts'=>array());
        $annexColumns=array();foreach($spec['annexes']as$key){$cols=array();if($key==='data')$cols=$schema['columns'];elseif($key==='dictionary')$cols=Transport::columns(array('dictionary'=>$schema['dictionary']),'dictionary');else foreach(self::rows($storage,$r['id'],self::kind($key))as$row)foreach(array_keys($row)as$c)$cols[$c]=$c;$annexColumns[$key]=array_values($cols);}
        $stream=fopen('php://temp/maxmemory:1048576','w+b');
        try {
            if($r['format']==='csv')Transport::csv($stream,self::rows($storage,$r['id'],'rows'),$schema['columns']);
            elseif($r['format']==='json'){
                fwrite($stream,'{"manifest":'.C::json($manifest));foreach($spec['annexes']as$key){fwrite($stream,','.C::json($key).':[');$first=true;$iterator=$key==='dictionary'?$schema['dictionary']:self::rows($storage,$r['id'],$key==='data'?'rows':self::kind($key));foreach($iterator as$row){if($key==='data')$row=array_replace(array_fill_keys($schema['columns'],null),$row);fwrite($stream,($first?'':',').C::json($row));$first=false;}fwrite($stream,']');}fwrite($stream,'}');
            } else {
                $sheets=array();foreach($spec['annexes']as$key)$sheets[$key]=array('columns'=>$annexColumns[$key],'rows'=>$key==='dictionary'?$schema['dictionary']:self::rows($storage,$r['id'],$key==='data'?'rows':self::kind($key)));
                $sheets['manifest']=array('columns'=>array('field','value'),'rows'=>array_map(static function($k,$v){return array('field'=>$k,'value'=>$v);},array_keys($manifest),array_values($manifest)));fclose($stream);$stream=Transport::workbook($sheets);
            }
            rewind($stream);$chunks=array();$hash=hash_init('sha256');$bytes=0;$sequence=0;while(!feof($stream)){$payload=fread($stream,ExportStorage::CHUNK_BYTES);if($payload===false)throw new \RuntimeException('DATASET_RENDER_FAILED');if($payload==='')break;$chunks[]=$storage->chunk($r['id'],'download',$sequence++,$payload,0);hash_update($hash,$payload);$bytes+=strlen($payload);}
            $rowChunks=array();foreach($storage->chunks($r['id'],'rows')as$c)$rowChunks[]=array('sequence'=>(int)$c['sequence'],'byte_count'=>(int)$c['byte_count'],'sha256'=>$c['sha256'],'row_count'=>(int)$c['row_count']);
            return $manifest+array('byte_count'=>$bytes,'chunk_count'=>count($chunks),'chunks'=>$chunks,'row_chunks'=>$rowChunks,'sha256'=>hash_final($hash),'dictionary_sha256'=>hash('sha256',$dictionaryPayload),'dictionary_contract'=>'EIPSI_EXTERNAL_DICTIONARY_V2');
        } finally {if(is_resource($stream))fclose($stream);}
    }
}
