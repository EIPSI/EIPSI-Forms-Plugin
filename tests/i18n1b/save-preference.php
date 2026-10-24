<?php
define('ABSPATH', __DIR__);
$GLOBALS['id']=7; $GLOBALS['allowed']=true; $GLOBALS['nonce_valid']=true; $GLOBALS['writes']=array();
function get_current_user_id(){return $GLOBALS['id'];}
function get_option($key,$default=false){return 'en';}
function current_user_can($cap){return in_array($cap,array('eipsi_access','eipsi_manage_settings'),true) && $GLOBALS['allowed'];}
function check_admin_referer($action){if($action!=='eipsi_admin_language'||!$GLOBALS['nonce_valid'])throw new RuntimeException('nonce');}
function wp_unslash($value){return $value;}
function wp_die($message,$title,$args){throw new RuntimeException($message.':'.$args['response']);}
function update_option($key,$value,$autoload){$GLOBALS['writes'][]=array($key,$value,$autoload);}
function admin_url($path){return '/wp-admin/'.$path;}
function wp_safe_redirect($url){throw new RuntimeException('redirect:'.$url);}
require __DIR__.'/../../includes/authorization/Policy.php';
require __DIR__.'/../../includes/i18n/admin.php';
$n=0;
function test($yes,$name){global $n;if(!$yes)throw new RuntimeException($name);++$n;echo "PASS $name\n";}
function save(){try{return eipsi_admin_language_save();}catch(RuntimeException $e){return $e->getMessage();}}
test(save()==='' && count($GLOBALS['writes'])===0,'Unrelated POST never writes preference');
$_POST=array('eipsi_save_admin_language'=>'1','eipsi_admin_language'=>'es','user_id'=>99);
test(strpos(save(),'redirect:')===0,'Successful save refreshes administrative UI');
test($GLOBALS['writes']===array(array('eipsi_forms_language','es',false)),'Writes plugin setting only, ignores submitted user id');
$_POST['eipsi_admin_language']='en';save();test(end($GLOBALS['writes'])[1]==='en','English can be persisted');
foreach(array('fr','es_ES',array('es'),'') as $invalid){$_POST['eipsi_admin_language']=$invalid;$before=count($GLOBALS['writes']);save();test(count($GLOBALS['writes'])===$before,'Invalid value rejected '.json_encode($invalid));}
$_POST['eipsi_admin_language']='es';$GLOBALS['allowed']=false;$before=count($GLOBALS['writes']);test(save()==='EIPSI_LANGUAGE_FORBIDDEN:403'&&count($GLOBALS['writes'])===$before,'Unauthorized save rejected');
$GLOBALS['allowed']=true;$GLOBALS['nonce_valid']=false;test(save()==='nonce'&&count($GLOBALS['writes'])===$before,'CSRF rejected');
echo "$n tests, 0 failures\n";
