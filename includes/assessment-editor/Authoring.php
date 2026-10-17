<?php
namespace EIPSI\AssessmentEditor;
require_once __DIR__.'/RichText.php';
/** Backward-compatible additions; scientific identities are never derived from content. */
final class Authoring {
    public static function defaults(array $e): array {
        $p=$e['properties'];$t=$e['type'];
        if(in_array($t,Definition::QUESTIONS,true)){
            $p+=array('label_html'=>null,'helper_html'=>null,'footer_html'=>'');
            foreach(array('label_html','helper_html','footer_html') as $k)if(is_string($p[$k]))$p[$k]=RichText::sanitize($p[$k]);
        }
        if(in_array($t,array('text','textarea','email','tel','url'),true))$p+=array('content_type'=>$t==='textarea'?'free':($t==='text'?'free':$t),'display_mode'=>$t==='textarea'?'multiline':'single');
        if($t==='number')$p+=array('integer_only'=>false);
        if(in_array($t,array('single_choice','multiple_choice'),true))$p+=array('columns'=>1);
        if($t==='vas')$p+=array('label_scale'=>($p['label_font_size']??16)<=14?'small':(($p['label_font_size']??16)<=24?'normal':'large'));
        if($t==='vas'&&!array_key_exists('label_settings',$p)){if(!is_array($p['labels']))Definition::fail('Invalid VAS labels');$p['label_settings']=array();foreach($p['labels'] as $i=>$text)$p['label_settings'][]=array('id'=>'L_'.substr($e['id'],0,70).'_'.$i,'position'=>null);}
        if($t==='matrix'){$p+=array('matrix_kind'=>'likert','matrix_rows'=>array());if(!is_array($p['matrix_rows']))Definition::fail('Invalid Matrix rows');foreach($p['matrix_rows'] as &$r){if(is_array($r)){$r+=array('label_html'=>null,'helper_html'=>null,'footer_html'=>'','required'=>false);foreach(array('label_html','helper_html','footer_html') as $k)if(is_string($r[$k]))$r[$k]=RichText::sanitize($r[$k]);}}unset($r);}
        return $p;
    }
    public static function validate(array $e,array &$seen,array &$keys): void {
        $p=$e['properties'];$t=$e['type'];
        foreach(array('label_html','helper_html','footer_html') as $k)if(array_key_exists($k,$p)&&$p[$k]!==null&&(!is_string($p[$k])||strlen($p[$k])>80000))Definition::fail('Invalid rich text');
        if(in_array($t,array('text','textarea','email','tel','url'),true)){
            if(!in_array($p['content_type'],array('free','email','url','tel','letters'),true)||!in_array($p['display_mode'],array('single','multiline'),true))Definition::fail('Invalid Text format');
            if($t!=='textarea'&&($p['display_mode']!=='single'||($t==='text'?!in_array($p['content_type'],array('free','letters'),true):$p['content_type']!==$t)))Definition::fail('Incompatible Text discriminator');
            if($t==='textarea'&&$p['display_mode']!=='multiline')Definition::fail('Invalid multiline mode');
        }
        if($t==='number'&&!is_bool($p['integer_only']))Definition::fail('Invalid integer-only setting');
        if(isset($p['columns'])&&(!is_int($p['columns'])||$p['columns']<1||$p['columns']>12))Definition::fail('Columns must be 1–12');
        if($t==='vas'){
            if(!in_array($p['label_scale'],array('small','normal','large'),true))Definition::fail('Invalid VAS label scale');
            if(!is_array($p['label_settings'])||array_keys($p['label_settings'])!==array_keys($p['labels']))Definition::fail('VAS label settings mismatch');
            foreach($p['label_settings'] as $label){if(!is_array($label))Definition::fail('Invalid label settings');Definition::fields($label,array('id','position'));Definition::id($label['id']);if(isset($seen[$label['id']]))Definition::fail('Duplicate label identity');$seen[$label['id']]=true;$v=$label['position'];if($v!==null&&((!is_int($v)&&!is_float($v))||!is_finite((float)$v)||$v<$p['min']||$v>$p['max']))Definition::fail('VAS label position outside scale');}
        }
        if($t==='matrix'){
            if($p['matrix_kind']!=='likert'||!is_array($p['matrix_rows'])||count($p['matrix_rows'])<1||count($p['matrix_rows'])>100||array_keys($p['matrix_rows'])!==range(0,count($p['matrix_rows'])-1))Definition::fail('Matrix requires 1–100 rows');
            foreach($p['matrix_rows'] as $r){if(!is_array($r))Definition::fail('Invalid Matrix row');Definition::fields($r,array('id','response_key','variable_name','label','label_html','helper_html','footer_html','required'));foreach(array('id','response_key') as $k){Definition::id($r[$k]);$bag=&$seen;if($k==='response_key')$bag=&$keys;if(isset($bag[$r[$k]]))Definition::fail('Duplicate Matrix row identity');$bag[$r[$k]]=true;unset($bag);}if(!is_string($r['label'])||strlen($r['label'])>8000||!is_bool($r['required']))Definition::fail('Invalid Matrix row');foreach(array('label_html','helper_html','footer_html') as $k)if($r[$k]!==null&&(!is_string($r[$k])||strlen($r[$k])>80000))Definition::fail('Invalid row rich text');}
        }
    }
    public static function variables(array $elements): void { Variables::validate($elements); }
}
