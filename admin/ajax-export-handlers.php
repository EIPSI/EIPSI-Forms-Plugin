<?php
/**
 * EIPSI Forms — Export AJAX Handlers
 *
 * Handles all AJAX requests triggered from the Export tab:
 *   - eipsi_get_export_stats          : longitudinal completion stats
 *   - eipsi_export_to_excel           : longitudinal Excel
 *   - eipsi_export_to_csv             : longitudinal CSV
 *   - eipsi_get_participant_stats     : participant roster summary stats  [NEW]
 *   - eipsi_get_participant_waves     : dynamic wave list for a study     [NEW]
 *   - eipsi_get_participant_preview   : live table preview (first 10)     [NEW]
 *
 * @package EIPSI_Forms
 * @since   1.4.0
 * @updated 1.8.0 — Participant export endpoints
 */

if (!defined('ABSPATH')) {
    exit;
}

// ---------------------------------------------------------------------------
// Longitudinal stats
// ---------------------------------------------------------------------------

add_action('wp_ajax_eipsi_get_export_stats', 'eipsi_get_export_stats_handler');

function eipsi_get_export_stats_handler() {
    check_ajax_referer('eipsi_admin_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Unauthorized'));
    }

    $survey_id = isset($_POST['survey_id']) ? absint($_POST['survey_id']) : 0;
    if (!$survey_id) {
        wp_send_json_error(array('message' => 'Invalid survey ID'));
    }

    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-export-service.php';
    $export_service = new EIPSI_Export_Service();
    $stats          = $export_service->get_export_statistics($survey_id);

    wp_send_json_success($stats);
}

// ---------------------------------------------------------------------------
// Longitudinal Excel
// ---------------------------------------------------------------------------

add_action('wp_ajax_eipsi_export_to_excel', 'eipsi_export_to_excel_handler');

function eipsi_export_to_excel_handler() {
    check_ajax_referer('eipsi_admin_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Unauthorized'));
    }

    $survey_id = isset($_POST['survey_id']) ? absint($_POST['survey_id']) : 0;
    $filters   = isset($_POST['filters'])
        ? array_map('sanitize_text_field', (array) $_POST['filters'])
        : array();

    if (!$survey_id) {
        wp_send_json_error(array('message' => 'Invalid survey ID'));
    }

    try {
    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-export-service.php';
    $export_service = new EIPSI_Export_Service();
    $data           = $export_service->export_longitudinal_data($survey_id, $filters);
    $filename       = $export_service->export_to_excel($data, $survey_id);

    wp_send_json_success(array('filename' => $filename));
    } catch (Throwable $error) {
        wp_send_json_error(array('message' => $error->getMessage()), 500);
    }
}

// ---------------------------------------------------------------------------
// Longitudinal CSV
// ---------------------------------------------------------------------------

add_action('wp_ajax_eipsi_export_to_csv', 'eipsi_export_to_csv_handler');

function eipsi_export_to_csv_handler() {
    check_ajax_referer('eipsi_admin_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Unauthorized'));
    }

    $survey_id = isset($_POST['survey_id']) ? absint($_POST['survey_id']) : 0;
    $filters   = isset($_POST['filters'])
        ? array_map('sanitize_text_field', (array) $_POST['filters'])
        : array();

    if (!$survey_id) {
        wp_send_json_error(array('message' => 'Invalid survey ID'));
    }

    try {
    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-export-service.php';
    $export_service = new EIPSI_Export_Service();
    $data           = $export_service->export_longitudinal_data($survey_id, $filters);
    $filename       = $export_service->export_to_csv($data, $survey_id);

    wp_send_json_success(array('filename' => $filename));
    } catch (Throwable $error) {
        wp_send_json_error(array('message' => $error->getMessage()), 500);
    }
}

// ---------------------------------------------------------------------------
// Participant stats (new — v1.8.0)
// ---------------------------------------------------------------------------

add_action('wp_ajax_eipsi_get_participant_stats', 'eipsi_get_participant_stats_handler');

/**
 * Returns summary stats for the participant export panel:
 *   total, active, inactive, completed_all, waves list.
 */
function eipsi_get_participant_stats_handler() {
    check_ajax_referer('eipsi_admin_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Unauthorized'));
    }

    $study_id = isset($_POST['study_id']) ? absint($_POST['study_id']) : 0;
    if (!$study_id) {
        wp_send_json_error(array('message' => 'Invalid study ID'));
    }

    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-export-service.php';
    $svc   = new EIPSI_Export_Service();
    $stats = $svc->get_export_statistics($study_id);

    // Also return the wave list so the UI can build the wave filter dropdown
    global $wpdb;
    $waves = $wpdb->get_results($wpdb->prepare(
        "SELECT wave_index, name
         FROM {$wpdb->prefix}survey_waves
         WHERE study_id = %d
         ORDER BY wave_index ASC",
        $study_id
    ));

    $stats['waves'] = $waves;

    wp_send_json_success($stats);
}

// ---------------------------------------------------------------------------
// Participant wave list (new — v1.8.0)
// ---------------------------------------------------------------------------

add_action('wp_ajax_eipsi_get_participant_waves', 'eipsi_get_participant_waves_handler');

/**
 * Lightweight wave list for a study (used to populate filter dropdowns).
 */
function eipsi_get_participant_waves_handler() {
    check_ajax_referer('eipsi_admin_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Unauthorized'));
    }

    $study_id = isset($_POST['study_id']) ? absint($_POST['study_id']) : 0;
    if (!$study_id) {
        wp_send_json_error(array('message' => 'Invalid study ID'));
    }

    global $wpdb;
    $waves = $wpdb->get_results($wpdb->prepare(
        "SELECT wave_index, name
         FROM {$wpdb->prefix}survey_waves
         WHERE study_id = %d
         ORDER BY wave_index ASC",
        $study_id
    ));

    wp_send_json_success($waves);
}

// ---------------------------------------------------------------------------
// Participant preview (new — v1.8.0)
// ---------------------------------------------------------------------------

add_action('wp_ajax_eipsi_get_participant_preview', 'eipsi_get_participant_preview_handler');

/**
 * Returns live preview data (first 10 rows) for the export table preview.
 * Also returns total row count and column count for the summary bar.
 */
function eipsi_get_participant_preview_handler() {
    check_ajax_referer('eipsi_admin_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Unauthorized'));
    }

    $study_id = isset($_POST['study_id']) ? absint($_POST['study_id']) : 0;
    if (!$study_id) {
        wp_send_json_error(array('message' => 'Invalid study ID'));
    }

    $filters = array(
        'status'     => isset($_POST['status'])     ? sanitize_text_field($_POST['status'])     : 'all',
        'wave_index' => isset($_POST['wave_index']) ? sanitize_text_field($_POST['wave_index']) : 'all',
        'search'     => isset($_POST['search'])     ? sanitize_text_field($_POST['search'])     : '',
        'date_from'  => isset($_POST['date_from'])  ? sanitize_text_field($_POST['date_from'])  : null,
        'date_to'    => isset($_POST['date_to'])    ? sanitize_text_field($_POST['date_to'])    : null,
    );

    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-export-service.php';
    $svc     = new EIPSI_Export_Service();
    $preview = $svc->get_participants_preview($study_id, $filters, 10);

    wp_send_json_success($preview);
}

// ---------------------------------------------------------------------------
// Participant Wide Excel (new — v1.8.0)
// ---------------------------------------------------------------------------

add_action('wp_ajax_eipsi_export_participants_wide_excel', 'eipsi_export_participants_wide_excel_handler');

function eipsi_export_participants_wide_excel_handler() {
    check_ajax_referer('eipsi_admin_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Unauthorized'));
    }

    $study_id = isset($_POST['study_id']) ? absint($_POST['study_id']) : 0;
    $filters  = isset($_POST['filters'])
        ? array_map('sanitize_text_field', (array) $_POST['filters'])
        : array();

    if (!$study_id) {
        wp_send_json_error(array('message' => 'Invalid study ID'));
    }

    try {
    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-export-service.php';
    $svc      = new EIPSI_Export_Service();
    $filename = $svc->export_participants_wide_excel($study_id, $filters);

    wp_send_json_success(array('filename' => $filename));
    } catch (Throwable $error) {
        wp_send_json_error(array('message' => $error->getMessage()), 500);
    }
}

// ---------------------------------------------------------------------------
// Participant Wide CSV (new — v1.8.0)
// ---------------------------------------------------------------------------

add_action('wp_ajax_eipsi_export_participants_wide_csv', 'eipsi_export_participants_wide_csv_handler');

function eipsi_export_participants_wide_csv_handler() {
    check_ajax_referer('eipsi_admin_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Unauthorized'));
    }

    $study_id = isset($_POST['study_id']) ? absint($_POST['study_id']) : 0;
    $filters  = isset($_POST['filters'])
        ? array_map('sanitize_text_field', (array) $_POST['filters'])
        : array();

    if (!$study_id) {
        wp_send_json_error(array('message' => 'Invalid study ID'));
    }

    $filename   = 'participantes-wide-' . $study_id . '-' . date('Y-m-d_H-i-s') . '.csv';
    $export_dir = EIPSI_FORMS_PLUGIN_DIR . 'exports';
    if (!file_exists($export_dir)) {
        wp_mkdir_p($export_dir);
    }
    $file_path = $export_dir . '/' . $filename;
    $output    = fopen($file_path, 'w');

    if (!$output) {
        wp_send_json_error(array('message' => 'Could not create export file'));
    }

    try {
    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-export-service.php';
    $svc = new EIPSI_Export_Service();
    $svc->stream_participants_wide_csv($study_id, $filters, $output);
    if (!fclose($output) || !is_file($file_path) || !filesize($file_path)) { throw new RuntimeException('No se pudo confirmar el archivo CSV.'); }

    wp_send_json_success(array('filename' => $filename));
    } catch (Throwable $error) {
        wp_send_json_error(array('message' => $error->getMessage()), 500);
    }
}

// ---------------------------------------------------------------------------
// Participant Wide Preview (new — v1.8.0)
// ---------------------------------------------------------------------------

add_action('wp_ajax_eipsi_get_participants_wide_preview', 'eipsi_get_participants_wide_preview_handler');

/**
 * Returns live preview data (first 10 rows) for the Wide export format.
 * Also returns total row count and column count for the summary bar.
 */
function eipsi_get_participants_wide_preview_handler() {
    check_ajax_referer('eipsi_admin_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Unauthorized'));
    }

    $study_id = isset($_POST['study_id']) ? absint($_POST['study_id']) : 0;
    if (!$study_id) {
        wp_send_json_error(array('message' => 'Invalid study ID'));
    }

    $filters = isset($_POST['filters'])
        ? array_map('sanitize_text_field', (array) $_POST['filters'])
        : array();

    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-export-service.php';
    $svc     = new EIPSI_Export_Service();
    $preview = $svc->get_participants_wide_preview($study_id, $filters, 10);

    wp_send_json_success($preview);
}
