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
    eipsi_process_reminders('daily');
}

/**
 * Procesa recordatorios semanales
 * 
 * @since 1.3.0
 */
function eipsi_process_weekly_reminders() {
    eipsi_process_reminders('weekly');
}

/**
 * Lógica principal de procesamiento de recordatorios
 * 
 * @param string $frequency 'daily' | 'weekly'
 * @since 1.3.0
 */
function eipsi_process_reminders($frequency) {
    global $wpdb;
    
    // Log inicio
    error_log("[EIPSI Forms] Processing {$frequency} reminders - " . current_time('mysql'));
    
    // Obtener formularios con aleatorización activa
    $forms = get_posts(array(
        'post_type' => 'eipsi_form',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'meta_query' => array(
            array(
                'key' => '_eipsi_random_config',
                'compare' => 'EXISTS',
            ),
        ),
    ));
    
    if (empty($forms)) {
        error_log("[EIPSI Forms] No forms with randomization found.");
        return;
    }
    
    $reminders_sent = 0;
    $rate_limit = apply_filters('eipsi_cron_reminders_rate_limit', 100);
    
    foreach ($forms as $form_id) {
        if ($reminders_sent >= $rate_limit) {
            error_log("[EIPSI Forms] Rate limit reached: {$rate_limit} emails. Stopping.");
            break;
        }
        
        // Verificar si este formulario tiene recordatorios habilitados con esta frecuencia
        $config = get_post_meta($form_id, '_eipsi_random_config', true);
        
        if (empty($config) || !isset($config['reminderFrequency'])) {
            continue;
        }
        
        // Solo procesar si la frecuencia coincide
        if ($config['reminderFrequency'] !== $frequency) {
            continue;
        }
        
        // Obtener tomas pendientes para este formulario
        $pending_takes = eipsi_get_pending_takes($form_id);
        
        foreach ($pending_takes as $take) {
            if ($reminders_sent >= $rate_limit) {
                break;
            }
            
            // Verificar que no se haya enviado ya hoy
            if (eipsi_reminder_sent_today($form_id, $take['email'])) {
                continue;
            }
            
            // Verificar que no esté unsubscribed
            if (eipsi_is_unsubscribed($form_id, $take['email'])) {
                continue;
            }
            
            // Verificar intentos fallidos (max 3)
            $failed_attempts = get_post_meta($form_id, "_eipsi_reminder_fails_{$take['email']}", true);
            if ($failed_attempts && intval($failed_attempts) >= 3) {
                error_log("[EIPSI Forms] Max failed attempts reached for {$take['email']}");
                continue;
            }
            
            // Generar token y enviar
            $sent = eipsi_send_take_reminder($form_id, $take);
            
            if ($sent) {
                $reminders_sent++;
            }
        }
    }
    
    error_log("[EIPSI Forms] {$frequency} reminders process completed. Sent: {$reminders_sent}");
}

/**
 * Obtiene tomas pendientes para un formulario
 * 
 * @param int $form_id Form ID
 * @return array Array de tomas pendientes { email, take_num, form_id, ... }
 * @since 1.3.0
 */
function eipsi_get_pending_takes($form_id) {
    global $wpdb;
    
    $pending = array();
    
    // Buscar todos los postmeta con pattern _eipsi_toma_*_assign
    $meta_table = $wpdb->postmeta;
    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT meta_key, meta_value 
        FROM {$meta_table} 
        WHERE post_id = %d 
        AND meta_key LIKE %s",
        $form_id,
        $wpdb->esc_like('_eipsi_toma_') . '%' . $wpdb->esc_like('_assign')
    ));
    
    foreach ($results as $row) {
        $data = maybe_unserialize($row->meta_value);
        
        if (!is_array($data)) {
            continue;
        }
        
        // Solo tomas pendientes
        if (isset($data['status']) && $data['status'] === 'pending') {
            // Verificar si está vencida (>7 días sin responder)
            if (isset($data['timestamp'])) {
                $created = strtotime($data['timestamp']);
                $now = current_time('timestamp');
                $days_elapsed = floor(($now - $created) / DAY_IN_SECONDS);
                
                // Solo enviar recordatorios después de 7 días
                if ($days_elapsed < 7) {
                    continue;
                }
            }
            
            // Extraer take_num del meta_key (ej. _eipsi_toma_2_assign → 2)
            preg_match('/_eipsi_toma_(\d+)_assign/', $row->meta_key, $matches);
            $take_num = isset($matches[1]) ? intval($matches[1]) : 1;
            
            $pending[] = array(
                'email' => $data['email'] ?? '',
                'take_num' => $take_num,
                'form_id' => $data['form_id'] ?? $form_id,
                'seed' => $data['seed'] ?? '',
                'timestamp' => $data['timestamp'] ?? current_time('mysql'),
            );
        }
    }
    
    return $pending;
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
    $email = $take['email'];
    $take_num = $take['take_num'];
    $assigned_form_id = $take['form_id'];
    $seed = $take['seed'];
    
    if (empty($email) || !is_email($email)) {
        return false;
    }
    
    // Generar token único
    $token = wp_generate_uuid4();
    
    // Guardar token en postmeta temporal (expira en 48h)
    $token_data = array(
        'token' => $token,
        'email' => $email,
        'form_id' => $assigned_form_id,
        'take' => $take_num,
        'seed' => $seed,
        'created' => current_time('mysql'),
        'expires' => gmdate('Y-m-d H:i:s', strtotime('+48 hours')),
        'manual' => false,
    );
    
    update_post_meta($form_id, '_eipsi_reminder_token_' . md5($email . $take_num), $token_data);
    
    // Construir link de recordatorio
    $reminder_link = add_query_arg(array(
        'eipsi_token' => $token,
        'form_id' => $assigned_form_id,
        'take' => $take_num,
    ), home_url('/formulario/'));
    
    // Construir link de unsubscribe
    $unsubscribe_link = add_query_arg(array(
        'eipsi_unsubscribe' => '1',
        'email' => urlencode($email),
        'form_id' => $form_id,
        'token' => $token,
    ), home_url('/'));
    
    // Cargar template de email
    ob_start();
    include EIPSI_FORMS_PLUGIN_DIR . 'includes/emails/reminder-take.php';
    $email_body = ob_get_clean();
    
    // Headers
    $headers = array('Content-Type: text/html; charset=UTF-8');
    
    // Subject
    $form_name = get_the_title($form_id);
    $subject = sprintf(__('Recordatorio: Tu Toma %d está lista - %s', 'eipsi-forms'), $take_num, $form_name);
    
    // Enviar email
    $sent = wp_mail($email, $subject, $email_body, $headers);
    
    if ($sent) {
        // Log envío exitoso
        update_post_meta($form_id, '_eipsi_reminder_sent_' . md5($email . $take_num), array(
            'timestamp' => current_time('mysql'),
            'token' => $token,
            'take' => $take_num,
        ));
        
        // Reset failed attempts counter
        delete_post_meta($form_id, "_eipsi_reminder_fails_{$email}");
        
        error_log("[EIPSI Forms] Reminder sent to {$email} for take {$take_num}");
    } else {
        // Incrementar contador de fallos
        $fails = get_post_meta($form_id, "_eipsi_reminder_fails_{$email}", true);
        $fails = $fails ? intval($fails) + 1 : 1;
        update_post_meta($form_id, "_eipsi_reminder_fails_{$email}", $fails);
        
        error_log("[EIPSI Forms] Failed to send reminder to {$email}. Attempt {$fails}/3");
    }
    
    return $sent;
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
    global $wpdb;
    
    $meta_table = $wpdb->postmeta;
    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT meta_value 
        FROM {$meta_table} 
        WHERE post_id = %d 
        AND meta_key LIKE %s",
        $form_id,
        $wpdb->esc_like('_eipsi_reminder_sent_') . '%'
    ));
    
    $today = current_time('Y-m-d');
    
    foreach ($results as $row) {
        $data = maybe_unserialize($row->meta_value);
        
        if (is_array($data) && isset($data['timestamp'])) {
            $sent_date = gmdate('Y-m-d', strtotime($data['timestamp']));
            if ($sent_date === $today) {
                return true;
            }
        }
    }
    
    return false;
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
    $unsubscribed = get_post_meta($form_id, '_eipsi_unsubscribed_' . md5($email), true);
    return !empty($unsubscribed);
}

/**
 * Handler para envío manual de recordatorio (programado)
 * 
 * @param string $email Email del participante
 * @param string $reminder_link Link de recordatorio
 * @since 1.3.0
 */
function eipsi_send_manual_reminder_handler($email, $reminder_link) {
    if (empty($email) || !is_email($email)) {
        return;
    }
    
    $headers = array('Content-Type: text/html; charset=UTF-8');
    $subject = __('Recordatorio: Toma pendiente', 'eipsi-forms');

    $body = sprintf(
        '<p>%s</p><p><a href="%s">%s</a></p>',
        __('Hola, tu toma está lista para ser completada.', 'eipsi-forms'),
        esc_url($reminder_link),
        __('Responder ahora', 'eipsi-forms')
    );

    wp_mail($email, $subject, $body, $headers);

    error_log("[EIPSI Forms] Manual reminder sent to {$email}");
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
    error_log('[EIPSI Cron] Wave reminders started at ' . current_time('mysql'));

    if (!class_exists('EIPSI_Email_Service')) {
        require_once plugin_dir_path(__FILE__) . 'services/class-email-service.php';
    }

    if (!class_exists('EIPSI_Wave_Service')) {
        require_once plugin_dir_path(__FILE__) . 'services/class-wave-service.php';
    }

    global $wpdb;

    // Obtener estudios publicados (surveys)
    $surveys = get_posts(array(
        'post_type' => 'eipsi_form',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'fields' => 'ids',
    ));

    if (empty($surveys)) {
        error_log('[EIPSI Cron] No surveys found for wave reminders');
        return;
    }

    $total_sent = 0;
    $total_failed = 0;

    foreach ($surveys as $survey_id) {
        // Obtener configuración de recordatorios para este survey
        $reminders_enabled = get_post_meta($survey_id, '_eipsi_reminders_enabled', true);
        $reminder_days_before = get_post_meta($survey_id, '_eipsi_reminder_days_before', true);
        $max_emails = get_post_meta($survey_id, '_eipsi_max_reminder_emails_per_run', true);

        // Validar configuración
        if (!$reminders_enabled) {
            continue;
        }

        $reminder_days_before = intval($reminder_days_before) ?: 3;
        $max_emails = intval($max_emails) ?: 100;

        // Obtener waves activas de este survey
        $waves = EIPSI_Wave_Service::get_study_waves($survey_id, 'active');

        if (empty($waves)) {
            continue;
        }

        $survey_sent = 0;
        $survey_failed = 0;

        foreach ($waves as $wave) {
            // Verificar si la wave vence en los próximos X días
            $due_date = isset($wave['due_date']) ? $wave['due_date'] : null;
            if (!$due_date) {
                continue;
            }

            $due_timestamp = strtotime($due_date);
            $now = current_time('timestamp');
            $days_until_due = ceil(($due_timestamp - $now) / DAY_IN_SECONDS);

            // Solo enviar si está dentro del rango de recordatorios
            if ($days_until_due <= $reminder_days_before && $days_until_due >= 0) {
                // Obtener participantes pendientes para esta wave
                $pending_participants = $wpdb->get_col($wpdb->prepare(
                    "SELECT p.id
                    FROM {$wpdb->prefix}survey_participants p
                    INNER JOIN {$wpdb->prefix}survey_assignments a ON p.id = a.participant_id
                    WHERE p.survey_id = %d
                    AND a.wave_id = %d
                    AND a.status = 'pending'
                    AND p.is_active = 1
                    LIMIT %d",
                    $survey_id,
                    $wave['id'],
                    $max_emails - $survey_sent
                ));

                foreach ($pending_participants as $participant_id) {
                    // Verificar rate limiting (transient 24h)
                    $transient_key = "eipsi_reminder_sent_{$participant_id}_{$wave['id']}";
                    if (get_transient($transient_key)) {
                        continue; // Ya se envió en las últimas 24h
                    }

                    // Verificar si ya se envió un recordatorio hoy (log check)
                    $already_sent_today = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*)
                        FROM {$wpdb->prefix}survey_email_log
                        WHERE survey_id = %d
                        AND participant_id = %d
                        AND email_type = 'reminder'
                        AND sent_at >= CURDATE()",
                        $survey_id,
                        $participant_id
                    ));

                    if ($already_sent_today > 0) {
                        continue;
                    }

                    // Verificar email válido
                    $participant = $wpdb->get_row($wpdb->prepare(
                        "SELECT email, first_name FROM {$wpdb->prefix}survey_participants WHERE id = %d",
                        $participant_id
                    ));

                    if (!$participant || !is_email($participant->email)) {
                        continue;
                    }

                    // Enviar recordatorio
                    $sent = EIPSI_Email_Service::send_wave_reminder_email($survey_id, $participant_id, $wave);

                    if ($sent) {
                        // Set transient para rate limiting (24h)
                        set_transient($transient_key, true, DAY_IN_SECONDS);
                        $survey_sent++;
                        $total_sent++;
                        error_log("[EIPSI Cron] Wave reminder sent to participant {$participant_id} for wave {$wave['id']}");
                    } else {
                        $survey_failed++;
                        $total_failed++;
                        error_log("[EIPSI Cron] Failed to send wave reminder to participant {$participant_id}");
                    }

                    // Respetar max emails por survey
                    if ($survey_sent >= $max_emails) {
                        break;
                    }
                }
            }
        }

        // Enviar alerta al investigador si está habilitada
        $alert_enabled = get_post_meta($survey_id, '_eipsi_investigator_alert_enabled', true);
        if ($alert_enabled && $survey_sent > 0) {
            $investigator_email = get_post_meta($survey_id, '_eipsi_investigator_alert_email', true);
            if (is_email($investigator_email)) {
                eipsi_send_investigator_alert($survey_id, array(
                    'type' => 'wave_reminders',
                    'sent' => $survey_sent,
                    'failed' => $survey_failed,
                ));
            }
        }
    }

    error_log("[EIPSI Cron] Wave reminders completed. Sent: {$total_sent}, Failed: {$total_failed}");
}

/**
 * Ejecuta el proceso de recuperación de dropouts
 *
 * @since 1.4.2
 */
function eipsi_run_send_dropout_recovery() {
    error_log('[EIPSI Cron] Dropout recovery started at ' . current_time('mysql'));

    if (!class_exists('EIPSI_Email_Service')) {
        require_once plugin_dir_path(__FILE__) . 'services/class-email-service.php';
    }

    if (!class_exists('EIPSI_Wave_Service')) {
        require_once plugin_dir_path(__FILE__) . 'services/class-wave-service.php';
    }

    global $wpdb;

    // Obtener estudios publicados
    $surveys = get_posts(array(
        'post_type' => 'eipsi_form',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'fields' => 'ids',
    ));

    if (empty($surveys)) {
        error_log('[EIPSI Cron] No surveys found for dropout recovery');
        return;
    }

    $total_sent = 0;
    $total_failed = 0;

    foreach ($surveys as $survey_id) {
        // Obtener configuración de recovery
        $recovery_enabled = get_post_meta($survey_id, '_eipsi_dropout_recovery_enabled', true);
        $recovery_days = get_post_meta($survey_id, '_eipsi_dropout_recovery_days_overdue', true);
        $max_emails = get_post_meta($survey_id, '_eipsi_max_recovery_emails_per_run', true);

        if (!$recovery_enabled) {
            continue;
        }

        $recovery_days = intval($recovery_days) ?: 7;
        $max_emails = intval($max_emails) ?: 50;

        // Obtener waves activas
        $waves = EIPSI_Wave_Service::get_study_waves($survey_id, 'active');

        if (empty($waves)) {
            continue;
        }

        $survey_sent = 0;
        $survey_failed = 0;

        foreach ($waves as $wave) {
            // Verificar si la wave ya venció hace X días
            $due_date = isset($wave['due_date']) ? $wave['due_date'] : null;
            if (!$due_date) {
                continue;
            }

            $due_timestamp = strtotime($due_date);
            $now = current_time('timestamp');
            $days_overdue = floor(($now - $due_timestamp) / DAY_IN_SECONDS);

            // Solo procesar waves que están overdue por recovery_days
            if ($days_overdue >= $recovery_days) {
                // Obtener participantes que NO han completado
                $pending_participants = $wpdb->get_col($wpdb->prepare(
                    "SELECT p.id
                    FROM {$wpdb->prefix}survey_participants p
                    INNER JOIN {$wpdb->prefix}survey_assignments a ON p.id = a.participant_id
                    WHERE p.survey_id = %d
                    AND a.wave_id = %d
                    AND a.status != 'submitted'
                    AND p.is_active = 1
                    LIMIT %d",
                    $survey_id,
                    $wave['id'],
                    $max_emails - $survey_sent
                ));

                foreach ($pending_participants as $participant_id) {
                    // Verificar si ya se envió recovery email
                    $already_sent = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*)
                        FROM {$wpdb->prefix}survey_email_log
                        WHERE survey_id = %d
                        AND participant_id = %d
                        AND email_type = 'recovery'",
                        $survey_id,
                        $participant_id
                    ));

                    if ($already_sent > 0) {
                        continue; // Ya se envió recovery email
                    }

                    // Verificar email válido
                    $participant = $wpdb->get_row($wpdb->prepare(
                        "SELECT email, first_name FROM {$wpdb->prefix}survey_participants WHERE id = %d",
                        $participant_id
                    ));

                    if (!$participant || !is_email($participant->email)) {
                        continue;
                    }

                    // Enviar email de recovery
                    $sent = EIPSI_Email_Service::send_dropout_recovery_email($survey_id, $participant_id, $wave);

                    if ($sent) {
                        $survey_sent++;
                        $total_sent++;
                        error_log("[EIPSI Cron] Dropout recovery sent to participant {$participant_id} for wave {$wave['id']}");
                    } else {
                        $survey_failed++;
                        $total_failed++;
                        error_log("[EIPSI Cron] Failed to send dropout recovery to participant {$participant_id}");
                    }

                    // Respetar max emails por survey
                    if ($survey_sent >= $max_emails) {
                        break;
                    }
                }
            }
        }

        // Enviar alerta al investigador
        $alert_enabled = get_post_meta($survey_id, '_eipsi_investigator_alert_enabled', true);
        if ($alert_enabled && $survey_sent > 0) {
            $investigator_email = get_post_meta($survey_id, '_eipsi_investigator_alert_email', true);
            if (is_email($investigator_email)) {
                eipsi_send_investigator_alert($survey_id, array(
                    'type' => 'dropout_recovery',
                    'sent' => $survey_sent,
                    'failed' => $survey_failed,
                ));
            }
        }
    }

    error_log("[EIPSI Cron] Dropout recovery completed. Sent: {$total_sent}, Failed: {$total_failed}");
}

/**
 * Envía alerta al investigador con resumen de actividad
 *
 * @param int $survey_id
 * @param array $stats
 * @since 1.4.2
 */
function eipsi_send_investigator_alert($survey_id, $stats) {
    $investigator_email = get_post_meta($survey_id, '_eipsi_investigator_alert_email', true);

    if (!is_email($investigator_email)) {
        return;
    }

    $survey_name = get_the_title($survey_id);
    $subject = sprintf('[EIPSI Forms] Alerta de actividad - %s', $survey_name);

    ob_start();
    ?>
    <p>Investigador/a,</p>
    <p>Se ha completado una ejecución de cron para el estudio <strong><?php echo esc_html($survey_name); ?></strong>.</p>

    <?php if ($stats['type'] === 'wave_reminders'): ?>
        <h3>Recordatorios de Waves</h3>
        <p>Recordatorios enviados: <strong><?php echo intval($stats['sent']); ?></strong></p>
        <p>Fallos: <strong><?php echo intval($stats['failed']); ?></strong></p>
    <?php elseif ($stats['type'] === 'dropout_recovery'): ?>
        <h3>Recuperación de Dropouts</h3>
        <p>Correos de recuperación enviados: <strong><?php echo intval($stats['sent']); ?></strong></p>
        <p>Fallos: <strong><?php echo intval($stats['failed']); ?></strong></p>
    <?php endif; ?>

    <hr>
    <p><small>Hora de ejecución: <?php echo current_time('Y-m-d H:i:s'); ?></small></p>
    <p><small>Este mensaje fue generado automáticamente por EIPSI Forms. No respondas a este email.</small></p>
    <?php
    $message = ob_get_clean();

    $headers = array('Content-Type: text/html; charset=UTF-8');

    wp_mail($investigator_email, $subject, $message, $headers);

    error_log("[EIPSI Cron] Investigator alert sent to {$investigator_email} for survey {$survey_id}");
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
    global $wpdb;

    // Obtener waves pendientes
    $pending_waves = $wpdb->get_results($wpdb->prepare(
        "SELECT w.* 
        FROM {$wpdb->prefix}survey_waves w
        WHERE w.study_id = %d
        AND w.status = 'active'
        AND w.due_date > CURDATE()",
        $study_id
    ));

    $sent_count = 0;
    $failed_count = 0;

    foreach ($pending_waves as $wave) {
        // Obtener participantes pendientes
        $participants = $wpdb->get_col($wpdb->prepare(
            "SELECT p.id
            FROM {$wpdb->prefix}survey_participants p
            INNER JOIN {$wpdb->prefix}survey_assignments a ON p.id = a.participant_id
            WHERE p.survey_id = %d
            AND a.wave_id = %d
            AND a.status = 'pending'
            AND p.is_active = 1",
            $study_id,
            $wave->id
        ));

        foreach ($participants as $participant_id) {
            // Verificar si ya se envió recordatorio hoy
            $already_sent = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*)
                FROM {$wpdb->prefix}survey_email_log
                WHERE survey_id = %d
                AND participant_id = %d
                AND email_type = 'reminder'
                AND sent_at >= CURDATE()",
                $study_id,
                $participant_id
            ));

            if ($already_sent > 0) {
                continue;
            }

            // Enviar recordatorio
            if (!class_exists('EIPSI_Email_Service')) {
                require_once plugin_dir_path(__FILE__) . 'services/class-email-service.php';
            }

            $sent = EIPSI_Email_Service::send_wave_reminder_email($study_id, $participant_id, $wave);

            if ($sent) {
                $sent_count++;
            } else {
                $failed_count++;
            }
        }
    }

    return array(
        'success' => true,
        'sent' => $sent_count,
        'failed' => $failed_count,
        'message' => sprintf('Sent %d reminders, %d failed', $sent_count, $failed_count)
    );
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
    global $wpdb;

    error_log('[EIPSI Cron] Wave availability processor started at ' . current_time('mysql'));

    $now = current_time('mysql');
    $assignments_table = $wpdb->prefix . 'survey_assignments';

    // Find assignments that just became available (available_at <= NOW, status = pending, no email sent yet)
    $newly_available=EIPSI_Longitudinal_Assignment_Repository::get_newly_available($now);

    if (empty($newly_available)) {
        error_log('[EIPSI Cron] No waves newly available.');
        return;
    }

    $notified_count = 0;

    // Load email service
    if (!class_exists('EIPSI_Wave_Availability_Email_Service')) {
        require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-wave-availability-email-service.php';
    }

    foreach ($newly_available as $assignment) {
        // Load required objects for the email service
        global $wpdb;
        
        $wave = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}survey_waves WHERE id = %d",
            $assignment->wave_id
        ));
        
        $participant = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}survey_participants WHERE id = %d",
            $assignment->participant_id
        ));
        
        if (!$wave || !$participant) {
            error_log("[EIPSI Cron] Wave availability: Missing wave or participant data for assignment {$assignment->id}");
            continue;
        }
        
        // Send wave availability notification using the correct method
        $result = EIPSI_Wave_Availability_Email_Service::ensure_wave_availability_email_sent(
            $assignment,
            $wave,
            $participant,
            $assignment->study_id
        );

        if ($result['success'] && $result['sent']) {
            $notified_count++;

            // ========================================
            // DEPRECATED: This cron is now redundant with event-driven system
            // Keeping for backward compatibility during transition
            // ========================================
            error_log(sprintf(
                '[EIPSI WaveAvail] DEPRECATED: Wave detected by cron for assignment %d. Event-driven system should handle this.',
                $assignment->id
            ));

            // Trigger hook
            do_action('eipsi_wave_became_available', array(
                'assignment_id' => $assignment->id,
                'participant_id' => $assignment->participant_id,
                'wave_id' => $assignment->wave_id,
                'study_id' => $assignment->study_id,
                'wave_index' => $assignment->wave_index,
            ));
        }
    }

    error_log(sprintf(
        '[EIPSI Cron] Wave availability processor completed. Notified: %d participants.',
        $notified_count
    ));
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
