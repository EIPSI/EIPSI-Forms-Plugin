<?php
require_once __DIR__.'/../includes/study-engine/persistence.php';
use EIPSI\StudyEngine\Contracts\Canonical;
use EIPSI\StudyEngine\Engine\DefinitionCompiler;
use EIPSI\StudyEngine\Engine\Simulator;
use EIPSI\StudyEngine\Persistence\SeedVault;
use EIPSI\StudyEngine\Persistence\IntegrityFailure;
use EIPSI\StudyEngine\Persistence\Database;
set_error_handler(static function($n,$m,$f,$l){throw new ErrorException($m,0,$n,$f,$l);});
$tests=0;$failed=0;
function pu_case($label,$fn){global $tests,$failed;$tests++;try{$fn();echo 'PASS '.$label."\n";}catch(Throwable $e){$failed++;echo 'FAIL '.$label.': '.$e->getMessage()."\n";}}
function pu_assert($v,$label){if(!$v)throw new RuntimeException($label);}
function pu_reject($fn,$message){try{$fn();}catch(Throwable $e){pu_assert(strpos($e->getMessage(),$message)!==false,'Wrong error: '.$e->getMessage());return;}throw new RuntimeException('Expected rejection '.$message);}
function pu_sim($n='01'){foreach(glob(__DIR__.'/study-engine/fixtures/valid/'.$n.'*.json') as $file)return new Simulator((new DefinitionCompiler())->compile(json_decode(file_get_contents($file),true)),array('execution_id'=>'SE_UNIT','created_at'=>'2026-10-12T11:00:00Z','timezone'=>'America/Argentina/Buenos_Aires','seed_hex'=>str_repeat('01',32)));}
foreach(array('01','02','03','04','05','06','07','08','09','10') as $n)pu_case('Checkpoint roundtrip graph/initial state '.$n,function()use($n){$s=pu_sim($n);$r=Simulator::restore($s->compiled(),Database::json(Canonical::json($s->checkpoint())),str_repeat('01',32));pu_assert(Canonical::json($s->snapshot())===Canonical::json($r->snapshot())&&$s->nextAction()===$r->nextAction(),'Roundtrip mismatch');});
pu_case('Checkpoint excludes secret and request/command cache',function(){$s=pu_sim();$s->dispatch(array('id'=>'ANSWER_1','kind'=>'ANSWER','data'=>array('question_id'=>'Q_AGE','value'=>42)));$c=$s->checkpoint();pu_assert(!isset($c['context']['seed_hex'])&&!isset($c['cache'])&&strpos(Canonical::json($c),'fingerprint')===false,'Secrets/cache persisted');});
pu_case('Partial continuation restores exact events and cursor',function(){$s=pu_sim();$s->dispatch(array('id'=>'ANSWER_1','kind'=>'ANSWER','data'=>array('question_id'=>'Q_AGE','value'=>42)));$r=Simulator::restore($s->compiled(),$s->checkpoint(),str_repeat('01',32));$c=array('id'=>'DONE','kind'=>'SUBMIT_ASSESSMENT','data'=>array('node_id'=>'E_SIMPLE','responses'=>array('Q_WELLBEING'=>3)));pu_assert($s->dispatch($c)===$r->dispatch($c)&&Canonical::json($s->snapshot())===Canonical::json($r->snapshot()),'Continuation mismatch');});
pu_case('Checkpoint graph pin cannot change',function(){$s=pu_sim();$c=$s->checkpoint();$c['graph_hash']=str_repeat('0',64);pu_reject(function()use($s,$c){Simulator::restore($s->compiled(),$c,str_repeat('01',32));},'CHECKPOINT_CONTRACT');});
pu_case('Checkpoint version incompatible rejected',function(){$s=pu_sim();$c=$s->checkpoint();$c['contract']='FUTURE';pu_reject(function()use($s,$c){Simulator::restore($s->compiled(),$c,str_repeat('01',32));},'CHECKPOINT_CONTRACT');});
pu_case('Checkpoint missing instance order rejected',function(){$s=pu_sim();$c=$s->checkpoint();array_pop($c['instance_order']);pu_reject(function()use($s,$c){Simulator::restore($s->compiled(),$c,str_repeat('01',32));},'INVALID_INSTANCE_ORDER');});
pu_case('Checkpoint duplicate instance order rejected',function(){$s=pu_sim();$c=$s->checkpoint();$c['instance_order'][]=$c['instance_order'][0];pu_reject(function()use($s,$c){Simulator::restore($s->compiled(),$c,str_repeat('01',32));},'INVALID_INSTANCE_ORDER');});
pu_case('Checkpoint missing selector history target rejected',function(){$s=pu_sim();$c=$s->checkpoint();$c['selectors']['frozen']='BE_ABSENT';pu_reject(function()use($s,$c){Simulator::restore($s->compiled(),$c,str_repeat('01',32));},'INVALID_SELECTOR_HISTORY');});
pu_case('Unknown checkpoint state field rejected',function(){$s=pu_sim();$c=$s->checkpoint();$c['state']['secret']='x';pu_reject(function()use($s,$c){Simulator::restore($s->compiled(),$c,str_repeat('01',32));},'UNKNOWN_FIELD');});
pu_case('Restored lazy pending template remains pending',function(){$s=pu_sim('04');$c=$s->checkpoint();$c['context']['eager_limit']=1;$r=Simulator::restore($s->compiled(),$c,str_repeat('01',32));$r->dispatch(array('id'=>'CLOCK','kind'=>'ADVANCE_TIME','data'=>array('seconds'=>60)));pu_assert($r->snapshot()['execution']['state']!=='COMPLETED','Premature completion');});
pu_case('Seed encryption entropy and authenticated roundtrip',function(){$v=new SeedVault(array('K1'=>str_repeat('k',32)),'K1');$a=$v->create('SE_A');$b=$v->create('SE_A');pu_assert($a['seed_hex']!==$b['seed_hex']&&$a['ciphertext']!==$b['ciphertext']&&$v->open('SE_A','K1',$a['ciphertext'])===$a['seed_hex'],'Seed failure');});
pu_case('Seed AAD rejects another execution',function(){$v=new SeedVault(array('K1'=>str_repeat('k',32)),'K1');$a=$v->create('SE_A');pu_reject(function()use($v,$a){$v->open('SE_B','K1',$a['ciphertext']);},'SEED_AUTHENTICATION_FAILED');});
pu_case('Seed ciphertext modified rejects',function(){$v=new SeedVault(array('K1'=>str_repeat('k',32)),'K1');$a=$v->create('SE_A');$raw=base64_decode($a['ciphertext']);$raw[40]=chr(ord($raw[40])^1);pu_reject(function()use($v,$raw){$v->open('SE_A','K1',base64_encode($raw));},'SEED_AUTHENTICATION_FAILED');});
pu_case('Seed missing historical key fails visibly',function(){$v=new SeedVault(array('K2'=>str_repeat('l',32)),'K2');pu_reject(function()use($v){$v->open('SE_A','K1',str_repeat('a',80));},'SEED_KEY_UNAVAILABLE');});
pu_case('Seed rotation retains older executions',function(){$v=new SeedVault(array('K1'=>str_repeat('k',32)),'K1');$a=$v->create('SE_A');$r=new SeedVault(array('K1'=>str_repeat('k',32),'K2'=>str_repeat('l',32)),'K2');pu_assert($r->open('SE_A','K1',$a['ciphertext'])===$a['seed_hex']&&$r->create('SE_B')['key_id']==='K2','Rotation');});
pu_case('Weak key rejected',function(){pu_reject(function(){new SeedVault(array('K1'=>'short'),'K1');},'32_BYTES');});
pu_case('Malformed ciphertext rejected',function(){$v=new SeedVault(array('K1'=>str_repeat('k',32)),'K1');pu_reject(function()use($v){$v->open('SE_A','K1','invalid');},'CIPHERTEXT_INVALID');});
pu_case('Canonical durable JSON keeps numeric types',function(){$d=array('int'=>1,'float'=>1.0,'bool'=>false,'null'=>null);pu_assert(Database::json(Canonical::json($d))===Canonical::normalize($d),'Coercion');});
pu_case('Noncanonical durable JSON rejected',function(){pu_reject(function(){Database::json('{"b":1,"a":2}');},'NONCANONICAL');});
pu_case('Invalid durable JSON rejected',function(){pu_reject(function(){Database::json('{');},'INVALID_DURABLE_JSON');});
pu_case('Checkpoint export is a copy',function(){$s=pu_sim();$c=$s->checkpoint();$c['state']['execution']['state']='COMPLETED';pu_assert($s->snapshot()['execution']['state']==='ACTIVE','Mutable export');});
echo "$tests tests, $failed failures\n";exit($failed?1:0);
