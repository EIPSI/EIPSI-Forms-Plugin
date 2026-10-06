<?php
/**
 * AJAX handler for checking wave state
 * Used by auto-refresh system to detect backend changes
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Check current wave state for participant
 * Returns wave status, availability, and next wave info
 */
add_action('wp_ajax_eipsi_check_wave_state', 'eipsi_ajax_check_wave_state');
add_action('wp_ajax_nopriv_eipsi_check_wave_state', 'eipsi_ajax_check_wave_state');

function eipsi_ajax_check_wave_state() {
    global $wpdb;
    
    // Get current participant
    if (!class_exists('EIPSI_Auth_Service')) {
        wp_send_json_error('Auth service not available');
    }
    
    if (!is_string($_POST['nonce'] ?? null) || !wp_verify_nonce($_POST['nonce'], 'eipsi_auto_refresh')) {
        wp_send_json_error('Invalid nonce', 403);
    }
    $access = EIPSI_Authorization_Policy::authorize_session_context($_POST);
    if (!$access['success']) { wp_send_json_error('Not authenticated', 403); }
    $participant_id = $access['participant_id'];
    $study_id = $access['study_id'];
    $record = EIPSI_Authorization_Policy::authorize_participant($participant_id, $study_id);
    if (!$record['success']) { wp_send_json_error('Not authenticated', 403); }

    $wave_id = isset($_POST['wave_id']) ? intval($_POST['wave_id']) : 0;
    
    if (!$wave_id) {
        wp_send_json_error('Invalid wave_id');
    }
    
    // Longitudinal owns wave lookup; schema name maps to public wave_name.
    $wave = EIPSI_Longitudinal_Wave_Definition_Service::get_wave($wave_id);
    if (!$wave) { wp_send_json_error('Wave not found'); }
    if ((int)$wave->study_id !== $study_id) { wp_send_json_error('Not authorized', 403); }

    // Get assignment for this wave
    $assignment = $wpdb->get_row($wpdb->prepare(
        "SELECT status, available_at, due_at, submitted_at
        FROM {$wpdb->prefix}survey_assignments
        WHERE participant_id = %d AND wave_id = %d AND study_id = %d",
        $participant_id,
        $wave_id,
        $study_id
    ));
    
    if (!$assignment) {
        wp_send_json_error('Assignment not found');
    }
    
    // Check if wave is locked (available_at in future)
    $is_locked = false;
    $seconds_until_available = 0;
    
    if (!empty($assignment->available_at)) {
        $available_timestamp = strtotime($assignment->available_at);
        $now = current_time('timestamp');
        
        if ($available_timestamp > $now) {
            $is_locked = true;
            $seconds_until_available = $available_timestamp - $now;
        }
    }
    
    // Check if wave was skipped
    $was_skipped = in_array($assignment->status, array('skipped', 'expired'));
    
    // Get next available wave (if current was skipped)
    $next_wave_id = null;
    if ($was_skipped) {
        $next_wave = $wpdb->get_row($wpdb->prepare(
            "SELECT w.id, a.status
            FROM {$wpdb->prefix}survey_waves w
            LEFT JOIN {$wpdb->prefix}survey_assignments a 
                ON w.id = a.wave_id AND a.participant_id = %d
            WHERE w.study_id = %d
            AND w.wave_index > %d
            AND (a.status IS NULL OR a.status NOT IN ('expired', 'skipped', 'submitted'))
            ORDER BY w.wave_index ASC
            LIMIT 1",
            $participant_id,
            $wave->study_id,
            $wave->wave_index
        ));
        
        if ($next_wave) {
            $next_wave_id = $next_wave->id;
        }
    }
    
    // Log the check
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log(sprintf(
            '[EIPSI State Check] participant=%d, wave=%d, status=%s, locked=%s, skipped=%s',
            $participant_id,
            $wave_id,
            $assignment->status,
            $is_locked ? 'yes' : 'no',
            $was_skipped ? 'yes' : 'no'
        ));
    }
    
    // Return state
    wp_send_json_success(array(
        'wave_id' => $wave_id,
        'wave_name' => $wave->name,
        'status' => $assignment->status,
        'is_locked' => $is_locked,
        'seconds_until_available' => $seconds_until_available,
        'was_skipped' => $was_skipped,
        'next_wave_id' => $next_wave_id,
        'available_at' => $assignment->available_at,
        'timestamp' => current_time('mysql'),
    ));
}
