<?php
/** Participant imports; notifications remain an existing external service dependency. */
if (!defined('ABSPATH')) { exit; }
class EIPSI_Participant_Import_Service {
    public static function add_emails($study_id, $emails_array) {
    $success_count = 0;
    $failed_count = 0;
    $errors = array();

    foreach ($emails_array as $email) {
        $email = sanitize_email($email);

        if (!is_email($email)) {
            $errors[] = "$email - Email inválido";
            $failed_count++;
            continue;
        }

        // Generar password automático
        $password = wp_generate_password(16, true, true);

        // Crear participante
        $result = EIPSI_Participant_Service::create_participant($study_id, $email, $password, array(
            'first_name' => '',
            'last_name' => ''
        ));

        if ($result['success']) {
            // Enviar welcome email con Magic Link
            EIPSI_Email_Service::send_welcome_email($study_id, $result['participant_id']);
            $success_count++;
        } else {
            if ($result['error'] === 'email_exists') {
                $errors[] = "$email - Ya registrado";
            } else {
                $errors[] = "$email - Error al crear";
            }
            $failed_count++;
        }
    }

        return array('success_count'=>$success_count, 'failed_count'=>$failed_count, 'errors'=>$errors);
    }

    public static function import_rows($study_id, $participants) {
    $results = array(
        'imported' => 0,
        'failed' => 0,
        'emails_sent' => 0,
        'emails_failed' => 0,
        'errors' => array()
    );

    foreach ($participants as $participant) {
        // Solo importar participantes válidos que no existan
        if ($participant['status'] !== 'valid') {
            continue;
        }

        $email = sanitize_email($participant['email']);
        $first_name = sanitize_text_field($participant['first_name']);
        $last_name = sanitize_text_field($participant['last_name']);

        // Generar contraseña automática
        $password = wp_generate_password(12, false);

        $metadata = array();
        if (!empty($first_name)) {
            $metadata['first_name'] = $first_name;
        }
        if (!empty($last_name)) {
            $metadata['last_name'] = $last_name;
        }

        // Crear participante
        $participant_result = EIPSI_Participant_Service::create_participant($study_id, $email, $password, $metadata);

        if (!$participant_result['success']) {
            $results['failed']++;
            $results['errors'][] = array(
                'email' => $email,
                'error' => $participant_result['error']
            );
            continue;
        }

        $results['imported']++;
        $participant_id = $participant_result['participant_id'];

        // Enviar invitación por email
        $email_sent = EIPSI_Email_Service::send_welcome_email($study_id, $participant_id);

        if ($email_sent) {
            $results['emails_sent']++;
        } else {
            $results['emails_failed']++;
        }
    }

        return $results;
    }
}
