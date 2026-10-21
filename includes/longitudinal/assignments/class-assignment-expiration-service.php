<?php
/** M4 longitudinal owner. Existing public adapters preserve their contracts. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Longitudinal_Assignment_Expiration_Service {
public static function process_expirations() {
        global $wpdb;

        $start_time = microtime(true);
        $results = array(
            'success' => true,
            'expired_count' => 0,
            'nudges_cancelled' => 0,
            'errors' => array(),
            'execution_time' => 0,
        );

        // Find assignments that should be expired
        // Status must be 'pending' or 'available' (not already submitted/expired)
        // due_at must be set and in the past
        $expired_assignments = $wpdb->get_results("
            SELECT
                a.id,
                a.participant_id,
                a.wave_id,
                a.study_id,
                a.status,
                a.due_at,
                w.wave_index,
                w.name as wave_name
            FROM {$wpdb->prefix}survey_assignments a
            JOIN {$wpdb->prefix}survey_waves w ON a.wave_id = w.id
            WHERE a.status IN ('pending', 'available')
            AND a.due_at IS NOT NULL
            AND a.due_at <= NOW()
            ORDER BY a.due_at ASC
        ");

        if (empty($expired_assignments)) {
            $results['execution_time'] = round((microtime(true) - $start_time) * 1000, 2);
            return $results;
        }

        // Process each expired assignment
        foreach ($expired_assignments as $assignment) {
            try {
                // Transition to expired
                $updated = EIPSI_Longitudinal_Assignment_Transition_Service::expire_snapshot($assignment, true);

                if ($updated === 0) { continue; }
                if ($updated === false) {
                    $results['errors'][] = sprintf(
                        'Failed to expire assignment %d: %s',
                        $assignment->id,
                        $wpdb->last_error
                    );
                    continue;
                }

                $results['expired_count']++;

                // Log cambio de estado
                if ($updated > 0 && class_exists('EIPSI_Assignment_State_Logger')) {
                    EIPSI_Assignment_State_Logger::log_assignment_change($assignment->id, $assignment->status, 'expired');
                }

                // Cancel pending nudges for this assignment
                $cancelled = self::cancel_pending_nudges($assignment->participant_id, $assignment->wave_id);
                $results['nudges_cancelled'] += $cancelled;

                // Log the expiration
                self::log_expiration($assignment);

                // Fire hook for extensibility
                do_action('eipsi_wave_expired', $assignment->id, $assignment->participant_id, $assignment->wave_id);

                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log(sprintf(
                        '[EIPSI-EXPIRATION] Assignment %d expired (Wave %d - %s, Participant %d, Due: %s)',
                        $assignment->id,
                        $assignment->wave_index,
                        $assignment->wave_name,
                        $assignment->participant_id,
                        $assignment->due_at
                    ));
                }

            } catch (Exception $e) {
                $results['errors'][] = sprintf(
                    'Exception processing assignment %d: %s',
                    $assignment->id,
                    $e->getMessage()
                );
            }
        }

        $results['execution_time'] = round((microtime(true) - $start_time) * 1000, 2);

        // Log cron execution
        self::log_cron_execution($results);

        return $results;
    }

public static function cancel_pending_nudges($participant_id,$wave_id) { return EIPSI_Notification_Nudge_Queue_Service::cancel_pending_nudges($participant_id,$wave_id); }

private static function log_expiration($assignment) {
        global $wpdb;

        $audit_table = $wpdb->prefix . 'survey_audit_log';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$audit_table}'") !== $audit_table) {
            return;
        }

        $wpdb->insert(
            $audit_table,
            array(
                'survey_id' => $assignment->study_id,
                'participant_id' => $assignment->participant_id,
                'action' => 'wave_expired',
                'actor_type' => 'system',
                'actor_id' => 0,
                'metadata' => wp_json_encode(array(
                    'assignment_id' => $assignment->id,
                    'wave_id' => $assignment->wave_id,
                    'wave_index' => $assignment->wave_index,
                    'wave_name' => $assignment->wave_name,
                    'due_at' => $assignment->due_at,
                    'previous_status' => $assignment->status,
                )),
                'created_at' => current_time('mysql'),
            ),
            array('%d', '%d', '%s', '%s', '%d', '%s', '%s')
        );
    }

private static function log_cron_execution($results) {
        global $wpdb;

        $cron_log_table = $wpdb->prefix . 'survey_cron_log';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$cron_log_table}'") !== $cron_log_table) {
            return;
        }

        $wpdb->insert(
            $cron_log_table,
            array(
                'job_name' => 'wave_expiration_check',
                'status' => empty($results['errors']) ? 'success' : 'partial',
                'message' => sprintf(
                    'Expired %d assignments, cancelled %d nudges in %s ms',
                    $results['expired_count'],
                    $results['nudges_cancelled'],
                    $results['execution_time']
                ),
                'metadata' => wp_json_encode($results),
                'executed_at' => current_time('mysql'),
            ),
            array('%s', '%s', '%s', '%s', '%s')
        );
    }

public static function is_assignment_expired($assignment) {
        // Handle both object and array
        $status = is_array($assignment) ? $assignment['status'] : $assignment->status;
        $due_at = is_array($assignment) ? ($assignment['due_at'] ?? null) : ($assignment->due_at ?? null);

        // Already marked as expired or submitted
        if (in_array($status, array('expired', 'submitted'), true)) {
            return $status === 'expired';
        }

        // No due date = never expires
        if (empty($due_at)) {
            return false;
        }

        // Check if past due date
        $now = current_time('timestamp');
        $due_timestamp = strtotime($due_at);

        return $now >= $due_timestamp;
    }

public static function get_visual_status($assignment) {
        $db_status = is_array($assignment) ? $assignment['status'] : $assignment->status;

        // If already submitted or expired, return as-is
        if (in_array($db_status, array('submitted', 'expired'), true)) {
            return $db_status;
        }

        // Real-time expiration check
        if (self::is_assignment_expired($assignment)) {
            return 'expired';
        }

        // Check if available
        $available_at = is_array($assignment) ? ($assignment['available_at'] ?? null) : ($assignment->available_at ?? null);
        if (!empty($available_at)) {
            $now = current_time('timestamp');
            $available_timestamp = strtotime($available_at);

            if ($now >= $available_timestamp) {
                return $db_status === 'in_progress' ? 'in_progress' : 'available';
            }
        }

        return 'pending';
    }

public static function manual_expire($assignment_id) { return EIPSI_Longitudinal_Assignment_Transition_Service::manual_expire($assignment_id); }

public static function get_study_expiration_stats($study_id) {
        global $wpdb;

        $study_id = absint($study_id);

        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COUNT(*) as total_assignments,
                SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) as expired_count,
                SUM(CASE WHEN status IN ('pending', 'available') AND due_at IS NOT NULL AND due_at <= NOW() THEN 1 ELSE 0 END) as pending_expiration
            FROM {$wpdb->prefix}survey_assignments
            WHERE study_id = %d",
            $study_id
        ), ARRAY_A);

        if (!$stats) {
            return array(
                'total_assignments' => 0,
                'expired_count' => 0,
                'pending_expiration' => 0,
                'expiration_rate' => 0,
            );
        }

        $stats['expiration_rate'] = $stats['total_assignments'] > 0
            ? round(($stats['expired_count'] / $stats['total_assignments']) * 100, 2)
            : 0;

        return $stats;
    }
}
