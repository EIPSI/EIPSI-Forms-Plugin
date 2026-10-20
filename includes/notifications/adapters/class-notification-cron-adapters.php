<?php
/** Notifications owner; public WordPress adapters retain existing contracts. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Notification_Cron_Adapters {

public static function eipsi_run_process_wave_availability() {
    global $wpdb;

    EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_notification_cron_adapters_L10'), 'debug');

    $now = current_time('mysql');
    $assignments_table = $wpdb->prefix . 'survey_assignments';

    // Find assignments that just became available (available_at <= NOW, status = pending, no email sent yet)
    $newly_available=EIPSI_Longitudinal_Assignment_Repository::get_newly_available($now);

    if (empty($newly_available)) {
        EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_notification_cron_adapters_L19'), 'debug');
        return;
    }

    $notified_count = 0;

    // Load email service
    if (!class_exists('EIPSI_Wave_Availability_Email_Service')) {
        require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-wave-availability-email-service.php';
    }

    foreach ($newly_available as $assignment) {
        // Load required objects for the email service
        global $wpdb;

        $wave = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}survey_waves WHERE id = %d",
            $assignment->wave_id
        ));

        $participant = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}survey_participants WHERE id = %d",
            $assignment->participant_id
        ));

        if (!$wave || !$participant) {
            EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_notification_cron_adapters_L45'), 'debug');
            continue;
        }

        // Send wave availability notification using the correct method
        $result = EIPSI_Wave_Availability_Email_Service::ensure_wave_availability_email_sent(
            $assignment,
            $wave,
            $participant,
            $assignment->study_id
        );

        if ($result['success'] && $result['sent']) {
            $notified_count++;

            // ========================================
            // DEPRECATED: This cron is now redundant with event-driven system
            // Keeping for backward compatibility during transition
            // ========================================
            EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_notification_cron_adapters_L64'), 'debug');

            // Trigger hook
            do_action('eipsi_wave_became_available', array(
                'assignment_id' => $assignment->id,
                'participant_id' => $assignment->participant_id,
                'wave_id' => $assignment->wave_id,
                'study_id' => $assignment->study_id,
                'wave_index' => $assignment->wave_index,
            ));
        }
    }

    EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_notification_cron_adapters_L80'), 'debug');
}

public static function eipsi_process_nudge_jobs_worker() {
    // Check if Job Queue class exists
    if (!class_exists('EIPSI_Nudge_Job_Queue')) {
        require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/services/class-nudge-job-queue.php';
    }

    // Check for process lock
    $lock_key = 'eipsi_job_worker_lock';
    if (get_transient($lock_key)) {
        EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_notification_cron_adapters_L95'), 'debug');
        return;
    }

    // Set lock for 5 minutes
    set_transient($lock_key, true, 5 * MINUTE_IN_SECONDS);

    EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_notification_cron_adapters_L102'), 'debug');

    // v2.1.5 - Process only 1 job per cron run to ensure reminder_count updates
    // between nudges and prevent race conditions where multiple nudges execute
    // before the database reflects the updated reminder_count
    $stats = EIPSI_Nudge_Job_Queue::process_batch(1); // Only 1 job at a time

    if (!empty($stats['persistence_failed'])) { EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_notification_cron_adapters_L109'), 'debug'); }
    // Log result
    if ($stats['processed'] > 0) {
        EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_notification_cron_adapters_L112'), 'debug');
    } else {
        EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_notification_cron_adapters_L119'), 'debug');
    }

    // Release lock
    delete_transient($lock_key);
}
}
