<?php
require __DIR__.'/../m0/bootstrap.php';
$result=array('plugin_active'=>is_plugin_active('EIPSI-Forms-Plugin/eipsi-forms.php'),'shortcodes'=>array(),'blocks'=>array(),'admin'=>array());
m0_assert($result['plugin_active'],'Plugin inactive');
foreach(array('eipsi_form','eipsi_survey_login','eipsi_participant_dashboard','eipsi_longitudinal_study','eipsi_randomized_form','eipsi_randomization','eipsi_pool','eipsi_pool_join','eipsi_randomized_form_page')as$tag){
 m0_assert(isset($GLOBALS['shortcode_tags'][$tag])&&is_callable($GLOBALS['shortcode_tags'][$tag]),'Invalid shortcode '.$tag);
 // Deliberately invalid zero IDs exercise guards without creating participant assignments.
 $html=do_shortcode('['.$tag.' id="0" pool_id="0" study_id="0" survey_id="0" template="0"]');m0_assert(is_string($html),'Shortcode result '.$tag);$result['shortcodes'][$tag]=array('invoked'=>true,'bytes'=>strlen($html),'scope'=>'guard path; valid basic form covered by M0 HTTP smoke');
}
foreach(WP_Block_Type_Registry::get_instance()->get_all_registered()as$name=>$block){if(strpos($name,'eipsi/')!==0)continue;$html=render_block(array('blockName'=>$name,'attrs'=>array(),'innerBlocks'=>array(),'innerHTML'=>'','innerContent'=>array('')));m0_assert(is_string($html),'Block render failed '.$name);$result['blocks'][$name]=array('rendered'=>true,'bytes'=>strlen($html),'dynamic'=>(bool)$block->render_callback);}
foreach(array('page=eipsi-configuration&tab=schema-status','page=eipsi-results-experience&tab=randomization','page=eipsi-longitudinal-study&tab=pool-hub','page=eipsi-longitudinal-study&tab=dashboard-study')as$query){$r=m0_http('/wp-admin/admin.php?'.$query,null,true);$status=wp_remote_retrieve_response_code($r);$body=wp_remote_retrieve_body($r);m0_assert($status===200&&strpos($body,'There has been a critical error')===false,'Admin failed '.$query);$result['admin'][$query]=array('status'=>$status,'bytes'=>strlen($body));}
m0_assert(count($result['blocks'])===13,'Blocks missing');ob_end_clean();echo wp_json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
