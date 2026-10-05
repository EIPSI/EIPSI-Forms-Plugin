<?php
/**
 * AJAX API Handlers for Study Dashboard
 * 
 * @since 1.5.2
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/longitudinal/bootstrap.php';

/**
 * Register AJAX handlers
 */
add_action('wp_ajax_eipsi_test_no_nonce', 'wp_ajax_eipsi_test_no_nonce_handler');
add_action('wp_ajax_eipsi_test_deadline', 'wp_ajax_eipsi_test_deadline_handler');
add_action('wp_ajax_eipsi_get_study_overview', 'wp_ajax_eipsi_get_study_overview_handler');
add_action('wp_ajax_eipsi_get_wave_details', 'wp_ajax_eipsi_get_wave_details_handler');
add_action('wp_ajax_eipsi_send_wave_reminder_manual', 'wp_ajax_eipsi_send_wave_reminder_manual_handler');
add_action('wp_ajax_eipsi_extend_wave_deadline', 'wp_ajax_eipsi_extend_wave_deadline_handler');
add_action('wp_ajax_eipsi_remove_wave_deadline', 'wp_ajax_eipsi_remove_wave_deadline_handler');
add_action('wp_ajax_eipsi_save_wave_nudges', 'wp_ajax_eipsi_save_wave_nudges_handler');
add_action('wp_ajax_eipsi_redistribute_nudges', 'wp_ajax_eipsi_redistribute_nudges_handler');
add_action('wp_ajax_eipsi_get_study_email_logs', 'wp_ajax_eipsi_get_study_email_logs_handler');
add_action('wp_ajax_eipsi_add_participant', 'wp_ajax_eipsi_add_participant_handler');
add_action('wp_ajax_eipsi_validate_csv_participants', 'wp_ajax_eipsi_validate_csv_participants_handler');
add_action('wp_ajax_eipsi_import_csv_participants', 'wp_ajax_eipsi_import_csv_participants_handler');
add_action('wp_ajax_eipsi_get_participants_list', 'wp_ajax_eipsi_get_participants_list_handler');
add_action('wp_ajax_eipsi_toggle_participant_status', 'wp_ajax_eipsi_toggle_participant_status_handler');
add_action('wp_ajax_eipsi_save_study_cron_config', 'wp_ajax_eipsi_save_study_cron_config_handler');
add_action('wp_ajax_eipsi_get_study_cron_config', 'wp_ajax_eipsi_get_study_cron_config_handler');
add_action('wp_ajax_eipsi_save_study_settings', 'wp_ajax_eipsi_save_study_settings_handler');
add_action('wp_ajax_eipsi_close_study', 'wp_ajax_eipsi_close_study_handler');
add_action('wp_ajax_eipsi_generate_magic_link', 'wp_ajax_eipsi_generate_magic_link_handler');
add_action('wp_ajax_eipsi_send_magic_link', 'wp_ajax_eipsi_send_magic_link_handler');
add_action('wp_ajax_eipsi_get_magic_link_preview', 'wp_ajax_eipsi_get_magic_link_preview_handler');
add_action('wp_ajax_eipsi_resend_magic_link', 'wp_ajax_eipsi_resend_magic_link_handler');
add_action('wp_ajax_eipsi_extend_magic_link', 'wp_ajax_eipsi_extend_magic_link_handler');
add_action('wp_ajax_eipsi_resend_confirmation', 'wp_ajax_eipsi_resend_confirmation_handler');
add_action('wp_ajax_eipsi_get_pending_confirmations', 'wp_ajax_eipsi_get_pending_confirmations_handler');
add_action('wp_ajax_eipsi_resend_participant_email', 'wp_ajax_eipsi_resend_participant_email_handler');
add_action('wp_ajax_eipsi_get_participant_email_history', 'wp_ajax_eipsi_get_participant_email_history_handler');
add_action('wp_ajax_eipsi_get_participant_detail', 'wp_ajax_eipsi_get_participant_detail_handler');
add_action('wp_ajax_eipsi_remove_participant', 'wp_ajax_eipsi_remove_participant_handler');
add_action('wp_ajax_eipsi_delete_participant', 'eipsi_delete_participant_dispatch');
function eipsi_delete_participant_dispatch() {
    $nonce = $_POST['nonce'] ?? '';
    if (is_string($nonce) && wp_verify_nonce($nonce, 'eipsi_waves_nonce')) {
        wp_ajax_eipsi_delete_participant_waves_handler();
        return;
    }
    wp_ajax_eipsi_delete_participant_handler();
}

/**
 * GET consolidated study data
 */
function wp_ajax_eipsi_get_study_overview_handler() {
    error_log('[EIPSI DASHBOARD API] get_study_overview called');
    check_ajax_referer('eipsi_study_dashboard_nonce', 'nonce');

    if (!eipsi_user_can_manage_longitudinal()) {
        error_log('[EIPSI DASHBOARD API] Unauthorized access attempt');
        wp_send_json_error('Unauthorized');
    }

    $study_id = isset($_GET['study_id']) ? intval($_GET['study_id']) : 0;
    error_log("[EIPSI DASHBOARD API] Study ID: {$study_id}");
    if (empty($study_id)) {
        wp_send_json_error('Missing study ID');
    }


    EIPSI_Form_Response::emit(EIPSI_Longitudinal_Study_Dashboard_Service::wp_ajax_eipsi_get_study_overview_handler($study_id,$_POST,$_GET));
}

/**
 * POST close study
 */
function wp_ajax_eipsi_close_study_handler() {
    check_ajax_referer('eipsi_study_dashboard_nonce', 'nonce');

    if (!eipsi_user_can_manage_longitudinal()) {
        wp_send_json_error(array('message' => __('Unauthorized', 'eipsi-forms')));
    }

    $study_id = isset($_POST['study_id']) ? intval($_POST['study_id']) : 0;
    if (empty($study_id)) {
        wp_send_json_error(array('message' => __('Missing study ID', 'eipsi-forms')));
    }


    EIPSI_Form_Response::emit(EIPSI_Longitudinal_Study_Dashboard_Service::wp_ajax_eipsi_close_study_handler($study_id,$_POST,$_GET));
}

/**
 * GET specific wave details
 */
function wp_ajax_eipsi_get_wave_details_handler() {
    check_ajax_referer('eipsi_study_dashboard_nonce', 'nonce');

    if (!eipsi_user_can_manage_longitudinal()) {
        wp_send_json_error('Unauthorized');
    }

    $wave_id = isset($_GET['wave_id']) ? (int) $_GET['wave_id'] : 0;
    if (!$wave_id) {
        wp_send_json_error('Missing wave ID');
    }

    global $wpdb;

    $assignments = $wpdb->get_results($wpdb->prepare(
        "SELECT a.*, p.email, CONCAT(p.first_name, ' ', p.last_name) as full_name 
         FROM {$wpdb->prefix}survey_assignments a
         JOIN {$wpdb->prefix}survey_participants p ON a.participant_id = p.id
         WHERE a.wave_id = %d",
        $wave_id
    ));

    wp_send_json_success($assignments);
}

/**
 * POST send manual reminder
 */
function wp_ajax_eipsi_send_wave_reminder_manual_handler() {
    error_log('[EIPSI DASHBOARD API] send_wave_reminder_manual called');
    check_ajax_referer('eipsi_study_dashboard_nonce', 'nonce');

    if (!eipsi_user_can_manage_longitudinal()) {
        error_log('[EIPSI DASHBOARD API] Unauthorized access attempt');
        wp_send_json_error('Unauthorized');
    }

    $wave_id = isset($_POST['wave_id']) ? (int) $_POST['wave_id'] : 0;
    error_log("[EIPSI DASHBOARD API] Wave ID: {$wave_id}");
    if (!$wave_id) {
        wp_send_json_error('Missing wave ID');
    }

    try {
        global $wpdb;

        // Get study_id and form_id from wave (study_id is the survey_id in other tables)
        $wave = $wpdb->get_row($wpdb->prepare(
            "SELECT study_id, form_id FROM {$wpdb->prefix}survey_waves WHERE id = %d",
            $wave_id
        ));

        if (!$wave || empty($wave->study_id)) {
            wp_send_json_error('Wave not found or missing survey association');
        }

        if (empty($wave->form_id)) {
            wp_send_json_error('Wave is not associated with a form');
        }

        $survey_id = (int) $wave->study_id;

        // Include eligibility service
        require_once dirname(__FILE__) . '/services/class-wave-eligibility-service.php';

        // Get participants with PENDING assignments for THIS SPECIFIC WAVE only
        $candidate_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT sa.participant_id 
             FROM {$wpdb->prefix}survey_assignments sa
             JOIN {$wpdb->prefix}survey_participants p ON sa.participant_id = p.id
             WHERE sa.wave_id = %d           -- Only this specific wave
             AND sa.study_id = %d 
             AND sa.status = 'pending'       -- Only pending (not submitted/paused)
             AND p.is_active = 1",
            $wave_id,
            $survey_id
        ));

        if (empty($candidate_ids)) {
            error_log("[EIPSI Reminder] No participants found for wave {$wave_id}, study {$survey_id}");
            wp_send_json_error('No active participants found for this wave');
        }

        // Filter by eligibility: only those who completed previous wave
        $participant_ids = EIPSI_Wave_Eligibility_Service::filter_pending_by_eligibility(
            $wave_id,
            $survey_id,
            $candidate_ids
        );

        if (empty($participant_ids)) {
            error_log("[EIPSI Reminder] No eligible participants found for wave {$wave_id}, study {$survey_id}");
            wp_send_json_error('No eligible participants found for this wave (previous wave not completed)');
        }
        
        error_log("[EIPSI Reminder] Found " . count($participant_ids) . " eligible participants for wave {$wave_id} (filtered from " . count($candidate_ids) . " candidates)");

        // Send reminders via Email Service
        $result = EIPSI_Email_Service::send_manual_reminders($survey_id, $participant_ids, $wave_id);

        wp_send_json_success(array(
            'message' => sprintf(__('Se han enviado %d recordatorios.', 'eipsi-forms'), $result['sent_count']),
            'sent' => $result['sent_count'],
            'failed' => $result['failed_count']
        ));
    } catch (Exception $e) {
        error_log('[EIPSI Reminder] Error: ' . $e->getMessage());
        wp_send_json_error(array(
            'message' => 'Error al enviar recordatorios: ' . $e->getMessage(),
            'error' => 'exception'
        ), 500);
    }
}

/**
 * POST send global reminder to all waves in a study
 */
add_action('wp_ajax_eipsi_send_global_reminder', 'wp_ajax_eipsi_send_global_reminder_handler');
function wp_ajax_eipsi_send_global_reminder_handler() {
    error_log('[EIPSI DASHBOARD API] send_global_reminder called');
    check_ajax_referer('eipsi_study_dashboard_nonce', 'nonce');

    if (!eipsi_user_can_manage_longitudinal()) {
        error_log('[EIPSI DASHBOARD API] Unauthorized access attempt');
        wp_send_json_error('Unauthorized');
    }

    $study_id = isset($_POST['study_id']) ? (int) $_POST['study_id'] : 0;
    error_log("[EIPSI DASHBOARD API] Study ID: {$study_id}");
    if (!$study_id) {
        wp_send_json_error('Missing study ID');
    }

    try {
        global $wpdb;

        // Get all waves for this study
        $waves = $wpdb->get_results($wpdb->prepare(
            "SELECT id, form_id FROM {$wpdb->prefix}survey_waves WHERE study_id = %d",
            $study_id
        ));

        if (empty($waves)) {
            wp_send_json_error('No waves found for this study');
        }

        $total_sent = 0;
        $total_failed = 0;

        // Send reminders for each wave
        foreach ($waves as $wave) {
            if (!$wave->form_id) continue;

            // Get active participant IDs assigned to this wave
            $participant_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT participant_id FROM {$wpdb->prefix}survey_assignments 
                 WHERE wave_id = %d AND status = 'active'",
                $wave->id
            ));

            if (empty($participant_ids)) continue;

            // Send reminders via Email Service (survey_id = study_id for longitudinal)
            $result = EIPSI_Email_Service::send_manual_reminders($study_id, $participant_ids, $wave->id);
            
            $total_sent += $result['sent_count'];
            $total_failed += $result['failed_count'];
        }

        wp_send_json_success(array(
            'message' => sprintf(__('Se han enviado %d recordatorios globales.', 'eipsi-forms'), $total_sent),
            'sent_count' => $total_sent,
            'failed_count' => $total_failed
        ));
    } catch (Exception $e) {
        error_log('[EIPSI Global Reminder] Error: ' . $e->getMessage());
        wp_send_json_error(array(
            'message' => 'Error al enviar recordatorios globales: ' . $e->getMessage(),
            'error' => 'exception'
        ), 500);
    }
}

/**
 * Helper: Redistribute nudges using evidence-based fixed percentages
 * System: deadline → window → nudges
 * 
 * Distribution based on adherence research:
 * - Nudge 1: 15% of window (early follow-up)
 * - Nudge 2: 40% of window (mid-point reminder)
 * - Nudge 3: 70% of window (moderate urgency)
 * - Nudge 4: 90% of window (last call, 10% margin)
 * 
 * @param array $current_nudges Current nudge configuration (for enabled status)
 * @param int $original_window_minutes Original window in minutes (unused, kept for compatibility)
 * @param int $new_window_minutes New window in minutes (from deadline)
 * @return array Redistributed nudge configuration
 */
function eipsi_redistribute_nudges($current_nudges, $original_window_minutes, $new_window_minutes) {
    // Fixed percentages based on adherence research (cumulative from start)
    $percentages = array(
        'nudge_1' => 0.15,  // 15% of window
        'nudge_2' => 0.40,  // 40% of window
        'nudge_3' => 0.70,  // 70% of window
        'nudge_4' => 0.90   // 90% of window
    );
    
    $redistributed = array();
    
    foreach ($current_nudges as $key => $nudge) {
        if (!isset($nudge['enabled'])) {
            $redistributed[$key] = $nudge;
            continue;
        }
        
        // Get cumulative position in window (absolute offset from start)
        $cumulative_minutes = isset($percentages[$key]) 
            ? round($new_window_minutes * $percentages[$key])
            : 0;
        
        // Convert to hours (preferred unit for display)
        $interval_hours = $cumulative_minutes / 60;
        
        $redistributed[$key] = array(
            'enabled' => $nudge['enabled'],
            'value' => round($interval_hours, 2),
            'unit' => 'hours'
        );
    }
    
    return $redistributed;
}

/**
 * POST extend wave deadline
 * Phase 5 T1-Anchor: Updates due_at in assignments and reschedules nudges
 * System: deadline → window → nudges (automatic redistribution)
 */
function wp_ajax_eipsi_extend_wave_deadline_handler() {
    $received_nonce = isset($_POST['nonce']) ? $_POST['nonce'] : '';
    
    if (!wp_verify_nonce($received_nonce, 'eipsi_study_dashboard_nonce')) {
        wp_send_json_error('Invalid nonce');
        return;
    }

    if (!eipsi_user_can_manage_longitudinal()) {
        wp_send_json_error('Unauthorized');
        return;
    }


    EIPSI_Form_Response::emit(EIPSI_Longitudinal_Assignment_Deadline_Service::wp_ajax_eipsi_extend_wave_deadline_handler($_POST,$_GET));
}

/**
 * POST remove wave deadline
 * Phase 5 T1-Anchor: Restores automatic due_at calculation and reschedules nudges
 */
function wp_ajax_eipsi_remove_wave_deadline_handler() {
    check_ajax_referer('eipsi_study_dashboard_nonce', 'nonce');

    if (!eipsi_user_can_manage_longitudinal()) {
        wp_send_json_error('Unauthorized');
    }


    EIPSI_Form_Response::emit(EIPSI_Longitudinal_Assignment_Deadline_Service::wp_ajax_eipsi_remove_wave_deadline_handler($_POST,$_GET));
}

/**
 * GET email logs
 */
function wp_ajax_eipsi_get_study_email_logs_handler() {
    error_log('[EIPSI DASHBOARD API] get_study_email_logs called');
    check_ajax_referer('eipsi_study_dashboard_nonce', 'nonce');

    if (!eipsi_user_can_manage_longitudinal()) {
        error_log('[EIPSI DASHBOARD API] Unauthorized access attempt');
        wp_send_json_error('Unauthorized');
    }

    $study_id = isset($_GET['study_id']) ? intval($_GET['study_id']) : 0;
    error_log("[EIPSI DASHBOARD API] Study ID: {$study_id}");
    if (empty($study_id)) {
        wp_send_json_error('Missing study ID');
    }

    global $wpdb;
    // La tabla email_log usa survey_id (INT), no study_id
    $logs = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}survey_email_log 
         WHERE survey_id = %d 
         ORDER BY sent_at DESC LIMIT 50",
        $study_id
    ));
    
    // v2.6.1 - Formatear fechas según timezone de WordPress
    foreach ($logs as $log) {
        if (!empty($log->sent_at)) {
            $timestamp = strtotime($log->sent_at);
            $log->sent_at_formatted = wp_date(
                get_option('date_format') . ', ' . get_option('time_format'),
                $timestamp
            );
        }
        error_log("[EIPSI EMAIL LOGS] Log ID {$log->id}: email_type='{$log->email_type}', status='{$log->status}'");
    }

    wp_send_json_success($logs);
}


/**
 * POST resend email to participant
 * Supports: welcome, magic_link, reminder, confirmation, recovery
 * 
 * @since 1.5.3
 * @fix v1.7.3 - Added survey_id validation to prevent FK errors
 */
function wp_ajax_eipsi_resend_participant_email_handler() {
    error_log('[EIPSI DASHBOARD API] resend_participant_email called');
    
    // v2.5.1 - Ensure Email Service is loaded
    if (!class_exists('EIPSI_Email_Service')) {
        require_once plugin_dir_path(__FILE__) . 'services/class-email-service.php';
    }
    
    // Check nonce - accept both nonces for compatibility
    $nonce_valid = false;
    if (isset($_POST['nonce'])) {
        $nonce_valid = wp_verify_nonce($_POST['nonce'], 'eipsi_study_dashboard_nonce') ||
                      wp_verify_nonce($_POST['nonce'], 'eipsi_waves_nonce');
    }

    if (!$nonce_valid) {
        wp_send_json_error(array('message' => 'Invalid nonce'));
    }

    if (!eipsi_user_can_manage_longitudinal()) {
        wp_send_json_error(array('message' => 'Unauthorized'));
    }

    // Get and validate parameters
    $participant_id = isset($_POST['participant_id']) ? intval($_POST['participant_id']) : 0;
    $email_type = isset($_POST['email_type']) ? sanitize_text_field($_POST['email_type']) : '';
    $survey_id = isset($_POST['study_id']) ? intval($_POST['study_id']) : 0;
    $wave_id = isset($_POST['wave_id']) ? intval($_POST['wave_id']) : null;

    if (empty($participant_id)) {
        wp_send_json_error(array('message' => 'Missing participant ID'));
    }

    $valid_types = array('welcome', 'magic_link', 'reminder', 'confirmation', 'recovery');
    if (empty($email_type) || !in_array($email_type, $valid_types)) {
        wp_send_json_error(array('message' => 'Invalid email type'));
    }

    // Load Email Service
    if (!class_exists('EIPSI_Email_Service')) {
        require_once plugin_dir_path(__FILE__) . 'services/class-email-service.php';
    }

    // Load Participant Service to validate participant's survey_id
    if (!class_exists('EIPSI_Participant_Service')) {
        require_once plugin_dir_path(__FILE__) . 'services/class-participant-service.php';
    }

    // Get participant to validate survey_id
    $participant = EIPSI_Participant_Service::get_by_id($participant_id);
    if (!$participant) {
        wp_send_json_error(array(
            'message' => 'Participante no encontrado',
            'error' => 'participant_not_found'
        ));
    }

    // Validate participant has a valid survey_id
    $participant_survey_id = intval($participant->survey_id);
    if ($participant_survey_id <= 0) {
        wp_send_json_error(array(
            'message' => 'El participante no tiene un estudio asignado válido',
            'error' => 'invalid_survey_id'
        ));
    }

    // Use the participant's survey_id if not provided in request
    if (empty($survey_id)) {
        $survey_id = $participant_survey_id;
    }

    // v2.5.1 - Use EIPSI_Email_Service to resend the email
    try {
        $result = EIPSI_Email_Service::resend_participant_email($participant_id, $email_type, $survey_id, $wave_id);
        
        if ($result['success']) {
            wp_send_json_success(array(
                'message' => $result['message'],
                'email_type' => $email_type,
                'participant_id' => $participant_id
            ));
        } else {
            wp_send_json_error(array(
                'message' => $result['message'],
                'error' => $result['error'] ?? 'unknown_error'
            ));
        }
    } catch (Exception $e) {
        error_log('[EIPSI Resend Email] Exception: ' . $e->getMessage());
        wp_send_json_error(array(
            'message' => 'Error al enviar email: ' . $e->getMessage(),
            'error' => 'exception'
        ));
    }
}

/**
 * POST save wave nudge configuration
 */
add_action('wp_ajax_eipsi_save_wave_nudges', 'wp_ajax_eipsi_save_wave_nudges_handler');
function wp_ajax_eipsi_save_wave_nudges_handler() {
    error_log('[EIPSI DASHBOARD API] ========================================');
    error_log('[EIPSI DASHBOARD API] save_wave_nudges called');
    error_log('[EIPSI DASHBOARD API] FULL POST DATA: ' . print_r($_POST, true));
    check_ajax_referer('eipsi_study_dashboard_nonce', 'nonce');

    if (!eipsi_user_can_manage_longitudinal()) {
        error_log('[EIPSI DASHBOARD API] Unauthorized access attempt');
        wp_send_json_error('Unauthorized');
    }

    $wave_id = isset($_POST['wave_id']) ? (int) $_POST['wave_id'] : 0;
    $nudges = isset($_POST['nudges']) ? $_POST['nudges'] : array();
    $window_minutes = isset($_POST['window_minutes']) ? (int) $_POST['window_minutes'] : null;
    // v2.5.0 - Robust enabled detection: accepts 'true', true, '1', 1, 'on'
    $enabled_raw = isset($_POST['enabled']) ? $_POST['enabled'] : false;
    $enabled = in_array($enabled_raw, array('true', true, '1', 1, 'on', 'yes'), true);
    error_log("[EIPSI DASHBOARD API] Raw enabled value: " . var_export($enabled_raw, true));
    error_log("[EIPSI DASHBOARD API] Parsed enabled value: " . ($enabled ? 'true' : 'false'));
    error_log("[EIPSI DASHBOARD API] window_minutes: " . ($window_minutes ? $window_minutes : 'NULL'));
    
    // Handle JSON string if passed
    if (is_string($nudges)) {
        $nudges = json_decode(stripslashes($nudges), true);
        error_log("[EIPSI DASHBOARD API] Decoded nudges from JSON: " . print_r($nudges, true));
    }
    if (!is_array($nudges)) {
        wp_send_json_error(array('message' => 'Configuración de nudges inválida.'));
    }
    
    $nudge_count = is_array($nudges) ? count($nudges) : 0;
    error_log("[EIPSI DASHBOARD API] Wave ID: {$wave_id}");
    error_log("[EIPSI DASHBOARD API] Enabled (final): " . ($enabled ? 'true' : 'false'));
    error_log("[EIPSI DASHBOARD API] Nudges count: " . $nudge_count);
    error_log("[EIPSI DASHBOARD API] Nudges data: " . print_r($nudges, true));

    if (!$wave_id) {
        error_log("[EIPSI DASHBOARD API] ERROR: Missing wave ID");
        wp_send_json_error('Missing wave ID');
    }

    require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/services/class-nudge-service.php';
    $config = array();
    for ($stage = 1; $stage <= 4; $stage++) {
        $item = $nudges[$stage - 1] ?? array('value' => 24 * $stage, 'unit' => 'hours');
        $config['nudge_' . $stage] = array('enabled' => $enabled && isset($nudges[$stage - 1]),
            'value' => $item['value'] ?? null, 'unit' => $item['unit'] ?? null);
    }
    // Empty UI field means preserve the window; it must not overwrite it with NULL.
    $window_supplied = isset($_POST['window_minutes']) && $_POST['window_minutes'] !== '';
    $result = EIPSI_Nudge_Service::save_wave_configuration($wave_id, $config, $window_supplied, $_POST['window_minutes'] ?? null);
    if (is_wp_error($result)) { wp_send_json_error(array('message' => $result->get_error_message(), 'error' => $result->get_error_code())); }
    wp_send_json_success(array_merge(array('message' => 'Configuración de nudges guardada'), $result));
}

/**
 * POST redistribute nudges proportionally to current window
 */
function wp_ajax_eipsi_redistribute_nudges_handler() {
    check_ajax_referer('eipsi_study_dashboard_nonce', 'nonce');

    if (!eipsi_user_can_manage_longitudinal()) {
        wp_send_json_error('Unauthorized');
    }

    $wave_id = isset($_POST['wave_id']) ? (int) $_POST['wave_id'] : 0;
    if (!$wave_id) {
        wp_send_json_error('Missing wave ID');
    }

    try {
        global $wpdb;
        
        error_log('[EIPSI REDISTRIBUTE BTN] ========================================');
        error_log('[EIPSI REDISTRIBUTE BTN] Manual redistribution requested for wave_id: ' . $wave_id);
        
        // Get wave info
        $wave = $wpdb->get_row($wpdb->prepare(
            "SELECT id, study_id, offset_minutes, window_minutes, due_date, nudge_config 
             FROM {$wpdb->prefix}survey_waves WHERE id = %d",
            $wave_id
        ));
        
        if (!$wave) {
            error_log('[EIPSI REDISTRIBUTE BTN] ERROR: Wave not found');
            wp_send_json_error('Wave not found');
        }
        
        error_log('[EIPSI REDISTRIBUTE BTN] Wave found: study_id=' . $wave->study_id . ', offset_minutes=' . $wave->offset_minutes . ', window_minutes=' . $wave->window_minutes . ', due_date=' . $wave->due_date);
        
        // Get study creation date
        $study = $wpdb->get_row($wpdb->prepare(
            "SELECT created_at FROM {$wpdb->prefix}survey_studies WHERE id = %d",
            $wave->study_id
        ));
        
        if (!$study) {
            error_log('[EIPSI REDISTRIBUTE BTN] ERROR: Study not found');
            wp_send_json_error('Study not found');
        }
        
        error_log('[EIPSI REDISTRIBUTE BTN] Study created_at: ' . $study->created_at);
        
        $study_created_timestamp = strtotime($study->created_at);
        $is_t1 = ($wave->offset_minutes == 0);
        
        error_log('[EIPSI REDISTRIBUTE BTN] study_created_timestamp (parsed): ' . $study_created_timestamp . ' (' . date('Y-m-d H:i:s', $study_created_timestamp) . ')');
        error_log('[EIPSI REDISTRIBUTE BTN] is_t1: ' . ($is_t1 ? 'YES' : 'NO'));
        
        // Calculate current window
        $current_window_minutes = 0;
        
        if (!empty($wave->due_date)) {
            error_log('[EIPSI REDISTRIBUTE BTN] Wave has due_date: ' . $wave->due_date);
            
            // Extract date part only (due_date from DB has format "YYYY-MM-DD HH:MM:SS")
            $deadline_date = substr($wave->due_date, 0, 10);
            $deadline_datetime = $deadline_date . ' 23:59:59';
            $deadline_timestamp = strtotime($deadline_datetime);
            error_log('[EIPSI REDISTRIBUTE BTN] deadline_date extracted: ' . $deadline_date);
            error_log('[EIPSI REDISTRIBUTE BTN] deadline_datetime built: ' . $deadline_datetime);
            error_log('[EIPSI REDISTRIBUTE BTN] deadline_timestamp (parsed): ' . $deadline_timestamp . ' (' . date('Y-m-d H:i:s', $deadline_timestamp) . ')');
            
            // T1 (offset=0): window from created_at to deadline (fixed)
            // Other waves: window from NOW to deadline (dynamic, remaining time)
            if ($is_t1) {
                error_log('[EIPSI REDISTRIBUTE BTN] T1 calculation: deadline_timestamp - study_created_timestamp');
                error_log('[EIPSI REDISTRIBUTE BTN] T1 calculation: ' . $deadline_timestamp . ' - ' . $study_created_timestamp . ' = ' . ($deadline_timestamp - $study_created_timestamp) . ' seconds');
                $current_window_minutes = ceil(($deadline_timestamp - $study_created_timestamp) / 60);
                error_log('[EIPSI REDISTRIBUTE BTN] T1 current_window_minutes: ' . $current_window_minutes);
            } else {
                $now_timestamp = current_time('timestamp');
                error_log('[EIPSI REDISTRIBUTE BTN] Other wave calculation: deadline_timestamp - now_timestamp');
                error_log('[EIPSI REDISTRIBUTE BTN] now_timestamp: ' . $now_timestamp . ' (' . date('Y-m-d H:i:s', $now_timestamp) . ')');
                error_log('[EIPSI REDISTRIBUTE BTN] Other calculation: ' . $deadline_timestamp . ' - ' . $now_timestamp . ' = ' . ($deadline_timestamp - $now_timestamp) . ' seconds');
                $current_window_minutes = ceil(($deadline_timestamp - $now_timestamp) / 60);
                error_log('[EIPSI REDISTRIBUTE BTN] Other current_window_minutes: ' . $current_window_minutes);
            }
        } else {
            // No deadline: use original window
            error_log('[EIPSI REDISTRIBUTE BTN] No due_date, using original window_minutes: ' . $wave->window_minutes);
            $current_window_minutes = $wave->window_minutes;
        }
        
        error_log('[EIPSI REDISTRIBUTE BTN] FINAL current_window_minutes: ' . $current_window_minutes);
        
        // Validate minimum window for nudge redistribution
        if ($current_window_minutes < 60) {
            error_log(sprintf(
                '[EIPSI Redistribute] Wave %d: Window too short (%d min) for redistribution',
                $wave_id,
                $current_window_minutes
            ));
            
            wp_send_json_error(array(
                'message' => sprintf(
                    'La ventana es muy corta (%d minutos). Se requiere un mínimo de 60 minutos para redistribuir nudges de manera efectiva.',
                    $current_window_minutes
                ),
                'window_minutes' => $current_window_minutes,
                'minimum_required' => 60
            ));
        }
        
        // Get current nudge config
        $nudge_config = !empty($wave->nudge_config) ? json_decode($wave->nudge_config, true) : array();
        
        // Save original nudges if not already saved
        if (!isset($nudge_config['original_nudges'])) {
            $nudge_config['original_nudges'] = array(
                'nudge_1' => $nudge_config['nudge_1'] ?? null,
                'nudge_2' => $nudge_config['nudge_2'] ?? null,
                'nudge_3' => $nudge_config['nudge_3'] ?? null,
                'nudge_4' => $nudge_config['nudge_4'] ?? null,
            );
            $nudge_config['original_window_minutes'] = $wave->window_minutes;
        }
        
        // Use original nudges as base for redistribution
        $base_nudges = isset($nudge_config['original_nudges']) 
            ? $nudge_config['original_nudges']
            : array(
                'nudge_1' => $nudge_config['nudge_1'] ?? null,
                'nudge_2' => $nudge_config['nudge_2'] ?? null,
                'nudge_3' => $nudge_config['nudge_3'] ?? null,
                'nudge_4' => $nudge_config['nudge_4'] ?? null,
            );
        
        // Use original window as base
        $original_window_minutes = $nudge_config['original_window_minutes'];
        
        // Redistribute from original values
        $redistributed = eipsi_redistribute_nudges(
            $base_nudges,
            $original_window_minutes,
            $current_window_minutes
        );
        
        // Update nudge config
        $nudge_config['nudge_1'] = $redistributed['nudge_1'];
        $nudge_config['nudge_2'] = $redistributed['nudge_2'];
        $nudge_config['nudge_3'] = $redistributed['nudge_3'];
        $nudge_config['nudge_4'] = $redistributed['nudge_4'];
        
        // Save to database
        $wpdb->update(
            "{$wpdb->prefix}survey_waves",
            array('nudge_config' => wp_json_encode($nudge_config)),
            array('id' => $wave_id),
            array('%s'),
            array('%d')
        );
        
        $window_days = ceil($current_window_minutes / 1440);
        
        error_log(sprintf('[EIPSI Redistribute] Wave %d: Redistributed to %d days (%d minutes)',
            $wave_id, $window_days, $current_window_minutes));
        
        wp_send_json_success(array(
            'message' => 'Nudges redistribuidos correctamente',
            'window_days' => $window_days,
            'window_minutes' => $current_window_minutes
        ));
    } catch (Exception $e) {
        error_log('[EIPSI Redistribute] Error: ' . $e->getMessage());
        wp_send_json_error(array(
            'message' => 'Error al redistribuir nudges: ' . $e->getMessage(),
            'error' => 'exception'
        ), 500);
    }
}

// ============================================
// Email Log Handler
// ============================================

/**
 * GET participant email history
 */
function wp_ajax_eipsi_get_participant_email_history_handler() {
    check_ajax_referer('eipsi_study_dashboard_nonce', 'nonce');

    if (!eipsi_user_can_manage_longitudinal()) {
        wp_send_json_error('Unauthorized');
    }

    $participant_id = isset($_GET['participant_id']) ? intval($_GET['participant_id']) : 0;
    if (empty($participant_id)) {
        wp_send_json_error('Missing participant ID');
    }

    // Load Email Service
    if (!class_exists('EIPSI_Email_Service')) {
        require_once plugin_dir_path(__FILE__) . 'services/class-email-service.php';
    }

    $history = EIPSI_Email_Service::get_email_log_entries(0, array(), 10, 0);
    
    // Filter for this participant
    $participant_logs = array_filter($history['logs'], function($log) use ($participant_id) {
        return $log->participant_id == $participant_id;
    });

    wp_send_json_success(array(
        'logs' => array_values($participant_logs),
        'total' => count($participant_logs)
    ));
}

/**
 * POST add participant and send invitation
 * This handler accepts both 'eipsi_study_dashboard_nonce' and 'eipsi_waves_nonce' for compatibility
 */
function wp_ajax_eipsi_add_participant_handler() {
    // Check nonce - accept both nonces for compatibility with different contexts
    $nonce_valid = false;

    // Try study dashboard nonce first
    if (isset($_POST['nonce'])) {
        $nonce_valid = wp_verify_nonce($_POST['nonce'], 'eipsi_study_dashboard_nonce') ||
                      wp_verify_nonce($_POST['nonce'], 'eipsi_waves_nonce');
    }

    if (!$nonce_valid) {
        wp_send_json_error('Invalid nonce');
    }

    if (!eipsi_user_can_manage_longitudinal()) {
        wp_send_json_error('Unauthorized');
    }

    $study_id = isset($_POST['study_id']) ? intval($_POST['study_id']) : 0;
    if (empty($study_id)) {
        wp_send_json_error('Missing study ID');
    }

    // Sanitizar y validar datos
    $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
    $first_name = isset($_POST['first_name']) ? sanitize_text_field($_POST['first_name']) : '';
    $last_name = isset($_POST['last_name']) ? sanitize_text_field($_POST['last_name']) : '';
    $password = isset($_POST['password']) ? sanitize_text_field($_POST['password']) : '';

    // Validaciones
    if (empty($email) || !is_email($email)) {
        wp_send_json_error('Email inválido');
    }

    // Generar contraseña automática si no se proporcionó
    if (empty($password)) {
        $password = wp_generate_password(12, false);
    }

    // Validar longitud mínima de contraseña
    if (strlen($password) < 8) {
        wp_send_json_error('La contraseña debe tener al menos 8 caracteres');
    }

    // Cargar servicios necesarios
    if (!class_exists('EIPSI_Participant_Service')) {
        require_once plugin_dir_path(__FILE__) . 'services/class-participant-service.php';
    }

    if (!class_exists('EIPSI_Email_Service')) {
        require_once plugin_dir_path(__FILE__) . 'services/class-email-service.php';
    }

    // Crear participante
    $metadata = array();
    if (!empty($first_name)) {
        $metadata['first_name'] = $first_name;
    }
    if (!empty($last_name)) {
        $metadata['last_name'] = $last_name;
    }

    $participant_result = EIPSI_Participant_Service::create_participant($study_id, $email, $password, $metadata);

    if (!$participant_result['success']) {
        switch ($participant_result['error']) {
            case 'invalid_email':
                wp_send_json_error('Formato de email inválido');
            case 'short_password':
                wp_send_json_error('La contraseña debe tener al menos 8 caracteres');
            case 'email_exists':
                wp_send_json_error('Este email ya existe en el estudio');
            default:
                wp_send_json_error('Error al crear el participante');
        }
    }

    $participant_id = $participant_result['participant_id'];

    // Check if double opt-in is enabled
    $double_optin_enabled = defined('EIPSI_DOUBLE_OPTIN_ENABLED') ? EIPSI_DOUBLE_OPTIN_ENABLED : true;
    
    if ($double_optin_enabled) {
        // Load confirmation service
        if (!class_exists('EIPSI_Email_Confirmation_Service')) {
            require_once plugin_dir_path(__FILE__) . 'services/class-email-confirmation-service.php';
        }
        
        // Set participant as inactive initially (pending confirmation)
        EIPSI_Participant_Service::set_active($participant_id, false);
        
        // Generate confirmation token
        $confirmation_result = EIPSI_Email_Confirmation_Service::generate_confirmation_token($study_id, $participant_id, $email);
        
        if (!$confirmation_result['success']) {
            wp_send_json_error('Error al generar el token de confirmación');
        }
        
        // Send confirmation email
        if (!class_exists('EIPSI_Email_Service')) {
            require_once plugin_dir_path(__FILE__) . 'services/class-email-service.php';
        }
        
        $email_sent = EIPSI_Email_Service::send_confirmation_email($study_id, $participant_id, $confirmation_result['token']);
        
        if ($email_sent) {
            wp_send_json_success(array(
                'message' => 'Participante creado. Se ha enviado un email de confirmación.',
                'participant_id' => $participant_id,
                'email_sent' => true,
                'double_optin' => true
            ));
        } else {
            wp_send_json_success(array(
                'message' => 'Participante creado, pero el email de confirmación no pudo ser enviado',
                'participant_id' => $participant_id,
                'email_sent' => false,
                'double_optin' => true
            ));
        }
    } else {
        // Original flow without double opt-in
        // Enviar invitación por email
        $email_sent = EIPSI_Email_Service::send_welcome_email($study_id, $participant_id);

        if ($email_sent) {
            wp_send_json_success(array(
                'message' => 'Participante creado exitosamente e invitación enviada',
                'participant_id' => $participant_id,
                'email_sent' => true,
                'temporary_password' => $password // Include for backward compatibility
            ));
        } else {
            wp_send_json_success(array(
                'message' => 'Participante creado exitosamente, pero hubo un problema enviando el email',
                'participant_id' => $participant_id,
                'email_sent' => false,
                'temporary_password' => $password // Include for backward compatibility
            ));
        }
    }
}

/**
 * POST validate CSV participants data
 */
function wp_ajax_eipsi_validate_csv_participants_handler() {
    check_ajax_referer('eipsi_study_dashboard_nonce', 'nonce');

    if (!eipsi_user_can_manage_longitudinal()) {
        wp_send_json_error('Unauthorized');
    }

    $study_id = isset($_POST['study_id']) ? intval($_POST['study_id']) : 0;
    $csv_data = isset($_POST['csv_data']) ? $_POST['csv_data'] : '';

    if (empty($study_id)) {
        wp_send_json_error('Missing study ID');
    }

    if (empty($csv_data)) {
        wp_send_json_error('No se proporcionaron datos CSV');
    }

    // Parsear CSV
    $participants = eipsi_parse_csv_data($csv_data);

    if (empty($participants)) {
        wp_send_json_error('No se encontraron participantes válidos en el CSV');
    }

    // Límite máximo de participantes
    if (count($participants) > 500) {
        wp_send_json_error('El archivo CSV contiene más de 500 participantes. Por favor, divide el archivo en partes más pequeñas.');
    }

    global $wpdb;

    // Validar cada participante
    $validation_results = array();
    $valid_count = 0;
    $invalid_count = 0;
    $existing_count = 0;

    foreach ($participants as $index => $participant) {
        $result = array(
            'row' => $index + 1,
            'email' => $participant['email'],
            'first_name' => $participant['first_name'],
            'last_name' => $participant['last_name'],
            'is_valid' => true,
            'errors' => array(),
            'status' => 'valid' // valid, invalid, existing
        );

        // Validar email
        if (empty($participant['email'])) {
            $result['is_valid'] = false;
            $result['errors'][] = 'Email vacío';
        } elseif (!is_email($participant['email'])) {
            $result['is_valid'] = false;
            $result['errors'][] = 'Formato de email inválido';
        } else {
            // Verificar si ya existe en el estudio
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}survey_participants WHERE survey_id = %d AND email = %s",
                $study_id,
                sanitize_email($participant['email'])
            ));

            if ($existing) {
                $result['status'] = 'existing';
                $existing_count++;
            }
        }

        // Sanitizar nombres
        $result['first_name'] = sanitize_text_field($participant['first_name']);
        $result['last_name'] = sanitize_text_field($participant['last_name']);

        if (!$result['is_valid']) {
            $result['status'] = 'invalid';
            $invalid_count++;
        } elseif ($result['status'] === 'valid') {
            $valid_count++;
        }

        $validation_results[] = $result;
    }

    wp_send_json_success(array(
        'participants' => $validation_results,
        'summary' => array(
            'total' => count($participants),
            'valid' => $valid_count,
            'invalid' => $invalid_count,
            'existing' => $existing_count
        )
    ));
}

/**
 * POST import CSV participants and send invitations
 */
function wp_ajax_eipsi_import_csv_participants_handler() {
    check_ajax_referer('eipsi_study_dashboard_nonce', 'nonce');

    if (!eipsi_user_can_manage_longitudinal()) {
        wp_send_json_error('Unauthorized');
    }

    $study_id = isset($_POST['study_id']) ? intval($_POST['study_id']) : 0;
    $participants = isset($_POST['participants']) ? $_POST['participants'] : array();

    if (empty($study_id)) {
        wp_send_json_error('Missing study ID');
    }

    if (empty($participants)) {
        wp_send_json_error('No hay participantes para importar');
    }

    // Cargar servicios necesarios
    if (!class_exists('EIPSI_Participant_Service')) {
        require_once plugin_dir_path(__FILE__) . 'services/class-participant-service.php';
    }

    if (!class_exists('EIPSI_Email_Service')) {
        require_once plugin_dir_path(__FILE__) . 'services/class-email-service.php';
    }

    $results = EIPSI_Participant_Import_Service::import_rows($study_id, $participants);

    wp_send_json_success(array(
        'message' => sprintf(
            'Importación completada: %d participantes importados, %d emails enviados',
            $results['imported'],
            $results['emails_sent']
        ),
        'results' => $results
    ));
}

/**
 * Parse CSV data string into array
 *
 * @param string $csv_data CSV content
 * @return array Parsed participants
 */
function eipsi_parse_csv_data($csv_data) {
    $participants = array();

    // Normalizar saltos de línea
    $csv_data = str_replace("\r\n", "\n", $csv_data);
    $csv_data = str_replace("\r", "\n", $csv_data);

    $lines = explode("\n", $csv_data);

    $is_first_line = true;
    foreach ($lines as $line) {
        $line = trim($line);

        // Saltar líneas vacías
        if (empty($line)) {
            continue;
        }

        // Parsear CSV respetando comillas
        $row = eipsi_parse_csv_line($line);

        // Saltar encabezados si es la primera línea
        if ($is_first_line) {
            $is_first_line = false;
            // Detectar si es encabezado (contiene 'email' o similar)
            $first_col = strtolower(trim($row[0] ?? ''));
            if (strpos($first_col, 'email') !== false) {
                continue;
            }
        }

        // Extraer datos
        $participant = array(
            'email' => trim($row[0] ?? ''),
            'first_name' => trim($row[1] ?? ''),
            'last_name' => trim($row[2] ?? '')
        );

        // Solo agregar si hay email
        if (!empty($participant['email'])) {
            $participants[] = $participant;
        }
    }

    return $participants;
}

/**
 * Parse a single CSV line respecting quotes
 *
 * @param string $line CSV line
 * @return array Fields
 */
function eipsi_parse_csv_line($line) {
    $fields = array();
    $field = '';
    $in_quotes = false;
    $length = strlen($line);

    for ($i = 0; $i < $length; $i++) {
        $char = $line[$i];

        if ($char === '"') {
            if ($in_quotes && $i + 1 < $length && $line[$i + 1] === '"') {
                // Comilla escapada
                $field .= '"';
                $i++;
            } else {
                $in_quotes = !$in_quotes;
            }
        } elseif ($char === ',' && !$in_quotes) {
            $fields[] = $field;
            $field = '';
        } else {
            $field .= $char;
        }
    }

    $fields[] = $field;

    return $fields;
}

/**
 * GET participants list with pagination and filters
 */
function wp_ajax_eipsi_get_participants_list_handler() {
    check_ajax_referer('eipsi_study_dashboard_nonce', 'nonce');

    if (!eipsi_user_can_manage_longitudinal()) {
        wp_send_json_error('Unauthorized');
    }

    $study_id = isset($_GET['study_id']) ? intval($_GET['study_id']) : 0;
    if (empty($study_id)) {
        wp_send_json_error('Missing study ID');
    }

    // Parámetros de paginación
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $per_page = isset($_GET['per_page']) ? max(1, min(100, intval($_GET['per_page']))) : 20;

    // Filtros
    $filters = array();
    if (isset($_GET['status']) && in_array($_GET['status'], array('active', 'inactive'))) {
        $filters['status'] = $_GET['status'];
    }
    if (isset($_GET['search']) && !empty($_GET['search'])) {
        $filters['search'] = sanitize_text_field($_GET['search']);
    }

    // Cargar servicio de participantes
    if (!class_exists('EIPSI_Participant_Service')) {
        require_once plugin_dir_path(__FILE__) . 'services/class-participant-service.php';
    }

    $result = EIPSI_Participant_Service::list_participants($study_id, $page, $per_page, $filters);

    if (!empty($result['participants'])) {
        $participant_ids = array_map('intval', wp_list_pluck($result['participants'], 'id'));
        $magic_links_map = eipsi_get_latest_magic_links_map($study_id, $participant_ids);
        $magic_link_email_map = eipsi_get_magic_link_email_status_map($study_id, $participant_ids);

        foreach ($result['participants'] as $participant) {
            $magic_link = isset($magic_links_map[$participant->id]) ? $magic_links_map[$participant->id] : null;
            $email_status = isset($magic_link_email_map[$participant->id]) ? $magic_link_email_map[$participant->id] : null;
            $status_data = eipsi_get_magic_link_status_data($magic_link, $email_status);

            $participant->magic_link_status = $status_data['status'];
            $participant->magic_link_expires_at = $magic_link ? $magic_link->expires_at : null;
            $participant->magic_link_used_at = $magic_link ? $magic_link->used_at : null;
            $participant->magic_link_sent_at = $email_status ? $email_status['sent_at'] : null;
            $participant->magic_link_sent_status = $email_status ? $email_status['status'] : null;
            $participant->magic_link_can_extend = $status_data['can_extend'];
        }
    }

    wp_send_json_success($result);
}

/**
 * Get latest magic links for participants.
 */
function eipsi_get_latest_magic_links_map($study_id, $participant_ids) {
    global $wpdb;

    if (empty($participant_ids)) {
        return array();
    }

    $placeholders = implode(',', array_fill(0, count($participant_ids), '%d'));
    $table_name = $wpdb->prefix . 'survey_magic_links';

    $query = "SELECT ml.*
        FROM {$table_name} ml
        INNER JOIN (
            SELECT participant_id, MAX(created_at) AS latest_created
            FROM {$table_name}
            WHERE survey_id = %d AND participant_id IN ({$placeholders})
            GROUP BY participant_id
        ) latest ON ml.participant_id = latest.participant_id AND ml.created_at = latest.latest_created
        WHERE ml.survey_id = %d";

    $params = array_merge(array($study_id), $participant_ids, array($study_id));
    $results = $wpdb->get_results($wpdb->prepare($query, $params));

    $map = array();
    foreach ($results as $row) {
        $map[(int) $row->participant_id] = $row;
    }

    return $map;
}

/**
 * Get latest magic link email status per participant.
 */
function eipsi_get_magic_link_email_status_map($study_id, $participant_ids) {
    global $wpdb;

    if (empty($participant_ids)) {
        return array();
    }

    $placeholders = implode(',', array_fill(0, count($participant_ids), '%d'));
    $table_name = $wpdb->prefix . 'survey_email_log';

    $query = "SELECT el.participant_id, el.status, el.sent_at
        FROM {$table_name} el
        INNER JOIN (
            SELECT participant_id, MAX(sent_at) AS latest_sent
            FROM {$table_name}
            WHERE survey_id = %d AND email_type = %s AND participant_id IN ({$placeholders})
            GROUP BY participant_id
        ) latest ON el.participant_id = latest.participant_id AND el.sent_at = latest.latest_sent
        WHERE el.survey_id = %d AND el.email_type = %s";

    $params = array_merge(array($study_id, 'magic_link'), $participant_ids, array($study_id, 'magic_link'));
    $results = $wpdb->get_results($wpdb->prepare($query, $params));

    $map = array();
    foreach ($results as $row) {
        $map[(int) $row->participant_id] = array(
            'status' => $row->status,
            'sent_at' => $row->sent_at
        );
    }

    return $map;
}

/**
 * Resolve magic link status and actions.
 */
function eipsi_get_magic_link_status_data($magic_link, $email_status) {
    $status = 'none';
    $can_extend = false;
    $now_ts = current_time('timestamp', true);

    $email_state = $email_status ? $email_status['status'] : null;

    if ($magic_link) {
        $can_extend = empty($magic_link->used_at);

        if (!empty($magic_link->used_at)) {
            $status = 'clicked';
        } elseif (!empty($magic_link->expires_at) && strtotime($magic_link->expires_at) < $now_ts) {
            $status = 'expired';
        } elseif ($email_state === 'sent') {
            $status = 'delivered';
        } elseif ($email_state === 'failed' || $email_state === 'bounced') {
            $status = 'failed';
        } else {
            $status = 'sent';
        }
    } elseif ($email_state === 'failed' || $email_state === 'bounced') {
        $status = 'failed';
    }

    return array(
        'status' => $status,
        'can_extend' => $can_extend
    );
}

/**
 * POST toggle participant active status
 */
function wp_ajax_eipsi_toggle_participant_status_handler() {
    check_ajax_referer('eipsi_study_dashboard_nonce', 'nonce');

    if (!eipsi_user_can_manage_longitudinal()) {
        wp_send_json_error('Unauthorized');
    }

    $participant_id = isset($_POST['participant_id']) ? intval($_POST['participant_id']) : 0;
    $is_active = isset($_POST['is_active']) ? filter_var($_POST['is_active'], FILTER_VALIDATE_BOOLEAN) : true;

    if (empty($participant_id)) {
        wp_send_json_error('Missing participant ID');
    }

    // Cargar servicio de participantes
    if (!class_exists('EIPSI_Participant_Service')) {
        require_once plugin_dir_path(__FILE__) . 'services/class-participant-service.php';
    }

    $success = EIPSI_Participant_Service::set_active($participant_id, $is_active);

    if ($success) {
        $status_text = $is_active ? 'activado' : 'desactivado';
        wp_send_json_success(array(
            'message' => sprintf('Participante %s exitosamente', $status_text),
            'is_active' => $is_active
        ));
    } else {
        wp_send_json_error('Error al cambiar el estado del participante');
    }
}

/**
 * GET participant detail with full history
 * 
 * @since 1.6.0
 */
function wp_ajax_eipsi_get_participant_detail_handler() {
    check_ajax_referer('eipsi_study_dashboard_nonce', 'nonce');

    if (!eipsi_user_can_manage_longitudinal()) {
        wp_send_json_error('Unauthorized');
    }

    $participant_id = isset($_GET['participant_id']) ? intval($_GET['participant_id']) : 0;
    if (empty($participant_id)) {
        wp_send_json_error('Missing participant ID');
    }

    // Load participant service
    if (!class_exists('EIPSI_Participant_Service')) {
        require_once plugin_dir_path(__FILE__) . 'services/class-participant-service.php';
    }

    // Get participant basic info
    $participant = EIPSI_Participant_Service::get_by_id($participant_id);
    if (!$participant) {
        wp_send_json_error('Participant not found');
    }

    // Get wave completions
    $wave_completions = EIPSI_Participant_Service::get_wave_completions($participant_id, $participant->survey_id);

    // Get magic link history
    $magic_link_history = EIPSI_Participant_Service::get_magic_link_history($participant_id);

    // Check active session
    $has_active_session = EIPSI_Participant_Service::has_active_session($participant_id);

    // Get study info
    global $wpdb;
    $study = $wpdb->get_row($wpdb->prepare(
        "SELECT id, study_name, study_code FROM {$wpdb->prefix}survey_studies WHERE id = %d",
        $participant->survey_id
    ));

    // Build timeline
    $timeline = array();

    // 1. Registration event
    $timeline[] = array(
        'event' => 'registered',
        'label' => __('Registrado', 'eipsi-forms'),
        'date' => $participant->created_at,
        'status' => 'completed',
        'icon' => '📝'
    );

    // 2. Last login event
    if (!empty($participant->last_login_at)) {
        $timeline[] = array(
            'event' => 'last_login',
            'label' => __('Último acceso', 'eipsi-forms'),
            'date' => $participant->last_login_at,
            'status' => 'info',
            'icon' => '🔐'
        );
    }

    // 3. Wave events
    foreach ($wave_completions as $wave) {
        $wave_status = 'pending';
        $wave_icon = '⏳';
        
        if ($wave->status === 'submitted') {
            $wave_status = 'completed';
            $wave_icon = '✅';
        } elseif ($wave->status === 'in_progress') {
            $wave_status = 'in_progress';
            $wave_icon = '🔄';
        } elseif (!empty($wave->started_at)) {
            $wave_status = 'started';
            $wave_icon = '▶️';
        }

        $timeline[] = array(
            'event' => 'wave_' . $wave->wave_index,
            'label' => sprintf(__('Wave %d: %s', 'eipsi-forms'), $wave->wave_index, $wave->wave_name),
            'date' => !empty($wave->completed_at) ? $wave->completed_at : (!empty($wave->started_at) ? $wave->started_at : null),
            'status' => $wave_status,
            'icon' => $wave_icon,
            'wave_name' => $wave->wave_name,
            'wave_index' => $wave->wave_index,
            'form_title' => $wave->form_title
        );
    }

    wp_send_json_success(array(
        'participant' => $participant,
        'study' => $study,
        'wave_completions' => $wave_completions,
        'magic_link_history' => $magic_link_history,
        'has_active_session' => $has_active_session,
        'timeline' => $timeline
    ));
}

/**
 * POST remove participant (deactivate - soft delete)
 * 
 * @since 1.6.0
 */
function wp_ajax_eipsi_remove_participant_handler() {
    check_ajax_referer('eipsi_study_dashboard_nonce', 'nonce');

    if (!eipsi_user_can_manage_longitudinal()) {
        wp_send_json_error('Unauthorized');
    }

    $participant_id = isset($_POST['participant_id']) ? intval($_POST['participant_id']) : 0;
    $reason = isset($_POST['reason']) ? sanitize_text_field($_POST['reason']) : '';

    if (empty($participant_id)) {
        wp_send_json_error(array('message' => 'Missing participant ID'));
    }

    // Load participant service
    if (!class_exists('EIPSI_Participant_Service')) {
        require_once plugin_dir_path(__FILE__) . 'services/class-participant-service.php';
    }

    // Get participant info for response
    $participant = EIPSI_Participant_Service::get_by_id($participant_id);
    if (!$participant) {
        wp_send_json_error(array('message' => 'Participant not found'));
    }

    // Deactivate (soft delete)
    $success = EIPSI_Participant_Service::deactivate($participant_id, $reason);

    if ($success) {
        wp_send_json_success(array(
            'message' => sprintf(__('Participante "%s" ha sido desactivado. Su historial se ha conservado.', 'eipsi-forms'), $participant->email),
            'action' => 'deactivated',
            'participant_id' => $participant_id
        ));
    } else {
        wp_send_json_error(array('message' => __('Error al desactivar el participante.', 'eipsi-forms')));
    }
}

/**
 * POST delete participant (hard delete)
 * 
 * @since 1.6.0
 */
function wp_ajax_eipsi_delete_participant_handler() {
    check_ajax_referer('eipsi_study_dashboard_nonce', 'nonce');

    if (!eipsi_user_can_manage_longitudinal()) {
        wp_send_json_error('Unauthorized');
    }

    $participant_id = isset($_POST['participant_id']) ? intval($_POST['participant_id']) : 0;
    $reason = isset($_POST['reason']) ? sanitize_text_field($_POST['reason']) : '';

    if (empty($participant_id)) {
        wp_send_json_error(array('message' => 'Missing participant ID'));
    }

    // Load participant service
    if (!class_exists('EIPSI_Participant_Service')) {
        require_once plugin_dir_path(__FILE__) . 'services/class-participant-service.php';
    }

    // Get participant info for response
    $participant = EIPSI_Participant_Service::get_by_id($participant_id);
    if (!$participant) {
        wp_send_json_error(array('message' => 'Participant not found'));
    }

    // Hard delete - now returns array with details
    $result = EIPSI_Participant_Service::hard_delete($participant_id, $reason);

    if ($result['success']) {
        $deleted_count = count($result['deleted']);
        $anonymized_count = count($result['anonymized']);
        
        $message = sprintf(
            __('Participante "%s" ha sido eliminado. %d tablas purgadas, %d tablas anonimizadas (logs de auditoría preservados).', 'eipsi-forms'),
            $participant->email,
            $deleted_count,
            $anonymized_count
        );
        
        wp_send_json_success(array(
            'message' => $message,
            'action' => 'deleted',
            'participant_id' => $participant_id,
            'details' => array(
                'deleted_tables' => array_keys($result['deleted']),
                'anonymized_tables' => array_keys($result['anonymized'])
            )
        ));
    } else {
        wp_send_json_error(array(
            'message' => __('Error al eliminar el participante.', 'eipsi-forms'),
            'details' => $result['errors']
        ));
    }
}

/**
 * POST save study cron configuration
 */
function wp_ajax_eipsi_save_study_cron_config_handler() {
    check_ajax_referer('eipsi_study_cron_nonce', 'nonce');

    if (!eipsi_user_can_manage_longitudinal()) {
        wp_send_json_error('Unauthorized');
    }

    $study_id = isset($_POST['study_id']) ? intval($_POST['study_id']) : 0;
    if (empty($study_id)) {
        wp_send_json_error('Missing study ID');
    }


    EIPSI_Form_Response::emit(EIPSI_Longitudinal_Study_Config_Service::wp_ajax_eipsi_save_study_cron_config_handler($study_id,$_POST,$_GET));
}

/**
 * GET study cron configuration HTML
 */
function wp_ajax_eipsi_get_study_cron_config_handler() {
    check_ajax_referer('eipsi_study_dashboard_nonce', 'nonce');

    if (!eipsi_user_can_manage_longitudinal()) {
        wp_send_json_error('Unauthorized');
    }

    $study_id = isset($_GET['study_id']) ? intval($_GET['study_id']) : 0;
    if (empty($study_id)) {
        wp_send_json_error('Missing study ID');
    }


    EIPSI_Form_Response::emit(EIPSI_Longitudinal_Study_Config_Service::wp_ajax_eipsi_get_study_cron_config_handler($study_id,$_POST,$_GET));
}

/**
 * POST save study settings
 */
function wp_ajax_eipsi_save_study_settings_handler() {
    check_ajax_referer('eipsi_study_dashboard_nonce', 'nonce');

    if (!eipsi_user_can_manage_longitudinal()) {
        wp_send_json_error('Unauthorized');
    }

    $study_id = isset($_POST['study_id']) ? intval($_POST['study_id']) : 0;
    if (empty($study_id)) {
        wp_send_json_error('Missing study ID');
    }


    EIPSI_Form_Response::emit(EIPSI_Longitudinal_Study_Config_Service::wp_ajax_eipsi_save_study_settings_handler($study_id,$_POST,$_GET));
}

// ============================================================================
// MAGIC LINK HANDLERS (v1.7.0)
// ============================================================================

/**
 * POST generate magic link from Study Dashboard (email only).
 *
 * Este handler permite generar un magic link para un participante
 * usando solo su email. Si el participante no existe, lo crea.
 *
 * @since 1.7.0
 */
function wp_ajax_eipsi_generate_magic_link_handler() {
    check_ajax_referer('eipsi_study_dashboard_nonce', 'nonce');

    if (!eipsi_user_can_manage_longitudinal()) {
        wp_send_json_error('Unauthorized');
    }

    $study_id = isset($_POST['study_id']) ? intval($_POST['study_id']) : 0;
    $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';

    if (empty($study_id)) {
        wp_send_json_error(array('message' => 'Missing study ID'));
    }

    if (empty($email) || !is_email($email)) {
        wp_send_json_error(array('message' => 'Email inválido'));
    }

    // Load services
    if (!class_exists('EIPSI_Participant_Service')) {
        require_once plugin_dir_path(__FILE__) . 'services/class-participant-service.php';
    }

    if (!class_exists('EIPSI_Email_Service')) {
        require_once plugin_dir_path(__FILE__) . 'services/class-email-service.php';
    }

    // Create or get participant (email only)
    $participant_result = EIPSI_Participant_Service::create_or_get_for_magic_link($study_id, $email);

    if (!$participant_result['success']) {
        $error_messages = array(
            'invalid_email' => 'El formato del email es inválido',
            'db_error' => 'Error al crear/obtener el participante'
        );
        wp_send_json_error(array(
            'message' => $error_messages[$participant_result['error']] ?? 'Error desconocido'
        ));
    }

    $participant_id = $participant_result['participant_id'];

    // Send magic link email
    $email_result = EIPSI_Email_Service::send_magic_link_email($study_id, $participant_id);

    if ($email_result['success']) {
        wp_send_json_success(array(
            'message' => $participant_result['is_new']
                ? 'Participante creado y Magic Link enviado exitosamente'
                : 'Magic Link enviado exitosamente',
            'participant_id' => $participant_id,
            'email' => $email,
            'is_new' => $participant_result['is_new'],
            'magic_link' => $email_result['magic_link']
        ));
    } else {
        wp_send_json_success(array(
            'message' => 'Participante creado/obtenido, pero hubo un problema enviando el Magic Link',
            'participant_id' => $participant_id,
            'email' => $email,
            'is_new' => $participant_result['is_new'],
            'error' => $email_result['error']
        ));
    }
}

/**
 * POST send magic link to existing participant.
 * Also accepts 'email' parameter to create/get participant if needed.
 *
 * @since 1.7.0
 * @fix v1.7.x - Added email support to match frontend behavior
 */
function wp_ajax_eipsi_send_magic_link_handler() {
    check_ajax_referer('eipsi_study_dashboard_nonce', 'nonce');

    if (!eipsi_user_can_manage_longitudinal()) {
        wp_send_json_error('Unauthorized');
    }

    $study_id = isset($_POST['study_id']) ? intval($_POST['study_id']) : 0;
    $participant_id = isset($_POST['participant_id']) ? intval($_POST['participant_id']) : 0;
    $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
    $custom_message = isset($_POST['custom_message']) ? sanitize_textarea_field($_POST['custom_message']) : '';

    if (empty($study_id)) {
        wp_send_json_error(array('message' => 'Missing study ID'));
    }

    // If no participant_id but we have email, create/get participant
    if (empty($participant_id) && !empty($email)) {
        if (!is_email($email)) {
            wp_send_json_error(array('message' => 'Email inválido'));
        }

        // Load services
        if (!class_exists('EIPSI_Participant_Service')) {
            require_once plugin_dir_path(__FILE__) . 'services/class-participant-service.php';
        }

        if (!class_exists('EIPSI_Email_Service')) {
            require_once plugin_dir_path(__FILE__) . 'services/class-email-service.php';
        }

        // Create or get participant (email only)
        $participant_result = EIPSI_Participant_Service::create_or_get_for_magic_link($study_id, $email);

        if (!$participant_result['success']) {
            wp_send_json_error(array(
                'message' => 'Error al obtener el participante: ' . ($participant_result['error'] ?? 'desconocido')
            ));
        }

        $participant_id = $participant_result['participant_id'];
    }

    if (empty($participant_id)) {
        wp_send_json_error(array('message' => 'Se requiere participant_id o email'));
    }

    // Load Email Service
    if (!class_exists('EIPSI_Email_Service')) {
        require_once plugin_dir_path(__FILE__) . 'services/class-email-service.php';
    }

    // Check if double opt-in is enabled and participant needs confirmation
    $double_optin_enabled = defined('EIPSI_DOUBLE_OPTIN_ENABLED') ? EIPSI_DOUBLE_OPTIN_ENABLED : true;
    
    if ($double_optin_enabled) {
        // Load participant service to check status
        if (!class_exists('EIPSI_Participant_Service')) {
            require_once plugin_dir_path(__FILE__) . 'services/class-participant-service.php';
        }
        
        // Check if participant is active (confirmed) or still pending
        global $wpdb;
        $participant = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}survey_participants WHERE id = %d",
            $participant_id
        ));
        
        if ($participant && !$participant->is_active) {
            // Participant not confirmed - send confirmation instead
            if (!class_exists('EIPSI_Email_Confirmation_Service')) {
                require_once plugin_dir_path(__FILE__) . 'services/class-email-confirmation-service.php';
            }
            
            $resend_result = EIPSI_Email_Confirmation_Service::resend_confirmation_email($participant_id);
            
            if ($resend_result['success']) {
                wp_send_json_error(array(
                    'message' => 'El participante aún no ha confirmado su email. Se ha reenviado el email de confirmación.',
                    'needs_confirmation' => true
                ));
            } else {
                wp_send_json_error(array(
                    'message' => 'El participante aún no ha confirmado su email y no se pudo reenviar el correo de confirmación.',
                    'needs_confirmation' => true,
                    'error' => $resend_result['error']
                ));
            }
            return;
        }
    }

    $result = EIPSI_Email_Service::send_magic_link_email($study_id, $participant_id, $custom_message);

    if ($result['success']) {
        wp_send_json_success(array(
            'message' => 'Magic Link enviado exitosamente',
            'magic_link' => $result['magic_link']
        ));
    } else {
        wp_send_json_error(array(
            'message' => 'Error al enviar el Magic Link',
            'error' => $result['error']
        ));
    }
}

/**
 * GET magic link email preview.
 *
 * @since 1.7.0
 */
function wp_ajax_eipsi_get_magic_link_preview_handler() {
    check_ajax_referer('eipsi_study_dashboard_nonce', 'nonce');

    if (!eipsi_user_can_manage_longitudinal()) {
        wp_send_json_error('Unauthorized');
    }

    $study_id = isset($_GET['study_id']) ? intval($_GET['study_id']) : 0;
    $participant_id = isset($_GET['participant_id']) ? intval($_GET['participant_id']) : 0;

    if (empty($study_id) || empty($participant_id)) {
        wp_send_json_error(array('message' => 'Missing study ID or participant ID'));
    }

    // Load Email Service
    if (!class_exists('EIPSI_Email_Service')) {
        require_once plugin_dir_path(__FILE__) . 'services/class-email-service.php';
    }

    $preview = EIPSI_Email_Service::get_magic_link_preview($study_id, $participant_id);

    if ($preview['success']) {
        wp_send_json_success($preview);
    } else {
        wp_send_json_error(array('message' => $preview['message']));
    }
}

/**
 * POST resend magic link to participant.
 *
 * @since 1.7.0
 */
function wp_ajax_eipsi_resend_magic_link_handler() {
    check_ajax_referer('eipsi_study_dashboard_nonce', 'nonce');

    if (!eipsi_user_can_manage_longitudinal()) {
        wp_send_json_error('Unauthorized');
    }

    $study_id = isset($_POST['study_id']) ? intval($_POST['study_id']) : 0;
    $participant_id = isset($_POST['participant_id']) ? intval($_POST['participant_id']) : 0;

    if (empty($study_id) || empty($participant_id)) {
        wp_send_json_error(array('message' => 'Missing study ID or participant ID'));
    }

    // Load Email Service
    if (!class_exists('EIPSI_Email_Service')) {
        require_once plugin_dir_path(__FILE__) . 'services/class-email-service.php';
    }

    $result = EIPSI_Email_Service::send_magic_link_email($study_id, $participant_id);

    if ($result['success']) {
        wp_send_json_success(array(
            'message' => 'Magic Link reenviado exitosamente',
            'magic_link' => $result['magic_link']
        ));
    } else {
        wp_send_json_error(array(
            'message' => 'Error al reenviar el Magic Link',
            'error' => $result['error']
        ));
    }
}

/**
 * POST extend magic link expiry.
 *
 * @since 1.7.0
 */
function wp_ajax_eipsi_extend_magic_link_handler() {
    check_ajax_referer('eipsi_study_dashboard_nonce', 'nonce');

    if (!eipsi_user_can_manage_longitudinal()) {
        wp_send_json_error('Unauthorized');
    }

    $study_id = isset($_POST['study_id']) ? intval($_POST['study_id']) : 0;
    $participant_id = isset($_POST['participant_id']) ? intval($_POST['participant_id']) : 0;
    $hours = isset($_POST['hours']) ? intval($_POST['hours']) : 48;

    if (empty($study_id) || empty($participant_id)) {
        wp_send_json_error(array('message' => 'Missing study ID or participant ID'));
    }

    // Validate hours (max 168 = 7 days)
    $hours = max(1, min(168, $hours));

    // Load Email Service
    if (!class_exists('EIPSI_Email_Service')) {
        require_once plugin_dir_path(__FILE__) . 'services/class-email-service.php';
    }

    $result = EIPSI_Email_Service::extend_magic_link_expiry($study_id, $participant_id, $hours);

    if ($result['success']) {
        wp_send_json_success(array(
            'message' => sprintf('Magic Link extendido %d horas', $hours),
            'expires_at' => $result['expires_at']
        ));
    } else {
        wp_send_json_error(array('message' => $result['message']));
    }
}

// ============================================================================
// DOUBLE OPT-IN HANDLERS (v1.5.0)
// ============================================================================

/**
 * POST resend confirmation email to participant
 * 
 * @since 1.5.0
 */
function wp_ajax_eipsi_resend_confirmation_handler() {
    check_ajax_referer('eipsi_study_dashboard_nonce', 'nonce');

    if (!eipsi_user_can_manage_longitudinal()) {
        wp_send_json_error(array('message' => 'Unauthorized'));
    }

    $study_id = isset($_POST['study_id']) ? intval($_POST['study_id']) : 0;
    $participant_id = isset($_POST['participant_id']) ? intval($_POST['participant_id']) : 0;

    if (empty($study_id) || empty($participant_id)) {
        wp_send_json_error(array('message' => 'Missing study ID or participant ID'));
    }

    // Load required services
    if (!class_exists('EIPSI_Email_Confirmation_Service')) {
        require_once plugin_dir_path(__FILE__) . 'services/class-email-confirmation-service.php';
    }

    if (!class_exists('EIPSI_Participant_Service')) {
        require_once plugin_dir_path(__FILE__) . 'services/class-participant-service.php';
    }

    // Verify participant exists and belongs to study
    $participant = EIPSI_Participant_Service::get_by_id($participant_id);
    if (!$participant) {
        wp_send_json_error(array('message' => 'Participante no encontrado'));
    }

    if ($participant->survey_id != $study_id) {
        wp_send_json_error(array('message' => 'El participante no pertenece a este estudio'));
    }

    // Check if already confirmed
    if ($participant->is_active && EIPSI_Email_Confirmation_Service::is_confirmed($participant_id)) {
        wp_send_json_error(array('message' => 'El participante ya ha confirmado su email'));
    }

    // Resend confirmation
    $result = EIPSI_Email_Confirmation_Service::resend_confirmation_email($participant_id);

    if ($result['success']) {
        wp_send_json_success(array(
            'message' => sprintf('Email de confirmación reenviado a %s', $participant->email),
            'participant_id' => $participant_id,
            'email' => $participant->email
        ));
    } else {
        $error_messages = array(
            'participant_not_found' => 'Participante no encontrado',
            'already_confirmed' => 'El participante ya ha confirmado su email',
            'db_error' => 'Error de base de datos al generar el token',
            'email_send_failed' => 'No se pudo enviar el email de confirmación'
        );
        wp_send_json_error(array(
            'message' => $error_messages[$result['error']] ?? 'Error al reenviar el email de confirmación',
            'error_code' => $result['error']
        ));
    }
}

/**
 * GET list of pending confirmations for a study
 * 
 * @since 1.5.0
 */
function wp_ajax_eipsi_get_pending_confirmations_handler() {
    check_ajax_referer('eipsi_study_dashboard_nonce', 'nonce');

    if (!eipsi_user_can_manage_longitudinal()) {
        wp_send_json_error(array('message' => 'Unauthorized'));
    }

    $study_id = isset($_GET['study_id']) ? intval($_GET['study_id']) : 0;
    if (empty($study_id)) {
        wp_send_json_error(array('message' => 'Missing study ID'));
    }

    global $wpdb;

    // Get participants with pending confirmations
    $participants_table = $wpdb->prefix . 'survey_participants';
    $confirmations_table = $wpdb->prefix . 'survey_email_confirmations';

    $pending = $wpdb->get_results($wpdb->prepare(
        "SELECT 
            p.id as participant_id,
            p.email,
            p.first_name,
            p.last_name,
            p.created_at as registered_at,
            c.token_plain,
            c.expires_at,
            c.created_at as confirmation_sent_at
         FROM {$participants_table} p
         INNER JOIN {$confirmations_table} c ON p.id = c.participant_id
         WHERE p.survey_id = %d 
           AND p.is_active = 0
           AND c.confirmed_at IS NULL
           AND c.expires_at > %s
         ORDER BY c.created_at DESC",
        $study_id,
        current_time('mysql')
    ));

    // Calculate time remaining for each
    $now = current_time('timestamp');
    foreach ($pending as $item) {
        $expires = strtotime($item->expires_at);
        $hours_remaining = max(0, round(($expires - $now) / HOUR_IN_SECONDS));
        $item->hours_remaining = $hours_remaining;
        $item->expires_soon = $hours_remaining < 24;
    }

    // Get summary stats
    $total_pending = count($pending);
    $expiring_soon = count(array_filter($pending, function($p) { return $p->expires_soon; }));

    wp_send_json_success(array(
        'pending' => $pending,
        'summary' => array(
            'total_pending' => $total_pending,
            'expiring_soon' => $expiring_soon,
            'hours_threshold' => 24
        )
    ));
}

// ============================================================================
// STUDY PAGE HANDLERS (v1.7.0)
// ============================================================================

/**
 * POST create study page
 *
 * Creates a WordPress page for the study if it doesn't exist.
 *
 * @since 1.7.0
 */
add_action('wp_ajax_eipsi_create_study_page', 'wp_ajax_eipsi_create_study_page_handler');

function wp_ajax_eipsi_create_study_page_handler() {
    check_ajax_referer('eipsi_study_dashboard_nonce', 'nonce');

    if (!eipsi_user_can_manage_longitudinal()) {
        wp_send_json_error('Unauthorized');
    }

    $study_id = isset($_POST['study_id']) ? intval($_POST['study_id']) : 0;
    if (empty($study_id)) {
        wp_send_json_error(array('message' => 'Missing study ID'));
    }

    global $wpdb;

    // Get study info
    $study = $wpdb->get_row($wpdb->prepare(
        "SELECT id, study_code, study_name FROM {$wpdb->prefix}survey_studies WHERE id = %d",
        $study_id
    ));

    if (!$study) {
        wp_send_json_error(array('message' => 'Study not found'));
    }

    // Generate study code if not set
    if (!$study->study_code) {
        $study->study_code = 'STUDY_' . $study_id;
    }
    
    // T1-Anchor: ensure study_end_offset_minutes is included
    if (!isset($study->study_end_offset_minutes)) {
        $study->study_end_offset_minutes = 0;
    }

    // Check if function exists
    if (!function_exists('eipsi_create_study_page')) {
        require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/setup-wizard.php';
    }

    // Create the page
    $page_id = eipsi_create_study_page($study_id, $study->study_code, $study->study_name ?? 'Estudio');

    if (!$page_id) {
        wp_send_json_error(array('message' => 'Failed to create study page'));
    }

    wp_send_json_success(array(
        'message' => 'Página del estudio creada correctamente',
        'page_id' => $page_id,
        'page_url' => get_permalink($page_id)
    ));
}

/**
 * Test handler WITHOUT nonce verification
 */
function wp_ajax_eipsi_test_no_nonce_handler() {
    error_log('[EIPSI TEST NO NONCE] Handler called - NO nonce check');
    wp_send_json_success(array('message' => 'Handler works without nonce check'));
}

/**
 * Test AJAX handler to verify registration
 */
function wp_ajax_eipsi_test_deadline_handler() {
    error_log('[EIPSI TEST] Test deadline handler called!');
    error_log('[EIPSI TEST] POST data: ' . print_r($_POST, true));
    wp_send_json_success(array('message' => 'Test handler works!'));
}
