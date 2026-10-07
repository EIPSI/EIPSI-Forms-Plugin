<?php
define('DOING_CRON',true);
require __DIR__.'/../m0/bootstrap.php';
require_once EIPSI_FORMS_PLUGIN_DIR.'includes/services/Wave_Service.php';
$dir=$argv[1]??'';$slot=$argv[2]??'';
if(!preg_match('#^/tmp/eipsi-s4-race-[a-z0-9]+$#',$dir)||!in_array($slot,array('0','1'),true))exit(2);
$input=json_decode(file_get_contents($dir.'/input.json'),true);$mode=$input['modes'][(int)$slot]??'';
m0_assert((int)$wpdb->get_var("SELECT study_id FROM {$wpdb->prefix}survey_assignments WHERE id=999432")===999403,'Owned fixture required');
touch($dir.'/ready'.$slot);$deadline=microtime(true)+15;
while(!file_exists($dir.'/start')){if(microtime(true)>$deadline)exit(3);usleep(10000);}
touch($dir.'/attempt'.$slot);
try{
 if($mode==='apply')$r=EIPSI_Longitudinal_T1_Recalculation_Service::recalculate_study(999403,1);
 elseif($mode==='deadline')$r=EIPSI_Longitudinal_Assignment_Deadline_Service::save_wave_configuration(999422,array(),true,60);
 elseif($mode==='submit')$r=EIPSI_Longitudinal_Assignment_Transition_Service::submit_locked(999407,999403,999422);
 else throw new RuntimeException('Unknown fixture mode');
 file_put_contents($dir.'/result'.$slot.'.json',wp_json_encode(array('mode'=>$mode,'result'=>is_wp_error($r)?array('error'=>$r->get_error_code()):$r)));
}catch(Throwable$e){file_put_contents($dir.'/result'.$slot.'.json',wp_json_encode(array('exception'=>$e->getMessage())));}
ob_end_clean();
