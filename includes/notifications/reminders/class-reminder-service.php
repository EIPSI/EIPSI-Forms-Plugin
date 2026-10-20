<?php
/** Notifications owner; public WordPress adapters retain existing contracts. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Notification_Reminder_Service {

public static function eipsi_send_wave_reminders_hourly($specific_study_id = null) {
    EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_reminder_service_L8'), 'debug');

    global $wpdb;

    // =========================================================================
    // PROFESSIONAL CRON OPTIMIZATIONS - v2.4.0
    // =========================================================================

    // 1. PROCESS LOCKING - Prevent concurrent executions
    $lock_key = 'eipsi_cron_wave_reminders_lock';
    $lock_timeout = 5 * MINUTE_IN_SECONDS; // 5 minutes max execution time

    if (get_transient($lock_key)) {
        EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_reminder_service_L21'), 'debug');
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
            EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_reminder_service_L44'), 'debug');
        }
    });

    // FIX 2: SILENT CRON TRACKING - Log cron health for diagnostics (no UI)
    $last_cron = get_option('eipsi_last_cron_run');
    $now_ts = time();
    if ($last_cron) {
        $minutes_since = round(($now_ts - $last_cron) / 60);
        EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_reminder_service_L53'), 'debug');
        if ($minutes_since > 10) {
            EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_reminder_service_L55'), 'debug');
        }
    } else {
        EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_reminder_service_L58'), 'debug');
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

    EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_reminder_service_L75'), 'debug');

    // 3. BATCH LIMITING - Process max emails per run to avoid timeouts
    $batch_size = 5; // Maximum emails to send per cron run
    $emails_processed = 0;

    // 4. MEMORY MONITORING
    $start_memory = memory_get_usage(true);
    $memory_limit = ini_get('memory_limit');
    $memory_limit_bytes = wp_convert_hr_to_bytes($memory_limit);
    $max_memory_usage = $memory_limit_bytes * 0.8; // 80% of available memory

    EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_reminder_service_L87'), 'debug');

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
        EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_reminder_service_L104'), 'debug');
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
            EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_reminder_service_L121'), 'debug');
            $aborted_due_to_time = true;
            break;
        }

        // Check memory usage
        $current_memory = memory_get_usage(true);
        if ($current_memory > $max_memory_usage) {
            EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_reminder_service_L129'), 'debug');
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

        EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_reminder_service_L157'), 'debug');

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
        EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_reminder_service_L172'), 'debug');
        foreach ($all_pending as $pend) {
            EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_reminder_service_L174'), 'debug');
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
        EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_reminder_service_L209'), 'debug');
        $available_now_assignments = array();
        $now = current_time('timestamp');
        EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_reminder_service_L212'), 'debug');
        foreach ($nudge_zero_assignments as $assignment) {
            EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_reminder_service_L214'), 'debug');

            $available_at=EIPSI_Longitudinal_Notification_Context_Service::resolve_legacy_availability($assignment);
            EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_reminder_service_L217'), 'debug');
            if ($available_at && $now >= $available_at) {
                $available_now_assignments[] = $assignment;
                EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_reminder_service_L220'), 'debug');

                // v2.5.0 - Trigger event-driven scheduling for follow-up nudges
                EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_reminder_service_L223'), 'debug');
                do_action('eipsi_wave_available', $assignment->id);
                EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_reminder_service_L225'), 'debug');
            } else {
                EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_reminder_service_L227'), 'debug');
            }
        }

        EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_reminder_service_L231'), 'debug');

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

        EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_reminder_service_L257'), 'debug');

        // Combine Nudge 0 (initial availability) with Nudges 1-4 (follow-up reminders)
        $all_assignments_to_process = array_merge($available_now_assignments, $pending_assignments);

        if (empty($all_assignments_to_process)) {
            EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_reminder_service_L263'), 'debug');
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
                EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_reminder_service_L281'), 'debug');
                $aborted_due_to_time = true; // Actually batch limit, but same handling
                break 2; // Break out of both loops
            }

            // TIME CHECK: Check time limit every 3 emails
            if ($emails_processed % 3 === 0) {
                $elapsed = microtime(true) - $start_time;
                if ($elapsed > $max_execution_time) {
                    EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_reminder_service_L290'), 'debug');
                    $aborted_due_to_time = true;
                    break 2; // Break out of both loops
                }
            }

            $current_stage = (int) $assignment->reminder_count;
            $has_due_date = !empty($assignment->due_date);

            // FIX 3: Logging completo para diagnóstico
            EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_reminder_service_L300'), 'debug');

            // Verificar status
            if ($assignment->status !== 'pending') {
                EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_reminder_service_L314'), 'debug');
                continue;
            }

            EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_reminder_service_L318'), 'debug');

            // v2.3.0 - Load nudge config from wave's nudge_config JSON column
            $custom_config = null;
            if (!empty($assignment->nudge_config)) {
                $custom_config = json_decode($assignment->nudge_config, true);
            }

            // Check if stage is enabled in custom config
            $nudge_key = "nudge_{$current_stage}";
            if ($custom_config && isset($custom_config[$nudge_key])) {
                if (empty($custom_config[$nudge_key]['enabled'])) {
                    EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_reminder_service_L330'), 'debug');
                    continue;
                }
            } elseif ($current_stage > 0) {
                // FIX 3: Log cuando no hay config personalizada para nudges 1-4
                EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_reminder_service_L335'), 'debug');
                continue;
            }

            // Check if we should send this nudge now
            $should_send = EIPSI_Nudge_Service::should_send_nudge($assignment, (object) $assignment, $current_stage, $custom_config);
            EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_reminder_service_L341'), 'debug');
            if (!$should_send) {
                // FIX 3: Log detallado de por qué no se envía
                $now = current_time('timestamp');
                $available_ts = !empty($assignment->available_at) ? strtotime($assignment->available_at) : 0;
                EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_reminder_service_L346'), 'debug');
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
                    EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_reminder_service_L362'), 'debug');
                    $total_emails_queued++;
                } else {
                    EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_reminder_service_L365'), 'debug');
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
            EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_reminder_service_L379'), 'debug');
            continue;
        }

        // Nudges 1-4 only send if reminders_enabled is set
        if (!$reminders_enabled) {
            EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_reminder_service_L385'), 'debug');
            continue;
        }

        // Check rate limiting - max 1 email per participant per wave per 24 hours
        $rate_limit_key = "eipsi_reminder_{$assignment->participant_id}_{$assignment->wave_id}_{$current_stage}";
        $is_rate_limited = get_transient($rate_limit_key);
        EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_reminder_service_L392'), 'debug');
        if ($is_rate_limited) {
            EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_reminder_service_L394'), 'debug');
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
            EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_reminder_service_L409'), 'debug');
            $total_emails_queued++;

            // Set rate limit para prevenir duplicados mientras el job está en cola
            set_transient($rate_limit_key, true, DAY_IN_SECONDS);
        } else {
            EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_reminder_service_L421'), 'debug');
        }
    } // end foreach

    // Calculate execution stats
    $end_time = microtime(true);
    $execution_time = round($end_time - $start_time, 3);
    $end_memory = memory_get_usage(true);
    $memory_used = round(($end_memory - $start_memory) / 1024 / 1024, 2); // MB

    EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_reminder_service_L435'), 'debug');
    EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_reminder_service_L436'), 'debug');
    EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_reminder_service_L437'), 'debug');
    EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_reminder_service_L438'), 'debug');
    EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_reminder_service_L439'), 'debug');
    EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_reminder_service_L440'), 'debug');
    EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_reminder_service_L441'), 'debug');
    EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_reminder_service_L442'), 'debug');
    EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_reminder_service_L443'), 'debug');
    EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_reminder_service_L444'), 'debug');
    EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_reminder_service_L445'), 'debug');

    // RELEASE LOCK - Always release at the end
    delete_transient($lock_key);
    EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_reminder_service_L449'), 'debug');

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
