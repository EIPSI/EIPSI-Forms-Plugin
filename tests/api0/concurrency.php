<?php
/** Real independent PHP connections; production code has no test-only hooks. */
function a0_wait($path){$deadline=microtime(true)+20;while(!file_exists($path)){if(microtime(true)>$deadline)throw new RuntimeException('Concurrency barrier timed out');usleep(10000);}}
foreach(array('revoke','scopes','grants')as$mutation)a0_case('Concurrent authorization snapshot orders before '.$mutation.' and next request revalidates',function()use($mutation,$policy){
 $a=a0_key();$p=a0_auth($a);$dir=sys_get_temp_dir().'/eipsi-api0-'.bin2hex(random_bytes(8));mkdir($dir,0700);$input=array('id'=>$p->id(),'revision'=>1,'operation'=>$mutation,'input'=>a0_input($mutation==='scopes'?array('scopes'=>array()):array('studies'=>array($GLOBALS['studyB']))));file_put_contents($dir.'/input.json',json_encode($input));chmod($dir.'/input.json',0600);$process=null;
 try{
  $policy->run($p,'read:studies',$GLOBALS['studyA'],function($fresh)use($dir,&$process){$pipes=array();$process=proc_open(array(PHP_BINARY,__DIR__.'/worker.php',$dir),array(0=>array('pipe','r'),1=>array('file',$dir.'/stdout','w'),2=>array('file',$dir.'/stderr','w')),$pipes);a0_assert(is_resource($process));fclose($pipes[0]);a0_wait($dir.'/attempt');usleep(100000);a0_assert(!file_exists($dir.'/result.json'),'Mutation passed an in-flight key lock');return true;});
  a0_wait($dir.'/result.json');$result=json_decode(file_get_contents($dir.'/result.json'),true);a0_assert(proc_close($process)===0&&$result['ok'],'Mutation did not commit');$process=null;
  a0_error(function()use($p){a0_use($p);},$mutation==='revoke'?401:($mutation==='scopes'?403:404));
 }finally{if(is_resource($process))proc_terminate($process);foreach(glob($dir.'/*')as$f)unlink($f);rmdir($dir);}
});
