<?php
if(defined('DB_HOST')&&DB_HOST==='eipsi-m0-db:3306'&&DB_NAME==='m0'&&get_option('eipsi_m0_isolated_install')){
 $s41_delete_failure = (bool) get_option('eipsi_s41_delete_sql_failure');
 add_filter('query',function($sql)use($s41_delete_failure){if($s41_delete_failure&&preg_match('/^\s*DELETE FROM.*eipsi_randomization_assignments/i',$sql)&&strpos($sql,'s41-sql-http')!==false)return 'SELECT s41_controlled_http_delete_failure';return $sql;});
}
