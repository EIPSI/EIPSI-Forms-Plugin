<?php
/** Isolated scheduler entry: no WordPress bootstrap, DB or existing filters. */
define('ABSPATH', __DIR__);
define('MINUTE_IN_SECONDS', 60);
define('DAY_IN_SECONDS', 86400);
define('WEEK_IN_SECONDS', 604800);
$filters = array(); $events = array(); $invalid = array();
function add_filter($hook, $callback) { $GLOBALS['filters'][$hook][] = $callback; }
function __($text, $domain) { return $text; }
function get_option($name) { return false; }
function wp_get_schedules() {
    $s = array('daily'=>array('interval'=>86400),'hourly'=>array('interval'=>3600));
    foreach ($GLOBALS['filters']['cron_schedules'] ?? array() as $callback) { $s = $callback($s); }
    return $s;
}
function wp_next_scheduled($hook) { return isset($GLOBALS['events'][$hook]) ? 1 : false; }
function wp_schedule_event($time, $schedule, $hook) {
    if (!isset(wp_get_schedules()[$schedule])) { $GLOBALS['invalid'][] = $schedule; return false; }
    $GLOBALS['events'][$hook][] = $schedule; return true;
}
require dirname(__DIR__, 2).'/includes/bootstrap/class-cron-registry.php';
EIPSI_Cron_Registry::schedule_activation();
$checks = array(
    'schedule entry registers custom intervals before use'=>!$invalid,
    'monthly event exactly once from unregistered context'=>($events['eipsi_cleanup_pool_email_logs_monthly'] ?? array()) === array('eipsi_monthly'),
);
EIPSI_Cron_Registry::register_schedules();
EIPSI_Cron_Registry::schedule_activation();
$checks['repeat registration and scheduling idempotent'] = count($filters['cron_schedules'] ?? array()) === 1 && count($events) === 13 && !array_filter($events, static function($items) { return count($items) !== 1; });
$failures = 0;
foreach ($checks as $name => $pass) { echo ($pass ? 'PASS ' : 'FAIL ').$name."\n"; if (!$pass) { $failures++; } }
echo count($checks)." tests, $failures failures\n";
exit($failures ? 1 : 0);
