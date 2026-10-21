<?php
/** M3 owner; external contracts remain behind their existing facades. */
if (!defined('ABSPATH')) { exit; }

require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/forms/class-form-response.php';
require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/forms/class-form-context.php';
require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/forms/class-form-capture-service.php';
require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/forms/class-form-storage-adapter.php';
require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/forms/class-submit-service.php';
require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/forms/class-form-tracking-service.php';
require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/forms/class-form-consent-service.php';
require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-form-longitudinal-submit-adapter.php';
