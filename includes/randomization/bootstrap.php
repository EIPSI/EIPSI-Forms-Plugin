<?php
/** M7 owner. Public compatibility adapters retain registration and signatures. */
if (!defined('ABSPATH')) { exit; }

require_once EIPSI_FORMS_PLUGIN_DIR.'includes/randomization/class-randomization-repository.php';
require_once EIPSI_FORMS_PLUGIN_DIR.'includes/randomization/adapters/class-db-rest-adapter.php';
require_once EIPSI_FORMS_PLUGIN_DIR.'includes/randomization/class-submission-algorithm-service.php';
require_once EIPSI_FORMS_PLUGIN_DIR.'includes/randomization/class-randomization-config-service.php';
require_once EIPSI_FORMS_PLUGIN_DIR.'includes/randomization/adapters/class-config-adapter.php';
require_once EIPSI_FORMS_PLUGIN_DIR.'includes/randomization/class-randomization-assignment-service.php';
require_once EIPSI_FORMS_PLUGIN_DIR.'includes/randomization/class-randomization-override-service.php';
require_once EIPSI_FORMS_PLUGIN_DIR.'includes/randomization/class-randomization-algorithm-service.php';
require_once EIPSI_FORMS_PLUGIN_DIR.'includes/randomization/adapters/class-shortcode-adapter.php';
require_once EIPSI_FORMS_PLUGIN_DIR.'includes/randomization/adapters/class-legacy-config-adapter.php';
require_once EIPSI_FORMS_PLUGIN_DIR.'includes/randomization/adapters/class-tracking-key-adapter.php';
require_once EIPSI_FORMS_PLUGIN_DIR.'includes/randomization/adapters/class-frontend-adapter.php';
require_once EIPSI_FORMS_PLUGIN_DIR.'includes/randomization/adapters/class-admin-adapter.php';
require_once EIPSI_FORMS_PLUGIN_DIR.'includes/randomization/adapters/class-form-load-adapter.php';
