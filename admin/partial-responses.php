<?php
if (!defined('ABSPATH')) {
    exit;
}

require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/forms/class-partial-response-service.php';

/**
 * EIPSI Forms - Partial Responses Manager
 * Handles save & continue functionality
 * 
 * @since 1.3.0
 */
class EIPSI_Partial_Responses {
    
    /**
     * Create partial responses table
     */
    public static function create_table() {
        require_once EIPSI_FORMS_PLUGIN_DIR.'includes/schema/bootstrap.php';
        return EIPSI_Schema_Repair_Service::sync_local_table('eipsi_partial_responses')['success'];
    }
    
    /**
     * Save partial response
     * 
     * @param string $form_id Form identifier
     * @param string $participant_id Participant identifier
     * @param string $session_id Session identifier
     * @param int $page_index Current page index
     * @param array $responses Form responses
     * @return array Result with success status
     */
    public static function save($form_id, $participant_id, $session_id, $page_index, $responses) {
    return EIPSI_Partial_Response_Service::save($form_id, $participant_id, $session_id, $page_index, $responses);
}
    
    /**
     * Load partial response
     * 
     * @param string $form_id Form identifier
     * @param string $participant_id Participant identifier
     * @param string $session_id Session identifier
     * @return array|null Partial response data or null
     */
    public static function load($form_id, $participant_id, $session_id) {
    return EIPSI_Partial_Response_Service::load($form_id, $participant_id, $session_id);
}
    
    /**
     * Mark session as completed
     * 
     * @param string $form_id Form identifier
     * @param string $participant_id Participant identifier
     * @param string $session_id Session identifier
     * @return bool Success status
     */
    public static function mark_completed($form_id, $participant_id, $session_id) {
    return EIPSI_Partial_Response_Service::mark_completed($form_id, $participant_id, $session_id);
}
    
    /**
     * Discard partial response
     * 
     * @param string $form_id Form identifier
     * @param string $participant_id Participant identifier
     * @param string $session_id Session identifier
     * @return bool Success status
     */
    public static function discard($form_id, $participant_id, $session_id) {
    return EIPSI_Partial_Response_Service::discard($form_id, $participant_id, $session_id);
}
    
    /**
     * Clean up old partial responses (> 30 days)
     * 
     * @return int Number of deleted records
     */
    public static function cleanup_old_responses() {
    return EIPSI_Partial_Response_Service::cleanup_old_responses();
}
}
