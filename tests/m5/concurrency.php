<?php
function m5_concurrent($mode,$jobs=array(),$configs=array()) {
 $dir='/tmp/eipsi-m5-race-'.bin2hex(random_bytes(6));mkdir($dir);$workers=array();$pipes=array();
 file_put_contents($dir.'/input.json',wp_json_encode(array('mode'=>$mode,'assignment_id'=>992231,'job_ids'=>$jobs,'configs'=>$configs)));
 try {
  for($i=0;$i<2;$i++){$workers[$i]=proc_open(array(PHP_BINARY,__DIR__.'/concurrency-worker.php',$dir,(string)$i),array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('file',$dir.'/worker'.$i.'.log','a')),$pipes[$i]);m0_assert(is_resource($workers[$i]),'Cannot start worker');fclose($pipes[$i][0]);}
  $deadline=microtime(true)+25;
  while(!file_exists($dir.'/ready0')||!file_exists($dir.'/ready1')){m0_assert(microtime(true)<$deadline,'Barrier timeout');usleep(10000);}
  touch($dir.'/start');
  while(!file_exists($dir.'/result0.json')||!file_exists($dir.'/result1.json')){m0_assert(microtime(true)<$deadline,'Workers timeout');usleep(10000);}
  $results=array();foreach(array(0,1) as $i){$row=json_decode(file_get_contents($dir.'/result'.$i.'.json'),true);m0_assert(!isset($row['error']),'Worker exception: '.($row['error']??''));$results[]=$row['result'];}return $results;
 }finally{foreach($workers as $i=>$w){$state=proc_get_status($w);if($state['running']){proc_terminate($w);}fclose($pipes[$i][1]);proc_close($w);}foreach(glob($dir.'/*') as $p){unlink($p);}rmdir($dir);wp_cache_delete('cron','options');wp_cache_delete('alloptions','options');}
}
