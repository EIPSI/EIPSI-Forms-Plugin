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
    foreach (array_keys(EIPSI_Cron_Registry::owners()) as $hook) {
        if (wp_next_scheduled($hook)) { throw new RuntimeException('Owned event survived deactivation: '.$hook); }
    }
    echo "Plugin deactivated; next process must load bootstrap again\n";
    exit(0);
}
if (is_plugin_active($plugin)) {
    fwrite(STDERR, "Activation fixture must start inactive\n");
    exit(1);
}
// Capture warnings, SQL diagnostics and output independently of WordPress's buffer.
$log = tempnam(sys_get_temp_dir(), 'eipsi-activation-');
ini_set('error_log', $log);
$wpdb->show_errors();
$errors = array();
set_error_handler(static function($severity, $message, $file, $line) use (&$errors) {
    if (error_reporting() & $severity) { $errors[] = "$message at $file:$line"; }
    return false;
});
$queries = array();
add_filter('query', static function($sql) use (&$queries) { $queries[] = $sql; return $sql; });
$before_tables = $wpdb->get_col("SHOW TABLES");
$first = !in_array($wpdb->prefix.'survey_studies', $before_tables, true);
if ($first) {
    foreach ($before_tables as $table) {
        if (preg_match('/^'.preg_quote($wpdb->prefix, '/').'(eipsi_|survey_|vas_)/', $table)) { throw new RuntimeException('Fixture is not empty'); }
    }
    // The install helper marker is the only test-owned EIPSI option allowed.
    $prior = $wpdb->get_col("SELECT option_name FROM {$wpdb->options}");
    foreach ($prior as $option) {
        if (strpos($option, 'eipsi_') === 0 && $option !== 'eipsi_m0_isolated_install') { throw new RuntimeException('Prior EIPSI option: '.$option); }
    }
    foreach (_get_cron_array() as $events) {
        foreach ($events as $hook => $unused) { if (strpos($hook, 'eipsi_') === 0) { throw new RuntimeException('Prior EIPSI cron'); } }
    }
}
$cron_errors = array();
add_action('cron_reschedule_event_error', static function($error) use (&$cron_errors) { $cron_errors[] = $error->get_error_code(); });
ob_start();
$result = activate_plugin($plugin);
$outside = ob_get_clean();
$output = is_wp_error($result) ? $result->get_error_data('unexpected_output') : '';
$checks = array();
$checks['activation succeeds with zero output'] = !is_wp_error($result) && is_plugin_active($plugin) && $outside === '' && $output === '';
$checks['monthly schedule available'] = isset(wp_get_schedules()['eipsi_monthly']);
$monthly = static function() {
    $n = 0;
    foreach (_get_cron_array() as $events) { $n += count($events['eipsi_cleanup_pool_email_logs_monthly'] ?? array()); }
    return $n;
};
$checks['monthly event exactly once'] = $monthly() === 1;
$event = wp_get_scheduled_event('eipsi_cleanup_pool_email_logs_monthly');
$checks['monthly rescheduling succeeds'] = $event && wp_reschedule_event($event->timestamp, $event->schedule, $event->hook, $event->args, true) === true;
// Match wp-cron.php: reschedule, then remove the occurrence just processed.
if ($event) { wp_unschedule_event($event->timestamp, $event->hook, $event->args); }
$checks['repeated activation scheduling is idempotent'] = false;
EIPSI_Cron_Registry::schedule_activation();
$checks['repeated activation scheduling is idempotent'] = $monthly() === 1;
$checks['all registered tables converge'] = true;
foreach (EIPSI_Schema_Registry::get_schema_map() as $slug => $definition) {
    $inspection = EIPSI_Schema_Inspector::inspect($slug);
    if (!$inspection['exists'] || $inspection['issues']) { $checks['all registered tables converge'] = false; }
}
$runner = new EIPSI_Schema_Migration_Runner();
$column = new ReflectionMethod($runner, 'column_exists');
$column->setAccessible(true);
$missing = $wpdb->prefix.'eipsi_activation_absent';
$queries = array();
ob_start();
$absent = $column->invoke($runner, $wpdb, $missing, 'user_fingerprint');
$metadata_output = ob_get_clean();
$checks['O1 absent table false without column query or error'] = $absent === false && $metadata_output === '' && $wpdb->last_error === '' && !array_filter($queries, static function($sql) use ($missing) { return stripos($sql, 'SHOW COLUMNS') !== false && strpos($sql, $missing) !== false; });
$table = $wpdb->prefix.'eipsi_randomization_assignments';
$checks['O2 absent column false'] = $column->invoke($runner, $wpdb, $table, 'activation_absent_column') === false;
$checks['O3 fingerprint column present'] = $column->invoke($runner, $wpdb, $table, 'user_fingerprint') === true;
$failed_db = new class {
    public $last_error = '';
    public function esc_like($value) { return $value; }
    public function prepare($sql, $value) { return $sql; }
    public function get_var($sql) { $this->last_error = 'Injected metadata read failure'; return null; }
};
$checks['real metadata failures remain errors'] = false;
try { $column->invoke($runner, $failed_db, $table, 'user_fingerprint'); }
catch (RuntimeException $error) { $checks['real metadata failures remain errors'] = $error->getMessage() === 'Injected metadata read failure'; }
$migration = $runner->run();
$checks['current schema upgrade idempotent'] = $migration['success'] === true;
$logged = file_get_contents($log);
$checks['zero PHP SQL schema and cron errors'] = !$errors && !$cron_errors && !preg_match('/WordPress database error|PHP (Warning|Notice|Deprecated|Fatal)|invalid_schedule|\[EIPSI Migration\] FAILED|Schema installation failed/i', $logged);
restore_error_handler();
unlink($log);
$passed = !in_array(false, $checks, true);
foreach ($checks as $name => $ok) { echo ($ok ? 'PASS ' : 'FAIL ').$name."\n"; }
if (!$passed) { echo $logged; }
echo '1 tests, ' . ($passed ? '0' : '1') . " failures\n";
exit($passed ? 0 : 1);
