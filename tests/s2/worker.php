<?php
define('DOING_CRON',true);
require __DIR__.'/../m0/bootstrap.php';
$dir=$argv[1]??'';$slot=$argv[2]??'';
if(!preg_match('#^/tmp/eipsi-s2-race-[a-f0-9]+$#',$dir)||!in_array($slot,array('0','1'),true)){exit(2);}
$input=json_decode(file_get_contents($dir.'/input.json'),true);$id=intval($input['id']);
$job=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}survey_nudge_jobs WHERE id=%d",$id));
m0_assert($job&&json_decode($job->payload,true)['assignment_id']===998231,'Owned fixture required');
touch($dir.'/ready'.$slot);$deadline=microtime(true)+20;
while(!file_exists($dir.'/start')){m0_assert(microtime(true)<$deadline,'Start timeout');usleep(10000);}
try{
    switch($input['modes'][(int)$slot]){
        case 'held_worker':
            m0_assert(EIPSI_Notification_Nudge_Queue_Service::mark_processing($id),'Held claim');touch($dir.'/claimed');
            while(!file_exists($dir.'/continue')){m0_assert(microtime(true)<$deadline,'Held timeout');usleep(10000);}
            $result=array('delivered'=>wp_mail('s2@example.invalid','Held worker','controlled'),'completed'=>EIPSI_Notification_Nudge_Queue_Service::mark_completed($id));break;
        case 'worker':$result=EIPSI_Notification_Nudge_Worker_Service::process_job($job);$result['intercepted_mail_count']=count($GLOBALS['m0_mail']??array());break;
        case 'recover':$result=EIPSI_Notification_Nudge_Queue_Service::recover_stale_processing();break;
        case 'cancel':$result=EIPSI_Notification_Nudge_Queue_Service::cancel_jobs_for_assignment(998231);break;
        case 'complete':$result=EIPSI_Notification_Nudge_Queue_Service::mark_completed($id);break;
        case 'retry':$result=EIPSI_Notification_Nudge_Queue_Service::mark_processing($id)?EIPSI_Notification_Nudge_Queue_Service::persist_retry_outcome($id,'Controlled delivery failure'):'lost';break;
        case 'crash':$result=EIPSI_Notification_Nudge_Queue_Service::mark_processing($id);break;
        case 'crash_after_mail':case 'disconnect':
            m0_assert(EIPSI_Notification_Nudge_Queue_Service::mark_processing($id),'Claim');
            $delivered=wp_mail('s2@example.invalid','S2 crash window','controlled');
            $result=array('delivered'=>$delivered);
            if($input['modes'][(int)$slot]==='disconnect'){
                // Close the real connection after mail; reconnect loses its advisory lock.
                $wpdb->close();$wpdb->db_connect();
                $result['completed']=EIPSI_Notification_Nudge_Queue_Service::mark_completed($id);
            }
            break;
        case 'noop':$result=null;break;
        default:throw new RuntimeException('Unknown mode');
    }
    file_put_contents($dir.'/result'.$slot.'.json',wp_json_encode(array('result'=>$result)));
}catch(Throwable $e){file_put_contents($dir.'/result'.$slot.'.json',wp_json_encode(array('error'=>$e->getMessage())));}
// Exit without terminal persistence models a crashed worker: DB releases connection lock.
ob_end_clean();exit;
