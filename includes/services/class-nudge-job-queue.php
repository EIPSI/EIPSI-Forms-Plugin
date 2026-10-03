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

/**
 * Job Queue para nudges
 */
class EIPSI_Nudge_Job_Queue {
    
    /** Cancel pending follow-ups without touching the availability notification. */
    public static function cancel_follow_up_jobs($assignment_id) {
        global $wpdb;
        $table = self::get_table_name();
        return $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status = 'cancelled', updated_at = %s
             WHERE status = 'pending' AND job_type IN ('send_nudge_1','send_nudge_2','send_nudge_3','send_nudge_4')
             AND JSON_EXTRACT(payload, '$.assignment_id') = %d", current_time('mysql'), $assignment_id
        ));
    }

    /**
     * Tabla de jobs
     */
    private static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'survey_nudge_jobs';
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
        global $wpdb;
        
        if (!$scheduled_at) {
            $scheduled_at = current_time('mysql');
        }
        
        $result = $wpdb->insert(
            self::get_table_name(),
            array(
                'job_type' => sanitize_text_field($job_type),
                'payload' => wp_json_encode($payload),
                'priority' => intval($priority),
                'scheduled_at' => $scheduled_at,
                'status' => 'pending',
                'retries' => 0,
                'created_at' => current_time('mysql')
            ),
            array('%s', '%s', '%d', '%s', '%s', '%d', '%s')
        );
        
        if ($result === false) {
            error_log('[EIPSI JobQueue] ERROR: Failed to enqueue job: ' . $wpdb->last_error);
            return false;
        }
        
        $job_id = $wpdb->insert_id;
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[EIPSI JobQueue] Enqueued job #%d: type=%s, priority=%d, scheduled=%s',
                $job_id,
                $job_type,
                $priority,
                $scheduled_at
            ));
        }
        
        return $job_id;
    }
    
    /**
     * Contar jobs urgentes pendientes (que deberían ya haberse ejecutado)
     * 
     * @return int Cantidad de jobs urgentes
     */
    public static function count_pending_urgent() {
        global $wpdb;
        
        $table = self::get_table_name();
        $now = current_time('mysql');
        
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} 
             WHERE status = 'pending' 
             AND scheduled_at <= %s 
             AND retries < 5",
            $now
        ));
        
        return intval($count);
    }
    
    /**
     * Obtener jobs pendientes para procesar
     * 
     * @param int $limit Cuántos jobs procesar
     * @return array Array de jobs
     */
    public static function get_pending_jobs($limit = 10) {
        global $wpdb;
        
        // SKIP LOCKED requiere MySQL 8.0+ o PostgreSQL
        // Para WordPress/MySQL 5.7, usamos status='pending' + update atómico
        $table = self::get_table_name();
        $now = current_time('mysql');
        
        $jobs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} 
             WHERE status = 'pending' 
             AND scheduled_at <= %s 
             AND retries < 5
             ORDER BY priority ASC, scheduled_at ASC 
             LIMIT %d",
            $now,
            intval($limit)
        ));
        
        return $jobs;
    }
    
    /**
     * Marcar job como procesando (bloqueo optimista)
     * 
     * @param int $job_id ID del job
     * @return bool Éxito
     */
    public static function mark_processing($job_id) {
        global $wpdb;
        
        $result = $wpdb->update(
            self::get_table_name(),
            array(
                'status' => 'processing',
                'processed_at' => current_time('mysql')
            ),
            array('id' => $job_id, 'status' => 'pending'),
            array('%s', '%s'),
            array('%d', '%s')
        );
        
        return $result > 0;
    }
    
    /**
     * Marcar job como completado
     * 
     * @param int $job_id ID del job
     * @param string|null $result Resultado opcional
     * @return bool Éxito
     */
    public static function mark_completed($job_id, $result = null) {
        global $wpdb;
        
        $data = array(
            'status' => 'completed',
            'completed_at' => current_time('mysql')
        );
        
        if ($result) {
            $data['result'] = $result;
        }
        
        $wpdb->update(
            self::get_table_name(),
            $data,
            array('id' => $job_id),
            array('%s', '%s', '%s'),
            array('%d')
        );
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf('[EIPSI JobQueue] Job #%d completed', $job_id));
        }
        
        return true;
    }
    
    /**
     * Marcar job para reintento (con backoff exponencial)
     * 
     * @param int $job_id ID del job
     * @param string $error Mensaje de error
     * @return bool Éxito
     */
    public static function mark_for_retry($job_id, $error) {
        global $wpdb;
        
        $job = $wpdb->get_row($wpdb->prepare(
            "SELECT retries FROM " . self::get_table_name() . " WHERE id = %d",
            $job_id
        ));
        
        if (!$job) {
            return false;
        }
        
        // v2.5.3 - Errores irrecuperables: no reintentar, marcar como failed inmediatamente
        $irrecoverable_errors = [
            'Assignment not found',
            'Participant not found',
            'Wave not found',
            'Invalid payload JSON',
            'Invalid assignment ID',
            'Invalid participant ID'
        ];
        
        foreach ($irrecoverable_errors as $irrecoverable) {
            if (stripos($error, $irrecoverable) !== false) {
                // Marcar como failed permanentemente sin reintentar
                $wpdb->update(
                    self::get_table_name(),
                    array(
                        'status' => 'failed',
                        'error' => sanitize_text_field($error . ' [IRRECOVERABLE]'),
                        'retries' => intval($job->retries),
                        'failed_at' => current_time('mysql')
                    ),
                    array('id' => $job_id),
                    array('%s', '%s', '%d', '%s'),
                    array('%d')
                );
                
                error_log(sprintf(
                    '[EIPSI JobQueue] Job #%d marked as PERMANENTLY FAILED (irrecoverable error): %s',
                    $job_id,
                    $error
                ));
                
                return false;
            }
        }
        
        $retries = intval($job->retries) + 1;
        
        if ($retries >= 5) {
            // Máximo de reintentos alcanzado
            $wpdb->update(
                self::get_table_name(),
                array(
                    'status' => 'failed',
                    'error' => sanitize_text_field($error),
                    'retries' => $retries,
                    'failed_at' => current_time('mysql')
                ),
                array('id' => $job_id),
                array('%s', '%s', '%d', '%s'),
                array('%d')
            );
            
            error_log(sprintf(
                '[EIPSI JobQueue] Job #%d failed permanently after %d retries: %s',
                $job_id,
                $retries,
                $error
            ));
            
            return false;
        }
        
        // Backoff exponencial: 5min, 15min, 45min, 2h, 6h
        $backoff_minutes = [5, 15, 45, 120, 360];
        $delay = $backoff_minutes[min($retries - 1, count($backoff_minutes) - 1)];
        $new_scheduled = date('Y-m-d H:i:s', strtotime("+{$delay} minutes"));
        
        $wpdb->update(
            self::get_table_name(),
            array(
                'status' => 'pending',
                'retries' => $retries,
                'error' => sanitize_text_field($error),
                'scheduled_at' => $new_scheduled
            ),
            array('id' => $job_id),
            array('%s', '%d', '%s', '%s'),
            array('%d')
        );
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[EIPSI JobQueue] Job #%d scheduled for retry #%d at %s',
                $job_id,
                $retries,
                $new_scheduled
            ));
        }
        
        return true;
    }
    
    /**
     * Procesar un batch de jobs
     * 
     * @param int $limit Cuántos jobs procesar
     * @return array Estadísticas
     */
    public static function process_batch($limit = 10) {
        $stats = array(
            'processed' => 0,
            'completed' => 0,
            'failed' => 0,
            'retried' => 0
        );
        
        $jobs = self::get_pending_jobs($limit);
        
        if (empty($jobs)) {
            return $stats;
        }
        
        foreach ($jobs as $job) {
            $stats['processed']++;
            
            // Intentar bloquear el job
            if (!self::mark_processing($job->id)) {
                // Otro worker lo agarró
                continue;
            }
            
            $result = self::execute_job($job);
            
            if ($result['success']) {
                self::mark_completed($job->id, $result['message']);
                $stats['completed']++;
            } else {
                $retry_scheduled = self::mark_for_retry($job->id, $result['error']);
                if ($retry_scheduled) {
                    $stats['retried']++;
                } else {
                    $stats['failed']++;
                }
            }
        }
        
        return $stats;
    }
    
    /**
     * Ejecutar un job específico
     * 
     * @param object $job Job de la BD
     * @return array Resultado
     */
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
    
    /**
     * Ejecutar Nudge 0 (disponibilidad de wave)
     * v2.1.4 - Cambiado a público para permitir ejecución síncrona desde el scheduler
     */
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
            require_once plugin_dir_path(dirname(__FILE__)) . '../admin/services/class-wave-availability-email-service.php';
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
    
    /**
     * Ejecutar Nudge follow-up (1-4)
     * v2.1.4 - Cambiado a público para permitir ejecución síncrona desde el scheduler
     */
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
            require_once plugin_dir_path(dirname(__FILE__)) . '../admin/services/class-email-service.php';
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
    
    /**
     * Obtener estadísticas de la cola
     */
    public static function get_stats() {
        global $wpdb;
        $table = self::get_table_name();
        
        $stats = $wpdb->get_row(
            "SELECT 
                SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status='processing' THEN 1 ELSE 0 END) as processing,
                SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) as failed
             FROM {$table}"
        );
        
        return array(
            'pending' => intval($stats->pending),
            'processing' => intval($stats->processing),
            'completed' => intval($stats->completed),
            'failed' => intval($stats->failed)
        );
    }
    
    /**
     * Cancelar jobs pendientes para un assignment
     * 
     * @param int $assignment_id ID del assignment
     * @return int Número de jobs cancelados
     */
    public static function cancel_jobs_for_assignment($assignment_id) {
        global $wpdb;
        $table = self::get_table_name();
        
        $assignment_id = intval($assignment_id);
        
        // Cancelar jobs pendientes que contengan el assignment_id en el payload
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} 
             SET status = 'cancelled', 
                 updated_at = %s 
             WHERE status IN ('pending', 'processing') 
             AND JSON_EXTRACT(payload, '$.assignment_id') = %d",
            current_time('mysql'),
            $assignment_id
        ));
        
        if ($result === false) {
            error_log('[EIPSI JobQueue] ERROR canceling jobs for assignment ' . $assignment_id . ': ' . $wpdb->last_error);
            return 0;
        }
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[EIPSI JobQueue] Cancelled %d jobs for assignment %d',
                $result,
                $assignment_id
            ));
        }
        
        return intval($result);
    }
}
