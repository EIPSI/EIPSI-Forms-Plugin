<?php
/** M4 longitudinal owner. Existing public adapters preserve their contracts. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Longitudinal_T1_Anchor_Service {
public static function on_form_submitted($data) {
        $survey_id = absint($data['survey_id'] ?? 0);
        $participant_id = $data['participant_id'] ?? '';
        $wave_index = absint($data['wave_index'] ?? 0);

        if (!$survey_id || !$participant_id) {
            return;
        }

        // Check if this is T1 (wave_index = 1)
        if ($wave_index !== 1) {
            return;
        }

        // Resolve numeric participant_id
        $participant_id = self::resolve_participant_id($participant_id, $survey_id);
        if (!$participant_id) {
            return;
        }

        // Anchor the timeline
        self::anchor_participant_timeline($survey_id, $participant_id);
    }

public static function on_assignment_status_changed($wave_id, $participant_id, $new_status) {
        if ($new_status !== 'submitted') {
            return;
        }

        global $wpdb;

        // Get wave info to check if it's T1
        $wave = $wpdb->get_row($wpdb->prepare(
            "SELECT study_id, wave_index FROM {$wpdb->prefix}survey_waves WHERE id = %d",
            $wave_id
        ));

        if (!$wave || $wave->wave_index != 1) {
            return;
        }

        // Anchor the timeline
        self::anchor_participant_timeline($wave->study_id, $participant_id);
    }

public static function anchor_participant_timeline($study_id, $participant_id) {
        global $wpdb;

        $study_id = absint($study_id);
        $participant_id = absint($participant_id);

        if (!$study_id || !$participant_id) {
            return new WP_Error('invalid_params', 'study_id and participant_id are required');
        }

        // Check if already anchored (t1_completed_at is set)
        $existing_anchor = $wpdb->get_var($wpdb->prepare(
            "SELECT t1_completed_at FROM {$wpdb->prefix}survey_participants
             WHERE id = %d AND survey_id = %d",
            $participant_id,
            $study_id
        ));

        if (!empty($existing_anchor)) {
            // Already anchored - do not recalculate
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf(
                    '[EIPSI-T1-ANCHOR] Participant %d already anchored at %s - skipping',
                    $participant_id,
                    $existing_anchor
                ));
            }
            return array(
                'success' => true,
                'already_anchored' => true,
                't1_completed_at' => $existing_anchor,
            );
        }

        // Get T1 submission timestamp
        $t1_assignment = $wpdb->get_row($wpdb->prepare(
            "SELECT a.submitted_at, w.id as wave_id
             FROM {$wpdb->prefix}survey_assignments a
             JOIN {$wpdb->prefix}survey_waves w ON a.wave_id = w.id
             WHERE a.participant_id = %d
             AND w.study_id = %d
             AND w.wave_index = 1
             AND a.status = 'submitted'",
            $participant_id,
            $study_id
        ));

        if (!$t1_assignment || empty($t1_assignment->submitted_at)) {
            return new WP_Error('t1_not_completed', 'T1 has not been submitted yet');
        }

        $t1_timestamp = $t1_assignment->submitted_at;
        $t1_unix = strtotime($t1_timestamp);

        // Store t1_completed_at on participant
        $wpdb->update(
            $wpdb->prefix . 'survey_participants',
            array('t1_completed_at' => $t1_timestamp),
            array('id' => $participant_id, 'survey_id' => $study_id),
            array('%s'),
            array('%d', '%d')
        );

        // Get all waves for this study (ordered by wave_index)
        $waves = $wpdb->get_results($wpdb->prepare(
            "SELECT id, wave_index, offset_minutes, window_minutes, name
             FROM {$wpdb->prefix}survey_waves
             WHERE study_id = %d
             ORDER BY wave_index ASC",
            $study_id
        ));

        if (empty($waves)) {
            return new WP_Error('no_waves', 'No waves found for this study');
        }

        // Get study end offset
        $study_end_offset = $wpdb->get_var($wpdb->prepare(
            "SELECT study_end_offset_minutes FROM {$wpdb->prefix}survey_studies WHERE id = %d",
            $study_id
        ));

        $study_end_timestamp = null;
        if (!empty($study_end_offset)) {
            $study_end_timestamp = date('Y-m-d H:i:s', $t1_unix + ($study_end_offset * 60));
        }

        // Calculate and persist dates for each wave
        $anchored_waves = array();
        $wave_count = count($waves);

        foreach ($waves as $index => $wave) {
            $wave_id = absint($wave->id);
            $offset_minutes = absint($wave->offset_minutes ?? 0);
            $window_minutes = $wave->window_minutes;

            // Calculate available_at: T1 + offset_minutes
            $available_at = date('Y-m-d H:i:s', $t1_unix + ($offset_minutes * 60));

            // Calculate due_at:
            // - If window_minutes is set, use it
            // - Otherwise, use next wave's offset (or study_end)
            $due_at = null;

            if (!empty($window_minutes)) {
                // Explicit window
                $due_at = date('Y-m-d H:i:s', $t1_unix + ($offset_minutes * 60) + ($window_minutes * 60));
            } else {
                // Use next wave's offset as deadline
                if (isset($waves[$index + 1])) {
                    $next_offset = absint($waves[$index + 1]->offset_minutes ?? 0);
                    $due_at = date('Y-m-d H:i:s', $t1_unix + ($next_offset * 60));
                } else {
                    // Last wave - use study_end or add 7 days default
                    $due_at = $study_end_timestamp ?? date('Y-m-d H:i:s', $t1_unix + ($offset_minutes * 60) + (7 * 24 * 60 * 60));
                }
            }

            // Cap due_at to study_end if set
            if (!empty($study_end_timestamp) && strtotime($due_at) > strtotime($study_end_timestamp)) {
                $due_at = $study_end_timestamp;
            }

            // Update assignment with calculated dates
            $updated = $wpdb->update(
                $wpdb->prefix . 'survey_assignments',
                array(
                    'available_at' => $available_at,
                    'due_at' => $due_at,
                ),
                array(
                    'wave_id' => $wave_id,
                    'participant_id' => $participant_id,
                ),
                array('%s', '%s'),
                array('%d', '%d')
            );

            // T1 is immediately available and already submitted
            if ($wave->wave_index == 1) {
                $available_at = $t1_timestamp;
                $due_at = $t1_timestamp; // Already completed
            }

            $anchored_waves[] = array(
                'wave_id' => $wave_id,
                'wave_index' => $wave->wave_index,
                'wave_name' => $wave->name,
                'available_at' => $available_at,
                'due_at' => $due_at,
                'updated' => $updated !== false,
            );
        }

        // Log the anchoring
        $audit_table = $wpdb->prefix . 'survey_audit_log';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$audit_table}'")) {
            $wpdb->insert(
                $audit_table,
                array(
                    'survey_id' => $study_id,
                    'participant_id' => $participant_id,
                    'action' => 't1_anchor_calculated',
                    'actor_type' => 'system',
                    'metadata' => wp_json_encode(array(
                        't1_completed_at' => $t1_timestamp,
                        'study_end_at' => $study_end_timestamp,
                        'waves_anchored' => count($anchored_waves),
                    )),
                    'created_at' => current_time('mysql'),
                ),
                array('%d', '%d', '%s', '%s', '%s', '%s')
            );
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[EIPSI-T1-ANCHOR] Anchored timeline for participant %d in study %d: %d waves, T1=%s',
                $participant_id,
                $study_id,
                count($anchored_waves),
                $t1_timestamp
            ));
        }

        // Phase 5 T1-Anchor: Trigger hook to reschedule nudges with new deadlines
        do_action('eipsi_t1_anchored', $study_id, $participant_id);

        return array(
            'success' => true,
            'participant_id' => $participant_id,
            'study_id' => $study_id,
            't1_completed_at' => $t1_timestamp,
            'study_end_at' => $study_end_timestamp,
            'waves' => $anchored_waves,
        );
    }

public static function get_participant_anchored_timeline($study_id, $participant_id) {
        global $wpdb;

        $study_id = absint($study_id);
        $participant_id = absint($participant_id);

        // Get participant's T1 anchor
        $participant = $wpdb->get_row($wpdb->prepare(
            "SELECT t1_completed_at, email, first_name, last_name
             FROM {$wpdb->prefix}survey_participants
             WHERE id = %d AND survey_id = %d",
            $participant_id,
            $study_id
        ));

        if (!$participant) {
            return array(
                'success' => false,
                'error' => 'Participant not found',
            );
        }

        // Get all assignments with their calculated dates
        $assignments = $wpdb->get_results($wpdb->prepare(
            "SELECT a.*, w.wave_index, w.name as wave_name, w.offset_minutes, w.window_minutes
             FROM {$wpdb->prefix}survey_assignments a
             JOIN {$wpdb->prefix}survey_waves w ON a.wave_id = w.id
             WHERE a.participant_id = %d AND w.study_id = %d
             ORDER BY w.wave_index ASC",
            $participant_id,
            $study_id
        ));

        $now = current_time('timestamp');
        $timeline = array();

        foreach ($assignments as $a) {
            $available_ts = $a->available_at ? strtotime($a->available_at) : null;
            $due_ts = $a->due_at ? strtotime($a->due_at) : null;

            // Calculate visual status
            $visual_status = $a->status;
            if ($a->status !== 'submitted' && $a->status !== 'expired') {
                if ($due_ts && $now > $due_ts) {
                    $visual_status = 'expired';
                } elseif ($available_ts && $now >= $available_ts) {
                    $visual_status = $a->status === 'in_progress' ? 'in_progress' : 'available';
                } else {
                    $visual_status = 'pending';
                }
            }

            $timeline[] = array(
                'wave_id' => $a->wave_id,
                'wave_index' => $a->wave_index,
                'wave_name' => $a->wave_name,
                'status' => $a->status,
                'visual_status' => $visual_status,
                'available_at' => $a->available_at,
                'due_at' => $a->due_at,
                'submitted_at' => $a->submitted_at,
                'time_remaining' => ($due_ts && $visual_status === 'available') ? max(0, $due_ts - $now) : null,
                'time_until_open' => ($available_ts && $visual_status === 'pending') ? max(0, $available_ts - $now) : null,
                'is_current' => ($visual_status === 'available' || $visual_status === 'in_progress'),
            );
        }

        // Find active wave
        $active_wave = null;
        foreach ($timeline as $wave) {
            if ($wave['is_current']) {
                $active_wave = $wave;
                break;
            }
        }

        // If no active wave, find next pending
        if (!$active_wave) {
            foreach ($timeline as $wave) {
                if ($wave['visual_status'] === 'pending') {
                    $active_wave = $wave;
                    break;
                }
            }
        }

        return array(
            'success' => true,
            'participant' => array(
                'id' => $participant_id,
                'email' => $participant->email,
                'name' => trim($participant->first_name . ' ' . $participant->last_name),
                't1_completed_at' => $participant->t1_completed_at,
                'is_anchored' => !empty($participant->t1_completed_at),
            ),
            'timeline' => $timeline,
            'active_wave' => $active_wave,
            'study_completed' => self::is_study_completed($timeline),
        );
    }

private static function is_study_completed($timeline) {
        if (empty($timeline)) {
            return false;
        }

        foreach ($timeline as $wave) {
            if ($wave['status'] !== 'submitted') {
                return false;
            }
        }

        return true;
    }

private static function resolve_participant_id($participant_id, $survey_id) {
        global $wpdb;

        // If already numeric, validate it exists
        if (is_numeric($participant_id)) {
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}survey_participants
                 WHERE id = %d AND survey_id = %d",
                $participant_id,
                $survey_id
            ));

            return $exists ? absint($participant_id) : null;
        }

        // If it's an email, look up the participant
        if (is_email($participant_id)) {
            return $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}survey_participants
                 WHERE email = %s AND survey_id = %d",
                $participant_id,
                $survey_id
            ));
        }

        return null;
    }

public static function manual_anchor($study_id, $participant_id, $t1_timestamp = null, $force = false) {
        global $wpdb;

        $study_id = absint($study_id);
        $participant_id = absint($participant_id);

        if (!$study_id || !$participant_id) {
            return new WP_Error('invalid_params', 'study_id and participant_id are required');
        }

        // Check current anchor state
        $current_anchor = $wpdb->get_var($wpdb->prepare(
            "SELECT t1_completed_at FROM {$wpdb->prefix}survey_participants
             WHERE id = %d AND survey_id = %d",
            $participant_id,
            $study_id
        ));

        if (!empty($current_anchor) && !$force) {
            return new WP_Error('already_anchored', 'Participant is already anchored. Use force=true to re-anchor.');
        }

        // If forcing, clear the existing anchor first
        if ($force && !empty($current_anchor)) {
            $wpdb->update(
                $wpdb->prefix . 'survey_participants',
                array('t1_completed_at' => null),
                array('id' => $participant_id, 'survey_id' => $study_id),
                array(null),
                array('%d', '%d')
            );
        }

        // If t1_timestamp provided, set it first
        if (!empty($t1_timestamp)) {
            // Validate timestamp format
            $ts = strtotime($t1_timestamp);
            if ($ts === false) {
                return new WP_Error('invalid_timestamp', 'Invalid timestamp format');
            }

            // Update the T1 assignment's submitted_at
            $t1_wave = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}survey_waves WHERE study_id = %d AND wave_index = 1",
                $study_id
            ));

            if ($t1_wave) {
                EIPSI_Longitudinal_Assignment_Transition_Service::force_t1_submitted($t1_wave,$participant_id,date('Y-m-d H:i:s',$ts));
            }
        }

        // Now run the anchor process
        return self::anchor_participant_timeline($study_id, $participant_id);
    }

public static function batch_anchor_existing_participants($study_id = 0) {
        global $wpdb;

        $where = "WHERE p.t1_completed_at IS NULL";
        $params = array();

        if ($study_id > 0) {
            $where .= " AND p.survey_id = %d";
            $params[] = $study_id;
        }

        // Find participants who have submitted T1 but aren't anchored
        $query = "
            SELECT p.id as participant_id, p.survey_id, a.submitted_at as t1_submitted_at
            FROM {$wpdb->prefix}survey_participants p
            JOIN {$wpdb->prefix}survey_assignments a ON p.id = a.participant_id
            JOIN {$wpdb->prefix}survey_waves w ON a.wave_id = w.id AND w.study_id = p.survey_id
            {$where}
            AND w.wave_index = 1
            AND a.status = 'submitted'
            ORDER BY a.submitted_at ASC
        ";

        $unanchored = empty($params)
            ? $wpdb->get_results($query)
            : $wpdb->get_results($wpdb->prepare($query, $params));

        $results = array(
            'total' => count($unanchored),
            'anchored' => 0,
            'errors' => 0,
            'details' => array(),
        );

        foreach ($unanchored as $row) {
            $anchor_result = self::anchor_participant_timeline($row->survey_id, $row->participant_id);

            if (is_wp_error($anchor_result)) {
                $results['errors']++;
                $results['details'][] = array(
                    'participant_id' => $row->participant_id,
                    'study_id' => $row->survey_id,
                    'error' => $anchor_result->get_error_message(),
                );
            } else {
                $results['anchored']++;
            }
        }

        return $results;
    }

public static function calculate_wave_availability($participant_id, $study_id, $wave) {
        global $wpdb;

        // T1: disponible desde el registro del participante
        if ($wave->wave_index == 1) {
            $participant = $wpdb->get_row($wpdb->prepare(
                "SELECT created_at FROM {$wpdb->prefix}survey_participants WHERE id = %d",
                $participant_id
            ));

            return $participant ? $participant->created_at : null;
        }

        // T2+: requiere que T1 esté completado
        $t1 = $wpdb->get_row($wpdb->prepare(
            "SELECT t1_completed_at FROM {$wpdb->prefix}survey_assignments
             WHERE participant_id = %d AND study_id = %d AND wave_index = 1",
            $participant_id, $study_id
        ));

        if (!$t1 || !$t1->t1_completed_at) {
            error_log("[EIPSI Wave Service] T1 not completed for participant {$participant_id}, wave {$wave->wave_index} not available");
            return null; // T1 no completado → wave no disponible
        }

        // Calcular desde t1_completed_at + offset_minutes
        $offset_seconds = $wave->offset_minutes * 60;
        $available_at = date('Y-m-d H:i:s', strtotime($t1->t1_completed_at) + $offset_seconds);

        error_log("[EIPSI Wave Service] Wave {$wave->wave_index} available at {$available_at} (T1 completed: {$t1->t1_completed_at}, offset: {$wave->offset_minutes} min)");

        return $available_at;
    }
}
