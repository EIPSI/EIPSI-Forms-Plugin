<?php
namespace EIPSI\Dataset;
use EIPSI\StudyEngine\Contracts\Canonical as C;
final class Conflict extends \DomainException {
    public $details;
    public function __construct(string $code,array $details=array()){parent::__construct($code);$this->details=$details;}
}
/** Scientific projection only. Same inputs always produce the same observations, types and bindings. */
final class CanonicalStudyDataset {
    const CONTRACT='EIPSI_STUDY_DATA_V2';
    const CONTEXT=array('observation_id','study_id','study_code','cycle','participation_id','response_at','study_version_id','attempt_state','response_kind');
    const ANNEXES=array('data','dictionary','response_status','bindings','process_metadata','randomization','audit','identities');
    public static function datetime($value): string {
        if($value===null||$value==='')throw new Conflict('MISSING_RESPONSE_AT');
        if(is_int($value)||ctype_digit((string)$value))$d=(new \DateTimeImmutable('@'.(int)$value))->setTimezone(new \DateTimeZone('UTC'));
        else {if(!is_string($value)||!preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:Z| UTC|[+-]\d{2}:\d{2})?$/D',$value))throw new Conflict('INVALID_RESPONSE_AT');try{$d=new \DateTimeImmutable($value,new \DateTimeZone('UTC'));$errors=\DateTimeImmutable::getLastErrors();if($errors&&($errors['warning_count']||$errors['error_count']))throw new \RuntimeException();}catch(\Throwable $e){throw new Conflict('INVALID_RESPONSE_AT');}}
        return $d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
    public static function filters(array $f): array {
        $allowed=array('version','cycle','execution','assessment','opportunity','include_partials','include_retained','response_from','response_to');
        if(array_diff(array_keys($f),$allowed))throw new Conflict('DATASET_FILTER_INVALID');
        foreach(array('include_partials','include_retained')as$k)if(isset($f[$k])&&!is_bool($f[$k]))throw new Conflict('DATASET_FILTER_INVALID');
        foreach(array('version','execution','assessment','opportunity')as$k)if(isset($f[$k])&&(!is_string($f[$k])||!preg_match('/^[A-Za-z][A-Za-z0-9_.:-]{0,127}$/D',$f[$k])))throw new Conflict('DATASET_FILTER_INVALID');
        if(isset($f['cycle'])&&(!is_int($f['cycle'])||$f['cycle']<1))throw new Conflict('DATASET_FILTER_INVALID');
        foreach(array('response_from','response_to')as$k)if(isset($f[$k])){if(!is_string($f[$k])||!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D',$f[$k]))throw new Conflict('DATASET_FILTER_INVALID');$normalized=self::datetime($f[$k]);if($normalized!==$f[$k])throw new Conflict('DATASET_FILTER_INVALID');}
        if(isset($f['response_from'],$f['response_to'])&&$f['response_from']>$f['response_to'])throw new Conflict('DATASET_FILTER_INVALID');
        return $f+array('include_partials'=>false,'include_retained'=>true);
    }
    private static function variantKey(array $d): string {return C::json(array_intersect_key($d,array_flip(array('study_version_id','native_version_id','assessment_id','response_key','definition_hash'))));}
    private static function family(string $type,$value,array $dictionary): string {
        if(in_array($type,array('number','vas'),true))return 'number';
        if(in_array($type,array('likert','matrix'),true))return count(array_filter($dictionary['options']??array(),static function($o){return is_string($o['value']??null);}))?'string':'number';
        if($type==='consent')return 'boolean';if($type==='multiple_choice')return 'array';if($type==='date')return 'date';return 'string';
    }
    public static function project(array $long,array $dictionary,array $attempts=array(),array $filters=array(),string $code=''): array {
        $filters=self::filters($filters);$dict=array();foreach($dictionary as$d)$dict[self::variantKey($d)]=$d;
        $rows=array();$owners=array();$values=array();$statuses=array();$bindings=array();$variants=array();$process=array();$random=array();
        foreach($long as$r){
            if(($r['data_policy']??'USE_ALLOWED')==='DO_NOT_USE'||(!$filters['include_retained']&&($r['data_policy']??'')==='RETAIN_FOR_ANALYSIS'))continue;
            foreach(array('version'=>'study_version_id','cycle'=>'cycle','execution'=>'participation_id','assessment'=>'assessment_id','opportunity'=>'opportunity_id')as$filter=>$field)if(isset($filters[$filter])&&(string)$r[$field] !== (string)$filters[$filter])continue 2;
            $partial=$r['attempt_state']==='PARTIAL_SAVED'||!in_array($r['attempt_state'],array('COMPLETED','LOGICAL_EXIT'),true);
            if($partial&&!$filters['include_partials'])continue;
            $attempt=$attempts[$r['attempt_id']]??null;if(($r['native_version_id']??null)!==null&&!$attempt)throw new Conflict('MISSING_ATTEMPT_PROVENANCE',array('attempt_id'=>$r['attempt_id']));
            $at=self::datetime($partial?$r['saved_at']:($attempt['completed_at']??$r['saved_at']));
            if(isset($filters['response_from'])&&$at<$filters['response_from']||isset($filters['response_to'])&&$at>$filters['response_to'])continue;
            $key=$r['participation_id'].'|'.$at;$attemptId=$r['attempt_id'];
            if(isset($owners[$key])&&$owners[$key]!==$attemptId)throw new Conflict('RESPONSE_TIME_COLLISION',array('participation_id'=>$r['participation_id'],'response_at'=>$at,'attempts'=>array($owners[$key],$attemptId)));
            $owners[$key]=$attemptId;$name=$r['variable_name'];
            if(!is_string($name)||!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D',$name)||in_array($name,self::CONTEXT,true))throw new Conflict('VARIABLE_NAME_RESERVED',array('variable_name'=>$name));
            $d=$dict[self::variantKey($r)]??null;if(!$d)throw new Conflict('MISSING_DICTIONARY',array('response_key'=>$r['response_key']));
            $provenance=array_intersect_key($r,array_flip(array('study_version_id','assessment_id','native_version_id','attempt_id','opportunity_id','occurrence','definition_hash','item_id','response_key','variable_name')));
            $cell=C::json(array('value'=>$r['value'],'provenance'=>$provenance,'status'=>$r['response_status']));
            if(isset($values[$key][$name])){if($values[$key][$name]!==$cell)throw new Conflict('VARIABLE_VALUE_COLLISION',array('variable_name'=>$name,'participation_id'=>$r['participation_id'],'response_at'=>$at));continue;}
            $values[$key][$name]=$cell;
            if(!isset($rows[$key]))$rows[$key]=array('observation_id'=>'OB_'.substr(hash('sha256',$r['study_id'].'|'.$key.'|'.$attemptId),0,40),'study_id'=>$r['study_id'],'study_code'=>$code,'cycle'=>(int)$r['cycle'],'participation_id'=>$r['participation_id'],'response_at'=>$at,'study_version_id'=>$r['study_version_id'],'attempt_state'=>$r['attempt_state'],'response_kind'=>$partial?'partial':'final');
            $rows[$key][$name]=$r['value'];$observation=$rows[$key]['observation_id'];
            $statuses[]=array('observation_id'=>$observation,'variable_name'=>$name,'status'=>$r['response_status']);
            $bindings[]=array('observation_id'=>$observation)+$provenance;
            $entry=$d; $entry['variable_name']=$name;$entry['value_family']=self::family((string)$r['type'],$r['value'],$d);$variants[$name][C::json($entry)]=$entry;
        }
        $warnings=array();$outDict=array();foreach($variants as$name=>$entries){$families=array_unique(array_column($entries,'value_family'));if(count($families)>1)throw new Conflict('VARIABLE_TYPE_CONFLICT',array('variable_name'=>$name,'families'=>array_values($families)));$labels=array_unique(array_column($entries,'label'));if(count($labels)>1)$warnings[]=array('code'=>'HISTORICAL_LABEL_CHANGED','variable_name'=>$name);$options=array_unique(array_map(static function($d){return C::json($d['options']??array());},array_values($entries)));if(count($options)>1)$warnings[]=array('code'=>'HISTORICAL_OPTIONS_CHANGED','variable_name'=>$name);foreach($entries as$d)$outDict[]=$d;}
        $names=array_keys($variants);sort($names,SORT_STRING);ksort($rows,SORT_STRING);$out=array();foreach($rows as$r){$out[]=array_replace(array_fill_keys(array_merge(self::CONTEXT,$names),null),$r);$a=$attempts[$owners[$r['participation_id'].'|'.$r['response_at']]]??null;if($a){foreach(self::process($r,$a)as$p)$process[]=$p;$record=$a['metadata']['randomization']??array();foreach(array('blocks','option_orders')as$kind)foreach($record[$kind]??array()as$owner=>$decision)$random[]=array('observation_id'=>$r['observation_id'],'participation_id'=>$r['participation_id'],'response_at'=>$r['response_at'],'attempt_id'=>$a['id'],'level'=>$kind,'owner_id'=>$owner,'decision'=>$decision);}}
        return array('data'=>$out,'dictionary'=>$outDict,'response_status'=>$statuses,'bindings'=>$bindings,'process_metadata'=>$process,'randomization'=>$random,'audit'=>array(),'identities'=>array(),'columns'=>array_merge(self::CONTEXT,$names),'warnings'=>$warnings,'conflicts'=>array());
    }
    private static function process(array $row,array $a): array {
        $base=array('observation_id'=>$row['observation_id'],'participation_id'=>$row['participation_id'],'response_at'=>$row['response_at'],'attempt_id'=>$a['id'],'assessment_id'=>$a['assessment_id'],'native_version_id'=>$a['version_id'],'definition_hash'=>$a['definition_hash']);$out=array();$meta=$a['metadata'];
        $append=static function($level,$entity,$field,$value)use(&$out,$base){$out[]=$base+array('level'=>$level,'entity_id'=>(string)$entity,'field'=>(string)$field,'value'=>$value,'unit'=>$field==='server_completed_at'?'unix_seconds':(preg_match('/(?:_at$|timestamp$|duration$|^time_to_)/',(string)$field)?'milliseconds':(preg_match('/(?:_count$|^dropped_events$|transitions$)/',(string)$field)?'count':'value')));};
        foreach($meta as$key=>$value){if(in_array($key,array('randomization','pages','items','events','context','definition_ref'),true))continue;$append('session',$a['id'],$key,$value);}
        foreach($meta['context']??array()as$key=>$value)$append('client',$a['id'],$key,$value);
        foreach(array('pages'=>'page','items'=>'item','events'=>'event')as$collection=>$level)foreach($meta[$collection]??array()as$id=>$fields)foreach($fields as$key=>$value)$append($level,$id,$key,$value);
        return $out;
    }
    public static function manifest(array $dataset,array $filters,array $selected,string $asOf): array {
        return array('contract'=>self::CONTRACT,'as_of'=>$asOf,'generated_at'=>gmdate('Y-m-d H:i:s'),'timezone'=>'UTC','filters'=>self::filters($filters),'include_partials'=>!empty($filters['include_partials']),'selected_annexes'=>$selected,'rows'=>count($dataset['data']),'variables'=>count($dataset['columns'])-count(self::CONTEXT),'columns'=>$dataset['columns'],'warnings'=>$dataset['warnings'],'conflicts'=>array(),'identity'=>'participation_id','moment'=>'confirmed completed_at or explicit partial saved_at; second precision; collisions block');
    }
}
