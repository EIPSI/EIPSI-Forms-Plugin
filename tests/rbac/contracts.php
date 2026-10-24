<?php
/** Explicit RBAC inventory additions; no filtering of arbitrary hooks or source changes. */
function rbac_include($file){return in_array($file,array('includes/authorization/bootstrap.php','includes/authorization/Policy.php'),true);}
function rbac_hook($row){
 $expected=array(
  'plugins_loaded'=>array('EIPSI_Admin_Authorization_Policy::upgrade',1),
  'switch_blog'=>array('EIPSI_Admin_Authorization_Policy::switched',20),
  'wp_initialize_site'=>array('EIPSI_Admin_Authorization_Policy::initialized',20),
  'editable_roles'=>array('EIPSI_Admin_Authorization_Policy::roleLabels',10)
 );$hook=$row['hook']??'';if(!isset($expected[$hook])||($row['callback']??'')!==$expected[$hook][0])return false;
 m0_assert($row['priority']===$expected[$hook][1]&&$row['accepted_args']===1,'RBAC hook contract');return true;
}
function rbac_fingerprint($section,$name,$hash){
 $entry=json_decode(file_get_contents(__DIR__.'/fingerprints.json'),true)[$section][$name]??null;
 if(!$entry)return $hash;m0_assert($hash===$entry['before_sha256'],'RBAC predecessor mismatch: '.$name);return $entry['after_sha256'];
}
