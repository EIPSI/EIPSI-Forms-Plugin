<?php
if (!defined('ABSPATH')) exit;
/** EIPSI Forms menu destination now opens the unified Study Engine. */
function eipsi_display_results_experience_page() {
    require_once EIPSI_FORMS_PLUGIN_DIR.'includes/study-engine/builder.php';
    \EIPSI\StudyEngine\Builder\Admin::screen();
}
function eipsi_display_form_responses() { eipsi_display_results_experience_page(); }
