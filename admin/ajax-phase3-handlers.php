<?php
/**
 * AJAX Handlers for Phase 3 - Researcher Data Confidence
 *
 * @package EIPSI_Forms
 * @version 2.1.0
 * @since Phase 3
 */

if (!defined('ABSPATH')) {
    exit;
}

// ============================================================================
// ACCESS LOG EXPORT HANDLERS (Task 3A.1)
// ============================================================================

add_action('wp_ajax_eipsi_export_access_logs', 'eipsi_ajax_export_access_logs');
add_action('wp_ajax_eipsi_get_access_log_filters', 'eipsi_ajax_get_access_log_filters');
add_action('wp_ajax_eipsi_download_access_log_export', 'eipsi_ajax_download_access_log_export');

function eipsi_ajax_download_access_log_export() {
    check_ajax_referer('eipsi_admin_nonce', 'nonce');
    if (!current_user_can('manage_options')) { wp_die('No autorizado', '', array('response'=>403)); }
    $filename=isset($_GET['file']) ? sanitize_text_field(wp_unslash($_GET['file'])) : '';
    if (!preg_match('/^access-logs[a-zA-Z0-9_-]*\.(csv|xlsx)$/',$filename)) { wp_die('Archivo inválido'); }
    require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/export/bootstrap.php';
    $resolved=EIPSI_Download_Authorization_Service::resolve_admin_file($filename);
    if (!$resolved['success']) { wp_die('Archivo no disponible'); }
    $path=$resolved['file_path'];
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    header('Cache-Control: no-store');
    readfile($path);
    exit;
}

function eipsi_ajax_export_access_logs() {
    check_ajax_referer('eipsi_admin_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'No autorizado'));
    }

    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-access-log-export-service.php';

    $format = isset($_POST['format']) ? sanitize_text_field($_POST['format']) : 'csv';
    $filters = array(
        'date_from' => isset($_POST['date_from']) ? sanitize_text_field($_POST['date_from']) : '',
        'date_to' => isset($_POST['date_to']) ? sanitize_text_field($_POST['date_to']) : '',
        'study_id' => isset($_POST['study_id']) ? sanitize_text_field($_POST['study_id']) : 'all',
        'action_type' => isset($_POST['action_type']) ? sanitize_text_field($_POST['action_type']) : 'all',
        'participant_id' => isset($_POST['participant_id']) ? sanitize_text_field($_POST['participant_id']) : ''
    );

    if ($format === 'stream') {
        EIPSI_Access_Log_Export_Service::stream_access_logs_csv($filters);
        exit;
    }

    $result = EIPSI_Access_Log_Export_Service::export_access_logs($filters, $format);

    if ($result['success']) {
        wp_send_json_success(array(
            'message' => $result['message'],
            'count' => $result['count'],
            'download_url' => add_query_arg(array('action'=>'eipsi_download_access_log_export','file'=>$result['filename'],'nonce'=>wp_create_nonce('eipsi_admin_nonce')),admin_url('admin-ajax.php'))
        ));
    } else {
        wp_send_json_error(array('message' => $result['message']));
    }
}

function eipsi_ajax_get_access_log_filters() {
    check_ajax_referer('eipsi_admin_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'No autorizado'));
    }

    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-access-log-export-service.php';

    wp_send_json_success(array(
        'action_types' => EIPSI_Access_Log_Export_Service::get_action_types()
    ));
}

// ============================================================================
// COMPLETION VERIFICATION HANDLERS (Task 3A.2)
// ============================================================================

add_action('wp_ajax_eipsi_verify_completion_rates', 'eipsi_ajax_verify_completion_rates');
add_action('wp_ajax_eipsi_get_export_verification', 'eipsi_ajax_get_export_verification');

function eipsi_ajax_verify_completion_rates() {
    check_ajax_referer('eipsi_admin_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'No autorizado'));
    }

    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-completion-verification-service.php';

    $study_id = isset($_POST['study_id']) ? absint($_POST['study_id']) : 0;

    if (!$study_id) {
        wp_send_json_error(array('message' => 'ID de estudio requerido'));
    }

    $result = EIPSI_Completion_Verification_Service::verify_completion_rates($study_id);

    if ($result['success']) {
        wp_send_json_success($result['data']);
    } else {
        wp_send_json_error(array('message' => $result['error']));
    }
}

function eipsi_ajax_get_export_verification() {
    check_ajax_referer('eipsi_admin_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'No autorizado'));
    }

    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-completion-verification-service.php';

    $study_id = isset($_POST['study_id']) ? absint($_POST['study_id']) : 0;

    if (!$study_id) {
        wp_send_json_error(array('message' => 'ID de estudio requerido'));
    }

    $summary = EIPSI_Completion_Verification_Service::get_export_verification_summary($study_id);

    wp_send_json_success($summary);
}

// ============================================================================
// PARTICIPANT TIMELINE HANDLERS (Task 3B.4)
// ============================================================================

add_action('wp_ajax_eipsi_get_participant_timeline', 'eipsi_ajax_get_participant_timeline');
add_action('wp_ajax_eipsi_get_study_participants_timeline', 'eipsi_ajax_get_study_participants_timeline');

function eipsi_ajax_get_participant_timeline() {
    check_ajax_referer('eipsi_admin_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'No autorizado'));
    }

    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-participant-timeline-service.php';

    $participant_id = isset($_POST['participant_id']) ? absint($_POST['participant_id']) : 0;

    if (!$participant_id) {
        wp_send_json_error(array('message' => 'ID de participante requerido'));
    }

    $timeline = EIPSI_Participant_Timeline_Service::get_participant_timeline($participant_id);

    if ($timeline['success']) {
        wp_send_json_success($timeline);
    } else {
        wp_send_json_error(array('message' => $timeline['error']));
    }
}

function eipsi_ajax_get_study_participants_timeline() {
    check_ajax_referer('eipsi_admin_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'No autorizado'));
    }

    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-participant-timeline-service.php';

    $study_id = isset($_POST['study_id']) ? absint($_POST['study_id']) : 0;
    $filters = array(
        'status' => isset($_POST['status']) ? sanitize_text_field($_POST['status']) : '',
        'search' => isset($_POST['search']) ? sanitize_text_field($_POST['search']) : ''
    );

    if (!$study_id) {
        wp_send_json_error(array('message' => 'ID de estudio requerido'));
    }

    $result = EIPSI_Participant_Timeline_Service::get_study_participants_timeline($study_id, $filters);

    if ($result['success']) {
        wp_send_json_success($result);
    } else {
        wp_send_json_error(array('message' => $result['error']));
    }
}

// ============================================================================
// FAILED EMAIL ALERTS HANDLERS (Task 3B.5)
// ============================================================================

add_action('wp_ajax_eipsi_get_failed_email_alerts', 'eipsi_ajax_get_failed_email_alerts');
add_action('wp_ajax_eipsi_retry_failed_email', 'eipsi_ajax_retry_failed_email');
add_action('wp_ajax_eipsi_bulk_retry_emails', 'eipsi_ajax_bulk_retry_emails');
add_action('wp_ajax_eipsi_get_email_failure_summary', 'eipsi_ajax_get_email_failure_summary');

function eipsi_ajax_get_failed_email_alerts() {
    check_ajax_referer('eipsi_admin_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'No autorizado'));
    }

    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-failed-email-alerts-service.php';

    $limit = isset($_POST['limit']) ? absint($_POST['limit']) : 50;
    $study_id = isset($_POST['study_id']) ? absint($_POST['study_id']) : 0;

    $alerts = EIPSI_Failed_Email_Alerts_Service::get_failed_email_alerts($limit, $study_id);

    wp_send_json_success(array('alerts' => $alerts));
}

function eipsi_ajax_retry_failed_email() {
    check_ajax_referer('eipsi_admin_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'No autorizado'));
    }

    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-failed-email-alerts-service.php';

    $log_id = isset($_POST['log_id']) ? absint($_POST['log_id']) : 0;

    if (!$log_id) {
        wp_send_json_error(array('message' => 'ID de log requerido'));
    }

    $result = EIPSI_Failed_Email_Alerts_Service::retry_failed_email($log_id);

    if ($result['success']) {
        wp_send_json_success($result);
    } else {
        wp_send_json_error($result);
    }
}

function eipsi_ajax_bulk_retry_emails() {
    check_ajax_referer('eipsi_admin_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'No autorizado'));
    }

    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-failed-email-alerts-service.php';

    $log_ids = isset($_POST['log_ids']) ? array_map('intval', $_POST['log_ids']) : array();

    if (empty($log_ids)) {
        wp_send_json_error(array('message' => 'No se seleccionaron emails para reintentar'));
    }

    $result = EIPSI_Failed_Email_Alerts_Service::bulk_retry($log_ids);

    if ($result['success']) {
        wp_send_json_success($result);
    } else {
        wp_send_json_error($result);
    }
}

function eipsi_ajax_get_email_failure_summary() {
    check_ajax_referer('eipsi_admin_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'No autorizado'));
    }

    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-failed-email-alerts-service.php';

    $days = isset($_POST['days']) ? absint($_POST['days']) : 7;

    $summary = EIPSI_Failed_Email_Alerts_Service::get_failure_summary($days);

    wp_send_json_success($summary);
}

// ============================================================================
// CRON HEALTH HANDLERS (Task 3B.6)
// ============================================================================

add_action('wp_ajax_eipsi_get_cron_health', 'eipsi_ajax_get_cron_health');
add_action('wp_ajax_eipsi_force_run_cron', 'eipsi_ajax_force_run_cron');
add_action('wp_ajax_eipsi_reschedule_cron', 'eipsi_ajax_reschedule_cron');

function eipsi_ajax_get_cron_health() {
    check_ajax_referer('eipsi_admin_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'No autorizado'));
    }

    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-cron-health-service.php';

    $health = EIPSI_Cron_Health_Service::get_cron_health();

    wp_send_json_success($health);
}

function eipsi_ajax_force_run_cron() {
    check_ajax_referer('eipsi_admin_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'No autorizado'));
    }

    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-cron-health-service.php';

    $hook = isset($_POST['hook']) ? sanitize_text_field($_POST['hook']) : '';

    if (!$hook) {
        wp_send_json_error(array('message' => 'Hook de cron requerido'));
    }

    $result = EIPSI_Cron_Health_Service::force_run_cron($hook);

    if ($result['success']) {
        wp_send_json_success($result);
    } else {
        wp_send_json_error($result);
    }
}

function eipsi_ajax_reschedule_cron() {
    check_ajax_referer('eipsi_admin_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'No autorizado'));
    }

    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-cron-health-service.php';

    $hook = isset($_POST['hook']) ? sanitize_text_field($_POST['hook']) : '';

    if (!$hook) {
        wp_send_json_error(array('message' => 'Hook de cron requerido'));
    }

    $result = EIPSI_Cron_Health_Service::reschedule_cron($hook);

    if ($result['success']) {
        wp_send_json_success($result);
    } else {
        wp_send_json_error($result);
    }
}

// ============================================================================
// PARTICIPANT DATA REQUEST HANDLERS (Task 3C.7)
// ============================================================================

add_action('wp_ajax_eipsi_submit_data_request', 'eipsi_ajax_submit_data_request');
add_action('wp_ajax_nopriv_eipsi_submit_data_request', 'eipsi_ajax_submit_data_request');
add_action('wp_ajax_eipsi_get_data_requests', 'eipsi_ajax_get_data_requests');
add_action('wp_ajax_eipsi_process_data_request', 'eipsi_ajax_process_data_request');
add_action('wp_ajax_eipsi_get_data_request_counts', 'eipsi_ajax_get_data_request_counts');

function eipsi_ajax_submit_data_request() {
    // Verify participant nonce for security
    if (!isset($_POST['participant_nonce']) || !wp_verify_nonce($_POST['participant_nonce'], 'eipsi_participant_nonce')) {
        wp_send_json_error(array('message' => 'Token de seguridad requerido'));
    }

    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-participant-data-request-service.php';

    $participant_id = isset($_POST['participant_id']) ? absint($_POST['participant_id']) : 0;
    $request_type = isset($_POST['request_type']) ? sanitize_text_field($_POST['request_type']) : '';
    $reason = isset($_POST['reason']) ? sanitize_textarea_field($_POST['reason']) : '';

    if (!$participant_id || !$request_type) {
        wp_send_json_error(array('message' => 'Datos incompletos'));
    }

    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-auth-service.php';
    $context = EIPSI_Auth_Service::authorize_session_context($_POST);
    if (!$context['success']) { wp_send_json_error(array('message'=>'No autorizado'),403); }
    $result = EIPSI_Participant_Data_Request_Service::submit_request($context['participant_id'], $request_type, $reason);

    if ($result['success']) {
        wp_send_json_success($result);
    } else {
        wp_send_json_error($result);
    }
}

function eipsi_ajax_get_data_requests() {
    check_ajax_referer('eipsi_admin_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'No autorizado'));
    }

    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-participant-data-request-service.php';

    $filters = array(
        'status' => isset($_POST['status']) ? sanitize_text_field($_POST['status']) : '',
        'survey_id' => isset($_POST['survey_id']) ? absint($_POST['survey_id']) : 0,
        'request_type' => isset($_POST['request_type']) ? sanitize_text_field($_POST['request_type']) : ''
    );

    $limit = isset($_POST['limit']) ? absint($_POST['limit']) : 20;
    $offset = isset($_POST['offset']) ? absint($_POST['offset']) : 0;

    $result = EIPSI_Participant_Data_Request_Service::get_requests($filters, $limit, $offset);

    wp_send_json_success($result);
}

function eipsi_ajax_process_data_request() {
    check_ajax_referer('eipsi_admin_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'No autorizado'));
    }

    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-participant-data-request-service.php';

    $request_id = isset($_POST['request_id']) ? absint($_POST['request_id']) : 0;
    $action = isset($_POST['action_type']) ? sanitize_text_field($_POST['action_type']) : '';
    $admin_notes = isset($_POST['admin_notes']) ? sanitize_textarea_field($_POST['admin_notes']) : '';

    if (!$request_id || !in_array($action, array('approve', 'reject'), true)) {
        wp_send_json_error(array('message' => 'Datos inválidos'));
    }

    $result = EIPSI_Participant_Data_Request_Service::process_request($request_id, $action, $admin_notes);

    if ($result['success']) {
        wp_send_json_success($result);
    } else {
        wp_send_json_error($result);
    }
}

function eipsi_ajax_get_data_request_counts() {
    check_ajax_referer('eipsi_admin_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'No autorizado'));
    }

    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-participant-data-request-service.php';

    $counts = EIPSI_Participant_Data_Request_Service::get_request_counts();

    wp_send_json_success($counts);
}


add_action('wp_ajax_eipsi_download_personal_data', 'eipsi_download_personal_data_handler');
add_action('wp_ajax_nopriv_eipsi_download_personal_data', 'eipsi_download_personal_data_handler');
function eipsi_download_personal_data_handler() {
    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-participant-data-request-service.php';
    $result=EIPSI_Participant_Data_Request_Service::get_download($_GET['request_id']??0,$_GET['nonce']??'');
    if(!$result['success']){wp_send_json_error(array('message'=>$result['message']),403);return;}
    header('Content-Type: application/json');
    header('Cache-Control: private, no-store');
    header('Content-Disposition: attachment; filename="'.basename($result['filename']).'"');
    readfile($result['file_path']);exit;
}
