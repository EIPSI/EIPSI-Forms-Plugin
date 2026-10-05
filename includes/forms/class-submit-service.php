<?php
/** M3 owner; external contracts remain behind their existing facades. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Submit_Service {
    public static function submit($request, $query = array(), $server = array()) {


    global $wpdb;
    $wpdb->suppress_errors(true);

    // ✅ EIPSI DATA SAFETY SYSTEM v2.1.0
    // Carga el sistema crítico de seguridad de datos
    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/data-safety-system.php';

    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-auth-service.php';
    $form_name = isset($request['form_id']) ? sanitize_text_field($request['form_id']) : 'default';
    $submission_context = EIPSI_Auth_Service::authorize_form_operation($form_name, $request, $query);
    if (!$submission_context['success']) {
        return EIPSI_Form_Response::error(array('message' => __('Unauthorized', 'eipsi-forms'), 'code' => $submission_context['error']), 403);
        return;
    }
    $authenticated_participant_id = $submission_context['participant_id'];
    $authenticated_study_id = $submission_context['study_id'];

    // Ética clínica: si el estudio está cerrado, no aceptamos nuevos envíos
    if (eipsi_get_study_status_for_form_name($form_name) === 'closed') {
        return EIPSI_Form_Response::error(array(
            'message' => __('Este estudio está cerrado y no acepta más respuestas. Contacta al investigador si tienes dudas.', 'eipsi-forms')
        ), 403);
    }

    $capture=EIPSI_Form_Capture_Service::capture($request, $server, $form_name, $submission_context);
    $data=$capture['data'];
    $metadata_array=$capture['metadata_array'];
    $partial_participant_id=$capture['partial_participant_id'];
    $session_id=$capture['session_id'];
    $wave_id=$capture['wave_id'];
    $study_id=$capture['study_id'];
    $longitudinal_participant_id=$capture['longitudinal_participant_id'];
    $user_data=$capture['user_data'];
    $stable_form_id=$capture['stable_form_id'];
    $wave_index=$capture['wave_index'];
    $submitted_at=$capture['submitted_at'];
    // ✅ DATA SAFETY: Pre-flight validation
    $safety_check = EIPSI_Form_Storage_Adapter::validate($data);
    if (!$safety_check['valid']) {
        error_log('[EIPSI SAFETY] CRITICAL: Pre-flight validation failed: ' . implode(', ', $safety_check['errors']));
        // Aún intentamos guardar en modo emergencia
    }

    // ✅ DATA SAFETY: Guardar con sistema de seguridad (retry + emergencia)
    $safety_result = EIPSI_Form_Storage_Adapter::save($data, 3);

    if ($safety_result['success']) {
        $insert_id = $safety_result['insert_id'] ?? null;
        $storage_type = $safety_result['storage'] ?? 'unknown';
        $emergency_mode = $safety_result['emergency_mode'] ?? false;
        $used_fallback = $safety_result['fallback_used'] ?? false;

        // ✅ DATA SAFETY: Verificación post-submit
        $verified = EIPSI_Form_Storage_Adapter::verify($insert_id, $storage_type, $data);

        if (!$verified && !$emergency_mode) {
            error_log(sprintf('[EIPSI SAFETY] Post-submit verification failed for ID: %s', $insert_id));
        }

        // ✅ v2.1.3 - Guardar device data extendido en tabla separada
        // Asegurar que la clase esté cargada
        if (!class_exists('EIPSI_Device_Data_Service')) {
            require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-device-data-service.php';
        }
        error_log("[EIPSI-SUBMIT-DIAG] CHECK save_device_data: insert_id={$insert_id}, has_device_data=" . (isset($metadata_array['device_data']) ? 'YES' : 'NO') . ", class_exists=" . (class_exists('EIPSI_Device_Data_Service') ? 'YES' : 'NO'));
        if ($storage_type === 'wordpress_db' && !$emergency_mode && $insert_id && !empty($metadata_array['device_data']) && class_exists('EIPSI_Device_Data_Service')) {
            error_log("[EIPSI-SUBMIT-DIAG] CALLING save_device_data with insert_id={$insert_id}, device_data_keys=" . implode(',', array_keys($metadata_array['device_data'])));
            $result = EIPSI_Device_Data_Service::save_device_data($insert_id, $metadata_array['device_data']);
            error_log("[EIPSI-SUBMIT-DIAG] save_device_data result: " . ($result ? "SUCCESS (insert_id={$result})" : "FAILED"));
        }

        // Marcar partial response como completado
        EIPSI_Partial_Responses::mark_completed($form_name, $partial_participant_id, $session_id);

        // Si fue modo emergencia, notificar al usuario pero confirmar éxito
        if ($emergency_mode) {
            return EIPSI_Form_Response::success(array(
                'message' => $safety_result['message'],
                'emergency_mode' => true,
                'emergency_id' => $safety_result['emergency_id'],
                'insert_id' => $insert_id,
                'verified' => $verified
            ));
        }

        $longitudinal=EIPSI_Form_Longitudinal_Submit_Adapter::after_persistence($wave_id, $study_id, $longitudinal_participant_id, $stable_form_id, $submitted_at, $user_data);
        if (isset($longitudinal['success']) && !$longitudinal['success']) { return $longitudinal; }
        $next_wave_data=$longitudinal['next_wave_data'];
        $has_next_wave=$longitudinal['has_next_wave'];
        $nudge_0_sent=$longitudinal['nudge_0_sent'];
        $nudge_0_message=$longitudinal['nudge_0_message'];
        // Preparar respuesta de éxito con información de próximas tomas
        $success_response = array(
            'message' => __('Form submitted successfully!', 'eipsi-forms'),
            'external_db' => false,
            'insert_id' => $insert_id,
            'has_next' => $has_next_wave,
            'next_wave' => $next_wave_data
        );

        // Si no hay próxima toma, agregar mensaje de completado
        if (!$has_next_wave && $study_id) {
            $success_response['completion_message'] = __('All waves completed!', 'eipsi-forms');
        }

        // ✅ DATA SAFETY: Agregar info de verificación a la respuesta
        $success_response['verified'] = $verified;
        $success_response['storage_type'] = $storage_type;

        if ($used_fallback) {
            // Fallback succeeded - inform user with warning
            $success_response['fallback_used'] = true;
            $success_response['warning'] = __('Form was saved to local database (external database temporarily unavailable).', 'eipsi-forms');
            $success_response['error_code'] = $error_info['error_code'];
        }

        // v2.2.2 - Agregar info de email Nudge 0 si se envió inmediatamente
        if (!empty($nudge_0_sent)) {
            $success_response['nudge_0_sent'] = true;
            $success_response['nudge_0_message'] = $nudge_0_message;
        }

        // ==========================================================================
        // FASE 4 - TRACKING DE COMPLETITUD EN POOLS (v2.5.3)
        // Disparar hook para que otros handlers verifiquen completitud de pools
        // Solo para participantes autenticados con contexto longitudinal válido
        // ==========================================================================
        if ($authenticated_participant_id && $study_id) {
            do_action('eipsi_form_submitted', array(
                'survey_id'      => $study_id,
                'participant_id' => $authenticated_participant_id,
                'wave_index'     => $wave_index,
                'form_id'        => $stable_form_id,
                'insert_id'      => $insert_id,
            ));
        }

        return EIPSI_Form_Response::success($success_response);

    } else {
        // ✅ DATA SAFETY: Fallo crítico - todos los intentos fallaron incluyendo modo emergencia
        error_log('[EIPSI SAFETY] CRITICAL: All save attempts failed including emergency mode');

        return EIPSI_Form_Response::error(array(
            'message' => __('Error crítico: No se pudo guardar la respuesta. Por favor, contacte al administrador inmediatamente.', 'eipsi-forms'),
            'error_code' => 'SAFETY_SYSTEM_FAILURE',
            'contact_admin' => true
        ), 500);
    }

    }
}
