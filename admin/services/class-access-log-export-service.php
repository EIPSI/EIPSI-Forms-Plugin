<?php
/**
 * EIPSI_Access_Log_Export_Service
 *
 * Servicio de exportación de logs de acceso para compliance IRB y GDPR.
 * Permite exportar logs de participantes con filtros avanzados.
 *
 * @package EIPSI_Forms
 * @subpackage Services
 * @version 2.1.0
 * @since Phase 3 - Task 3A.1
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/export/bootstrap.php';


class EIPSI_Access_Log_Export_Service {

    /**
     * Export access logs to CSV/Excel with filters.
     *
     * @param array $filters Filtros: date_from, date_to, study_id, action_type, participant_id
     * @param string $format 'csv' o 'excel'
     * @return array {success, file_path, filename, count, message}
     */
    public static function export_access_logs($filters = array(), $format = 'csv') { return EIPSI_Export_Access_Log_Service::export_access_logs($filters, $format); }


    /**
     * Get available action types for filter dropdown.
     */
    public static function get_action_types() { return EIPSI_Export_Access_Log_Service::get_action_types(); }

    /**
     * Stream access logs CSV directly to output (for download).
     */
    public static function stream_access_logs_csv($filters = array()) { return EIPSI_Export_Access_Log_Service::stream_access_logs_csv($filters); }
}
