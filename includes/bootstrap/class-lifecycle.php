<?php
/** M1 bootstrap: preserve existing contracts and registration order. */
if (!defined('ABSPATH')) { exit; }

final class EIPSI_Lifecycle {
    public static function register() {
        register_activation_hook(EIPSI_FORMS_PLUGIN_FILE, 'eipsi_forms_activate');
        register_deactivation_hook(EIPSI_FORMS_PLUGIN_FILE, 'eipsi_forms_deactivate');
    }

    public static function register_schema_verification() {
        add_action('plugins_loaded', function() {
            if ( class_exists( 'EIPSI_Database_Schema_Manager' ) ) {

                $status = EIPSI_Database_Schema_Manager::get_verification_status();
                if ( $status['needs_verification'] ) {
                    EIPSI_Database_Schema_Manager::repair_local_schema();
                }
            }
        }, 5);
        add_action('admin_init', array('EIPSI_Database_Schema_Manager', 'periodic_verification'));
    }

    public static function register_pool_migration() {
        add_action( 'plugins_loaded', function() {

            if ( is_admin() ) {
                eipsi_migrate_pools_to_v2();
            }
        }, 20 );
    }

    public static function activate() {
        global $wpdb;

        EIPSI_Cron_Registry::schedule_activation();

        // Initialize/Repair Database Schema
        if ( class_exists( 'EIPSI_Database_Schema_Manager' ) ) {
            EIPSI_Database_Schema_Manager::repair_local_schema();
        }

        // Log activation
        error_log('[EIPSI Forms] Plugin activated - Schema synchronized');
    }

    public static function deactivate() {
        EIPSI_Cron_Registry::unschedule_owned_events();
    }
}
