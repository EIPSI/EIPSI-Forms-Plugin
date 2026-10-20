<?php
/** Test-only MU plugin, never a public clock control or product route. */
if(getenv('WORDPRESS_DB_NAME')!=='m0'||!get_option('eipsi_m0_isolated_install'))return;
if(!defined('EIPSI_STUDY_ENGINE_KEYRING'))define('EIPSI_STUDY_ENGINE_KEYRING',array('current'=>'HTTP_TEST','keys'=>array('HTTP_TEST'=>str_repeat('6b',32))));
if(!defined('EIPSI_STUDY_ENGINE_ACCESS_KEYRING'))define('EIPSI_STUDY_ENGINE_ACCESS_KEYRING',array('current'=>'ACCESS_TEST','keys'=>array('ACCESS_TEST'=>str_repeat('61',32))));
add_filter('eipsi_runtime_timestamp',static function($real){$f=ABSPATH.'wp-content/sec2-clock';$v=is_file($f)?file_get_contents($f):'';return ctype_digit($v)?(int)$v:$real;});
