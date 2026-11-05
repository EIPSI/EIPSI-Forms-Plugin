<?php
if(PHP_SAPI!=='cli'||getenv('EIPSI_PERF1')!=='1'||getenv('WORDPRESS_DB_HOST')!=='eipsi-m0-db:3306')exit(2);
putenv('EIPSI_UX_F0=1');require __DIR__.'/../ux-f0/setup.php';
$p='/var/www/html/wp-config.php';$s=file_get_contents($p);$s=preg_replace('/<\?php/','<?php require "/opt/eipsi-ci/tests/perf1/prepend.php";',$s,1);file_put_contents($p,$s);copy(__DIR__.'/mu.php','/var/www/html/wp-content/mu-plugins/perf1.php');echo "PERF-1 hooks installed only in fixture volume\n";

file_put_contents('/tmp/perf1-http.jsonl','');chown('/tmp/perf1-http.jsonl','www-data');chmod('/tmp/perf1-http.jsonl',0600);
