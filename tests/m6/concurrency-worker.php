<?php
define('DOING_CRON',true);
require __DIR__.'/../m0/bootstrap.php';
require_once EIPSI_FORMS_PLUGIN_DIR.'admin/services/class-participant-data-cleanup.php';
require_once EIPSI_FORMS_PLUGIN_DIR.'admin/data-safety-system.php';
$dir=$argv[1]??'';$slot=$argv[2]??'';
if(!preg_match('#^/tmp/eipsi-m6-race-[a-zA-Z0-9]+$#',$dir)||!in_array($slot,array('0','1'),true)){exit(2);}
$input=json_decode(file_get_contents($dir.'/input.json'),true);
m0_assert(($input['participant_id']??0)===993607,'Fixture scope');
touch($dir.'/ready'.$slot);$deadline=microtime(true)+20;
while(!file_exists($dir.'/start')){if(microtime(true)>$deadline){exit(3);}usleep(10000);}
try{
 if($input['mode']==='approve'){$r=EIPSI_Participant_Data_Request_Service::process_request($input['request_id'],'approve');}
 elseif(in_array($input['mode'],array('fallback','emergency'),true)){
 $data=array('form_id'=>'m6-race','form_name'=>'m6-race','participant_id'=>'993607','survey_id'=>993603,'form_responses'=>'{"answer":4}','created_at'=>current_time('mysql'));
 if($input['mode']==='emergency'){$data['m6_nonexistent_column']='controlled';$r=eipsi_safety_save_with_retry($data,1);}else{$r=EIPSI_Submission_Storage_Service::attempt($data);}
 }
 elseif($slot==='0'){$r=EIPSI_Participant_Data_Cleanup::run(993607,'anonymize');}
 else{$r=EIPSI_Submission_Storage_Service::attempt(array('form_id'=>'m6-race','form_name'=>'m6-race','participant_id'=>'993607','survey_id'=>993603,'form_responses'=>'{"answer":4}','created_at'=>current_time('mysql')));}
 file_put_contents($dir.'/result'.$slot.'.json',wp_json_encode(array('result'=>$r)));
}catch(Throwable $e){file_put_contents($dir.'/result'.$slot.'.json',wp_json_encode(array('error'=>$e->getMessage())));}
ob_end_clean();
