<?php
/**
 * EIPSI Setup Wizard AJAX Handlers
 * 
 * Handlers AJAX para el wizard de creación de estudios longitudinales.
 *
 * @package EIPSI_Forms
 * @since 1.5.1
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/longitudinal/bootstrap.php';

// === Handlers del Setup Wizard (v1.5.1) ===
add_action('wp_ajax_eipsi_save_wizard_step', 'eipsi_save_wizard_step_handler');
add_action('wp_ajax_eipsi_auto_save_wizard_step', 'eipsi_auto_save_wizard_step_handler');
add_action('wp_ajax_eipsi_activate_study', 'eipsi_activate_study_handler');
add_action('wp_ajax_eipsi_get_available_forms', 'eipsi_get_available_forms_handler');
add_action('wp_ajax_eipsi_get_wizard_data', 'eipsi_get_wizard_data_handler');

/**
 * AJAX Handler: Save wizard step
 */
function eipsi_save_wizard_step_handler() {
    // Verify nonce
    if (!isset($_POST['eipsi_wizard_nonce']) || !wp_verify_nonce($_POST['eipsi_wizard_nonce'], 'eipsi_wizard_action')) {
        wp_send_json_error(array('message' => 'Error de seguridad. Por favor, recarga la página.'));
    }
    
    // Check permissions
    if (!function_exists('eipsi_user_can_manage_longitudinal') || !eipsi_user_can_manage_longitudinal()) {
        wp_send_json_error(array('message' => 'No tienes permisos para realizar esta acción.'));
    }
    
    $current_step = isset($_POST['current_step']) ? intval($_POST['current_step']) : 1;
    
    // DEBUG: Log raw POST data for step 3
    if ($current_step === 3 && isset($_POST['timing_intervals'])) {
        error_log('[EIPSI DEBUG] Step 3 POST data: ' . json_encode($_POST['timing_intervals']));
    }
    
    // Include validators if not already loaded
    if (!function_exists('eipsi_validate_step_data')) {
        require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/wizard-validators.php';
    }
    
    // Validate step data
    $validation_result = eipsi_validate_step_data($current_step, $_POST);
    
    if (!$validation_result['valid']) {
        wp_send_json_error(array('message' => implode("\n", $validation_result['errors'])));
    }
    
    // Sanitize step data
    $sanitized_data = eipsi_sanitize_step_data($current_step, $_POST);
    
    // Save to transient
    $transient_key = eipsi_get_wizard_transient_key();
    $wizard_data = eipsi_get_wizard_data();
    
    $wizard_data['step_' . $current_step] = $sanitized_data;
    $wizard_data['current_step'] = max($wizard_data['current_step'], $current_step);
    $wizard_data['last_updated'] = current_time('mysql');
    
    set_transient($transient_key, $wizard_data, HOUR_IN_SECONDS * 2);
    
    wp_send_json_success(array(
        'message' => 'Paso guardado correctamente.',
        'step' => $current_step,
        'redirect_url' => admin_url('admin.php?page=eipsi-longitudinal-study&tab=create-study&step=' . min($current_step + 1, 5))
    ));
}

/**
 * AJAX Handler: Auto-save wizard step
 */
function eipsi_auto_save_wizard_step_handler() {
    // Verify nonce
    if (!isset($_POST['eipsi_wizard_nonce']) || !wp_verify_nonce($_POST['eipsi_wizard_nonce'], 'eipsi_wizard_action')) {
        wp_send_json_error(array('message' => 'Error de seguridad.'));
    }
    
    // Check permissions
    if (!function_exists('eipsi_user_can_manage_longitudinal') || !eipsi_user_can_manage_longitudinal()) {
        wp_send_json_error(array('message' => 'Sin permisos.'));
    }
    
    $current_step = isset($_POST['current_step']) ? intval($_POST['current_step']) : 1;
    
    // Include validators if not already loaded
    if (!function_exists('eipsi_sanitize_step_data')) {
        require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/wizard-validators.php';
    }
    
    // Sanitize step data (without validation for auto-save)
    $sanitized_data = eipsi_sanitize_step_data($current_step, $_POST);
    
    // Save to transient
    $transient_key = eipsi_get_wizard_transient_key();
    $wizard_data = eipsi_get_wizard_data();
    
    $wizard_data['step_' . $current_step] = $sanitized_data;
    $wizard_data['last_updated'] = current_time('mysql');
    
    set_transient($transient_key, $wizard_data, HOUR_IN_SECONDS * 2);
    
    wp_send_json_success(array(
        'message' => 'Auto-guardado completado.',
        'timestamp' => current_time('mysql')
    ));
}

/**
 * AJAX Handler: Activate study from wizard
 */
function eipsi_activate_study_handler() {
    // Verify nonce
    if (!isset($_POST['eipsi_wizard_nonce']) || !wp_verify_nonce($_POST['eipsi_wizard_nonce'], 'eipsi_wizard_action')) {
        wp_send_json_error(array('message' => 'Error de seguridad. Por favor, recarga la página.'));
    }
    
    // Check permissions
    if (!function_exists('eipsi_user_can_manage_longitudinal') || !eipsi_user_can_manage_longitudinal()) {
        wp_send_json_error(array('message' => 'No tienes permisos para realizar esta acción.'));
    }
    
    // Include setup wizard functions
    if (!function_exists('eipsi_create_study_from_wizard')) {
        require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/setup-wizard.php';
    }
    
    $wizard_data = eipsi_get_wizard_data();
    
    // Validate all steps are complete
    for ($i = 1; $i <= 4; $i++) {
        if (empty($wizard_data['step_' . $i])) {
            wp_send_json_error(array('message' => 'Debes completar todos los pasos antes de activar el estudio.'));
        }
    }
    
    // Validate activation confirmation
    if (!isset($_POST['activation_confirmed']) || $_POST['activation_confirmed'] !== '1') {
        wp_send_json_error(array('message' => 'Debes confirmar la activación del estudio.'));
    }
    
    // Create the study
    $study_id = eipsi_create_study_from_wizard($wizard_data);
    
    if (!$study_id) {
        wp_send_json_error(array('message' => 'Error al crear el estudio. Por favor, intenta nuevamente.'));
    }
    
    // Clear wizard transient
    $transient_key = eipsi_get_wizard_transient_key();
    delete_transient($transient_key);
    
    wp_send_json_success(array(
        'message' => 'Estudio creado exitosamente.',
        'study_id' => $study_id,
        'redirect_url' => admin_url('admin.php?page=eipsi-longitudinal-study&tab=dashboard-study&study_id=' . $study_id)
    ));
}

/**
 * AJAX Handler: Get available forms for wizard
 */
function eipsi_get_available_forms_handler() {
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'eipsi_wizard_action')) {
        wp_send_json_error(array('message' => 'Error de seguridad.'));
    }
    
    // Check permissions
    if (!function_exists('eipsi_user_can_manage_longitudinal') || !eipsi_user_can_manage_longitudinal()) {
        wp_send_json_error(array('message' => 'Sin permisos.'));
    }
    
    $forms = eipsi_get_available_forms_for_wizard();
    
    wp_send_json_success(array(
        'forms' => $forms
    ));
}

/**
 * AJAX Handler: Get wizard data
 */
function eipsi_get_wizard_data_handler() {
    // Verify nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'eipsi_wizard_action')) {
        wp_send_json_error(array('message' => 'Error de seguridad.'));
    }
    
    // Check permissions
    if (!function_exists('eipsi_user_can_manage_longitudinal') || !eipsi_user_can_manage_longitudinal()) {
        wp_send_json_error(array('message' => 'Sin permisos.'));
    }
    
    $wizard_data = eipsi_get_wizard_data();
    
    wp_send_json_success(array(
        'wizard_data' => $wizard_data
    ));
}

/**
 * Get available forms for wizard dropdown
 */
function eipsi_get_available_forms_for_wizard() {
    $result = array();

    // Buscar form templates personalizados (Form Library)
    $template_post_types = array();

    if (post_type_exists('eipsi_form_template')) {
        $template_post_types[] = 'eipsi_form_template';
    }

    if (post_type_exists('eipsi_form')) {
        $template_post_types[] = 'eipsi_form';
    }

    if (!empty($template_post_types)) {
        $forms = get_posts(array(
            'post_type' => $template_post_types,
            'posts_per_page' => -1,
            'post_status' => array('publish', 'private', 'draft', 'pending'),
            'orderby' => 'title',
            'order' => 'ASC'
        ));

        foreach ($forms as $form) {
            $title = $form->post_title ? $form->post_title : __('(Sin título)', 'eipsi-forms');
            $result[] = array(
                'ID' => $form->ID,
                'post_title' => $title,
                'type' => $form->post_type
            );
        }
    }

    // También buscar páginas con formularios activos (retrocompatibilidad)
    $pages_with_forms = get_posts(array(
        'post_type' => 'page',
        'posts_per_page' => -1,
        'meta_query' => array(
            array(
                'key' => '_eipsi_form_active',
                'value' => '1',
                'compare' => '='
            )
        ),
        'orderby' => 'title',
        'order' => 'ASC'
    ));

    foreach ($pages_with_forms as $page) {
        $result[] = array(
            'ID' => $page->ID,
            'post_title' => $page->post_title . ' (Página)',
            'type' => 'page'
        );
    }

    return $result;
}

// === Handlers de Control de Estudios (v1.6.1) ===
add_action('wp_ajax_eipsi_pause_study', 'eipsi_pause_study_handler');
add_action('wp_ajax_eipsi_resume_study', 'eipsi_resume_study_handler');
add_action('wp_ajax_eipsi_get_study_status_counts', 'eipsi_get_study_status_counts_handler');

/**
 * AJAX Handler: Pause study
 */
function eipsi_pause_study_handler() {
    check_ajax_referer('eipsi_study_dashboard_nonce', 'nonce');
    
    if (!eipsi_user_can_manage_longitudinal()) {
        wp_send_json_error(array('message' => 'No tienes permisos'));
    }
    
    $study_id = isset($_POST['study_id']) ? intval($_POST['study_id']) : 0;
    if (!$study_id) {
        wp_send_json_error(array('message' => 'Study ID requerido'));
    }
    
    global $wpdb;
    $result = EIPSI_Longitudinal_Study_Service::set_status($study_id,'paused');
    
    if ($result !== false) {
        error_log("[EIPSI] Study {$study_id} paused by user " . get_current_user_id());
        wp_send_json_success(array(
            'message' => 'Estudio pausado correctamente',
            'status' => 'paused'
        ));
    } else {
        wp_send_json_error(array('message' => 'Error al pausar el estudio'));
    }
}

/**
 * AJAX Handler: Resume study
 */
function eipsi_resume_study_handler() {
    check_ajax_referer('eipsi_study_dashboard_nonce', 'nonce');
    
    if (!eipsi_user_can_manage_longitudinal()) {
        wp_send_json_error(array('message' => 'No tienes permisos'));
    }
    
    $study_id = isset($_POST['study_id']) ? intval($_POST['study_id']) : 0;
    if (!$study_id) {
        wp_send_json_error(array('message' => 'Study ID requerido'));
    }
    
    global $wpdb;
    $result = EIPSI_Longitudinal_Study_Service::set_status($study_id,'active');
    
    if ($result !== false) {
        error_log("[EIPSI] Study {$study_id} resumed by user " . get_current_user_id());
        wp_send_json_success(array(
            'message' => 'Estudio reanudado correctamente',
            'status' => 'active'
        ));
    } else {
        wp_send_json_error(array('message' => 'Error al reanudar el estudio'));
    }
}

/**
 * AJAX Handler: Get study status counts
 */
function eipsi_get_study_status_counts_handler() {
    check_ajax_referer('eipsi_study_dashboard_nonce', 'nonce');
    
    if (!eipsi_user_can_manage_longitudinal()) {
        wp_send_json_error(array('message' => 'No tienes permisos'));
    }
    
    $study_id = isset($_POST['study_id']) ? intval($_POST['study_id']) : 0;
    if (!$study_id) {
        wp_send_json_error(array('message' => 'Study ID requerido'));
    }
    
    global $wpdb;
    
    $study_status = $wpdb->get_var($wpdb->prepare(
        "SELECT status FROM {$wpdb->prefix}survey_studies WHERE id = %d",
        $study_id
    ));
    
    $active_count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}survey_participants WHERE survey_id = %d AND is_active = 1",
        $study_id
    ));
    
    $completed_count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(DISTINCT participant_id) FROM {$wpdb->prefix}survey_assignments WHERE study_id = %d AND status = 'submitted'",
        $study_id
    ));
    
    $paused_count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(DISTINCT participant_id) FROM {$wpdb->prefix}survey_assignments WHERE study_id = %d AND status = 'paused'",
        $study_id
    ));
    
    $total_count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}survey_participants WHERE survey_id = %d",
        $study_id
    ));
    
    wp_send_json_success(array(
        'study_status' => $study_status,
        'active' => intval($active_count),
        'completed' => intval($completed_count),
        'paused' => intval($paused_count),
        'total' => intval($total_count)
    ));
}
