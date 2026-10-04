<?php
/**
 * Plugin Name: EIPSI Forms
 * Plugin URI: https://enmediodelcontexto.com.ar
 * Description: Professional form builder with Gutenberg blocks, conditional logic, and Excel export capabilities.
 * Version: 2.6.1
 * Author: Mathias N. Rojas de la Fuente
 * Author URI: https://www.instagram.com/enmediodel.contexto/
 * Text Domain: eipsi-forms
 * Domain Path: /languages
 * Requires at least: 5.8
 * Tested up to: 6.7
 * Requires PHP: 7.4
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Tags: forms, contact-form, survey, quiz, poll, form-builder, gutenberg, blocks, admin-dashboard, excel-export, analytics, RCT, randomization, longitudinal, studies
 * Stable tag: 2.6.1
 *
 * @package EIPSI_Forms
 */

 if (!defined('ABSPATH')) {
    exit;
 }

 define('EIPSI_FORMS_VERSION', '2.6.1');
define('EIPSI_FORMS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('EIPSI_FORMS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('EIPSI_FORMS_PLUGIN_FILE', __FILE__);
define('EIPSI_FORMS_SLUG', 'eipsi-forms');

// Session Cookie Name for Participant Authentication
define('EIPSI_SESSION_COOKIE_NAME', 'eipsi_session_token');

// Composition only; global APIs and registration order are preserved by the bootstrap.
require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/bootstrap/bootstrap.php';
