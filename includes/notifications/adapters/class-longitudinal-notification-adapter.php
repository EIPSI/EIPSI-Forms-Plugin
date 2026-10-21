<?php
/** Notification effects called at the original longitudinal boundaries. */
if(!defined('ABSPATH')){exit;}
class EIPSI_Notification_Longitudinal_Adapter {
public static function notify_available_context($context) {
    $next_assignment=$context['next_assignment'];$now=$context['now'];$available_at=$context['available_at'];
        // Only send if the wave is actually available now
        if ($now < $available_at) {
            $wait_hours = ceil(($available_at - $now) / 3600);
            EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_longitudinal_notification_adapter_L10'), 'debug');

            // Schedule exact event when wave becomes available
            wp_clear_scheduled_hook('eipsi_wave_available', array($next_assignment->id));
            wp_schedule_single_event($available_at, 'eipsi_wave_available', array($next_assignment->id));
            return;
        }

        // Wave is NOW available - trigger event-driven nudge system
        EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_longitudinal_notification_adapter_L24'), 'debug');
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
                    EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_longitudinal_notification_adapter_L36'), 'debug');

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
                            EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_longitudinal_notification_adapter_L72'), 'debug');

                            // Guardar para agregar al success_response después
                            if ($email_result['success'] && $email_result['sent']) {
                                $nudge_0_sent = true;
                                $nudge_0_message = __('Email de siguiente toma enviado inmediatamente', 'eipsi-forms');
                            }
                        } else {
                            EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_longitudinal_notification_adapter_L80'), 'debug');
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
                    EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_longitudinal_notification_adapter_L96'), 'debug');
                    do_action('eipsi_wave_available', $next_assignment->id);
                } else {
                    // Wave disponible en el FUTURO - programar evento
                    EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_longitudinal_notification_adapter_L104'), 'debug');
                    wp_clear_scheduled_hook('eipsi_wave_available', array($next_assignment->id));
                    wp_schedule_single_event($available_at, 'eipsi_wave_available', array($next_assignment->id));
                }
            }
}
}
