<?php
if (!defined('ABSPATH')) { exit; }
require_once __DIR__.'/Policy.php';
add_action('plugins_loaded',array('EIPSI_Admin_Authorization_Policy','upgrade'),1);
add_action('switch_blog',array('EIPSI_Admin_Authorization_Policy','switched'),20);
add_action('wp_initialize_site',array('EIPSI_Admin_Authorization_Policy','initialized'),20);
add_filter('editable_roles',array('EIPSI_Admin_Authorization_Policy','roleLabels'));
