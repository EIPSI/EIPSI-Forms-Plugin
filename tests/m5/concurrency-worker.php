<?php
// A cron worker must not let the page-visit wake-up consume its fixture before the barrier.
define('DOING_CRON',true);
require __DIR__.'/../m0/bootstrap.php';
$dir=$argv[1]??'';$slot=$argv[2]??'';
if(!preg_match('#^/tmp/eipsi-m5-race-[a-zA-Z0-9]+$#',$dir)||!in_array($slot,array('0','1'),true)){exit(2);}
$input=json_decode(file_get_contents($dir.'/input.json'),true);
m0_assert(($input['assignment_id']??0)===992231,'Fixture scope');
$job=isset($input['job_ids'])?$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}survey_nudge_jobs WHERE id=%d",$input['job_ids'][(int)$slot])):null;
touch($dir.'/ready'.$slot);$deadline=microtime(true)+20;
while(!file_exists($dir.'/start')){if(microtime(true)>$deadline){exit(3);}usleep(10000);}
try {
 switch($input['mode']){
 case 'worker':$result=EIPSI_Notification_Nudge_Worker_Service::process_job($job);break;
 case 'claim':$result=EIPSI_Nudge_Job_Queue::mark_processing($job->id);break;
 case 'schedule':$result=EIPSI_Nudge_Event_Scheduler::schedule_nudge_sequence(992231);break;
 case 'reschedule':
 $config=$input['configs'][(int)$slot];
 $wpdb->update($wpdb->prefix.'survey_waves',array('nudge_config'=>wp_json_encode($config)),array('id'=>992221));
 $result=EIPSI_Nudge_Event_Scheduler::refresh_wave_follow_ups(992221,992231);break;
 default:throw new RuntimeException('Unknown mode');
 }
 file_put_contents($dir.'/result'.$slot.'.json',wp_json_encode(array('result'=>$result)));
}catch(Throwable $e){file_put_contents($dir.'/result'.$slot.'.json',wp_json_encode(array('error'=>$e->getMessage())));}
ob_end_clean();
