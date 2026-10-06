<?php
/** M6 owner; existing public adapters retain their contracts. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Export_File_Service {
private $form_fields_cache=array();
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

public static function directory() {
        $directory = EIPSI_FORMS_PLUGIN_DIR . 'exports';
        if (!is_dir($directory) && !wp_mkdir_p($directory)) { throw new RuntimeException('No se pudo crear el directorio de export.'); }
        $rules = "Require all denied\n";
        $guard = $directory . '/.htaccess';
        if (!is_file($guard) && file_put_contents($guard, $rules, LOCK_EX) !== strlen($rules)) { throw new RuntimeException('No se pudo proteger el directorio de export.'); }
        return $directory;
    }

    public static function reserve_filename($filename) {
        if (!preg_match('/^[a-zA-Z0-9_-]+\.(csv|xlsx)$/D', $filename)) { throw new RuntimeException('Nombre de export inválido.'); }
        $directory = self::directory();
        if (is_dir($directory . '/' . $filename)) { throw new RuntimeException('El destino del export no es un archivo.'); }
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $candidate = $attempt === 0 ? $filename : preg_replace('/(\.(csv|xlsx))$/', '-' . bin2hex(random_bytes(8)) . '$1', $filename);
            $stream = @fopen($directory . '/' . $candidate, 'x');
            if ($stream) { fclose($stream); return $candidate; }
            if (!file_exists($directory . '/' . $candidate)) { throw new RuntimeException('No se pudo reservar el archivo de export.'); }
        }
        throw new RuntimeException('No se pudo reservar un nombre único.');
    }

    public static function xlsx($data) {
        if (!class_exists('\\Shuchkin\\SimpleXLSXGen')) { require_once EIPSI_FORMS_PLUGIN_DIR . 'lib/SimpleXLSXGen.php'; }
        return \Shuchkin\SimpleXLSXGen::fromArray($data);
    }

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
        $export_dir = self::directory();
        $filename = self::reserve_filename($filename);
        if (!file_exists($export_dir)) {
            wp_mkdir_p($export_dir);
        }

        $xlsx = \Shuchkin\SimpleXLSXGen::fromArray($xlsx_data);
        if (!$xlsx->saveAs($export_dir . '/' . $filename) || !is_file($export_dir . '/' . $filename) || !filesize($export_dir . '/' . $filename)) { throw new RuntimeException('No se pudo crear el export XLSX.'); }

        if (!is_file($export_dir . '/' . $filename) || !filesize($export_dir . '/' . $filename)) { throw new RuntimeException('El archivo de export no fue generado.'); }
        return $filename;
    }

public function export_to_csv($data, $survey_id) {
        $filename   = "longitudinal_export_{$survey_id}_" . date('Y-m-d_H-i-s') . '.csv';
        $export_dir = self::directory();
        $filename = self::reserve_filename($filename);
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

public function stream_participants_csv($study_id, $filters, $output) {
        $this->stream_participants_wide_csv($study_id, $filters, $output);
    }

public function get_participants_preview($study_id, $filters = array(), $limit = 10) {
        return $this->get_participants_wide_preview($study_id, $filters, $limit);
    }

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

public function export_participants_wide_excel($study_id, $filters = array()) {
        if ( ! class_exists( '\Shuchkin\SimpleXLSXGen' ) ) {     require_once EIPSI_FORMS_PLUGIN_DIR . 'lib/SimpleXLSXGen.php'; }

        $result = (new EIPSI_Export_Query_Service())->fetch_participants_data($study_id, $filters);
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
        $export_dir = self::directory();
        $filename = self::reserve_filename($filename);
        if (!file_exists($export_dir)) {
            wp_mkdir_p($export_dir);
        }

        $xlsx = \Shuchkin\SimpleXLSXGen::fromArray($xlsx_data);
        if (!$xlsx->saveAs($export_dir . '/' . $filename) || !is_file($export_dir . '/' . $filename) || !filesize($export_dir . '/' . $filename)) { throw new RuntimeException('No se pudo crear el export XLSX.'); }

        if (!is_file($export_dir . '/' . $filename) || !filesize($export_dir . '/' . $filename)) { throw new RuntimeException('El archivo de export no fue generado.'); }
        return $filename;
    }

public function stream_participants_wide_csv($study_id, $filters, $output) {
        $result = (new EIPSI_Export_Query_Service())->fetch_participants_data($study_id, $filters);
        $rows   = isset($result['rows'])  ? $result['rows']  : array();
        $waves  = isset($result['waves']) ? $result['waves'] : array();

        self::write_csv_row($output, $this->build_participants_wide_headers($waves));

        foreach ($rows as $row) {
            self::write_csv_row($output, $this->build_participants_wide_row($row, $waves));
        }
    }

public function get_participants_wide_preview($study_id, $filters = array(), $limit = 8) {
        $result = (new EIPSI_Export_Query_Service())->fetch_participants_data($study_id, $filters);
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

private function extract_preview_columns($full_row, $full_headers, $preview_headers) {
        // Return only the first 8 columns (base columns: ID, Email, Estado, etc.)
        return array_slice($full_row, 0, 8);
    }
public static function stream_pool_roster_dashboard($pool_id, $dataset) {
    $pool_name=$dataset['pool_name']; $rows=$dataset['rows'];
    $filename = sprintf( 'pool-%d-assignments.csv', $pool_id );

    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename=' . $filename );

    $output = fopen( 'php://output', 'w' );

    fputcsv( $output, array(
        'Pool',
        'Assignment ID',
        'Participant ID',
        'Participant Name',
        'Participant Email',
        'Study Name',
        'Study Code',
        'Status',
        'Assigned At',
    ) );

    foreach ( $rows as $row ) {
        $participant_name = trim( sprintf( '%s %s', $row['first_name'], $row['last_name'] ) );
        if ( '' === $participant_name ) {
            $participant_name = $row['email'];
        }

        fputcsv( $output, array(
            $pool_name,
            $row['assignment_id'],
            $row['participant_id'],
            $participant_name,
            $row['email'],
            $row['study_name'],
            $row['study_code'],
            $row['completed'] ? 'completed' : 'assigned',
            $row['assigned_at'],
        ) );
    }

    fclose( $output );

}
public static function stream_pool_roster_hub($pool_id, $dataset) {
    $pool_name=$dataset['pool_name']; $assignments=$dataset['rows'];
    $filename = sanitize_file_name('pool-' . $pool_name . '-assignments-' . date('Y-m-d') . '.csv');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    fputcsv($output, array(
        'participant_id', 'email', 'participant_name', 'study_id', 'study_name',
        'assigned_at', 'last_access', 'access_count', 'completed', 'completed_at'
    ));

    foreach ($assignments as $row) {
        fputcsv($output, array(
            $row['participant_id'],
            $row['email'],
            $row['participant_name'],
            $row['study_id'],
            $row['study_name'],
            $row['assigned_at'],
            $row['last_access'],
            $row['access_count'],
            $row['completed'] ? '1' : '0',
            $row['completed_at']
        ));
    }

    fclose($output);

}
}
