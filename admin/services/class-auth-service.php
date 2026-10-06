<?php
/**
 * EIPSI_Auth_Service
 *
 * Maneja autenticación sin login vía magic links y sesiones propias:
 * - Token generation + validation
 * - Session creation
 * - Rate limiting
 *
 * @package EIPSI_Forms
 * @subpackage Services
 * @version 1.4.2
 * @since 1.4.0
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/auth/class-authorization-policy.php';
require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/auth/class-authentication-service.php';
require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/auth/class-session-service.php';

class EIPSI_Auth_Service {

    /**
     * Shared longitudinal access policy. An undecided participant may enter to
     * consent; declined/withdrawn/inactive participants may never authorize access.
     */
    public static function authorize_participant($participant_id, $survey_id) {
        return EIPSI_Authorization_Policy::authorize_participant($participant_id, $survey_id);
    }

    /** Read both identity and study from one token, revalidating state on every use. */
    public static function get_current_session() {
        return EIPSI_Session_Service::get_current_session();
    }

    /** Client IDs may confirm a session context, never replace it. */
    public static function authorize_session_context($request = array()) {
        return EIPSI_Authorization_Policy::authorize_session_context($request);
    }

    /** Resolve actual templates for a container form name, including older slug inputs. */
    public static function get_form_template_ids($form_name) {
        return EIPSI_Authorization_Policy::get_form_template_ids($form_name);
    }

    /**
     * Authorize form identity before any persistence. Standalone submissions keep
     * their anonymous fingerprint; longitudinal submissions use only the session.
     * No timing/scheduling rules are changed here.
     */
    public static function authorize_form_operation($form_name, $request = array(), $query = array(), $operation = 'submit') {
        return EIPSI_Authorization_Policy::authorize_form_operation($form_name, $request, $query, $operation);
    }
    
    /**
     * Authenticate participant (login).
     *
     * Valida credenciales y crea sesión si son correctas.
     *
     * @param int    $survey_id ID del survey.
     * @param string $email Email del participante.
     * @param string $password Password en texto plano.
     * @return array { success: bool, participant_id: int|null, error: string|null }
     * @since 1.4.0
     * @access public
     */
    public static function authenticate($survey_id, $email, $password) {
        return EIPSI_Authentication_Service::authenticate($survey_id, $email, $password);
    }

    /**
     * Compatibility API: email-only authentication is disabled.
     *
     * Returns proof_required without resolving identity. Passwordless access requires
     * a valid secret magic-link token, never a public nonce or an email lookup.
     *
     * @param int    $survey_id ID del survey.
     * @param string $email Email del participante.
     * @return array { success: bool, participant_id: int|null, error: string|null }
     * @since 2.0.0
     * @access public
     */
    public static function authenticate_passwordless($survey_id, $email) {
        return EIPSI_Authentication_Service::authenticate_passwordless($survey_id, $email);
    }
    
    /**
     * Create session token and cookie AFTER the trusted caller has authenticated.
     *
     * La sesión se almacena en:
     * 1. Cookie HTTP-only: EIPSI_SESSION_COOKIE_NAME
     * 2. Tabla wp_survey_sessions (para invalidación)
     *
     * @param int $participant_id ID del participante.
     * @param int $survey_id ID del survey.
     * @param int $ttl_hours Tiempo de vida en horas (default 168 = 7 días).
     * @return array { success: bool, token: string|null, error: string|null }
     * @since 1.4.0
     * @access public
     */
    public static function create_session($participant_id, $survey_id, $ttl_hours = 168) {
        return EIPSI_Session_Service::create_session($participant_id, $survey_id, $ttl_hours);
    }
    
    /**
     * Get current participant from session.
     *
     * Lee la cookie del plugin y valida contra la tabla wp_survey_sessions.
     *
     * @return int|null participant_id o null si no hay sesión.
     * @since 1.4.0
     * @access public
     */
    public static function get_current_participant() {
        return EIPSI_Session_Service::get_current_participant();
    }
    
    /**
     * Get current survey from session.
     *
     * @return int|null survey_id o null si no hay sesión.
     * @since 1.4.0
     * @access public
     */
    public static function get_current_survey() {
        return EIPSI_Session_Service::get_current_survey();
    }
    
    /**
     * Destroy session (logout).
     *
     * Elimina la cookie y marca la sesión como inválida en la DB.
     *
     * @return bool True si se ejecutó el logout.
     * @since 1.4.0
     * @access public
     */
    public static function destroy_session() {
        return EIPSI_Session_Service::destroy_session();
    }
    
    /**
     * Validate active session.
     *
     * Verifica si hay una sesión válida activa.
     *
     * @return bool True si hay sesión válida.
     * @since 1.4.0
     * @access public
     */
    public static function is_authenticated() {
        return EIPSI_Session_Service::is_authenticated();
    }
    
    /**
     * Cleanup expired sessions.
     *
     * DELETE FROM wp_survey_sessions WHERE expires_at < NOW()
     *
     * @return int Número de sesiones eliminadas.
     * @since 1.4.0
     * @access public
     */
    public static function cleanup_expired_sessions() {
        return EIPSI_Session_Service::cleanup_expired_sessions();
    }
    
    /**
     * Get current session info.
     *
     * @return object|null Objeto con: participant_id, survey_id, ip_address, user_agent, created_at, expires_at, time_remaining_hours.
     * @since 1.4.0
     * @access public
     */
    public static function get_current_session_info() {
        return EIPSI_Session_Service::get_current_session_info();
    }
    
    /**
     * Get session remaining time in seconds.
     *
     * Returns the remaining time for the current session.
     *
     * @return array { remaining_seconds: int, expires_at: string, is_expired: bool }
     * @since 2.0.0
     * @access public
     */
    public static function get_session_remaining_time() {
        return EIPSI_Session_Service::get_session_remaining_time();
    }
    
    /**
     * Extend current session.
     *
     * Extends the session expiration time by the specified TTL.
     *
     * @param int $ttl_hours Time to extend in hours (default 168 = 7 days).
     * @return array { success: bool, new_expires_at: string|null, error: string|null }
     * @since 2.0.0
     * @access public
     */
    public static function extend_session($ttl_hours = 168) {
        return EIPSI_Session_Service::extend_session($ttl_hours);
    }
}
