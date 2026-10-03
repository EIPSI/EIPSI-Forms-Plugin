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

class EIPSI_Auth_Service {

    /**
     * Shared longitudinal access policy. An undecided participant may enter to
     * consent; declined/withdrawn/inactive participants may never authorize access.
     */
    public static function authorize_participant($participant_id, $survey_id) {
        global $wpdb;
        $participant = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}survey_participants WHERE id = %d",
            $participant_id
        ));
        $error = null;
        if (!$participant) {
            $error = 'user_not_found';
        } elseif ((int) $survey_id <= 0 || (int) $participant->survey_id !== (int) $survey_id) {
            $error = 'study_mismatch';
        } elseif (($participant->consent_decision ?? '') === 'withdrawn' || ($participant->status ?? '') === 'withdrawn') {
            $error = 'study_withdrawn';
        } elseif (($participant->consent_decision ?? '') === 'declined' || in_array($participant->status ?? '', array('declined', 'consent_declined'), true)) {
            $error = 'consent_declined';
        } elseif ((int) $participant->is_active !== 1) {
            $error = 'user_inactive';
        } elseif (!in_array($participant->consent_decision ?? '', array('', 'accepted'), true)) {
            $error = 'invalid_consent_state';
        }
        return array('success' => $error === null, 'error' => $error, 'participant' => $error === null ? $participant : null);
    }

    /** Read both identity and study from one token, revalidating state on every use. */
    public static function get_current_session() {
        global $wpdb;
        $cookie_name = defined('EIPSI_SESSION_COOKIE_NAME') ? EIPSI_SESSION_COOKIE_NAME : 'eipsi_session_token';
        $token = $_COOKIE[$cookie_name] ?? null;
        if (!is_string($token) || $token === '') {
            return null;
        }
        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT participant_id, survey_id FROM {$wpdb->prefix}survey_sessions WHERE token = %s AND expires_at > %s",
            hash('sha256', $token), current_time('mysql')
        ));
        if (!$session) {
            return null;
        }
        $access = self::authorize_participant($session->participant_id, $session->survey_id);
        if (!$access['success']) {
            self::destroy_session();
            return null;
        }
        return $session;
    }

    /** Client IDs may confirm a session context, never replace it. */
    public static function authorize_session_context($request = array()) {
        $session = self::get_current_session();
        if (!$session) {
            return array('success' => false, 'error' => 'authentication_required');
        }
        foreach (array('participant_id' => $session->participant_id, 'longitudinal_participant_id' => $session->participant_id,
                       'study_id' => $session->survey_id, 'survey_id' => $session->survey_id) as $key => $expected) {
            if (isset($request[$key]) && $request[$key] !== '' &&
                (!is_scalar($request[$key]) || (string) $request[$key] !== (string) $expected)) {
                return array('success' => false, 'error' => 'session_context_mismatch');
            }
        }
        return array('success' => true, 'error' => null, 'participant_id' => (int) $session->participant_id, 'study_id' => (int) $session->survey_id);
    }

    /** Resolve actual templates for a container form name, including older slug inputs. */
    public static function get_form_template_ids($form_name) {
        $args = array('post_type' => array('eipsi_form_template', 'eipsi_form', 'page'),
                      'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids');
        $ids = get_posts(array_merge($args, array('meta_key' => '_eipsi_form_name', 'meta_value' => $form_name)));
        $ids = array_merge($ids, get_posts(array_merge($args, array('name' => $form_name))));
        if (ctype_digit((string) $form_name)) {
            $post = get_post((int) $form_name);
            if ($post && $post->post_status === 'publish' && in_array($post->post_type, $args['post_type'], true)) {
                $ids[] = (int) $form_name;
            }
        }
        return array_values(array_unique(array_filter(array_map('absint', $ids))));
    }

    /**
     * Authorize form identity before any persistence. Standalone submissions keep
     * their anonymous fingerprint; longitudinal submissions use only the session.
     * No timing/scheduling rules are changed here.
     */
    public static function authorize_form_operation($form_name, $request = array(), $query = array(), $operation = 'submit') {
        global $wpdb;
        $ids = self::get_form_template_ids($form_name);
        $form_ids = $ids ? implode(',', $ids) : '0';
        $has_waves = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}survey_waves WHERE form_id IN ({$form_ids})");
        $explicit_context = false;
        $wave_id = 0;
        foreach (array($request, $query) as $input) {
            foreach (array('wave_id', 'study_id', 'survey_id') as $key) {
                if (isset($input[$key]) && $input[$key] !== '' && $input[$key] !== '0' && $input[$key] !== 0) {
                    $explicit_context = true;
                    if (!is_scalar($input[$key]) || !ctype_digit((string) $input[$key]) || (int) $input[$key] <= 0) {
                        return array('success' => false, 'error' => 'invalid_context');
                    }
                }
            }
            if (!empty($input['wave_id'])) {
                if ($wave_id && $wave_id !== (int) $input['wave_id']) {
                    return array('success' => false, 'error' => 'session_context_mismatch');
                }
                $wave_id = (int) $input['wave_id'];
            }
        }
        $longitudinal = $has_waves || $explicit_context;
        $context_request = $request;
        // participant_id in submit is a browser fingerprint in the existing API.
        // Numeric participant claims still have to match; fingerprints never authorize.
        if ($operation === 'submit' && isset($context_request['participant_id']) && is_string($context_request['participant_id']) && !is_numeric($context_request['participant_id'])) {
            unset($context_request['participant_id']);
        }
        if (!$longitudinal) {
            $requires_login = false;
            foreach ($ids as $id) { $requires_login = $requires_login || (bool) get_post_meta($id, '_eipsi_require_login', true); }
            if ($requires_login || ($operation === 'consent' && !empty($context_request['participant_id']))) {
                $access = self::authorize_session_context($context_request);
                if (!$access['success']) { return $access; }
            }
            return array('success' => true, 'longitudinal' => false, 'participant_id' => 0, 'study_id' => null, 'wave_id' => 0, 'wave_index' => null, 'template_id' => $ids[0] ?? 0);
        }
        $access = self::authorize_session_context($context_request);
        if (!$access['success']) { return $access; }
        $query_access = self::authorize_session_context($query);
        if (!$query_access['success']) { return $query_access; }
        $study_id = $access['study_id'];
        $participant_id = $access['participant_id'];
        if ($operation === 'consent') {
            $template_id = $wpdb->get_var($wpdb->prepare(
                "SELECT form_id FROM {$wpdb->prefix}survey_waves WHERE study_id = %d AND form_id IN ({$form_ids}) LIMIT 1", $study_id
            ));
            if (!$template_id) { return array('success' => false, 'error' => 'form_study_mismatch'); }
            return array_merge($access, array('longitudinal' => true, 'template_id' => (int) $template_id));
        }
        $wave_filter = $wave_id ? $wpdb->prepare(' AND w.id = %d', $wave_id) : " AND a.status IN ('pending', 'in_progress')";
        $assignment = $wpdb->get_row($wpdb->prepare(
            "SELECT a.id AS assignment_id, a.status, w.id AS wave_id, w.wave_index, w.form_id
             FROM {$wpdb->prefix}survey_assignments a JOIN {$wpdb->prefix}survey_waves w ON a.wave_id = w.id AND a.study_id = w.study_id
             WHERE a.participant_id = %d AND a.study_id = %d AND w.study_id = %d AND w.form_id IN ({$form_ids})"
             . $wave_filter . ' ORDER BY w.wave_index ASC LIMIT 1', $participant_id, $study_id, $study_id
        ));
        if (!$assignment || !in_array($assignment->status, array('pending', 'in_progress'), true)) {
            return array('success' => false, 'error' => 'assignment_unavailable');
        }
        return array_merge($access, array('longitudinal' => true, 'wave_id' => (int) $assignment->wave_id,
            'wave_index' => (int) $assignment->wave_index, 'template_id' => (int) $assignment->form_id));
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
        // Sanitizar email
        $email = sanitize_email($email);

        // Obtener participante
        $participant = EIPSI_Participant_Service::get_by_email($survey_id, $email);

        if (!$participant) {
            return array(
                'success' => false,
                'participant_id' => null,
                'error' => 'user_not_found'
            );
        }

        $access = self::authorize_participant($participant->id, $survey_id);
        if (!$access['success']) {
            return array('success' => false, 'participant_id' => null, 'error' => $access['error']);
        }

        // Verificar password
        $is_valid = EIPSI_Participant_Service::verify_password($participant->id, $password);

        if (!$is_valid) {
            return array(
                'success' => false,
                'participant_id' => null,
                'error' => 'invalid_credentials'
            );
        }

        // Actualizar último login
        EIPSI_Participant_Service::update_last_login($participant->id);

        return array(
            'success' => true,
            'participant_id' => $participant->id,
            'error' => null
        );
    }

    /**
     * Authenticate participant (passwordless - email only).
     *
     * Valida email y estado activo sin verificar contraseña.
     * Usado para flujo de autenticación sin contraseña.
     *
     * @param int    $survey_id ID del survey.
     * @param string $email Email del participante.
     * @return array { success: bool, participant_id: int|null, error: string|null }
     * @since 2.0.0
     * @access public
     */
    public static function authenticate_passwordless($survey_id, $email) {
        // Sanitizar email
        $email = sanitize_email($email);

        // Obtener participante
        $participant = EIPSI_Participant_Service::get_by_email($survey_id, $email);

        if (!$participant) {
            return array(
                'success' => false,
                'participant_id' => null,
                'error' => 'user_not_found'
            );
        }

        $access = self::authorize_participant($participant->id, $survey_id);
        if (!$access['success']) {
            return array('success' => false, 'participant_id' => null, 'error' => $access['error']);
        }

        // Actualizar último login
        EIPSI_Participant_Service::update_last_login($participant->id);

        return array(
            'success' => true,
            'participant_id' => $participant->id,
            'error' => null
        );
    }
    
    /**
     * Create session token and cookie.
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
        global $wpdb;
        
        $access = self::authorize_participant($participant_id, $survey_id);
        if (!$access['success']) {
            return array('success' => false, 'token' => null, 'error' => $access['error']);
        }

        try {
            // Generar token único
            $token = wp_generate_password(64, true, true);
            
            // Hash token para almacenar en DB
            $token_hash = hash('sha256', $token);
            
            // Calcular expires_at
            $expires_at = date('Y-m-d H:i:s', strtotime("+{$ttl_hours} hours"));
            
            // Obtener IP
            $ip_address = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : '0.0.0.0';
            
            // Obtener User-Agent
            $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : 'unknown';
            
            // Insertar en wp_survey_sessions
            $table_name = $wpdb->prefix . 'survey_sessions';
            $result = $wpdb->insert(
                $table_name,
                array(
                    'token' => $token_hash, // Hash almacenado en DB
                    'participant_id' => $participant_id,
                    'survey_id' => $survey_id,
                    'ip_address' => $ip_address,
                    'user_agent' => $user_agent,
                    'expires_at' => $expires_at,
                    'created_at' => current_time('mysql')
                ),
                array('%s', '%d', '%d', '%s', '%s', '%s', '%s')
            );
            
            if ($result === false) {
                error_log('EIPSI Session creation failed: ' . $wpdb->last_error);
                return array(
                    'success' => false,
                    'token' => null,
                    'error' => 'db_error'
                );
            }
            
            // Setear cookie segura (HTTP-only, Secure, SameSite)
            $cookie_expires = strtotime("+{$ttl_hours} hours");
            
            // Cookie name desde constante (definir en plugin principal)
            $cookie_name = defined('EIPSI_SESSION_COOKIE_NAME') ? EIPSI_SESSION_COOKIE_NAME : 'eipsi_session_token';
            
            // Setear cookie - usar setcookie() simple para compatibilidad PHP < 7.3
            $secure = is_ssl(); // Solo HTTPS
            
            $cookie_set = false;
            if ( ! headers_sent() ) {
                if (version_compare(PHP_VERSION, '7.3.0', '>=')) {
                    $cookie_options = array(
                        'expires'  => $cookie_expires,
                        'path'     => '/',
                        'domain'   => '',
                        'secure'   => $secure,
                        'httponly' => true,
                        'samesite' => 'Lax'
                    );
                    $cookie_set = setcookie($cookie_name, $token, $cookie_options);
                } else {
                    $cookie_set = setcookie($cookie_name, $token, $cookie_expires, '/', '', $secure, true);
                }
            } else {
                error_log('EIPSI Auth: headers already sent, cookie fallback required for participant ' . $participant_id);
            }
            
            // Make the validated new identity available to magic-link rendering
            // in this same request, before the browser returns the cookie.
            $_COOKIE[$cookie_name] = $token;

            return array(
                'success'     => true,
                'token'       => $token,
                'cookie_name' => $cookie_name,
                'cookie_set'  => $cookie_set,
                'error'       => null
            );
            
        } catch (Exception $e) {
            error_log('EIPSI Session creation exception: ' . $e->getMessage());
            return array(
                'success' => false,
                'token'   => null,
                'error'   => 'db_error'
            );
        }
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
        $session = self::get_current_session();
        return $session ? (int) $session->participant_id : null;
    }
    
    /**
     * Get current survey from session.
     *
     * @return int|null survey_id o null si no hay sesión.
     * @since 1.4.0
     * @access public
     */
    public static function get_current_survey() {
        $session = self::get_current_session();
        return $session ? (int) $session->survey_id : null;
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
        global $wpdb;
        
        // Cookie name
        $cookie_name = defined('EIPSI_SESSION_COOKIE_NAME') ? EIPSI_SESSION_COOKIE_NAME : 'eipsi_session_token';
        
        // Leer token de cookie
        $token = isset($_COOKIE[$cookie_name]) ? $_COOKIE[$cookie_name] : null;
        
        if ($token) {
            // Hash token para buscar en DB
            $token_hash = hash('sha256', $token);
            
            // DELETE FROM sessions WHERE token_hash = hash(token)
            $table_name = $wpdb->prefix . 'survey_sessions';
            $wpdb->delete(
                $table_name,
                array('token' => $token_hash),
                array('%s')
            );
        }
        
        unset($_COOKIE[$cookie_name]);

        // Borrar cookie: setear con fecha de expiración en el pasado
        $past_time = time() - 3600;
        
        if (version_compare(PHP_VERSION, '7.3.0', '>=')) {
            $cookie_options = array(
                'expires' => $past_time,
                'path' => '/',
                'domain' => '',
                'secure' => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax'
            );
            setcookie($cookie_name, '', $cookie_options);
        } else {
            setcookie($cookie_name, '', $past_time, '/', '', is_ssl(), true);
        }
        
        return true; // Siempre true si llegamos aquí
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
        return self::get_current_participant() !== null;
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
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'survey_sessions';
        
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM $table_name WHERE expires_at < %s",
            current_time('mysql')
        ));
        
        return (int) $deleted;
    }
    
    /**
     * Get current session info.
     *
     * @return object|null Objeto con: participant_id, survey_id, ip_address, user_agent, created_at, expires_at, time_remaining_hours.
     * @since 1.4.0
     * @access public
     */
    public static function get_current_session_info() {
        global $wpdb;
        
        // Cookie name
        $cookie_name = defined('EIPSI_SESSION_COOKIE_NAME') ? EIPSI_SESSION_COOKIE_NAME : 'eipsi_session_token';
        
        // Leer token
        $token = isset($_COOKIE[$cookie_name]) ? $_COOKIE[$cookie_name] : null;
        if (!$token) {
            return null;
        }
        
        // Hash token
        $token_hash = hash('sha256', $token);
        
        // Query: SELECT * FROM sessions WHERE token = hash AND expires_at > NOW()
        $table_name = $wpdb->prefix . 'survey_sessions';
        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE token = %s AND expires_at > %s",
            $token_hash,
            current_time('mysql')
        ));
        
        if (!$session) {
            return null;
        }
        
        // Calcular time_remaining_hours
        $expires_timestamp = strtotime($session->expires_at);
        $now_timestamp = time();
        $time_remaining_seconds = max(0, $expires_timestamp - $now_timestamp);
        $time_remaining_hours = round($time_remaining_seconds / 3600, 2);
        
        // Agregar time_remaining_hours al objeto
        $session->time_remaining_hours = $time_remaining_hours;
        $session->time_remaining_seconds = $time_remaining_seconds;
        
        return $session;
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
        $session = self::get_current_session_info();
        
        if (!$session) {
            return array(
                'remaining_seconds' => 0,
                'expires_at' => null,
                'is_expired' => true
            );
        }
        
        $expires_timestamp = strtotime($session->expires_at);
        $now_timestamp = time();
        $remaining_seconds = max(0, $expires_timestamp - $now_timestamp);
        
        return array(
            'remaining_seconds' => (int) $remaining_seconds,
            'expires_at' => $session->expires_at,
            'is_expired' => $remaining_seconds <= 0
        );
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
        global $wpdb;
        
        // Cookie name
        $cookie_name = defined('EIPSI_SESSION_COOKIE_NAME') ? EIPSI_SESSION_COOKIE_NAME : 'eipsi_session_token';
        
        // Leer token
        $token = isset($_COOKIE[$cookie_name]) ? $_COOKIE[$cookie_name] : null;
        if (!$token) {
            return array(
                'success' => false,
                'new_expires_at' => null,
                'error' => 'no_session'
            );
        }
        
        // Hash token
        $token_hash = hash('sha256', $token);
        
        // Check if session exists and is valid
        $table_name = $wpdb->prefix . 'survey_sessions';
        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE token = %s",
            $token_hash
        ));
        
        if (!$session) {
            return array(
                'success' => false,
                'new_expires_at' => null,
                'error' => 'session_not_found'
            );
        }
        
        // Check if already expired
        $expires_timestamp = strtotime($session->expires_at);
        if ($expires_timestamp < time()) {
            return array(
                'success' => false,
                'new_expires_at' => null,
                'error' => 'session_expired'
            );
        }
        
        // Calculate new expiration time
        $new_expires_at = date('Y-m-d H:i:s', strtotime("+{$ttl_hours} hours"));
        
        // Update session in database
        $result = $wpdb->update(
            $table_name,
            array('expires_at' => $new_expires_at),
            array('token' => $token_hash),
            array('%s'),
            array('%s')
        );
        
        if ($result === false) {
            return array(
                'success' => false,
                'new_expires_at' => null,
                'error' => 'db_error'
            );
        }
        
        // Update cookie with new expiration
        $cookie_expires = strtotime("+{$ttl_hours} hours");
        $secure = is_ssl();
        
        if (version_compare(PHP_VERSION, '7.3.0', '>=')) {
            $cookie_options = array(
                'expires' => $cookie_expires,
                'path' => '/',
                'domain' => '',
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax'
            );
            setcookie($cookie_name, $token, $cookie_options);
        } else {
            setcookie(
                $cookie_name,
                $token,
                $cookie_expires,
                '/',
                '',
                $secure,
                true
            );
        }
        
        return array(
            'success' => true,
            'new_expires_at' => $new_expires_at,
            'error' => null
        );
    }
}
