<?php
/**
 * EIPSI_Participant_Data_Request_Service
 *
 * Gestiona solicitudes de datos por parte de participantes (GDPR).
 * Permite a los participantes solicitar sus datos desde el portal.
 *
 * @package EIPSI_Forms
 * @subpackage Services
 * @version 2.1.0
 * @since Phase 3 - Task 3C.7
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/privacy/bootstrap.php';


class EIPSI_Participant_Data_Request_Service {

    /**
     * Request statuses.
     */
    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_REJECTED = 'rejected';

    /**
     * Submit a data request from a participant.
     *
     * @param int $participant_id
     * @param string $request_type Type of request: 'export', 'delete', 'anonymize'
     * @param string $reason Optional reason
     * @return array Result
     */
    public static function submit_request($participant_id, $request_type, $reason = '') { return EIPSI_Privacy_Data_Request_Service::submit_request($participant_id, $request_type, $reason); }

    /**
     * Get all data requests (for admin).
     *
     * @param array $filters Optional filters
     * @param int $limit
     * @param int $offset
     * @return array Requests with participant info
     */
    public static function get_requests($filters = array(), $limit = 20, $offset = 0) { return EIPSI_Privacy_Data_Request_Service::get_requests($filters, $limit, $offset); }

    /**
     * Process a pending request.
     *
     * @param int $request_id
     * @param string $action 'approve' or 'reject'
     * @param string $admin_notes Optional notes
     * @return array Result
     */
    public static function process_request($request_id, $action, $admin_notes = '') { return EIPSI_Privacy_Data_Request_Service::process_request($request_id, $action, $admin_notes); }


    public static function remove_secrets($data) { return EIPSI_Personal_Export_Service::remove_secrets($data); }

    /** Authorize every download; paths are read from DB, never from a client. */
    public static function get_download($request_id, $nonce) { return EIPSI_Download_Authorization_Service::get_download($request_id, $nonce); }


    /**
     * Get request counts by status.
     */
    public static function get_request_counts() { return EIPSI_Privacy_Data_Request_Service::get_request_counts(); }

    /**
     * Create data requests table.
     */
    public static function create_table() { return EIPSI_Privacy_Data_Request_Service::create_table(); }


}
