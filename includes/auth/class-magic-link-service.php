<?php
/** M2 owner extracted from existing implementation; legacy APIs remain facades. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Magic_Link_Service {

    public static function generate_magic_link($survey_id, $participant_id) {
        global $wpdb;

        // Validate inputs
        $survey_id = intval($survey_id);
        $participant_id = intval($participant_id);

        if ($survey_id <= 0 || $participant_id <= 0) {
            error_log('[EIPSI MagicLinksService] Invalid survey_id or participant_id: survey_id=' . $survey_id . ', participant_id=' . $participant_id);
            return false;
        }

        // Check if participant exists
        $participant_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}survey_participants WHERE id = %d",
            $participant_id
        ));

        if (!$participant_exists) {
            // SISTEMA DE DIAGNÓSTICO: Detectar si se pasó assignment_id en lugar de participant_id
            $diagnostic_info = self::diagnose_participant_id_issue($participant_id, $survey_id);
            error_log(sprintf(
                '[EIPSI MagicLinksService] Participant not found: %d. Diagnóstico: %s',
                $participant_id,
                $diagnostic_info['message']
            ));

            // Si detectamos el participant_id correcto, usarlo
            if ($diagnostic_info['correct_participant_id']) {
                error_log(sprintf(
                    '[EIPSI MagicLinksService] AUTO-CORRECCIÓN: Usando participant_id=%d en lugar de %d',
                    $diagnostic_info['correct_participant_id'],
                    $participant_id
                ));
                $participant_id = $diagnostic_info['correct_participant_id'];
                $participant_exists = true;
            } else {
                return false;
            }
        }

        // Validate survey_id exists in wp_survey_studies (longitudinal study table)
        // survey_id references survey_studies.id, NOT wp_posts.ID
        $study_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}survey_studies WHERE id = %d",
            $survey_id
        ));

        if (!$study_exists) {
            error_log('[EIPSI MagicLinksService] Study not found in survey_studies for survey_id: ' . $survey_id);
            return false;
        }

        // Delete any existing unused tokens for this participant in this survey
        $deleted = $wpdb->delete(
            "{$wpdb->prefix}survey_magic_links",
            array(
                'survey_id' => $survey_id,
                'participant_id' => $participant_id,
                'used_at' => null
            ),
            array('%d', '%d', '%s')
        );

        if ($deleted !== false && $deleted > 0) {
            error_log('[EIPSI MagicLinksService] Deleted ' . $deleted . ' old unused tokens for participant ' . $participant_id);
        }

        // Generate UUID4 token
        $token_plain = wp_generate_uuid4();
        $token_hash = hash('sha256', $token_plain);

        // Calculate expiration (48 hours from now)
        $expires_at = current_time('mysql', 1); // GMT
        $expires_at = date('Y-m-d H:i:s', strtotime($expires_at . ' +48 hours'));

        // Insert into database
        $inserted = $wpdb->insert(
            "{$wpdb->prefix}survey_magic_links",
            array(
                'survey_id' => $survey_id,
                'participant_id' => $participant_id,
                'token_hash' => $token_hash,
                'expires_at' => $expires_at,
                'used_at' => null,
                'created_at' => current_time('mysql')
            ),
            array('%d', '%d', '%s', '%s', '%s', '%s')
        );

        if ($inserted === false) {
            error_log('[EIPSI MagicLinksService] Failed to insert magic link: ' . $wpdb->last_error);
            return false;
        }

        $ml_id = $wpdb->insert_id;
        error_log('[EIPSI MagicLinksService] Generated magic link ID ' . $ml_id . ' for participant ' . $participant_id);

        return $token_plain;
    }

    public static function validate_magic_link($token_plain) {
        global $wpdb;

        if (empty($token_plain)) {
            return array('valid' => false, 'reason' => 'empty_token');
        }

        // Hash the token for lookup
        $token_hash = hash('sha256', $token_plain);

        // Query the database
        $magic_link = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}survey_magic_links WHERE token_hash = %s",
            $token_hash
        ));

        if (!$magic_link) {
            return array('valid' => false, 'reason' => 'not_found');
        }

        // Check if already used
        if ($magic_link->used_at !== null) {
            return array(
                'valid' => false,
                'reason' => 'already_used',
                'used_at' => $magic_link->used_at
            );
        }

        // Check expiration
        $now = current_time('mysql', 1); // GMT
        if ($magic_link->expires_at < $now) {
            return array(
                'valid' => false,
                'reason' => 'expired',
                'expired_at' => $magic_link->expires_at
            );
        }

        if (!class_exists('EIPSI_Auth_Service')) {
            require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-auth-service.php';
        }
        $access = EIPSI_Auth_Service::authorize_participant($magic_link->participant_id, $magic_link->survey_id);
        if (!$access['success']) {
            return array('valid' => false, 'reason' => $access['error']);
        }

        // Valid token
        return array(
            'valid' => true,
            'ml_id' => $magic_link->id,
            'survey_id' => $magic_link->survey_id,
            'participant_id' => $magic_link->participant_id,
            'expires_at' => $magic_link->expires_at
        );
    }

    public static function mark_magic_link_used($ml_id) {
        global $wpdb;

        $ml_id = intval($ml_id);

        if ($ml_id <= 0) {
            error_log('[EIPSI MagicLinksService] Invalid ml_id: ' . $ml_id);
            return false;
        }

        $updated = $wpdb->update(
            "{$wpdb->prefix}survey_magic_links",
            array('used_at' => current_time('mysql')),
            array('id' => $ml_id, 'used_at' => null),
            array('%s'),
            array('%d', '%s')
        );

        if ($updated === false) {
            error_log('[EIPSI MagicLinksService] Failed to mark magic link as used: ' . $wpdb->last_error);
            return false;
        }

        if ($updated === 0) {
            error_log('[EIPSI MagicLinksService] Magic link already used or not found: ' . $ml_id);
            return false;
        }

        error_log('[EIPSI MagicLinksService] Marked magic link ' . $ml_id . ' as used');
        return true;
    }

    public static function get_magic_link_by_token($token_hash) {
        global $wpdb;

        if (empty($token_hash)) {
            return null;
        }

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}survey_magic_links WHERE token_hash = %s",
            $token_hash
        ));
    }

    public static function cleanup_expired_magic_links() {
        global $wpdb;

        $now = current_time('mysql', 1); // GMT

        $deleted = $wpdb->delete(
            "{$wpdb->prefix}survey_magic_links",
            array(
                'expires_at' => $now,
                'used_at' => null
            ),
            array('%s', '%s')
        );

        if ($deleted > 0) {
            error_log('[EIPSI MagicLinksService] Cleaned up ' . $deleted . ' expired magic links');
        }

        return $deleted;
    }

    private static function diagnose_participant_id_issue($wrong_id, $study_id) {
        global $wpdb;

        $result = array(
            'message' => 'ID no encontrado en ninguna tabla',
            'correct_participant_id' => null,
            'issue_type' => 'unknown'
        );

        // Verificar si el ID es un assignment_id válido
        $assignment_data = $wpdb->get_row($wpdb->prepare(
            "SELECT id, participant_id, study_id, wave_id, status
             FROM {$wpdb->prefix}survey_assignments
             WHERE id = %d",
            $wrong_id
        ));

        if ($assignment_data) {
            $result['issue_type'] = 'assignment_id_passed';
            $result['correct_participant_id'] = intval($assignment_data->participant_id);
            $result['message'] = sprintf(
                'Se pasó assignment_id=%d en lugar de participant_id=%d. Assignment: study_id=%d, wave_id=%d, status=%s',
                $wrong_id,
                $assignment_data->participant_id,
                $assignment_data->study_id,
                $assignment_data->wave_id,
                $assignment_data->status
            );
            return $result;
        }

        // Verificar si es un wave_id (otro error común)
        $wave_data = $wpdb->get_row($wpdb->prepare(
            "SELECT id, study_id, name, wave_index
             FROM {$wpdb->prefix}survey_waves
             WHERE id = %d",
            $wrong_id
        ));

        if ($wave_data) {
            $result['issue_type'] = 'wave_id_passed';
            $result['message'] = sprintf(
                'Se pasó wave_id=%d (study_id=%d, name=%s) en lugar de participant_id',
                $wrong_id,
                $wave_data->study_id,
                $wave_data->name
            );
            return $result;
        }

        // Verificar si el participante existe pero fue borrado lógicamente
        $deleted_participant = $wpdb->get_row($wpdb->prepare(
            "SELECT id, email, is_active, deleted_at
             FROM {$wpdb->prefix}survey_participants
             WHERE id = %d AND (is_active = 0 OR deleted_at IS NOT NULL)",
            $wrong_id
        ));

        if ($deleted_participant) {
            $result['issue_type'] = 'participant_inactive';
            $result['message'] = sprintf(
                'Participante %d existe pero está inactivo/borrado: email=%s, is_active=%d',
                $wrong_id,
                $deleted_participant->email,
                $deleted_participant->is_active
            );
            return $result;
        }

        // Verificar si el ID es de otra tabla
        $tables_to_check = array(
            'survey_studies' => 'study',
            'survey_responses' => 'response',
            'survey_email_log' => 'email_log',
            'survey_magic_links' => 'magic_link'
        );

        foreach ($tables_to_check as $table => $type) {
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}{$table} WHERE id = %d",
                $wrong_id
            ));
            if ($exists) {
                $result['issue_type'] = 'wrong_table_id';
                $result['message'] = sprintf(
                    'Se pasó un %s_id=%d en lugar de participant_id',
                    $type,
                    $wrong_id
                );
                return $result;
            }
        }

        // El ID no existe en ninguna tabla conocida
        $result['message'] = sprintf(
            'ID=%d no existe en ninguna tabla del sistema (ni participants, assignments, waves, studies, etc.)',
            $wrong_id
        );

        return $result;
    }
}
