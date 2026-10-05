<?php
/** M2 owner extracted from existing implementation; legacy APIs remain facades. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Participant_Password_Service {

    public static function verify_password($participant_id, $plain_password) {
        global $wpdb;

        // Obtener participante
        $participant = EIPSI_Participant_Repository::get_by_id($participant_id);
        if (!$participant) {
            return false;
        }

        // Verificar si está activo
        if (!$participant->is_active) {
            return false;
        }

        // Usar wp_check_password para verificar
        $is_valid = wp_check_password($plain_password, $participant->password_hash, $participant_id);

        // Log para rate limiting (implementado en Auth_Service)
        return $is_valid;
    }

    public static function change_password($participant_id, $password_old, $password_new) {
        global $wpdb;

        // Verificar password actual
        if (!self::verify_password($participant_id, $password_old)) {
            return array(
                'success' => false,
                'error' => 'invalid_password'
            );
        }

        // Validar password nuevo: mínimo 8 chars
        if (strlen($password_new) < 8) {
            return array(
                'success' => false,
                'error' => 'short_password'
            );
        }

        // Hash password nuevo
        $password_hash = wp_hash_password($password_new);

        // UPDATE password_hash
        $table_name = $wpdb->prefix . 'survey_participants';
        $result = EIPSI_Participant_Repository::update(
            array('password_hash' => $password_hash),
            array('id' => $participant_id),
            array('%s'),
            array('%d')
        );

        if ($result === false) {
            error_log('EIPSI Password change failed: ' . $wpdb->last_error);
            return array(
                'success' => false,
                'error' => 'db_error'
            );
        }

        return array(
            'success' => true,
            'error' => null
        );
    }
}
