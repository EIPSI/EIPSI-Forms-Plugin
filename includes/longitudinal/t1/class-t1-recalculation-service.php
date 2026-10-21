<?php
/** M4 longitudinal owner. Existing public adapters preserve their contracts. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Longitudinal_T1_Recalculation_Service {
/** Read-only candidates for the active admin modal, not legacy unguarded writes. */
private static function study_candidates($study_id) {
    global $wpdb;
    if (!$study_id || !EIPSI_Longitudinal_Study_Repository::get($study_id)) {
        return new WP_Error('invalid_study','Estudio no encontrado.');
    }
    $rows=$wpdb->get_results($wpdb->prepare("SELECT a.id,a.wave_id,a.participant_id,
        COALESCE(p.t1_completed_at,(SELECT t.submitted_at FROM {$wpdb->prefix}survey_assignments t
            JOIN {$wpdb->prefix}survey_waves tw ON tw.id=t.wave_id
            WHERE t.participant_id=p.id AND t.study_id=p.survey_id AND tw.study_id=p.survey_id
            AND tw.wave_index=1 AND t.status='submitted' LIMIT 1)) AS anchor
        FROM {$wpdb->prefix}survey_assignments a
        JOIN {$wpdb->prefix}survey_participants p ON p.id=a.participant_id AND p.survey_id=a.study_id
        JOIN {$wpdb->prefix}survey_waves w ON w.id=a.wave_id AND w.study_id=a.study_id
        WHERE a.study_id=%d AND p.is_active=1 AND a.status IN ('pending','in_progress') AND w.wave_index>1
        ORDER BY a.id",$study_id));
    if ($wpdb->last_error) { return new WP_Error('db_error','No se pudo leer el preview.'); }
    return array_values(array_filter($rows,function($row){return $row->anchor && strtotime($row->anchor)!==false;}));
}
public static function preview_study($study_id) {
    $rows=self::study_candidates($study_id);
    if (is_wp_error($rows)) { return $rows; }
    return array('affected_participants'=>count(array_unique(array_map(function($row){return $row->participant_id;},$rows))),
        'waves_to_update'=>count($rows));
}
/** Per-assignment command; old arithmetic/audit owner is reused under its row lock. */
public static function recalculate_study($study_id, $user_id) {
    global $wpdb;
    $rows=self::study_candidates($study_id);
    if (is_wp_error($rows)) { return $rows; }
    $updated=0;$participants=array();
    foreach($rows as $candidate) {
        if ($wpdb->query('START TRANSACTION')===false) { return new WP_Error('db_error','No se pudo iniciar el recálculo.',array('updated'=>$updated)); }
        $committed=false;
        try {
            // Match submit's lookup/lock order, including its secondary index.
            // A primary-key lock here can deadlock with submit's index lock.
            $assignment=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}survey_assignments
                WHERE participant_id=%d AND study_id=%d AND wave_id=%d FOR UPDATE",
                $candidate->participant_id,$study_id,$candidate->wave_id));
            if ($wpdb->last_error) { throw new RuntimeException('No se pudo bloquear la asignación.'); }
            if (!$assignment || (int)$assignment->id!==(int)$candidate->id || !in_array($assignment->status,array('pending','in_progress'),true)) { continue; }
            $context=$wpdb->get_row($wpdb->prepare("SELECT p.is_active,p.t1_completed_at,w.wave_index
                FROM {$wpdb->prefix}survey_participants p JOIN {$wpdb->prefix}survey_waves w ON w.study_id=p.survey_id
                WHERE p.id=%d AND p.survey_id=%d AND w.id=%d LOCK IN SHARE MODE",$assignment->participant_id,$study_id,$assignment->wave_id));
            if ($wpdb->last_error) { throw new RuntimeException('No se pudo releer el contexto.'); }
            if (!$context || !$context->is_active || (int)$context->wave_index<=1) { continue; }
            $anchor=$context->t1_completed_at;
            if (!$anchor) {
                $anchor=$wpdb->get_var($wpdb->prepare("SELECT a.submitted_at FROM {$wpdb->prefix}survey_assignments a
                    JOIN {$wpdb->prefix}survey_waves w ON w.id=a.wave_id
                    WHERE a.participant_id=%d AND a.study_id=%d AND w.study_id=%d AND w.wave_index=1 AND a.status='submitted'
                    LIMIT 1 LOCK IN SHARE MODE",$assignment->participant_id,$study_id,$study_id));
            }
            if ($wpdb->last_error) { throw new RuntimeException('No se pudo leer el ancla T1.'); }
            if (!$anchor || strtotime($anchor)===false) { continue; }
            $ok=self::recalculate_single_wave($assignment->participant_id,$assignment->wave_id,$anchor,'admin',$user_id);
            if (!$ok || $wpdb->last_error) { throw new RuntimeException('No se pudo persistir el recálculo y su auditoría.'); }
            if ($wpdb->query('COMMIT')===false) { throw new RuntimeException('No se pudo confirmar el recálculo.'); }
            $committed=true;$updated++;$participants[$assignment->participant_id]=true;
        } catch(Throwable $error) {
            return new WP_Error('recalculation_failed',$error->getMessage(),array('updated'=>$updated));
        } finally { if (!$committed) { $wpdb->query('ROLLBACK'); } }
        // Dates committed before scheduling; notification failure cannot undo them.
        $refresh=EIPSI_Notification_Nudge_Schedule_Service::refresh_wave_follow_ups($assignment->wave_id,$assignment->id);
        if (is_wp_error($refresh)) { return new WP_Error('refresh_failed','Fechas guardadas; no se pudieron refrescar los recordatorios.',array('updated'=>$updated)); }
    }
    return array('message'=>sprintf('Recálculo completado: %d tomas.', $updated),'waves_to_update'=>$updated,'affected_participants'=>count($participants));
}

public static function recalculate_after_t1($participant_id, $study_id, $t1_completed_at, $triggered_by = 'system', $user_id = null) {
        global $wpdb;

        $batch_id = self::generate_batch_id();
        $results = array(
            'success' => true,
            'batch_id' => $batch_id,
            'affected_waves' => array(),
            'errors' => array()
        );

        // Get all waves for this study ordered by wave_index
        $waves = $wpdb->get_results($wpdb->prepare("
            SELECT id, wave_index, name, offset_minutes, window_minutes, form_id
            FROM {$wpdb->prefix}survey_waves
            WHERE study_id = %d
            ORDER BY wave_index ASC
        ", $study_id));

        if (empty($waves)) {
            return $results;
        }

        // Get study end offset
        $study_end_offset = $wpdb->get_var($wpdb->prepare("
            SELECT study_end_offset_minutes
            FROM {$wpdb->prefix}survey_studies
            WHERE id = %d
        ", $study_id));

        $t1_timestamp = strtotime($t1_completed_at);
        if (!$t1_timestamp) {
            $results['success'] = false;
            $results['errors'][] = 'Invalid T1 completion timestamp';
            return $results;
        }

        foreach ($waves as $wave) {
            // Skip T1 (wave_index = 1) as it's already completed
            if ((int) $wave->wave_index === 1) {
                continue;
            }

            // Calculate available_at based on offset_minutes from T1
            $available_at = date('Y-m-d H:i:s', $t1_timestamp + ($wave->offset_minutes * 60));

            // Calculate due_at based on window_minutes (if set)
            $due_at = null;
            if ($wave->window_minutes) {
                $due_at = date('Y-m-d H:i:s', strtotime($available_at) + ($wave->window_minutes * 60));
            }

            // Get current assignment for comparison
            $current = $wpdb->get_row($wpdb->prepare("
                SELECT available_at, due_at
                FROM {$wpdb->prefix}survey_assignments
                WHERE participant_id = %d AND wave_id = %d
            ", $participant_id, $wave->id));

            $old_values = array();
            if ($current) {
                $old_values = array(
                    'available_at' => $current->available_at,
                    'due_at' => $current->due_at
                );
            }

            $new_values = array(
                'available_at' => $available_at,
                'due_at' => $due_at
            );

            // Update assignment
            $updated = $wpdb->update(
                $wpdb->prefix . 'survey_assignments',
                array(
                    'available_at' => $available_at,
                    'due_at' => $due_at,
                    'updated_at' => current_time('mysql')
                ),
                array(
                    'participant_id' => $participant_id,
                    'wave_id' => $wave->id
                ),
                array('%s', '%s', '%s'),
                array('%d', '%d')
            );

            if ($updated !== false) {
                $results['affected_waves'][] = array(
                    'wave_id' => $wave->id,
                    'wave_index' => $wave->wave_index,
                    'wave_name' => $wave->name,
                    'available_at' => $available_at,
                    'due_at' => $due_at
                );

                // Log to audit log with adapted schema
                self::log_audit(
                    'wave_recalculated',
                    $study_id,
                    $participant_id,
                    $wave->id,
                    $batch_id,
                    $old_values,
                    $new_values,
                    $triggered_by,
                    $user_id
                );
            } else {
                $results['errors'][] = "Failed to update wave {$wave->wave_index}";
            }
        }

        // Handle study end offset if configured
        if ($study_end_offset) {
            $study_end_at = date('Y-m-d H:i:s', $t1_timestamp + ($study_end_offset * 60));

            // Store study end in participant record or handle as needed
            $wpdb->update(
                $wpdb->prefix . 'survey_participants',
                array('study_end_at' => $study_end_at),
                array('id' => $participant_id, 'survey_id' => $study_id),
                array('%s'),
                array('%d', '%d')
            );
        }

        return $results;
    }

private static function generate_batch_id() {
        return wp_generate_password(40, false, false);
    }

private static function log_audit($event_type, $study_id, $participant_id, $wave_id, $batch_id, $old_values, $new_values, $triggered_by, $user_id) {
        global $wpdb;

        // Map triggered_by to actor_type (only 'admin' or 'system' allowed)
        $actor_type = ($triggered_by === 'user' || $triggered_by === 'admin') ? 'admin' : 'system';

        // Build metadata JSON with all fields that don't exist in base schema
        $metadata = wp_json_encode(array(
            'batch_id' => $batch_id,
            'study_id' => $study_id,
            'wave_id' => $wave_id,
            'old_value' => $old_values,
            'new_value' => $new_values,
            'triggered_by_original' => $triggered_by
        ));

        $wpdb->insert(
            $wpdb->prefix . 'survey_audit_log',
            array(
                'survey_id' => $study_id,
                'participant_id' => $participant_id,
                'action' => $event_type,          // event_type → action
                'actor_type' => $actor_type,      // triggered_by → actor_type
                'actor_id' => $user_id ?: 0,      // user_id → actor_id
                'metadata' => $metadata,
                'created_at' => current_time('mysql', 1)
            ),
            array('%d', '%d', '%s', '%s', '%d', '%s', '%s')
        );
    }

public static function recalculate_single_wave($participant_id, $wave_id, $t1_completed_at, $triggered_by = 'system', $user_id = null) {
        global $wpdb;

        $batch_id = self::generate_batch_id();

        $wave = $wpdb->get_row($wpdb->prepare("
            SELECT w.*, a.study_id
            FROM {$wpdb->prefix}survey_waves w
            JOIN {$wpdb->prefix}survey_assignments a ON a.wave_id = w.id
            WHERE w.id = %d AND a.participant_id = %d
        ", $wave_id, $participant_id));

        if (!$wave) {
            return false;
        }

        $t1_timestamp = strtotime($t1_completed_at);
        if (!$t1_timestamp) {
            return false;
        }

        $available_at = date('Y-m-d H:i:s', $t1_timestamp + ($wave->offset_minutes * 60));
        $due_at = null;
        if ($wave->window_minutes) {
            $due_at = date('Y-m-d H:i:s', strtotime($available_at) + ($wave->window_minutes * 60));
        }

        // Get current values for audit
        $current = $wpdb->get_row($wpdb->prepare("
            SELECT available_at, due_at
            FROM {$wpdb->prefix}survey_assignments
            WHERE participant_id = %d AND wave_id = %d
        ", $participant_id, $wave_id));

        $old_values = $current ? array('available_at' => $current->available_at, 'due_at' => $current->due_at) : array();
        $new_values = array('available_at' => $available_at, 'due_at' => $due_at);

        $updated = $wpdb->update(
            $wpdb->prefix . 'survey_assignments',
            array(
                'available_at' => $available_at,
                'due_at' => $due_at,
                'updated_at' => current_time('mysql')
            ),
            array('participant_id' => $participant_id, 'wave_id' => $wave_id),
            array('%s', '%s', '%s'),
            array('%d', '%d')
        );

        if ($updated !== false) {
            self::log_audit(
                'wave_recalculated_single',
                $wave->study_id,
                $participant_id,
                $wave_id,
                $batch_id,
                $old_values,
                $new_values,
                $triggered_by,
                $user_id
            );
            return true;
        }

        return false;
    }

public static function recalculate_legacy_availability($participant_id, $study_id) {
        global $wpdb;

        error_log("[EIPSI Wave Service] Recalculating waves for participant {$participant_id} after T1 completion");

        $waves = EIPSI_Longitudinal_Wave_Definition_Service::get_study_waves($study_id);
        $recalculated = 0;

        foreach ($waves as $wave) {
            // Skip T1
            if ($wave['wave_index'] == 1) {
                continue;
            }

            // Calcular nueva disponibilidad
            $new_available_at = EIPSI_Longitudinal_T1_Anchor_Service::calculate_wave_availability($participant_id, $study_id, (object)$wave);

            if (!$new_available_at) {
                error_log("[EIPSI Wave Service] Could not calculate availability for wave {$wave['wave_index']}");
                continue;
            }

            // Actualizar assignment
            $updated = $wpdb->update(
                $wpdb->prefix . 'survey_assignments',
                array('available_at' => $new_available_at),
                array(
                    'participant_id' => $participant_id,
                    'wave_id' => $wave['id']
                ),
                array('%s'),
                array('%d', '%d')
            );

            if ($updated === false) {
                error_log("[EIPSI Wave Service] Failed to update assignment for wave {$wave['wave_index']}: " . $wpdb->last_error);
                continue;
            }

            // Obtener assignment_id para re-programar nudges
            $assignment_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}survey_assignments
                 WHERE participant_id = %d AND wave_id = %d",
                $participant_id, $wave['id']
            ));

            if ($assignment_id) {
                // Re-programar nudges (requiere EIPSI_Nudge_Event_Scheduler)
                if (class_exists('EIPSI_Nudge_Event_Scheduler')) {
                    EIPSI_Nudge_Event_Scheduler::reschedule_nudges_for_assignment($assignment_id);
                    error_log("[EIPSI Wave Service] Rescheduled nudges for assignment {$assignment_id} (wave {$wave['wave_index']})");
                }

                $recalculated++;
            }
        }

        error_log("[EIPSI Wave Service] Recalculated {$recalculated} waves for participant {$participant_id}");

        return $recalculated;
    }
}
