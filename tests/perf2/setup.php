<?php
if(PHP_SAPI!=='cli'||getenv('EIPSI_PERF2')!=='1'||getenv('WORDPRESS_DB_HOST')!=='eipsi-m0-db:3306')exit(2);
putenv('EIPSI_UX_F0=1');require __DIR__.'/../ux-f0/setup.php';
$p='/var/www/html/wp-config.php';$s=file_get_contents($p);$s=preg_replace('/<\?php/','<?php require "/opt/eipsi-ci/tests/perf2/entropy.php";',$s,1);file_put_contents($p,$s);
if(!is_dir('/tmp/perf2'))mkdir('/tmp/perf2',0770);chgrp('/tmp/perf2','www-data');chmod('/tmp/perf2',0770);file_put_contents('/tmp/perf2/context.jsonl','');chgrp('/tmp/perf2/context.jsonl','www-data');chmod('/tmp/perf2/context.jsonl',0660);
file_put_contents('/tmp/perf2/sql-digest.json','{}');chgrp('/tmp/perf2/sql-digest.json','www-data');chmod('/tmp/perf2/sql-digest.json',0660);
copy(__DIR__.'/mu.php','/var/www/html/wp-content/mu-plugins/perf2-clock.php');
echo "PERF-2 setup only disposable fixture\n";
