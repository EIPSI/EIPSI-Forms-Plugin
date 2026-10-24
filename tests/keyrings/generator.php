<?php
$tests=0;$failures=0;$root=dirname(__DIR__,2);$dir=sys_get_temp_dir().'/eipsi-keyring-generator-'.bin2hex(random_bytes(6));mkdir($dir,0700);$target=$dir.'/eipsi-server-keyrings.php';
function kg_assert($v){if(!$v)throw new RuntimeException('Assertion failed');}
function kg_case($name,$fn){global $tests,$failures;$tests++;try{$fn();echo 'PASS '.$name."\n";}catch(Throwable $e){$failures++;echo 'FAIL '.$name.': '.$e->getMessage()."\n";}}
function kg_run($args){global $root;$pipes=array();$p=proc_open(array_merge(array(PHP_BINARY,$root.'/scripts/generate-eipsi-keyrings.php'),$args),array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);return array(proc_close($p),$out.$err);}
try{
kg_case('Manual generator creates two valid independent random keyrings without printing secrets',function()use($target){list($code,$out)=kg_run(array($target));kg_assert($code===0);require $target;$a=EIPSI_STUDY_ENGINE_KEYRING;$b=EIPSI_STUDY_ENGINE_ACCESS_KEYRING;foreach(array($a,$b)as $r){kg_assert(isset($r['keys'][$r['current']])&&preg_match('/^[a-f0-9]{64}$/D',$r['keys'][$r['current']]));kg_assert(strpos($out,$r['keys'][$r['current']])===false);}kg_assert($a['keys'][$a['current']]!==$b['keys'][$b['current']]);});
kg_case('Generated file has mode 0600',function()use($target){kg_assert((fileperms($target)&0777)===0600);});
kg_case('Generator refuses to overwrite any existing configuration',function()use($target){$old=file_get_contents($target);list($code,$out)=kg_run(array($target));kg_assert($code!==0&&file_get_contents($target)===$old);});
kg_case('Explicit private output destination is mandatory',function(){list($code,$out)=kg_run(array());kg_assert($code!==0&&strpos($out,'Usage:')!==false);});
kg_case('Generated configurations pass the same diagnostic without leaking keys',function()use($root){require $root.'/includes/study-engine/configuration/ServerKeyrings.php';$s=EIPSI\StudyEngine\Configuration\ServerKeyrings::status();foreach($s as $row){kg_assert(array_intersect_key($row,array_flip(array('available','format_valid','active_key_resolvable','ready')))===array('available'=>true,'format_valid'=>true,'active_key_resolvable'=>true,'ready'=>true));kg_assert($row['source']==='CONSTANT');}kg_assert(count($s)===2);});
}finally{if(is_file($target))unlink($target);rmdir($dir);}
echo "$tests tests, $failures failures\n";exit($failures?1:0);
