<?php
/** M6 owner; existing public adapters retain their contracts. */
if (!defined('ABSPATH')) { exit; }

require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/storage/class-external-submission-store.php';
require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/storage/class-device-data-store.php';
require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/storage/class-submission-storage-service.php';
require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/storage/class-emergency-submission-store.php';
require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/storage/class-submission-verification-service.php';
require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/storage/class-local-submission-store.php';
require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/storage/class-partial-response-store.php';

require_once EIPSI_FORMS_PLUGIN_DIR.'includes/privacy/bootstrap.php';

require_once EIPSI_FORMS_PLUGIN_DIR.'includes/storage/class-storage-result.php';
require_once EIPSI_FORMS_PLUGIN_DIR.'includes/storage/class-event-store.php';
