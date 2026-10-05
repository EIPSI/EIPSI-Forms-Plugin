<?php
/**
 * Nudge Event Scheduler
 *
 * Sistema Event-Driven que programa envíos exactos de nudges
 * usando wp_schedule_single_event en lugar de polling.
 *
 * @package EIPSI_Forms
 * @since 2.5.0
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/notifications/bootstrap.php';


/**
 * Event Scheduler para Nudges
 */
class EIPSI_Nudge_Event_Scheduler {

    /** Rebuild only follow-ups: configuration edits must never resend nudge 0. */
    public static function refresh_wave_follow_ups($wave_id, $assignment_id = null) {
        return EIPSI_Notification_Nudge_Schedule_Service::refresh_wave_follow_ups($wave_id, $assignment_id);
    }

    /**
     * Hook para eventos de nudge
     */
    const NUDGE_EVENT_HOOK = 'eipsi_scheduled_nudge_event';

    /**
     * Inicializar el sistema de eventos
     */
    public static function init() {
        // Registrar el hook que procesará los eventos programados
        add_action(self::NUDGE_EVENT_HOOK, array(__CLASS__, 'execute_scheduled_nudge'), 10, 1);

        // Hook para cuando una wave se hace disponible
        add_action('eipsi_wave_available', array(__CLASS__, 'schedule_nudge_sequence'), 10, 1);

        // Hook para reintento programado cuando wave no estaba disponible inicialmente
        add_action('eipsi_wave_available_retry', array(__CLASS__, 'schedule_nudge_sequence'), 10, 1);

        // Phase 5 T1-Anchor: Hook para recalcular nudges cuando cambian deadlines
        add_action('eipsi_assignment_deadline_changed', array(__CLASS__, 'reschedule_nudges_for_deadline'), 10, 1);

        // Phase 5 T1-Anchor: Hook automático cuando se ancla T1
        add_action('eipsi_t1_anchored', array(__CLASS__, 'reschedule_all_nudges_for_participant'), 10, 2);
    }

    /**
     * Programar secuencia completa de nudges cuando una wave se hace disponible
     *
     * @param int $assignment_id ID de la asignación
     */
    public static function schedule_nudge_sequence($assignment_id) {
        return EIPSI_Notification_Nudge_Schedule_Service::schedule_nudge_sequence($assignment_id);
    }

    /**
     * Ejecutar un nudge programado
     *
     * @param array $args Argumentos del evento: assignment_id, stage, scheduled_at
     */
    public static function execute_scheduled_nudge($args) {
        return EIPSI_Notification_Nudge_Worker_Service::execute_scheduled_nudge($args);
    }



    /**
     * Cancelar todos los eventos programados para una asignación
     *
     * @param int $assignment_id ID de la asignación
     */
    public static function cancel_scheduled_nudges($assignment_id) {
        return EIPSI_Notification_Nudge_Schedule_Service::cancel_scheduled_nudges($assignment_id);
    }

    /**
     * Phase 5 T1-Anchor: Recalcular nudges cuando cambia el deadline de un assignment
     *
     * @param int $assignment_id Assignment ID
     * @since 2.6.0
     */
    public static function reschedule_nudges_for_deadline($assignment_id) {
        return EIPSI_Notification_Nudge_Schedule_Service::reschedule_nudges_for_deadline($assignment_id);
    }

    /**
     * Phase 5 T1-Anchor: Recalcular todos los nudges de un participante cuando se ancla T1
     *
     * @param int $study_id Study ID
     * @param int $participant_id Participant ID
     * @since 2.6.0
     */
    public static function reschedule_all_nudges_for_participant($study_id, $participant_id) {
        return EIPSI_Notification_Nudge_Schedule_Service::reschedule_all_nudges_for_participant($study_id, $participant_id);
    }

    /**
     * Obtener lista de eventos programados para debugging
     */
    public static function get_scheduled_events() {
        return EIPSI_Notification_Nudge_Schedule_Service::get_scheduled_events();
    }





    /**
     * Cancel all pending nudges for an assignment (Phase 5 T1-Anchor)
     *
     * Marks all pending jobs in the queue as 'cancelled'. Used when:
     * - Wave availability changes (T1 completion triggers recalculation)
     * - Wave is skipped (participant moved to next wave)
     *
     * @param int $assignment_id Assignment ID
     * @return int Number of nudges cancelled
     * @since 2.6.0
     */
    public static function cancel_nudges_for_assignment($assignment_id) {
        return EIPSI_Notification_Nudge_Schedule_Service::cancel_nudges_for_assignment($assignment_id);
    }

    /**
     * Reschedule nudges for an assignment (Phase 5 T1-Anchor)
     *
     * Cancels existing pending nudges and schedules new ones based on
     * the current available_at timestamp. Used when wave availability
     * changes due to T1 completion.
     *
     * @param int $assignment_id Assignment ID
     * @return bool True if rescheduled successfully
     * @since 2.6.0
     */
    public static function reschedule_nudges_for_assignment($assignment_id) {
        return EIPSI_Notification_Nudge_Schedule_Service::reschedule_nudges_for_assignment($assignment_id);
    }

    /**
     * Schedule ONLY follow-up nudges (1-4) for an assignment
     * Used when Nudge 0 was already sent but follow-ups weren't scheduled
     *
     * @param object $assignment Assignment object with all fields
     * @return int Number of nudges scheduled
     * @since 2.6.3
     */
    public static function schedule_follow_up_nudges_only($assignment) {
        return EIPSI_Notification_Nudge_Schedule_Service::schedule_follow_up_nudges_only($assignment);
    }


}

// Inicializar al cargar
add_action('init', array('EIPSI_Nudge_Event_Scheduler', 'init'));
