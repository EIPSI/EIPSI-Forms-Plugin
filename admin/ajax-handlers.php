<?php
if (!defined('ABSPATH')) {
    exit;
}

function generate_stable_form_id($form_name) {
    global $wpdb;
    
    $initials = get_form_initials($form_name);
    
    if (strlen($initials) < 2) {
        $slug = sanitize_title($form_name);
        $initials = strtoupper(substr($slug, 0, 3));
    }
    
    $slug = sanitize_title($form_name);
    $hash = substr(md5($slug), 0, 6);
    $form_id = "{$initials}-{$hash}";
    
    $table_name = $wpdb->prefix . 'vas_form_results';
    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table_name WHERE form_id = %s",
        $form_id
    ));
    
    if ($exists > 0) {
        return $form_id;
    }
    
    return $form_id;
}

function get_form_initials($form_name) {
    // Handle null or non-string input safely
    if (empty($form_name)) {
        return 'UNK';
    }
    
    $words = explode(' ', trim((string) $form_name));
    $initials = '';
    
    foreach ($words as $word) {
        // Limpiar caracteres especiales
        $clean_word = preg_replace('/[^a-zA-Z0-9]/', '', $word);
        
        if (!empty($clean_word)) {
            if (strlen($clean_word) >= 3) {
                $initials .= strtoupper(substr($clean_word, 0, 3));
            } else {
                $initials .= strtoupper($clean_word); // Palabra completa si < 3
            }
            
            if (strlen($initials) >= 3) break; // Máximo 3 caracteres total
        }
    }
    
    return !empty($initials) ? $initials : 'UNK'; // Fallback
}

/**
 * Study status resolver (ethics guard)
 * Finds template by _eipsi_form_name (formId slug) and returns open|closed.
 *
 * @param string $form_name
 * @return string
 */
function eipsi_get_study_status_for_form_name($form_name) {
    $form_name = sanitize_text_field($form_name);

    if (empty($form_name)) {
        return 'open';
    }

    $templates = get_posts(array(
        'post_type' => 'eipsi_form_template',
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

    if (empty($templates)) {
        return 'open';
    }

    $template_id = (int) $templates[0];
    $status = get_post_meta($template_id, '_eipsi_study_status', true);

    return ($status === 'closed') ? 'closed' : 'open';
}

/**
 * Device type detection from User Agent
 * 
 * @param string $user_agent
 * @return string 'desktop', 'mobile', 'tablet', or 'unknown'
 */
function eipsi_detect_device_type($user_agent) {
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

/**
 * Browser detection from User Agent
 * 
 * @param string $user_agent
 * @return string Browser name or 'unknown'
 */
function eipsi_detect_browser($user_agent) {
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

/**
 * OS detection from User Agent
 * 
 * @param string $user_agent
 * @return string OS name or 'unknown'
 */
function eipsi_detect_os($user_agent) {
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

function generateStableFingerprint($user_data) {
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

function normalizeName($name) {
    return strtoupper(trim($name));
}

add_action('wp_ajax_nopriv_eipsi_forms_submit_form', 'eipsi_forms_submit_form_handler');
add_action('wp_ajax_eipsi_forms_submit_form', 'eipsi_forms_submit_form_handler');

add_action('wp_ajax_nopriv_eipsi_get_response_details', 'eipsi_ajax_get_response_details');
add_action('wp_ajax_eipsi_get_response_details', 'eipsi_ajax_get_response_details');

add_action('wp_ajax_nopriv_eipsi_track_event', 'eipsi_track_event_handler');
add_action('wp_ajax_eipsi_track_event', 'eipsi_track_event_handler');

add_action('wp_ajax_eipsi_test_db_connection', 'eipsi_test_db_connection_handler');
add_action('wp_ajax_eipsi_save_db_config', 'eipsi_save_db_config_handler');
add_action('wp_ajax_eipsi_disable_external_db', 'eipsi_disable_external_db_handler');
add_action('wp_ajax_eipsi_get_db_status', 'eipsi_get_db_status_handler');
add_action('wp_ajax_eipsi_check_external_db', 'eipsi_check_external_db_handler');
add_action('wp_ajax_nopriv_eipsi_check_external_db', 'eipsi_check_external_db_handler');

add_action('wp_ajax_eipsi_save_privacy_config', 'eipsi_save_privacy_config_handler');
add_action('wp_ajax_eipsi_save_global_privacy_config', 'eipsi_save_global_privacy_config_handler');
add_action('wp_ajax_eipsi_verify_schema', 'eipsi_verify_schema_handler');
add_action('wp_ajax_eipsi_verify_local_schema', 'eipsi_verify_local_schema_handler');
add_action('wp_ajax_eipsi_check_table_status', 'eipsi_check_table_status_handler');
add_action('wp_ajax_eipsi_check_local_table_status', 'eipsi_check_local_table_status_handler');
add_action('wp_ajax_eipsi_delete_all_data', 'eipsi_delete_all_data_handler');

// SMTP configuration handlers
add_action('wp_ajax_eipsi_test_smtp_connection', 'eipsi_test_smtp_connection_handler');
add_action('wp_ajax_eipsi_save_smtp_config', 'eipsi_save_smtp_config_handler');
add_action('wp_ajax_eipsi_disable_smtp', 'eipsi_disable_smtp_handler');

// Thank-you page handlers
add_action('wp_ajax_nopriv_eipsi_get_completion_config', 'eipsi_get_completion_config_handler');
add_action('wp_ajax_eipsi_get_completion_config', 'eipsi_get_completion_config_handler');
add_action('wp_ajax_nopriv_eipsi_get_site_logo', 'eipsi_get_site_logo_handler');
add_action('wp_ajax_eipsi_get_site_logo', 'eipsi_get_site_logo_handler');

// Save & Continue handlers
add_action('wp_ajax_nopriv_eipsi_save_partial_response', 'eipsi_save_partial_response_handler');
add_action('wp_ajax_eipsi_save_partial_response', 'eipsi_save_partial_response_handler');
add_action('wp_ajax_nopriv_eipsi_load_partial_response', 'eipsi_load_partial_response_handler');
add_action('wp_ajax_eipsi_load_partial_response', 'eipsi_load_partial_response_handler');
add_action('wp_ajax_nopriv_eipsi_discard_partial_response', 'eipsi_discard_partial_response_handler');
add_action('wp_ajax_eipsi_discard_partial_response', 'eipsi_discard_partial_response_handler');

// === Handlers de Aleatorización (Fase 1) ===
add_action('wp_ajax_eipsi_random_assign', 'eipsi_random_assign_handler');
add_action('wp_ajax_nopriv_eipsi_random_assign', 'eipsi_random_assign_handler');
add_action('wp_ajax_eipsi_get_forms_list', 'eipsi_get_forms_list_handler');
add_action('wp_ajax_eipsi_get_demo_templates', 'eipsi_get_demo_templates_handler');

// === Handlers de Aleatorización Fase 2 (Longitudinal + Reminders) ===
add_action('wp_ajax_eipsi_validate_reminder_token', 'eipsi_validate_reminder_token_handler');
add_action('wp_ajax_nopriv_eipsi_validate_reminder_token', 'eipsi_validate_reminder_token_handler');
add_action('wp_ajax_eipsi_send_reminder_manual', 'eipsi_send_reminder_manual_handler');
add_action('wp_ajax_eipsi_unsubscribe_reminders', 'eipsi_unsubscribe_reminders_handler');

// Monitoring dashboard handlers
add_action('wp_ajax_eipsi_get_monitoring_data', 'eipsi_get_monitoring_data_handler');
add_action('wp_ajax_eipsi_get_audit_log', 'eipsi_get_audit_log_handler');
add_action('wp_ajax_eipsi_export_monitoring_report', 'eipsi_export_monitoring_report_handler');

// === Close Randomization Session (persistent_mode=OFF) ===
add_action( 'wp_ajax_nopriv_eipsi_close_randomization_session', 'eipsi_close_randomization_session_handler' );
add_action( 'wp_ajax_eipsi_close_randomization_session', 'eipsi_close_randomization_session_handler' );

/**
 * AJAX Handler: Monitoring data
 */
function eipsi_get_monitoring_data_handler() {
    check_ajax_referer('eipsi_admin_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Unauthorized'));
    }

    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/monitoring.php';

    $data = array(
        'email' => EIPSI_Monitoring::get_email_stats(),
        'cron' => EIPSI_Monitoring::get_cron_status(),
        'sessions' => EIPSI_Monitoring::get_session_stats(),
        'database' => EIPSI_Monitoring::get_db_health(),
        'timestamp' => current_time('mysql'),
    );

    wp_send_json_success($data);
}

/**
 * AJAX Handler: Audit log entries
 */
function eipsi_get_audit_log_handler() {
    check_ajax_referer('eipsi_admin_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Unauthorized'));
    }

    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/monitoring.php';

    $limit = isset($_POST['limit']) ? absint($_POST['limit']) : 10;
    $entries = EIPSI_Monitoring::get_audit_log_entries($limit);

    wp_send_json_success($entries);
}

/**
 * AJAX Handler: Export monitoring report
 */
function eipsi_export_monitoring_report_handler() {
    // Verify nonce manually to avoid sending headers too early
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'eipsi_admin_nonce')) {
        status_header(403);
        echo wp_json_encode(array('success' => false, 'message' => 'Unauthorized'));
        exit;
    }

    // Check permissions before any output
    if (!current_user_can('manage_options')) {
        status_header(403);
        echo wp_json_encode(array('success' => false, 'message' => 'Unauthorized'));
        exit;
    }

    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/monitoring.php';

    $data = array(
        'email' => EIPSI_Monitoring::get_email_stats(),
        'cron' => EIPSI_Monitoring::get_cron_status(),
        'sessions' => EIPSI_Monitoring::get_session_stats(),
        'database' => EIPSI_Monitoring::get_db_health(),
        'audit_log' => EIPSI_Monitoring::get_audit_log_entries(50),
    );


    // Clear any existing output buffer to prevent header issues
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $filename = 'monitoring_report_' . gmdate('Y-m-d_H-i-s') . '.json';
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, must-revalidate');
    header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');
    echo wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * AJAX Handler: Test SMTP configuration
 */
function eipsi_test_smtp_connection_handler() {
    check_ajax_referer('eipsi_admin_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('Unauthorized', 'eipsi-forms')));
    }

    if (!class_exists('EIPSI_SMTP_Service')) {
        wp_send_json_error(array('message' => __('SMTP service not available.', 'eipsi-forms')));
    }

    $smtp_service = new EIPSI_SMTP_Service();
    $host = isset($_POST['host']) ? sanitize_text_field(wp_unslash($_POST['host'])) : '';
    $port = isset($_POST['port']) ? absint($_POST['port']) : 0;
    $user = isset($_POST['user']) ? sanitize_email(wp_unslash($_POST['user'])) : '';
    $password = isset($_POST['password']) ? wp_unslash($_POST['password']) : '';
    $encryption = isset($_POST['encryption']) ? sanitize_key(wp_unslash($_POST['encryption'])) : 'tls';

    $existing_config = $smtp_service->get_config();
    if (empty($password) && $existing_config && !empty($existing_config['password'])) {
        $password = $existing_config['password'];
    }

    $validation = $smtp_service->validate_config($host, $port, $user, $password, $encryption);
    if (empty($validation['valid'])) {
        wp_send_json_error(array('message' => $validation['message'] ?? __('Configuración SMTP inválida.', 'eipsi-forms')));
    }

    $config = array(
        'host' => $host,
        'port' => $port,
        'user' => $user,
        'password' => $password,
        'encryption' => $encryption,
        'enabled' => true
    );

    $test_email = get_option('eipsi_investigator_email', get_option('admin_email'));
    $result = $smtp_service->send_test_email($config, $test_email);

    if (!empty($result['success'])) {
        $labels = array(
            'tls' => __('TLS (recomendado)', 'eipsi-forms'),
            'ssl' => __('SSL', 'eipsi-forms'),
            'none' => __('Sin cifrado', 'eipsi-forms')
        );

        wp_send_json_success(array(
            'message' => sprintf(__('Correo de prueba enviado a %s', 'eipsi-forms'), $test_email),
            'status' => array(
                'host' => $host,
                'port' => $port,
                'user' => $user,
                'encryption' => $encryption,
                'encryption_label' => $labels[$encryption] ?? $encryption
            )
        ));
    }

    wp_send_json_error(array(
        'message' => $result['error'] ?? __('No se pudo enviar el correo de prueba.', 'eipsi-forms')
    ));
}

/**
 * AJAX Handler: Save SMTP configuration
 */
function eipsi_save_smtp_config_handler() {
    check_ajax_referer('eipsi_admin_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('Unauthorized', 'eipsi-forms')));
    }

    if (!class_exists('EIPSI_SMTP_Service')) {
        wp_send_json_error(array('message' => __('SMTP service not available.', 'eipsi-forms')));
    }

    $smtp_service = new EIPSI_SMTP_Service();
    $host = isset($_POST['host']) ? sanitize_text_field(wp_unslash($_POST['host'])) : '';
    $port = isset($_POST['port']) ? absint($_POST['port']) : 0;
    $user = isset($_POST['user']) ? sanitize_email(wp_unslash($_POST['user'])) : '';
    $password = isset($_POST['password']) ? wp_unslash($_POST['password']) : '';
    $encryption = isset($_POST['encryption']) ? sanitize_key(wp_unslash($_POST['encryption'])) : 'tls';

    $existing_config = $smtp_service->get_config();
    if (empty($password) && $existing_config && !empty($existing_config['password'])) {
        $password = $existing_config['password'];
    }

    $validation = $smtp_service->validate_config($host, $port, $user, $password, $encryption);
    if (empty($validation['valid'])) {
        wp_send_json_error(array('message' => $validation['message'] ?? __('Configuración SMTP inválida.', 'eipsi-forms')));
    }

    $smtp_service->save_config($host, $port, $user, $password, $encryption);

    $labels = array(
        'tls' => __('TLS (recomendado)', 'eipsi-forms'),
        'ssl' => __('SSL', 'eipsi-forms'),
        'none' => __('Sin cifrado', 'eipsi-forms')
    );

    wp_send_json_success(array(
        'message' => __('Configuración SMTP guardada correctamente.', 'eipsi-forms'),
        'status' => array(
            'host' => $host,
            'port' => $port,
            'user' => $user,
            'encryption' => $encryption,
            'encryption_label' => $labels[$encryption] ?? $encryption
        )
    ));
}

/**
 * AJAX Handler: Disable SMTP configuration
 */
function eipsi_disable_smtp_handler() {
    check_ajax_referer('eipsi_admin_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('Unauthorized', 'eipsi-forms')));
    }

    if (!class_exists('EIPSI_SMTP_Service')) {
        wp_send_json_error(array('message' => __('SMTP service not available.', 'eipsi-forms')));
    }

    $smtp_service = new EIPSI_SMTP_Service();
    $smtp_service->disable_config();

    wp_send_json_success(array(
        'message' => __('Configuración SMTP desactivada.', 'eipsi-forms')
    ));
}

/**
 * AJAX Handler: Get list of available forms for randomization dropdown
 *
 * - CPT can vary depending on installation/migrations.
 *   Prefer eipsi_form (Form Library actual), but also support eipsi_form_template
 *   to avoid breaking older sites.
 * - Includes publish + private.
 *
 * Returns: array of {id, name, label, status, postType}
 * Frontend expects: data.data = [{id, name, ...}]
 *
 * @since 1.3.0
 */
function eipsi_get_forms_list_handler() {
    // Verificar nonce (aceptar GET o POST para robustez)
    $nonce = '';
    if (isset($_GET['nonce'])) {
        $nonce = sanitize_text_field(wp_unslash($_GET['nonce']));
    } elseif (isset($_POST['nonce'])) {
        $nonce = sanitize_text_field(wp_unslash($_POST['nonce']));
    }

    if (empty($nonce) || !wp_verify_nonce($nonce, 'eipsi_admin_nonce')) {
        wp_send_json_error(array(
            'message' => __('Invalid security token', 'eipsi-forms')
        ), 403);
        return;
    }

    // Determinar CPT disponible.
    // Preferimos eipsi_form (instalaciones nuevas / librería real), y si no existe,
    // caemos a eipsi_form_template (compatibilidad instalaciones viejas).
    $post_type = null;

    if (post_type_exists('eipsi_form')) {
        $post_type = 'eipsi_form';
    } elseif (post_type_exists('eipsi_form_template')) {
        $post_type = 'eipsi_form_template';
    }

    if (!$post_type) {
        wp_send_json_success(array());
        return;
    }

    $args = array(
        'post_type' => $post_type,
        'post_status' => array('publish', 'private'),
        'posts_per_page' => -1,
        'orderby' => 'title',
        'order' => 'ASC',
    );

    $forms = get_posts($args);

    if (empty($forms)) {
        wp_send_json_success(array());
        return;
    }

    $forms_list = array_map(function($form) {
        $title = $form->post_title ? $form->post_title : __('(Sin título)', 'eipsi-forms');

        return array(
            'id' => intval($form->ID),
            // Compat: el editor actualmente usa .name
            'name' => esc_html($title),
            // Compat: si algún frontend usa .label
            'label' => esc_html($title),
            'status' => $form->post_status,
            'postType' => $form->post_type,
        );
    }, $forms);

    wp_send_json_success($forms_list);
}

/**
 * AJAX Handler: Get demo templates list
 *
 * Returns templates from eipsi_get_demo_templates() in demo-templates.php
 * Returns: array of {id, name, description, icon}
 * Frontend expects: data.data = [{id, name, description, icon}, ...]
 *
 * @since 1.3.0
 */
function eipsi_get_demo_templates_handler() {
    // Verificar nonce (aceptar GET o POST para robustez)
    $nonce = '';
    if (isset($_GET['nonce'])) {
        $nonce = sanitize_text_field(wp_unslash($_GET['nonce']));
    } elseif (isset($_POST['nonce'])) {
        $nonce = sanitize_text_field(wp_unslash($_POST['nonce']));
    }

    if (empty($nonce) || !wp_verify_nonce($nonce, 'eipsi_admin_nonce')) {
        wp_send_json_error(array(
            'message' => __('Invalid security token', 'eipsi-forms')
        ), 403);
        return;
    }

    // Cargar la función de templates demo
    require_once plugin_dir_path(__FILE__) . 'demo-templates.php';
    
    // Obtener templates disponibles
    $templates = eipsi_get_demo_templates();
    
    if (empty($templates)) {
        wp_send_json_success(array());
        return;
    }

    $templates_list = array_map(function($template) {
        return array(
            'id' => sanitize_text_field($template['id']),
            'name' => esc_html($template['name']),
            'description' => esc_html($template['description']),
            'icon' => esc_html($template['icon']),
        );
    }, $templates);

    wp_send_json_success($templates_list);
}

/**
 * Handler principal de aleatorización - Fisher-Yates weighted
 * 
 * @since 1.3.0
 */
function eipsi_random_assign_handler() {
    // Validar nonce
    check_ajax_referer('eipsi_random_nonce', 'nonce');
    
    // Sanitizar input
    $main_form_id = isset($_POST['form_id']) ? intval($_POST['form_id']) : 0;
    $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
    $participant_id = isset($_POST['participant_id']) ? sanitize_text_field($_POST['participant_id']) : '';
    $is_manual = isset($_POST['is_manual']) && $_POST['is_manual'] === 'true';
    
    // Validar permisos (cualquier editor puede configurar random)
    if (!current_user_can('edit_posts')) {
        wp_send_json_error(array('message' => __('Sin permisos para realizar esta acción.', 'eipsi-forms')));
    }
    
    // Validar que el formulario principal existe
    if (!$main_form_id || get_post_type($main_form_id) !== 'eipsi_form') {
        wp_send_json_error(array('message' => __('Formulario principal inválido.', 'eipsi-forms')));
    }
    
    // Identificador: priorizar email, si no participant_id
    if (!empty($email) && !is_email($email)) {
        wp_send_json_error(array('message' => __('Email inválido.', 'eipsi-forms')));
    }
    
    if (empty($email) && empty($participant_id)) {
        wp_send_json_error(array('message' => __('Se requiere email o participant_id.', 'eipsi-forms')));
    }
    
    $identifier = !empty($email) ? $email : $participant_id;
    
    // Si es asignación manual directa (desde el panel de admin)
    if ($is_manual && isset($_POST['assigned_form_id'])) {
        $assigned_form_id = intval($_POST['assigned_form_id']);
        
        // Validar que el formulario asignado existe
        if (!$assigned_form_id || get_post_type($assigned_form_id) !== 'eipsi_form') {
            wp_send_json_error(array('message' => __('Formulario asignado inválido.', 'eipsi-forms')));
        }
        
        $seed = wp_generate_uuid4();
        $type = 'manual';
        
        // Guardar asignación
        eipsi_save_assignment($main_form_id, $identifier, $assigned_form_id, $seed, $type);
        
        wp_send_json_success(array(
            'form_id' => $assigned_form_id,
            'seed' => $seed,
            'type' => $type,
        ));
    }
    
    // BUSCAR ASIGNACIÓN PREVIA (persistencia)
    $existing_assignment = eipsi_get_assignment($main_form_id, $identifier);
    
    if ($existing_assignment) {
        // El participante YA tiene asignado un formulario → devolver el mismo
        wp_send_json_success(array(
            'form_id' => intval($existing_assignment['form_id']),
            'seed' => $existing_assignment['seed'],
            'type' => 'persistent',
            'message' => __('Usando asignación anterior', 'eipsi-forms')
        ));
    }
    
    // Leer configuración de aleatorización
    $config = get_post_meta($main_form_id, '_eipsi_random_config', true);
    
    if (empty($config) || !isset($config['forms']) || count($config['forms']) < 2) {
        wp_send_json_error(array('message' => __('Aleatorización no configurada o incompleta (mínimo 2 formularios requeridos).', 'eipsi-forms')));
    }
    
    // Verificar override manual (el identifier coincide con una asignación manual)
    // Si el participante usó email, verificar si hay override manual
    if (!empty($email)) {
        $manual_assigns = $config['manualAssigns'] ?? array();
        foreach ($manual_assigns as $assign) {
            if (strtolower($assign['email']) === strtolower($email)) {
                // Manual override encontrado - retornar esa asignación
                $seed = wp_generate_uuid4();
                $type = 'manual_override';
                
                // Guardar la asignación para futuras visitas
                eipsi_save_assignment($main_form_id, $identifier, intval($assign['formId']), $seed, $type);
                
                wp_send_json_success(array(
                    'form_id' => intval($assign['formId']),
                    'seed' => $seed,
                    'type' => $type,
                ));
            }
        }
    }
    
    // Fisher-Yates weighted shuffle - NUEVA ASIGNACIÓN
    $forms = $config['forms'];
    $probabilities = $config['probabilities'];
    $method = $config['method'] ?? 'seeded';
    
    // Generar seed para reproducibilidad
    $seed = ($method === 'seeded') ? wp_generate_uuid4() : null;
    if ($seed) {
        // Usar crc32 del UUID para seed reproducible en mt_rand
        mt_srand(crc32($seed));
    }
    
    $assigned_form_id = eipsi_weighted_random($forms, $probabilities);
    $type = 'random';
    
    // Guardar asignación en postmeta para futuras visitas
    eipsi_save_assignment($main_form_id, $identifier, $assigned_form_id, $seed, $type);
    
    wp_send_json_success(array(
        'form_id' => intval($assigned_form_id),
        'seed' => $seed,
        'type' => $type,
    ));
}

/**
 * Guarda la asignación de formulario en postmeta temporal
 * 
 * @param int $main_form_id Formulario principal (el que tiene la config de random)
 * @param string $email Email del participante
 * @param int $assigned_form_id Formulario asignado
 * @param string|null $seed Seed para reproducibilidad
 * @param string $type Tipo de asignación: 'random' | 'manual' | 'manual_override'
 * @since 1.3.0
 */
function eipsi_save_assignment($main_form_id, $email, $assigned_form_id, $seed, $type) {
    $meta_key = '_eipsi_assign_' . md5(strtolower($email));
    
    $assignment = array(
        'form_id' => intval($assigned_form_id),
        'seed' => $seed,
        'type' => $type,
        'timestamp' => current_time('mysql'),
        'main_form_id' => intval($main_form_id),
    );
    
    update_post_meta($main_form_id, $meta_key, $assignment);
}

/**
 * Fisher-Yates weighted shuffle para selección según probabilidades
 * Implementa selección ponderada con distribución uniforme
 * 
 * @param array $forms Array de post IDs
 * @param array $probabilities { formId: percentage }
 * @return int Post ID seleccionado
 * @since 1.3.0
 */
function eipsi_weighted_random($forms, $probabilities) {
    // Crear array ponderado para selección
    // Cada formulario aparece N veces según su peso (simplificado)
    $weighted = array();
    $total_weight = 0;
    
    foreach ($forms as $form_id) {
        $weight = isset($probabilities[$form_id]) ? intval($probabilities[$form_id]) : 1;
        // Usar el porcentaje directamente como peso (0-100)
        // Para distribuciones más precisas con pesos pequeños, usaríamos multiplicador
        for ($i = 0; $i < $weight; $i++) {
            $weighted[] = $form_id;
        }
        $total_weight += $weight;
    }
    
    // Si no hay pesos válidos, retornar primero
    if (empty($weighted)) {
        return intval($forms[0]);
    }
    
    // Fisher-Yates shuffle del array ponderado
    $n = count($weighted);
    for ($i = $n - 1; $i > 0; $i--) {
        $j = mt_rand(0, $i);
        $temp = $weighted[$i];
        $weighted[$i] = $weighted[$j];
        $weighted[$j] = $temp;
    }
    
    // Seleccionar primer elemento después del shuffle
    // Esto da distribución proporcional a los pesos originales
    return intval($weighted[0]);
}

/**
 * Obtiene la asignación de un participante
 * 
 * @param int $main_form_id Formulario principal
 * @param string $email Email del participante
 * @return array|null Datos de asignación o null si no existe
 * @since 1.3.0
 */
function eipsi_get_assignment($main_form_id, $email) {
    $meta_key = '_eipsi_assign_' . md5(strtolower($email));
    return get_post_meta($main_form_id, $meta_key, true);
}

/**
 * Valida un token de recordatorio (Fase 2 - Longitudinal)
 * 
 * @since 1.3.0
 */
function eipsi_validate_reminder_token_handler() {
    // Permitir validación sin nonce para links de email (nopriv)
    // Validaremos el token mismo como seguridad
    
    $token = isset($_POST['token']) ? sanitize_text_field($_POST['token']) : '';
    
    if (empty($token)) {
        wp_send_json_error(array('message' => __('Token inválido.', 'eipsi-forms')));
    }
    
    global $wpdb;
    
    // Buscar token en postmeta de todos los formularios
    $meta_table = $wpdb->postmeta;
    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT post_id, meta_value 
        FROM {$meta_table} 
        WHERE meta_key LIKE %s 
        AND meta_value LIKE %s 
        LIMIT 10",
        $wpdb->esc_like('_eipsi_reminder_token_') . '%',
        '%' . $wpdb->esc_like($token) . '%'
    ));
    
    if (empty($results)) {
        wp_send_json_error(array('message' => __('Token no encontrado o expirado.', 'eipsi-forms')));
    }
    
    // Validar cada match (puede haber colisiones parciales)
    foreach ($results as $row) {
        $data = maybe_unserialize($row->meta_value);
        
        if (!is_array($data) || !isset($data['token'])) {
            continue;
        }
        
        // Match exacto del token
        if ($data['token'] !== $token) {
            continue;
        }
        
        // Verificar expiración
        if (isset($data['expires'])) {
            $expires = strtotime($data['expires']);
            $now = current_time('timestamp');
            
            if ($now > $expires) {
                wp_send_json_error(array('message' => __('Este link ha expirado. Solicita uno nuevo.', 'eipsi-forms')));
            }
        }
        
        // Token válido - retornar datos
        wp_send_json_success(array(
            'valid' => true,
            'email' => $data['email'] ?? '',
            'form_id' => intval($data['form_id'] ?? 0),
            'take' => intval($data['take'] ?? 1),
            'seed' => $data['seed'] ?? '',
        ));
    }
    
    wp_send_json_error(array('message' => __('Token inválido.', 'eipsi-forms')));
}

/**
 * Envía un recordatorio manual desde el panel de Submissions
 * 
 * @since 1.3.0
 */
function eipsi_send_reminder_manual_handler() {
    check_ajax_referer('eipsi_admin_nonce', 'nonce');
    
    if (!current_user_can('edit_posts')) {
        wp_send_json_error(array('message' => __('Sin permisos.', 'eipsi-forms')));
    }
    
    $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
    $form_id = isset($_POST['form_id']) ? intval($_POST['form_id']) : 0;
    $take_num = isset($_POST['take']) ? intval($_POST['take']) : 1;
    $frequency = isset($_POST['frequency']) ? sanitize_text_field($_POST['frequency']) : 'now';
    
    if (empty($email) || !is_email($email) || !$form_id) {
        wp_send_json_error(array('message' => __('Datos inválidos.', 'eipsi-forms')));
    }
    
    // Verificar que existe una toma pendiente para este email
    $take_meta_key = "_eipsi_toma_{$take_num}_assign";
    $take_data = get_post_meta($form_id, $take_meta_key, true);
    
    if (empty($take_data) || !is_array($take_data)) {
        wp_send_json_error(array('message' => __('No se encontró toma pendiente para este participante.', 'eipsi-forms')));
    }
    
    // Generar token
    $token = wp_generate_uuid4();
    $assigned_form_id = $take_data['form_id'] ?? $form_id;
    $seed = $take_data['seed'] ?? '';
    
    // Guardar token
    $token_data = array(
        'token' => $token,
        'email' => $email,
        'form_id' => $assigned_form_id,
        'take' => $take_num,
        'seed' => $seed,
        'created' => current_time('mysql'),
        'expires' => gmdate('Y-m-d H:i:s', strtotime('+48 hours')),
        'manual' => true,
    );
    
    update_post_meta($form_id, '_eipsi_reminder_token_' . md5($email . $take_num), $token_data);
    
    // Construir link de recordatorio
    $reminder_link = add_query_arg(array(
        'eipsi_token' => $token,
        'form_id' => $assigned_form_id,
        'take' => $take_num,
    ), home_url('/formulario/'));
    
    if ($frequency === 'now') {
        // Enviar inmediatamente
        $headers = array('Content-Type: text/html; charset=UTF-8');
        $form_name = get_the_title($form_id);
        $subject = sprintf(__('Recordatorio: Tu Toma %d está lista - %s', 'eipsi-forms'), $take_num, $form_name);
        
        // Construir link de unsubscribe
        $unsubscribe_link = add_query_arg(array(
            'eipsi_unsubscribe' => '1',
            'email' => urlencode($email),
            'form_id' => $form_id,
            'token' => $token,
        ), home_url('/'));
        
        // Cargar template
        ob_start();
        include EIPSI_FORMS_PLUGIN_DIR . 'includes/emails/reminder-take.php';
        $email_body = ob_get_clean();
        
        $sent = wp_mail($email, $subject, $email_body, $headers);
        
        if ($sent) {
            wp_send_json_success(array('message' => __('Recordatorio enviado exitosamente.', 'eipsi-forms')));
        } else {
            wp_send_json_error(array('message' => __('Error al enviar el email.', 'eipsi-forms')));
        }
    } elseif ($frequency === 'tomorrow') {
        // Programar para mañana 10 AM
        $tomorrow = strtotime('tomorrow 10:00 AM');
        wp_schedule_single_event($tomorrow, 'eipsi_send_manual_reminder', array($email, $reminder_link));
        
        wp_send_json_success(array('message' => __('Recordatorio programado para mañana.', 'eipsi-forms')));
    } elseif ($frequency === 'weekly') {
        // Programar para 1 semana desde ahora
        $next_week = strtotime('+1 week');
        wp_schedule_single_event($next_week, 'eipsi_send_manual_reminder', array($email, $reminder_link));
        
        wp_send_json_success(array('message' => __('Recordatorio programado para la próxima semana.', 'eipsi-forms')));
    }
    
    wp_send_json_error(array('message' => __('Frecuencia inválida.', 'eipsi-forms')));
}

/**
 * Desuscribirse de recordatorios (unsubscribe)
 * 
 * @since 1.3.0
 */
function eipsi_unsubscribe_reminders_handler() {
    // No requiere nonce (viene de link de email)
    
    $email = isset($_GET['email']) ? sanitize_email($_GET['email']) : '';
    $form_id = isset($_GET['form_id']) ? intval($_GET['form_id']) : 0;
    $token = isset($_GET['token']) ? sanitize_text_field($_GET['token']) : '';
    
    if (empty($email) || !is_email($email) || !$form_id || empty($token)) {
        wp_die(__('Solicitud inválida.', 'eipsi-forms'));
    }
    
    // Validar que el token existe (seguridad básica)
    global $wpdb;
    $meta_table = $wpdb->postmeta;
    $token_exists = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) 
        FROM {$meta_table} 
        WHERE post_id = %d 
        AND meta_key LIKE %s 
        AND meta_value LIKE %s",
        $form_id,
        $wpdb->esc_like('_eipsi_reminder_token_') . '%',
        '%' . $wpdb->esc_like($token) . '%'
    ));
    
    if (!$token_exists) {
        wp_die(__('Token inválido.', 'eipsi-forms'));
    }
    
    // Guardar flag de unsubscribe
    update_post_meta($form_id, '_eipsi_unsubscribed_' . md5($email), array(
        'timestamp' => current_time('mysql'),
        'reason' => 'user_request',
    ));
    
    // Mostrar mensaje de confirmación
    wp_die(
        sprintf(
            '<div style="max-width: 600px; margin: 50px auto; padding: 30px; text-align: center; font-family: -apple-system, BlinkMacSystemFont, sans-serif;">
                <h2 style="color: #00a32a; margin-bottom: 20px;">✓ %s</h2>
                <p style="font-size: 16px; color: #666; margin-bottom: 30px;">%s</p>
                <p style="font-size: 14px; color: #999;">%s</p>
            </div>',
            esc_html__('Desuscrito exitosamente', 'eipsi-forms'),
            esc_html__('Ya no recibirás más recordatorios de este estudio.', 'eipsi-forms'),
            esc_html__('Si necesitas volver a participar, contacta al equipo de investigación.', 'eipsi-forms')
        ),
        __('Desuscrito', 'eipsi-forms')
    );
}

/**
 * NOTE: Quality Flags y Patrones de Evitación fueron removidos en v1.0
 * RAZÓN CLÍNICA: 
 * - Quality Flags = ruido sin valor (investigador ve todo en Submissions)
 * - Patrones de Evitación = indetectable con Save & Continue, falsos positivos altos
 * RESPONSABILIDAD: Solo investigador decide qué datos usar, no algoritmos
 */

/**
 * Handler para guardar configuración de privacidad
 */
function eipsi_save_privacy_config_handler() {
    check_ajax_referer('eipsi_privacy_nonce', 'eipsi_privacy_nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('No tienes permisos para realizar esta acción.', 'eipsi-forms')));
    }
    
    $form_id = isset($_POST['form_id']) ? sanitize_text_field($_POST['form_id']) : '';
    
    if (empty($form_id)) {
        wp_send_json_error(array('message' => __('Form ID is required.', 'eipsi-forms')));
    }
    
    require_once dirname(__FILE__) . '/privacy-config.php';
    
    $config = array(
        'device_type' => isset($_POST['device_type']),
        'browser' => isset($_POST['browser']),
        'os' => isset($_POST['os']),
        'screen_width' => isset($_POST['screen_width']),
        'ip_address' => isset($_POST['ip_address']),
        'fingerprint_enabled' => isset($_POST['fingerprint_enabled']),
        // v2.1.3: Extended metadata export fields
        'export_canvas_fingerprint' => isset($_POST['export_canvas_fingerprint']),
        'export_webgl_renderer' => isset($_POST['export_webgl_renderer']),
        'export_screen_resolution' => isset($_POST['export_screen_resolution']),
        'export_screen_depth' => isset($_POST['export_screen_depth']),
        'export_pixel_ratio' => isset($_POST['export_pixel_ratio']),
        'export_timezone' => isset($_POST['export_timezone']),
        'export_language' => isset($_POST['export_language']),
        'export_cpu_cores' => isset($_POST['export_cpu_cores']),
        'export_ram' => isset($_POST['export_ram']),
        'export_plugins' => isset($_POST['export_plugins']),
        'export_touch_support' => isset($_POST['export_touch_support']),
        'export_cookies_enabled' => isset($_POST['export_cookies_enabled'])
    );
    
    $result = save_privacy_config($form_id, $config);
    
    if ($result) {
        wp_send_json_success(array('message' => __('✅ Configuración guardada correctamente.', 'eipsi-forms')));
    } else {
        wp_send_json_error(array('message' => __('Error al guardar la configuración.', 'eipsi-forms')));
    }
}

function eipsi_save_global_privacy_config_handler() {
    check_ajax_referer('eipsi_global_privacy_nonce', 'eipsi_global_privacy_nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('No tienes permisos para realizar esta acción.', 'eipsi-forms')));
    }
    
    require_once dirname(__FILE__) . '/privacy-config.php';
    
    $config = array(
        'device_type' => isset($_POST['device_type']),
        'browser' => isset($_POST['browser']),
        'os' => isset($_POST['os']),
        'screen_width' => isset($_POST['screen_width']),
        'ip_address' => isset($_POST['ip_address']),
        'fingerprint_enabled' => isset($_POST['fingerprint_enabled']),
        // v2.1.3: Extended metadata export fields
        'export_canvas_fingerprint' => isset($_POST['export_canvas_fingerprint']),
        'export_webgl_renderer' => isset($_POST['export_webgl_renderer']),
        'export_screen_resolution' => isset($_POST['export_screen_resolution']),
        'export_screen_depth' => isset($_POST['export_screen_depth']),
        'export_pixel_ratio' => isset($_POST['export_pixel_ratio']),
        'export_timezone' => isset($_POST['export_timezone']),
        'export_language' => isset($_POST['export_language']),
        'export_cpu_cores' => isset($_POST['export_cpu_cores']),
        'export_ram' => isset($_POST['export_ram']),
        'export_plugins' => isset($_POST['export_plugins']),
        'export_touch_support' => isset($_POST['export_touch_support']),
        'export_cookies_enabled' => isset($_POST['export_cookies_enabled'])
    );
    
    $result = save_global_privacy_defaults($config);
    
    if ($result) {
        wp_send_json_success(array('message' => __('✅ Configuración global guardada correctamente.', 'eipsi-forms')));
    } else {
        wp_send_json_error(array('message' => __('Error al guardar la configuración global.', 'eipsi-forms')));
    }
}

function eipsi_forms_submit_form_handler() {
    check_ajax_referer('eipsi_forms_nonce', 'nonce');
    
    global $wpdb;
    $wpdb->suppress_errors(true);
    
    // ✅ EIPSI DATA SAFETY SYSTEM v2.1.0
    // Carga el sistema crítico de seguridad de datos
    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/data-safety-system.php';
    
    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-auth-service.php';
    $form_name = isset($_POST['form_id']) ? sanitize_text_field($_POST['form_id']) : 'default';
    $submission_context = EIPSI_Auth_Service::authorize_form_operation($form_name, $_POST, $_GET);
    if (!$submission_context['success']) {
        wp_send_json_error(array('message' => __('Unauthorized', 'eipsi-forms'), 'code' => $submission_context['error']), 403);
        return;
    }
    $authenticated_participant_id = $submission_context['participant_id'];
    $authenticated_study_id = $submission_context['study_id'];

    // Ética clínica: si el estudio está cerrado, no aceptamos nuevos envíos
    if (eipsi_get_study_status_for_form_name($form_name) === 'closed') {
        wp_send_json_error(array(
            'message' => __('Este estudio está cerrado y no acepta más respuestas. Contacta al investigador si tienes dudas.', 'eipsi-forms')
        ), 403);
    }
    
    // ✅ v2.0.1 - Capturar datos del dispositivo desde múltiples fuentes
    // Fuente 1: Campos individuales (legacy)
    $device = isset($_POST['device']) ? sanitize_text_field($_POST['device']) : '';
    $browser_raw = isset($_POST['browser']) ? sanitize_text_field($_POST['browser']) : '';
    $os_raw = isset($_POST['os']) ? sanitize_text_field($_POST['os']) : '';
    $screen_width_raw = isset($_POST['screen_width']) ? sanitize_text_field($_POST['screen_width']) : '';
    
    // ✅ v2.1.3 - Store raw device data for saving to database
    $device_data_raw = null;
    
    // Fuente 2: eipsi_device_data JSON (current approach)
    // Si no tenemos datos individuales, extraer del JSON del fingerprint
    if (empty($device) || empty($browser_raw) || empty($os_raw)) {
        $device_data_json = isset($_POST['eipsi_device_data']) ? wp_unslash($_POST['eipsi_device_data']) : '';
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
    $ip_address_raw = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    
    // Si está detrás de proxy/CDN (Cloudflare, Load Balancer, etc.)
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $ip_address_raw = $_SERVER['HTTP_CF_CONNECTING_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip_address_raw = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    }
    
    // Validar IP
    $ip_address_raw = filter_var($ip_address_raw, FILTER_VALIDATE_IP) ?: 'invalid';
    $start_time = isset($_POST['form_start_time']) ? sanitize_text_field($_POST['form_start_time']) : '';
    $end_time = isset($_POST['form_end_time']) ? sanitize_text_field($_POST['form_end_time']) : '';
    
    // ✅ v1.4.0 - Capturar user fingerprint desde POST
    $user_fingerprint = isset($_POST['eipsi_user_fingerprint']) ? sanitize_text_field($_POST['eipsi_user_fingerprint']) : '';

    // ✅ v1.5.4 - Capturar detalles crudos del fingerprint
    $fingerprint_raw = isset($_POST['eipsi_fingerprint_raw']) ? wp_unslash($_POST['eipsi_fingerprint_raw']) : '';
    $fingerprint_raw_array = null;

    if (!empty($fingerprint_raw)) {
        $fingerprint_raw_decoded = json_decode($fingerprint_raw, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $fingerprint_raw_array = $fingerprint_raw_decoded;
        }
    }

    
    // Obtener IDs universales del frontend
    $frontend_participant_id = isset($_POST['participant_id']) ? sanitize_text_field($_POST['participant_id']) : '';
    $session_id = isset($_POST['session_id']) ? sanitize_text_field($_POST['session_id']) : '';
    
    // Capturar metadata del frontend incluyendo page_transitions
    $frontend_metadata = isset($_POST['metadata']) ? wp_unslash($_POST['metadata']) : '';
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
    
    foreach ($_POST as $key => $value) {
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
        $frontend_end_timestamp_ms = isset($_POST['end_timestamp_ms']) ? intval($_POST['end_timestamp_ms']) : null;

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
    require_once dirname(__FILE__) . '/privacy-config.php';
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
    if (isset($_POST['eipsi_consent_accepted']) && $_POST['eipsi_consent_accepted'] === 'on') {
        $metadata['consent_given'] = true;
        $metadata['consent_timestamp'] = current_time('Y-m-d\TH:i:s\Z');
        $metadata['consent_ip'] = ($privacy_config['ip_address'] ?? true) ? $ip_address_raw : 'anonymized';
        $metadata['consent_user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    }
    
    // RANDOMIZATION INFO - Guardar datos si existen
    $random_assignment = array(
        'form_id' => isset($_POST['assignment_form_id']) ? sanitize_text_field($_POST['assignment_form_id']) : '-',
        'seed' => isset($_POST['assignment_seed']) ? sanitize_text_field($_POST['assignment_seed']) : '-',
        'type' => isset($_POST['assignment_type']) ? sanitize_text_field($_POST['assignment_type']) : '-'
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
    
    // ✅ DATA SAFETY: Pre-flight validation
    $safety_check = eipsi_safety_validate_submission($data);
    if (!$safety_check['valid']) {
        error_log('[EIPSI SAFETY] CRITICAL: Pre-flight validation failed: ' . implode(', ', $safety_check['errors']));
        // Aún intentamos guardar en modo emergencia
    }
    
    // ✅ DATA SAFETY: Guardar con sistema de seguridad (retry + emergencia)
    $safety_result = eipsi_safety_save_with_retry($data, 3);
    
    if ($safety_result['success']) {
        $insert_id = $safety_result['insert_id'] ?? null;
        $storage_type = $safety_result['storage'] ?? 'unknown';
        $emergency_mode = $safety_result['emergency_mode'] ?? false;
        $used_fallback = $safety_result['fallback_used'] ?? false;
        
        // ✅ DATA SAFETY: Verificación post-submit
        $verified = eipsi_safety_verify_submission($insert_id, $storage_type, $data);
        
        if (!$verified && !$emergency_mode) {
            error_log(sprintf('[EIPSI SAFETY] Post-submit verification failed for ID: %s', $insert_id));
        }
        
        // ✅ v2.1.3 - Guardar device data extendido en tabla separada
        // Asegurar que la clase esté cargada
        if (!class_exists('EIPSI_Device_Data_Service')) {
            require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-device-data-service.php';
        }
        error_log("[EIPSI-SUBMIT-DIAG] CHECK save_device_data: insert_id={$insert_id}, has_device_data=" . (isset($metadata_array['device_data']) ? 'YES' : 'NO') . ", class_exists=" . (class_exists('EIPSI_Device_Data_Service') ? 'YES' : 'NO'));
        if ($storage_type === 'wordpress_db' && !$emergency_mode && $insert_id && !empty($metadata_array['device_data']) && class_exists('EIPSI_Device_Data_Service')) {
            error_log("[EIPSI-SUBMIT-DIAG] CALLING save_device_data with insert_id={$insert_id}, device_data_keys=" . implode(',', array_keys($metadata_array['device_data'])));
            $result = EIPSI_Device_Data_Service::save_device_data($insert_id, $metadata_array['device_data']);
            error_log("[EIPSI-SUBMIT-DIAG] save_device_data result: " . ($result ? "SUCCESS (insert_id={$result})" : "FAILED"));
        }
        
        // Marcar partial response como completado
        EIPSI_Partial_Responses::mark_completed($form_name, $partial_participant_id, $session_id);
        
        // Si fue modo emergencia, notificar al usuario pero confirmar éxito
        if ($emergency_mode) {
            wp_send_json_success(array(
                'message' => $safety_result['message'],
                'emergency_mode' => true,
                'emergency_id' => $safety_result['emergency_id'],
                'insert_id' => $insert_id,
                'verified' => $verified
            ));
        }
        
        // === Task 2.4B: Marcar assignment como submitted y obtener próxima toma ===
        $next_wave_data = null;
        $has_next_wave = false;
        $nudge_0_sent = false;
        $nudge_0_message = '';
        
        // ✅ v1.5.6 - DEBUG: Log para verificar variables de contexto longitudinal
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[EIPSI] Longitudinal context check: wave_id=%s, study_id=%s, longitudinal_participant_id=%s, user_email=%s',
                $wave_id ?: 'NULL',
                $study_id ?: 'NULL',
                $longitudinal_participant_id ?: 'NULL',
                $user_data['email'] ?: 'NULL'
            ));
        }
        
        // v1.5.6 - Si hay contexto longitudinal (wave_id detectable), actualizar assignment
        // Usar longitudinal_participant_id (INT) y study_id en lugar de participant_id (string) y survey_id
        
        // ✅ DIAGNÓSTICO: Siempre loguear variables críticas
        error_log(sprintf(
            '[EIPSI-DIAG] Pre-check: wave_id=%s, study_id=%s, longitudinal_participant_id=%s',
            $wave_id ?: 'NULL',
            $study_id ?: 'NULL',
            $longitudinal_participant_id ?: 'NULL'
        ));
        
        if (!empty($wave_id) && $study_id && $longitudinal_participant_id) {
            // Cargar Wave_Service
            require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/services/Wave_Service.php';

            // ✅ FIX: Si el assignment no existe, crearlo primero (fallback defensivo)
            // Cargar el servicio si no está disponible (verificar función, no clase)
            $func_exists_before = function_exists('eipsi_create_assignments_for_participant');
            error_log('[EIPSI-DIAG] Función eipsi_create_assignments_for_participant existe ANTES: ' . ($func_exists_before ? 'SÍ' : 'NO'));
            
            if (!$func_exists_before) {
                $assignment_service_path = EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-assignment-service.php';
                $file_exists = file_exists($assignment_service_path);
                error_log('[EIPSI-DIAG] Archivo class-assignment-service.php existe: ' . ($file_exists ? 'SÍ' : 'NO'));
                if ($file_exists) {
                    require_once $assignment_service_path;
                    error_log('[EIPSI-DIAG] Archivo cargado. Función existe DESPUÉS: ' . (function_exists('eipsi_create_assignments_for_participant') ? 'SÍ' : 'NO'));
                }
            }
            
            // Verificar si existe el assignment
            $existing_assignment = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}survey_assignments 
                 WHERE wave_id = %d AND participant_id = %d",
                $wave_id,
                $longitudinal_participant_id
            ));
            
            error_log(sprintf('[EIPSI-DIAG] Assignment existente para wave_id=%d, participant_id=%d: %s',
                $wave_id, $longitudinal_participant_id, $existing_assignment ?: 'NO ENCONTRADO'
            ));
            
            // Si no existe, crearlo primero
            if (!$existing_assignment && function_exists('eipsi_create_assignments_for_participant')) {
                error_log(sprintf('[EIPSI-DIAG] Creando assignments para participant_id=%d, study_id=%d', 
                    $longitudinal_participant_id, $study_id));
                $create_result = eipsi_create_assignments_for_participant($longitudinal_participant_id, $study_id);
                error_log(sprintf(
                    '[EIPSI-DIAG] Resultado creación: created=%d, skipped=%d, errors=%d',
                    $create_result['created'],
                    $create_result['skipped'],
                    count($create_result['errors'])
                ));
                if (!empty($create_result['errors'])) {
                    error_log('[EIPSI-DIAG] Errores: ' . implode(', ', $create_result['errors']));
                }
            } elseif (!$existing_assignment) {
                error_log('[EIPSI-DIAG] ERROR: Función eipsi_create_assignments_for_participant NO disponible');
            }

            // Marcar assignment como submitted usando study_id (columna correcta en wp_survey_assignments)
            error_log(sprintf('[EIPSI-DIAG] Marcando como submitted: participant_id=%d, study_id=%d, wave_id=%d',
                $longitudinal_participant_id, $study_id, $wave_id));
            
            // ========== PHASE 5 T1-ANCHOR: TRANSACCIÓN CRÍTICA ==========
            // Submit + t1_completed_at deben ser atómicos
            $is_t1 = false; // Inicializar antes del try para scope externo
            
            $wpdb->query('START TRANSACTION');
            
            try {
                // 1. LOCK y verificar status del assignment (prevenir race condition con wave skipping)
                $assignment = $wpdb->get_row($wpdb->prepare(
                    "SELECT id, status FROM {$wpdb->prefix}survey_assignments 
                     WHERE participant_id = %d AND study_id = %d AND wave_id = %d
                     FOR UPDATE",
                    $longitudinal_participant_id, $study_id, $wave_id
                ));
                
                if (!$assignment) {
                    throw new Exception('Assignment not found');
                }
                
                // Validar que el status permita submit
                $allowed_statuses = array('pending', 'in_progress');
                if (!in_array($assignment->status, $allowed_statuses)) {
                    throw new Exception("Cannot submit assignment with status '{$assignment->status}'. This wave may have been skipped or expired.");
                }
                
                error_log("[EIPSI-DIAG] Assignment status validated: {$assignment->status} (allowed for submit)");
                
                // 2. Marcar assignment como submitted
                $marked = Wave_Service::mark_assignment_submitted($longitudinal_participant_id, $study_id, $wave_id);
                
                if (!$marked) {
                    throw new Exception('Failed to mark assignment as submitted');
                }
                
                error_log('[EIPSI-DIAG] Resultado mark_assignment_submitted: ÉXITO');
                
                // 2. Si es T1, actualizar t1_completed_at (dentro de la misma transacción)
                $wave_info = $wpdb->get_row($wpdb->prepare(
                    "SELECT wave_index FROM {$wpdb->prefix}survey_waves WHERE id = %d",
                    $wave_id
                ));
                
                $is_t1 = ($wave_info && $wave_info->wave_index == 1);
                
                if ($is_t1) {
                    error_log("[EIPSI T1-Anchor] T1 detected, updating t1_completed_at");
                    
                    $t1_updated = $wpdb->update(
                        $wpdb->prefix . 'survey_assignments',
                        array('t1_completed_at' => current_time('mysql')),
                        array(
                            'participant_id' => $longitudinal_participant_id,
                            'wave_id' => $wave_id
                        ),
                        array('%s'),
                        array('%d', '%d')
                    );
                    
                    if ($t1_updated === false) {
                        throw new Exception('Failed to update t1_completed_at: ' . $wpdb->last_error);
                    }
                    
                    error_log("[EIPSI T1-Anchor] t1_completed_at updated successfully");
                }
                
                // COMMIT: submit + t1_completed_at están guardados atómicamente
                $wpdb->query('COMMIT');
                error_log('[EIPSI T1-Anchor] Transaction committed successfully');
                
            } catch (Exception $e) {
                $wpdb->query('ROLLBACK');
                error_log("[EIPSI T1-Anchor] CRITICAL: Transaction failed, rolled back: " . $e->getMessage());
                
                // Retornar error al frontend - el participante debe reintentar
                wp_send_json_error(array(
                    'message' => __('Error al guardar la respuesta. Por favor, intentá nuevamente.', 'eipsi-forms'),
                    'error' => $e->getMessage()
                ), 500);
                return; // Detener ejecución
            }
            
            // ========== POST-COMMIT: Recalcular waves (fuera de transacción) ==========
            if ($is_t1) {
                // Load Wave Recalculator service if not already loaded
                if (!class_exists('EIPSI_Wave_Recalculator')) {
                    require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/services/class-wave-recalculator.php';
                }
                
                try {
                    error_log("[EIPSI T1-Anchor] Starting wave recalculation (post-commit)");
                    $t1_timestamp = current_time('mysql');
                    $result = EIPSI_Wave_Recalculator::recalculate_after_t1(
                        $longitudinal_participant_id, 
                        $study_id, 
                        $t1_timestamp,
                        'system'
                    );
                    $affected_count = count($result['affected_waves'] ?? array());
                    error_log("[EIPSI T1-Anchor] Successfully recalculated {$affected_count} waves");
                } catch (Exception $e) {
                    // No revertir el submit - solo loggear el error
                    error_log("[EIPSI T1-Anchor] WARNING: Wave recalculation failed (submit was successful): " . $e->getMessage());
                    // El submit fue exitoso, continuar con el flujo normal
                }
            }

            // ==========================================================================
            // POOL COMPLETION CHECK (v2.5.3)
            // Check if all waves in this study are completed, and if so, mark pool assignment
            // ==========================================================================
            eipsi_check_and_mark_pool_completion($longitudinal_participant_id, $study_id, $stable_form_id);

            // Obtener próxima toma pendiente usando study_id
            $next_wave = Wave_Service::get_next_pending_wave($longitudinal_participant_id, $study_id);
            
            error_log(sprintf('[EIPSI-DIAG] Próxima wave pendiente: %s', $next_wave ? 'ENCONTRADA (index=' . $next_wave['wave_index'] . ')' : 'NO ENCONTRADA'));
            
            if ($next_wave) {
                $has_next_wave = true;
                
                // Obtener configuración de la wave (intervalo y recordatorio)
                $wave_config = $wpdb->get_row($wpdb->prepare(
                    "SELECT offset_minutes, interval_days, reminder_days, time_unit FROM {$wpdb->prefix}survey_waves WHERE id = %d",
                    $next_wave['wave_id']
                ), ARRAY_A);
                
                // DEBUG: Log raw values from database
                error_log(sprintf('[EIPSI-DIAG] Raw wave_config from DB: wave_id=%d, offset_minutes=%s, interval_days=%s, time_unit=%s',
                    $next_wave['wave_id'],
                    $wave_config['offset_minutes'] ?? 'NULL',
                    $wave_config['interval_days'] ?? 'NULL',
                    $wave_config['time_unit'] ?? 'NULL'
                ));
                
                // FIX: Ensure time_unit is a valid string value
                $raw_time_unit = $wave_config['time_unit'] ?? 'days';
                $valid_time_units = array('minutes', 'hours', 'days');
                
                // Map numeric values to strings (if stored as 0, 1, 2)
                $numeric_map = array(
                    '0' => 'minutes',
                    '1' => 'hours',
                    '2' => 'days'
                );
                
                if (isset($numeric_map[$raw_time_unit])) {
                    $time_unit = $numeric_map[$raw_time_unit];
                } elseif (in_array($raw_time_unit, $valid_time_units)) {
                    $time_unit = $raw_time_unit;
                } else {
                    $time_unit = 'days'; // default
                }
                
                // Calculate exact available timestamp for countdown
                // Phase 5 T1-Anchor: Use offset_minutes from T1 completion, not interval_days
                $offset_minutes = isset($wave_config['offset_minutes']) ? intval($wave_config['offset_minutes']) : 0;
                $submitted_at = current_time('timestamp');
                $available_at = strtotime("+{$offset_minutes} minutes", $submitted_at);
                
                // Legacy fallback for old studies using interval_days
                if ($offset_minutes === 0 && isset($wave_config['interval_days'])) {
                    $interval_value = intval($wave_config['interval_days']);
                    switch ($time_unit) {
                        case 'minutes':
                            $available_at = strtotime("+{$interval_value} minutes", $submitted_at);
                            break;
                        case 'hours':
                            $available_at = strtotime("+{$interval_value} hours", $submitted_at);
                            break;
                        case 'days':
                        default:
                            $available_at = strtotime("+{$interval_value} days", $submitted_at);
                            break;
                    }
                }
                
                $next_wave_data = array(
                    'wave_index' => $next_wave['wave_index'],
                    'due_date' => $next_wave['due_date'],
                    'wave_name' => $next_wave['wave_name'],
                    'offset_minutes' => $offset_minutes,
                    'interval_days' => isset($interval_value) ? $interval_value : 0,
                    'reminder_days' => isset($wave_config['reminder_days']) ? intval($wave_config['reminder_days']) : 0,
                    'time_unit' => $time_unit,
                    'available_at' => $available_at * 1000 // Convert to milliseconds for JS
                );
                
                error_log(sprintf('[EIPSI-DIAG] Prepared next_wave_data: %s', json_encode($next_wave_data)));
                
                // v2.2.2 - TRIGGER INMEDIATO: Enviar Nudge 0 ahora si la siguiente toma YA está disponible
                // (evita esperar al cron hourly cuando interval=0 o el tiempo ya pasó)
                $available_timestamp = intval($available_at);
                $current_timestamp = current_time('timestamp');
                if ($available_timestamp <= $current_timestamp) {
                    error_log(sprintf('[EIPSI-DIAG] NEXT WAVE AVAILABLE NOW: wave_id=%d, available_at=%s, current=%s - Triggering immediate Nudge 0 email', 
                        $next_wave['wave_id'], date('Y-m-d H:i:s', $available_timestamp), date('Y-m-d H:i:s', $current_timestamp)));
                    
                    // Asegurar que la clase esté cargada
                    if (!class_exists('EIPSI_Wave_Availability_Email_Service')) {
                        require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-wave-availability-email-service.php';
                    }
                    
                    if (class_exists('EIPSI_Wave_Availability_Email_Service')) {
                        // Cargar dependencias necesarias para obtener los objetos (v2.2.3 Fix)
                        if (!class_exists('EIPSI_Wave_Service')) {
                            require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-wave-service.php';
                        }
                        if (!class_exists('EIPSI_Participant_Service')) {
                            require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-participant-service.php';
                        }

                        // Obtener objetos requeridos por el servicio
                        global $wpdb;
                        $assignment_obj = $wpdb->get_row($wpdb->prepare(
                            "SELECT * FROM {$wpdb->prefix}survey_assignments WHERE wave_id = %d AND participant_id = %d",
                            $next_wave['wave_id'],
                            $longitudinal_participant_id
                        ), OBJECT);

                        $wave_obj = EIPSI_Wave_Service::get_wave($next_wave['wave_id']);
                        $participant_obj = EIPSI_Participant_Service::get_by_id($longitudinal_participant_id);

                        // Solo proceder si tenemos todos los datos necesarios
                        if ($assignment_obj && $wave_obj && $participant_obj) {
                            $email_result = EIPSI_Wave_Availability_Email_Service::ensure_wave_availability_email_sent(
                                $assignment_obj,
                                $wave_obj,
                                $participant_obj,
                                $study_id
                            );
                            error_log(sprintf('[EIPSI-DIAG] Immediate Nudge 0 email result: %s', json_encode($email_result)));
                            
                            // Guardar para agregar al success_response después
                            if ($email_result['success'] && $email_result['sent']) {
                                $nudge_0_sent = true;
                                $nudge_0_message = __('Email de siguiente toma enviado inmediatamente', 'eipsi-forms');
                            }
                        } else {
                            error_log(sprintf('[EIPSI-DIAG] Could not trigger immediate Nudge 0: Missing objects. Assignment: %s, Wave: %s, Participant: %s', 
                                $assignment_obj ? 'OK' : 'MISSING', 
                                $wave_obj ? 'OK' : 'MISSING', 
                                $participant_obj ? 'OK' : 'MISSING'));
                        }
                    }
                }
            }
        } else {
            error_log('[EIPSI-DIAG] CONDICIÓN NO CUMPLIDA - No se procesa assignment. Faltan: ' . 
                (empty($wave_id) ? 'wave_id ' : '') .
                (!$study_id ? 'study_id ' : '') .
                (!$longitudinal_participant_id ? 'longitudinal_participant_id' : ''));
        }
        
        // Preparar respuesta de éxito con información de próximas tomas
        $success_response = array(
            'message' => __('Form submitted successfully!', 'eipsi-forms'),
            'external_db' => false,
            'insert_id' => $insert_id,
            'has_next' => $has_next_wave,
            'next_wave' => $next_wave_data
        );
        
        // Si no hay próxima toma, agregar mensaje de completado
        if (!$has_next_wave && $study_id) {
            $success_response['completion_message'] = __('All waves completed!', 'eipsi-forms');
        }
        
        // ✅ DATA SAFETY: Agregar info de verificación a la respuesta
        $success_response['verified'] = $verified;
        $success_response['storage_type'] = $storage_type;
        
        if ($used_fallback) {
            // Fallback succeeded - inform user with warning
            $success_response['fallback_used'] = true;
            $success_response['warning'] = __('Form was saved to local database (external database temporarily unavailable).', 'eipsi-forms');
            $success_response['error_code'] = $error_info['error_code'];
        }
        
        // v2.2.2 - Agregar info de email Nudge 0 si se envió inmediatamente
        if (!empty($nudge_0_sent)) {
            $success_response['nudge_0_sent'] = true;
            $success_response['nudge_0_message'] = $nudge_0_message;
        }

        // ==========================================================================
        // FASE 4 - TRACKING DE COMPLETITUD EN POOLS (v2.5.3)
        // Disparar hook para que otros handlers verifiquen completitud de pools
        // Solo para participantes autenticados con contexto longitudinal válido
        // ==========================================================================
        if ($authenticated_participant_id && $study_id) {
            do_action('eipsi_form_submitted', array(
                'survey_id'      => $study_id,
                'participant_id' => $authenticated_participant_id,
                'wave_index'     => $wave_index,
                'form_id'        => $stable_form_id,
                'insert_id'      => $insert_id,
            ));
        }

        wp_send_json_success($success_response);
        
    } else {
        // ✅ DATA SAFETY: Fallo crítico - todos los intentos fallaron incluyendo modo emergencia
        error_log('[EIPSI SAFETY] CRITICAL: All save attempts failed including emergency mode');
        
        wp_send_json_error(array(
            'message' => __('Error crítico: No se pudo guardar la respuesta. Por favor, contacte al administrador inmediatamente.', 'eipsi-forms'),
            'error_code' => 'SAFETY_SYSTEM_FAILURE',
            'contact_admin' => true
        ), 500);
    }
}

// =============================================================================
// FUNCIONES AUXILIARES PARA INVESTIGACIÓN EN PSICOTERAPIA - EIPSI
// =============================================================================

function eipsi_get_research_context($device, $duration) {
    if ($device === 'mobile') {
        return '📱 Posible contexto informal';
    } else {
        return '💻 Posible contexto formal';
    }
}

function eipsi_get_time_context($datetime) {
    $hour = date('H', strtotime($datetime));
    
    if ($hour >= 6 && $hour < 12) return '🌅 Mañana';
    if ($hour >= 12 && $hour < 18) return '🌞 Tarde'; 
    if ($hour >= 18 && $hour < 22) return '🌆 Noche';
    return '🌙 Madrugada';
}

function eipsi_get_platform_type($device, $screen_width) {
    if ($device === 'mobile') {
        if ($screen_width < 400) return '📱 Teléfono pequeño';
        if ($screen_width < 768) return '📱 Teléfono estándar';
        return '📱 Teléfono grande/Tablet pequeña';
    } else {
        if ($screen_width < 1200) return '💻 Laptop';
        return '🖥️ Desktop grande';
    }
}

function eipsi_ajax_get_response_details() {
    check_ajax_referer('eipsi_admin_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Unauthorized', 'eipsi-forms'));
    }
    
    global $wpdb;
    
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    
    if (empty($id)) {
        wp_send_json_error(__('Invalid ID', 'eipsi-forms'));
    }
    
    $table_name = $wpdb->prefix . 'vas_form_results';
    
    // INTENTO 1: Buscar en BD Externa si está habilitada
    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/database.php';
    $external_db = new EIPSI_External_Database();
    $response = null;

    if ($external_db->is_enabled()) {
        $mysqli = $external_db->get_connection();
        if ($mysqli) {
             // Determinar nombre de tabla (con o sin prefijo)
             // Esto es crítico porque algunos servidores externos no usan el prefijo WP
             $ext_table_name = $table_name;
             $check = $mysqli->query("SHOW TABLES LIKE '{$ext_table_name}'");
             if (!$check || $check->num_rows === 0) {
                $ext_table_name = 'vas_form_results';
             }

             $stmt = $mysqli->prepare("SELECT * FROM `{$ext_table_name}` WHERE id = ?");
             if ($stmt) {
                 $stmt->bind_param("i", $id);
                 $stmt->execute();
                 $result = $stmt->get_result();
                 if ($result && $row = $result->fetch_object()) {
                     $response = $row;
                 }
                 $stmt->close();
             }
             $mysqli->close();
        }
    }
    
    // INTENTO 2: Fallback a BD Local
    if (!$response) {
        $response = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d",
            $id
        ));
    }
    
    if (!$response) {
        wp_send_json_error(__('Response not found', 'eipsi-forms'));
    }
    
    $form_responses = json_decode($response->form_responses, true);

    $html = '';

    // =============================================================================
    // ANÁLISIS DE TIEMPOS POR PÁGINA
    // =============================================================================
    $html .= '<div style="margin-bottom: 20px;">';
        $html .= '<button type="button" id="toggle-timing-analysis" class="button" style="background: #135e96; color: white; border: none; padding: 8px 15px; border-radius: 4px; cursor: pointer;">';
        $html .= '⏱️ Mostrar Análisis de Tiempos';
        $html .= '</button>';
        $html .= '</div>';

        $html .= '<div id="timing-analysis-section" style="display: none; margin: 15px 0; padding: 15px; background: #f0f8ff; border-radius: 5px; border-left: 4px solid #135e96;">';

        // Parse metadata para obtener timing data
        $timing_metadata = null;
        if (!empty($response->metadata)) {
            $metadata_obj = json_decode($response->metadata, true);
            if (isset($metadata_obj['page_timings'])) {
                $timing_metadata = $metadata_obj['page_timings'];
            }
        }

        if ($timing_metadata) {
            // PAGE TIMINGS
            $html .= '<h4>⏱️ Tiempos por Página</h4>';

            if (isset($timing_metadata['total_duration'])) {
                $total_seconds = $timing_metadata['total_duration'];
                $minutes = floor($total_seconds / 60);
                $seconds = intval(round(fmod($total_seconds, 60)));

                if ($minutes > 0) {
                    $html .= '<p><strong>⏰ Tiempo total:</strong> ' . sprintf('%d min %d sec', $minutes, $seconds) . '</p>';
                } else {
                    $html .= '<p><strong>⏰ Tiempo total:</strong> ' . sprintf('%d sec', $seconds) . '</p>';
                }
                }

                // Page breakdown
            $html .= '<div style="margin-top: 10px; max-height: 200px; overflow-y: auto;">';
            $html .= '<table style="width: 100%; border-collapse: collapse; font-size: 12px;">';
            $html .= '<thead><tr style="background: #e9ecef;">';
            $html .= '<th style="padding: 8px; text-align: left; border-bottom: 2px solid #dee2e6;">Página</th>';
            $html .= '<th style="padding: 8px; text-align: right; border-bottom: 2px solid #dee2e6;">Duración</th>';
            $html .= '<th style="padding: 8px; text-align: left; border-bottom: 2px solid #dee2e6;">Timestamp</th>';
            $html .= '</tr></thead>';
            $html .= '<tbody>';

            $page_number = 0;
            foreach ($timing_metadata as $key => $page_data) {
                if ($key === 'total_duration') continue;

                $page_number++;
                $duration = isset($page_data['duration']) ? floatval($page_data['duration']) : 0;
                $timestamp = isset($page_data['timestamp']) ? $page_data['timestamp'] : '';

                // Format timestamp
                $formatted_time = '';
                if (!empty($timestamp)) {
                    try {
                        $dt = new DateTime($timestamp);
                        $formatted_time = $dt->format('H:i:s');
                    } catch (Exception $e) {
                        $formatted_time = '';
                    }
                }

                $html .= '<tr>';
                $html .= '<td style="padding: 12px 8px; border-bottom: 1px solid #eee;"><strong>Página ' . $page_number . '</strong></td>';
                $html .= '<td style="padding: 12px 8px; border-bottom: 1px solid #eee;">' . number_format($duration, 1) . ' s</td>';
                $html .= '<td style="padding: 12px 8px; border-bottom: 1px solid #eee;">' . esc_html($formatted_time) . '</td>';
                $html .= '</tr>';
            }

            $html .= '</tbody></table>';
            $html .= '</div>';

            // FIELD TIMINGS (si están disponibles)
            if (isset($metadata_obj['field_timings']) && !empty($metadata_obj['field_timings'])) {
                $html .= '<div style="margin-top: 20px; max-height: 250px; overflow-y: auto;">';
                $html .= '<h4>🎯 Tiempos por Campo</h4>';
                $html .= '<table style="width: 100%; border-collapse: collapse; font-size: 12px;">';
                $html .= '<thead><tr style="background: #e9ecef;">';
                $html .= '<th style="padding: 8px; text-align: left; border-bottom: 2px solid #dee2e6;">Campo</th>';
                $html .= '<th style="padding: 8px; text-align: right; border-bottom: 2px solid #dee2e6;">Tiempo (s)</th>';
                $html .= '<th style="padding: 8px; text-align: center; border-bottom: 2px solid #dee2e6;">Interacciones</th>';
                $html .= '<th style="padding: 8px; text-align: center; border-bottom: 2px solid #dee2e6;">Foco</th>';
                $html .= '</tr></thead>';
                $html .= '<tbody>';

                $field_count = 0;
                foreach ($metadata_obj['field_timings'] as $field_name => $field_data) {
                    $field_count++;
                    $time_focused = isset($field_data['time_focused']) ? floatval($field_data['time_focused']) : 0;
                    $interactions = isset($field_data['interaction_count']) ? intval($field_data['interaction_count']) : 0;
                    $focus_count = isset($field_data['focus_count']) ? intval($field_data['focus_count']) : 0;

                    $row_color = $field_count % 2 === 0 ? '#ffffff' : '#f8f9fa';
                    $html .= '<tr style="background: ' . $row_color . ';">';
                    $html .= '<td style="padding: 8px; border-bottom: 1px solid #e9ecef;"><code>' . esc_html($field_name) . '</code></td>';
                    $html .= '<td style="padding: 8px; border-bottom: 1px solid #e9ecef; text-align: right;">' . number_format($time_focused, 1) . '</td>';
                    $html .= '<td style="padding: 8px; border-bottom: 1px solid #e9ecef; text-align: center;">' . $interactions . '</td>';
                    $html .= '<td style="padding: 8px; border-bottom: 1px solid #e9ecef; text-align: center;">' . $focus_count . '</td>';
                    $html .= '</tr>';
                }

                $html .= '</tbody></table>';
                $html .= '</div>';
            }

            // ACTIVITY METRICS (si están disponibles)
            if (isset($metadata_obj['activity_metrics'])) {
                $activity = $metadata_obj['activity_metrics'];
                $active_time = isset($activity['active_time']) ? floatval($activity['active_time']) : 0;
                $inactive_time = isset($activity['inactive_time']) ? floatval($activity['inactive_time']) : 0;
                $activity_ratio = isset($activity['activity_ratio']) ? floatval($activity['activity_ratio']) : 0;

                $html .= '<div style="margin-top: 20px;">';
                $html .= '<h4>💤 Métricas de Actividad</h4>';

                $minutes_active = floor($active_time / 60);
                $seconds_active = intval(round($active_time % 60));
                $minutes_inactive = floor($inactive_time / 60);
                $seconds_inactive = intval(round($inactive_time % 60));

                $html .= '<p><strong>⏱️ Tiempo activo:</strong> ' . sprintf('%d min %d sec', $minutes_active, $seconds_active) . '</p>';
                $html .= '<p><strong>💤 Tiempo inactivo:</strong> ' . sprintf('%d min %d sec', $minutes_inactive, $seconds_inactive) . '</p>';
                $html .= '<p><strong>📊 Ratio de actividad:</strong> ' . number_format($activity_ratio * 100, 1) . '%</p>';

                // Activity bar visualization
                $html .= '<div style="margin-top: 10px; width: 100%; height: 24px; background: #e9ecef; border-radius: 4px; overflow: hidden;">';
                $html .= '<div style="width: ' . ($activity_ratio * 100) . '%; height: 100%; background: #10b981;"></div>';
                $html .= '</div>';
                $html .= '</div>';
            }
        } else {
            $html .= '<p><em style="color: #666;">No hay datos de tiempos disponibles para esta respuesta.</em></p>';
        }

        $html .= '</div>';

        // =============================================================================
        // METADATOS TÉCNICOS (SIEMPRE VISIBLES)
        // =============================================================================
        $html .= '<div style="margin-bottom: 20px;">';
        $html .= '<h4>📊 Metadatos Técnicos</h4>';

        // SOLO CAMBIA ESTA LÍNEA:
        $timezone = get_option('timezone_string') ?: 'UTC';
        $timezone_offset = get_option('gmt_offset');
        if ($timezone_offset && empty($timezone)) {
            $timezone_display = 'UTC' . ($timezone_offset > 0 ? '+' : '') . $timezone_offset;
        } else {
            $timezone_display = $timezone;
        }
        $html .= '<p><strong>📅 Fecha y hora:</strong> ' . esc_html($response->created_at) . ' <em style="color: #666; font-size: 0.9em;">(' . esc_html($timezone_display) . ')</em></p>';
    
    // Display timestamps if available
    if (!empty($response->start_timestamp_ms) || !empty($response->end_timestamp_ms)) {
        $html .= '<div style="margin: 10px 0; padding: 10px; background: #f8f9fa; border-radius: 4px;">';
        
        if (!empty($response->start_timestamp_ms)) {
            $start_time_formatted = gmdate('Y-m-d H:i:s.v', intval($response->start_timestamp_ms / 1000));
            $html .= '<p style="margin: 5px 0;"><strong>🕐 Inicio:</strong> ' . esc_html($start_time_formatted) . ' UTC</p>';
        }
        
        if (!empty($response->end_timestamp_ms)) {
            $end_time_formatted = gmdate('Y-m-d H:i:s.v', intval($response->end_timestamp_ms / 1000));
            $html .= '<p style="margin: 5px 0;"><strong>🕑 Fin:</strong> ' . esc_html($end_time_formatted) . ' UTC</p>';
        }
        
        if (!empty($response->start_timestamp_ms) && !empty($response->end_timestamp_ms)) {
            $calculated_duration_ms = intval($response->end_timestamp_ms) - intval($response->start_timestamp_ms);
            $calculated_duration_seconds = round($calculated_duration_ms / 1000, 3);
            $html .= '<p style="margin: 5px 0;"><strong>⏱️ Duración calculada:</strong> ' . number_format($calculated_duration_seconds, 3) . ' segundos</p>';
        }
        
        $html .= '</div>';
    }
    
    // ESTAS LÍNEAS QUEDAN IGUAL:
    $html .= '<p><strong>⏱️ Duración registrada:</strong> ' . (!empty($response->duration_seconds) ? number_format($response->duration_seconds, 3) : intval($response->duration)) . ' segundos</p>';
    $html .= '<p><strong>📍 Dispositivo:</strong> ' . esc_html($response->device) . '</p>';
    $html .= '</div>';
    
    // =============================================================================
    // DETALLES TÉCNICOS DEL DISPOSITIVO (COLAPSABLE)
    // =============================================================================
    $has_device_info = !empty($response->browser) || !empty($response->os) || !empty($response->screen_width) || !empty($response->ip_address);
    
    if ($has_device_info) {
        $html .= '<div style="margin-bottom: 20px;">';
        $html .= '<button type="button" id="toggle-device-info" class="button" style="background: #6c757d; color: white; border: none; padding: 8px 15px; border-radius: 4px; cursor: pointer; margin-bottom: 10px;">';
        $html .= '🖥️ Mostrar Detalles Técnicos del Dispositivo';
        $html .= '</button>';
        
        $html .= '<div id="device-info-section" style="display: none; padding: 15px; background: #f8f9fa; border-radius: 5px; border-left: 4px solid #6c757d;">';
        $html .= '<h4 style="margin-top: 0;">🖥️ Fingerprint Liviano (Dispositivo)</h4>';
        $html .= '<p style="color: #666; font-size: 0.9em; margin-bottom: 10px;">Ayuda a distinguir envíos desde la misma IP (ej. wifi de clínica). Solo se captura si los toggles están ON en Privacy & Metadata.</p>';
        
        if (!empty($response->ip_address)) {
            $html .= '<p><strong>🌐 IP Address:</strong> ' . esc_html($response->ip_address) . '</p>';
        } else {
            $html .= '<p><strong>🌐 IP Address:</strong> <em style="color: #999;">No disponible (toggle OFF)</em></p>';
        }
        
        if (!empty($response->browser)) {
            $html .= '<p><strong>🌍 Navegador:</strong> ' . esc_html($response->browser) . '</p>';
        } else {
            $html .= '<p><strong>🌍 Navegador:</strong> <em style="color: #999;">No disponible (toggle OFF)</em></p>';
        }
        
        if (!empty($response->os)) {
            $html .= '<p><strong>💻 Sistema Operativo:</strong> ' . esc_html($response->os) . '</p>';
        } else {
            $html .= '<p><strong>💻 Sistema Operativo:</strong> <em style="color: #999;">No disponible (toggle OFF)</em></p>';
        }
        
        if (!empty($response->screen_width)) {
            $html .= '<p><strong>📐 Tamaño de Pantalla:</strong> ' . esc_html($response->screen_width) . '</p>';
        } else {
            $html .= '<p><strong>📐 Tamaño de Pantalla:</strong> <em style="color: #999;">No disponible (toggle OFF)</em></p>';
        }
        
        if (!empty($response->session_id)) {
            $html .= '<p><strong>🔑 Session ID:</strong> <code style="background: #e9ecef; padding: 2px 6px; border-radius: 3px; font-family: monospace; font-size: 0.85em;">' . esc_html($response->session_id) . '</code></p>';
        }
        
        $html .= '</div>';
        $html .= '</div>';
    }
    
    // =============================================================================
    // SESSION IDENTIFIERS
    // =============================================================================
    $html .= '<div style="margin-bottom: 20px;">';
    $html .= '<h4>🔑 Session Identifiers</h4>';
    $html .= '<p><strong>Form ID:</strong> ' . (!empty($response->form_id) ? esc_html($response->form_id) : '<em>Not available</em>') . '</p>';
    $html .= '<p><strong>Participant ID:</strong> ' . (!empty($response->participant_id) ? esc_html($response->participant_id) : '<em>Not available</em>') . '</p>';
    $html .= '<p><strong>Form Name:</strong> ' . esc_html($response->form_name) . '</p>';
    $html .= '</div>';
    
    // =============================================================================
    // DATA EXPORT NOTICE
    // =============================================================================
    $html .= '<div style="margin: 20px 0; padding: 15px; background: #f0f6fc; border-left: 4px solid #2271b1; border-radius: 4px;">';
    $html .= '<h4 style="margin-top: 0;">📊 Access Complete Response Data</h4>';
    $html .= '<p style="margin-bottom: 10px;">For privacy and data protection, questionnaire responses are not displayed in the dashboard.</p>';
    $html .= '<p style="margin-bottom: 10px;"><strong>To view complete responses:</strong></p>';
    $html .= '<ol style="margin-left: 20px; line-height: 1.6;">';
    $html .= '<li>Use the <strong>CSV Export</strong> button for statistical analysis software (SPSS, R, etc.)</li>';
    $html .= '<li>Use the <strong>Excel Export</strong> button for spreadsheet analysis</li>';
    $html .= '</ol>';
    $html .= '<p style="margin-bottom: 0; font-size: 0.9em; color: #666;">Number of questions answered: <strong>' . (!empty($form_responses) ? count($form_responses) : 0) . '</strong></p>';
    $html .= '</div>';
    
    wp_send_json_success($html);
}

function eipsi_track_event_handler() {
    // Verify nonce for security
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'eipsi_tracking_nonce')) {
        wp_send_json_error(array(
            'message' => __('Invalid security token.', 'eipsi-forms')
        ), 403);
        return;
    }
    
    // Define allowed event types
    $allowed_events = array('view', 'start', 'page_change', 'submit', 'abandon', 'branch_jump');
    
    // Sanitize and validate POST data
    $event_type = isset($_POST['event_type']) ? sanitize_text_field($_POST['event_type']) : '';
    
    // Validate event type
    if (empty($event_type) || !in_array($event_type, $allowed_events, true)) {
        wp_send_json_error(array(
            'message' => __('Invalid event type.', 'eipsi-forms')
        ), 400);
        return;
    }
    
    // Sanitize other required fields
    $form_id = isset($_POST['form_id']) ? sanitize_text_field($_POST['form_id']) : '';
    $session_id = isset($_POST['session_id']) ? sanitize_text_field($_POST['session_id']) : '';
    
    // Validate required fields
    if (empty($session_id)) {
        wp_send_json_error(array(
            'message' => __('Missing required field: session_id.', 'eipsi-forms')
        ), 400);
        return;
    }
    
    // Sanitize optional fields
    $page_number = isset($_POST['page_number']) ? intval($_POST['page_number']) : null;
    $user_agent = isset($_POST['user_agent']) ? sanitize_text_field($_POST['user_agent']) : '';
    
    // Collect metadata for branch_jump events
    $metadata = null;
    if ($event_type === 'branch_jump') {
        $metadata = array();
        if (isset($_POST['from_page'])) {
            $metadata['from_page'] = intval($_POST['from_page']);
        }
        if (isset($_POST['to_page'])) {
            $metadata['to_page'] = intval($_POST['to_page']);
        }
        if (isset($_POST['field_id'])) {
            $metadata['field_id'] = sanitize_text_field($_POST['field_id']);
        }
        if (isset($_POST['matched_value'])) {
            $metadata['matched_value'] = sanitize_text_field($_POST['matched_value']);
        }
        $metadata = !empty($metadata) ? wp_json_encode($metadata) : null;
    }
    
    // Prepare data for database insertion
    global $wpdb;
    
    $insert_data = array(
        'form_id' => $form_id,
        'session_id' => $session_id,
        'event_type' => $event_type,
        'page_number' => $page_number,
        'metadata' => $metadata,
        'user_agent' => $user_agent,
        'created_at' => current_time('mysql')
    );
    
    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/privacy-config.php';
    $config = get_privacy_config($form_id);
    $insert_data = eipsi_filter_capture_data($insert_data, $config);

    // Check if external database is configured
    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/database.php';
    $db_helper = new EIPSI_External_Database();
    $external_db_enabled = $db_helper->is_enabled();
    $used_fallback = false;
    
    if ($external_db_enabled) {
        // Try external database first
        $result = $db_helper->insert_form_event($insert_data);
        
        if ($result['success']) {
            // External DB insert succeeded
            wp_send_json_success(array(
                'message' => __('Event tracked successfully.', 'eipsi-forms'),
                'event_id' => $result['insert_id'],
                'tracked' => true,
                'external_db' => true
            ));
            return;
        } else {
            // External DB failed, fall back to WordPress DB
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('EIPSI Tracking: External DB insert failed, falling back to WordPress DB - ' . $result['error']);
            }
            $used_fallback = true;
        }
    }
    
    // Use WordPress database (either as default or as fallback)
    $table_name = $wpdb->prefix . 'vas_form_events';
    $insert_formats = array('%s', '%s', '%s', '%d', '%s', '%s', '%s');
    
    $wpdb_result = $wpdb->insert($table_name, $insert_data, $insert_formats);
    
    // Check for database errors
    if ($wpdb_result === false) {
        // Log error but don't crash tracking
        error_log('EIPSI Tracking: Failed to insert event - ' . $wpdb->last_error);
        
        // Still return success to keep tracking JS resilient
        wp_send_json_success(array(
            'message' => __('Event logged.', 'eipsi-forms'),
            'event_id' => null,
            'logged' => true
        ));
        return;
    }
    
    // Return success with event ID
    wp_send_json_success(array(
        'message' => __('Event tracked successfully.', 'eipsi-forms'),
        'event_id' => $wpdb->insert_id,
        'tracked' => true,
        'external_db' => false,
        'fallback_used' => $used_fallback
    ));
}

// =============================================================================
// EXTERNAL DATABASE CONFIGURATION HANDLERS
// =============================================================================

function eipsi_test_db_connection_handler() {
    check_ajax_referer('eipsi_admin_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array(
            'message' => __('Unauthorized', 'eipsi-forms')
        ));
    }
    
    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/database.php';
    $db_helper = new EIPSI_External_Database();
    
    $host = isset($_POST['host']) ? sanitize_text_field($_POST['host']) : '';
    $user = isset($_POST['user']) ? sanitize_text_field($_POST['user']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    $db_name = isset($_POST['db_name']) ? sanitize_text_field($_POST['db_name']) : '';
    
    if (empty($host) || empty($user) || empty($db_name)) {
        wp_send_json_error(array(
            'message' => __('Please fill in all required fields.', 'eipsi-forms')
        ));
    }
    
    $result = $db_helper->test_connection($host, $user, $password, $db_name);
    
    if ($result['success']) {
        wp_send_json_success($result);
    } else {
        wp_send_json_error($result);
    }
}

function eipsi_save_db_config_handler() {
    check_ajax_referer('eipsi_admin_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array(
            'message' => __('Unauthorized', 'eipsi-forms')
        ));
    }
    
    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/database.php';
    $db_helper = new EIPSI_External_Database();
    
    $host = isset($_POST['host']) ? sanitize_text_field($_POST['host']) : '';
    $user = isset($_POST['user']) ? sanitize_text_field($_POST['user']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    $db_name = isset($_POST['db_name']) ? sanitize_text_field($_POST['db_name']) : '';
    
    // If password is empty, get existing password
    if (empty($password)) {
        $existing_credentials = $db_helper->get_credentials();
        if ($existing_credentials && isset($existing_credentials['password'])) {
            $password = $existing_credentials['password'];
        }
    }
    
    if (empty($host) || empty($user) || empty($password) || empty($db_name)) {
        wp_send_json_error(array(
            'message' => __('Please fill in all required fields.', 'eipsi-forms')
        ));
    }
    
    // Test connection before saving
    $test_result = $db_helper->test_connection($host, $user, $password, $db_name);
    
    if (!$test_result['success']) {
        wp_send_json_error(array(
            'message' => __('Connection test failed. Please verify your credentials.', 'eipsi-forms') . ' ' . $test_result['message']
        ));
    }
    
    // Save credentials
    $success = $db_helper->save_credentials($host, $user, $password, $db_name);
    
    if ($success) {
        // Trigger schema verification and synchronization
        require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/database-schema-manager.php';
        $schema_result = EIPSI_Database_Schema_Manager::on_credentials_changed();
        
        $status = $db_helper->get_status();
        
        // Include schema verification results in response
        $response_data = array(
            'message' => sprintf(
                __('Configuration saved successfully! Data will now be stored in: %s', 'eipsi-forms'),
                $db_name
            ),
            'status' => $status,
            'schema_verified' => $schema_result['success'],
            'tables_created' => array(
                'results' => $schema_result['results_table']['created'],
                'events' => $schema_result['events_table']['created']
            ),
            'columns_added' => array(
                'results' => count($schema_result['results_table']['columns_added']),
                'events' => count($schema_result['events_table']['columns_added'])
            )
        );
        
        if (!$schema_result['success']) {
            $response_data['schema_warnings'] = $schema_result['errors'];
        }
        
        wp_send_json_success($response_data);
    } else {
        wp_send_json_error(array(
            'message' => __('Failed to save configuration.', 'eipsi-forms')
        ));
    }
}

function eipsi_disable_external_db_handler() {
    check_ajax_referer('eipsi_admin_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array(
            'message' => __('Unauthorized', 'eipsi-forms')
        ));
    }
    
    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/database.php';
    $db_helper = new EIPSI_External_Database();
    
    $db_helper->disable();
    
    wp_send_json_success(array(
        'message' => __('External database disabled. Form submissions will now be stored in the WordPress database.', 'eipsi-forms')
    ));
}

function eipsi_get_db_status_handler() {
    check_ajax_referer('eipsi_admin_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array(
            'message' => __('Unauthorized', 'eipsi-forms')
        ));
    }
    
    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/database.php';
    $db_helper = new EIPSI_External_Database();
    
    $status = $db_helper->get_status();
    
    wp_send_json_success($status);
}

function eipsi_check_external_db_handler() {
    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/database.php';
    $db_helper = new EIPSI_External_Database();
    
    wp_send_json_success(array(
        'enabled' => $db_helper->is_enabled()
    ));
}

function eipsi_verify_schema_handler() {
    check_ajax_referer('eipsi_admin_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array(
            'message' => __('Unauthorized', 'eipsi-forms')
        ));
    }
    
    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/database.php';
    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/database-schema-manager.php';
    
    $db_helper = new EIPSI_External_Database();
    
    if (!$db_helper->is_enabled()) {
        wp_send_json_error(array(
            'message' => __('External database is not enabled', 'eipsi-forms')
        ));
    }
    
    $mysqli = $db_helper->get_connection();
    
    if (!$mysqli) {
        wp_send_json_error(array(
            'message' => __('Failed to connect to external database', 'eipsi-forms')
        ));
    }
    
    $result = EIPSI_Database_Schema_Manager::verify_and_sync_schema($mysqli);
    $mysqli->close();
    
    if ($result['success']) {
        wp_send_json_success(array(
            'message' => __('Schema verification completed successfully', 'eipsi-forms'),
            'results' => $result
        ));
    } else {
        wp_send_json_error(array(
            'message' => __('Schema verification failed', 'eipsi-forms'),
            'errors' => $result['errors']
        ));
    }
}

function eipsi_verify_local_schema_handler() {
    check_ajax_referer('eipsi_admin_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array(
            'message' => __('Unauthorized', 'eipsi-forms')
        ));
    }

    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/database.php';
    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/database-schema-manager.php';

    $db_helper = new EIPSI_External_Database();
    $before_status = $db_helper->get_local_table_status();

    $repair_log = EIPSI_Database_Schema_Manager::repair_local_schema();
    $after_status = $db_helper->get_local_table_status();

    if (empty($repair_log) || (isset($repair_log['success']) && $repair_log['success'] === false)) {
        wp_send_json_error(array(
            'message' => __('No se pudo verificar el esquema local.', 'eipsi-forms')
        ));
    }

    $tables_created = array();
    $table_keys = array(
        'results_table',
        'events_table',
        'randomization_configs_table',
        'randomization_assignments_table',
    );

    foreach ($table_keys as $key) {
        $before_exists = isset($before_status[$key]['exists']) ? (bool) $before_status[$key]['exists'] : false;
        $after_exists = isset($after_status[$key]['exists']) ? (bool) $after_status[$key]['exists'] : false;
        if (!$before_exists && $after_exists && !empty($after_status[$key]['table_name'])) {
            $tables_created[] = $after_status[$key]['table_name'];
        }
    }

    if (!empty($before_status['longitudinal_tables']) && !empty($after_status['longitudinal_tables'])) {
        foreach ($after_status['longitudinal_tables'] as $key => $table_info) {
            $before_exists = isset($before_status['longitudinal_tables'][$key]['exists']) ? (bool) $before_status['longitudinal_tables'][$key]['exists'] : false;
            $after_exists = isset($table_info['exists']) ? (bool) $table_info['exists'] : false;
            if (!$before_exists && $after_exists && !empty($table_info['table_name'])) {
                $tables_created[] = $table_info['table_name'];
            }
        }
    }

    $columns_added_total = 0;
    foreach ($repair_log as $table_info) {
        if (is_array($table_info) && isset($table_info['columns_added']) && is_array($table_info['columns_added'])) {
            $columns_added_total += count($table_info['columns_added']);
        }
    }

    $last_verified = $after_status['last_verified'] ?? get_option('eipsi_schema_last_verified', '');
    if (!empty($last_verified)) {
        $last_verified = mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $last_verified);
    }

    wp_send_json_success(array(
        'message' => __('Esquema local verificado y reparado correctamente.', 'eipsi-forms'),
        'tables_created' => $tables_created,
        'columns_added' => $columns_added_total,
        'last_verified' => $last_verified,
        'repair_log' => $repair_log
    ));
}

function eipsi_check_local_table_status_handler() {
    check_ajax_referer('eipsi_admin_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array(
            'message' => __('Unauthorized', 'eipsi-forms')
        ));
    }

    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/database.php';
    $db_helper = new EIPSI_External_Database();

    $result = $db_helper->get_local_table_status();

    if (!empty($result['success'])) {
        wp_send_json_success($result);
    }

    wp_send_json_error($result);
}

function eipsi_check_table_status_handler() {
    check_ajax_referer('eipsi_admin_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array(
            'message' => __('Unauthorized', 'eipsi-forms')
        ));
    }

    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/database.php';
    $db_helper = new EIPSI_External_Database();

    $result = $db_helper->check_table_status();

    if ($result['success']) {
        wp_send_json_success($result);
    } else {
        wp_send_json_error($result);
    }
}

function eipsi_delete_all_data_handler() {
    check_ajax_referer('eipsi_delete_all_data', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array(
            'message' => __('Unauthorized', 'eipsi-forms')
        ), 403);
    }

    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/database.php';
    $db_helper = new EIPSI_External_Database();

    $result = $db_helper->delete_all_data();

    if ($result['success']) {
        wp_send_json_success(array(
            'message' => $result['message'],
            'database' => isset($result['database']) ? $result['database'] : 'wordpress'
        ));
    } else {
        wp_send_json_error(array(
            'message' => isset($result['message']) ? $result['message'] : __('Failed to delete clinical data.', 'eipsi-forms'),
            'error_code' => isset($result['error_code']) ? $result['error_code'] : 'UNKNOWN'
        ));
    }
}

/**
 * AJAX Handler: Save completion message configuration
 */
function eipsi_save_completion_message_handler() {
    check_ajax_referer('eipsi_admin_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('Unauthorized', 'eipsi-forms')), 403);
    }
    
    $config = array(
        'title'            => isset($_POST['title']) ? sanitize_text_field($_POST['title']) : '',
        'message'          => isset($_POST['message']) ? wp_kses_post($_POST['message']) : '',
        'show_logo'        => isset($_POST['show_logo']),
        'show_home_button' => isset($_POST['show_home_button']),
        'button_text'      => isset($_POST['button_text']) ? sanitize_text_field($_POST['button_text']) : '',
        'button_action'    => isset($_POST['button_action']) ? sanitize_text_field($_POST['button_action']) : 'reload',
        'show_animation'   => isset($_POST['show_animation']),
    );
    
    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/completion-message-backend.php';
    
    if (EIPSI_Completion_Message::save_config($config)) {
        wp_send_json_success(array(
            'message' => __('Completion message saved successfully', 'eipsi-forms'),
            'config'  => EIPSI_Completion_Message::get_config(),
        ));
    } else {
        wp_send_json_error(array('message' => __('Failed to save configuration', 'eipsi-forms')));
    }
}
add_action('wp_ajax_eipsi_save_completion_message', 'eipsi_save_completion_message_handler');

/**
 * AJAX Handler: Get completion message configuration for frontend
 */
function eipsi_get_completion_config_handler() {
    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/completion-message-backend.php';
    
    $config = EIPSI_Completion_Message::get_config();
    
    wp_send_json_success(array(
        'config' => $config,
    ));
}

/**
 * Save & Continue: Save partial response
 */
function eipsi_save_partial_response_handler() {
    // Nonce required even for nopriv. Prevents CSRF + drive-by writes.
    check_ajax_referer('eipsi_save_partial', 'nonce');

    // #region agent log
    $eipsi_dbg_log = static function($message, $data = array(), $hypothesisId = 'SC') {
        try {
            $log_path = defined('WP_CONTENT_DIR')
                ? trailingslashit(WP_CONTENT_DIR) . 'eipsi-save-partial-debug.log'
                : 'eipsi-save-partial-debug.log';
            $payload = array(
                'hypothesisId' => $hypothesisId,
                'location' => 'admin/ajax-handlers.php:eipsi_save_partial_response_handler',
                'message' => $message,
                'data' => $data,
                'timestamp' => (int) round(microtime(true) * 1000),
            );
            @file_put_contents($log_path, wp_json_encode($payload) . PHP_EOL, FILE_APPEND);
        } catch (Exception $e) {
            // ignore
        }
    };
    // #endregion agent log
    
    $form_id = isset($_POST['form_id']) ? sanitize_text_field($_POST['form_id']) : '';
    $participant_id = isset($_POST['participant_id']) ? sanitize_text_field($_POST['participant_id']) : '';
    $session_id = isset($_POST['session_id']) ? sanitize_text_field($_POST['session_id']) : '';
    $page_index = isset($_POST['page_index']) ? intval($_POST['page_index']) : 1;
    $responses = isset($_POST['responses']) ? $_POST['responses'] : array();

    // Payload size guard (50KB max) — prevents logical DoS.
    $raw_data = '';
    if (isset($_POST['data'])) {
        $raw_data = is_string($_POST['data']) ? wp_unslash($_POST['data']) : wp_json_encode($_POST['data']);
    } elseif (isset($_POST['responses'])) {
        $raw_data = is_string($_POST['responses']) ? wp_unslash($_POST['responses']) : wp_json_encode($_POST['responses']);
    }
    $raw_len = is_string($raw_data) ? strlen($raw_data) : 0;
    if ($raw_len > 51200) {
        $eipsi_dbg_log('save_partial_rejected_payload_too_large', array(
            'bytes' => $raw_len,
        ), 'SC');
        wp_send_json_error(
            new WP_Error('eipsi_payload_too_large', __('Draft payload exceeds 50KB limit.', 'eipsi-forms')),
            400
        );
    }

    // Rate limit: 30 requests / minute by session_id (fallback to IP hash).
    $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : '';
    $rate_key_material = !empty($session_id) ? ('sid:' . $session_id) : ('ip:' . $ip);
    $rate_key = 'eipsi_sc_rl_' . md5($rate_key_material);
    $bucket = get_transient($rate_key);
    if (!is_array($bucket) || !isset($bucket['count'], $bucket['reset'])) {
        $bucket = array('count' => 0, 'reset' => time() + 60);
    }
    // reset window if needed
    if ((int) $bucket['reset'] < time()) {
        $bucket = array('count' => 0, 'reset' => time() + 60);
    }
    $bucket['count'] = (int) $bucket['count'] + 1;
    set_transient($rate_key, $bucket, 70);

    if ($bucket['count'] > 30) {
        $eipsi_dbg_log('save_partial_rate_limited', array(
            'count' => $bucket['count'],
            'windowSeconds' => max(0, (int) $bucket['reset'] - time()),
            'keyHash' => md5($rate_key_material),
        ), 'SC');
        wp_send_json_error(
            new WP_Error('eipsi_rate_limited', __('Too many Save & Continue requests. Please wait a minute and try again.', 'eipsi-forms')),
            429
        );
    }
    
    if (empty($form_id) || empty($participant_id) || empty($session_id)) {
        $eipsi_dbg_log('save_partial_missing_required', array(
            'hasFormId' => !empty($form_id),
            'hasParticipantId' => !empty($participant_id),
            'hasSessionId' => !empty($session_id),
        ), 'SC');
        wp_send_json_error(array(
            'message' => __('Missing required parameters', 'eipsi-forms')
        ));
    }
    
    $result = EIPSI_Partial_Responses::save($form_id, $participant_id, $session_id, $page_index, $responses);
    
    if ($result['success']) {
        $eipsi_dbg_log('save_partial_ok', array(
            'action' => $result['action'] ?? null,
            'pageIndex' => $page_index,
            'bytes' => $raw_len,
            'count' => $bucket['count'],
        ), 'SC');
        wp_send_json_success(array(
            'message' => __('Partial response saved', 'eipsi-forms'),
            'action' => $result['action'],
            'id' => $result['id']
        ));
    } else {
        $eipsi_dbg_log('save_partial_failed', array(
            'error' => $result['error'] ?? null,
            'pageIndex' => $page_index,
        ), 'SC');
        wp_send_json_error(array(
            'message' => __('Failed to save partial response', 'eipsi-forms'),
            'error' => $result['error']
        ));
    }
}

/**
 * Save & Continue: Load partial response
 */
function eipsi_load_partial_response_handler() {
    $form_id = isset($_POST['form_id']) ? sanitize_text_field($_POST['form_id']) : '';
    $participant_id = isset($_POST['participant_id']) ? sanitize_text_field($_POST['participant_id']) : '';
    $session_id = isset($_POST['session_id']) ? sanitize_text_field($_POST['session_id']) : '';
    
    error_log(sprintf('[EIPSI SAVE&CONTINUE] Load request: form_id=%s, participant_id=%s, session_id=%s', 
        $form_id, $participant_id, $session_id));
    
    if (empty($form_id) || empty($participant_id) || empty($session_id)) {
        error_log('[EIPSI SAVE&CONTINUE] Load failed: missing required parameters');
        wp_send_json_error(array(
            'message' => __('Missing required parameters', 'eipsi-forms')
        ));
    }
    
    $partial = EIPSI_Partial_Responses::load($form_id, $participant_id, $session_id);
    
    if ($partial) {
        error_log(sprintf('[EIPSI SAVE&CONTINUE] Load success: found partial response, page_index=%d, response_count=%d', 
            isset($partial['page_index']) ? intval($partial['page_index']) : 0,
            isset($partial['responses']) && is_array($partial['responses']) ? count($partial['responses']) : 0));
        wp_send_json_success(array(
            'found' => true,
            'partial' => $partial
        ));
    } else {
        error_log('[EIPSI SAVE&CONTINUE] Load success: no partial response found (clean start)');
        wp_send_json_success(array(
            'found' => false,
            'partial' => null
        ));
    }
}

/**
 * Save & Continue: Diagnóstico - Mostrar estado guardado
 * 
 * Endpoint para debugging que muestra qué datos tiene guardados el backend
 * para una sesión específica, incluyendo detalles de cada campo.
 */
add_action('wp_ajax_nopriv_eipsi_debug_partial_response', 'eipsi_debug_partial_response_handler');
add_action('wp_ajax_eipsi_debug_partial_response', 'eipsi_debug_partial_response_handler');

function eipsi_debug_partial_response_handler() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('Unauthorized', 'eipsi-forms')), 403);
        return;
    }
    check_ajax_referer('eipsi_admin_nonce', 'nonce');

    $form_id = isset($_POST['form_id']) ? sanitize_text_field($_POST['form_id']) : '';
    $participant_id = isset($_POST['participant_id']) ? sanitize_text_field($_POST['participant_id']) : '';
    $session_id = isset($_POST['session_id']) ? sanitize_text_field($_POST['session_id']) : '';
    
    error_log('[EIPSI S&C DEBUG] Diagnostic request received');
    
    if (empty($form_id) || empty($participant_id) || empty($session_id)) {
        wp_send_json_error(array(
            'message' => 'Missing required parameters',
            'received' => array(
                'form_id' => $form_id,
                'participant_id' => $participant_id,
                'session_id' => $session_id
            )
        ));
        return;
    }
    
    // Cargar datos guardados
    $partial = EIPSI_Partial_Responses::load($form_id, $participant_id, $session_id);
    
    if (!$partial) {
        error_log('[EIPSI S&C DEBUG] No partial response found in database');
        wp_send_json_success(array(
            'found' => false,
            'message' => 'No hay datos guardados para esta sesión',
            'search_params' => array(
                'form_id' => $form_id,
                'participant_id' => $participant_id,
                'session_id' => $session_id
            )
        ));
        return;
    }
    
    // Analizar respuestas guardadas
    $responses = isset($partial['responses']) && is_array($partial['responses']) ? $partial['responses'] : array();
    $response_count = count($responses);
    
    // Categorizar campos
    $field_analysis = array(
        'consent_fields' => array(),
        'demographic_fields' => array(),
        'scale_fields' => array(),
        'other_fields' => array()
    );
    
    foreach ($responses as $field_name => $field_value) {
        $field_info = array(
            'name' => $field_name,
            'value' => $field_value,
            'type' => gettype($field_value),
            'length' => is_string($field_value) ? strlen($field_value) : null
        );
        
        // Categorizar
        if (strpos($field_name, 'consent') !== false || strpos($field_name, 'eipsi_consent') !== false) {
            $field_analysis['consent_fields'][] = $field_info;
        } elseif (strpos($field_name, 'phq') !== false || strpos($field_name, 'gad') !== false || strpos($field_name, 'mbi') !== false) {
            $field_analysis['scale_fields'][] = $field_info;
        } elseif (in_array($field_name, array('edad', 'genero', 'profesion_sanitaria', 'anos_experiencia', 'tipo_institucion', 'horas_semanales', 'situacion_laboral'))) {
            $field_analysis['demographic_fields'][] = $field_info;
        } else {
            $field_analysis['other_fields'][] = $field_info;
        }
    }
    
    $diagnostic = array(
        'found' => true,
        'summary' => array(
            'total_responses' => $response_count,
            'page_index' => isset($partial['page_index']) ? intval($partial['page_index']) : 0,
            'last_updated' => isset($partial['updated_at']) ? $partial['updated_at'] : null,
            'consent_fields_count' => count($field_analysis['consent_fields']),
            'demographic_fields_count' => count($field_analysis['demographic_fields']),
            'scale_fields_count' => count($field_analysis['scale_fields']),
            'other_fields_count' => count($field_analysis['other_fields'])
        ),
        'field_analysis' => $field_analysis,
        'raw_responses' => $responses,
        'search_params' => array(
            'form_id' => $form_id,
            'participant_id' => $participant_id,
            'session_id' => $session_id
        )
    );
    
    error_log(sprintf('[EIPSI S&C DEBUG] Found %d responses: %d consent, %d demographic, %d scale, %d other',
        $response_count,
        count($field_analysis['consent_fields']),
        count($field_analysis['demographic_fields']),
        count($field_analysis['scale_fields']),
        count($field_analysis['other_fields'])
    ));
    
    wp_send_json_success($diagnostic);
}

/**
 * Fase 2 - v2.5: Save Consent Decision (T1 rejection)
 * Handles both 'accepted' and 'declined' decisions at consent block
 * 
 * @since 2.5.0
 */
add_action('wp_ajax_nopriv_eipsi_save_consent_decision', 'eipsi_save_consent_decision_handler');
add_action('wp_ajax_eipsi_save_consent_decision', 'eipsi_save_consent_decision_handler');

function eipsi_save_consent_decision_handler() {
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'eipsi_forms_nonce')) {
        wp_send_json_error(array('message' => __('Invalid nonce', 'eipsi-forms')));
        return;
    }
    
    $form_id = sanitize_text_field($_POST['form_id'] ?? '');
    $decision = sanitize_text_field($_POST['decision'] ?? '');
    if (empty($form_id) || !in_array($decision, array('accepted', 'declined'), true)) {
        wp_send_json_error(array('message' => __('Invalid parameters', 'eipsi-forms')));
        return;
    }
    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-auth-service.php';
    $consent_context = EIPSI_Auth_Service::authorize_form_operation($form_id, $_POST, array(), 'consent');
    if (!$consent_context['success']) {
        wp_send_json_error(array('message' => __('Unauthorized', 'eipsi-forms'), 'code' => $consent_context['error']), 403);
        return;
    }
    $participant_id = $consent_context['participant_id'];
    $study_id = $consent_context['study_id'];
    $template_id = $consent_context['template_id'];
    $participant_source = $consent_context['longitudinal'] ? 'SESSION' : 'ANONYMOUS';
    $study_source = $consent_context['longitudinal'] ? 'SESSION' : 'NONE';
    global $wpdb;

    // Instrumentación detallada antes de la validación
    error_log(sprintf(
        '[EIPSI-CONSENT-DEBUG] Decision Handler: FormID=%s (TemplateID=%d), ParticipantID=%s (Source=%s), StudyID=%s (Source=%s)',
        $form_id,
        $template_id,
        $participant_id,
        $participant_source,
        $study_id ?: 'NULL',
        $study_source
    ));
    
    if ($study_id) {
        // Longitudinal study: save to wp_survey_participants
        $table = $wpdb->prefix . 'survey_participants';
        
        $data = array(
            'consent_decision' => $decision,
            'consent_decided_at' => current_time('mysql'),
            'consent_ip_address' => eipsi_get_client_ip(),
            'consent_user_agent' => sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'consent_context' => 'T1_consent_block',
        );
        
        require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/privacy-config.php';
        $config=get_privacy_config(generate_stable_form_id($form_id));
        if (empty($config['ip_address'])) { $data['consent_ip_address']=null; }
        if (empty($config['user_agent_full'])) { $data['consent_user_agent']=null; }

        // If declined, also set blocked_survey_id
        if ($decision === 'declined') {
            // v2.5.5: Ensure it is a numeric ID (template_id if available, else numeric fallback)
            $blocked_id = (is_numeric($template_id) && $template_id > 0) ? intval($template_id) : null;
            
            if (!$blocked_id && is_numeric($form_id)) {
                $blocked_id = intval($form_id);
            }
            
            // Final fallback to study_id if all else fails to be numeric
            if (!$blocked_id && is_numeric($study_id)) {
                $blocked_id = intval($study_id);
            }
            
            $data['consent_blocked_survey_id'] = $blocked_id;
            $data["is_active"] = 0; // Marcar como inactivo al rechazar

        }
        
        $validate_query = $wpdb->prepare(
            "SELECT id FROM {$table} WHERE id = %d AND survey_id = %d LIMIT 1",
            $participant_id,
            $study_id
        );
        
        error_log("[EIPSI-CONSENT-DEBUG] Validation SQL: " . $validate_query);
        
        $existing_participant = $wpdb->get_var($validate_query);

        if (!$existing_participant) {
            error_log(sprintf('[EIPSI-CONSENT-ERROR] Participant %s not found for Study %s', $participant_id, $study_id));
            wp_send_json_error(array('message' => __('Participant not found for this study', 'eipsi-forms')));
            return;
        }

        $data['status'] = ($decision === 'declined') ? 'consent_declined' : 'active';

        $result = $wpdb->update(
            $table,
            $data,
            array(
                'id' => $participant_id,
                'survey_id' => $study_id,
            )
        );

        if ($result === false) {
            wp_send_json_error(array('message' => __('Could not save consent decision', 'eipsi-forms')));
            return;
        }
    }
    // Standalone consent keeps the existing UI response, without manufacturing
    // a longitudinal participant from an unverified client identifier.

    // Log the decision
    if (function_exists('eipsi_log_audit')) {
        eipsi_log_audit('consent_decision', array(
            'form_id' => $form_id,
            'participant_id' => $participant_id,
            'decision' => $decision,
            'study_id' => $study_id ?? null,
        ));
    }
    
    // Prepare redirect URL for declined consent
    $redirect_url = null;
    if ($decision === 'declined') {
        $study_url = '';

        if ($study_id) {
            // Utilizar el helper que busca por meta eipsi_study_id (inmune a colisiones de texto)
            if (!function_exists('eipsi_get_study_page_url')) {
                require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/setup-wizard.php';
            }
            
            $study_url = function_exists('eipsi_get_study_page_url') 
                ? eipsi_get_study_page_url($study_id) 
                : '';
        }

        if (empty($study_url)) {
            $study_url = home_url('/');
        }

        $redirect_url = add_query_arg(array('consent' => 'declined'), $study_url);
        
        error_log("[EIPSI-CONSENT] Decision declined - Redirecting to: {$redirect_url} (Study ID: {$study_id})");
        
        // Destruir sesión SOLO después de haber procesado redirección y logs
        if ($consent_context['longitudinal'] && class_exists('EIPSI_Auth_Service')) {
            EIPSI_Auth_Service::destroy_session();
        }
    }
    
    error_log("[EIPSI-CONSENT] Sending response with redirect: {$redirect_url}");
    
    wp_send_json_success(array(
        'message' => __('Decision saved', 'eipsi-forms'),
        'decision' => $decision,
        'redirect' => $redirect_url,
    ));
}

/**
 * Fase 3 - v2.5: AJAX Handler for Study Abandonment
 * Handles both B1 (standard withdrawal) and B2 (data deletion)
 * 
 * @since 2.5.0
 */
add_action('wp_ajax_eipsi_abandon_study', 'eipsi_abandon_study_handler');
add_action('wp_ajax_nopriv_eipsi_abandon_study', 'eipsi_abandon_study_handler');

function eipsi_abandon_study_handler() {
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'eipsi_abandon_study')) {
        error_log('[EIPSI-ABANDON] ERROR: Invalid nonce');
        wp_send_json_error(array('message' => __('Invalid security token', 'eipsi-forms')));
        return;
    }
    
    global $wpdb;
    
    // Participant identity and study context must come from the authenticated session.
    $participant_id = class_exists('EIPSI_Auth_Service') ? (int) EIPSI_Auth_Service::get_current_participant() : 0;
    $study_id = $participant_id ? (int) EIPSI_Auth_Service::get_current_survey() : 0;
    if (!$participant_id || !$study_id) {
        wp_send_json_error(array('message' => __('Authentication required', 'eipsi-forms')), 403);
        return;
    }

    // Preserve existing payloads, but reject attempts to act on another identity or study.
    foreach (array('participant_id' => $participant_id, 'study_id' => $study_id) as $key => $expected) {
        if (isset($_POST[$key]) && (!is_scalar($_POST[$key]) || (string) $_POST[$key] !== (string) $expected)) {
            wp_send_json_error(array('message' => __('Unauthorized', 'eipsi-forms')), 403);
            return;
        }
    }
    $belongs_to_study = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}survey_participants WHERE id = %d AND survey_id = %d",
        $participant_id,
        $study_id
    ));
    if (!$belongs_to_study) {
        wp_send_json_error(array('message' => __('Unauthorized', 'eipsi-forms')), 403);
        return;
    }

    $withdrawal_type = sanitize_text_field($_POST['withdrawal_type'] ?? ''); // 'b1' or 'b2'
    $verification_text = sanitize_text_field($_POST['verification_text'] ?? '');
    error_log("[EIPSI-ABANDON] === START === participant_id={$participant_id}, study_id={$study_id}, type={$withdrawal_type}");

    // Validate withdrawal type
    if (!in_array($withdrawal_type, array('b1', 'b2'), true)) {
        wp_send_json_error(array('message' => __('Invalid withdrawal type', 'eipsi-forms')));
        return;
    }
    
    // For B2 (data deletion), verify the exact text was entered
    if ($withdrawal_type === 'b2') {
        // Simplified verification text (must match frontend)
        $required_text = 'QUIERO QUE ELIMINEN MIS DATOS';
        if (strtoupper(trim($verification_text)) !== $required_text) {
            wp_send_json_error(array(
                'message' => __('Verification text does not match. Please type exactly as shown.', 'eipsi-forms'),
                    'expected' => $required_text,
                'code' => 'verification_failed'
            ));
            return;
        }
    }
    
    $current_time = current_time('mysql');
    $ip_address = eipsi_get_client_ip();
    $user_agent = sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? '');
    
    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/privacy-config.php';
    $capture_config=get_global_privacy_defaults();
    if (empty($capture_config['ip_address'])) { $ip_address=null; }
    if (empty($capture_config['user_agent_full'])) { $user_agent=null; }

    // Update participant record
    $participants_table = $wpdb->prefix . 'survey_participants';
    error_log("[EIPSI-ABANDON] Table: {$participants_table}");
    
    // Check if table exists
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$participants_table}'") === $participants_table;
    if (!$table_exists) {
        error_log("[EIPSI-ABANDON] ERROR: Table {$participants_table} does not exist");
        wp_send_json_error(array('message' => __('Database table not found', 'eipsi-forms')));
        return;
    }
    
    // Prepare update data
    $update_data = array(
        'consent_decision' => 'withdrawn',
        'consent_decided_at' => $current_time,
        'consent_ip_address' => $ip_address,
        'consent_user_agent' => $user_agent,
        'consent_context' => ($withdrawal_type === 'b2') ? 'T2B_data_deletion' : 'T2A_withdrawal',
        'is_active' => 0,  // Mark as inactive so they don't appear as "Active" in dashboard
    );
    
    $where = array(
        'survey_id' => $study_id,
        'id' => (int) $participant_id,
    );
    
    error_log("[EIPSI-ABANDON] UPDATE data: " . json_encode($update_data));
    error_log("[EIPSI-ABANDON] WHERE: " . json_encode($where));
    
    $result = $wpdb->update($participants_table, $update_data, $where);
    
    if ($result === false) {
        error_log("[EIPSI-ABANDON] ERROR: Update failed - " . $wpdb->last_error);
        wp_send_json_error(array(
            'message' => __('Failed to update participant record', 'eipsi-forms'),
            'error' => $wpdb->last_error
        ));
        return;
    }
    
    error_log("[EIPSI-ABANDON] UPDATE successful, rows affected: " . ($result === 0 ? '0 (already withdrawn?)' : $result));
    
    // Mark all pending waves as withdrawn
    $waves_table = $wpdb->prefix . 'survey_waves';
    $assignments_table = $wpdb->prefix . 'survey_assignments';
    
    // Check if waves table exists
    $waves_table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$waves_table}'") === $waves_table;
    error_log("[EIPSI-ABANDON] Waves table exists: " . ($waves_table_exists ? 'yes' : 'no'));
    
    if ($waves_table_exists) {
        // Get current wave if any
        $current_wave = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$waves_table} WHERE study_id = %s AND status = 'active' ORDER BY wave_index ASC LIMIT 1",
            $study_id
        ));
        error_log("[EIPSI-ABANDON] Current wave: " . ($current_wave ? $current_wave->id : 'none'));
    } else {
        $current_wave = null;
    }
    
    // Note: withdrawal_wave_id column doesn't exist, skipping update
    
    // B2 uses the real local relationships and reports any critical failure.
    $cleanup_coverage = null;
    if ($withdrawal_type === 'b2') {
        require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-participant-data-cleanup.php';
        $cleanup = EIPSI_Participant_Data_Cleanup::run($participant_id, 'b2');
        if (!$cleanup['success']) {
            EIPSI_Auth_Service::destroy_session();
            wp_send_json_error(array('message'=>__('El retiro fue registrado; la eliminación de datos no se completó.', 'eipsi-forms'),
                'errors'=>$cleanup['errors'], 'coverage'=>$cleanup['coverage']), 500);
            return;
        }
        $cleanup_coverage = $cleanup['coverage'];
        $ip_address = null; // Do not reintroduce participant PII after B2 cleanup.
    }

    // Destroy session and logout participant (B1 and B2)
    if (class_exists('EIPSI_Auth_Service')) {
        // Log logout before destroying session
        if (function_exists('EIPSI_Participant_Access_Log_Service::log')) {
            EIPSI_Participant_Access_Log_Service::log($participant_id, $study_id, 'logout', array(
                'reason' => 'study_withdrawal',
                'withdrawal_type' => $withdrawal_type
            ));
        }
        EIPSI_Auth_Service::destroy_session();
        error_log("[EIPSI-ABANDON] Session destroyed for participant {$participant_id}");
    }
    
    // Log the abandonment
    if (function_exists('eipsi_log_audit')) {
        eipsi_log_audit('study_abandonment', array(
            'participant_id' => $participant_id,
            'study_id' => $study_id,
            'withdrawal_type' => $withdrawal_type,
            'data_deleted' => ($withdrawal_type === 'b2'),
            'coverage' => $cleanup_coverage,
            'ip_address' => $ip_address,
        ));
    }
    
    // Get study URL for redirect
    $study_url = '';

    if (function_exists('eipsi_get_study_page_url')) {
        $study_url = eipsi_get_study_page_url($study_id);
    }

    if (empty($study_url)) {
        $study_config = $wpdb->get_var($wpdb->prepare(
            "SELECT config FROM {$wpdb->prefix}survey_studies WHERE id = %d",
            $study_id
        ));
        $study_config_array = $study_config ? json_decode($study_config, true) : array();
        $study_url = $study_config_array['shortcode_page_url'] ?? '';
    }

    if (empty($study_url)) {
        $study_url = home_url('/');
    }

    $redirect_url = add_query_arg(array('withdrawal' => 'success', 'type' => $withdrawal_type), $study_url);
    error_log("[EIPSI-ABANDON] Redirect URL: {$redirect_url}");
    
    error_log("[EIPSI-ABANDON] === SUCCESS === type={$withdrawal_type}, redirect={$redirect_url}");
    
    wp_send_json_success(array(
        'message' => ($withdrawal_type === 'b2') 
            ? __('Your local data has been deleted. External data is not included.', 'eipsi-forms')
            : __('You have successfully withdrawn from the study', 'eipsi-forms'),
        'withdrawal_type' => $withdrawal_type,
        'redirect_url' => $redirect_url,
        'coverage' => $cleanup_coverage,
    ));
}

/**
 * Save & Continue: Discard partial response
 */
function eipsi_discard_partial_response_handler() {
    $form_id = isset($_POST['form_id']) ? sanitize_text_field($_POST['form_id']) : '';
    $participant_id = isset($_POST['participant_id']) ? sanitize_text_field($_POST['participant_id']) : '';
    $session_id = isset($_POST['session_id']) ? sanitize_text_field($_POST['session_id']) : '';
    
    if (empty($form_id) || empty($participant_id) || empty($session_id)) {
        wp_send_json_error(array(
            'message' => __('Missing required parameters', 'eipsi-forms')
        ));
    }
    
    $success = EIPSI_Partial_Responses::discard($form_id, $participant_id, $session_id);
    
    if ($success) {
        wp_send_json_success(array(
            'message' => __('Partial response discarded', 'eipsi-forms')
        ));
    } else {
        wp_send_json_error(array(
            'message' => __('Failed to discard partial response', 'eipsi-forms')
        ));
    }
}

/**
 * Submissions: Sync form list with database
 */
function eipsi_sync_submissions_handler() {
    // Security check
    if (!current_user_can('manage_options') || !check_ajax_referer('eipsi_admin_nonce', 'nonce', false)) {
        wp_send_json_error(array(
            'message' => __('Permission denied or invalid security token.', 'eipsi-forms')
        ));
    }
    
    global $wpdb;
    
    // Query para obtener formularios únicos con respuestas
    $table_name = $wpdb->prefix . 'vas_form_results';
    $forms = array();
    
    // Instanciar clase de BD externa
    $external_db = new EIPSI_External_Database();
    
    if (!$external_db->is_enabled()) {
        // Fallback a BD local si BD externa no está habilitada
        $forms = $wpdb->get_col("SELECT DISTINCT form_id FROM $table_name WHERE form_id IS NOT NULL AND form_id != '' ORDER BY form_id");
        
        // Log para debugging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('EIPSI Sync Submissions: Found ' . count($forms) . ' unique forms in local database');
        }
        
        wp_send_json_success(array(
            'forms_found' => count($forms),
            'count' => count($forms),
            'forms' => $forms,
            'message' => __('Submissions synchronized with database.', 'eipsi-forms'),
            'source' => 'local'
        ));
        return;
    }
    
    // Conectarse a BD externa
    $mysqli = $external_db->get_connection();
    if (!$mysqli) {
        // Si conexión externa falla, fallback a BD local
        $forms = $wpdb->get_col("SELECT DISTINCT form_id FROM $table_name WHERE form_id IS NOT NULL AND form_id != '' ORDER BY form_id");
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('EIPSI Sync Submissions: Could not connect to external database, using local fallback. Found ' . count($forms) . ' forms');
        }
        
        wp_send_json_success(array(
            'forms_found' => count($forms),
            'count' => count($forms),
            'forms' => $forms,
            'message' => __('Submissions synchronized with local database (external connection unavailable).', 'eipsi-forms'),
            'source' => 'local_fallback'
        ));
        return;
    }
    
    // Ejecutar query en BD externa
    $result = $mysqli->query("SELECT DISTINCT form_id FROM `{$table_name}` WHERE form_id IS NOT NULL AND form_id != '' ORDER BY form_id");
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $forms[] = $row['form_id'];
        }
    }
    
    $mysqli->close();
    
    // Log para debugging
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('EIPSI Sync Submissions: Found ' . count($forms) . ' unique forms in external database');
    }
    
    // Retornar éxito - el frontend se encarga del refresh
    wp_send_json_success(array(
        'forms_found' => count($forms),
        'count' => count($forms),
        'forms' => $forms,
        'message' => __('Submissions synchronized with database.', 'eipsi-forms'),
        'source' => 'external'
    ));
}

add_action('wp_ajax_eipsi_sync_submissions', 'eipsi_sync_submissions_handler');

/**
 * AJAX Handler: Get site logo URL
 * Returns the logo URL from WordPress customizer
 * Guaranteed to work on any page context (Elementor, custom headers, etc.)
 */
function eipsi_get_site_logo_handler() {
    // Try to get logo from theme customizer
    $logo_url = '';

    // Method 1: WordPress 5.8+ - get_theme_mod('custom_logo')
    if (function_exists('get_theme_mod')) {
        $logo_id = get_theme_mod('custom_logo');
        if ($logo_id) {
            $logo_src = wp_get_attachment_image_src($logo_id, 'full');
            if ($logo_src) {
                $logo_url = $logo_src[0]; // Return URL
            }
        }
    }

    // Fallback: Try site_icon (browser tab icon)
    if (empty($logo_url)) {
        $site_icon_id = get_option('site_icon');
        if ($site_icon_id) {
            $site_icon_src = wp_get_attachment_image_src($site_icon_id, 'full');
            if ($site_icon_src) {
                $logo_url = $site_icon_src[0];
            }
        }
    }

    wp_send_json_success(array(
        'logo_url' => $logo_url
    ));
}

// =============================================================================
// === Handlers de Aleatorización Pública (Fase 3) ===
add_action('wp_ajax_eipsi_get_randomization_config', 'eipsi_get_randomization_config');
add_action('wp_ajax_nopriv_eipsi_get_randomization_config', 'eipsi_get_randomization_config');
add_action('wp_ajax_eipsi_check_manual_assignment', 'eipsi_check_manual_assignment');
add_action('wp_ajax_nopriv_eipsi_check_manual_assignment', 'eipsi_check_manual_assignment');
add_action('wp_ajax_eipsi_persist_assignment', 'eipsi_persist_assignment');
add_action('wp_ajax_nopriv_eipsi_persist_assignment', 'eipsi_persist_assignment');
add_action('wp_ajax_eipsi_get_randomization_pages', 'eipsi_get_randomization_pages_handler');
add_action('wp_ajax_nopriv_eipsi_get_randomization_pages', 'eipsi_get_randomization_pages_handler');

/**
 * AJAX Handler: Obtener configuración de aleatorización
 * 
 * @since 1.3.0
 */
function eipsi_get_randomization_config() {
    // Verificar nonce
    $nonce = '';
    if (isset($_POST['nonce'])) {
        $nonce = sanitize_text_field(wp_unslash($_POST['nonce']));
    } elseif (isset($_GET['nonce'])) {
        $nonce = sanitize_text_field(wp_unslash($_GET['nonce']));
    }

    if (empty($nonce) || !wp_verify_nonce($nonce, 'eipsi_admin_nonce')) {
        wp_send_json_error(array(
            'message' => __('Token de seguridad inválido', 'eipsi-forms')
        ), 403);
        return;
    }

    $form_id = isset($_POST['form_id']) ? intval($_POST['form_id']) : 0;
    if (!$form_id) {
        wp_send_json_error('Form ID requerido');
        return;
    }

    // Verificar que el formulario existe
    $form_post = get_post($form_id);
    if (!$form_post || get_post_type($form_post) !== 'eipsi_form') {
        wp_send_json_error('Formulario no encontrado');
        return;
    }

    // Obtener config de postmeta
    $random_config = get_post_meta($form_id, '_eipsi_random_config', true);
    
    if (!$random_config || !is_array($random_config)) {
        wp_send_json_error('No se encontró configuración de aleatorización');
        return;
    }

    // Preparar respuesta con formularios válidos
    $forms_list = array();
    if (!empty($random_config['forms'])) {
        foreach ($random_config['forms'] as $form_id) {
            $form_post = get_post($form_id);
            if ($form_post && get_post_type($form_post) === 'eipsi_form') {
                $forms_list[] = array(
                    'id' => intval($form_id),
                    'title' => $form_post->post_title ?: 'Formulario sin título'
                );
            }
        }
    }

    wp_send_json_success(array(
        'enabled' => !empty($random_config['enabled']),
        'forms' => $forms_list,
        'method' => $random_config['method'] ?? 'simple',
        'seed_base' => $random_config['seed_base'] ?? null,
        'has_manual_assignments' => !empty($random_config['manualAssigns'])
    ));
}

/**
 * Verificar asignación manual.
 *
 * Este nombre se usa en dos contextos:
 * 1) Modo helper (llamada interna desde PHP): si se pasa $config (attrs del bloque)
 *    y $participant_identifier, devuelve (int|null) el formId asignado manualmente.
 * 2) Modo AJAX (legacy): si se llama sin parámetros (WordPress AJAX action), responde
 *    JSON leyendo la config desde post_meta.
 *
 * @since 1.3.0
 *
 * @param array|null  $config Config de bloque/legacy.
 * @param string|null $participant_identifier Identificador (email o ip_xxx).
 * @return int|null|void
 */
function eipsi_check_manual_assignment($config = null, $participant_identifier = null) {
    // === Helper mode: usado por el shortcode público [eipsi_randomization] ===
    if (is_array($config) && !empty($participant_identifier)) {
        $manual_assignments = array();

        // Nuevo bloque standalone: manualAssignments
        if (isset($config['manualAssignments']) && is_array($config['manualAssignments'])) {
            $manual_assignments = $config['manualAssignments'];
        } elseif (isset($config['manualAssigns']) && is_array($config['manualAssigns'])) {
            // Backwards compat / naming legacy
            $manual_assignments = $config['manualAssigns'];
        }

        if (empty($manual_assignments)) {
            return null;
        }

        // Limpiar identificador (remover "ip_" prefix si existe)
        $clean_identifier = str_replace('ip_', '', strtolower(trim((string) $participant_identifier)));

        foreach ($manual_assignments as $assignment) {
            $assignment_identifier = '';

            // Standalone block: { email, formId, ... }
            if (isset($assignment['email'])) {
                $assignment_identifier = $assignment['email'];
            } elseif (isset($assignment['participant_id'])) {
                $assignment_identifier = $assignment['participant_id'];
            } elseif (isset($assignment['participantId'])) {
                $assignment_identifier = $assignment['participantId'];
            }

            $assignment_identifier = strtolower(trim((string) $assignment_identifier));

            if ($assignment_identifier === $clean_identifier) {
                $form_id = null;

                if (isset($assignment['formId'])) {
                    $form_id = $assignment['formId'];
                } elseif (isset($assignment['assigned_form_id'])) {
                    $form_id = $assignment['assigned_form_id'];
                } elseif (isset($assignment['form_id'])) {
                    $form_id = $assignment['form_id'];
                }

                return $form_id !== null ? intval($form_id) : null;
            }
        }

        return null;
    }

    // === AJAX mode (legacy) ===

    // Verificar nonce
    $nonce = '';
    if (isset($_POST['nonce'])) {
        $nonce = sanitize_text_field(wp_unslash($_POST['nonce']));
    } elseif (isset($_GET['nonce'])) {
        $nonce = sanitize_text_field(wp_unslash($_GET['nonce']));
    }

    if (empty($nonce) || !wp_verify_nonce($nonce, 'eipsi_admin_nonce')) {
        wp_send_json_error(array(
            'message' => __('Token de seguridad inválido', 'eipsi-forms')
        ), 403);
        return;
    }

    $form_id = isset($_POST['form_id']) ? intval($_POST['form_id']) : 0;
    $participant_id = isset($_POST['participant_id']) ? sanitize_text_field($_POST['participant_id']) : '';

    if (!$form_id || empty($participant_id)) {
        wp_send_json_error('Parámetros faltantes');
        return;
    }

    // Obtener asignaciones manuales desde config
    $random_config = get_post_meta($form_id, '_eipsi_random_config', true);
    $manual_assignments = $random_config['manualAssigns'] ?? array();

    // Buscar coincidencia por participant_id (puede ser email o ID)
    foreach ($manual_assignments as $assignment) {
        if (strtolower($assignment['participant_id'] ?? '') === strtolower($participant_id)) {
            wp_send_json_success(array(
                'assigned_form_id' => intval($assignment['formId']),
                'seed' => $assignment['seed'] ?? '',
                'is_manual' => true,
                'timestamp' => $assignment['timestamp'] ?? current_time('mysql')
            ));
            return;
        }
    }

    // Verificar también en metadata de asignaciones persistidas
    $persisted_assignments = get_post_meta($form_id, '_eipsi_assignments', true) ?: array();
    if (isset($persisted_assignments[$participant_id])) {
        $assignment = $persisted_assignments[$participant_id];
        wp_send_json_success(array(
            'assigned_form_id' => intval($assignment['assigned_form_id']),
            'seed' => $assignment['seed'] ?? '',
            'is_manual' => !empty($assignment['is_manual']),
            'timestamp' => $assignment['timestamp'] ?? current_time('mysql')
        ));
        return;
    }

    // No hay override manual
    wp_send_json_success(null);
}

/**
 * AJAX Handler: Persistir asignación en metadata
 * 
 * @since 1.3.0
 */
function eipsi_persist_assignment() {
    // Verificar nonce
    $nonce = '';
    if (isset($_POST['nonce'])) {
        $nonce = sanitize_text_field(wp_unslash($_POST['nonce']));
    } elseif (isset($_GET['nonce'])) {
        $nonce = sanitize_text_field(wp_unslash($_GET['nonce']));
    }

    if (empty($nonce) || !wp_verify_nonce($nonce, 'eipsi_admin_nonce')) {
        wp_send_json_error(array(
            'message' => __('Token de seguridad inválido', 'eipsi-forms')
        ), 403);
        return;
    }

    $form_id = isset($_POST['form_id']) ? intval($_POST['form_id']) : 0;
    $assigned_form_id = isset($_POST['assigned_form_id']) ? intval($_POST['assigned_form_id']) : 0;
    $participant_id = isset($_POST['participant_id']) ? sanitize_text_field($_POST['participant_id']) : '';
    $seed = isset($_POST['seed']) ? sanitize_text_field($_POST['seed']) : '';
    $is_manual = isset($_POST['is_manual']) && $_POST['is_manual'] === '1';

    if (!$form_id || !$assigned_form_id || empty($participant_id)) {
        wp_send_json_error('Parámetros faltantes');
        return;
    }

    // Verificar que los formularios existen
    if (!get_post($form_id) || get_post_type($form_id) !== 'eipsi_form') {
        wp_send_json_error('Formulario principal inválido');
        return;
    }

    if (!get_post($assigned_form_id) || get_post_type($assigned_form_id) !== 'eipsi_form') {
        wp_send_json_error('Formulario asignado inválido');
        return;
    }

    // Obtener asignaciones existentes
    $assignments = get_post_meta($form_id, '_eipsi_assignments', true);
    if (!is_array($assignments)) {
        $assignments = array();
    }

    // Actualizar o crear asignación
    $assignments[$participant_id] = array(
        'assigned_form_id' => $assigned_form_id,
        'seed' => $seed,
        'timestamp' => current_time('mysql'),
        'is_manual' => $is_manual,
        'study_id' => $form_id
    );

    // Guardar en metadata
    $result = update_post_meta($form_id, '_eipsi_assignments', $assignments);

    if ($result !== false) {
        wp_send_json_success(array(
            'message' => 'Asignación persistida correctamente',
            'participant_id' => $participant_id,
            'assigned_form_id' => $assigned_form_id
        ));
    } else {
        wp_send_json_error('Error al persistir asignación');
    }
}

// =============================================================================
// END RANDOMIZATION SYSTEM HANDLERS
// =============================================================================

/**
 * AJAX Handler: Obtener páginas que contienen el shortcode de aleatorización
 * 
 * @since 1.3.0
 */
function eipsi_get_randomization_pages_handler() {
    // Verificar nonce
    $nonce = '';
    if (isset($_POST['nonce'])) {
        $nonce = sanitize_text_field(wp_unslash($_POST['nonce']));
    } elseif (isset($_GET['nonce'])) {
        $nonce = sanitize_text_field(wp_unslash($_GET['nonce']));
    }

    if (empty($nonce) || !wp_verify_nonce($nonce, 'eipsi_admin_nonce')) {
        wp_send_json_error(array(
            'message' => __('Token de seguridad inválido', 'eipsi-forms')
        ), 403);
        return;
    }

    // Buscar páginas que contengan el shortcode [eipsi_randomized_form]
    $pages = get_posts(array(
        'post_type' => 'page',
        'post_status' => array('publish', 'private'),
        'posts_per_page' => -1,
        'meta_query' => array(
            array(
                'key' => '_wp_page_template',
                'value' => 'default',
                'compare' => '!='
            )
        )
    ));

    $matching_pages = array();

    foreach ($pages as $page) {
        // Verificar si el contenido de la página contiene el shortcode
        if (has_shortcode($page->post_content, 'eipsi_randomized_form')) {
            $matching_pages[] = array(
                'id' => intval($page->ID),
                'title' => $page->post_title ?: 'Página sin título',
                'link' => get_permalink($page->ID)
            );
        }
    }

    // Si no hay páginas con shortcode, incluir páginas públicas generales como fallback
    if (empty($matching_pages)) {
        $public_pages = get_posts(array(
            'post_type' => 'page',
            'post_status' => 'publish',
            'posts_per_page' => 5,
            'orderby' => 'title',
            'order' => 'ASC'
        ));

        foreach ($public_pages as $page) {
            $matching_pages[] = array(
                'id' => intval($page->ID),
                'title' => $page->post_title ?: 'Página sin título',
                'link' => get_permalink($page->ID),
                'note' => 'Nota: Esta página no contiene el shortcode [eipsi_randomized_form]'
            );
        }
    }

    wp_send_json_success($matching_pages);
}

// =================================================================
// PARTICIPANT AUTHENTICATION ENDPOINTS (v1.4.0+)
// =================================================================

/**
 * Get error message based on error code
 * 
 * @param string $error_code
 * @return string
 */
function eipsi_get_error_message($error_code) {
    $messages = array(
        'invalid_email' => __('Email inválido.', 'eipsi-forms'),
        'short_password' => __('La contraseña debe tener al menos 8 caracteres.', 'eipsi-forms'),
        'email_exists' => __('Este email ya está registrado en este estudio.', 'eipsi-forms'),
        'db_error' => __('Error de base de datos. Intenta nuevamente.', 'eipsi-forms'),
        'user_not_found' => __('Usuario no encontrado.', 'eipsi-forms'),
        'user_inactive' => __('Usuario inactivo. Contacta al administrador.', 'eipsi-forms'),
        'invalid_credentials' => __('Email o contraseña incorrectos.', 'eipsi-forms'),
        'missing_fields' => __('Campos requeridos faltantes.', 'eipsi-forms'),
        'rate_limited' => __('Demasiados intentos fallidos. Intenta en 15 minutos.', 'eipsi-forms'),
        'not_authenticated' => __('No estás autenticado.', 'eipsi-forms'),
        'participant_not_found' => __('Participante no encontrado.', 'eipsi-forms')
    );
    
    return isset($messages[$error_code]) ? $messages[$error_code] : __('Error desconocido.', 'eipsi-forms');
}

/**
 * Rate limit check: máximo 5 intentos fallidos en 15 minutos
 * 
 * @param string $email
 * @param int $survey_id
 * @return bool
 */
function eipsi_check_login_rate_limit($email, $survey_id) {
    $key = 'eipsi_login_attempts_' . md5($email . $survey_id);
    $attempts = get_transient($key);
    
    return !($attempts && $attempts >= 5);
}

/**
 * Record failed login attempt
 * 
 * @param string $email
 * @param int $survey_id
 */
function eipsi_record_failed_login($email, $survey_id) {
    $key = 'eipsi_login_attempts_' . md5($email . $survey_id);
    $attempts = (int) get_transient($key);
    set_transient($key, $attempts + 1, 15 * MINUTE_IN_SECONDS);
}

/**
 * Clear login rate limit on successful login
 * 
 * @param string $email
 * @param int $survey_id
 */
function eipsi_clear_login_rate_limit($email, $survey_id) {
    $key = 'eipsi_login_attempts_' . md5($email . $survey_id);
    delete_transient($key);
}


// ============================================================================
// NOTE: Participant authentication handlers moved to ajax-participant-handlers.php (v1.5.5)
// The following handlers are now in ajax-participant-handlers.php:
// - eipsi_participant_register_handler()
// - eipsi_participant_login_handler()
// - eipsi_participant_logout_handler()
// - eipsi_participant_info_handler()
//
// Rate limiting helper functions remain here below.
// ============================================================================

/**
 * AJAX Handler: Close randomization session (persistent_mode=OFF)
 * 
 * Elimina la asignación del usuario y borra la cookie de rotación.
 * Esto permite que en el próximo F5/reload se asigne un nuevo formulario (rotación cíclica).
 * 
 * @since 1.3.20
 */
function eipsi_close_randomization_session_handler() {
    // Validar nonce (aceptar POST)
    $nonce = '';
    if ( isset( $_POST['nonce'] ) ) {
        $nonce = sanitize_text_field( wp_unslash( $_POST['nonce'] ) );
    }

    // Validar parámetros requeridos
    $randomization_id = isset( $_POST['randomization_id'] ) ? sanitize_text_field( wp_unslash( $_POST['randomization_id'] ) ) : '';
    $user_fingerprint = isset( $_POST['user_fingerprint'] ) ? sanitize_text_field( wp_unslash( $_POST['user_fingerprint'] ) ) : '';

    if ( empty( $randomization_id ) || empty( $user_fingerprint ) ) {
        wp_send_json_error( array(
            'message' => __( 'Parámetros incompletos (randomization_id o user_fingerprint faltantes)', 'eipsi-forms' )
        ), 400 );
        return;
    }

    // Llamar a la función de cierre de sesión (requiere randomization-shortcode-handler.php)
    if ( ! function_exists( 'eipsi_close_randomization_session' ) ) {
        wp_send_json_error( array(
            'message' => __( 'Función eipsi_close_randomization_session no disponible', 'eipsi-forms' )
        ), 500 );
        return;
    }

    $success = eipsi_close_randomization_session( $randomization_id, $user_fingerprint );

    if ( $success ) {
        wp_send_json_success( array(
            'message' => __( 'Sesión de aleatorización cerrada correctamente', 'eipsi-forms' )
        ) );
    } else {
        wp_send_json_error( array(
            'message' => __( 'Error al cerrar sesión de aleatorización', 'eipsi-forms' )
        ), 500 );
    }
}

/**
 * AJAX Handler: Save Form Authentication Config
 * 
 * Saves the _eipsi_require_login post meta for form templates
 * 
 * @since 1.4.0
 */
add_action('wp_ajax_eipsi_save_form_auth_config', 'eipsi_ajax_save_form_auth_config');

function eipsi_ajax_save_form_auth_config() {
    check_ajax_referer('eipsi_admin_nonce', 'nonce');
    
    if (!current_user_can('edit_posts')) {
        wp_send_json_error('Insufficient permissions');
    }
    
    $template_id = absint($_POST['template_id'] ?? 0);
    $require_login = (bool) ($_POST['require_login'] ?? false);
    
    if (!$template_id) {
        wp_send_json_error('Template ID missing');
    }
    
    update_post_meta($template_id, '_eipsi_require_login', $require_login ? 1 : 0);
    
    wp_send_json_success(array(
        'message' => 'Configuration saved',
        'require_login' => $require_login
    ));
}

/**
 * EIPSI Setup Wizard AJAX Handlers
 * 
 * Handles AJAX requests for the setup wizard functionality.
 *
 * @since 1.5.1
 */

// Note: Wizard AJAX handlers are defined in ajax-handlers-wizard.php
// to avoid duplication and ensure single source of truth

// DASHBOARD-EIPSI-MARKER


/**
 * AJAX Handler: Anonimizar survey
 * 
 * POST action=eipsi_anonymize_survey
 * Parámetros:
 *   - survey_id: ID del survey
 *   - nonce: wp_nonce_field value
 *   - close_reason: razón de cierre
 *   - close_notes: notas (opcional)
 */
add_action('wp_ajax_eipsi_anonymize_survey', function() {
    // 1. Validar nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'eipsi_anonymize_survey_nonce')) {
        wp_send_json_error(array('message' => 'Nonce inválido'), 403);
    }
    
    // 2. Validar permisos
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'No tienes permisos para anonimizar'), 403);
    }
    
    // 3. Obtener survey_id
    $survey_id = intval($_POST['survey_id'] ?? 0);
    if ($survey_id <= 0) {
        wp_send_json_error(array('message' => 'Survey ID inválido'));
    }
    
    // 4. Verificar que survey existe
    $survey = get_post($survey_id);
    if (!$survey || $survey->post_type !== 'eipsi_form') {
        wp_send_json_error(array('message' => 'Survey no encontrado'));
    }
    
    // 5. Verificar que se puede anonimizar
    if (!class_exists('EIPSI_Anonymize_Service')) {
        wp_send_json_error(array('message' => 'EIPSI_Anonymize_Service no disponible'));
    }
    
    $can_anon = EIPSI_Anonymize_Service::can_anonymize_survey($survey_id);
    if (!$can_anon['can_anonymize']) {
        wp_send_json_error(array('message' => $can_anon['reason']));
    }
    
    // 6. Construir audit_reason
    $close_reason = sanitize_text_field($_POST['close_reason'] ?? '');
    $close_notes = sanitize_textarea_field($_POST['close_notes'] ?? '');
    
    $audit_reason = $close_reason;
    if ($close_notes) {
        $audit_reason .= ' | ' . $close_notes;
    }
    
    // 7. Ejecutar anonimización
    $result = EIPSI_Anonymize_Service::anonymize_survey($survey_id, $audit_reason);
    
    if (!$result['success']) {
        wp_send_json_error(array('message' => $result['error'] ?? 'Error desconocido al anonimizar'));
    }
    
    // 8. Retornar success
    wp_send_json_success(array(
        'message' => 'Estudio anonimizado exitosamente',
        'anonymized_count' => intval($result['anonymized_count']),
        'survey_title' => sanitize_text_field($survey->post_title),
    ));
});



/**
 * ========================================
 * WAVE MANAGER - ADD PARTICIPANT HANDLERS
 * ========================================
 * @since 1.4.5
 */

// AJAX: Add participant with Magic Link (individual)
add_action('wp_ajax_eipsi_add_participant_magic_link', 'eipsi_add_participant_magic_link_handler');

function eipsi_add_participant_magic_link_handler() {
    // Accept multiple nonce types for compatibility
    $nonce_valid = false;
    if (isset($_POST['nonce'])) {
        $nonce_valid = wp_verify_nonce($_POST['nonce'], 'eipsi_waves_nonce') ||
                       wp_verify_nonce($_POST['nonce'], 'eipsi_anonymize_survey_nonce') ||
                       wp_verify_nonce($_POST['nonce'], 'eipsi_admin_nonce');
    }
    
    if (!$nonce_valid) {
        wp_send_json_error(array('message' => 'Nonce inválido'));
    }
    
    if (!eipsi_user_can_manage_longitudinal()) {
        wp_send_json_error(array('message' => 'No tienes permisos para realizar esta acción'));
    }
    
    $study_id = isset($_POST['study_id']) ? absint($_POST['study_id']) : 0;
    $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
    $first_name = isset($_POST['first_name']) ? sanitize_text_field($_POST['first_name']) : '';
    $last_name = isset($_POST['last_name']) ? sanitize_text_field($_POST['last_name']) : '';
    
    if (!$study_id || !$email) {
        wp_send_json_error(array('message' => 'Datos incompletos'));
    }
    
    // Validar email
    if (!is_email($email)) {
        wp_send_json_error(array('message' => 'Email inválido'));
    }
    
    // Cargar servicios
    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-participant-service.php';
    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-email-service.php';
    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-device-data-service.php';
    
    // Generar password automático seguro
    $password = wp_generate_password(16, true, true);
    
    // Crear participante
    $result = EIPSI_Participant_Service::create_participant($study_id, $email, $password, array(
        'first_name' => $first_name,
        'last_name' => $last_name
    ));
    
    if (!$result['success']) {
        $error_messages = array(
            'invalid_email' => 'El email es inválido',
            'email_exists' => 'Este email ya está registrado en el estudio',
            'short_password' => 'Error al generar contraseña',
            'db_error' => 'Error al guardar el participante'
        );
        
        $message = isset($error_messages[$result['error']]) ? $error_messages[$result['error']] : 'Error desconocido';
        wp_send_json_error(array('message' => $message));
    }
    
    // Enviar welcome email con Magic Link
    $email_sent = EIPSI_Email_Service::send_welcome_email($study_id, $result['participant_id']);
    
    wp_send_json_success(array(
        'message' => 'Participante agregado exitosamente',
        'participant_id' => $result['participant_id'],
        'email_sent' => $email_sent
    ));
}

// AJAX: Bulk add participants from CSV or manual list
add_action('wp_ajax_eipsi_add_participants_bulk', 'eipsi_add_participants_bulk_handler');

function eipsi_add_participants_bulk_handler() {
    // Accept multiple nonce types for compatibility
    $nonce_valid = false;
    if (isset($_POST['nonce'])) {
        $nonce_valid = wp_verify_nonce($_POST['nonce'], 'eipsi_waves_nonce') ||
                       wp_verify_nonce($_POST['nonce'], 'eipsi_anonymize_survey_nonce') ||
                       wp_verify_nonce($_POST['nonce'], 'eipsi_admin_nonce');
    }
    
    if (!$nonce_valid) {
        wp_send_json_error(array('message' => 'Nonce inválido'));
    }
    
    if (!eipsi_user_can_manage_longitudinal()) {
        wp_send_json_error(array('message' => 'No tienes permisos para realizar esta acción'));
    }
    
    $study_id = isset($_POST['study_id']) ? absint($_POST['study_id']) : 0;
    $emails_raw = isset($_POST['emails']) ? $_POST['emails'] : '';
    
    if (!$study_id || !$emails_raw) {
        wp_send_json_error(array('message' => 'Datos incompletos'));
    }
    
    // Parse emails (comma or newline separated)
    $emails_array = preg_split('/[\r\n,;]+/', $emails_raw);
    $emails_array = array_map('trim', $emails_array);
    $emails_array = array_filter($emails_array); // Remove empty
    $emails_array = array_unique($emails_array); // Remove duplicates
    
    if (empty($emails_array)) {
        wp_send_json_error(array('message' => 'No se encontraron emails válidos'));
    }
    
    // Cargar servicios
    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-participant-service.php';
    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-email-service.php';
    
    $success_count = 0;
    $failed_count = 0;
    $errors = array();
    
    foreach ($emails_array as $email) {
        $email = sanitize_email($email);
        
        if (!is_email($email)) {
            $errors[] = "$email - Email inválido";
            $failed_count++;
            continue;
        }
        
        // Generar password automático
        $password = wp_generate_password(16, true, true);
        
        // Crear participante
        $result = EIPSI_Participant_Service::create_participant($study_id, $email, $password, array(
            'first_name' => '',
            'last_name' => ''
        ));
        
        if ($result['success']) {
            // Enviar welcome email con Magic Link
            EIPSI_Email_Service::send_welcome_email($study_id, $result['participant_id']);
            $success_count++;
        } else {
            if ($result['error'] === 'email_exists') {
                $errors[] = "$email - Ya registrado";
            } else {
                $errors[] = "$email - Error al crear";
            }
            $failed_count++;
        }
    }
    
    wp_send_json_success(array(
        'message' => "Proceso completado: $success_count agregados, $failed_count fallaron",
        'success_count' => $success_count,
        'failed_count' => $failed_count,
        'errors' => $errors
    ));
}

// AJAX: Get public registration link for study
add_action('wp_ajax_eipsi_get_public_registration_link', 'eipsi_get_public_registration_link_handler');

function eipsi_get_public_registration_link_handler() {
    check_ajax_referer('eipsi_admin_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Unauthorized'));
    }
    
    $study_id = isset($_POST['study_id']) ? absint($_POST['study_id']) : 0;
    
    if (!$study_id) {
        wp_send_json_error(array('message' => 'Study ID requerido'));
    }
    
    global $wpdb;
    $study = $wpdb->get_row($wpdb->prepare(
        "SELECT study_code FROM {$wpdb->prefix}survey_studies WHERE id = %d",
        $study_id
    ));
    
    if (!$study) {
        wp_send_json_error(array('message' => 'Estudio no encontrado'));
    }
    
    // Generate public registration URL
    // Format: site_url/?eipsi_register=STUDY_CODE
    $registration_url = add_query_arg('eipsi_register', $study->study_code, site_url('/'));
    
    wp_send_json_success(array(
        'registration_url' => $registration_url,
        'study_code' => $study->study_code
    ));
}


// =============================================================================
// SCHEMA STATUS AJAX HANDLERS (v1.6.0+)
// =============================================================================

/**
 * AJAX Handler: Get schema status
 * 
 * Returns complete schema status for all monitored tables
 * 
 * @since 1.6.0
 */
add_action('wp_ajax_eipsi_get_schema_status', 'eipsi_get_schema_status_handler');

function eipsi_get_schema_status_handler() {
    // Validate nonce
    $nonce_valid = false;
    if (isset($_POST['nonce'])) {
        $nonce_valid = wp_verify_nonce($_POST['nonce'], 'eipsi_admin_nonce') ||
                       wp_verify_nonce($_POST['nonce'], 'eipsi_wizard_nonce');
    }
    
    if (!$nonce_valid) {
        wp_send_json_error(array('message' => 'Nonce inválido'), 403);
    }
    
    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'No tienes permisos para realizar esta acción'), 403);
    }
    
    // Load database schema manager if not already loaded
    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/database-schema-manager.php';
    
    // Get all tables status
    $tables = EIPSI_Database_Schema_Manager::get_all_tables_status();
    
    // Get health summary
    $summary = EIPSI_Database_Schema_Manager::get_schema_health_summary();
    
    // Update last verified timestamp
    update_option('eipsi_schema_last_verified', current_time('mysql'));
    
    wp_send_json_success(array(
        'tables' => $tables,
        'summary' => $summary,
        'timestamp' => current_time('mysql')
    ));
}

/**
 * AJAX Handler: Repair single table
 * 
 * Repairs a specific table (creates if missing, adds columns if missing)
 * 
 * @since 1.6.0
 */
add_action('wp_ajax_eipsi_repair_single_table', 'eipsi_repair_single_table_handler');

function eipsi_repair_single_table_handler() {
    // Validate nonce
    $nonce_valid = false;
    if (isset($_POST['nonce'])) {
        $nonce_valid = wp_verify_nonce($_POST['nonce'], 'eipsi_admin_nonce') ||
                       wp_verify_nonce($_POST['nonce'], 'eipsi_wizard_nonce');
    }
    
    if (!$nonce_valid) {
        wp_send_json_error(array('message' => 'Nonce inválido'), 403);
    }
    
    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'No tienes permisos para realizar esta acción'), 403);
    }
    
    // Get table name
    $table_name = isset($_POST['table_name']) ? sanitize_text_field(wp_unslash($_POST['table_name'])) : '';
    
    // Validate table name - only allow known tables
    $allowed_tables = array(
        'vas_form_results',
        'vas_form_events',
        'eipsi_randomization_configs',
        'eipsi_randomization_assignments',
        'survey_studies',
        'survey_participants',
        'survey_sessions',
        'survey_waves',
        'survey_assignments',
        'survey_magic_links',
        'survey_email_log',
        'survey_audit_log',
        'eipsi_longitudinal_pools',
        'eipsi_pool_assignments',
        'survey_participant_access_log',
        'eipsi_device_data'
    );
    
    if (!in_array($table_name, $allowed_tables, true)) {
        wp_send_json_error(array('message' => 'Tabla no permitida: ' . $table_name), 400);
    }
    
    // Load database schema manager if not already loaded
    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/database-schema-manager.php';
    
    // Repair the table
    $result = EIPSI_Database_Schema_Manager::repair_single_table($table_name);
    
    // Log the repair action
    error_log("[EIPSI Schema Repair] User repair request for table {$table_name}: " . json_encode($result));
    
    if ($result['success']) {
        wp_send_json_success($result);
    } else {
        wp_send_json_error($result['error'] ?? 'Error desconocido al reparar la tabla');
    }
}

/**
 * AJAX Handler: Export schema report
 * 
 * Generates a downloadable JSON report of the schema status
 * 
 * @since 1.6.0
 */
add_action('wp_ajax_eipsi_export_schema_report', 'eipsi_export_schema_report_handler');

function eipsi_export_schema_report_handler() {
    // Validate nonce
    $nonce_valid = false;
    if (isset($_POST['nonce'])) {
        $nonce_valid = wp_verify_nonce($_POST['nonce'], 'eipsi_admin_nonce') ||
                       wp_verify_nonce($_POST['nonce'], 'eipsi_wizard_nonce');
    }
    
    if (!$nonce_valid) {
        wp_send_json_error(array('message' => 'Nonce inválido'), 403);
    }
    
    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'No tienes permisos para realizar esta acción'), 403);
    }
    
    // Load database schema manager if not already loaded
    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/database-schema-manager.php';
    
    // Get all tables status
    $tables = EIPSI_Database_Schema_Manager::get_all_tables_status();
    
    // Get health summary
    $summary = EIPSI_Database_Schema_Manager::get_schema_health_summary();
    
    // Build report
    $report = array(
        'generated_at' => current_time('mysql'),
        'plugin_version' => defined('EIPSI_FORMS_VERSION') ? EIPSI_FORMS_VERSION : 'Unknown',
        'wordpress_version' => get_bloginfo('version'),
        'php_version' => PHP_VERSION,
        'summary' => $summary,
        'tables' => $tables,
        'database' => array(
            'name' => DB_NAME,
            'host' => DB_HOST,
            'prefix' => $wpdb->prefix ?? 'wp_'
        )
    );
    
    wp_send_json_success($report);
}

/**
 * AJAX Handler: Save granular nudge configuration for a wave
 * Allows per-nudge customization with minutes/hours/days units
 * Supports dual modes: wave_availability (after) or due_date (before)
 * 
 * @since 2.3.0
 * @return void
 */
function eipsi_save_wave_nudge_config_handler() {
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'eipsi_study_dashboard_nonce')) {
        check_ajax_referer('eipsi_admin_nonce', 'nonce');
    }
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('Permisos insuficientes', 'eipsi-forms')));
    }
    
    $wave_id = isset($_POST['wave_id']) ? intval($_POST['wave_id']) : 0;
    $nudge_config = isset($_POST['nudge_config']) ? $_POST['nudge_config'] : array();
    
    if ($wave_id <= 0) {
        wp_send_json_error(array('message' => __('ID de wave inválido', 'eipsi-forms')));
    }
    
    // Accept the actual dashboard nonce as well as the existing admin consumer.
    require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/services/class-nudge-service.php';
    $result = EIPSI_Nudge_Service::save_wave_configuration($wave_id, $nudge_config);
    if (is_wp_error($result)) { wp_send_json_error(array('message' => $result->get_error_message())); }
    wp_send_json_success(array_merge(array('message' => __('Configuración de recordatorios guardada', 'eipsi-forms'), 'auto_activated' => false), $result));
}
add_action('wp_ajax_eipsi_save_wave_nudge_config', 'eipsi_save_wave_nudge_config_handler');

/**
 * AJAX Handler: Fix collations
 * 
 * Fixes collations for all plugin tables to ensure utf8mb4_unicode_ci
 * 
 * @since 2.0.0
 */
add_action('wp_ajax_eipsi_fix_collations', 'eipsi_fix_collations_handler');

function eipsi_fix_collations_handler() {
    check_ajax_referer('eipsi_admin_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error();
    
    // Load database schema manager if not already loaded
    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/database-schema-manager.php';
    
    $result = EIPSI_Database_Schema_Manager::fix_collations();
    wp_send_json_success($result);
}

/**
 * AJAX Handler: Check if collations need fixing
 * Returns status of collation issues without fixing them
 * 
 * @since 1.6.1
 */
add_action('wp_ajax_eipsi_check_collation_issues', 'eipsi_check_collation_issues_handler');

function eipsi_check_collation_issues_handler() {
    check_ajax_referer('eipsi_admin_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error();
    
    // Load database schema manager if not already loaded
    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/database-schema-manager.php';
    
    $result = EIPSI_Database_Schema_Manager::check_collation_issues();
    wp_send_json_success($result);
}

/**
 * AJAX Handler: Execute maintenance SQL
 * Allows running safe maintenance queries on plugin tables
 * 
 * @since 1.6.1
 */
add_action('wp_ajax_eipsi_execute_maintenance_sql', 'eipsi_execute_maintenance_sql_handler');

function eipsi_execute_maintenance_sql_handler() {
    check_ajax_referer('eipsi_admin_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(array('message' => 'No tienes permisos'));
    
    $sql_statements = isset($_POST['sql_statements']) ? $_POST['sql_statements'] : array();
    
    if (empty($sql_statements) || !is_array($sql_statements)) {
        wp_send_json_error(array('message' => 'No se proporcionaron sentencias SQL'));
    }
    
    // Sanitize SQL statements
    $sanitized_statements = array_map('sanitize_textarea_field', $sql_statements);
    
    // Load database schema manager if not already loaded
    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/database-schema-manager.php';
    
    $result = EIPSI_Database_Schema_Manager::execute_maintenance_sql($sanitized_statements);
    wp_send_json_success($result);
}

/**
 * AJAX Handler: Check for schema issues that need auto-fix
 * Detects common data inconsistencies
 * 
 * @since 1.6.1
 */
add_action('wp_ajax_eipsi_check_schema_issues', 'eipsi_check_schema_issues_handler');

function eipsi_check_schema_issues_handler() {
    check_ajax_referer('eipsi_admin_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error();
    
    global $wpdb;
    
    $issues = array();
    $needs_fix = false;
    
    // Check 1: Waves with invalid time_unit (NULL, empty) 
    // Note: '0', '1', '2' are valid numeric indices for minutes/hours/days
    $invalid_time_unit = $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->prefix}survey_waves 
         WHERE time_unit IS NULL OR time_unit = ''"
    );
    if ($invalid_time_unit > 0) {
        $issues[] = array(
            'type' => 'invalid_time_unit',
            'description' => 'Waves con time_unit NULL o vacío',
            'count' => intval($invalid_time_unit)
        );
        $needs_fix = true;
    }
    
    // Check 1b: Waves with numeric time_unit that should be converted to string
    $numeric_time_unit = $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->prefix}survey_waves 
         WHERE time_unit IN ('0', '1', '2')"
    );
    if ($numeric_time_unit > 0) {
        $issues[] = array(
            'type' => 'numeric_time_unit',
            'description' => 'Waves con time_unit numérico (0/1/2) - será convertido a minutes/hours/days',
            'count' => intval($numeric_time_unit)
        );
        $needs_fix = true;
    }
    
    // Check 2: Participants without proper assignments
    $orphaned_participants = $wpdb->get_var(
        "SELECT COUNT(DISTINCT p.id) FROM {$wpdb->prefix}survey_participants p
         LEFT JOIN {$wpdb->prefix}survey_assignments a ON p.id = a.participant_id
         WHERE a.id IS NULL"
    );
    if ($orphaned_participants > 0) {
        $issues[] = array(
            'type' => 'orphaned_participants',
            'description' => 'Participantes sin assignments',
            'count' => intval($orphaned_participants)
        );
        $needs_fix = true;
    }
    
    wp_send_json_success(array(
        'needs_fix' => $needs_fix,
        'issues' => $issues,
        'total_issues' => count($issues)
    ));
}

/**
 * AJAX Handler: Auto-fix schema issues
 * Automatically fixes common data inconsistencies
 * 
 * @since 1.6.1
 */
add_action('wp_ajax_eipsi_auto_fix_schema_issues', 'eipsi_auto_fix_schema_issues_handler');

function eipsi_auto_fix_schema_issues_handler() {
    check_ajax_referer('eipsi_admin_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(array('message' => 'No tienes permisos'));
    
    global $wpdb;
    
    $fixes = array();
    $wpdb->suppress_errors(true);
    
    // Fix 1a: Set default time_unit = 'days' for NULL/empty values
    $result = $wpdb->query(
        "UPDATE {$wpdb->prefix}survey_waves 
         SET time_unit = 'days' 
         WHERE time_unit IS NULL OR time_unit = ''"
    );
    if ($result !== false && $wpdb->rows_affected > 0) {
        $fixes[] = array(
            'type' => 'time_unit_default',
            'description' => 'Waves corregidas con time_unit = days (valores NULL/vacíos)',
            'affected_rows' => $wpdb->rows_affected
        );
        error_log("[EIPSI Auto-Fix] Set time_unit='days' for {$wpdb->rows_affected} waves (NULL/empty)");
    }
    
    // Fix 1b: Convert numeric time_unit to string values
    // '0' -> 'minutes', '1' -> 'hours', '2' -> 'days'
    $numeric_map = array(
        '0' => 'minutes',
        '1' => 'hours',
        '2' => 'days'
    );
    
    $converted_total = 0;
    foreach ($numeric_map as $numeric => $string_value) {
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}survey_waves 
             SET time_unit = %s 
             WHERE time_unit = %s",
            $string_value,
            $numeric
        ));
        if ($result !== false && $wpdb->rows_affected > 0) {
            $converted_total += $wpdb->rows_affected;
            error_log("[EIPSI Auto-Fix] Converted time_unit '{$numeric}' to '{$string_value}' for {$wpdb->rows_affected} waves");
        }
    }
    
    if ($converted_total > 0) {
        $fixes[] = array(
            'type' => 'time_unit_numeric_converted',
            'description' => 'Waves con time_unit numérico convertido a string',
            'affected_rows' => $converted_total
        );
    }
    
    // Fix 2: Create missing assignments for participants in active studies
    // This is more complex - get participants without assignments and create them
    $orphaned = $wpdb->get_results(
        "SELECT DISTINCT p.id as participant_id, p.survey_id as study_id
         FROM {$wpdb->prefix}survey_participants p
         LEFT JOIN {$wpdb->prefix}survey_assignments a ON p.id = a.participant_id AND p.survey_id = a.study_id
         WHERE a.id IS NULL AND p.status != 'inactive'"
    );
    
    if (!empty($orphaned)) {
        require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-assignment-service.php';
        $created_count = 0;
        
        foreach ($orphaned as $orphan) {
            if (class_exists('EIPSI_Assignment_Service')) {
                $result = EIPSI_Assignment_Service::create_assignments_for_participant(
                    $orphan->participant_id, 
                    $orphan->study_id
                );
                if ($result['success'] && $result['created'] > 0) {
                    $created_count += $result['created'];
                }
            }
        }
        
        if ($created_count > 0) {
            $fixes[] = array(
                'type' => 'missing_assignments',
                'description' => 'Assignments creados para participantes',
                'affected_rows' => $created_count
            );
            error_log("[EIPSI Auto-Fix] Created {$created_count} missing assignments");
        }
    }
    
    $wpdb->suppress_errors(false);
    
    wp_send_json_success(array(
        'success' => true,
        'fixes' => $fixes,
        'total_fixes' => count($fixes)
    ));
}

// =============================================================================
// POOL STUDIES COMPLETION CHECK (v2.5.3)
// =============================================================================

/**
 * Check if all waves in a study are completed and mark pool assignment as completed
 *
 * @param int $participant_id The longitudinal participant ID
 * @param int $study_id The study ID
 * @param string $form_id The form ID that was just submitted
 * @return bool True if pool assignment was marked as completed
 */
function eipsi_check_and_mark_pool_completion($participant_id, $study_id, $form_id) {
    global $wpdb;

    error_log(sprintf('[EIPSI-POOL-COMPLETION] Checking completion: participant_id=%d, study_id=%d, form_id=%s',
        $participant_id, $study_id, $form_id));

    // Get the participant's email to identify them in pool assignments
    $participants_table = $wpdb->prefix . 'survey_participants';
    $participant = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT email FROM {$participants_table} WHERE id = %d",
            $participant_id
        ),
        ARRAY_A
    );

    if (!$participant) {
        error_log('[EIPSI-POOL-COMPLETION] Participant not found');
        return false;
    }

    // Find all pool assignments for this participant (matching by email pattern)
    $pool_assignments_table = $wpdb->prefix . 'eipsi_pool_assignments';
    $pool_assignment = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT id, pool_id, completed FROM {$pool_assignments_table} 
             WHERE study_id = %d AND participant_id LIKE %s AND completed = 0
             ORDER BY assigned_at DESC LIMIT 1",
            $study_id,
            '%' . $wpdb->esc_like($participant['email']) . '%'
        ),
        ARRAY_A
    );

    if (!$pool_assignment) {
        error_log('[EIPSI-POOL-COMPLETION] No active pool assignment found for this study');
        return false;
    }

    // Check if all waves for this study are completed
    $assignments_table = $wpdb->prefix . 'survey_assignments';
    $waves_table = $wpdb->prefix . 'survey_waves';

    // Get total waves for this study
    $total_waves = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$waves_table} WHERE study_id = %d",
            $study_id
        )
    );

    // Get completed assignments for this participant and study
    $completed_waves = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$assignments_table} 
             WHERE participant_id = %d AND study_id = %d AND status = 'submitted'",
            $participant_id,
            $study_id
        )
    );

    error_log(sprintf('[EIPSI-POOL-COMPLETION] Waves: total=%d, completed=%d', $total_waves, $completed_waves));

    // If all waves are completed, mark the pool assignment as completed
    if ($completed_waves >= $total_waves) {
        $result = $wpdb->update(
            $pool_assignments_table,
            array(
                'completed' => 1,
                'completed_at' => current_time('mysql'),
                'completion_form_id' => $form_id,
            ),
            array('id' => $pool_assignment['id']),
            array('%d', '%s', '%s'),
            array('%d')
        );

        if ($result !== false) {
            error_log(sprintf('[EIPSI-POOL-COMPLETION] Pool assignment %d marked as completed', $pool_assignment['id']));
            return true;
        } else {
            error_log('[EIPSI-POOL-COMPLETION] Error updating pool assignment: ' . $wpdb->last_error);
            return false;
        }
    }

    error_log('[EIPSI-POOL-COMPLETION] Study not yet fully completed');
    return false;
}
