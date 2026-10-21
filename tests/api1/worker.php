<?php
define('DOING_CRON',true);define('WP_ENVIRONMENT_TYPE','local');require dirname(__DIR__).'/m0/bootstrap.php';
require_once EIPSI_FORMS_PLUGIN_DIR.'includes/study-engine/builder.php';
$dir=$argv[1]??'';if(!preg_match('#^/tmp/eipsi-api1-[a-f0-9]{16}$#D',$dir))exit(1);
$input=json_decode(stream_get_contents(STDIN),true);touch($dir.'/attempt');
try{
 if($input['op']==='delete'){$s=new EIPSI\StudyEngine\Builder\Service(new EIPSI\StudyEngine\Persistence\Database($wpdb));$s->deleteStudy($input['study'],'ELIMINAR '.$input['study']);$data=array('deleted'=>true);}
 else{$p=(new EIPSI\ExternalApi\Authenticator())->authenticate('Bearer '.$input['secret'],EIPSI\ExternalApi\Authenticator::requestId(),'api1-worker');$q=new EIPSI\ExternalApi\StudyDiscoveryQuery();$data=$input['op']==='detail'?$q->detail($p,$input['code']):$q->page($p);}
 $result=array('status'=>200,'data'=>$data);
}catch(EIPSI\ExternalApi\ApiError$e){$result=array('status'=>$e->getCode(),'code'=>$e->apiCode);}catch(Throwable$e){$result=array('status'=>503,'code'=>'WORKER_FAILED');}
file_put_contents($dir.'/result.json.tmp',json_encode($result));rename($dir.'/result.json.tmp',$dir.'/result.json');exit(0);
