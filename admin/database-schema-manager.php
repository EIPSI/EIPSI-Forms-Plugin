<?php

if (!defined('ABSPATH')) {

    exit;

}



// Load database repair utilities for fixing corrupt indexes

require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/database-schema-repair.php';



if ( ! class_exists( 'WP_Error' ) ) {

    require_once ABSPATH . 'wp-includes/class-wp-error.php';

}



/**

 * EIPSI Forms Database Schema Manager

 * Handles automatic table creation and schema synchronization

 * 

 * @package EIPSI_Forms

 * @since 1.2.1

 */

class EIPSI_Database_Schema_Manager {

    

    /**

     * Centralized Schema Map

     * Contains all table definitions (columns and indices)

     * 

     * @since 1.4.0

     * @return array

     */

    public static function get_schema_map() {

        return array(

            'vas_form_results' => array(

                'columns' => array(

                    'id' => 'bigint(20) unsigned NOT NULL AUTO_INCREMENT',

                    'form_id' => 'varchar(20) DEFAULT NULL',

                    'participant_id' => 'varchar(255) DEFAULT NULL',

                    'survey_id' => 'INT(11) DEFAULT NULL',

                    'wave_index' => 'INT(11) DEFAULT NULL',

                    'session_id' => 'varchar(255) DEFAULT NULL',

                    'user_fingerprint' => 'varchar(255) DEFAULT NULL',

                    'participant' => 'varchar(255) DEFAULT NULL',

                    'interaction' => 'varchar(255) DEFAULT NULL',

                    'form_name' => 'varchar(255) NOT NULL',

                    'created_at' => 'datetime NOT NULL',

                    'submitted_at' => 'datetime DEFAULT NULL',

                    'device' => 'varchar(100) DEFAULT NULL',

                    'browser' => 'varchar(100) DEFAULT NULL',

                    'os' => 'varchar(100) DEFAULT NULL',

                    'screen_width' => 'int(11) DEFAULT NULL',

                    'duration' => 'int(11) DEFAULT NULL',

                    'duration_seconds' => 'decimal(8,3) DEFAULT NULL',

                    'start_timestamp_ms' => 'bigint(20) DEFAULT NULL',

                    'end_timestamp_ms' => 'bigint(20) DEFAULT NULL',

                    'ip_address' => 'varchar(45) DEFAULT NULL',

                    'metadata' => 'LONGTEXT DEFAULT NULL',

                    'status' => "enum('pending','submitted','error') DEFAULT 'submitted'",

                    'rct_assigned_variant' => 'varchar(100) DEFAULT NULL',

                    'rct_randomization_id' => 'varchar(100) DEFAULT NULL',

                    'form_responses' => 'longtext DEFAULT NULL',

                ),

                'indices' => array(

                    'PRIMARY KEY  (id)',

                    'KEY form_name (form_name)',

                    'KEY created_at (created_at)',

                    'KEY form_id (form_id)',

                    'KEY participant_id (participant_id)',

                    'KEY session_id (session_id)',

                    'KEY ip_address (ip_address)',

                    'KEY submitted_at (submitted_at)',

                    'KEY participant_survey_wave (participant_id, survey_id, wave_index)',

                    'KEY form_participant (form_id, participant_id)'

                )

            ),

            'vas_form_events' => array(

                'columns' => array(

                    'id' => 'bigint(20) unsigned NOT NULL AUTO_INCREMENT',

                    'form_id' => "varchar(255) NOT NULL DEFAULT ''",

                    'session_id' => 'varchar(255) NOT NULL',

                    'event_type' => 'varchar(50) NOT NULL',

                    'page_number' => 'int(11) DEFAULT NULL',

                    'metadata' => 'text DEFAULT NULL',

                    'user_agent' => 'text DEFAULT NULL',

                    'created_at' => 'datetime NOT NULL',

                ),

                'indices' => array(

                    'PRIMARY KEY  (id)',

                    'KEY form_id (form_id)',

                    'KEY session_id (session_id)',

                    'KEY event_type (event_type)',

                    'KEY created_at (created_at)',

                    'KEY form_session (form_id, session_id)'

                )

            ),

            'eipsi_randomization_configs' => array(

                'columns' => array(

                    'id' => 'bigint(20) unsigned NOT NULL AUTO_INCREMENT',

                    'randomization_id' => 'varchar(255) NOT NULL',

                    'formularios' => 'LONGTEXT NOT NULL',

                    'probabilidades' => 'LONGTEXT',

                    'method' => "varchar(20) DEFAULT 'seeded'",

                    'manual_assignments' => 'LONGTEXT',

                    'show_instructions' => 'tinyint(1) DEFAULT 0',

                    'created_at' => 'datetime DEFAULT CURRENT_TIMESTAMP',

                    'updated_at' => 'datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',

                ),

                'indices' => array(

                    'PRIMARY KEY  (id)',

                    'UNIQUE KEY randomization_id (randomization_id)',

                    'KEY method (method)',

                    'KEY created_at (created_at)'

                )

            ),

            'eipsi_randomization_assignments' => array(

                'columns' => array(

                    'id' => 'bigint(20) unsigned NOT NULL AUTO_INCREMENT',

                    'randomization_id' => 'varchar(255) NOT NULL',

                    'config_id' => 'varchar(255) NOT NULL',

                    'user_fingerprint' => 'varchar(255) NOT NULL',

                    'assigned_form_id' => 'bigint(20) unsigned NOT NULL',

                    'assigned_at' => 'datetime DEFAULT CURRENT_TIMESTAMP',

                    'last_access' => 'datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',

                    'access_count' => 'int(11) DEFAULT 1',

                ),

                'indices' => array(

                    'PRIMARY KEY  (id)',

                    'UNIQUE KEY unique_assignment (randomization_id, config_id, user_fingerprint)',

                    'KEY randomization_id (randomization_id)',

                    'KEY config_id (config_id)',

                    'KEY user_fingerprint (user_fingerprint)',

                    'KEY assigned_form_id (assigned_form_id)',

                    'KEY assigned_at (assigned_at)'

                )

            ),

            'survey_studies' => array(

                'columns' => array(

                    'id' => 'BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT',

                    'study_code' => 'VARCHAR(50) NOT NULL',

                    'study_name' => 'VARCHAR(255) NOT NULL',

                    'description' => 'TEXT',

                    'principal_investigator_id' => 'BIGINT(20) UNSIGNED',

                    'status' => "ENUM('active', 'completed', 'paused', 'archived') DEFAULT 'active'",

                    'config' => 'JSON',

                    'study_end_offset_minutes' => "INT(11) DEFAULT NULL COMMENT 'Minutes after T1 when study closes for participant'",

                    'created_at' => 'DATETIME NOT NULL',

                    'updated_at' => 'DATETIME NOT NULL',

                ),

                'indices' => array(

                    'PRIMARY KEY  (id)',

                    'UNIQUE KEY unique_study_code (study_code)',

                    'KEY status (status)',

                    'KEY principal_investigator_id (principal_investigator_id)',

                    'KEY created_at (created_at)',

                    'KEY idx_study_end_offset (study_end_offset_minutes)'

                )

            ),

            'survey_participants' => array(

                'columns' => array(

                    'id' => 'BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT',

                    'survey_id' => 'INT(11)',

                    'email' => 'VARCHAR(255) NOT NULL',

                    'password_hash' => 'VARCHAR(255)',

                    'first_name' => 'VARCHAR(100)',

                    'last_name' => 'VARCHAR(100)',

                    'created_at' => 'DATETIME NOT NULL',

                    'last_login_at' => 'DATETIME',

                    'is_active' => 'TINYINT(1) DEFAULT 1',

                    'status' => "VARCHAR(30) DEFAULT 'active'",

                    'consent_decision' => 'VARCHAR(20) NULL',

                    'consent_decided_at' => 'DATETIME NULL',

                    'consent_ip_address' => 'VARCHAR(45) NULL',

                    'consent_user_agent' => 'VARCHAR(500) NULL',

                    'consent_context' => 'VARCHAR(50) NULL',
                    'consent_blocked_survey_id' => 'BIGINT(20) UNSIGNED NULL',

                    't1_completed_at' => "DATETIME DEFAULT NULL COMMENT 'Anchor timestamp: when participant completed T1'",

                    'withdrawal_wave_id' => 'BIGINT(20) UNSIGNED NULL',

                    'data_deleted' => 'TINYINT(1) DEFAULT 0',

                    'updated_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',

                ),

                'indices' => array(

                    'PRIMARY KEY  (id)',

                    'UNIQUE KEY unique_survey_email (survey_id, email)',

                    'KEY survey_id (survey_id)',

                    'KEY is_active (is_active)',

                    'KEY idx_email (email)',

                    'KEY idx_created_at (created_at)',

                    'KEY idx_consent_decision (consent_decision)'

                )

            ),

            'survey_sessions' => array(

                'columns' => array(

                    'id' => 'BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT',

                    'token' => 'VARCHAR(255) NOT NULL',

                    'participant_id' => 'BIGINT(20) UNSIGNED NOT NULL',

                    'survey_id' => 'INT(11)',

                    'ip_address' => 'VARCHAR(45)',

                    'user_agent' => 'VARCHAR(500)',

                    'expires_at' => 'DATETIME NOT NULL',

                    'created_at' => 'DATETIME NOT NULL',

                ),

                'indices' => array(

                    'PRIMARY KEY  (id)',

                    'UNIQUE KEY unique_token (token)',

                    'KEY participant_id (participant_id)',

                    'KEY expires_at (expires_at)'

                )

            ),

            'survey_waves' => array(

                'columns' => array(

                    'id' => 'BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT',

                    'study_id' => 'BIGINT(20) UNSIGNED NOT NULL',

                    'wave_index' => 'INT NOT NULL',

                    'name' => 'VARCHAR(255) NOT NULL',

                    'form_id' => 'BIGINT(20) UNSIGNED NOT NULL',

                    'start_date' => 'DATETIME NULL',

                    'due_date' => 'DATETIME NULL',

                    'interval_days' => 'INT(11) DEFAULT 7',

                    'offset_minutes' => "INT(11) DEFAULT 0 COMMENT 'Minutes after T1 completion when this wave becomes available'",

                    'window_minutes' => "INT(11) DEFAULT NULL COMMENT 'Minutes the wave stays open after available_at'",

                    'time_unit' => "VARCHAR(10) DEFAULT 'days'",

                    'reminder_days' => 'INT DEFAULT 3',

                    'retry_enabled' => 'TINYINT(1) DEFAULT 1',

                    'retry_days' => 'INT DEFAULT 7',

                    'max_retries' => 'INT DEFAULT 3',

                    'has_time_limit' => 'TINYINT(1) DEFAULT 0',

                    'completion_time_limit' => 'INT DEFAULT NULL',

                    'status' => "ENUM('draft', 'active', 'completed', 'paused') DEFAULT 'draft'",

                    'is_mandatory' => 'TINYINT(1) DEFAULT 1',

                    'follow_up_reminders_enabled' => 'TINYINT(1) DEFAULT 1',

                    'nudge_config' => 'TEXT DEFAULT NULL',

                    'created_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP',

                    'updated_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',

                ),

                'indices' => array(

                    'PRIMARY KEY  (id)',

                    'KEY idx_study_id (study_id)',

                    'KEY idx_status (status)',

                    'KEY idx_due_date (due_date)',

                    'KEY idx_offset_minutes (offset_minutes)',

                    'UNIQUE KEY uk_study_index (study_id, wave_index)'

                )

            ),

            'survey_assignments' => array(

                'columns' => array(

                    'id' => 'BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT',

                    'study_id' => 'BIGINT(20) UNSIGNED NOT NULL',

                    'wave_id' => 'BIGINT(20) UNSIGNED NOT NULL',

                    'participant_id' => 'BIGINT(20) UNSIGNED NOT NULL',

                    'status' => "ENUM('pending', 'in_progress', 'submitted', 'skipped', 'expired') DEFAULT 'pending'",

                    'assigned_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP',

                    'first_viewed_at' => 'DATETIME NULL',

                    'submitted_at' => 'DATETIME NULL',

                    't1_completed_at' => "DATETIME NULL COMMENT 'Phase 5 T1-Anchor: Timestamp when T1 was completed (triggers T2+ availability calculation)'",

                    'reminder_count' => 'INT DEFAULT 0',

                    'last_nudge_sent_at' => "DATETIME NULL COMMENT 'Timestamp real del último nudge enviado exitosamente'",

                    'last_reminder_sent' => 'DATETIME NULL',

                    'retry_count' => 'INT DEFAULT 0',

                    'last_retry_sent' => 'DATETIME NULL',

                    'due_at' => 'DATETIME NULL',

                    'available_at' => 'DATETIME NULL',

                    'created_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP',

                    'updated_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',

                ),

                'indices' => array(

                    'PRIMARY KEY  (id)',

                    'KEY idx_study_id (study_id)',

                    'KEY idx_wave_id (wave_id)',

                    'KEY idx_participant_id (participant_id)',

                    'KEY idx_status (status)',

                    'KEY idx_submitted_at (submitted_at)',

                    'KEY idx_due_at (due_at)',

                    'KEY idx_available_at (available_at)',

                    'KEY idx_t1_completed (t1_completed_at)',

                    'UNIQUE KEY uk_wave_participant (wave_id, participant_id)'

                )

            ),

            'survey_magic_links' => array(

                'columns' => array(

                    'id' => 'BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT',

                    'survey_id' => 'BIGINT(20) UNSIGNED NOT NULL',

                    'participant_id' => 'BIGINT(20) UNSIGNED NOT NULL',

                    'token_hash' => 'VARCHAR(255) NOT NULL',

                    'expires_at' => 'DATETIME NOT NULL',

                    'used_at' => 'DATETIME NULL',

                    'created_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP',

                ),

                'indices' => array(

                    'PRIMARY KEY  (id)',

                    'KEY idx_survey_participant (survey_id, participant_id)',

                    'KEY idx_token_hash (token_hash)',

                    'KEY idx_expires_used (expires_at, used_at)',

                    'UNIQUE KEY uk_token_hash (token_hash)'

                )

            ),

            'survey_email_log' => array(

                'columns' => array(

                    'id' => 'BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT',

                    'participant_id' => 'BIGINT(20) UNSIGNED NOT NULL',

                    'survey_id' => 'INT(11)',

                    // v2.6.1 - Changed from ENUM to VARCHAR to support wave-specific types (wave_availability_T1, nudge_1_T2, etc.)
                    'email_type' => "VARCHAR(100) DEFAULT 'custom'",

                    'wave_id' => 'BIGINT(20) UNSIGNED',

                    'recipient_email' => 'VARCHAR(255)',

                    'subject' => 'VARCHAR(500)',

                    'content' => 'TEXT',

                    'sent_at' => 'DATETIME NOT NULL',

                    'status' => "ENUM('sent', 'failed', 'bounced', 'audit') DEFAULT 'sent'",

                    'error_message' => 'TEXT',

                    'magic_link_used' => 'TINYINT(1) DEFAULT 0',

                    'metadata' => 'JSON',

                    'created_at' => 'DATETIME',

                ),

                'indices' => array(

                    'PRIMARY KEY  (id)',

                    'KEY participant_id (participant_id)',

                    'KEY sent_at (sent_at)',

                    'KEY status (status)'

                )

            ),

            'survey_audit_log' => array(

                'columns' => array(

                    'id' => 'BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT',

                    'survey_id' => 'BIGINT(20) UNSIGNED NOT NULL',

                    'participant_id' => 'BIGINT(20) UNSIGNED NULL',

                    'action' => 'VARCHAR(100) NOT NULL',

                    'actor_type' => "ENUM('admin', 'system') DEFAULT 'system'",

                    'actor_id' => 'BIGINT(20) UNSIGNED NULL',

                    'actor_username' => 'VARCHAR(255) NULL',

                    'ip_address' => 'VARCHAR(45) NULL',

                    'metadata' => 'JSON NULL',

                    'created_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP',

                ),

                'indices' => array(

                    'PRIMARY KEY  (id)',

                    'KEY idx_survey_id (survey_id)',

                    'KEY idx_action (action)',

                    'KEY idx_created_at (created_at)',

                    'KEY idx_participant_id (participant_id)',

                    'KEY idx_survey_action (survey_id, action)',

                    'KEY idx_survey_created (survey_id, created_at)',

                    'KEY idx_action_created (action, created_at)'

                )

            ),

            'survey_email_confirmations' => array(

                'columns' => array(

                    'id' => 'BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT',

                    'survey_id' => 'INT(11) NOT NULL',

                    'participant_id' => 'BIGINT(20) UNSIGNED NOT NULL',

                    'email' => 'VARCHAR(255) NOT NULL',

                    'token_hash' => 'VARCHAR(64) NOT NULL',

                    'expires_at' => 'DATETIME NOT NULL',

                    'confirmed_at' => 'DATETIME NULL',

                    'created_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP',

                ),

                'indices' => array(

                    'PRIMARY KEY  (id)',

                    'KEY idx_participant (participant_id)',

                    'KEY idx_email (email)',

                    'KEY idx_token_hash (token_hash)',

                    'KEY idx_expires_at (expires_at)',

                    'KEY idx_confirmed_at (confirmed_at)',

                    'UNIQUE KEY idx_participant_email (participant_id, email)'

                )

            ),

            'eipsi_longitudinal_pools' => array(

                'columns' => array(

                    'id' => 'BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT',

                    'pool_name' => 'VARCHAR(255) NOT NULL',

                    'pool_description' => 'TEXT',

                    'studies' => 'JSON',

                    'probabilities' => 'JSON',

                    'method' => "ENUM('seeded', 'pure-random') DEFAULT 'seeded'",

                    'status' => "ENUM('active', 'inactive') DEFAULT 'active'",

                    'config' => 'JSON',

                    'page_id' => 'BIGINT(20) UNSIGNED DEFAULT NULL',

                    'created_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP',

                    'updated_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',

                ),

                'indices' => array(

                    'PRIMARY KEY  (id)',

                    'KEY idx_status (status)',

                    'KEY idx_method (method)',

                    'KEY idx_created_at (created_at)'

                )

            ),

            'eipsi_pool_assignments' => array(

                'columns' => array(

                    'id' => 'BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT',

                    'pool_id' => 'BIGINT(20) UNSIGNED NOT NULL',

                    // v2.6.1: BIGINT UNSIGNED to match survey_participants.id. VARCHAR(255) broke

                    // fk_pool_assignments_participant with errno 150 (FK incorrectly formed).

                    'participant_id' => 'BIGINT(20) UNSIGNED NOT NULL',

                    'study_id' => 'BIGINT(20) UNSIGNED NOT NULL',

                    'assigned_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',

                    'first_access' => 'DATETIME DEFAULT NULL',

                    'last_access' => 'DATETIME DEFAULT NULL',

                    'access_count' => 'INT(11) DEFAULT 0',

                    'completed' => 'TINYINT(1) DEFAULT 0',

                    'completed_at' => 'DATETIME DEFAULT NULL',

                    'completion_form_id' => 'VARCHAR(20) DEFAULT NULL',

                ),

                'indices' => array(

                    'PRIMARY KEY  (id)',

                    'KEY idx_pool_id (pool_id)',

                    'KEY idx_participant_id (participant_id)',

                    'KEY idx_study_id (study_id)',

                    'UNIQUE KEY unique_pool_participant (pool_id, participant_id)'

                )

            ),

            'eipsi_pool_analytics' => array(

                'columns' => array(

                    'id' => 'BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT',

                    'pool_id' => 'BIGINT(20) UNSIGNED NOT NULL',

                    'date' => 'DATE NOT NULL',

                    'study_id' => 'BIGINT(20) UNSIGNED NOT NULL',

                    'assignments' => 'INT(11) DEFAULT 0',

                    'completions' => 'INT(11) DEFAULT 0',

                    'cumulative_assignments' => 'INT(11) DEFAULT 0',

                ),

                'indices' => array(

                    'PRIMARY KEY  (id)',

                    'KEY idx_pool_date (pool_id, date)',

                    'KEY idx_study_id (study_id)'

                )

            ),

            'survey_participant_access_log' => array(

                'columns' => array(

                    'id' => 'BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT',

                    'participant_id' => 'BIGINT(20) UNSIGNED NOT NULL',

                    'study_id' => 'INT(11) NOT NULL',

                    'action_type' => "ENUM('registration', 'login', 'login_failed', 'magic_link_clicked', 'magic_link_sent', 'wave_started', 'wave_completed', 'logout', 'session_expired', 'password_reset_requested', 'password_reset_completed') NOT NULL",

                    'ip_address' => 'VARCHAR(45) NOT NULL',

                    'user_agent' => 'VARCHAR(500)',

                    'metadata' => 'JSON',

                    'created_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP',

                ),

                'indices' => array(

                    'PRIMARY KEY  (id)',

                    'KEY idx_participant_id (participant_id)',

                    'KEY idx_study_id (study_id)',

                    'KEY idx_action_type (action_type)',

                    'KEY idx_created_at (created_at)',

                    'KEY idx_participant_action (participant_id, action_type)',

                    'KEY idx_study_created (study_id, created_at)'

                )

            ),

            'eipsi_device_data' => array(

                'columns' => array(

                    'id' => 'BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT',

                    'submission_id' => 'BIGINT(20) UNSIGNED NULL',

                    'participant_id' => 'BIGINT(20) UNSIGNED NULL',

                    'canvas_fingerprint' => 'VARCHAR(255) NULL',

                    'webgl_renderer' => 'VARCHAR(255) NULL',

                    'screen_resolution' => 'VARCHAR(50) NULL',

                    'screen_depth' => 'INT NULL',

                    'pixel_ratio' => 'DECIMAL(4,2) NULL',

                    'timezone' => 'VARCHAR(100) NULL',

                    'timezone_offset' => 'INT NULL',

                    'language' => 'VARCHAR(50) NULL',

                    'languages' => 'VARCHAR(255) NULL',

                    'cpu_cores' => 'INT NULL',

                    'ram' => 'INT NULL',

                    'do_not_track' => 'VARCHAR(20) NULL',

                    'cookies_enabled' => 'VARCHAR(10) NULL',

                    'plugins' => 'TEXT NULL',

                    'user_agent' => 'TEXT NULL',

                    'platform' => 'VARCHAR(100) NULL',

                    'touch_support' => 'VARCHAR(10) NULL',

                    'max_touch_points' => 'INT NULL',

                    'captured_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP',

                ),

                'indices' => array(

                    'PRIMARY KEY  (id)',

                    'KEY idx_submission_id (submission_id)',

                    'KEY idx_participant_id (participant_id)',

                    'KEY idx_captured_at (captured_at)'

                )

            ),

            'eipsi_pool_email_log' => array(

                'columns' => array(

                    'id' => 'BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT',

                    'pool_id' => 'INT NOT NULL',

                    'participant_id' => 'BIGINT UNSIGNED NOT NULL',

                    'email' => 'VARCHAR(255) NOT NULL',

                    'action' => "ENUM('sent', 'confirmed', 'resent') NOT NULL",

                    'created_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP',

                ),

                'indices' => array(

                    'PRIMARY KEY  (id)',

                    'KEY idx_pool_id (pool_id)',

                    'KEY idx_participant_id (participant_id)',

                    'KEY idx_email (email)',

                    'KEY idx_action (action)',

                    'KEY idx_created_at (created_at)'

                )

            ),

            'eipsi_emergency_submissions' => array(

                'columns' => array(

                    'id' => 'bigint(20) unsigned NOT NULL AUTO_INCREMENT',

                    'form_id' => 'varchar(50) DEFAULT NULL',

                    'participant_id' => 'varchar(255) DEFAULT NULL',

                    'session_id' => 'varchar(255) DEFAULT NULL',

                    'survey_id' => 'bigint(20) DEFAULT NULL',

                    'wave_id' => 'bigint(20) DEFAULT NULL',

                    'form_responses' => 'longtext DEFAULT NULL',

                    'form_data' => 'longtext DEFAULT NULL',

                    'raw_post_data' => 'longtext DEFAULT NULL',

                    'metadata' => 'longtext DEFAULT NULL',

                    'device' => 'varchar(100) DEFAULT NULL',

                    'browser' => 'varchar(100) DEFAULT NULL',

                    'os' => 'varchar(100) DEFAULT NULL',

                    'screen_width' => 'int(11) DEFAULT NULL',

                    'duration' => 'int(11) DEFAULT NULL',

                    'ip_address' => 'varchar(45) DEFAULT NULL',

                    'user_agent' => 'text DEFAULT NULL',

                    'error_message' => 'text DEFAULT NULL',

                    'error_code' => 'varchar(50) DEFAULT NULL',

                    'db_type' => "varchar(20) DEFAULT 'wordpress'",

                    'resolved' => 'tinyint(1) DEFAULT 0',

                    'resolved_at' => 'datetime DEFAULT NULL',

                    'created_at' => 'datetime DEFAULT CURRENT_TIMESTAMP',

                ),

                'indices' => array(

                    'PRIMARY KEY  (id)',

                    'KEY form_id (form_id)',

                    'KEY participant_id (participant_id)',

                    'KEY session_id (session_id)',

                    'KEY survey_id (survey_id)',

                    'KEY resolved (resolved)',

                    'KEY created_at (created_at)'

                )

            ),

            'survey_nudge_jobs' => array(

                'columns' => array(

                    'id' => 'BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT',

                    'job_type' => 'VARCHAR(50) NOT NULL',

                    'payload' => 'LONGTEXT',

                    'priority' => 'INT(11) DEFAULT 10',

                    'status' => "VARCHAR(20) DEFAULT 'pending'",

                    'retries' => 'INT(11) DEFAULT 0',

                    'error' => 'TEXT',

                    'result' => 'TEXT',

                    'scheduled_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP',

                    'processed_at' => 'DATETIME NULL',

                    'completed_at' => 'DATETIME NULL',

                    'failed_at' => 'DATETIME NULL',

                    'created_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP',

                    'updated_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',

                ),

                'indices' => array(

                    'PRIMARY KEY  (id)',

                    'KEY status_scheduled (status, scheduled_at)',

                    'KEY priority_created (priority, created_at)',

                    'KEY job_type (job_type)'

                )

            ),

            'eipsi_partial_responses' => array(

                'columns' => array(

                    'id' => 'bigint(20) unsigned NOT NULL AUTO_INCREMENT',

                    'form_id' => 'varchar(64) NOT NULL',

                    'participant_id' => 'varchar(255) NOT NULL',

                    'session_id' => 'varchar(255) NOT NULL',

                    'page_index' => 'int(11) DEFAULT 1',

                    'responses_json' => 'longtext DEFAULT NULL',

                    'completed' => 'tinyint(1) DEFAULT 0',

                    'created_at' => 'datetime NOT NULL',

                    'updated_at' => 'datetime NOT NULL',

                ),

                'indices' => array(

                    'PRIMARY KEY  (id)',

                    'UNIQUE KEY unique_session (form_id, participant_id, session_id)',

                    'KEY updated_at (updated_at)',

                    'KEY completed (completed)',

                    'KEY idx_participant_completed (participant_id, completed)',

                    'KEY idx_updated_completed (updated_at, completed)',

                    'KEY idx_form_participant (form_id, participant_id)'

                )

            ),

            'survey_cron_log' => array(

                'columns' => array(

                    'id' => 'BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT',

                    'cron_hook' => 'VARCHAR(100) NOT NULL',

                    'executed_at' => 'DATETIME NOT NULL',

                    'metadata' => 'TEXT',

                ),

                'indices' => array(

                    'PRIMARY KEY  (id)',

                    'KEY cron_hook (cron_hook)',

                    'KEY executed_at (executed_at)'

                )

            ),

            'survey_weekly_reminders' => array(

                'columns' => array(

                    'id' => 'BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT',

                    'assignment_id' => 'BIGINT(20) UNSIGNED NOT NULL',

                    'reminder_number' => 'INT NOT NULL',

                    'sent_at' => 'DATETIME NOT NULL',

                    'created_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP',

                ),

                'indices' => array(

                    'PRIMARY KEY  (id)',

                    'KEY idx_assignment (assignment_id)',

                    'KEY idx_sent_at (sent_at)'

                )

            ),

            'survey_data_requests' => array(

                'columns' => array(

                    'id' => 'BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT',

                    'participant_id' => 'BIGINT(20) UNSIGNED NOT NULL',

                    'survey_id' => 'BIGINT(20) UNSIGNED NOT NULL',

                    'request_type' => 'VARCHAR(20) NOT NULL',

                    'reason' => 'TEXT',

                    'status' => "VARCHAR(20) NOT NULL DEFAULT 'pending'",

                    'admin_id' => 'BIGINT(20) UNSIGNED',

                    'admin_notes' => 'TEXT',

                    'result_data' => 'TEXT',

                    'created_at' => 'DATETIME NOT NULL',

                    'started_processing_at' => 'DATETIME',

                    'processed_at' => 'DATETIME',

                ),

                'indices' => array(

                    'PRIMARY KEY  (id)',

                    'KEY participant_id (participant_id)',

                    'KEY survey_id (survey_id)',

                    'KEY status (status)',

                    'KEY created_at (created_at)'

                )

            )

        );

    }



    /**

     * Get creation order for tables based on dependencies

     * 

     * Level 0: Roots

     * Level 1: Primary dependents

     * Level 2: Secondary dependents

     * 

     * @since 1.6.0

     * @return array

     */

    private static function get_table_creation_order() {

        return array(

            // Level 0: Independent tables (Roots)

            'vas_form_results',

            'vas_form_events',

            'eipsi_randomization_configs',

            'survey_studies',

            'eipsi_longitudinal_pools',

            'survey_audit_log',

            'eipsi_emergency_submissions',

            'survey_nudge_jobs',

            'eipsi_partial_responses',

            'survey_cron_log',

            

            // Level 1: Tables depending on Level 0

            'survey_participants',

            'survey_waves',

            'survey_sessions',

            'survey_magic_links',

            'survey_email_log',

            'survey_email_confirmations',

            'eipsi_pool_email_log',

            'eipsi_pool_analytics',

            

            // Level 2: Tables depending on Level 1 or multiple

            'eipsi_randomization_assignments',

            'survey_assignments',

            'survey_weekly_reminders',

            'eipsi_pool_assignments',

            'survey_participant_access_log',

            'eipsi_device_data',

            'survey_data_requests'

        );

    }



    /**

     * Generate dbDelta-compliant CREATE TABLE SQL

     * 

     * @param string $slug Table slug

     * @return string|false SQL or false if not found

     */

    public static function get_table_sql( $slug ) {

        global $wpdb;

        $map = self::get_schema_map();

        if ( ! isset( $map[$slug] ) ) return false;



        $table_name = $wpdb->prefix . $slug;

        $definition = $map[$slug];

        $lines = array();



        foreach ( $definition['columns'] as $col => $def ) {

            $lines[] = "$col $def";

        }



        foreach ( $definition['indices'] as $idx ) {

            $lines[] = $idx;

        }



        $sql = "CREATE TABLE $table_name (\n  " . implode( ",\n  ", $lines ) . "\n) " . $wpdb->get_charset_collate() . ";";

        return $sql;

    }



    /**

     * Synchronize a local table using dbDelta

     * 

     * @param string $slug Table slug

     * @return array Result

     */

    public static function sync_local_table( $slug ) {

        global $wpdb;

        $table_name = $wpdb->prefix . $slug;

        

        $result = array(

            'success' => true,

            'exists' => false,

            'created' => false,

            'columns_added' => array(),

            'error' => null

        );



        $already_existed = self::local_table_exists( $table_name );

        $result['exists'] = true;



        // Fix corrupt indexes before dbDelta

        if ( function_exists( 'eipsi_fix_corrupt_indexes' ) ) {

            eipsi_fix_corrupt_indexes( array( $table_name ) );

        }



        $sql = self::get_table_sql( $slug );

        if ( ! $sql ) {

            $result['success'] = false;

            $result['error'] = "No definition found for $slug";

            return $result;

        }



        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta( $sql );



        if ( ! $already_existed ) {

            $result['created'] = true;

            error_log( "[EIPSI Forms] Created table: $table_name" );

        }



        return $result;

    }



    /**

     * Repair all LOCAL WordPress database schema

     * 

     * @return array Repair log

     */

    public static function repair_local_schema() {

        $order = self::get_table_creation_order();

        $repair_log = array( 'success' => true );



        foreach ( $order as $slug ) {

            $res = self::sync_local_table( $slug );

            $repair_log[$slug . '_table'] = $res;

            if ( ! $res['success'] ) $repair_log['success'] = false;

        }



        // Phase 2: Foreign Keys

        self::add_foreign_keys_phase2();



        // Update version and timestamp

        update_option( 'eipsi_db_schema_version', EIPSI_FORMS_VERSION );

        update_option( 'eipsi_schema_last_verified', current_time( 'mysql' ) );

        // Fix collations
        self::fix_collations();
        // v2.6.1: email_type ENUM->VARCHAR migration is handled by dbDelta via
        // get_schema_map() (survey_email_log is created as VARCHAR(100)). The legacy
        // migrate_email_type_to_varchar() no longer exists and crashed activation
        // on fresh installs (Call to undefined method).
        return $repair_log;

    }



    /**

     * Migration: Add missing columns to survey_waves for Fase 4 (Reactive Proportional Nudges)

     * Runs on admin_init to ensure columns exist even if dbDelta missed them

     *

     * @since 2.5.0

     */



    /**

     * Verify and synchronize schema for both local and external databases

     * 

     * @param mysqli|null $mysqli Optional external database connection

     * @return array Result

     */

    public static function verify_and_sync_schema( $mysqli = null ) {

        if ( $mysqli ) {

            return self::sync_external_schema( $mysqli );

        }



        return self::repair_local_schema();

    }



    /**

     * Sync external database schema using the centralized map

     * 

     * @param mysqli $mysqli

     * @return array

     */

    private static function sync_external_schema( $mysqli ) {

        global $wpdb;

        $map = self::get_schema_map();

        $results = array( 'success' => true, 'errors' => array() );

        $charset = $mysqli->character_set_name();



        // Core tables supported for external sync

        $external_tables = array(

            'vas_form_results',

            'vas_form_events',

            'eipsi_randomization_configs',

            'eipsi_randomization_assignments'

        );



        foreach ( $external_tables as $slug ) {

            $table_name = $wpdb->prefix . $slug;

            if ( ! isset( $map[$slug] ) ) continue;



            $definition = $map[$slug];

            

            // Check if table exists

            $check = $mysqli->query( "SHOW TABLES LIKE '$table_name'" );

            if ( ! $check || $check->num_rows === 0 ) {

                $lines = array();

                foreach ( $definition['columns'] as $col => $def ) {

                    $lines[] = "`$col` $def";

                }

                foreach ( $definition['indices'] as $idx ) {

                    $lines[] = $idx;

                }

                $sql = "CREATE TABLE `$table_name` (\n  " . implode( ",\n  ", $lines ) . "\n) ENGINE=InnoDB DEFAULT CHARSET=$charset;";

                

                if ( ! $mysqli->query( $sql ) ) {

                    $results['success'] = false;

                    $results['errors'][] = "Failed to create $table_name: " . $mysqli->error;

                    continue;

                }

            }



            // Sync columns

            foreach ( $definition['columns'] as $col => $def ) {

                $check_col = $mysqli->query( "SHOW COLUMNS FROM `$table_name` LIKE '$col'" );

                if ( ! $check_col || $check_col->num_rows === 0 ) {

                    $mysqli->query( "ALTER TABLE `$table_name` ADD COLUMN `$col` $def" );

                }

            }

        }



        return $results;

    }



    /**

     * Check if table exists in local database

     */

    private static function local_table_exists( $table_name ) {

        global $wpdb;

        return $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) ) === $table_name;

    }



    /**

     * Periodic verification hook

     */

    public static function periodic_verification() {

        $last_verified = get_option( 'eipsi_schema_last_verified', 0 );

        $current_time = current_time( 'timestamp' );

        if ( ( $current_time - strtotime( $last_verified ) ) > 86400 ) {

            self::repair_local_schema();

            require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/database.php';

            $db_helper = new EIPSI_External_Database();

            if ( $db_helper->is_enabled() ) {

                $mysqli = $db_helper->get_connection();

                if ( $mysqli ) {

                    self::verify_and_sync_schema( $mysqli );

                    $mysqli->close();

                }

            }

        }

    }



    /**

     * Hook: Called when database credentials are changed

     */

    public static function on_credentials_changed() {

        delete_option( 'eipsi_schema_last_verified' );

        require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/database.php';

        $db_helper = new EIPSI_External_Database();

        $mysqli = $db_helper->get_connection();

        if ( $mysqli ) {

            $result = self::verify_and_sync_schema( $mysqli );

            $mysqli->close();

            update_option( 'eipsi_schema_last_sync_result', $result );

            return $result;

        }

        return array( 'success' => false, 'error' => 'Could not connect to database' );

    }



    /**

     * Get verification status for UI

     */

    public static function get_verification_status() {

        $last_verified = get_option( 'eipsi_schema_last_verified', null );

        return array(

            'last_verified' => $last_verified,

            'last_sync_result' => get_option( 'eipsi_schema_last_sync_result', null ),

            'needs_verification' => empty( $last_verified ) || ( current_time( 'timestamp' ) - strtotime( $last_verified ) ) > 86400,

        );

    }



    /**

     * Get status for all tables

     */

    public static function get_all_tables_status() {

        $order = self::get_table_creation_order();

        $status = array();

        foreach ( $order as $slug ) {

            $status[$slug] = self::get_detailed_table_status( $slug );

        }

        return $status;

    }



    /**

     * Get detailed status for a single table

     */

    public static function get_detailed_table_status( $slug ) {

        global $wpdb;

        $full_table_name = $wpdb->prefix . $slug;

        $result = array(

            'table_name' => $slug,

            'full_table_name' => $full_table_name,

            'exists' => self::local_table_exists( $full_table_name ),

            'row_count' => 0,

            'status' => 'ok',

            'issues' => array(),

            'columns' => array(),

            'required_columns' => array(),

            'missing_columns' => array(),

            'indexes' => array(),

            'size_mb' => 0

        );

        if ( ! $result['exists'] ) {

            $result['status'] = 'error';

            $result['issues'][] = 'Table does not exist';

            return $result;

        }

        $result['row_count'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $full_table_name" );

        

        // Get table size

        $table_status = $wpdb->get_row( $wpdb->prepare( "SHOW TABLE STATUS LIKE %s", $full_table_name ), ARRAY_A );

        if ( $table_status ) {

            $result['size_mb'] = round( ( $table_status['Data_length'] + $table_status['Index_length'] ) / 1024 / 1024, 2 );

        }

        

        // Get columns

        $columns = $wpdb->get_results( "SHOW COLUMNS FROM $full_table_name", ARRAY_A );

        if ( $columns ) {

            foreach ( $columns as $col ) {

                $result['columns'][] = $col['Field'];

            }

        }

        

        // Get indexes

        $indexes = $wpdb->get_results( "SHOW INDEX FROM $full_table_name", ARRAY_A );

        if ( $indexes ) {

            $index_names = array();

            foreach ( $indexes as $idx ) {

                $index_names[] = $idx['Key_name'];

            }

            $result['indexes'] = array_unique( $index_names );

        }

        

        return $result;

    }



    /**

     * Get schema health summary

     */

    public static function get_schema_health_summary() {

        $all_tables = self::get_all_tables_status();

        $summary = array(

            'total_tables' => count( $all_tables ),

            'healthy_tables' => 0,

            'warning_tables' => 0,

            'error_tables' => 0,

            'total_rows' => 0,

            'total_size_mb' => 0,

            'last_verified' => get_option( 'eipsi_schema_last_verified', null ),

        );

        foreach ( $all_tables as $table ) {

            if ( $table['status'] === 'ok' ) {

                $summary['healthy_tables']++;

                $summary['total_rows'] += $table['row_count'];

                $summary['total_size_mb'] += $table['size_mb'];

            } else {

                $summary['error_tables']++;

            }

        }

        $summary['health_score'] = $summary['total_tables'] > 0 ? round( ( $summary['healthy_tables'] / $summary['total_tables'] ) * 100 ) : 0;

        return $summary;

    }



    /**

     * Fix collations for all plugin tables

     */

    public static function fix_collations() {

        global $wpdb;

        $tables = self::get_table_creation_order();

        $target_collation = 'utf8mb4_unicode_ci';

        $fixed = 0;



        foreach ( $tables as $slug ) {

            $table = $wpdb->prefix . $slug;

            if ( ! self::local_table_exists( $table ) ) continue;



            $status = $wpdb->get_row( $wpdb->prepare( "SHOW TABLE STATUS LIKE %s", $table ), ARRAY_A );

            if ( $status && $status['Collation'] !== $target_collation ) {

                $wpdb->query( "ALTER TABLE $table CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" );

                $fixed++;

            }

        }

        return array( 'success' => true, 'total_fixed' => $fixed );

    }



    /**

     * Phase 2: Add foreign keys after all tables exist

     */

    private static function add_foreign_keys_phase2() {

        global $wpdb;

        $fks = array(

            'survey_waves' => array(

                'fk_waves_study' => "ALTER TABLE {$wpdb->prefix}survey_waves ADD CONSTRAINT fk_waves_study FOREIGN KEY (study_id) REFERENCES {$wpdb->prefix}survey_studies(id) ON DELETE CASCADE",

            ),

            'survey_assignments' => array(

                'fk_assignments_study' => "ALTER TABLE {$wpdb->prefix}survey_assignments ADD CONSTRAINT fk_assignments_study FOREIGN KEY (study_id) REFERENCES {$wpdb->prefix}survey_studies(id) ON DELETE CASCADE",

                'fk_assignments_wave' => "ALTER TABLE {$wpdb->prefix}survey_assignments ADD CONSTRAINT fk_assignments_wave FOREIGN KEY (wave_id) REFERENCES {$wpdb->prefix}survey_waves(id) ON DELETE CASCADE",

                'fk_assignments_participant' => "ALTER TABLE {$wpdb->prefix}survey_assignments ADD CONSTRAINT fk_assignments_participant FOREIGN KEY (participant_id) REFERENCES {$wpdb->prefix}survey_participants(id) ON DELETE CASCADE",

            ),

            'survey_weekly_reminders' => array(

                'fk_weekly_reminders_assignment' => "ALTER TABLE {$wpdb->prefix}survey_weekly_reminders ADD CONSTRAINT fk_weekly_reminders_assignment FOREIGN KEY (assignment_id) REFERENCES {$wpdb->prefix}survey_assignments(id) ON DELETE CASCADE",

            ),

            'eipsi_pool_assignments' => array(

                'fk_pool_assignments_pool' => "ALTER TABLE {$wpdb->prefix}eipsi_pool_assignments ADD CONSTRAINT fk_pool_assignments_pool FOREIGN KEY (pool_id) REFERENCES {$wpdb->prefix}eipsi_longitudinal_pools(id) ON DELETE CASCADE",

                'fk_pool_assignments_participant' => "ALTER TABLE {$wpdb->prefix}eipsi_pool_assignments ADD CONSTRAINT fk_pool_assignments_participant FOREIGN KEY (participant_id) REFERENCES {$wpdb->prefix}survey_participants(id) ON DELETE CASCADE",

                'fk_pool_assignments_study' => "ALTER TABLE {$wpdb->prefix}eipsi_pool_assignments ADD CONSTRAINT fk_pool_assignments_study FOREIGN KEY (study_id) REFERENCES {$wpdb->prefix}survey_studies(id) ON DELETE CASCADE",

            ),

        );

        foreach ( $fks as $table => $constraints ) {

            foreach ( $constraints as $name => $sql ) {

                if ( function_exists( 'eipsi_longitudinal_ensure_foreign_key' ) ) {

                    eipsi_longitudinal_ensure_foreign_key( $wpdb->prefix . $table, $name, $sql );

                }

            }

        }

    }

}



// Global helper functions

function eipsi_longitudinal_fk_exists( $table_name, $constraint_name ) {

    global $wpdb;

    $exists = $wpdb->get_var( $wpdb->prepare(

        "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS

        WHERE CONSTRAINT_SCHEMA = %s AND TABLE_NAME = %s AND CONSTRAINT_NAME = %s AND CONSTRAINT_TYPE = 'FOREIGN KEY'",

        DB_NAME, $table_name, $constraint_name

    ) );

    return ! empty( $exists );

}



function eipsi_longitudinal_ensure_foreign_key( $table_name, $constraint_name, $alter_sql ) {

    global $wpdb;

    // Check if FK already exists
    if ( eipsi_longitudinal_fk_exists( $table_name, $constraint_name ) ) {
        return true;
    }

    // Suppress errors temporarily to avoid duplicate key warnings
    $wpdb->suppress_errors( true );
    $result = $wpdb->query( $alter_sql );
    $wpdb->suppress_errors( false );

    if ( $result === false ) {
        // Check if error is "duplicate key" (errno 121) - this means FK already exists
        if ( strpos( $wpdb->last_error, 'errno: 121' ) !== false || strpos( $wpdb->last_error, 'Duplicate key' ) !== false ) {
            // FK already exists, silently succeed
            return true;
        }
        
        // Only log real errors
        error_log( "[EIPSI] Failed to add FK $constraint_name on $table_name: " . $wpdb->last_error );
        return false;
    }

    return true;

}



function eipsi_table_exists( $table_name ) {

    global $wpdb;

    return ! empty( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) ) );

}



function eipsi_column_exists_db( $table_name, $column_name ) {

    global $wpdb;

    return ! empty( $wpdb->get_var( $wpdb->prepare(

        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS

        WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",

        DB_NAME, $table_name, $column_name

    ) ) );

}



function eipsi_get_column_info( $table_name, $column_name ) {

    global $wpdb;

    return $wpdb->get_row( $wpdb->prepare(

        "SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_KEY

        FROM INFORMATION_SCHEMA.COLUMNS

        WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",

        DB_NAME, $table_name, $column_name

    ), ARRAY_A );

}



/**

 * Unified longitudinal synchronization hook.

 */

add_action('eipsi_sync_longitudinal_tables', function() {

    if (class_exists('EIPSI_Database_Schema_Manager')) {

        EIPSI_Database_Schema_Manager::repair_local_schema();

    }

});



/**

 * RCT synchronization hook.

 */

add_action('eipsi_sync_rct_tables', function() {

    if (class_exists('EIPSI_Database_Schema_Manager')) {

        EIPSI_Database_Schema_Manager::sync_local_table('eipsi_randomization_configs');

        EIPSI_Database_Schema_Manager::sync_local_table('eipsi_randomization_assignments');

    }

});

