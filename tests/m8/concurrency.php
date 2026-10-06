<?php
function m8_concurrent($mode,$request_id=0) {
 $dir='/tmp/eipsi-m8-race-'.bin2hex(random_bytes(6));mkdir($dir);$workers=array();$pipes=array();
 file_put_contents($dir.'/input.json',wp_json_encode(array('mode'=>$mode,'prefix'=>$GLOBALS['wpdb']->prefix)));
 try {
  for($i=0;$i<2;$i++){$workers[$i]=proc_open(array(PHP_BINARY,__DIR__.'/concurrency-worker.php',$dir,(string)$i),array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('file',$dir.'/worker'.$i.'.log','a')),$pipes[$i]);m0_assert(is_resource($workers[$i]),'Cannot start worker');fclose($pipes[$i][0]);}
  $deadline=microtime(true)+25;
  while(!file_exists($dir.'/ready0')||!file_exists($dir.'/ready1')){m0_assert(microtime(true)<$deadline,'Barrier timeout');usleep(10000);}
  touch($dir.'/start');
  while(!file_exists($dir.'/result0.json')||!file_exists($dir.'/result1.json')){m0_assert(microtime(true)<$deadline,'Workers timeout');usleep(10000);}
  $results=array();foreach(array(0,1) as $i){$row=json_decode(file_get_contents($dir.'/result'.$i.'.json'),true);m0_assert(!isset($row['error']),'Worker exception: '.($row['error']??''));$results[]=$row['result'];}return $results;
 }finally{foreach($workers as $i=>$w){$state=proc_get_status($w);if($state['running']){proc_terminate($w);}fclose($pipes[$i][1]);proc_close($w);}foreach(glob($dir.'/*') as $p){unlink($p);}rmdir($dir);wp_cache_delete('cron','options');wp_cache_delete('alloptions','options');}
}

function m8_interrupt() {
 global $wpdb;
 $dir='/tmp/eipsi-m8-race-'.bin2hex(random_bytes(6));mkdir($dir);$worker=null;$pipes=[];
 file_put_contents($dir.'/input.json',wp_json_encode(['mode'=>'interrupted','prefix'=>$wpdb->prefix]));
 try {
  $worker=proc_open([PHP_BINARY,__DIR__.'/concurrency-worker.php',$dir,'0'],[0=>['pipe','r'],1=>['file',$dir.'/stdout.log','a'],2=>['file',$dir.'/stderr.log','a']],$pipes);m0_assert(is_resource($worker),'Start interrupted worker');fclose($pipes[0]);touch($dir.'/start');$deadline=microtime(true)+25;
  while(!file_exists($dir.'/paused')){m0_assert(microtime(true)<$deadline,'Interrupted worker did not reach ALTER');usleep(10000);}
  proc_terminate($worker,9);proc_close($worker);$worker=null;
 } finally {
  if(is_resource($worker)){proc_terminate($worker,9);proc_close($worker);}
  foreach(glob($dir.'/*')as$p)unlink($p);rmdir($dir);wp_cache_flush();
 }
}
