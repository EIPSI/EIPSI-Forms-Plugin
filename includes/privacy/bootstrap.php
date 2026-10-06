<?php
/** M6 owner; existing public adapters retain their contracts. */
if (!defined('ABSPATH')) { exit; }

require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/privacy/class-data-cleanup-service.php';
require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/privacy/class-anonymization-service.php';
require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/privacy/class-capture-policy.php';
require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/privacy/class-data-request-service.php';

require_once EIPSI_FORMS_PLUGIN_DIR.'includes/export/bootstrap.php';

require_once EIPSI_FORMS_PLUGIN_DIR.'includes/privacy/class-coverage-report.php';
