<?php
/**
 * Shared rendering helpers for form templates
 *
 * @package EIPSI_Forms
 * @since 1.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/forms/class-form-renderer.php';

/**
 * Render helper: build HTML attributes string
 *
 * @param array $attributes Key => value map
 * @return string
 */
function eipsi_build_html_attributes($attributes = array()) {
    return EIPSI_Form_Renderer::eipsi_build_html_attributes($attributes);
}

/**
 * Render helper: consistent notice UI for editors/front-end
 *
 * @param string $message Message to display
 * @param string $type    info|warning|error
 * @return string
 */
function eipsi_render_form_notice($message, $type = 'info') {
    return EIPSI_Form_Renderer::eipsi_render_form_notice($message, $type);
}

/**
 * Fetch a form template post
 *
 * @param int $template_id
 * @return WP_Post|WP_Error
 */
function eipsi_get_form_template($template_id) {
    return EIPSI_Form_Renderer::eipsi_get_form_template($template_id);
}

/**
 * Render a form template and wrap it for block/shortcode usage
 *
 * @param int    $template_id Template post ID
 * @param string $context     block|shortcode
 * @param array  $options    Optional: { 'survey_id' => 123 }
 * @return string HTML markup
 */
function eipsi_render_form_template_markup($template_id, $context = 'block', $options = array()) {
    return EIPSI_Form_Renderer::eipsi_render_form_template_markup($template_id, $context, $options);
}

/**
 * Shortcode dispatcher (shared helper)
 *
 * @param int $template_id
 * @return string
 */
function eipsi_render_form_shortcode_markup($template_id) {
    return EIPSI_Form_Renderer::eipsi_render_form_shortcode_markup($template_id);
}

/**
 * Check if form requires login
 * 
 * @param int $template_id
 * @return bool
 */
function eipsi_form_requires_login($template_id) {
    return EIPSI_Form_Renderer::eipsi_form_requires_login($template_id);
}

/**
 * Check if participant is authenticated
 * 
 * @return bool
 */
if (!function_exists('eipsi_is_participant_logged_in')) {
    function eipsi_is_participant_logged_in() {
        // Use the official Auth Service for proper authentication check
        if (!class_exists('EIPSI_Auth_Service')) {
            require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-auth-service.php';
        }
        
        return EIPSI_Auth_Service::is_authenticated();
    }
}

/**
 * Get current authenticated participant
 * 
 * @return array|false { 'id' => ..., 'email' => ..., 'survey_id' => ... } or false
 */
function eipsi_get_current_participant() {
    if (!eipsi_is_participant_logged_in()) {
        return false;
    }
    
    $participant_id = EIPSI_Auth_Service::get_current_participant();
    if (!$participant_id) {
        return false;
    }

    // Fetch from DB
    global $wpdb;
    return $wpdb->get_row(
        $wpdb->prepare(
            "SELECT id, email, survey_id FROM {$wpdb->prefix}survey_participants WHERE id = %d",
            absint($participant_id)
        ),
        ARRAY_A
    );
}
