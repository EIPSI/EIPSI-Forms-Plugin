<?php
if (PHP_SAPI !== 'cli') { exit; }
define('EIPSI_P1C_TESTS',true);
require __DIR__.'/bootstrap-p1.php';
require EIPSI_FORMS_PLUGIN_DIR.'admin/config/longitudinal-config.php';
if(!class_exists('WP_Error')){require ABSPATH.'wp-includes/class-wp-error.php';}
function sanitize_textarea_field($value){return sanitize_text_field($value);}
function eipsi_user_can_manage_longitudinal(){return $GLOBALS['p0_admin'];}
function get_current_user_id(){return $GLOBALS['p0_admin']?1:0;}
function wp_get_current_user(){return (object)array('ID'=>get_current_user_id(),'user_login'=>'fixture');}
function wp_create_nonce($action){return 'valid:'.$action;}
function admin_url($path){return 'https://example.invalid/wp-admin/'.$path;}
function wp_mkdir_p($path){return is_dir($path)||mkdir($path,0755,true);}
function get_temp_dir(){return $GLOBALS['p1c_temp'];}
function plugin_dir_path($file){return dirname($file).'/';}
function parse_blocks($value){return array();}
function date_i18n($format,$timestamp=null){return date($format,$timestamp??time());}
require EIPSI_FORMS_PLUGIN_DIR.'admin/services/class-export-service.php';
require EIPSI_FORMS_PLUGIN_DIR.'admin/services/class-participant-data-request-service.php';
require EIPSI_FORMS_PLUGIN_DIR.'admin/services/class-anonymize-service.php';
require EIPSI_FORMS_PLUGIN_DIR.'admin/services/class-participant-data-cleanup.php';
require EIPSI_FORMS_PLUGIN_DIR.'admin/ajax-phase3-handlers.php';
function p1c_seed_response($db){
    $data=array('form_id'=>generate_stable_form_id('long-form'),'form_name'=>'long-form','participant_id'=>'7','survey_id'=>3,'wave_index'=>2,
        'session_id'=>'browser-fixture','user_fingerprint'=>'fingerprint-fixture','form_responses'=>'{"email":"p7@example.invalid","answer":4,"password_hash":"secret","session_token":"secret"}',
        'metadata'=>'{"email":"p7@example.invalid","ip_address":"203.0.113.17"}','ip_address'=>'203.0.113.17','created_at'=>current_time('mysql'),'submitted_at'=>current_time('mysql'));
    p0_assert($db->insert($db->prefix.'vas_form_results',$data)!==false,'Response fixture failed');return (int)$db->insert_id;
}
function p1c_request($db){
    p1_session();$result=EIPSI_Participant_Data_Request_Service::submit_request(7,'export');
    p0_assert($result['success'],'Request fixture failed');
    return (int)$db->get_var("SELECT id FROM {$db->prefix}survey_data_requests WHERE participant_id=7");
}
function p1c_config($form='anonymous-form'){
    $config=get_privacy_defaults();foreach(array('ip_address','user_agent_full','device_type','browser','os','screen_width','fingerprint_enabled')as$key){$config[$key]=false;}
    $GLOBALS['p0_options']['eipsi_privacy_config_'.generate_stable_form_id($form)]=$config;return $config;
}
require_once EIPSI_FORMS_PLUGIN_DIR."admin/data-safety-system.php";
require_once EIPSI_FORMS_PLUGIN_DIR."admin/database.php";
require_once EIPSI_FORMS_PLUGIN_DIR."includes/forms/bootstrap.php";
$tests=array();
require __DIR__."/m6/cases.php";
if (getenv('EIPSI_M6_CASE')) { $tests=array_filter($tests,function($name){return preg_match(getenv('EIPSI_M6_CASE'),$name);},ARRAY_FILTER_USE_KEY); }
$export_directory_existed=is_dir(EIPSI_FORMS_PLUGIN_DIR.'exports');
$failed=0;
foreach($tests as$name=>$test){
    $temp=sys_get_temp_dir().'/eipsi-p1c-'.bin2hex(random_bytes(6));mkdir($temp,0700);$GLOBALS['p1c_temp']=$temp;$GLOBALS['p1c_exports']=array();
    try{p1_fixture($test);echo "PASS $name\n";}catch(Throwable$error){$failed++;echo "FAIL $name: {$error->getMessage()}\n";}
    finally{foreach(glob($temp.'/*')as$file){unlink($file);}rmdir($temp);foreach($GLOBALS['p1c_exports']as$file){if(is_file($file)){unlink($file);}}}
}
if(!$export_directory_existed && is_dir(EIPSI_FORMS_PLUGIN_DIR.'exports')){rmdir(EIPSI_FORMS_PLUGIN_DIR.'exports');}
echo count($tests).' tests, '.$failed." failures\n";ob_end_flush();exit($failed?1:0);
