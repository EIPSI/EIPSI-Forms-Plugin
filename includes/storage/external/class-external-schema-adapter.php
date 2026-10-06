<?php
/** M8 definition owner; historical public contracts remain facades. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_External_Schema_Adapter {
public static function sync_external_schema( $mysqli ) {

        global $wpdb;

        $map = EIPSI_Schema_Registry::get_schema_map();

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

                    if (!$mysqli->query( "ALTER TABLE `$table_name` ADD COLUMN `$col` $def" )) {
                        $results['success'] = false;
                        $results['errors'][] = "Failed to add $table_name.$col: " . $mysqli->error;
                    }

                }

            }

        }



        return $results;

    }
public static function create_results($mysqli) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'vas_form_results';
        $charset = $mysqli->character_set_name();
        
        $sql = "CREATE TABLE IF NOT EXISTS `{$table_name}` (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            form_id varchar(15) DEFAULT NULL,
            participant_id varchar(255) DEFAULT NULL,
            survey_id INT(11) DEFAULT NULL,
            wave_index INT(11) DEFAULT NULL,
            session_id varchar(255) DEFAULT NULL,
            user_fingerprint varchar(255) DEFAULT NULL,
            participant varchar(255) DEFAULT NULL,
            interaction varchar(255) DEFAULT NULL,
            form_name varchar(255) NOT NULL,
            created_at datetime NOT NULL,
            submitted_at datetime DEFAULT NULL,
            device varchar(100) DEFAULT NULL,
            browser varchar(100) DEFAULT NULL,
            os varchar(100) DEFAULT NULL,
            screen_width int(11) DEFAULT NULL,
            duration int(11) DEFAULT NULL,
            duration_seconds decimal(8,3) DEFAULT NULL,
            start_timestamp_ms bigint(20) DEFAULT NULL,
            end_timestamp_ms bigint(20) DEFAULT NULL,
            ip_address varchar(45) DEFAULT NULL,
            metadata LONGTEXT DEFAULT NULL,
            status enum('pending','submitted','error') DEFAULT 'submitted',
            form_responses longtext DEFAULT NULL,
            PRIMARY KEY (id),
            KEY form_name (form_name),
            KEY created_at (created_at),
            KEY form_id (form_id),
            KEY participant_id (participant_id),
            KEY session_id (session_id),
            KEY submitted_at (submitted_at),
            KEY ip_address (ip_address),
            KEY form_participant (form_id, participant_id)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset}";
        
        $result = $mysqli->query($sql);
        
        if (!$result) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('EIPSI Forms: Failed to create table - ' . $mysqli->error);
            }
            return false;
        }
        
        return true;
    }

public static function ensure_required_columns($mysqli, $table_name) {
        $required_columns = array(
            'browser' => "ALTER TABLE `{$table_name}` ADD COLUMN browser varchar(100) DEFAULT NULL",
            'os' => "ALTER TABLE `{$table_name}` ADD COLUMN os varchar(100) DEFAULT NULL",
            'screen_width' => "ALTER TABLE `{$table_name}` ADD COLUMN screen_width int(11) DEFAULT NULL",
            'form_id' => "ALTER TABLE `{$table_name}` ADD COLUMN form_id varchar(15) DEFAULT NULL AFTER id",
            'participant_id' => "ALTER TABLE `{$table_name}` ADD COLUMN participant_id varchar(255) DEFAULT NULL AFTER form_id",
            'survey_id' => "ALTER TABLE `{$table_name}` ADD COLUMN survey_id INT(11) DEFAULT NULL AFTER participant_id",
            'wave_index' => "ALTER TABLE `{$table_name}` ADD COLUMN wave_index INT(11) DEFAULT NULL AFTER survey_id",
            'session_id' => "ALTER TABLE `{$table_name}` ADD COLUMN session_id varchar(255) DEFAULT NULL AFTER wave_index",
            'user_fingerprint' => "ALTER TABLE `{$table_name}` ADD COLUMN user_fingerprint varchar(255) DEFAULT NULL AFTER session_id",
            'duration_seconds' => "ALTER TABLE `{$table_name}` ADD COLUMN duration_seconds decimal(8,3) DEFAULT NULL AFTER duration",
            'submitted_at' => "ALTER TABLE `{$table_name}` ADD COLUMN submitted_at datetime DEFAULT NULL AFTER created_at",
            'start_timestamp_ms' => "ALTER TABLE `{$table_name}` ADD COLUMN start_timestamp_ms bigint(20) DEFAULT NULL AFTER duration_seconds",
            'end_timestamp_ms' => "ALTER TABLE `{$table_name}` ADD COLUMN end_timestamp_ms bigint(20) DEFAULT NULL AFTER start_timestamp_ms",
            'metadata' => "ALTER TABLE `{$table_name}` ADD COLUMN metadata LONGTEXT DEFAULT NULL AFTER ip_address",
            'status' => "ALTER TABLE `{$table_name}` ADD COLUMN status enum('pending','submitted','error') DEFAULT 'submitted' AFTER metadata"
        );
        
        foreach ($required_columns as $column => $alter_sql) {
            // Check if column exists
            $result = $mysqli->query("SHOW COLUMNS FROM `{$table_name}` LIKE '{$column}'");
            
            if (!$result || $result->num_rows === 0) {
                // Column doesn't exist, add it
                if (!$mysqli->query($alter_sql)) {
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log("EIPSI Forms: Failed to add column {$column} - " . $mysqli->error);
                    }
                    return false;
                }
            }
        }
        
        return true;
    }

}
