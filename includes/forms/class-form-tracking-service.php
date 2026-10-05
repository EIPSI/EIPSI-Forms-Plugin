<?php
/** M3 owner; external contracts remain behind their existing facades. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Form_Tracking_Service {
    public static function handle($request, $server = array()) {
    // Define allowed event types
    $allowed_events = array('view', 'start', 'page_change', 'submit', 'abandon', 'branch_jump');

    // Sanitize and validate POST data
    $event_type = isset($request['event_type']) ? sanitize_text_field($request['event_type']) : '';

    // Validate event type
    if (empty($event_type) || !in_array($event_type, $allowed_events, true)) {
        return EIPSI_Form_Response::error(array(
            'message' => __('Invalid event type.', 'eipsi-forms')
        ), 400);
        return;
    }

    // Sanitize other required fields
    $form_id = isset($request['form_id']) ? sanitize_text_field($request['form_id']) : '';
    $session_id = isset($request['session_id']) ? sanitize_text_field($request['session_id']) : '';

    // Validate required fields
    if (empty($session_id)) {
        return EIPSI_Form_Response::error(array(
            'message' => __('Missing required field: session_id.', 'eipsi-forms')
        ), 400);
        return;
    }

    // Sanitize optional fields
    $page_number = isset($request['page_number']) ? intval($request['page_number']) : null;
    $user_agent = isset($request['user_agent']) ? sanitize_text_field($request['user_agent']) : '';

    // Collect metadata for branch_jump events
    $metadata = null;
    if ($event_type === 'branch_jump') {
        $metadata = array();
        if (isset($request['from_page'])) {
            $metadata['from_page'] = intval($request['from_page']);
        }
        if (isset($request['to_page'])) {
            $metadata['to_page'] = intval($request['to_page']);
        }
        if (isset($request['field_id'])) {
            $metadata['field_id'] = sanitize_text_field($request['field_id']);
        }
        if (isset($request['matched_value'])) {
            $metadata['matched_value'] = sanitize_text_field($request['matched_value']);
        }
        $metadata = !empty($metadata) ? wp_json_encode($metadata) : null;
    }

    // Prepare data for database insertion
    global $wpdb;

    $insert_data = array(
        'form_id' => $form_id,
        'session_id' => $session_id,
        'event_type' => $event_type,
        'page_number' => $page_number,
        'metadata' => $metadata,
        'user_agent' => $user_agent,
        'created_at' => current_time('mysql')
    );

    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/privacy-config.php';
    $config = get_privacy_config($form_id);
    $insert_data = eipsi_filter_capture_data($insert_data, $config);

    // Check if external database is configured
    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/database.php';
    $db_helper = new EIPSI_External_Database();
    $external_db_enabled = $db_helper->is_enabled();
    $used_fallback = false;

    if ($external_db_enabled) {
        // Try external database first
        $result = $db_helper->insert_form_event($insert_data);

        if ($result['success']) {
            // External DB insert succeeded
            return EIPSI_Form_Response::success(array(
                'message' => __('Event tracked successfully.', 'eipsi-forms'),
                'event_id' => $result['insert_id'],
                'tracked' => true,
                'external_db' => true
            ));
            return;
        } else {
            // External DB failed, fall back to WordPress DB
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('EIPSI Tracking: External DB insert failed, falling back to WordPress DB - ' . $result['error']);
            }
            $used_fallback = true;
        }
    }

    // Use WordPress database (either as default or as fallback)
    $table_name = $wpdb->prefix . 'vas_form_events';
    $insert_formats = array('%s', '%s', '%s', '%d', '%s', '%s', '%s');

    $wpdb_result = $wpdb->insert($table_name, $insert_data, $insert_formats);

    // Check for database errors
    if ($wpdb_result === false) {
        // Log error but don't crash tracking
        error_log('EIPSI Tracking: Failed to insert event - ' . $wpdb->last_error);

        // Still return success to keep tracking JS resilient
        return EIPSI_Form_Response::success(array(
            'message' => __('Event logged.', 'eipsi-forms'),
            'event_id' => null,
            'logged' => true
        ));
        return;
    }

    // Return success with event ID
    return EIPSI_Form_Response::success(array(
        'message' => __('Event tracked successfully.', 'eipsi-forms'),
        'event_id' => $wpdb->insert_id,
        'tracked' => true,
        'external_db' => false,
        'fallback_used' => $used_fallback
    ));

    }
}
