<?php
define('DOING_CRON',true);require dirname(__DIR__).'/m0/bootstrap.php';
$dir=$argv[1];$i=$argv[2];$p=json_decode(file_get_contents($dir.'/input.json'),true);
function sec1_wait_worker($f){$end=microtime(true)+20;while(!file_exists($f)){if(microtime(true)>$end)throw new RuntimeException('Worker barrier timeout');usleep(10000);}}
try{
 if($p['mode']==='rate'){
  touch($dir.'/ready'.$i);sec1_wait_worker($dir.'/start');
  try{$n=EIPSI_Rate_Limiter::consume('sec1.concurrent',$p['principal'],7,60,$p['now']);$result=array('allowed'=>true,'count'=>$n);}catch(EIPSI_Rate_Limit_Exception $e){$result=array('allowed'=>false,'status'=>$e->getCode(),'retry'=>$e->retry_after);}
 }elseif($p['mode']==='download'){
  require_once EIPSI_FORMS_PLUGIN_DIR.'includes/study-engine/management.php';
  $q=new EIPSI\StudyEngine\Management\Query($p['study']);
  $observer=new class($q->db,$p['job'],$dir) extends EIPSI\StudyEngine\Persistence\TransitionObserver {
   private $db;private $job;private $dir;
   public function __construct($db,$job,$dir){$this->db=$db;$this->job=$job;$this->dir=$dir;}
   public function reached(string $point): void {if($point!=='management_export_download_locked')return;$this->db->query($this->db->prepare('UPDATE '.$this->db->table('management_exports').' SET expires_at=%s WHERE id=%s',gmdate('Y-m-d H:i:s',time()-10),$this->job));touch($this->dir.'/locked');sec1_wait_worker($this->dir.'/release');}
  };
  $x=new EIPSI\StudyEngine\Management\Exports($q,$observer);$file=$x->download($p['job'],'json');$result=array('content'=>$file['content']);
 }else{
  require_once EIPSI_FORMS_PLUGIN_DIR.'includes/study-engine/management.php';touch($dir.'/cleanup_started');$result=array('deleted'=>EIPSI\StudyEngine\Management\Exports::cleanup());
 }
 file_put_contents($dir.'/result'.$i.'.tmp',json_encode($result));rename($dir.'/result'.$i.'.tmp',$dir.'/result'.$i.'.json');
}catch(Throwable $e){file_put_contents($dir.'/result'.$i.'.tmp',json_encode(array('error'=>$e->getMessage())));rename($dir.'/result'.$i.'.tmp',$dir.'/result'.$i.'.json');exit(1);}
ob_end_flush();
