<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * EIPSI Device Data Service
 * 
 * Handles saving and retrieving RAW device data (no hash processing).
 * The researcher decides what data to use at export time.
 *
 * @package EIPSI_Forms
 * @since 2.1.0
 */
class EIPSI_Device_Data_Service {

    /**
     * Save device data from submission
     *
     * @param int   $submission_id The submission ID
     * @param array $device_data   Raw device data from frontend
     * @return int|false The device data ID or false on failure
     */
    public static function save_device_data($submission_id, $device_data) {
        global $wpdb;
        require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/privacy-config.php';
        $submission = $wpdb->get_row($wpdb->prepare("SELECT form_id,participant_id FROM {$wpdb->prefix}vas_form_results WHERE id=%d", $submission_id));
        if (!$submission) { return false; }
        $device_data = eipsi_filter_capture_data($device_data, get_privacy_config($submission->form_id));
        if (!$device_data) { return false; }
        $table_name = $wpdb->prefix . 'eipsi_device_data';

        error_log("[EIPSI-DEVICE-DATA] SAVE START: submission_id={$submission_id}, data_keys=" . implode(',', array_keys($device_data)));

        // Ensure table exists
        if (!self::ensure_table_exists()) {
            error_log('[EIPSI-DEVICE-DATA] ERROR: Device data table does not exist');
            return false;
        }

        // Sanitize and prepare data
        $data = array(
            'submission_id' => absint($submission_id),
            'canvas_fingerprint' => isset($device_data['canvas_fingerprint']) 
                ? sanitize_text_field(substr($device_data['canvas_fingerprint'], 0, 255)) 
                : null,
            'webgl_renderer' => isset($device_data['webgl_renderer']) 
                ? sanitize_text_field(substr($device_data['webgl_renderer'], 0, 255)) 
                : null,
            'screen_resolution' => isset($device_data['screen_resolution']) 
                ? sanitize_text_field($device_data['screen_resolution']) 
                : null,
            'screen_depth' => isset($device_data['screen_depth']) 
                ? absint($device_data['screen_depth']) 
                : null,
            'pixel_ratio' => isset($device_data['pixel_ratio']) 
                ? floatval($device_data['pixel_ratio']) 
                : null,
            'timezone' => isset($device_data['timezone']) 
                ? sanitize_text_field($device_data['timezone']) 
                : null,
            'timezone_offset' => isset($device_data['timezone_offset']) 
                ? intval($device_data['timezone_offset']) 
                : null,
            'language' => isset($device_data['language']) 
                ? sanitize_text_field($device_data['language']) 
                : null,
            'languages' => isset($device_data['languages']) 
                ? sanitize_text_field(substr($device_data['languages'], 0, 255)) 
                : null,
            'cpu_cores' => isset($device_data['cpu_cores']) 
                ? absint($device_data['cpu_cores']) 
                : null,
            'ram' => isset($device_data['ram']) 
                ? absint($device_data['ram']) 
                : null,
            'do_not_track' => isset($device_data['do_not_track']) 
                ? sanitize_text_field($device_data['do_not_track']) 
                : null,
            'cookies_enabled' => isset($device_data['cookies_enabled']) 
                ? sanitize_text_field($device_data['cookies_enabled']) 
                : null,
            'plugins' => isset($device_data['plugins']) 
                ? sanitize_textarea_field($device_data['plugins']) 
                : null,
            'user_agent' => isset($device_data['user_agent']) 
                ? sanitize_textarea_field($device_data['user_agent']) 
                : null,
            'platform' => isset($device_data['platform']) 
                ? sanitize_text_field($device_data['platform']) 
                : null,
            'touch_support' => isset($device_data['touch_support']) 
                ? sanitize_text_field($device_data['touch_support']) 
                : null,
            'max_touch_points' => isset($device_data['max_touch_points']) 
                ? absint($device_data['max_touch_points']) 
                : null,
            'captured_at' => current_time('mysql'),
        );

        $format = array(
            '%d', // submission_id
            '%s', // canvas_fingerprint
            '%s', // webgl_renderer
            '%s', // screen_resolution
            '%d', // screen_depth
            '%f', // pixel_ratio
            '%s', // timezone
            '%d', // timezone_offset
            '%s', // language
            '%s', // languages
            '%d', // cpu_cores
            '%d', // ram
            '%s', // do_not_track
            '%s', // cookies_enabled
            '%s', // plugins
            '%s', // user_agent
            '%s', // platform
            '%s', // touch_support
            '%d', // max_touch_points
            '%s', // captured_at
        );

        $result = $wpdb->insert($table_name, $data, $format);

        if ($result === false) {
            error_log('[EIPSI-DEVICE-DATA] SAVE ERROR: ' . $wpdb->last_error);
            error_log('[EIPSI-DEVICE-DATA] SAVE ERROR DATA: ' . print_r($data, true));
            return false;
        }

        error_log("[EIPSI-DEVICE-DATA] SAVE SUCCESS: submission_id={$submission_id}, insert_id={$wpdb->insert_id}");
        error_log("[EIPSI-DEVICE-DATA] SAVE VALUES: canvas=" . substr($data['canvas_fingerprint'] ?? 'NULL', 0, 20) . "..., webgl=" . substr($data['webgl_renderer'] ?? 'NULL', 0, 20) . "...");
        return $wpdb->insert_id;
    }

    /**
     * Get device data for a submission
     *
     * @param int $submission_id The submission ID
     * @return object|null Device data object or null
     */
    public static function get_device_data($submission_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'eipsi_device_data';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE submission_id = %d",
            absint($submission_id)
        ));
    }

    /**
     * Get device data for multiple submissions
     *
     * @param array $submission_ids Array of submission IDs
     * @return array Array of device data objects keyed by submission_id
     */
    public static function get_device_data_batch($submission_ids) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'eipsi_device_data';

        if (empty($submission_ids)) {
            error_log('[EIPSI-DEVICE-DATA] BATCH: Empty submission_ids array');
            return array();
        }

        $ids = array_map('absint', $submission_ids);
        $ids_placeholder = implode(',', array_fill(0, count($ids), '%d'));

        error_log("[EIPSI-DEVICE-DATA] BATCH: Fetching data for " . count($ids) . " submissions: " . implode(',', $ids));

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE submission_id IN ({$ids_placeholder})",
            $ids
        ));

        $data = array();
        error_log("[EIPSI-DEVICE-DATA] BATCH: Found " . count($results) . " rows from DB");
        foreach ($results as $row) {
            $data[$row->submission_id] = $row;
            error_log("[EIPSI-DEVICE-DATA] BATCH: Row for submission_id={$row->submission_id}, type=" . gettype($row) . ", canvas=" . (isset($row->canvas_fingerprint) ? substr($row->canvas_fingerprint, 0, 20) : 'NOTSET'));
        }

        if (empty($data)) {
            error_log("[EIPSI-DEVICE-DATA] BATCH WARNING: No device data found for any of the requested submissions");
            // Try direct query to diagnose
            $sample_id = $ids[0];
            $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE submission_id = %d", $sample_id));
            error_log("[EIPSI-DEVICE-DATA] BATCH DIAG: Direct check for submission_id={$sample_id}, exists={$exists}");
        }

        return $data;
    }

    /**
     * Get available device data columns for export
     *
     * @return array Array of column definitions
     */
    public static function get_export_columns() {
        return array(
            'canvas_fingerprint' => array(
                'label' => 'Canvas Fingerprint',
                'description' => 'GPU rendering signature (truncated)',
            ),
            'webgl_renderer' => array(
                'label' => 'WebGL Renderer',
                'description' => 'GPU vendor + renderer info',
            ),
            'screen_resolution' => array(
                'label' => 'Screen Resolution',
                'description' => 'Screen width × height (ej: 1920x1080)',
            ),
            'screen_depth' => array(
                'label' => 'Color Depth',
                'description' => 'Color depth in bits (ej: 24)',
            ),
            'pixel_ratio' => array(
                'label' => 'Pixel Ratio',
                'description' => 'Device pixel ratio (ej: 2.0)',
            ),
            'timezone' => array(
                'label' => 'Timezone',
                'description' => 'Timezone (ej: America/Argentina/Buenos_Aires)',
            ),
            'timezone_offset' => array(
                'label' => 'Timezone Offset',
                'description' => 'Timezone offset in minutes',
            ),
            'language' => array(
                'label' => 'Language',
                'description' => 'Browser language (ej: es-AR)',
            ),
            'languages' => array(
                'label' => 'Languages',
                'description' => 'Preferred languages list',
            ),
            'cpu_cores' => array(
                'label' => 'CPU Cores',
                'description' => 'Number of CPU cores',
            ),
            'ram' => array(
                'label' => 'RAM (GB)',
                'description' => 'Device memory in GB (if available)',
            ),
            'do_not_track' => array(
                'label' => 'Do Not Track',
                'description' => 'DNT setting (1, null, or unspecified)',
            ),
            'cookies_enabled' => array(
                'label' => 'Cookies Enabled',
                'description' => 'Whether cookies are enabled (true/false)',
            ),
            'plugins' => array(
                'label' => 'Browser Plugins',
                'description' => 'List of installed plugins',
            ),
            'user_agent' => array(
                'label' => 'User Agent',
                'description' => 'Full user agent string',
            ),
            'platform' => array(
                'label' => 'Platform',
                'description' => 'Operating system platform (ej: Win32)',
            ),
            'touch_support' => array(
                'label' => 'Touch Support',
                'description' => 'Whether device supports touch (true/false)',
            ),
            'max_touch_points' => array(
                'label' => 'Max Touch Points',
                'description' => 'Number of touch points supported',
            ),
        );
    }

    /**
     * Get export column groups for UI
     *
     * @return array Array of column groups
     */
    public static function get_export_column_groups() {
        return array(
            'fingerprint_full' => array(
                'label' => '🖥️ Fingerprint Completo del Dispositivo',
                'description' => 'Genera datos del dispositivo para distinguir pacientes con IP compartida.',
                'badge' => 'Activado por defecto',
                'badge_class' => 'eipsi-badge-default',
                'default_checked' => true,
                'columns' => array(
                    'canvas_fingerprint',
                    'webgl_renderer',
                    'screen_resolution',
                    'screen_depth',
                    'pixel_ratio',
                    'timezone',
                    'language',
                    'cpu_cores',
                    'ram',
                    'do_not_track',
                    'cookies_enabled',
                    'plugins',
                ),
            ),
            'fingerprint_light' => array(
                'label' => '🖥️ Fingerprint Liviano del Dispositivo',
                'description' => '⚠️ Desactivado por defecto. Útil para distinguir pacientes con IP compartida.',
                'badge' => 'Opcional',
                'badge_class' => 'eipsi-badge-optional',
                'default_checked' => false,
                'columns' => array(
                    'user_agent',
                    'platform',
                ),
            ),
            'screen_only' => array(
                'label' => '📱 Solo Tamaño de Pantalla',
                'description' => 'Capturar solo resolución y densidad de píxeles.',
                'badge' => 'Opcional',
                'badge_class' => 'eipsi-badge-optional',
                'default_checked' => false,
                'columns' => array(
                    'screen_resolution',
                    'pixel_ratio',
                ),
            ),
        );
    }

    /**
     * Ensure the device data table exists
     *
     * @return bool True if table exists or was created
     */
    private static function ensure_table_exists() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'eipsi_device_data';

        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'");

        if (!$table_exists) {
            // Trigger schema sync
            if (class_exists('EIPSI_Database_Schema_Manager')) {
                EIPSI_Database_Schema_Manager::verify_and_sync_schema();
            }
        }

        return $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name;
    }

    /**
     * Delete device data for a submission
     *
     * @param int $submission_id The submission ID
     * @return bool True on success
     */
    public static function delete_device_data($submission_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'eipsi_device_data';

        $result = $wpdb->delete(
            $table_name,
            array('submission_id' => absint($submission_id)),
            array('%d')
        );

        return $result !== false;
    }
}
