<?php
/** Separate process/connection, not a production test endpoint. */
define('DOING_CRON',true);require dirname(__DIR__,2).'/m0/bootstrap.php';require_once EIPSI_FORMS_PLUGIN_DIR.'includes/study-engine/privacy.php';require dirname(__DIR__).'/cross-sectional/support.php';require dirname(__DIR__).'/longitudinal/support.php';
use EIPSI\StudyEngine\Privacy as G;
$dir=$argv[1]??'';$index=$argv[2]??'';if(!preg_match('#^/tmp/eipsi-p1g-race-[a-f0-9]+$#D',$dir)||!in_array($index,array('0','1'),true))exit(2);
$input=json_decode(file_get_contents($dir.'/input.json'),true);$GLOBALS['pf_now']=$input['clock'];$op=$input['operations'][(int)$index];
$observer=new class($dir,$index,$op) extends EIPSI\StudyEngine\Persistence\TransitionObserver {private $dir;private $index;private $op;public function __construct($d,$i,$o){$this->dir=$d;$this->index=$i;$this->op=$o;}public function reached(string $p): void {if($this->index==='0'&&$p===($this->op['hold']??null)){touch($this->dir.'/locked0');pg_wait_worker($this->dir.'/release0');}}};
touch($dir.'/ready'.$index);pg_wait_worker($dir.'/start'.$index);touch($dir.'/attempt'.$index);
try{
 if($op['kind']==='export'){$r=(new G\ExportAdapter(pf_db(),$observer))->dataset($input['study']);$out=array('rows'=>count($r['rows']));}
 elseif($op['kind']==='withdraw'){
  $access=G\AccessContext::session(pf_db(),$input['public'],$input['withdrawal_token'],pf_clock());
  try{$r=pf_owner($observer)->withdrawExecution($input['execution'],'DO_NOT_USE','RACE_WITHDRAW',(int)$input['revision'],$access);}
  catch(EIPSI\StudyEngine\Persistence\PersistenceConflict $e){if(empty($op['retry']))throw $e;$revision=(int)(new EIPSI\StudyEngine\Persistence\StudyExecutionRepository(pf_db()))->find($input['execution'])['revision'];$r=pf_owner($observer)->withdrawExecution($input['execution'],'DO_NOT_USE','RACE_WITHDRAW',$revision,$access);}
  $out=array('status'=>200,'revision'=>$r['revision']);
 }
 elseif($op['kind']==='submit'){
  try{$r=pf_runtime($observer)->handle($input['public'],$op['token'],$op['request']);$out=array('status'=>200,'state'=>$r['view']['next_action']['kind']);}
  catch(EIPSI\StudyEngine\Runtime\AccessDenied $e){$out=array('status'=>403);}
 }
 else{
  $cookie=new WP_Http_Cookie(array('name'=>EIPSI\StudyEngine\Longitudinal\Identity::cookieName($input['public']),'value'=>$op['token']));$r=pf_http($input['public'],array($cookie),$op['request']);
  if($r['status']===409&&!empty($op['retry'])){$lookup=array('action'=>'withdrawal_lookup','command_id'=>'RACE_LOOKUP_RETRY','csrf'=>$op['request']['csrf'],'email'=>$op['request']['email']);$state=pf_http($input['public'],array($cookie),$lookup);$op['request']['expected_revision']=$state['data']['withdrawal_check']['revision'];$r=pf_http($input['public'],array($cookie),$op['request']);}
  $out=array('status'=>$r['status'],'state'=>$r['data']['state']??null);
 }
}catch(Throwable $e){$out=array('error'=>$e->getMessage());}
file_put_contents($dir.'/result'.$index.'.json',json_encode($out));
function pg_wait_worker($p){$end=microtime(true)+20;while(!file_exists($p)){if(microtime(true)>$end)throw new RuntimeException('Race timeout');usleep(10000);}}
