<?php
/**
 * EIPSI Cron Diagnostic Tool
 * 
 * Verifica el estado de los cron jobs y ayuda a diagnosticar problemas.
 * 
 * @package EIPSI_Forms
 * @since 2.6.1
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get cron diagnostic information
 * 
 * @return array Diagnostic data
 */
function eipsi_get_cron_diagnostic() {
    $diagnostics = array(
        'wp_cron_disabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
        'current_time' => current_time('mysql'),
        'server_time' => date('Y-m-d H:i:s'),
        'timezone' => wp_timezone_string(),
        'cron_jobs' => array(),
        'recent_executions' => array(),
        'issues' => array(),
    );
    
    // Check critical cron jobs
    $critical_jobs = array(
        'eipsi_send_wave_reminders_hourly' => 'every_minute',
        'eipsi_process_assignment_expirations' => 'every_minute',
        'eipsi_process_wave_availability' => 'every_minute',
        'eipsi_wave_skipping_cron' => 'hourly',
        'eipsi_weekly_t1_reminders_cron' => 'daily',
    );
    
    foreach ($critical_jobs as $hook => $expected_schedule) {
        $next_run = wp_next_scheduled($hook);
        $job_info = array(
            'hook' => $hook,
            'expected_schedule' => $expected_schedule,
            'is_scheduled' => (bool) $next_run,
            'next_run' => $next_run ? date('Y-m-d H:i:s', $next_run) : null,
            'next_run_in' => $next_run ? human_time_diff(time(), $next_run) : null,
            'is_overdue' => $next_run && $next_run < time(),
        );
        
        // Check for issues
        if (!$next_run) {
            $diagnostics['issues'][] = "Cron job '{$hook}' is not scheduled!";
        } elseif ($job_info['is_overdue']) {
            $minutes_overdue = floor((time() - $next_run) / 60);
            $diagnostics['issues'][] = "Cron job '{$hook}' is {$minutes_overdue} minutes overdue!";
        }
        
        $diagnostics['cron_jobs'][$hook] = $job_info;
    }
    
    // Check recent executions from transients
    $execution_hooks = array(
        'eipsi_cron_last_run_wave_reminders',
        'eipsi_cron_last_run_session_cleanup',
        'eipsi_cron_last_run_email_retry',
        'eipsi_cron_last_run_dropout_recovery',
    );
    
    foreach ($execution_hooks as $transient) {
        $last_run = get_transient($transient);
        if ($last_run) {
            $diagnostics['recent_executions'][$transient] = array(
                'last_run' => $last_run,
                'minutes_ago' => floor((strtotime(current_time('mysql')) - strtotime($last_run)) / 60),
            );
        }
    }
    
    // Check if DISABLE_WP_CRON is set
    if (!$diagnostics['wp_cron_disabled']) {
        $diagnostics['issues'][] = "DISABLE_WP_CRON is not set to true. WordPress cron will only run when someone visits the site!";
    }
    
    // Check for stuck cron jobs (overdue by more than 5 minutes)
    $all_crons = _get_cron_array();
    $stuck_count = 0;
    foreach ($all_crons as $timestamp => $cron) {
        if ($timestamp < (time() - 300)) { // 5 minutes
            $stuck_count++;
        }
    }
    
    if ($stuck_count > 0) {
        $diagnostics['issues'][] = "{$stuck_count} cron jobs are stuck (overdue by more than 5 minutes). This suggests the cron system is not running.";
    }
    
    return $diagnostics;
}

/**
 * Force run a specific cron job (for testing)
 * Note: This function is similar to the one in ajax-phase3-handlers.php
 * but uses the existing hook name, so we don't redeclare it.
 */
// Function already exists in ajax-phase3-handlers.php, no need to redeclare

/**
 * Reschedule all EIPSI cron jobs
 */
add_action('wp_ajax_eipsi_reschedule_all_crons', 'eipsi_ajax_reschedule_all_crons');
function eipsi_ajax_reschedule_all_crons() {
    check_ajax_referer('eipsi_admin_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }
    
    $cron_jobs = array(
        'eipsi_send_wave_reminders_hourly' => 'every_minute',
        'eipsi_send_dropout_recovery_hourly' => 'every_minute',
        'eipsi_process_assignment_expirations' => 'every_minute',
        'eipsi_process_wave_availability' => 'every_minute',
        'eipsi_wave_skipping_cron' => 'hourly',
        'eipsi_weekly_t1_reminders_cron' => 'daily',
        'eipsi_purge_access_logs_daily' => 'daily',
        'eipsi_cleanup_unconfirmed_participants_daily' => 'daily',
    );
    
    $rescheduled = array();
    
    foreach ($cron_jobs as $hook => $schedule) {
        // Clear existing schedule
        $timestamp = wp_next_scheduled($hook);
        if ($timestamp) {
            wp_unschedule_event($timestamp, $hook);
        }
        
        // Reschedule
        $result = wp_schedule_event(time(), $schedule, $hook);
        
        $rescheduled[$hook] = array(
            'success' => $result !== false,
            'schedule' => $schedule,
            'next_run' => date('Y-m-d H:i:s', wp_next_scheduled($hook)),
        );
    }
    
    error_log("[EIPSI Cron Diagnostic] Rescheduled all cron jobs");
    
    wp_send_json_success(array(
        'message' => 'All EIPSI cron jobs have been rescheduled',
        'rescheduled' => $rescheduled,
    ));
}
