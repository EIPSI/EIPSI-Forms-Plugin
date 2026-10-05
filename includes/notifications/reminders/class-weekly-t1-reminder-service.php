<?php
/** Notifications owner; public WordPress adapters retain existing contracts. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Notification_Weekly_T1_Reminder_Service {

public static function eipsi_weekly_t1_reminders() {
    global $wpdb;

    error_log('[EIPSI Weekly T1] Starting cron job');

    // Get all pending T1 assignments with participant and study info
    $pending_t1 = $wpdb->get_results("
        SELECT
            a.id as assignment_id,
            a.participant_id,
            a.study_id,
            a.wave_id,
            a.reminder_count,
            a.created_at as assignment_created,
            p.email,
            p.first_name,
            s.config,
            s.study_name,
            w.nudge_config
        FROM {$wpdb->prefix}survey_assignments a
        JOIN {$wpdb->prefix}survey_participants p ON a.participant_id = p.id
        JOIN {$wpdb->prefix}survey_studies s ON a.study_id = s.id
        JOIN {$wpdb->prefix}survey_waves w ON a.wave_id = w.id
        WHERE w.wave_index = 1
          AND a.status = 'pending'
    ");

    if (empty($pending_t1)) {
        error_log('[EIPSI Weekly T1] No pending T1 assignments found');
        return;
    }

    error_log(sprintf('[EIPSI Weekly T1] Found %d pending T1 assignments', count($pending_t1)));

    $sent_count = 0;
    $expired_count = 0;

    foreach ($pending_t1 as $assignment) {
        // Parse study config
        $study_config = !empty($assignment->config) ? json_decode($assignment->config, true) : array();
        $study_config = is_array($study_config) ? $study_config : array();
        $weekly_config = $study_config['weekly_reminders'] ?? array('enabled' => false);

        // Check if weekly reminders are enabled for this study
        if (empty($weekly_config['enabled'])) {
            continue;
        }

        // Parse nudge config to determine how many nudges were configured
        $nudge_config = json_decode($assignment->nudge_config, true) ?: array();
        $total_nudges = count($nudge_config);
        $start_after = $weekly_config['start_after_nudge'] ?? $total_nudges;

        // Only send weekly reminders after all nudges have been sent
        if ($assignment->reminder_count < $start_after) {
            continue;
        }

        // Check if enough time has passed since last weekly reminder
        $last_sent = $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(sent_at) FROM {$wpdb->prefix}survey_weekly_reminders
             WHERE assignment_id = %d",
            $assignment->assignment_id
        ));

        $frequency_days = $weekly_config['frequency_days'] ?? 7;

        if ($last_sent) {
            $days_since = (time() - strtotime($last_sent)) / 86400;
            if ($days_since < $frequency_days) {
                continue; // Not enough time has passed
            }
        }

        // Check if max reminders limit has been reached
        $reminder_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}survey_weekly_reminders
             WHERE assignment_id = %d",
            $assignment->assignment_id
        ));

        $max_reminders = $weekly_config['max_reminders'] ?? null;

        if ($max_reminders && $reminder_count >= $max_reminders) {
            // Mark as expired
            eipsi_expire_t1_assignment($assignment);
            $expired_count++;
            continue;
        }

        // Check auto-expiration by days since assignment creation
        $auto_expire_after = $weekly_config['auto_expire_after'] ?? null;

        if ($auto_expire_after) {
            $days_since_created = (time() - strtotime($assignment->assignment_created)) / 86400;
            if ($days_since_created >= $auto_expire_after) {
                // Mark as expired
                eipsi_expire_t1_assignment($assignment);
                $expired_count++;
                continue;
            }
        }

        // Send weekly reminder
        $sent = eipsi_send_weekly_t1_reminder($assignment, $reminder_count + 1);

        if ($sent) {
            $sent_count++;
        }
    }

    error_log(sprintf('[EIPSI Weekly T1] Cron completed: %d reminders sent, %d assignments expired',
        $sent_count, $expired_count));
}

public static function eipsi_send_weekly_t1_reminder($assignment, $reminder_number) {
    global $wpdb;

    // Generate magic link
    if (!class_exists('EIPSI_MagicLinksService')) {
        require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-magic-links-service.php';
    }

    $token = EIPSI_MagicLinksService::generate_magic_link(
        $assignment->study_id,
        $assignment->participant_id
    );

    if (!$token) {
        error_log("[EIPSI Weekly T1] Failed to generate magic link for participant {$assignment->participant_id}");
        return false;
    }

    $magic_link = home_url("/survey-access/?token={$token}");

    // Load email template
    require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/emails/weekly-t1-reminder.php';

    $email = eipsi_email_weekly_t1_reminder(array(
        'participant_name' => $assignment->first_name,
        'study_name' => $assignment->study_name,
        'magic_link' => $magic_link,
        'reminder_number' => $reminder_number
    ));

    // Send email
    if (!class_exists('EIPSI_Email_Service')) {
        require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-email-service.php';
    }

    $result = EIPSI_Email_Service::send_email(
        $assignment->study_id,
        $assignment->participant_id,
        $assignment->email,
        'weekly_t1_reminder',
        $email['subject'],
        $email['body'],
        array(
            'email_type' => 'weekly_t1_reminder',
            'assignment_id' => $assignment->assignment_id,
            'participant_id' => $assignment->participant_id,
            'study_id' => $assignment->study_id,
            'wave_id' => $assignment->wave_id
        )
    );

    // Log to weekly_reminders table
    if ($result) {
        $wpdb->insert(
            $wpdb->prefix . 'survey_weekly_reminders',
            array(
                'assignment_id' => $assignment->assignment_id,
                'reminder_number' => $reminder_number,
                'sent_at' => current_time('mysql')
            ),
            array('%d', '%d', '%s')
        );

        error_log(sprintf(
            '[EIPSI Weekly T1] Sent reminder #%d to participant %d (assignment %d)',
            $reminder_number, $assignment->participant_id, $assignment->assignment_id
        ));
    } else {
        error_log(sprintf(
            '[EIPSI Weekly T1] Failed to send reminder to participant %d',
            $assignment->participant_id
        ));
    }

    return $result;
}
}
