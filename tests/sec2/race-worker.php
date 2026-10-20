<?php
use EIPSI\StudyEngine\Longitudinal as L;
/** Two real connections, guarded disposable database, no participant endpoint. */
define('DOING_CRON',true);require dirname(__DIR__).'/m0/bootstrap.php';
require_once EIPSI_FORMS_PLUGIN_DIR.'includes/study-engine/builder.php';
require dirname(__DIR__).'/study-engine/cross-sectional/support.php';require dirname(__DIR__).'/study-engine/longitudinal/support.php';
require_once EIPSI_FORMS_PLUGIN_DIR.'includes/study-engine/runtime/NativeBridge.php';
$dir=$argv[1]??'';$slot=$argv[2]??'';if(!preg_match('#^/tmp/eipsi-sec2-race-[a-f0-9]+$#D',$dir)||!in_array($slot,array('0','1'),true))exit(2);
$input=json_decode(file_get_contents($dir.'/input'.$slot.'.json'),true);$GLOBALS['pf_now']=$input['clock'];
function s2_worker_wait($path){$end=microtime(true)+20;while(!is_file($path)){if(microtime(true)>$end)throw new RuntimeException('SEC2 barrier timeout');usleep(10000);}}
$observer=new class($dir,$slot,$input['point']??null) extends EIPSI\StudyEngine\Persistence\TransitionObserver {
 private $dir;private $slot;private $point;public function __construct($dir,$slot,$point){$this->dir=$dir;$this->slot=$slot;$this->point=$point;}
 public function reached(string $point): void {if($point!==$this->point)return;touch($this->dir.'/locked'.$this->slot);s2_worker_wait($this->dir.'/release'.$this->slot);}
};
try{
 touch($dir.'/ready'.$slot);s2_worker_wait($dir.'/start'.$slot);touch($dir.'/attempt'.$slot);
 $_COOKIE[L\Identity::cookieName($input['public'])]=$input['token']??'';
 $native=new EIPSI\AssessmentEditor\Runtime\Owner(null,static fn()=>strtotime($GLOBALS['pf_now']),$observer);
 switch($input['mode']){
 case 'pause':case 'resume':$svc=new EIPSI\StudyEngine\Builder\Service(pf_db(),$observer);$v=$svc->load($input['version']);$result=$svc->changeStudyState($input['version'],$v['lifecycle']['revision'],$input['mode']==='pause'?'PAUSED':'ACTIVE',false,'SEC2_RACE_'.$slot.'_'.$input['request']);$result=array('state'=>$result['lifecycle']['state']);break;
 case 'open':$result=$native->studyAssignment($input['native_version'],$input['binding']);break;
 case 'submit':$r=pf_native_fixture_command($native,$input['native'],'submit');$result=array('state'=>$r['state'],'execution_id'=>$r['execution_id']);break;
 case 'recover':$r=$native->view($input['native']);$result=array('state'=>$r['state'],'execution_id'=>$r['execution_id'],'session'=>$r['session']);break;
 case 'advance':$result=array('advanced'=>(new L\Worker(pf_db(),pe_vault(),pf_caps(),pf_clock()))->advanceBatch(100));break;
 default:throw new RuntimeException('SEC2 mode');
 }
}catch(Throwable$e){$result=array('error'=>$e->getMessage(),'type'=>get_class($e),'cause_code'=>$e->getPrevious()?$e->getPrevious()->getCode():null);}
file_put_contents($dir.'/result'.$slot.'.tmp',json_encode($result));rename($dir.'/result'.$slot.'.tmp',$dir.'/result'.$slot.'.json');ob_end_flush();
