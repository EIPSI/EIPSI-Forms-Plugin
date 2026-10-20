<?php
if (!defined('ABSPATH')) {
    exit;
}

function eipsi_forms_menu() {
    $capabilities = function_exists('eipsi_get_menu_capabilities')
        ? eipsi_get_menu_capabilities()
        : apply_filters('eipsi_forms_menu_capabilities', array(
            'main' => 'edit_posts',
            'results' => 'manage_options',
            'configuration' => 'manage_options',
            'longitudinal' => 'edit_posts',
            'form_library' => 'edit_posts'
        ));

    // Menu Principal: EIPSI Forms
    add_menu_page(
        __('EIPSI Forms', 'eipsi-forms'),
        __('EIPSI Forms', 'eipsi-forms'),
        $capabilities['main'],
        'eipsi-results-experience',
        'eipsi_display_results_experience_page',
        plugin_dir_url(__FILE__) . '../assets/eipsi-icon-menu.svg',
        25
    );

    // Register the parent destination explicitly below, after the authoring tools.
    global $submenu;
    if (!isset($submenu['eipsi-results-experience'])) {
        $submenu['eipsi-results-experience'] = array();
    }

    // Submenú: Configuration
    add_submenu_page(
        'eipsi-results-experience',
        eipsi_admin_translate('Configuration'),
        eipsi_admin_translate('Configuration'),
        $capabilities['configuration'],
        'eipsi-configuration',
        'eipsi_display_configuration_page'
    );

    add_submenu_page('eipsi-results-experience', eipsi_admin_translate('Email Templates'), eipsi_admin_translate('Email Templates'), 'manage_options', 'eipsi-email-templates', static function() { require_once EIPSI_FORMS_PLUGIN_DIR.'includes/study-engine/builder.php'; \EIPSI\StudyEngine\Builder\EmailLibrary::screen(); });

    add_submenu_page('eipsi-results-experience', eipsi_admin_translate('Assessment Editor'), eipsi_admin_translate('Assessment Editor'), 'manage_options', 'eipsi-assessment-editor', static function () {
        require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/assessment-editor/bootstrap.php';
        \EIPSI\AssessmentEditor\Admin::screen();
    });

    add_submenu_page('eipsi-results-experience', eipsi_admin_translate('Study Engine'), eipsi_admin_translate('Study Engine'), 'manage_options', 'eipsi-study-builder', static function() { require_once EIPSI_FORMS_PLUGIN_DIR.'includes/study-engine/builder.php'; \EIPSI\StudyEngine\Builder\Admin::screen(); });



}

/**
 * Route the menu destination and reject retired administrative pages.
 * Study authoring and management share the current Study Engine screen.
 */
function eipsi_admin_menu_redirects() {
    if (!is_admin()) return;
    $page=isset($_GET['page'])?sanitize_key($_GET['page']):'';
    if($page==='eipsi-results-experience'){wp_safe_redirect(admin_url('admin.php?page=eipsi-study-builder'));exit;}
    if(in_array($page,array('eipsi-longitudinal-study','eipsi-randomization','eipsi-longitudinal-pools','eipsi-pool-dashboard','eipsi-notifications'),true))wp_die('Esta sección fue retirada.',410);
}
add_action('admin_menu', 'eipsi_forms_menu', 1);
add_action('admin_init', 'eipsi_admin_menu_redirects', 1);
