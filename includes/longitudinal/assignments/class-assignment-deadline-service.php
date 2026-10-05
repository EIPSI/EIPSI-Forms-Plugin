<?php
/** M4 longitudinal owner. Existing public adapters preserve their contracts. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Longitudinal_Assignment_Deadline_Service {
public static function wp_ajax_eipsi_extend_wave_deadline_handler($request,$query=array()) {    $wave_id = isset($request['wave_id']) ? (int) $request['wave_id'] : 0;
    $new_deadline = isset($request['deadline_date']) ? sanitize_text_field($request['deadline_date']) : '';

    if (!$wave_id || empty($new_deadline)) {
        return EIPSI_Form_Response::error('Missing parameters');
        return;
    }

    global $wpdb;

    // Get wave info to check if it's T1 (anchor wave)
    $wave = $wpdb->get_row($wpdb->prepare(
        "SELECT id, study_id, offset_minutes, window_minutes FROM {$wpdb->prefix}survey_waves WHERE id = %d",
        $wave_id
    ));

    if (!$wave) {
        return EIPSI_Form_Response::error('Wave not found');
        return;
    }

    $is_t1_anchor = ($wave->offset_minutes == 0);
    $deadline_datetime = $new_deadline . ' 23:59:59';
    $deadline_timestamp = strtotime($deadline_datetime);

    // Update legacy due_date in wave for backward compatibility
    // Mark this as a manual deadline and redistribute nudges if T1
    $current_nudge_config = $wpdb->get_var($wpdb->prepare(
        "SELECT nudge_config FROM {$wpdb->prefix}survey_waves WHERE id = %d",
        $wave_id
    ));
    $nudge_config = !empty($current_nudge_config) ? json_decode($current_nudge_config, true) : array();
    $nudge_config['manual_deadline'] = true;

    // System: deadline → window → nudges (any wave)
    if ($wave->window_minutes > 0) {
        // Get study creation date to calculate dynamic window
        $study = $wpdb->get_row($wpdb->prepare(
            "SELECT created_at FROM {$wpdb->prefix}survey_studies WHERE id = %d",
            $wave->study_id
        ));

        if ($study) {
            // T1 (offset=0): window from created_at to deadline (fixed)
            // Other waves: window from NOW to deadline (dynamic, remaining time)
            $is_t1 = ($wave->offset_minutes == 0);

            if ($is_t1) {
                $study_created_timestamp = strtotime($study->created_at);
                $new_window_minutes = ceil(($deadline_timestamp - $study_created_timestamp) / 60);
            } else {
                $now_timestamp = current_time('timestamp');
                $new_window_minutes = ceil(($deadline_timestamp - $now_timestamp) / 60);
            }

            // Save original nudges if not already saved
            if (!isset($nudge_config['original_nudges'])) {
                $nudge_config['original_nudges'] = array(
                    'nudge_1' => $nudge_config['nudge_1'] ?? null,
                    'nudge_2' => $nudge_config['nudge_2'] ?? null,
                    'nudge_3' => $nudge_config['nudge_3'] ?? null,
                    'nudge_4' => $nudge_config['nudge_4'] ?? null,
                );
                $nudge_config['original_window_minutes'] = $wave->window_minutes;
            }

            // Redistribute nudges proportionally from original values
            $base_nudges = isset($nudge_config['original_nudges'])
                ? $nudge_config['original_nudges']
                : array(
                    'nudge_1' => $nudge_config['nudge_1'] ?? null,
                    'nudge_2' => $nudge_config['nudge_2'] ?? null,
                    'nudge_3' => $nudge_config['nudge_3'] ?? null,
                    'nudge_4' => $nudge_config['nudge_4'] ?? null,
                );

            $redistributed = eipsi_redistribute_nudges(
                $base_nudges,
                $nudge_config['original_window_minutes'],
                $new_window_minutes
            );

            $nudge_config['nudge_1'] = $redistributed['nudge_1'];
            $nudge_config['nudge_2'] = $redistributed['nudge_2'];
            $nudge_config['nudge_3'] = $redistributed['nudge_3'];
            $nudge_config['nudge_4'] = $redistributed['nudge_4'];
            $nudge_config['redistributed'] = true;

            error_log(sprintf('[EIPSI Sequential] Wave %d: Redistributed nudges - original_window=%d min, new_window=%d min',
                $wave_id, $nudge_config['original_window_minutes'], $new_window_minutes));
        }
    }

    $wpdb->update(
        "{$wpdb->prefix}survey_waves",
        array(
            'due_date' => $new_deadline,
            'nudge_config' => wp_json_encode($nudge_config)
        ),
        array('id' => $wave_id),
        array('%s', '%s'),
        array('%d')
    );

    // Sequential Logic: Recalculate subsequent waves (any wave with deadline triggers this)
    // Get all waves in this study ordered by offset
    $all_waves = $wpdb->get_results($wpdb->prepare(
        "SELECT id, offset_minutes, window_minutes FROM {$wpdb->prefix}survey_waves
         WHERE study_id = %d ORDER BY offset_minutes ASC",
        $wave->study_id
    ));

    // Sequential: Each wave opens when the previous wave closes
    // Start with current wave's deadline as the opening time for the next wave
    $previous_deadline_timestamp = $deadline_timestamp;
    $start_processing = false; // Flag to start processing after current wave

    foreach ($all_waves as $subsequent_wave) {
        // Start processing waves after the current wave
        if ($subsequent_wave->id == $wave_id) {
            $start_processing = true;
            continue; // Skip current wave
        }

        if ($start_processing) {
                // Get current wave data to check if it has a manual deadline
                $current_wave_data = $wpdb->get_row($wpdb->prepare(
                    "SELECT due_date, nudge_config FROM {$wpdb->prefix}survey_waves WHERE id = %d",
                    $subsequent_wave->id
                ));

                // Check if this wave has a manual deadline set by the user
                $wave_nudge_config = !empty($current_wave_data->nudge_config) ? json_decode($current_wave_data->nudge_config, true) : array();
                $has_manual_deadline = isset($wave_nudge_config['manual_deadline']) && $wave_nudge_config['manual_deadline'] === true;

                // This wave opens when the previous wave closes
                $new_available_at = date('Y-m-d H:i:s', $previous_deadline_timestamp);

                // Calculate new due_at based on window OR manual deadline
                $new_due_at = null;
                $new_due_date = null;

                if ($has_manual_deadline) {
                    // Keep manual deadline, just update available_at
                    $new_due_at = $current_wave_data->due_date . ' 23:59:59';
                    $new_due_date = $current_wave_data->due_date;
                    // Update previous_deadline for next wave
                    $previous_deadline_timestamp = strtotime($new_due_at);
                } else if ($subsequent_wave->window_minutes > 0) {
                    // Calculate automatic deadline from available_at + window
                    $new_due_timestamp = $previous_deadline_timestamp + ($subsequent_wave->window_minutes * 60);
                    $new_due_at = date('Y-m-d 23:59:59', $new_due_timestamp);
                    $new_due_date = date('Y-m-d', $new_due_timestamp);

                    // Update wave's due_date for display (only if no manual deadline)
                    $wpdb->update(
                        "{$wpdb->prefix}survey_waves",
                        array('due_date' => $new_due_date),
                        array('id' => $subsequent_wave->id),
                        array('%s'),
                        array('%d')
                    );

                    // Update previous_deadline for next wave
                    $previous_deadline_timestamp = $new_due_timestamp;
                }

                // Update all assignments for this wave
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$wpdb->prefix}survey_assignments
                     SET available_at = %s, due_at = %s
                     WHERE wave_id = %d AND status NOT IN ('submitted', 'expired')",
                    $new_available_at,
                    $new_due_at,
                    $subsequent_wave->id
                ));

                // Reschedule nudges for affected assignments
                $affected = $wpdb->get_col($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}survey_assignments
                     WHERE wave_id = %d AND status NOT IN ('submitted', 'expired')",
                    $subsequent_wave->id
                ));

                foreach ($affected as $assignment_id) {
                    do_action('eipsi_assignment_deadline_changed', $assignment_id, $new_due_at);
                }
        }
    }

    // Update T1 (or current wave) assignments
    $assignments_updated = $wpdb->update(
        "{$wpdb->prefix}survey_assignments",
        array('due_at' => $deadline_datetime),
        array('wave_id' => $wave_id),
        array('%s'),
        array('%d')
    );

    if ($assignments_updated !== false) {
        // Reschedule nudges for T1 assignments
        $affected_assignments = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}survey_assignments WHERE wave_id = %d AND status NOT IN ('submitted', 'expired')",
            $wave_id
        ));

        foreach ($affected_assignments as $assignment_id) {
            do_action('eipsi_assignment_deadline_changed', $assignment_id, $deadline_datetime);
        }

        return EIPSI_Form_Response::success(array(
            'message' => 'Deadline set successfully. Subsequent waves recalculated sequentially.',
            'assignments_updated' => count($affected_assignments)
        ));
    } else {
        return EIPSI_Form_Response::error('Failed to extend deadline: ' . $wpdb->last_error);
    }
}

public static function wp_ajax_eipsi_remove_wave_deadline_handler($request,$query=array()) {    $wave_id = isset($request['wave_id']) ? (int) $request['wave_id'] : 0;
    if (!$wave_id) {
        return EIPSI_Form_Response::error('Missing wave ID');
    }

    try {
        global $wpdb;

        // Get wave info to check if it's T1
        $wave = $wpdb->get_row($wpdb->prepare(
            "SELECT id, study_id, offset_minutes FROM {$wpdb->prefix}survey_waves WHERE id = %d",
            $wave_id
        ));

        if (!$wave) {
            return EIPSI_Form_Response::error('Wave not found');
        }

        $is_t1_anchor = ($wave->offset_minutes == 0);

        // Remove legacy due_date from wave and restore original nudges if T1
        $current_nudge_config = $wpdb->get_var($wpdb->prepare(
            "SELECT nudge_config FROM {$wpdb->prefix}survey_waves WHERE id = %d",
            $wave_id
        ));
        $nudge_config = !empty($current_nudge_config) ? json_decode($current_nudge_config, true) : array();
        unset($nudge_config['manual_deadline']);

        // System: deadline → window → nudges (restore original nudges for T1)
        if ($is_t1_anchor && isset($nudge_config['original_nudges'])) {
            $nudge_config['nudge_1'] = $nudge_config['original_nudges']['nudge_1'];
            $nudge_config['nudge_2'] = $nudge_config['original_nudges']['nudge_2'];
            $nudge_config['nudge_3'] = $nudge_config['original_nudges']['nudge_3'];
            $nudge_config['nudge_4'] = $nudge_config['original_nudges']['nudge_4'];

            // Clean up temporary fields
            unset($nudge_config['original_nudges']);
            unset($nudge_config['original_window_minutes']);
            unset($nudge_config['redistributed']);

            error_log('[EIPSI T1-Anchor] Restored original nudges after removing deadline');
        }

        $wpdb->update(
            "{$wpdb->prefix}survey_waves",
            array(
                'due_date' => null,
                'nudge_config' => wp_json_encode($nudge_config)
            ),
            array('id' => $wave_id),
            array('%s', '%s'),
            array('%d')
        );

        // Sequential Logic: Revert subsequent waves to participant-based timing
        // Get all waves in this study
        $all_waves = $wpdb->get_results($wpdb->prepare(
            "SELECT id, offset_minutes, window_minutes FROM {$wpdb->prefix}survey_waves
             WHERE study_id = %d ORDER BY offset_minutes ASC",
            $wave->study_id
        ));

        $start_processing = false;

        // Remove due_date from all subsequent waves (only auto-calculated ones)
        foreach ($all_waves as $subsequent_wave) {
            // Start processing after current wave
            if ($subsequent_wave->id == $wave_id) {
                $start_processing = true;
                continue;
            }

            if ($start_processing) {
                // Check if this wave has a manual deadline
                $wave_data = $wpdb->get_row($wpdb->prepare(
                    "SELECT nudge_config FROM {$wpdb->prefix}survey_waves WHERE id = %d",
                    $subsequent_wave->id
                ));
                $wave_nudge_config = !empty($wave_data->nudge_config) ? json_decode($wave_data->nudge_config, true) : array();
                $is_manual = isset($wave_nudge_config['manual_deadline']) && $wave_nudge_config['manual_deadline'] === true;

                // Only remove auto-calculated deadlines, keep manual ones
                if (!$is_manual) {
                    $wpdb->update(
                        "{$wpdb->prefix}survey_waves",
                        array('due_date' => null),
                        array('id' => $subsequent_wave->id),
                        array('%s'),
                        array('%d')
                    );
                }
            }
        }

        // Recalculate each subsequent wave's assignments based on participant's t1_completed_at
        $start_processing = false;
        foreach ($all_waves as $subsequent_wave) {
            if ($subsequent_wave->id == $wave_id) {
                $start_processing = true;
                continue;
            }

            if ($start_processing) {
                $assignments = $wpdb->get_results($wpdb->prepare(
                    "SELECT a.id, a.participant_id
                     FROM {$wpdb->prefix}survey_assignments a
                     WHERE a.wave_id = %d AND a.status NOT IN ('submitted', 'expired')",
                    $subsequent_wave->id
                ));

                foreach ($assignments as $assignment) {
                    // Get participant's T1 timestamp
                    $participant = $wpdb->get_row($wpdb->prepare(
                        "SELECT t1_completed_at FROM {$wpdb->prefix}survey_participants WHERE id = %d",
                        $assignment->participant_id
                    ));

                    if ($participant && $participant->t1_completed_at) {
                        $t1_unix = strtotime($participant->t1_completed_at);

                        // Recalculate available_at based on participant's T1
                        $new_available_at = date('Y-m-d H:i:s', $t1_unix + ($subsequent_wave->offset_minutes * 60));

                        // Recalculate due_at based on window
                        $new_due_at = null;
                        if ($subsequent_wave->window_minutes > 0) {
                            $new_due_at = date('Y-m-d H:i:s', strtotime($new_available_at) + ($subsequent_wave->window_minutes * 60));
                        }

                        // Update assignment
                        $wpdb->update(
                            "{$wpdb->prefix}survey_assignments",
                            array('available_at' => $new_available_at, 'due_at' => $new_due_at),
                            array('id' => $assignment->id),
                            array('%s', '%s'),
                            array('%d')
                        );

                        // Reschedule nudges
                        do_action('eipsi_assignment_deadline_changed', $assignment->id, $new_due_at);
                    }
                }
            }
        }

        // Phase 5 T1-Anchor: Recalculate automatic due_at for all assignments
        // Get all assignments for this wave with T1 anchor
        $assignments = $wpdb->get_results($wpdb->prepare(
            "SELECT a.id, a.participant_id, a.available_at, w.study_id, w.offset_minutes, w.window_minutes
             FROM {$wpdb->prefix}survey_assignments a
             JOIN {$wpdb->prefix}survey_waves w ON a.wave_id = w.id
             WHERE a.wave_id = %d
             AND a.status NOT IN ('submitted', 'expired')",
            $wave_id
        ));

        $recalculated = 0;
        foreach ($assignments as $assignment) {
            // Get participant's T1 timestamp
            $participant = $wpdb->get_row($wpdb->prepare(
                "SELECT t1_completed_at FROM {$wpdb->prefix}survey_participants WHERE id = %d",
                $assignment->participant_id
            ));

            if (!$participant || !$participant->t1_completed_at) {
                continue; // Skip if T1 not completed yet
            }

            $t1_unix = strtotime($participant->t1_completed_at);
            $offset_minutes = absint($assignment->offset_minutes ?? 0);

            // Recalculate automatic due_at
            $due_at = null;
            if (!empty($assignment->window_minutes)) {
                // Use explicit window
                $due_at = date('Y-m-d H:i:s', $t1_unix + ($offset_minutes * 60) + ($assignment->window_minutes * 60));
            } else {
                // Use next wave's offset or study_end
                $next_wave_offset = $wpdb->get_var($wpdb->prepare(
                    "SELECT offset_minutes FROM {$wpdb->prefix}survey_waves
                     WHERE study_id = %d AND offset_minutes > %d
                     ORDER BY offset_minutes ASC LIMIT 1",
                    $assignment->study_id,
                    $offset_minutes
                ));

                if ($next_wave_offset) {
                    $due_at = date('Y-m-d H:i:s', $t1_unix + ($next_wave_offset * 60));
                } else {
                    // Last wave - use study_end_offset
                    $study_end_offset = $wpdb->get_var($wpdb->prepare(
                        "SELECT study_end_offset_minutes FROM {$wpdb->prefix}survey_studies WHERE id = %d",
                        $assignment->study_id
                    ));

                    if ($study_end_offset) {
                        $due_at = date('Y-m-d H:i:s', $t1_unix + ($study_end_offset * 60));
                    }
                }
            }

            // Update assignment with automatic due_at
            if ($due_at) {
                $wpdb->update(
                    "{$wpdb->prefix}survey_assignments",
                    array('due_at' => $due_at),
                    array('id' => $assignment->id),
                    array('%s'),
                    array('%d')
                );

                // Trigger hook to reschedule nudges
                do_action('eipsi_assignment_deadline_changed', $assignment->id);
                $recalculated++;
            }
        }

        error_log(sprintf('[EIPSI DASHBOARD API] Removed manual deadline, recalculated %d assignments', $recalculated));

        return EIPSI_Form_Response::success(array(
            'message' => 'Deadline removed successfully',
            'recalculated' => $recalculated
        ));
    } catch (Exception $e) {
        error_log('[EIPSI Remove Deadline] Error: ' . $e->getMessage());
        return EIPSI_Form_Response::error(array(
            'message' => 'Error al quitar plazo: ' . $e->getMessage(),
            'error' => 'exception'
        ), 500);
    }
}
}
