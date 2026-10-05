<?php
/**
 * Cron Reminders Handler
 *
 * Handles cron jobs for sending reminders and recovery emails
 * for longitudinal studies.
 *
 * @package EIPSI_Forms
 * @since 1.4.2
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/notifications/bootstrap.php';


// v2.2.0 - Load new robust wave availability email service
require_once plugin_dir_path(__FILE__) . 'services/class-wave-availability-email-service.php';

// v2.5.0 - Job Queue for Nudge system
require_once plugin_dir_path(dirname(__FILE__)) . 'includes/services/class-nudge-job-queue.php';

// v2.5.0 - Event-Driven Nudge Scheduler
require_once plugin_dir_path(dirname(__FILE__)) . 'includes/services/class-nudge-event-scheduler.php';

// v2.5.0 - Cache for Nudge calculations
require_once plugin_dir_path(dirname(__FILE__)) . 'includes/services/class-nudge-cache.php';

/**
 * Send wave reminders - Hourly cron job
 *
 * @since 1.4.2
 */
function eipsi_send_wave_reminders_hourly($specific_study_id = null) {
        return EIPSI_Notification_Reminder_Service::eipsi_send_wave_reminders_hourly($specific_study_id);
    }
add_action('eipsi_send_wave_reminders_hourly', 'eipsi_send_wave_reminders_hourly');

/**
 * Send dropout recovery emails - Hourly cron job
 *
 * @since 1.4.2
 */
function eipsi_send_dropout_recovery_hourly() {
        return EIPSI_Notification_Dropout_Recovery_Service::eipsi_send_dropout_recovery_hourly();
    }
add_action('eipsi_send_dropout_recovery_hourly', 'eipsi_send_dropout_recovery_hourly');

/**
 * Legacy: Send daily reminders
 *
 * @since 1.0.0
 * @deprecated 1.4.2 Use eipsi_send_wave_reminders_hourly instead
 */
function eipsi_send_take_reminders_daily() {
    error_log('[EIPSI Cron] Legacy daily reminders triggered (deprecated)');
}
add_action('eipsi_send_take_reminders_daily', 'eipsi_send_take_reminders_daily');

/**
 * Legacy: Send weekly reminders
 *
 * @since 1.0.0
 * @deprecated 1.4.2 Use eipsi_send_wave_reminders_hourly instead
 */
function eipsi_send_take_reminders_weekly() {
    error_log('[EIPSI Cron] Legacy weekly reminders triggered (deprecated)');
}
add_action('eipsi_send_take_reminders_weekly', 'eipsi_send_take_reminders_weekly');

/**
 * AJAX handler to save cron reminders configuration
 *
 * @since 1.4.2
 */
add_action('wp_ajax_eipsi_save_cron_reminders_config', 'eipsi_ajax_save_cron_reminders_config');

function eipsi_ajax_save_cron_reminders_config() {
    check_ajax_referer('eipsi_admin_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('Unauthorized', 'eipsi-forms')));
    }

    $study_id = isset($_POST['study_id']) ? intval($_POST['study_id']) : 0;
    if (!$study_id) {
        wp_send_json_error(array('message' => __('Missing study ID', 'eipsi-forms')));
    }

    // Validate and sanitize inputs
    $config = array(
        'reminders_enabled' => isset($_POST['reminders_enabled']),
        'reminder_days_before' => isset($_POST['reminder_days_before']) ? max(1, min(30, intval($_POST['reminder_days_before']))) : 3,
        'max_reminder_emails' => isset($_POST['max_reminder_emails']) ? max(1, min(500, intval($_POST['max_reminder_emails']))) : 100,
        'investigator_alert_enabled' => isset($_POST['investigator_alert_enabled']),
        'investigator_alert_email' => isset($_POST['investigator_alert_email']) ? sanitize_email($_POST['investigator_alert_email']) : get_option('admin_email'),
    );
    
    // v2.6.0 - Weekly T1 reminders config
    if (isset($_POST['weekly_reminders']) && is_array($_POST['weekly_reminders'])) {
        $config['weekly_reminders'] = array(
            'enabled' => isset($_POST['weekly_reminders']['enabled']),
            'start_after_nudge' => 4, // Always start after nudge 4
            'frequency_days' => isset($_POST['weekly_reminders']['frequency_days']) ? max(1, min(30, intval($_POST['weekly_reminders']['frequency_days']))) : 7,
            'max_reminders' => !empty($_POST['weekly_reminders']['max_reminders']) ? max(1, min(52, intval($_POST['weekly_reminders']['max_reminders']))) : null,
            'auto_expire_after' => !empty($_POST['weekly_reminders']['auto_expire_after']) ? max(1, min(365, intval($_POST['weekly_reminders']['auto_expire_after']))) : null,
        );
    }

    // Get existing config
    global $wpdb;
    $existing_config = $wpdb->get_var($wpdb->prepare(
        "SELECT config FROM {$wpdb->prefix}survey_studies WHERE id = %d",
        $study_id
    ));

    $existing_data = array();
    if ($existing_config) {
        $existing_data = json_decode($existing_config, true);
        if (!is_array($existing_data)) {
            $existing_data = array();
        }
    }

    // Merge configs
    $merged_config = array_merge($existing_data, $config);

    // Update database
    $result = $wpdb->update(
        $wpdb->prefix . 'survey_studies',
        array('config' => wp_json_encode($merged_config)),
        array('id' => $study_id),
        array('%s'),
        array('%d')
    );

    if ($result === false) {
        wp_send_json_error(array('message' => __('Database error: ', 'eipsi-forms') . $wpdb->last_error));
    }

    wp_send_json_success(array('message' => __('Configuration saved successfully', 'eipsi-forms')));
}

/**
 * AJAX handler for running reminders cron manually
 * Useful for testing minute-based intervals
 *
 * @since 1.6.0
 */
add_action('wp_ajax_eipsi_run_reminders_cron', 'eipsi_run_reminders_cron_handler');

function eipsi_run_reminders_cron_handler() {
    check_ajax_referer('eipsi_cron_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('Permission denied', 'eipsi-forms')));
    }

    // Get selected study ID from request
    $study_id = isset($_POST['study_id']) ? intval($_POST['study_id']) : 0;
    
    error_log('[EIPSI] Manual cron execution triggered by user' . ($study_id ? " for study {$study_id}" : ''));

    global $wpdb;
    
    // Get study info for test email
    $study_info = null;
    $investigator_email = null;
    if ($study_id) {
        $study_info = $wpdb->get_row($wpdb->prepare(
            "SELECT study_name, study_code, config 
             FROM {$wpdb->prefix}survey_studies 
             WHERE id = %d",
            $study_id
        ));
        if ($study_info) {
            $config = !empty($study_info->config) ? json_decode($study_info->config, true) : array();
            $investigator_email = !empty($config['investigator_alert_email']) 
                ? $config['investigator_alert_email'] 
                : get_option('admin_email');
        }
    }

    // Run the reminders function for specific study (or all if no study selected)
    $result = eipsi_send_wave_reminders_hourly($study_id > 0 ? $study_id : null);

    // Send test email to investigator using EIPSI_Email_Service (with SMTP)
    $test_email_sent = false;
    if ($study_info && $investigator_email) {
        // Ensure email service is loaded
        if (!class_exists('EIPSI_Email_Service')) {
            require_once plugin_dir_path(__FILE__) . 'services/class-email-service.php';
        }
        
        $test_subject = "[EIPSI Forms] Prueba de Recordatorio - {$study_info->study_name}";
        $test_message = sprintf(
            "Este es un email de prueba del sistema EIPSI Forms.\n\n" .
            "Estudio: %s (%s)\n" .
            "ID de Estudio: %d\n" .
            "Fecha/Hora: %s\n" .
            "Ejecutado por: %s\n\n" .
            "Resumen de ejecución:\n" .
            "- Estudios procesados: %d\n" .
            "- Emails enviados a participantes: %d\n\n" .
            "Si recibiste este email, el sistema de recordatorios está funcionando correctamente.",
            $study_info->study_name,
            $study_info->study_code,
            $study_id,
            date('Y-m-d H:i:s'),
            wp_get_current_user()->display_name,
            $result['processed_studies'] ?? 0,
            $result['total_emails_sent'] ?? 0
        );
        
        // v2.1.3: Use wp_mail directly for test email (send_email is private)
        // Note: Test emails to investigators don't need SMTP logging
        $headers = array('Content-Type: text/html; charset=UTF-8');
        $test_email_sent = wp_mail($investigator_email, $test_subject, nl2br($test_message), $headers);

        error_log("[EIPSI] Test email sent to investigator: {$investigator_email} - Result: " . ($test_email_sent ? 'SUCCESS' : 'FAILED'));
    }

    // Get last cron logs
    $logs = $wpdb->get_results(
        "SELECT id, survey_id, participant_id, email_type, status, sent_at, error_message
         FROM {$wpdb->prefix}survey_email_log
         WHERE email_type = 'reminder'
         AND sent_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
         ORDER BY sent_at DESC
         LIMIT 10"
    );

    // Build response message
    if ($study_info) {
        $message = sprintf('Cron ejecutado para estudio "%s".', $study_info->study_name);
    } else {
        $message = 'Cron ejecutado para todos los estudios activos.';
    }
    
    if (!empty($logs)) {
        $count = count($logs);
        $message .= " Enviados {$count} recordatorios a participantes.";
    }
    
    if ($test_email_sent) {
        $message .= " Email de prueba enviado a {$investigator_email}.";
    }

    wp_send_json_success(array(
        'message' => $message,
        'logs' => $logs,
        'study_id' => $study_id,
        'test_email_sent' => $test_email_sent,
        'investigator_email' => $investigator_email
    ));
}

/**
 * AJAX handler for clearing rate limit transients
 * Useful for testing minute-based intervals repeatedly
 *
 * @since 1.6.0
 */
add_action('wp_ajax_eipsi_clear_rate_limits', 'eipsi_clear_rate_limits_handler');

function eipsi_clear_rate_limits_handler() {
    check_ajax_referer('eipsi_cron_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('Permission denied', 'eipsi-forms')));
    }

    global $wpdb;

    // Delete all eipsi_reminder transients
    $deleted = $wpdb->query(
        "DELETE FROM {$wpdb->options}
         WHERE option_name LIKE '_transient_eipsi_reminder_%'
         OR option_name LIKE '_transient_timeout_eipsi_reminder_%'"
    );

    error_log('[EIPSI] Rate limits cleared by user. Deleted: ' . ($deleted !== false ? $deleted : 0));

    wp_send_json_success(array(
        'message' => __('Rate limits cleared. You can now test again.', 'eipsi-forms'),
        'deleted' => $deleted !== false ? $deleted : 0
    ));
}

/**
 * Job Queue Worker - Process pending nudge jobs
 * 
 * @since 2.5.0
 */
function eipsi_process_nudge_jobs_worker() {
        return EIPSI_Notification_Cron_Adapters::eipsi_process_nudge_jobs_worker();
    }
add_action('eipsi_process_nudge_jobs', 'eipsi_process_nudge_jobs_worker');

/**
 * Schedule Job Worker cron (runs every 5 minutes)
 */
function eipsi_schedule_job_worker() {
    $hook = 'eipsi_process_nudge_jobs';
    $timestamp = wp_next_scheduled($hook);
    
    // If event exists, verify it has valid schedule
    if ($timestamp) {
        $crons = _get_cron_array();
        $event_found = false;
        
        if (is_array($crons) && isset($crons[$timestamp][$hook])) {
            foreach ($crons[$timestamp][$hook] as $event) {
                if (isset($event['schedule']) && $event['schedule'] === 'every_5_minutes') {
                    $event_found = true;
                    break;
                }
            }
        }
        
        // If event has invalid schedule, clear it
        if (!$event_found) {
            wp_clear_scheduled_hook($hook);
            $timestamp = false;
        }
    }
    
    // Schedule if not exists or was cleared
    if (!$timestamp) {
        $result = wp_schedule_event(time(), 'every_5_minutes', $hook);
        if ($result !== false) {
            error_log('[EIPSI JobWorker] Scheduled job worker cron');
        }
    }
}
add_action('wp', 'eipsi_schedule_job_worker');

// ============================================================================
// PHASE 5 T1-ANCHOR: TIME TRAVEL AJAX HANDLERS (Only in WP_DEBUG)
// ============================================================================

/**
 * AJAX handler: Execute time travel
 * 
 * @since 2.6.0
 */
add_action('wp_ajax_eipsi_time_travel', 'eipsi_ajax_time_travel');
function eipsi_ajax_time_travel() {
    check_ajax_referer('eipsi_admin_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }
    
    if (!defined('WP_DEBUG') || !WP_DEBUG) {
        wp_send_json_error('Time travel only available in WP_DEBUG mode');
    }
    
    $interval = sanitize_text_field($_POST['interval']);
    $study_id = intval($_POST['study_id']);
    
    if (empty($interval)) {
        wp_send_json_error('Interval is required');
    }
    
    if (empty($study_id)) {
        wp_send_json_error('Study ID is required');
    }
    
    $result = eipsi_simulate_time_travel($interval, $study_id, null);
    
    if (isset($result['error'])) {
        wp_send_json_error($result['error']);
    }
    
    // Add message about auto-executed crons
    $result['message'] = sprintf(
        'Time travel ejecutado: %s. Cron jobs ejecutados automáticamente: Wave Skipping, Wave Reminders, Weekly T1 Reminders.',
        $interval
    );
    
    wp_send_json_success($result);
}

/**
 * AJAX handler: Trigger cron manually
 * 
 * @since 2.6.0
 */
add_action('wp_ajax_eipsi_trigger_cron', 'eipsi_ajax_trigger_cron');
function eipsi_ajax_trigger_cron() {
    check_ajax_referer('eipsi_admin_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }
    
    if (!defined('WP_DEBUG') || !WP_DEBUG) {
        wp_send_json_error('Manual cron only available in WP_DEBUG mode');
    }
    
    $hook = sanitize_text_field($_POST['hook']);
    $study_id = intval($_POST['study_id']);
    
    if (empty($hook)) {
        wp_send_json_error('Hook is required');
    }
    
    // Validate hook is an EIPSI cron
    $allowed_hooks = array(
        'eipsi_wave_skipping_cron',
        'eipsi_weekly_t1_reminders_cron',
        'eipsi_send_wave_reminders_hourly',
        'eipsi_send_dropout_recovery_hourly'
    );
    
    if (!in_array($hook, $allowed_hooks)) {
        wp_send_json_error('Invalid cron hook');
    }
    
    error_log("[EIPSI Manual Cron] Triggering: {$hook} for study_id={$study_id}");
    
    do_action($hook);
    
    wp_send_json_success(array('hook' => $hook, 'study_id' => $study_id));
}

/**
 * AJAX handler: Get time travel status
 * 
 * @since 2.6.0
 */
add_action('wp_ajax_eipsi_get_time_travel_status', 'eipsi_ajax_get_time_travel_status');
function eipsi_ajax_get_time_travel_status() {
    check_ajax_referer('eipsi_admin_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }
    
    if (!defined('WP_DEBUG') || !WP_DEBUG) {
        wp_send_json_error('Status check only available in WP_DEBUG mode');
    }
    
    $study_id = intval($_POST['study_id']);
    
    if (empty($study_id)) {
        wp_send_json_error('Study ID is required');
    }
    
    $status = eipsi_get_time_travel_status($study_id, null);
    
    wp_send_json_success($status);
}
