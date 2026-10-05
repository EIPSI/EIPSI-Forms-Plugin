<?php
/** Notifications owner; public WordPress adapters retain existing contracts. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Notification_Legacy_Reminder_Service {

public static function eipsi_process_daily_reminders() {
    eipsi_process_reminders('daily');
}

public static function eipsi_process_weekly_reminders() {
    eipsi_process_reminders('weekly');
}

public static function eipsi_process_reminders($frequency) {
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

public static function eipsi_get_pending_takes($form_id) {
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

public static function eipsi_send_take_reminder($form_id, $take) {
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

public static function eipsi_reminder_sent_today($form_id, $email) {
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

public static function eipsi_is_unsubscribed($form_id, $email) {
    $unsubscribed = get_post_meta($form_id, '_eipsi_unsubscribed_' . md5($email), true);
    return !empty($unsubscribed);
}

public static function eipsi_send_manual_reminder_handler($email, $reminder_link) {
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

public static function eipsi_run_send_wave_reminders() {
    error_log('[EIPSI Cron] Wave reminders started at ' . current_time('mysql'));

    if (!class_exists('EIPSI_Email_Service')) {
        require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-email-service.php';
    }

    if (!class_exists('EIPSI_Wave_Service')) {
        require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-wave-service.php';
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

public static function eipsi_run_send_dropout_recovery() {
    error_log('[EIPSI Cron] Dropout recovery started at ' . current_time('mysql'));

    if (!class_exists('EIPSI_Email_Service')) {
        require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-email-service.php';
    }

    if (!class_exists('EIPSI_Wave_Service')) {
        require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-wave-service.php';
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

public static function eipsi_send_investigator_alert($survey_id, $stats) {
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

public static function eipsi_cron_action_send_reminders($study_id) {
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
                require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-email-service.php';
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
}
