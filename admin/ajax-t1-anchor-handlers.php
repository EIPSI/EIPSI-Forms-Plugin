<?php
/**
 * EIPSI Forms - AJAX Handlers for T1-Anchor System
 *
 * Admin AJAX endpoints for:
 * - Running the offset columns migration
 * - Batch anchoring existing participants
 * - Manual anchoring for a specific participant
 * - Getting anchored timeline for a participant
 *
 * @package EIPSI_Forms
 * @since 2.6.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// ============================================================================
// AJAX: Run Offset Columns Migration
// ============================================================================

add_action('wp_ajax_eipsi_run_offset_migration', 'eipsi_ajax_run_offset_migration');

/**
 * AJAX handler to run the offset columns migration.
 *
 * @since 2.6.0
 */
function eipsi_ajax_run_offset_migration() {
    // Security check
    check_ajax_referer('eipsi_admin_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array(
            'message' => __('No tienes permisos para realizar esta acción.', 'eipsi-forms'),
        ));
    }

    // Include migration script
    require_once EIPSI_FORMS_PLUGIN_DIR . 'scripts/migration-add-offset-columns.php';

    // Run migration
    $result = eipsi_run_offset_migration();

    if ($result['success']) {
        wp_send_json_success(array(
            'message' => __('Migración completada exitosamente.', 'eipsi-forms'),
            'changes' => $result['changes'],
            'warnings' => $result['warnings'],
        ));
    } else {
        wp_send_json_error(array(
            'message' => __('La migración completó con errores.', 'eipsi-forms'),
            'errors' => $result['errors'],
            'changes' => $result['changes'],
            'warnings' => $result['warnings'],
        ));
    }
}

// ============================================================================
// AJAX: Batch Anchor Existing Participants
// ============================================================================

add_action('wp_ajax_eipsi_batch_anchor_participants', 'eipsi_ajax_batch_anchor_participants');

/**
 * AJAX handler to batch anchor existing participants.
 *
 * @since 2.6.0
 */
function eipsi_ajax_batch_anchor_participants() {
    // Security check
    check_ajax_referer('eipsi_admin_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array(
            'message' => __('No tienes permisos para realizar esta acción.', 'eipsi-forms'),
        ));
    }

    $study_id = isset($_POST['study_id']) ? absint($_POST['study_id']) : 0;

    if (!class_exists('EIPSI_T1_Anchor_Service')) {
        require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-t1-anchor-service.php';
    }

    $result = EIPSI_T1_Anchor_Service::batch_anchor_existing_participants($study_id);

    wp_send_json_success(array(
        'message' => sprintf(
            __('Proceso completado: %d participantes anclados, %d errores de %d total.', 'eipsi-forms'),
            $result['anchored'],
            $result['errors'],
            $result['total']
        ),
        'total' => $result['total'],
        'anchored' => $result['anchored'],
        'errors' => $result['errors'],
        'details' => $result['details'],
    ));
}

// ============================================================================
// AJAX: Manual Anchor Participant
// ============================================================================

add_action('wp_ajax_eipsi_manual_anchor_participant', 'eipsi_ajax_manual_anchor_participant');

/**
 * AJAX handler to manually anchor a participant.
 *
 * @since 2.6.0
 */
function eipsi_ajax_manual_anchor_participant() {
    // Security check
    check_ajax_referer('eipsi_admin_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array(
            'message' => __('No tienes permisos para realizar esta acción.', 'eipsi-forms'),
        ));
    }

    $study_id = isset($_POST['study_id']) ? absint($_POST['study_id']) : 0;
    $participant_id = isset($_POST['participant_id']) ? absint($_POST['participant_id']) : 0;
    $t1_timestamp = isset($_POST['t1_timestamp']) ? sanitize_text_field($_POST['t1_timestamp']) : null;
    $force = isset($_POST['force']) && $_POST['force'] === 'true';

    if (!$study_id || !$participant_id) {
        wp_send_json_error(array(
            'message' => __('Se requiere study_id y participant_id.', 'eipsi-forms'),
        ));
    }

    if (!class_exists('EIPSI_T1_Anchor_Service')) {
        require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-t1-anchor-service.php';
    }

    $result = EIPSI_T1_Anchor_Service::manual_anchor($study_id, $participant_id, $t1_timestamp, $force);

    if (is_wp_error($result)) {
        wp_send_json_error(array(
            'message' => $result->get_error_message(),
        ));
    }

    wp_send_json_success(array(
        'message' => __('Participante anclado exitosamente.', 'eipsi-forms'),
        't1_completed_at' => $result['t1_completed_at'],
        'waves' => $result['waves'],
    ));
}

// ============================================================================
// AJAX: Get Participant Anchored Timeline
// ============================================================================

add_action('wp_ajax_eipsi_get_anchored_timeline', 'eipsi_ajax_get_anchored_timeline');

/**
 * AJAX handler to get anchored timeline for a participant.
 *
 * @since 2.6.0
 */
function eipsi_ajax_get_anchored_timeline() {
    // Security check
    check_ajax_referer('eipsi_admin_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array(
            'message' => __('No tienes permisos para ver esta información.', 'eipsi-forms'),
        ), 403);
    }

    $study_id = isset($_GET['study_id']) ? absint($_GET['study_id']) : 0;
    $participant_id = isset($_GET['participant_id']) ? absint($_GET['participant_id']) : 0;

    if (!$study_id || !$participant_id) {
        wp_send_json_error(array(
            'message' => __('Se requiere study_id y participant_id.', 'eipsi-forms'),
        ));
    }

    if (!class_exists('EIPSI_T1_Anchor_Service')) {
        require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-t1-anchor-service.php';
    }

    $result = EIPSI_T1_Anchor_Service::get_participant_anchored_timeline($study_id, $participant_id);

    if (!$result['success']) {
        wp_send_json_error(array(
            'message' => $result['error'] ?? __('Error al obtener el timeline.', 'eipsi-forms'),
        ));
    }

    wp_send_json_success($result);
}

// ============================================================================
// AJAX: Check Migration Status
// ============================================================================

add_action('wp_ajax_eipsi_check_offset_migration_status', 'eipsi_ajax_check_offset_migration_status');

/**
 * AJAX handler to check if the offset migration has been applied.
 *
 * @since 2.6.0
 */
function eipsi_ajax_check_offset_migration_status() {
    global $wpdb;

    // Security check
    check_ajax_referer('eipsi_admin_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(array(
            'message' => __('No tienes permisos para ver esta información.', 'eipsi-forms'),
        ));
    }

    // Check for offset_minutes column in survey_waves
    $waves_table = $wpdb->prefix . 'survey_waves';
    $offset_exists = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = %s
         AND TABLE_NAME = %s
         AND COLUMN_NAME = 'offset_minutes'",
        DB_NAME,
        $waves_table
    ));

    // Check for t1_completed_at column in survey_participants
    $participants_table = $wpdb->prefix . 'survey_participants';
    $t1_exists = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = %s
         AND TABLE_NAME = %s
         AND COLUMN_NAME = 't1_completed_at'",
        DB_NAME,
        $participants_table
    ));

    // Check for study_end_offset_minutes column in survey_studies
    $studies_table = $wpdb->prefix . 'survey_studies';
    $end_offset_exists = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = %s
         AND TABLE_NAME = %s
         AND COLUMN_NAME = 'study_end_offset_minutes'",
        DB_NAME,
        $studies_table
    ));

    $migration_complete = ($offset_exists > 0) && ($t1_exists > 0) && ($end_offset_exists > 0);

    // Count unanchored participants (have submitted T1 but t1_completed_at is NULL)
    $unanchored_count = 0;
    if ($t1_exists > 0) {
        $unanchored_count = $wpdb->get_var(
            "SELECT COUNT(DISTINCT p.id)
             FROM {$participants_table} p
             JOIN {$wpdb->prefix}survey_assignments a ON p.id = a.participant_id
             JOIN {$wpdb->prefix}survey_waves w ON a.wave_id = w.id AND w.study_id = p.survey_id
             WHERE p.t1_completed_at IS NULL
             AND w.wave_index = 1
             AND a.status = 'submitted'"
        );
    }

    wp_send_json_success(array(
        'migration_complete' => $migration_complete,
        'columns' => array(
            'offset_minutes' => $offset_exists > 0,
            't1_completed_at' => $t1_exists > 0,
            'study_end_offset_minutes' => $end_offset_exists > 0,
        ),
        'unanchored_participants' => (int) $unanchored_count,
    ));
}
