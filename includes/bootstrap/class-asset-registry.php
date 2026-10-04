<?php
/** M1 bootstrap: preserve existing contracts and registration order. */
if (!defined('ABSPATH')) { exit; }

final class EIPSI_Asset_Registry {
    public static function register_admin_and_participant() {
        add_action('admin_enqueue_scripts', 'eipsi_enqueue_randomization_assets');
        add_action('admin_enqueue_scripts', 'eipsi_enqueue_admin_light_theme');
        add_action('admin_enqueue_scripts', 'eipsi_enqueue_setup_wizard_assets');
        add_action('wp_enqueue_scripts', 'eipsi_enqueue_participant_auth_assets');
        add_action('admin_enqueue_scripts', 'eipsi_enqueue_participant_auth_assets');
        add_action('wp_enqueue_scripts', 'eipsi_enqueue_survey_login_assets');
        add_action('wp_enqueue_scripts', 'eipsi_enqueue_participant_ux_assets');
    }

    public static function register_forms() {
        add_action('admin_enqueue_scripts', 'eipsi_forms_enqueue_admin_assets');
        add_action('enqueue_block_editor_assets', 'eipsi_forms_enqueue_block_editor_assets');
        add_action('wp_enqueue_scripts', 'eipsi_forms_enqueue_frontend_assets');
    }

    public static function register_frontend_fallback() {
        add_action('wp_enqueue_scripts', function() {
            eipsi_forms_enqueue_frontend_assets();
        });
    }
}
