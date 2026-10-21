<?php
namespace EIPSI\ExternalApi;
/** Operational defaults only. Security/scientific invariants do not become switches. */
final class OperationsSettings {
    const OPTION='eipsi_external_operations_v1';
    public static function bounds(): array {return array(
        'export_ttl'=>array(3600,604800,86400),'active_per_key'=>array(1,20,4),
        'preparing_per_key'=>array(1,4,2),'global_preparing'=>array(1,64,16),
        'export_bytes'=>array(16777216,536870912,268435456),
        'aggregate_bytes'=>array(67108864,4294967296,1073741824),
        'worker_seconds'=>array(1,15,5),'worker_steps'=>array(1,500,100),
        'cleanup_batch'=>array(1,50,10),'poll_seconds'=>array(2,60,5),
        'audit_days'=>array(7,365,90),'key_audit_days'=>array(90,1095,365)
    );}
    public static function get(): array {
        $out=array('revision'=>0);foreach(self::bounds()as$k=>$b)$out[$k]=$b[2];
        $stored=get_option(self::OPTION,null);if(is_string($stored))$stored=json_decode($stored,true);if(!is_array($stored))return $out;
        try{$valid=self::validate($stored['values']??array());return array('revision'=>(int)($stored['revision']??0))+$valid;}catch(\Throwable $e){return $out;}
    }
    public static function validate(array $values): array {
        if(array_diff(array_keys($values),array_keys(self::bounds()))||count($values)!==count(self::bounds()))throw new ApiError('eipsi_external_invalid_settings',400);
        foreach(self::bounds()as$k=>$b)if(!is_int($values[$k])||$values[$k]<$b[0]||$values[$k]>$b[1])throw new ApiError('eipsi_external_invalid_settings',400);
        if($values['preparing_per_key']>$values['active_per_key']||$values['preparing_per_key']>$values['global_preparing']||$values['export_bytes']>$values['aggregate_bytes']||$values['key_audit_days']<$values['audit_days'])throw new ApiError('eipsi_external_invalid_settings',400);
        return $values;
    }
    public static function save(array $values,int $revision): array {
        Operations::admin();$values=self::validate($values);$s=new Store();$result=$s->transaction(function()use($s,$values,$revision){global$wpdb;
            $r=$s->row($s->prepare("SELECT option_value FROM `{$wpdb->options}` WHERE option_name=%s FOR UPDATE",self::OPTION));$old=$r?json_decode($r['option_value'],true):null;
            if((int)($old['revision']??0)!==$revision)throw new ApiError('eipsi_external_conflict',409);
            $next=array('revision'=>$revision+1,'values'=>$values);$s->rows($s->prepare("INSERT INTO `{$wpdb->options}` (option_name,option_value,autoload) VALUES (%s,%s,'off') ON DUPLICATE KEY UPDATE option_value=VALUES(option_value)",self::OPTION,json_encode($next)));
            $s->audit('OPERATIONS_SETTINGS','',get_current_user_id(),Authenticator::requestId(),time(),array('revision'=>$revision+1));return array('revision'=>$revision+1)+$values;
        });wp_cache_delete(self::OPTION,'options');wp_cache_delete('notoptions','options');wp_cache_delete('alloptions','options');return $result;
    }
    public static function pinned(array $export): array {
        // Existing API-2B resources keep the exact default they were created with.
        $p=json_decode($export['operations_json']??'null',true);return is_array($p)&&isset($p['export_bytes'])?$p:array('revision'=>0,'export_bytes'=>ExportStorage::MAX_BYTES);
    }
}
