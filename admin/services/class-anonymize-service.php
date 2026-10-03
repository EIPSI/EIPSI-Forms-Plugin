<?php
/**
 * EIPSI Anonymize Service
 *
 * Maneja anonimización y cierre ético de estudios.
 *
 * La anonimización es irreversible y debe ejecutarse con cautela:
 * - Borra PII (email, password, nombre) de participantes
 * - Invalida todos los magic links
 * - Mantiene datos de respuestas (sin PII) para análisis
 * - Registra todas las acciones en audit log
 *
 * @package EIPSI_Forms
 * @since 1.4.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class EIPSI_Anonymize_Service {

    /**
     * Anonimizar encuesta completa (irreversible)
     *
     * Anonimiza TODOS los participantes de un survey, borra PII y magic links.
     * Esta acción es irreversible y debe confirmarse antes de ejecutarse.
     *
     * @param int $survey_id ID del survey
     * @param string $audit_reason Razón por la que se anonimiza (para auditoría)
     * @return array { success: bool, anonymized_count: int, error: string }
     *
     * @example
     * $result = EIPSI_Anonymize_Service::anonymize_survey(123, 'Study completed');
     * if ($result['success']) {
     *     echo "Anonymized {$result['anonymized_count']} participants";
     * }
     */
    public static function anonymize_survey($survey_id, $audit_reason = '') {
        global $wpdb;
        $count=0;
        $coverage=array('source'=>'wordpress_db','complete'=>false,'not_covered'=>array('external_db','unlinked_browser_records','historical_export_files','unclassified_free_text'));
        if(!current_user_can('manage_options')){return array('success'=>false,'anonymized_count'=>0,'error'=>'insufficient_permissions');}
        $allowed=self::can_anonymize_survey($survey_id);
        if(!$allowed['can_anonymize']){return array('success'=>false,'anonymized_count'=>0,'error'=>$allowed['reason']);}
        $participants=$wpdb->get_col($wpdb->prepare("SELECT id FROM {$wpdb->prefix}survey_participants WHERE survey_id=%d",absint($survey_id)));
        if($wpdb->last_error){return array('success'=>false,'anonymized_count'=>0,'error'=>'participant_query_failed');}
        foreach($participants as$id){
            $result=self::anonymize_participant($id,$audit_reason);
            $coverage['not_covered']=array_values(array_unique(array_merge($coverage['not_covered'],$result['coverage']['not_covered']??array())));
            if(!$result['success']){return array('success'=>false,'anonymized_count'=>$count,'partial_count'=>$count,'error'=>$result['error'],'coverage'=>$coverage);}
            $count++;
        }
        if(!self::audit_log('anonymize_survey',$survey_id,null,array('operation'=>'anonymize','anonymized_count'=>$count,'local_only'=>true))){
            return array('success'=>false,'anonymized_count'=>$count,'error'=>'audit_failed','coverage'=>$coverage);
        }
        return array('success'=>true,'anonymized_count'=>$count,'error'=>null,'coverage'=>$coverage);
    }

    /**
     * Anonimizar un solo participante
     *
     * @param int $participant_id ID del participante
     * @param string $audit_reason Razón de la anonimización
     * @return array { success: bool, error: string }
     *
     * @example
     * $result = EIPSI_Anonymize_Service::anonymize_participant(456, 'Participant withdrawal');
     * if ($result['success']) {
     *     echo "Participant anonymized successfully";
     * }
     */
    public static function anonymize_participant($participant_id, $audit_reason = '') {
        if (!current_user_can('manage_options')) { return array('success'=>false,'error'=>'insufficient_permissions'); }
        require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-participant-data-cleanup.php';
        $result = EIPSI_Participant_Data_Cleanup::run($participant_id, 'anonymize', $audit_reason);
        $result['error'] = $result['success'] ? null : implode('; ', $result['errors']);
        return $result;
    }

    /**
     * Borrar PII de un participante
     *
     * Borra Personal Identifiable Information manteniendo los datos clínicos.
     *
     * @param int $participant_id ID del participante
     * @return bool True si se borró PII correctamente
     *
     * @example
     * $deleted = EIPSI_Anonymize_Service::delete_pii(456);
     * // Email becomes: anonymous_456@deleted.local
     * // password_hash = NULL
     * // first_name = NULL
     * // last_name = NULL
     */
    public static function delete_pii($participant_id) {
        $result = self::anonymize_participant($participant_id);
        return $result['success'];
    }

    /**
     * Invalidar todos los magic links de un survey
     *
     * Marca todos los magic links como usados para evitar acceso futuro.
     *
     * @param int $survey_id ID del survey
     * @return int Count de filas afectadas (int)
     *
     * @example
     * $invalidated = EIPSI_Anonymize_Service::invalidate_magic_links(123);
     * echo "Invalidated $invalidated magic links";
     */
    public static function invalidate_magic_links($survey_id) {
        global $wpdb;

        $survey_id = intval($survey_id);
        if ($survey_id <= 0) {
            return 0;
        }

        $magic_links_table = $wpdb->prefix . 'survey_magic_links';

        // Mark all unused links as used
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE $magic_links_table SET
                used_at = NOW(),
                expires_at = NOW()
            WHERE survey_id = %d AND used_at IS NULL AND expires_at > NOW()",
            $survey_id
        ));

        return $result !== false ? intval($result) : 0;
    }

    /**
     * Invalidar magic links de un participante
     *
     * @param int $participant_id ID del participante
     * @return int Count de filas afectadas (int)
     *
     * @example
     * $invalidated = EIPSI_Anonymize_Service::invalidate_participant_magic_links(456);
     * echo "Invalidated $invalidated magic links for participant";
     */
    public static function invalidate_participant_magic_links($participant_id) {
        global $wpdb;

        $participant_id = intval($participant_id);
        if ($participant_id <= 0) {
            return 0;
        }

        $magic_links_table = $wpdb->prefix . 'survey_magic_links';

        // Mark all unused links as used for this participant
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE $magic_links_table SET
                used_at = NOW(),
                expires_at = NOW()
            WHERE participant_id = %d AND used_at IS NULL",
            $participant_id
        ));

        return $result !== false ? intval($result) : 0;
    }

    /**
     * Registrar acción en audit log
     *
     * Todas las acciones sensibles deben registrarse para auditoría ética.
     *
     * @param string $action Tipo de acción ('anonymize_survey', 'anonymize_participant', 'invalidate_links', etc.)
     * @param int $survey_id ID del survey
     * @param int $participant_id ID del participante (opcional)
     * @param array $metadata Metadatos adicionales (JSON)
     * @return bool True si se registró correctamente
     *
     * @example
     * EIPSI_Anonymize_Service::audit_log(
     *     'manual_override_wave_status',
     *     123,
     *     456,
     *     array('wave_index' => 2, 'old_status' => 'pending', 'new_status' => 'submitted')
     * );
     */
    public static function audit_log($action, $survey_id, $participant_id = null, $metadata = array()) {
        global $wpdb;

        // Validate action is in required actions list
        $required_actions = unserialize(EIPSI_AUDIT_REQUIRED_ACTIONS);
        if (!in_array($action, $required_actions, true)) {
            return false;
        }

        // Get actor info
        $user_id = get_current_user_id();
        $is_cli = (php_sapi_name() === 'cli' || defined('WP_CLI') && WP_CLI);

        $actor_type = $is_cli || $user_id === 0 ? 'system' : 'admin';
        $actor_id = $user_id;

        if ($user_id > 0) {
            $current_user = wp_get_current_user();
            $actor_username = $current_user->user_login;
        } else {
            $actor_username = null;
        }

        // Get IP address
        $ip_address = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : null;

        // Serialize metadata
        $metadata_json = is_string($metadata) ? $metadata : wp_json_encode($metadata);

        // Insert into audit log
        $audit_table = $wpdb->prefix . 'survey_audit_log';

        $result = $wpdb->insert(
            $audit_table,
            array(
                'survey_id' => intval($survey_id),
                'participant_id' => $participant_id ? intval($participant_id) : null,
                'action' => sanitize_text_field($action),
                'actor_type' => in_array($actor_type, array('admin', 'system'), true) ? $actor_type : 'system',
                'actor_id' => $actor_id,
                'actor_username' => $actor_username,
                'ip_address' => $ip_address,
                'metadata' => $metadata_json,
                'created_at' => current_time('mysql', 1)
            ),
            array('%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s')
        );

        if (EIPSI_LONGITUDINAL_DEBUG) {
            error_log(sprintf(
                '[EIPSI Audit] %s action logged: survey=%d, participant=%s, actor=%s (%s)',
                $action,
                $survey_id,
                $participant_id ? $participant_id : 'N/A',
                $actor_type,
                $actor_username ? $actor_username : 'N/A'
            ));
        }

        return $result !== false;
    }

    /**
     * Obtener historial de auditoría de un survey
     *
     * @param int $survey_id ID del survey
     * @param int $limit Cantidad máxima de registros (default: 100)
     * @return array Array de objetos stdClass con todos los campos
     *
     * @example
     * $log = EIPSI_Anonymize_Service::get_survey_audit_log(123, 50);
     * foreach ($log as $entry) {
     *     echo "{$entry->action} by {$entry->actor_username} at {$entry->created_at}";
     * }
     */
    public static function get_survey_audit_log($survey_id, $limit = 100) {
        global $wpdb;

        $survey_id = intval($survey_id);
        $limit = intval($limit);

        if ($survey_id <= 0) {
            return array();
        }

        $audit_table = $wpdb->prefix . 'survey_audit_log';

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $audit_table
            WHERE survey_id = %d
            ORDER BY created_at DESC
            LIMIT %d",
            $survey_id,
            $limit
        ));

        return $results ? $results : array();
    }

    /**
     * Verificar si un survey puede anonimizarse
     *
     * Valida condiciones previas:
     * - No hay assignments con status='pending' o 'in_progress'
     * - (Opcional) Al menos un assignment con status='submitted'
     *
     * @param int $survey_id ID del survey
     * @return array { can_anonymize: bool, reason: string }
     *
     * @example
     * $check = EIPSI_Anonymize_Service::can_anonymize_survey(123);
     * if (!$check['can_anonymize']) {
     *     echo "Cannot anonymize: " . $check['reason'];
     * }
     */
    public static function can_anonymize_survey($survey_id) {
        global $wpdb;

        $survey_id = intval($survey_id);
        if ($survey_id <= 0) {
            return array(
                'can_anonymize' => false,
                'reason' => 'Invalid survey_id'
            );
        }

        // Check survey exists
        $survey = $wpdb->get_row($wpdb->prepare("SELECT id FROM {$wpdb->prefix}survey_studies WHERE id=%d", $survey_id));
        if (!$survey) {
            return array(
                'can_anonymize' => false,
                'reason' => 'Survey not found'
            );
        }

        // Check for pending/in-progress assignments
        $assignments_table = $wpdb->prefix . 'survey_assignments';

        $pending_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $assignments_table
            WHERE study_id = %d AND status IN ('pending', 'in_progress')",
            $survey_id
        ));

        if ($pending_count > 0) {
            return array(
                'can_anonymize' => false,
                'reason' => sprintf('Survey has %d pending or in-progress assignments', $pending_count)
            );
        }

        // (Optional) Check if at least one submitted assignment exists
        $submitted_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $assignments_table
            WHERE study_id = %d AND status = 'submitted'",
            $survey_id
        ));

        if ($submitted_count === 0) {
            return array(
                'can_anonymize' => false,
                'reason' => 'No submitted assignments found in this survey'
            );
        }

        // All checks passed
        return array(
            'can_anonymize' => true,
            'reason' => 'Survey ready for anonymization',
            'pending_count' => 0,
            'submitted_count' => intval($submitted_count)
        );
    }
}
