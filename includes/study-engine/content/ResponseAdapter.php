<?php
namespace EIPSI\StudyEngine\Content;
use EIPSI\StudyEngine\Persistence\IntegrityFailure;

/** HTTP values become typed domain values, never labels or posted participant/form IDs. */
final class ResponseAdapter {
    public function decode(array $definition,array $values,array $offered): array {
        $nodes=array();foreach($definition['nodes'] as $n)if($n['kind']==='QUESTION')$nodes[$n['response_key']]=$n;
        $out=array();foreach($values as $key=>$value){
            if(!isset($nodes[$key])||!in_array($nodes[$key]['id'],$offered,true))throw new \DomainException('QUESTION_NOT_OFFERED');
            $q=$nodes[$key];if(!is_string($value))throw new \DomainException('RESPONSE_TYPE');
            if($value==='')continue;
            $type=$q['type'];
            if($type==='BOOLEAN'){if(!in_array($value,array('true','false'),true))throw new \DomainException('RESPONSE_TYPE');$typed=$value==='true';}
            elseif(in_array($type,array('INTEGER','NUMBER'),true)){
                if(!preg_match($type==='INTEGER'?'/^-?[0-9]+$/D':'/^-?(?:[0-9]+(?:\.[0-9]+)?|\.[0-9]+)$/D',$value)||strlen($value)>24)throw new \DomainException('RESPONSE_TYPE');
                $typed=$type==='INTEGER'?filter_var($value,FILTER_VALIDATE_INT):(float)$value;if($typed===false||!is_finite((float)$typed))throw new \DomainException('RESPONSE_TYPE');
            }else{$typed=$value;}
            if($type==='CHOICE'&&!in_array($typed,array_column($q['options'],'id'),true))throw new \DomainException('RESPONSE_CHOICE');
            if(isset($q['validation']['min'])&&$typed<$q['validation']['min'])throw new \DomainException('RESPONSE_MIN');
            if(isset($q['validation']['max'])&&$typed>$q['validation']['max'])throw new \DomainException('RESPONSE_MAX');
            $content=json_decode($q['content']['snapshot'],true);if(!is_array($content))throw new IntegrityFailure('CONTENT_ADAPTER_SNAPSHOT_INVALID');
            if($type==='TEXT'){
                $length=preg_match_all('/./us',$typed,$unused);if($length===false)throw new \DomainException('RESPONSE_ENCODING');
                if($q['required']&&trim($typed)==='')throw new \DomainException('RESPONSE_EMPTY');
                if($length<($content['min_length']??0))throw new \DomainException('RESPONSE_MIN_LENGTH');
                if($length>($q['validation']['max_length']??1000))throw new \DomainException('RESPONSE_LENGTH');
                if(($content['format']??'text')==='email'&&!is_email($typed))throw new \DomainException('RESPONSE_EMAIL');
            }
            if(($content['step']??null)!==null){$step=(float)$content['step'];if($step<=0||abs((($typed-($q['validation']['min']??0))/$step)-round(($typed-($q['validation']['min']??0))/$step))>0.000001)throw new \DomainException('RESPONSE_STEP');}
            $out[$q['id']]=$typed;
        }return $out;
    }
}
