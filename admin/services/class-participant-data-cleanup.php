<?php
if (!defined('ABSPATH')) { exit; }

require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/privacy/bootstrap.php';

/** Focused local cleanup shared by the existing privacy operations. */
class EIPSI_Participant_Data_Cleanup {
    public static function columns($table) { return EIPSI_Privacy_Data_Cleanup_Service::columns($table); }

    public static function scrub($value, $email = '') { return EIPSI_Privacy_Data_Cleanup_Service::scrub($value, $email); }
    public static function run($participant_id, $mode = 'hard_delete', $reason = '') { return EIPSI_Privacy_Data_Cleanup_Service::run($participant_id, $mode, $reason); }
}
