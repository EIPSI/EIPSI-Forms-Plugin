<?php
/**
 * EIPSI Forms - Wave Skipping Cron Job (Phase 5 T1-Anchor)
 * 
 * Marks waves as 'skipped' if:
 * - A subsequent wave has become available
 * - The participant hasn't completed the prior wave
 * 
 * Example: If T4 is available but T3 is still pending/in_progress, T3 gets skipped.
 * 
 * Runs: Hourly via WP Cron
 * 
 * @package EIPSI_Forms
 * @since 2.6.0
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/longitudinal/bootstrap.php';

/**
 * Main cron function - iterates all participants with pending assignments
 */
function eipsi_check_wave_skipping() {
        return EIPSI_Longitudinal_Assignment_Lifecycle_Service::eipsi_check_wave_skipping();
    }

/**
 * Check and skip waves for a single participant
 * 
 * @param int $participant_id Participant ID
 * @param int $study_id Study ID
 * @return int Number of waves skipped
 */
function eipsi_check_wave_skipping_for_participant($participant_id, $study_id) {
        return EIPSI_Longitudinal_Assignment_Lifecycle_Service::eipsi_check_wave_skipping_for_participant($participant_id, $study_id);
    }

/**
 * Register the cron job
 * Called from eipsi-forms.php on plugin activation
 */
function eipsi_schedule_wave_skipping_cron() {
    if (!wp_next_scheduled('eipsi_wave_skipping_cron')) {
        wp_schedule_event(time(), 'hourly', 'eipsi_wave_skipping_cron');
        error_log('[EIPSI Wave Skipping] Cron job scheduled (hourly)');
    }
}

/**
 * Unregister the cron job
 * Called from eipsi-forms.php on plugin deactivation
 */
function eipsi_unschedule_wave_skipping_cron() {
    $timestamp = wp_next_scheduled('eipsi_wave_skipping_cron');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'eipsi_wave_skipping_cron');
        error_log('[EIPSI Wave Skipping] Cron job unscheduled');
    }
}

// Hook the cron action
add_action('eipsi_wave_skipping_cron', 'eipsi_check_wave_skipping');
