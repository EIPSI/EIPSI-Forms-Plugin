<?php
if (PHP_SAPI !== 'cli' || getenv('WORDPRESS_DB_HOST') !== 'eipsi-m0-db:3306' || getenv('WORDPRESS_DB_NAME') !== 'm0') {
    fwrite(STDERR, "M0 requires its disposable Docker database.\n"); exit(1);
}
ob_start();
$_SERVER['HTTP_HOST'] = '127.0.0.1:18080';
$_SERVER['REQUEST_URI'] = '/';
if (getenv('EIPSI_M0_PROFILE') === 'admin') { define('WP_ADMIN', true); }
require '/var/www/html/wp-load.php';
if (!get_option('eipsi_m0_isolated_install')) { exit(1); }
add_filter('pre_wp_mail', function ($result, $atts) { $GLOBALS['m0_mail'][] = $atts; return true; }, PHP_INT_MAX, 2);
wp_set_current_user(1);
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';
function m0_assert($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
function m0_admin_cookies() {
    if (empty($GLOBALS['m0_admin_cookies'])) {
        $token = WP_Session_Tokens::get_instance(1)->create(time() + 3600);
        foreach (array(LOGGED_IN_COOKIE => 'logged_in', AUTH_COOKIE => 'auth') as $name => $scheme) {
            $GLOBALS['m0_admin_cookies'][$name] = wp_generate_auth_cookie(1, time() + 3600, $scheme, $token);
        }
    }
    $_COOKIE[LOGGED_IN_COOKIE] = $GLOBALS['m0_admin_cookies'][LOGGED_IN_COOKIE];
    return $GLOBALS['m0_admin_cookies'];
}
function m0_admin_nonce($action) { m0_admin_cookies(); return wp_create_nonce($action); }
function m0_http($path, $body = null, $authenticated = false) {
    $cookies = array();
    if ($authenticated) {
        foreach (m0_admin_cookies() as $name => $value) {
            $cookies[] = new WP_Http_Cookie(array('name' => $name, 'value' => $value));
        }
    }
    return wp_remote_request('http://127.0.0.1' . $path, array('method' => $body === null ? 'GET' : 'POST', 'body' => $body, 'cookies' => $cookies, 'timeout' => 25, 'redirection' => 0, 'headers' => array('Host' => '127.0.0.1:18080')));
}
