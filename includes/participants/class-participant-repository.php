<?php
/** M2 owner extracted from existing implementation; legacy APIs remain facades. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Participant_Repository {

    public static function get_by_email($survey_id, $email) {
        global $wpdb;

        // Sanitizar email
        $email = sanitize_email($email);

        $table_name = $wpdb->prefix . 'survey_participants';
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE survey_id = %d AND email = %s",
            $survey_id,
            $email
        ));
    }

    public static function get_by_id($participant_id) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'survey_participants';
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d",
            $participant_id
        ));
    }

    public static function update_last_login($participant_id) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'survey_participants';
        $result = $wpdb->update(
            $table_name,
            array('last_login_at' => current_time('mysql')),
            array('id' => $participant_id),
            array('%s'),
            array('%d')
        );

        return $result !== false;
    }

    public static function list_participants($survey_id, $page = 1, $per_page = 50, $filters = array()) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'survey_participants';
        $offset = ($page - 1) * $per_page;

        // Construir WHERE clause
        $where_conditions = array('survey_id = %d');
        $where_values = array($survey_id);

        // Filtro por status
        if (isset($filters['status'])) {
            if ($filters['status'] === 'active') {
                $where_conditions[] = 'is_active = 1';
            } elseif ($filters['status'] === 'inactive') {
                $where_conditions[] = 'is_active = 0';
            }
        }

        // Filtro por búsqueda
        if (isset($filters['search']) && !empty($filters['search'])) {
            $where_conditions[] = 'email LIKE %s';
            $where_values[] = '%' . $wpdb->esc_like($filters['search']) . '%';
        }

        $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);

        // Query total (sin LIMIT)
        $total_query = "SELECT COUNT(*) FROM $table_name $where_clause";
        $total = (int) $wpdb->get_var($wpdb->prepare($total_query, $where_values));

        // Query paginada
        $query = "SELECT * FROM $table_name $where_clause ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $where_values[] = $per_page;
        $where_values[] = $offset;

        $participants = $wpdb->get_results($wpdb->prepare($query, $where_values));

        return array(
            'total' => $total,
            'participants' => $participants,
            'page' => (int) $page,
            'per_page' => (int) $per_page,
            'pages' => ceil($total / $per_page)
        );
    }
    public static function update($data, $where, $formats = null, $where_formats = null) {
        global $wpdb;
        return $wpdb->update($wpdb->prefix . 'survey_participants', $data, $where, $formats, $where_formats);
    }
    public static function insert($data, $formats = null) {
        global $wpdb;
        return $wpdb->insert($wpdb->prefix . 'survey_participants', $data, $formats);
    }
    public static function registration_study($survey_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT id, config FROM {$wpdb->prefix}survey_studies WHERE id = %d", $survey_id));
    }

    public static function get_id_by_email($survey_id, $email) {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}survey_participants WHERE survey_id = %d AND email = %s", $survey_id, $email));
    }
    public static function study_by_code($study_code) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT id FROM {$wpdb->prefix}survey_studies WHERE study_code = %s", $study_code));
    }

    public static function get_id_in_study($participant_id, $study_id) {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}survey_participants WHERE id = %d AND survey_id = %d LIMIT 1",
            $participant_id, $study_id
        ));
    }
}
