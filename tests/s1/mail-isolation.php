<?php
if(getenv('WORDPRESS_DB_HOST')!=='eipsi-m0-db:3306'||getenv('WORDPRESS_DB_NAME')!=='m0')return;
add_filter('pre_wp_mail',static function($result,$atts){if(!get_option('eipsi_m0_isolated_install'))return$result;foreach((array)$atts['to']as$to)if(strpos($to,'@example.invalid')===false)return$result;return true;},PHP_INT_MAX,2);

$move=(bool)get_option('eipsi_s1_move_deadline',false);
add_filter('query',static function($query)use($move){global$wpdb;static$done=false;if($move&&!$done&&strpos($query,'INSERT INTO `'.$wpdb->prefix.'vas_form_results`')===0&&strpos($query,'s1-t2')!==false){$done=true;$wpdb->update($wpdb->prefix.'survey_assignments',array('due_at'=>date('Y-m-d H:i:s',current_time('timestamp')-1)),array('id'=>997132,'study_id'=>997103));}return$query;});
add_action('eipsi_form_submitted',static function($context){if(($context['survey_id']??0)!==997103)return;$rows=get_option('eipsi_s1_completion',array());$rows[]=$context;update_option('eipsi_s1_completion',$rows,false);});
