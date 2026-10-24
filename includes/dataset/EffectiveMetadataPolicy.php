<?php
namespace EIPSI\Dataset;
/** One capture ceiling. Published profile requests a subset; historical rows are never rewritten by save(). */
final class EffectiveMetadataPolicy {
    const OPTION='eipsi_metadata_policy_v1';
    public static function fields(): array {return array(
        'session'=>array('started_at','completed_at','duration','recovery_used','recovery_count','dropped_events'),
        'page'=>array('presented_at','leave_at','duration','visit_count','revisit_count','next_transitions','previous_transitions','resumed_here','current_presented_at'),
        'item'=>array('presented_at','first_interaction_at','final_response_at','time_to_first_interaction','time_to_final_response','interaction_count','change_count','focus_count','focus_duration','initial_state','first_interacted_state','final_state','visit_count','revisit_count','input_method','trajectory'),
        'client'=>array('device','browser','os','screen','viewport','timezone','language','input_capabilities','ip_address','webgl_renderer','screen_depth','pixel_ratio','cpu_cores','cookies_enabled'),
        'events'=>array('events'));
    }
    public static function defaults(): array {$out=array();foreach(self::fields()as$group=>$fields)foreach($fields as$field)$out[$group.'_'.$field]=true;return $out;}
    public static function global(): array {
        $raw=function_exists('get_option')?get_option(self::OPTION,array()):array();$out=self::defaults();if(is_array($raw))foreach($out as$key=>$default)if(isset($raw[$key])&&is_bool($raw[$key]))$out[$key]=$raw[$key];return $out;
    }
    public static function save(array $policy): void {
        if(!\EIPSI_Admin_Authorization_Policy::allows('privacy.manage'))throw new \RuntimeException('METADATA_POLICY_FORBIDDEN',403);
        $defaults=self::defaults();if(array_diff(array_keys($policy),array_keys($defaults))||array_diff(array_keys($defaults),array_keys($policy)))throw new \RuntimeException('METADATA_POLICY_INVALID',400);foreach($policy as$value)if(!is_bool($value))throw new \RuntimeException('METADATA_POLICY_INVALID',400);
        update_option(self::OPTION,$policy,false);
    }
    public static function resolve(string $profile,?array $global=null): array {
        if(!in_array($profile,array('basic','standard','detailed'),true))throw new \InvalidArgumentException('METADATA_PROFILE_INVALID');$global=$global??self::global();$allowed=array();foreach(self::fields()as$group=>$fields){$allowed[$group]=array();foreach($fields as$field){if(!($global[$group.'_'.$field]??false))continue;if($profile==='basic'&&($group==='client'||$group==='events'||($group==='item'&&in_array($field,array('focus_count','focus_duration','input_method','trajectory'),true))))continue;if($profile==='standard'&&$field==='trajectory')continue;$allowed[$group][]=$field;}}return $allowed;
    }
    public static function filter(array $meta,string $profile): array {
        $allowed=self::resolve($profile);$out=array_intersect_key($meta,array_flip(array_merge(array('contract','assessment_id','definition_ref','status','server_completed_at'),$allowed['session'])));
        foreach(array('pages'=>'page','items'=>'item')as$collection=>$group){$out[$collection]=array();foreach($meta[$collection]??array()as$id=>$row){$ids=$group==='page'?array('page_id'):array('item_id','response_key','variable_name');$out[$collection][$id]=array_intersect_key($row,array_flip(array_merge($ids,$allowed[$group])));}}
        $out['events']=$allowed['events']?($meta['events']??array()):array();if($allowed['client']&&isset($meta['context']))$out['context']=array_intersect_key($meta['context'],array_flip($allowed['client']));
        return $out;
    }
    /** Preserve previously captured fields when disabled mid-attempt; do not accept new values for them. */
    public static function preserve(array $current,array $old,string $profile): array {
        $allowed=self::resolve($profile);foreach(self::fields()['session']as$field)if(!in_array($field,$allowed['session'],true)&&array_key_exists($field,$old))$current[$field]=$old[$field];
        foreach(array('pages'=>'page','items'=>'item')as$collection=>$group)foreach($old[$collection]??array()as$id=>$row)foreach(self::fields()[$group]as$field)if(!in_array($field,$allowed[$group],true)&&array_key_exists($field,$row))$current[$collection][$id][$field]=$row[$field];
        foreach($old['context']??array()as$field=>$value)if(!in_array($field,$allowed['client'],true))$current['context'][$field]=$value;
        if(!$allowed['events']&&!empty($old['events']))$current['events']=$old['events'];return $current;
    }
    /** Domain HTML path has only this bounded client vocabulary; fingerprint/raw UA are outside Native policy. */
    public static function legacyMetadata(array $metadata): array {
        $global=self::global();$map=array('device'=>'device','browser'=>'browser','os'=>'os','screen_width'=>'screen','screen_height'=>'screen');$out=array();foreach($map as$key=>$field)if(($global['client_'.$field]??false)&&array_key_exists($key,$metadata))$out[$key]=$metadata[$key];return $out;
    }
    public static function legacy(array $config): array {
        $p=self::global();foreach(array('device_type'=>'device','browser'=>'browser','os'=>'os','screen_width'=>'screen')as$key=>$field)$config[$key]=$p['client_'.$field];
        // These are not part of modern Native capture; historical functions retain a bounded legacy adapter.
        $config['user_agent_full']=false;return $config;
    }
}
