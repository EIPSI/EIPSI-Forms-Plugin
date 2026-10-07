<?php
/**
 * EIPSI_Participant_Auth_Handler
 *
 * Maneja la autenticación de participantes via AJAX:
 * - Login con email/password
 * - Registro con study_code
 * - Magic link para password reset
 * - Rate limiting
 * - CSRF protection
 *
 * @package EIPSI_Forms
 * @subpackage Services
 * @version 2.1.0
 * @since Phase 2 - Task 2A
 */

if (!defined('ABSPATH')) {
    exit;
}

class EIPSI_Participant_Auth_Handler {
    
    /**
     * Initialize AJAX handlers.
     *
     * NOTE (v2.0.0 fix): Login and register hooks are intentionally NOT registered here.
     * They are registered exclusively in ajax-participant-handlers.php to avoid duplicate
     * handler execution (which caused "Campos requeridos faltantes" error in login/register).
     * Only actions exclusive to this class (magic_link via this class) are registered here.
     *
     * @since 2.0.0
     */
    public static function init() {
        // DISABLED - These hooks are registered in ajax-participant-handlers.php
        // Registering them here too caused duplicate execution and broken auth flow.
        // add_action('wp_ajax_eipsi_participant_login', array(__CLASS__, 'handle_login'));
        // add_action('wp_ajax_nopriv_eipsi_participant_login', array(__CLASS__, 'handle_login'));
        // add_action('wp_ajax_eipsi_participant_register', array(__CLASS__, 'handle_register'));
        // add_action('wp_ajax_nopriv_eipsi_participant_register', array(__CLASS__, 'handle_register'));
        // add_action('wp_ajax_eipsi_participant_logout', array(__CLASS__, 'handle_logout'));
        // add_action('wp_ajax_nopriv_eipsi_participant_logout', array(__CLASS__, 'handle_logout'));
        // add_action('wp_ajax_eipsi_participant_check_session', array(__CLASS__, 'handle_check_session'));
        // add_action('wp_ajax_nopriv_eipsi_participant_check_session', array(__CLASS__, 'handle_check_session'));

        // Magic Link (unique to this class - different action name)
        add_action('wp_ajax_eipsi_participant_magic_link', array(__CLASS__, 'handle_magic_link_request'));
        add_action('wp_ajax_nopriv_eipsi_participant_magic_link', array(__CLASS__, 'handle_magic_link_request'));
    }

    /**
     * Resolve and validate a post-auth redirect URL.
     *
     * Priority: redirect_url POST param → redirect_to POST param → fallback dashboard.
     * Security: only same-domain URLs are accepted; external URLs are silently dropped.
     * Fallback: uses eipsi_get_participant_redirect_url() if available, otherwise home_url('/').
     *
     * @since 2.1.0
     * @param int $survey_id Survey ID for dashboard fallback.
     * @return string Validated, absolute redirect URL.
     */
    private static function resolve_redirect_url( $survey_id = 0 ) {
        $redirect_url = isset( $_POST['redirect_url'] ) ? esc_url_raw( $_POST['redirect_url'] ) : '';

        // Fall back to redirect_to if redirect_url is empty
        if ( empty( $redirect_url ) ) {
            $redirect_url = isset( $_POST['redirect_to'] ) ? esc_url_raw( $_POST['redirect_to'] ) : '';
        }

        // Security: reject external / cross-domain redirects
        if ( ! empty( $redirect_url ) ) {
            $parsed      = wp_parse_url( $redirect_url );
            $home_parsed = wp_parse_url( home_url() );

            // Allow relative URLs (no host) and same-host URLs only
            if ( isset( $parsed['host'] ) && $parsed['host'] !== $home_parsed['host'] ) {
                $redirect_url = ''; // Drop external URL
            }
        }

        // Fallback: use plugin helper if available, otherwise home_url
        if ( empty( $redirect_url ) ) {
            if ( function_exists( 'eipsi_get_participant_redirect_url' ) ) {
                $redirect_url = eipsi_get_participant_redirect_url( $survey_id );
            } else {
                $redirect_url = home_url( '/' );
            }
        }

        return $redirect_url;
    }
    
    /**
     * Handle participant login (passwordless - email only).
     *
     * @since 2.0.0
     */
    public static function handle_login() {
        // Verify nonce (CSRF protection)
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'eipsi_participant_auth')) {
            wp_send_json_error(array(
                'code' => 'invalid_nonce',
                'message' => __('Error de seguridad. Por favor, recargá la página.', 'eipsi-forms')
            ));
            wp_die();
        }

        // Get and validate inputs
        $email     = sanitize_email( $_POST['email'] ?? '' );
        $survey_id = absint( $_POST['survey_id'] ?? 0 );
        $remember  = ! empty( $_POST['remember'] );

        // Validate email
        if (!is_email($email)) {
            wp_send_json_error(array(
                'code' => 'invalid_email',
                'message' => __('Por favor, ingresá un email válido.', 'eipsi-forms')
            ));
            wp_die();
        }

        // Resolve survey_id from study_code if not provided
        $study_code = sanitize_text_field( $_POST['study_code'] ?? '' );
        if ( ! empty( $study_code ) && empty( $survey_id ) ) {
            global $wpdb;
            $study = $wpdb->get_row( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}survey_studies WHERE study_code = %s",
                $study_code
            ) );
            if ( $study ) {
                $survey_id = (int) $study->id;
            }
        }

        // FIX (v2.1.0): survey_id is required — cannot authenticate without a study context
        if ( empty( $survey_id ) ) {
            wp_send_json_error( array(
                'code'    => 'missing_study',
                'message' => __( 'No se pudo determinar el estudio. Por favor, recargá la página.', 'eipsi-forms' ),
            ) );
            wp_die();
        }

        // Check rate limit
        $rate_limit = EIPSI_Participant_Access_Log_Service::check_rate_limit($email, $survey_id);
        if (!$rate_limit['allowed']) {
            wp_send_json_error(array(
                'code' => 'rate_limited',
                'message' => sprintf(
                    __('Demasiados intentos fallidos. Por favor, esperá %d minutos antes de intentar nuevamente.', 'eipsi-forms'),
                    ceil($rate_limit['retry_after'] / 60)
                ),
                'retry_after' => $rate_limit['retry_after']
            ));
            wp_die();
        }

        if (!EIPSI_Authentication_Service::allow_public_request($email, $survey_id)) {
            wp_send_json_error(array('code' => 'rate_limited', 'message' => __('Demasiados intentos. Intentá nuevamente en 15 minutos.', 'eipsi-forms')));
        }
        $password = isset($_POST['password']) && is_string($_POST['password']) ? wp_unslash($_POST['password']) : '';
        if ($password === '') {
            wp_send_json_success(EIPSI_Authentication_Service::request_magic_link($survey_id, $email));
        }
        $result = EIPSI_Auth_Service::authenticate($survey_id, $email, $password);
        if (!$result['success']) {
            EIPSI_Participant_Access_Log_Service::log(0, $survey_id, 'login_failed', array('reason' => $result['error']));
            wp_send_json_error(array('code' => 'invalid_credentials', 'message' => __('Credenciales inválidas.', 'eipsi-forms')));
        }

        // Create session
        $ttl_hours    = $remember ? 720 : 168; // 30 days if remember, 7 days otherwise
        $session_result = EIPSI_Auth_Service::create_session($result['participant_id'], $survey_id, $ttl_hours);

        if (!$session_result['success']) {
            wp_send_json_error(array(
                'code' => 'session_error',
                'message' => __('Error al crear la sesión. Por favor, intentá nuevamente.', 'eipsi-forms')
            ));
            wp_die();
        }

        // Log successful login
        EIPSI_Participant_Access_Log_Service::log($result['participant_id'], $survey_id, 'login', array(
            'email'    => $email,
            'remember' => $remember
        ));

        // FIX (v2.1.0): use centralized redirect resolver
        $redirect_url = self::resolve_redirect_url( $survey_id );

        wp_send_json_success(array(
            'message'      => __('¡Bienvenido/a! Redirigiendo...', 'eipsi-forms'),
            'redirect_url' => $redirect_url
        ));

        wp_die();
    }
    
    /**
     * Handle participant registration (passwordless - email + terms only).
     *
     * @since 2.0.0
     */
    public static function handle_register() {
        // Verify nonce (CSRF protection)
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'eipsi_participant_auth')) {
            wp_send_json_error(array(
                'code' => 'invalid_nonce',
                'message' => __('Error de seguridad. Por favor, recargá la página.', 'eipsi-forms')
            ));
            wp_die();
        }

        // Get and validate inputs
        $email        = sanitize_email( $_POST['email'] ?? '' );
        $study_code   = sanitize_text_field( $_POST['study_code'] ?? '' );
        $survey_id    = absint( $_POST['survey_id'] ?? 0 );
        $accept_terms = isset( $_POST['accept_terms'] ) && $_POST['accept_terms'] === '1';

        // Validate email
        if (!is_email($email)) {
            wp_send_json_error(array(
                'code' => 'invalid_email',
                'message' => __('Por favor, ingresá un email válido.', 'eipsi-forms')
            ));
            wp_die();
        }

        // Validate terms acceptance
        if (!$accept_terms) {
            wp_send_json_error(array(
                'code' => 'terms_required',
                'message' => __('Debés aceptar los términos y condiciones para continuar.', 'eipsi-forms')
            ));
            wp_die();
        }

        // Get survey_id from study_code if provided
        $study = null;
        if (!empty($study_code) && empty($survey_id)) {
            global $wpdb;
            $study = $wpdb->get_row($wpdb->prepare(
                "SELECT id, study_name, status, config FROM {$wpdb->prefix}survey_studies WHERE study_code = %s",
                $study_code
            ));

            if (!$study) {
                wp_send_json_error(array(
                    'code' => 'invalid_study_code',
                    'message' => __('El código de estudio no es válido.', 'eipsi-forms')
                ));
                wp_die();
            }

            if (!in_array($study->status, array('active', 'paused'))) {
                wp_send_json_error(array(
                    'code' => 'study_not_active',
                    'message' => __('El estudio no está disponible actualmente.', 'eipsi-forms')
                ));
                wp_die();
            }

            $survey_id = (int) $study->id;
        }

        // Validate survey_id
        if (empty($survey_id)) {
            wp_send_json_error(array(
                'code' => 'missing_study',
                'message' => __('Por favor, ingresá un código de estudio válido.', 'eipsi-forms')
            ));
            wp_die();
        }

        // FIX (v2.1.0): Load study config to check double opt-in setting if not already loaded
        if ( null === $study ) {
            global $wpdb;
            $study = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, config FROM {$wpdb->prefix}survey_studies WHERE id = %d",
                $survey_id
            ) );
        }

        $study_config   = ( $study && ! empty( $study->config ) ) ? json_decode( $study->config, true ) : array();
        $double_opt_in  = !isset($study_config['double_opt_in']) || $study_config['double_opt_in'] === true;

        if (!EIPSI_Authentication_Service::allow_public_request($email, $survey_id)) {
            wp_send_json_error(array('code' => 'rate_limited', 'message' => __('Demasiados intentos. Intentá nuevamente en 15 minutos.', 'eipsi-forms')));
        }

        // Check if email already exists for this study
        $existing_participant = EIPSI_Participant_Service::get_by_email($survey_id, $email);
        if ($existing_participant) {
            EIPSI_Participant_Access_Log_Service::log(0, $survey_id, 'registration', array(
                'email'  => $email,
                'status' => 'email_exists'
            ));

            wp_send_json_error(array(
                'code'            => 'email_exists',
                'message'         => __('Ya existe una cuenta con ese email en este estudio.', 'eipsi-forms'),
                'show_login_link' => true
            ));
            wp_die();
        }

        // Create participant (passwordless - no password, no names)
        $result = EIPSI_Participant_Service::create_participant_with_status(
            $survey_id,
            $email,
            null, // No password for passwordless flow
            array(
                'first_name' => '',
                'last_name'  => ''
            ),
            !$double_opt_in
        );

        if (!$result['success']) {
            $error_messages = array(
                'invalid_email' => __('El email no es válido.', 'eipsi-forms'),
                'email_exists'  => __('Ya existe una cuenta con ese email.', 'eipsi-forms'),
                'db_error'      => __('Error al crear la cuenta. Por favor, intentá nuevamente.', 'eipsi-forms')
            );

            wp_send_json_error(array(
                'code'    => $result['error'],
                'message' => $error_messages[$result['error']] ?? __('Error en el registro.', 'eipsi-forms')
            ));
            wp_die();
        }

        // Log successful registration
        EIPSI_Participant_Access_Log_Service::log($result['participant_id'], $survey_id, 'registration', array(
            'email' => $email
        ));

        // FIX (v2.1.0): Only auto-login if double opt-in is NOT required.
        // If double opt-in is enabled, the participant must confirm their email first.
        if ( $double_opt_in ) {
            EIPSI_Participant_Registration_Service::send_confirmation($survey_id, $result['participant_id'], $email);
            wp_send_json_success( array(
                'message'        => __( '¡Registro exitoso! Revisá tu email para confirmar tu cuenta.', 'eipsi-forms' ),
                'requires_confirmation' => true,
                'redirect_url'   => '',
            ) );
            wp_die();
        }

        wp_send_json_success(EIPSI_Authentication_Service::request_magic_link($survey_id, $email));
        wp_die();
    }
    
    /**
     * Handle magic link request (for login and password reset).
     *
     * @since 2.0.0
     */
    public static function handle_magic_link_request() {
        // Verify nonce (CSRF protection)
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'eipsi_participant_auth')) {
            wp_send_json_error(array(
                'code' => 'invalid_nonce',
                'message' => __('Error de seguridad. Por favor, recargá la página.', 'eipsi-forms')
            ));
            wp_die();
        }
        
        // Get and validate inputs
        $email      = sanitize_email( $_POST['email'] ?? '' );
        $study_code = sanitize_text_field( $_POST['study_code'] ?? '' );
        $survey_id  = absint( $_POST['survey_id'] ?? 0 );
        
        // Validate email
        if (!is_email($email)) {
            wp_send_json_error(array(
                'code' => 'invalid_email',
                'message' => __('Por favor, ingresá un email válido.', 'eipsi-forms')
            ));
            wp_die();
        }
        
        // Get survey_id from study_code if provided
        if (!empty($study_code) && empty($survey_id)) {
            global $wpdb;
            $study = EIPSI_Participant_Repository::study_by_code($study_code);
            if ($study) {
                $survey_id = (int) $study->id;
            }
        }
        
        if (!EIPSI_Authentication_Service::allow_public_request($email, $survey_id)) {
            wp_send_json_error(array('code' => 'rate_limited', 'message' => __('Demasiados intentos. Intentá nuevamente en 15 minutos.', 'eipsi-forms')));
        }
        wp_send_json_success(EIPSI_Authentication_Service::request_magic_link($survey_id, $email));

        wp_die();
    }
    
    /**
     * Handle participant logout.
     *
     * @since 2.0.0
     */
    public static function handle_logout() {
        // Verify nonce (CSRF protection)
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'eipsi_participant_logout')) {
            wp_send_json_error(array(
                'code' => 'invalid_nonce',
                'message' => __('Error de seguridad.', 'eipsi-forms')
            ));
            wp_die();
        }
        
        // Get participant info before destroying session
        $participant_id = EIPSI_Auth_Service::get_current_participant();
        $survey_id      = EIPSI_Auth_Service::get_current_survey();
        
        // Log logout
        if ($participant_id) {
            EIPSI_Participant_Access_Log_Service::log($participant_id, $survey_id, 'logout');
        }
        
        // Destroy session
        if (!EIPSI_Auth_Service::destroy_session()) {
            wp_send_json_error(array('message' => __('No se pudo revocar la sesión. Reintentá cerrar sesión.', 'eipsi-forms')), 500);
        }
        
        wp_send_json_success(array(
            'message'      => __('Sesión cerrada exitosamente.', 'eipsi-forms'),
            'redirect_url' => home_url('/')
        ));
        
        wp_die();
    }
    
    /**
     * Check session status.
     *
     * @since 2.0.0
     */
    public static function handle_check_session() {
        $participant_id = EIPSI_Auth_Service::get_current_participant();
        
        if (!$participant_id) {
            wp_send_json_error(array(
                'code'    => 'no_session',
                'message' => __('No hay sesión activa.', 'eipsi-forms')
            ));
            wp_die();
        }
        
        $session_info = EIPSI_Auth_Service::get_current_session_info();
        $participant  = EIPSI_Participant_Service::get_by_id($participant_id);
        
        wp_send_json_success(array(
            'participant_id'       => $participant_id,
            'email'                => $participant->email ?? '',
            'first_name'           => $participant->first_name ?? '',
            'time_remaining_hours' => $session_info->time_remaining_hours ?? 0
        ));
        
        wp_die();
    }
}

// Initialize
EIPSI_Participant_Auth_Handler::init();
