<?php
/** Notification effects called at the original longitudinal boundaries. */
if(!defined('ABSPATH')){exit;}
class EIPSI_Notification_Longitudinal_Adapter {
public static function notify_available_context($context) {
    $next_assignment=$context['next_assignment'];$now=$context['now'];$available_at=$context['available_at'];
        // Only send if the wave is actually available now
        if ($now < $available_at) {
            $wait_hours = ceil(($available_at - $now) / 3600);
            error_log(sprintf(
                "[Wave_Service] Next wave not available yet. Scheduling event for assignment %d at %s (~%d hours)",
                $next_assignment->id,
                date('Y-m-d H:i:s', $available_at),
                $wait_hours
            ));

            // Schedule exact event when wave becomes available
            wp_clear_scheduled_hook('eipsi_wave_available', array($next_assignment->id));
            wp_schedule_single_event($available_at, 'eipsi_wave_available', array($next_assignment->id));
            return;
        }

        // Wave is NOW available - trigger event-driven nudge system
        error_log("[Wave_Service] Next wave is NOW available - triggering event-driven nudge sequence");
        do_action('eipsi_wave_available', $next_assignment->id);

}
public static function notify_next_wave($next_wave,$study_id,$longitudinal_participant_id,$available_at) {
    global $wpdb;
    $nudge_0_sent=false;$nudge_0_message='';
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
    return compact('nudge_0_sent','nudge_0_message');
}
public static function after_auto_skip($next_assignment,$next_index) {
            if ($next_assignment && $next_assignment->status === 'pending' && $next_assignment->reminder_count == 0) {
                $available_at = strtotime($next_assignment->available_at);
                $now_ts = current_time('timestamp');

                if ($available_at <= $now_ts) {
                    // Wave disponible AHORA - trigger inmediato
                    error_log(sprintf(
                        '[EIPSI Auto-Skip] Triggering eipsi_wave_available for assignment %d (T%d now available)',
                        $next_assignment->id,
                        $next_index
                    ));
                    do_action('eipsi_wave_available', $next_assignment->id);
                } else {
                    // Wave disponible en el FUTURO - programar evento
                    error_log(sprintf(
                        '[EIPSI Auto-Skip] Scheduling eipsi_wave_available for assignment %d (T%d available at %s)',
                        $next_assignment->id,
                        $next_index,
                        date('Y-m-d H:i:s', $available_at)
                    ));
                    wp_clear_scheduled_hook('eipsi_wave_available', array($next_assignment->id));
                    wp_schedule_single_event($available_at, 'eipsi_wave_available', array($next_assignment->id));
                }
            }
}
}
