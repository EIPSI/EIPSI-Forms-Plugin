<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/** Isolated WordPress doubles: no WordPress bootstrap, live options, email or participant writes. */
define('ABSPATH', '/var/www/html/');
define('EIPSI_FORMS_PLUGIN_DIR', dirname(__DIR__) . '/');
$GLOBALS['p0_options'] = array('admin_email' => 'test@example.invalid');
$GLOBALS['p0_hooks'] = array();
$GLOBALS['p0_mail'] = array();
$GLOBALS['p0_admin'] = false;
class P0JsonResponse extends RuntimeException {
    public $success;
    public $data;
    public $status;
    public function __construct($success, $data, $status = 200) {
        parent::__construct('JSON response');
        $this->success = $success; $this->data = $data; $this->status = $status;
    }
}
function add_action($hook, $callback, ...$args) { $GLOBALS['p0_hooks'][$hook][] = $callback; }
function add_filter($hook, $callback, ...$args) { $GLOBALS['p0_hooks'][$hook][] = $callback; }
function current_user_can($cap) { return $cap === 'manage_options' && $GLOBALS['p0_admin']; }
function wp_verify_nonce($nonce, $action) { return $nonce === 'valid:' . $action; }
function check_ajax_referer($action, $key) {
    if (!wp_verify_nonce($_POST[$key] ?? '', $action)) {
        throw new P0JsonResponse(false, array('message' => 'Invalid nonce'), 403);
    }
}
function wp_send_json_error($data, $status = 200) { throw new P0JsonResponse(false, $data, $status); }
function wp_send_json_success($data, $status = 200) { throw new P0JsonResponse(true, $data, $status); }
function __($text, $domain = '') { return $text; }
function sanitize_text_field($text) { return trim(strip_tags((string) $text)); }
function absint($value) { return abs((int) $value); }
function current_time($format, $gmt = false) {
    if (defined('EIPSI_P1_TESTS')) {
        return $format === 'timestamp' ? time() : gmdate($format === 'mysql' ? 'Y-m-d H:i:s' : $format);
    }
    return '2026-10-03 12:00:00';
}
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
function get_option($key, $default = false) { return $GLOBALS['p0_options'][$key] ?? $default; }
function update_option($key, $value) { $GLOBALS['p0_options'][$key] = $value; }
function wp_salt($scheme) { return 'isolated-p0-test-salt'; }
function wp_mail($to, $subject, $body) { $GLOBALS['p0_mail'][] = compact('to', 'subject', 'body'); return true; }
function eipsi_get_client_ip() { return '127.0.0.1'; }
function eipsi_get_study_page_url($study_id) { return 'https://example.invalid/study'; }
function home_url($path) { return 'https://example.invalid' . $path; }
function add_query_arg($args, $url) { return $url . '?' . http_build_query($args); }
if (!defined('EIPSI_P1_TESTS')) {
class EIPSI_Auth_Service {
    public static $participant = 7;
    public static $survey = 3;
    public static $destroyed = false;
    public static function get_current_participant() { return self::$participant; }
    public static function get_current_survey() { return self::$survey; }
    public static function destroy_session() { self::$destroyed = true; }
}
}
class P0Database {
    public $prefix = 'p0_';
    public $insert_id = 99; // Deliberately stale: failure must not trust this value.
    public $last_error = '';
    public $insert_result = 1;
    public $create_result = 0;
    public $writes = array();
    public $loads = 0;
    public $member = true;
    public $records = array(7 => array('survey_id' => 3, 'is_active' => 1), 8 => array('survey_id' => 3, 'is_active' => 1));
    public function prepare($sql, ...$args) {
        foreach ($args as $arg) {
            $sql = preg_replace_callback('/%[ds]/', function ($m) use ($arg) {
                return $m[0] === '%d' ? (string) (int) $arg : "'" . addslashes($arg) . "'";
            }, $sql, 1);
        }
        return $sql;
    }
    public function query($sql) {
        $this->writes[] = $sql;
        if ($this->create_result === false) { $this->last_error = 'CREATE denied'; }
        return $this->create_result;
    }
    public function insert($table, $data) {
        $this->writes[] = array('table' => $table, 'data' => $data);
        if ($this->insert_result === false) { $this->last_error = 'INSERT denied'; return false; }
        $this->insert_id = 42;
        return $this->insert_result;
    }
    public function get_var($sql) {
        if (strpos($sql, 'SELECT id FROM p0_survey_participants') !== false) { return $this->member ? 7 : null; }
        if (strpos($sql, 'SHOW TABLES LIKE') !== false) { return strpos($sql, 'p0_survey_participants') !== false ? 'p0_survey_participants' : null; }
        return null;
    }
    public function esc_like($value) { return addcslashes($value, '_%\\'); }
    public function get_results($sql, $output = null) { return array(); }
    public function get_col($sql) { return array(); }
    public function get_row($sql) {
        $this->loads++;
        return (object) array('id' => 1, 'form_id' => 'f', 'participant_id' => '7', 'session_id' => 's',
            'page_index' => 2, 'responses_json' => '{"phq_score":4}', 'created_at' => '2026-10-03', 'updated_at' => '2026-10-03');
    }
    public function update($table, $data, $where, ...$args) {
        $this->writes[] = compact('table', 'data', 'where');
        $this->records[$where['id']] = array_merge($this->records[$where['id']], $data);
        return 1;
    }
}
// Optional source directory permits running the same regressions against the pre-change handlers.
$source_dir = getenv('EIPSI_P0_SOURCE_DIR') ?: dirname(__DIR__) . '/admin';
require $source_dir . '/data-safety-system.php';
require $source_dir . '/ajax-handlers.php';
require EIPSI_FORMS_PLUGIN_DIR . 'admin/partial-responses.php';
function p0_reset() {
    $GLOBALS['wpdb'] = new P0Database();
    $GLOBALS['p0_options'] = array('admin_email' => 'test@example.invalid');
    $GLOBALS['p0_mail'] = array(); $GLOBALS['p0_admin'] = false;
    EIPSI_Auth_Service::$participant = 7; EIPSI_Auth_Service::$survey = 3; EIPSI_Auth_Service::$destroyed = false;
    $_POST = array();
}
function p0_assert($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
function p0_ajax($callback) {
    try { $callback(); } catch (P0JsonResponse $response) { return $response; }
    throw new RuntimeException('Handler did not return JSON');
}
