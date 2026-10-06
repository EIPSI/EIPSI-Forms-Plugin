<?php
function s1_concurrent($modes,$change=null,$wait_available=false){
 global$wpdb;$dir='/tmp/eipsi-s1-race-'.bin2hex(random_bytes(6));mkdir($dir);$workers=array();$pipes=array();$locked=false;
 file_put_contents($dir.'/input.json',wp_json_encode(array('modes'=>$modes)));
 try{
  m0_assert($wpdb->query('START TRANSACTION')!==false,'Parent transaction failed');$locked=true;m0_assert($wpdb->get_row("SELECT * FROM {$wpdb->prefix}survey_assignments WHERE participant_id=997107 AND study_id=997103 AND wave_id=997122 FOR UPDATE"),'Parent lock failed');
  foreach($modes as$i=>$mode){$workers[$i]=proc_open(array(PHP_BINARY,__DIR__.'/concurrency-worker.php',$dir,(string)$i),array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('file',$dir.'/worker'.$i.'.log','a')),$pipes[$i]);m0_assert(is_resource($workers[$i]),'Worker could not start');fclose($pipes[$i][0]);}
  $deadline=microtime(true)+20;
  foreach($modes as$i=>$mode)while(!file_exists($dir.'/ready'.$i)){m0_assert(microtime(true)<$deadline,'Worker readiness timeout');usleep(10000);}
  touch($dir.'/start');foreach($modes as$i=>$mode)while(!file_exists($dir.'/attempt'.$i)){m0_assert(microtime(true)<$deadline,'Worker did not attempt');usleep(10000);}
  usleep(150000);foreach($modes as$i=>$mode)m0_assert(!file_exists($dir.'/result'.$i.'.json'),'Writer bypassed held lock');
  if($wait_available){$available=s1_assignment()->available_at;while(current_time('mysql')<$available){m0_assert(microtime(true)<$deadline,'Clock barrier timeout');usleep(10000);}}
  if($change)s1_set($change);
  m0_assert($wpdb->query('COMMIT')!==false,'Parent commit failed');$locked=false;
  foreach($modes as$i=>$mode)while(!file_exists($dir.'/result'.$i.'.json')){m0_assert(microtime(true)<$deadline,'Worker completion timeout');usleep(10000);}
  $r=array();foreach($modes as$i=>$mode){$row=json_decode(file_get_contents($dir.'/result'.$i.'.json'),true);m0_assert($row&&!isset($row['exception']),'Worker exception: '.($row['exception']??''));$r[]=$row;}return$r;
 }finally{
  if($locked)$wpdb->query('ROLLBACK');foreach($workers as$i=>$worker){if(proc_get_status($worker)['running'])proc_terminate($worker);if(is_resource($pipes[$i][1]))fclose($pipes[$i][1]);proc_close($worker);}foreach(glob($dir.'/*')as$file)unlink($file);rmdir($dir);
 }
}
