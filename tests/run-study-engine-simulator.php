<?php
/** Pure PHP 7.4 runner. --fixture=08, --trace, --snapshot supported. */
require_once __DIR__.'/../includes/study-engine/engine.php';
require_once __DIR__.'/study-engine/simulator/TraceOracle.php';
use EIPSI\StudyEngine\Tests\TraceOracle;
use EIPSI\StudyEngine\Contracts\Canonical;
use EIPSI\StudyEngine\Engine\DefinitionCompiler;

set_error_handler(static function($severity,$message,$file,$line){throw new ErrorException($message,0,$severity,$file,$line);});
$filter=null;$showTrace=false;$snapshotMode=false;
foreach(array_slice($argv,1) as $arg){if(strpos($arg,'--fixture=')===0)$filter=substr($arg,10);elseif($arg==='--trace')$showTrace=true;elseif($arg==='--snapshot')$snapshotMode=true;else{fwrite(STDERR,"Unknown option: $arg\n");exit(2);}}
$tests=0;$failures=0;$snapshots=array();$regressionRechecks=0;
function check_case(string $name,callable $test): void {
    global $tests,$failures,$snapshotMode;++$tests;
    try{$test();if(!$snapshotMode)echo 'PASS '.$name."\n";}catch(Throwable $e){++$failures;fwrite(STDERR,'FAIL '.$name.': '.$e->getMessage()."\n");}
}
function regression_recheck(string $name,callable $test): void {
    global $regressionRechecks,$failures,$snapshotMode;++$regressionRechecks;
    try{$test();if(!$snapshotMode)echo 'PASS recheck '.$name."\n";}catch(Throwable $e){++$failures;fwrite(STDERR,'FAIL recheck '.$name.': '.$e->getMessage()."\n");}
}
foreach(glob(__DIR__.'/study-engine/fixtures/valid/*.json') as $file) {
    $name=basename($file,'.json');if($filter!==null&&strpos($name,$filter)!==0)continue;
    $definition=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);$trace=json_decode(file_get_contents(__DIR__.'/study-engine/traces/'.$name.'.json'),true,512,JSON_THROW_ON_ERROR);
    check_case('Compiler '.$name,static function()use($definition,$name){global $snapshots;$g=(new DefinitionCompiler())->compile($definition);$snapshots[$name]['protocol_hash']=$g->protocolHash();$snapshots[$name]['graph_hash']=$g->hash();});
    foreach($trace['scenarios'] as $scenario)check_case('Trace '.$name.'/'.$scenario['id'],static function()use($definition,$trace,$scenario,$name,$showTrace){global $snapshots;$sim=TraceOracle::scenario($definition,$trace,$scenario);$snapshots[$name]['scenarios'][$scenario['id']]=$sim->snapshot();if($showTrace)echo Canonical::json($sim->trace())."\n";});
}
if($filter===null){require __DIR__.'/study-engine/simulator/cases.php';}
if($tests===0){fwrite(STDERR,"No fixture matched\n");exit(2);}
if($snapshotMode)echo Canonical::json($snapshots)."\n";else echo "$tests tests, $failures failures; $regressionRechecks P1-B negative rechecks (not additional unique tests)\n";
exit($failures?1:0);
