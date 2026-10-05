<?php
/**
 * Nudge Job Queue Service
 *
 * Sistema de cola de trabajos para envío de nudges.
 * Reemplaza el envío síncrono por una cola persistente con reintentos.
 *
 * @package EIPSI_Forms
 * @since 2.5.0
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/notifications/bootstrap.php';


/**
 * Job Queue para nudges
 */
class EIPSI_Nudge_Job_Queue {

    /** Cancel pending follow-ups without touching the availability notification. */
    public static function cancel_follow_up_jobs($assignment_id) {
        return EIPSI_Notification_Nudge_Queue_Service::cancel_follow_up_jobs($assignment_id);
    }



    /**
     * Encolar un job
     *
     * @param string $job_type Tipo de job: 'send_nudge_0', 'send_nudge_1', etc.
     * @param array $payload Datos del job: assignment_id, participant_id, etc.
     * @param int $priority Prioridad: 1=alta, 10=normal, 100=baja
     * @param string|null $scheduled_at Fecha programada (null = ahora)
     * @return int|false ID del job o false
     */
    public static function enqueue($job_type, $payload, $priority = 10, $scheduled_at = null) {
        return EIPSI_Notification_Nudge_Queue_Service::enqueue($job_type, $payload, $priority, $scheduled_at);
    }

    /**
     * Contar jobs urgentes pendientes (que deberían ya haberse ejecutado)
     *
     * @return int Cantidad de jobs urgentes
     */
    public static function count_pending_urgent() {
        return EIPSI_Notification_Nudge_Queue_Service::count_pending_urgent();
    }

    /**
     * Obtener jobs pendientes para procesar
     *
     * @param int $limit Cuántos jobs procesar
     * @return array Array de jobs
     */
    public static function get_pending_jobs($limit = 10) {
        return EIPSI_Notification_Nudge_Queue_Service::get_pending_jobs($limit);
    }

    /**
     * Marcar job como procesando (bloqueo optimista)
     *
     * @param int $job_id ID del job
     * @return bool Éxito
     */
    public static function mark_processing($job_id) {
        return EIPSI_Notification_Nudge_Queue_Service::mark_processing($job_id);
    }

    /**
     * Marcar job como completado
     *
     * @param int $job_id ID del job
     * @param string|null $result Resultado opcional
     * @return bool Éxito
     */
    public static function mark_completed($job_id, $result = null) {
        return EIPSI_Notification_Nudge_Queue_Service::mark_completed($job_id, $result);
    }

    /**
     * Marcar job para reintento (con backoff exponencial)
     *
     * @param int $job_id ID del job
     * @param string $error Mensaje de error
     * @return bool Éxito
     */
    public static function mark_for_retry($job_id, $error) {
        return EIPSI_Notification_Nudge_Queue_Service::mark_for_retry($job_id, $error);
    }

    /**
     * Procesar un batch de jobs
     *
     * @param int $limit Cuántos jobs procesar
     * @return array Estadísticas
     */
    public static function process_batch($limit = 10) {
        return EIPSI_Notification_Nudge_Worker_Service::process_batch($limit);
    }



    /**
     * Ejecutar Nudge 0 (disponibilidad de wave)
     * v2.1.4 - Cambiado a público para permitir ejecución síncrona desde el scheduler
     */
    public static function execute_nudge_0($payload) {
        return EIPSI_Notification_Nudge_Worker_Service::execute_nudge_0($payload);
    }

    /**
     * Ejecutar Nudge follow-up (1-4)
     * v2.1.4 - Cambiado a público para permitir ejecución síncrona desde el scheduler
     */
    public static function execute_nudge_followup($payload, $stage) {
        return EIPSI_Notification_Nudge_Worker_Service::execute_nudge_followup($payload, $stage);
    }

    /**
     * Obtener estadísticas de la cola
     */
    public static function get_stats() {
        return EIPSI_Notification_Nudge_Queue_Service::get_stats();
    }

    /**
     * Cancelar jobs pendientes para un assignment
     *
     * @param int $assignment_id ID del assignment
     * @return int Número de jobs cancelados
     */
    public static function cancel_jobs_for_assignment($assignment_id) {
        return EIPSI_Notification_Nudge_Queue_Service::cancel_jobs_for_assignment($assignment_id);
    }
}
