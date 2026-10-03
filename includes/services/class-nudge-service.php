<?php
/**
 * EIPSI Nudge Service
 * 
 * Maneja el sistema de 5 nudges (0-4) para recordatorios de waves.
 * Nudge 0: Siempre se envía inmediatamente cuando la wave está disponible.
 * Nudges 1-4: Solo si el investigador activó follow_up_reminders_enabled.
 * 
 * @package EIPSI_Forms
 * @since 2.2.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class EIPSI_Nudge_Service
 */
class EIPSI_Nudge_Service {
    
    /** Persist the existing UI contract before rebuilding follow-up events. */
    public static function save_wave_configuration($wave_id, $config, $window_supplied = false, $window_minutes = null) {
        global $wpdb;
        $wave = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}survey_waves WHERE id = %d", $wave_id));
        if (!$wave || !is_array($config)) { return new WP_Error('invalid_wave_config', 'Wave o configuración inválida.'); }
        if ($window_supplied && $window_minutes !== null &&
            (!is_scalar($window_minutes) || !ctype_digit((string) $window_minutes) || (int) $window_minutes < 1)) {
            return new WP_Error('invalid_window', 'La ventana debe ser positiva, en minutos.');
        }
        $enabled = false;
        $clean = array();
        foreach (array('nudge_1', 'nudge_2', 'nudge_3', 'nudge_4') as $key) {
            $item = $config[$key] ?? array('enabled' => false, 'value' => 24, 'unit' => 'hours');
            if (!is_array($item) || !isset($item['value'], $item['unit']) || !is_numeric($item['value']) ||
                !is_finite((float) $item['value']) || (float) $item['value'] <= 0 ||
                !in_array($item['unit'], array('minutes', 'hours', 'days'), true)) {
                return new WP_Error('invalid_nudge', 'Offset o unidad inválida: ' . $key);
            }
            $on = filter_var($item['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $clean[$key] = array('enabled' => $on, 'value' => (float) $item['value'], 'unit' => $item['unit']);
            if (isset($item['subject'])) { $clean[$key]['subject'] = sanitize_text_field($item['subject']); }
            $enabled = $enabled || $on;
        }
        // Preserve deadline metadata used by the existing extend/remove flows.
        $stored = json_decode($wave->nudge_config ?? '', true);
        foreach (array('manual_deadline', 'original_nudges', 'original_window_minutes', 'redistributed') as $key) {
            if (isset($stored[$key])) { $clean[$key] = $stored[$key]; }
        }
        $update = array('nudge_config' => wp_json_encode($clean), 'follow_up_reminders_enabled' => $enabled ? 1 : 0);
        if ($window_supplied) { $update['window_minutes'] = $window_minutes === null ? null : (int) $window_minutes; }
        if ($wpdb->query('START TRANSACTION') === false) { return new WP_Error('db_error', 'No se pudo iniciar el guardado.'); }
        $result = $wpdb->update($wpdb->prefix . 'survey_waves', $update, array('id' => $wave_id));
        if ($result === false) { $wpdb->query('ROLLBACK'); return new WP_Error('db_error', 'No se pudo guardar configuración.'); }
        if ($window_supplied && (string) $wave->window_minutes !== (string) $window_minutes) {
            $assignments = $wpdb->get_results($wpdb->prepare(
                "SELECT id, available_at FROM {$wpdb->prefix}survey_assignments WHERE wave_id = %d AND status IN ('pending','in_progress')", $wave_id
            ));
            foreach ($assignments as $assignment) {
                // Without an anchor leave the unanchored assignment intact.
                if (!$assignment->available_at) { continue; }
                if ($window_minutes !== null) {
                    $due_at = date('Y-m-d H:i:s', strtotime($assignment->available_at) + ((int) $window_minutes * 60));
                } else {
                    // NULL restores the existing inferred-window convention.
                    $next_offset = $wpdb->get_var($wpdb->prepare(
                        "SELECT offset_minutes FROM {$wpdb->prefix}survey_waves WHERE study_id = %d AND wave_index > %d ORDER BY wave_index LIMIT 1",
                        $wave->study_id, $wave->wave_index
                    ));
                    if ($next_offset === null) {
                        $next_offset = $wpdb->get_var($wpdb->prepare("SELECT study_end_offset_minutes FROM {$wpdb->prefix}survey_studies WHERE id = %d", $wave->study_id));
                    }
                    $window = (int) $next_offset - (int) $wave->offset_minutes;
                    $due_at = $window > 0 ? date('Y-m-d H:i:s', strtotime($assignment->available_at) + $window * 60) : null;
                }
                if ($wpdb->update($wpdb->prefix . 'survey_assignments', array('due_at' => $due_at), array('id' => $assignment->id)) === false) {
                    $wpdb->query('ROLLBACK'); return new WP_Error('db_error', 'No se pudo actualizar el plazo.');
                }
            }
        }
        if ($wpdb->query('COMMIT') === false) { $wpdb->query('ROLLBACK'); return new WP_Error('db_error', 'No se pudo confirmar configuración.'); }
        require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/services/class-nudge-event-scheduler.php';
        $scheduled = EIPSI_Nudge_Event_Scheduler::refresh_wave_follow_ups($wave_id);
        if (is_wp_error($scheduled)) { return $scheduled; }
        return array('nudge_config' => $clean, 'follow_up_reminders_enabled' => $enabled, 'rows_updated' => $result,
            'window_minutes' => $window_supplied ? $window_minutes : $wave->window_minutes, 'events_scheduled' => $scheduled);
    }

    /**
     * Nudge configurations
     */
    const NUDGE_AVAILABLE = 0;      // "Tu Toma X está lista"
    const NUDGE_FOLLOW_UP = 1;      // "¿Ya completaste?" / "Quedan 2 días"
    const NUDGE_REMINDER = 2;       // "Te esperamos" / "Mañana vence"
    const NUDGE_URGENCY = 3;        // "¿Necesitás ayuda?" / "Plazo extendido"
    const NUDGE_LAST_CALL = 4;      // "Última oportunidad"
    
    /**
     * Get the nudge configuration for a specific stage
     * 
     * @param int $stage Nudge stage (0-4)
     * @param bool $has_due_date Whether the wave has a due date
     * @return array|null Nudge configuration or null if invalid
     */
    public static function get_nudge_config($stage, $has_due_date = false) {
        $configs = self::get_all_nudge_configs($has_due_date);
        return isset($configs[$stage]) ? $configs[$stage] : null;
    }
    
    /**
     * Get all nudge configurations
     * 
     * @param bool $has_due_date Whether the wave has a due date
     * @return array All nudge configurations
     */
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
    
    /**
     * Get timeline preview for UI display
     * 
     * @param bool $has_due_date Whether the wave has a due date
     * @return string Timeline description
     */
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
    
    /**
     * Convert any time unit to seconds for calculations
     * 
     * @param int $value Time value
     * @param string $unit Unit: minutes, hours, days
     * @return int Seconds
     */
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
    
    /**
     * Check if a nudge should be sent now
     * 
     * @param object $assignment Assignment data
     * @param object $wave Wave data
     * @param int $current_stage Current reminder stage
     * @param array $custom_config Optional custom config with unit support
     * @return bool Whether nudge should be sent
     */
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
    
    /**
     * Get the next stage to send
     * 
     * @param int $current_stage Current stage (0-4)
     * @return int|null Next stage or null if completed
     */
    public static function get_next_stage($current_stage) {
        $next = $current_stage + 1;
        return ($next <= self::NUDGE_LAST_CALL) ? $next : null;
    }
    
    /**
     * Get human-readable description of a nudge stage
     * 
     * @param int $stage Stage number
     * @param bool $has_due_date Whether wave has due date
     * @return string Description
     */
    public static function get_stage_description($stage, $has_due_date = false) {
        $config = self::get_nudge_config($stage, $has_due_date);
        return $config ? $config['description'] : '';
    }
}
