<?php
// Only disposable S0 HTTP mail transport; never included by the productive plugin.
if(getenv('WORDPRESS_DB_HOST')!=='eipsi-m0-db:3306'||getenv('WORDPRESS_DB_NAME')!=='m0')return;
add_filter('pre_wp_mail',static function($result,$atts){
 if(!get_option('eipsi_m0_isolated_install'))return$result;
 foreach((array)$atts['to']as$to)if(strpos($to,'@example.invalid')===false)return$result;
 if(get_option('eipsi_s0_deny_delivery'))return false;
 $mail=get_option('eipsi_s0_mail',array());$mail[]=$atts;update_option('eipsi_s0_mail',$mail,false);return true;
},PHP_INT_MAX,2);

foreach(array('login','register')as$kind){add_action('wp_ajax_nopriv_eipsi_s0_legacy_'.$kind,static function()use($kind){$method='handle_'.$kind;EIPSI_Participant_Auth_Handler::$method();});}
$deny=(int)get_option('eipsi_s0_deny_consume',0);
add_filter('query',static function($query)use($deny){global$wpdb;$id=$deny;if($id&&strpos($query,'UPDATE `'.$wpdb->prefix.'survey_magic_links`')===0&&strpos($query,'`id` = '.$id)!==false)return'UPDATE eipsi_s0_owned_nonexistent_table SET used_at=1';return$query;});
