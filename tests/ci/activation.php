<?php
/** Real admin activation in a fresh process, including the plugin bootstrap. */
if (PHP_SAPI !== 'cli' || getenv('WORDPRESS_DB_HOST') !== 'eipsi-m0-db:3306' || getenv('WORDPRESS_DB_NAME') !== 'm0') { exit(1); }
define('WP_ADMIN', true);
$_SERVER['HTTP_HOST'] = '127.0.0.1:18080';
$_SERVER['REQUEST_URI'] = '/wp-admin/plugins.php';
require '/var/www/html/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
if (!get_option('eipsi_m0_isolated_install')) { exit(1); }
wp_set_current_user(1);
$plugin = 'EIPSI-Forms-Plugin/eipsi-forms.php';
if (($argv[1] ?? '') === 'deactivate') {
    deactivate_plugins($plugin);
    if (is_plugin_active($plugin)) { exit(1); }
    echo "Plugin deactivated; next process must load bootstrap again\n";
    exit(0);
}
if (is_plugin_active($plugin)) {
    fwrite(STDERR, "Activation fixture must start inactive\n");
    exit(1);
}
$result = activate_plugin($plugin);
$output = is_wp_error($result) ? $result->get_error_data('unexpected_output') : '';
$passed = !is_wp_error($result) && is_plugin_active($plugin) && $output === '' && strlen($output) === 0;
echo ($passed ? 'PASS' : 'FAIL') . " Admin activation succeeds with exactly 0 unexpected bytes\n";
if (!$passed) {
    echo json_encode(array('error' => is_wp_error($result) ? $result->get_error_code() : null,
        'bytes' => is_string($output) ? strlen($output) : null, 'output' => $output), JSON_UNESCAPED_SLASHES) . "\n";
}
echo '1 tests, ' . ($passed ? '0' : '1') . " failures\n";
exit($passed ? 0 : 1);
