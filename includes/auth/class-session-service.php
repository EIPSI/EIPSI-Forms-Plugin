<?php
/** M2 owner extracted from existing implementation; legacy APIs remain facades. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Session_Service {

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
        $access = EIPSI_Authorization_Policy::authorize_participant($session->participant_id, $session->survey_id);
        if (!$access['success']) {
            self::destroy_session();
            return null;
        }
        return $session;
    }

    /** Trusted PHP API: caller MUST authenticate first (password or claimed secret token).
     * Authorization below checks eligibility, not proof of possession. IDs/email/nonce
     * alone may never reach this method from a public request.
     */
    public static function create_session($participant_id, $survey_id, $ttl_hours = 168) {
        global $wpdb;

        $access = EIPSI_Authorization_Policy::authorize_participant($participant_id, $survey_id);
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

    public static function get_current_participant() {
        $session = self::get_current_session();
        return $session ? (int) $session->participant_id : null;
    }

    public static function get_current_survey() {
        $session = self::get_current_session();
        return $session ? (int) $session->survey_id : null;
    }

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
            $deleted = $wpdb->delete(
                $table_name,
                array('token' => $token_hash),
                array('%s')
            );
            if ($deleted === false) {
                return false;
            }
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

        return true; // Confirmed deletion or already absent (idempotent logout).
    }

    public static function is_authenticated() {
        return self::get_current_participant() !== null;
    }

    public static function cleanup_expired_sessions() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'survey_sessions';

        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM $table_name WHERE expires_at < %s",
            current_time('mysql')
        ));

        return (int) $deleted;
    }

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

        $access = EIPSI_Authorization_Policy::authorize_participant($session->participant_id, $session->survey_id);
        if (!$access['success']) {
            self::destroy_session();
            return array('success' => false, 'new_expires_at' => null, 'error' => 'session_not_found');
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
