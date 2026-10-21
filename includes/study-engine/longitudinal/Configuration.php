<?php
namespace EIPSI\StudyEngine\Longitudinal;
use EIPSI\StudyEngine\Contracts\Check;
require_once __DIR__.'/InitialScreen.php';

/** Published snapshot contract; scheduling has no message text or mail transport. */
final class Configuration {
    public static function minutes($input): array {
        if(is_string($input))$input=explode(',',$input);
        if(!is_array($input)||count($input)>64)throw new \DomainException('INVALID_REMINDER_LIST');
        $values=array();foreach($input as $value){
            if(is_string($value))$value=trim($value);
            if(!(is_int($value)||(is_string($value)&&preg_match('/^[1-9][0-9]*$/D',$value)))||(int)$value<1||(int)$value>525600)throw new \DomainException('INVALID_REMINDER_MINUTES');
            $values[]=(int)$value;
        }$values=array_values(array_unique($values));sort($values,SORT_NUMERIC);return $values;
    }
    public static function normalize(array $config): array {
        Check::fields($config,array('contract','email_enabled','availability_template','reminder_template','reminder_unit','reminder_basis','reminder_offsets','verification_policy','provisional_hours','session_days','capability_hours','immediate_availability_policy','show_next_time'),array('initial_screen'));
        if($config['contract']!=='P1F_V1'||$config['reminder_unit']!=='minutes'||$config['reminder_basis']!=='AFTER_AVAILABLE')throw new \DomainException('INVALID_NOTIFICATION_CONTRACT');
        foreach(array('email_enabled','show_next_time') as $k)if(!is_bool($config[$k]))throw new \DomainException('INVALID_NOTIFICATION_BOOLEAN');
        if(!in_array($config['verification_policy'],array('VERIFIED_ONLY','ALLOW_UNVERIFIED'),true)||!in_array($config['immediate_availability_policy'],array('SUPPRESS_WHILE_PRESENT','SEND'),true))throw new \DomainException('INVALID_NOTIFICATION_POLICY');
        foreach(array('availability_template','reminder_template') as $k)Check::id($config[$k],$k);
        if($config['availability_template']!=='ASSESSMENT_AVAILABLE'||$config['reminder_template']!=='ASSESSMENT_REMINDER')throw new \DomainException('UNKNOWN_EMAIL_TEMPLATE');
        foreach(array('provisional_hours'=>array(1,72),'session_days'=>array(1,90),'capability_hours'=>array(1,48)) as $k=>$bounds){Check::integer($config[$k],$k,$bounds[0]);if($config[$k]>$bounds[1])throw new \DomainException('INVALID_ACCESS_TTL');}
        if(isset($config['initial_screen']))$config['initial_screen']=InitialScreen::normalize($config['initial_screen']);
        $config['reminder_offsets']=self::minutes($config['reminder_offsets']);return $config;
    }
    /** Internal opt-in publishing helper writes every choice into the version. */
    public static function apply(array $definition,array $overrides=array()): array {
        $config=array_merge(array('contract'=>'P1F_V1','email_enabled'=>true,'availability_template'=>'ASSESSMENT_AVAILABLE','reminder_template'=>'ASSESSMENT_REMINDER','reminder_unit'=>'minutes','reminder_basis'=>'AFTER_AVAILABLE','reminder_offsets'=>array(15,30,60,120),'verification_policy'=>'VERIFIED_ONLY','provisional_hours'=>24,'session_days'=>30,'capability_hours'=>48,'immediate_availability_policy'=>'SUPPRESS_WHILE_PRESENT','show_next_time'=>true),$overrides);
        if(isset($definition['protocol_metadata']['content_language'])&&!isset($config['initial_screen']))$config['initial_screen']=InitialScreen::defaults($definition['protocol_metadata']['content_language']);
        $definition['protocol_metadata']['enrollment_mode']='EMAIL_LONGITUDINAL';$definition['protocol_metadata']['longitudinal']=self::normalize($config);return $definition;
    }
    public static function read(array $definition): array {
        if(($definition['protocol_metadata']['enrollment_mode']??null)!=='EMAIL_LONGITUDINAL'||!isset($definition['protocol_metadata']['longitudinal']))throw new \DomainException('LONGITUDINAL_CONFIG_REQUIRED');
        return self::normalize($definition['protocol_metadata']['longitudinal']);
    }
}
/** Injectable UTC clock used by credentials/worker; engine remains sole temporal authority. */
class Clock {
    private $source;
    public function __construct(?callable $source=null){$this->source=$source??static function(){return gmdate('Y-m-d\TH:i:s\Z',function_exists('apply_filters')?(int)apply_filters('eipsi_runtime_timestamp',time()):time());};}
    public function now(): string {$v=($this->source)();Check::timestamp($v,'clock',false);return $v;}
    public function mysql(): string {return \EIPSI\StudyEngine\Persistence\Database::date($this->now());}
    public function later(int $seconds): string {return gmdate('Y-m-d H:i:s',strtotime($this->now())+$seconds);}
}
