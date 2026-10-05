<?php
/** M4 longitudinal owner. Existing public adapters preserve their contracts. */
if (!defined('ABSPATH')) { exit; }

require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/longitudinal/waves/class-wave-definition-service.php';
require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/longitudinal/assignments/class-assignment-repository.php';
require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/longitudinal/assignments/class-assignment-service.php';
require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/longitudinal/assignments/class-assignment-transition-service.php';
require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/longitudinal/class-longitudinal-submission-service.php';
require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/longitudinal/t1/class-t1-anchor-service.php';
require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/longitudinal/t1/class-t1-recalculation-service.php';
require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/longitudinal/assignments/class-assignment-expiration-service.php';
require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/longitudinal/assignments/class-assignment-lifecycle-service.php';
require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/longitudinal/studies/class-study-repository.php';
require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/longitudinal/studies/class-study-service.php';
require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/longitudinal/studies/class-study-dashboard-service.php';
require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/longitudinal/studies/class-study-config-service.php';
require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/longitudinal/assignments/class-assignment-deadline-service.php';
