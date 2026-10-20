<?php
namespace EIPSI\ExternalApi;
use EIPSI\StudyEngine\Management\ScientificDatasetOwner as Dataset;
use EIPSI\StudyEngine\Management\DatasetLimit;
require_once dirname(__DIR__).'/security/Csv.php';
/** Thin transport contract over the same scientific rows consumed by Management. */
final class StudyDataExport {
    private $clock;private $cursor;
    public function __construct(?callable $clock=null){$this->clock=$clock??static function(){return time();};$this->cursor=new DatasetCursor($this->clock);}
    public function read(ExternalPrincipal $p,$code,array $params=array(),bool $dictionary=false): array {
        require_once dirname(__DIR__).'/study-engine/management.php';
        $start=microtime(true);$ok=false;$count=0;$bytes=0;$studyRef='';$operation=$dictionary?'DICTIONARY':(($params['format']??'json')==='csv'?'DATA_CSV':'DATA_JSON');
        try{
            \EIPSI_Rate_Limiter::consume('external_data',$p->id(),30,60,(int)call_user_func($this->clock));
            if($operation!=='DATA_JSON')\EIPSI_Rate_Limiter::consume('external_data_bulk',$p->id(),5,60,(int)call_user_func($this->clock));
            if(!is_string($code)||!preg_match('/^[A-Za-z][A-Za-z0-9_.:-]{0,127}$/D',$code))throw new ApiError('eipsi_external_study_unavailable',404);
            $result=Dataset::external($p,$code,function(Dataset $owner,ExternalPrincipal $fresh)use($params,$dictionary,$code,&$count,&$studyRef){
                $studyRef=$owner->study;
                if(($params['contract']??null)===\EIPSI\Dataset\CanonicalStudyDataset::CONTRACT){$result=$this->canonical($owner,$fresh,$code,$params,$dictionary);$count=count($result['items']??array());return $result;}
                if(array_diff(array_keys($params),$dictionary?array('include_partials'):array('format','limit','cursor','include_partials')))throw new ApiError('eipsi_external_invalid_filters',400);
                $format=$dictionary?'json':(array_key_exists('format',$params)?$params['format']:'json');if(!is_string($format)||!in_array($format,array('json','csv'),true))throw new ApiError('eipsi_external_invalid_format',400);
                $partial=array_key_exists('include_partials',$params)?$params['include_partials']:'false';if(!in_array($partial,array('true','false'),true))throw new ApiError('eipsi_external_invalid_filters',400);$partial=$partial==='true';
                $asOf=gmdate('Y-m-d\TH:i:s\Z',(int)call_user_func($this->clock));
                if($dictionary){$items=$owner->historicalDictionary($partial);$count=count($items);return array('contract'=>'EIPSI_EXTERNAL_DICTIONARY_V1','dataset_contract'=>'EIPSI_STUDY_DATA_V1','study_code'=>$code,'as_of'=>$asOf,'items'=>$items);}
                if($format==='csv'){
                    if(array_key_exists('cursor',$params)||array_key_exists('limit',$params))throw new ApiError('eipsi_external_invalid_filters',400);
                    $rows=$owner->full($partial);$stream=fopen('php://temp','w+');try{\EIPSI_Csv_Serializer::row($stream,array_merge(array('study_code'),Dataset::COLUMNS));foreach($rows as$row){$cells=array($code);foreach(self::dates($row)as$v){if(is_bool($v))$v=$v?'true':'false';if(is_array($v))$v=json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$cells[]=$v;}\EIPSI_Csv_Serializer::row($stream,$cells);if(ftell($stream)>Dataset::MAX_CSV_BYTES)throw new DatasetLimit('EXPORT_LIMIT_EXCEEDED');}rewind($stream);$content=stream_get_contents($stream);}finally{fclose($stream);}
                    $count=count($rows);return array('csv'=>$content,'as_of'=>$asOf,'filename'=>'eipsi-'.preg_replace('/[^A-Za-z0-9_-]/','-',$code).'-data-'.gmdate('Ymd',(int)call_user_func($this->clock)).'.csv');
                }
                $limit=array_key_exists('limit',$params)?$params['limit']:500;
                if((!is_int($limit)&&(!is_string($limit)||!preg_match('/^[1-9][0-9]{0,3}$/D',$limit)))||(int)$limit<1||(int)$limit>1000)throw new ApiError('eipsi_external_invalid_filters',400);
                $position=array('execution'=>'','row'=>null);if(array_key_exists('cursor',$params))$position=$this->cursor->decode($params['cursor'],$fresh->id(),$owner->study,$partial);
                $page=$owner->page($partial,(int)$limit,$position);$items=array_map(array(self::class,'dates'),$page['items']);$count=count($items);
                return array('contract'=>'EIPSI_EXTERNAL_DATA_V1','dataset_contract'=>'EIPSI_STUDY_DATA_V1','study_code'=>$code,'as_of'=>$asOf,'items'=>$items,'next_cursor'=>$page['position']===null?null:$this->cursor->encode($fresh->id(),$owner->study,$partial,$page['position']));
            });
            $bytes=isset($result['csv'])?strlen($result['csv']):strlen(json_encode($result,JSON_THROW_ON_ERROR));if($bytes>Dataset::MAX_CSV_BYTES)throw new DatasetLimit('EXPORT_LIMIT_EXCEEDED');$ok=true;return $result;
        }catch(\EIPSI\Dataset\Conflict $e){if(in_array($e->getMessage(),array('DATASET_FILTER_INVALID','INVALID_RESPONSE_AT'),true))throw new ApiError('eipsi_external_invalid_filters',400);throw new ApiError('eipsi_external_dataset_conflict',422);}
        catch(DatasetLimit $e){throw new ApiError('eipsi_external_export_limit_exceeded',422);}
        catch(ApiError|\EIPSI_Rate_Limit_Exception $e){throw $e;}
        catch(\Throwable $e){throw new ApiError('eipsi_external_dataset_failed',503);}
        finally{(new Store())->audit($operation.'_'.($ok?'OK':'FAILED'),$p->id(),0,$p->context()['request_id'],(int)call_user_func($this->clock),array('study_ref'=>$studyRef,'returned_count'=>$ok?$count:0,'bytes'=>$ok?$bytes:0,'duration_ms'=>(int)round((microtime(true)-$start)*1000),'result'=>$ok?'OK':'FAILED'),true);}
    }
    private function canonical(Dataset $owner,ExternalPrincipal $principal,string $code,array $params,bool $dictionary): array {
        $allowed=array('contract','format','limit','cursor','include_partials','include_retained','version','cycle','execution','assessment','opportunity','response_from','response_to');if(array_diff(array_keys($params),$allowed))throw new ApiError('eipsi_external_invalid_filters',400);
        $filters=array_intersect_key($params,array_flip(array('include_partials','include_retained','version','cycle','execution','assessment','opportunity','response_from','response_to')));
        foreach(array('include_partials','include_retained')as$key)if(isset($filters[$key])){if(!in_array($filters[$key],array('true','false'),true))throw new ApiError('eipsi_external_invalid_filters',400);$filters[$key]=$filters[$key]==='true';}
        if(isset($filters['cycle'])){if(!is_scalar($filters['cycle'])||!preg_match('/^[1-9][0-9]{0,5}$/D',(string)$filters['cycle']))throw new ApiError('eipsi_external_invalid_filters',400);$filters['cycle']=(int)$filters['cycle'];}
        $filters=\EIPSI\Dataset\CanonicalStudyDataset::filters($filters);$bundle=\EIPSI\Dataset\Source::bundle($owner,$filters,$code);$asOf=gmdate('Y-m-d\TH:i:s\Z',(int)call_user_func($this->clock));
        if($dictionary)return array('contract'=>'EIPSI_EXTERNAL_DICTIONARY_V2','dataset_contract'=>\EIPSI\Dataset\CanonicalStudyDataset::CONTRACT,'study_code'=>$code,'as_of'=>$asOf,'items'=>$bundle['dictionary']);
        $format=$params['format']??'json';if(!in_array($format,array('csv','json'),true))throw new ApiError('eipsi_external_invalid_format',400);
        if($format==='csv'){if(isset($params['limit'])||isset($params['cursor']))throw new ApiError('eipsi_external_invalid_filters',400);$f=fopen('php://temp','w+b');try{\EIPSI\Dataset\Transport::csv($f,$bundle['data'],$bundle['columns']);rewind($f);$csv=stream_get_contents($f);}finally{fclose($f);}return array('csv'=>$csv,'as_of'=>$asOf,'filename'=>'eipsi-data-v2.csv');}
        $limit=$params['limit']??500;if(!is_scalar($limit)||!preg_match('/^[1-9][0-9]{0,3}$/D',(string)$limit)||(int)$limit>1000)throw new ApiError('eipsi_external_invalid_filters',400);$limit=(int)$limit;
        $binding=$owner->study.'|V2|'.hash('sha256',\EIPSI\StudyEngine\Contracts\Canonical::json($filters));$offset=0;if(isset($params['cursor'])){$pos=$this->cursor->decode($params['cursor'],$principal->id(),$binding,$filters['include_partials']);if(!preg_match('/^P_[0-9]{1,6}$/D',$pos['execution']))throw new ApiError('eipsi_external_invalid_cursor',400);$offset=(int)substr($pos['execution'],2);}
        $rows=array_slice($bundle['data'],$offset,$limit);$next=$offset+count($rows)<count($bundle['data'])?$this->cursor->encode($principal->id(),$binding,$filters['include_partials'],array('execution'=>'P_'.($offset+count($rows)),'row'=>null)):null;
        return array('contract'=>'EIPSI_EXTERNAL_DATA_V2','dataset_contract'=>\EIPSI\Dataset\CanonicalStudyDataset::CONTRACT,'study_code'=>$code,'as_of'=>$asOf,'timezone'=>'UTC','columns'=>$bundle['columns'],'warnings'=>$bundle['warnings'],'items'=>$rows,'next_cursor'=>$next);
    }
    public static function dates(array $row): array {
        if($row['saved_at']!==null){$t=$row['saved_at'];if(is_int($t)||ctype_digit((string)$t))$time=(int)$t;else{$d=new \DateTimeImmutable($t,new \DateTimeZone('UTC'));$time=$d->getTimestamp();}$row['saved_at']=gmdate('Y-m-d\TH:i:s\Z',$time);}return $row;
    }
}
