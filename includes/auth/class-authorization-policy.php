<?php
/** M2 owner extracted from existing implementation; legacy APIs remain facades. */
if (!defined('ABSPATH')) { exit; }

require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/participants/class-participant-repository.php';

class EIPSI_Authorization_Policy {

    public static function authorize_participant($participant_id, $survey_id) {
        global $wpdb;
        $participant = EIPSI_Participant_Repository::get_by_id($participant_id);
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

    public static function authorize_session_context($request = array()) {
        $session = EIPSI_Session_Service::get_current_session();
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
}
