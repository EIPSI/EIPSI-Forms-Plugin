<?php
/**
 * AJAX Handlers for Email Log & Dropout Management
 *
 * @package EIPSI_Forms
 * @since 1.5.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// ============================================================================
// EMAIL LOG & DROPOUT MANAGEMENT HANDLERS (Task 4.3)
// ============================================================================

/**
 * Get email logs for dashboard
 *
 * @since 1.5.0
 */
add_action('wp_ajax_eipsi_get_email_logs', 'eipsi_ajax_get_email_logs');

function eipsi_ajax_get_email_logs() {
    check_ajax_referer('eipsi_admin_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Permisos insuficientes', 'eipsi-forms'), 403);
    }

    if (!class_exists('EIPSI_Email_Service')) {
        require_once plugin_dir_path(__FILE__) . 'services/class-email-service.php';
    }

    $survey_id = isset($_POST['survey_id']) ? intval($_POST['survey_id']) : 0;
    $filters = isset($_POST['filters']) ? $_POST['filters'] : array();
    $limit = isset($_POST['limit']) ? intval($_POST['limit']) : 20;
    $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;

    // Sanitize filters
    if (!is_array($filters)) {
        $filters = array();
    }

    $allowed_filters = array('type', 'status', 'date_from', 'date_to');
    $sanitized_filters = array();
    foreach ($allowed_filters as $filter_key) {
        if (isset($filters[$filter_key])) {
            $sanitized_filters[$filter_key] = sanitize_text_field($filters[$filter_key]);
        }
    }

    try {
        $result = EIPSI_Email_Service::get_email_log_entries($survey_id, $sanitized_filters, $limit, $offset);
        wp_send_json_success($result);
    } catch (Exception $e) {
        EIPSI_Security_Log::event('EMAIL_LOG_OPERATION_FAILED');
        wp_send_json_error(__('Error al cargar los logs de email: ', 'eipsi-forms') . $e->getMessage(), 500);
    }
}

/**
 * Get email details
 *
 * @since 1.5.0
 */
add_action('wp_ajax_eipsi_get_email_details', 'eipsi_ajax_get_email_details');

function eipsi_ajax_get_email_details() {
    check_ajax_referer('eipsi_admin_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Permisos insuficientes', 'eipsi-forms'), 403);
    }

    if (!class_exists('EIPSI_Email_Service')) {
        require_once plugin_dir_path(__FILE__) . 'services/class-email-service.php';
    }

    $email_log_id = isset($_POST['email_log_id']) ? intval($_POST['email_log_id']) : 0;

    if (!$email_log_id) {
        wp_send_json_error(__('ID de email inválido', 'eipsi-forms'));
    }

    $email = EIPSI_Email_Service::get_email_details($email_log_id);

    if (!$email) {
        wp_send_json_error(__('Email no encontrado', 'eipsi-forms'));
    }

    wp_send_json_success($email);
}

/**
 * Resend email
 *
 * @since 1.5.0
 */
add_action('wp_ajax_eipsi_resend_email', 'eipsi_ajax_resend_email');

function eipsi_ajax_resend_email() {
    check_ajax_referer('eipsi_admin_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Permisos insuficientes', 'eipsi-forms'), 403);
    }

    if (!class_exists('EIPSI_Email_Service')) {
        require_once plugin_dir_path(__FILE__) . 'services/class-email-service.php';
    }

    $email_log_id = isset($_POST['email_log_id']) ? intval($_POST['email_log_id']) : 0;

    if (!$email_log_id) {
        wp_send_json_error(__('ID de email inválido', 'eipsi-forms'));
    }

    $result = EIPSI_Email_Service::resend_email($email_log_id);

    if ($result['success']) {
        wp_send_json_success(array(
            'message' => __('Email reenviado exitosamente', 'eipsi-forms'),
            'new_log_id' => $result['new_log_id']
        ));
    } else {
        wp_send_json_error($result['message']);
    }
}

/**
 * Export email logs to CSV
 *
 * @since 1.5.0
 */
add_action('wp_ajax_eipsi_email_log_export', 'eipsi_ajax_email_log_export');
add_action('wp_ajax_nopriv_eipsi_email_log_export', 'eipsi_ajax_email_log_export');

function eipsi_ajax_email_log_export() {
    // Verify nonce first - use wp_send_json_error for AJAX handlers
    $nonce = isset($_GET['nonce']) ? sanitize_text_field($_GET['nonce']) : '';
    if (!wp_verify_nonce($nonce, 'eipsi_admin_nonce')) {
        status_header(403);
        echo wp_json_encode(array('success' => false, 'message' => __('Error de seguridad', 'eipsi-forms')));
        exit;
    }

    // Check permissions before any output
    if (!current_user_can('manage_options')) {
        status_header(403);
        echo wp_json_encode(array('success' => false, 'message' => __('Permisos insuficientes', 'eipsi-forms')));
        exit;
    }

    if (!class_exists('EIPSI_Email_Service')) {
        require_once plugin_dir_path(__FILE__) . 'services/class-email-service.php';
    }

    $survey_id = isset($_GET['survey_id']) ? intval($_GET['survey_id']) : 0;
    $filters = isset($_GET['filters']) ? json_decode(stripslashes($_GET['filters']), true) : array();

    // Get all logs (no limit)
    $result = EIPSI_Email_Service::get_email_log_entries($survey_id, $filters, 10000, 0);

    if (empty($result['logs'])) {
        status_header(400);
        echo wp_json_encode(array('success' => false, 'message' => __('No hay emails para exportar', 'eipsi-forms')));
        exit;
    }

    // Clear any existing output buffer to prevent header issues
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    // Generate CSV
    $filename = 'eipsi-email-log-' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, must-revalidate');
    header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');

    $output = fopen('php://output', 'w');

    // CSV headers
    EIPSI_Csv_Serializer::row($output, array(
        'ID',
        'Fecha',
        'Tipo',
        'Participante',
        'Email',
        'Estado',
        'Error'
    ));

    // CSV rows
    foreach ($result['logs'] as $log) {
        EIPSI_Csv_Serializer::row($output, array(
            $log->id,
            $log->sent_at,
            $log->email_type,
            $log->participant_name,
            $log->recipient_email,
            $log->status,
            $log->error_message
        ));
    }

    fclose($output);
    exit;
}

/**
 * Get at-risk participants (Dropout Management)
 *
 * @since 1.5.0
 */
add_action('wp_ajax_eipsi_get_at_risk_participants', 'eipsi_ajax_get_at_risk_participants');

function eipsi_ajax_get_at_risk_participants() {
    check_ajax_referer('eipsi_admin_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Permisos insuficientes', 'eipsi-forms'), 403);
    }

    if (!class_exists('EIPSI_Assignment_Service')) {
        require_once plugin_dir_path(__FILE__) . 'services/class-assignment-service.php';
    }

    $survey_id = isset($_POST['survey_id']) ? intval($_POST['survey_id']) : 0;
    $days_overdue = isset($_POST['days_overdue']) ? intval($_POST['days_overdue']) : 7;

    $participants = EIPSI_Assignment_Service::get_at_risk_participants($survey_id, $days_overdue);
    $stats = EIPSI_Assignment_Service::get_dropout_stats($survey_id, $days_overdue);

    wp_send_json_success(array(
        'participants' => $participants,
        'stats' => $stats
    ));
}

/**
 * Send dropout reminder
 *
 * @since 1.5.0
 */
add_action('wp_ajax_eipsi_send_dropout_reminder', 'eipsi_ajax_send_dropout_reminder');

function eipsi_ajax_send_dropout_reminder() {
    check_ajax_referer('eipsi_admin_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Permisos insuficientes', 'eipsi-forms'), 403);
    }

    $participant_id = isset($_POST['participant_id']) ? intval($_POST['participant_id']) : 0;
    $wave_id = isset($_POST['wave_id']) ? intval($_POST['wave_id']) : 0;

    if (!$participant_id || !$wave_id) {
        wp_send_json_error(__('Parámetros inválidos', 'eipsi-forms'));
    }

    // Get assignment info
    if (!class_exists('EIPSI_Assignment_Service')) {
        require_once plugin_dir_path(__FILE__) . 'services/class-assignment-service.php';
    }
    $assignment = EIPSI_Assignment_Service::get_assignment($wave_id, $participant_id);

    if (!$assignment) {
        wp_send_json_error(__('Asignación no encontrada', 'eipsi-forms'));
    }

    // Load services
    if (!class_exists('EIPSI_Email_Service')) {
        require_once plugin_dir_path(__FILE__) . 'services/class-email-service.php';
    }

    if (!class_exists('EIPSI_Wave_Service')) {
        require_once plugin_dir_path(__FILE__) . 'services/class-wave-service.php';
    }

    // Get wave details
    $wave = EIPSI_Wave_Service::get_wave($wave_id);

    if (!$wave) {
        wp_send_json_error(__('Wave no encontrada', 'eipsi-forms'));
    }

    // Send recovery email
    $result = EIPSI_Email_Service::send_dropout_recovery_email(
        $assignment['study_id'],
        $participant_id,
        $wave
    );

    if ($result) {
        // Increment reminder count
        EIPSI_Assignment_Service::increment_reminder_count($wave_id, $participant_id);

        wp_send_json_success(__('Recordatorio enviado exitosamente', 'eipsi-forms'));
    } else {
        wp_send_json_error(__('Error al enviar recordatorio', 'eipsi-forms'));
    }
}

/**
 * Extend wave deadline
 *
 * @since 1.5.0
 * @deprecated Conflicto con study-dashboard-api.php - Comentado temporalmente
 */
// add_action('wp_ajax_eipsi_extend_wave_deadline', 'eipsi_ajax_extend_wave_deadline');

function eipsi_ajax_extend_wave_deadline_DEPRECATED() {
    check_ajax_referer('eipsi_admin_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Permisos insuficientes', 'eipsi-forms'), 403);
    }

    if (!class_exists('EIPSI_Assignment_Service')) {
        require_once plugin_dir_path(__FILE__) . 'services/class-assignment-service.php';
    }

    $assignment_id = isset($_POST['assignment_id']) ? intval($_POST['assignment_id']) : 0;
    $days = isset($_POST['days']) ? intval($_POST['days']) : 7;

    if (!$assignment_id || $days < 1) {
        wp_send_json_error(__('Parámetros inválidos', 'eipsi-forms'));
    }

    $result = EIPSI_Assignment_Service::extend_wave_deadline($assignment_id, $days);

    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message());
    }

    wp_send_json_success(__('Vencimiento extendido exitosamente', 'eipsi-forms'));
}

/**
 * Mark wave as completed (manual override)
 *
 * @since 1.5.0
 */
add_action('wp_ajax_eipsi_mark_wave_completed', 'eipsi_ajax_mark_wave_completed');

function eipsi_ajax_mark_wave_completed() {
    check_ajax_referer('eipsi_admin_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Permisos insuficientes', 'eipsi-forms'), 403);
    }

    if (!class_exists('EIPSI_Assignment_Service')) {
        require_once plugin_dir_path(__FILE__) . 'services/class-assignment-service.php';
    }

    $assignment_id = isset($_POST['assignment_id']) ? intval($_POST['assignment_id']) : 0;

    if (!$assignment_id) {
        wp_send_json_error(__('ID de asignación inválido', 'eipsi-forms'));
    }

    $result = EIPSI_Assignment_Service::mark_wave_completed($assignment_id);

    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message());
    }

    wp_send_json_success(__('Toma marcada como completada', 'eipsi-forms'));
}

/**
 * Deactivate participant
 *
 * @since 1.5.0
 */
add_action('wp_ajax_eipsi_deactivate_participant', 'eipsi_ajax_deactivate_participant');

function eipsi_ajax_deactivate_participant() {
    check_ajax_referer('eipsi_admin_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Permisos insuficientes', 'eipsi-forms'), 403);
    }

    if (!class_exists('EIPSI_Assignment_Service')) {
        require_once plugin_dir_path(__FILE__) . 'services/class-assignment-service.php';
    }

    $participant_id = isset($_POST['participant_id']) ? intval($_POST['participant_id']) : 0;

    if (!$participant_id) {
        wp_send_json_error(__('ID de participante inválido', 'eipsi-forms'));
    }

    $result = EIPSI_Assignment_Service::deactivate_participant($participant_id);

    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message());
    }

    wp_send_json_success(__('Participante desactivado exitosamente', 'eipsi-forms'));
}

/**
 * Execute bulk action on participants
 *
 * @since 1.5.0
 */
add_action('wp_ajax_eipsi_execute_bulk_action', 'eipsi_ajax_execute_bulk_action');

function eipsi_ajax_execute_bulk_action() {
    check_ajax_referer('eipsi_admin_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Permisos insuficientes', 'eipsi-forms'), 403);
    }

    if (!class_exists('EIPSI_Assignment_Service')) {
        require_once plugin_dir_path(__FILE__) . 'services/class-assignment-service.php';
    }

    if (!class_exists('EIPSI_Email_Service')) {
        require_once plugin_dir_path(__FILE__) . 'services/class-email-service.php';
    }

    if (!class_exists('EIPSI_Wave_Service')) {
        require_once plugin_dir_path(__FILE__) . 'services/class-wave-service.php';
    }

    $bulk_action = isset($_POST['bulk_action']) ? sanitize_text_field($_POST['bulk_action']) : '';
    $participant_ids = isset($_POST['participant_ids']) ? array_map('intval', (array) $_POST['participant_ids']) : array();

    if (empty($bulk_action) || empty($participant_ids)) {
        wp_send_json_error(__('Parámetros inválidos', 'eipsi-forms'));
    }

    $results = array(
        'success' => 0,
        'failed' => 0
    );

    foreach ($participant_ids as $participant_id) {
        try {
            switch ($bulk_action) {
                case 'send_reminder':
                    // Find pending assignment
                    $assignment = EIPSI_Assignment_Service::get_participant_assignments($participant_id);
                    $pending_assignment = null;
                    foreach ($assignment as $a) {
                        if ($a['status'] === 'pending') {
                            $pending_assignment = $a;
                            break;
                        }
                    }

                    if ($pending_assignment) {
                        $wave = EIPSI_Wave_Service::get_wave($pending_assignment['wave_id']);
                        if ($wave) {
                            $result = EIPSI_Email_Service::send_dropout_recovery_email(
                                $pending_assignment['study_id'],
                                $participant_id,
                                $wave
                            );
                            if ($result) {
                                EIPSI_Assignment_Service::increment_reminder_count(
                                    $pending_assignment['wave_id'],
                                    $participant_id
                                );
                                $results['success']++;
                            } else {
                                $results['failed']++;
                            }
                        } else {
                            $results['failed']++;
                        }
                    } else {
                        $results['failed']++;
                    }
                    break;

                case 'extend_7':
                case 'extend_14':
                case 'extend_30':
                    $days = (int) str_replace('extend_', '', $bulk_action);
                    $assignment = EIPSI_Assignment_Service::get_participant_assignments($participant_id);
                    $pending_assignment = null;
                    foreach ($assignment as $a) {
                        if ($a['status'] === 'pending') {
                            $pending_assignment = $a;
                            break;
                        }
                    }

                    if ($pending_assignment) {
                        $result = EIPSI_Assignment_Service::extend_wave_deadline(
                            $pending_assignment['id'],
                            $days
                        );
                        if (!is_wp_error($result)) {
                            $results['success']++;
                        } else {
                            $results['failed']++;
                        }
                    } else {
                        $results['failed']++;
                    }
                    break;

                default:
                    $results['failed']++;
            }
        } catch (Exception $e) {
            EIPSI_Security_Log::event('EMAIL_LOG_OPERATION_FAILED');
            $results['failed']++;
        }
    }

    wp_send_json_success(array(
        'message' => sprintf(
            __('Acción completada: %d exitosos, %d fallidos', 'eipsi-forms'),
            $results['success'],
            $results['failed']
        ),
        'results' => $results
    ));
}
