<?php
if (!defined('ABSPATH')) { exit; }
class EIPSI_Access_Log_Export_Query_Service {
public static function load($filters=array()){
        global $wpdb;

        $table_name = $wpdb->prefix . 'survey_participant_access_log';
        $participants_table = $wpdb->prefix . 'survey_participants';
        $studies_table = $wpdb->prefix . 'survey_studies';

        // Build query with filters
        $where = array('1=1');
        $params = array();

        // Date range filter
        if (!empty($filters['date_from'])) {
            $where[] = 'al.created_at >= %s';
            $params[] = sanitize_text_field($filters['date_from']) . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'al.created_at <= %s';
            $params[] = sanitize_text_field($filters['date_to']) . ' 23:59:59';
        }

        // Study filter
        if (!empty($filters['study_id']) && $filters['study_id'] !== 'all') {
            $where[] = 'al.study_id = %d';
            $params[] = absint($filters['study_id']);
        }

        // Action type filter
        if (!empty($filters['action_type']) && $filters['action_type'] !== 'all') {
            $valid_actions = array(
                'registration', 'login', 'login_failed', 'magic_link_clicked',
                'magic_link_sent', 'wave_started', 'wave_completed', 'logout',
                'session_expired', 'password_reset_requested', 'password_reset_completed'
            );
            if (in_array($filters['action_type'], $valid_actions, true)) {
                $where[] = 'al.action_type = %s';
                $params[] = sanitize_text_field($filters['action_type']);
            }
        }

        // Participant filter
        if (!empty($filters['participant_id'])) {
            $where[] = 'al.participant_id = %d';
            $params[] = absint($filters['participant_id']);
        }

        $where_clause = implode(' AND ', $where);

        // Get logs with participant and study info
        $query = "SELECT
                    al.id,
                    al.created_at as date,
                    al.action_type as action,
                    al.ip_address as ip,
                    al.user_agent as device,
                    p.email as participant_email,
                    CONCAT(COALESCE(p.first_name, ''), ' ', COALESCE(p.last_name, '')) as participant_name,
                    p.id as participant_id,
                    s.study_name as study,
                    s.id as study_id,
                    al.metadata
                  FROM {$table_name} al
                  LEFT JOIN {$participants_table} p ON al.participant_id = p.id
                  LEFT JOIN {$studies_table} s ON al.study_id = s.id
                  WHERE {$where_clause}
                  ORDER BY al.created_at DESC";

        if (!empty($params)) {
            $query = $wpdb->prepare($query, $params);
        }

        $logs = $wpdb->get_results($query);
if($wpdb->last_error){throw new RuntimeException('No se pudieron consultar los logs de acceso.');}
return $logs;
}
public static function load_stream($filters=array()){
        global $wpdb;

        $table_name = $wpdb->prefix . 'survey_participant_access_log';
        $participants_table = $wpdb->prefix . 'survey_participants';
        $studies_table = $wpdb->prefix . 'survey_studies';

        // Build query (same as export function)
        $where = array('1=1');
        $params = array();

        if (!empty($filters['date_from'])) {
            $where[] = 'al.created_at >= %s';
            $params[] = sanitize_text_field($filters['date_from']) . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'al.created_at <= %s';
            $params[] = sanitize_text_field($filters['date_to']) . ' 23:59:59';
        }
        if (!empty($filters['study_id']) && $filters['study_id'] !== 'all') {
            $where[] = 'al.study_id = %d';
            $params[] = absint($filters['study_id']);
        }
        if (!empty($filters['action_type']) && $filters['action_type'] !== 'all') {
            $where[] = 'al.action_type = %s';
            $params[] = sanitize_text_field($filters['action_type']);
        }
        if (!empty($filters['participant_id'])) {
            $where[] = 'al.participant_id = %d';
            $params[] = absint($filters['participant_id']);
        }

        $where_clause = implode(' AND ', $where);

        $query = "SELECT
                    al.created_at as date,
                    al.action_type as action,
                    al.ip_address as ip,
                    al.user_agent as device,
                    p.email as participant_email,
                    CONCAT(COALESCE(p.first_name, ''), ' ', COALESCE(p.last_name, '')) as participant_name,
                    s.study_name as study,
                    al.metadata
                  FROM {$table_name} al
                  LEFT JOIN {$participants_table} p ON al.participant_id = p.id
                  LEFT JOIN {$studies_table} s ON al.study_id = s.id
                  WHERE {$where_clause}
                  ORDER BY al.created_at DESC";

        if (!empty($params)) {
            $query = $wpdb->prepare($query, $params);
        }

        $logs = $wpdb->get_results($query);
if($wpdb->last_error){throw new RuntimeException('No se pudieron consultar los logs de acceso.');}
return $logs;
}
}
