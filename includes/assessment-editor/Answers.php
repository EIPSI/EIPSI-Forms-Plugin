<?php
namespace EIPSI\AssessmentEditor;
require_once __DIR__.'/Definition.php';
final class AnswerValidationFailure extends \InvalidArgumentException {public $errors;public function __construct(array $errors){$this->errors=$errors;parent::__construct($errors[0]['message']);}}
/** Pure preview-response validation. Never stores, sends or creates an execution. */
final class Answers {
    public static function errors(array $definition,array $answers,array $touched=array()): array {
        $definition=Definition::validate($definition);$errors=array();$keys=array();
        $visit=function($items)use(&$visit,&$errors,&$keys,$answers,$touched,$definition){foreach($items as $e){if($e['type']==='matrix'){foreach($e['properties']['matrix_rows'] as $r){$row=array('type'=>'likert','options'=>$e['options'],'properties'=>array_merge($e['properties'],array('required'=>$e['properties']['required']||$r['required'])));$key=$r['response_key'];$keys[$key]=true;$message=self::error($row,$answers[$key]??null,true);if($message)$errors[]=array('id'=>$r['id'],'message'=>$message);}}elseif($e['response_key']){$key=$e['response_key'];$keys[$key]=true;$message=self::error($e,$answers[$key]??null,!empty($touched[$key]));if($message&&$e['type']==='textarea'&&isset($answers[$key])&&!is_string($answers[$key]))$message=$definition['flow']['messages']['invalid']??Flow::messages()['invalid'];if($message)$errors[]=array('id'=>$e['id'],'message'=>$message);} $visit($e['children']);}};
        $visit($definition['elements']);foreach($answers as $key=>$value)if(!isset($keys[$key]))$errors[]=array('id'=>$key,'message'=>$definition['flow']['messages']['invalid']??Flow::messages()['invalid']);return $errors;
    }
    private static function numeric($v,$min,$max,$step,$base): bool {return (is_int($v)||is_float($v))&&is_finite((float)$v)&&($min===null||$v>=$min)&&($max===null||$v<=$max)&&abs(($v-$base)/$step-round(($v-$base)/$step))<=1e-7;}
    private static function error(array $e,$value,bool $touched): string {
        $message=self::defaultError($e,$value,$touched);if($message==='')return '';
        $p=$e['properties'];
        if($e['type']==='multiple_choice'){
            $list=$value??array();$ids=array_column($e['options'],'id');$valid=is_array($list)&&(!$list||array_keys($list)===range(0,count($list)-1));$seen=array();if($valid)foreach($list as $id){if(!is_string($id)||!in_array($id,$ids,true)||isset($seen[$id])){$valid=false;break;}$seen[$id]=true;}
            if($valid){$minimum=count($list)<$p['min_selected'];$maximum=$p['max_selected']!==null&&count($list)>$p['max_selected'];if($minimum||$maximum){$key=$minimum?'selection_min_message':'selection_max_message';$fallback=$minimum?'Elegí al menos [MINIMO] opciones.':'Elegí como máximo [MAXIMO] opciones.';$custom=$p[$key]??'';return str_replace(array('[MINIMO]','[MAXIMO]'),array((string)$p['min_selected'],(string)$p['max_selected']),trim($custom)!==''?$custom:$fallback);}}
        }
        if(array_key_exists('min_length',$p)&&is_string($value)&&trim($value)!==''){$length=preg_match_all('/./us',$value);if(($p['min_length']!==null&&$length<$p['min_length'])||($p['max_length']!==null&&$length>$p['max_length'])){$custom=$p['length_message']??'';return str_replace(array('[MINIMO]','[MAXIMO]'),array((string)($p['min_length']??0),(string)($p['max_length']??'∞')),trim($custom)!==''?$custom:'La respuesta debe tener entre [MINIMO] - [MAXIMO] caracteres.');}}
        if($e['type']==='number'&&(is_int($value)||is_float($value))&&is_finite((float)$value)&&(($p['min']!==null&&$value<$p['min'])||($p['max']!==null&&$value>$p['max']))){$custom=$p['number_range_message']??'';return str_replace(array('[MINIMO]','[MAXIMO]'),array((string)($p['min']??'−∞'),(string)($p['max']??'∞')),trim($custom)!==''?$custom:'La respuesta debe estar entre [MINIMO] - [MAXIMO].');}
        $empty=$value===null||$value===''||(is_string($value)&&trim($value)==='')||$value===array()||($e['type']==='vas'&&!$touched);
        $text=in_array($e['type'],array('text','textarea','email','tel','url','number'),true);
        $custom=$empty?($p['required_message']??''):($text?($p['validation_message']??''):($p['invalid_value_message']??''));return trim($custom)!==''?$custom:($empty?'Respondé esta pregunta para continuar.':$message);
    }
    private static function defaultError(array $e,$value,bool $touched): string {
        $p=$e['properties'];$type=$e['type'];$empty=$value===null||$value===''||(is_string($value)&&trim($value)==='')||$value===array();
        if($type==='vas'&&!$touched)return $p['required']?'Tocá la escala para responder.':'';
        if($empty)return ($p['required']||($type==='multiple_choice'&&$p['min_selected']>0))?'Respondé esta pregunta para continuar.':'';
        if(in_array($type,array('text','textarea','email','tel','url'),true)){
            if(!is_string($value)||preg_match('//u',$value)!==1)return 'Ingresá texto válido.';$length=preg_match_all('/./us',$value);
            if($p['min_length']!==null&&$length<$p['min_length'])return 'La respuesta es demasiado corta.';if($p['max_length']!==null&&$length>$p['max_length'])return 'La respuesta supera la longitud máxima.';
        }
        if(($p['content_type']??$type)==='email'&&!preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/uD',$value))return 'Ingresá un email válido.';
        if(($p['content_type']??$type)==='url'){ $parts=parse_url($value);if(!$parts||!isset($parts['host'],$parts['scheme'])||!in_array(strtolower($parts['scheme']),array('http','https','ftp'),true)||preg_match('/[\s]/u',$value))return 'Ingresá una URL completa válida.'; }
        if($type==='date'){if(!is_string($value)||!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D',$value,$m)||!checkdate((int)$m[2],(int)$m[3],(int)$m[1]))return 'Ingresá una fecha válida.';}
        if(($p['content_type']??'')==='letters'&&!preg_match('/^[\p{L}\p{M}\s]+$/uD',$value))return 'Usá sólo letras y espacios.';
        if($type==='number'&&$p['integer_only']&&(!is_numeric($value)||floor((float)$value)!==(float)$value))return 'Ingresá un número entero.';
        if($type==='number'&&!((is_int($value)||is_float($value))&&is_finite((float)$value)&&($p['min']===null||$value>=$p['min'])&&($p['max']===null||$value<=$p['max'])))return 'Ingresá un número dentro del rango configurado.';
        if($type==='vas'&&!self::numeric($value,$p['min'],$p['max'],$p['step'],$p['min']))return 'Elegí un valor válido en la escala.';
        $ids=array_column($e['options'],'id');
        if(in_array($type,array('single_choice','select','likert'),true)&&!in_array($value,$ids,true))return 'Elegí una opción disponible.';
        if($type==='multiple_choice'){
            if(!is_array($value)||($value&&array_keys($value)!==range(0,count($value)-1)))return 'Elegí opciones disponibles sin repetir.';
            $seen=array();foreach($value as $id){if(!is_string($id)||!in_array($id,$ids,true)||isset($seen[$id]))return 'Elegí opciones disponibles sin repetir.';$seen[$id]=true;}
            if(count($value)<$p['min_selected'])return 'Elegí al menos '.$p['min_selected'].' opciones.';
            if($p['max_selected']!==null&&count($value)>$p['max_selected'])return 'Elegí como máximo '.$p['max_selected'].' opciones.';
        }
        return '';
    }
    public static function value(array $e,$answer) {if($e['type']==='likert'){$index=array_search($answer,array_column($e['options'],'id'),true);return $index===false?null:($e['options'][$index]['value']??$e['options'][$index]['label']);}return $answer;}
}
