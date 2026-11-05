<?php
if(getenv('WORDPRESS_DB_HOST')!=='eipsi-m0-db:3306')return;
add_filter('log_query_custom_data',array('Perf1Profiler','wpdb'),PHP_INT_MAX,3);
add_action('template_redirect',static function(){Perf1Profiler::mark('handler_entry');},-999999);
add_action('admin_init',static function(){Perf1Profiler::mark('admin_entry');},-999999);

add_filter('eipsi_runtime_timestamp',static function($real){$path='/tmp/perf1-clock';return is_file($path)?(int)file_get_contents($path):$real;});
