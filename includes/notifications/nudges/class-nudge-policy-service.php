<?php
/** Notifications owner; public WordPress adapters retain existing contracts. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Notification_Nudge_Policy_Service {
const NUDGE_AVAILABLE = 0;
const NUDGE_FOLLOW_UP = 1;
const NUDGE_REMINDER = 2;
const NUDGE_URGENCY = 3;
const NUDGE_LAST_CALL = 4;
public static function get_nudge_config($stage, $has_due_date = false) {
        $configs = self::get_all_nudge_configs($has_due_date);
        return isset($configs[$stage]) ? $configs[$stage] : null;
    }

public static function get_all_nudge_configs($has_due_date = false) {
        if ($has_due_date) {
            // Strategy: Days before/after due date
            return array(
                self::NUDGE_AVAILABLE => array(
                    'label' => __('Disponible', 'eipsi-forms'),
                    'subject_key' => 'nudge_0_available',
                    'template' => 'wave-nudge-0',
                    'timing' => 'immediate',
                    'timing_days' => 0,
                    'tone' => 'neutral',
                    'description' => __('Email inmediato cuando la wave está disponible', 'eipsi-forms')
                ),
                self::NUDGE_FOLLOW_UP => array(
                    'label' => __('Seguimiento', 'eipsi-forms'),
                    'subject_key' => 'nudge_1_follow_up',
                    'template' => 'wave-nudge-1-due',
                    'timing' => 'days_before',
                    'timing_days' => 2,
                    'tone' => 'gentle',
                    'description' => __('2 días antes del vencimiento', 'eipsi-forms')
                ),
                self::NUDGE_REMINDER => array(
                    'label' => __('Recordatorio', 'eipsi-forms'),
                    'subject_key' => 'nudge_2_reminder',
                    'template' => 'wave-nudge-2-due',
                    'timing' => 'days_before',
                    'timing_days' => 1,
                    'tone' => 'urgent',
                    'description' => __('1 día antes del vencimiento', 'eipsi-forms')
                ),
                self::NUDGE_URGENCY => array(
                    'label' => __('Extensión', 'eipsi-forms'),
                    'subject_key' => 'nudge_3_extension',
                    'template' => 'wave-nudge-3-due',
                    'timing' => 'days_after',
                    'timing_days' => 0,
                    'tone' => 'helpful',
                    'description' => __('Día del vencimiento (extensión ofrecida)', 'eipsi-forms')
                ),
                self::NUDGE_LAST_CALL => array(
                    'label' => __('Último llamado', 'eipsi-forms'),
                    'subject_key' => 'nudge_4_last_call',
                    'template' => 'wave-nudge-4-due',
                    'timing' => 'days_after',
                    'timing_days' => 7,
                    'tone' => 'final',
                    'description' => __('7 días después del vencimiento', 'eipsi-forms')
                )
            );
        } else {
            // Strategy: Days since available (no due date)
            return array(
                self::NUDGE_AVAILABLE => array(
                    'label' => __('Disponible', 'eipsi-forms'),
                    'subject_key' => 'nudge_0_available',
                    'template' => 'wave-nudge-0',
                    'timing' => 'immediate',
                    'timing_days' => 0,
                    'tone' => 'neutral',
                    'description' => __('Email inmediato cuando la wave está disponible', 'eipsi-forms')
                ),
                self::NUDGE_FOLLOW_UP => array(
                    'label' => __('Seguimiento', 'eipsi-forms'),
                    'subject_key' => 'nudge_1_follow_up',
                    'template' => 'wave-nudge-1',
                    'timing' => 'days_after',
                    'timing_days' => 3,
                    'tone' => 'gentle',
                    'description' => __('3 días después de disponible', 'eipsi-forms')
                ),
                self::NUDGE_REMINDER => array(
                    'label' => __('Recordatorio', 'eipsi-forms'),
                    'subject_key' => 'nudge_2_reminder',
                    'template' => 'wave-nudge-2',
                    'timing' => 'days_after',
                    'timing_days' => 7,
                    'tone' => 'warm',
                    'description' => __('7 días después de disponible', 'eipsi-forms')
                ),
                self::NUDGE_URGENCY => array(
                    'label' => __('Ayuda', 'eipsi-forms'),
                    'subject_key' => 'nudge_3_help',
                    'template' => 'wave-nudge-3',
                    'timing' => 'days_after',
                    'timing_days' => 14,
                    'tone' => 'helpful',
                    'description' => __('14 días después de disponible', 'eipsi-forms')
                ),
                self::NUDGE_LAST_CALL => array(
                    'label' => __('Último llamado', 'eipsi-forms'),
                    'subject_key' => 'nudge_4_last_call',
                    'template' => 'wave-nudge-4',
                    'timing' => 'days_after',
                    'timing_days' => 30,
                    'tone' => 'final',
                    'description' => __('30 días después de disponible', 'eipsi-forms')
                )
            );
        }
    }

public static function get_timeline_preview($has_due_date = false) {
        $configs = self::get_all_nudge_configs($has_due_date);

        $timeline = array();
        foreach ($configs as $stage => $config) {
            if ($stage === self::NUDGE_AVAILABLE) {
                $timeline[] = '0d'; // Inmediato
            } elseif ($config['timing'] === 'days_before') {
                $timeline[] = '-' . $config['timing_days'] . 'd';
            } else {
                $timeline[] = '+' . $config['timing_days'] . 'd';
            }
        }

        return implode(' → ', $timeline);
    }

public static function convert_to_seconds($value, $unit = 'days') {
        switch ($unit) {
            case 'minutes':
                return $value * 60;
            case 'hours':
                return $value * 3600;
            case 'days':
            default:
                return $value * 86400;
        }
    }

public static function should_send_nudge($assignment, $wave, $current_stage, $custom_config = null) {
        $assignment_id = isset($assignment->id) ? $assignment->id : 'unknown';
        $participant_id = isset($assignment->participant_id) ? $assignment->participant_id : 'unknown';
        $wave_id = isset($wave->id) ? $wave->id : 'unknown';
        $wave_name = isset($wave->name) ? $wave->name : 'unknown';

        // Stage 0 (NUDGE_AVAILABLE) is always sent immediately when wave becomes available
        if ((int)$current_stage === self::NUDGE_AVAILABLE) {
            $available_at = isset($assignment->available_at) ? $assignment->available_at : 'not_set';
            error_log("[EIPSI Nudge] CHECK NUDGE 0: assignment_id={$assignment_id}, available_at={$available_at}, result=ALLOWED");
            return true;
        }

        // For stages 1-4, check if follow_up_reminders_enabled
        if (empty($wave->follow_up_reminders_enabled)) {
            error_log("[EIPSI Nudge] Stage {$current_stage} - BLOCKED: follow_up_reminders_enabled is empty");
            return false;
        }

        // v2.5.0 - Check cache first (short TTL because this can change over time)
        if (class_exists('EIPSI_Nudge_Cache')) {
            $cached = EIPSI_Nudge_Cache::get_cached_should_send($assignment_id, $current_stage);
            if ($cached !== null) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("[EIPSI Nudge] Stage {$current_stage}: CACHE HIT for assignment {$assignment_id} = " . ($cached ? 'SEND' : 'SKIP'));
                }
                return $cached;
            }
        }

        // Get config
        if ($custom_config && isset($custom_config[$current_stage])) {
            $config = $custom_config[$current_stage];
            $timing_value = isset($config['hours']) ? intval($config['hours']) : 24;
            $timing_unit = isset($config['unit']) ? $config['unit'] : 'hours';
        } else {
            $nudge_config = isset($wave->nudge_config) ? json_decode($wave->nudge_config, true) : array();
            $nudge_key = "nudge_{$current_stage}";
            if (isset($nudge_config[$nudge_key])) {
                $config = $nudge_config[$nudge_key];
                $timing_value = isset($config['value']) ? intval($config['value']) : 24;
                $timing_unit = isset($config['unit']) ? $config['unit'] : 'hours';
            } else {
                $config = self::get_nudge_config($current_stage, false);
                if (!$config) {
                    return false;
                }
                $timing_value = $config['timing_days'];
                $timing_unit = 'days';
            }
        }

        // Convert to seconds for calculation
        $timing_seconds = self::convert_to_seconds($timing_value, $timing_unit);

        $now = current_time('timestamp');

        // v2.5.0 - Use cached trigger timestamp if available (immutable calculation)
        if (class_exists('EIPSI_Nudge_Cache')) {
            $trigger_ts = EIPSI_Nudge_Cache::get_trigger_timestamp($assignment_id, $current_stage, $assignment, $wave);
        } else {
            $available_ts = strtotime($assignment->available_at);
            $trigger_ts = $available_ts + $timing_seconds;
        }

        $should_send = ($now >= $trigger_ts);

        // v2.5.1 - Verificar intervalo mínimo desde el último nudge enviado
        // Esto evita que nudges consecutivos se envíen seguidos si el cron tuvo delay
        if ($should_send && $current_stage > 0 && !empty($assignment->last_nudge_sent_at)) {
            $segundos_desde_ultimo = $now - strtotime($assignment->last_nudge_sent_at);

            // El intervalo mínimo es el configurado para este nudge en nudge_config
            // Si no hay config específica, usar 2 horas como mínimo
            $intervalo_minimo_segundos = 2 * HOUR_IN_SECONDS; // fallback

            if (!empty($nudge_config[$nudge_key]) &&
                !empty($nudge_config[$nudge_key]['value']) &&
                !empty($nudge_config[$nudge_key]['unit'])) {
                $valor = intval($nudge_config[$nudge_key]['value']);
                $unidad = $nudge_config[$nudge_key]['unit'];
                $intervalo_minimo_segundos = ($unidad === 'days')
                    ? $valor * DAY_IN_SECONDS
                    : $valor * HOUR_IN_SECONDS;
            }

            if ($segundos_desde_ultimo < $intervalo_minimo_segundos) {
                $minutos_restantes = round(($intervalo_minimo_segundos - $segundos_desde_ultimo) / 60);
                error_log(sprintf(
                    '[EIPSI NUDGE] SKIP intervalo: nudge_%d para assignment %d - último nudge hace %d min, intervalo mínimo %d min, faltan %d min',
                    $current_stage,
                    $assignment_id,
                    round($segundos_desde_ultimo / 60),
                    round($intervalo_minimo_segundos / 60),
                    $minutos_restantes
                ));
                $should_send = false;
            } else {
                error_log(sprintf(
                    '[EIPSI NUDGE] OK intervalo: nudge_%d para assignment %d - último nudge hace %d min >= intervalo mínimo %d min',
                    $current_stage,
                    $assignment_id,
                    round($segundos_desde_ultimo / 60),
                    round($intervalo_minimo_segundos / 60)
                ));
            }
        }

        // Cache the result
        if (class_exists('EIPSI_Nudge_Cache')) {
            EIPSI_Nudge_Cache::cache_should_send($assignment_id, $current_stage, $should_send, 300); // 5 min cache
        }

        error_log("[EIPSI Nudge] Stage {$current_stage}: trigger at " . date('Y-m-d H:i:s', $trigger_ts) . " ({$timing_value} {$timing_unit} after available)");

        return $should_send;
    }

public static function get_next_stage($current_stage) {
        $next = $current_stage + 1;
        return ($next <= self::NUDGE_LAST_CALL) ? $next : null;
    }

public static function get_stage_description($stage, $has_due_date = false) {
        $config = self::get_nudge_config($stage, $has_due_date);
        return $config ? $config['description'] : '';
    }
public static function schedule_seconds($value,$unit) {
        switch ($unit) {
            case 'minutes':
            case 'minutos':
                return $value * 60;
            case 'hours':
            case 'horas':
                return $value * 3600;
            case 'days':
            case 'días':
            case 'dias':
                return $value * 86400;
            default:
                return $value * 3600; // Default a horas
        }
    }
public static function follow_up_plan($assignment,$config,$available_at=null,$now=null) {
    $now=$now===null?current_time('timestamp'):$now;
    $available_at=$available_at===null?(!empty($assignment->available_at)?strtotime($assignment->available_at):$now):$available_at;
    if (!$available_at || !is_array($config)) { return array(); }
    $due=!empty($assignment->due_at)?strtotime($assignment->due_at):null;
    $plan=array();
    for($stage=1;$stage<=4;$stage++) {
        if($stage<(int)($assignment->reminder_count??0)){continue;}
        $item=$config['nudge_'.$stage]??array();
        if(empty($item['enabled'])){continue;}
        $value=isset($item['value'])?(float)$item['value']:$stage*24;
        $unit=$item['unit']??'hours';
        $at=(int)round($available_at+self::schedule_seconds($value,$unit));
        if($at<=$now || ($due!==null && $at>=$due)){continue;}
        $plan[$stage]=array('timestamp'=>$at,'value'=>$value,'unit'=>$unit);
    }
    return $plan;
}

public static function cached_trigger_timestamp($assignment_id, $stage, $assignment = null, $wave = null) {
        global $wpdb;
        
        // Si no tenemos los objetos, cargarlos
        if (!$assignment) {
            $assignment = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}survey_assignments WHERE id = %d",
                $assignment_id
            ));
        }
        
        if (!$assignment) {
            return false;
        }
        
        // Para nudge 0, es el available_at
        if ($stage === 0) {
            return !empty($assignment->available_at) 
                ? strtotime($assignment->available_at)
                : false;
        }
        
        // Para nudges 1-4, necesitamos la configuración de la wave
        if (!$wave) {
            $wave = $wpdb->get_row($wpdb->prepare(
                "SELECT nudge_config FROM {$wpdb->prefix}survey_waves WHERE id = %d",
                $assignment->wave_id
            ));
        }
        
        if (!$wave || empty($wave->nudge_config)) {
            return false;
        }
        
        $nudge_config = json_decode($wave->nudge_config, true);
        $nudge_key = "nudge_{$stage}";
        
        if (!isset($nudge_config[$nudge_key])) {
            return false;
        }
        
        $config = $nudge_config[$nudge_key];
        
        // Si no está habilitado, no hay trigger
        if (empty($config['enabled'])) {
            return false;
        }
        
        $value = isset($config['value']) ? intval($config['value']) : ($stage * 24);
        $unit = isset($config['unit']) ? $config['unit'] : 'hours';
        
        // Calcular delay en segundos
        $delay_seconds = self::schedule_seconds($value, $unit);
        
        // El trigger es available_at + delay
        $available_at = !empty($assignment->available_at) 
            ? strtotime($assignment->available_at)
            : current_time('timestamp');
        
        return $available_at + $delay_seconds;
    }
}
