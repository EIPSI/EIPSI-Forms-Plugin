<?php
/**
 * EIPSI_Wave_Service
 *
 * Gestiona waves (oleadas) en estudios longitudinales:
 * - CRUD operations
 * - Due date calculation
 * - Completion tracking
 * - Stats
 *
 * @package EIPSI_Forms
 * @subpackage Services
 * @version 1.4.2
 * @since 1.4.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class EIPSI_Wave_Service {

    /**
     * Create wave for survey.
     *
     * @param int   $study_id ID del estudio.
     * @param array $wave_data Datos de la wave.
     * @return int|WP_Error Wave ID insertado o WP_Error.
     * @since 1.4.0
     * @access public
     */
    public static function create_wave($study_id, $wave_data) {
        global $wpdb;

        $study_id = absint($study_id);
        if (!$study_id) {
            return new WP_Error('invalid_study_id', 'Invalid study_id');
        }

        $name = isset($wave_data['name']) ? sanitize_text_field($wave_data['name']) : '';
        $form_id = isset($wave_data['form_id']) ? absint($wave_data['form_id']) : 0;

        if (empty($name) || empty($form_id)) {
            return new WP_Error('missing_required_fields', 'Name and form_id required');
        }

        $wave_index = isset($wave_data['wave_index']) ? absint($wave_data['wave_index']) : 1;
        if ($wave_index < 1 || $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}survey_waves WHERE study_id = %d AND wave_index = %d", $study_id, $wave_index
        ))) { return new WP_Error('invalid_wave_index', 'Índice inválido o ya utilizado en el estudio.'); }
        foreach (array('start_date', 'due_date') as $field) {
            if (empty($wave_data[$field])) { continue; }
            $date = DateTime::createFromFormat('!Y-m-d\TH:i', $wave_data[$field]) ?: DateTime::createFromFormat('!Y-m-d H:i:s', $wave_data[$field]);
            $errors = DateTime::getLastErrors();
            if (!$date || ($errors && ($errors['warning_count'] || $errors['error_count']))) { return new WP_Error('invalid_date', 'Fecha inválida: ' . $field); }
            $wave_data[$field] = $date->format('Y-m-d H:i:s');
        }
        $reminder_days = isset($wave_data['reminder_days']) ? absint($wave_data['reminder_days']) : 3;
        $retry_enabled = isset($wave_data['retry_enabled']) ? (int) (bool) $wave_data['retry_enabled'] : 1;
        $retry_days = isset($wave_data['retry_days']) ? absint($wave_data['retry_days']) : 7;
        $max_retries = isset($wave_data['max_retries']) ? absint($wave_data['max_retries']) : 3;
        $is_mandatory = isset($wave_data['is_mandatory']) ? (int) (bool) $wave_data['is_mandatory'] : 1;

        // T1-Anchor: offset_minutes (minutes after T1 completion when wave becomes available)
        // For T1 (wave_index = 1), this should be 0
        $offset_minutes = isset($wave_data['offset_minutes']) ? absint($wave_data['offset_minutes']) : 0;
        
        // DEPRECATED: interval_days and time_unit (kept for backward compatibility)
        // These are now calculated from offset_minutes if not provided
        $interval_days = isset($wave_data['interval_days']) ? absint($wave_data['interval_days']) : round($offset_minutes / 1440);
        $time_unit = isset($wave_data['time_unit']) ? $wave_data['time_unit'] : 'days';
        $window_minutes = isset($wave_data['window_minutes']) && $wave_data['window_minutes'] !== '' 
            ? absint($wave_data['window_minutes']) 
            : null;

        $allowed_statuses = array('draft', 'active', 'completed', 'paused');
        $status = isset($wave_data['status']) ? sanitize_text_field($wave_data['status']) : 'draft';
        if (!in_array($status, $allowed_statuses, true)) {
            $status = 'draft';
        }

        // Build data array with base fields (without optional fields)
        $data = array(
            'study_id' => $study_id,
            'wave_index' => $wave_index,
            'name' => $name,
            'form_id' => $form_id,
            'interval_days' => $interval_days,
            'offset_minutes' => $offset_minutes,
            'time_unit' => $time_unit,
            'reminder_days' => $reminder_days,
            'retry_enabled' => $retry_enabled,
            'retry_days' => $retry_days,
            'max_retries' => $max_retries,
            'status' => $status,
            'is_mandatory' => $is_mandatory,
            'follow_up_reminders_enabled' => 1, // v2.5.0 - Nudges activados por defecto
        );

        // Formats for base fields (without window_minutes, nudge_config, start_date, due_date)
        $formats = array('%d', '%d', '%s', '%d', '%d', '%d', '%s', '%d', '%d', '%d', '%d', '%s', '%d', '%d');

        // Add optional fields in order
        
        // 1. window_minutes (optional, can be NULL)
        if ($window_minutes !== null) {
            $data['window_minutes'] = $window_minutes;
            $formats[] = '%d';
        }

        // 2. nudge_config (Phase 5 T1-Anchor: JSON string with proportional nudges)
        if (isset($wave_data['nudge_config']) && !empty($wave_data['nudge_config'])) {
            $data['nudge_config'] = $wave_data['nudge_config']; // Already JSON encoded
            $formats[] = '%s';
            error_log(sprintf('[EIPSI WAVE SERVICE] Wave "%s" (index=%d): nudge_config provided, length=%d bytes', 
                $name, $wave_index, strlen($wave_data['nudge_config'])));
        } else {
            error_log(sprintf('[EIPSI WAVE SERVICE] Wave "%s" (index=%d): NO nudge_config provided', 
                $name, $wave_index));
        }

        if (!empty($wave_data['start_date'])) {
            $data['start_date'] = sanitize_text_field($wave_data['start_date']);
            $formats[] = '%s';
        }

        if (!empty($wave_data['due_date'])) {
            $data['due_date'] = sanitize_text_field($wave_data['due_date']);
            $formats[] = '%s';
        }

        foreach (array('has_time_limit', 'completion_time_limit') as $field) {
            if (array_key_exists($field, $wave_data)) {
                $data[$field] = $wave_data[$field] === null ? null : absint($wave_data[$field]);
                $formats[] = '%d';
            }
        }

        error_log(sprintf('[EIPSI WAVE SERVICE] Inserting wave: study_id=%d, wave_index=%d, name="%s", offset_minutes=%d, window_minutes=%s', 
            $study_id, $wave_index, $name, $offset_minutes, $window_minutes !== null ? $window_minutes : 'NULL'));

        $result = $wpdb->insert(
            $wpdb->prefix . 'survey_waves',
            $data,
            $formats
        );

        if ($result === false) {
            error_log(sprintf('[EIPSI WAVE SERVICE] ERROR inserting wave: %s', $wpdb->last_error));
            return new WP_Error('db_error', 'Failed to create wave: ' . $wpdb->last_error);
        }

        $wave_id = (int) $wpdb->insert_id;
        error_log(sprintf('[EIPSI WAVE SERVICE] Wave created successfully: id=%d, name="%s", offset=%d min', 
            $wave_id, $name, $offset_minutes));

        return $wave_id;
    }

    /**
     * Get wave by ID.
     *
     * @param int $wave_id Wave ID.
     * @return object|null Wave data.
     * @since 1.4.0
     * @access public
     */
    public static function get_wave($wave_id) {
        global $wpdb;

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}survey_waves WHERE id = %d",
                absint($wave_id)
            ),
            OBJECT
        );
    }

    /**
     * Get study waves.
     *
     * @param int         $study_id Study ID.
     * @param string|null $status Status filter.
     * @return array Waves list.
     * @since 1.4.0
     * @access public
     */
    public static function get_study_waves($study_id, $status = null) {
        global $wpdb;

        $query = "SELECT * FROM {$wpdb->prefix}survey_waves WHERE study_id = %d";
        $params = array(absint($study_id));

        if (!empty($status)) {
            $query .= ' AND status = %s';
            $params[] = sanitize_text_field($status);
        }

        $query .= ' ORDER BY wave_index ASC';

        return $wpdb->get_results(
            $wpdb->prepare($query, $params),
            ARRAY_A
        );
    }

    /**
     * Update wave.
     *
     * @param int   $wave_id Wave ID.
     * @param array $wave_data Datos a actualizar.
     * @return bool|WP_Error True si actualiza o WP_Error si falla.
     * @since 1.4.0
     * @access public
     */
    public static function update_wave($wave_id, $wave_data) {
        global $wpdb;

        $wave_id = absint($wave_id);
        if (!$wave_id) {
            return new WP_Error('invalid_wave_id', 'Invalid wave_id');
        }

        $existing = self::get_wave($wave_id);
        if (!$existing) { return new WP_Error('wave_not_found', 'Wave not found'); }
        if (isset($wave_data['wave_index']) && (int) $wave_data['wave_index'] !== (int) $existing->wave_index) {
            return new WP_Error('read_only_index', 'El índice de una onda existente es de solo lectura.');
        }
        $has_assignments = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}survey_assignments WHERE wave_id = %d", $wave_id
        ));
        foreach (array('form_id', 'start_date', 'due_date') as $field) {
            if (!array_key_exists($field, $wave_data)) { continue; }
            $value = $wave_data[$field];
            if ($field !== 'form_id') {
                if ($value === '' || $value === null) { $value = null; }
                else {
                    $date = DateTime::createFromFormat('!Y-m-d\TH:i', $value) ?: DateTime::createFromFormat('!Y-m-d H:i:s', $value);
                    $errors = DateTime::getLastErrors();
                    if (!$date || ($errors && ($errors['warning_count'] || $errors['error_count']))) {
                        return new WP_Error('invalid_date', 'Fecha inválida: ' . $field);
                    }
                    $value = $date->format('Y-m-d H:i:s');
                }
                $wave_data[$field] = $value;
            }
            if ($has_assignments && (string) $value !== (string) $existing->$field) {
                return new WP_Error('assigned_wave_read_only', 'Formulario y fechas no se editan aquí después de asignar participantes.');
            }
        }

        $allowed_fields = array(
            'name',
            'form_id',
            'start_date',
            'due_date',
            'offset_minutes',
            'window_minutes',
            'reminder_days',
            'retry_enabled',
            'retry_days',
            'max_retries',
            'status',
            'is_mandatory',
            'has_time_limit',
            'completion_time_limit',
        );

        $data = array();
        $formats = array();

        foreach ((array) $wave_data as $key => $value) {
            if (!in_array($key, $allowed_fields, true)) {
                continue;
            }

            switch ($key) {
                case 'name':
                    $data[$key] = sanitize_text_field($value);
                    $formats[] = '%s';
                    break;
                case 'form_id':
                case 'reminder_days':
                case 'retry_days':
                case 'max_retries':
                case 'offset_minutes':
                    $data[$key] = absint($value);
                    $formats[] = '%d';
                    break;
                case 'window_minutes':
                    // window_minutes can be NULL (uses next wave's offset as deadline)
                    $data[$key] = ($value === null || $value === '') ? null : absint($value);
                    $formats[] = ($value === null || $value === '') ? null : '%d';
                    break;
                case 'retry_enabled':
                case 'is_mandatory':
                case 'has_time_limit':
                    $data[$key] = (int) (bool) $value;
                    $formats[] = '%d';
                    break;
                case 'completion_time_limit':
                    $data[$key] = $value === null ? null : absint($value);
                    $formats[] = $value === null ? null : '%d';
                    break;
                case 'status':
                    $allowed_statuses = array('draft', 'active', 'completed', 'paused');
                    $value = sanitize_text_field($value);
                    if (!in_array($value, $allowed_statuses, true)) {
                        return new WP_Error('invalid_status', 'Invalid status');
                    }
                    $data[$key] = $value;
                    $formats[] = '%s';
                    break;
                case 'start_date':
                case 'due_date':
                    $data[$key] = ($value === null || $value === '') ? null : sanitize_text_field($value);
                    $formats[] = '%s';
                    break;
            }
        }

        if (empty($data)) {
            return false;
        }

        $updated = $wpdb->update(
            $wpdb->prefix . 'survey_waves',
            $data,
            array('id' => $wave_id),
            $formats,
            array('%d')
        );

        if ($updated === false) {
            return new WP_Error('db_error', 'Failed to update wave: ' . $wpdb->last_error);
        }

        return true;
    }

    /**
     * Get wave completion stats.
     *
     * @param int $wave_id Wave ID.
     * @return array Stats array.
     * @since 1.4.2
     * @access public
     */
    public static function get_wave_stats($wave_id) {
        global $wpdb;

        $stats = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'submitted' THEN 1 ELSE 0 END) as submitted,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
                    SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) as expired
                FROM {$wpdb->prefix}survey_assignments
                WHERE wave_id = %d",
                absint($wave_id)
            ),
            ARRAY_A
        );

        if (!is_array($stats)) {
            return array(
                'total' => 0,
                'submitted' => 0,
                'pending' => 0,
                'in_progress' => 0,
                'expired' => 0,
            );
        }

        foreach ($stats as $k => $v) {
            $stats[$k] = (int) $v;
        }

        return $stats;
    }

    /**
     * Delete wave with validation.
     *
     * No permite borrar una wave con asignaciones ya submitted.
     *
     * @param int $wave_id Wave ID.
     * @return bool|WP_Error True si elimina o WP_Error si falla.
     * @since 1.4.0
     * @access public
     */
    public static function delete_wave($wave_id) {
        global $wpdb;

        $wave_id = absint($wave_id);
        if (!$wave_id) {
            return new WP_Error('invalid_wave_id', 'Invalid wave_id');
        }

        $has_responses = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}survey_assignments
                 WHERE wave_id = %d AND status = 'submitted'",
                $wave_id
            )
        );

        if ($has_responses > 0) {
            return new WP_Error('has_responses', 'Cannot delete wave with responses');
        }

        $deleted = $wpdb->delete(
            $wpdb->prefix . 'survey_waves',
            array('id' => $wave_id),
            array('%d')
        );

        if ($deleted === false) {
            return new WP_Error('db_error', 'Failed to delete wave: ' . $wpdb->last_error);
        }

        return true;
    }

    /**
     * Calculate wave status based on dates.
     *
     * @param int|array $wave Wave ID or wave array.
     * @return string Status: upcoming, active, closed, overdue.
     * @since 1.7.1
     * @access public
     */
    public static function calculate_wave_status($wave) {
        global $wpdb;

        // Get wave object if ID passed
        if (is_numeric($wave)) {
            $wave = self::get_wave($wave);
        }

        if (!$wave) {
            return 'unknown';
        }

        // ✅ v1.5.6 - Manejar tanto objetos como arrays
        $start_date = null;
        $due_date = null;
        
        if (is_array($wave)) {
            $start_date = !empty($wave['start_date']) ? $wave['start_date'] : null;
            $due_date = !empty($wave['due_date']) ? $wave['due_date'] : null;
        } else if (is_object($wave)) {
            $start_date = !empty($wave->start_date) ? $wave->start_date : null;
            $due_date = !empty($wave->due_date) ? $wave->due_date : null;
        }
        
        // Usar due_date como fallback para start_date
        $start_date = $start_date ?: $due_date;

        $now = current_time('mysql');

        // If no dates set, default to active
        if (empty($start_date) && empty($due_date)) {
            return 'active';
        }

        // upcoming: start_date > today (or no start_date but due_date > today)
        if (!empty($start_date) && strtotime($start_date) > strtotime($now)) {
            return 'upcoming';
        }

        // Obtener el ID del wave (manejar tanto array como objeto)
        $wave_id = is_array($wave) ? ($wave['id'] ?? 0) : ($wave->id ?? 0);

        // If we have due_date
        if (!empty($due_date)) {
            // active: start_date <= today <= due_date
            $start_ts = !empty($start_date) ? strtotime($start_date) : 0;
            $due_ts = strtotime($due_date);
            $now_ts = strtotime($now);

            if ($start_ts <= $now_ts && $now_ts <= $due_ts) {
                return 'active';
            }

            // overdue: due_date < today (participant started but didn't complete)
            // This requires checking if there are assignments in progress
            if ($now_ts > $due_ts) {
                $has_in_progress = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}survey_assignments
                     WHERE wave_id = %d AND status = 'in_progress'",
                    $wave_id
                ));

                if ($has_in_progress > 0) {
                    return 'overdue';
                }

                return 'closed';
            }
        }

        // Default if only start_date exists and we're past it
        if (!empty($start_date) && strtotime($start_date) <= strtotime($now)) {
            // Check if there are pending assignments
            $has_pending = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}survey_assignments
                 WHERE wave_id = %d AND status = 'pending'",
                $wave_id
            ));

            if ($has_pending > 0) {
                return 'active';
            }
        }

        return 'active';
    }

    /**
     * Validate wave dates before saving.
     *
     * @param array $wave_data Wave data to validate.
     * @param int   $study_id Study ID.
     * @param int   $exclude_wave_id Wave ID to exclude (for updates).
     * @return array {valid: bool, warnings: array, errors: array}
     * @since 1.7.1
     * @access public
     */
    public static function validate_wave_dates($wave_data, $study_id, $exclude_wave_id = 0) {
        global $wpdb;
        
        $warnings = array();
        $errors = array();

        $start_date = isset($wave_data['start_date']) ? $wave_data['start_date'] : null;
        $due_date = isset($wave_data['due_date']) ? $wave_data['due_date'] : null;
        $wave_index = isset($wave_data['wave_index']) ? absint($wave_data['wave_index']) : 1;
        $is_new = empty($exclude_wave_id);

        // Validate: Due date > start date
        if (!empty($start_date) && !empty($due_date)) {
            if (strtotime($due_date) <= strtotime($start_date)) {
                $errors[] = __('La fecha de vencimiento debe ser posterior a la fecha de inicio.', 'eipsi-forms');
            }
        }

        // Validate: Start date not in past for new waves
        if ($is_new && !empty($start_date)) {
            $now = current_time('mysql');
            if (strtotime($start_date) < strtotime($now . ' -1 day')) {
                $warnings[] = __('La fecha de inicio está en el pasado. Los participantes no podrán acceder hasta esa fecha.', 'eipsi-forms');
            }
        }

        // Validate: Wave N+1 start date > Wave N end date
        if (!empty($wave_index)) {
            // Get previous wave
            $previous_wave = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}survey_waves
                 WHERE study_id = %d AND wave_index < %d
                 ORDER BY wave_index DESC LIMIT 1",
                $study_id,
                $wave_index
            ));

            // Get next wave
            $next_wave = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}survey_waves
                 WHERE study_id = %d AND wave_index > %d AND id != %d
                 ORDER BY wave_index ASC LIMIT 1",
                $study_id,
                $wave_index,
                $exclude_wave_id
            ));

            // Check: This wave start should be > previous wave end (due_date)
            if ($previous_wave && !empty($previous_wave->due_date)) {
                if (!empty($start_date) && strtotime($start_date) <= strtotime($previous_wave->due_date)) {
                    $warnings[] = sprintf(
                        __('La fecha de inicio de la Onda T%d debería ser posterior a la fecha de vencimiento de la Onda T%d (%s).', 'eipsi-forms'),
                        $wave_index,
                        $previous_wave->wave_index,
                        date_i18n(get_option('date_format'), strtotime($previous_wave->due_date))
                    );
                }
            }

            // Check: Next wave start should be > this wave end (due_date)
            if ($next_wave && !empty($next_wave->start_date)) {
                if (!empty($due_date) && strtotime($next_wave->start_date) <= strtotime($due_date)) {
                    $warnings[] = sprintf(
                        __('La fecha de inicio de la Onda T%d (%s) debería ser posterior a la fecha de vencimiento de esta onda (%s).', 'eipsi-forms'),
                        $next_wave->wave_index,
                        date_i18n(get_option('date_format'), strtotime($next_wave->start_date)),
                        !empty($due_date) ? date_i18n(get_option('date_format'), strtotime($due_date)) : 'no establecida'
                    );
                }
            }
        }

        return array(
            'valid' => empty($errors),
            'warnings' => $warnings,
            'errors' => $errors
        );
    }

    /**
     * Update wave status based on current dates.
     *
     * @param int $wave_id Wave ID.
     * @return bool True if updated.
     * @since 1.7.1
     * @access public
     */
    public static function update_wave_status($wave_id) {
        global $wpdb;

        $wave = self::get_wave($wave_id);
        if (!$wave) {
            return false;
        }

        $status = self::calculate_wave_status($wave);

        // Map our status to DB status
        $db_status = $status;
        if ($status === 'upcoming') {
            $db_status = 'draft';
        } elseif ($status === 'overdue') {
            $db_status = 'active';
        }

        $result = $wpdb->update(
            $wpdb->prefix . 'survey_waves',
            array('status' => $db_status),
            array('id' => $wave_id),
            array('%s'),
            array('%d')
        );

        return $result !== false;
    }

    /**
     * Update all wave statuses for a study via cron.
     *
     * @param int $study_id Study ID. If 0, update all.
     * @return array Results.
     * @since 1.7.1
     * @access public
     */
    public static function update_all_wave_statuses($study_id = 0) {
        global $wpdb;

        $query = "SELECT id FROM {$wpdb->prefix}survey_waves";
        $params = array();

        if ($study_id > 0) {
            $query .= " WHERE study_id = %d";
            $params[] = $study_id;
        }

        $waves = $wpdb->get_results($wpdb->prepare($query, $params));

        $updated = 0;
        $failed = 0;

        foreach ($waves as $wave) {
            if (self::update_wave_status($wave->id)) {
                $updated++;
            } else {
                $failed++;
            }
        }

        return array(
            'updated' => $updated,
            'failed' => $failed,
            'total' => count($waves)
        );
    }

    /**
     * Get next pending wave (assignment) for a participant.
     *
     * Returns the wave row (OBJECT) so callers can read ->id and other fields.
     *
     * @param int $participant_id
     * @param int $study_id
     * @return object|null
     */
    public static function get_next_pending_wave($participant_id, $study_id) {
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

    /**
     * Calculate wave availability based on T1 completion (Phase 5 T1-Anchor)
     * 
     * For T1 (wave_index = 1): available from participant registration
     * For T2+: available from T1 completion + offset_minutes
     * 
     * @param int $participant_id Participant ID
     * @param int $study_id Study ID
     * @param object $wave Wave object with wave_index and offset_minutes
     * @return string|null DateTime string or null if not available yet
     * @since 2.6.0
     */
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

    /**
     * Recalculate wave availability after T1 completion (Phase 5 T1-Anchor)
     * 
     * Called when a participant completes T1. Updates available_at for all
     * subsequent waves (T2, T3, T4...) and reschedules their nudges.
     * 
     * @param int $participant_id Participant ID
     * @param int $study_id Study ID
     * @return int Number of waves recalculated
     * @since 2.6.0
     */
    public static function recalculate_after_t1($participant_id, $study_id) {
        global $wpdb;
        
        error_log("[EIPSI Wave Service] Recalculating waves for participant {$participant_id} after T1 completion");
        
        $waves = self::get_study_waves($study_id);
        $recalculated = 0;
        
        foreach ($waves as $wave) {
            // Skip T1
            if ($wave['wave_index'] == 1) {
                continue;
            }
            
            // Calcular nueva disponibilidad
            $new_available_at = self::calculate_wave_availability($participant_id, $study_id, (object)$wave);
            
            if (!$new_available_at) {
                error_log("[EIPSI Wave Service] Could not calculate availability for wave {$wave['wave_index']}");
                continue;
            }
            
            // Actualizar assignment
            $updated = $wpdb->update(
                $wpdb->prefix . 'survey_assignments',
                array('available_at' => $new_available_at),
                array(
                    'participant_id' => $participant_id,
                    'wave_id' => $wave['id']
                ),
                array('%s'),
                array('%d', '%d')
            );
            
            if ($updated === false) {
                error_log("[EIPSI Wave Service] Failed to update assignment for wave {$wave['wave_index']}: " . $wpdb->last_error);
                continue;
            }
            
            // Obtener assignment_id para re-programar nudges
            $assignment_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}survey_assignments 
                 WHERE participant_id = %d AND wave_id = %d",
                $participant_id, $wave['id']
            ));
            
            if ($assignment_id) {
                // Re-programar nudges (requiere EIPSI_Nudge_Event_Scheduler)
                if (class_exists('EIPSI_Nudge_Event_Scheduler')) {
                    EIPSI_Nudge_Event_Scheduler::reschedule_nudges_for_assignment($assignment_id);
                    error_log("[EIPSI Wave Service] Rescheduled nudges for assignment {$assignment_id} (wave {$wave['wave_index']})");
                }
                
                $recalculated++;
            }
        }
        
        error_log("[EIPSI Wave Service] Recalculated {$recalculated} waves for participant {$participant_id}");
        
        return $recalculated;
    }
}
