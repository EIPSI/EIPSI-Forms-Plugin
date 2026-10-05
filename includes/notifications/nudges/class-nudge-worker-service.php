<?php
/** Notifications owner; public WordPress adapters retain existing contracts. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Notification_Nudge_Worker_Service {

public static function process_batch($limit = 10) {
        $stats = array(
            'processed' => 0,
            'completed' => 0,
            'failed' => 0,
            'retried' => 0
        );

        $jobs = EIPSI_Notification_Nudge_Queue_Service::get_pending_jobs($limit);

        if (empty($jobs)) {
            return $stats;
        }

        foreach ($jobs as $job) {
            $result=self::process_job($job);
            foreach($stats as $key=>$value){$stats[$key]+=$result[$key];}
        }

        return $stats;
    }

private static function execute_job($job) {
        $payload = json_decode($job->payload, true);

        if (!$payload) {
            return array(
                'success' => false,
                'error' => 'Invalid payload JSON'
            );
        }

        $job_type = $job->job_type;

        switch ($job_type) {
            case 'send_nudge_0':
                return self::execute_nudge_0($payload);

            case 'send_nudge_1':
            case 'send_nudge_2':
            case 'send_nudge_3':
            case 'send_nudge_4':
                $stage = intval(str_replace('send_nudge_', '', $job_type));
                return self::execute_nudge_followup($payload, $stage);

            default:
                return array(
                    'success' => false,
                    'error' => 'Unknown job type: ' . $job_type
                );
        }
    }

public static function execute_nudge_0($payload) {
        $assignment_id = isset($payload['assignment_id']) ? intval($payload['assignment_id']) : 0;

        if (!$assignment_id) {
            return array('success' => false, 'error' => 'Missing assignment_id');
        }

        // Cargar datos necesarios
        global $wpdb;
        $assignment = $wpdb->get_row($wpdb->prepare(
            "SELECT a.*, w.name as wave_name, w.wave_index, w.study_id
             FROM {$wpdb->prefix}survey_assignments a
             JOIN {$wpdb->prefix}survey_waves w ON a.wave_id = w.id
             WHERE a.id = %d",
            $assignment_id
        ));

        if (!$assignment) {
            return array('success' => false, 'error' => 'Assignment not found');
        }

        // Verificar que aún no se haya enviado (idempotencia)
        if ($assignment->reminder_count > 0) {
            return array('success' => true, 'message' => 'Nudge 0 already sent');
        }

        // Verificar que la wave está disponible
        if (!empty($assignment->available_at) && strtotime($assignment->available_at) > current_time('timestamp')) {
            return array('success' => false, 'error' => 'Wave not yet available');
        }

        // v2.5.0 - Enviar email real usando Wave Availability Email Service
        if (!class_exists('EIPSI_Wave_Availability_Email_Service')) {
            require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-wave-availability-email-service.php';
        }

        // Cargar datos del participant para obtener email, first_name, last_name
        $participant_data = $wpdb->get_row($wpdb->prepare(
            "SELECT email, first_name, last_name FROM {$wpdb->prefix}survey_participants WHERE id = %d",
            $assignment->participant_id
        ));

        // Construir objetos necesarios para el servicio
        $wave = (object) array(
            'id' => $assignment->wave_id,
            'name' => $assignment->wave_name,
            'wave_index' => $assignment->wave_index,
            'study_id' => $assignment->study_id
        );

        $participant = (object) array(
            'id' => $assignment->participant_id,
            'email' => $participant_data ? $participant_data->email : '',
            'first_name' => $participant_data ? $participant_data->first_name : '',
            'last_name' => $participant_data ? $participant_data->last_name : ''
        );

        $result = EIPSI_Wave_Availability_Email_Service::ensure_wave_availability_email_sent(
            $assignment,
            $wave,
            $participant,
            $assignment->study_id
        );

        // v2.1.3 - Actualizar reminder_count después de enviar Nudge 0
        // v2.5.1 - También actualizar last_nudge_sent_at como punto de referencia para nudge 1
        if ($result['success'] && $result['sent']) {
            global $wpdb;

            // Log ANTES del update
            error_log(sprintf(
                '[EIPSI STATE TRANSITION] BEFORE UPDATE: assignment=%d, reminder_count=%d, status=%s',
                $assignment_id,
                $assignment->reminder_count,
                $assignment->status
            ));

            $rows_affected = $wpdb->update(
                $wpdb->prefix . 'survey_assignments',
                array(
                    'reminder_count' => 1,
                    'last_nudge_sent_at' => current_time('mysql')
                ),
                array('id' => $assignment_id),
                array('%d', '%s'),
                array('%d')
            );

            // Log DESPUÉS del update
            error_log(sprintf(
                '[EIPSI STATE TRANSITION] AFTER UPDATE: assignment=%d, reminder_count=1, rows_affected=%d, last_nudge_sent_at=%s',
                $assignment_id,
                $rows_affected,
                current_time('mysql')
            ));

            // Verificar que el update funcionó
            $updated_assignment = $wpdb->get_row($wpdb->prepare(
                "SELECT reminder_count, status FROM {$wpdb->prefix}survey_assignments WHERE id = %d",
                $assignment_id
            ));

            error_log(sprintf(
                '[EIPSI STATE VERIFICATION] assignment=%d, reminder_count=%d, status=%s',
                $assignment_id,
                $updated_assignment ? $updated_assignment->reminder_count : 'NULL',
                $updated_assignment ? $updated_assignment->status : 'NULL'
            ));

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf(
                    '[EIPSI JobQueue] Nudge 0 email ENVIADO: assignment=%d, participant=%d, reminder_count=1 a las %s',
                    $assignment_id,
                    $assignment->participant_id,
                    current_time('mysql')
                ));
            }
            return array('success' => true, 'message' => 'Nudge 0 email sent successfully');
        } else {
            $reason = isset($result['reason']) ? $result['reason'] : 'unknown';
            error_log(sprintf(
                '[EIPSI JobQueue] Nudge 0 email FALLÓ: assignment=%d, reason=%s',
                $assignment_id,
                $reason
            ));
            return array('success' => false, 'error' => $reason);
        }
    }

public static function execute_nudge_followup($payload, $stage) {
        $assignment_id = isset($payload['assignment_id']) ? intval($payload['assignment_id']) : 0;

        if (!$assignment_id) {
            return array('success' => false, 'error' => 'Missing assignment_id');
        }

        global $wpdb;

        // Verificar estado actual
        $assignment = $wpdb->get_row($wpdb->prepare(
            "SELECT reminder_count, status FROM {$wpdb->prefix}survey_assignments WHERE id = %d",
            $assignment_id
        ));

        if (!$assignment) {
            return array('success' => false, 'error' => 'Assignment not found');
        }

        // Verificar que estamos en el stage correcto
        $expected_count = $stage; // Nudge 1 espera reminder_count=1 (después de Nudge 0)

        if ($assignment->reminder_count != $expected_count) {
            return array(
                'success' => false,
                'error' => "Invalid stage: expected reminder_count={$expected_count}, got {$assignment->reminder_count}"
            );
        }

        if ($assignment->status !== 'pending') {
            return array('success' => true, 'message' => 'Assignment already completed');
        }

        // v2.5.0 - Enviar email real usando EIPSI_Email_Service
        if (!class_exists('EIPSI_Email_Service')) {
            require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-email-service.php';
        }

        // Cargar datos completos del assignment
        $full_assignment = $wpdb->get_row($wpdb->prepare(
            "SELECT a.*, w.name as wave_name, w.wave_index, w.due_date, w.nudge_config
             FROM {$wpdb->prefix}survey_assignments a
             JOIN {$wpdb->prefix}survey_waves w ON a.wave_id = w.id
             WHERE a.id = %d",
            $assignment_id
        ));

        if (!$full_assignment) {
            return array('success' => false, 'error' => 'Could not load assignment data');
        }

        // Construir objeto wave
        $wave = (object) array(
            'id' => $full_assignment->wave_id,
            'name' => $full_assignment->wave_name,
            'wave_index' => $full_assignment->wave_index,
            'due_date' => $full_assignment->due_date
        );

        // Enviar email de recordatorio
        $email_sent = EIPSI_Email_Service::send_wave_reminder_email(
            $full_assignment->study_id,
            $full_assignment->participant_id,
            $wave,
            $stage
        );

        if ($email_sent) {
            // Log ANTES del update
            error_log(sprintf(
                '[EIPSI STATE TRANSITION] NUDGE %d BEFORE UPDATE: assignment=%d, reminder_count=%d, status=%s',
                $stage,
                $assignment_id,
                $assignment->reminder_count,
                $assignment->status
            ));

            // Actualizar contador solo si el email se envió realmente
            $rows_affected = $wpdb->update(
                $wpdb->prefix . 'survey_assignments',
                array('reminder_count' => $stage + 1),
                array('id' => $assignment_id),
                array('%d'),
                array('%d')
            );

            // v2.5.1 - Guardar timestamp real del envío para control de intervalos
            $wpdb->update(
                $wpdb->prefix . 'survey_assignments',
                array('last_nudge_sent_at' => current_time('mysql')),
                array('id' => $assignment_id),
                array('%s'),
                array('%d')
            );

            // Log DESPUÉS del update
            error_log(sprintf(
                '[EIPSI STATE TRANSITION] NUDGE %d AFTER UPDATE: assignment=%d, reminder_count=%d, rows_affected=%d, last_nudge_sent_at=%s',
                $stage,
                $assignment_id,
                $stage + 1,
                $rows_affected,
                current_time('mysql')
            ));

            // Verificar que el update funcionó
            $updated_assignment = $wpdb->get_row($wpdb->prepare(
                "SELECT reminder_count, status FROM {$wpdb->prefix}survey_assignments WHERE id = %d",
                $assignment_id
            ));

            error_log(sprintf(
                '[EIPSI STATE VERIFICATION] NUDGE %d: assignment=%d, reminder_count=%d, status=%s',
                $stage,
                $assignment_id,
                $updated_assignment ? $updated_assignment->reminder_count : 'NULL',
                $updated_assignment ? $updated_assignment->status : 'NULL'
            ));

            // Invalidar cache
            if (class_exists('EIPSI_Nudge_Cache')) {
                EIPSI_Nudge_Cache::mark_nudge_sent($assignment_id, $stage);
            }

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf(
                    '[EIPSI JobQueue] Nudge %d email ENVIADO: assignment=%d, participant=%d a las %s',
                    $stage,
                    $assignment_id,
                    $full_assignment->participant_id,
                    current_time('mysql')
                ));
            }

            return array('success' => true, 'message' => "Nudge {$stage} email sent successfully");
        } else {
            error_log(sprintf(
                '[EIPSI JobQueue] Nudge %d email FALLÓ: assignment=%d, participant=%d',
                $stage,
                $assignment_id,
                $full_assignment->participant_id
            ));
            return array('success' => false, 'error' => 'Email sending failed');
        }
    }
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
                $delay_seconds = EIPSI_Notification_Nudge_Policy_Service::schedule_seconds($value, $unit);
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
                        EIPSI_Notification_Nudge_Schedule_Service::reschedule_next_stage($assignment_id,$stage);
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
private static function send_nudge_direct($assignment, $stage) {
        // Esta función solo se usa si Job Queue no está disponible
        // Implementación básica para mantener compatibilidad

        if (!class_exists('EIPSI_Email_Service')) {
            require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/admin/services/class-email-service.php';
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
public static function process_job($job) {
    $stats=array('processed'=>0,'completed'=>0,'failed'=>0,'retried'=>0);

            $stats['processed']++;

            // Intentar bloquear el job
            if (!EIPSI_Notification_Nudge_Queue_Service::mark_processing($job->id)) {
                // Otro worker lo agarró
                return $stats;
            }

            $result = self::execute_job($job);

            if ($result['success']) {
                EIPSI_Notification_Nudge_Queue_Service::mark_completed($job->id, $result['message']);
                $stats['completed']++;
            } else {
                $retry_scheduled = EIPSI_Notification_Nudge_Queue_Service::mark_for_retry($job->id, $result['error']);
                if ($retry_scheduled) {
                    $stats['retried']++;
                } else {
                    $stats['failed']++;
                }
            }
        
    return $stats;
}
}
