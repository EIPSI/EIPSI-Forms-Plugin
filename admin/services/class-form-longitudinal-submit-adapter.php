<?php
/** M3 owner; external contracts remain behind their existing facades. */
if (!defined('ABSPATH')) { exit; }

/** Legacy M4 boundary: original transaction/T1/next-wave/notification calls, not Forms rules. */
class EIPSI_Form_Longitudinal_Submit_Adapter {
    public static function after_persistence($wave_id, $study_id, $longitudinal_participant_id, $stable_form_id, $submitted_at, $user_data) {
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
            $is_t1 = false; // Inicializar antes del try para scope externo

            $wpdb->query('START TRANSACTION');

            try {
                // 1. LOCK y verificar status del assignment (prevenir race condition con wave skipping)
                $assignment = $wpdb->get_row($wpdb->prepare(
                    "SELECT id, status FROM {$wpdb->prefix}survey_assignments
                     WHERE participant_id = %d AND study_id = %d AND wave_id = %d
                     FOR UPDATE",
                    $longitudinal_participant_id, $study_id, $wave_id
                ));

                if (!$assignment) {
                    throw new Exception('Assignment not found');
                }

                // Validar que el status permita submit
                $allowed_statuses = array('pending', 'in_progress');
                if (!in_array($assignment->status, $allowed_statuses)) {
                    throw new Exception("Cannot submit assignment with status '{$assignment->status}'. This wave may have been skipped or expired.");
                }

                error_log("[EIPSI-DIAG] Assignment status validated: {$assignment->status} (allowed for submit)");

                // 2. Marcar assignment como submitted
                $marked = Wave_Service::mark_assignment_submitted($longitudinal_participant_id, $study_id, $wave_id);

                if (!$marked) {
                    throw new Exception('Failed to mark assignment as submitted');
                }

                error_log('[EIPSI-DIAG] Resultado mark_assignment_submitted: ÉXITO');

                // 2. Si es T1, actualizar t1_completed_at (dentro de la misma transacción)
                $wave_info = $wpdb->get_row($wpdb->prepare(
                    "SELECT wave_index FROM {$wpdb->prefix}survey_waves WHERE id = %d",
                    $wave_id
                ));

                $is_t1 = ($wave_info && $wave_info->wave_index == 1);

                if ($is_t1) {
                    error_log("[EIPSI T1-Anchor] T1 detected, updating t1_completed_at");

                    $t1_updated = $wpdb->update(
                        $wpdb->prefix . 'survey_assignments',
                        array('t1_completed_at' => current_time('mysql')),
                        array(
                            'participant_id' => $longitudinal_participant_id,
                            'wave_id' => $wave_id
                        ),
                        array('%s'),
                        array('%d', '%d')
                    );

                    if ($t1_updated === false) {
                        throw new Exception('Failed to update t1_completed_at: ' . $wpdb->last_error);
                    }

                    error_log("[EIPSI T1-Anchor] t1_completed_at updated successfully");
                }

                // COMMIT: submit + t1_completed_at están guardados atómicamente
                $wpdb->query('COMMIT');
                error_log('[EIPSI T1-Anchor] Transaction committed successfully');

            } catch (Exception $e) {
                $wpdb->query('ROLLBACK');
                error_log("[EIPSI T1-Anchor] CRITICAL: Transaction failed, rolled back: " . $e->getMessage());

                // Retornar error al frontend - el participante debe reintentar
                return EIPSI_Form_Response::error(array(
                    'message' => __('Error al guardar la respuesta. Por favor, intentá nuevamente.', 'eipsi-forms'),
                    'error' => $e->getMessage()
                ), 500);
                return; // Detener ejecución
            }

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

                // v2.2.2 - TRIGGER INMEDIATO: Enviar Nudge 0 ahora si la siguiente toma YA está disponible
                // (evita esperar al cron hourly cuando interval=0 o el tiempo ya pasó)
                $available_timestamp = intval($available_at);
                $current_timestamp = current_time('timestamp');
                if ($available_timestamp <= $current_timestamp) {
                    error_log(sprintf('[EIPSI-DIAG] NEXT WAVE AVAILABLE NOW: wave_id=%d, available_at=%s, current=%s - Triggering immediate Nudge 0 email',
                        $next_wave['wave_id'], date('Y-m-d H:i:s', $available_timestamp), date('Y-m-d H:i:s', $current_timestamp)));

                    // Asegurar que la clase esté cargada
                    if (!class_exists('EIPSI_Wave_Availability_Email_Service')) {
                        require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-wave-availability-email-service.php';
                    }

                    if (class_exists('EIPSI_Wave_Availability_Email_Service')) {
                        // Cargar dependencias necesarias para obtener los objetos (v2.2.3 Fix)
                        if (!class_exists('EIPSI_Wave_Service')) {
                            require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-wave-service.php';
                        }
                        if (!class_exists('EIPSI_Participant_Service')) {
                            require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-participant-service.php';
                        }

                        // Obtener objetos requeridos por el servicio
                        global $wpdb;
                        $assignment_obj = $wpdb->get_row($wpdb->prepare(
                            "SELECT * FROM {$wpdb->prefix}survey_assignments WHERE wave_id = %d AND participant_id = %d",
                            $next_wave['wave_id'],
                            $longitudinal_participant_id
                        ), OBJECT);

                        $wave_obj = EIPSI_Wave_Service::get_wave($next_wave['wave_id']);
                        $participant_obj = EIPSI_Participant_Service::get_by_id($longitudinal_participant_id);

                        // Solo proceder si tenemos todos los datos necesarios
                        if ($assignment_obj && $wave_obj && $participant_obj) {
                            $email_result = EIPSI_Wave_Availability_Email_Service::ensure_wave_availability_email_sent(
                                $assignment_obj,
                                $wave_obj,
                                $participant_obj,
                                $study_id
                            );
                            error_log(sprintf('[EIPSI-DIAG] Immediate Nudge 0 email result: %s', json_encode($email_result)));

                            // Guardar para agregar al success_response después
                            if ($email_result['success'] && $email_result['sent']) {
                                $nudge_0_sent = true;
                                $nudge_0_message = __('Email de siguiente toma enviado inmediatamente', 'eipsi-forms');
                            }
                        } else {
                            error_log(sprintf('[EIPSI-DIAG] Could not trigger immediate Nudge 0: Missing objects. Assignment: %s, Wave: %s, Participant: %s',
                                $assignment_obj ? 'OK' : 'MISSING',
                                $wave_obj ? 'OK' : 'MISSING',
                                $participant_obj ? 'OK' : 'MISSING'));
                        }
                    }
                }
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
