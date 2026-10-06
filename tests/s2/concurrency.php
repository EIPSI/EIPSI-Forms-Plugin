<?php
function s2_race($id,$modes,$after_start=null) {
    $dir='/tmp/eipsi-s2-race-'.bin2hex(random_bytes(6));mkdir($dir);$workers=array();$pipes=array();
    file_put_contents($dir.'/input.json',wp_json_encode(array('id'=>$id,'modes'=>$modes)));
    try {
        for($i=0;$i<2;$i++){$workers[$i]=proc_open(array(PHP_BINARY,__DIR__.'/worker.php',$dir,(string)$i),array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('file',$dir.'/worker'.$i.'.log','a')),$pipes[$i]);m0_assert(is_resource($workers[$i]),'Process failed');fclose($pipes[$i][0]);}
        $deadline=microtime(true)+25;
        while(!file_exists($dir.'/ready0')||!file_exists($dir.'/ready1')){m0_assert(microtime(true)<$deadline,'Ready timeout');usleep(10000);}touch($dir.'/start');if($after_start){$after_start($dir);}
        while(!file_exists($dir.'/result0.json')||!file_exists($dir.'/result1.json')){m0_assert(microtime(true)<$deadline,'Result timeout');usleep(10000);}
        $results=array();foreach(array(0,1) as $i){$r=json_decode(file_get_contents($dir.'/result'.$i.'.json'),true);m0_assert(!isset($r['error']),$r['error']??'');$results[]=$r['result'];}return $results;
    }finally{foreach($workers as $i=>$p){$state=proc_get_status($p);if($state['running']){proc_terminate($p);}fclose($pipes[$i][1]);proc_close($p);}foreach(glob($dir.'/*') as $f){unlink($f);}rmdir($dir);}
}
