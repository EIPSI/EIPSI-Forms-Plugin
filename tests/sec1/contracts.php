<?php
/** Intentional SEC-1 removals; historical signed snapshots remain immutable. */
function sec1_retired_functions(){return array('wp_ajax_eipsi_test_no_nonce_handler','wp_ajax_eipsi_test_deadline_handler','eipsi_check_external_db_handler','eipsi_debug_partial_response_handler','eipsi_ajax_get_cron_diagnostic');}
function sec1_expected($value){
 if(!is_array($value))return $value;$out=array();
 foreach($value as$key=>$item){
  if(in_array($key,sec1_retired_functions(),true))continue;
  if(is_array($item)&&isset($item['callback'])&&in_array($item['callback'],sec1_retired_functions(),true))continue;
  $out[$key]=sec1_expected($item);
 }
 return array_is_list($value)?array_values($out):$out;
}
function sec1_new_hook($row){
 if(!in_array($row['callback']??'',array('EIPSI_Security_Maintenance::register','EIPSI_Security_Maintenance::run'),true))return false;
 m0_assert(($row['priority']??null)===20,'SEC1 hook priority');
 m0_assert(($row['hook']??null)===($row['callback']==='EIPSI_Security_Maintenance::register'?'init':'eipsi_process_nudge_jobs'),'SEC1 hook scope');return true;
}
