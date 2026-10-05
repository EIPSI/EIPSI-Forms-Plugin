<?php
/** M4 longitudinal owner. Existing public adapters preserve their contracts. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Longitudinal_Study_Service {
public static function eipsi_create_study_from_wizard($wizard_data) {
    global $wpdb;

    // Get study data from wizard
    $step_1 = $wizard_data['step_1'];
    $step_2 = $wizard_data['step_2'];
    $step_3 = $wizard_data['step_3'];
    $step_4 = $wizard_data['step_4'];

    // Generate unique study code
    $study_code = eipsi_generate_unique_study_code($step_1['study_code']);

    // Default config: Weekly T1 reminders enabled (v2.6.0)
    $default_config = array(
        'weekly_reminders' => array(
            'enabled' => true,
            'start_after_nudge' => 4,
            'frequency_days' => 7,
            'max_reminders' => null,
            'auto_expire_after' => null
        )
    );

    // Insert study record
    $table_name = $wpdb->prefix . 'survey_studies';

    $study_data = array(
        'study_code' => $study_code,
        'study_name' => $step_1['study_name'],
        'description' => $step_1['description'],
        'principal_investigator_id' => $step_1['principal_investigator_id'],
        'status' => 'active',
        'config' => wp_json_encode($default_config),
        'created_at' => current_time('mysql'),
        'updated_at' => current_time('mysql')
    );
    $study_formats = array('%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s');

    // T1-Anchor: Include study closure offset
    if (isset($step_3['study_end_offset_minutes'])) {
        $study_data['study_end_offset_minutes'] = intval($step_3['study_end_offset_minutes']);
        $study_formats[] = '%d';
    }

    $result = EIPSI_Longitudinal_Study_Repository::insert($study_data,$study_formats);

    if (!$result) {
        return false;
    }

    $study_id = $wpdb->insert_id;

    // Create waves for this study
    eipsi_create_study_waves($study_id, $step_2, $step_3);

    // Store participant configuration
    eipsi_store_participant_config($study_id, $step_4);

    // Auto-create WordPress page for this study with shortcode
    $page_id = eipsi_create_study_page($study_id, $study_code, $step_1['study_name']);

    if ($page_id) {
        error_log('[EIPSI] Created study page ID ' . $page_id . ' for study ' . $study_id);
    }

    return $study_id;
}

public static function set_status($study_id,$status) { return EIPSI_Longitudinal_Study_Repository::update($study_id,array('status'=>$status),array('%s')); }
}
