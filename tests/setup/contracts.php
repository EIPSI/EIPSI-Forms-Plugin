<?php
/** Explicit Setup composition additions; existing inventory remains fully compared. */
function setup_include($file){return in_array($file,array('includes/setup/bootstrap.php','includes/setup/SetupStatus.php','includes/setup/SetupAdmin.php','includes/study-engine/configuration/ServerKeyrings.php','includes/study-engine/configuration/KeyringResolver.php','includes/study-engine/configuration/SecretStore.php'),true);}
function setup_hook($row){$expected=array('admin_post_eipsi_setup_keys'=>'EIPSI\\Setup\\SetupAdmin::post','admin_notices'=>'EIPSI\\Setup\\SetupAdmin::notice');$hook=$row['hook']??'';if(!isset($expected[$hook])||($row['callback']??'')!==$expected[$hook])return false;m0_assert($row['priority']===($hook==='admin_notices'?20:10)&&$row['accepted_args']===1,'Setup hook contract');return true;}

function setup_fingerprint($name,$hash){$entry=json_decode(file_get_contents(__DIR__.'/fingerprints.json'),true)[$name]??null;if(!$entry)return $hash;m0_assert($hash===$entry['before_sha256'],'Setup notice predecessor mismatch');return $entry['after_sha256'];}

function setup_asset_fingerprint($name,$hash){$entry=json_decode(file_get_contents(__DIR__.'/fingerprints.json'),true)['assets'][$name]??null;if(!$entry)return $hash;m0_assert($hash===$entry['before_sha256'],'Setup asset predecessor mismatch: '.$name);return $entry['after_sha256'];}
