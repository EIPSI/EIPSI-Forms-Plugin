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
    private static function maybe_send_immediate_wave_reminder($participant_id, $study_id, $completed_wave_id) {
        global $wpdb;

        // Get the completed wave info
        $completed_wave = $wpdb->get_row($wpdb->prepare(
            "SELECT wave_index FROM {$wpdb->prefix}survey_waves WHERE id = %d",
            $completed_wave_id
        ));

        if (!$completed_wave) {
            return;
        }

        // Find next wave
        $next_wave = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}survey_waves
             WHERE study_id = %d AND wave_index = %d
             ORDER BY wave_index ASC LIMIT 1",
            $study_id,
            $completed_wave->wave_index + 1
        ));

        if (!$next_wave) {
            error_log('[Wave_Service] No next wave found after wave ' . $completed_wave->wave_index);
            return;
        }

        // T1-Anchor System: Use offset_minutes (absolute time from T1)
        $offset_minutes = (int) ($next_wave->offset_minutes ?? 0);

        // Get T1 submission time (first wave completion)
        $t1_assignment = $wpdb->get_row($wpdb->prepare(
            "SELECT submitted_at FROM {$wpdb->prefix}survey_assignments 
             WHERE participant_id = %d AND study_id = %d AND wave_id = (
                 SELECT id FROM {$wpdb->prefix}survey_waves 
                 WHERE study_id = %d AND wave_index = 1 LIMIT 1
             ) AND status = 'submitted'
             ORDER BY submitted_at DESC LIMIT 1",
            $participant_id,
            $study_id,
            $study_id
        ));

        if (!$t1_assignment || !$t1_assignment->submitted_at) {
            error_log("[Wave_Service] Could not find T1 submission time for participant {$participant_id}");
            return;
        }

        // Calculate when the next wave becomes available (offset from T1)
        $t1_submitted_at = strtotime($t1_assignment->submitted_at);
        $available_at = $t1_submitted_at + ($offset_minutes * 60);
        $now = current_time('timestamp');

        // Log the calculation for debugging
        error_log(sprintf(
            '[Wave_Service] Next wave availability check (T1-Anchor): t1_submitted_at=%s, offset_minutes=%d, available_at=%s, now=%s',
            date('Y-m-d H:i:s', $t1_submitted_at),
            $offset_minutes,
            date('Y-m-d H:i:s', $available_at),
            date('Y-m-d H:i:s', $now)
        ));

        // Get or create assignment for the next wave
        $next_assignment = $wpdb->get_row($wpdb->prepare(
            "SELECT id, available_at FROM {$wpdb->prefix}survey_assignments 
             WHERE participant_id = %d AND study_id = %d AND wave_id = %d",
            $participant_id,
            $study_id,
            $next_wave->id
        ));

        // Persist available_at if not already set
        $available_at_formatted = date('Y-m-d H:i:s', $available_at);
        if (!$next_assignment) {
            // Create assignment with available_at
            $wpdb->insert(
                $wpdb->prefix . 'survey_assignments',
                array(
                    'study_id' => $study_id,
                    'wave_id' => $next_wave->id,
                    'participant_id' => $participant_id,
                    'status' => 'pending',
                    'available_at' => $available_at_formatted,
                ),
                array('%d', '%d', '%d', '%s', '%s')
            );
            error_log("[Wave_Service] Created assignment for next wave {$next_wave->id} with available_at: {$available_at_formatted}");
        } elseif (empty($next_assignment->available_at)) {
            // Update existing assignment with available_at
            $wpdb->update(
                $wpdb->prefix . 'survey_assignments',
                array('available_at' => $available_at_formatted),
                array('id' => $next_assignment->id),
                array('%s'),
                array('%d')
            );
            error_log("[Wave_Service] Persisted available_at for assignment {$next_assignment->id}: {$available_at_formatted}");
            
            // v2.5.0 - Trigger event-driven scheduling for follow-up nudges
            do_action('eipsi_wave_available', $next_assignment->id);
        } else {
            error_log("[Wave_Service] Using existing available_at for assignment {$next_assignment->id}: {$next_assignment->available_at}");
        }

        // Only send if the wave is actually available now
        if ($now < $available_at) {
            $wait_hours = ceil(($available_at - $now) / 3600);
            error_log(sprintf(
                "[Wave_Service] Next wave not available yet. Scheduling event for assignment %d at %s (~%d hours)",
                $next_assignment->id,
                date('Y-m-d H:i:s', $available_at),
                $wait_hours
            ));
            
            // Schedule exact event when wave becomes available
            wp_clear_scheduled_hook('eipsi_wave_available', array($next_assignment->id));
            wp_schedule_single_event($available_at, 'eipsi_wave_available', array($next_assignment->id));
            return;
        }

        // Wave is NOW available - trigger event-driven nudge system
        error_log("[Wave_Service] Next wave is NOW available - triggering event-driven nudge sequence");
        do_action('eipsi_wave_available', $next_assignment->id);
    }
    
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
