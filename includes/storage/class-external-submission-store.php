<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * EIPSI Forms External Database Helper
 * Manages connections and operations for external MySQL databases
 */
class EIPSI_Storage_External_Submission_Store {
    
    private $option_prefix = 'eipsi_external_db_';
    
    private $critical_columns = array(
        'form_id',
        'participant_id',
        'session_id',
        'duration_seconds',
        'form_responses'
    );
    
    /**
     * Encrypt credentials before storing
     * Uses WordPress salts for encryption
     */
    private function encrypt_data($data) {
        if (empty($data)) {
            return '';
        }
        
        $key = wp_salt('auth');
        $iv_length = openssl_cipher_iv_length('aes-256-cbc');
        $iv = openssl_random_pseudo_bytes($iv_length);
        
        $encrypted = openssl_encrypt(
            $data,
            'aes-256-cbc',
            $key,
            0,
            $iv
        );
        
        return base64_encode($iv . '::' . $encrypted);
    }
    
    /**
     * Decrypt stored credentials
     */
    private function decrypt_data($encrypted_data) {
        if (empty($encrypted_data)) {
            return '';
        }
        
        $key = wp_salt('auth');
        $decoded = base64_decode($encrypted_data, true);
        
        $iv_length = openssl_cipher_iv_length('aes-256-cbc');
        if ($decoded === false || strlen($decoded) <= $iv_length + 2 || substr($decoded, $iv_length, 2) !== '::') {
            return '';
        }
        
        // The IV is binary; delimiters inside it must not split the stored format.
        $iv = substr($decoded, 0, $iv_length);
        $encrypted = substr($decoded, $iv_length + 2);
        
        return openssl_decrypt(
            $encrypted,
            'aes-256-cbc',
            $key,
            0,
            $iv
        );
    }
    
    /**
     * Save custom database credentials
     * 
     * @param string $host Database host
     * @param string $user Database username
     * @param string $password Database password
     * @param string $db_name Database name
     * @return bool Success or failure
     */
    public function save_credentials($host, $user, $password, $db_name) {
        $host = sanitize_text_field($host);
        $user = sanitize_text_field($user);
        $db_name = sanitize_text_field($db_name);
        
        if (empty($host) || empty($user) || empty($db_name)) {
            return false;
        }
        
        // Encrypt sensitive data
        $encrypted_password = $this->encrypt_data($password);
        
        // Save to wp_options
        update_option($this->option_prefix . 'host', $host);
        update_option($this->option_prefix . 'user', $user);
        update_option($this->option_prefix . 'password', $encrypted_password);
        update_option($this->option_prefix . 'name', $db_name);
        update_option($this->option_prefix . 'enabled', true);
        update_option($this->option_prefix . 'last_updated', current_time('mysql'));
        
        return true;
    }
    
    /**
     * Get stored credentials
     * 
     * @return array|null Array with credentials or null if not configured
     */
    public function get_credentials() {
        $enabled = get_option($this->option_prefix . 'enabled', false);
        
        if (!$enabled) {
            return null;
        }
        
        $host = get_option($this->option_prefix . 'host', '');
        $user = get_option($this->option_prefix . 'user', '');
        $encrypted_password = get_option($this->option_prefix . 'password', '');
        $db_name = get_option($this->option_prefix . 'name', '');
        
        if (empty($host) || empty($user) || empty($db_name)) {
            return null;
        }
        
        $password = $this->decrypt_data($encrypted_password);
        
        return array(
            'host' => $host,
            'user' => $user,
            'password' => $password,
            'name' => $db_name
        );
    }
    
    /**
     * Test database connection
     * 
     * @param string $host Database host
     * @param string $user Database username
     * @param string $password Database password
     * @param string $db_name Database name
     * @return array Array with success status and message
     */
    public function test_connection($host, $user, $password, $db_name) {
        $host = sanitize_text_field($host);
        $user = sanitize_text_field($user);
        $db_name = sanitize_text_field($db_name);
        
        // Suppress errors to handle them gracefully
        $mysqli = @new mysqli($host, $user, $password, $db_name);
        
        if ($mysqli->connect_error) {
            return array(
                'success' => false,
                'message' => sprintf(
                    __('Connection failed: %s', 'eipsi-forms'),
                    $mysqli->connect_error
                ),
                'error_code' => $mysqli->connect_errno
            );
        }
        
        // Ensure schema is ready (create table if missing, add columns if needed)
        $schema_result = $this->ensure_schema_ready($mysqli);
        
        if (!$schema_result['success']) {
            $mysqli->close();
            return array(
                'success' => false,
                'message' => sprintf(
                    __('Schema validation failed: %s', 'eipsi-forms'),
                    $schema_result['error']
                ),
                'error_code' => 'SCHEMA_ERROR'
            );
        }
        
        // Validate critical columns before confirming connection
        $column_validation = $this->validate_required_columns($mysqli, $schema_result['table_name']);
        
        if (!$column_validation['success']) {
            $mysqli->close();
            return array(
                'success' => false,
                'message' => sprintf(
                    __('La base de datos externa no tiene columnas críticas requeridas para EIPSI Forms: %s', 'eipsi-forms'),
                    implode(', ', $column_validation['missing'])
                ),
                'error_code' => 'SCHEMA_MISSING_COLUMNS'
            );
        }
        
        // Get record count
        $record_count = $this->get_record_count_from_connection($mysqli);
        
        $mysqli->close();
        
        return array(
            'success' => true,
            'message' => __('Connection successful! Schema validated.', 'eipsi-forms'),
            'db_name' => $db_name,
            'record_count' => $record_count,
            'table_exists' => true
        );
    }
    
    /**
     * Resolve table name (with or without WP prefix)
     * Checks both prefixed and bare table names
     */
    private function resolve_table_name($mysqli) {
        global $wpdb;
        $prefixed_table = $wpdb->prefix . 'vas_form_results';
        $bare_table = 'vas_form_results';

        // Check prefixed table first
        $result = $mysqli->query("SHOW TABLES LIKE '{$prefixed_table}'");
        if ($result && $result->num_rows > 0) {
            return $prefixed_table;
        }

        // Check bare table
        $result = $mysqli->query("SHOW TABLES LIKE '{$bare_table}'");
        if ($result && $result->num_rows > 0) {
            return $bare_table;
        }

        // Default to prefixed table for creation
        return $prefixed_table;
    }

    /**
     * Resolve events table name (with or without WP prefix)
     */
    private function resolve_events_table_name($mysqli) {
        global $wpdb;
        $prefixed_table = $wpdb->prefix . 'vas_form_events';
        $bare_table = 'vas_form_events';

        $result = $mysqli->query("SHOW TABLES LIKE '{$prefixed_table}'");
        if ($result && $result->num_rows > 0) {
            return $prefixed_table;
        }

        $result = $mysqli->query("SHOW TABLES LIKE '{$bare_table}'");
        if ($result && $result->num_rows > 0) {
            return $bare_table;
        }

        return $prefixed_table;
    }

    /**
     * Check if vas_form_results table exists
     */
    private function check_table_exists($mysqli) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'vas_form_results';

        $result = $mysqli->query("SHOW TABLES LIKE '{$table_name}'");

        if (!$result) {
            return false;
        }

        return $result->num_rows > 0;
    }
    
    /**
     * Get record count from custom database connection
     */
    private function get_record_count_from_connection($mysqli) {
        $table_name = $this->resolve_table_name($mysqli);
        
        $result = $mysqli->query("SELECT COUNT(*) as count FROM `{$table_name}`");
        
        if (!$result) {
            return 0;
        }
        
        $row = $result->fetch_assoc();
        return isset($row['count']) ? intval($row['count']) : 0;
    }
    
    /**
     * Create the vas_form_results table in the external database
     * 
     * @param mysqli $mysqli Active database connection
     * @return bool Success or failure
     */
    private function create_table_if_missing($mysqli) { require_once EIPSI_FORMS_PLUGIN_DIR.'includes/schema/bootstrap.php'; return EIPSI_External_Schema_Adapter::create_results($mysqli); }

    /**
     * Ensure required columns exist in the table
     * 
     * @param mysqli $mysqli Active database connection
     * @param string $table_name Resolved table name
     * @return bool Success or failure
     */
    private function ensure_required_columns($mysqli, $table_name) {
        require_once EIPSI_FORMS_PLUGIN_DIR.'includes/schema/bootstrap.php';
        return EIPSI_External_Schema_Adapter::ensure_required_columns($mysqli, $table_name);
    }

    /**
     * Ensure schema is ready (table exists with all required columns)
     * 
     * @param mysqli $mysqli Active database connection
     * @return array Array with success status and table name
     */
    private function ensure_schema_ready($mysqli) {
        // Try to create table if missing
        if (!$this->create_table_if_missing($mysqli)) {
            return array(
                'success' => false,
                'error' => 'Failed to create table',
                'table_name' => null
            );
        }
        
        // Resolve the actual table name
        $table_name = $this->resolve_table_name($mysqli);
        
        // Ensure all required columns exist
        if (!$this->ensure_required_columns($mysqli, $table_name)) {
            return array(
                'success' => false,
                'error' => 'Failed to add required columns',
                'table_name' => $table_name
            );
        }
        
        return array(
            'success' => true,
            'table_name' => $table_name
        );
    }

    /**
     * Validate that the external table includes the critical columns required for tracking.
     *
     * @param mysqli $mysqli Active database connection
     * @param string $table_name Table name to inspect
     * @return array Result with success flag and missing columns (if any)
     */
    private function validate_required_columns($mysqli, $table_name) {
        $result = $mysqli->query("DESCRIBE `{$table_name}`");

        if (!$result) {
            return array(
                'success' => false,
                'error' => 'Unable to inspect external table schema',
                'missing' => $this->critical_columns
            );
        }

        $columns = array();
        while ($row = $result->fetch_assoc()) {
            if (isset($row['Field'])) {
                $columns[] = strtolower($row['Field']);
            }
        }

        $missing = array_diff($this->critical_columns, $columns);

        if (!empty($missing)) {
            return array(
                'success' => false,
                'error' => 'Missing columns: ' . implode(', ', $missing),
                'missing' => $missing
            );
        }

        return array(
            'success' => true,
            'missing' => array()
        );
    }

    /**
     * Get active database connection (custom or WordPress)
     * 
     * @return mysqli|null Custom database connection or null
     */
    public function get_connection() {
        $credentials = $this->get_credentials();
        
        if (!$credentials) {
            return null;
        }
        
        $mysqli = @new mysqli(
            $credentials['host'],
            $credentials['user'],
            $credentials['password'],
            $credentials['name']
        );
        
        if ($mysqli->connect_error) {
            error_log('EIPSI Forms: Failed to connect to external DB - ' . $mysqli->connect_error);
            return null;
        }
        
        return $mysqli;
    }
    
    /**
     * Get current database record count
     * 
     * @return int Record count
     */
    public function get_record_count() {
        $custom_connection = $this->get_connection();
        
        if ($custom_connection) {
            $count = $this->get_record_count_from_connection($custom_connection);
            $custom_connection->close();
            return $count;
        }
        
        // Fallback to WordPress database
        global $wpdb;
        $table_name = $wpdb->prefix . 'vas_form_results';
        
        $count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
        
        return intval($count);
    }
    
    /**
     * Insert form submission into custom database
     * 
     * @param array $data Form submission data
     * @return array Result array with success status, insert_id, and error details
     */
    public function insert_form_submission($data) {
        require_once EIPSI_FORMS_PLUGIN_DIR."admin/privacy-config.php";
        $data=eipsi_filter_capture_data($data,get_privacy_config($data["form_id"]??null));
        $mysqli = $this->get_connection();
        
        if (!$mysqli) {
            $error_msg = 'Failed to connect to external database';
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('EIPSI Forms External DB: ' . $error_msg);
            }
            return array(
                'success' => false,
                'error' => $error_msg,
                'error_code' => 'CONNECTION_FAILED',
                'insert_id' => null
            );
        }
        
        // Ensure schema is ready before insert
        $schema_result = $this->ensure_schema_ready($mysqli);
        
        if (!$schema_result['success']) {
            $error_msg = 'Schema validation failed: ' . $schema_result['error'];
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('EIPSI Forms External DB: ' . $error_msg);
            }
            $mysqli->close();
            return array(
                'success' => false,
                'error' => $error_msg,
                'error_code' => 'SCHEMA_ERROR',
                'insert_id' => null
            );
        }
        
        $table_name = $schema_result['table_name'];
        
        $column_validation = $this->validate_required_columns($mysqli, $table_name);
        
        if (!$column_validation['success']) {
            $error_msg = 'Missing critical columns: ' . implode(', ', $column_validation['missing']);
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('EIPSI Forms External DB: ' . $error_msg);
            }
            $mysqli->close();
            return array(
                'success' => false,
                'error' => $error_msg,
                'error_code' => 'SCHEMA_MISSING_COLUMNS',
                'missing_columns' => $column_validation['missing'],
                'insert_id' => null
            );
        }
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('EIPSI Forms External DB: Attempting insert into table ' . $table_name);
        }
        
        // Prepare statement with new columns: session_id, user_fingerprint, metadata, status
        $stmt = $mysqli->prepare(
            "INSERT INTO `{$table_name}` 
            (form_id, participant_id, survey_id, wave_index, session_id, user_fingerprint, form_name, created_at, submitted_at, ip_address, device, browser, os, screen_width, duration, duration_seconds, start_timestamp_ms, end_timestamp_ms, metadata, status, form_responses) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        
        if (!$stmt) {
            $error_msg = 'Failed to prepare statement: ' . $mysqli->error;
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('EIPSI Forms External DB: ' . $error_msg);
                error_log('EIPSI Forms External DB: MySQL Error Code: ' . $mysqli->errno);
            }
            $mysql_errno=$mysqli->errno;
            $mysqli->close();
            return array(
                'success' => false,
                'error' => $error_msg,
                'error_code' => 'PREPARE_FAILED',
                'mysql_errno' => $mysql_errno,
                'insert_id' => null
            );
        }
        
        // Bind types: string × 14, int, double, bigint, bigint, string × 3
        $stmt->bind_param(
            'ssiissssssssssidiisss',
            $data['form_id'],
            $data['participant_id'],
            $data['survey_id'],
            $data['wave_index'],
            $data['session_id'],
            $data['user_fingerprint'],
            $data['form_name'],
            $data['created_at'],
            $data['submitted_at'],
            $data['ip_address'],
            $data['device'],
            $data['browser'],
            $data['os'],
            $data['screen_width'],
            $data['duration'],
            $data['duration_seconds'],
            $data['start_timestamp_ms'],
            $data['end_timestamp_ms'],
            $data['metadata'],
            $data['status'],
            $data['form_responses']
        );
        
        $success = $stmt->execute();
        
        if (!$success || $stmt->affected_rows !== 1 || $mysqli->insert_id < 1) {
            $error_msg = 'Failed to execute insert: ' . $stmt->error;
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('EIPSI Forms External DB: ' . $error_msg);
                error_log('EIPSI Forms External DB: MySQL Error Code: ' . $stmt->errno);
            }
            $mysql_errno=$stmt->errno;
            $stmt->close();
            $mysqli->close();
            return array(
                'success' => false,
                'error' => $error_msg,
                'error_code' => 'EXECUTE_FAILED',
                'mysql_errno' => $mysql_errno,
                'insert_id' => null
            );
        }
        
        $insert_id = $mysqli->insert_id;
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('EIPSI Forms External DB: Successfully inserted record with ID ' . $insert_id);
        }
        
        $stmt->close();
        $mysqli->close();
        
        return array(
            'success' => true,
            'insert_id' => $insert_id,
            'error' => null,
            'error_code' => null
        );
    }
    
    /**
     * Record external database error for admin diagnostics
     * 
     * @param string $error_message Error message
     * @param string $error_code Machine-readable error code
     */
    public function record_error($error_message, $error_code) {
        update_option($this->option_prefix . 'last_error', $error_message);
        update_option($this->option_prefix . 'last_error_code', $error_code);
        update_option($this->option_prefix . 'last_error_time', current_time('mysql'));
    }

    /**
     * Clear recorded errors
     */
    public function clear_errors() {
        delete_option($this->option_prefix . 'last_error');
        delete_option($this->option_prefix . 'last_error_code');
        delete_option($this->option_prefix . 'last_error_time');
    }

    /**
     * Disable external database
     */
    public function disable() {
        update_option($this->option_prefix . 'enabled', false);
        $this->clear_errors();
    }
    
    /**
     * Check if external database is enabled and configured
     * 
     * @return bool
     */
    public function is_enabled() {
        $enabled = get_option($this->option_prefix . 'enabled', false);
        
        if (!$enabled) {
            return false;
        }
        
        $credentials = $this->get_credentials();
        return !empty($credentials);
    }
    
    /**
     * Insert form event into external database
     * 
     * @param array $data Event data
     * @return array Result array with success status
     */
    public function insert_form_event($data) {
        require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/privacy-config.php';
        $data = eipsi_filter_capture_data($data, get_privacy_config($data['form_id'] ?? null));
        $mysqli = $this->get_connection();
        
        if (!$mysqli) {
            return array(
                'success' => false,
                'error' => 'Failed to connect to external database',
                'error_code' => 'CONNECTION_FAILED'
            );
        }
        
        // Ensure schema is ready
        require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/database-schema-manager.php';
        $schema_result = EIPSI_Database_Schema_Manager::verify_and_sync_schema($mysqli);
        
        if (!$schema_result['success']) {
            $mysqli->close();
            return array(
                'success' => false,
                'error' => 'Schema validation failed',
                'error_code' => 'SCHEMA_ERROR'
            );
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'vas_form_events';
        
        $stmt = $mysqli->prepare(
            "INSERT INTO `{$table_name}` 
            (form_id, session_id, event_type, page_number, metadata, user_agent, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        
        if (!$stmt) {
            $mysqli->close();
            return array(
                'success' => false,
                'error' => 'Failed to prepare statement: ' . $mysqli->error,
                'error_code' => 'PREPARE_FAILED'
            );
        }
        
        $stmt->bind_param(
            'sssssss',
            $data['form_id'],
            $data['session_id'],
            $data['event_type'],
            $data['page_number'],
            $data['metadata'],
            $data['user_agent'],
            $data['created_at']
        );
        
        $success = $stmt->execute();
        
        if (!$success) {
            $stmt->close();
            $mysqli->close();
            return array(
                'success' => false,
                'error' => 'Failed to execute insert: ' . $stmt->error,
                'error_code' => 'EXECUTE_FAILED'
            );
        }
        
        $insert_id = $mysqli->insert_id;
        $stmt->close();
        $mysqli->close();
        
        return array(
            'success' => true,
            'insert_id' => $insert_id
        );
    }
    
    /**
     * Check database table status (existence, schema, row count)
     * 
     * @return array Detailed table status information
     */
    public function check_table_status() {
        $credentials = $this->get_credentials();
        
        if (!$credentials) {
            // No external DB configured - check local WordPress tables
            return $this->check_local_table_status();
        }
        
        // Test connection
        $mysqli = @new mysqli(
            $credentials['host'],
            $credentials['user'],
            $credentials['password'],
            $credentials['name']
        );
        
        if ($mysqli->connect_error) {
            return array(
                'success' => false,
                'message' => sprintf(
                    __('Connection failed: %s', 'eipsi-forms'),
                    $mysqli->connect_error
                ),
                'error_code' => $mysqli->connect_errno,
                'using_wordpress_db' => false,
                'results_table' => array('exists' => false),
                'events_table' => array('exists' => false)
            );
        }
        
        global $wpdb;
        
        // Check results table
        $results_table_name = $wpdb->prefix . 'vas_form_results';
        $results_exists_query = $mysqli->query("SHOW TABLES LIKE '{$results_table_name}'");
        $results_exists = $results_exists_query && $results_exists_query->num_rows > 0;
        
        $results_info = array(
            'exists' => $results_exists,
            'table_name' => $results_table_name,
            'row_count' => 0,
            'columns_ok' => false
        );
        
        if ($results_exists) {
            // Get row count
            $count_result = $mysqli->query("SELECT COUNT(*) as count FROM `{$results_table_name}`");
            if ($count_result) {
                $count_row = $count_result->fetch_assoc();
                $results_info['row_count'] = intval($count_row['count']);
            }
            
            // Check required columns
            $required_columns = array(
                'form_id', 'participant_id', 'survey_id', 'wave_index', 'session_id', 'user_fingerprint', 'form_name',
                'created_at', 'submitted_at', 'duration_seconds',
                'start_timestamp_ms', 'end_timestamp_ms', 'metadata',
                'status', 'form_responses'
            );
            
            $missing_columns = array();
            foreach ($required_columns as $column) {
                $column_check = $mysqli->query("SHOW COLUMNS FROM `{$results_table_name}` LIKE '{$column}'");
                if (!$column_check || $column_check->num_rows === 0) {
                    $missing_columns[] = $column;
                }
            }
            
            $results_info['columns_ok'] = empty($missing_columns);
            $results_info['missing_columns'] = $missing_columns;
        }
        
        // Check events table
        $events_table_name = $wpdb->prefix . 'vas_form_events';
        $events_exists_query = $mysqli->query("SHOW TABLES LIKE '{$events_table_name}'");
        $events_exists = $events_exists_query && $events_exists_query->num_rows > 0;
        
        $events_info = array(
            'exists' => $events_exists,
            'table_name' => $events_table_name,
            'row_count' => 0,
            'columns_ok' => false
        );
        
        if ($events_exists) {
            // Get row count
            $count_result = $mysqli->query("SELECT COUNT(*) as count FROM `{$events_table_name}`");
            if ($count_result) {
                $count_row = $count_result->fetch_assoc();
                $events_info['row_count'] = intval($count_row['count']);
            }
            
            // Check required columns
            $required_columns = array(
                'form_id', 'session_id', 'event_type', 'page_number',
                'metadata', 'user_agent', 'created_at'
            );
            
            $missing_columns = array();
            foreach ($required_columns as $column) {
                $column_check = $mysqli->query("SHOW COLUMNS FROM `{$events_table_name}` LIKE '{$column}'");
                if (!$column_check || $column_check->num_rows === 0) {
                    $missing_columns[] = $column;
                }
            }
            
            $events_info['columns_ok'] = empty($missing_columns);
            $events_info['missing_columns'] = $missing_columns;
        }
        
        $mysqli->close();
        
        // Determine overall status
        $all_tables_exist = $results_exists && $events_exists;
        $all_columns_ok = $results_info['columns_ok'] && $events_info['columns_ok'];
        
        $message = '';
        if (!$all_tables_exist) {
            $message = __('⚠️ One or more database tables are missing', 'eipsi-forms');
        } elseif (!$all_columns_ok) {
            $message = __('⚠️ Database tables exist but some columns are missing', 'eipsi-forms');
        } else {
            $message = __('✓ All database tables exist and are properly configured', 'eipsi-forms');
        }
        
        return array(
            'success' => true,
            'message' => $message,
            'db_name' => $credentials['name'],
            'all_tables_exist' => $all_tables_exist,
            'all_columns_ok' => $all_columns_ok,
            'using_wordpress_db' => false,
            'results_table' => $results_info,
            'events_table' => $events_info
        );
    }

    /**
     * Get local WordPress database table status directly
     * Useful even when external credentials are configured but disconnected.
     *
     * @return array Detailed local table status information
     */
    public function get_local_table_status() {
        return $this->check_local_table_status();
    }
    
    /**
     * Get connection status information
     * 
     * @return array Status information
     */
    public function get_status() {
        $credentials = $this->get_credentials();
        
        // Get error information
        $last_error = get_option($this->option_prefix . 'last_error', '');
        $last_error_code = get_option($this->option_prefix . 'last_error_code', '');
        $last_error_time = get_option($this->option_prefix . 'last_error_time', '');
        
        if (!$credentials) {
            return array(
                'connected' => false,
                'db_name' => '',
                'record_count' => 0,
                'last_updated' => '',
                'last_error' => $last_error,
                'last_error_code' => $last_error_code,
                'last_error_time' => $last_error_time,
                'fallback_active' => !empty($last_error)
            );
        }
        
        $test_result = $this->test_connection(
            $credentials['host'],
            $credentials['user'],
            $credentials['password'],
            $credentials['name']
        );
        
        // Clear errors if connection is successful
        if ($test_result['success']) {
            $this->clear_errors();
            $last_error = '';
            $last_error_code = '';
            $last_error_time = '';
        }
        
        return array(
            'connected' => $test_result['success'],
            'db_name' => $credentials['name'],
            'record_count' => $test_result['success'] ? $test_result['record_count'] : 0,
            'last_updated' => get_option($this->option_prefix . 'last_updated', ''),
            'message' => $test_result['message'],
            'last_error' => $last_error,
            'last_error_code' => $last_error_code,
            'last_error_time' => $last_error_time,
            'fallback_active' => !empty($last_error) && !$test_result['success']
        );
    }
    
    /**
     * Delete all clinical data from EIPSI Forms tables
     * Truncates both vas_form_results and vas_form_events tables
     * 
     * @return array Result with success status and message
     */
    public function delete_all_data() {
        global $wpdb;
        
        $wordpress_tables = array(
            $wpdb->prefix . 'vas_form_results',
            $wpdb->prefix . 'vas_form_events'
        );
        
        // Always clear local WordPress tables first
        foreach ($wordpress_tables as $table_name) {
            if ($this->wp_table_exists($table_name)) {
                if (!$this->truncate_wp_table($table_name)) {
                    return array(
                        'success' => false,
                        'message' => sprintf(
                            __('Failed to delete table %s: %s', 'eipsi-forms'),
                            $table_name,
                            $wpdb->last_error
                        ),
                        'error_code' => 'WP_DELETE_FAILED'
                    );
                }
            }
        }
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[EIPSI Forms] WordPress clinical tables truncated by user ID: ' . get_current_user_id());
        }
        
        $credentials = $this->get_credentials();
        
        if (!$credentials) {
            return array(
                'success' => true,
                'message' => __('All clinical data has been successfully deleted from the WordPress database.', 'eipsi-forms'),
                'database' => 'wordpress'
            );
        }
        
        $mysqli = $this->get_connection();
        
        if (!$mysqli) {
            return array(
                'success' => false,
                'message' => __('Failed to connect to external database', 'eipsi-forms'),
                'error_code' => 'CONNECTION_FAILED'
            );
        }
        
        $external_tables = array(
            $this->resolve_table_name($mysqli),
            $this->resolve_events_table_name($mysqli)
        );
        
        foreach ($external_tables as $table_name) {
            if (!$this->truncate_external_table($mysqli, $table_name)) {
                $error = $mysqli->error;
                $mysqli->close();
                return array(
                    'success' => false,
                    'message' => sprintf(
                        __('Failed to delete table %s: %s', 'eipsi-forms'),
                        $table_name,
                        $error
                    ),
                    'error_code' => 'EXTERNAL_DELETE_FAILED'
                );
            }
        }
        
        $mysqli->close();
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[EIPSI Forms] External clinical tables truncated by user ID: ' . get_current_user_id());
        }
        
        return array(
            'success' => true,
            'message' => __('All clinical data has been deleted from both WordPress and the external database.', 'eipsi-forms'),
            'database' => 'wordpress_and_external'
        );
    }
    
    /**
     * Check if a WordPress table exists
     */
    private function wp_table_exists($table_name) {
        global $wpdb;
        $like = $wpdb->prepare('SHOW TABLES LIKE %s', $table_name);
        $result = $wpdb->get_var($like);
        return $result === $table_name;
    }
    
    /**
     * Truncate or delete a WordPress table
     */
    private function truncate_wp_table($table_name) {
        global $wpdb;
        $truncate = $wpdb->query("TRUNCATE TABLE `{$table_name}`");
        if ($truncate === false) {
            $delete = $wpdb->query("DELETE FROM `{$table_name}`");
            return $delete !== false;
        }
        return true;
    }
    
    /**
     * Truncate or delete an external table
     */
    private function truncate_external_table($mysqli, $table_name) {
        if ($mysqli->query("TRUNCATE TABLE `{$table_name}`")) {
            return true;
        }
        if ($mysqli->query("DELETE FROM `{$table_name}`")) {
            return true;
        }
        return false;
    }

    /**
     * Check local WordPress database table status
     * Used when no external database is configured
     * 
     * @return array Detailed local table status information
     */
    private function check_local_table_status() {
        global $wpdb;
        
        $last_verified = get_option('eipsi_schema_last_verified', null);
        
        // Table names
        $results_table_name = $wpdb->prefix . 'vas_form_results';
        $events_table_name = $wpdb->prefix . 'vas_form_events';
        $rct_configs_table = $wpdb->prefix . 'eipsi_randomization_configs';
        $rct_assignments_table = $wpdb->prefix . 'eipsi_randomization_assignments';
        
        // Longitudinal tables (v1.4.0+)
        $survey_studies_table = $wpdb->prefix . 'survey_studies';
        $survey_participants_table = $wpdb->prefix . 'survey_participants';
        $survey_sessions_table = $wpdb->prefix . 'survey_sessions';
        $survey_waves_table = $wpdb->prefix . 'survey_waves';
        $survey_assignments_table = $wpdb->prefix . 'survey_assignments';
        $survey_magic_links_table = $wpdb->prefix . 'survey_magic_links';
        $survey_email_log_table = $wpdb->prefix . 'survey_email_log';
        $survey_audit_log_table = $wpdb->prefix . 'survey_audit_log';
        
        // Check results table
        $results_exists = $wpdb->get_var("SHOW TABLES LIKE '{$results_table_name}'") === $results_table_name;
        $results_info = array(
            'exists' => $results_exists,
            'table_name' => $results_table_name,
            'row_count' => 0,
            'columns_ok' => false,
            'missing_columns' => array()
        );
        
        if ($results_exists) {
            // Get row count
            $results_info['row_count'] = intval($wpdb->get_var("SELECT COUNT(*) FROM {$results_table_name}"));
            
            // Check required columns
            $required_columns = array(
                'form_id', 'participant_id', 'survey_id', 'wave_index', 'session_id', 'user_fingerprint',
                'form_name', 'created_at', 'submitted_at', 'duration_seconds',
                'start_timestamp_ms', 'end_timestamp_ms', 'metadata',
                'status', 'form_responses'
            );
            
            foreach ($required_columns as $column) {
                $column_exists = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
                        WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                        DB_NAME,
                        $results_table_name,
                        $column
                    )
                );
                if (empty($column_exists)) {
                    $results_info['missing_columns'][] = $column;
                }
            }
            
            $results_info['columns_ok'] = empty($results_info['missing_columns']);
        }
        
        // Check events table
        $events_exists = $wpdb->get_var("SHOW TABLES LIKE '{$events_table_name}'") === $events_table_name;
        $events_info = array(
            'exists' => $events_exists,
            'table_name' => $events_table_name,
            'row_count' => 0,
            'columns_ok' => false,
            'missing_columns' => array()
        );
        
        if ($events_exists) {
            // Get row count
            $events_info['row_count'] = intval($wpdb->get_var("SELECT COUNT(*) FROM {$events_table_name}"));
            
            // Check required columns
            $required_columns = array(
                'form_id', 'session_id', 'event_type', 'page_number',
                'metadata', 'user_agent', 'created_at'
            );
            
            foreach ($required_columns as $column) {
                $column_exists = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
                        WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                        DB_NAME,
                        $events_table_name,
                        $column
                    )
                );
                if (empty($column_exists)) {
                    $events_info['missing_columns'][] = $column;
                }
            }
            
            $events_info['columns_ok'] = empty($events_info['missing_columns']);
        }
        
        // Check RCT config table
        $rct_configs_exists = $wpdb->get_var("SHOW TABLES LIKE '{$rct_configs_table}'") === $rct_configs_table;
        $rct_configs_info = array(
            'exists' => $rct_configs_exists,
            'table_name' => $rct_configs_table,
            'row_count' => 0,
            'columns_ok' => false
        );
        
        if ($rct_configs_exists) {
            $rct_configs_info['row_count'] = intval($wpdb->get_var("SELECT COUNT(*) FROM {$rct_configs_table}"));
            $rct_configs_info['columns_ok'] = true; // Simplified check for now
        }
        
        // Check RCT assignments table
        $rct_assignments_exists = $wpdb->get_var("SHOW TABLES LIKE '{$rct_assignments_table}'") === $rct_assignments_table;
        $rct_assignments_info = array(
            'exists' => $rct_assignments_exists,
            'table_name' => $rct_assignments_table,
            'row_count' => 0,
            'columns_ok' => false
        );
        
        if ($rct_assignments_exists) {
            $rct_assignments_info['row_count'] = intval($wpdb->get_var("SELECT COUNT(*) FROM {$rct_assignments_table}"));
            $rct_assignments_info['columns_ok'] = true; // Simplified check for now
        }
        
        // Check longitudinal tables
        $longitudinal_tables = array(
            'survey_studies' => $survey_studies_table,
            'survey_participants' => $survey_participants_table,
            'survey_sessions' => $survey_sessions_table,
            'survey_waves' => $survey_waves_table,
            'survey_assignments' => $survey_assignments_table,
            'survey_magic_links' => $survey_magic_links_table,
            'survey_email_log' => $survey_email_log_table,
            'survey_audit_log' => $survey_audit_log_table,
        );
        
        $longitudinal_info = array();
        foreach ($longitudinal_tables as $key => $table_name) {
            $exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name;
            $longitudinal_info[$key] = array(
                'exists' => $exists,
                'table_name' => $table_name,
                'row_count' => $exists ? intval($wpdb->get_var("SELECT COUNT(*) FROM {$table_name}")) : 0
            );
        }
        
        // Determine overall status
        $all_tables_exist = $results_exists && $events_exists && $rct_configs_exists && $rct_assignments_exists;
        $all_columns_ok = $results_info['columns_ok'] && $events_info['columns_ok'];
        
        // Calculate total records
        $total_records = $results_info['row_count'] + $events_info['row_count'];
        
        // Build message
        $message = '';
        if (!$all_tables_exist) {
            $message = __('⚠️ Algunas tablas de la base de datos local no existen', 'eipsi-forms');
        } elseif (!$all_columns_ok) {
            $message = __('⚠️ Las tablas existen pero faltan algunas columnas', 'eipsi-forms');
        } else {
            $message = __('✓ Todas las tablas locales están correctamente configuradas', 'eipsi-forms');
        }
        
        return array(
            'success' => true,
            'message' => $message,
            'db_name' => DB_NAME,
            'db_host' => DB_HOST,
            'all_tables_exist' => $all_tables_exist,
            'all_columns_ok' => $all_columns_ok,
            'using_wordpress_db' => true,
            'last_verified' => $last_verified,
            'total_records' => $total_records,
            'results_table' => $results_info,
            'events_table' => $events_info,
            'randomization_configs_table' => $rct_configs_info,
            'randomization_assignments_table' => $rct_assignments_info,
            'longitudinal_tables' => $longitudinal_info
        );
    }
}

