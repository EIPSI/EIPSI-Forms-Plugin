<?php
/** Notifications owner; public WordPress adapters retain existing contracts. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Notification_Dropout_Recovery_Service {

public static function eipsi_send_dropout_recovery_hourly() {
    error_log('[EIPSI Cron] Starting hourly dropout recovery');

    global $wpdb;

    // Get all active studies with dropout recovery enabled
    $studies = $wpdb->get_results(
        "SELECT id, study_name, config FROM {$wpdb->prefix}survey_studies WHERE status = 'active'"
    );

    if (empty($studies)) {
        error_log('[EIPSI Cron] No active studies found');
        return;
    }

    foreach ($studies as $study) {
        // Guard against null config (json_decode(null) is deprecated in PHP 8.1+)
        $config = !empty($study->config) ? json_decode($study->config, true) : array();
        if (!is_array($config) || empty($config['dropout_recovery_enabled'])) {
            continue;
        }

        $dropout_days = isset($config['dropout_recovery_days']) ? intval($config['dropout_recovery_days']) : 7;
        $max_emails = isset($config['max_recovery_emails']) ? intval($config['max_recovery_emails']) : 50;
        $today = current_time('Y-m-d');
        $emails_sent = 0;

        $overdue_assignments = $wpdb->get_results($wpdb->prepare(
            "SELECT a.*, w.name as wave_name, w.wave_index, w.due_date, p.email, p.first_name, p.last_name, p.id as participant_id
             FROM {$wpdb->prefix}survey_assignments a
             JOIN {$wpdb->prefix}survey_waves w ON a.wave_id = w.id
             JOIN {$wpdb->prefix}survey_participants p ON a.participant_id = p.id
             WHERE a.study_id = %d
             AND a.status = 'pending'
             AND p.is_active = 1
             AND p.email IS NOT NULL
             AND a.reminder_count < 3
             AND DATE_ADD(DATE(COALESCE(
                 (SELECT MAX(a2.submitted_at) FROM {$wpdb->prefix}survey_assignments a2
                  WHERE a2.participant_id = p.id AND a2.study_id = a.study_id AND a2.status = 'submitted'),
                 p.created_at
             )), INTERVAL (w.interval_days + %d) DAY) <= %s
             ORDER BY a.id DESC
             LIMIT %d",
            $study->id,
            $dropout_days,
            $today,
            $max_emails
        ));

        if (empty($overdue_assignments)) {
            continue;
        }

        // Load email service
        if (!class_exists('EIPSI_Email_Service')) {
            require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-email-service.php';
        }

        foreach ($overdue_assignments as $assignment) {
            // Check rate limiting - max 1 recovery email per participant per week
            $rate_limit_key = "eipsi_recovery_{$assignment->participant_id}_{$assignment->wave_id}";
            if (get_transient($rate_limit_key)) {
                continue;
            }

            // Send recovery email
            $wave = array(
                'id' => $assignment->wave_id,
                'name' => $assignment->wave_name,
                'wave_index' => $assignment->wave_index,
                'due_date' => $assignment->due_date
            );

            $result = EIPSI_Email_Service::send_dropout_recovery_email(
                $study->id,
                $assignment->participant_id,
                (object) $wave
            );

            if ($result) {
                $emails_sent++;
                // Increment reminder count
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$wpdb->prefix}survey_assignments SET reminder_count = reminder_count + 1 WHERE id = %d",
                    $assignment->id
                ));
                // Set rate limit - 7 days
                set_transient($rate_limit_key, true, 7 * DAY_IN_SECONDS);
            }
        }

        error_log("[EIPSI Cron] Study {$study->study_name}: {$emails_sent} recovery emails sent");
    }

    error_log('[EIPSI Cron] Completed hourly dropout recovery');
}
}
