<?php
/** M4 longitudinal owner. Existing public adapters preserve their contracts. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Longitudinal_Assignment_Repository {
public static function create_assignment($study_id, $wave_id, $participant_id) {
        global $wpdb;

        $study_id = absint($study_id);
        $wave_id = absint($wave_id);
        $participant_id = absint($participant_id);

        if (!$study_id || !$wave_id || !$participant_id) {
            return new WP_Error('invalid_params', 'study_id, wave_id and participant_id are required');
        }

        // Validar que la wave exista (y pertenezca al estudio)
        $wave_row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, study_id FROM {$wpdb->prefix}survey_waves WHERE id = %d",
                $wave_id
            ),
            ARRAY_A
        );

        if (empty($wave_row)) {
            return new WP_Error('wave_not_found', 'Wave not found');
        }

        if (!empty($wave_row['study_id']) && (int) $wave_row['study_id'] !== $study_id) {
            return new WP_Error('study_mismatch', 'Wave does not belong to the provided study_id');
        }

        // Validar que el participante exista
        $participant_exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}survey_participants WHERE id = %d",
                $participant_id
            )
        );

        if (!$participant_exists) {
            return new WP_Error('participant_not_found', 'Participant not found');
        }

        // Insertar asignación
        $result = $wpdb->insert(
            $wpdb->prefix . 'survey_assignments',
            array(
                'study_id' => $study_id,
                'wave_id' => $wave_id,
                'participant_id' => $participant_id,
                'status' => 'pending',
            ),
            array('%d', '%d', '%d', '%s')
        );

        if ($result === false) {
            // Si es duplicate, ok (idempotente)
            if (strpos($wpdb->last_error, 'Duplicate entry') !== false) {
                $existing = self::get_assignment($wave_id, $participant_id);
                if ($existing) {
                    return $existing;
                }
            }

            return new WP_Error('db_error', 'Failed to create assignment: ' . $wpdb->last_error);
        }

        return (int) $wpdb->insert_id;
    }

public static function get_assignment($wave_id, $participant_id) {
        global $wpdb;

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}survey_assignments WHERE wave_id = %d AND participant_id = %d",
                absint($wave_id),
                absint($participant_id)
            ),
            ARRAY_A
        );
    }

public static function get_participant_assignments($participant_id, $study_id = null) {
        global $wpdb;

        $query = "SELECT a.*, w.name, w.due_date, w.wave_index
                  FROM {$wpdb->prefix}survey_assignments a
                  JOIN {$wpdb->prefix}survey_waves w ON a.wave_id = w.id
                  WHERE a.participant_id = %d";

        $params = array(absint($participant_id));

        if (!is_null($study_id)) {
            $query .= ' AND a.study_id = %d';
            $params[] = absint($study_id);
        }

        $query .= ' ORDER BY w.wave_index ASC';

        return $wpdb->get_results(
            $wpdb->prepare($query, $params),
            ARRAY_A
        );
    }

public static function get_at_risk_participants($study_id = 0, $days_overdue = 7) {
        global $wpdb;

        $assignments_table = $wpdb->prefix . 'survey_assignments';
        $participants_table = $wpdb->prefix . 'survey_participants';
        $waves_table = $wpdb->prefix . 'survey_waves';

        $where_clause = "WHERE a.status = 'pending' AND a.due_at < DATE_SUB(NOW(), INTERVAL %d DAY)";
        $params = array((int) $days_overdue);

        if ($study_id > 0) {
            $where_clause .= " AND a.study_id = %d";
            $params[] = (int) $study_id;
        }

        // Query: participante + wave + assignment info
        $query = "SELECT a.id as assignment_id,
                         a.wave_id,
                         a.participant_id,
                         a.due_at,
                         p.first_name,
                         p.last_name,
                         p.email,
                         p.is_active,
                         w.name as wave_name,
                         w.wave_index,
                         COALESCE(MAX(s.created_at), a.due_at) as last_activity_at
                  FROM {$assignments_table} a
                  JOIN {$participants_table} p ON a.participant_id = p.id
                  JOIN {$waves_table} w ON a.wave_id = w.id
                  LEFT JOIN {$wpdb->prefix}survey_sessions s ON s.participant_id = p.id
                  {$where_clause}
                  GROUP BY a.id, a.wave_id, a.participant_id, a.due_at, p.first_name, p.last_name, p.email, p.is_active, w.name, w.wave_index
                  ORDER BY a.due_at ASC";

        return $wpdb->get_results($wpdb->prepare($query, $params));
    }

public static function get_dropout_stats($study_id = 0, $days_overdue = 7) {
        global $wpdb;

        $assignments_table = $wpdb->prefix . 'survey_assignments';
        $where = $study_id > 0 ? "WHERE study_id = %d" : "";
        $params = $study_id > 0 ? array((int) $study_id) : array();

        // At risk (pending + overdue)
        $at_risk_where = $where ? $where . " AND " : "WHERE ";
        $at_risk_where .= "status = 'pending' AND due_at < DATE_SUB(NOW(), INTERVAL %d DAY)";
        $params = array_merge($params, array((int) $days_overdue));

        // v2.5.3 - Fix: Usar spread operator para pasar parámetros a prepare()
        $at_risk = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$assignments_table} {$at_risk_where}", ...array_values($params))
        );

        // Pending total
        $pending_where = $where ? $where . " AND " : "WHERE ";
        $pending_where .= "status = 'pending'";
        $pending_params = $study_id > 0 ? array((int) $study_id) : array();

        // v2.5.3 - Fix: Usar spread operator para pasar parámetros a prepare()
        $pending = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$assignments_table} {$pending_where}", ...array_values($pending_params))
        );

        // Reminders sent today
        $email_log_table = $wpdb->prefix . 'survey_email_log';
        $email_where = $where ? str_replace('study_id', 'survey_id', $where) . " AND " : "WHERE ";
        $email_where .= "DATE(sent_at) = CURDATE() AND email_type IN ('reminder', 'recovery')";
        $email_params = $study_id > 0 ? array((int) $study_id) : array();

        // v2.5.3 - Fix: Usar spread operator para pasar parámetros a prepare()
        $reminders_today = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$email_log_table} {$email_where}", ...array_values($email_params))
        );

        return array(
            'at_risk' => $at_risk,
            'pending' => $pending,
            'reminders_today' => $reminders_today
        );
    }

public static function get_assignment_by_id($assignment_id) {
        global $wpdb;

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}survey_assignments WHERE id = %d",
                absint($assignment_id)
            ),
            ARRAY_A
        );
    }

public static function get_next_pending_wave($participant_id, $study_id) {
        global $wpdb;

        if (!$participant_id || !$study_id) {
            return null;
        }

        // Query para obtener la próxima toma pendiente (status='pending')
        $table = $wpdb->prefix . 'survey_assignments';

        $next_wave = $wpdb->get_row($wpdb->prepare(
            "SELECT a.*, w.wave_index, w.due_date, w.name as wave_name
             FROM {$table} a
             INNER JOIN {$wpdb->prefix}survey_waves w ON a.wave_id = w.id
             WHERE a.participant_id = %d
             AND a.study_id = %d
             AND a.status = 'pending'
             ORDER BY w.wave_index ASC
             LIMIT 1",
            $participant_id,
            $study_id
        ), ARRAY_A);

        if (!$next_wave) {
            return null;
        }

        return array(
            'wave_id' => (int) $next_wave['wave_id'],
            'wave_index' => (int) $next_wave['wave_index'],
            'due_date' => $next_wave['due_date'],
            'wave_name' => $next_wave['wave_name'] ?? sprintf('Toma %d', $next_wave['wave_index']),
            'study_id' => (int) $study_id
        );
    }

public static function get_participant_waves($participant_id, $study_id) {
        global $wpdb;

        if (!$participant_id || !$study_id) {
            return array();
        }

        $table = $wpdb->prefix . 'survey_assignments';

        $waves = $wpdb->get_results($wpdb->prepare(
            "SELECT a.*, w.wave_index, w.due_date, w.name as wave_name
             FROM {$table} a
             INNER JOIN {$wpdb->prefix}survey_waves w ON a.wave_id = w.id
             WHERE a.participant_id = %d
             AND a.study_id = %d
             ORDER BY w.wave_index ASC",
            $participant_id,
            $study_id
        ), ARRAY_A);

        return $waves;
    }

public static function assignment_exists($participant_id, $study_id, $wave_id) {
        global $wpdb;

        $table = $wpdb->prefix . 'survey_assignments';

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE participant_id = %d
             AND study_id = %d
             AND wave_id = %d",
            $participant_id,
            $study_id,
            $wave_id
        ));

        return (bool) $exists;
    }

public static function get_assignment_status($participant_id, $study_id, $wave_id) {
        global $wpdb;

        $table = $wpdb->prefix . 'survey_assignments';

        $status = $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$table}
             WHERE participant_id = %d
             AND study_id = %d
             AND wave_id = %d",
            $participant_id,
            $study_id,
            $wave_id
        ));

        return $status;
    }

public static function get_next_pending_wave_object($participant_id, $study_id) {
        global $wpdb;

        $participant_id = absint($participant_id);
        $study_id = absint($study_id);

        if (!$participant_id || !$study_id) {
            return null;
        }

        $sql = "SELECT w.*
                FROM {$wpdb->prefix}survey_assignments a
                INNER JOIN {$wpdb->prefix}survey_waves w ON a.wave_id = w.id
                WHERE a.participant_id = %d
                  AND a.study_id = %d
                  AND a.status = 'pending'
                ORDER BY w.wave_index ASC
                LIMIT 1";

        return $wpdb->get_row($wpdb->prepare($sql, $participant_id, $study_id), OBJECT);
    }

public static function get_newly_available($now) {
    global $wpdb;
    $assignments_table=$wpdb->prefix.'survey_assignments';
    return $wpdb->get_results($wpdb->prepare(
        "SELECT a.id, a.participant_id, a.wave_id, a.study_id, a.available_at,
                w.wave_index, w.name as wave_name
         FROM {$assignments_table} a
         JOIN {$wpdb->prefix}survey_waves w ON a.wave_id = w.id
         WHERE a.available_at IS NOT NULL
         AND a.available_at <= %s
         AND a.status = 'pending'
         AND a.wave_id IN (
             SELECT id FROM {$wpdb->prefix}survey_waves WHERE wave_index > 1
         )
         LIMIT 100",
        $now
    ));
}
}
