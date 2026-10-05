<?php
/** M3 owner; external contracts remain behind their existing facades. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Form_Capture_Service {
    public static function capture($request, $server, $form_name, $submission_context) {
        global $wpdb;
        $authenticated_participant_id=$submission_context['participant_id'];
        $authenticated_study_id=$submission_context['study_id'];
    // ✅ v2.0.1 - Capturar datos del dispositivo desde múltiples fuentes
    // Fuente 1: Campos individuales (legacy)
    $device = isset($request['device']) ? sanitize_text_field($request['device']) : '';
    $browser_raw = isset($request['browser']) ? sanitize_text_field($request['browser']) : '';
    $os_raw = isset($request['os']) ? sanitize_text_field($request['os']) : '';
    $screen_width_raw = isset($request['screen_width']) ? sanitize_text_field($request['screen_width']) : '';

    // ✅ v2.1.3 - Store raw device data for saving to database
    $device_data_raw = null;

    // Fuente 2: eipsi_device_data JSON (current approach)
    // Si no tenemos datos individuales, extraer del JSON del fingerprint
    if (empty($device) || empty($browser_raw) || empty($os_raw)) {
        $device_data_json = isset($request['eipsi_device_data']) ? wp_unslash($request['eipsi_device_data']) : '';
        if (!empty($device_data_json)) {
            $device_data = json_decode($device_data_json, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($device_data)) {
                // ✅ Guardar datos completos para almacenar en DB
                $device_data_raw = $device_data;

                // Extraer device type desde user agent
                if (empty($device) && !empty($device_data['user_agent'])) {
                    $device = eipsi_detect_device_type($device_data['user_agent']);
                }
                // Extraer browser desde user agent
                if (empty($browser_raw) && !empty($device_data['user_agent'])) {
                    $browser_raw = eipsi_detect_browser($device_data['user_agent']);
                }
                // Extraer OS desde platform o user agent
                if (empty($os_raw)) {
                    $platform = $device_data['platform'] ?? '';
                    // Usar platform solo si tiene valor real (no vacío, no "unknown", no null)
                    if (!empty($platform) && strtolower($platform) !== 'unknown' && strlen(trim($platform)) > 2) {
                        $os_raw = trim($platform);
                    } elseif (!empty($device_data['user_agent'])) {
                        $os_raw = eipsi_detect_os($device_data['user_agent']);
                    }
                }
                // Extraer screen width
                if (empty($screen_width_raw) && !empty($device_data['screen_resolution'])) {
                    // screen_resolution viene como "1920x1080", extraer ancho
                    $screen_width_raw = explode('x', $device_data['screen_resolution'])[0] ?? '';
                }
            }
        }
    }

    // Capturar IP del participante con detección de proxy
    $ip_address_raw = $server['REMOTE_ADDR'] ?? 'unknown';

    // Si está detrás de proxy/CDN (Cloudflare, Load Balancer, etc.)
    if (!empty($server['HTTP_CF_CONNECTING_IP'])) {
        $ip_address_raw = $server['HTTP_CF_CONNECTING_IP'];
    } elseif (!empty($server['HTTP_X_FORWARDED_FOR'])) {
        $ip_address_raw = trim(explode(',', $server['HTTP_X_FORWARDED_FOR'])[0]);
    }

    // Validar IP
    $ip_address_raw = filter_var($ip_address_raw, FILTER_VALIDATE_IP) ?: 'invalid';
    $start_time = isset($request['form_start_time']) ? sanitize_text_field($request['form_start_time']) : '';
    $end_time = isset($request['form_end_time']) ? sanitize_text_field($request['form_end_time']) : '';

    // ✅ v1.4.0 - Capturar user fingerprint desde POST
    $user_fingerprint = isset($request['eipsi_user_fingerprint']) ? sanitize_text_field($request['eipsi_user_fingerprint']) : '';

    // ✅ v1.5.4 - Capturar detalles crudos del fingerprint
    $fingerprint_raw = isset($request['eipsi_fingerprint_raw']) ? wp_unslash($request['eipsi_fingerprint_raw']) : '';
    $fingerprint_raw_array = null;

    if (!empty($fingerprint_raw)) {
        $fingerprint_raw_decoded = json_decode($fingerprint_raw, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $fingerprint_raw_array = $fingerprint_raw_decoded;
        }
    }


    // Obtener IDs universales del frontend
    $frontend_participant_id = isset($request['participant_id']) ? sanitize_text_field($request['participant_id']) : '';
    $session_id = isset($request['session_id']) ? sanitize_text_field($request['session_id']) : '';

    // Capturar metadata del frontend incluyendo page_transitions
    $frontend_metadata = isset($request['metadata']) ? wp_unslash($request['metadata']) : '';
    $metadata_array = null;

    error_log("[EIPSI-SUBMIT-DIAG] Metadata received, bytes=" . strlen($frontend_metadata));

    if (!empty($frontend_metadata)) {
        $metadata_decoded = json_decode($frontend_metadata, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $metadata_array = $metadata_decoded;
            error_log("[EIPSI-SUBMIT-DIAG] Metadata decoded successfully, keys=" . implode(',', array_keys($metadata_array)));
            if (isset($metadata_array['device_data'])) {
                error_log("[EIPSI-SUBMIT-DIAG] Device data keys=" . implode(',', array_keys($metadata_array['device_data'])));
                error_log("[EIPSI-SUBMIT-DIAG] Canvas: " . substr($metadata_array['device_data']['canvas_fingerprint'] ?? 'NULL', 0, 20));
                error_log("[EIPSI-SUBMIT-DIAG] WebGL: " . substr($metadata_array['device_data']['webgl_renderer'] ?? 'NULL', 0, 20));
            } else {
                error_log("[EIPSI-SUBMIT-DIAG] WARNING: No device_data in metadata");
            }
        } else {
            error_log("[EIPSI-SUBMIT-DIAG] ERROR decoding metadata: " . json_last_error_msg());
        }
    } else {
        error_log("[EIPSI-SUBMIT-DIAG] WARNING: No metadata received from frontend");
    }

    $form_responses = array();
    $exclude_fields = array('form_id', 'form_action', 'ip_address', 'device', 'browser', 'os', 'screen_width', 'form_start_time', 'form_end_time', 'current_page', 'nonce', 'action', 'participant_id', 'session_id', 'metadata', 'end_timestamp_ms', 'eipsi_user_fingerprint', 'eipsi_fingerprint_raw', 'survey_id', 'study_id', 'wave_id', 'longitudinal_participant_id');  // ✅ v1.5.4 - Agregar fingerprint fields

    $user_data = array(
        'email' => '',
        'name' => ''
    );

    foreach ($request as $key => $value) {
        if (!in_array($key, $exclude_fields)) {
            if (is_array($value)) {
                // Support for array values (checkboxes/multi-select)
                $form_responses[$key] = array_map('sanitize_text_field', $value);
            } elseif (is_string($value)) {
                $form_responses[$key] = sanitize_text_field($value);

                if (strtolower($key) === 'email' || strpos(strtolower($key), 'correo') !== false) {
                    $user_data['email'] = sanitize_email($value);
                }
                if (strtolower($key) === 'name' || strtolower($key) === 'nombre') {
                    $user_data['name'] = sanitize_text_field($value);
                }
            }
        }
    }

    $start_timestamp_ms = null;
    $end_timestamp_ms = null;
    $duration = 0;
    $duration_seconds = 0.0;

    if (!empty($start_time)) {
        $start_timestamp_ms = intval($start_time);

        // === FIJO: Usar end_timestamp_ms del frontend si existe ===
        // Esto evita el error de ~0.6s por delay de red
        $frontend_end_timestamp_ms = isset($request['end_timestamp_ms']) ? intval($request['end_timestamp_ms']) : null;

        if (!empty($frontend_end_timestamp_ms)) {
            // Usar timestamp del frontend (preciso, sin delay de red)
            $end_timestamp_ms = $frontend_end_timestamp_ms;
            $duration_ms = max(0, $end_timestamp_ms - $start_timestamp_ms);
            $duration = intval($duration_ms / 1000);
            $duration_seconds = round($duration_ms / 1000, 3);
        } elseif (!empty($end_time)) {
            // Fallback: usar form_end_time si no hay end_timestamp_ms separado
            $end_timestamp_ms = intval($end_time);
            $duration_ms = max(0, $end_timestamp_ms - $start_timestamp_ms);
            $duration = intval($duration_ms / 1000);
            $duration_seconds = round($duration_ms / 1000, 3);
        } else {
            // Último fallback: recapturar en backend (legacy)
            $current_timestamp_ms = round(microtime(true) * 1000);
            $end_timestamp_ms = $current_timestamp_ms;
            $duration_ms = max(0, $end_timestamp_ms - $start_timestamp_ms);
            $duration = intval($duration_ms / 1000);
            $duration_seconds = round($duration_ms / 1000, 3);
        }
    }

    $stable_form_id = generate_stable_form_id($form_name);

    // Usar Participant ID universal del frontend si está disponible, sino fallback al viejo sistema
    $participant_id = !empty($frontend_participant_id) ? $frontend_participant_id : generateStableFingerprint($user_data);

    // The browser participant_id remains a tracking key, never a longitudinal identity.
    $partial_participant_id = $participant_id;
    $longitudinal_participant_id = $authenticated_participant_id;
    $study_id = $authenticated_study_id;
    $wave_id = $submission_context['wave_id'];
    $wave_index = $submission_context['wave_index'];
    if ($submission_context['longitudinal']) {
        $participant_id = (string) $longitudinal_participant_id;
    }

    $submitted_at = current_time('mysql');

    // Obtener configuración de privacidad
    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/privacy-config.php';
    $privacy_config = get_privacy_config($stable_form_id);

    // Aplicar privacy config a los campos capturados
    $browser = ($privacy_config['browser'] ?? false) ? $browser_raw : null;
    $os = ($privacy_config['os'] ?? false) ? $os_raw : null;
    $screen_width = ($privacy_config['screen_width'] ?? false) ? $screen_width_raw : null;
    $ip_address = ($privacy_config['ip_address'] ?? true) ? $ip_address_raw : null;

    // Construir metadatos según configuración de privacidad
    // Primero, si tenemos metadata del frontend, lo usamos como base
    $metadata = array();

    // Si tenemos metadata del frontend (incluyendo page_transitions), lo preservamos
    if ($metadata_array && is_array($metadata_array)) {
        // Mantener los datos del frontend (page_transitions, form_start_time, device_type, etc.)
        $metadata = $metadata_array;
    } else {
        // Fallback a la estructura original si no hay metadata del frontend
        $metadata = array();
    }

    $metadata['participant_id'] = $participant_id;
    if ($submission_context['longitudinal']) {
        $metadata['longitudinal_participant_id'] = $longitudinal_participant_id;
        $metadata['survey_id'] = $study_id;
        $metadata['study_id'] = $study_id;
        $metadata['wave_id'] = $wave_id;
        $metadata['wave_index'] = $wave_index;
    } else {
        unset($metadata['longitudinal_participant_id'], $metadata['survey_id'], $metadata['study_id'], $metadata['wave_id'], $metadata['wave_index']);
    }

    // Asegurar que siempre tengamos los campos base
    if (!isset($metadata['form_id'])) {
        $metadata['form_id'] = $stable_form_id;
    }
    if (!isset($metadata['participant_id'])) {
        $metadata['participant_id'] = $participant_id;
    }
    if (!isset($metadata['session_id'])) {
        $metadata['session_id'] = $session_id;
    }

    // TIMESTAMPS (SIEMPRE)
    $metadata['timestamps'] = array(
        'start' => $start_timestamp_ms,
        'end' => $end_timestamp_ms,
        'duration_seconds' => $duration_seconds
    );

    // DEVICE INFO (según privacy config)
    $device_info = array();
    if ($privacy_config['device_type']) {
        $device_info['device_type'] = $device;
    }
    if ($browser !== null) {
        $device_info['browser'] = $browser;
    }
    if ($os !== null) {
        $device_info['os'] = $os;
    }
    if ($screen_width !== null) {
        $device_info['screen_width'] = $screen_width;
    }
    if (!empty($device_info)) {
        $metadata['device_info'] = $device_info;
    }

    // ✅ v1.5.4 - FINGERPRINT RAW DETAILS (según fingerprint_enabled)
    if (($privacy_config['fingerprint_enabled'] ?? true) && $fingerprint_raw_array) {
        $metadata['fingerprint_raw'] = $fingerprint_raw_array;
    }

    // NETWORK INFO (según privacy config)
    if ($ip_address !== null) {
        $metadata['network_info'] = array(
            'ip_address' => $ip_address,
            'ip_storage_type' => ($privacy_config['ip_address'] ?? true) ? 'full' : 'anonymized'
        );
    }

    // Removed in v1.0: Quality Flags and Avoidance Patterns deprecated
    // Clinical metadata is now strictly objective (Timing and Completion)
    $metadata['quality_metrics'] = array(
        'completion_rate' => 1.0
    );

    // CONSENT INFO
    if (isset($request['eipsi_consent_accepted']) && $request['eipsi_consent_accepted'] === 'on') {
        $metadata['consent_given'] = true;
        $metadata['consent_timestamp'] = current_time('Y-m-d\TH:i:s\Z');
        $metadata['consent_ip'] = ($privacy_config['ip_address'] ?? true) ? $ip_address_raw : 'anonymized';
        $metadata['consent_user_agent'] = $server['HTTP_USER_AGENT'] ?? 'unknown';
    }

    // RANDOMIZATION INFO - Guardar datos si existen
    $random_assignment = array(
        'form_id' => isset($request['assignment_form_id']) ? sanitize_text_field($request['assignment_form_id']) : '-',
        'seed' => isset($request['assignment_seed']) ? sanitize_text_field($request['assignment_seed']) : '-',
        'type' => isset($request['assignment_type']) ? sanitize_text_field($request['assignment_type']) : '-'
    );

    // Solo guardar en metadata si hay datos reales (no placeholder)
    if ($random_assignment['form_id'] !== '-' || $random_assignment['seed'] !== '-' || $random_assignment['type'] !== '-') {
        $metadata['random_assignment'] = $random_assignment;
    }

    // v1.5.5 - RCT at submission time: Calculate assignment server-side
    $rct_assigned_variant = null;
    $rct_randomization_id = null;

    // Get the form post ID from form name to check for RCT config
    $form_posts = get_posts(array(
        'post_type' => array('eipsi_form', 'eipsi_form_template'),
        'post_status' => 'publish',
        'posts_per_page' => 1,
        'fields' => 'ids',
        'meta_query' => array(
            array(
                'key' => '_eipsi_form_name',
                'value' => $form_name,
                'compare' => '=',
            )
        ),
    ));

    $form_post_id = !empty($form_posts) ? intval($form_posts[0]) : 0;

    // Calculate RCT assignment at submission time if RCT config exists for this form
    if (!empty($user_fingerprint) && $form_post_id > 0) {
        require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/randomization-db-setup.php';

        $timestamp_for_seed = $end_timestamp_ms ?? round(microtime(true) * 1000);
        $rct_assignment = eipsi_calculate_submission_assignment($user_fingerprint, $form_post_id, $timestamp_for_seed);

        if (!empty($rct_assignment)) {
            $rct_assigned_variant = $rct_assignment['assigned_variant'];
            $rct_randomization_id = $rct_assignment['randomization_id'];

            // Update metadata with server-calculated assignment
            $metadata['random_assignment'] = array(
                'form_id' => strval($rct_assignment['assigned_form_id']),
                'seed' => $rct_assignment['seed'],
                'type' => 'server-calculated',
                'method' => $rct_assignment['method'] ?? 'seeded'
            );

            error_log("[EIPSI Forms] RCT Assignment calculated at submission: {$rct_assigned_variant} for fingerprint {$user_fingerprint}");
        }
    }

    // Scrub raw frontend metadata and answers as well as the canonical columns.
    $form_responses = eipsi_filter_capture_data($form_responses, $privacy_config);
    $metadata = eipsi_filter_capture_data($metadata, $privacy_config);
    $metadata_array = eipsi_filter_capture_data($metadata_array ?: array(), $privacy_config);
    $device = !empty($privacy_config['device_type']) ? $device : null;
    $user_fingerprint = ($privacy_config['fingerprint_enabled'] ?? true) ? $user_fingerprint : null;

    // Prepare data for insertion
    $data = array(
        'form_id' => $stable_form_id,
        'participant_id' => $participant_id,
        'survey_id' => $study_id,  // ✅ v1.5.6 - Corregido: era $survey_id (undefined)
        'wave_index' => $wave_index,
        'longitudinal_participant_id' => $authenticated_participant_id ?: null,
        'session_id' => $session_id,
        'user_fingerprint' => $user_fingerprint,  // ✅ v1.4.0 - Guardar fingerprint
        'form_name' => $form_name,
        'created_at' => current_time('mysql'),
        'submitted_at' => $submitted_at,
        'ip_address' => $ip_address,
        'device' => $device,
        'browser' => $browser,
        'os' => $os,
        'screen_width' => $screen_width,
        'duration' => $duration,
        'duration_seconds' => $duration_seconds,
        'start_timestamp_ms' => $start_timestamp_ms,
        'end_timestamp_ms' => $end_timestamp_ms,
        'metadata' => wp_json_encode($metadata),
        'status' => 'submitted',
        'form_responses' => wp_json_encode($form_responses),
        // v1.5.5 - RCT at submission time
        'rct_assigned_variant' => $rct_assigned_variant,
        'rct_randomization_id' => $rct_randomization_id
    );


        return compact('data', 'metadata_array', 'partial_participant_id', 'session_id', 'wave_id', 'study_id', 'longitudinal_participant_id', 'user_data', 'stable_form_id', 'wave_index', 'submitted_at');
    }
    public static function eipsi_detect_device_type($user_agent) {
        if (empty($user_agent)) {
            return 'unknown';
        }

        $ua = strtolower($user_agent);

        // Tablet detection
        if (preg_match('/(tablet|ipad|android(?!.*mobile)|kindle|silk|playbook)/', $ua)) {
            return 'tablet';
        }

        // Mobile detection
        if (preg_match('/(mobile|iphone|ipod|android|blackberry|windows phone|palm|operamini|opera mini)/', $ua)) {
            return 'mobile';
        }

        // Desktop (default)
        return 'desktop';
    }
    public static function eipsi_detect_browser($user_agent) {
        if (empty($user_agent)) {
            return 'unknown';
        }

        $ua = strtolower($user_agent);

        // Common browsers in order of specificity
        if (preg_match('/edg\//', $ua)) {
            return 'Edge';
        }
        if (preg_match('/opr|opera/', $ua)) {
            return 'Opera';
        }
        if (preg_match('/firefox/', $ua)) {
            return 'Firefox';
        }
        if (preg_match('/safari/', $ua) && !preg_match('/chrome|chromium/', $ua)) {
            return 'Safari';
        }
        if (preg_match('/chrome|chromium/', $ua)) {
            return 'Chrome';
        }
        if (preg_match('/msie|trident/', $ua)) {
            return 'IE';
        }

        return 'unknown';
    }
    public static function eipsi_detect_os($user_agent) {
        if (empty($user_agent)) {
            return 'unknown';
        }

        $ua = strtolower($user_agent);

        // Windows - múltiples formatos para compatibilidad moderna
        if (preg_match('/windows nt 10\.0/', $ua) || preg_match('/windows nt 10/', $ua)) {
            return 'Windows 10/11';
        }
        if (preg_match('/windows nt 6\.3/', $ua)) {
            return 'Windows 8.1';
        }
        if (preg_match('/windows nt 6\.2/', $ua)) {
            return 'Windows 8';
        }
        if (preg_match('/windows nt 6\.1/', $ua)) {
            return 'Windows 7';
        }
        if (preg_match('/windows nt 6\.0/', $ua)) {
            return 'Windows Vista';
        }
        if (preg_match('/windows nt 5\.[12]/', $ua)) {
            return 'Windows XP/2003';
        }
        // Formatos alternativos (Win64, Win32 sin NT version)
        if (preg_match('/win64|win32|windows/', $ua)) {
            return 'Windows';
        }

        // macOS
        if (preg_match('/macintosh|mac os x|macos/', $ua)) {
            return 'macOS';
        }

        // iOS (iPhone/iPad)
        if (preg_match('/iphone|ipad|ipod/', $ua)) {
            return 'iOS';
        }

        // Android
        if (preg_match('/android/', $ua)) {
            return 'Android';
        }

        // Linux y variantes
        if (preg_match('/linux/', $ua)) {
            return 'Linux';
        }

        // Chrome OS
        if (preg_match('/cros|chrome os|chromeos/', $ua)) {
            return 'Chrome OS';
        }

        return 'unknown';
    }
    public static function generateStableFingerprint($user_data) {
        $components = array(
            'email' => strtolower(trim($user_data['email'] ?? '')),
            'name' => normalizeName($user_data['name'] ?? ''),
        );

        $fingerprint_string = implode('|', array_filter($components));

        if ($fingerprint_string) {
            $hash = substr(hash('sha256', $fingerprint_string), 0, 8);
            return "FP-{$hash}";
        } else {
            $session_id = session_id();
            if (empty($session_id)) {
                session_start();
                $session_id = session_id();
            }
            $remote_addr = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $session_hash = substr(md5($session_id . $remote_addr), 0, 6);
            return "FP-SESS-{$session_hash}";
        }
    }
    public static function normalizeName($name) {
        return strtoupper(trim($name));
    }
    public static function eipsi_get_research_context($device, $duration) {
        if ($device === 'mobile') {
            return '📱 Posible contexto informal';
        } else {
            return '💻 Posible contexto formal';
        }
    }
    public static function eipsi_get_time_context($datetime) {
        $hour = date('H', strtotime($datetime));

        if ($hour >= 6 && $hour < 12) return '🌅 Mañana';
        if ($hour >= 12 && $hour < 18) return '🌞 Tarde';
        if ($hour >= 18 && $hour < 22) return '🌆 Noche';
        return '🌙 Madrugada';
    }
    public static function eipsi_get_platform_type($device, $screen_width) {
        if ($device === 'mobile') {
            if ($screen_width < 400) return '📱 Teléfono pequeño';
            if ($screen_width < 768) return '📱 Teléfono estándar';
            return '📱 Teléfono grande/Tablet pequeña';
        } else {
            if ($screen_width < 1200) return '💻 Laptop';
            return '🖥️ Desktop grande';
        }
    }
}
