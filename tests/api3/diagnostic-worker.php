<?php
$input=json_decode(stream_get_contents(STDIN),true);$mode=$input['mode']??'';if(!in_array($mode,array('production_http','production_proxy','production_https','cli'),true))exit(1);
define('DOING_CRON',true);define('WP_ENVIRONMENT_TYPE',$mode==='cli'?'local':'production');require dirname(__DIR__).'/m0/bootstrap.php';
try{
 if($mode==='cli'){
  class WP_CLI {public static $commands=array();public static $lines=array();public static function add_command($name,$class){self::$commands[$name]=$class;}public static function line($line){self::$lines[]=$line;}}
  define('WP_CLI',true);require EIPSI_FORMS_PLUGIN_DIR.'includes/external-api/OperationsCli.php';$c=new EIPSI\ExternalApi\OperationsCli();$c->status();$c->cleanup();$c->maintenance();$result=array('commands'=>WP_CLI::$commands,'lines'=>array_map(static function($x){return json_decode($x,true);},WP_CLI::$lines));
 }else{
  $_SERVER['SERVER_PORT']=80;unset($_SERVER['HTTPS'],$_SERVER['HTTP_X_FORWARDED_PROTO']);
  if($mode==='production_proxy'){$_SERVER['HTTP_X_FORWARDED_PROTO']='https';$_SERVER['HTTPS']='on';}
  if($mode==='production_https'){$_SERVER['HTTPS']='on';$_SERVER['SERVER_PORT']=443;add_filter('home_url',static function($u){return preg_replace('#^http:#','https:',$u);});add_filter('site_url',static function($u){return preg_replace('#^http:#','https:',$u);});}
  $result=array('health'=>EIPSI\ExternalApi\ExternalApiHealth::inspect(),'secure_transport'=>EIPSI\ExternalApi\Authenticator::secureTransport());
 }
 echo json_encode(array('ok'=>true,'data'=>$result));
}catch(Throwable$e){echo json_encode(array('ok'=>false,'code'=>'DIAGNOSTIC_FAILED'));}
ob_end_flush();
