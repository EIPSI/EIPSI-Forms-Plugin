<?php
/** M4 longitudinal owner. Existing public adapters preserve their contracts. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Longitudinal_Assignment_Lifecycle_Service {
public static function eipsi_run_process_assignment_expirations() {
    global $wpdb;

    error_log('[EIPSI Cron] Assignment expiration processor started at ' . current_time('mysql'));

    $now = current_time('mysql');
    $assignments_table = $wpdb->prefix . 'survey_assignments';
    $audit_table = $wpdb->prefix . 'survey_audit_log';

    // Find assignments that are past due and not yet expired or submitted
    $expired_assignments = $wpdb->get_results($wpdb->prepare(
        "SELECT a.id, a.participant_id, a.wave_id, a.study_id, a.due_at, a.status,
                w.wave_index, w.name as wave_name
         FROM {$assignments_table} a
         JOIN {$wpdb->prefix}survey_waves w ON a.wave_id = w.id
         WHERE a.due_at IS NOT NULL
         AND a.due_at < %s
         AND a.status NOT IN ('submitted', 'expired', 'skipped')
         LIMIT 500",
        $now
    ));

    if (empty($expired_assignments)) {
        error_log('[EIPSI Cron] No assignments to expire.');
        return;
    }

    $expired_count = 0;
    $audit_entries = array();

    foreach ($expired_assignments as $assignment) {
        // Update status to expired
        $updated = EIPSI_Longitudinal_Assignment_Transition_Service::expire_snapshot($assignment);

        if ($updated !== false && $updated > 0) {
            $expired_count++;

            // Prepare audit entry
            $audit_entries[] = array(
                'survey_id' => $assignment->study_id,
                'participant_id' => $assignment->participant_id,
                'action' => 'wave_expired',
                'actor_type' => 'system',
                'metadata' => wp_json_encode(array(
                    'wave_id' => $assignment->wave_id,
                    'wave_index' => $assignment->wave_index,
                    'wave_name' => $assignment->wave_name,
                    'due_at' => $assignment->due_at,
                    'expired_at' => $now,
                    'previous_status' => $assignment->status,
                )),
                'created_at' => $now,
            );

            // Trigger hook for extensibility (notifications, etc.)
            do_action('eipsi_assignment_expired', array(
                'assignment_id' => $assignment->id,
                'participant_id' => $assignment->participant_id,
                'wave_id' => $assignment->wave_id,
                'study_id' => $assignment->study_id,
                'wave_index' => $assignment->wave_index,
            ));
        }
    }

    // Batch insert audit entries
    if (!empty($audit_entries) && $wpdb->get_var("SHOW TABLES LIKE '{$audit_table}'")) {
        foreach ($audit_entries as $entry) {
            $wpdb->insert(
                $audit_table,
                $entry,
                array('%d', '%d', '%s', '%s', '%s', '%s')
            );
        }
    }

    error_log(sprintf(
        '[EIPSI Cron] Assignment expiration processor completed. Expired: %d assignments.',
        $expired_count
    ));

    // Auto-skip expired waves when a later wave is available
    eipsi_auto_skip_expired_waves();
}

public static function eipsi_auto_skip_expired_waves() {
    global $wpdb;

    $assignments_table = $wpdb->prefix . 'survey_assignments';
    $waves_table = $wpdb->prefix . 'survey_waves';
    $now = current_time('mysql');

    // Find participants with expired waves that have a later available wave
    $expired_to_skip = $wpdb->get_results("
        SELECT a1.id as expired_assignment_id,
               a1.participant_id,
               a1.wave_id as expired_wave_id,
               a1.study_id,
               w1.wave_index as expired_wave_index,
               w1.name as expired_wave_name,
               MIN(w2.wave_index) as next_available_wave_index
        FROM {$assignments_table} a1
        JOIN {$waves_table} w1 ON a1.wave_id = w1.id
        JOIN {$waves_table} w2 ON w2.study_id = w1.study_id AND w2.wave_index > w1.wave_index
        LEFT JOIN {$assignments_table} a2 ON a2.participant_id = a1.participant_id
                                          AND a2.wave_id = w2.id
        WHERE a1.status = 'expired'
        AND (a2.status IN ('pending', 'in_progress')
             OR (a2.available_at IS NOT NULL AND a2.available_at <= '{$now}'))
        GROUP BY a1.id, a1.participant_id, a1.wave_id, a1.study_id, w1.wave_index, w1.name
        LIMIT 100
    ");

    if (empty($expired_to_skip)) {
        return;
    }

    $skipped_count = 0;

    foreach ($expired_to_skip as $item) {
        // Update expired assignment to skipped
        $updated = EIPSI_Longitudinal_Assignment_Transition_Service::change_snapshot($item->expired_assignment_id, 'expired', 'skipped');

        if ($updated !== false && $updated > 0) {
            $skipped_count++;

            // Log to audit
            $audit_table = $wpdb->prefix . 'survey_audit_log';
            if ($wpdb->get_var("SHOW TABLES LIKE '{$audit_table}'")) {
                $wpdb->insert(
                    $audit_table,
                    array(
                        'survey_id' => $item->study_id,
                        'participant_id' => $item->participant_id,
                        'action' => 'wave_auto_skipped',
                        'actor_type' => 'system',
                        'metadata' => wp_json_encode(array(
                            'wave_id' => $item->expired_wave_id,
                            'wave_index' => $item->expired_wave_index,
                            'wave_name' => $item->expired_wave_name,
                            'reason' => 'expired_with_later_wave_available',
                            'next_wave_index' => $item->next_available_wave_index,
                            'skipped_at' => $now,
                        )),
                        'created_at' => $now,
                    ),
                    array('%d', '%d', '%s', '%s', '%s', '%s')
                );
            }

            error_log(sprintf(
                '[EIPSI Auto-Skip] Skipped expired wave T%d for participant %d (next available: T%d)',
                $item->expired_wave_index,
                $item->participant_id,
                $item->next_available_wave_index
            ));

            // ========================================
            // FIX: Trigger event-driven system for next available wave
            // ========================================
            $next_assignment = $wpdb->get_row($wpdb->prepare(
                "SELECT a.id, a.available_at, a.status, a.reminder_count
                 FROM {$assignments_table} a
                 JOIN {$waves_table} w ON a.wave_id = w.id
                 WHERE a.participant_id = %d
                 AND a.study_id = %d
                 AND w.wave_index = %d
                 LIMIT 1",
                $item->participant_id,
                $item->study_id,
                $item->next_available_wave_index
            ));

            EIPSI_Notification_Longitudinal_Adapter::after_auto_skip($next_assignment,$item->next_available_wave_index);
        }
    }

    if ($skipped_count > 0) {
        error_log(sprintf(
            '[EIPSI Auto-Skip] Auto-skipped %d expired waves with later waves available.',
            $skipped_count
        ));
    }
}

public static function eipsi_check_wave_skipping() {
    global $wpdb;

    error_log('[EIPSI Wave Skipping] Starting cron job');

    // Get all participants with at least one pending assignment
    $participants = $wpdb->get_results("
        SELECT DISTINCT participant_id, study_id
        FROM {$wpdb->prefix}survey_assignments
        WHERE status IN ('pending', 'in_progress')
    ");

    if (empty($participants)) {
        error_log('[EIPSI Wave Skipping] No participants with pending assignments');
        return;
    }

    error_log(sprintf('[EIPSI Wave Skipping] Processing %d participants', count($participants)));

    $total_skipped = 0;

    foreach ($participants as $p) {
        $skipped = eipsi_check_wave_skipping_for_participant($p->participant_id, $p->study_id);
        $total_skipped += $skipped;
    }

    error_log(sprintf('[EIPSI Wave Skipping] Cron completed: %d waves skipped across %d participants',
        $total_skipped, count($participants)));
}

public static function eipsi_check_wave_skipping_for_participant($participant_id, $study_id) {
    global $wpdb;

    error_log(sprintf('[EIPSI Wave Skipping] ========== Checking participant %d (study %d) ==========', $participant_id, $study_id));

    // Get all assignments for this participant, ordered by wave_index
    $assignments = $wpdb->get_results($wpdb->prepare(
        "SELECT a.id, a.wave_id, a.status, a.available_at, w.wave_index, w.name as wave_name
         FROM {$wpdb->prefix}survey_assignments a
         JOIN {$wpdb->prefix}survey_waves w ON a.wave_id = w.id
         WHERE a.participant_id = %d AND a.study_id = %d
         ORDER BY w.wave_index ASC",
        $participant_id, $study_id
    ));

    if (empty($assignments)) {
        error_log('[EIPSI Wave Skipping] No assignments found for this participant');
        return 0;
    }

    // Log current state of all waves
    error_log(sprintf('[EIPSI Wave Skipping] Found %d waves for participant %d:', count($assignments), $participant_id));
    foreach ($assignments as $a) {
        $available_str = $a->available_at ? date('Y-m-d H:i:s', strtotime($a->available_at)) : 'NULL';
        error_log(sprintf('  - Wave %d (%s): status=%s, available_at=%s, assignment_id=%d',
            $a->wave_index, $a->wave_name, $a->status, $available_str, $a->id));
    }

    // Find the highest wave_index that is currently available
    $last_available_index = 0;
    $now = current_time('timestamp');
    $now_str = date('Y-m-d H:i:s', $now);

    error_log(sprintf('[EIPSI Wave Skipping] Current time: %s', $now_str));

    foreach ($assignments as $a) {
        if ($a->available_at && strtotime($a->available_at) <= $now) {
            $last_available_index = max($last_available_index, $a->wave_index);
            error_log(sprintf('[EIPSI Wave Skipping] Wave %d is AVAILABLE (available_at=%s <= now=%s)',
                $a->wave_index, date('Y-m-d H:i:s', strtotime($a->available_at)), $now_str));
        } else {
            $reason = !$a->available_at ? 'no available_at set' : 'not yet available';
            error_log(sprintf('[EIPSI Wave Skipping] Wave %d is NOT AVAILABLE (%s)', $a->wave_index, $reason));
        }
    }

    error_log(sprintf('[EIPSI Wave Skipping] Last available wave index: %d', $last_available_index));

    if ($last_available_index == 0) {
        error_log('[EIPSI Wave Skipping] No waves are available yet - nothing to skip');
        return 0;
    }

    // Skip all waves that are:
    // 1. NOT T1 (wave_index > 1) - T1 NEVER gets skipped
    // 2. Skippable status (pending or in_progress)
    // 3. Have wave_index < last_available_index
    $skippable_statuses = array('pending', 'in_progress');
    $skipped_count = 0;

    error_log('[EIPSI Wave Skipping] Evaluating which waves to skip...');

    foreach ($assignments as $a) {
        // CRITICAL: T1 (wave_index = 1) NEVER gets skipped
        if ($a->wave_index == 1) {
            error_log(sprintf('[EIPSI Wave Skipping] Wave %d (T1): PROTECTED - T1 never gets skipped (status=%s)',
                $a->wave_index, $a->status));
            continue;
        }

        $should_skip = in_array($a->status, $skippable_statuses) && $a->wave_index < $last_available_index;

        if ($should_skip) {
            error_log(sprintf('[EIPSI Wave Skipping] Wave %d (%s): WILL SKIP - status=%s (skippable), wave_index=%d < last_available=%d',
                $a->wave_index, $a->wave_name, $a->status, $a->wave_index, $last_available_index));
        } else {
            $reason = !in_array($a->status, $skippable_statuses)
                ? "status={$a->status} (not skippable)"
                : "wave_index={$a->wave_index} >= last_available={$last_available_index}";
            error_log(sprintf('[EIPSI Wave Skipping] Wave %d (%s): NO SKIP - %s',
                $a->wave_index, $a->wave_name, $reason));
        }

        if ($should_skip) {

            // Skip this wave with transaction and lock
            $a->participant_id=$participant_id;
            if (EIPSI_Longitudinal_Assignment_Transition_Service::skip_locked($a)) { $skipped_count++; }

        }
    }

    error_log(sprintf('[EIPSI Wave Skipping] ========== Summary: %d waves skipped for participant %d ==========',
        $skipped_count, $participant_id));

    return $skipped_count;
}
}
