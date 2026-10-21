<?php
/** M7 owner. Public compatibility adapters retain registration and signatures. */
if (!defined('ABSPATH')) { exit; }

require_once EIPSI_FORMS_PLUGIN_DIR.'includes/pools/class-pool-repository.php';
require_once EIPSI_FORMS_PLUGIN_DIR.'includes/pools/class-pool-completion-service.php';
require_once EIPSI_FORMS_PLUGIN_DIR.'includes/pools/class-pool-analytics-service.php';
require_once EIPSI_FORMS_PLUGIN_DIR.'includes/pools/adapters/class-longitudinal-read-adapter.php';
require_once EIPSI_FORMS_PLUGIN_DIR.'includes/pools/class-pool-assignment-service.php';
require_once EIPSI_FORMS_PLUGIN_DIR.'includes/pools/class-pool-dashboard-query-service.php';
require_once EIPSI_FORMS_PLUGIN_DIR.'includes/pools/adapters/class-rest-adapter.php';
require_once EIPSI_FORMS_PLUGIN_DIR.'includes/pools/adapters/class-ajax-adapter.php';
require_once EIPSI_FORMS_PLUGIN_DIR.'includes/pools/adapters/class-dashboard-adapter.php';
require_once EIPSI_FORMS_PLUGIN_DIR.'includes/pools/class-pool-service.php';
require_once EIPSI_FORMS_PLUGIN_DIR.'includes/pools/adapters/class-completion-adapter.php';
require_once EIPSI_FORMS_PLUGIN_DIR.'includes/pools/class-pool-algorithm-service.php';
require_once EIPSI_FORMS_PLUGIN_DIR.'includes/pools/adapters/class-block-adapter.php';
require_once EIPSI_FORMS_PLUGIN_DIR.'includes/pools/adapters/class-shortcode-adapter.php';
