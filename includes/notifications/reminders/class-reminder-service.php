<?php
/** Notifications owner; public WordPress adapters retain existing contracts. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Notification_Reminder_Service {

public static function eipsi_send_wave_reminders_hourly($specific_study_id = null) {
    error_log('[EIPSI Cron] Starting hourly wave reminders' . ($specific_study_id ? " for study {$specific_study_id}" : ' for all studies'));

    global $wpdb;

    // =========================================================================
    // PROFESSIONAL CRON OPTIMIZATIONS - v2.4.0
    // =========================================================================

    // 1. PROCESS LOCKING - Prevent concurrent executions
    $lock_key = 'eipsi_cron_wave_reminders_lock';
    $lock_timeout = 5 * MINUTE_IN_SECONDS; // 5 minutes max execution time

    if (get_transient($lock_key)) {
        error_log('[EIPSI Cron] LOCKED: Another instance is running. Aborting.');
        return array(
            'processed_studies' => 0,
            'total_emails_queued' => 0,
            'total_emails_sent' => 0,
            'status' => 'locked',
            'message' => 'Another cron instance is already running'
        );
    }

    // Set lock
    set_transient($lock_key, array(
        'started_at' => current_time('mysql'),
        'pid' => getmypid(),
        'host' => isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'cli'
    ), $lock_timeout);

    // 5. SHUTDOWN HANDLER - Ensure lock is released on fatal errors
    register_shutdown_function(function() use ($lock_key) {
        // Only release if this process still holds the lock
        $lock_data = get_transient($lock_key);
        if ($lock_data && isset($lock_data['pid']) && $lock_data['pid'] === getmypid()) {
            delete_transient($lock_key);
            error_log('[EIPSI Cron] Lock released via shutdown handler');
        }
    });

    // FIX 2: SILENT CRON TRACKING - Log cron health for diagnostics (no UI)
    $last_cron = get_option('eipsi_last_cron_run');
    $now_ts = time();
    if ($last_cron) {
        $minutes_since = round(($now_ts - $last_cron) / 60);
        error_log("[EIPSI Cron] Last execution was {$minutes_since} minutes ago");
        if ($minutes_since > 10) {
            error_log("[EIPSI Cron] WARNING: Cron delay detected (>10 min). WP-Cron depends on site traffic. Consider server cron: */5 * * * * curl -s " . site_url('/wp-cron.php?doing_wp_cron') . " > /dev/null 2>&1");
        }
    } else {
        error_log("[EIPSI Cron] First execution tracked (no previous history)");
    }
    update_option('eipsi_last_cron_run', $now_ts);

    // 2. TIME LIMIT CONTROL - Prevent gateway timeouts
    $start_time = microtime(true);
    $max_execution_time = 25; // 25 seconds (Hostinger typically allows 30)

    // Check if running via real cron (CLI) or WP-Cron (HTTP)
    $is_cli = (php_sapi_name() === 'cli');
    $is_real_cron = defined('DOING_CRON') && DOING_CRON;

    // If via WP-Cron HTTP, be more conservative with time
    if (!$is_cli && !$is_real_cron) {
        $max_execution_time = 20; // 20 seconds for WP-Cron HTTP requests
    }

    error_log("[EIPSI Cron] Process started at {$start_time}, max execution: {$max_execution_time}s, CLI: " . ($is_cli ? 'YES' : 'NO'));

    // 3. BATCH LIMITING - Process max emails per run to avoid timeouts
    $batch_size = 5; // Maximum emails to send per cron run
    $emails_processed = 0;

    // 4. MEMORY MONITORING
    $start_memory = memory_get_usage(true);
    $memory_limit = ini_get('memory_limit');
    $memory_limit_bytes = wp_convert_hr_to_bytes($memory_limit);
    $max_memory_usage = $memory_limit_bytes * 0.8; // 80% of available memory

    error_log("[EIPSI Cron] Memory: {$start_memory} bytes / {$memory_limit} ({$memory_limit_bytes} bytes limit)");

    // Get studies to process
    if ($specific_study_id) {
        // Process only specific study (from manual cron)
        $studies = $wpdb->get_results($wpdb->prepare(
            "SELECT id, study_name, config FROM {$wpdb->prefix}survey_studies WHERE id = %d AND status = 'active'",
            $specific_study_id
        ));
    } else {
        // Get all active studies with reminders enabled (automated cron)
        $studies = $wpdb->get_results(
            "SELECT id, study_name, config FROM {$wpdb->prefix}survey_studies WHERE status = 'active'"
        );
    }

    if (empty($studies)) {
        error_log('[EIPSI Cron] No active studies found');
        return array(
            'processed_studies' => 0,
            'total_emails_queued' => 0,
            'total_emails_sent' => 0
        );
    }

    $total_emails_sent = 0;
    $total_emails_queued = 0;  // v2.5.0 - Jobs encolados para procesamiento asíncrono
    $studies_processed = 0;
    $aborted_due_to_time = false;

    foreach ($studies as $study) {
        // Check time limit before processing each study
        $elapsed = microtime(true) - $start_time;
        if ($elapsed > $max_execution_time) {
            error_log("[EIPSI Cron] TIMEOUT WARNING: {$elapsed}s elapsed, aborting gracefully. Processed {$emails_processed} emails.");
            $aborted_due_to_time = true;
            break;
        }

        // Check memory usage
        $current_memory = memory_get_usage(true);
        if ($current_memory > $max_memory_usage) {
            error_log("[EIPSI Cron] MEMORY WARNING: {$current_memory} bytes used, aborting gracefully.");
            $aborted_due_to_time = true; // Actually memory, but same handling
            break;
        }
        $studies_processed++;

        // Guard against null config (json_decode(null) is deprecated in PHP 8.1+)
        $config = !empty($study->config) ? json_decode($study->config, true) : array();
        if (!is_array($config)) {
            $config = array();
        }

        // Nudge 0 (available) always sends regardless of reminders_enabled setting
        // Nudges 1-4 depend on reminders_enabled configuration
        $reminders_enabled = !empty($config['reminders_enabled']);

        $today = current_time('Y-m-d');
        $max_emails = isset($config['max_reminder_emails']) ? intval($config['max_reminder_emails']) : 100;

        // Get pending assignments that need reminders
        // New logic: available_date = (last_submission_date OR participant_created_at) + interval (days/minutes)
        $emails_sent = 0;

        // FIXED: Get pending assignments where the wave is now available
        // Available date = last submission date + interval (with correct time_unit)
        $now = current_time('Y-m-d H:i:s');
        $now_date = current_time('Y-m-d');

        error_log("[EIPSI Cron] Processing study {$study->id} - Now: {$now}");

        // DEBUG: First check all pending assignments for this study
        $all_pending = $wpdb->get_results($wpdb->prepare(
            "SELECT a.id, a.wave_id, a.participant_id, a.status, a.reminder_count,
                    w.time_unit, w.interval_days,
                    p.created_at as participant_created
             FROM {$wpdb->prefix}survey_assignments a
             JOIN {$wpdb->prefix}survey_waves w ON a.wave_id = w.id
             JOIN {$wpdb->prefix}survey_participants p ON a.participant_id = p.id
             WHERE a.study_id = %d
             AND a.status = 'pending'
             AND p.is_active = 1",
            $study->id
        ));
        error_log("[EIPSI Cron] Total pending assignments: " . count($all_pending));
        foreach ($all_pending as $pend) {
            error_log("[EIPSI Cron] Pending: assignment_id={$pend->id}, wave_id={$pend->wave_id}, time_unit={$pend->time_unit}, interval={$pend->interval_days}, reminder_count={$pend->reminder_count}");
        }

        // v2.2.0: Get pending assignments for follow-up nudges (stages 1-4)
        // This cron NOW ALSO handles Nudge 0 when wave becomes available after interval
        // Nudge 0: reminder_count = 0 AND wave is now available (based on interval)
        // Nudges 1-4: reminder_count >= 1 AND < 5

        // FIRST: Handle Nudge 0 - Initial availability emails
        // v2.3.0: Nudge 0 is ALWAYS enabled (immediate availability email)
        // Get pending assignments where reminder_count = 0 AND wave is now available
        $nudge_zero_assignments = $wpdb->get_results($wpdb->prepare(
            "SELECT a.*, w.name as wave_name, w.wave_index, w.due_date, w.follow_up_reminders_enabled,
             w.time_unit, w.interval_days, w.nudge_config,
             p.email, p.first_name, p.last_name, p.id as participant_id,
             (SELECT submitted_at FROM {$wpdb->prefix}survey_assignments a2
              WHERE a2.participant_id = a.participant_id AND a2.wave_id = w.id - 1 AND a2.status = 'submitted'
              ORDER BY a2.submitted_at DESC LIMIT 1) as last_submission_date
             FROM {$wpdb->prefix}survey_assignments a
             JOIN {$wpdb->prefix}survey_waves w ON a.wave_id = w.id
             JOIN {$wpdb->prefix}survey_participants p ON a.participant_id = p.id
             WHERE a.study_id = %d
             AND a.status = 'pending'
             AND p.is_active = 1
             AND p.email IS NOT NULL
             AND a.reminder_count = 0
             AND (a.available_at IS NULL OR a.available_at <= NOW())
             HAVING last_submission_date IS NOT NULL
             ORDER BY a.id DESC
             LIMIT %d",
            $study->id,
            $max_emails
        ));

        // Filter Nudge 0 assignments to only those where wave is NOW available
        error_log("[EIPSI Cron] NUDGE 0 DIAGNOSTIC: Found " . count($nudge_zero_assignments) . " pending assignments with reminder_count=0");
        $available_now_assignments = array();
        $now = current_time('timestamp');
        error_log("[EIPSI Cron] NUDGE 0 FILTER: Current time (timestamp)={$now}, formatted=" . date('Y-m-d H:i:s', $now));
        foreach ($nudge_zero_assignments as $assignment) {
            error_log("[EIPSI Cron] NUDGE 0 CHECK: assignment_id={$assignment->id}, participant_id={$assignment->participant_id}, wave_id={$assignment->wave_id}, wave_name={$assignment->wave_name}, last_submission_date={$assignment->last_submission_date}, interval_days={$assignment->interval_days}, time_unit={$assignment->time_unit}, available_at_db={$assignment->available_at}");

            $available_at=EIPSI_Longitudinal_Notification_Context_Service::resolve_legacy_availability($assignment);
            error_log("[EIPSI Cron] NUDGE 0 EVAL: assignment_id={$assignment->id}, available_at_timestamp={$available_at}, now={$now}, condition_met=" . ($available_at && $now >= $available_at ? 'YES' : 'NO'));
            if ($available_at && $now >= $available_at) {
                $available_now_assignments[] = $assignment;
                error_log("[EIPSI Cron] Nudge 0 READY: participant={$assignment->participant_id}, wave={$assignment->wave_name}, available_at=" . date('Y-m-d H:i:s', $available_at));

                // v2.5.0 - Trigger event-driven scheduling for follow-up nudges
                error_log("[EIPSI Cron] NUDGE 0 TRIGGER: Firing eipsi_wave_available action for assignment_id={$assignment->id}");
                do_action('eipsi_wave_available', $assignment->id);
                error_log("[EIPSI Cron] NUDGE 0 TRIGGER: eipsi_wave_available action completed for assignment_id={$assignment->id}");
            } else {
                error_log("[EIPSI Cron] NUDGE 0 BLOCKED: assignment_id={$assignment->id}, reason=NOT_YET_AVAILABLE, available_at=" . ($available_at ? date('Y-m-d H:i:s', $available_at) : 'N/A') . ", now=" . date('Y-m-d H:i:s', $now));
            }
        }

        error_log("[EIPSI Cron] Nudge 0 assignments ready for email: " . count($available_now_assignments));

        // SECOND: Handle Nudges 1-4 - Follow-up reminders
        $pending_assignments = $wpdb->get_results($wpdb->prepare(
            "SELECT a.*, w.name as wave_name, w.wave_index, w.due_date, w.follow_up_reminders_enabled,
             w.nudge_config,
             p.email, p.first_name, p.last_name, p.id as participant_id,
             (SELECT submitted_at FROM {$wpdb->prefix}survey_assignments a2
              WHERE a2.participant_id = a.participant_id AND a2.wave_id = w.id - 1 AND a2.status = 'submitted'
              ORDER BY a2.submitted_at DESC LIMIT 1) as last_submission_date
             FROM {$wpdb->prefix}survey_assignments a
             JOIN {$wpdb->prefix}survey_waves w ON a.wave_id = w.id
             JOIN {$wpdb->prefix}survey_participants p ON a.participant_id = p.id
             WHERE a.study_id = %d
             AND a.status = 'pending'
             AND p.is_active = 1
             AND p.email IS NOT NULL
             AND a.reminder_count >= 1
             AND a.reminder_count < 5
             AND w.follow_up_reminders_enabled = 1
             ORDER BY a.id DESC
             LIMIT %d",
            $study->id,
            $max_emails
        ));

        error_log("[EIPSI Cron] Assignments ready for email: " . count($pending_assignments));

        // Combine Nudge 0 (initial availability) with Nudges 1-4 (follow-up reminders)
        $all_assignments_to_process = array_merge($available_now_assignments, $pending_assignments);

        if (empty($all_assignments_to_process)) {
            error_log("[EIPSI Cron] No pending assignments ready for email");
            continue;
        }

        // Load required services
        if (!class_exists('EIPSI_Email_Service')) {
            require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-email-service.php';
        }
        if (!class_exists('EIPSI_Assignment_Service')) {
            require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-assignment-service.php';
        }
        if (!class_exists('EIPSI_Nudge_Service')) {
            require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/services/class-nudge-service.php';
        }

        foreach ($all_assignments_to_process as $assignment) {
            // BATCH LIMITING: Check if we've reached max emails for this run
            if ($emails_processed >= $batch_size) {
                error_log("[EIPSI Cron] BATCH LIMIT REACHED: {$emails_processed} emails processed. Stopping gracefully.");
                $aborted_due_to_time = true; // Actually batch limit, but same handling
                break 2; // Break out of both loops
            }

            // TIME CHECK: Check time limit every 3 emails
            if ($emails_processed % 3 === 0) {
                $elapsed = microtime(true) - $start_time;
                if ($elapsed > $max_execution_time) {
                    error_log("[EIPSI Cron] TIMEOUT WARNING: {$elapsed}s elapsed after {$emails_processed} emails. Stopping.");
                    $aborted_due_to_time = true;
                    break 2; // Break out of both loops
                }
            }

            $current_stage = (int) $assignment->reminder_count;
            $has_due_date = !empty($assignment->due_date);

            // FIX 3: Logging completo para diagnóstico
            error_log(sprintf(
                '[EIPSI NUDGE] Evaluando assignment %d | wave %d | participante %d | status: %s | reminder_count: %d | available_at: %s | due_date: %s | follow_up_enabled: %s',
                $assignment->id,
                $assignment->wave_id,
                $assignment->participant_id,
                $assignment->status,
                $assignment->reminder_count,
                $assignment->available_at ?? 'NULL',
                $assignment->due_date ?? 'NULL',
                $assignment->follow_up_reminders_enabled ? 'SÍ' : 'NO'
            ));

            // Verificar status
            if ($assignment->status !== 'pending') {
                error_log("[EIPSI NUDGE] SKIP: status no es pending ({$assignment->status}) para assignment {$assignment->id}");
                continue;
            }

            error_log("[EIPSI Cron] Processing assignment {$assignment->id}, participant {$assignment->participant_id}, wave {$assignment->wave_id}, stage {$current_stage}");

            // v2.3.0 - Load nudge config from wave's nudge_config JSON column
            $custom_config = null;
            if (!empty($assignment->nudge_config)) {
                $custom_config = json_decode($assignment->nudge_config, true);
            }

            // Check if stage is enabled in custom config
            $nudge_key = "nudge_{$current_stage}";
            if ($custom_config && isset($custom_config[$nudge_key])) {
                if (empty($custom_config[$nudge_key]['enabled'])) {
                    error_log("[EIPSI NUDGE] SKIP: nudge_{$current_stage} desactivado en config de wave {$assignment->wave_id} (participante {$assignment->participant_id})");
                    continue;
                }
            } elseif ($current_stage > 0) {
                // FIX 3: Log cuando no hay config personalizada para nudges 1-4
                error_log("[EIPSI NUDGE] SKIP: nudge_{$current_stage} no tiene config personalizada para wave {$assignment->wave_id} (participante {$assignment->participant_id})");
                continue;
            }

            // Check if we should send this nudge now
            $should_send = EIPSI_Nudge_Service::should_send_nudge($assignment, (object) $assignment, $current_stage, $custom_config);
            error_log("[EIPSI Cron] should_send_nudge result for stage {$current_stage}: " . ($should_send ? 'YES' : 'NO'));
            if (!$should_send) {
                // FIX 3: Log detallado de por qué no se envía
                $now = current_time('timestamp');
                $available_ts = !empty($assignment->available_at) ? strtotime($assignment->available_at) : 0;
                error_log("[EIPSI NUDGE] SKIP: nudge_{$current_stage} aún no vence. Now: " . date('Y-m-d H:i:s', $now) . " | available_at: " . ($available_ts ? date('Y-m-d H:i:s', $available_ts) : 'N/A') . " (participante {$assignment->participant_id})");
                continue;
            }

            // v2.2.0 - For Nudge 0, use robust Wave Availability Email Service
            if ($current_stage === 0) {
                // v2.5.4 - FIX: Solo encolar en Job Queue, NO enviar directamente
                // El envío directo + encolado causaba emails duplicados
                $job_id = EIPSI_Nudge_Job_Queue::enqueue('send_nudge_0', [
                    'assignment_id' => $assignment->id,
                    'participant_id' => $assignment->participant_id,
                    'wave_id' => $assignment->wave_id,
                    'study_id' => $study->id
                ], 5); // Prioridad 5 (alta) para Nudge 0

                if ($job_id) {
                    error_log("[EIPSI Cron] Nudge 0 encolado: job_id={$job_id}, participant={$assignment->participant_id}");
                    $total_emails_queued++;
                } else {
                    error_log("[EIPSI Cron] ERROR: Fallo al encolar Nudge 0 para participant={$assignment->participant_id}");
                }

                continue; // Skip old logic for Nudge 0
            }

            // Nota: Los bloques elseif/else fueron removidos porque $result solo existe dentro del if anterior
            // y el continue hacía que nunca se ejecutaran. Si se necesita manejo de errores del servicio,
            // debe implementarse dentro del bloque if ($current_stage === 0) antes del continue.
        }

        // v2.5.3 - Nudges 1-4 ahora son manejados por Event Scheduler, no por cron handler
        // El cron handler solo procesa Nudge 0 (wave availability)
        if ($current_stage > 0) {
            error_log("[EIPSI Cron] SKIPPED: Nudge {$current_stage} - delegated to Event Scheduler");
            continue;
        }

        // Nudges 1-4 only send if reminders_enabled is set
        if (!$reminders_enabled) {
            error_log("[EIPSI Cron] SKIPPED: Nudge {$current_stage} - reminders disabled for study {$study->id}");
            continue;
        }

        // Check rate limiting - max 1 email per participant per wave per 24 hours
        $rate_limit_key = "eipsi_reminder_{$assignment->participant_id}_{$assignment->wave_id}_{$current_stage}";
        $is_rate_limited = get_transient($rate_limit_key);
        error_log("[EIPSI Cron] Rate limit check: key={$rate_limit_key}, limited=" . ($is_rate_limited ? 'YES' : 'NO'));
        if ($is_rate_limited) {
            error_log("[EIPSI Cron] SKIPPED: Rate limited for participant {$assignment->participant_id}, wave {$assignment->wave_id}, nudge {$current_stage}");
            continue;
        }

        // v2.5.0 - Encolar en Job Queue
        $job_type = "send_nudge_{$current_stage}";
        $job_id = EIPSI_Nudge_Job_Queue::enqueue($job_type, [
            'assignment_id' => $assignment->id,
            'participant_id' => $assignment->participant_id,
            'wave_id' => $assignment->wave_id,
            'study_id' => $study->id,
            'stage' => $current_stage
        ], 10); // Prioridad 10 (normal) para follow-ups

        if ($job_id) {
            error_log(sprintf(
                '[EIPSI Cron] Nudge %d encolado: job_id=%d, participant=%d, wave=%d',
                $current_stage,
                $job_id,
                $assignment->participant_id,
                $assignment->wave_id
            ));
            $total_emails_queued++;

            // Set rate limit para prevenir duplicados mientras el job está en cola
            set_transient($rate_limit_key, true, DAY_IN_SECONDS);
        } else {
            error_log(sprintf(
                '[EIPSI Cron] ERROR: Fallo al encolar Nudge %d para participant=%d',
                $current_stage,
                $assignment->participant_id
            ));
        }
    } // end foreach

    // Calculate execution stats
    $end_time = microtime(true);
    $execution_time = round($end_time - $start_time, 3);
    $end_memory = memory_get_usage(true);
    $memory_used = round(($end_memory - $start_memory) / 1024 / 1024, 2); // MB

    error_log("[EIPSI Cron] ========== RESUMEN EJECUCIÓN v2.5.0 (Job Queue) ==========");
    error_log("[EIPSI Cron] Estudios procesados: {$studies_processed} / " . count($studies));
    error_log("[EIPSI Cron] Jobs encolados (Nudge 0 + otros): {$total_emails_queued}");
    error_log("[EIPSI Cron] Total emails enviados (Nudge 0 + otros): {$total_emails_sent}");
    error_log("[EIPSI Cron] Emails procesados en batch: {$emails_processed} / {$batch_size}");
    error_log("[EIPSI Cron] Tiempo de ejecución: {$execution_time}s / {$max_execution_time}s");
    error_log("[EIPSI Cron] Memoria usada: {$memory_used} MB");
    error_log("[EIPSI Cron] Estado: " . ($aborted_due_to_time ? 'ABORTADO (timeout/batch limit)' : 'COMPLETADO'));
    error_log("[EIPSI Cron] Verificar en base de datos: SELECT * FROM wp_survey_assignments WHERE reminder_count = 0 AND status = 'pending'");
    error_log("[EIPSI Cron] ==================================");
    error_log('[EIPSI Cron] Completed hourly wave reminders' . ($specific_study_id ? " for study {$specific_study_id}" : ''));

    // RELEASE LOCK - Always release at the end
    delete_transient($lock_key);
    error_log('[EIPSI Cron] Lock released');

    // Return summary for manual cron
    return array(
        'processed_studies' => $studies_processed,
        'total_studies' => count($studies),
        'total_emails_queued' => $total_emails_queued,
        'total_emails_sent' => $total_emails_sent,
        'emails_processed' => $emails_processed,
        'batch_size' => $batch_size,
        'execution_time' => $execution_time,
        'max_execution_time' => $max_execution_time,
        'memory_used_mb' => $memory_used,
        'aborted' => $aborted_due_to_time,
        'status' => $aborted_due_to_time ? 'aborted' : 'completed',
        'message' => $aborted_due_to_time ? 'Stopped gracefully due to time/batch limits. Remaining emails will be processed in next cron run.' : 'All studies processed successfully'
    );
}
}
