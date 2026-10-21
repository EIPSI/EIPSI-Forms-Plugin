<?php
/**
 * EIPSI_Wave_Availability_Email_Service
 *
 * Sistema robusto de verificación y envío de emails de disponibilidad de wave (Nudge 0).
 * Verifica si la toma está disponible, si el email ya se envió, y reintenta si es necesario.
 *
 * @package EIPSI_Forms
 * @subpackage Services
 * @version 2.2.0
 * @since 2.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/notifications/bootstrap.php';


class EIPSI_Wave_Availability_Email_Service {

    const MAX_RETRY_ATTEMPTS = 3;
    const RETRY_DELAY_MINUTES = 5;
    const EMAIL_TYPE = 'wave_available'; // Nudge 0

    /**
     * Verificación completa del sistema:
     * 1. ¿La toma está disponible?
     * 2. ¿Se envió mail de inmediato (Nudge 0)?
     * 3. Si no se envió → Enviar con reintentos
     * 4. Si ya se envió → No duplicar, esperar siguientes nudges
     *
     * @param object $assignment Assignment data
     * @param object $wave Wave data
     * @param object $participant Participant data
     * @param int $study_id Study ID
     * @return array Resultado detallado
     */
    public static function ensure_wave_availability_email_sent($assignment, $wave, $participant, $study_id) {
        return EIPSI_Notification_Wave_Availability_Service::ensure_wave_availability_email_sent($assignment, $wave, $participant, $study_id);
    }















    /**
     * Forzar envío de Nudge 0 (para uso manual/admin)
     */
    public static function force_send_nudge_zero($assignment, $wave, $participant, $study_id) {
        return EIPSI_Notification_Wave_Availability_Service::force_send_nudge_zero($assignment, $wave, $participant, $study_id);
    }


}
