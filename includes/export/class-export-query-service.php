<?php
/** M6 owner; existing public adapters retain their contracts. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Export_Query_Service {

public function export_longitudinal_data($survey_id, $filters = array()) {
        $default_filters = array(
            'wave_index'          => null,
            'date_from'           => null,
            'date_to'             => null,
            'status'              => 'all',
            'include_fingerprint' => true,
        );

        $filters = array_merge($default_filters, $filters);
        return $this->fetch_longitudinal_data($survey_id, $filters);
    }

private function fetch_longitudinal_data($survey_id, $filters) {
        global $wpdb;

        $query = "
            SELECT
                sp.id as participant_id,
                sp.consent_decision,
                sw.wave_index,
                sr.id as submission_id,
                sr.submitted_at,
                TIMESTAMPDIFF(SECOND, sr.created_at, sr.submitted_at) as response_time_seconds,
                sr.form_responses as response_data,
                sr.user_fingerprint,
                CASE
                    WHEN sw.due_date < sr.submitted_at THEN 'Late'
                    WHEN sr.submitted_at IS NOT NULL THEN 'Completed'
                    ELSE 'Pending'
                END as status,
                sa.assigned_at as wave_assigned_at
            FROM {$wpdb->prefix}survey_participants sp
            JOIN {$wpdb->prefix}survey_waves sw ON sp.survey_id = sw.study_id
            LEFT JOIN {$wpdb->prefix}vas_form_results sr ON CAST(sp.id AS CHAR) = sr.participant_id AND sp.survey_id = sr.survey_id AND sw.wave_index = sr.wave_index
            LEFT JOIN {$wpdb->prefix}survey_assignments sa ON sp.id = sa.participant_id AND sw.id = sa.wave_id
            WHERE sp.survey_id = %d
        ";

        $params = array($survey_id);

        if (!empty($filters['wave_index']) && $filters['wave_index'] !== 'all') {
            $query   .= ' AND sw.wave_index = %s';
            $params[] = $filters['wave_index'];
        }

        if (!empty($filters['date_from'])) {
            $query   .= ' AND sr.submitted_at >= %s';
            $params[] = $filters['date_from'] . ' 00:00:00';
        }

        if (!empty($filters['date_to'])) {
            $query   .= ' AND sr.submitted_at <= %s';
            $params[] = $filters['date_to'] . ' 23:59:59';
        }

        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $status   = ucfirst(sanitize_text_field($filters['status']));
            $query   .= " AND
                CASE
                    WHEN sw.due_date < sr.submitted_at THEN 'Late'
                    WHEN sr.submitted_at IS NOT NULL THEN 'Completed'
                    ELSE 'Pending'
                END = %s";
            $params[] = $status;
        }

        $query .= ' ORDER BY sp.id, sw.wave_index';

        $rows = $wpdb->get_results($wpdb->prepare($query, $params));
        if ($wpdb->last_error) { throw new RuntimeException('No se pudo consultar el export longitudinal.'); }
        return $rows;
    }

public function fetch_participants_data($study_id, $filters = array()) {
    global $wpdb;

    $defaults = array(
        'status'     => 'all',
        'wave_index' => 'all',
        'search'     => '',
        'date_from'  => null,
        'date_to'    => null,
    );
    $filters = array_merge($defaults, $filters);

    // --- Step 1: Get participants from study ---
    $participants = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, email, is_active, created_at, last_login_at, consent_decision, consent_context
             FROM {$wpdb->prefix}survey_participants
             WHERE survey_id = %d
             ORDER BY created_at DESC",
            $study_id
        )
    );

    if ($wpdb->last_error) { throw new RuntimeException('No se pudo consultar participantes.'); }
    if (empty($participants)) {
        return array('rows' => array(), 'waves' => array());
    }

    // --- Step 2: Get waves for this study ---
    $waves = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, wave_index, name, form_id
             FROM {$wpdb->prefix}survey_waves
             WHERE study_id = %d
             ORDER BY wave_index ASC",
            $study_id
        )
    );

    if ($wpdb->last_error) { throw new RuntimeException('No se pudo consultar waves para el export.'); }
    // Index waves by wave_index and by form_id
    $waves_by_index = array();
    $waves_by_form_id = array();
    $wave_id_to_index = array(); // ✅ Mapeo wave_id -> wave_index
    foreach ($waves as $w) {
        $waves_by_index[$w->wave_index] = $w;
        $waves_by_form_id[$w->form_id] = $w;
        $wave_id_to_index[$w->id] = $w->wave_index; // Mapear ID de tabla a índice
    }

    // --- Step 3: Get longitudinal submissions from vas_form_results ---
    $submissions = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT r.id, r.participant_id as longitudinal_participant_id, r.wave_index, r.form_id, r.form_responses,
                    r.submitted_at, r.duration_seconds, r.user_fingerprint,
                    r.device, r.browser, r.os, r.screen_width, r.ip_address,
                    r.participant_id as fingerprint_participant_id
             FROM {$wpdb->prefix}vas_form_results r
             WHERE r.survey_id = %d
             AND r.wave_index IS NOT NULL
             ORDER BY r.submitted_at ASC",
            $study_id
        )
    );

    if ($wpdb->last_error) { throw new RuntimeException('No se pudo consultar respuestas de participantes.'); }
    // Load privacy configs
    if (!function_exists('get_privacy_config')) {
        require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/privacy-config.php';
    }

    // v2.1.3 - Always load Device Data Service for extended metadata (even for wide exports)
    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-device-data-service.php';
    $submission_ids = array_column($submissions, 'id');
    error_log("[EIPSI-EXPORT-DIAG] fetch_participants_data: submissions_count=" . count($submissions) . ", submission_ids=" . implode(',', $submission_ids));
    $device_data_batch = !empty($submission_ids) 
        ? EIPSI_Device_Data_Service::get_device_data_batch($submission_ids) 
        : array();
    error_log("[EIPSI-EXPORT-DIAG] fetch_participants_data: device_data_batch count=" . count($device_data_batch) . ", keys=" . implode(',', array_keys($device_data_batch)));

    // Process submissions and organize by participant
    $submissions_by_participant = array();
    $privacy_configs = array();
    
    foreach ($submissions as $sub) {
        $longitudinal_participant_id = $sub->longitudinal_participant_id;
        $wave_index = $sub->wave_index;
        $form_id = $sub->form_id;
        $fingerprint_participant_id = $sub->fingerprint_participant_id;

        // Get privacy config for this form
        if (!isset($privacy_configs[$form_id])) {
            $privacy_configs[$form_id] = get_privacy_config($form_id);
        }
        $privacy = $privacy_configs[$form_id];

        // Decode form responses
        $decoded_responses = EIPSI_Export_File_Service::strip_credentials(json_decode($sub->form_responses, true));
        
        // Build submission data
        $submission_data = array(
            'longitudinal_participant_id' => $longitudinal_participant_id,
            'fingerprint_participant_id' => $fingerprint_participant_id,
            'form_responses' => is_array($decoded_responses) ? $decoded_responses : array(),
            'submitted_at' => $sub->submitted_at,
            'duration_seconds' => $sub->duration_seconds,
            'user_fingerprint' => $sub->user_fingerprint,
        );

        // Add privacy-controlled fields
        if (!empty($privacy['device_type'])) $submission_data['device'] = $sub->device;
        if (!empty($privacy['browser'])) $submission_data['browser'] = $sub->browser;
        if (!empty($privacy['os'])) $submission_data['os'] = $sub->os;
        if (!empty($privacy['screen_width'])) $submission_data['screen_width'] = $sub->screen_width;
        if (!empty($privacy['ip_address'])) $submission_data['ip_address'] = $sub->ip_address;

        // v2.1.3 - Add extended device metadata
        $device_data = $device_data_batch[$sub->id] ?? null;
        if ($device_data) {
            error_log("[EIPSI-EXPORT-DIAG] Processing device_data for submission_id={$sub->id}, device_data_type=" . gettype($device_data));
            if (is_object($device_data)) {
                error_log("[EIPSI-EXPORT-DIAG] Device data object properties: " . implode(',', array_keys(get_object_vars($device_data))));
            } elseif (is_array($device_data)) {
                error_log("[EIPSI-EXPORT-DIAG] Device data array keys: " . implode(',', array_keys($device_data)));
            }
            $submission_data['canvas_fingerprint'] = ($privacy['export_canvas_fingerprint'] ?? true) ? ($device_data->canvas_fingerprint ?? '') : '';
            $submission_data['webgl_renderer'] = ($privacy['export_webgl_renderer'] ?? true) ? ($device_data->webgl_renderer ?? '') : '';
            $submission_data['screen_resolution'] = ($privacy['export_screen_resolution'] ?? true) ? ($device_data->screen_resolution ?? '') : '';
            $submission_data['screen_depth'] = ($privacy['export_screen_depth'] ?? true) ? ($device_data->screen_depth ?? '') : '';
            $submission_data['pixel_ratio'] = ($privacy['export_pixel_ratio'] ?? true) ? ($device_data->pixel_ratio ?? '') : '';
            $submission_data['timezone'] = ($privacy['export_timezone'] ?? true) ? ($device_data->timezone ?? '') : '';
            $submission_data['language'] = ($privacy['export_language'] ?? true) ? ($device_data->language ?? '') : '';
            $submission_data['cpu_cores'] = ($privacy['export_cpu_cores'] ?? true) ? ($device_data->cpu_cores ?? '') : '';
            $submission_data['ram'] = ($privacy['export_ram'] ?? true) ? ($device_data->ram ?? '') : '';
            $submission_data['plugins'] = ($privacy['export_plugins'] ?? true) ? ($device_data->plugins ?? '') : '';
            $submission_data['touch_support'] = ($privacy['export_touch_support'] ?? true) ? ($device_data->touch_support ?? '') : '';
            $submission_data['cookies_enabled'] = ($privacy['export_cookies_enabled'] ?? true) ? ($device_data->cookies_enabled ?? '') : '';
            error_log("[EIPSI-EXPORT-DIAG] Set extended fields: canvas=" . substr($submission_data['canvas_fingerprint'], 0, 15) . ", webgl=" . substr($submission_data['webgl_renderer'], 0, 15) . ", ram=" . $submission_data['ram']);
        } else {
            error_log("[EIPSI-EXPORT-DIAG] NO device_data found for submission_id={$sub->id}");
        }

        // Organize by longitudinal participant ID (integer)
        if ($longitudinal_participant_id) {
            if (!isset($submissions_by_participant[$longitudinal_participant_id])) {
                $submissions_by_participant[$longitudinal_participant_id] = array();
            }
            $submissions_by_participant[$longitudinal_participant_id][$wave_index] = $submission_data;
        }
    }

    // --- Step 4: Match submissions with participants by email ---
    $participant_by_email = array();
    foreach ($participants as $p) {
        $participant_by_email[strtolower($p->email)] = $p;
    }

        // --- Step 5: Get assignments for progress tracking ---
        $participant_ids = array_column($participants, 'id');
        if (!empty($participant_ids)) {
            $ids_placeholder = implode(',', array_fill(0, count($participant_ids), '%d'));
            $assignments = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT participant_id, wave_id, status, submitted_at
                     FROM {$wpdb->prefix}survey_assignments
                     WHERE participant_id IN ({$ids_placeholder})
                     ORDER BY wave_id ASC",
                    $participant_ids
                )
            );
            if ($wpdb->last_error) { throw new RuntimeException('No se pudo consultar progreso para el export.'); }
        } else {
            $assignments = array();
        }

        // Group assignments by participant
        $assignments_by_participant = array();
        foreach ($assignments as $a) {
            $assignments_by_participant[$a->participant_id][] = $a;
        }

        // --- Step 6: Build result rows ---
        $rows = array();
        
        foreach ($participants as $participant) {
            $p_id = $participant->id;
            
            // Get assignments for this participant
            $p_assignments = isset($assignments_by_participant[$p_id]) ? $assignments_by_participant[$p_id] : array();
            
            // Build wave status map
            $wave_status_map = array();
            $submitted_count = 0;
            foreach ($p_assignments as $a) {
                // ✅ v1.5.6 - Usar mapeo wave_id -> wave_index
                if (isset($wave_id_to_index[$a->wave_id])) {
                    $wi = $wave_id_to_index[$a->wave_id];
                    $wave_status_map[$wi] = array(
                        'status' => $a->status,
                        'submitted_at' => $a->submitted_at,
                    );
                    if ($a->status === 'submitted') {
                        $submitted_count++;
                    }
                }
            }

            // Apply filters
            if ($filters['status'] === 'active' && !$participant->is_active) continue;
            if ($filters['status'] === 'inactive' && $participant->is_active) continue;
            
            if (!empty($filters['search'])) {
                $search = strtolower($filters['search']);
                if (strpos(strtolower($participant->email), $search) === false) continue;
            }

            if ($filters['wave_index'] !== 'all') {
                if (!isset($wave_status_map[$filters['wave_index']])) continue;
            }

            $total_waves = count($waves);
            $completion_percent = $total_waves > 0 ? round(($submitted_count / $total_waves) * 100) : 0;

            // Build participant row
            $row = array(
                'id' => (int) $participant->id,
                'email' => $participant->email,
                'is_active' => (bool) $participant->is_active,
                'created_at' => $participant->created_at,
                'last_login_at' => $participant->last_login_at,
                'consent_decision' => $participant->consent_decision,
                'consent_context' => $participant->consent_context,
                'waves_assigned' => count($p_assignments),
                'waves_submitted' => $submitted_count,
                'waves_total' => $total_waves,
                'completion_percent' => $completion_percent,
                'wave_statuses' => $wave_status_map,
                'submissions' => isset($submissions_by_participant[$p_id]) ? $submissions_by_participant[$p_id] : array(),
            );

            $rows[] = $row;
        }

        return array(
            'rows' => $rows,
            'waves' => $waves,
        );
    }

public function get_export_statistics($survey_id, $filters = array()) {
        global $wpdb;

        $stats = array(
            'total_participants'  => 0,
            'active_participants' => 0,
            'completed_all_waves' => 0,
            'completion_rates'    => array(),
            'avg_response_times'  => array(),
        );

        // Total + active participants
        $stats['total_participants'] = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}survey_participants WHERE survey_id = %d",
            $survey_id
        ));

        $stats['active_participants'] = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}survey_participants WHERE survey_id = %d AND is_active = 1",
            $survey_id
        ));

        // Waves for this study
        $waves = $wpdb->get_results($wpdb->prepare(
            "SELECT id, wave_index, name
             FROM {$wpdb->prefix}survey_waves
             WHERE study_id = %d
             ORDER BY wave_index ASC",
            $survey_id
        ));

        // Completion rates per wave
        foreach ($waves as $wave) {
            $completed = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT participant_id)
                 FROM {$wpdb->prefix}survey_assignments
                 WHERE wave_id = %d AND status = 'submitted'",
                $wave->id
            ));

            $total = $stats['total_participants'];
            $rate  = ($total > 0) ? round(($completed / $total) * 100, 1) : 0;

            $stats['completion_rates']['T' . $wave->wave_index] = array(
                'wave_name' => $wave->name,
                'completed' => $completed,
                'total'     => $total,
                'rate'      => $rate,
            );

            // Average response time (seconds)
            $avg_time = (float) $wpdb->get_var($wpdb->prepare(
                "SELECT AVG(TIMESTAMPDIFF(SECOND, created_at, submitted_at))
                 FROM {$wpdb->prefix}survey_assignments
                 WHERE wave_id = %d AND status = 'submitted' AND submitted_at IS NOT NULL",
                $wave->id
            ));

            $stats['avg_response_times']['T' . $wave->wave_index] = array(
                'seconds' => (int) $avg_time,
                'minutes' => round($avg_time / 60, 1),
            );
        }

        // Completed ALL waves
        $wave_count = count($waves);
        if ($wave_count > 0) {
            $stats['completed_all_waves'] = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT p.id)
                 FROM {$wpdb->prefix}survey_participants p
                 WHERE p.survey_id = %d AND p.is_active = 1
                 AND (
                     SELECT COUNT(DISTINCT a.wave_id)
                     FROM {$wpdb->prefix}survey_assignments a
                     WHERE a.participant_id = p.id AND a.status = 'submitted'
                 ) = %d",
                $survey_id,
                $wave_count
            ));
        }

        return $stats;
    }

public function get_available_surveys() {
        global $wpdb;

        return $wpdb->get_results(
            "SELECT id, title, description
             FROM {$wpdb->prefix}survey_surveys
             WHERE is_active = 1
             ORDER BY created_at DESC"
        );
    }

public function get_survey_waves($survey_id) {
        global $wpdb;

        $waves = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT wave_index
             FROM {$wpdb->prefix}survey_waves
             WHERE survey_id = %d
             ORDER BY wave_index",
            $survey_id
        ));

        return array_column($waves, 'wave_index');
    }
public static function eipsi_export_responses_with_pool_context($form_id, $study_id) {
    global $wpdb;
    
    if (!current_user_can('manage_options')) {
        wp_die(__('No tenés permisos.', 'eipsi-forms'));
    }
    
    $responses_table = $wpdb->prefix . 'vas_form_results'; // Tabla existente de respuestas
    $assignments_table = $wpdb->prefix . 'eipsi_pool_assignments';
    
    // Query con LEFT JOIN para obtener contexto de pool
    $sql = "
        SELECT 
            r.*,
            a.pool_id as pool_code,
            a.assigned_at as pool_assigned_at,
            pool.method as pool_assignment_method
        FROM {$responses_table} r
        LEFT JOIN {$assignments_table} a 
            ON r.participant_id = a.participant_id 
            AND a.study_id = %s
        LEFT JOIN {$wpdb->prefix}eipsi_longitudinal_pools pool ON pool.id = a.pool_id
        WHERE r.form_id = %s
        ORDER BY r.submitted_at DESC
    ";
    
    $results = $wpdb->get_results($wpdb->prepare($sql, $study_id, $form_id));
    if ($wpdb->last_error) { throw new RuntimeException('No se pudieron consultar las respuestas con contexto de pool.'); }
    
    error_log('[EIPSI-POOL] Export with context: ' . count($results) . ' responses for study ' . $study_id);
    
    return $results;
}
public static function raw_responses($form_id=null) {
        require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/database.php';
        global $wpdb;
        $table_name = $wpdb->prefix . 'vas_form_results';

        // Instanciar clase de BD externa
        $external_db = new EIPSI_External_Database();
        $results = array();
        $export_source = 'wordpress_db';

        if ($external_db->is_enabled()) {
            // Usar BD externa si está habilitada
            $mysqli = $external_db->get_connection();
            if ($mysqli) {
                $export_source = 'external_db';
                // Preparar filtro de forma segura para mysqli
                $where = "WHERE 1=1";
                if (isset($form_id) && !empty($form_id)) {
                    $form_id = $mysqli->real_escape_string($form_id);
                    $where .= " AND form_id = '{$form_id}'";
                }

                $query = "SELECT * FROM `{$table_name}` {$where} ORDER BY created_at DESC";
                $result = $mysqli->query($query);

                if (!$result) { throw new RuntimeException('No se pudieron consultar las respuestas externas.'); }
                if ($result) {
                    while ($row = $result->fetch_assoc()) {
                        // Convertir array asociativo a stdClass para mantener compatibilidad
                        $results[] = (object) $row;
                    }
                }
                $mysqli->close();
            } else {
                // Fallback a BD local si conexión externa falla
                $form_filter = isset($form_id) ? $wpdb->prepare('AND form_id = %s', $form_id) : '';
                $results = $wpdb->get_results("SELECT * FROM $table_name WHERE 1=1 $form_filter ORDER BY created_at DESC");
            }
        } else {
            // Fallback a BD local si no hay BD externa
            $form_filter = isset($form_id) ? $wpdb->prepare('AND form_id = %s', $form_id) : '';
            $results = $wpdb->get_results("SELECT * FROM $table_name WHERE 1=1 $form_filter ORDER BY created_at DESC");
        }

        if ($export_source === 'wordpress_db' && $wpdb->last_error) { throw new RuntimeException('No se pudieron consultar las respuestas locales.'); }

return array('rows'=>$results,'source'=>$export_source);
}

public static function pool_roster_dashboard($pool_id) {
    global $wpdb;

    $assignments_table  = $wpdb->prefix . 'eipsi_pool_assignments';
    $participants_table = $wpdb->prefix . 'survey_participants';
    $studies_table      = $wpdb->prefix . 'survey_studies';
    $pools_table        = $wpdb->prefix . 'eipsi_longitudinal_pools';

    $pool_name = $wpdb->get_var(
        $wpdb->prepare( "SELECT pool_name FROM {$pools_table} WHERE id = %d", $pool_id )
    );

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT a.id AS assignment_id,
                a.participant_id,
                a.study_id as assigned_study_id,
                a.completed,
                a.assigned_at,
                p.email,
                p.first_name,
                p.last_name,
                s.study_name,
                s.study_code
            FROM {$assignments_table} a
            LEFT JOIN {$participants_table} p ON a.participant_id = p.id
            LEFT JOIN {$studies_table} s ON a.study_id = s.id
            WHERE a.pool_id = %d
            ORDER BY a.assigned_at DESC",
            $pool_id
        ),
        ARRAY_A
    );

    if ($wpdb->last_error) { throw new RuntimeException('No se pudo consultar el roster del pool.'); }
    return array('pool_name'=>$pool_name,'rows'=>$rows);
}
public static function pool_roster_hub($pool_id) {
    global $wpdb;
    $assignments_table = $wpdb->prefix . 'eipsi_pool_assignments';
    $participants_table = $wpdb->prefix . 'survey_participants';
    $studies_table = $wpdb->prefix . 'survey_studies';

    $pool_name = $wpdb->get_var($wpdb->prepare(
        "SELECT pool_name FROM {$wpdb->prefix}eipsi_longitudinal_pools WHERE id = %d",
        $pool_id
    ));

    $assignments = $wpdb->get_results($wpdb->prepare(
        "SELECT a.*, p.email, CONCAT(COALESCE(p.first_name,''), ' ', COALESCE(p.last_name,'')) as participant_name, s.study_name
         FROM {$assignments_table} a
         LEFT JOIN {$participants_table} p ON a.participant_id = p.id
         LEFT JOIN {$studies_table} s ON a.study_id = s.id
         WHERE a.pool_id = %d
         ORDER BY a.assigned_at DESC",
        $pool_id
    ), ARRAY_A);

    if ($wpdb->last_error) { throw new RuntimeException('No se pudo consultar el roster del pool.'); }
    return array('pool_name'=>$pool_name,'rows'=>$assignments);
}
}
