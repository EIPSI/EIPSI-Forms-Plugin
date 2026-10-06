<?php
/** M2 owner extracted from existing implementation; legacy APIs remain facades. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Authentication_Service {

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

        $access = EIPSI_Authorization_Policy::authorize_participant($participant->id, $survey_id);
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

    /** Compatibility only: email lookup is never proof of identity. Use a token flow. */
    public static function authenticate_passwordless($survey_id, $email) {
        return array('success' => false, 'participant_id' => null, 'error' => 'proof_required');
    }

    /** Reserve every public auth/email attempt, including successful requests.
     * REMOTE_ADDR is server supplied; client forwarding headers cannot select the key.
     */
    public static function allow_public_request($email, $survey_id) {
        $origin = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
        $keys = array(
            'eipsi_auth_origin_' . md5($origin) => 20,
            'eipsi_auth_email_' . md5(strtolower($email) . ':' . (int) $survey_id) => 5,
        );
        foreach ($keys as $key => $limit) {
            if ((int) get_transient($key) >= $limit) { return false; }
        }
        foreach ($keys as $key => $limit) {
            set_transient($key, (int) get_transient($key) + 1, 15 * MINUTE_IN_SECONDS);
        }
        return true;
    }

    /** Initiate access, not authentication. Public callers must reserve a rate-limit slot.
     * Existing/missing/ineligible accounts and delivery failures share the public result.
     */
    public static function request_magic_link($survey_id, $email) {
        $participant = EIPSI_Participant_Service::get_by_email($survey_id, sanitize_email($email));
        if ($participant && EIPSI_Authorization_Policy::authorize_participant($participant->id, $survey_id)['success']) {
            require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-magic-links-service.php';
            require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-email-service.php';
            // Existing mail service generates and sends exactly one MagicLinkService token.
            $delivery = EIPSI_Email_Service::send_magic_link_email($survey_id, $participant->id);
            if (empty($delivery['success'])) {
                error_log('[EIPSI Auth] Access-link delivery failed; study=' . (int) $survey_id);
            }
        }
        return array(
            'message' => __('Si el email puede acceder a este estudio, recibirás un enlace de acceso.', 'eipsi-forms'),
            'requires_email_link' => true,
            'auto_login' => false,
        );
    }

    public static function check_login_rate_limit($email, $survey_id) {
        $key = 'eipsi_login_attempts_' . md5($email . $survey_id);
        $attempts = get_transient($key);

        return !($attempts && $attempts >= 5);
    }

    public static function record_failed_login($email, $survey_id) {
        $key = 'eipsi_login_attempts_' . md5($email . $survey_id);
        $attempts = (int) get_transient($key);
        set_transient($key, $attempts + 1, 15 * MINUTE_IN_SECONDS);
    }

    public static function clear_login_rate_limit($email, $survey_id) {
        $key = 'eipsi_login_attempts_' . md5($email . $survey_id);
        delete_transient($key);
    }
}
