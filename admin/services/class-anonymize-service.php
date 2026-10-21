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

require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/privacy/bootstrap.php';


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
    public static function anonymize_survey($survey_id, $audit_reason = '') { return EIPSI_Privacy_Anonymization_Service::anonymize_survey($survey_id, $audit_reason); }

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
    public static function anonymize_participant($participant_id, $audit_reason = '') { return EIPSI_Privacy_Anonymization_Service::anonymize_participant($participant_id, $audit_reason); }

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
    public static function delete_pii($participant_id) { return EIPSI_Privacy_Anonymization_Service::delete_pii($participant_id); }

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
    public static function invalidate_magic_links($survey_id) { return EIPSI_Privacy_Anonymization_Service::invalidate_magic_links($survey_id); }

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
    public static function invalidate_participant_magic_links($participant_id) { return EIPSI_Privacy_Anonymization_Service::invalidate_participant_magic_links($participant_id); }

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
    public static function audit_log($action, $survey_id, $participant_id = null, $metadata = array()) { return EIPSI_Privacy_Anonymization_Service::audit_log($action, $survey_id, $participant_id, $metadata); }

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
    public static function get_survey_audit_log($survey_id, $limit = 100) { return EIPSI_Privacy_Anonymization_Service::get_survey_audit_log($survey_id, $limit); }

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
    public static function can_anonymize_survey($survey_id) { return EIPSI_Privacy_Anonymization_Service::can_anonymize_survey($survey_id); }
}
