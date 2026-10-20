<?php
define('ABSPATH',__DIR__);define('EIPSI_FORMS_PLUGIN_DIR',dirname(__DIR__,2).'/');define('EIPSI_FORMS_PLUGIN_URL','/plugin/');
$GLOBALS['calls']=array();
function get_current_user_id(){return 1;}
function get_option($key,$default=false){return 'es';}
function sanitize_key($s){return preg_replace('/[^a-z0-9_-]/','',strtolower($s));}
function wp_enqueue_script(...$args){$GLOBALS['calls'][]=array('script',$args);}
function wp_add_inline_script(...$args){$GLOBALS['calls'][]=array('inline',$args);}
function wp_json_encode($value){return json_encode($value);}
require __DIR__.'/../../includes/i18n/admin.php';
$n=0;
foreach(array('', 'plugins', 'other-plugin', 'eipsi_native') as $page){$_GET=array('page'=>$page);eipsi_admin_i18n_assets();if($GLOBALS['calls'])throw new Exception('Leaked assets: '.$page);++$n;}
foreach(array('eipsi-configuration','eipsi-email-templates','eipsi-assessment-editor','eipsi-study-builder') as $page){$GLOBALS['calls']=array();$_GET=array('page'=>$page);eipsi_admin_i18n_assets();if(count($GLOBALS['calls'])!==2||strpos($GLOBALS['calls'][1][1][1],'"language":"es"')===false)throw new Exception('Missing context: '.$page);++$n;}
echo "$n tests, 0 failures\n";
