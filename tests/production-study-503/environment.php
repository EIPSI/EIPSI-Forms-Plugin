<?php
/** Disposable HTTP fixture only. Never installed by the plugin or included in its ZIP. */
if (getenv('WORDPRESS_DB_NAME') !== 'm0' || !get_option('eipsi_m0_isolated_install')) return;
$mode = get_option('eipsi_503_fixture_mode', 'VALID');
$seed=array('current'=>'HTTP_TEST','keys'=>array('HTTP_TEST'=>str_repeat('6b',32)));
$access=array('current'=>'ACCESS_TEST','keys'=>array('ACCESS_TEST'=>str_repeat('61',32)));
putenv('EIPSI_STUDY_ENGINE_KEYRING');putenv('EIPSI_STUDY_ENGINE_ACCESS_KEYRING');
if($mode==='INVALID_SEED')$seed['keys']['HTTP_TEST']='invalid';
if($mode==='MISSING_SEED_ACTIVE')$seed['current']='ABSENT';
if($mode==='INVALID_ACCESS')$access['keys']['ACCESS_TEST']='invalid';
if($mode==='MISSING_ACCESS_ACTIVE')$access['current']='ABSENT';
if($mode==='ENVIRONMENT'){
    putenv('EIPSI_STUDY_ENGINE_KEYRING='.json_encode($seed));putenv('EIPSI_STUDY_ENGINE_ACCESS_KEYRING='.json_encode($access));
}else{
    if($mode==='PRECEDENCE'){putenv('EIPSI_STUDY_ENGINE_KEYRING={"invalid":true}');putenv('EIPSI_STUDY_ENGINE_ACCESS_KEYRING={"invalid":true}');}
    if($mode==='INVALID_CONSTANT') {putenv('EIPSI_STUDY_ENGINE_KEYRING='.json_encode($seed));$seed='malformed';}
    if($mode!=='NO_SEED')define('EIPSI_STUDY_ENGINE_KEYRING',$mode==='JSON_CONSTANT'?json_encode($seed):$seed);
    if($mode!=='NO_ACCESS')define('EIPSI_STUDY_ENGINE_ACCESS_KEYRING',$mode==='JSON_CONSTANT'?json_encode($access):$access);
}
unset($seed,$access);
if ($mode === 'PROXY') { $_SERVER['HTTPS']='on'; $_SERVER['HTTP_X_FORWARDED_PROTO']='https'; }
if ($mode === 'DB_FAILURE') add_action('template_redirect', static function() {
    if (!isset($_GET['eipsi_study'])) return;
    global $wpdb; $original=$wpdb->options;
    // Core is loaded; fail the route's first alias SELECT, never issue a destructive query.
    $wpdb->options=$wpdb->prefix.'eipsi_503_absent_options';
    add_action('shutdown', static function()use($original){global $wpdb;$wpdb->options=$original;},-10000);
},-101);
unset($mode);
