<?php
function m4_concurrent($mode,$hold_lock=false) {
    global $wpdb;
    $dir='/tmp/eipsi-m4-race-'.bin2hex(random_bytes(6));mkdir($dir);$workers=array();$pipes=array();$locked=false;$released_at=null;
    file_put_contents($dir.'/input.json',wp_json_encode(array('mode'=>$mode,'context'=>m4_context())));
    try {
        if($hold_lock){$wpdb->query('START TRANSACTION');m0_assert($wpdb->get_row("SELECT id FROM {$wpdb->prefix}survey_assignments WHERE id=992231 FOR UPDATE"),'Parent lock failed');$locked=true;}
        for($i=0;$i<2;$i++){$workers[$i]=proc_open(array(PHP_BINARY,__DIR__.'/concurrency-worker.php',$dir,(string)$i),array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('file',$dir.'/worker'.$i.'.log','a')),$pipes[$i]);m0_assert(is_resource($workers[$i]),'Cannot start worker');fclose($pipes[$i][0]);}
        $deadline=microtime(true)+20;
        while(!file_exists($dir.'/ready0')||!file_exists($dir.'/ready1')){m0_assert(microtime(true)<$deadline,'Workers did not reach barrier');usleep(10000);}
        touch($dir.'/start');
        if($hold_lock){while(!file_exists($dir.'/attempt0')||!file_exists($dir.'/attempt1')){m0_assert(microtime(true)<$deadline,'Workers did not attempt transition');usleep(10000);}usleep(250000);m0_assert(!file_exists($dir.'/result0.json')&&!file_exists($dir.'/result1.json'),'Transition bypassed parent row lock');$released_at=microtime(true);$wpdb->query('COMMIT');$locked=false;}
        while(!file_exists($dir.'/result0.json')||!file_exists($dir.'/result1.json')){m0_assert(microtime(true)<$deadline,'Workers did not finish');usleep(10000);}
        $results=array();foreach(array(0,1) as $i){$result=json_decode(file_get_contents($dir.'/result'.$i.'.json'),true);m0_assert(!isset($result['error']),'Worker exception');$result['released_at']=$released_at;$results[]=$result;}
        return $results;
    } finally {
        if($locked){$wpdb->query('ROLLBACK');}
        foreach($workers as $i=>$worker){$status=proc_get_status($worker);if($status['running']){proc_terminate($worker);}if(is_resource($pipes[$i][1])){fclose($pipes[$i][1]);}proc_close($worker);}
        foreach(glob($dir.'/*') as $file){unlink($file);}rmdir($dir);
    }
}
