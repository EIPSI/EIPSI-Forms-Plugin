<?php
/** Frozen P1-A decision from develop 2775faa; test fixture, never loaded by product. */
class EIPSI_M2_Baseline_Policy {
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
}
