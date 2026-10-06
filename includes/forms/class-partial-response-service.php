<?php
/** M3 owner; external contracts remain behind their existing facades. */
if (!defined('ABSPATH')) { exit; }

require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/storage/bootstrap.php';


class EIPSI_Partial_Response_Service {
    public static function save($form_id, $participant_id, $session_id, $page_index, $responses) { return EIPSI_Storage_Partial_Response_Store::save($form_id, $participant_id, $session_id, $page_index, $responses); }
    public static function load($form_id, $participant_id, $session_id) { return EIPSI_Storage_Partial_Response_Store::load($form_id, $participant_id, $session_id); }
    public static function mark_completed($form_id, $participant_id, $session_id) { return EIPSI_Storage_Partial_Response_Store::mark_completed($form_id, $participant_id, $session_id); }
    public static function discard($form_id, $participant_id, $session_id) { return EIPSI_Storage_Partial_Response_Store::discard($form_id, $participant_id, $session_id); }
    public static function cleanup_old_responses() { return EIPSI_Storage_Partial_Response_Store::cleanup_old_responses(); }
}
