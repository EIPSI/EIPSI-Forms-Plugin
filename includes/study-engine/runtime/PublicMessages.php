<?php
namespace EIPSI\StudyEngine\Runtime;
require_once __DIR__.'/DateFormat.php';
/** Public text only; never interprets HTML, PHP or arbitrary shortcodes. */
final class PublicMessages {
    public static function catalog(): array {static $rows;if($rows===null)$rows=json_decode(file_get_contents(__DIR__.'/public-messages.json'),true,32,JSON_THROW_ON_ERROR);return $rows;}
    public static function defaults(): array {return array_column(self::catalog(),'default','key');}
    public static function validate($messages): array {
        if(!is_array($messages))throw new \DomainException('INVALID_PUBLIC_MESSAGES');$keys=self::defaults();
        foreach($messages as $key=>$value)if(!array_key_exists($key,$keys)||!is_string($value)||strlen($value)>16000||preg_match('//u',$value)!==1||strpos($value,"\0")!==false)throw new \DomainException('INVALID_PUBLIC_MESSAGE');return $messages;
    }
    public static function all(array $definition=array()): array {
        $custom=$definition['protocol_metadata']['public_messages']??array();
        if(!$definition)$custom=get_option('eipsi_study_public_fallback_messages',array());
        $defaults=self::defaults();foreach(array('finished_title'=>'completion_title','finished_body'=>'completion_message') as $key=>$source)if(isset($definition['protocol_metadata'][$source])&&is_string($definition['protocol_metadata'][$source]))$defaults[$key]=$definition['protocol_metadata'][$source];
        return array_replace($defaults,self::validate($custom));
    }
    public static function text(array $definition,string $key,array $values=array()): string {
        $all=self::all($definition);if(!array_key_exists($key,$all))throw new \DomainException('UNKNOWN_PUBLIC_MESSAGE');$text=$all[$key];
        foreach($values as $name=>$value)$text=str_replace('['.$name.']',(string)$value,$text);return $text;
    }
    public static function attributes(array $definition): string {return ' data-study-messages="'.esc_attr(wp_json_encode(array_intersect_key(self::all($definition),array_flip(array('change_title','change_body','change_cancel','change_accept','email_required','email_invalid','withdrawal_invalid','withdrawal_email_not_found','operation_failed'))))).'"';}
    public static function fallbackFields(): array {return array_values(array_filter(self::catalog(),static function($f){return empty($f['retired'])&&in_array($f['group'],array('errors'),true);}));}
}
