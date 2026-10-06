<?php
require __DIR__.'/../m0/bootstrap.php';
require_once EIPSI_FORMS_PLUGIN_DIR.'includes/services/Wave_Service.php';
$dir=$argv[1]??'';$slot=$argv[2]??'';
if(!preg_match('#^/tmp/eipsi-s1-race-[a-z0-9]+$#',$dir)||!in_array($slot,array('0','1'),true))exit(2);
$input=json_decode(file_get_contents($dir.'/input.json'),true);$mode=$input['modes'][(int)$slot]??'';
m0_assert((int)$wpdb->get_var("SELECT study_id FROM {$wpdb->prefix}survey_assignments WHERE id=997132")===997103,'Owned fixture required');
// Capture a stale expiration snapshot before the parent releases its row lock.
$old=$wpdb->get_row("SELECT * FROM {$wpdb->prefix}survey_assignments WHERE id=997132");
touch($dir.'/ready'.$slot);$deadline=microtime(true)+15;
while(!file_exists($dir.'/start')){if(microtime(true)>$deadline)exit(3);usleep(10000);}
touch($dir.'/attempt'.$slot);
try{
 if($mode==='submit')$r=EIPSI_Longitudinal_Assignment_Transition_Service::submit_locked(997107,997103,997122);
 elseif($mode==='expire')$r=EIPSI_Longitudinal_Assignment_Transition_Service::expire_snapshot($old,true);
 elseif($mode==='skip')$r=EIPSI_Longitudinal_Assignment_Transition_Service::skip_locked($old);
 elseif($mode==='deadline')$r=EIPSI_Longitudinal_Assignment_Deadline_Service::save_wave_configuration(997122,array(),true,1);
 else throw new RuntimeException('Unknown fixture mode');
 file_put_contents($dir.'/result'.$slot.'.json',wp_json_encode(array('mode'=>$mode,'result'=>is_wp_error($r)?array('error'=>$r->get_error_code()):$r)));
}catch(Throwable$e){file_put_contents($dir.'/result'.$slot.'.json',wp_json_encode(array('exception'=>$e->getMessage())));}
ob_end_clean();
