<?php
/**
 * EIPSI Setup Wizard Controller
 * 
 * Gestiona el flujo paso-a-paso para crear estudios longitudinales.
 * Maneja GET/POST requests, validación, guardado en transient y activación.
 *
 * @package EIPSI_Forms
 * @since 1.5.1
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/longitudinal/bootstrap.php';

// Delay session start until WordPress admin is initialized to avoid headers already sent warning
add_action('admin_init', function() {
    if (!session_id() && !headers_sent()) {
        session_start();
    }
});

/**
 * Display Setup Wizard Page
 */
function eipsi_display_setup_wizard_page() {
    // Require wizard validations
    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/wizard-validators.php';
    
    // Start wizard session
    eipsi_start_wizard_session();
    
    // Handle POST requests (form submissions)
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $result = eipsi_handle_wizard_submission();
        if ($result['success']) {
            wp_redirect($result['redirect_url']);
            exit;
        }
        $errors = $result['errors'];
    }
    
    // Get current step
    $current_step = isset($_GET['step']) ? intval($_GET['step']) : 1;
    $current_step = max(1, min(5, $current_step)); // Clamp between 1-5
    
    // Get wizard data from transient
    $wizard_data = eipsi_get_wizard_data();
    
    // Get available forms for dropdowns
    $available_forms = eipsi_get_available_forms();
    
    // Get admin users for investigator selection
    $admin_users = eipsi_get_admin_users();
    
    // Load the modern wizard template
    include EIPSI_FORMS_PLUGIN_DIR . 'admin/templates/longitudinal-study-wizard.php';
}

/**
 * Start wizard session and initialize transient
 */
function eipsi_start_wizard_session() {
    // Initialize wizard transient if not exists
    $transient_key = eipsi_get_wizard_transient_key();
    if (false === get_transient($transient_key)) {
        $initial_data = array(
            'step_1' => array(), // Basic info
            'step_2' => array(), // Waves configuration
            'step_3' => array(), // Timing configuration
            'step_4' => array(), // Participants configuration
            'step_5' => array(), // Summary/activation
            'current_step' => 1,
            'created_at' => current_time('mysql'),
            'last_updated' => current_time('mysql')
        );
        
        set_transient($transient_key, $initial_data, HOUR_IN_SECONDS * 2); // 2 hours
    }
}

/**
 * Get wizard transient key based on user session
 */
function eipsi_get_wizard_transient_key() {
    $user_id = get_current_user_id();
    $session_id = session_id();
    
    return 'eipsi_wizard_' . $user_id . '_' . substr($session_id, 0, 10);
}

/**
 * Get wizard data from transient
 */
function eipsi_get_wizard_data() {
    $transient_key = eipsi_get_wizard_transient_key();
    $data = get_transient($transient_key);
    
    return $data ? $data : array(
        'step_1' => array(),
        'step_2' => array(),
        'step_3' => array(),
        'step_4' => array(),
        'step_5' => array(),
        'current_step' => 1
    );
}

/**
 * Save wizard step data to transient
 */
function eipsi_save_wizard_step($step_number, $step_data) {
    $transient_key = eipsi_get_wizard_transient_key();
    $wizard_data = eipsi_get_wizard_data();
    
    // Update step data
    $wizard_data['step_' . $step_number] = $step_data;
    $wizard_data['current_step'] = max($wizard_data['current_step'], $step_number);
    $wizard_data['last_updated'] = current_time('mysql');
    
    // Save to transient
    set_transient($transient_key, $wizard_data, HOUR_IN_SECONDS * 2);
    
    return true;
}

/**
 * Handle wizard form submission
 */
function eipsi_handle_wizard_submission() {
    // Verify nonce
    if (!isset($_POST['eipsi_wizard_nonce']) || !wp_verify_nonce($_POST['eipsi_wizard_nonce'], 'eipsi_wizard_action')) {
        return array(
            'success' => false,
            'errors' => array('Security error. Please reload the page.')
        );
    }
    
    $action = sanitize_text_field($_POST['wizard_action']);
    $current_step = intval($_POST['current_step']);
    
    switch ($action) {
        case 'save_step':
            return eipsi_save_step_submission($current_step);
            
        case 'activate_study':
            return eipsi_activate_study_submission();
            
        default:
            return array(
                'success' => false,
                'errors' => array('Invalid action.')
            );
    }
}

/**
 * Save individual step submission
 */
function eipsi_save_step_submission($step_number) {
    $step_data = $_POST;
    
    // Validate step data
    $validation_result = eipsi_validate_step_data($step_number, $step_data);
    
    if (!$validation_result['valid']) {
        return array(
            'success' => false,
            'errors' => $validation_result['errors']
        );
    }
    
    // Sanitize step data
    $sanitized_data = eipsi_sanitize_step_data($step_number, $step_data);
    
    // DEBUG: Log timing intervals with time_unit for step 3
    if ($step_number === 3 && !empty($sanitized_data['timing_intervals'])) {
        error_log('[EIPSI DEBUG] Step 3 sanitized timing_intervals: ' . json_encode($sanitized_data['timing_intervals']));
    }
    
    // Save to transient
    eipsi_save_wizard_step($step_number, $sanitized_data);
    
    // Determine next step
    $next_step = min($step_number + 1, 5);
    
    // Redirect to next step
    $redirect_url = admin_url('admin.php?page=eipsi-longitudinal-study&tab=create-study&step=' . $next_step);
    
    return array(
        'success' => true,
        'redirect_url' => $redirect_url,
        'message' => 'Step saved successfully.'
    );
}

/**
 * Activate study submission
 */
function eipsi_activate_study_submission() {
    $wizard_data = eipsi_get_wizard_data();
    
    // Validate all steps are complete
    for ($i = 1; $i <= 4; $i++) {
        if (empty($wizard_data['step_' . $i])) {
            return array(
                'success' => false,
                'errors' => array('You must complete all steps before activating the study.')
            );
        }
    }
    
    // Validate activation confirmation
    if (!isset($_POST['activation_confirmed']) || $_POST['activation_confirmed'] !== '1') {
        return array(
            'success' => false,
            'errors' => array('You must confirm study activation.')
        );
    }
    
    // Create the study
    $study_id = eipsi_create_study_from_wizard($wizard_data);
    
    if (!$study_id) {
        return array(
            'success' => false,
            'errors' => array('Error creating the study. Please try again.')
        );
    }
    
    // Clear wizard transient
    $transient_key = eipsi_get_wizard_transient_key();
    delete_transient($transient_key);
    
    // Redirect to study dashboard (to be implemented in Task 1.5.2)
    $redirect_url = admin_url('admin.php?page=eipsi-longitudinal-study&tab=dashboard-study&study_id=' . $study_id);
    
    return array(
        'success' => true,
        'redirect_url' => $redirect_url,
        'message' => 'Study created successfully.'
    );
}

/**
 * Create study from wizard data
 */
function eipsi_create_study_from_wizard($wizard_data) {
        return EIPSI_Longitudinal_Study_Service::eipsi_create_study_from_wizard($wizard_data);
    }

/**
 * Auto-create WordPress page for study with shortcode
 *
 * @param int    $study_id   Study ID from wp_survey_studies
 * @param string $study_code Unique study code
 * @param string $study_name Study name
 * @return int|false Page ID or false on failure
 * @since 1.7.0
 */
function eipsi_create_study_page($study_id, $study_code, $study_name) {
    // Check if page already exists for this study
    $existing_page = get_page_by_path('estudio-' . sanitize_title($study_code));
    
    if (!$existing_page) {
        // Check by meta field as well
        $existing_pages = get_posts(array(
            'post_type' => 'page',
            'meta_key' => 'eipsi_study_id',
            'meta_value' => $study_id,
            'posts_per_page' => 1
        ));
        
        if (!empty($existing_pages)) {
            $existing_page = $existing_pages[0];
        }
    }
    
    if ($existing_page) {
        // Page exists, update it
        update_post_meta($existing_page->ID, 'eipsi_study_code', $study_code);
        update_post_meta($existing_page->ID, 'eipsi_study_id', $study_id);
        return $existing_page->ID;
    }
    
    // Create new page with proper Shortcode block (not Classic block)
    $page_title = sprintf(__('Estudio: %s', 'eipsi-forms'), $study_name);
    $page_slug = 'estudio-' . sanitize_title($study_code);
    $shortcode = '[eipsi_longitudinal_study study_code="' . esc_attr($study_code) . '"]';

    $page_id = eipsi_create_shortcode_page(
        $page_title,
        $page_slug,
        $shortcode,
        array(
            'meta_input' => array(
                'eipsi_study_code' => $study_code,
                'eipsi_study_id' => $study_id
            )
        )
    );

    if (is_wp_error($page_id)) {
        error_log('[EIPSI] Failed to create study page: ' . $page_id->get_error_message());
        return false;
    }

    return $page_id;
}

/**
 * Get the study page URL for a study
 *
 * @param int    $study_id   Study ID
 * @param string $study_code Study code (optional, will be fetched if not provided)
 * @return string|null Page URL or null if no page exists
 * @since 1.7.0
 */
function eipsi_get_study_page_url($study_id, $study_code = '') {
    // Try to find by meta field first
    $pages = get_posts(array(
        'post_type' => 'page',
        'meta_key' => 'eipsi_study_id',
        'meta_value' => $study_id,
        'posts_per_page' => 1
    ));
    
    if (!empty($pages)) {
        return get_permalink($pages[0]->ID);
    }
    
    // Try by study code
    if (!empty($study_code)) {
        $page = get_page_by_path('estudio-' . sanitize_title($study_code));
        if ($page) {
            return get_permalink($page->ID);
        }
    }
    
    return null;
}

/**
 * Generate unique study code
 */
function eipsi_generate_unique_study_code($requested_code) {
        return EIPSI_Longitudinal_Study_Repository::eipsi_generate_unique_study_code($requested_code);
    }

/**
 * Create waves for study
 * 
 * @param int   $study_id       Study ID
 * @param array $wave_config    Wave configuration from wizard step 2
 * @param array $timing_config  Timing configuration from wizard step 3
 * @return bool Success status
 */
function eipsi_create_study_waves($study_id, $wave_config, $timing_config) {
    if (empty($wave_config['waves_config']) || !is_array($wave_config['waves_config'])) {
        error_log('[EIPSI] No waves configured for study ' . $study_id);
        return false;
    }

    // Extract timing configuration with defaults
    $reminder_days = isset($timing_config['reminder_days_before']) ? absint($timing_config['reminder_days_before']) : 3;
    $retry_enabled = isset($timing_config['enable_retries']) ? (int)(bool)$timing_config['enable_retries'] : 1;
    $retry_days = isset($timing_config['retry_after_days']) ? absint($timing_config['retry_after_days']) : 7;
    $max_retries = isset($timing_config['max_retries']) ? absint($timing_config['max_retries']) : 3;

    // Extract accumulated offsets (from step 3 - T1-Anchor System)
    $timing_intervals = isset($timing_config['timing_intervals']) ? $timing_config['timing_intervals'] : array();
    
    error_log(sprintf('[EIPSI WIZARD] Creating waves for study_id=%d, wave_count=%d, study_end_offset=%d min', 
        $study_id, 
        count($wave_config['waves_config']), 
        isset($timing_config['study_end_offset_minutes']) ? $timing_config['study_end_offset_minutes'] : 0));
    
    // Build offset, interval, and window maps
    $offset_map = array();
    $interval_map = array();
    $time_unit_map = array();
    $window_map = array();

    // Extract window_minutes from timing_intervals (stored in transient from Step 3)
    foreach ($timing_intervals as $interval) {
        if (isset($interval['window_minutes'])) {
            $interval_key = intval($interval['from_wave']); // T2=0, T3=1, etc.
            $window_map[$interval_key] = intval($interval['window_minutes']);
            error_log(sprintf('[EIPSI WIZARD] Window config: T%d window = %d min', 
                $interval_key + 2, $window_map[$interval_key]));
        }
    }

    foreach ($timing_intervals as $interval) {
        if (isset($interval['from_wave'])) {
            $interval_key = intval($interval['from_wave']);
            
            // New system: accumulated offset
            if (isset($interval['offset_minutes'])) {
                $offset_map[$interval_key] = intval($interval['offset_minutes']);
                error_log(sprintf('[EIPSI WIZARD] Timing config: T%d offset = %d min', 
                    $interval_key + 1, $offset_map[$interval_key]));
            }
            
            // Legacy/Fallback: relative interval
            if (isset($interval['days_after'])) {
                $interval_map[$interval_key] = absint($interval['days_after']);
                $time_unit_map[$interval_key] = isset($interval['time_unit']) && $interval['time_unit'] === 'minutes' ? 'minutes' : 'days';
            }
        }
    }

    $created_count = 0;

    foreach ($wave_config['waves_config'] as $index => $wave) {
        // Map wizard fields to wave service format
        $wave_data = array(
            'name' => sanitize_text_field($wave['name'] ?? ('Toma ' . ($index + 1))),
            'wave_index' => absint($wave['wave_index'] ?? ($index + 1)),
            'form_id' => absint($wave['form_template_id'] ?? 0),
            'is_mandatory' => isset($wave['is_required']) ? (int)(bool)$wave['is_required'] : 1,
            'status' => 'active',
            'reminder_days' => $reminder_days,
            'retry_enabled' => $retry_enabled,
            'retry_days' => $retry_days,
            'max_retries' => $max_retries,
            'follow_up_reminders_enabled' => 1,
        );

        $wave_index = $wave_data['wave_index'];
        $interval_key = $wave_index - 2; // T2(2)→0, T3(3)→1, etc.

        if ($wave_index > 1) {
            // T1-Anchor System: Use offset_minutes (absolute time from T1)
            if (isset($offset_map[$interval_key])) {
                $wave_data['offset_minutes'] = $offset_map[$interval_key];
            } else {
                // Fallback: default weekly intervals
                $wave_data['offset_minutes'] = ($wave_index - 1) * 10080; // 7 days in minutes
            }
        } else {
            // First wave (T1) - always available immediately
            $wave_data['offset_minutes'] = 0;
        }

        // Get window_minutes for this wave (T2+ only)
        $window_minutes = 0;
        if ($wave_index > 1) {
            $window_idx = $wave_index - 2; // T2=0, T3=1, etc.
            if (isset($window_map[$window_idx])) {
                $window_minutes = $window_map[$window_idx];
                $wave_data['window_minutes'] = $window_minutes;
                error_log(sprintf('[EIPSI WIZARD] Wave T%d: window_minutes = %d min', 
                    $wave_index, $window_minutes));
            } else {
                // Fallback: calculate from offset to next wave
                $next_wave_key = $interval_key + 1;
                if (isset($offset_map[$next_wave_key])) {
                    $window_minutes = $offset_map[$next_wave_key] - $wave_data['offset_minutes'];
                } else if (isset($timing_config['study_end_offset_minutes'])) {
                    $window_minutes = $timing_config['study_end_offset_minutes'] - $wave_data['offset_minutes'];
                } else {
                    $window_minutes = 10080; // 7 days
                }
                $wave_data['window_minutes'] = $window_minutes;
                error_log(sprintf('[EIPSI WIZARD] Wave T%d: window_minutes (fallback) = %d min', 
                    $wave_index, $window_minutes));
            }
        } else {
            // T1: use interval to T2 as window
            if (isset($offset_map[0])) {
                $window_minutes = $offset_map[0];
            } else {
                $window_minutes = 10080; // 7 days default
            }
            $wave_data['window_minutes'] = $window_minutes;
            error_log(sprintf('[EIPSI WIZARD] Wave T1: window_minutes = %d min', $window_minutes));
        }

        // Distribute nudges proportionally within the WINDOW (not interval)
        // nudge1: 20%, nudge2: 40%, nudge3: 60%, nudge4: 80%
        
        if ($window_minutes < 1440) {
            // Short window: use minutes
            $nudge1_minutes = max(1, round($window_minutes * 0.20));
            $nudge2_minutes = max(1, round($window_minutes * 0.40));
            $nudge3_minutes = max(1, round($window_minutes * 0.60));
            $nudge4_minutes = max(1, round($window_minutes * 0.80));
            
            // Convert to hours for storage
            $nudge1_hours = round($nudge1_minutes / 60, 2);
            $nudge2_hours = round($nudge2_minutes / 60, 2);
            $nudge3_hours = round($nudge3_minutes / 60, 2);
            $nudge4_hours = round($nudge4_minutes / 60, 2);
        } else {
            // Long window: use hours
            $nudge1_hours = round($window_minutes * 0.20 / 60, 2);
            $nudge2_hours = round($window_minutes * 0.40 / 60, 2);
            $nudge3_hours = round($window_minutes * 0.60 / 60, 2);
            $nudge4_hours = round($window_minutes * 0.80 / 60, 2);
        }

        error_log(sprintf('[EIPSI WIZARD] Wave T%d nudges calculated: n1=%.2fh (20%%), n2=%.2fh (40%%), n3=%.2fh (60%%), n4=%.2fh (80%%) | window=%dmin', 
            $wave_index, $nudge1_hours, $nudge2_hours, $nudge3_hours, $nudge4_hours, $window_minutes));

        $wave_data['nudge_config'] = json_encode(array(
            'nudge_1' => array('enabled' => true, 'value' => $nudge1_hours, 'unit' => 'hours'),
            'nudge_2' => array('enabled' => true, 'value' => $nudge2_hours, 'unit' => 'hours'),
            'nudge_3' => array('enabled' => true, 'value' => $nudge3_hours, 'unit' => 'hours'),
            'nudge_4' => array('enabled' => true, 'value' => $nudge4_hours, 'unit' => 'hours'),
        ));

        // Skip if no form_id
        if (empty($wave_data['form_id'])) {
            error_log('[EIPSI] Skipping wave ' . $wave_data['name'] . ' - no form template');
            continue;
        }

        // Create wave using service
        $result = EIPSI_Wave_Service::create_wave($study_id, $wave_data);

        if (is_wp_error($result)) {
            error_log('[EIPSI] Error creating wave: ' . $result->get_error_message());
        } else {
            $created_count++;
        }
    }

    error_log('[EIPSI] Created ' . $created_count . ' waves for study ' . $study_id);
    return $created_count > 0;
}

/**
 * Store participant configuration
 */
function eipsi_store_participant_config($study_id, $participant_config) {
    // TODO: Store participant configuration in study settings
    // This could be stored in wp_options or a separate study_config table
    
    error_log('[EIPSI] Storing participant config for study ' . $study_id . ': ' . json_encode($participant_config));
    
    return true;
}

/**
 * Get available forms for dropdowns
 * 
 * Busca tanto en form templates como en páginas con formularios activos
 */
function eipsi_get_available_forms() {
    return eipsi_get_available_forms_for_wizard();
}

/**
 * Get admin users for investigator selection
 */
function eipsi_get_admin_users() {
    $users = get_users(array(
        'role__in' => array('administrator', 'editor'),
        'orderby' => 'display_name',
        'order' => 'ASC'
    ));
    
    return $users;
}