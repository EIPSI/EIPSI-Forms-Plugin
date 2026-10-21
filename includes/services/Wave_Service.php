<?php
/**
 * Wave Service - Gestión de tomas longitudinales
 * 
 * Maneja lógica de negocio relacionada con waves (tomas) en estudios longitudinales
 * 
 * @package EIPSI_Forms
 * @since 1.4.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/longitudinal/bootstrap.php';

// Ensure email service is available for reminder functions
if (!class_exists('EIPSI_Email_Service')) {
    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-email-service.php';
}

class Wave_Service {
    /** Compatibility boundary: retain historical notification implementation for M5. */
    public static function notify_submission($participant_id,$study_id,$wave_id) { self::maybe_send_immediate_wave_reminder($participant_id,$study_id,$wave_id); }
    
    /**
     * Obtener próxima toma pendiente para un participante
     * 
     * @param int $participant_id ID del participante
     * @param int $study_id ID del estudio
     * @return array|null Datos de la próxima wave o null si no hay más
     */
    public static function get_next_pending_wave($participant_id, $study_id) {
        return EIPSI_Longitudinal_Assignment_Repository::get_next_pending_wave($participant_id, $study_id);
    }
    
    /**
     * Obtener todas las tomas de un participante
     * 
     * @param int $participant_id
     * @param int $study_id
     * @return array
     */
    public static function get_participant_waves($participant_id, $study_id) {
        return EIPSI_Longitudinal_Assignment_Repository::get_participant_waves($participant_id, $study_id);
    }
    
    /**
     * Marcar assignment como completado
     * 
     * @param int $participant_id
     * @param int $study_id
     * @param int $wave_id
     * @return bool
     */
    public static function mark_assignment_submitted($participant_id, $study_id, $wave_id) {
        return EIPSI_Longitudinal_Assignment_Transition_Service::mark_assignment_submitted($participant_id, $study_id, $wave_id);
    }

    /**
     * Send immediate reminder when next wave becomes available
     * Works for any time unit (minutes, hours, days) - sends email immediately when wave is ready
     *
     * @param int $participant_id
     * @param int $study_id
     * @param int $completed_wave_id
     */
    private static function maybe_send_immediate_wave_reminder($participant_id,$study_id,$wave_id) { return EIPSI_Longitudinal_Notification_Context_Service::notify_submission($participant_id,$study_id,$wave_id); }
    
    /**
     * Verificar si existe un assignment
     * 
     * @param int $participant_id
     * @param int $study_id
     * @param int $wave_id
     * @return bool
     */
    public static function assignment_exists($participant_id, $study_id, $wave_id) {
        return EIPSI_Longitudinal_Assignment_Repository::assignment_exists($participant_id, $study_id, $wave_id);
    }
    
    /**
     * Obtener status actual de un assignment
     * 
     * @param int $participant_id
     * @param int $study_id
     * @param int $wave_id
     * @return string|null 'pending', 'submitted', 'expired', etc.
     */
    public static function get_assignment_status($participant_id, $study_id, $wave_id) {
        return EIPSI_Longitudinal_Assignment_Repository::get_assignment_status($participant_id, $study_id, $wave_id);
    }

    /**
     * NORMALIZAR time_unit de forma segura
     * 
     * CRITICAL: Esta función evita el bug de empty(0) === true
     * que causaba que '0' (minutos) se tratara como vacío.
     * 
     * Acepta: '0', '1', '2', 0, 1, 2, 'minutes', 'hours', 'days'
     * Retorna siempre: 'minutes', 'hours', o 'days'
     * 
     * @param mixed $raw_value Valor crudo de time_unit (puede ser int, string, null)
     * @return string 'minutes', 'hours', o 'days' (default: 'days')
     */
    public static function normalize_time_unit($raw_value) {
        return EIPSI_Longitudinal_Wave_Definition_Service::normalize_time_unit($raw_value);
    }

    /**
     * VALIDAR time_unit al guardar una wave
     * 
     * Usar esta función antes de insertar/actualizar waves para
     * asegurar que time_unit siempre se almacene como string válido.
     * 
     * @param array $wave_data Datos de la wave
     * @return array Datos con time_unit normalizado
     */
    public static function validate_wave_time_unit($wave_data) {
        return EIPSI_Longitudinal_Wave_Definition_Service::validate_wave_time_unit($wave_data);
    }
}
