<?php
define('DOING_CRON',true);require dirname(__DIR__).'/m0/bootstrap.php';
$dir=$argv[1]??'';$id=$argv[2]??'';if(!preg_match('~^/tmp/eipsi-setup-race-[a-f0-9]{12}$~D',$dir)||!in_array($id,array('0','1'),true))exit(1);
add_filter('query',static function($sql)use($dir,$id){if(strpos($sql,'INSERT IGNORE INTO')===0&&strpos($sql,'eipsi_server_secrets_v1')!==false){touch($dir.'/ready'.$id);$end=microtime(true)+15;while(!file_exists($dir.'/go')){if(microtime(true)>$end)throw new RuntimeException('FIXTURE_BARRIER_TIMEOUT');usleep(20000);}}return $sql;});
try{$created=EIPSI\StudyEngine\Configuration\SecretStore::provision();file_put_contents($dir.'/result'.$id.'.json',json_encode(array('created'=>$created)));ob_end_clean();exit;}catch(Throwable $e){file_put_contents($dir.'/result'.$id.'.json',json_encode(array('error'=>'PROVISION_WORKER_FAILED')));ob_end_clean();exit(1);}
