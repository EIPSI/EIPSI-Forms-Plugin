<?php
/** Notifications owner; public WordPress adapters retain existing contracts. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Notification_Nudge_Queue_Service {

public static function cancel_follow_up_jobs($assignment_id) {
        global $wpdb;
        $table = self::get_table_name();
        return $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status = 'cancelled', updated_at = %s
             WHERE status = 'pending' AND job_type IN ('send_nudge_1','send_nudge_2','send_nudge_3','send_nudge_4')
             AND JSON_EXTRACT(payload, '$.assignment_id') = %d", current_time('mysql'), $assignment_id
        ));
    }

private static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'survey_nudge_jobs';
    }

public static function enqueue($job_type, $payload, $priority = 10, $scheduled_at = null) {
        global $wpdb;

        if (!$scheduled_at) {
            $scheduled_at = current_time('mysql');
        }

        $result = $wpdb->insert(
            self::get_table_name(),
            array(
                'job_type' => sanitize_text_field($job_type),
                'payload' => wp_json_encode($payload),
                'priority' => intval($priority),
                'scheduled_at' => $scheduled_at,
                'status' => 'pending',
                'retries' => 0,
                'created_at' => current_time('mysql')
            ),
            array('%s', '%s', '%d', '%s', '%s', '%d', '%s')
        );

        if ($result === false) {
            error_log('[EIPSI JobQueue] ERROR: Failed to enqueue job: ' . $wpdb->last_error);
            return false;
        }

        $job_id = $wpdb->insert_id;

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[EIPSI JobQueue] Enqueued job #%d: type=%s, priority=%d, scheduled=%s',
                $job_id,
                $job_type,
                $priority,
                $scheduled_at
            ));
        }

        return $job_id;
    }

public static function count_pending_urgent() {
        global $wpdb;

        $table = self::get_table_name();
        $now = current_time('mysql');

        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE status = 'pending'
             AND scheduled_at <= %s
             AND retries < 5",
            $now
        ));

        return intval($count);
    }

public static function get_pending_jobs($limit = 10) {
        global $wpdb;
        
        // SKIP LOCKED requiere MySQL 8.0+ o PostgreSQL
        // Para WordPress/MySQL 5.7, usamos status='pending' + update atómico
        $table = self::get_table_name();
        $now = current_time('mysql');
        
        $jobs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} 
             WHERE status = 'pending' 
             AND scheduled_at <= %s 
             AND retries < 5
             ORDER BY priority ASC, scheduled_at ASC 
             LIMIT %d",
            $now,
            intval($limit)
        ));
        
        return $jobs;
    }

public static function mark_processing($job_id) {
        global $wpdb;

        $result = $wpdb->update(
            self::get_table_name(),
            array(
                'status' => 'processing',
                'processed_at' => current_time('mysql')
            ),
            array('id' => $job_id, 'status' => 'pending'),
            array('%s', '%s'),
            array('%d', '%s')
        );

        return $result > 0;
    }

public static function mark_completed($job_id, $result = null) {
        global $wpdb;

        $data = array(
            'status' => 'completed',
            'completed_at' => current_time('mysql')
        );

        if ($result) {
            $data['result'] = $result;
        }

        $wpdb->update(
            self::get_table_name(),
            $data,
            array('id' => $job_id),
            array('%s', '%s', '%s'),
            array('%d')
        );

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf('[EIPSI JobQueue] Job #%d completed', $job_id));
        }

        return true;
    }

public static function mark_for_retry($job_id, $error) {
        global $wpdb;

        $job = $wpdb->get_row($wpdb->prepare(
            "SELECT retries FROM " . self::get_table_name() . " WHERE id = %d",
            $job_id
        ));

        if (!$job) {
            return false;
        }

        // v2.5.3 - Errores irrecuperables: no reintentar, marcar como failed inmediatamente
        $irrecoverable_errors = [
            'Assignment not found',
            'Participant not found',
            'Wave not found',
            'Invalid payload JSON',
            'Invalid assignment ID',
            'Invalid participant ID'
        ];

        foreach ($irrecoverable_errors as $irrecoverable) {
            if (stripos($error, $irrecoverable) !== false) {
                // Marcar como failed permanentemente sin reintentar
                $wpdb->update(
                    self::get_table_name(),
                    array(
                        'status' => 'failed',
                        'error' => sanitize_text_field($error . ' [IRRECOVERABLE]'),
                        'retries' => intval($job->retries),
                        'failed_at' => current_time('mysql')
                    ),
                    array('id' => $job_id),
                    array('%s', '%s', '%d', '%s'),
                    array('%d')
                );

                error_log(sprintf(
                    '[EIPSI JobQueue] Job #%d marked as PERMANENTLY FAILED (irrecoverable error): %s',
                    $job_id,
                    $error
                ));

                return false;
            }
        }

        $retries = intval($job->retries) + 1;

        if ($retries >= 5) {
            // Máximo de reintentos alcanzado
            $wpdb->update(
                self::get_table_name(),
                array(
                    'status' => 'failed',
                    'error' => sanitize_text_field($error),
                    'retries' => $retries,
                    'failed_at' => current_time('mysql')
                ),
                array('id' => $job_id),
                array('%s', '%s', '%d', '%s'),
                array('%d')
            );

            error_log(sprintf(
                '[EIPSI JobQueue] Job #%d failed permanently after %d retries: %s',
                $job_id,
                $retries,
                $error
            ));

            return false;
        }

        // Backoff exponencial: 5min, 15min, 45min, 2h, 6h
        $backoff_minutes = [5, 15, 45, 120, 360];
        $delay = $backoff_minutes[min($retries - 1, count($backoff_minutes) - 1)];
        $new_scheduled = date('Y-m-d H:i:s', strtotime("+{$delay} minutes"));

        $wpdb->update(
            self::get_table_name(),
            array(
                'status' => 'pending',
                'retries' => $retries,
                'error' => sanitize_text_field($error),
                'scheduled_at' => $new_scheduled
            ),
            array('id' => $job_id),
            array('%s', '%d', '%s', '%s'),
            array('%d')
        );

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[EIPSI JobQueue] Job #%d scheduled for retry #%d at %s',
                $job_id,
                $retries,
                $new_scheduled
            ));
        }

        return true;
    }

public static function get_stats() {
        global $wpdb;
        $table = self::get_table_name();

        $stats = $wpdb->get_row(
            "SELECT
                SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status='processing' THEN 1 ELSE 0 END) as processing,
                SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) as failed
             FROM {$table}"
        );

        return array(
            'pending' => intval($stats->pending),
            'processing' => intval($stats->processing),
            'completed' => intval($stats->completed),
            'failed' => intval($stats->failed)
        );
    }

public static function cancel_jobs_for_assignment($assignment_id) {
        global $wpdb;
        $table = self::get_table_name();

        $assignment_id = intval($assignment_id);

        // Cancelar jobs pendientes que contengan el assignment_id en el payload
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET status = 'cancelled',
                 updated_at = %s
             WHERE status IN ('pending', 'processing')
             AND JSON_EXTRACT(payload, '$.assignment_id') = %d",
            current_time('mysql'),
            $assignment_id
        ));

        if ($result === false) {
            error_log('[EIPSI JobQueue] ERROR canceling jobs for assignment ' . $assignment_id . ': ' . $wpdb->last_error);
            return 0;
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[EIPSI JobQueue] Cancelled %d jobs for assignment %d',
                $result,
                $assignment_id
            ));
        }

        return intval($result);
    }
public static function cancel_pending_nudges($participant_id, $wave_id) {
        global $wpdb;

        // Check if nudge jobs table exists
        $table_name = $wpdb->prefix . 'survey_nudge_jobs';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
            return 0;
        }

        // Cancel all pending nudges for this participant + wave
        $cancelled = $wpdb->update(
            $table_name,
            array(
                'status' => 'cancelled',
                'cancelled_reason' => 'wave_expired',
                'updated_at' => current_time('mysql'),
            ),
            array(
                'participant_id' => $participant_id,
                'wave_id' => $wave_id,
                'status' => 'pending',
            ),
            array('%s', '%s', '%s'),
            array('%d', '%d', '%s')
        );

        return $cancelled !== false ? $cancelled : 0;
    }
}
