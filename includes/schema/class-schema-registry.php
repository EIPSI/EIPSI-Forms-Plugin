<?php
/** M8 definition owner; historical public contracts remain facades. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Schema_Registry {
public static function get_schema_map() {

        $map = array(

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

    
        $map['survey_participants']['columns']['study_end_at'] = 'DATETIME DEFAULT NULL';
        $map['survey_studies']['columns']['start_date'] = 'DATETIME DEFAULT NULL';
        $map['survey_studies']['columns']['end_date'] = 'DATETIME DEFAULT NULL';
        $map['eipsi_manual_overrides'] = array('columns'=>array(
            'id'=>'BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT', 'randomization_id'=>'VARCHAR(255) NOT NULL',
            'user_fingerprint'=>'VARCHAR(255) NOT NULL', 'assigned_form_id'=>'BIGINT(20) UNSIGNED NOT NULL',
            'reason'=>'TEXT DEFAULT NULL', 'created_by'=>'BIGINT(20) UNSIGNED NOT NULL',
            'created_at'=>'DATETIME DEFAULT CURRENT_TIMESTAMP', 'updated_at'=>'DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
            'status'=>"ENUM('active','revoked','expired') DEFAULT 'active'", 'expires_at'=>'DATETIME DEFAULT NULL'),
            'indices'=>array('PRIMARY KEY  (id)', 'UNIQUE KEY unique_override (randomization_id,user_fingerprint)', 'KEY randomization_id (randomization_id)', 'KEY user_fingerprint (user_fingerprint)', 'KEY status (status)', 'KEY expires_at (expires_at)', 'KEY created_by (created_by)'));
        return $map;
    }


public static function get_table_creation_order() {

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

            'survey_data_requests', 'eipsi_manual_overrides'

        );

    }

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
}
