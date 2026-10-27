<?php
/** Notifications owner; public WordPress adapters retain existing contracts. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Notification_Email_Log_Service {

public static function log_email($survey_id, $participant_id, $type, $status, $error_message = null, $subject = '', $metadata = array()) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'survey_email_log';
        $subject = sanitize_text_field($subject);

        // v2.6.1 - Ensure email_type is never null or empty
        if (empty($type)) {
            $type = 'custom';
            // Log stack trace to identify where empty email_type is coming from
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);
            $caller = isset($backtrace[1]) ? $backtrace[1]['function'] : 'unknown';
            $file = isset($backtrace[1]) ? basename($backtrace[1]['file']) : 'unknown';
            EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_email_log_service_L19'), 'debug');
        }

        // Log para debug
        EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_email_log_service_L23'), 'debug');

        // Validate survey_id - allow 0 but cast to int for safe insert
        $survey_id = intval($survey_id);

        // Skip logging if participant_id is invalid
        if ($participant_id <= 0) {
            EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_email_log_service_L30'), 'debug');
            return;
        }

        // Encode metadata as JSON if provided
        $metadata_json = !empty($metadata) ? wp_json_encode($metadata) : null;

        $result = $wpdb->insert(
            $table_name,
            array(
                'survey_id' => $survey_id,
                'participant_id' => $participant_id,
                'email_type' => $type,
                'recipient_email' => self::get_participant_email($participant_id),
                'subject' => $subject,
                'status' => $status,
                'error_message' => $error_message,
                'metadata' => $metadata_json,
                'sent_at' => current_time('mysql'),
                'created_at' => current_time('mysql')
            ),
            array('%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );

        if ($result === false) {
            EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_email_log_service_L55'), 'debug');
            return 0;
        }

        return $wpdb->insert_id;
    }

private static function get_participant_email($participant_id) {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare("SELECT email FROM {$wpdb->prefix}survey_participants WHERE id = %d", $participant_id));
    }

public static function get_email_history($survey_id, $limit = 100) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'survey_email_log';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE survey_id = %d ORDER BY sent_at DESC LIMIT %d",
            $survey_id,
            $limit
        ));
    }

public static function get_email_log_entries($survey_id = 0, $filters = array(), $limit = 20, $offset = 0) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'survey_email_log';
        $participants_table = $wpdb->prefix . 'survey_participants';

        $where = array();
        $params = array();

        // Survey filter
        if ($survey_id > 0) {
            $where[] = 'el.survey_id = %d';
            $params[] = $survey_id;
        }

        // Type filter
        if (!empty($filters['type'])) {
            $where[] = 'el.email_type = %s';
            $params[] = sanitize_text_field($filters['type']);
        }

        // Status filter
        if (!empty($filters['status'])) {
            $where[] = 'el.status = %s';
            $params[] = sanitize_text_field($filters['status']);
        }

        // Date range filters
        if (!empty($filters['date_from'])) {
            $where[] = 'DATE(el.sent_at) >= %s';
            $params[] = sanitize_text_field($filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $where[] = 'DATE(el.sent_at) <= %s';
            $params[] = sanitize_text_field($filters['date_to']);
        }

        $where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        // Get total count
        $count_query = "SELECT COUNT(*) FROM {$table_name} el {$where_clause}";
        $total = $wpdb->get_var($wpdb->prepare($count_query, $params));

        // v2.5.4 - Get logs with participant names (fallback to email if name is missing)
        $query = "SELECT el.*,
                         CASE
                            WHEN p.first_name IS NOT NULL AND p.first_name != '' THEN TRIM(CONCAT(p.first_name, ' ', p.last_name))
                            ELSE el.recipient_email
                         END as participant_name
                  FROM {$table_name} el
                  LEFT JOIN {$participants_table} p ON el.participant_id = p.id
                  {$where_clause}
                  ORDER BY el.sent_at DESC
                  LIMIT %d OFFSET %d";

        $params[] = (int) $limit;
        $params[] = (int) $offset;

        $logs = $wpdb->get_results($wpdb->prepare($query, $params));

        // v2.1.2 - Formatear fechas según zona horaria de WordPress
        foreach ($logs as &$log) {
            if (!empty($log->sent_at)) {
                $timestamp = strtotime($log->sent_at);
                $log->sent_at_formatted = wp_date(
                    get_option('date_format') . ' ' . get_option('time_format'),
                    $timestamp
                );
            } else {
                $log->sent_at_formatted = '-';
            }
        }

        return array(
            'logs' => $logs,
            'total' => (int) $total
        );
    }

public static function get_email_details($email_log_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'survey_email_log';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT el.*,
                    CASE
                        WHEN p.first_name IS NOT NULL AND p.first_name != '' THEN TRIM(CONCAT(p.first_name, ' ', p.last_name))
                        ELSE el.recipient_email
                    END as participant_name
             FROM {$table_name} el
             LEFT JOIN {$wpdb->prefix}survey_participants p ON el.participant_id = p.id
             WHERE el.id = %d",
            (int) $email_log_id
        ));
    }

public static function get_email_deliverability_stats() {
        global $wpdb;
        // Current Study Engine transport outcomes; do not mix retired survey logs.
        $table = $wpdb->prefix . 'eipsi_engine_management_events';
        $empty = array('has_data'=>false,'total_emails'=>0,'sent'=>0,'failed'=>0,
            'success_rate'=>null,'last_7_days'=>0,'last_30_days'=>0,
            'common_error'=>null,'health_status'=>'no_data');
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) return $empty;
        $row = $wpdb->get_row("SELECT COUNT(*) AS total,
            SUM(action='MAIL_ACCEPTED') AS sent, SUM(action='MAIL_RETRYABLE') AS failed,
            SUM(created_at >= UTC_TIMESTAMP() - INTERVAL 7 DAY) AS recent7,
            SUM(created_at >= UTC_TIMESTAMP() - INTERVAL 30 DAY) AS recent30
            FROM `$table` WHERE action IN ('MAIL_ACCEPTED','MAIL_RETRYABLE')", ARRAY_A);
        if (!$row || !(int)$row['total']) return $empty;
        $rate = round((int)$row['sent'] / (int)$row['total'] * 100, 1);
        return array('has_data'=>true,'total_emails'=>(int)$row['total'],
            'sent'=>(int)$row['sent'],'failed'=>(int)$row['failed'],'success_rate'=>$rate,
            'last_7_days'=>(int)$row['recent7'],'last_30_days'=>(int)$row['recent30'],
            'common_error'=>null,'health_status'=>$rate>=95?'excellent':($rate>=85?'good':'needs_attention'));
    }
}
