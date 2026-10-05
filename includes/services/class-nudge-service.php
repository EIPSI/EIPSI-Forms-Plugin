<?php
/**
 * EIPSI Nudge Service
 * 
 * Maneja el sistema de 5 nudges (0-4) para recordatorios de waves.
 * Nudge 0: Siempre se envía inmediatamente cuando la wave está disponible.
 * Nudges 1-4: Solo si el investigador activó follow_up_reminders_enabled.
 * 
 * @package EIPSI_Forms
 * @since 2.2.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/notifications/bootstrap.php';


/**
 * Class EIPSI_Nudge_Service
 */
class EIPSI_Nudge_Service {
    
    /** Persist the existing UI contract before rebuilding follow-up events. */
    public static function save_wave_configuration($wave_id, $config, $window_supplied = false, $window_minutes = null) {
        return EIPSI_Longitudinal_Assignment_Deadline_Service::save_wave_configuration($wave_id, $config, $window_supplied, $window_minutes);
    }

    /**
     * Nudge configurations
     */
    const NUDGE_AVAILABLE = 0;      // "Tu Toma X está lista"
    const NUDGE_FOLLOW_UP = 1;      // "¿Ya completaste?" / "Quedan 2 días"
    const NUDGE_REMINDER = 2;       // "Te esperamos" / "Mañana vence"
    const NUDGE_URGENCY = 3;        // "¿Necesitás ayuda?" / "Plazo extendido"
    const NUDGE_LAST_CALL = 4;      // "Última oportunidad"
    
    /**
     * Get the nudge configuration for a specific stage
     * 
     * @param int $stage Nudge stage (0-4)
     * @param bool $has_due_date Whether the wave has a due date
     * @return array|null Nudge configuration or null if invalid
     */
    public static function get_nudge_config($stage, $has_due_date = false) {
        return EIPSI_Notification_Nudge_Policy_Service::get_nudge_config($stage, $has_due_date);
    }
    
    /**
     * Get all nudge configurations
     * 
     * @param bool $has_due_date Whether the wave has a due date
     * @return array All nudge configurations
     */
    public static function get_all_nudge_configs($has_due_date = false) {
        return EIPSI_Notification_Nudge_Policy_Service::get_all_nudge_configs($has_due_date);
    }
    
    /**
     * Get timeline preview for UI display
     * 
     * @param bool $has_due_date Whether the wave has a due date
     * @return string Timeline description
     */
    public static function get_timeline_preview($has_due_date = false) {
        return EIPSI_Notification_Nudge_Policy_Service::get_timeline_preview($has_due_date);
    }
    
    /**
     * Convert any time unit to seconds for calculations
     * 
     * @param int $value Time value
     * @param string $unit Unit: minutes, hours, days
     * @return int Seconds
     */
    public static function convert_to_seconds($value, $unit = 'days') {
        return EIPSI_Notification_Nudge_Policy_Service::convert_to_seconds($value, $unit);
    }
    
    /**
     * Check if a nudge should be sent now
     * 
     * @param object $assignment Assignment data
     * @param object $wave Wave data
     * @param int $current_stage Current reminder stage
     * @param array $custom_config Optional custom config with unit support
     * @return bool Whether nudge should be sent
     */
    public static function should_send_nudge($assignment, $wave, $current_stage, $custom_config = null) {
        return EIPSI_Notification_Nudge_Policy_Service::should_send_nudge($assignment, $wave, $current_stage, $custom_config);
    }
    
    /**
     * Get the next stage to send
     * 
     * @param int $current_stage Current stage (0-4)
     * @return int|null Next stage or null if completed
     */
    public static function get_next_stage($current_stage) {
        return EIPSI_Notification_Nudge_Policy_Service::get_next_stage($current_stage);
    }
    
    /**
     * Get human-readable description of a nudge stage
     * 
     * @param int $stage Stage number
     * @param bool $has_due_date Whether wave has due date
     * @return string Description
     */
    public static function get_stage_description($stage, $has_due_date = false) {
        return EIPSI_Notification_Nudge_Policy_Service::get_stage_description($stage, $has_due_date);
    }
}
