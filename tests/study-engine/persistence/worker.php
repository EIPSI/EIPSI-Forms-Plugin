<?php
define('DOING_CRON',true);require __DIR__.'/../../m0/bootstrap.php';require __DIR__.'/../../../includes/study-engine/persistence.php';require __DIR__.'/support.php';
$dir=$argv[1]??'';$slot=$argv[2]??'';if(!preg_match('#^/tmp/eipsi-p1d-race-[a-f0-9]+$#D',$dir)||!in_array($slot,array('0','1'),true))exit(2);
$input=json_decode(file_get_contents($dir.'/input.json'),true);if(!preg_match('/^pd_[a-f0-9]+_$/D',$input['prefix']))exit(2);
$wpdb=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);$wpdb->set_prefix($input['prefix']);$wpdb->suppress_errors(true);
$observer=$slot==='0'?new class($dir) extends EIPSI\StudyEngine\Persistence\TransitionObserver {
 private $dir;public function __construct($dir){$this->dir=$dir;}
 public function reached(string $point): void {if($point==='after_command_claim'){touch($this->dir.'/locked0');pd_wait($this->dir.'/release0');}}
}:null;
touch($dir.'/ready'.$slot);pd_wait($dir.'/start'.$slot);if($slot==='1')touch($dir.'/attempt1');
try{$r=pd_owner($observer)->dispatch($input['execution_id'],$input['commands'][(int)$slot]);$out=array('result'=>$r);}catch(Throwable $e){$out=array('error'=>$e->getMessage());}
file_put_contents($dir.'/result'.$slot.'.json',json_encode($out));ob_end_clean();
