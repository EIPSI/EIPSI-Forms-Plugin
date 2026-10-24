<?php
// Isolated boundary fixture: no installation or real configuration is loaded.
function current_user_can($cap){return in_array($cap,array('eipsi_access','eipsi_manage_scheduler'),true);}
function admin_url(){return 'https://fixture.invalid/wp-admin/';}
function wp_verify_nonce($nonce,$action){return $nonce==='valid'&&$action==='eipsi_scheduler';}
function is_multisite(){return true;}
define('ABSPATH',__DIR__);
function get_current_user_id(){return 1;}
function get_option($key,$default=false){if($key!=='eipsi_forms_language')throw new RuntimeException('Unexpected option read before multisite denial');return $default;}
require dirname(__DIR__,2).'/includes/i18n/admin.php';
require dirname(__DIR__,2).'/includes/security/Http.php';
require __DIR__.'/../../includes/authorization/Policy.php';
require dirname(__DIR__,2).'/includes/scheduler/Admin.php';
$_SERVER['REQUEST_METHOD']='POST';
try {
    EIPSI\Scheduler\Admin::dispatch(array('operation'=>'toggle','disabled'=>false,'expected'=>str_repeat('0',64),'nonce'=>'valid'));
} catch(RuntimeException$e) {
    if($e->getCode()===403){echo "PASS multisite installation-wide write blocked\n";exit(0);}
}
exit(1);
