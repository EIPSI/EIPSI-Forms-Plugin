<?php
/**
 * EIPSI Wave Recalculator Service
 *
 * Recalculates wave availability windows when T1 is completed.
 * Uses offset_minutes from wave configuration anchored to T1 completion.
 *
 * @package EIPSI_Forms
 * @since 2.5.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class EIPSI_Wave_Recalculator
 *
 * Handles recalculation of wave availability based on T1 completion timestamp.
 * All audit log entries use the existing survey_audit_log schema.
 */
class EIPSI_Wave_Recalculator {

    /**
     * Recalculate all wave availability for a participant after T1 completion
     *
     * @param int $participant_id Participant ID
     * @param int $study_id Study ID
     * @param string $t1_completed_at ISO datetime of T1 completion
     * @param string $triggered_by 'admin' or 'system'
     * @param int|null $user_id User ID if triggered by admin
     * @return array Result with status and affected waves
     */
    public static function recalculate_after_t1($participant_id, $study_id, $t1_completed_at, $triggered_by = 'system', $user_id = null) {
        global $wpdb;

        $batch_id = self::generate_batch_id();
        $results = array(
            'success' => true,
            'batch_id' => $batch_id,
            'affected_waves' => array(),
            'errors' => array()
        );

        // Get all waves for this study ordered by wave_index
        $waves = $wpdb->get_results($wpdb->prepare("
            SELECT id, wave_index, name, offset_minutes, window_minutes, form_id
            FROM {$wpdb->prefix}survey_waves
            WHERE study_id = %d
            ORDER BY wave_index ASC
        ", $study_id));

        if (empty($waves)) {
            return $results;
        }

        // Get study end offset
        $study_end_offset = $wpdb->get_var($wpdb->prepare("
            SELECT study_end_offset_minutes
            FROM {$wpdb->prefix}survey_studies
            WHERE id = %d
        ", $study_id));

        $t1_timestamp = strtotime($t1_completed_at);
        if (!$t1_timestamp) {
            $results['success'] = false;
            $results['errors'][] = 'Invalid T1 completion timestamp';
            return $results;
        }

        foreach ($waves as $wave) {
            // Skip T1 (wave_index = 1) as it's already completed
            if ((int) $wave->wave_index === 1) {
                continue;
            }

            // Calculate available_at based on offset_minutes from T1
            $available_at = date('Y-m-d H:i:s', $t1_timestamp + ($wave->offset_minutes * 60));

            // Calculate due_at based on window_minutes (if set)
            $due_at = null;
            if ($wave->window_minutes) {
                $due_at = date('Y-m-d H:i:s', strtotime($available_at) + ($wave->window_minutes * 60));
            }

            // Get current assignment for comparison
            $current = $wpdb->get_row($wpdb->prepare("
                SELECT available_at, due_at
                FROM {$wpdb->prefix}survey_assignments
                WHERE participant_id = %d AND wave_id = %d
            ", $participant_id, $wave->id));

            $old_values = array();
            if ($current) {
                $old_values = array(
                    'available_at' => $current->available_at,
                    'due_at' => $current->due_at
                );
            }

            $new_values = array(
                'available_at' => $available_at,
                'due_at' => $due_at
            );

            // Update assignment
            $updated = $wpdb->update(
                $wpdb->prefix . 'survey_assignments',
                array(
                    'available_at' => $available_at,
                    'due_at' => $due_at,
                    'updated_at' => current_time('mysql')
                ),
                array(
                    'participant_id' => $participant_id,
                    'wave_id' => $wave->id
                ),
                array('%s', '%s', '%s'),
                array('%d', '%d')
            );

            if ($updated !== false) {
                $results['affected_waves'][] = array(
                    'wave_id' => $wave->id,
                    'wave_index' => $wave->wave_index,
                    'wave_name' => $wave->name,
                    'available_at' => $available_at,
                    'due_at' => $due_at
                );

                // Log to audit log with adapted schema
                self::log_audit(
                    'wave_recalculated',
                    $study_id,
                    $participant_id,
                    $wave->id,
                    $batch_id,
                    $old_values,
                    $new_values,
                    $triggered_by,
                    $user_id
                );
            } else {
                $results['errors'][] = "Failed to update wave {$wave->wave_index}";
            }
        }

        // Handle study end offset if configured
        if ($study_end_offset) {
            $study_end_at = date('Y-m-d H:i:s', $t1_timestamp + ($study_end_offset * 60));

            // Store study end in participant record or handle as needed
            $wpdb->update(
                $wpdb->prefix . 'survey_participants',
                array('study_end_at' => $study_end_at),
                array('id' => $participant_id, 'survey_id' => $study_id),
                array('%s'),
                array('%d', '%d')
            );
        }

        return $results;
    }

    /**
     * Generate unique batch ID for tracking recalculation operations
     *
     * @return string Batch ID
     */
    private static function generate_batch_id() {
        return wp_generate_password(40, false, false);
    }

    /**
     * Log audit entry using existing survey_audit_log schema
     *
     * Schema adaptations:
     * - event_type → action
     * - triggered_by → actor_type (maps 'user' to 'admin')
     * - user_id → actor_id
     * - batch_id, study_id, wave_id, old_value, new_value → metadata JSON
     *
     * @param string $event_type Event type
     * @param int $study_id Study ID
     * @param int $participant_id Participant ID
     * @param int $wave_id Wave ID
     * @param string $batch_id Batch ID
     * @param array $old_values Old values
     * @param array $new_values New values
     * @param string $triggered_by 'admin' or 'system'
     * @param int|null $user_id User ID
     */
    private static function log_audit($event_type, $study_id, $participant_id, $wave_id, $batch_id, $old_values, $new_values, $triggered_by, $user_id) {
        global $wpdb;

        // Map triggered_by to actor_type (only 'admin' or 'system' allowed)
        $actor_type = ($triggered_by === 'user' || $triggered_by === 'admin') ? 'admin' : 'system';

        // Build metadata JSON with all fields that don't exist in base schema
        $metadata = wp_json_encode(array(
            'batch_id' => $batch_id,
            'study_id' => $study_id,
            'wave_id' => $wave_id,
            'old_value' => $old_values,
            'new_value' => $new_values,
            'triggered_by_original' => $triggered_by
        ));

        $wpdb->insert(
            $wpdb->prefix . 'survey_audit_log',
            array(
                'survey_id' => $study_id,
                'participant_id' => $participant_id,
                'action' => $event_type,          // event_type → action
                'actor_type' => $actor_type,      // triggered_by → actor_type
                'actor_id' => $user_id ?: 0,      // user_id → actor_id
                'metadata' => $metadata,
                'created_at' => current_time('mysql', 1)
            ),
            array('%d', '%d', '%s', '%s', '%d', '%s', '%s')
        );
    }

    /**
     * Recalculate for a single wave (useful for manual adjustments)
     *
     * @param int $participant_id Participant ID
     * @param int $wave_id Wave ID
     * @param string $t1_completed_at T1 completion timestamp
     * @param string $triggered_by 'admin' or 'system'
     * @param int|null $user_id User ID
     * @return bool Success
     */
    public static function recalculate_single_wave($participant_id, $wave_id, $t1_completed_at, $triggered_by = 'system', $user_id = null) {
        global $wpdb;

        $batch_id = self::generate_batch_id();

        $wave = $wpdb->get_row($wpdb->prepare("
            SELECT w.*, a.study_id
            FROM {$wpdb->prefix}survey_waves w
            JOIN {$wpdb->prefix}survey_assignments a ON a.wave_id = w.id
            WHERE w.id = %d AND a.participant_id = %d
        ", $wave_id, $participant_id));

        if (!$wave) {
            return false;
        }

        $t1_timestamp = strtotime($t1_completed_at);
        if (!$t1_timestamp) {
            return false;
        }

        $available_at = date('Y-m-d H:i:s', $t1_timestamp + ($wave->offset_minutes * 60));
        $due_at = null;
        if ($wave->window_minutes) {
            $due_at = date('Y-m-d H:i:s', strtotime($available_at) + ($wave->window_minutes * 60));
        }

        // Get current values for audit
        $current = $wpdb->get_row($wpdb->prepare("
            SELECT available_at, due_at
            FROM {$wpdb->prefix}survey_assignments
            WHERE participant_id = %d AND wave_id = %d
        ", $participant_id, $wave_id));

        $old_values = $current ? array('available_at' => $current->available_at, 'due_at' => $current->due_at) : array();
        $new_values = array('available_at' => $available_at, 'due_at' => $due_at);

        $updated = $wpdb->update(
            $wpdb->prefix . 'survey_assignments',
            array(
                'available_at' => $available_at,
                'due_at' => $due_at,
                'updated_at' => current_time('mysql')
            ),
            array('participant_id' => $participant_id, 'wave_id' => $wave_id),
            array('%s', '%s', '%s'),
            array('%d', '%d')
        );

        if ($updated !== false) {
            self::log_audit(
                'wave_recalculated_single',
                $wave->study_id,
                $participant_id,
                $wave_id,
                $batch_id,
                $old_values,
                $new_values,
                $triggered_by,
                $user_id
            );
            return true;
        }

        return false;
    }
}
