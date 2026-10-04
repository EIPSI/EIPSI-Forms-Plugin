<?php
/**
 * EIPSI Forms - Migration Runner
 *
 * Coordinates and executes database migrations.
 *
 * @package EIPSI_Forms
 * @since 2.6.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EIPSI_Migration_Runner {

    const VERSION_OPTION  = 'eipsi_migration_version';
    const DATE_OPTION     = 'eipsi_migration_date';
    const LOCK_TRANSIENT  = 'eipsi_migration_running';
    const LOCK_TIMEOUT    = 60;
    const LATEST_VERSION  = 9;

    /**
     * Initialize the runner.
     */
    public static function init() {
        if ( ! is_admin() ) {
            return;
        }
        $instance = new self();
        $instance->run();
    }

    /**
     * Get the current migration version.
     * Seeds initial version if not set.
     *
     * @return int
     */
    private function get_current_version() {
        $version = get_option( self::VERSION_OPTION, false );

        if ( false === $version ) {
            $version = $this->seed_initial_version();
            $this->update_version( $version );
        }

        return (int) $version;
    }

    /**
     * Seed initial version based on legacy migration flags.
     * Uses minimum candidate approach to avoid skipping migrations.
     *
     * @return int
     */
    private function seed_initial_version() {
        $candidates = array( 9 );

        if ( ! get_option( 'eipsi_autofix_schema_version' ) ) {
            $candidates[] = 2;
        }
        if ( ! get_option( 'eipsi_fk_fix_version' ) ) {
            $candidates[] = 6;
        }
        if ( ! get_option( 'eipsi_pools_migrated_v2' ) ) {
            $candidates[] = 8;
        }

        return min( $candidates );
    }

    /**
     * Update the migration version.
     *
     * @param int $version
     */
    private function update_version( $version ) {
        update_option( self::VERSION_OPTION, (int) $version );
        update_option( self::DATE_OPTION, current_time( 'mysql' ) );
    }

    /**
     * Execute migrations in sequence with concurrency lock.
     */
    public function run() {
        $current_version = $this->get_current_version();

        if ( $current_version >= self::LATEST_VERSION ) {
            return;
        }

        if ( get_transient( self::LOCK_TRANSIENT ) ) {
            return;
        }

        set_transient( self::LOCK_TRANSIENT, time(), self::LOCK_TIMEOUT );

        try {
            for ( $v = $current_version + 1; $v <= self::LATEST_VERSION; $v++ ) {
                $method = "migrate_v{$v}";
                if ( method_exists( $this, $method ) ) {
                    error_log( "[EIPSI Migration] Starting migration v{$v}" );
                    $this->$method();
                    $this->update_version( $v );
                    error_log( "[EIPSI Migration] Completed migration v{$v}" );
                }
            }
        } catch ( Exception $e ) {
            error_log( "[EIPSI Migration] FAILED at version {$v}: " . $e->getMessage() );
            delete_transient( self::LOCK_TRANSIENT );
            return;
        }

        delete_transient( self::LOCK_TRANSIENT );
    }

    /**
     * Get database connection (local or external).
     *
     * @return mixed wpdb or mysqli
     */
    private function get_connection() {
        global $wpdb;
        return $wpdb;
    }

    /**
     * Execute query on connection.
     *
     * @param mixed  $db   Database connection
     * @param string $sql  SQL query
     * @return bool
     * @throws Exception
     */
    private function db_query( $db, $sql ) {
        $result = $db->query( $sql );
        if ( false === $result && ! empty( $db->last_error ) ) {
            throw new Exception( "Query failed: {$db->last_error}" );
        }
        return true;
    }

    /**
     * Get results from query.
     *
     * @param mixed  $db   Database connection
     * @param string $sql  SQL query
     * @return array
     */
    private function db_get_results( $db, $sql ) {
        return $db->get_results( $sql );
    }

    /**
     * Get single value from query.
     *
     * @param mixed  $db   Database connection
     * @param string $sql  SQL query
     * @return mixed
     */
    private function db_get_var( $db, $sql ) {
        return $db->get_var( $sql );
    }

    /**
     * Check if column exists in table.
     *
     * @param mixed  $db         Database connection
     * @param string $table      Table name
     * @param string $column     Column name
     * @return bool
     */
    private function column_exists( $db, $table, $column ) {
        $result = $this->db_get_var( $db, "SHOW COLUMNS FROM `{$table}` LIKE '{$column}'" );
        return ! is_null( $result );
    }

    /**
     * v1 - RCT Schema Rename
     * Rename template_id to randomization_id in eipsi_randomization_assignments
     */
    private function migrate_v1() {
        global $wpdb;
        $db = $this->get_connection();
        $table = $wpdb->prefix . 'eipsi_randomization_assignments';

        if ( $this->column_exists( $db, $table, 'template_id' ) ) {
            $sql = "ALTER TABLE `{$table}` CHANGE `template_id` `randomization_id` BIGINT(20) UNSIGNED NOT NULL";
            $this->db_query( $db, $sql );
        }
    }

    /**
     * v2 - RCT Autofix
     * Add config_id, persistent_mode, access_count columns and rebuild unique index
     */
    private function migrate_v2() {
        global $wpdb;
        $db = $this->get_connection();
        $table = $wpdb->prefix . 'eipsi_randomization_assignments';

        if ( ! $this->column_exists( $db, $table, 'config_id' ) ) {
            $sql = "ALTER TABLE `{$table}` ADD COLUMN `config_id` BIGINT(20) UNSIGNED DEFAULT NULL AFTER `randomization_id`";
            $this->db_query( $db, $sql );
        }

        if ( ! $this->column_exists( $db, $table, 'persistent_mode' ) ) {
            $sql = "ALTER TABLE `{$table}` ADD COLUMN `persistent_mode` VARCHAR(20) DEFAULT 'session' AFTER `config_id`";
            $this->db_query( $db, $sql );
        }

        if ( ! $this->column_exists( $db, $table, 'access_count' ) ) {
            $sql = "ALTER TABLE `{$table}` ADD COLUMN `access_count` INT(11) DEFAULT 0 AFTER `persistent_mode`";
            $this->db_query( $db, $sql );
        }

        $indexes = $this->db_get_results( $db, "SHOW INDEX FROM `{$table}` WHERE Key_name = 'unique_assignment'" );
        if ( ! empty( $indexes ) ) {
            $this->db_query( $db, "ALTER TABLE `{$table}` DROP INDEX `unique_assignment`" );
        }

        $this->db_query( $db, "ALTER TABLE `{$table}` ADD UNIQUE KEY `unique_assignment` (`participant_id`, `randomization_id`, `config_id`)" );
    }

    /**
     * v3 - Index Repair
     * Drop corrupted indexes with empty names or malformed backticks
     */
    private function migrate_v3() {
        global $wpdb;
        $db = $this->get_connection();

        $tables = $this->db_get_results( $db, "SHOW TABLES LIKE '{$wpdb->prefix}survey_%'" );

        foreach ( $tables as $table_obj ) {
            $table = array_values( (array) $table_obj )[0];
            $indexes = $this->db_get_results( $db, "SHOW INDEX FROM `{$table}`" );

            foreach ( $indexes as $index ) {
                if ( empty( $index->Key_name ) || preg_match( '/[`\']/', $index->Key_name ) ) {
                    $safe_name = $wpdb->_escape( $index->Key_name );
                    $this->db_query( $db, "ALTER TABLE `{$table}` DROP INDEX `{$safe_name}`" );
                }
            }
        }
    }

    /**
     * v4 - Wave Columns
     * Add offset_minutes, window_minutes to survey_waves
     * Add study_end_offset_minutes to survey_studies
     */
    private function migrate_v4() {
        global $wpdb;
        $db = $this->get_connection();

        $waves_table = $wpdb->prefix . 'survey_waves';
        if ( ! $this->column_exists( $db, $waves_table, 'offset_minutes' ) ) {
            $sql = "ALTER TABLE `{$waves_table}` ADD COLUMN `offset_minutes` INT(11) DEFAULT 0";
            $this->db_query( $db, $sql );
        }

        if ( ! $this->column_exists( $db, $waves_table, 'window_minutes' ) ) {
            $sql = "ALTER TABLE `{$waves_table}` ADD COLUMN `window_minutes` INT(11) DEFAULT NULL";
            $this->db_query( $db, $sql );
        }

        $studies_table = $wpdb->prefix . 'survey_studies';
        if ( ! $this->column_exists( $db, $studies_table, 'study_end_offset_minutes' ) ) {
            $sql = "ALTER TABLE `{$studies_table}` ADD COLUMN `study_end_offset_minutes` INT(11) DEFAULT 0";
            $this->db_query( $db, $sql );
        }
    }

    /**
     * v5 - Participant Schema Repair
     * Add status column, update consent_blocked_survey_id values, modify column type
     */
    private function migrate_v5() {
        global $wpdb;
        $db = $this->get_connection();
        $table = $wpdb->prefix . 'survey_participants';

        if ( ! $this->column_exists( $db, $table, 'status' ) ) {
            $sql = "ALTER TABLE `{$table}` ADD COLUMN `status` VARCHAR(20) DEFAULT 'active'";
            $this->db_query( $db, $sql );
        }

        $this->db_query( $db, "UPDATE `{$table}` SET `consent_blocked_survey_id` = NULL WHERE `consent_blocked_survey_id` = 0" );

        $col_info = $this->db_get_results( $db, "SHOW COLUMNS FROM `{$table}` LIKE 'consent_blocked_survey_id'" );
        if ( ! empty( $col_info ) && strpos( $col_info[0]->Type, 'bigint' ) === false ) {
            $sql = "ALTER TABLE `{$table}` MODIFY `consent_blocked_survey_id` BIGINT(20) UNSIGNED DEFAULT NULL";
            $this->db_query( $db, $sql );
        }
    }

    /**
     * v6 - Foreign Key Fix
     * Drop invalid foreign keys from survey_email_log and survey_magic_links
     */
    private function migrate_v6() {
        global $wpdb;
        $db = $this->get_connection();

        $fks = $this->db_get_results( $db, "
            SELECT CONSTRAINT_NAME, TABLE_NAME
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME IN ('{$wpdb->prefix}survey_email_log', '{$wpdb->prefix}survey_magic_links')
            AND REFERENCED_TABLE_NAME IS NOT NULL
        " );

        foreach ( $fks as $fk ) {
            $this->db_query( $db, "ALTER TABLE `{$fk->TABLE_NAME}` DROP FOREIGN KEY `{$fk->CONSTRAINT_NAME}`" );
        }
    }

    /**
     * v7 - Dynamic Column TEXT Conversion
     * Convert T[0-9]+_ columns from VARCHAR to TEXT
     */
    private function migrate_v7() {
        global $wpdb;
        $db = $this->get_connection();
        $table = $wpdb->prefix . 'survey_participants';

        error_log( '[EIPSI Migration v7] ADVERTENCIA: Iniciando conversión de columnas dinámicas a TEXT. Esto puede causar table lock en tablas grandes. Timestamp: ' . current_time( 'mysql' ) );

        $columns = $this->db_get_results( $db, "
            SELECT COLUMN_NAME, DATA_TYPE
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = '{$table}'
            AND COLUMN_NAME REGEXP '^T[0-9]+_'
            AND DATA_TYPE = 'varchar'
        " );

        foreach ( $columns as $col ) {
            try {
                $sql = "ALTER TABLE `{$table}` MODIFY `{$col->COLUMN_NAME}` TEXT";
                $this->db_query( $db, $sql );
            } catch ( Exception $e ) {
                error_log( "[EIPSI Migration v7] Failed to convert column {$col->COLUMN_NAME}: " . $e->getMessage() );
                throw $e;
            }
        }
    }

    /**
     * v8 - Pool Format v2
     * Migrate JSON structure in eipsi_longitudinal_pools to v2 format
     */
    private function migrate_v8() {
        global $wpdb;
        $db = $this->get_connection();
        $table = $wpdb->prefix . 'eipsi_longitudinal_pools';

        // Fresh/current schemas use config; only legacy schemas have pool_data.
        if ( ! $this->column_exists( $db, $table, 'pool_data' ) ) {
            return;
        }

        $pools = $this->db_get_results( $db, "SELECT id, pool_data FROM `{$table}`" );

        foreach ( $pools as $pool ) {
            $data = json_decode( $pool->pool_data, true );
            if ( is_array( $data ) && ( ! isset( $data['version'] ) || $data['version'] < 2 ) ) {
                $data['version'] = 2;
                if ( ! isset( $data['probability_mapping'] ) ) {
                    $data['probability_mapping'] = array();
                }
                $new_json = json_encode( $data );
                $wpdb->update( $table, array( 'pool_data' => $new_json ), array( 'id' => $pool->id ) );
                if ( ! empty( $wpdb->last_error ) ) {
                    throw new Exception( "Failed to update pool {$pool->id}: {$wpdb->last_error}" );
                }
            }
        }
    }

    /**
     * v9 - Cleanup Legacy Flags
     * Remove all legacy migration flags from wp_options
     */
    private function migrate_v9() {
        delete_option( 'eipsi_autofix_schema_version' );
        delete_option( 'eipsi_fk_fix_version' );
        delete_option( 'eipsi_randomization_schema_version' );
        delete_option( 'eipsi_db_schema_migration_version' );
        delete_option( 'eipsi_pools_migrated_v2' );
        delete_option( 'eipsi_db_schema_version' );
        delete_option( 'eipsi_migration_email_type_varchar_done' );
        delete_option( 'eipsi_migration_token_plain_removed' );
    }
}
