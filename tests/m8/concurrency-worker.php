<?php
define('DOING_CRON',true);require __DIR__.'/../m0/bootstrap.php';
$dir=$argv[1]??'';$slot=$argv[2]??'';
if(!preg_match('#^/tmp/eipsi-m8-race-[a-zA-Z0-9]+$#',$dir)||!in_array($slot,['0','1'],true))exit(2);
$input=json_decode(file_get_contents($dir.'/input.json'),true);
if(!preg_match('/^m8_[a-f0-9]{8}_$/',$input['prefix']??''))exit(2);
$wpdb=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);$wpdb->set_prefix($input['prefix']);$wpdb->suppress_errors(true);wp_cache_flush();
touch($dir.'/ready'.$slot);$deadline=microtime(true)+25;
while(!file_exists($dir.'/start')){if(microtime(true)>$deadline)exit(3);usleep(10000);}
if($input['mode']==='interrupted'){add_filter('query',function($sql)use($dir,$wpdb){if(strpos($sql,'ALTER TABLE `'.$wpdb->prefix.'survey_studies` ADD COLUMN `end_date`')===0){touch($dir.'/paused');while(true){usleep(10000);}}return$sql;});}
try{$r=$input['mode']==='repair'?EIPSI_Schema_Repair_Service::sync_local_table('survey_studies'):(new EIPSI_Migration_Runner())->run();file_put_contents($dir.'/result'.$slot.'.json',wp_json_encode(['result'=>$r]));}
catch(Throwable$e){file_put_contents($dir.'/result'.$slot.'.json',wp_json_encode(['error'=>$e->getMessage()]));}ob_end_clean();
