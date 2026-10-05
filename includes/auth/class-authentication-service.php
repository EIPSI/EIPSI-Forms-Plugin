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

        $access = EIPSI_Authorization_Policy::authorize_participant($participant->id, $survey_id);
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
