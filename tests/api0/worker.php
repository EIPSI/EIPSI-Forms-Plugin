<?php
define('DOING_CRON',true);define('WP_ENVIRONMENT_TYPE','local');require dirname(__DIR__).'/m0/bootstrap.php';
$dir=$argv[1]??'';if(!preg_match('#^/tmp/eipsi-api0-[a-f0-9]{16}$#D',$dir))exit(1);
$input=json_decode(file_get_contents($dir.'/input.json'),true);touch($dir.'/attempt');
try{$keys=new EIPSI\ExternalApi\Keys();$request=EIPSI\ExternalApi\Authenticator::requestId();if($input['operation']==='revoke')$keys->revoke($input['id'],$input['revision'],1,$request);else $keys->update($input['id'],$input['revision'],$input['input'],1,$request);file_put_contents($dir.'/result.json',json_encode(array('ok'=>true)));exit(0);}catch(Throwable$e){file_put_contents($dir.'/result.json',json_encode(array('ok'=>false)));exit(1);}
