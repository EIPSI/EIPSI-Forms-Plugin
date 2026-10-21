<?php
/** Notifications owner; public WordPress adapters retain existing contracts. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Notification_Nudge_Schedule_Service {
const NUDGE_EVENT_HOOK = 'eipsi_scheduled_nudge_event';
public static function refresh_wave_follow_ups($wave_id, $assignment_id = null) {
        global $wpdb;
        $filter = $assignment_id === null ? '' : $wpdb->prepare(' AND a.id = %d', $assignment_id);
        $assignments = $wpdb->get_results($wpdb->prepare(
            "SELECT a.*, w.follow_up_reminders_enabled FROM {$wpdb->prefix}survey_assignments a
             JOIN {$wpdb->prefix}survey_waves w ON w.id = a.wave_id
             WHERE a.wave_id = %d AND a.status IN ('pending','in_progress')" . $filter, $wave_id
        ));
        if ($wpdb->last_error) {
            return new WP_Error('reschedule_failed', 'No se pudieron leer las asignaciones para refrescar recordatorios.');
        }
        $scheduled = 0;
        foreach ($assignments as $assignment) {
            $count=self::with_assignment_lock($assignment->id,function() use ($assignment,$wpdb) {
                $assignment=$wpdb->get_row($wpdb->prepare("SELECT a.*, w.follow_up_reminders_enabled FROM {$wpdb->prefix}survey_assignments a JOIN {$wpdb->prefix}survey_waves w ON w.id=a.wave_id WHERE a.id=%d",$assignment->id));
                if ($wpdb->last_error) {
                    return new WP_Error('reschedule_failed', 'No se pudo releer la asignación para refrescar recordatorios.');
                }
                if(!$assignment){return 0;}
                require_once EIPSI_FORMS_PLUGIN_DIR.'includes/services/class-nudge-job-queue.php';
                if(EIPSI_Nudge_Job_Queue::cancel_follow_up_jobs($assignment->id)===false){return new WP_Error('reschedule_failed','Configuración guardada; no se pudieron cancelar los jobs anteriores. Reintentá.');}
                for($stage=1;$stage<=4;$stage++){
                    foreach(array((int)$assignment->id,(string)$assignment->id) as $id){wp_clear_scheduled_hook(self::NUDGE_EVENT_HOOK,array(array('assignment_id'=>$id,'stage'=>$stage)));}
                }
                if((int)$assignment->reminder_count>=1 && !empty($assignment->follow_up_reminders_enabled)){return self::schedule_follow_up_nudges_only($assignment);}
                return 0;
            });
            if(is_wp_error($count)){return $count;}
            if($count===false){return new WP_Error('reschedule_failed','Configuración guardada; falló la programación. Reintentá.');}
            $scheduled+=$count;
        }
        return $scheduled;
    }

private static function schedule_nudge_sequence_unlocked($assignment_id) {
        global $wpdb;

        EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_nudge_schedule_service_L44'), 'debug');
        EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_nudge_schedule_service_L45'), 'debug');
        EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_nudge_schedule_service_L46'), 'debug');

        // Obtener datos de la asignación y su configuración de nudges
        $assignment = $wpdb->get_row($wpdb->prepare(
            "SELECT a.*, w.nudge_config, w.follow_up_reminders_enabled,
                    a.available_at, p.email, p.first_name
             FROM {$wpdb->prefix}survey_assignments a
             JOIN {$wpdb->prefix}survey_waves w ON a.wave_id = w.id
             JOIN {$wpdb->prefix}survey_participants p ON a.participant_id = p.id
             WHERE a.id = %d AND a.status = 'pending'",
            $assignment_id
        ));

        if (!$assignment) {
            EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_nudge_schedule_service_L60'), 'debug');
            return false;
        }

        EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_nudge_schedule_service_L64'), 'debug');

        // Calcular timestamp base (cuándo la wave se hizo disponible)
        $available_at = !empty($assignment->available_at)
            ? strtotime($assignment->available_at)
            : current_time('timestamp');

        if (!$available_at) {
            EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_nudge_schedule_service_L75'), 'debug');
            return false;
        }

        // Parsear configuración de nudges
        $nudge_config = !empty($assignment->nudge_config)
            ? json_decode($assignment->nudge_config, true)
            : array();

        EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_nudge_schedule_service_L84'), 'debug');

        $scheduled_count = 0;

        // v2.1.7 - PRIMERO ejecutar Nudge 0, LUEGO programar nudges 1-4 solo si tuvo éxito
        // Esto evita que queden nudges programados para un assignment que nunca recibió Nudge 0
        $nudge_0_success = false;

        if (class_exists('EIPSI_Nudge_Job_Queue')) {
            EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_nudge_schedule_service_L93'), 'debug');
            $nudge_0_result = EIPSI_Nudge_Job_Queue::execute_nudge_0(array(
                'assignment_id' => $assignment_id,
                'participant_id' => $assignment->participant_id,
                'wave_id' => $assignment->wave_id,
                'study_id' => $assignment->study_id
            ));

            if ($nudge_0_result['success']) {
                EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_nudge_schedule_service_L102'), 'debug');
                $scheduled_count++;
                $nudge_0_success = true;
            } else {
                $error_msg = $nudge_0_result['error'] ?? 'unknown';
                EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_nudge_schedule_service_L107'), 'debug');

                // v2.1.7 - Si falló porque la wave no está disponible, programar reintento exacto
                if (strpos($error_msg, 'Wave not yet available') !== false || strpos($error_msg, 'not yet available') !== false) {
                    $retry_time = !empty($assignment->available_at) ? strtotime($assignment->available_at) : time() + 60;
                    EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_nudge_schedule_service_L112'), 'debug');

                    // Programar reintento exacto en available_at
                    wp_schedule_single_event($retry_time, 'eipsi_wave_available_retry', array($assignment_id));

                    // También encolar en Job Queue como backup
                    EIPSI_Nudge_Job_Queue::enqueue('send_nudge_0', array(
                        'assignment_id' => $assignment_id,
                        'participant_id' => $assignment->participant_id,
                        'wave_id' => $assignment->wave_id,
                        'study_id' => $assignment->study_id
                    ), 5, date('Y-m-d H:i:s', $retry_time));
                } else {
                    // Fallback genérico: retry en 5 minutos
                    EIPSI_Nudge_Job_Queue::enqueue('send_nudge_0', array(
                        'assignment_id' => $assignment_id,
                        'participant_id' => $assignment->participant_id,
                        'wave_id' => $assignment->wave_id,
                        'study_id' => $assignment->study_id
                    ), 5);
                }

                // Si Nudge 0 falló, NO programar los nudges de seguimiento
                EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_nudge_schedule_service_L139'), 'debug');
                EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_nudge_schedule_service_L140'), 'debug');
                EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_nudge_schedule_service_L141'), 'debug');
                return $scheduled_count;
            }
        } else {
            EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_nudge_schedule_service_L145'), 'debug');
            return 0;
        }

        // v2.1.7 - STEP 2: Solo si Nudge 0 tuvo éxito, programar nudges 1-4
        if ($nudge_0_success && !empty($assignment->follow_up_reminders_enabled)) {
            EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_nudge_schedule_service_L151'), 'debug');

            // Phase 5 T1-Anchor: Get due_at deadline to prevent nudges after expiration
            $due_at_timestamp = null;
            if (!empty($assignment->due_at)) {
                $due_at_timestamp = strtotime($assignment->due_at);
                EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_nudge_schedule_service_L157'), 'debug');
            }

            // v1.4.2 - Absolute offsets: calculate directly from available_at
            foreach(EIPSI_Notification_Nudge_Policy_Service::follow_up_plan($assignment,$nudge_config,$available_at) as $stage=>$item) {
                $args=array('assignment_id'=>$assignment_id,'stage'=>$stage);
                wp_clear_scheduled_hook(self::NUDGE_EVENT_HOOK,array($args));
                if(wp_schedule_single_event($item['timestamp'],self::NUDGE_EVENT_HOOK,array($args))!==false){$scheduled_count++;}
            }
        } else if (!$nudge_0_success) {
            EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_nudge_schedule_service_L168'), 'debug');
        } else {
            EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_nudge_schedule_service_L170'), 'debug');
        }

        EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_nudge_schedule_service_L173'), 'debug');
        EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_nudge_schedule_service_L174'), 'debug');

        return $scheduled_count;
    }

private static function cancel_scheduled_nudges_unlocked($assignment_id) {
        for ($stage = 0; $stage <= 4; $stage++) {
            $event_args = array(
                'assignment_id' => $assignment_id,
                'stage' => $stage
            );

            // SQL ids arrive as strings; cron args distinguish strings from integers.
            foreach(array((int)$assignment_id,(string)$assignment_id) as $id){
                $event_args['assignment_id']=$id;
                wp_clear_scheduled_hook(self::NUDGE_EVENT_HOOK, array($event_args));
            }
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_nudge_schedule_service_L194'), 'debug');
        }
    }

private static function reschedule_nudges_for_deadline_unlocked($assignment_id) {
        global $wpdb;

        $assignment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}survey_assignments WHERE id = %d",
            $assignment_id
        ));

        if (!$assignment || $assignment->status === 'submitted' || $assignment->status === 'expired') {
            return; // No recalcular si ya está completado o expirado
        }

        EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_nudge_schedule_service_L213'), 'debug');

        if ((int) $assignment->reminder_count >= 1) {
            return self::refresh_wave_follow_ups($assignment->wave_id, $assignment_id);
        }
        // Cancelar nudges programados existentes
        self::cancel_scheduled_nudges($assignment_id);

        // Reprogramar con el nuevo deadline
        self::schedule_nudge_sequence($assignment_id);
    }

public static function reschedule_all_nudges_for_participant($study_id, $participant_id) {
        global $wpdb;

        EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_nudge_schedule_service_L228'), 'debug');

        // Obtener todos los assignments del participante (excepto T1 y los ya completados)
        $assignments = $wpdb->get_results($wpdb->prepare(
            "SELECT a.id, a.status, w.wave_index
             FROM {$wpdb->prefix}survey_assignments a
             JOIN {$wpdb->prefix}survey_waves w ON a.wave_id = w.id
             WHERE a.participant_id = %d
             AND w.study_id = %d
             AND w.wave_index > 1
             AND a.status NOT IN ('submitted', 'expired')
             ORDER BY w.wave_index ASC",
            $participant_id,
            $study_id
        ));

        $rescheduled = 0;
        foreach ($assignments as $assignment) {
            // Cancelar nudges existentes
            self::cancel_scheduled_nudges($assignment->id);

            // Reprogramar con los nuevos deadlines calculados por T1-Anchor
            if ($assignment->status === 'available') {
                self::schedule_nudge_sequence($assignment->id);
                $rescheduled++;
            }
        }

        EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_nudge_schedule_service_L256'), 'debug');
    }

public static function get_scheduled_events() {
        $crons = _get_cron_array();
        $events = array();

        if (empty($crons)) {
            return $events;
        }

        foreach ($crons as $timestamp => $cron) {
            if (isset($cron[self::NUDGE_EVENT_HOOK])) {
                foreach ($cron[self::NUDGE_EVENT_HOOK] as $key => $event) {
                    $args = isset($event['args'][0]) ? $event['args'][0] : array();
                    $events[] = array(
                        'timestamp' => $timestamp,
                        'date' => date('Y-m-d H:i:s', $timestamp),
                        'assignment_id' => isset($args['assignment_id']) ? $args['assignment_id'] : null,
                        'stage' => isset($args['stage']) ? $args['stage'] : null
                    );
                }
            }
        }

        return $events;
    }

private static function get_event_key($assignment_id, $stage) {
        return "eipsi_nudge_{$assignment_id}_{$stage}";
    }

private static function convert_to_seconds($value,$unit) { return EIPSI_Notification_Nudge_Policy_Service::schedule_seconds($value,$unit); }

private static function cancel_nudges_for_assignment_unlocked($assignment_id) {
        global $wpdb;
        $modern=EIPSI_Notification_Nudge_Queue_Service::cancel_jobs_for_assignment($assignment_id);
        if ($modern===false) { return false; }
        // Preserve optional historical storage; no current producer/schema creates it.
        $legacy=$wpdb->prefix.'survey_job_queue';
        $exists=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($legacy)));
        if ($wpdb->last_error) { return false; }
        if ($exists!==$legacy) { return $modern; }
        $cancelled=$wpdb->update($legacy,array('status'=>'cancelled'),array('assignment_id'=>$assignment_id,'status'=>'pending'),array('%s'),array('%d','%s'));
        if ($cancelled===false) { EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_nudge_schedule_service_L300'), 'debug'); return false; }
        return $modern+intval($cancelled);
    }

private static function reschedule_nudges_for_assignment_unlocked($assignment_id) {
        global $wpdb;
        $assignment = $wpdb->get_row($wpdb->prepare("SELECT wave_id, reminder_count FROM {$wpdb->prefix}survey_assignments WHERE id = %d", $assignment_id));
        if ($assignment && (int) $assignment->reminder_count >= 1) {
            $result = self::refresh_wave_follow_ups($assignment->wave_id, $assignment_id);
            return !is_wp_error($result);
        }
        EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_nudge_schedule_service_L311'), 'debug');

        // 1. Cancelar nudges pendientes
        $cancelled = self::cancel_nudges_for_assignment($assignment_id);
        if ($cancelled === false) { return false; }
        EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_nudge_schedule_service_L316'), 'debug');

        // 2. Re-programar desde cero (lee available_at actualizado de la DB)
        $result = self::schedule_nudge_sequence($assignment_id);

        if ($result) {
            EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_nudge_schedule_service_L322'), 'debug');
        } else {
            EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_nudge_schedule_service_L324'), 'debug');
        }

        return $result;
    }

private static function schedule_follow_up_nudges_only_unlocked($assignment) {
        global $wpdb;

        EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_nudge_schedule_service_L333'), 'debug');
        EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_nudge_schedule_service_L334'), 'debug');
        EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_nudge_schedule_service_L335'), 'debug');

        if (empty($assignment->id)) {
            EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_nudge_schedule_service_L338'), 'debug');
            return 0;
        }

        $assignment_id = (int) $assignment->id;

        EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_nudge_schedule_service_L344'), 'debug');

        // Verify reminder_count is 1 (Nudge 0 was sent)
        if ((int) $assignment->reminder_count < 1) {
            EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_nudge_schedule_service_L355'), 'debug');
            return 0;
        }

        EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_nudge_schedule_service_L363'), 'debug');

        // Check if follow-up reminders are enabled
        if (empty($assignment->follow_up_reminders_enabled)) {
            EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_nudge_schedule_service_L369'), 'debug');
            return 0;
        }

        // Get wave to check nudge config
        $wave = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}survey_waves WHERE id = %d",
            $assignment->wave_id
        ));

        if (!$wave) {
            EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_nudge_schedule_service_L380'), 'debug');
            return 0;
        }

        // Parse nudge config
        $nudge_config = !empty($wave->nudge_config) ? json_decode($wave->nudge_config, true) : array();
        if (empty($nudge_config)) {
            EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_nudge_schedule_service_L387'), 'debug');
            return 0;
        }

        // Get available_at timestamp
        $available_at = !empty($assignment->available_at) ? strtotime($assignment->available_at) : null;
        if (!$available_at) {
            EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_nudge_schedule_service_L394'), 'debug');
            return 0;
        }

        // Get due_at deadline to prevent nudges after expiration
        $due_at_timestamp = null;
        if (!empty($assignment->due_at)) {
            $due_at_timestamp = strtotime($assignment->due_at);
        }

        EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_nudge_schedule_service_L404'), 'debug');

        $scheduled_count = 0;

        foreach(EIPSI_Notification_Nudge_Policy_Service::follow_up_plan($assignment,$nudge_config,$available_at) as $stage=>$item) {
            $args=array('assignment_id'=>$assignment_id,'stage'=>$stage);
            wp_clear_scheduled_hook(self::NUDGE_EVENT_HOOK,array($args));
            $result=wp_schedule_single_event($item['timestamp'],self::NUDGE_EVENT_HOOK,array($args));
            if($result===false || is_wp_error($result)){return false;}
            $scheduled_count++;
        }

        EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_nudge_schedule_service_L420'), 'debug');

        return $scheduled_count;
    }

private static function format_delay($value, $unit) {
        $units = array(
            'minutes' => 'minutos',
            'minutos' => 'minutos',
            'hours' => 'horas',
            'horas' => 'horas',
            'days' => 'días',
            'días' => 'días',
            'dias' => 'días'
        );
        $unit_name = isset($units[$unit]) ? $units[$unit] : $unit;
        return sprintf('%s %s', $value, $unit_name);
    }
public static function schedule_nudge_sequence($assignment_id) { return self::with_assignment_lock($assignment_id,function() use ($assignment_id) { return self::schedule_nudge_sequence_unlocked($assignment_id); }); }

public static function cancel_scheduled_nudges($assignment_id) { return self::with_assignment_lock($assignment_id,function() use ($assignment_id) { return self::cancel_scheduled_nudges_unlocked($assignment_id); }); }

public static function reschedule_nudges_for_deadline($assignment_id) { return self::with_assignment_lock($assignment_id,function() use ($assignment_id) { return self::reschedule_nudges_for_deadline_unlocked($assignment_id); }); }

public static function cancel_nudges_for_assignment($assignment_id) { return self::with_assignment_lock($assignment_id,function() use ($assignment_id) { return self::cancel_nudges_for_assignment_unlocked($assignment_id); }); }

public static function reschedule_nudges_for_assignment($assignment_id) { return self::with_assignment_lock($assignment_id,function() use ($assignment_id) { return self::reschedule_nudges_for_assignment_unlocked($assignment_id); }); }

public static function schedule_follow_up_nudges_only($assignment) { return self::with_assignment_lock($assignment->id,function() use ($assignment) { return self::schedule_follow_up_nudges_only_unlocked($assignment); }); }

private static $held_locks=array();
private static function with_assignment_lock($id,$operation) {
    global $wpdb;
    $key='eipsi_nudge_'.md5($wpdb->prefix.':'.(int)$id);
    if(isset(self::$held_locks[$key])){return $operation();}
    if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)',$key))!==1){return false;}
    self::$held_locks[$key]=true;
    try {
        // WP-Cron is persisted in an option. Refresh process-local caches after waiting.
        if(function_exists('wp_cache_delete')){wp_cache_delete('cron','options');wp_cache_delete('alloptions','options');}
        return $operation();
    } finally {unset(self::$held_locks[$key]);$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$key));}
}
public static function reschedule_next_stage($id,$stage) {
    return self::with_assignment_lock($id,function() use ($id,$stage) {
        global $wpdb;
        $assignment=$wpdb->get_row($wpdb->prepare("SELECT a.*,w.nudge_config,w.follow_up_reminders_enabled FROM {$wpdb->prefix}survey_assignments a JOIN {$wpdb->prefix}survey_waves w ON w.id=a.wave_id WHERE a.id=%d",$id));
        if(!$assignment){return false;}
        $next=$stage+1;$args=array('assignment_id'=>$id,'stage'=>$next);
        wp_clear_scheduled_hook(self::NUDGE_EVENT_HOOK,array($args));
        if(empty($assignment->follow_up_reminders_enabled)){return 0;}
        $plan=EIPSI_Notification_Nudge_Policy_Service::follow_up_plan($assignment,json_decode($assignment->nudge_config,true));
        if(!isset($plan[$next])){return 0;}
        return wp_schedule_single_event($plan[$next]['timestamp'],self::NUDGE_EVENT_HOOK,array($args));
    });
}

}
