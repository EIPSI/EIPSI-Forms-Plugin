<?php
/**
 * EIPSI Forms - Participant AJAX Handlers
 * 
 * Handles AJAX requests for participant authentication:
 * - Registration
 * - Login
 * - Logout
 * - Session info
 * 
 * @package EIPSI_Forms
 * @since 1.5.5
 */

if (!defined('ABSPATH')) {
    exit;
}

// =============================================================================
// PARTICIPANT AUTHENTICATION AJAX HANDLERS
// =============================================================================

/**
 * AJAX Handler: Participant Registration
 * 
 * Registers a new participant for a longitudinal study.
 * 
 * @since 1.5.5
 */
function eipsi_participant_register_handler() {
    // Verify nonce
    check_ajax_referer('eipsi_participant_auth', 'nonce');
    
    // Sanitize and validate inputs
    $survey_id = isset($_POST['survey_id']) ? absint($_POST['survey_id']) : 0;
    $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
    $study_code = isset($_POST['study_code']) ? sanitize_text_field(wp_unslash($_POST['study_code'])) : '';
    $redirect_to = isset($_POST['redirect_url']) ? esc_url_raw(wp_unslash($_POST['redirect_url'])) : '';

    if (function_exists('eipsi_resolve_survey_context')) {
        $resolved_context = eipsi_resolve_survey_context($survey_id, $study_code, $redirect_to);
        $survey_id = !empty($resolved_context['survey_id']) ? (int) $resolved_context['survey_id'] : $survey_id;
        $study_code = !empty($resolved_context['study_code']) ? $resolved_context['study_code'] : $study_code;
    }

    if (empty($survey_id)) {
        wp_send_json_error(array(
            'message' => __('No pudimos identificar el estudio para este acceso. Abrí nuevamente el enlace del estudio e intentá otra vez.', 'eipsi-forms'),
            'code' => 'study_not_resolved'
        ));
    }

    if (empty($email) || !is_email($email)) {
        wp_send_json_error(array(
            'message' => __('Por favor ingresa un email válido.', 'eipsi-forms'),
            'code' => 'invalid_email'
        ));
    }
    
    // Ensure services are loaded
    if (!class_exists('EIPSI_Participant_Service')) {
        require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-participant-service.php';
    }
    if (!class_exists('EIPSI_Auth_Service')) {
        require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-auth-service.php';
    }
    
    if (!EIPSI_Authentication_Service::allow_public_request($email, $survey_id)) {
        wp_send_json_error(array('message' => __('Demasiados intentos. Intentá nuevamente en 15 minutos.', 'eipsi-forms'), 'code' => 'rate_limited'));
    }

    $registration = EIPSI_Participant_Registration_Service::register_passwordless($survey_id, $email);
    $double_opt_in = $registration['double_opt_in'];
    $result = $registration['result'];

    if (!$result['success']) {
        // Special handling for email_exists - provide friendly options
        if ($result['error'] === 'email_exists') {
            // Check if participant is active
            $existing_participant = EIPSI_Participant_Service::get_by_email($survey_id, $email);
            $is_active = $existing_participant && $existing_participant->is_active;
            
            $options = array();
            
            if ($is_active) {
                // Active participant - offer login or magic link
                wp_send_json_error(array(
                    'message' => __('Este email ya está registrado en este estudio.', 'eipsi-forms'),
                    'code' => 'email_exists',
                    'existing_email' => true,
                    'options' => array(
                        'login' => array(
                            'label' => __('Iniciar sesión', 'eipsi-forms'),
                            'description' => __('¿Ya tienes una cuenta? Inicia sesión con tu contraseña.', 'eipsi-forms')
                        ),
                        'magic_link' => array(
                            'label' => __('Solicitar Magic Link', 'eipsi-forms'),
                            'description' => __('¿Olvidaste tu contraseña? Te enviamos un enlace de acceso por email.', 'eipsi-forms')
                        )
                    )
                ));
            } else {
                // Inactive participant - offer to reactivate
                wp_send_json_error(array(
                    'message' => __('Este email ya está registrado pero tu cuenta está desactivada.', 'eipsi-forms'),
                    'code' => 'email_exists_inactive',
                    'existing_email' => true,
                    'is_inactive' => true,
                    'options' => array(
                        'contact' => array(
                            'label' => __('Contactar al investigador', 'eipsi-forms'),
                            'description' => __('Tu cuenta está inactiva. Por favor contacta al investigador del estudio para reactivarla.', 'eipsi-forms')
                        )
                    )
                ));
            }
        }
        
        $error_messages = array(
            'invalid_email' => __('El email ingresado no es válido.', 'eipsi-forms'),
            'short_password' => __('La contraseña debe tener al menos 8 caracteres.', 'eipsi-forms'),
            'db_error' => __('Error al crear el registro. Intenta nuevamente.', 'eipsi-forms')
        );
        
        wp_send_json_error(array(
            'message' => isset($error_messages[$result['error']]) ? $error_messages[$result['error']] : __('Error en el registro.', 'eipsi-forms'),
            'code' => $result['error']
        ));
    }
    
    // v1.5.7 - Handle double opt-in flow
    if ($double_opt_in) {
        EIPSI_Participant_Registration_Service::send_confirmation($survey_id, $result['participant_id'], $email);

        // Return response indicating confirmation is required (no session created)
        wp_send_json_success(array(
            'message' => __('Revisá tu bandeja de entrada para confirmar tu email.', 'eipsi-forms'),
            'participant_id' => $result['participant_id'],
            'requires_confirmation' => true,
            'auto_login' => false
        ));
    }
    
    // Even when registration does not require double opt-in, email is not a credential.
    wp_send_json_success(EIPSI_Authentication_Service::request_magic_link($survey_id, $email));
}
add_action('wp_ajax_nopriv_eipsi_participant_register', 'eipsi_participant_register_handler');
add_action('wp_ajax_eipsi_participant_register', 'eipsi_participant_register_handler');

/**
 * AJAX Handler: Participant Login
 * 
 * Authenticates a participant and creates a session.
 * 
 * @since 1.5.5
 */
function eipsi_participant_login_handler() {
    // Verify nonce
    check_ajax_referer('eipsi_participant_auth', 'nonce');
    
    // Sanitize and validate inputs
    $survey_id = isset($_POST['survey_id']) ? absint($_POST['survey_id']) : 0;
    $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
    $study_code = isset($_POST['study_code']) ? sanitize_text_field(wp_unslash($_POST['study_code'])) : '';
    $redirect_to = isset($_POST['redirect_url']) ? esc_url_raw(wp_unslash($_POST['redirect_url'])) : '';

    if (function_exists('eipsi_resolve_survey_context')) {
        $resolved_context = eipsi_resolve_survey_context($survey_id, $study_code, $redirect_to);
        $survey_id = !empty($resolved_context['survey_id']) ? (int) $resolved_context['survey_id'] : $survey_id;
        $study_code = !empty($resolved_context['study_code']) ? $resolved_context['study_code'] : $study_code;
    }

    if (empty($survey_id)) {
        wp_send_json_error(array(
            'message' => __('No pudimos identificar el estudio para este acceso. Abrí nuevamente el enlace del estudio e intentá otra vez.', 'eipsi-forms'),
            'code' => 'study_not_resolved'
        ));
    }

    if (empty($email) || !is_email($email)) {
        wp_send_json_error(array(
            'message' => __('Por favor ingresa un email válido.', 'eipsi-forms'),
            'code' => 'invalid_email'
        ));
    }

    if (!EIPSI_Authentication_Service::allow_public_request($email, $survey_id)) {
        wp_send_json_error(array('message' => __('Demasiados intentos. Intentá nuevamente en 15 minutos.', 'eipsi-forms'), 'code' => 'rate_limited'));
    }

    $password = isset($_POST['password']) && is_string($_POST['password']) ? wp_unslash($_POST['password']) : '';
    if ($password === '') {
        wp_send_json_success(EIPSI_Authentication_Service::request_magic_link($survey_id, $email));
    }

    $auth_result = EIPSI_Auth_Service::authenticate($survey_id, $email, $password);
    if (!$auth_result['success']) {
        eipsi_record_failed_login($email, $survey_id);
        wp_send_json_error(array('message' => __('Credenciales inválidas.', 'eipsi-forms'), 'code' => 'invalid_credentials'));
    }
    // The password was verified above; participant authorization is rechecked by SessionService.
    $session_result = EIPSI_Auth_Service::create_session($auth_result['participant_id'], $survey_id);
    if (!$session_result['success']) {
        wp_send_json_error(array('message' => __('Error al crear la sesión. Intenta nuevamente.', 'eipsi-forms'), 'code' => 'session_error'));
    }
    eipsi_clear_login_rate_limit($email, $survey_id);
    $redirect_url = eipsi_get_participant_redirect_url($survey_id, $auth_result['participant_id']);
    wp_send_json_success(array(
        'message' => __('¡Bienvenido! Redirigiendo...', 'eipsi-forms'),
        'participant_id' => $auth_result['participant_id'],
        'redirect' => $redirect_url,
        'session_token' => $session_result['token'],
        'cookie_name' => $session_result['cookie_name'],
    ));
}
add_action('wp_ajax_nopriv_eipsi_participant_login', 'eipsi_participant_login_handler');
add_action('wp_ajax_eipsi_participant_login', 'eipsi_participant_login_handler');

/**
 * AJAX Handler: Participant Logout
 * 
 * Destroys the current session.
 * 
 * @since 1.5.5
 */
function eipsi_participant_logout_handler() {
    // Verify nonce
    check_ajax_referer('eipsi_participant_auth', 'nonce');
    
    // Ensure service is loaded
    if (!class_exists('EIPSI_Auth_Service')) {
        require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-auth-service.php';
    }
    
    // Destroy session
    EIPSI_Auth_Service::destroy_session();
    
    wp_send_json_success(array(
        'message' => __('Sesión cerrada correctamente.', 'eipsi-forms'),
        'redirect' => home_url('/')
    ));
}
add_action('wp_ajax_nopriv_eipsi_participant_logout', 'eipsi_participant_logout_handler');
add_action('wp_ajax_eipsi_participant_logout', 'eipsi_participant_logout_handler');

/**
 * AJAX Handler: Participant Info
 * 
 * Returns information about the currently authenticated participant.
 * 
 * @since 1.5.5
 */
function eipsi_participant_info_handler() {
    // Verify nonce
    check_ajax_referer('eipsi_participant_auth', 'nonce');
    
    // Ensure services are loaded
    if (!class_exists('EIPSI_Auth_Service')) {
        require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-auth-service.php';
    }
    if (!class_exists('EIPSI_Participant_Service')) {
        require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-participant-service.php';
    }
    
    // Check if authenticated
    if (!EIPSI_Auth_Service::is_authenticated()) {
        wp_send_json_error(array(
            'message' => __('No hay sesión activa.', 'eipsi-forms'),
            'code' => 'not_authenticated'
        ));
    }
    
    // Get participant ID
    $participant_id = EIPSI_Auth_Service::get_current_participant();
    if (!$participant_id) {
        wp_send_json_error(array(
            'message' => __('Sesión inválida.', 'eipsi-forms'),
            'code' => 'invalid_session'
        ));
    }
    
    // Get participant data
    $participant = EIPSI_Participant_Service::get_by_id($participant_id);
    if (!$participant) {
        EIPSI_Auth_Service::destroy_session();
        wp_send_json_error(array(
            'message' => __('Participante no encontrado.', 'eipsi-forms'),
            'code' => 'participant_not_found'
        ));
    }
    
    // Get session info
    $session_info = EIPSI_Auth_Service::get_current_session_info();
    
    wp_send_json_success(array(
        'participant_id' => (int) $participant->id,
        'survey_id' => (int) $participant->survey_id,
        'email' => $participant->email,
        'first_name' => $participant->first_name,
        'last_name' => $participant->last_name,
        'is_active' => (bool) $participant->is_active,
        'last_login_at' => $participant->last_login_at,
        'session' => $session_info ? array(
            'expires_at' => $session_info->expires_at,
            'time_remaining_hours' => $session_info->time_remaining_hours
        ) : null
    ));
}
add_action('wp_ajax_nopriv_eipsi_participant_info', 'eipsi_participant_info_handler');
add_action('wp_ajax_eipsi_participant_info', 'eipsi_participant_info_handler');

/**
 * AJAX Handler: Request Magic Link
 * 
 * Sends a magic link to the participant's email for passwordless login.
 * 
 * @since 1.5.5
 */
function eipsi_request_magic_link_handler() {
    // Verify nonce
    check_ajax_referer('eipsi_participant_auth', 'nonce');
    
    // Sanitize inputs
    $survey_id = isset($_POST['survey_id']) ? absint($_POST['survey_id']) : 0;
    $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
    
    // Validate
    if (empty($survey_id) || empty($email) || !is_email($email)) {
        wp_send_json_error(array(
            'message' => __('Por favor ingresa un email válido.', 'eipsi-forms'),
            'code' => 'invalid_input'
        ));
    }
    
    if (!EIPSI_Authentication_Service::allow_public_request($email, $survey_id)) {
        wp_send_json_error(array('message' => __('Demasiados intentos. Intentá nuevamente en 15 minutos.', 'eipsi-forms'), 'code' => 'rate_limited'));
    }
    wp_send_json_success(EIPSI_Authentication_Service::request_magic_link($survey_id, $email));
}
add_action('wp_ajax_nopriv_eipsi_request_magic_link', 'eipsi_request_magic_link_handler');
add_action('wp_ajax_eipsi_request_magic_link', 'eipsi_request_magic_link_handler');

/**
 * AJAX Handler: Validate Magic Link Token
 * 
 * Validates a magic link token from the URL.
 * 
 * @since 1.5.5
 */
function eipsi_validate_magic_link_token_handler() {
    // Get token from POST
    $token = isset($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '';
    
    if (empty($token)) {
        wp_send_json_error(array(
            'message' => __('Token no proporcionado.', 'eipsi-forms'),
            'code' => 'empty_token'
        ));
    }
    
    // Ensure service is loaded
    if (!class_exists('EIPSI_MagicLinksService')) {
        require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-magic-links-service.php';
    }
    
    // Validate token
    $result = EIPSI_MagicLinksService::validate_magic_link($token);
    
    if (!$result['valid']) {
        $error_messages = array(
            'empty_token' => __('Token no proporcionado.', 'eipsi-forms'),
            'not_found' => __('Token inválido.', 'eipsi-forms'),
            'already_used' => __('Este enlace ya fue utilizado.', 'eipsi-forms'),
            'expired' => __('Este enlace ha expirado. Solicita uno nuevo.', 'eipsi-forms')
        );
        
        wp_send_json_error(array(
            'message' => isset($error_messages[$result['reason']]) ? $error_messages[$result['reason']] : __('Token inválido.', 'eipsi-forms'),
            'code' => $result['reason']
        ));
    }
    
    wp_send_json_success(array(
        'valid' => true,
        'survey_id' => (int) $result['survey_id'],
        'participant_id' => (int) $result['participant_id'],
        'expires_at' => $result['expires_at']
    ));
}
add_action('wp_ajax_nopriv_eipsi_validate_magic_link_token', 'eipsi_validate_magic_link_token_handler');
add_action('wp_ajax_eipsi_validate_magic_link_token', 'eipsi_validate_magic_link_token_handler');

/**
 * AJAX Handler: Check Session Remaining Time
 * 
 * Returns the remaining time for the current session.
 * 
 * @since 2.0.0
 */
function eipsi_check_session_time_handler() {
    // Verify nonce
    check_ajax_referer('eipsi_participant_auth', 'nonce');
    
    // Ensure service is loaded
    if (!class_exists('EIPSI_Auth_Service')) {
        require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-auth-service.php';
    }
    
    // Get remaining time
    $result = EIPSI_Auth_Service::get_session_remaining_time();
    
    if ($result['is_expired']) {
        wp_send_json_error(array(
            'message' => __('Tu sesión ha expirado.', 'eipsi-forms'),
            'code' => 'session_expired',
            'remaining_seconds' => 0,
            'expires_at' => null
        ));
    }
    
    wp_send_json_success(array(
        'remaining_seconds' => $result['remaining_seconds'],
        'expires_at' => $result['expires_at'],
        'is_expired' => false
    ));
}
add_action('wp_ajax_nopriv_eipsi_check_session_time', 'eipsi_check_session_time_handler');
add_action('wp_ajax_eipsi_check_session_time', 'eipsi_check_session_time_handler');

/**
 * AJAX Handler: Extend Session
 * 
 * Extends the current session expiration time.
 * 
 * @since 2.0.0
 */
function eipsi_extend_session_handler() {
    // Verify nonce
    check_ajax_referer('eipsi_participant_auth', 'nonce');
    
    // Ensure service is loaded
    if (!class_exists('EIPSI_Auth_Service')) {
        require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-auth-service.php';
    }
    
    // Get TTL from request (default 168 hours = 7 days)
    $ttl_hours = isset($_POST['ttl_hours']) ? absint($_POST['ttl_hours']) : 168;
    
    // Validate TTL (max 30 days)
    if ($ttl_hours < 1 || $ttl_hours > 720) {
        $ttl_hours = 168;
    }
    
    // Extend session
    $result = EIPSI_Auth_Service::extend_session($ttl_hours);
    
    if (!$result['success']) {
        $error_messages = array(
            'no_session' => __('No hay sesión activa.', 'eipsi-forms'),
            'session_not_found' => __('Sesión no encontrada.', 'eipsi-forms'),
            'session_expired' => __('Tu sesión ya ha expirado. Por favor inicia sesión nuevamente.', 'eipsi-forms'),
            'db_error' => __('Error al extender la sesión. Intenta nuevamente.', 'eipsi-forms')
        );
        
        wp_send_json_error(array(
            'message' => isset($error_messages[$result['error']]) ? $error_messages[$result['error']] : __('Error desconocido.', 'eipsi-forms'),
            'code' => $result['error']
        ));
    }
    
    // Get new remaining time
    $remaining = EIPSI_Auth_Service::get_session_remaining_time();
    
    wp_send_json_success(array(
        'message' => __('¡Sesión extendida exitosamente!', 'eipsi-forms'),
        'new_expires_at' => $result['new_expires_at'],
        'remaining_seconds' => $remaining['remaining_seconds']
    ));
}
add_action('wp_ajax_nopriv_eipsi_extend_session', 'eipsi_extend_session_handler');
add_action('wp_ajax_eipsi_extend_session', 'eipsi_extend_session_handler');

/**
 * AJAX Handler: Check Participant Session Status
 * 
 * Returns whether the participant has an active session.
 * Used by login page to redirect already-logged-in users.
 * 
 * @since 1.5.5
 */
function eipsi_participant_check_session_handler() {
    // Verify nonce
    check_ajax_referer('eipsi_participant_auth', 'nonce');
    
    // Ensure service is loaded
    if (!class_exists('EIPSI_Auth_Service')) {
        require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-auth-service.php';
    }
    
    // Check if authenticated
    $is_authenticated = EIPSI_Auth_Service::is_authenticated();
    
    if (!$is_authenticated) {
        wp_send_json_error(array(
            'message' => __('No hay sesión activa.', 'eipsi-forms'),
            'code' => 'not_authenticated'
        ));
    }
    
    // Get participant and survey IDs
    $participant_id = EIPSI_Auth_Service::get_current_participant();
    $survey_id = EIPSI_Auth_Service::get_current_survey();
    
    wp_send_json_success(array(
        'authenticated' => true,
        'participant_id' => $participant_id,
        'survey_id' => $survey_id
    ));
}
add_action('wp_ajax_nopriv_eipsi_participant_check_session', 'eipsi_participant_check_session_handler');
add_action('wp_ajax_eipsi_participant_check_session', 'eipsi_participant_check_session_handler');

// =============================================================================
// HELPER FUNCTIONS
// =============================================================================

/**
 * Get the redirect URL for a participant after login/registration.
 * 
 * @param int $survey_id Survey/Study ID
 * @param int $participant_id Participant ID
 * @return string Redirect URL
 */
function eipsi_get_participant_redirect_url($survey_id, $participant_id) {
    // Check for a custom redirect URL in session or POST
    if (isset($_POST['redirect_url']) && !empty($_POST['redirect_url'])) {
        $custom_redirect = esc_url_raw(wp_unslash($_POST['redirect_url']));
        
        // Security: Prevent open redirect (v1.6.0)
        // Only allow redirect if it's a local URL within the same domain
        $parsed      = wp_parse_url( $custom_redirect );
        $home_parsed = wp_parse_url( home_url() );
        if ( ! isset( $parsed['host'] ) || $parsed['host'] === $home_parsed['host'] ) {
            return $custom_redirect;
        }
    }
    
    // NEW PRIORITY: Exact match by eipsi_study_id (Avoids false positives in Pools)
    $precise_pages = get_posts(array(
        'post_type'      => 'page',
        'post_status'    => 'publish',
        'posts_per_page' => 1,
        'meta_query'     => array(
            array(
                'key'   => 'eipsi_study_id',
                'value' => $survey_id,
            )
        )
    ));

    if (!empty($precise_pages)) {
        return get_permalink($precise_pages[0]->ID);
    }
    
    // Method 1: Check survey meta for assigned dashboard page
    $assigned_dashboard = get_post_meta($survey_id, '_eipsi_dashboard_page_id', true);
    if (!empty($assigned_dashboard)) {
        $permalink = get_permalink($assigned_dashboard);
        if ($permalink) {
            return $permalink;
        }
    }
    
    // Method 2: Find page with eipsi_participant_dashboard shortcode by meta key (more reliable than search)
    $dashboard_pages = get_posts(array(
        'post_type' => 'page',
        'post_status' => 'publish',
        'posts_per_page' => 10,
        'meta_query' => array(
            array(
                'key' => '_eipsi_has_dashboard',
                'value' => '1'
            )
        )
    ));
    
    if (!empty($dashboard_pages)) {
        // Return first matching page
        return get_permalink($dashboard_pages[0]->ID);
    }
    
    // Method 3: Legacy search - try to find a page containing the shortcode in content
    $dashboard_page = get_posts(array(
        'post_type' => 'page',
        'post_status' => 'publish',
        'posts_per_page' => 1,
        's' => 'eipsi_participant_dashboard'
    ));
    
    if (!empty($dashboard_page)) {
        return get_permalink($dashboard_page[0]->ID);
    }
    
    // Method 4: Try to find a page with the survey login shortcode for this survey
    $login_pages = get_posts(array(
        'post_type' => 'page',
        'post_status' => 'publish',
        'posts_per_page' => 1,
        'meta_query' => array(
            array(
                'key' => '_eipsi_survey_id',
                'value' => $survey_id
            )
        )
    ));
    
    if (!empty($login_pages)) {
        return get_permalink($login_pages[0]->ID);
    }
    
    // Default to home page
    return home_url('/');
}

/**
 * Helper function to get the current participant ID.
 * 
 * @return int|null
 */
if (!function_exists('eipsi_get_current_participant_id')) {
    function eipsi_get_current_participant_id() {
        if (!class_exists('EIPSI_Auth_Service')) {
            require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-auth-service.php';
        }
        
        // Priority 1: From verified session
        $participant_id = EIPSI_Auth_Service::get_current_participant();
        if ($participant_id) {
            return (int) $participant_id;
        }

        // Priority 2: From POST (context resolution fallback)
        if (!empty($_POST['participant_id']) && is_numeric($_POST['participant_id'])) {
            return (int) $_POST['participant_id'];
        }

        // Priority 3: From Cookie (legacy fallback)
        if (isset($_COOKIE['eipsi_participant_id']) && is_numeric($_COOKIE['eipsi_participant_id'])) {
            return (int) $_COOKIE['eipsi_participant_id'];
        }
        
        return null;
    }
}

/**
 * Helper function to get the current survey ID from session.
 * 
 * @return int|null
 */
if (!function_exists('eipsi_get_current_survey_id')) {
    function eipsi_get_current_survey_id() {
        if (!class_exists('EIPSI_Auth_Service')) {
            require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-auth-service.php';
        }
        
        return EIPSI_Auth_Service::get_current_survey();
    }
}

// Participant dashboard refresh: identity comes only from the existing session.
add_action('wp_ajax_eipsi_get_participant_dashboard','eipsi_get_participant_dashboard_handler');
add_action('wp_ajax_nopriv_eipsi_get_participant_dashboard','eipsi_get_participant_dashboard_handler');
function eipsi_get_participant_dashboard_handler() {
    if (!is_string($_POST['nonce']??null) || !wp_verify_nonce($_POST['nonce'],'eipsi_participant_dashboard')) {
        wp_send_json_error(array('message'=>'Invalid nonce'),403);
    }
    $access=EIPSI_Authorization_Policy::authorize_session_context($_POST);
    if (!$access['success']) { wp_send_json_error(array('message'=>'Not authenticated'),403); }
    $record=EIPSI_Authorization_Policy::authorize_participant($access['participant_id'],$access['study_id']);
    if (!$record['success']) { wp_send_json_error(array('message'=>'Not authenticated'),403); }
    $data=EIPSI_Participant_Dashboard_Data::get_timeline_data($access['participant_id'],$access['study_id']);
    $completed=0;$pending=0;
    foreach($data['timeline'] as $wave) {
        if($wave['db_status']==='submitted'){$completed++;}
        if(in_array($wave['db_status'],array('pending','in_progress'),true)){$pending++;}
    }
    $data['completed_waves']=$completed;$data['pending_waves']=$pending;
    $data['progress_percentage']=count($data['timeline'])?(int)round(100*$completed/count($data['timeline'])):0;
    wp_send_json_success($data);
}
