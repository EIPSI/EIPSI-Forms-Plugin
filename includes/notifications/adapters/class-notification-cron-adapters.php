<?php
/** Notifications owner; public WordPress adapters retain existing contracts. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Notification_Cron_Adapters {

public static function eipsi_run_process_wave_availability() {
    global $wpdb;

    error_log('[EIPSI Cron] Wave availability processor started at ' . current_time('mysql'));

    $now = current_time('mysql');
    $assignments_table = $wpdb->prefix . 'survey_assignments';

    // Find assignments that just became available (available_at <= NOW, status = pending, no email sent yet)
    $newly_available=EIPSI_Longitudinal_Assignment_Repository::get_newly_available($now);

    if (empty($newly_available)) {
        error_log('[EIPSI Cron] No waves newly available.');
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
            error_log("[EIPSI Cron] Wave availability: Missing wave or participant data for assignment {$assignment->id}");
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
            error_log(sprintf(
                '[EIPSI WaveAvail] DEPRECATED: Wave detected by cron for assignment %d. Event-driven system should handle this.',
                $assignment->id
            ));

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

    error_log(sprintf(
        '[EIPSI Cron] Wave availability processor completed. Notified: %d participants.',
        $notified_count
    ));
}

public static function eipsi_process_nudge_jobs_worker() {
    // Check if Job Queue class exists
    if (!class_exists('EIPSI_Nudge_Job_Queue')) {
        require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/services/class-nudge-job-queue.php';
    }

    // Check for process lock
    $lock_key = 'eipsi_job_worker_lock';
    if (get_transient($lock_key)) {
        error_log('[EIPSI JobWorker] Another worker is running, skipping');
        return;
    }

    // Set lock for 5 minutes
    set_transient($lock_key, true, 5 * MINUTE_IN_SECONDS);

    error_log('[EIPSI JobWorker] Starting job processing');

    // v2.1.5 - Process only 1 job per cron run to ensure reminder_count updates
    // between nudges and prevent race conditions where multiple nudges execute
    // before the database reflects the updated reminder_count
    $stats = EIPSI_Nudge_Job_Queue::process_batch(1); // Only 1 job at a time

    if (!empty($stats['persistence_failed'])) { error_log('[EIPSI JobWorker] persistence_failed=' . intval($stats['persistence_failed'])); }
    // Log result
    if ($stats['processed'] > 0) {
        error_log(sprintf(
            '[EIPSI JobWorker] Processed: %d completed, %d retried, %d failed (single job mode)',
            $stats['completed'],
            $stats['retried'],
            $stats['failed']
        ));
    } else {
        error_log('[EIPSI JobWorker] No pending jobs to process');
    }

    // Release lock
    delete_transient($lock_key);
}
}
