<?php
function m7_concurrent($mode,$request_id=0) {
 $dir='/tmp/eipsi-m7-race-'.bin2hex(random_bytes(6));mkdir($dir);$workers=array();$pipes=array();
 file_put_contents($dir.'/input.json',wp_json_encode(array('mode'=>$mode,'participant_id'=>994707,'request_id'=>$request_id, 'config'=>m7_cfg())));
 try {
  for($i=0;$i<2;$i++){$workers[$i]=proc_open(array(PHP_BINARY,__DIR__.'/concurrency-worker.php',$dir,(string)$i),array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('file',$dir.'/worker'.$i.'.log','a')),$pipes[$i]);m0_assert(is_resource($workers[$i]),'Cannot start worker');fclose($pipes[$i][0]);}
  $deadline=microtime(true)+25;
  while(!file_exists($dir.'/ready0')||!file_exists($dir.'/ready1')){m0_assert(microtime(true)<$deadline,'Barrier timeout');usleep(10000);}
  touch($dir.'/start');
  while(!file_exists($dir.'/result0.json')||!file_exists($dir.'/result1.json')){m0_assert(microtime(true)<$deadline,'Workers timeout');usleep(10000);}
  $results=array();foreach(array(0,1) as $i){$row=json_decode(file_get_contents($dir.'/result'.$i.'.json'),true);m0_assert(!isset($row['error']),'Worker exception: '.($row['error']??''));$results[]=$row['result'];}return $results;
 }finally{foreach($workers as $i=>$w){$state=proc_get_status($w);if($state['running']){proc_terminate($w);}fclose($pipes[$i][1]);proc_close($w);}foreach(glob($dir.'/*') as $p){unlink($p);}rmdir($dir);wp_cache_delete('cron','options');wp_cache_delete('alloptions','options');}
}
