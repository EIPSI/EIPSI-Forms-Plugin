<?php
define('DOING_CRON',true);require __DIR__.'/../m0/bootstrap.php';
$dir=$argv[1]??'';$slot=$argv[2]??'';
if(!preg_match('#^/tmp/eipsi-m7-race-[a-zA-Z0-9]+$#',$dir)||!in_array($slot,array('0','1'),true)){exit(2);}
$input=json_decode(file_get_contents($dir.'/input.json'),true);m0_assert(($input['participant_id']??0)===994707,'Scope');touch($dir.'/ready'.$slot);$deadline=microtime(true)+20;
while(!file_exists($dir.'/start')){if(microtime(true)>$deadline){exit(3);}usleep(10000);}
try{
 if($input['mode']==='pool'){$r=(array)(new EIPSI_Pool_Assignment_Service())->assign_participant(994701,994707);}
 elseif($input['mode']==='complete'){$r=(new EIPSI_Pool_Completion_Service())->mark_completed(994701,994707,'m7');}
 elseif($input['mode']==='override'&&$slot==='0'){$r=EIPSI_Randomization_Override_Service::save('m7-config','fixture',$input['config']['formularios'][1]['id'],'fixture',1,null);}
 else{$r=EIPSI_Randomization_Assignment_Service::resolve('m7-config',$input['config'],'fixture');}
 file_put_contents($dir.'/result'.$slot.'.json',wp_json_encode(array('result'=>$r)));
}catch(Throwable$e){file_put_contents($dir.'/result'.$slot.'.json',wp_json_encode(array('error'=>$e->getMessage())));}ob_end_clean();
