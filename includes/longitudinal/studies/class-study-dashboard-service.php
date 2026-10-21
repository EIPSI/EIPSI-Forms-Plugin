<?php
/** M4 longitudinal owner. Existing public adapters preserve their contracts. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Longitudinal_Study_Dashboard_Service {
public static function wp_ajax_eipsi_get_study_overview_handler($study_id,$request=array(),$query=array()) {    global $wpdb;

    // 1. General study info (usar 'id' como PK, no 'study_id')
    $study = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}survey_studies WHERE id = %d",
        $study_id
    ));

    if (!$study) {
        return EIPSI_Form_Response::error('Study not found');
    }

    // Ensure study_code is always populated for shortcode display
    // If study_code is empty, generate one from study_name or use ID as fallback
    if (empty($study->study_code)) {
        // Try to generate from study_name
        if (!empty($study->study_name)) {
            $generated_code = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '_', substr($study->study_name, 0, 15)));
            $generated_code = preg_replace('/_+/', '_', $generated_code); // Remove double underscores
            $generated_code = trim($generated_code, '_') . '_' . date('Y');

            // Update the study with the generated code
            $wpdb->update(
                "{$wpdb->prefix}survey_studies",
                array('study_code' => $generated_code),
                array('id' => $study_id),
                array('%s'),
                array('%d')
            );

            $study->study_code = $generated_code;
        } else {
            // Last resort: use the numeric ID
            $study->study_code = 'STUDY_' . $study_id;
        }
    }

    // Get study page URL
    $study_page_url = null;
    $study_page_id = null;
    if (function_exists('eipsi_get_study_page_url')) {
        $study_page_url = eipsi_get_study_page_url($study_id, $study->study_code);

        // Get page ID for edit link
        $pages = get_posts(array(
            'post_type' => 'page',
            'meta_key' => 'eipsi_study_id',
            'meta_value' => $study_id,
            'posts_per_page' => 1
        ));
        if (!empty($pages)) {
            $study_page_id = $pages[0]->ID;
        }
    }

    // If no page exists, create one
    if (empty($study_page_url) && function_exists('eipsi_create_study_page')) {
        $study_page_id = eipsi_create_study_page($study_id, $study->study_code, $study->study_name ?? 'Estudio');
        if ($study_page_id) {
            $study_page_url = get_permalink($study_page_id);
        }
    }

    // 2. Participant stats
    // La tabla participants usa 'survey_id' (que es el ID del estudio), no 'study_id'
    $participants_stats = array(
        'total' => (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}survey_participants WHERE survey_id = %d",
            $study_id
        )),
        'active' => (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}survey_participants
             WHERE survey_id = %d AND is_active = 1",
            $study_id
        )),
        'completed' => (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT participant_id) FROM {$wpdb->prefix}survey_assignments
             WHERE study_id = %d AND status = 'submitted'",
            $study_id
        )),
        'paused' => (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT participant_id) FROM {$wpdb->prefix}survey_assignments
             WHERE study_id = %d AND status = 'paused'",
            $study_id
        )),
        'in_progress' => (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT participant_id) FROM {$wpdb->prefix}survey_assignments
             WHERE study_id = %d AND status = 'in_progress'",
            $study_id
        )),
        'inactive' => (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}survey_participants
             WHERE survey_id = %d AND is_active = 0",
            $study_id
        )),
    );

    // 3. Waves stats
    $waves = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}survey_waves WHERE study_id = %d ORDER BY wave_index ASC",
        $study_id
    ));

    // T1-Anchor: Check if T1 has a deadline set
    $t1_deadline = null;
    $t1_deadline_timestamp = null;
    $t1_dynamic_window_days = null; // Ventana dinámica: días desde created_at hasta deadline
    foreach ($waves as $wave) {
        error_log(sprintf('[EIPSI DASHBOARD API] Checking wave %d: offset_minutes=%d, due_date=%s',
            $wave->id,
            $wave->offset_minutes,
            $wave->due_date ?? 'NULL'
        ));
        if ($wave->offset_minutes == 0 && !empty($wave->due_date)) {
            $t1_deadline = $wave->due_date;
            // Use end of T1 deadline day (23:59:59) as the anchor point
            // Subsequent waves become available starting from this timestamp
            // If due_date already has time, use it; otherwise add 23:59:59
            if (strpos($wave->due_date, ':') !== false) {
                // Already has time component, replace with 23:59:59
                $t1_deadline_timestamp = strtotime(date('Y-m-d', strtotime($wave->due_date)) . ' 23:59:59');
            } else {
                $t1_deadline_timestamp = strtotime($wave->due_date . ' 23:59:59');
            }

            // Calculate dynamic window: days from study creation to T1 deadline
            $study_created_timestamp = strtotime($study->created_at);
            $t1_dynamic_window_days = ceil(($t1_deadline_timestamp - $study_created_timestamp) / 86400);

            error_log(sprintf('[EIPSI DASHBOARD API] T1 deadline detected: %s (timestamp: %d), dynamic window: %d days',
                $t1_deadline, $t1_deadline_timestamp, $t1_dynamic_window_days));
            break;
        }
    }

    $waves_stats = array();
    $previous_wave_completed_ids = null; // Track who completed previous wave
    $previous_wave_deadline_timestamp = $t1_deadline_timestamp; // Track previous wave's deadline for sequential calculation

    foreach ($waves as $index => $wave) {
        $completed_assignments = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}survey_assignments WHERE wave_id = %d AND status = 'submitted'",
            $wave->id
        ));

        // Calculate eligible participants (those who can actually do this wave)
        if ($index === 0) {
            // First wave: all active participants are eligible
            $eligible_participants = $participants_stats['active'];
        } else {
            // Subsequent waves: only those who completed the previous wave
            $eligible_participants = count($previous_wave_completed_ids);
        }

        // Pending = eligible - completed (those who should do it but haven't)
        $pending_participants = max(0, $eligible_participants - $completed_assignments);

        // Get participant IDs who completed this wave (for next iteration)
        $previous_wave_completed_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT participant_id FROM {$wpdb->prefix}survey_assignments WHERE wave_id = %d AND status = 'submitted'",
            $wave->id
        ));

        // v2.1.2: Add interval configuration for visual verification
        // This helps confirm that "minutes = minutes" and not accidentally "days"

        // v2.3.0 - Wave Manager Enhancement: Configuración de recordatorios
        $due_date_formatted = !empty($wave->due_date)
            ? date_i18n(get_option('date_format'), strtotime($wave->due_date))
            : __('Sin fecha límite', 'eipsi-forms');

        // Timeline de recordatorios según configuración
        $reminder_days = !empty($wave->reminder_days) ? $wave->reminder_days : '';
        $timeline = '';
        if (!empty($wave->due_date) && !empty($reminder_days)) {
            $days_array = array_map('intval', explode(',', $reminder_days));
            sort($days_array);
            $timeline = implode('d → ', $days_array) . 'd';
        } else {
            $timeline = '3d → 7d → 14d → 30d'; // Default SIN due_date
        }

        // v2.3.0 - Nudge configuration JSON (default OFF for follow-ups)
        $nudge_config_raw = isset($wave->nudge_config) ? $wave->nudge_config : '';
        $nudge_config = !empty($nudge_config_raw) ? json_decode($nudge_config_raw, true) : array();

        // Log what we read from DB
        if (!empty($nudge_config_raw)) {
            error_log(sprintf('[EIPSI DASHBOARD API] Wave id=%d "%s": nudge_config from DB (length=%d): %s',
                $wave->id, $wave->name, strlen($nudge_config_raw), $nudge_config_raw));
        } else {
            error_log(sprintf('[EIPSI DASHBOARD API] Wave id=%d "%s": NO nudge_config in DB, will use legacy defaults',
                $wave->id, $wave->name));
        }

        // Fallback defaults for legacy waves without nudge_config
        // NOTE: New waves created via wizard have proportional nudges calculated based on interval
        // These defaults are only used for old waves created before the proportional system
        $default_nudge_config = array(
            'nudge_1' => array('enabled' => true, 'value' => 24, 'unit' => 'hours'),
            'nudge_2' => array('enabled' => true, 'value' => 72, 'unit' => 'hours'),
            'nudge_3' => array('enabled' => true, 'value' => 168, 'unit' => 'hours'),
            'nudge_4' => array('enabled' => true, 'value' => 336, 'unit' => 'hours'),
        );

        $nudge_config = wp_parse_args($nudge_config, $default_nudge_config);

        error_log(sprintf('[EIPSI DASHBOARD API] Wave id=%d "%s": offset_minutes=%d, window_minutes=%s',
            $wave->id, $wave->name,
            isset($wave->offset_minutes) ? intval($wave->offset_minutes) : 0,
            isset($wave->window_minutes) ? (is_null($wave->window_minutes) ? 'NULL' : intval($wave->window_minutes)) : 'NOT SET'));

        // Check if any follow-up is enabled (for toggle display)
        $follow_ups_enabled = $nudge_config['nudge_1']['enabled'] ||
                              $nudge_config['nudge_2']['enabled'] ||
                              $nudge_config['nudge_3']['enabled'] ||
                              $nudge_config['nudge_4']['enabled'];

        // T1-Anchor: Calculate absolute availability when T1 has deadline (sequential)
        $absolute_available_at = null;
        $absolute_available_at_formatted = null;
        error_log(sprintf('[EIPSI DASHBOARD API] Wave %d: t1_deadline_timestamp=%s, offset_minutes=%d, previous_wave_deadline_timestamp=%s',
            $wave->id,
            $t1_deadline_timestamp ? date('Y-m-d H:i:s', $t1_deadline_timestamp) : 'NULL',
            $wave->offset_minutes,
            $previous_wave_deadline_timestamp ? date('Y-m-d H:i:s', $previous_wave_deadline_timestamp) : 'NULL'
        ));
        if ($t1_deadline_timestamp && $wave->offset_minutes > 0) {
            // This wave opens when the previous wave closes (sequential)
            $absolute_available_at = date('Y-m-d H:i:s', $previous_wave_deadline_timestamp);
            $absolute_available_at_formatted = date_i18n(get_option('date_format'), $previous_wave_deadline_timestamp);
            error_log(sprintf('[EIPSI DASHBOARD API] Wave %d: Calculated absolute_available_at=%s', $wave->id, $absolute_available_at));

            // Calculate when THIS wave closes (for next wave's opening)
            if (!empty($wave->due_date)) {
                // Use wave's actual deadline (manual or auto-calculated)
                // If due_date already has time, replace with 23:59:59
                if (strpos($wave->due_date, ':') !== false) {
                    $previous_wave_deadline_timestamp = strtotime(date('Y-m-d', strtotime($wave->due_date)) . ' 23:59:59');
                } else {
                    $previous_wave_deadline_timestamp = strtotime($wave->due_date . ' 23:59:59');
                }
            } else if ($wave->window_minutes > 0) {
                // Calculate from available_at + window
                $previous_wave_deadline_timestamp = $previous_wave_deadline_timestamp + ($wave->window_minutes * 60);
            }
        }

        $waves_stats[] = array(
            'id' => $wave->id,
            'wave_name' => $wave->name,
            'form_id' => $wave->form_id,
            'deadline' => $wave->due_date,
            'deadline_formatted' => $due_date_formatted,
            'status' => $wave->status,
            // T1-Anchor: relative timing fields
            'offset_minutes' => isset($wave->offset_minutes) ? intval($wave->offset_minutes) : 0,
            'window_minutes' => isset($wave->window_minutes) ? (is_null($wave->window_minutes) ? null : intval($wave->window_minutes)) : null,
            // T1-Anchor: absolute availability when T1 has deadline
            'absolute_available_at' => $absolute_available_at,
            'absolute_available_at_formatted' => $absolute_available_at_formatted,
            't1_has_deadline' => !empty($t1_deadline),
            't1_dynamic_window_days' => ($wave->offset_minutes == 0 && $t1_dynamic_window_days) ? $t1_dynamic_window_days : null,
            // Logical calculation: only count those who are actually eligible
            'total' => $eligible_participants, // Total eligible (can do this wave)
            'completed' => $completed_assignments,
            'pending' => $pending_participants, // Those who should do it but haven't
            'progress' => ($eligible_participants > 0) ? round(($completed_assignments / $eligible_participants) * 100) : 0,
            'reminders_sent' => 0, // TODO: Implement reminder tracking
            'wave_index' => intval($wave->wave_index),
            // v2.3.0 - Nuevos campos para wave manager
            'follow_up_reminders_enabled' => $follow_ups_enabled,
            'reminder_days' => $reminder_days,
            'reminder_timeline' => $timeline,
            'has_due_date' => !empty($wave->due_date),
            'nudge_config' => $nudge_config // Granular configuration per nudge
        );
    }

    // 4. Email stats
    // La tabla email_log usa survey_id (INT), no study_id
    $emails_stats = array(
        'sent_today' => (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}survey_email_log
             WHERE survey_id = %d AND DATE(sent_at) = CURDATE()",
            $study_id
        )),
        'failed' => (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}survey_email_log
             WHERE survey_id = %d AND status = 'failed'",
            $study_id
        )),
        'last_sent' => $wpdb->get_var($wpdb->prepare(
            "SELECT sent_at FROM {$wpdb->prefix}survey_email_log
             WHERE survey_id = %d ORDER BY sent_at DESC LIMIT 1",
            $study_id
        )),
    );

    return EIPSI_Form_Response::success(array(
        'general' => $study,
        'participants' => $participants_stats,
        'waves' => $waves_stats,
        'emails' => $emails_stats,
        'page' => array(
            'url' => $study_page_url,
            'id' => $study_page_id,
            'edit_url' => $study_page_id ? get_edit_post_link($study_page_id, 'raw') : null,
            'shortcode' => '[eipsi_longitudinal_study study_code="' . $study->study_code . '"]'
        )
    ));
}

public static function wp_ajax_eipsi_close_study_handler($study_id,$request=array(),$query=array()) {    global $wpdb;

    $study = $wpdb->get_row($wpdb->prepare(
        "SELECT id, study_name, status FROM {$wpdb->prefix}survey_studies WHERE id = %d",
        $study_id
    ));

    if (!$study) {
        return EIPSI_Form_Response::error(array('message' => __('Study not found', 'eipsi-forms')));
    }

    $updated = EIPSI_Longitudinal_Study_Repository::update($study_id,array('status'=>'completed','updated_at'=>current_time('mysql')),array('%s','%s'));

    if ($updated === false) {
        return EIPSI_Form_Response::error(array('message' => __('No se pudo cerrar el estudio.', 'eipsi-forms')));
    }

    return EIPSI_Form_Response::success(array(
        'message' => sprintf(__('Estudio "%s" cerrado correctamente.', 'eipsi-forms'), $study->study_name),
        'status' => 'completed'
    ));
}
}
