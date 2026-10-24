<?php
/**
 * AJAX Handlers for Email System Testing & Diagnostics
 *
 * @package EIPSI_Forms
 * @since 1.5.4
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * AJAX Handler: Test default email system (wp_mail)
 * @since 1.5.4
 */
add_action('wp_ajax_eipsi_test_default_email', 'eipsi_test_default_email_handler');

function eipsi_test_default_email_handler() {
    check_ajax_referer('eipsi_admin_nonce', 'nonce');

    if (!EIPSI_Admin_Authorization_Policy::allows('email.manage')) {
        wp_send_json_error(array('message' => __('Unauthorized', 'eipsi-forms')), 403);
    }

    if (!class_exists('EIPSI_Email_Service')) {
        require_once plugin_dir_path(__FILE__) . 'services/class-email-service.php';
    }

    $test_email = isset($_POST['test_email']) ? sanitize_email(wp_unslash($_POST['test_email'])) : '';
    if (empty($test_email)) {
        $test_email = get_option('eipsi_investigator_email', get_option('admin_email'));
    }

    $diagnostic = EIPSI_Email_Service::diagnose_email_system();
    $result = EIPSI_Email_Service::send_test_email($test_email);

    if ($result['success']) {
        wp_send_json_success(array(
            'message' => $result['message'],
            'details' => $result['details'],
            'diagnostic' => $diagnostic
        ));
    } else {
        wp_send_json_error(array(
            'message' => $result['message'],
            'details' => $result['details'],
            'diagnostic' => $diagnostic
        ));
    }
}

/**
 * AJAX Handler: Get email system diagnostic
 * @since 1.5.4
 */
add_action('wp_ajax_eipsi_get_email_diagnostic', 'eipsi_get_email_diagnostic_handler');

function eipsi_get_email_diagnostic_handler() {
    check_ajax_referer('eipsi_admin_nonce', 'nonce');

    if (!EIPSI_Admin_Authorization_Policy::allows('email.manage')) {
        wp_send_json_error(array('message' => __('Unauthorized', 'eipsi-forms')), 403);
    }

    if (!class_exists('EIPSI_Email_Service')) {
        require_once plugin_dir_path(__FILE__) . 'services/class-email-service.php';
    }

    $diagnostic = EIPSI_Email_Service::diagnose_email_system();
    $stats = EIPSI_Email_Service::get_email_deliverability_stats();

    wp_send_json_success(array(
        'diagnostic' => $diagnostic,
        'stats' => $stats
    ));
}

/**
 * AJAX Handler: Test SMTP configuration
 * @since 1.5.4
 */
add_action('wp_ajax_eipsi_test_smtp', 'eipsi_test_smtp_handler');

function eipsi_test_smtp_handler() {
    check_ajax_referer('eipsi_admin_nonce', 'nonce');

    if (!EIPSI_Admin_Authorization_Policy::allows('email.manage')) {
        wp_send_json_error(array('message' => __('Unauthorized', 'eipsi-forms')), 403);
    }

    if (!class_exists('EIPSI_SMTP_Service')) {
        wp_send_json_error(array('message' => __('SMTP Service not available', 'eipsi-forms')));
    }

    $smtp_service = new EIPSI_SMTP_Service();

    // Get test email from request or use default
    $test_email = isset($_POST['test_email']) ? sanitize_email(wp_unslash($_POST['test_email'])) : '';
    if (empty($test_email)) {
        $test_email = get_option('eipsi_investigator_email', get_option('admin_email'));
    }

    if (!is_email($test_email)) {
        wp_send_json_error(array(
            'message' => __('Email inválido', 'eipsi-forms'),
            'details' => "El email especificado no es válido: $test_email"
        ));
    }

    // Get current SMTP config
    $config = $smtp_service->get_config();

    if (!$config) {
        wp_send_json_error(array(
            'message' => __('SMTP no configurado', 'eipsi-forms'),
            'details' => __('Primero debes configurar y guardar los datos SMTP antes de probar.', 'eipsi-forms')
        ));
    }

    // Send test email
    $result = $smtp_service->send_test_email($config, $test_email);

    if ($result['success']) {
        wp_send_json_success(array(
            'message' => __('Email de prueba SMTP enviado exitosamente', 'eipsi-forms'),
            'details' => sprintf(
                __('Método: SMTP | Servidor: %s:%d | Destinatario: %s', 'eipsi-forms'),
                $config['host'],
                $config['port'],
                $test_email
            )
        ));
    } else {
        $error_message = $result['error'] ?? 'Error desconocido';
        wp_send_json_error(array(
            'message' => __('Error al enviar email de prueba SMTP', 'eipsi-forms'),
            'details' => $error_message
        ));
    }
}