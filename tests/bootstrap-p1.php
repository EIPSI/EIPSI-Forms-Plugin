<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('EIPSI_P1_TESTS', true);
// Buffer CLI output so the real session service can exercise setcookie().
ob_start();
require __DIR__ . '/bootstrap.php';
define('WP_DEBUG', false);
define('WP_DEBUG_DISPLAY', false);
define('WP_DEBUG_LOG', false);
function apply_filters($hook, $value, ...$args) { return $value; }
function has_filter($hook, $callback = false) { return false; }
function do_action($hook, ...$args) { $GLOBALS['p1_events'][] = array($hook, $args); }
function is_multisite() { return false; }
function mbstring_binary_safe_encoding(...$args) {}
function reset_mbstring_encoding() {}
function wp_load_translations_early() {}
function wp_debug_backtrace_summary(...$args) { return ''; }
function _doing_it_wrong($function, $message, $version) { throw new RuntimeException($function . ': ' . $message); }
function is_ssl() { return false; }
function wp_generate_password($length = 12, ...$args) { return substr(bin2hex(random_bytes($length)), 0, $length); }
function sanitize_email($email) { return filter_var($email, FILTER_SANITIZE_EMAIL); }
function wp_check_password($password, $hash, $id = '') { return password_verify($password, $hash); }
function wp_unslash($value) { return is_array($value) ? array_map('wp_unslash', $value) : stripslashes($value); }
function sanitize_title($value) { return trim(strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $value)), '-'); }
function get_posts($args) {
    $name = $args['meta_value'] ?? $args['meta_query'][0]['value'] ?? $args['name'] ?? '';
    return $GLOBALS['p1_forms'][$name] ?? array();
}
function get_post($id) {
    if (!in_array((int) $id, array(101, 102, 103), true)) { return null; }
    return (object) array('ID' => (int) $id, 'post_type' => 'eipsi_form_template', 'post_status' => 'publish', 'post_content' => '<form>fixture</form>');
}
function get_post_meta($id, $key, $single = false) { return $GLOBALS['p1_meta'][$id][$key] ?? ''; }
function is_wp_error($value) { return $value instanceof WP_Error; }
function esc_attr($value) { return htmlspecialchars((string) $value, ENT_QUOTES); }
function esc_html__($value, $domain = '') { return $value; }
function wp_kses_post($value) { return $value; }
function do_blocks($value) { return $value; }
function eipsi_forms_enqueue_frontend_assets() {}
require ABSPATH . 'wp-includes/class-wpdb.php';
$service_source = getenv('EIPSI_P1_SERVICE_DIR') ?: EIPSI_FORMS_PLUGIN_DIR . 'admin/services';
require $service_source . '/class-auth-service.php';
require EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-participant-service.php';
require $service_source . '/class-magic-links-service.php';
require EIPSI_FORMS_PLUGIN_DIR . 'admin/database-schema-manager.php';
require EIPSI_FORMS_PLUGIN_DIR . 'includes/form-template-render.php';
class P1Database extends wpdb {
    public $observed_queries = array();
    public function query($query) { $this->observed_queries[] = $query; return parent::query($query); }
}
function p1_fixture($callback) {
    if (!getenv('WORDPRESS_DB_HOST')) { throw new RuntimeException('P1 integration requires the Docker WordPress database environment'); }
    $db = new P1Database(getenv('WORDPRESS_DB_USER'), getenv('WORDPRESS_DB_PASSWORD'), getenv('WORDPRESS_DB_NAME'), getenv('WORDPRESS_DB_HOST'));
    $GLOBALS['wpdb'] = $db;
    $prefix = 'eipsi_p1_test_' . bin2hex(random_bytes(6)) . '_';
    $db->set_prefix($prefix);
    $db->suppress_errors(true);
    $tables = array('survey_studies', 'survey_participants', 'survey_sessions', 'survey_magic_links', 'survey_waves', 'survey_assignments',
                    'vas_form_results', 'eipsi_partial_responses', 'eipsi_pool_assignments', 'eipsi_emergency_submissions');
    if (defined('EIPSI_P1B_TESTS')) { $tables[] = 'survey_nudge_jobs'; $tables[] = 'survey_audit_log'; }
    $_POST = $_GET = $_COOKIE = $_SESSION = array();
    $GLOBALS['p0_options'] = array('admin_email' => 'test@example.invalid');
    $GLOBALS['p0_admin'] = false; $GLOBALS['p0_mail'] = array(); $GLOBALS['p1_events'] = array();
    $GLOBALS['p1_forms'] = array('long-form' => array(101), 'anonymous-form' => array(102), 'other-form' => array(103));
    $GLOBALS['p1_meta'] = array(101 => array('_eipsi_form_name' => 'long-form'), 102 => array('_eipsi_form_name' => 'anonymous-form'), 103 => array('_eipsi_form_name' => 'other-form'));
    try {
        foreach ($tables as $table) {
            p0_assert($db->query(EIPSI_Database_Schema_Manager::get_table_sql($table)) !== false, 'Fixture schema failed for ' . $table . ': ' . $db->last_error);
        }
        $now = current_time('mysql');
        foreach (array(3, 4) as $id) {
            p0_assert($db->insert($prefix . 'survey_studies', array('id' => $id, 'study_code' => 'study-' . $id, 'study_name' => 'Study fixture', 'created_at' => $now, 'updated_at' => $now)) !== false, 'Study fixture failed');
        }
        foreach (array(7 => 3, 8 => 3, 9 => 4) as $id => $study_id) {
            p0_assert($db->insert($prefix . 'survey_participants', array('id' => $id, 'survey_id' => $study_id, 'email' => 'p' . $id . '@example.invalid',
                'password_hash' => password_hash('test-password', PASSWORD_DEFAULT), 'is_active' => 1, 'consent_decision' => 'accepted', 'created_at' => $now)) !== false, 'Participant fixture failed');
        }
        foreach (array(21 => array(3, 101), 22 => array(4, 103)) as $id => $config) {
            p0_assert($db->insert($prefix . 'survey_waves', array('id' => $id, 'study_id' => $config[0], 'wave_index' => 2, 'name' => 'Wave fixture', 'form_id' => $config[1], 'status' => 'active')) !== false, 'Wave fixture failed');
        }
        foreach (array(7, 8) as $participant_id) {
            p0_assert($db->insert($prefix . 'survey_assignments', array('study_id' => 3, 'wave_id' => 21, 'participant_id' => $participant_id, 'status' => 'pending')) !== false, 'Assignment fixture failed');
        }
        $db->observed_queries = array();
        $callback($db);
    } finally {
        foreach (array_reverse($tables) as $table) { $db->query("DROP TABLE IF EXISTS `{$prefix}{$table}`"); }
        $db->close();
        $_COOKIE = array();
    }
}
function p1_session($id = 7, $study_id = 3) {
    $session = EIPSI_Auth_Service::create_session($id, $study_id);
    p0_assert($session['success'], 'Session fixture denied: ' . ($session['error'] ?? 'unknown'));
    $_COOKIE[$session['cookie_name']] = $session['token'];
    return $session;
}
function p1_magic_link($participant_id = 7, $study_id = 3, $used = false, $expired = false) {
    global $wpdb;
    $token = bin2hex(random_bytes(16));
    $wpdb->insert($wpdb->prefix . 'survey_magic_links', array('participant_id' => $participant_id, 'survey_id' => $study_id,
        'token_hash' => hash('sha256', $token), 'used_at' => $used ? current_time('mysql') : null,
        'expires_at' => gmdate('Y-m-d H:i:s', time() + ($expired ? -3600 : 3600))));
    return $token;
}
function p1_submit_request($form = 'long-form') {
    return array('form_id' => $form, 'participant_id' => 'p-browser-tracking', 'session_id' => 'browser-session',
                 'nonce' => 'valid:eipsi_forms_nonce', 'email' => 'p8@example.invalid', 'answer' => '4');
}
function p1_assert_no_responses($db) {
    p0_assert((int) $db->get_var("SELECT COUNT(*) FROM {$db->prefix}vas_form_results") === 0, 'Unauthorized response persisted');
    p0_assert((int) $db->get_var("SELECT COUNT(*) FROM {$db->prefix}eipsi_emergency_submissions") === 0, 'Unauthorized emergency response persisted');
}
