<?php
if(defined('DB_HOST')&&DB_HOST==='eipsi-m0-db:3306'&&DB_NAME==='m0'&&get_option('eipsi_m0_isolated_install')){
 add_filter('pre_wp_mail',function($pre,$mail){if(strpos(implode(',',(array)$mail['to']),'s3-')===false){return $pre;}update_option('eipsi_s3_mail',array('to'=>$mail['to'],'subject'=>$mail['subject']),false);return get_option('eipsi_s3_mail_failure')?false:true;},PHP_INT_MAX,2);
 // Test-only domain mutation/transaction trace, never SQL values or credentials.
 $GLOBALS['s3_query_trace']=array();
 $export_failure=get_option('eipsi_s3_export_sql_failure',false);
 add_filter('query',function($sql)use($export_failure){
  if(preg_match('/^(START TRANSACTION|COMMIT|ROLLBACK)/i',trim($sql))){$GLOBALS['s3_query_trace'][]=strtoupper(trim($sql));}
  if(preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP)\b/i',$sql,$m)&&preg_match('/survey_(assignments|waves|participants|audit_log|nudge_jobs)/',$sql,$table)){$GLOBALS['s3_query_trace'][]=strtoupper($m[1]).':'.$table[0];}
  if($export_failure&&preg_match('/^\s*SELECT.*FROM.*survey_participants/is',$sql)&&strpos($sql,'999203')!==false){return 'SELECT eipsi_s3_controlled_export_missing_column';}
  return $sql;
 });
 add_action('shutdown',function(){if(PHP_SAPI==='cli'){return;}file_put_contents('/tmp/eipsi-s3-http-trace.json',wp_json_encode($GLOBALS['s3_query_trace']));});
}
