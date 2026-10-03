<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * EIPSI_Export_Service
 *
 * Handles data export for both longitudinal (wave-based) studies and
 * participant rosters. Supports CSV (streamed) and Excel (.xlsx) output.
 *
 * @package EIPSI_Forms
 * @since   1.4.0
 * @updated 1.8.0 — Added participant export (CSV + Excel) with filters,
 *                   real preview endpoint, summary stats.
 */
class EIPSI_Export_Service {
    public static function strip_credentials($data) {
        if (!is_array($data)) { return $data; }
        foreach ($data as $key=>$value) {
            if (preg_match('/(^|_)(password|token|secret|credential|nonce)(_|$)/i',(string)$key)) { unset($data[$key]); }
            else { $data[$key]=self::strip_credentials($value); }
        }
        return $data;
    }

    public static function write_csv_row($stream, $row) {
        if (fputcsv($stream,$row) === false) { throw new RuntimeException('No se pudo escribir el export CSV.'); }
    }

    /** @var array Request-level cache for form fields */
    private $form_fields_cache = array();

    /**
     * Extracts data-generating fields from a form block definition.
     * v1.4.2 - Used to ensure consistent export alignment.
     */
    private function get_fields_by_form_id($form_id) {
        if (empty($form_id)) return array();
        
        if (isset($this->form_fields_cache[$form_id])) {
            return $this->form_fields_cache[$form_id];
        }

        $post = get_post($form_id);
        if (!$post || empty($post->post_content)) {
            $this->form_fields_cache[$form_id] = array();
            return array();
        }

        $blocks = parse_blocks($post->post_content);
        $fields = $this->extract_fields_from_blocks($blocks);
        
        $field_names = array_keys($fields);
        sort($field_names);
        
        $this->form_fields_cache[$form_id] = $field_names;
        return $this->form_fields_cache[$form_id];
    }

    private function extract_fields_from_blocks($blocks) {
        $fields = array();
        $allowed_blocks = array(
            'eipsi/campo-texto',
            'eipsi/campo-likert',
            'eipsi/campo-radio',
            'eipsi/campo-select',
            'eipsi/campo-textarea',
            'eipsi/vas-slider',
            'eipsi/campo-multiple',
        );

        foreach ($blocks as $block) {
            if (empty($block['blockName'])) continue;
            
            // Check if it's an allowed block (with or without prefix)
            $is_allowed = in_array($block['blockName'], $allowed_blocks) || 
                          (strpos($block['blockName'], 'eipsi/') === false && in_array('eipsi/' . $block['blockName'], $allowed_blocks));
            
            if ($is_allowed) {
                $fieldName = !empty($block['attrs']['fieldName']) ? $block['attrs']['fieldName'] : null;
                if ($fieldName) {
                    $fields[$fieldName] = true;
                }
            }

            if (!empty($block['innerBlocks'])) {
                $innerFields = $this->extract_fields_from_blocks($block['innerBlocks']);
                $fields = array_merge($fields, $innerFields);
            }
        }
        return $fields;
    }

    // =========================================================================
    // LONGITUDINAL EXPORT (wave responses)
    // =========================================================================

    /**
     * Main export method — fetches longitudinal (wave) data.
     *
     * @param int   $survey_id Study / survey ID.
     * @param array $filters   Optional filters (wave_index, date_from, date_to, status).
     * @return array Array of stdClass rows.
     */
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

    /** @internal */
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

    /** Export longitudinal data to .xlsx, returns filename. */
    public function export_to_excel($data, $survey_id) {
        if ( ! class_exists( '\Shuchkin\SimpleXLSXGen' ) ) {     require_once EIPSI_FORMS_PLUGIN_DIR . 'lib/SimpleXLSXGen.php'; }

        // Ensure device data service is available
        if (!class_exists('EIPSI_Device_Data_Service')) {
            require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-device-data-service.php';
        }

        $headers = array(
            'Participant ID',
            'Consent Decision',
            'Wave',
            'Fecha Asignación Toma',
            'Submitted At',
            'Response Time (min)',
            'Status',
            'User Fingerprint',
        );

        // Add extended device data headers (12 fields)
        $device_headers = array(
            'Canvas Fingerprint',
            'WebGL Renderer',
            'Screen Resolution',
            'Screen Depth',
            'Pixel Ratio',
            'Timezone',
            'Language',
            'CPU Cores',
            'RAM (GB)',
            'Browser Plugins',
            'Touch Support',
            'Cookies Enabled',
        );
        $headers = array_merge($headers, $device_headers);

        if (!empty($data)) {
            $response_data = self::strip_credentials(json_decode($data[0]->response_data, true));
            if (is_array($response_data)) {
                foreach (array_keys($response_data) as $field) {
                    $headers[] = $field;
                }
            }
        }

        $xlsx_data   = array($headers);

        // Get all submission IDs for batch device data retrieval
        $submission_ids = array();
        foreach ($data as $item) {
            if (!empty($item->submission_id)) {
                $submission_ids[] = $item->submission_id;
            }
        }

        // Fetch all device data in one query
        $device_data_batch = array();
        if (!empty($submission_ids) && class_exists('EIPSI_Device_Data_Service')) {
            $device_data_batch = EIPSI_Device_Data_Service::get_device_data_batch($submission_ids);
        }

        foreach ($data as $item) {
            $response_data = self::strip_credentials(json_decode($item->response_data, true));
            if (!is_array($response_data)) {
                $response_data = array();
            }

            // Get device data for this submission
            $device_data = isset($device_data_batch[$item->submission_id]) 
                ? $device_data_batch[$item->submission_id] 
                : array();

            $row = array(
                $item->participant_id,
                $item->consent_decision,
                $item->wave_index,
                $item->wave_assigned_at,
                $item->submitted_at,
                round($item->response_time_seconds / 60, 2),
                $item->status,
                $item->user_fingerprint,
                // Device data fields (12 fields)
                isset($device_data['canvas_fingerprint']) ? $device_data['canvas_fingerprint'] : '',
                isset($device_data['webgl_renderer']) ? $device_data['webgl_renderer'] : '',
                isset($device_data['screen_resolution']) ? $device_data['screen_resolution'] : '',
                isset($device_data['screen_depth']) ? $device_data['screen_depth'] : '',
                isset($device_data['pixel_ratio']) ? $device_data['pixel_ratio'] : '',
                isset($device_data['timezone']) ? $device_data['timezone'] : '',
                isset($device_data['language']) ? $device_data['language'] : '',
                isset($device_data['cpu_cores']) ? $device_data['cpu_cores'] : '',
                isset($device_data['ram_gb']) ? $device_data['ram_gb'] : '',
                isset($device_data['browser_plugins']) ? $device_data['browser_plugins'] : '',
                isset($device_data['touch_support']) ? $device_data['touch_support'] : '',
                isset($device_data['cookies_enabled']) ? $device_data['cookies_enabled'] : '',
            );

            foreach ($response_data as $value) {
                $row[] = $value;
            }

            $xlsx_data[] = $row;
        }

        $filename   = "longitudinal_export_{$survey_id}_" . date('Y-m-d_H-i-s') . '.xlsx';
        $export_dir = EIPSI_FORMS_PLUGIN_DIR . 'exports';
        if (!file_exists($export_dir)) {
            wp_mkdir_p($export_dir);
        }

        $xlsx = \Shuchkin\SimpleXLSXGen::fromArray($xlsx_data);
        if (!$xlsx->saveAs($export_dir . '/' . $filename) || !is_file($export_dir . '/' . $filename) || !filesize($export_dir . '/' . $filename)) { throw new RuntimeException('No se pudo crear el export XLSX.'); }

        if (!is_file($export_dir . '/' . $filename) || !filesize($export_dir . '/' . $filename)) { throw new RuntimeException('El archivo de export no fue generado.'); }
        return $filename;
    }

    /** Export longitudinal data to .csv, returns filename. */
    public function export_to_csv($data, $survey_id) {
        $filename   = "longitudinal_export_{$survey_id}_" . date('Y-m-d_H-i-s') . '.csv';
        $export_dir = EIPSI_FORMS_PLUGIN_DIR . 'exports';
        if (!file_exists($export_dir)) {
            wp_mkdir_p($export_dir);
        }

        $file_path = $export_dir . '/' . $filename;
        $file      = @fopen($file_path, 'w');
        if (!$file) { throw new RuntimeException('No se pudo crear el export CSV.'); }

        // Ensure device data service is available
        if (!class_exists('EIPSI_Device_Data_Service')) {
            require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-device-data-service.php';
        }

        $headers = array(
            'Participant ID',
            'Consent Decision',
            'Wave',
            'Fecha Asignación Toma',
            'Submitted At',
            'Response Time (min)',
            'Status',
            'User Fingerprint',
        );

        // Add extended device data headers (12 fields)
        $device_headers = array(
            'Canvas Fingerprint',
            'WebGL Renderer',
            'Screen Resolution',
            'Screen Depth',
            'Pixel Ratio',
            'Timezone',
            'Language',
            'CPU Cores',
            'RAM (GB)',
            'Browser Plugins',
            'Touch Support',
            'Cookies Enabled',
        );
        $headers = array_merge($headers, $device_headers);

        if (!empty($data)) {
            $response_data = self::strip_credentials(json_decode($data[0]->response_data, true));
            if (is_array($response_data)) {
                foreach (array_keys($response_data) as $field) {
                    $headers[] = $field;
                }
            }
        }

        self::write_csv_row($file, $headers);

        // Get all submission IDs for batch device data retrieval
        $submission_ids = array();
        foreach ($data as $item) {
            if (!empty($item->submission_id)) {
                $submission_ids[] = $item->submission_id;
            }
        }

        // Fetch all device data in one query
        $device_data_batch = array();
        if (!empty($submission_ids) && class_exists('EIPSI_Device_Data_Service')) {
            $device_data_batch = EIPSI_Device_Data_Service::get_device_data_batch($submission_ids);
        }

        foreach ($data as $item) {
            $response_data = self::strip_credentials(json_decode($item->response_data, true));
            if (!is_array($response_data)) {
                $response_data = array();
            }

            // Get device data for this submission
            $device_data = isset($device_data_batch[$item->submission_id]) 
                ? $device_data_batch[$item->submission_id] 
                : array();

            $row = array(
                $item->participant_id,
                $item->consent_decision,
                $item->wave_index,
                $item->wave_assigned_at,
                $item->submitted_at,
                round($item->response_time_seconds / 60, 2),
                $item->status,
                $item->user_fingerprint,
                // Device data fields (12 fields)
                isset($device_data['canvas_fingerprint']) ? $device_data['canvas_fingerprint'] : '',
                isset($device_data['webgl_renderer']) ? $device_data['webgl_renderer'] : '',
                isset($device_data['screen_resolution']) ? $device_data['screen_resolution'] : '',
                isset($device_data['screen_depth']) ? $device_data['screen_depth'] : '',
                isset($device_data['pixel_ratio']) ? $device_data['pixel_ratio'] : '',
                isset($device_data['timezone']) ? $device_data['timezone'] : '',
                isset($device_data['language']) ? $device_data['language'] : '',
                isset($device_data['cpu_cores']) ? $device_data['cpu_cores'] : '',
                isset($device_data['ram_gb']) ? $device_data['ram_gb'] : '',
                isset($device_data['browser_plugins']) ? $device_data['browser_plugins'] : '',
                isset($device_data['touch_support']) ? $device_data['touch_support'] : '',
                isset($device_data['cookies_enabled']) ? $device_data['cookies_enabled'] : '',
            );

            foreach ($response_data as $value) {
                $row[] = $value;
            }

            self::write_csv_row($file, $row);
        }

        if (!fclose($file)) { throw new RuntimeException('No se pudo cerrar el archivo CSV.'); }
        if (!is_file($export_dir . '/' . $filename) || !filesize($export_dir . '/' . $filename)) { throw new RuntimeException('El archivo de export no fue generado.'); }
        return $filename;
    }

  

// =========================================================================
// PARTICIPANT EXPORT (roster + wave progress)
// =========================================================================

/**
 * Fetch participant rows for export, with optional filters.
 *
 * Returns one row per participant with their wave-completion summary columns.
 *
 * Supported filters:
 *   - status      : 'all' | 'active' | 'inactive'
 *   - wave_index  : 'all' | e.g. '1', '2'  (filters by whether that wave is completed)
 *   - search      : email / name free-text
 *   - date_from   : YYYY-MM-DD (participant created_at)
 *   - date_to     : YYYY-MM-DD
 *
 * @param int   $study_id
 * @param array $filters
 * @return array
 */
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
        $decoded_responses = self::strip_credentials(json_decode($sub->form_responses, true));
        
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

    /**
     * Stream participant CSV directly to an open file handle (e.g. php://output).
     *
     * @param int      $study_id
     * @param array    $filters
     * @param resource $output   Open file handle.
     */
    public function stream_participants_csv($study_id, $filters, $output) {
        $this->stream_participants_wide_csv($study_id, $filters, $output);
    }
    
    public function get_participants_preview($study_id, $filters = array(), $limit = 10) {
        return $this->get_participants_wide_preview($study_id, $filters, $limit);
    }

    // =========================================================================
    // STATISTICS
    // =========================================================================

    /**
     * Get export statistics for a survey.
     *
     * @param int   $survey_id
     * @param array $filters   (unused – kept for BC)
     * @return array
     */
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

    // =========================================================================
    // HELPER — available surveys / waves
    // =========================================================================

    /** Get list of active surveys for dropdown. */
    public function get_available_surveys() {
        global $wpdb;

        return $wpdb->get_results(
            "SELECT id, title, description
             FROM {$wpdb->prefix}survey_surveys
             WHERE is_active = 1
             ORDER BY created_at DESC"
        );
    }

    /** Get wave indices for a survey. */
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

    // =========================================================================
    // WIDE FORMAT EXPORT (one row per participant)
    // =========================================================================

    /**
     * Build the wide header array for participant export.
     *
     * @param array $waves List of wave objects.
     * @return string[]
     */
    private function build_participants_wide_headers($waves) {
        $headers = array(
            'ID',
            'Email',
            'Estado',
            'Registrado',
            'Último acceso',
            'Ondas asignadas',
            'Ondas completadas',
            'Progreso (%)',
        );

        $excluded_base = array('id', 'email', 'estado', 'registrado', 'último acceso', 'ondas asignadas', 'ondas completadas', 'progreso (%)');
        $excluded_meta = array('submitted_at', 'duration_seconds', 'device', 'browser', 'os', 'screen_width', 'ip_address', 'canvas_fingerprint', 'webgl_renderer', 'screen_resolution', 'screen_depth', 'pixel_ratio', 'timezone', 'language', 'cpu_cores', 'ram', 'plugins', 'touch_support', 'cookies_enabled', 'eipsi_consent_decision');

        foreach ($waves as $wave) {
            $prefix = 'T' . $wave->wave_index;
            if ($wave->wave_index == 1) {
                $headers[] = $prefix . "_eipsi_consent_decision";
            }
            $headers[] = $prefix . '_submitted_at';
            $headers[] = $prefix . '_duration_seconds';
            $headers[] = $prefix . '_device';
            $headers[] = $prefix . '_browser';
            $headers[] = $prefix . '_os';
            $headers[] = $prefix . '_screen_width';
            $headers[] = $prefix . '_ip_address';
            $headers[] = $prefix . '_canvas_fingerprint';
            $headers[] = $prefix . '_webgl_renderer';
            $headers[] = $prefix . '_screen_resolution';
            $headers[] = $prefix . '_screen_depth';
            $headers[] = $prefix . '_pixel_ratio';
            $headers[] = $prefix . '_timezone';
            $headers[] = $prefix . '_language';
            $headers[] = $prefix . '_cpu_cores';
            $headers[] = $prefix . '_ram';
            $headers[] = $prefix . '_plugins';
            $headers[] = $prefix . '_touch_support';
            $headers[] = $prefix . '_cookies_enabled';

            // Add dynamic headers from form definition
            $fields = $this->get_fields_by_form_id($wave->form_id);
            foreach ($fields as $field_name) {
                if (in_array(strtolower($field_name), $excluded_base) || in_array(strtolower($field_name), $excluded_meta)) {
                    continue;
                }
                $headers[] = $prefix . '_' . $field_name;
            }
        }

        return $headers;
    }

    /**
     * Build a wide data row for one participant.
     *
     * @param array $row                    Participant row from fetch_participants_data().
     * @param array $waves                  Wave list.
     * @param array $response_keys_by_wave  Optional: keys per wave_index.
     * @return array
     */
    private function build_participants_wide_row($row, $waves) {
        error_log("[EIPSI-EXPORT-DIAG] build_participants_wide_row START: participant_id=" . ($row['id'] ?? 'N/A') . ", submissions_count=" . count($row['submissions'] ?? array()));

        // Determine Estado based on consent_decision and consent_context
        $estado = 'Inactivo';
        if ($row['is_active']) {
            $estado = 'Activo';
        } elseif ($row['consent_decision'] === 'withdrawn') {
            if ($row['consent_context'] === 'T2B_data_deletion') {
                $estado = 'Abandono (B2)';
            } elseif ($row['consent_context'] === 'T2A_withdrawal') {
                $estado = 'Abandono (B1)';
            }
        } elseif ($row['consent_decision'] === 'declined') {
            $estado = 'Consentimiento rechazado';
        }

        // Hide email for B2 withdrawals (show ANONYMIZED instead)
        $email_display = $row['email'];
        if ($row['consent_context'] === 'T2B_data_deletion') {
            $email_display = 'ANONYMIZED';
        }

        // Base participant data (without names)
        $data = array(
            $row['id'],
            $email_display,
            $estado,
            $row['created_at'] ? date('Y-m-d H:i', strtotime($row['created_at'])) : '',
            $row['last_login_at'] ? date('Y-m-d H:i', strtotime($row['last_login_at'])) : '',
            $row['waves_assigned'],
            $row['waves_submitted'],
            $row['completion_percent'],
        );

        $excluded_base = array('id', 'email', 'estado', 'registrado', 'último acceso', 'ondas asignadas', 'ondas completadas', 'progreso (%)');
        $excluded_meta = array('submitted_at', 'duration_seconds', 'device', 'browser', 'os', 'screen_width', 'ip_address', 'canvas_fingerprint', 'webgl_renderer', 'screen_resolution', 'screen_depth', 'pixel_ratio', 'timezone', 'language', 'cpu_cores', 'ram', 'plugins', 'touch_support', 'cookies_enabled', 'eipsi_consent_decision');

        // Add wave data for each wave
        foreach ($waves as $wave) {
            $wi = $wave->wave_index;
            if ($wave->wave_index == 1) {
                $data[] = $row["consent_decision"] ?? "";
            }
            $submission = isset($row['submissions'][$wi]) ? $row['submissions'][$wi] : null;
            $wave_fields = $this->get_fields_by_form_id($wave->form_id);

            if ($submission) {
                // Metadata fields
                $data[] = $submission['submitted_at'] ? date('Y-m-d H:i:s', strtotime($submission['submitted_at'])) : '';
                $data[] = $submission['duration_seconds'] ?? '';
                $data[] = $submission['device'] ?? '';
                $data[] = $submission['browser'] ?? '';
                $data[] = $submission['os'] ?? '';
                $data[] = $submission['screen_width'] ?? '';
                $data[] = $submission['ip_address'] ?? '';
                
                $data[] = $submission['canvas_fingerprint'] ?? '';
                $data[] = $submission['webgl_renderer'] ?? '';
                $data[] = $submission['screen_resolution'] ?? '';
                $data[] = $submission['screen_depth'] ?? '';
                $data[] = $submission['pixel_ratio'] ?? '';
                $data[] = $submission['timezone'] ?? '';
                $data[] = $submission['language'] ?? '';
                $data[] = $submission['cpu_cores'] ?? '';
                $data[] = $submission['ram'] ?? '';
                $data[] = $submission['plugins'] ?? '';
                $data[] = $submission['touch_support'] ?? '';
                $data[] = $submission['cookies_enabled'] ?? '';

                // Form response fields based on wave definition
                $form_responses = $submission['form_responses'] ?? array();
                foreach ($wave_fields as $field_name) {
                    if (in_array(strtolower($field_name), $excluded_base) || in_array(strtolower($field_name), $excluded_meta)) {
                        continue;
                    }
                    $value = '';
                    if (isset($form_responses[$field_name])) {
                        $val = $form_responses[$field_name];
                        if (is_array($val) || is_object($val)) {
                            $value = json_encode($val, JSON_UNESCAPED_UNICODE);
                        } else {
                            $value = $val;
                        }
                    }
                    $data[] = $value;
                }
            } else {
                // Empty submission - pad all columns defined for this wave
                // v1.4.2: 19 columns of metadata
                $metadata_count = 19;
                for ($i = 0; $i < $metadata_count; $i++) {
                    $data[] = '';
                }
                // Empty form response columns based on wave definition
                foreach ($wave_fields as $field_name) {
                    if (in_array(strtolower($field_name), $excluded_base) || in_array(strtolower($field_name), $excluded_meta)) {
                        continue;
                    }
                    $data[] = '';
                }
            }
        }

        return $data;
    }

    /**
     * Export participant roster to Excel in WIDE format, returns filename.
     *
     * Estructura Wide (v2.1.3): una fila por participante con columnas por cada wave.
     * Incluye 19 columnas de metadatos por wave (7 básicas + 12 extendidas, sin fingerprint_id).
     *
     * @param int   $study_id
     * @param array $filters
     * @return string Filename (in exports/ directory)
     */
    public function export_participants_wide_excel($study_id, $filters = array()) {
        if ( ! class_exists( '\Shuchkin\SimpleXLSXGen' ) ) {     require_once EIPSI_FORMS_PLUGIN_DIR . 'lib/SimpleXLSXGen.php'; }

        $result = $this->fetch_participants_data($study_id, $filters);
        $rows   = isset($result['rows'])  ? $result['rows']  : array();
        $waves  = isset($result['waves']) ? $result['waves'] : array();

        $headers = $this->build_participants_wide_headers($waves);
        $xlsx_data = array($headers);

        foreach ($rows as $row) {
            $xlsx_data[] = $this->build_participants_wide_row($row, $waves);
        }

        // Summary sheet row
        $xlsx_data[] = array();
        $xlsx_data[] = array('Total participantes', count($rows));
        $active      = count(array_filter($rows, function ($r) { return $r['is_active']; }));
        $xlsx_data[] = array('Activos', $active);
        $xlsx_data[] = array('Inactivos', count($rows) - $active);
        $xlsx_data[] = array('Formato', 'Wide');
        $xlsx_data[] = array('Exportado el', date('Y-m-d H:i:s'));

        $filename   = 'participantes-wide-' . $study_id . '-' . date('Y-m-d_H-i-s') . '.xlsx';
        $export_dir = EIPSI_FORMS_PLUGIN_DIR . 'exports';
        if (!file_exists($export_dir)) {
            wp_mkdir_p($export_dir);
        }

        $xlsx = \Shuchkin\SimpleXLSXGen::fromArray($xlsx_data);
        if (!$xlsx->saveAs($export_dir . '/' . $filename) || !is_file($export_dir . '/' . $filename) || !filesize($export_dir . '/' . $filename)) { throw new RuntimeException('No se pudo crear el export XLSX.'); }

        if (!is_file($export_dir . '/' . $filename) || !filesize($export_dir . '/' . $filename)) { throw new RuntimeException('El archivo de export no fue generado.'); }
        return $filename;
    }

    /**
     * Export participant roster to CSV in WIDE format.
     *
     * @param int      $study_id
     * @param array    $filters
     * @param resource $output   Open file handle (e.g., php://output).
     */
    public function stream_participants_wide_csv($study_id, $filters, $output) {
        $result = $this->fetch_participants_data($study_id, $filters);
        $rows   = isset($result['rows'])  ? $result['rows']  : array();
        $waves  = isset($result['waves']) ? $result['waves'] : array();

        self::write_csv_row($output, $this->build_participants_wide_headers($waves));

        foreach ($rows as $row) {
            self::write_csv_row($output, $this->build_participants_wide_row($row, $waves));
        }
    }

    /**
     * Return a lightweight preview (first N rows) of the participant WIDE export.
     *
     * Used by the AJAX preview endpoint in the UI.
     *
     * @param int   $study_id
     * @param array $filters
     * @param int   $limit    Max rows to return (default 10).
     * @return array { headers: string[], rows: array[], total: int }
     */
    public function get_participants_wide_preview($study_id, $filters = array(), $limit = 8) {
        $result = $this->fetch_participants_data($study_id, $filters);
        $rows   = isset($result['rows'])  ? $result['rows']  : array();
        $waves  = isset($result['waves']) ? $result['waves'] : array();

        $full_headers = $this->build_participants_wide_headers($waves);
        $preview_headers = $this->build_participants_wide_preview_headers($rows, $waves, count($full_headers));
        $preview_rows = array();

        foreach (array_slice($rows, 0, $limit) as $row) {
            $full_row = $this->build_participants_wide_row($row, $waves);
            // Extract only preview columns (base columns + first wave basic data)
            $preview_rows[] = $this->extract_preview_columns($full_row, $full_headers, $preview_headers);
        }

        return array(
            'headers' => $preview_headers,
            'rows'    => $preview_rows,
            'total'   => count($rows),
            'columns' => count($full_headers), // Show actual total columns
            'format'  => 'wide',
            'is_preview' => true,
        );
    }

    /**
     * Build limited headers for preview (base columns only).
     */
    private function build_participants_wide_preview_headers($rows, $waves, $full_headers_count = 0) {
        return array(
            'ID',
            'Email',
            'Estado',
            'Registrado',
            'Último acceso',
            'Ondas asignadas',
            'Ondas completadas',
            'Progreso (%)',
        );
    }

    /**
     * Extract preview columns from full row data (first 8 base columns only).
     */
    private function extract_preview_columns($full_row, $full_headers, $preview_headers) {
        // Return only the first 8 columns (base columns: ID, Email, Estado, etc.)
        return array_slice($full_row, 0, 8);
    }
}
