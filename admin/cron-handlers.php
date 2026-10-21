<?php
/**
 * EIPSI Forms - Cron Handlers for Longitudinal Reminders
 *
 * Gestiona envío automático de recordatorios para tomas pendientes.
 *
 * @package EIPSI_Forms
 * @since 1.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/notifications/bootstrap.php';


require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/longitudinal/bootstrap.php';

// === LEGACY CRON HOOKS (v1.3.0) ===
add_action('eipsi_send_take_reminders_daily', 'eipsi_process_daily_reminders');
add_action('eipsi_send_take_reminders_weekly', 'eipsi_process_weekly_reminders');
add_action('eipsi_send_manual_reminder', 'eipsi_send_manual_reminder_handler', 10, 2);

// === TASK 4.2 CRON HOOKS (v1.4.2) ===
add_action('eipsi_send_wave_reminders_hourly', 'eipsi_run_send_wave_reminders');
add_action('eipsi_send_dropout_recovery_hourly', 'eipsi_run_send_dropout_recovery');

// === DOUBLE OPT-IN CRON HOOKS (v1.5.0) ===
add_action('eipsi_cleanup_unconfirmed_participants_daily', 'eipsi_run_cleanup_unconfirmed_participants');

// === STUDY CRON JOBS (v1.5.3) ===
add_action('eipsi_study_cron_job', 'eipsi_run_study_cron_job', 10, 1);

// === WAVE EXPIRATION (v2.6.0 - Phase 2 T1-Anchor) ===
add_action('eipsi_hourly_wave_expiration_check', 'eipsi_run_wave_expiration_check');

/**
 * Procesa recordatorios diarios
 * 
 * @since 1.3.0
 */
function eipsi_process_daily_reminders() {
        return EIPSI_Notification_Legacy_Reminder_Service::eipsi_process_daily_reminders();
    }

/**
 * Procesa recordatorios semanales
 * 
 * @since 1.3.0
 */
function eipsi_process_weekly_reminders() {
        return EIPSI_Notification_Legacy_Reminder_Service::eipsi_process_weekly_reminders();
    }

/**
 * Lógica principal de procesamiento de recordatorios
 * 
 * @param string $frequency 'daily' | 'weekly'
 * @since 1.3.0
 */
function eipsi_process_reminders($frequency) {
        return EIPSI_Notification_Legacy_Reminder_Service::eipsi_process_reminders($frequency);
    }

/**
 * Obtiene tomas pendientes para un formulario
 * 
 * @param int $form_id Form ID
 * @return array Array de tomas pendientes { email, take_num, form_id, ... }
 * @since 1.3.0
 */
function eipsi_get_pending_takes($form_id) {
        return EIPSI_Notification_Legacy_Reminder_Service::eipsi_get_pending_takes($form_id);
    }

/**
 * Envía un recordatorio de toma pendiente
 * 
 * @param int $form_id Form ID
 * @param array $take Datos de la toma { email, take_num, form_id, seed }
 * @return bool True si se envió exitosamente
 * @since 1.3.0
 */
function eipsi_send_take_reminder($form_id, $take) {
        return EIPSI_Notification_Legacy_Reminder_Service::eipsi_send_take_reminder($form_id, $take);
    }

/**
 * Verifica si ya se envió un recordatorio hoy para este email
 * 
 * @param int $form_id Form ID
 * @param string $email Email del participante
 * @return bool True si ya se envió hoy
 * @since 1.3.0
 */
function eipsi_reminder_sent_today($form_id, $email) {
        return EIPSI_Notification_Legacy_Reminder_Service::eipsi_reminder_sent_today($form_id, $email);
    }

/**
 * Verifica si un email está unsubscribed
 * 
 * @param int $form_id Form ID
 * @param string $email Email del participante
 * @return bool True si está unsubscribed
 * @since 1.3.0
 */
function eipsi_is_unsubscribed($form_id, $email) {
        return EIPSI_Notification_Legacy_Reminder_Service::eipsi_is_unsubscribed($form_id, $email);
    }

/**
 * Handler para envío manual de recordatorio (programado)
 * 
 * @param string $email Email del participante
 * @param string $reminder_link Link de recordatorio
 * @since 1.3.0
 */
function eipsi_send_manual_reminder_handler($email, $reminder_link) {
        return EIPSI_Notification_Legacy_Reminder_Service::eipsi_send_manual_reminder_handler($email, $reminder_link);
    }

// =================================================================
// TASK 4.2 - Longitudinal Reminders & Recovery (v1.4.2)
// =================================================================

/**
 * Ejecuta el proceso de envío de recordatorios de waves
 *
 * @since 1.4.2
 */
function eipsi_run_send_wave_reminders() {
        return EIPSI_Notification_Legacy_Reminder_Service::eipsi_run_send_wave_reminders();
    }

/**
 * Ejecuta el proceso de recuperación de dropouts
 *
 * @since 1.4.2
 */
function eipsi_run_send_dropout_recovery() {
        return EIPSI_Notification_Legacy_Reminder_Service::eipsi_run_send_dropout_recovery();
    }

/**
 * Envía alerta al investigador con resumen de actividad
 *
 * @param int $survey_id
 * @param array $stats
 * @since 1.4.2
 */
function eipsi_send_investigator_alert($survey_id, $stats) {
        return EIPSI_Notification_Legacy_Reminder_Service::eipsi_send_investigator_alert($survey_id, $stats);
    }

/**
 * Ejecuta las tareas programadas para un estudio
 * 
 * @param int $study_id ID del estudio
 * @since 1.5.3
 */
function eipsi_run_study_cron_job($study_id) {
    error_log("[EIPSI Cron] Study cron job started for study {$study_id} at " . current_time('mysql'));

    // Verificar que el estudio existe
    if (!get_post($study_id)) {
        error_log("[EIPSI Cron] Study {$study_id} not found. Aborting.");
        return;
    }

    // Obtener configuración
    $cron_enabled = get_post_meta($study_id, '_eipsi_study_cron_enabled', true);
    $cron_actions = get_post_meta($study_id, '_eipsi_study_cron_actions', true);

    if (!$cron_enabled || empty($cron_actions)) {
        error_log("[EIPSI Cron] Study {$study_id} cron is not enabled or no actions configured.");
        return;
    }

    // Actualizar última ejecución
    update_post_meta($study_id, '_eipsi_study_cron_last_run', current_time('mysql'));

    $results = array(
        'study_id' => $study_id,
        'actions_executed' => 0,
        'actions_failed' => 0,
        'details' => array()
    );

    // Ejecutar cada acción configurada
    foreach ($cron_actions as $action) {
        try {
            switch ($action) {
                case 'send_reminders':
                    $result = eipsi_cron_action_send_reminders($study_id);
                    $results['details']['send_reminders'] = $result;
                    $results['actions_executed']++;
                    break;

                case 'sync_data':
                    $result = eipsi_cron_action_sync_data($study_id);
                    $results['details']['sync_data'] = $result;
                    $results['actions_executed']++;
                    break;

                case 'generate_reports':
                    $result = eipsi_cron_action_generate_reports($study_id);
                    $results['details']['generate_reports'] = $result;
                    $results['actions_executed']++;
                    break;

                default:
                    error_log("[EIPSI Cron] Unknown action: {$action}");
                    $results['actions_failed']++;
                    break;
            }
        } catch (Exception $e) {
            error_log("[EIPSI Cron] Error executing action {$action}: " . $e->getMessage());
            $results['details'][$action] = array(
                'success' => false,
                'error' => $e->getMessage()
            );
            $results['actions_failed']++;
        }
    }

    // Programar próxima ejecución
    $cron_frequency = get_post_meta($study_id, '_eipsi_study_cron_frequency', true);
    $timestamp = current_time('timestamp');

    switch ($cron_frequency) {
        case 'daily':
            $next_run = strtotime('tomorrow', $timestamp);
            break;
        case 'weekly':
            $next_run = strtotime('next monday', $timestamp);
            break;
        case 'monthly':
            $next_run = strtotime('first day of next month', $timestamp);
            break;
        default:
            $next_run = strtotime('tomorrow', $timestamp);
    }

    // Programar próxima ejecución
    wp_schedule_event($next_run, 'eipsi_' . $cron_frequency, 'eipsi_study_cron_job', array($study_id));
    update_post_meta($study_id, '_eipsi_study_cron_next_run', date('Y-m-d H:i:s', $next_run));

    error_log("[EIPSI Cron] Study cron job completed for study {$study_id}. Actions: " . $results['actions_executed'] . ", Failed: " . $results['actions_failed']);
    error_log("[EIPSI Cron] Next run scheduled for: " . date('Y-m-d H:i:s', $next_run));
}

// =================================================================
// DOUBLE OPT-IN CLEANUP CRON (v1.5.0)
// =================================================================

/**
 * Ejecuta la limpieza diaria de participantes no confirmados
 * 
 * Este cron job:
 * 1. Elimina tokens de confirmación expirados
 * 2. Elimina participantes que nunca confirmaron su email después del período de retención
 * 3. Registra estadísticas del proceso
 *
 * @since 1.5.0
 * @return void
 */
function eipsi_run_cleanup_unconfirmed_participants() {
    error_log('[EIPSI Cron] Unconfirmed participants cleanup started at ' . current_time('mysql'));
    
    // Verificar que el servicio de confirmación existe
    if (!class_exists('EIPSI_Email_Confirmation_Service')) {
        require_once plugin_dir_path(__FILE__) . 'services/class-email-confirmation-service.php';
    }
    
    try {
        // Ejecutar limpieza
        $result = EIPSI_Email_Confirmation_Service::cleanup_expired_confirmations();
        
        // Registrar resultados
        $deleted_confirmations = isset($result['deleted_confirmations']) ? intval($result['deleted_confirmations']) : 0;
        $deleted_participants = isset($result['deleted_participants']) ? intval($result['deleted_participants']) : 0;
        
        if ($deleted_confirmations > 0 || $deleted_participants > 0) {
            error_log("[EIPSI Cron] Cleanup completed: {$deleted_confirmations} expired confirmations deleted, {$deleted_participants} unconfirmed participants deleted");
        } else {
            error_log('[EIPSI Cron] Cleanup completed: No items to delete');
        }
        
        // Opcional: enviar notificación al admin si se eliminaron muchos participantes
        if ($deleted_participants > 10) {
            $admin_email = get_option('admin_email');
            if (is_email($admin_email)) {
                $subject = sprintf('[EIPSI Forms] Limpieza de participantes no confirmados - %d eliminados', $deleted_participants);
                $message = sprintf(
                    "Se han eliminado %d participantes no confirmados durante la limpieza automática diaria.\n\n" .
                    "Fecha: %s\n" .
                    "Tokens expirados eliminados: %d\n" .
                    "Participantes eliminados: %d\n\n" .
                    "Este es un mensaje automático del sistema EIPSI Forms.",
                    $deleted_participants,
                    current_time('mysql'),
                    $deleted_confirmations,
                    $deleted_participants
                );
                wp_mail($admin_email, $subject, $message);
            }
        }
        
    } catch (Exception $e) {
        error_log('[EIPSI Cron] Error during cleanup: ' . $e->getMessage());
    }
    
    error_log('[EIPSI Cron] Unconfirmed participants cleanup completed');
}

/**
 * Acción: Enviar recordatorios de waves pendientes
 * 
 * @param int $study_id ID del estudio
 * @return array Resultado de la ejecución
 */
function eipsi_cron_action_send_reminders($study_id) {
        return EIPSI_Notification_Legacy_Reminder_Service::eipsi_cron_action_send_reminders($study_id);
    }

/**
 * Acción: Sincronizar datos con servidores externos
 * 
 * @param int $study_id ID del estudio
 * @return array Resultado de la ejecución
 */
function eipsi_cron_action_sync_data($study_id) {
    // Implementación futura para sincronización con servidores externos
    error_log("[EIPSI Cron] Sync data action called for study {$study_id} - Not yet implemented");

    return array(
        'success' => true,
        'message' => 'Data sync action completed (not yet implemented)'
    );
}

/**
 * Acción: Generar reportes automáticos
 * 
 * @param int $study_id ID del estudio
 * @return array Resultado de la ejecución
 */
function eipsi_cron_action_generate_reports($study_id) {
    // Implementación futura para generación de reportes
    error_log("[EIPSI Cron] Generate reports action called for study {$study_id} - Not yet implemented");

    return array(
        'success' => true,
        'message' => 'Report generation action completed (not yet implemented)'
    );
}

// =================================================================
// T1-ANCHOR SYSTEM: Assignment Expiration Processor (v2.6.0)
// =================================================================
// This cron processes assignments based on their persisted available_at/due_at.
// Simple comparison: NOW() > due_at → status = 'expired'
// =================================================================
add_action('eipsi_process_assignment_expirations', 'eipsi_run_process_assignment_expirations');

/**
 * Process assignment expirations based on due_at timestamps.
 *
 * This is the core of the T1-Anchor system's cron logic:
 * - Queries assignments where NOW() > due_at AND status NOT IN ('submitted', 'expired')
 * - Marks them as 'expired'
 * - Logs the expiration for audit
 *
 * @since 2.6.0
 */
function eipsi_run_process_assignment_expirations() {
        return EIPSI_Longitudinal_Assignment_Lifecycle_Service::eipsi_run_process_assignment_expirations();
    }

/**
 * Auto-skip expired waves when a later wave is available.
 * 
 * This ensures participants don't see expired waves in their dashboard,
 * instead showing the next available wave.
 * 
 * @since 2.6.1
 */
function eipsi_auto_skip_expired_waves() {
        return EIPSI_Longitudinal_Assignment_Lifecycle_Service::eipsi_auto_skip_expired_waves();
    }

/**
 * Process assignments that are now available based on available_at.
 *
 * This updates assignments where NOW() >= available_at to be "ready"
 * and can trigger notifications if configured.
 *
 * @since 2.6.0
 */
add_action('eipsi_process_wave_availability', 'eipsi_run_process_wave_availability');

function eipsi_run_process_wave_availability() {
        return EIPSI_Notification_Cron_Adapters::eipsi_run_process_wave_availability();
    }

// =================================================================
// POOL EMAIL LOG CLEANUP (v2.5.5)
// =================================================================
add_action('eipsi_cleanup_pool_email_logs_monthly', 'eipsi_run_cleanup_pool_email_logs');

/**
 * Ejecuta la limpieza mensual de logs de emails de pool
 *
 * @since 2.5.5
 */
function eipsi_run_cleanup_pool_email_logs() {
    global $wpdb;
    $log_table = $wpdb->prefix . 'eipsi_pool_email_log';
    
    error_log('[EIPSI Cron] Pool email log cleanup started');
    
    $deleted = $wpdb->query(
        "DELETE FROM {$log_table} WHERE created_at < NOW() - INTERVAL 30 DAY"
    );
    
    error_log("[EIPSI Cron] Pool email log cleanup completed. Deleted: {$deleted} rows");
}

// =================================================================
// WAVE EXPIRATION CHECK (v2.6.0 - Phase 2 T1-Anchor)
// =================================================================

/**
 * Hourly cron job to expire waves that have passed their due_at timestamp.
 *
 * This is the core of Phase 2 automation:
 * - Finds assignments where NOW() >= due_at
 * - Transitions status from 'pending'/'available' to 'expired'
 * - Cancels pending nudges for expired waves
 * - Logs all expirations for audit
 *
 * @since 2.6.0
 */
function eipsi_run_wave_expiration_check() {
    if (!class_exists('EIPSI_Wave_Expiration_Service')) {
        error_log('[EIPSI Cron] EIPSI_Wave_Expiration_Service not found - skipping expiration check');
        return;
    }

    error_log('[EIPSI Cron] Wave expiration check started - ' . current_time('mysql'));

    $results = EIPSI_Wave_Expiration_Service::process_expirations();

    if ($results['success']) {
        error_log(sprintf(
            '[EIPSI Cron] Wave expiration check completed. Expired: %d assignments, Cancelled nudges: %d, Time: %s ms',
            $results['expired_count'],
            $results['nudges_cancelled'],
            $results['execution_time']
        ));
    } else {
        error_log(sprintf(
            '[EIPSI Cron] Wave expiration check completed with errors. Expired: %d, Errors: %d',
            $results['expired_count'],
            count($results['errors'])
        ));

        if (!empty($results['errors'])) {
            foreach ($results['errors'] as $error) {
                error_log('[EIPSI Cron] Expiration error: ' . $error);
            }
        }
    }
}
