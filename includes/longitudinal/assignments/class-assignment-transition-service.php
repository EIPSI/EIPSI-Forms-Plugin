<?php
/** M4 longitudinal owner. Existing public adapters preserve their contracts. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Longitudinal_Assignment_Transition_Service {
public static function update_assignment_status($wave_id, $participant_id, $status) {
        global $wpdb;

        $allowed_statuses = array('pending', 'in_progress', 'submitted', 'skipped', 'expired');

        $status = sanitize_text_field($status);
        if (!in_array($status, $allowed_statuses, true)) {
            return new WP_Error('invalid_status', 'Invalid status');
        }

        $wave_id = absint($wave_id);
        $participant_id = absint($participant_id);

        // Obtener estado anterior para logging
        $old_status = $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$wpdb->prefix}survey_assignments WHERE wave_id = %d AND participant_id = %d",
            $wave_id,
            $participant_id
        ));

        $data = array('status' => $status);
        $formats = array('%s');

        if ($status === 'submitted') {
            $data['submitted_at'] = current_time('mysql');
            $formats[] = '%s';
        }

        if ($status === 'in_progress') {
            $first_viewed_at = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT first_viewed_at FROM {$wpdb->prefix}survey_assignments WHERE wave_id = %d AND participant_id = %d",
                    $wave_id,
                    $participant_id
                )
            );

            if (empty($first_viewed_at)) {
                $data['first_viewed_at'] = current_time('mysql');
                $formats[] = '%s';
            }
        }

        $updated = $wpdb->update(
            $wpdb->prefix . 'survey_assignments',
            $data,
            array(
                'wave_id' => $wave_id,
                'participant_id' => $participant_id,
            ),
            $formats,
            array('%d', '%d')
        );

        if ($updated === false) {
            return new WP_Error('db_error', 'Failed to update assignment: ' . $wpdb->last_error);
        }

        // Log cambio de estado si hubo update
        if ($updated > 0 && $old_status !== $status) {
            // Obtener assignment_id y study_id para logging
            $assignment = $wpdb->get_row($wpdb->prepare(
                "SELECT id, study_id FROM {$wpdb->prefix}survey_assignments WHERE wave_id = %d AND participant_id = %d",
                $wave_id,
                $participant_id
            ));

            if ($assignment && class_exists('EIPSI_Assignment_State_Logger')) {
                EIPSI_Assignment_State_Logger::log_assignment_change($assignment->id, $old_status, $status);
            }
        }

        return true;
    }

public static function extend_wave_deadline($assignment_id, $days = 7) {
        global $wpdb;

        $assignment_id = absint($assignment_id);
        $days = absint($days);

        if (!$assignment_id || $days < 1) {
            return new WP_Error('invalid_params', 'Invalid assignment_id or days');
        }

        // Get current due_at
        $current_due = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT due_at FROM {$wpdb->prefix}survey_assignments WHERE id = %d",
                $assignment_id
            )
        );

        if (!$current_due) {
            return new WP_Error('assignment_not_found', 'Assignment not found');
        }

        // Calculate new due date
        $new_due_date = date('Y-m-d H:i:s', strtotime($current_due . " +{$days} days"));

        // Update
        $updated = $wpdb->update(
            $wpdb->prefix . 'survey_assignments',
            array('due_at' => $new_due_date),
            array('id' => $assignment_id),
            array('%s'),
            array('%d')
        );

        if ($updated === false) {
            return new WP_Error('db_error', 'Failed to extend deadline: ' . $wpdb->last_error);
        }

        return true;
    }

public static function mark_wave_completed($assignment_id) {
        global $wpdb;

        $assignment_id = absint($assignment_id);

        if (!$assignment_id) {
            return new WP_Error('invalid_params', 'Invalid assignment_id');
        }

        // Get wave_id and participant_id first
        $assignment = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT wave_id, participant_id FROM {$wpdb->prefix}survey_assignments WHERE id = %d",
                $assignment_id
            ),
            ARRAY_A
        );

        if (!$assignment) {
            return new WP_Error('assignment_not_found', 'Assignment not found');
        }

        // Update to submitted
        $updated = $wpdb->update(
            $wpdb->prefix . 'survey_assignments',
            array(
                'status' => 'submitted',
                'submitted_at' => current_time('mysql')
            ),
            array('id' => $assignment_id),
            array('%s', '%s'),
            array('%d')
        );

        if ($updated === false) {
            return new WP_Error('db_error', 'Failed to mark completed: ' . $wpdb->last_error);
        }

        return true;
    }

public static function mark_assignment_submitted($participant_id, $study_id, $wave_id) {
        global $wpdb;

        if (!$participant_id || !$study_id || !$wave_id) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[Wave_Service] mark_assignment_submitted: Parámetros inválidos');
            }
            return false;
        }

        $table = $wpdb->prefix . 'survey_assignments';

        // Obtener estado anterior para logging
        $old_status = $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$table} WHERE participant_id = %d AND study_id = %d AND wave_id = %d",
            $participant_id,
            $study_id,
            $wave_id
        ));

        $result = $wpdb->update(
            $table,
            array(
                'status'       => 'submitted',
                'submitted_at' => current_time( 'mysql' ),
                'updated_at'   => current_time( 'mysql' )
            ),
            array(
                'participant_id' => $participant_id,
                'study_id'       => $study_id,
                'wave_id'        => $wave_id
            ),
            array( '%s', '%s', '%s' ), // format for values
            array( '%d', '%d', '%d' )  // format for where
        );

        if ($result === false) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf(
                    '[Wave_Service] Error al actualizar assignment: participant_id=%d, study_id=%d, wave_id=%d - Error: %s',
                    $participant_id,
                    $study_id,
                    $wave_id,
                    $wpdb->last_error
                ));
            }
            return false;
        }

        // Si result = 0, puede significar que no existe o ya estaba en 'submitted'
        // Log informativo
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[Wave_Service] Assignment marcado como submitted: participant_id=%d, study_id=%d, wave_id=%d (affected rows: %d)',
                $participant_id,
                $study_id,
                $wave_id,
                $result
            ));
        }

        // v2.5.0 - Cancelar eventos programados y jobs en cola para este assignment
        if ($result > 0) {
            // Obtener el assignment_id
            $assignment = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}survey_assignments
                 WHERE participant_id = %d AND study_id = %d AND wave_id = %d",
                $participant_id,
                $study_id,
                $wave_id
            ));

            if ($assignment) {
                // Log cambio de estado
                if (class_exists('EIPSI_Assignment_State_Logger')) {
                    EIPSI_Assignment_State_Logger::log_assignment_change($assignment->id, $old_status, 'submitted');
                }
                // Cancelar eventos programados
                if (class_exists('EIPSI_Nudge_Event_Scheduler')) {
                    EIPSI_Nudge_Event_Scheduler::cancel_scheduled_nudges($assignment->id);
                }

                // Cancelar jobs pendientes en la cola
                if (class_exists('EIPSI_Nudge_Job_Queue')) {
                    EIPSI_Nudge_Job_Queue::cancel_jobs_for_assignment($assignment->id);
                }

                // Invalidar cache
                if (class_exists('EIPSI_Nudge_Cache')) {
                    EIPSI_Nudge_Cache::invalidate_assignment_cache($assignment->id);
                }

                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log(sprintf(
                        '[Wave_Service] Cancelados eventos y jobs para assignment_id=%d',
                        $assignment->id
                    ));
                }
            }
        }

        // v2.1.3: Trigger immediate email for minute-based intervals
        // If next wave has minutes interval and is immediately available, send email now
        if ($result !== false) {
            Wave_Service::notify_submission($participant_id, $study_id, $wave_id);
        }

        return true; // Devolvemos true aunque result sea 0 (ya estaba submitted)
    }

public static function change_snapshot($assignment_id, $old_status, $new_status, $extra = array()) {
    global $wpdb;
    // Atomic compare-and-set: stale cron snapshots cannot overwrite a completed transition.
    $data=array_merge(array('status'=>$new_status),$extra);
    return $wpdb->update($wpdb->prefix.'survey_assignments',$data,
        array('id'=>$assignment_id,'status'=>$old_status),
        array_fill(0,count($data),'%s'),array('%d','%s'));
}
public static function expire_snapshot($assignment, $timestamp = false) {
    return self::change_snapshot($assignment->id,$assignment->status,'expired',
        $timestamp ? array('updated_at'=>current_time('mysql')) : array());
}
public static function force_t1_submitted($wave_id,$participant_id,$timestamp) {
    global $wpdb;
    return $wpdb->update($wpdb->prefix.'survey_assignments',array('status'=>'submitted','submitted_at'=>$timestamp),
        array('wave_id'=>$wave_id,'participant_id'=>$participant_id),array('%s','%s'),array('%d','%d'));
}

public static function submit_locked($longitudinal_participant_id,$study_id,$wave_id) {
    global $wpdb;
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
                $marked = self::mark_assignment_submitted($longitudinal_participant_id, $study_id, $wave_id);

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
                return self::failure(array(
                    'message' => __('Error al guardar la respuesta. Por favor, intentá nuevamente.', 'eipsi-forms'),
                    'error' => $e->getMessage()
                ), 500);
                return; // Detener ejecución
            }


    return $is_t1;
}

public static function failure($data,$status) { return array('success'=>false,'data'=>$data,'status'=>$status); }

public static function manual_expire($assignment_id) {
        global $wpdb;

        $assignment_id = absint($assignment_id);
        if (!$assignment_id) {
            return new WP_Error('invalid_id', 'Invalid assignment ID');
        }

        // Get assignment details
        $assignment = $wpdb->get_row($wpdb->prepare(
            "SELECT a.*, w.wave_index, w.name as wave_name
             FROM {$wpdb->prefix}survey_assignments a
             JOIN {$wpdb->prefix}survey_waves w ON a.wave_id = w.id
             WHERE a.id = %d",
            $assignment_id
        ));

        if (!$assignment) {
            return new WP_Error('not_found', 'Assignment not found');
        }

        // Don't expire already submitted assignments
        if ($assignment->status === 'submitted') {
            return new WP_Error('already_submitted', 'Cannot expire a submitted assignment');
        }

        // Update status
        $updated = $wpdb->update(
            $wpdb->prefix . 'survey_assignments',
            array(
                'status' => 'expired',
                'updated_at' => current_time('mysql'),
            ),
            array('id' => $assignment_id),
            array('%s', '%s'),
            array('%d')
        );

        if ($updated === false) {
            return new WP_Error('db_error', 'Failed to update assignment: ' . $wpdb->last_error);
        }

        // Cancel nudges
        EIPSI_Longitudinal_Assignment_Expiration_Service::cancel_pending_nudges($assignment->participant_id, $assignment->wave_id);

        // Log with admin actor
        $audit_table = $wpdb->prefix . 'survey_audit_log';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$audit_table}'") === $audit_table) {
            $wpdb->insert(
                $audit_table,
                array(
                    'survey_id' => $assignment->study_id,
                    'participant_id' => $assignment->participant_id,
                    'action' => 'wave_expired_manual',
                    'actor_type' => 'admin',
                    'actor_id' => get_current_user_id(),
                    'metadata' => wp_json_encode(array(
                        'assignment_id' => $assignment_id,
                        'wave_id' => $assignment->wave_id,
                        'wave_index' => $assignment->wave_index,
                        'wave_name' => $assignment->wave_name,
                    )),
                    'created_at' => current_time('mysql'),
                ),
                array('%d', '%d', '%s', '%s', '%d', '%s', '%s')
            );
        }

        do_action('eipsi_wave_expired', $assignment_id, $assignment->participant_id, $assignment->wave_id);

        return true;
    }
public static function skip_locked($a) {
    global $wpdb;
    $skippable_statuses=array('pending','in_progress');
            $wpdb->query('START TRANSACTION');

            try {
                // Lock the assignment row
                $locked = $wpdb->get_row($wpdb->prepare(
                    "SELECT id, status FROM {$wpdb->prefix}survey_assignments
                     WHERE id = %d FOR UPDATE",
                    $a->id
                ));

                if (!$locked) {
                    throw new Exception("Assignment {$a->id} not found");
                }

                // Double-check status (may have changed since initial query)
                if (!in_array($locked->status, $skippable_statuses)) {
                    error_log("[EIPSI Wave Skipping] Assignment {$a->id} status changed to '{$locked->status}', skipping");
                    $wpdb->query('ROLLBACK');
                    return false;
                }

                // Mark as skipped
                $updated = self::change_snapshot($a->id, $locked->status, 'skipped');

                if ($updated === false) {
                    throw new Exception("Failed to update assignment {$a->id}: " . $wpdb->last_error);
                }

                // Log cambio de estado
                if ($updated > 0 && class_exists('EIPSI_Assignment_State_Logger')) {
                    EIPSI_Assignment_State_Logger::log_assignment_change($a->id, $locked->status, 'skipped');
                }

                // Cancel pending nudges for this assignment
                if (class_exists('EIPSI_Nudge_Event_Scheduler')) {
                    EIPSI_Nudge_Event_Scheduler::cancel_nudges_for_assignment($a->id);
                }

                $wpdb->query('COMMIT');

                error_log(sprintf(
                    '[EIPSI Wave Skipping] ✓ SKIPPED: Wave %d (%s) for participant %d (assignment %d) - Nudges cancelled',
                    $a->wave_index, $a->wave_name, $a->participant_id, $a->id
                ));

                return true;

            } catch (Exception $e) {
                $wpdb->query('ROLLBACK');
                error_log("[EIPSI Wave Skipping] Error skipping assignment {$a->id}: " . $e->getMessage());
            }
    return false;
}
public static function eipsi_expire_t1_assignment($assignment) {
    global $wpdb;

    // Update assignment status
    $updated = $wpdb->update(
        $wpdb->prefix . 'survey_assignments',
        array('status' => 'expired'),
        array('id' => $assignment->assignment_id),
        array('%s'),
        array('%d')
    );

    if ($updated === false) {
        error_log("[EIPSI Weekly T1] Failed to expire assignment {$assignment->assignment_id}");
        return;
    }

    // Cancel pending nudges
    if (class_exists('EIPSI_Nudge_Event_Scheduler')) {
        EIPSI_Nudge_Event_Scheduler::cancel_nudges_for_assignment($assignment->assignment_id);
    }

    // TODO: Send expiration notification email (optional)
    // Could use a template like 'study-expired.php'

    error_log(sprintf(
        '[EIPSI Weekly T1] Expired assignment %d for participant %d',
        $assignment->assignment_id, $assignment->participant_id
    ));
}
}
