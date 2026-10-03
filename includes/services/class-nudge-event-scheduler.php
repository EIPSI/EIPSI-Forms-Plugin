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

/**
 * Event Scheduler para Nudges
 */
class EIPSI_Nudge_Event_Scheduler {
    
    /** Rebuild only follow-ups: configuration edits must never resend nudge 0. */
    public static function refresh_wave_follow_ups($wave_id, $assignment_id = null) {
        global $wpdb;
        $filter = $assignment_id === null ? '' : $wpdb->prepare(' AND a.id = %d', $assignment_id);
        $assignments = $wpdb->get_results($wpdb->prepare(
            "SELECT a.*, w.follow_up_reminders_enabled FROM {$wpdb->prefix}survey_assignments a
             JOIN {$wpdb->prefix}survey_waves w ON w.id = a.wave_id
             WHERE a.wave_id = %d AND a.status IN ('pending','in_progress')" . $filter, $wave_id
        ));
        $scheduled = 0;
        foreach ($assignments as $assignment) {
            require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/services/class-nudge-job-queue.php';
            if (EIPSI_Nudge_Job_Queue::cancel_follow_up_jobs($assignment->id) === false) {
                return new WP_Error('reschedule_failed', 'Configuración guardada; no se pudieron cancelar los jobs anteriores. Reintentá.');
            }
            for ($stage = 1; $stage <= 4; $stage++) {
                foreach (array((int) $assignment->id, (string) $assignment->id) as $id) {
                    wp_clear_scheduled_hook(self::NUDGE_EVENT_HOOK, array(array('assignment_id' => $id, 'stage' => $stage)));
                }
            }
            // reminder_count >= 1 means availability has already been notified.
            if ((int) $assignment->reminder_count >= 1 && !empty($assignment->follow_up_reminders_enabled)) {
                $count = self::schedule_follow_up_nudges_only($assignment);
                if ($count === false) { return new WP_Error('reschedule_failed', 'Configuración guardada; falló la programación. Reintentá.'); }
                $scheduled += $count;
            }
        }
        return $scheduled;
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
        global $wpdb;
        
        error_log(sprintf('[EIPSI EventScheduler] ========================================'));
        error_log(sprintf('[EIPSI EventScheduler] schedule_nudge_sequence CALLED for assignment %d', $assignment_id));
        error_log(sprintf('[EIPSI EventScheduler] Current time: %s', current_time('mysql')));
        
        // Obtener datos de la asignación y su configuración de nudges
        $assignment = $wpdb->get_row($wpdb->prepare(
            "SELECT a.*, w.nudge_config, w.follow_up_reminders_enabled, 
                    a.available_at, p.email, p.first_name
             FROM {$wpdb->prefix}survey_assignments a
             JOIN {$wpdb->prefix}survey_waves w ON a.wave_id = w.id
             JOIN {$wpdb->prefix}survey_participants p ON a.participant_id = p.id
             WHERE a.id = %d AND a.status = 'pending'",
            $assignment_id
        ));
        
        if (!$assignment) {
            error_log(sprintf('[EIPSI EventScheduler] Assignment %d not found or not pending - ABORTING', $assignment_id));
            return false;
        }
        
        error_log(sprintf('[EIPSI EventScheduler] Assignment found: wave_id=%d, follow_up_reminders_enabled=%s', 
            $assignment->wave_id, 
            $assignment->follow_up_reminders_enabled ? 'YES' : 'NO'
        ));
        
        // Calcular timestamp base (cuándo la wave se hizo disponible)
        $available_at = !empty($assignment->available_at) 
            ? strtotime($assignment->available_at)
            : current_time('timestamp');
        
        if (!$available_at) {
            error_log(sprintf('[EIPSI EventScheduler] Invalid available_at for assignment %d', $assignment_id));
            return false;
        }
        
        // Parsear configuración de nudges
        $nudge_config = !empty($assignment->nudge_config) 
            ? json_decode($assignment->nudge_config, true) 
            : array();
        
        error_log(sprintf('[EIPSI EventScheduler] Nudge config: %s', json_encode($nudge_config)));
        
        $scheduled_count = 0;
        
        // v2.1.7 - PRIMERO ejecutar Nudge 0, LUEGO programar nudges 1-4 solo si tuvo éxito
        // Esto evita que queden nudges programados para un assignment que nunca recibió Nudge 0
        $nudge_0_success = false;
        
        if (class_exists('EIPSI_Nudge_Job_Queue')) {
            error_log(sprintf('[EIPSI EventScheduler] STEP 1: Executing Nudge 0 SYNCHRONOUSLY for assignment %d', $assignment_id));
            $nudge_0_result = EIPSI_Nudge_Job_Queue::execute_nudge_0(array(
                'assignment_id' => $assignment_id,
                'participant_id' => $assignment->participant_id,
                'wave_id' => $assignment->wave_id,
                'study_id' => $assignment->study_id
            ));
            
            if ($nudge_0_result['success']) {
                error_log(sprintf('[EIPSI EventScheduler] Nudge 0 executed SUCCESSFULLY for assignment %d', $assignment_id));
                $scheduled_count++;
                $nudge_0_success = true;
            } else {
                $error_msg = $nudge_0_result['error'] ?? 'unknown';
                error_log(sprintf('[EIPSI EventScheduler] Nudge 0 execution FAILED for assignment %d: %s', $assignment_id, $error_msg));
                
                // v2.1.7 - Si falló porque la wave no está disponible, programar reintento exacto
                if (strpos($error_msg, 'Wave not yet available') !== false || strpos($error_msg, 'not yet available') !== false) {
                    $retry_time = !empty($assignment->available_at) ? strtotime($assignment->available_at) : time() + 60;
                    error_log(sprintf(
                        '[EIPSI EventScheduler] RESCHEDULING: Nudge 0 for assignment %d will retry at %s (when wave becomes available)',
                        $assignment_id,
                        date('Y-m-d H:i:s', $retry_time)
                    ));
                    
                    // Programar reintento exacto en available_at
                    wp_schedule_single_event($retry_time, 'eipsi_wave_available_retry', array($assignment_id));
                    
                    // También encolar en Job Queue como backup
                    EIPSI_Nudge_Job_Queue::enqueue('send_nudge_0', array(
                        'assignment_id' => $assignment_id,
                        'participant_id' => $assignment->participant_id,
                        'wave_id' => $assignment->wave_id,
                        'study_id' => $assignment->study_id
                    ), 5, date('Y-m-d H:i:s', $retry_time));
                } else {
                    // Fallback genérico: retry en 5 minutos
                    EIPSI_Nudge_Job_Queue::enqueue('send_nudge_0', array(
                        'assignment_id' => $assignment_id,
                        'participant_id' => $assignment->participant_id,
                        'wave_id' => $assignment->wave_id,
                        'study_id' => $assignment->study_id
                    ), 5);
                }
                
                // Si Nudge 0 falló, NO programar los nudges de seguimiento
                error_log(sprintf('[EIPSI EventScheduler] ABORTING: Nudge 0 failed, not scheduling follow-up nudges for assignment %d', $assignment_id));
                error_log(sprintf('[EIPSI EventScheduler] COMPLETED: Scheduled %d nudges for assignment %d', $scheduled_count, $assignment_id));
                error_log(sprintf('[EIPSI EventScheduler] ========================================'));
                return $scheduled_count;
            }
        } else {
            error_log(sprintf('[EIPSI EventScheduler] EIPSI_Nudge_JobQueue class NOT FOUND - cannot execute Nudge 0'));
            return 0;
        }
        
        // v2.1.7 - STEP 2: Solo si Nudge 0 tuvo éxito, programar nudges 1-4
        if ($nudge_0_success && !empty($assignment->follow_up_reminders_enabled)) {
            error_log(sprintf('[EIPSI EventScheduler] STEP 2: Scheduling follow-up nudges for assignment %d', $assignment_id));
            
            // Phase 5 T1-Anchor: Get due_at deadline to prevent nudges after expiration
            $due_at_timestamp = null;
            if (!empty($assignment->due_at)) {
                $due_at_timestamp = strtotime($assignment->due_at);
                error_log(sprintf('[EIPSI EventScheduler] Assignment has due_at: %s (timestamp: %d)', 
                    $assignment->due_at, $due_at_timestamp));
            }
            
            // v1.4.2 - Absolute offsets: calculate directly from available_at
            for ($stage = 1; $stage <= 4; $stage++) {
                $nudge_key = "nudge_{$stage}";
                
                // Verificar si está habilitado
                if (!isset($nudge_config[$nudge_key]) || empty($nudge_config[$nudge_key]['enabled'])) {
                    continue;
                }
                
                $config = $nudge_config[$nudge_key];
                $value = isset($config['value']) ? floatval($config['value']) : ($stage * 24);
                $unit = isset($config['unit']) ? $config['unit'] : 'hours';
                
                // v1.4.2 - Scheduled time is absolute from available_at
                $delay_seconds = self::convert_to_seconds($value, $unit);
                $scheduled_time = (int) round($available_at + $delay_seconds);
                
                error_log(sprintf('[EIPSI EventScheduler] Nudge %d: %d seconds from available', 
                    $stage, $delay_seconds));
                
                // No programar en el pasado
                if ($scheduled_time <= current_time('timestamp')) {
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log(sprintf(
                            '[EIPSI EventScheduler] Skipping nudge %d for assignment %d - time already passed',
                            $stage,
                            $assignment_id
                        ));
                    }
                    continue;
                }
                
                // Phase 5 T1-Anchor: CRITICAL - No programar nudges después del deadline
                if ($due_at_timestamp !== null && $scheduled_time >= $due_at_timestamp) {
                    error_log(sprintf(
                        '[EIPSI EventScheduler] BLOCKED nudge %d for assignment %d - would occur AFTER due_at (%s >= %s)',
                        $stage,
                        $assignment_id,
                        date('Y-m-d H:i:s', $scheduled_time),
                        date('Y-m-d H:i:s', $due_at_timestamp)
                    ));
                    continue;
                }
                
                // Programar el evento exacto
                $event_args = array(
                    'assignment_id' => $assignment_id,
                    'stage' => $stage
                );
                
                // Limpiar evento previo si existe (evita duplicados)
                wp_clear_scheduled_hook(self::NUDGE_EVENT_HOOK, array($event_args));
                
                // Programar nuevo evento
                error_log(sprintf('[EIPSI EventScheduler] Scheduling nudge %d at %s (delay: %d seconds)', 
                    $stage, date('Y-m-d H:i:s', $scheduled_time), $delay_seconds));
                
                $scheduled = wp_schedule_single_event($scheduled_time, self::NUDGE_EVENT_HOOK, array($event_args));
                
                if ($scheduled === false) {
                    error_log(sprintf(
                        '[EIPSI EventScheduler] FAILED to schedule nudge %d for assignment %d at %s',
                        $stage,
                        $assignment_id,
                        date('Y-m-d H:i:s', $scheduled_time)
                    ));
                } else {
                    $scheduled_count++;
                    
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log(sprintf(
                            '[EIPSI EventScheduler] Scheduled nudge %d for assignment %d at %s (delay: %d %s)',
                            $stage,
                            $assignment_id,
                            date('Y-m-d H:i:s', $scheduled_time),
                            $value,
                            $unit
                        ));
                    }
                }
            }
        } else if (!$nudge_0_success) {
            error_log(sprintf('[EIPSI EventScheduler] SKIPPING follow-up nudges: Nudge 0 failed for assignment %d', $assignment_id));
        } else {
            error_log(sprintf('[EIPSI EventScheduler] SKIPPING follow-up nudges: disabled for assignment %d', $assignment_id));
        }
        
        error_log(sprintf('[EIPSI EventScheduler] COMPLETED: Scheduled %d nudges for assignment %d', $scheduled_count, $assignment_id));
        error_log(sprintf('[EIPSI EventScheduler] ========================================'));
        
        return $scheduled_count;
    }
    
    /**
     * Ejecutar un nudge programado
     * 
     * @param array $args Argumentos del evento: assignment_id, stage, scheduled_at
     */
    public static function execute_scheduled_nudge($args) {
        if (!is_array($args) || !isset($args['assignment_id']) || !isset($args['stage'])) {
            error_log('[EIPSI EventScheduler] Invalid event arguments');
            return;
        }
        
        $assignment_id = intval($args['assignment_id']);
        $stage = intval($args['stage']);
        $now = current_time('timestamp');
        
        // Verificar que sigue siendo válido enviar este nudge
        global $wpdb;
        $assignment = $wpdb->get_row($wpdb->prepare(
            "SELECT a.*, w.nudge_config, w.follow_up_reminders_enabled 
             FROM {$wpdb->prefix}survey_assignments a
             JOIN {$wpdb->prefix}survey_waves w ON a.wave_id = w.id
             WHERE a.id = %d",
            $assignment_id
        ));
        
        if (!$assignment) {
            error_log(sprintf('[EIPSI EventScheduler] Assignment %d no longer exists', $assignment_id));
            return;
        }

        // Recalcular si es catch-up basado en available_at y la configuración del nudge
        $is_catch_up = false;
        if (!empty($assignment->available_at)) {
            $available_at = strtotime($assignment->available_at);
            $nudge_config = !empty($assignment->nudge_config) ? json_decode($assignment->nudge_config, true) : array();
            $nudge_key = "nudge_{$stage}";
            
            if (isset($nudge_config[$nudge_key])) {
                $config = $nudge_config[$nudge_key];
                $value = isset($config['value']) ? floatval($config['value']) : ($stage * 24);
                $unit = isset($config['unit']) ? $config['unit'] : 'hours';
                $delay_seconds = self::convert_to_seconds($value, $unit);
                $intended_time = $available_at + $delay_seconds;
                $is_catch_up = ($now > $intended_time + 600); // 10 min grace period
            }
        }
        
        if (defined('WP_DEBUG') || $is_catch_up) {
            error_log(sprintf(
                '[EIPSI EventScheduler] Executing scheduled nudge %d for assignment %d (now: %s, catch-up: %s)',
                $stage,
                $assignment_id,
                date('Y-m-d H:i:s', $now),
                $is_catch_up ? 'YES' : 'NO'
            ));
        }
        
        // Log estado actual del assignment
        error_log(sprintf(
            '[EIPSI EventScheduler] Assignment %d state: status=%s, reminder_count=%d, expected_count=%d',
            $assignment_id,
            $assignment->status,
            $assignment->reminder_count,
            $stage
        ));
        
        // Si ya completó la toma, no enviar
        if ($assignment->status !== 'pending') {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf(
                    '[EIPSI EventScheduler] Assignment %d is %s, skipping nudge %d',
                    $assignment_id,
                    $assignment->status,
                    $stage
                ));
            }
            return;
        }
        
        // Verificar que reminders estén habilitados para follow-ups
        if ($stage > 0 && empty($assignment->follow_up_reminders_enabled)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf(
                    '[EIPSI EventScheduler] Follow-up reminders disabled for assignment %d',
                    $assignment_id
                ));
            }
            return;
        }
        
        // Verificar stage correcto (no enviar nudge 2 si nunca se envió el 1)
        $expected_reminder_count = $stage; // Nudge 1 espera reminder_count = 1
        if (intval($assignment->reminder_count) != $expected_reminder_count) {
            error_log(sprintf(
                '[EIPSI EventScheduler] Invalid stage for assignment %d: expected reminder_count=%d, got %d',
                $assignment_id,
                $expected_reminder_count,
                $assignment->reminder_count
            ));
            return;
        }
        
        // v2.1.6 - Lock Transacción con SELECT FOR UPDATE
        // Garantiza que solo un nudge por assignment se ejecute a la vez,
        // manteniendo el timing exacto incluso con intervalos cortos (72 segundos)
        
        $transaction_started = false;
        $result = null;
        
        try {
            // Iniciar transacción
            $wpdb->query('START TRANSACTION');
            $transaction_started = true;
            
            // Bloquear la fila del assignment - ningún otro proceso puede leer/escribir hasta COMMIT/ROLLBACK
            $locked_assignment = $wpdb->get_row($wpdb->prepare(
                "SELECT reminder_count, status, participant_id, wave_id, study_id 
                 FROM {$wpdb->prefix}survey_assignments 
                 WHERE id = %d 
                 FOR UPDATE",
                $assignment_id
            ));
            
            if (!$locked_assignment) {
                $wpdb->query('ROLLBACK');
                error_log(sprintf('[EIPSI EventScheduler] Assignment %d no longer exists (locked check)', $assignment_id));
                return;
            }
            
            // Verificar que sigue pendiente
            if ($locked_assignment->status !== 'pending') {
                $wpdb->query('ROLLBACK');
                error_log(sprintf(
                    '[EIPSI EventScheduler] Assignment %d is %s (locked check), skipping nudge %d',
                    $assignment_id,
                    $locked_assignment->status,
                    $stage
                ));
                return;
            }
            
            // Verificar stage correcto con el count bloqueado
            if (intval($locked_assignment->reminder_count) != $expected_reminder_count) {
                $actual_count = intval($locked_assignment->reminder_count);
                
                // Determinar si es duplicado o el anterior aún no terminó
                if ($actual_count < $expected_reminder_count) {
                    // Nudge anterior aún no completó, re-encolar para 1 minuto (timing más cercano que 5 min)
                    error_log(sprintf(
                        '[EIPSI EventScheduler] LOCK: Nudge %d for assignment %d waiting - count is %d, expected %d. Re-enqueuing for 1 min',
                        $stage,
                        $assignment_id,
                        $actual_count,
                        $expected_reminder_count
                    ));
                    
                    EIPSI_Nudge_Job_Queue::enqueue(
                        "send_nudge_{$stage}",
                        array(
                            'assignment_id' => $assignment_id,
                            'participant_id' => $locked_assignment->participant_id,
                            'wave_id' => $locked_assignment->wave_id,
                            'study_id' => $locked_assignment->study_id,
                            'stage' => $stage
                        ),
                        10,
                        date('Y-m-d H:i:s', strtotime('+1 minute'))
                    );
                } else {
                    // Ya se envió este nudge (count > expected)
                    error_log(sprintf(
                        '[EIPSI EventScheduler] LOCK: Nudge %d for assignment %d already sent (count=%d > expected=%d)',
                        $stage,
                        $assignment_id,
                        $actual_count,
                        $expected_reminder_count
                    ));
                }
                
                $wpdb->query('ROLLBACK');
                return;
            }
            
            // TODAS LAS VERIFICACIONES PASARON - ejecutar nudge dentro de la transacción
            if (class_exists('EIPSI_Nudge_Job_Queue')) {
                $payload = array(
                    'assignment_id' => $assignment_id,
                    'participant_id' => $locked_assignment->participant_id,
                    'wave_id' => $locked_assignment->wave_id,
                    'study_id' => $locked_assignment->study_id,
                    'stage' => $stage
                );
                
                // Ejecutar - esto enviará el email y actualizará reminder_count DENTRO de la transacción
                $result = EIPSI_Nudge_Job_Queue::execute_nudge_followup($payload, $stage);
                
                if ($result['success']) {
                    $wpdb->query('COMMIT');
                    error_log(sprintf(
                        '[EIPSI EventScheduler] LOCK: Nudge %d executed and COMMITTED for assignment %d',
                        $stage,
                        $assignment_id
                    ));
                    
                    // v2.5.4 - CATCH-UP: If this nudge was delayed, reschedule next nudge with proper interval from 'now'
                    if ($is_catch_up && $stage < 4) {
                        $next_stage = $stage + 1;
                        
                        // Get nudge config for next stage
                        $wave = $wpdb->get_row($wpdb->prepare(
                            "SELECT w.nudge_config, w.follow_up_reminders_enabled
                             FROM {$wpdb->prefix}survey_assignments a
                             JOIN {$wpdb->prefix}survey_waves w ON a.wave_id = w.id
                             WHERE a.id = %d",
                            $assignment_id
                        ));
                        
                        if ($wave && !empty($wave->follow_up_reminders_enabled)) {
                            $nudge_config = !empty($wave->nudge_config) ? json_decode($wave->nudge_config, true) : array();
                            $next_key = "nudge_{$next_stage}";
                            
                            if (isset($nudge_config[$next_key]) && !empty($nudge_config[$next_key]['enabled'])) {
                                $config = $nudge_config[$next_key];
                                $value = isset($config['value']) ? floatval($config['value']) : ($next_stage * 24);
                                $unit = isset($config['unit']) ? $config['unit'] : 'hours';
                                $delay_seconds = self::convert_to_seconds($value, $unit);
                                
                                // v1.4.2 - Calculate proportional shift based on absolute offsets
                                // We want to maintain the gap between stages if one was delayed
                                $current_key = "nudge_{$stage}";
                                $current_delay = isset($nudge_config[$current_key]) 
                                    ? self::convert_to_seconds(floatval($nudge_config[$current_key]['value']), $nudge_config[$current_key]['unit'])
                                    : 0;
                                
                                $interval = max(0, $delay_seconds - $current_delay);
                                $new_scheduled_time = $now + $interval;
                                
                                // Clear old event and schedule new one
                                $event_args = array(
                                    'assignment_id' => $assignment_id,
                                    'stage' => $next_stage
                                );
                                wp_clear_scheduled_hook(self::NUDGE_EVENT_HOOK, array($event_args));
                                
                                wp_schedule_single_event($new_scheduled_time, self::NUDGE_EVENT_HOOK, array($event_args));
                                
                                error_log(sprintf(
                                    '[EIPSI EventScheduler] CATCH-UP: Rescheduled nudge %d for assignment %d at %s (+ %d seconds interval from now)',
                                    $next_stage,
                                    $assignment_id,
                                    date('Y-m-d H:i:s', $new_scheduled_time),
                                    $interval
                                ));
                            }
                        }
                    }
                } else {
                    // Falló el envío - rollback para que se reintente
                    $wpdb->query('ROLLBACK');
                    error_log(sprintf(
                        '[EIPSI EventScheduler] LOCK: Nudge %d FAILED for assignment %d: %s - ROLLBACK',
                        $stage,
                        $assignment_id,
                        $result['error'] ?? 'unknown'
                    ));
                    
                    // Re-encolar para reintento en 1 minuto
                    EIPSI_Nudge_Job_Queue::enqueue("send_nudge_{$stage}", $payload, 10);
                }
            } else {
                // Fallback sin Job Queue
                $wpdb->query('ROLLBACK');
                self::send_nudge_direct((object)$locked_assignment, $stage);
            }
            
        } catch (Exception $e) {
            if ($transaction_started) {
                $wpdb->query('ROLLBACK');
            }
            error_log(sprintf(
                '[EIPSI EventScheduler] LOCK: EXCEPTION for assignment %d nudge %d: %s',
                $assignment_id,
                $stage,
                $e->getMessage()
            ));
        }
    }
    
    /**
     * Enviar nudge directamente (fallback sin Job Queue)
     */
    private static function send_nudge_direct($assignment, $stage) {
        // Esta función solo se usa si Job Queue no está disponible
        // Implementación básica para mantener compatibilidad
        
        if (!class_exists('EIPSI_Email_Service')) {
            require_once plugin_dir_path(dirname(__FILE__)) . 'admin/services/class-email-service.php';
        }
        
        $result = EIPSI_Email_Service::send_wave_reminder_email(
            $assignment->study_id,
            $assignment->participant_id,
            (object) array(
                'id' => $assignment->wave_id,
                'name' => 'Wave',
                'wave_index' => 1
            ),
            $stage
        );
        
        if ($result) {
            global $wpdb;
            $wpdb->update(
                $wpdb->prefix . 'survey_assignments',
                array('reminder_count' => $stage + 1),
                array('id' => $assignment->id),
                array('%d'),
                array('%d')
            );
        }
        
        return $result;
    }
    
    /**
     * Cancelar todos los eventos programados para una asignación
     * 
     * @param int $assignment_id ID de la asignación
     */
    public static function cancel_scheduled_nudges($assignment_id) {
        for ($stage = 0; $stage <= 4; $stage++) {
            $event_args = array(
                'assignment_id' => $assignment_id,
                'stage' => $stage
            );
            
            wp_clear_scheduled_hook(self::NUDGE_EVENT_HOOK, array($event_args));
        }
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[EIPSI EventScheduler] Cancelled all scheduled nudges for assignment %d',
                $assignment_id
            ));
        }
    }
    
    /**
     * Phase 5 T1-Anchor: Recalcular nudges cuando cambia el deadline de un assignment
     * 
     * @param int $assignment_id Assignment ID
     * @since 2.6.0
     */
    public static function reschedule_nudges_for_deadline($assignment_id) {
        global $wpdb;
        
        $assignment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}survey_assignments WHERE id = %d",
            $assignment_id
        ));
        
        if (!$assignment || $assignment->status === 'submitted' || $assignment->status === 'expired') {
            return; // No recalcular si ya está completado o expirado
        }
        
        error_log(sprintf('[EIPSI EventScheduler] Rescheduling nudges for assignment %d due to deadline change', $assignment_id));
        
        if ((int) $assignment->reminder_count >= 1) {
            return self::refresh_wave_follow_ups($assignment->wave_id, $assignment_id);
        }
        // Cancelar nudges programados existentes
        self::cancel_scheduled_nudges($assignment_id);
        
        // Reprogramar con el nuevo deadline
        self::schedule_nudge_sequence($assignment_id);
    }
    
    /**
     * Phase 5 T1-Anchor: Recalcular todos los nudges de un participante cuando se ancla T1
     * 
     * @param int $study_id Study ID
     * @param int $participant_id Participant ID
     * @since 2.6.0
     */
    public static function reschedule_all_nudges_for_participant($study_id, $participant_id) {
        global $wpdb;
        
        error_log(sprintf('[EIPSI EventScheduler] Rescheduling all nudges for participant %d in study %d (T1 anchored)', $participant_id, $study_id));
        
        // Obtener todos los assignments del participante (excepto T1 y los ya completados)
        $assignments = $wpdb->get_results($wpdb->prepare(
            "SELECT a.id, a.status, w.wave_index
             FROM {$wpdb->prefix}survey_assignments a
             JOIN {$wpdb->prefix}survey_waves w ON a.wave_id = w.id
             WHERE a.participant_id = %d
             AND w.study_id = %d
             AND w.wave_index > 1
             AND a.status NOT IN ('submitted', 'expired')
             ORDER BY w.wave_index ASC",
            $participant_id,
            $study_id
        ));
        
        $rescheduled = 0;
        foreach ($assignments as $assignment) {
            // Cancelar nudges existentes
            self::cancel_scheduled_nudges($assignment->id);
            
            // Reprogramar con los nuevos deadlines calculados por T1-Anchor
            if ($assignment->status === 'available') {
                self::schedule_nudge_sequence($assignment->id);
                $rescheduled++;
            }
        }
        
        error_log(sprintf('[EIPSI EventScheduler] Rescheduled nudges for %d assignments', $rescheduled));
    }
    
    /**
     * Obtener lista de eventos programados para debugging
     */
    public static function get_scheduled_events() {
        $crons = _get_cron_array();
        $events = array();
        
        if (empty($crons)) {
            return $events;
        }
        
        foreach ($crons as $timestamp => $cron) {
            if (isset($cron[self::NUDGE_EVENT_HOOK])) {
                foreach ($cron[self::NUDGE_EVENT_HOOK] as $key => $event) {
                    $args = isset($event['args'][0]) ? $event['args'][0] : array();
                    $events[] = array(
                        'timestamp' => $timestamp,
                        'date' => date('Y-m-d H:i:s', $timestamp),
                        'assignment_id' => isset($args['assignment_id']) ? $args['assignment_id'] : null,
                        'stage' => isset($args['stage']) ? $args['stage'] : null
                    );
                }
            }
        }
        
        return $events;
    }
    
    /**
     * Generar clave única para evento
     */
    private static function get_event_key($assignment_id, $stage) {
        return "eipsi_nudge_{$assignment_id}_{$stage}";
    }
    
    /**
     * Convertir valor+unidad a segundos
     */
    private static function convert_to_seconds($value, $unit) {
        switch ($unit) {
            case 'minutes':
            case 'minutos':
                return $value * 60;
            case 'hours':
            case 'horas':
                return $value * 3600;
            case 'days':
            case 'días':
            case 'dias':
                return $value * 86400;
            default:
                return $value * 3600; // Default a horas
        }
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
        global $wpdb;
        
        error_log("[EIPSI EventScheduler] Cancelling all pending nudges for assignment {$assignment_id}");
        
        $cancelled = $wpdb->update(
            $wpdb->prefix . 'survey_job_queue',
            array('status' => 'cancelled'),
            array(
                'assignment_id' => $assignment_id,
                'status' => 'pending'
            ),
            array('%s'),
            array('%d', '%s')
        );
        
        if ($cancelled === false) {
            error_log("[EIPSI EventScheduler] Error cancelling nudges: " . $wpdb->last_error);
            return 0;
        }
        
        error_log("[EIPSI EventScheduler] Cancelled {$cancelled} pending nudges for assignment {$assignment_id}");
        
        return $cancelled;
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
        global $wpdb;
        $assignment = $wpdb->get_row($wpdb->prepare("SELECT wave_id, reminder_count FROM {$wpdb->prefix}survey_assignments WHERE id = %d", $assignment_id));
        if ($assignment && (int) $assignment->reminder_count >= 1) {
            $result = self::refresh_wave_follow_ups($assignment->wave_id, $assignment_id);
            return !is_wp_error($result);
        }
        error_log("[EIPSI EventScheduler] Rescheduling nudges for assignment {$assignment_id}");
        
        // 1. Cancelar nudges pendientes
        $cancelled = self::cancel_nudges_for_assignment($assignment_id);
        error_log("[EIPSI EventScheduler] Cancelled {$cancelled} pending nudges before rescheduling");
        
        // 2. Re-programar desde cero (lee available_at actualizado de la DB)
        $result = self::schedule_nudge_sequence($assignment_id);
        
        if ($result) {
            error_log("[EIPSI EventScheduler] Successfully rescheduled nudges for assignment {$assignment_id}");
        } else {
            error_log("[EIPSI EventScheduler] Failed to reschedule nudges for assignment {$assignment_id}");
        }
        
        return $result;
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
        global $wpdb;
        
        error_log('[EIPSI EventScheduler] ========================================');
        error_log('[EIPSI EventScheduler] schedule_follow_up_nudges_only() CALLED');
        error_log('[EIPSI EventScheduler] ========================================');
        
        if (empty($assignment->id)) {
            error_log('[EIPSI EventScheduler] ❌ ERROR: Missing assignment ID');
            return 0;
        }
        
        $assignment_id = (int) $assignment->id;
        
        error_log(sprintf(
            '[EIPSI EventScheduler] Assignment %d: reminder_count=%d, status=%s, wave_id=%d, available_at=%s',
            $assignment_id,
            $assignment->reminder_count,
            $assignment->status,
            $assignment->wave_id,
            $assignment->available_at
        ));
        
        // Verify reminder_count is 1 (Nudge 0 was sent)
        if ((int) $assignment->reminder_count < 1) {
            error_log(sprintf(
                '[EIPSI EventScheduler] ❌ ABORT: Assignment %d has reminder_count=%d (expected 1)',
                $assignment_id,
                $assignment->reminder_count
            ));
            return 0;
        }
        
        error_log(sprintf(
            '[EIPSI EventScheduler] ✓ Reminder count validation passed (reminder_count=1)'
        ));
        
        // Check if follow-up reminders are enabled
        if (empty($assignment->follow_up_reminders_enabled)) {
            error_log(sprintf('[EIPSI EventScheduler] Follow-up reminders disabled for assignment %d', $assignment_id));
            return 0;
        }
        
        // Get wave to check nudge config
        $wave = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}survey_waves WHERE id = %d",
            $assignment->wave_id
        ));
        
        if (!$wave) {
            error_log(sprintf('[EIPSI EventScheduler] Wave %d not found for assignment %d', $assignment->wave_id, $assignment_id));
            return 0;
        }
        
        // Parse nudge config
        $nudge_config = !empty($wave->nudge_config) ? json_decode($wave->nudge_config, true) : array();
        if (empty($nudge_config)) {
            error_log(sprintf('[EIPSI EventScheduler] No nudge config for wave %d', $wave->id));
            return 0;
        }
        
        // Get available_at timestamp
        $available_at = !empty($assignment->available_at) ? strtotime($assignment->available_at) : null;
        if (!$available_at) {
            error_log(sprintf('[EIPSI EventScheduler] No available_at for assignment %d', $assignment_id));
            return 0;
        }
        
        // Get due_at deadline to prevent nudges after expiration
        $due_at_timestamp = null;
        if (!empty($assignment->due_at)) {
            $due_at_timestamp = strtotime($assignment->due_at);
        }
        
        error_log(sprintf(
            '[EIPSI EventScheduler] Scheduling follow-up nudges 1-4 for assignment %d (available_at=%s)',
            $assignment_id,
            date('Y-m-d H:i:s', $available_at)
        ));
        
        $scheduled_count = 0;
        
        for ($stage = 1; $stage <= 4; $stage++) {
            if ($stage < (int) $assignment->reminder_count) { continue; }
            $nudge_key = "nudge_{$stage}";
            
            // Check if enabled
            if (!isset($nudge_config[$nudge_key]) || empty($nudge_config[$nudge_key]['enabled'])) {
                continue;
            }
            
            $config = $nudge_config[$nudge_key];
            $value = isset($config['value']) ? floatval($config['value']) : ($stage * 24);
            $unit = isset($config['unit']) ? $config['unit'] : 'hours';
            
            // v1.4.2 - Absolute offset from available_at
            $delay_seconds = self::convert_to_seconds($value, $unit);
            $scheduled_time = (int) round($available_at + $delay_seconds);
            
            // Don't schedule in the past
            if ($scheduled_time <= current_time('timestamp')) {
                error_log(sprintf(
                    '[EIPSI EventScheduler] Skipping nudge %d for assignment %d - time already passed',
                    $stage,
                    $assignment_id
                ));
                continue;
            }
            
            // Don't schedule after deadline
            if ($due_at_timestamp !== null && $scheduled_time >= $due_at_timestamp) {
                error_log(sprintf(
                    '[EIPSI EventScheduler] BLOCKED nudge %d for assignment %d - would occur AFTER due_at',
                    $stage,
                    $assignment_id
                ));
                continue;
            }
            
            // Schedule the event
            $event_args = array(
                'assignment_id' => $assignment_id,
                'stage' => $stage
            );
            
            // Clear previous event if exists
            wp_clear_scheduled_hook(self::NUDGE_EVENT_HOOK, array($event_args));
            
            // Schedule new event
            $result = wp_schedule_single_event($scheduled_time, self::NUDGE_EVENT_HOOK, array($event_args));
            
            if ($result === false || is_wp_error($result)) { return false; }
            if ($result !== false) {
                $scheduled_count++;
                error_log(sprintf(
                    '[EIPSI EventScheduler] Scheduled nudge %d for assignment %d at %s (delay: %s)',
                    $stage,
                    $assignment_id,
                    date('Y-m-d H:i:s', $scheduled_time),
                    self::format_delay($value, $unit)
                ));
            } else {
                error_log(sprintf(
                    '[EIPSI EventScheduler] FAILED to schedule nudge %d for assignment %d',
                    $stage,
                    $assignment_id
                ));
            }
        }
        
        error_log(sprintf(
            '[EIPSI EventScheduler] Scheduled %d follow-up nudges for assignment %d',
            $scheduled_count,
            $assignment_id
        ));
        
        return $scheduled_count;
    }

    /**
     * Formatear delay para logs
     */
    private static function format_delay($value, $unit) {
        $units = array(
            'minutes' => 'minutos',
            'minutos' => 'minutos',
            'hours' => 'horas',
            'horas' => 'horas',
            'days' => 'días',
            'días' => 'días',
            'dias' => 'días'
        );
        $unit_name = isset($units[$unit]) ? $units[$unit] : $unit;
        return sprintf('%s %s', $value, $unit_name);
    }
}

// Inicializar al cargar
add_action('init', array('EIPSI_Nudge_Event_Scheduler', 'init'));
