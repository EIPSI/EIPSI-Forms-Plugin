<?php
/** Separate real wpdb connection. Only the guarded disposable M0 database. */
require __DIR__.'/../m0/bootstrap.php';
$dir=$argv[1]??'';$slot=$argv[2]??'';
if (!preg_match('#^/tmp/eipsi-m4-race-[a-zA-Z0-9]+$#',$dir) || !in_array($slot,array('0','1'),true)) { exit(2); }
$input=json_decode(file_get_contents($dir.'/input.json'),true);
m0_assert(($input['context']['study_id']??0)===992203,'Fixture scope required');
touch($dir.'/ready'.$slot);
$deadline=microtime(true)+15;
while (!file_exists($dir.'/start')) { if(microtime(true)>$deadline){exit(3);} usleep(10000); }
$start=microtime(true);touch($dir.'/attempt'.$slot);
try {
    $result=$input['mode']==='submit'
        ? EIPSI_Longitudinal_Submission_Service::handle_submission($input['context'])
        : EIPSI_Longitudinal_Assignment_Transition_Service::change_snapshot(992231,'pending',$slot==='0'?'expired':'skipped');
    file_put_contents($dir.'/result'.$slot.'.json',wp_json_encode(array('result'=>$result,'elapsed'=>microtime(true)-$start,'finished_at'=>microtime(true))));
} catch(Throwable $e){ file_put_contents($dir.'/result'.$slot.'.json',wp_json_encode(array('error'=>$e->getMessage()))); }
ob_end_clean();
