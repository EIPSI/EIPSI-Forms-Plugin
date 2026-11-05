<?php
// Test-only early route boundary. No data collection without the explicit header.
if(getenv('WORDPRESS_DB_NAME')==='m0'){
 add_action('template_redirect',static function(){$GLOBALS['ux_f0_route']=hrtime(true);},-999999);
 add_action('admin_init',static function(){$GLOBALS['ux_f0_route']=hrtime(true);},-999999);
 add_filter('pre_wp_mail',static function(){return true;},PHP_INT_MAX);
}
