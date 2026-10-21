<?php
if (!defined('ABSPATH')) { exit; }
class EIPSI_Storage_Event_Store {
 public static function insert($insert_data) {
  global $wpdb;
  require_once EIPSI_FORMS_PLUGIN_DIR.'admin/privacy-config.php';
  require_once EIPSI_FORMS_PLUGIN_DIR.'admin/database.php';
  $insert_data=eipsi_filter_capture_data($insert_data,get_privacy_config($insert_data['form_id']??null));
  $db=new EIPSI_External_Database();$fallback=false;
  if($db->is_enabled()){
   try { $r=$db->insert_form_event($insert_data); } catch (Throwable $error) { $r=array('success'=>false,'error_code'=>'EXTERNAL_DB_UNAVAILABLE'); }
   if($r['success']){return array('success'=>true,'insert_id'=>$r['insert_id'],'storage'=>'external_db','fallback_used'=>false);}
   $fallback=true;
  }
  $r=$wpdb->insert($wpdb->prefix.'vas_form_events',$insert_data,array('%s','%s','%s','%d','%s','%s','%s'));
  return array('success'=>$r===1 && (int)$wpdb->insert_id>0,'insert_id'=>$r===1 && (int)$wpdb->insert_id>0?$wpdb->insert_id:null,'storage'=>'wordpress_db','fallback_used'=>$fallback,'error'=>$r===false?$wpdb->last_error:null);
 }
}
