<?php
/** M1 bootstrap: preserve existing contracts and registration order. */
if (!defined('ABSPATH')) { exit; }

final class EIPSI_Hook_Registry {
    public static function register() {
        add_filter('wp_mail_from', 'eipsi_mail_from', 99);
        add_filter('wp_mail_from_name', 'eipsi_mail_from_name', 99);
        add_filter('wp_mail_content_type', 'eipsi_set_html_content_type', 99);
        add_action('wp_mail_failed', 'eipsi_log_mail_error');
        EIPSI_Asset_Registry::register_admin_and_participant();
        add_action('init', function() {
            if (function_exists('eipsi_randomized_form_shortcode')) {
                add_shortcode('eipsi_randomized_form', 'eipsi_randomized_form_shortcode');
            }
            if (function_exists('eipsi_randomized_form_page_shortcode')) {
                add_shortcode('eipsi_randomized_form_page', 'eipsi_randomized_form_page_shortcode');
            }
        });
        add_action('wp_loaded', 'eipsi_wake_up_job_processor', 20);
        EIPSI_Lifecycle::register();
        EIPSI_Cron_Registry::register_schedules();
        EIPSI_Cron_Registry::register_access_log_cleanup();
        add_action('wp_loaded', 'eipsi_handle_unsubscribe_request');
        EIPSI_Lifecycle::register_schema_verification();
        EIPSI_Asset_Registry::register_forms();
        EIPSI_Block_Registry::register();
        EIPSI_Asset_Registry::register_frontend_fallback();
        add_action('admin_post_eipsi_forms_export_excel', 'eipsi_export_to_excel');
        add_action('admin_post_eipsi_save_pool', 'eipsi_handle_save_pool');
        add_action('plugins_loaded', 'eipsi_forms_load_textdomain');
        add_action('admin_notices', 'eipsi_smtp_configuration_notice');
        EIPSI_Lifecycle::register_pool_migration();
        add_action('init', 'eipsi_register_pool_rewrite_rules', 10);
        add_action('template_redirect', 'eipsi_handle_pool_access', 1);
        add_action('template_redirect', 'eipsi_handle_pool_email_confirmation', 2);
        add_action('admin_post_nopriv_eipsi_pool_join', 'eipsi_handle_pool_join');
        add_action('admin_post_eipsi_pool_join', 'eipsi_handle_pool_join');
        EIPSI_Cron_Registry::register_partial_cleanup();
    }
}
