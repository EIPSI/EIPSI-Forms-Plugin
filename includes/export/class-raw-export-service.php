<?php
/** M6 owner; existing public adapters retain their contracts. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Raw_Export_Service {

public static function export_generate_stable_form_id($form_name) {
    global $wpdb;
    
    $initials = export_get_form_initials($form_name);
    
    if (strlen($initials) < 2) {
        $slug = sanitize_title($form_name);
        $initials = strtoupper(substr($slug, 0, 3));
    }
    
    $slug = sanitize_title($form_name);
    $hash = substr(md5($slug), 0, 6);
    $form_id = "{$initials}-{$hash}";
    
    return $form_id;
}

public static function export_get_form_initials($form_name) {
    // Handle null or non-string input safely
    if (empty($form_name)) {
        return '';
    }
    
    $stop_words = array('de', 'la', 'el', 'y', 'en', 'con', 'para', 'del', 'los', 'las');
    $words = preg_split('/\s+/', strtolower((string) $form_name));
    $words = array_diff($words, $stop_words);
    $initials = '';
    foreach ($words as $word) {
        if (!empty($word)) {
            $initials .= strtoupper(substr($word, 0, 1));
        }
    }
    return $initials;
}

public static function export_generateStableFingerprint($user_data) {
    $components = array(
        'email' => strtolower(trim($user_data['email'] ?? '')),
        'name' => export_normalizeName($user_data['name'] ?? ''),
    );
    
    $fingerprint_string = implode('|', array_filter($components));
    
    if ($fingerprint_string) {
        $hash = substr(hash('sha256', $fingerprint_string), 0, 8);
        return "FP-{$hash}";
    } else {
        return "FP-SESS-" . substr(md5(uniqid()), 0, 6);
    }
}

public static function export_normalizeName($name) {
    return strtoupper(trim($name));
}

public static function eipsi_sanitize_export_value($val) {
    if (is_array($val)) {
        return implode(' | ', array_map('strval', $val));
    }
    $val = wp_strip_all_tags((string) $val);
    $val = trim($val);
    return $val;
}

public static function eipsi_export_to_excel() {
    try {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to perform this action.', 'eipsi-forms'));
        }

        $dataset=EIPSI_Export_Query_Service::raw_responses($_GET['form_id']??null);
        $results=$dataset['rows'];$export_source=$dataset['source'];
        header('X-EIPSI-Data-Source: ' . $export_source);
        if (empty($results)) {
            wp_die(__('No data to export.', 'eipsi-forms'));
        }

    // Get privacy config for first form (assuming same config per form_name)
    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/privacy-config.php';
    $first_form_id = !empty($results[0]->form_id) ? $results[0]->form_id : export_generate_stable_form_id($results[0]->form_name);
    $privacy_config = get_privacy_config($first_form_id);

    // v2.1.3 - Load Device Data Service for extended metadata export
    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-device-data-service.php';
    $submission_ids = array_column($results, 'id');
    $device_data_batch = $export_source === 'wordpress_db' ? EIPSI_Device_Data_Service::get_device_data_batch($submission_ids) : array();

    // Obtener todas las preguntas únicas para crear columnas (excluir campos internos)
    $internal_fields = array('action', 'eipsi_nonce', 'start_time', 'end_time', 'form_start_time', 'form_end_time', 'nonce', 'form_action', 'ip_address', 'device', 'browser', 'os', 'screen_width', 'current_page', 'form_id', 'eipsi_consent_accepted');
    
    // v2.5.3 - Orden determinístico: usar submission con más campos como referencia
    $all_questions = [];
    $reference_submission = [];
    $max_fields = 0;

    foreach ($results as $row) {
        $form_data = $row->form_responses ? EIPSI_Export_Service::strip_credentials(json_decode($row->form_responses, true)) : [];
        if (!is_array($form_data)) continue;
        
        // Filtrar campos internos
        $filtered = array_diff_key($form_data, array_flip($internal_fields));
        
        // Si este submission tiene más campos que el actual de referencia, usarlo como base
        if (count($filtered) > $max_fields) {
            $max_fields = count($filtered);
            $reference_submission = array_keys($filtered);
        }
    }

    // El orden de referencia es el del submission más completo
    $all_questions = $reference_submission;

    // Segunda pasada: agregar al final cualquier campo que no esté en la referencia
    foreach ($results as $row) {
        $form_data = $row->form_responses ? EIPSI_Export_Service::strip_credentials(json_decode($row->form_responses, true)) : [];
        if (!is_array($form_data)) continue;
        
        foreach ($form_data as $question => $answer) {
            if (!in_array($question, $all_questions) && !in_array($question, $internal_fields)) {
                $all_questions[] = $question; // Campos extras van al final
            }
        }
    }

    // === DETECTAR TIEMPOS POR PÁGINA Y CAMPO (Excel) ===
    $page_timing_keys = [];
    $field_timing_keys = [];
    $has_page_timings = false;
    $has_field_timings = false;

    foreach ($results as $row) {
        $metadata = !empty($row->metadata) ? json_decode($row->metadata, true) : [];

        // Detectar page_timings
        if (isset($metadata['page_timings']) && is_array($metadata['page_timings'])) {
            $has_page_timings = true;
            foreach ($metadata['page_timings'] as $key => $val) {
                // Excluir total_duration, solo procesar page_0, page_1, etc.
                if ($key !== 'total_duration' && strpos($key, 'page_') === 0) {
                    if (!in_array($key, $page_timing_keys)) {
                        $page_timing_keys[] = $key;
                    }
                }
            }
        }

        // Detectar field_timings
        if (isset($metadata['field_timings']) && is_array($metadata['field_timings'])) {
            $has_field_timings = true;
            foreach ($metadata['field_timings'] as $field_name => $field_data) {
                if (!in_array($field_name, $field_timing_keys)) {
                    $field_timing_keys[] = $field_name;
                }
            }
        }
    }

    // Ordenar page_timing_keys numéricamente (page_0, page_1, page_2, ...)
    usort($page_timing_keys, function($a, $b) {
        $num_a = intval(str_replace('page_', '', $a));
        $num_b = intval(str_replace('page_', '', $b));
        return $num_a <=> $num_b;
    });

    // Detectar si hay aleatorización real en los resultados
    $has_randomization = false;
    foreach ($results as $row) {
        $metadata = !empty($row->metadata) ? json_decode($row->metadata, true) : [];
        if (!empty($metadata['random_assignment']['form_id']) && $metadata['random_assignment']['form_id'] !== '-') {
            $has_randomization = true;
            break;
        }
    }

    $data = array();
    // Encabezados: nuevo formato con IDs + metadatos + timestamps + preguntas dinámicas
    // ONLY include metadata columns if privacy config allows
    $headers = array('Form ID', 'Participant ID', 'Form Name', 'Date', 'Time', 'Duration(s)', 'Start Time (UTC)', 'End Time (UTC)');

    // === Columnas de Aleatorización (Fase 1) - Solo si hay datos reales ===
    if ($has_randomization) {
        $headers[] = 'Assignment Form';
        $headers[] = 'Seed';
        $headers[] = 'Type (Random/Manual)';
    }
    
    // v1.5.5 - RCT submission-based assignment columns
    $has_rct_submission = false;
    foreach ($results as $row) {
        if (!empty($row->rct_assigned_variant)) {
            $has_rct_submission = true;
            break;
        }
    }
    if ($has_rct_submission) {
        $headers[] = 'RCT Assigned Variant';
        $headers[] = 'RCT Randomization ID';
    }
    
    // v2.5.4 - Pool de estudios context
    $has_pool_submissions = false;
    $pool_context = array();
    if (function_exists('eipsi_get_pool_context_for_study')) {
        // Detectar si este formulario pertenece a un estudio con pool
        $pool_context = eipsi_get_pool_context_for_study($form_id);
        $has_pool_submissions = !empty($pool_context);
    }
    if ($has_pool_submissions) {
        $headers[] = 'Pool Code';
        $headers[] = 'Pool Assigned At';
    }

    if ($privacy_config['ip_address']) {
        $headers[] = 'IP Address';
    }
    if ($privacy_config['device_type']) {
        $headers[] = 'Device';
    }
    if ($privacy_config['browser']) {
        $headers[] = 'Browser';
    }
    if ($privacy_config['os']) {
        $headers[] = 'OS';
    }

    // === Columnas de Page Timings ===
    foreach ($page_timing_keys as $page_key) {
        $page_num = intval(str_replace('page_', '', $page_key));
        $headers[] = "Page {$page_num} - Duration(s)";
        $headers[] = "Page {$page_num} - Timestamp";
    }

    // === Columnas de Field Timings ===
    foreach ($field_timing_keys as $field_name) {
        $headers[] = "{$field_name} - Time(s)";
        $headers[] = "{$field_name} - Interactions";
        $headers[] = "{$field_name} - Focus Count";
    }

    // === Columna Total Duration ===
    if ($has_page_timings || $has_field_timings) {
        $headers[] = 'Total Duration(s)';
    }

    // v2.1.3 - Extended Device Metadata Headers (ON by default based on privacy config)
    if ($privacy_config['export_canvas_fingerprint'] ?? true) {
        $headers[] = 'Canvas Fingerprint';
    }
    if ($privacy_config['export_webgl_renderer'] ?? true) {
        $headers[] = 'WebGL Renderer';
    }
    if ($privacy_config['export_screen_resolution'] ?? true) {
        $headers[] = 'Screen Resolution';
    }
    if ($privacy_config['export_screen_depth'] ?? true) {
        $headers[] = 'Screen Depth';
    }
    if ($privacy_config['export_pixel_ratio'] ?? true) {
        $headers[] = 'Pixel Ratio';
    }
    if ($privacy_config['export_timezone'] ?? true) {
        $headers[] = 'Timezone';
    }
    if ($privacy_config['export_language'] ?? true) {
        $headers[] = 'Language';
    }
    if ($privacy_config['export_cpu_cores'] ?? true) {
        $headers[] = 'CPU Cores';
    }
    if ($privacy_config['export_ram'] ?? true) {
        $headers[] = 'RAM (GB)';
    }
    if ($privacy_config['export_plugins'] ?? true) {
        $headers[] = 'Browser Plugins';
    }
    if ($privacy_config['export_touch_support'] ?? true) {
        $headers[] = 'Touch Support';
    }
    if ($privacy_config['export_cookies_enabled'] ?? true) {
        $headers[] = 'Cookies Enabled';
    }

    $headers = array_merge($headers, $all_questions);
    $data[] = $headers;

    foreach ($results as $row) {
        $form_data = $row->form_responses ? EIPSI_Export_Service::strip_credentials(json_decode($row->form_responses, true)) : [];
        
        // Generate IDs if not already present
        $form_id = !empty($row->form_id) ? $row->form_id : export_generate_stable_form_id($row->form_name);
        
        // Extract user data for fingerprint
        $user_data = array(
            'email' => '',
            'name' => ''
        );
        foreach ($form_data as $key => $value) {
            if (strtolower($key) === 'email' || strpos(strtolower($key), 'correo') !== false) {
                $user_data['email'] = $value;
            }
            if (strtolower($key) === 'name' || strtolower($key) === 'nombre') {
                $user_data['name'] = $value;
            }
        }
        
        $participant_id = !empty($row->participant_id) ? $row->participant_id : export_generateStableFingerprint($user_data);
        
        // Format date and time separately
        $datetime = !empty($row->submitted_at) ? $row->submitted_at : $row->created_at;
        $date = date('Y-m-d', strtotime($datetime));
        $time = date('H:i:s', strtotime($datetime));
        
        // Use duration_seconds if available, otherwise duration
        $duration = !empty($row->duration_seconds) ? number_format($row->duration_seconds, 3, '.', '') : $row->duration;
        
        // Format timestamps as ISO 8601 datetime strings
        $start_time_utc = '';
        $end_time_utc = '';
        if (!empty($row->start_timestamp_ms)) {
            $start_time_utc = gmdate('Y-m-d\TH:i:s.v\Z', intval($row->start_timestamp_ms / 1000));
        }
        if (!empty($row->end_timestamp_ms)) {
            $end_time_utc = gmdate('Y-m-d\TH:i:s.v\Z', intval($row->end_timestamp_ms / 1000));
        }

        // === Obtener datos de aleatorización (solo si hay randomización real) ===
        $row_data = array(
            $form_id,
            $participant_id,
            $row->form_name,
            $date,
            $time,
            $duration,
            $start_time_utc,
            $end_time_utc,
        );

        // Solo agregar datos de aleatorización si hay randomización real
        if ($has_randomization) {
            $metadata = !empty($row->metadata) ? json_decode($row->metadata, true) : [];
            $assignment_form = $metadata['random_assignment']['form_id'] ?? '-';
            $assignment_seed = $metadata['random_assignment']['seed'] ?? '-';
            $assignment_type = $metadata['random_assignment']['type'] ?? '-';
            
            $row_data[] = $assignment_form;
            $row_data[] = $assignment_seed;
            $row_data[] = $assignment_type;
        }
        
        // v1.5.5 - RCT submission-based assignment data
        if ($has_rct_submission) {
            $row_data[] = $row->rct_assigned_variant ?? '-';
            $row_data[] = $row->rct_randomization_id ?? '-';
        }
        
        // v2.5.4 - Pool de estudios context data
        if ($has_pool_submissions) {
            $participant_key = 'email_' . md5($row->participant_id);
            $pool_data = $pool_context[$participant_key] ?? null;
            $row_data[] = $pool_data ? $pool_data['pool_code'] : '-';
            $row_data[] = $pool_data ? $pool_data['assigned_at'] : '-';
        }
        
        // Add metadata fields only if privacy config allows
        if ($privacy_config['ip_address']) {
            $row_data[] = $row->ip_address;
        }
        if ($privacy_config['device_type']) {
            $row_data[] = $row->device;
        }
        if ($privacy_config['browser']) {
            $row_data[] = $row->browser;
        }
        if ($privacy_config['os']) {
            $row_data[] = $row->os;
        }

        // === Agregar datos de Page Timings ===
        $metadata = !empty($row->metadata) ? json_decode($row->metadata, true) : [];
        foreach ($page_timing_keys as $page_key) {
            $page_data = $metadata['page_timings'][$page_key] ?? null;
            if ($page_data) {
                // Duration: usar rawDuration si existe, sino extraer de duration
                $duration = isset($page_data['rawDuration']) ? $page_data['rawDuration'] : (isset($page_data['duration']) ? floatval($page_data['duration']) : '');
                $row_data[] = $duration;
                // Timestamp
                $row_data[] = $page_data['timestamp'] ?? '';
            } else {
                $row_data[] = '';
                $row_data[] = '';
            }
        }

        // === Agregar datos de Field Timings ===
        foreach ($field_timing_keys as $field_name) {
            $field_data = $metadata['field_timings'][$field_name] ?? null;
            if ($field_data) {
                $row_data[] = $field_data['time_focused'] ?? '';
                $row_data[] = $field_data['interaction_count'] ?? '';
                $row_data[] = $field_data['focus_count'] ?? '';
            } else {
                $row_data[] = '';
                $row_data[] = '';
                $row_data[] = '';
            }
        }

        // === Agregar Total Duration ===
        if ($has_page_timings || $has_field_timings) {
            $total_duration = $metadata['page_timings']['total_duration'] ?? '';
            $row_data[] = $total_duration;
        }

        // v2.1.3 - Extended Device Metadata Values (ON by default based on privacy config)
        $device_data = $device_data_batch[$row->id] ?? null;
        if ($device_data) {
            if ($privacy_config['export_canvas_fingerprint'] ?? true) {
                $row_data[] = $device_data->canvas_fingerprint ?? '';
            }
            if ($privacy_config['export_webgl_renderer'] ?? true) {
                $row_data[] = $device_data->webgl_renderer ?? '';
            }
            if ($privacy_config['export_screen_resolution'] ?? true) {
                $row_data[] = $device_data->screen_resolution ?? '';
            }
            if ($privacy_config['export_screen_depth'] ?? true) {
                $row_data[] = $device_data->screen_depth ?? '';
            }
            if ($privacy_config['export_pixel_ratio'] ?? true) {
                $row_data[] = $device_data->pixel_ratio ?? '';
            }
            if ($privacy_config['export_timezone'] ?? true) {
                $row_data[] = $device_data->timezone ?? '';
            }
            if ($privacy_config['export_language'] ?? true) {
                $row_data[] = $device_data->language ?? '';
            }
            if ($privacy_config['export_cpu_cores'] ?? true) {
                $row_data[] = $device_data->cpu_cores ?? '';
            }
            if ($privacy_config['export_ram'] ?? true) {
                $row_data[] = $device_data->ram ?? '';
            }
            if ($privacy_config['export_plugins'] ?? true) {
                $row_data[] = $device_data->plugins ?? '';
            }
            if ($privacy_config['export_touch_support'] ?? true) {
                $row_data[] = $device_data->touch_support ?? '';
            }
            if ($privacy_config['export_cookies_enabled'] ?? true) {
                $row_data[] = $device_data->cookies_enabled ?? '';
            }
        } else {
            // Add empty values if no device data available
            $extended_metadata_count = 0;
            if ($privacy_config['export_canvas_fingerprint'] ?? true) $extended_metadata_count++;
            if ($privacy_config['export_webgl_renderer'] ?? true) $extended_metadata_count++;
            if ($privacy_config['export_screen_resolution'] ?? true) $extended_metadata_count++;
            if ($privacy_config['export_screen_depth'] ?? true) $extended_metadata_count++;
            if ($privacy_config['export_pixel_ratio'] ?? true) $extended_metadata_count++;
            if ($privacy_config['export_timezone'] ?? true) $extended_metadata_count++;
            if ($privacy_config['export_language'] ?? true) $extended_metadata_count++;
            if ($privacy_config['export_cpu_cores'] ?? true) $extended_metadata_count++;
            if ($privacy_config['export_ram'] ?? true) $extended_metadata_count++;
            if ($privacy_config['export_plugins'] ?? true) $extended_metadata_count++;
            if ($privacy_config['export_touch_support'] ?? true) $extended_metadata_count++;
            if ($privacy_config['export_cookies_enabled'] ?? true) $extended_metadata_count++;
            for ($i = 0; $i < $extended_metadata_count; $i++) {
                $row_data[] = '';
            }
        }

        // Agregar respuestas en el orden de las preguntas (excluir campos internos)
        foreach ($all_questions as $question) {
            $val = isset($form_data[$question]) ? $form_data[$question] : '';
            $row_data[] = eipsi_sanitize_export_value($val);
        }

        $data[] = $row_data;
    }

    $xlsx = EIPSI_Export_File_Service::xlsx($data);
    $form_suffix = isset($_GET['form_name']) ? '-' . sanitize_title($_GET['form_name']) : '';
    $filename = 'form-responses' . $form_suffix . '-' . date('Y-m-d-H-i-s') . '.xlsx';
    $xlsx->downloadAs($filename);
    exit;
    } catch (Exception $e) {
        error_log('EIPSI Forms Export Error (Excel): ' . $e->getMessage());
        wp_die(__('An error occurred while exporting to Excel. Please try again or contact support if the problem persists.', 'eipsi-forms'));
    }
}

public static function eipsi_export_to_csv() {
    try {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to perform this action.', 'eipsi-forms'));
        }

        $dataset=EIPSI_Export_Query_Service::raw_responses($_GET['form_id']??null);
        $results=$dataset['rows'];$export_source=$dataset['source'];
        header('X-EIPSI-Data-Source: ' . $export_source);
        if (empty($results)) {
            wp_die(__('No data to export.', 'eipsi-forms'));
        }
    
    // Get privacy config for first form (assuming same config per form_name)
    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/privacy-config.php';
    $first_form_id = !empty($results[0]->form_id) ? $results[0]->form_id : export_generate_stable_form_id($results[0]->form_name);
    $privacy_config = get_privacy_config($first_form_id);
    
    // Obtener todas las preguntas únicas para crear columnas (excluir campos internos)
    $internal_fields = array('action', 'eipsi_nonce', 'start_time', 'end_time', 'form_start_time', 'form_end_time', 'nonce', 'form_action', 'ip_address', 'device', 'browser', 'os', 'screen_width', 'current_page', 'form_id', 'eipsi_consent_accepted');
    
    // v2.5.3 - Orden determinístico: usar submission con más campos como referencia
    $all_questions = [];
    $reference_submission = [];
    $max_fields = 0;

    foreach ($results as $row) {
        $form_data = $row->form_responses ? EIPSI_Export_Service::strip_credentials(json_decode($row->form_responses, true)) : [];
        if (!is_array($form_data)) continue;
        
        // Filtrar campos internos
        $filtered = array_diff_key($form_data, array_flip($internal_fields));
        
        // Si este submission tiene más campos que el actual de referencia, usarlo como base
        if (count($filtered) > $max_fields) {
            $max_fields = count($filtered);
            $reference_submission = array_keys($filtered);
        }
    }

    // El orden de referencia es el del submission más completo
    $all_questions = $reference_submission;

    // Segunda pasada: agregar al final cualquier campo que no esté en la referencia
    foreach ($results as $row) {
        $form_data = $row->form_responses ? EIPSI_Export_Service::strip_credentials(json_decode($row->form_responses, true)) : [];
        if (!is_array($form_data)) continue;
        
        foreach ($form_data as $question => $answer) {
            if (!in_array($question, $all_questions) && !in_array($question, $internal_fields)) {
                $all_questions[] = $question; // Campos extras van al final
            }
        }
    }

    // === DETECTAR TIEMPOS POR PÁGINA Y CAMPO (CSV) ===
    $page_timing_keys = [];
    $field_timing_keys = [];
    $has_page_timings = false;
    $has_field_timings = false;

    foreach ($results as $row) {
        $metadata = !empty($row->metadata) ? json_decode($row->metadata, true) : [];

        // Detectar page_timings
        if (isset($metadata['page_timings']) && is_array($metadata['page_timings'])) {
            $has_page_timings = true;
            foreach ($metadata['page_timings'] as $key => $val) {
                // Excluir total_duration, solo procesar page_0, page_1, etc.
                if ($key !== 'total_duration' && strpos($key, 'page_') === 0) {
                    if (!in_array($key, $page_timing_keys)) {
                        $page_timing_keys[] = $key;
                    }
                }
            }
        }

        // Detectar field_timings
        if (isset($metadata['field_timings']) && is_array($metadata['field_timings'])) {
            $has_field_timings = true;
            foreach ($metadata['field_timings'] as $field_name => $field_data) {
                if (!in_array($field_name, $field_timing_keys)) {
                    $field_timing_keys[] = $field_name;
                }
            }
        }
    }

    // Ordenar page_timing_keys numéricamente (page_0, page_1, page_2, ...)
    usort($page_timing_keys, function($a, $b) {
        $num_a = intval(str_replace('page_', '', $a));
        $num_b = intval(str_replace('page_', '', $b));
        return $num_a <=> $num_b;
    });

    // Detectar si hay aleatorización real en los resultados
    $has_randomization = false;
    foreach ($results as $row) {
        $metadata = !empty($row->metadata) ? json_decode($row->metadata, true) : [];
        if (!empty($metadata['random_assignment']['form_id']) && $metadata['random_assignment']['form_id'] !== '-') {
            $has_randomization = true;
            break;
        }
    }
    
    header('Content-Type: text/csv; charset=utf-8');
    $form_suffix = isset($_GET['form_name']) ? '-' . sanitize_title($_GET['form_name']) : '';
    header('Content-Disposition: attachment; filename=form-responses' . $form_suffix . '-' . date('Y-m-d-H-i-s') . '.csv');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM UTF-8 para Excel
    
    // Encabezados: nuevo formato con IDs + metadatos + timestamps + preguntas dinámicas
    // ONLY include metadata columns if privacy config allows
    $headers = array('Form ID', 'Participant ID', 'Form Name', 'Date', 'Time', 'Duration(s)', 'Start Time (UTC)', 'End Time (UTC)');
    
    // === Columnas de Aleatorización (Fase 1) - Solo si hay datos reales ===
    if ($has_randomization) {
        $headers[] = 'Assignment Form';
        $headers[] = 'Seed';
        $headers[] = 'Type (Random/Manual)';
    }
    
    // v1.5.5 - RCT submission-based assignment columns
    $has_rct_submission = false;
    foreach ($results as $row) {
        if (!empty($row->rct_assigned_variant)) {
            $has_rct_submission = true;
            break;
        }
    }
    if ($has_rct_submission) {
        $headers[] = 'RCT Assigned Variant';
        $headers[] = 'RCT Randomization ID';
    }
    
    // v2.5.4 - Pool de estudios context
    $has_pool_submissions = false;
    $pool_context = array();
    if (function_exists('eipsi_get_pool_context_for_study')) {
        // Detectar si este formulario pertenece a un estudio con pool
        $pool_context = eipsi_get_pool_context_for_study($first_form_id);
        $has_pool_submissions = !empty($pool_context);
    }
    if ($has_pool_submissions) {
        $headers[] = 'Pool Code';
        $headers[] = 'Pool Assigned At';
    }
    
    if ($privacy_config['ip_address']) {
        $headers[] = 'IP Address';
    }
    if ($privacy_config['device_type']) {
        $headers[] = 'Device';
    }
    if ($privacy_config['browser']) {
        $headers[] = 'Browser';
    }
    if ($privacy_config['os']) {
        $headers[] = 'OS';
    }

    // === Columnas de Page Timings ===
    foreach ($page_timing_keys as $page_key) {
        $page_num = intval(str_replace('page_', '', $page_key));
        $headers[] = "Page {$page_num} - Duration(s)";
        $headers[] = "Page {$page_num} - Timestamp";
    }

    // === Columnas de Field Timings ===
    foreach ($field_timing_keys as $field_name) {
        $headers[] = "{$field_name} - Time(s)";
        $headers[] = "{$field_name} - Interactions";
        $headers[] = "{$field_name} - Focus Count";
    }

    // === Columna Total Duration ===
    if ($has_page_timings || $has_field_timings) {
        $headers[] = 'Total Duration(s)';
    }

    $headers = array_merge($headers, $all_questions);
    EIPSI_Export_Service::write_csv_row($output, $headers);
    
    foreach ($results as $row) {
        $form_data = $row->form_responses ? EIPSI_Export_Service::strip_credentials(json_decode($row->form_responses, true)) : [];
        
        // Generate IDs if not already present
        $form_id = !empty($row->form_id) ? $row->form_id : export_generate_stable_form_id($row->form_name);
        
        // Extract user data for fingerprint
        $user_data = array(
            'email' => '',
            'name' => ''
        );
        foreach ($form_data as $key => $value) {
            if (strtolower($key) === 'email' || strpos(strtolower($key), 'correo') !== false) {
                $user_data['email'] = $value;
            }
            if (strtolower($key) === 'name' || strtolower($key) === 'nombre') {
                $user_data['name'] = $value;
            }
        }
        
        $participant_id = !empty($row->participant_id) ? $row->participant_id : export_generateStableFingerprint($user_data);
        
        // Format date and time separately
        $datetime = !empty($row->submitted_at) ? $row->submitted_at : $row->created_at;
        $date = date('Y-m-d', strtotime($datetime));
        $time = date('H:i:s', strtotime($datetime));
        
        // Use duration_seconds if available, otherwise duration
        $duration = !empty($row->duration_seconds) ? number_format($row->duration_seconds, 3, '.', '') : $row->duration;
        
        // Format timestamps as ISO 8601 datetime strings
        $start_time_utc = '';
        $end_time_utc = '';
        if (!empty($row->start_timestamp_ms)) {
            $start_time_utc = gmdate('Y-m-d\TH:i:s.v\Z', intval($row->start_timestamp_ms / 1000));
        }
        if (!empty($row->end_timestamp_ms)) {
            $end_time_utc = gmdate('Y-m-d\TH:i:s.v\Z', intval($row->end_timestamp_ms / 1000));
        }

        // === Obtener datos de aleatorización (solo si hay randomización real) ===
        $row_data = array(
            $form_id,
            $participant_id,
            $row->form_name,
            $date,
            $time,
            $duration,
            $start_time_utc,
            $end_time_utc,
        );

        // Solo agregar datos de aleatorización si hay randomización real
        if ($has_randomization) {
            $metadata = !empty($row->metadata) ? json_decode($row->metadata, true) : [];
            $assignment_form = $metadata['random_assignment']['form_id'] ?? '-';
            $assignment_seed = $metadata['random_assignment']['seed'] ?? '-';
            $assignment_type = $metadata['random_assignment']['type'] ?? '-';
            
            $row_data[] = $assignment_form;
            $row_data[] = $assignment_seed;
            $row_data[] = $assignment_type;
        }
        
        // v1.5.5 - RCT submission-based assignment data
        if ($has_rct_submission) {
            $row_data[] = $row->rct_assigned_variant ?? '-';
            $row_data[] = $row->rct_randomization_id ?? '-';
        }
        
        // v2.5.4 - Pool de estudios context data
        if ($has_pool_submissions) {
            $participant_key = 'email_' . md5($row->participant_id);
            $pool_data = $pool_context[$participant_key] ?? null;
            $row_data[] = $pool_data ? $pool_data['pool_code'] : '-';
            $row_data[] = $pool_data ? $pool_data['assigned_at'] : '-';
        }
        
        // Add metadata fields only if privacy config allows
        if ($privacy_config['ip_address']) {
            $row_data[] = $row->ip_address;
        }
        if ($privacy_config['device_type']) {
            $row_data[] = $row->device;
        }
        if ($privacy_config['browser']) {
            $row_data[] = $row->browser;
        }
        if ($privacy_config['os']) {
            $row_data[] = $row->os;
        }

        // === Agregar datos de Page Timings ===
        $metadata = !empty($row->metadata) ? json_decode($row->metadata, true) : [];
        foreach ($page_timing_keys as $page_key) {
            $page_data = $metadata['page_timings'][$page_key] ?? null;
            if ($page_data) {
                // Duration: usar rawDuration si existe, sino extraer de duration
                $duration = isset($page_data['rawDuration']) ? $page_data['rawDuration'] : (isset($page_data['duration']) ? floatval($page_data['duration']) : '');
                $row_data[] = $duration;
                // Timestamp
                $row_data[] = $page_data['timestamp'] ?? '';
            } else {
                $row_data[] = '';
                $row_data[] = '';
            }
        }

        // === Agregar datos de Field Timings ===
        foreach ($field_timing_keys as $field_name) {
            $field_data = $metadata['field_timings'][$field_name] ?? null;
            if ($field_data) {
                $row_data[] = $field_data['time_focused'] ?? '';
                $row_data[] = $field_data['interaction_count'] ?? '';
                $row_data[] = $field_data['focus_count'] ?? '';
            } else {
                $row_data[] = '';
                $row_data[] = '';
                $row_data[] = '';
            }
        }

        // === Agregar Total Duration ===
        if ($has_page_timings || $has_field_timings) {
            $total_duration = $metadata['page_timings']['total_duration'] ?? '';
            $row_data[] = $total_duration;
        }

        // Agregar respuestas en el orden de las preguntas (excluir campos internos)
        foreach ($all_questions as $question) {
            $val = isset($form_data[$question]) ? $form_data[$question] : '';
            $row_data[] = eipsi_sanitize_export_value($val);
        }

        EIPSI_Export_Service::write_csv_row($output, $row_data);
    }

    fclose($output);
    exit;
    } catch (Exception $e) {
        error_log('EIPSI Forms Export Error (CSV): ' . $e->getMessage());
        if (isset($output) && is_resource($output)) {
            fclose($output);
        }
        wp_die(__('An error occurred while exporting to CSV. Please try again or contact support if the problem persists.', 'eipsi-forms'));
    }
}

public static function eipsi_export_longitudinal_to_excel() {
    // Check permissions before any output
    if (!current_user_can('manage_options')) {
        status_header(403);
        echo wp_json_encode(array('success' => false, 'message' => 'You do not have sufficient permissions to perform this action.'));
        exit;
    }

    $survey_id = isset($_GET['survey_id']) ? absint($_GET['survey_id']) : 0;
    if (!$survey_id) {
        wp_die(__('Invalid survey ID.', 'eipsi-forms'));
    }

    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-export-service.php';
    $export_service = new EIPSI_Export_Service();

    $filters = array(
        'wave_index' => isset($_GET['wave_index']) ? sanitize_text_field($_GET['wave_index']) : 'all',
        'date_from'  => isset($_GET['date_from'])  ? sanitize_text_field($_GET['date_from'])  : null,
        'date_to'    => isset($_GET['date_to'])     ? sanitize_text_field($_GET['date_to'])    : null,
        'status'     => isset($_GET['status'])      ? sanitize_text_field($_GET['status'])     : 'all',
    );

    $data     = $export_service->export_longitudinal_data($survey_id, $filters);
    $filename = $export_service->export_to_excel($data, $survey_id);

    // Download the file
    $export_dir = EIPSI_Export_File_Service::directory();
    $file_path  = $export_dir . '/' . $filename;

    if (file_exists($file_path)) {
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($file_path));
        readfile($file_path);
        exit;
    } else {
        wp_die(__('Export file not found.', 'eipsi-forms'));
    }
}

public static function eipsi_export_longitudinal_to_csv() {
    // Check permissions before any output
    if (!current_user_can('manage_options')) {
        status_header(403);
        echo wp_json_encode(array('success' => false, 'message' => 'You do not have sufficient permissions to perform this action.'));
        exit;
    }

    $survey_id = isset($_GET['survey_id']) ? absint($_GET['survey_id']) : 0;
    if (!$survey_id) {
        wp_die(__('Invalid survey ID.', 'eipsi-forms'));
    }

    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-export-service.php';
    $export_service = new EIPSI_Export_Service();

    $filters = array(
        'wave_index' => isset($_GET['wave_index']) ? sanitize_text_field($_GET['wave_index']) : 'all',
        'date_from'  => isset($_GET['date_from'])  ? sanitize_text_field($_GET['date_from'])  : null,
        'date_to'    => isset($_GET['date_to'])     ? sanitize_text_field($_GET['date_to'])    : null,
        'status'     => isset($_GET['status'])      ? sanitize_text_field($_GET['status'])     : 'all',
    );

    $data     = $export_service->export_longitudinal_data($survey_id, $filters);
    $filename = $export_service->export_to_csv($data, $survey_id);

    // Download the file
    $export_dir = EIPSI_Export_File_Service::directory();
    $file_path  = $export_dir . '/' . $filename;

    if (file_exists($file_path)) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($file_path));
        readfile($file_path);
        exit;
    } else {
        wp_die(__('Export file not found.', 'eipsi-forms'));
    }
}

public static function eipsi_export_participants_to_excel() {
    if (!current_user_can('manage_options')) {
        status_header(403);
        wp_die(__('You do not have sufficient permissions.', 'eipsi-forms'));
    }

    $study_id = isset($_GET['study_id']) ? absint($_GET['study_id']) : 0;
    if (!$study_id) {
        wp_die(__('Invalid study ID.', 'eipsi-forms'));
    }

    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-export-service.php';
    $export_service = new EIPSI_Export_Service();

    $filters = array(
        'status'     => isset($_GET['status'])     ? sanitize_text_field($_GET['status'])     : 'all',
        'wave_index' => isset($_GET['wave_index']) ? sanitize_text_field($_GET['wave_index']) : 'all',
        'search'     => isset($_GET['search'])     ? sanitize_text_field($_GET['search'])     : '',
        'date_from'  => isset($_GET['date_from'])  ? sanitize_text_field($_GET['date_from'])  : null,
        'date_to'    => isset($_GET['date_to'])    ? sanitize_text_field($_GET['date_to'])    : null,
    );

    $filename = $export_service->export_participants_to_excel($study_id, $filters);

    $export_dir = EIPSI_Export_File_Service::directory();
    $file_path  = $export_dir . '/' . $filename;

    if (file_exists($file_path)) {
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($file_path));
        header('Cache-Control: max-age=0');
        readfile($file_path);
        @unlink($file_path);
        exit;
    } else {
        wp_die(__('Export file not found.', 'eipsi-forms'));
    }
}

public static function eipsi_export_participants_to_csv() {
    if (!current_user_can('manage_options')) {
        status_header(403);
        wp_die(__('You do not have sufficient permissions.', 'eipsi-forms'));
    }

    $study_id = isset($_GET['study_id']) ? absint($_GET['study_id']) : 0;
    if (!$study_id) {
        wp_die(__('Invalid study ID.', 'eipsi-forms'));
    }

    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-export-service.php';
    $export_service = new EIPSI_Export_Service();

    $filters = array(
        'status'     => isset($_GET['status'])     ? sanitize_text_field($_GET['status'])     : 'all',
        'wave_index' => isset($_GET['wave_index']) ? sanitize_text_field($_GET['wave_index']) : 'all',
        'search'     => isset($_GET['search'])     ? sanitize_text_field($_GET['search'])     : '',
        'date_from'  => isset($_GET['date_from'])  ? sanitize_text_field($_GET['date_from'])  : null,
        'date_to'    => isset($_GET['date_to'])    ? sanitize_text_field($_GET['date_to'])    : null,
    );

    $study_title = eipsi_get_study_title($study_id);
    $slug        = sanitize_title($study_title);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="participantes-' . $slug . '-' . date('Y-m-d') . '.csv"');
    header('Cache-Control: max-age=0');

    $output = fopen('php://output', 'w');
    // UTF-8 BOM so Excel opens it correctly
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    $export_service->stream_participants_csv($study_id, $filters, $output);

    fclose($output);
    exit;
}

public static function eipsi_get_study_title($study_id) {
    global $wpdb;
    $title = $wpdb->get_var($wpdb->prepare(
        "SELECT study_name FROM {$wpdb->prefix}survey_studies WHERE id = %d",
        $study_id
    ));
    return $title ? $title : 'estudio-' . $study_id;
}

public static function eipsi_get_pool_context_for_study($study_id) {
    global $wpdb;
    
    $table = $wpdb->prefix . 'eipsi_pool_assignments';
    
    // Buscar asignaciones donde el study_id coincida
    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT participant_id, pool_id as pool_code, assigned_at 
         FROM {$table} 
         WHERE study_id = %s",
        $study_id
    ));
    
    $context = array();
    foreach ($results as $row) {
        // El participant_id guardado es 'email_' . md5(email)
        // Necesitamos mapearlo al formato usado en las respuestas
        $context[$row->participant_id] = array(
            'pool_code' => $row->pool_code,
            'assigned_at' => $row->assigned_at
        );
    }
    
    return $context;
}

public static function eipsi_generate_pool_context_csv($form_id, $study_id) {
    $responses = eipsi_export_responses_with_pool_context($form_id, $study_id);
    
    if (empty($responses)) {
        return false;
    }
    
    $output = fopen('php://temp', 'r+');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM UTF-8
    
    // Headers
    $headers = array(
        'Submission ID',
        'Participant ID', 
        'Submitted At',
        'Pool Code',
        'Pool Assigned At',
        'Assignment Method',
        'Form Responses (JSON)'
    );
    EIPSI_Export_Service::write_csv_row($output, $headers);
    
    // Data
    foreach ($responses as $row) {
        $data = array(
            $row->id,
            $row->participant_id,
            $row->submitted_at ?? $row->created_at,
            $row->pool_code ?: '-',
            $row->pool_assigned_at ?: '-',
            $row->pool_assignment_method ?: '-',
            wp_json_encode(EIPSI_Export_Service::strip_credentials(json_decode($row->form_responses,true)))
        );
        EIPSI_Export_Service::write_csv_row($output, $data);
    }
    
    rewind($output);
    $csv = stream_get_contents($output);
    fclose($output);
    
    return $csv;
}
}
