<?php
/** Notifications owner; public WordPress adapters retain existing contracts. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Notification_Wave_Availability_Service {
const MAX_RETRY_ATTEMPTS = 3;
const RETRY_DELAY_MINUTES = 5;
const EMAIL_TYPE = 'wave_available';
public static function ensure_wave_availability_email_sent($assignment, $wave, $participant, $study_id) {
        $participant_id = $assignment->participant_id;
        $wave_id = $assignment->wave_id;

        // Verificación consolidada en un solo log
        $is_available = self::is_wave_available($assignment, $wave);
        $already_sent = self::was_nudge_zero_already_sent($participant_id, $wave_id);
        $retry_status = self::get_retry_status($participant_id, $wave_id);

        error_log(sprintf(
            '[EIPSI WaveEmail] Verificando p=%d w=%d | disponible=%s | enviado=%s | reintentos=%d/%d',
            $participant_id,
            $wave_id,
            $is_available ? 'SÍ' : 'NO',
            $already_sent ? 'SÍ' : 'NO',
            $retry_status['attempts'],
            self::MAX_RETRY_ATTEMPTS
        ));

        if (!$is_available) {
            return array(
                'success' => false,
                'sent' => false,
                'reason' => 'wave_not_available',
                'message' => 'La toma aún no está disponible',
                'participant_id' => $participant_id,
                'wave_id' => $wave_id
            );
        }

        if ($already_sent) {
            return array(
                'success' => true,
                'sent' => false,
                'reason' => 'already_sent',
                'message' => 'Nudge 0 ya enviado anteriormente, no se duplica',
                'participant_id' => $participant_id,
                'wave_id' => $wave_id
            );
        }

        if ($retry_status['attempts'] >= self::MAX_RETRY_ATTEMPTS) {
            error_log("[EIPSI WaveEmail] ✗ Máximo reintentos alcanzado ({$retry_status['attempts']}/" . self::MAX_RETRY_ATTEMPTS . ")");
            return array(
                'success' => false,
                'sent' => false,
                'reason' => 'max_retries_reached',
                'message' => "Se alcanzó el máximo de reintentos (" . self::MAX_RETRY_ATTEMPTS . ")",
                'participant_id' => $participant_id,
                'wave_id' => $wave_id,
                'retry_history' => $retry_status
            );
        }

        // Verificar cooldown entre reintentos
        if ($retry_status['last_attempt'] > 0) {
            $minutes_since_last = (time() - $retry_status['last_attempt']) / 60;
            if ($minutes_since_last < self::RETRY_DELAY_MINUTES) {
                $wait = round(self::RETRY_DELAY_MINUTES - $minutes_since_last);
                error_log("[EIPSI WaveEmail] ⏳ COOLDOWN ACTIVO: Esperar {$wait} minutos antes de reintentar");
                return array(
                    'success' => false,
                    'sent' => false,
                    'reason' => 'retry_cooldown',
                    'message' => "Cooldown activo, esperar {$wait} minutos",
                    'participant_id' => $participant_id,
                    'wave_id' => $wave_id,
                    'wait_minutes' => $wait
                );
            }
        }

        // Intentar enviar
        $attempt = $retry_status['attempts'] + 1;
        $send_result = self::send_wave_availability_email_with_retry(
            $assignment,
            $wave,
            $participant,
            $study_id,
            $attempt
        );

        if ($send_result['success']) {
            self::mark_nudge_zero_sent($participant_id, $wave_id, $send_result['log_id']);
            error_log("[EIPSI WaveEmail] ✓ Enviado p={$participant_id} w={$wave_id} (log_id={$send_result['log_id']})");

            return array(
                'success' => true,
                'sent' => true,
                'reason' => 'sent_successfully',
                'message' => 'Email enviado correctamente',
                'participant_id' => $participant_id,
                'wave_id' => $wave_id,
                'log_id' => $send_result['log_id'],
                'attempts' => $attempt
            );
        } else {
            self::record_failed_attempt($participant_id, $wave_id, $send_result['error']);

            // Log detallado solo para errores críticos
            if ($send_result['error'] !== 'retry_cooldown') {
                error_log("[EIPSI WaveEmail] ✗ Fallo p={$participant_id} w={$wave_id}: {$send_result['error']} (intento {$attempt})");
            }

            return array(
                'success' => false,
                'sent' => false,
                'reason' => 'send_failed',
                'message' => $send_result['error'],
                'participant_id' => $participant_id,
                'wave_id' => $wave_id,
                'attempts' => $retry_status['attempts'] + 1,
                'error' => $send_result['error']
            );
        }
    }

private static function is_wave_available($assignment,$wave) { return EIPSI_Longitudinal_Notification_Context_Service::is_wave_available($assignment,$wave); }

private static function was_nudge_zero_already_sent($participant_id, $wave_id) {
        global $wpdb;

        // MÉTODO 1: Buscar en email_log con query mejorada que cubre:
        // - Tipos nuevos: wave_availability_T1, wave_availability_T2, etc. (v2.6.1+)
        // - Tipos legacy: 'reminder', 'wave_availability', 'nudge_0' (pre v2.6.1)
        // - Registros históricos con email_type vacío (migración ENUM→VARCHAR)

        $wave_id_pattern = '%"wave_id":' . intval($wave_id) . '%';

        $logs = $wpdb->get_results($wpdb->prepare(
            "SELECT id, status, metadata, email_type
             FROM {$wpdb->prefix}survey_email_log
             WHERE participant_id = %d
             AND status IN ('sent', 'pending')
             AND (
                 email_type LIKE 'wave_availability_%%'
                 OR email_type IN ('wave_availability', 'reminder', 'nudge_0')
                 OR (
                     email_type = ''
                     AND metadata LIKE %s
                     AND metadata LIKE '%%\"nudge_stage\":0%%'
                 )
             )
             ORDER BY sent_at DESC
             LIMIT 10",
            $participant_id,
            $wave_id_pattern
        ));

        foreach ($logs as $log) {
            $metadata = !empty($log->metadata) ? json_decode($log->metadata, true) : array();

            // Manejar metadata malformada
            if (!is_array($metadata)) {
                $metadata = array();
            }

            // Verificar que el registro corresponde al wave_id correcto
            if (!empty($metadata['wave_id'])) {
                if ((int) $metadata['wave_id'] === (int) $wave_id) {
                    // Verificar que es nudge_stage 0
                    if (isset($metadata['nudge_stage']) && (int) $metadata['nudge_stage'] === 0) {
                        // Logging para auditoría
                        if (empty($log->email_type)) {
                            error_log(sprintf(
                                '[EIPSI WaveEmail] Deduplicación por fallback metadata: participant=%d wave_id=%d log_id=%d',
                                $participant_id,
                                $wave_id,
                                $log->id
                            ));
                        } else {
                            if (defined('WP_DEBUG') && WP_DEBUG) {
                                error_log("[EIPSI WaveEmail] Nudge 0 ya enviado (log_id={$log->id}, type={$log->email_type})");
                            }
                        }
                        return true;
                    }
                }
                // Es de otro wave, ignorar y continuar
                continue;
            }

            // Si no tiene wave_id en metadata (registros muy antiguos),
            // usar el email_type como única señal
            if (!empty($log->email_type) && $log->email_type !== '') {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("[EIPSI WaveEmail] Nudge 0 detectado por email_type legacy (log_id={$log->id})");
                }
                return true;
            }
        }

        // MÉTODO 2 (BACKUP): Verificar si el usuario ya interactuó con la wave
        // Esto previene duplicados incluso si el email_log se borra o el metadata falla
        // Usamos vas_form_results que guarda las submissions de formularios
        $already_accessed = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}vas_form_results
             WHERE participant_id = %d AND form_id = %d",
            $participant_id,
            $wave_id
        ));

        if ($already_accessed > 0) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("[EIPSI WaveEmail] Usuario ya accedió a wave {$wave_id} - no necesita Nudge 0");
            }
            return true;
        }

        // MÉTODO 3 (BACKUP): Verificar si hay assignment con status 'submitted' o 'in_progress'
        $assignment_status = $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$wpdb->prefix}survey_assignments
             WHERE participant_id = %d AND wave_id = %d",
            $participant_id,
            $wave_id
        ));

        if (in_array($assignment_status, array('submitted', 'in_progress'))) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("[EIPSI WaveEmail] Assignment en '{$assignment_status}' - no necesita Nudge 0");
            }
            return true;
        }

        return false;
    }

private static function get_retry_status($participant_id, $wave_id) {
        $transient_key = "eipsi_wave_email_retries_{$participant_id}_{$wave_id}";
        $status = get_transient($transient_key);

        if (!$status) {
            return array(
                'attempts' => 0,
                'last_attempt' => 0,
                'errors' => array()
            );
        }

        return $status;
    }

private static function record_failed_attempt($participant_id, $wave_id, $error) {
        $transient_key = "eipsi_wave_email_retries_{$participant_id}_{$wave_id}";
        $status = self::get_retry_status($participant_id, $wave_id);

        $status['attempts']++;
        $status['last_attempt'] = time();
        $status['errors'][] = array(
            'time' => current_time('mysql'),
            'error' => $error
        );

        // Guardar por 24 horas
        set_transient($transient_key, $status, 24 * HOUR_IN_SECONDS);

        // Solo loguear errores críticos, no cooldowns
        if ($error !== 'retry_cooldown' && (defined('WP_DEBUG') && WP_DEBUG)) {
            error_log("[EIPSI WaveEmail] Intento fallido registrado: {$status['attempts']}/" . self::MAX_RETRY_ATTEMPTS);
        }
    }

private static function mark_nudge_zero_sent($participant_id, $wave_id, $log_id) {
        global $wpdb;

        $transient_key = "eipsi_wave_email_retries_{$participant_id}_{$wave_id}";

        $status = array(
            'attempts' => 0, // Resetear intentos
            'last_attempt' => 0,
            'sent' => true,
            'sent_at' => current_time('mysql'),
            'log_id' => $log_id,
            'errors' => array()
        );

        // Guardar por 7 días para evitar duplicados
        set_transient($transient_key, $status, 7 * DAY_IN_SECONDS);

        // CRITICAL FIX: Actualizar assignment en DB con reminder_count=1 y last_nudge_sent_at
        $assignment = $wpdb->get_row($wpdb->prepare(
            "SELECT id, reminder_count, last_nudge_sent_at, status FROM {$wpdb->prefix}survey_assignments
             WHERE participant_id = %d AND wave_id = %d",
            $participant_id,
            $wave_id
        ));

        if ($assignment) {
            error_log(sprintf(
                "[EIPSI NUDGE 0 UPDATE] BEFORE: assignment_id=%d, reminder_count=%d, last_nudge_sent_at=%s, status=%s",
                $assignment->id,
                $assignment->reminder_count,
                $assignment->last_nudge_sent_at ?: 'NULL',
                $assignment->status
            ));

            $now = current_time('mysql');
            $rows_affected = $wpdb->update(
                $wpdb->prefix . 'survey_assignments',
                array(
                    'reminder_count' => 1,
                    'last_nudge_sent_at' => $now,
                    'updated_at' => $now
                ),
                array(
                    'id' => $assignment->id
                ),
                array('%d', '%s', '%s'),
                array('%d')
            );

            if ($rows_affected === false) {
                error_log(sprintf(
                    "[EIPSI NUDGE 0 UPDATE] ❌ ERROR: Failed to update assignment_id=%d. DB Error: %s",
                    $assignment->id,
                    $wpdb->last_error
                ));
            } else {
                error_log(sprintf(
                    "[EIPSI NUDGE 0 UPDATE] ✅ SUCCESS: assignment_id=%d updated. reminder_count=1, last_nudge_sent_at=%s, rows_affected=%d",
                    $assignment->id,
                    $now,
                    $rows_affected
                ));

                // Verificar que el UPDATE funcionó
                $updated = $wpdb->get_row($wpdb->prepare(
                    "SELECT reminder_count, last_nudge_sent_at FROM {$wpdb->prefix}survey_assignments WHERE id = %d",
                    $assignment->id
                ));

                error_log(sprintf(
                    "[EIPSI NUDGE 0 UPDATE] AFTER: assignment_id=%d, reminder_count=%d, last_nudge_sent_at=%s",
                    $assignment->id,
                    $updated->reminder_count,
                    $updated->last_nudge_sent_at
                ));

                // ========================================
                // TRIGGER 1: Programar nudges 1-4 inmediatamente después de Nudge 0
                // ========================================
                error_log(sprintf(
                    "[EIPSI NUDGE0→FOLLOWUP] TRIGGER 1: Attempting to schedule follow-up nudges for assignment %d",
                    $assignment->id
                ));

                self::schedule_followup_nudges_after_nudge_zero($assignment->id);
            }
        } else {
            error_log(sprintf(
                "[EIPSI NUDGE 0 UPDATE] ❌ ERROR: Assignment not found for participant_id=%d, wave_id=%d",
                $participant_id,
                $wave_id
            ));
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("[EIPSI WaveEmail] Nudge 0 marcado como enviado. Log ID: {$log_id}");
        }
    }

private static function send_wave_availability_email_with_retry($assignment, $wave, $participant, $study_id, $attempt_number) {
        // Log simplificado solo en debug
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("[EIPSI WaveEmail] Enviando email (intento #{$attempt_number})");
        }
        global $wpdb;

        if (!class_exists('EIPSI_Email_Service')) {
            require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-email-service.php';
        }

        // Get study info for correct URL
        $study = $wpdb->get_row($wpdb->prepare(
            "SELECT study_code, study_name FROM {$wpdb->prefix}survey_studies WHERE id = %d",
            $study_id
        ));

        if (!$study) {
            error_log("[EIPSI WaveEmail] ERROR: Study {$study_id} no encontrado");
            return array(
                'success' => false,
                'error' => 'study_not_found',
                'message' => 'Estudio no encontrado'
            );
        }

        // Build study page URL
        $study_slug = 'estudio-' . sanitize_title($study->study_code);
        $study_page = get_page_by_path($study_slug);

        if (!$study_page) {
            // Fallback: try to find by meta
            $study_pages = get_posts([
                'post_type' => 'page',
                'meta_key' => 'eipsi_study_id',
                'meta_value' => $study_id,
                'posts_per_page' => 1
            ]);
            $study_page = !empty($study_pages) ? $study_pages[0] : null;
        }

        if (!$study_page) {
            error_log("[EIPSI WaveEmail] ERROR: Página no encontrada para study: {$study_slug}");
            return array(
                'success' => false,
                'error' => 'study_page_not_found',
                'message' => 'Página del estudio no encontrada'
            );
        }

        $study_url = get_permalink($study_page->ID);
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("[EIPSI WaveEmail] Study URL: {$study_url}");
        }

        // Generate magic token
        if (!class_exists('EIPSI_MagicLinksService')) {
            require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-magic-links-service.php';
        }

        $token = EIPSI_MagicLinksService::generate_magic_link($study_id, $participant->id);
        if (!$token) {
            // El error ya fue logueado por MagicLinksService con diagnóstico
            return array(
                'success' => false,
                'error' => 'token_generation_failed',
                'message' => 'No se pudo generar el token de acceso'
            );
        }

        // Build magic link with email pre-filled
        $magic_link = add_query_arg([
            'eipsi_magic' => $token,
            'email_pre' => urlencode($participant->email)
        ], $study_url);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("[EIPSI WaveEmail] Magic link generado para p={$participant->id}");
        }

        // Prepare email content
        $wave_name = $wave->name ?: 'Toma ' . $wave->order_index;
        $subject = "Tu siguiente evaluación está disponible - " . $study->study_name;

        $message = sprintf(
            '<p>¡Hola %s!</p>
            <p>¡Buenas noticias! Ya podés acceder a tu siguiente toma del estudio:</p>
            <h2>%s (%s)</h2>
            <p style="text-align: center; margin: 30px 0;">
                <a href="%s" style="background: #4F46E5; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; display: inline-block;">
                    Comenzar Evaluación →
                </a>
            </p>
            <p>O copiá este link en tu navegador:<br>
            <code style="background: #f3f4f6; padding: 8px; word-break: break-all;">%s</code></p>
            <hr>
            <p style="color: #666; font-size: 14px;">
                <strong>¿Necesitás ayuda?</strong><br>
                Si tenés problemas para acceder, respondé a este email o contactá al investigador.
            </p>
            <p style="color: #999; font-size: 12px;">
                Este es un email automático del sistema EIPSI Forms.<br>
                Por favor no respondas directamente a este mensaje.
            </p>',
            esc_html($participant->first_name),
            esc_html($study->study_name),
            esc_html($wave_name),
            esc_url($magic_link),
            esc_html($magic_link)
        );

        // Send email with metadata for duplicate detection
        // v2.6.1 - Incluir wave_index en email_type
        $wave_index = isset($wave->wave_index) ? $wave->wave_index : 1;
        $email_type = 'wave_availability_T' . $wave_index;

        $metadata = array(
            'wave_id' => $wave->id,
            'wave_index' => $wave_index,
            'wave_name' => $wave->name,
            'nudge_stage' => 0,
            'email_variant' => 'wave_available',
            'base_type' => 'wave_availability'
        );

        $log_id = EIPSI_Email_Service::send_email(
            $study_id,
            $participant->id,
            $participant->email,
            $email_type,
            $subject,
            $message,
            $metadata
        );

        if ($log_id) {
            return array(
                'success' => true,
                'log_id' => $log_id,
                'message' => 'Email enviado correctamente'
            );
        } else {
            error_log("[EIPSI WaveEmail] ERROR: Fallo SMTP al enviar a p={$participant->id}");
            return array(
                'success' => false,
                'error' => 'smtp_error',
                'message' => 'Fallo al enviar email via SMTP'
            );
        }
    }

private static function log_email_success($survey_id, $participant_id, $wave_id, $recipient_email = '') {
        global $wpdb;

        // Usar el email pasado como parámetro, o buscarlo si no se proporcionó
        if (empty($recipient_email)) {
            $participant = $wpdb->get_row($wpdb->prepare(
                "SELECT email FROM {$wpdb->prefix}survey_participants WHERE id = %d",
                $participant_id
            ));
            $recipient_email = $participant ? $participant->email : '';
        }

        $result = $wpdb->insert(
            $wpdb->prefix . 'survey_email_log',
            array(
                'survey_id' => $survey_id,
                'participant_id' => $participant_id,
                'email_type' => 'wave_availability',  // v2.5.3 - Nudge 0 específico
                'recipient_email' => $recipient_email,
                'subject' => 'Tu siguiente evaluación está disponible',
                'status' => 'sent',
                'sent_at' => current_time('mysql'),
                'created_at' => current_time('mysql'),
                'metadata' => wp_json_encode(array(
                    'wave_id' => $wave_id,
                    'nudge_stage' => 0,
                    'email_variant' => 'wave_available'
                ))
            ),
            array('%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );

        return $result ? $wpdb->insert_id : 0;
    }

public static function force_send_nudge_zero($assignment, $wave, $participant, $study_id) {
        error_log("[EIPSI WaveEmail] FORZANDO envío de Nudge 0 (bypass de verificaciones)");

        // Limpiar estado de reintentos
        $transient_key = "eipsi_wave_email_retries_{$assignment->participant_id}_{$wave->id}";
        delete_transient($transient_key);

        // Forzar envío
        return self::send_wave_availability_email_with_retry(
            $assignment,
            $wave,
            $participant,
            $study_id,
            1
        );
    }

private static function schedule_followup_nudges_after_nudge_zero($assignment_id) {
        global $wpdb;

        error_log(sprintf(
            "[EIPSI NUDGE0→FOLLOWUP] START: Scheduling follow-up nudges for assignment %d",
            $assignment_id
        ));

        // Verificar que la clase existe
        if (!class_exists('EIPSI_Nudge_Event_Scheduler')) {
            error_log("[EIPSI NUDGE0→FOLLOWUP] Loading EIPSI_Nudge_Event_Scheduler class");
            require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/services/class-nudge-event-scheduler.php';
        }

        // Obtener assignment fresco de la DB
        $assignments_table = $wpdb->prefix . 'survey_assignments';
        $fresh_assignment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$assignments_table} WHERE id = %d",
            $assignment_id
        ));

        if (!$fresh_assignment) {
            error_log(sprintf(
                "[EIPSI NUDGE0→FOLLOWUP] ❌ ERROR: Assignment %d not found in database",
                $assignment_id
            ));
            return false;
        }

        error_log(sprintf(
            "[EIPSI NUDGE0→FOLLOWUP] Assignment state: id=%d, reminder_count=%d, status=%s, last_nudge_sent_at=%s",
            $fresh_assignment->id,
            $fresh_assignment->reminder_count,
            $fresh_assignment->status,
            $fresh_assignment->last_nudge_sent_at
        ));

        // Verificar que reminder_count sea 1 (Nudge 0 enviado)
        if ($fresh_assignment->reminder_count != 1) {
            error_log(sprintf(
                "[EIPSI NUDGE0→FOLLOWUP] ⚠️ WARNING: reminder_count=%d (expected 1). Skipping scheduling.",
                $fresh_assignment->reminder_count
            ));
            return false;
        }

        // Verificar que no esté submitted o expired
        if (in_array($fresh_assignment->status, ['submitted', 'expired', 'skipped'])) {
            error_log(sprintf(
                "[EIPSI NUDGE0→FOLLOWUP] ⚠️ WARNING: Assignment status=%s. Skipping scheduling.",
                $fresh_assignment->status
            ));
            return false;
        }

        // Programar nudges 1-4
        try {
            error_log(sprintf(
                "[EIPSI NUDGE0→FOLLOWUP] Calling EIPSI_Nudge_Event_Scheduler::schedule_follow_up_nudges_only(%d)",
                $assignment_id
            ));

            EIPSI_Nudge_Event_Scheduler::schedule_follow_up_nudges_only($fresh_assignment);

            error_log(sprintf(
                "[EIPSI NUDGE0→FOLLOWUP] ✅ SUCCESS: Follow-up nudges scheduled for assignment %d",
                $assignment_id
            ));

            return true;

        } catch (Exception $e) {
            error_log(sprintf(
                "[EIPSI NUDGE0→FOLLOWUP] ❌ EXCEPTION: %s",
                $e->getMessage()
            ));
            return false;
        }
    }
}
