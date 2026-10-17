<?php
namespace EIPSI\AssessmentEditor;
require_once __DIR__.'/Answers.php';
/** Native admin-only owner. Not connected to longitudinal participant/auth APIs. */
final class PartialProgress {
    const PREFIX='eipsi_native_partial_';
    const OWNER_PREFIX='eipsi_native_partial_owner_';
    private static function ownerKey(array $d): string {return self::OWNER_PREFIX.hash('sha256',$d['id']).'_'.get_current_user_id();}
    private static function key(array $d): string {if(!current_user_can('manage_options'))throw new \RuntimeException('Forbidden',403);return self::PREFIX.hash('sha256',get_current_user_id().':'.$d['id']);}
    public static function save(array $definition,array $answers,array $touched,int $page,array $metadata,int $now=0): ?array {
        $d=Definition::validate($definition);$key=self::key($d);$now=$now?:time();if(!$d['flow']['save_continue']['enabled']){delete_transient($key);delete_option(self::ownerKey($d));return null;}if($page<0||$page>=count($d['flow']['pages']))Definition::fail('Invalid partial page');
        $optional=$d;$walk=function(&$items)use(&$walk){foreach($items as &$e){if($e['response_key'])$e['properties']['required']=false;if($e['type']==='multiple_choice')$e['properties']['min_selected']=0;if($e['type']==='matrix')foreach($e['properties']['matrix_rows'] as &$r)$r['required']=false;unset($r);$walk($e['children']);}unset($e);};$walk($optional['elements']);if(Answers::errors($optional,$answers,$touched))Definition::fail('Invalid partial answers');if(strlen(Definition::json($metadata))>524288)Definition::fail('Metadata volume exceeded');
        $hash=hash('sha256',Definition::json($d));$old=get_transient($key);$created=is_array($old)&&$old['definition_hash']===$hash&&$old['expires_at']>$now?$old['created_at']:$now;$expires=$created+Flow::retention($d);
        $record=array('contract'=>'NATIVE_PARTIAL_V1','assessment_id'=>$d['id'],'definition_hash'=>$hash,'answers'=>$answers,'touched'=>$touched,'page'=>$page,'metadata'=>$metadata,'created_at'=>$created,'expires_at'=>$expires);set_transient($key,$record,max(1,$expires-$now));add_option(self::ownerKey($d),$key,'',false);return $record;
    }
    public static function resume(array $definition,int $now=0): ?array {$d=Definition::validate($definition);$key=self::key($d);$r=get_transient($key);if(!$d['flow']['save_continue']['enabled']||!is_array($r)||$r['expires_at']<=($now?:time())||!hash_equals($r['definition_hash'],hash('sha256',Definition::json($d)))){delete_transient($key);delete_option(self::ownerKey($d));return null;}return $r;}
    public static function clear(array $definition): void {$d=Definition::validate($definition);delete_transient(self::key($d));delete_option(self::ownerKey($d));}
    public static function clearAssessment(string $id): void {if(!current_user_can('manage_options'))throw new \RuntimeException('Forbidden',403);Definition::id($id);global $wpdb;$rows=$wpdb->get_results($wpdb->prepare("SELECT option_name,option_value FROM {$wpdb->options} WHERE option_name LIKE %s",$wpdb->esc_like(self::OWNER_PREFIX.hash('sha256',$id).'_').'%'),ARRAY_A);foreach($rows as $row){delete_transient($row['option_value']);delete_option($row['option_name']);}}
    /** Native owner cleanup; WP's scheduled expired-transient cleanup also covers it. */
    public static function cleanup(int $now=0): int {if(!current_user_can('manage_options'))throw new \RuntimeException('Forbidden',403);global $wpdb;$prefix='_transient_timeout_'.self::PREFIX;$names=$wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value <= %d",$wpdb->esc_like($prefix).'%', $now?:time()));foreach($names as $n)delete_transient(substr($n,strlen('_transient_timeout_')));return count($names);}
}
