<?php
/** M6 owner; existing public adapters retain their contracts. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Local_Submission_Store {

public static function insert($data) {
 global $wpdb;
 require_once EIPSI_FORMS_PLUGIN_DIR."admin/privacy-config.php";
 $data=eipsi_filter_capture_data($data,get_privacy_config($data["form_id"]??null));
    // Fallback a WordPress DB
    $table_name = $wpdb->prefix . 'vas_form_results';
    
    // Internal authorization context is for participant sync, not a schema column.
    $submission_data = $data;
    unset($submission_data['longitudinal_participant_id']);
    $wpdb_result = $wpdb->insert($table_name, $submission_data);
    
    if ($wpdb_result === 1 && $wpdb->insert_id > 0) {
        return array(
            'success' => true,
            'insert_id' => $wpdb->insert_id,
            'storage' => 'wordpress_db',
            'timestamp' => current_time('mysql'),
        );
    }
    
    return array(
        'success' => false,
        'error' => $wpdb->last_error,
        'error_code' => 'DB_INSERT_FAILED',
    );

}
}
