<?php
define('DOING_CRON',true);define('WP_ENVIRONMENT_TYPE','local');require dirname(__DIR__).'/m0/bootstrap.php';
$dir=$argv[1]??'';if(!preg_match('#^/tmp/eipsi-api2b-[a-f0-9]{16}$#D',$dir))exit(1);
$input=json_decode(stream_get_contents(STDIN),true);touch($dir.'/attempt');
$observer=static function($point)use($dir,$input){if($point!==($input['pause']??null))return;touch($dir.'/paused');$deadline=microtime(true)+20;while(!file_exists($dir.'/release')){if(microtime(true)>$deadline)throw new RuntimeException('Worker barrier timeout');usleep(10000);}};
try{$api=new EIPSI\ExternalApi\DatasetSnapshots(null,$observer);
 if($input['op']==='work')$data=array('progress'=>$api->work($input['export']));
 else{$p=(new EIPSI\ExternalApi\Authenticator())->authenticate('Bearer '.$input['secret'],EIPSI\ExternalApi\Authenticator::requestId(),'api2b-worker');if($input['op']==='create')$data=$api->create($p,$input['code'],array('format'=>'json'));else{$d=$api->download($p,$input['export']);$data=array('sha256'=>$d->sha256);$d->close();}}
 $r=array('status'=>200,'data'=>$data);
}catch(EIPSI\ExternalApi\ApiError$e){$r=array('status'=>$e->getCode(),'code'=>$e->apiCode);}catch(Throwable$e){$r=array('status'=>503,'code'=>'WORKER_FAILED');}
file_put_contents($dir.'/result.json.tmp',json_encode($r));rename($dir.'/result.json.tmp',$dir.'/result.json');exit(0);
