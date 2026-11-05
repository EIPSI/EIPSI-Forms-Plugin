<?php
if(getenv('WORDPRESS_DB_HOST')==='eipsi-m0-db:3306')add_filter('eipsi_runtime_timestamp',static function($real){return is_file('/tmp/perf1-clock')?(int)file_get_contents('/tmp/perf1-clock'):$real;});
