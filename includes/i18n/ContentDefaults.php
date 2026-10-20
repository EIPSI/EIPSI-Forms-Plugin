<?php
namespace EIPSI\I18n;
/** Participant content authority. No current user, WordPress locale or browser lookup. */
final class ContentDefaults {
    public static function catalog(): array {static $c;if($c===null)$c=json_decode(file_get_contents(__DIR__.'/content-defaults.json'),true,64,JSON_THROW_ON_ERROR);return $c;}
    public static function valid($language): string {if(!is_string($language)||!in_array($language,array('en','es'),true))throw new \InvalidArgumentException('INVALID_CONTENT_LANGUAGE');return $language;}
    public static function language(array $object): string {
        // Objects without the new field predate bilingual content initialization (Spanish).
        $container=isset($object['protocol_metadata'])?$object['protocol_metadata']:$object;
        return array_key_exists('content_language',$container)?(in_array($container['content_language'],array('en','es'),true)?$container['content_language']:'en'):'es';
    }
    public static function get(string $section,string $language='es'): array {return self::catalog()[self::valid($language)][$section];}
    public static function question(string $type,string $language): array {
        $c=self::catalog()[self::valid($language)];$m=$c['question_messages'];$p=$c['question_defaults'][$type]??array();
        if(isset($c['question_defaults'][$type])){
            $p['required_message']=$m['required_message'];
            if(in_array($type,array('text','textarea','email','tel','url'),true))$p['length_message']=$m['length_message'];
            if(in_array($type,array('text','email','tel','url','number'),true))$p['validation_message']=$c['validation'][$type==='tel'?'text':$type];
            if($type==='number')$p['number_range_message']=$m['number_range_message'];
            if($type==='multiple_choice')foreach(array('selection_min_message','selection_max_message') as $key)$p[$key]=$m[$key];
            if(in_array($type,array('date','vas','single_choice','multiple_choice','select','likert','matrix'),true))$p['invalid_value_message']=$c['validation'][$type]??$m['invalid_value_message'];
            if($type==='date')foreach($m as $key=>$v)if(strpos($key,'date_')===0)$p[$key]=$v;
            if($type==='matrix')$p['matrix_heading']=$m['matrix_heading'];
            if($type==='vas')$p['unanswered_value_message']=$m['unanswered_value_message'];
        }
        if($type==='image')$p['empty_image_message']=$m['empty_image_message'];return $p;
    }
}
