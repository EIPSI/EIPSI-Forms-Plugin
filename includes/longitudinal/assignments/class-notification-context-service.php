<?php
/** Existing longitudinal compatibility calculations; no notification delivery policy. */
if(!defined('ABSPATH')){exit;}
class EIPSI_Longitudinal_Notification_Context_Service {
public static function is_wave_available($assignment, $wave) {
        global $wpdb;
        
        // T1 (wave_index = 1) is always available immediately
        if ($wave->wave_index == 1) {
            return true;
        }

        // Get T1 submission time for this participant
        $t1_submitted_at = $wpdb->get_var($wpdb->prepare(
            "SELECT submitted_at FROM {$wpdb->prefix}survey_assignments 
             WHERE participant_id = %d AND wave_id = (
                 SELECT id FROM {$wpdb->prefix}survey_waves 
                 WHERE study_id = %d AND wave_index = 1 LIMIT 1
             ) AND status = 'submitted'
             ORDER BY submitted_at DESC LIMIT 1",
            $assignment->participant_id,
            $wave->study_id
        ));

        if (!$t1_submitted_at) {
            return false; // T1 not completed yet
        }

        // Calculate availability (T1 + offset_minutes)
        $offset_minutes = intval($wave->offset_minutes ?? 0);
        $available_at = strtotime($t1_submitted_at) + ($offset_minutes * 60);
        $now = current_time('timestamp');

        return $now >= $available_at;
    }

public static function resolve_legacy_availability($assignment) {
    global $wpdb;
            // FIX 1: Priorizar available_at persistido, calcular solo como fallback
            if (!empty($assignment->available_at)) {
                $available_at = strtotime($assignment->available_at);
                error_log("[EIPSI Cron] NUDGE 0 USING PERSISTED: assignment_id={$assignment->id}, available_at={$assignment->available_at}");
            } else {
                // Calcular en runtime solo si no está persistido
                if (empty($assignment->last_submission_date)) {
                    error_log("[EIPSI Cron] NUDGE 0 BLOCKED: assignment_id={$assignment->id}, reason=NO_LAST_SUBMISSION_DATE_AND_NO_AVAILABLE_AT");
                    return false;
                }
                // time_unit: 0 = minutes, 1 = days (from database)
                $time_unit_str = (intval($assignment->time_unit) === 0) ? 'minutes' : 'days';
                $available_at = strtotime("+{$assignment->interval_days} {$time_unit_str}", strtotime($assignment->last_submission_date));
                error_log("[EIPSI Cron] NUDGE 0 CALC RUNTIME: assignment_id={$assignment->id}, available_at_timestamp={$available_at}, available_at_formatted=" . ($available_at ? date('Y-m-d H:i:s', $available_at) : 'INVALID'));
                
                // Persistir para la próxima vez
                if ($available_at) {
                    $available_at_formatted = date('Y-m-d H:i:s', $available_at);
                    $wpdb->update(
                        $wpdb->prefix . 'survey_assignments',
                        array('available_at' => $available_at_formatted),
                        array('id' => $assignment->id),
                        array('%s'),
                        array('%d')
                    );
                    error_log("[EIPSI Cron] NUDGE 0 PERSISTED: assignment_id={$assignment->id}, available_at={$available_at_formatted}");
                }
            }
            

    return $available_at;
}

public static function notify_submission($participant_id,$study_id,$wave_id) {
        global $wpdb;

        // Get the completed wave info
        $completed_wave = $wpdb->get_row($wpdb->prepare(
            "SELECT wave_index FROM {$wpdb->prefix}survey_waves WHERE id = %d",
            $completed_wave_id
        ));

        if (!$completed_wave) {
            return;
        }

        // Find next wave
        $next_wave = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}survey_waves
             WHERE study_id = %d AND wave_index = %d
             ORDER BY wave_index ASC LIMIT 1",
            $study_id,
            $completed_wave->wave_index + 1
        ));

        if (!$next_wave) {
            error_log('[Wave_Service] No next wave found after wave ' . $completed_wave->wave_index);
            return;
        }

        // T1-Anchor System: Use offset_minutes (absolute time from T1)
        $offset_minutes = (int) ($next_wave->offset_minutes ?? 0);

        // Get T1 submission time (first wave completion)
        $t1_assignment = $wpdb->get_row($wpdb->prepare(
            "SELECT submitted_at FROM {$wpdb->prefix}survey_assignments 
             WHERE participant_id = %d AND study_id = %d AND wave_id = (
                 SELECT id FROM {$wpdb->prefix}survey_waves 
                 WHERE study_id = %d AND wave_index = 1 LIMIT 1
             ) AND status = 'submitted'
             ORDER BY submitted_at DESC LIMIT 1",
            $participant_id,
            $study_id,
            $study_id
        ));

        if (!$t1_assignment || !$t1_assignment->submitted_at) {
            error_log("[Wave_Service] Could not find T1 submission time for participant {$participant_id}");
            return;
        }

        // Calculate when the next wave becomes available (offset from T1)
        $t1_submitted_at = strtotime($t1_assignment->submitted_at);
        $available_at = $t1_submitted_at + ($offset_minutes * 60);
        $now = current_time('timestamp');

        // Log the calculation for debugging
        error_log(sprintf(
            '[Wave_Service] Next wave availability check (T1-Anchor): t1_submitted_at=%s, offset_minutes=%d, available_at=%s, now=%s',
            date('Y-m-d H:i:s', $t1_submitted_at),
            $offset_minutes,
            date('Y-m-d H:i:s', $available_at),
            date('Y-m-d H:i:s', $now)
        ));

        // Get or create assignment for the next wave
        $next_assignment = $wpdb->get_row($wpdb->prepare(
            "SELECT id, available_at FROM {$wpdb->prefix}survey_assignments 
             WHERE participant_id = %d AND study_id = %d AND wave_id = %d",
            $participant_id,
            $study_id,
            $next_wave->id
        ));

        // Persist available_at if not already set
        $available_at_formatted = date('Y-m-d H:i:s', $available_at);
        if (!$next_assignment) {
            // Create assignment with available_at
            $wpdb->insert(
                $wpdb->prefix . 'survey_assignments',
                array(
                    'study_id' => $study_id,
                    'wave_id' => $next_wave->id,
                    'participant_id' => $participant_id,
                    'status' => 'pending',
                    'available_at' => $available_at_formatted,
                ),
                array('%d', '%d', '%d', '%s', '%s')
            );
            error_log("[Wave_Service] Created assignment for next wave {$next_wave->id} with available_at: {$available_at_formatted}");
        } elseif (empty($next_assignment->available_at)) {
            // Update existing assignment with available_at
            $wpdb->update(
                $wpdb->prefix . 'survey_assignments',
                array('available_at' => $available_at_formatted),
                array('id' => $next_assignment->id),
                array('%s'),
                array('%d')
            );
            error_log("[Wave_Service] Persisted available_at for assignment {$next_assignment->id}: {$available_at_formatted}");
            
            // v2.5.0 - Trigger event-driven scheduling for follow-up nudges
            do_action('eipsi_wave_available', $next_assignment->id);
        } else {
            error_log("[Wave_Service] Using existing available_at for assignment {$next_assignment->id}: {$next_assignment->available_at}");
        }


    return EIPSI_Notification_Longitudinal_Adapter::notify_available_context(compact('next_assignment','now','available_at'));
}
}
