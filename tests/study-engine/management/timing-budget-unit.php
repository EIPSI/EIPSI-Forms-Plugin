<?php
require __DIR__.'/timing-budget.php';$tests=0;$failures=0;
function mt_case($name,$fn){global $tests,$failures;$tests++;try{$fn();echo "PASS $name\n";}catch(Throwable $e){$failures++;echo 'FAIL '.$name.': '.$e->getMessage()."\n";}}
function mt_assert($condition){if(!$condition)throw new RuntimeException('Assertion failed');}
function mt_reject($fn,$class){try{$fn();}catch(Throwable $e){mt_assert($e instanceof $class);return $e;}throw new RuntimeException('Expected rejection');}
mt_case('Ordinary CI has no hardware-dependent wall-clock failure',function(){mt_assert(mg_timing_limit(false)===null);mg_timing_assert(12.5,mg_timing_limit(false),10000);});
mt_case('Explicit benchmark keeps the original ten second budget',function(){mt_assert(mg_timing_limit('10')===10.0);});
mt_case('Positive fractional benchmark limits are accepted',function(){mt_assert(mg_timing_limit('.5')===0.5&&mg_timing_limit('2.25')===2.25);});
mt_case('Invalid benchmark settings fail instead of silently disabling the guard',function(){foreach(array('','0','-1','NaN','INF','abc','10 seconds','1e999')as$raw)mt_reject(fn()=>mg_timing_limit($raw),InvalidArgumentException::class);});
mt_case('An explicit budget still rejects a slow run with measured diagnostics',function(){$e=mt_reject(fn()=>mg_timing_assert(12.5,10.0,10000),RuntimeException::class);mt_assert(strpos($e->getMessage(),'10000 participations, 12.5 s >= 10 s')!==false);});
mt_case('A measured result below the explicit budget passes',function(){mg_timing_assert(9.9,10.0,10000);});
mt_case('The explicit boundary remains strict',function(){mt_reject(fn()=>mg_timing_assert(10.0,10.0,10000),RuntimeException::class);});
echo "$tests tests, $failures failures\n";exit($failures?1:0);
