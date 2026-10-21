<?php
/** M1 bootstrap: preserve existing contracts and registration order. */
if (!defined('ABSPATH')) { exit; }

// This entry file is included in the same scope as the plugin: do not wrap requires in a method.
foreach (array('class-bootstrap.php', 'class-service-loader.php', 'class-hook-registry.php',
    'class-asset-registry.php', 'class-block-registry.php', 'class-cron-registry.php',
    'class-lifecycle.php', 'asset-callbacks.php', 'block-callbacks.php', 'lifecycle-callbacks.php') as $eipsi_bootstrap_file) {
    require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/bootstrap/' . $eipsi_bootstrap_file;
}
// Original global functions existed before service includes; preserve that availability.
require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/compatibility/legacy-main-callbacks.php';
foreach (EIPSI_Service_Loader::manifest() as $eipsi_load_step) {
    if ($eipsi_load_step['context'] === 'debug' && (!defined('WP_DEBUG') || !WP_DEBUG)) { continue; }
    if ($eipsi_load_step['file'] === ':migration') {
        EIPSI_Migration_Runner::init();
    } elseif ($eipsi_load_step['file'] === ':survey-access') {
        $eipsi_survey_access = new EIPSI_Survey_Access_Handler();
        $eipsi_survey_access->init();
    } else {
        require_once EIPSI_FORMS_PLUGIN_DIR . $eipsi_load_step['file'];
    }
}
unset($eipsi_bootstrap_file, $eipsi_load_step);
EIPSI_Bootstrap::register();
