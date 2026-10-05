<?php
/** M2 owner extracted from existing implementation; legacy APIs remain facades. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Participant_Registration_Service {

    public static function create_participant($survey_id, $email, $password = null, $metadata = array()) {
        global $wpdb;

        try {
            // Validar email con is_email()
            if (!is_email($email)) {
                return array(
                    'success' => false,
                    'participant_id' => null,
                    'error' => 'invalid_email'
                );
            }

            // Sanitizar email
            $email = sanitize_email($email);

            // Validar password solo si se proporciona (mínimo 8 caracteres, no espacios-only)
            if ($password !== null) {
                if (strlen($password) < 8 || trim($password) === '') {
                    return array(
                        'success' => false,
                        'participant_id' => null,
                        'error' => 'short_password'
                    );
                }
            }

            // Sanitizar metadata
            $first_name = isset($metadata['first_name']) ? sanitize_text_field($metadata['first_name']) : '';
            $last_name = isset($metadata['last_name']) ? sanitize_text_field($metadata['last_name']) : '';

            // Hash password o generar hash aleatorio para passwordless
            if ($password !== null) {
                $password_hash = wp_hash_password($password);
            } else {
                // Passwordless: generar hash aleatorio para satisfacer constraint de DB
                $temp_password = wp_generate_password(32, true, true);
                $password_hash = wp_hash_password($temp_password);
            }

            // Verificar UNIQUE(survey_id, email)
            $table_name = $wpdb->prefix . 'survey_participants';
            $existing = EIPSI_Participant_Repository::get_id_by_email($survey_id, $email);

            if ($existing) {
                return array(
                    'success' => false,
                    'participant_id' => null,
                    'error' => 'email_exists'
                );
            }

            // INSERT en wp_survey_participants
            $result = EIPSI_Participant_Repository::insert(
                array(
                    'survey_id' => $survey_id,
                    'email' => $email,
                    'password_hash' => $password_hash,
                    'first_name' => $first_name,
                    'last_name' => $last_name,
                    'created_at' => current_time('mysql'),
                    'is_active' => 1
                ),
                array('%d', '%s', '%s', '%s', '%s', '%s', '%d')
            );

            if ($result === false) {
                // Log error pero no mostrar al usuario
                error_log('EIPSI Participant creation failed: ' . $wpdb->last_error);
                return array(
                    'success' => false,
                    'participant_id' => null,
                    'error' => 'db_error'
                );
            }

            return array(
                'success' => true,
                'participant_id' => (int) $wpdb->insert_id,
                'error' => null
            );

        } catch (Exception $e) {
            error_log('EIPSI Participant creation exception: ' . $e->getMessage());
            return array(
                'success' => false,
                'participant_id' => null,
                'error' => 'db_error'
            );
        }
    }

    public static function create_participant_with_status($survey_id, $email, $password = null, $metadata = array(), $is_active = true) {
        global $wpdb;

        try {
            // Validar email con is_email()
            if (!is_email($email)) {
                return array(
                    'success' => false,
                    'participant_id' => null,
                    'error' => 'invalid_email'
                );
            }

            // Sanitizar email
            $email = sanitize_email($email);

            // Validar password solo si se proporciona
            if ($password !== null) {
                if (strlen($password) < 8 || trim($password) === '') {
                    return array(
                        'success' => false,
                        'participant_id' => null,
                        'error' => 'short_password'
                    );
                }
            }

            // Sanitizar metadata
            $first_name = isset($metadata['first_name']) ? sanitize_text_field($metadata['first_name']) : '';
            $last_name = isset($metadata['last_name']) ? sanitize_text_field($metadata['last_name']) : '';

            // Hash password o generar hash aleatorio para passwordless
            if ($password !== null) {
                $password_hash = wp_hash_password($password);
            } else {
                $temp_password = wp_generate_password(32, true, true);
                $password_hash = wp_hash_password($temp_password);
            }

            // Verificar UNIQUE(survey_id, email)
            $table_name = $wpdb->prefix . 'survey_participants';
            $existing = EIPSI_Participant_Repository::get_id_by_email($survey_id, $email);

            if ($existing) {
                return array(
                    'success' => false,
                    'participant_id' => null,
                    'error' => 'email_exists'
                );
            }

            // INSERT en wp_survey_participants with explicit is_active status
            $result = EIPSI_Participant_Repository::insert(
                array(
                    'survey_id' => $survey_id,
                    'email' => $email,
                    'password_hash' => $password_hash,
                    'first_name' => $first_name,
                    'last_name' => $last_name,
                    'created_at' => current_time('mysql'),
                    'is_active' => $is_active ? 1 : 0
                ),
                array('%d', '%s', '%s', '%s', '%s', '%s', '%d')
            );

            if ($result === false) {
                error_log('EIPSI Participant creation failed: ' . $wpdb->last_error);
                return array(
                    'success' => false,
                    'participant_id' => null,
                    'error' => 'db_error'
                );
            }

            return array(
                'success' => true,
                'participant_id' => (int) $wpdb->insert_id,
                'error' => null
            );

        } catch (Exception $e) {
            error_log('EIPSI Participant creation exception: ' . $e->getMessage());
            return array(
                'success' => false,
                'participant_id' => null,
                'error' => 'db_error'
            );
        }
    }

    public static function create_or_get_for_magic_link($survey_id, $email) {
        global $wpdb;

        try {
            // Validar email
            if (!is_email($email)) {
                return array(
                    'success' => false,
                    'participant_id' => null,
                    'is_new' => false,
                    'error' => 'invalid_email'
                );
            }

            // Sanitizar email
            $email = sanitize_email($email);
            $table_name = $wpdb->prefix . 'survey_participants';

            // Verificar si ya existe
            $existing = EIPSI_Participant_Repository::get_by_email($survey_id, $email);

            if ($existing) {
                // Si existe pero está inactivo, reactivarlo
                if (!$existing->is_active) {
                    EIPSI_Participant_Repository::update(
                        array('is_active' => 1, 'updated_at' => current_time('mysql')),
                        array('id' => $existing->id),
                        array('%d', '%s'),
                        array('%d')
                    );
                }

                return array(
                    'success' => true,
                    'participant_id' => (int) $existing->id,
                    'is_new' => false,
                    'error' => null
                );
            }

            // Crear nuevo participante sin contraseña (magic link only)
            // Generar contraseña aleatoria para satisfacer constraint de DB
            $temp_password = wp_generate_password(32, true, true);
            $password_hash = wp_hash_password($temp_password);

            $result = EIPSI_Participant_Repository::insert(
                array(
                    'survey_id' => $survey_id,
                    'email' => $email,
                    'password_hash' => $password_hash,
                    'first_name' => '',
                    'last_name' => '',
                    'created_at' => current_time('mysql'),
                    'is_active' => 1
                ),
                array('%d', '%s', '%s', '%s', '%s', '%s', '%d')
            );

            if ($result === false) {
                error_log('EIPSI Participant creation for magic link failed: ' . $wpdb->last_error);
                return array(
                    'success' => false,
                    'participant_id' => null,
                    'is_new' => false,
                    'error' => 'db_error'
                );
            }

            return array(
                'success' => true,
                'participant_id' => (int) $wpdb->insert_id,
                'is_new' => true,
                'error' => null
            );

        } catch (Exception $e) {
            error_log('EIPSI Participant creation for magic link exception: ' . $e->getMessage());
            return array(
                'success' => false,
                'participant_id' => null,
                'is_new' => false,
                'error' => 'db_error'
            );
        }
    }
    public static function register_passwordless($survey_id, $email) {
    // v1.5.7 - Load study config to check double opt-in setting
    global $wpdb;
    $study = EIPSI_Participant_Repository::registration_study($survey_id);

    $study_config = ($study && !empty($study->config)) ? json_decode($study->config, true) : array();
    // FIX: double_opt_in defaults to TRUE when config is NULL or not set
    $double_opt_in = !isset($study_config['double_opt_in']) || $study_config['double_opt_in'] === true;

    // Create participant (passwordless - no password, no names)
    // If double_opt_in is true, participant is created with is_active = 0
    $result = EIPSI_Participant_Service::create_participant_with_status(
        $survey_id,
        $email,
        null, // No password for passwordless flow
        array(
            'first_name' => '',
            'last_name' => ''
        ),
        !$double_opt_in // is_active = false if double_opt_in required
    );

        return array('result' => $result, 'double_opt_in' => $double_opt_in);
    }

    public static function send_confirmation($survey_id, $participant_id, $email) {
        // Load confirmation service
        if (!class_exists('EIPSI_Email_Confirmation_Service')) {
            require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-email-confirmation-service.php';
        }
        if (!class_exists('EIPSI_Email_Service')) {
            require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-email-service.php';
        }

        // Generate confirmation token
        $token_result = EIPSI_Email_Confirmation_Service::generate_confirmation_token(
            $survey_id,
            $participant_id,
            $email
        );

        if (!$token_result['success']) {
            // Token generation failed - log but still return success to user
            error_log('[EIPSI] Failed to generate confirmation token for participant ' . $participant_id);
        } else {
            // Send confirmation email
            $email_sent = EIPSI_Email_Service::send_confirmation_email(
                $survey_id,
                $participant_id,
                $token_result['token']
            );

            if (!$email_sent) {
                error_log('[EIPSI] Failed to send confirmation email to ' . $email);
            }
        }

    }

}
