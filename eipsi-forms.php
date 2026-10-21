<?php
/**
 * Plugin Name: EIPSI Forms
 * Plugin URI: https://github.com/EIPSI/EIPSI-Forms-Plugin
 * Description: Create assessments and manage cross-sectional and longitudinal studies with randomization, conditional logic, email notifications, and data export.
 * Version: 1.0.0
 * Author: EIPSI
 * Author URI: https://equipoeipsi.com/
 * Text Domain: eipsi-forms
 * Domain Path: /languages
 * Requires at least: 5.8
 * Tested up to: 6.7
 * Requires PHP: 7.4
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Tags: forms, contact-form, survey, quiz, poll, form-builder, gutenberg, blocks, admin-dashboard, excel-export, analytics, RCT, randomization, longitudinal, studies
 * Stable tag: 1.0.0
 *
 * @package EIPSI_Forms
 */

 if (!defined('ABSPATH')) {
    exit;
 }

 define('EIPSI_FORMS_VERSION', '1.0.0');
define('EIPSI_FORMS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('EIPSI_FORMS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('EIPSI_FORMS_PLUGIN_FILE', __FILE__);
define('EIPSI_FORMS_SLUG', 'eipsi-forms');
require_once EIPSI_FORMS_PLUGIN_DIR.'includes/i18n/admin.php';
require_once EIPSI_FORMS_PLUGIN_DIR.'includes/security/bootstrap.php';
require_once EIPSI_FORMS_PLUGIN_DIR.'includes/dataset/bootstrap.php';
require_once EIPSI_FORMS_PLUGIN_DIR.'includes/external-api/bootstrap.php';

// Session Cookie Name for Participant Authentication
define('EIPSI_SESSION_COOKIE_NAME', 'eipsi_session_token');

// Composition only; global APIs and registration order are preserved by the bootstrap.
require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/bootstrap/bootstrap.php';
if(get_option('eipsi_study_engine_longitudinal_enabled'))require_once EIPSI_FORMS_PLUGIN_DIR.'includes/study-engine/longitudinal/bootstrap.php';

// Dedicated Study Engine URL is opt-in and does not alter legacy Forms/Waves loading.
if (isset($_GET['eipsi_study'])) {
    if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE', true); }
    require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/study-engine/runtime/route.php';
}

// Authoring services load only for their admin screen or authenticated AJAX request.
if(is_admin()&&((isset($_GET["page"])&&$_GET["page"]==="eipsi-study-builder")||(isset($_GET["eipsi_builder_source"])&&$_GET["eipsi_builder_source"]==="1")||(wp_doing_ajax()&&($_REQUEST["action"]??"")==="eipsi_study_builder")))require_once EIPSI_FORMS_PLUGIN_DIR."includes/study-engine/builder/bootstrap.php";

// Isolated native authoring screen; existing consumers do not load these services.
if (is_admin() && ((isset($_GET['page']) && $_GET['page'] === 'eipsi-assessment-editor') || (wp_doing_ajax() && ($_REQUEST['action'] ?? '') === 'eipsi_assessment_editor'))) {
    require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/assessment-editor/bootstrap.php';
}

// Native execution is opt-in and separate from existing Engine/legacy routes.
if (isset($_GET['eipsi_native']) || isset($_GET['eipsi_native_asset']) || (wp_doing_ajax() && ($_REQUEST['action'] ?? '') === 'eipsi_native_participant')) {
    require_once EIPSI_FORMS_PLUGIN_DIR.'includes/assessment-editor/runtime/bootstrap.php';
}
if (get_option('eipsi_native_runtime_enabled')) {
    add_action('eipsi_cleanup_partial_responses', static function () {
        require_once EIPSI_FORMS_PLUGIN_DIR.'includes/assessment-editor/runtime/Transport.php';
        \EIPSI\AssessmentEditor\Runtime\Transport::cleanup();
    }, 11);
}

if (get_option('eipsi_native_runtime_enabled')) {
    add_action('deleted_user', static function ($user) {
        require_once EIPSI_FORMS_PLUGIN_DIR.'includes/assessment-editor/runtime/Store.php';
        require_once EIPSI_FORMS_PLUGIN_DIR.'includes/assessment-editor/runtime/Privacy.php';
        \EIPSI\AssessmentEditor\Runtime\Privacy::eraseUser((int)$user);
    });
}

// Plugin-wide diagnostics observe the existing WordPress cron owners.
require_once EIPSI_FORMS_PLUGIN_DIR.'includes/scheduler/bootstrap.php';
