<?php
/** M4 longitudinal owner. Existing public adapters preserve their contracts. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Longitudinal_Submission_Service {
public static function handle_submission($context) {
    $wave_id=$context['wave_id'];
    $study_id=$context['study_id'];
    $longitudinal_participant_id=$context['longitudinal_participant_id'];
    $stable_form_id=$context['stable_form_id'];
    $submitted_at=$context['submitted_at'];
    $user_data=$context['user_data'];

        global $wpdb;
        // === Task 2.4B: Marcar assignment como submitted y obtener próxima toma ===
        $next_wave_data = null;
        $has_next_wave = false;
        $nudge_0_sent = false;
        $nudge_0_message = '';

        // ✅ v1.5.6 - DEBUG: Log para verificar variables de contexto longitudinal
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[EIPSI] Longitudinal context check: wave_id=%s, study_id=%s, longitudinal_participant_id=%s, user_email=%s',
                $wave_id ?: 'NULL',
                $study_id ?: 'NULL',
                $longitudinal_participant_id ?: 'NULL',
                $user_data['email'] ?: 'NULL'
            ));
        }

        // v1.5.6 - Si hay contexto longitudinal (wave_id detectable), actualizar assignment
        // Usar longitudinal_participant_id (INT) y study_id en lugar de participant_id (string) y survey_id

        // ✅ DIAGNÓSTICO: Siempre loguear variables críticas
        error_log(sprintf(
            '[EIPSI-DIAG] Pre-check: wave_id=%s, study_id=%s, longitudinal_participant_id=%s',
            $wave_id ?: 'NULL',
            $study_id ?: 'NULL',
            $longitudinal_participant_id ?: 'NULL'
        ));

        if (!empty($wave_id) && $study_id && $longitudinal_participant_id) {
            // Cargar Wave_Service
            require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/services/Wave_Service.php';

            // ✅ FIX: Si el assignment no existe, crearlo primero (fallback defensivo)
            // Cargar el servicio si no está disponible (verificar función, no clase)
            $func_exists_before = function_exists('eipsi_create_assignments_for_participant');
            error_log('[EIPSI-DIAG] Función eipsi_create_assignments_for_participant existe ANTES: ' . ($func_exists_before ? 'SÍ' : 'NO'));

            if (!$func_exists_before) {
                $assignment_service_path = EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-assignment-service.php';
                $file_exists = file_exists($assignment_service_path);
                error_log('[EIPSI-DIAG] Archivo class-assignment-service.php existe: ' . ($file_exists ? 'SÍ' : 'NO'));
                if ($file_exists) {
                    require_once $assignment_service_path;
                    error_log('[EIPSI-DIAG] Archivo cargado. Función existe DESPUÉS: ' . (function_exists('eipsi_create_assignments_for_participant') ? 'SÍ' : 'NO'));
                }
            }

            // Verificar si existe el assignment
            $existing_assignment = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}survey_assignments
                 WHERE wave_id = %d AND participant_id = %d",
                $wave_id,
                $longitudinal_participant_id
            ));

            error_log(sprintf('[EIPSI-DIAG] Assignment existente para wave_id=%d, participant_id=%d: %s',
                $wave_id, $longitudinal_participant_id, $existing_assignment ?: 'NO ENCONTRADO'
            ));

            // Si no existe, crearlo primero
            if (!$existing_assignment && function_exists('eipsi_create_assignments_for_participant')) {
                error_log(sprintf('[EIPSI-DIAG] Creando assignments para participant_id=%d, study_id=%d',
                    $longitudinal_participant_id, $study_id));
                $create_result = eipsi_create_assignments_for_participant($longitudinal_participant_id, $study_id);
                error_log(sprintf(
                    '[EIPSI-DIAG] Resultado creación: created=%d, skipped=%d, errors=%d',
                    $create_result['created'],
                    $create_result['skipped'],
                    count($create_result['errors'])
                ));
                if (!empty($create_result['errors'])) {
                    error_log('[EIPSI-DIAG] Errores: ' . implode(', ', $create_result['errors']));
                }
            } elseif (!$existing_assignment) {
                error_log('[EIPSI-DIAG] ERROR: Función eipsi_create_assignments_for_participant NO disponible');
            }

            // Marcar assignment como submitted usando study_id (columna correcta en wp_survey_assignments)
            error_log(sprintf('[EIPSI-DIAG] Marcando como submitted: participant_id=%d, study_id=%d, wave_id=%d',
                $longitudinal_participant_id, $study_id, $wave_id));

            // ========== PHASE 5 T1-ANCHOR: TRANSACCIÓN CRÍTICA ==========
            // Submit + t1_completed_at deben ser atómicos
            $transition=EIPSI_Longitudinal_Assignment_Transition_Service::submit_locked($longitudinal_participant_id,$study_id,$wave_id);
            if (is_array($transition)) { return $transition; }
            $is_t1=$transition;

            // ========== POST-COMMIT: Recalcular waves (fuera de transacción) ==========
            if ($is_t1) {
                // Load Wave Recalculator service if not already loaded
                if (!class_exists('EIPSI_Wave_Recalculator')) {
                    require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/services/class-wave-recalculator.php';
                }

                try {
                    error_log("[EIPSI T1-Anchor] Starting wave recalculation (post-commit)");
                    $t1_timestamp = current_time('mysql');
                    $result = EIPSI_Wave_Recalculator::recalculate_after_t1(
                        $longitudinal_participant_id,
                        $study_id,
                        $t1_timestamp,
                        'system'
                    );
                    $affected_count = count($result['affected_waves'] ?? array());
                    error_log("[EIPSI T1-Anchor] Successfully recalculated {$affected_count} waves");
                } catch (Exception $e) {
                    // No revertir el submit - solo loggear el error
                    error_log("[EIPSI T1-Anchor] WARNING: Wave recalculation failed (submit was successful): " . $e->getMessage());
                    // El submit fue exitoso, continuar con el flujo normal
                }
            }

            // ==========================================================================
            // POOL COMPLETION CHECK (v2.5.3)
            // Check if all waves in this study are completed, and if so, mark pool assignment
            // ==========================================================================
            eipsi_check_and_mark_pool_completion($longitudinal_participant_id, $study_id, $stable_form_id);

            // Obtener próxima toma pendiente usando study_id
            $next_wave = Wave_Service::get_next_pending_wave($longitudinal_participant_id, $study_id);

            error_log(sprintf('[EIPSI-DIAG] Próxima wave pendiente: %s', $next_wave ? 'ENCONTRADA (index=' . $next_wave['wave_index'] . ')' : 'NO ENCONTRADA'));

            if ($next_wave) {
                $has_next_wave = true;

                // Obtener configuración de la wave (intervalo y recordatorio)
                $wave_config = $wpdb->get_row($wpdb->prepare(
                    "SELECT offset_minutes, interval_days, reminder_days, time_unit FROM {$wpdb->prefix}survey_waves WHERE id = %d",
                    $next_wave['wave_id']
                ), ARRAY_A);

                // DEBUG: Log raw values from database
                error_log(sprintf('[EIPSI-DIAG] Raw wave_config from DB: wave_id=%d, offset_minutes=%s, interval_days=%s, time_unit=%s',
                    $next_wave['wave_id'],
                    $wave_config['offset_minutes'] ?? 'NULL',
                    $wave_config['interval_days'] ?? 'NULL',
                    $wave_config['time_unit'] ?? 'NULL'
                ));

                // FIX: Ensure time_unit is a valid string value
                $raw_time_unit = $wave_config['time_unit'] ?? 'days';
                $valid_time_units = array('minutes', 'hours', 'days');

                // Map numeric values to strings (if stored as 0, 1, 2)
                $numeric_map = array(
                    '0' => 'minutes',
                    '1' => 'hours',
                    '2' => 'days'
                );

                if (isset($numeric_map[$raw_time_unit])) {
                    $time_unit = $numeric_map[$raw_time_unit];
                } elseif (in_array($raw_time_unit, $valid_time_units)) {
                    $time_unit = $raw_time_unit;
                } else {
                    $time_unit = 'days'; // default
                }

                // Calculate exact available timestamp for countdown
                // Phase 5 T1-Anchor: Use offset_minutes from T1 completion, not interval_days
                $offset_minutes = isset($wave_config['offset_minutes']) ? intval($wave_config['offset_minutes']) : 0;
                $submitted_at = current_time('timestamp');
                $available_at = strtotime("+{$offset_minutes} minutes", $submitted_at);

                // Legacy fallback for old studies using interval_days
                if ($offset_minutes === 0 && isset($wave_config['interval_days'])) {
                    $interval_value = intval($wave_config['interval_days']);
                    switch ($time_unit) {
                        case 'minutes':
                            $available_at = strtotime("+{$interval_value} minutes", $submitted_at);
                            break;
                        case 'hours':
                            $available_at = strtotime("+{$interval_value} hours", $submitted_at);
                            break;
                        case 'days':
                        default:
                            $available_at = strtotime("+{$interval_value} days", $submitted_at);
                            break;
                    }
                }

                $next_wave_data = array(
                    'wave_index' => $next_wave['wave_index'],
                    'due_date' => $next_wave['due_date'],
                    'wave_name' => $next_wave['wave_name'],
                    'offset_minutes' => $offset_minutes,
                    'interval_days' => isset($interval_value) ? $interval_value : 0,
                    'reminder_days' => isset($wave_config['reminder_days']) ? intval($wave_config['reminder_days']) : 0,
                    'time_unit' => $time_unit,
                    'available_at' => $available_at * 1000 // Convert to milliseconds for JS
                );

                error_log(sprintf('[EIPSI-DIAG] Prepared next_wave_data: %s', json_encode($next_wave_data)));

                $notification=EIPSI_Notification_Longitudinal_Adapter::notify_next_wave($next_wave,$study_id,$longitudinal_participant_id,$available_at);
                $nudge_0_sent=$notification['nudge_0_sent'];$nudge_0_message=$notification['nudge_0_message'];

            }
        } else {
            error_log('[EIPSI-DIAG] CONDICIÓN NO CUMPLIDA - No se procesa assignment. Faltan: ' .
                (empty($wave_id) ? 'wave_id ' : '') .
                (!$study_id ? 'study_id ' : '') .
                (!$longitudinal_participant_id ? 'longitudinal_participant_id' : ''));
        }


        return compact('next_wave_data', 'has_next_wave', 'nudge_0_sent', 'nudge_0_message');
    }
}
