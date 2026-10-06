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

require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/schema/bootstrap.php';

class EIPSI_Database_Schema_Manager {



    /**

     * Centralized Schema Map

     * Contains all table definitions (columns and indices)

     *

     * @since 1.4.0

     * @return array

     */

    public static function get_schema_map() { return EIPSI_Schema_Registry::get_schema_map(); }



    /**

     * Generate dbDelta-compliant CREATE TABLE SQL

     *

     * @param string $slug Table slug

     * @return string|false SQL or false if not found

     */

    public static function get_table_sql( $slug ) { return EIPSI_Schema_Registry::get_table_sql($slug); }



    /**

     * Synchronize a local table using dbDelta

     *

     * @param string $slug Table slug

     * @return array Result

     */

    public static function sync_local_table( $slug ) { return EIPSI_Schema_Repair_Service::sync_local_table($slug); }



    /**

     * Repair all LOCAL WordPress database schema

     *

     * @return array Repair log

     */

    public static function repair_local_schema() { return EIPSI_Schema_Installer::repair_local_schema(); }



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

    private static function sync_external_schema( $mysqli ) { return EIPSI_External_Schema_Adapter::sync_external_schema($mysqli); }



    /**

     * Periodic verification hook

     */

    public static function periodic_verification() { return EIPSI_Schema_Inspector::periodic_inspection(); }


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

    public static function get_verification_status() { return EIPSI_Schema_Inspector::get_verification_status(); }



    /**

     * Get status for all tables

     */

    public static function get_all_tables_status() { return EIPSI_Schema_Inspector::get_all_tables_status(); }



    /**

     * Get detailed status for a single table

     */

    public static function get_detailed_table_status( $slug ) { return EIPSI_Schema_Inspector::get_detailed_table_status($slug); }



    /**

     * Get schema health summary

     */

    public static function get_schema_health_summary() { return EIPSI_Schema_Inspector::get_schema_health_summary(); }



    /**

     * Fix collations for all plugin tables

     */

    public static function fix_collations() { return EIPSI_Schema_Repair_Service::fix_collations(); }


public static function check_collation_issues() { return EIPSI_Schema_Inspector::check_collation_issues(); }

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

