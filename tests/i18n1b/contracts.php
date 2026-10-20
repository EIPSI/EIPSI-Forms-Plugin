<?php
/** Only declared I18N-1B administrative boundaries; historical snapshots remain unchanged. */
function i18n1b_hook($row) {
 $map=array('eipsi_admin_language_submit'=>'admin_init','eipsi_admin_i18n_assets'=>'admin_enqueue_scripts');
 $cb=$row['callback']??'';if(!isset($map[$cb]))return false;
 m0_assert($row['hook']===$map[$cb]&&$row['priority']===0&&$row['accepted_args']===1,'I18N1B administrative hook contract');return true;
}
function i18n1b_include($file){return $file==='includes/i18n/admin.php';}
function i18n1b_assets($rows){
 $out=array();foreach($rows as$row){
  if($row['handle']!=='eipsi-admin-i18n'){$out[]=$row;continue;}
  m0_assert($row['kind']==='script'&&$row['deps']===array()&&$row['group']===null&&$row['enqueued']===true,'I18N1B head script contract');
  m0_assert($row['src']===EIPSI_FORMS_PLUGIN_URL.'assets/js/admin-i18n.js'&&(string)$row['version']===(string)filemtime(EIPSI_FORMS_PLUGIN_DIR.'assets/js/admin-i18n.js'),'I18N1B script path/cache version');
 }return $out;
}
function i18n1b_fingerprint($section,$name,$hash){
 foreach(array(__DIR__.'/presentation-boundaries.json',dirname(__DIR__).'/i18n1d/presentation-boundaries.json') as $file){
  $delta=json_decode(file_get_contents($file),true)[$section][$name]??null;
  if(!$delta)continue;
  m0_assert($hash===$delta['before_sha256'],'I18N presentation predecessor mismatch: '.$name);$hash=$delta['after_sha256'];
 }
 return $hash;
}
