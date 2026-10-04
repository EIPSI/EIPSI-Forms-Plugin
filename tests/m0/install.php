<?php
// Only the disposable M0 Docker database may run this installation helper.
if (PHP_SAPI !== 'cli' || getenv('WORDPRESS_DB_HOST') !== 'eipsi-m0-db:3306' || getenv('WORDPRESS_DB_NAME') !== 'm0') {
    exit(1);
}
define('WP_INSTALLING', true);
$_SERVER['HTTP_HOST'] = '127.0.0.1:18080';
$_SERVER['REQUEST_URI'] = '/';
require '/var/www/html/wp-load.php';
add_filter('pre_wp_mail', '__return_true');
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
if (!is_blog_installed()) {
    wp_install('EIPSI M0 isolated', 'm0admin', 'm0@example.invalid', true, '', 'm0-isolated-admin');
}
update_option('eipsi_m0_isolated_install', true);
wp_set_current_user(1);
$result = activate_plugin('EIPSI-Forms-Plugin/eipsi-forms.php');
if (is_wp_error($result)) {
    fwrite(STDERR, $result->get_error_message() . "\n");
    exit(1);
}
echo "M0 isolated install and activation OK\n";
