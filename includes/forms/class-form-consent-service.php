<?php
/** M3 owner; external contracts remain behind their existing facades. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Form_Consent_Service {
    public static function handle($request, $server = array()) {
    $form_id = sanitize_text_field($request['form_id'] ?? '');
    $decision = sanitize_text_field($request['decision'] ?? '');
    if (empty($form_id) || !in_array($decision, array('accepted', 'declined'), true)) {
        return EIPSI_Form_Response::error(array('message' => __('Invalid parameters', 'eipsi-forms')));
        return;
    }
    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-auth-service.php';
    $consent_context = EIPSI_Auth_Service::authorize_form_operation($form_id, $request, array(), 'consent');
    if (!$consent_context['success']) {
        return EIPSI_Form_Response::error(array('message' => __('Unauthorized', 'eipsi-forms'), 'code' => $consent_context['error']), 403);
        return;
    }
    $participant_id = $consent_context['participant_id'];
    $study_id = $consent_context['study_id'];
    $template_id = $consent_context['template_id'];
    $participant_source = $consent_context['longitudinal'] ? 'SESSION' : 'ANONYMOUS';
    $study_source = $consent_context['longitudinal'] ? 'SESSION' : 'NONE';
    global $wpdb;

    // Instrumentación detallada antes de la validación
    error_log(sprintf(
        '[EIPSI-CONSENT-DEBUG] Decision Handler: FormID=%s (TemplateID=%d), ParticipantID=%s (Source=%s), StudyID=%s (Source=%s)',
        $form_id,
        $template_id,
        $participant_id,
        $participant_source,
        $study_id ?: 'NULL',
        $study_source
    ));

    if ($study_id) {
        // Longitudinal study: save to wp_survey_participants
        $table = $wpdb->prefix . 'survey_participants';

        $data = array(
            'consent_decision' => $decision,
            'consent_decided_at' => current_time('mysql'),
            'consent_ip_address' => eipsi_get_client_ip(),
            'consent_user_agent' => sanitize_text_field($server['HTTP_USER_AGENT'] ?? ''),
            'consent_context' => 'T1_consent_block',
        );

        require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/privacy-config.php';
        $config=get_privacy_config(generate_stable_form_id($form_id));
        if (empty($config['ip_address'])) { $data['consent_ip_address']=null; }
        if (empty($config['user_agent_full'])) { $data['consent_user_agent']=null; }

        // If declined, also set blocked_survey_id
        if ($decision === 'declined') {
            // v2.5.5: Ensure it is a numeric ID (template_id if available, else numeric fallback)
            $blocked_id = (is_numeric($template_id) && $template_id > 0) ? intval($template_id) : null;

            if (!$blocked_id && is_numeric($form_id)) {
                $blocked_id = intval($form_id);
            }

            // Final fallback to study_id if all else fails to be numeric
            if (!$blocked_id && is_numeric($study_id)) {
                $blocked_id = intval($study_id);
            }

            $data['consent_blocked_survey_id'] = $blocked_id;
            $data["is_active"] = 0; // Marcar como inactivo al rechazar

        }

        $validate_query = $wpdb->prepare(
            "SELECT id FROM {$table} WHERE id = %d AND survey_id = %d LIMIT 1",
            $participant_id,
            $study_id
        );

        error_log("[EIPSI-CONSENT-DEBUG] Validation SQL: " . $validate_query);

        $existing_participant = EIPSI_Participant_Repository::get_id_in_study($participant_id, $study_id);

        if (!$existing_participant) {
            error_log(sprintf('[EIPSI-CONSENT-ERROR] Participant %s not found for Study %s', $participant_id, $study_id));
            return EIPSI_Form_Response::error(array('message' => __('Participant not found for this study', 'eipsi-forms')));
            return;
        }

        $data['status'] = ($decision === 'declined') ? 'consent_declined' : 'active';

        $result = EIPSI_Participant_State_Service::save_consent($participant_id, $study_id, $data);

        if ($result === false) {
            return EIPSI_Form_Response::error(array('message' => __('Could not save consent decision', 'eipsi-forms')));
            return;
        }
    }
    // Standalone consent keeps the existing UI response, without manufacturing
    // a longitudinal participant from an unverified client identifier.

    // Log the decision
    if (function_exists('eipsi_log_audit')) {
        eipsi_log_audit('consent_decision', array(
            'form_id' => $form_id,
            'participant_id' => $participant_id,
            'decision' => $decision,
            'study_id' => $study_id ?? null,
        ));
    }

    // Prepare redirect URL for declined consent
    $redirect_url = null;
    if ($decision === 'declined') {
        $study_url = '';

        if ($study_id) {
            // Utilizar el helper que busca por meta eipsi_study_id (inmune a colisiones de texto)
            if (!function_exists('eipsi_get_study_page_url')) {
                require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/setup-wizard.php';
            }

            $study_url = function_exists('eipsi_get_study_page_url')
                ? eipsi_get_study_page_url($study_id)
                : '';
        }

        if (empty($study_url)) {
            $study_url = home_url('/');
        }

        $redirect_url = add_query_arg(array('consent' => 'declined'), $study_url);

        error_log("[EIPSI-CONSENT] Decision declined - Redirecting to: {$redirect_url} (Study ID: {$study_id})");

        // Destruir sesión SOLO después de haber procesado redirección y logs
        if ($consent_context['longitudinal'] && class_exists('EIPSI_Auth_Service')) {
            EIPSI_Auth_Service::destroy_session();
        }
    }

    error_log("[EIPSI-CONSENT] Sending response with redirect: {$redirect_url}");

    return EIPSI_Form_Response::success(array(
        'message' => __('Decision saved', 'eipsi-forms'),
        'decision' => $decision,
        'redirect' => $redirect_url,
    ));

    }
}
