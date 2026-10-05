<?php
/**
 * Assignment Service
 *
 * Gestión de asignaciones participante → wave dentro de estudios longitudinales.
 *
 * @package EIPSI_Forms
 * @since 1.4.0
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/longitudinal/bootstrap.php';

// Commented out to reduce log noise - file loads on every request
// if (defined('WP_DEBUG') && WP_DEBUG) {
//     error_log('[EIPSI-DIAG-CREATE] Archivo class-assignment-service.php CARGADO - versión 1.5.7');
// }

class EIPSI_Assignment_Service {

    /**
     * Crear asignación
     *
     * Crea (o asegura) la asignación de un participante a una wave.
     *
     * Nota: es idempotente por UNIQUE(wave_id, participant_id).
     *
     * @param int $study_id ID del estudio
     * @param int $wave_id ID de la wave
     * @param int $participant_id ID del participante
     * @return int|array|WP_Error Insert ID (int) si se creó, asignación (array) si ya existía, o WP_Error
     */
    public static function create_assignment($study_id, $wave_id, $participant_id) {
        return EIPSI_Longitudinal_Assignment_Repository::create_assignment($study_id, $wave_id, $participant_id);
    }

    /**
     * Obtener asignación
     *
     * @param int $wave_id ID de la wave
     * @param int $participant_id ID del participante
     * @return array|null
     */
    public static function get_assignment($wave_id, $participant_id) {
        return EIPSI_Longitudinal_Assignment_Repository::get_assignment($wave_id, $participant_id);
    }

    /**
     * Obtener asignaciones de un participante
     *
     * @param int $participant_id ID del participante
     * @param int|null $study_id Opcional: filtrar por estudio
     * @return array
     */
    public static function get_participant_assignments($participant_id, $study_id = null) {
        return EIPSI_Longitudinal_Assignment_Repository::get_participant_assignments($participant_id, $study_id);
    }

    /**
     * Actualizar estado de asignación
     *
     * - Si pasa a "submitted", setea submitted_at.
     * - Si pasa a "in_progress" y no había first_viewed_at, setea first_viewed_at.
     *
     * @param int $wave_id ID de la wave
     * @param int $participant_id ID del participante
     * @param string $status Nuevo estado
     * @return bool|WP_Error
     */
    public static function update_assignment_status($wave_id, $participant_id, $status) {
        return EIPSI_Longitudinal_Assignment_Transition_Service::update_assignment_status($wave_id, $participant_id, $status);
    }

    /**
     * Incrementar contador de recordatorios
     *
     * @param int $wave_id ID de la wave
     * @param int $participant_id ID del participante
     * @return bool
     */
    public static function increment_reminder_count($wave_id, $participant_id) {
        global $wpdb;

        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->prefix}survey_assignments
                 SET reminder_count = reminder_count + 1,
                     last_reminder_sent = %s
                 WHERE wave_id = %d AND participant_id = %d",
                current_time('mysql'),
                absint($wave_id),
                absint($participant_id)
            )
        );
        
        // v2.5.0 - Invalidate cache for this assignment
        if ($updated !== false && class_exists('EIPSI_Nudge_Cache')) {
            // Get assignment ID for cache invalidation
            $assignment = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}survey_assignments WHERE wave_id = %d AND participant_id = %d",
                $wave_id,
                $participant_id
            ));
            
            if ($assignment) {
                EIPSI_Nudge_Cache::invalidate_assignment_cache($assignment->id);
            }
        }

        return $updated !== false;
    }

    /**
     * Obtener participantes en riesgo (para Dropout Management)
     *
     * @param int $study_id ID del estudio
     * @param int $days_overdue Días de retraso para considerar en riesgo (default: 7)
     * @return array Lista de participantes con info de wave y retraso
     */
    public static function get_at_risk_participants($study_id = 0, $days_overdue = 7) {
        return EIPSI_Longitudinal_Assignment_Repository::get_at_risk_participants($study_id, $days_overdue);
    }

    /**
     * Obtener estadísticas de dropout
     *
     * @param int $study_id ID del estudio
     * @param int $days_overdue Días de retraso
     * @return array {at_risk, pending, reminders_today}
     */
    public static function get_dropout_stats($study_id = 0, $days_overdue = 7) {
        return EIPSI_Longitudinal_Assignment_Repository::get_dropout_stats($study_id, $days_overdue);
    }

    /**
     * Extender vencimiento de asignación
     *
     * @param int $assignment_id ID de la asignación
     * @param int $days Días a extender
     * @return bool|WP_Error
     */
    public static function extend_wave_deadline($assignment_id, $days = 7) {
        return EIPSI_Longitudinal_Assignment_Transition_Service::extend_wave_deadline($assignment_id, $days);
    }

    /**
     * Marcar wave como completada (manual override)
     *
     * @param int $assignment_id ID de la asignación
     * @return bool|WP_Error
     */
    public static function mark_wave_completed($assignment_id) {
        return EIPSI_Longitudinal_Assignment_Transition_Service::mark_wave_completed($assignment_id);
    }

    /**
     * Desactivar participante
     *
     * @param int $participant_id ID del participante
     * @return bool|WP_Error
     */
    public static function deactivate_participant($participant_id) {
        global $wpdb;

        $participant_id = absint($participant_id);

        if (!$participant_id) {
            return new WP_Error('invalid_params', 'Invalid participant_id');
        }

        $updated = $wpdb->update(
            $wpdb->prefix . 'survey_participants',
            array('is_active' => 0),
            array('id' => $participant_id),
            array('%d'),
            array('%d')
        );

        if ($updated === false) {
            return new WP_Error('db_error', 'Failed to deactivate participant: ' . $wpdb->last_error);
        }

        return true;
    }

    /**
     * Obtener asignación por ID
     *
     * @param int $assignment_id
     * @return array|null
     */
    public static function get_assignment_by_id($assignment_id) {
        return EIPSI_Longitudinal_Assignment_Repository::get_assignment_by_id($assignment_id);
    }

    /**
     * Create assignments for a participant for all active waves of a study.
     *
     * This is called after email confirmation to set up all wave assignments.
     * The function is idempotent - if an assignment already exists, it's skipped.
     *
     * @param int $participant_id ID del participante
     * @param int $study_id ID del estudio
     * @return array { created: int, skipped: int, errors: array }
     * @since 1.5.7
     * @access public
     */
    public static function create_assignments_for_participant($participant_id, $study_id) {
        return EIPSI_Longitudinal_Assignment_Service::create_assignments_for_participant($participant_id, $study_id);
    }
}

/**
 * Global function wrapper for creating assignments for a participant.
 *
 * This function can be called from anywhere to create assignments
 * for a participant after email confirmation.
 *
 * @param int $participant_id ID del participante
 * @param int $study_id ID del estudio
 * @return array Result array with created, skipped, and errors counts
 * @since 1.5.7
 */
function eipsi_create_assignments_for_participant($participant_id, $study_id) {
    // Ensure service is loaded
    if (!class_exists('EIPSI_Assignment_Service')) {
        require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-assignment-service.php';
    }
    
    return EIPSI_Assignment_Service::create_assignments_for_participant($participant_id, $study_id);
}
