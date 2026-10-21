<?php
/**
 * EIPSI Forms - Weekly T1 Reminders Cron Job (Phase 5 T1-Anchor)
 * 
 * Sends weekly reminder emails to participants who:
 * - Haven't completed T1
 * - Have received all configured nudges (reminder_count >= start_after_nudge)
 * - Haven't exceeded max weekly reminders
 * - Haven't been auto-expired
 * 
 * Runs: Daily via WP Cron (checks if a week has passed since last reminder)
 * 
 * @package EIPSI_Forms
 * @since 2.6.0
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/notifications/bootstrap.php';


/**
 * Main cron function - sends weekly reminders for T1 non-completers
 */
function eipsi_weekly_t1_reminders() {
        return EIPSI_Notification_Weekly_T1_Reminder_Service::eipsi_weekly_t1_reminders();
    }

/**
 * Send weekly T1 reminder email
 * 
 * @param object $assignment Assignment data with participant and study info
 * @param int $reminder_number Current reminder number (1-indexed)
 * @return bool True if sent successfully
 */
function eipsi_send_weekly_t1_reminder($assignment, $reminder_number) {
        return EIPSI_Notification_Weekly_T1_Reminder_Service::eipsi_send_weekly_t1_reminder($assignment, $reminder_number);
    }

/**
 * Mark T1 assignment as expired and send notification
 * 
 * @param object $assignment Assignment data
 */
function eipsi_expire_t1_assignment($assignment) {
        return EIPSI_Longitudinal_Assignment_Transition_Service::eipsi_expire_t1_assignment($assignment);
    }

/**
 * Register the cron job
 * Called from eipsi-forms.php on plugin activation
 */
function eipsi_schedule_weekly_t1_reminders_cron() {
    if (!wp_next_scheduled('eipsi_weekly_t1_reminders_cron')) {
        wp_schedule_event(time(), 'daily', 'eipsi_weekly_t1_reminders_cron');
        error_log('[EIPSI Weekly T1] Cron job scheduled (daily)');
    }
}

/**
 * Unregister the cron job
 * Called from eipsi-forms.php on plugin deactivation
 */
function eipsi_unschedule_weekly_t1_reminders_cron() {
    $timestamp = wp_next_scheduled('eipsi_weekly_t1_reminders_cron');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'eipsi_weekly_t1_reminders_cron');
        error_log('[EIPSI Weekly T1] Cron job unscheduled');
    }
}

// Hook the cron action
add_action('eipsi_weekly_t1_reminders_cron', 'eipsi_weekly_t1_reminders');
