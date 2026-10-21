<?php
use EIPSI\StudyEngine\Contracts\Canonical;
use EIPSI\StudyEngine\Engine\Simulator;
use EIPSI\StudyEngine\Persistence as P;
use EIPSI\StudyEngine\Orchestration\ExecutionOwner;

/** Only test code can replace server entropy or inject failures. */
class P1DTestVault extends P\SeedVault { protected function entropy(): string {return str_repeat(chr(1),32);} }
class P1DFailure extends P\TransitionObserver {
    private $point;
    public function __construct(string $point){$this->point=$point;}
    public function reached(string $point): void {if($point===$this->point)throw new RuntimeException('INJECTED_'.$point);}
}
function pd_owner($observer=null,$vault=null){global $wpdb;return new ExecutionOwner(new P\Database($wpdb),$vault??new P1DTestVault(array('TEST_KEY'=>str_repeat('k',32)),'TEST_KEY'),$observer);}
function pd_assert($condition,$message='Assertion failed'){if(!$condition)throw new RuntimeException($message);}
function pd_equal($a,$b,$label='Mismatch'){pd_assert(Canonical::json($a)===Canonical::json($b),$label);}
function pd_reject($fn,$message){try{$fn();}catch(Throwable $e){pd_assert(strpos($e->getMessage(),$message)!==false,'Wrong error '.$e->getMessage());return;}throw new RuntimeException('Expected '.$message);}
function pd_definition($n){$files=glob(__DIR__.'/../fixtures/valid/'.$n.'*.json');return json_decode(file_get_contents($files[0]),true);}
function pd_create($n='01',$options=array(),$observer=null){global $wpdb;$db=new P\Database($wpdb);$d=pd_definition($n);$v=new P\StudyVersionRepository($db);$versionId=$v->publish($d);$ctx=array_merge(array('created_at'=>'2026-10-12T11:00:00Z','timezone'=>'America/Argentina/Buenos_Aires'),$options);return pd_owner($observer)->create($versionId,array('kind'=>'ANONYMOUS','id'=>'ANON_TEST'),'REQ_'.bin2hex(random_bytes(8)),$ctx);}
function pd_snapshot($id){$r=pd_owner()->view($id);pd_assert(isset($r['snapshot']),'Load error '.Canonical::json($r));return $r['snapshot'];}
function pd_dispatch($id,$kind,$data=array(),$commandId=null,$intents=array(),$external=null){$s=pd_snapshot($id);$cmd=array('id'=>$commandId??'CMD_'.bin2hex(random_bytes(8)),'kind'=>$kind,'data'=>$data,'expected_revision'=>$s['execution']['revision']);$r=pd_owner()->dispatch($id,$cmd,$intents,$external);pd_assert(isset($r['revision']),'Dispatch error '.Canonical::json($r));return $r;}
function pd_instance($id,$definition){$m=array_values(array_filter(pd_snapshot($id)['instances'],static function($i)use($definition){return $i['definition_id']===$definition;}));pd_assert(count($m)===1,'Ambiguous instance');return $m[0];}
function pd_counts($id){global $wpdb;$counts=array();foreach(array('study_executions','block_executions','execution_events','random_decisions','execution_commands','withdrawal_records','notification_intents','submission_journal') as $t){$col=$t==='study_executions'?'id':'execution_id';$counts[$t]=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM `'.$wpdb->prefix.'eipsi_engine_'.$t.'` WHERE '.$col.'=%s',$id));}return $counts;}
function pd_value($n){if($n['type']==='BOOLEAN')return true;if($n['type']==='CHOICE')return $n['options'][0]['id'];if($n['type']==='TEXT')return 'P1-D partial payload';return max($n['validation']['min']??1,min(3,$n['validation']['max']??3));}
function pd_pure($n,$id,$options=array()){return new Simulator((new EIPSI\StudyEngine\Engine\DefinitionCompiler())->compile(pd_definition($n)),array_merge(array('created_at'=>'2026-10-12T11:00:00Z','timezone'=>'America/Argentina/Buenos_Aires','execution_id'=>$id,'seed_hex'=>str_repeat('01',32),'seed_ref'=>'SEED_'.$id),$options));}
function pd_compare_command($id,Simulator $pure,array $command): void {
    $a=$pure->dispatch($command);$owner=pd_owner();$b=$owner->dispatch($id,$command);pd_equal($a,$b,'Command result differs '.$command['kind']);unset($owner);
    $loaded=pd_owner()->view($id);pd_assert(isset($loaded['snapshot']),'Reload failed '.Canonical::json($loaded));pd_equal($pure->snapshot(),$loaded['snapshot'],'Reloaded state/domain events differ '.$command['kind']);pd_equal($pure->nextAction(),$loaded['next_action'],'NextAction differs');pd_equal($pure->outcome(),$loaded['outcome'],'Outcome differs');
}
function pd_complete($n,$id,Simulator $pure): void {
    $step=0;for($guard=0;$guard<250;$guard++){
        $next=$pure->nextAction();if(in_array($next['kind'],array('FINISHED','TERMINATED','WITHDRAWN'),true))return;
        if($next['kind']==='WAIT'){$until=$next['payload']['until'];pd_assert($until!==null,'Unresolved anchor');$cmd=array('id'=>'FLOW_'.(++$step),'kind'=>'ADVANCE_TIME','data'=>array('at'=>$until));}
        else{$block=$next['payload']['assessment_execution_id'];$responses=array();foreach($pure->presentation($block)['pages'] as $page)foreach($page as $node){$q=$pure->compiled()->node($node);if($q['kind']==='QUESTION'&&!isset($pure->snapshot()['coverage'][$block][$node])&&!isset($pure->snapshot()['responses'][$block][$node]))$responses[$node]=pd_value($q);}$cmd=array('id'=>'FLOW_'.(++$step),'kind'=>'SUBMIT_ASSESSMENT','data'=>array('block_execution_id'=>$block,'responses'=>$responses));}
        $cmd['expected_revision']=$pure->snapshot()['execution']['revision'];pd_compare_command($id,$pure,$cmd);
    }throw new RuntimeException('Flow guard');
}
function pd_race($id,array $a,array $b): array {
    global $wpdb;$dir='/tmp/eipsi-p1d-race-'.bin2hex(random_bytes(5));mkdir($dir,0700);
    file_put_contents($dir.'/input.json',Canonical::json(array('prefix'=>$wpdb->prefix,'execution_id'=>$id,'commands'=>array($a,$b))));$procs=array();$pipes=array();$out=array();
    try{
        foreach(array(0,1) as $i){$procs[$i]=proc_open(array(PHP_BINARY,__DIR__.'/worker.php',$dir,(string)$i),array(0=>array('pipe','r'),1=>array('file',$dir.'/stdout'.$i,'w'),2=>array('file',$dir.'/stderr'.$i,'w')),$pipes[$i]);pd_assert(is_resource($procs[$i]),'Worker launch');fclose($pipes[$i][0]);}
        pd_wait($dir.'/ready0');pd_wait($dir.'/ready1');touch($dir.'/start0');pd_wait($dir.'/locked0');touch($dir.'/start1');pd_wait($dir.'/attempt1');usleep(100000);touch($dir.'/release0');
        foreach(array(0,1) as $i){pd_wait($dir.'/result'.$i.'.json');$out[]=json_decode(file_get_contents($dir.'/result'.$i.'.json'),true);pd_assert(proc_close($procs[$i])===0,'Worker error '.file_get_contents($dir.'/stderr'.$i));unset($procs[$i]);}return $out;
    }finally{touch($dir.'/release0');foreach($procs as $p){proc_terminate($p);proc_close($p);}foreach(glob($dir.'/*') as $f)unlink($f);rmdir($dir);}
}
function pd_wait($file){$end=microtime(true)+15;while(!file_exists($file)){if(microtime(true)>$end)throw new RuntimeException('Timed out '.$file);usleep(10000);}}

function pd_engine_schema(){return array_filter(EIPSI_Schema_Registry::get_schema_map(),static function($slug){return strpos($slug,'eipsi_engine_')===0&&!in_array($slug,array('eipsi_engine_public_studies','eipsi_engine_anonymous_access','eipsi_engine_participants','eipsi_engine_participant_sessions','eipsi_engine_access_capabilities','eipsi_engine_enrollment_receipts'),true);},ARRAY_FILTER_USE_KEY);}
