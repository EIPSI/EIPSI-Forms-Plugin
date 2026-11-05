<?php
if(getenv('WORDPRESS_DB_HOST')!=='eipsi-m0-db:3306')return;
require_once __DIR__.'/Profiler.php';
$scenario=$_SERVER['HTTP_X_PERF1_SCENARIO']??'';$rep=$_SERVER['HTTP_X_PERF1_REPETITION']??'';
if($scenario==='P3'&&preg_match('/^(?:warmup|[0-9]{1,3})$/D',$rep)){
 Perf1Profiler::start($scenario,$rep);
 ob_start(static function($body){if(Perf1Profiler::active())file_put_contents('/tmp/perf1-http.jsonl',json_encode(Perf1Profiler::finish())."\n",FILE_APPEND|LOCK_EX);return $body;});
}
