<?php
if (!defined('ABSPATH')) {
    exit;
}

require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/export/bootstrap.php';


/**
 * EIPSI_Export_Service
 *
 * Handles data export for both longitudinal (wave-based) studies and
 * participant rosters. Supports CSV (streamed) and Excel (.xlsx) output.
 *
 * @package EIPSI_Forms
 * @since   1.4.0
 * @updated 1.8.0 — Added participant export (CSV + Excel) with filters,
 *                   real preview endpoint, summary stats.
 */
class EIPSI_Export_Service {
    public static function strip_credentials($data) { return EIPSI_Export_File_Service::strip_credentials($data); }

    public static function write_csv_row($stream, $row) { return EIPSI_Export_File_Service::write_csv_row($stream, $row); }


    // =========================================================================
    // LONGITUDINAL EXPORT (wave responses)
    // =========================================================================

    /**
     * Main export method — fetches longitudinal (wave) data.
     *
     * @param int   $survey_id Study / survey ID.
     * @param array $filters   Optional filters (wave_index, date_from, date_to, status).
     * @return array Array of stdClass rows.
     */
    public function export_longitudinal_data($survey_id, $filters = array()) { return $this->query_owner()->export_longitudinal_data($survey_id, $filters); }


    /** Export longitudinal data to .xlsx, returns filename. */
    public function export_to_excel($data, $survey_id) { return $this->file_owner()->export_to_excel($data, $survey_id); }

    /** Export longitudinal data to .csv, returns filename. */
    public function export_to_csv($data, $survey_id) { return $this->file_owner()->export_to_csv($data, $survey_id); }


// =========================================================================
// PARTICIPANT EXPORT (roster + wave progress)
// =========================================================================

/**
 * Fetch participant rows for export, with optional filters.
 *
 * Returns one row per participant with their wave-completion summary columns.
 *
 * Supported filters:
 *   - status      : 'all' | 'active' | 'inactive'
 *   - wave_index  : 'all' | e.g. '1', '2'  (filters by whether that wave is completed)
 *   - search      : email / name free-text
 *   - date_from   : YYYY-MM-DD (participant created_at)
 *   - date_to     : YYYY-MM-DD
 *
 * @param int   $study_id
 * @param array $filters
 * @return array
 */
public function fetch_participants_data($study_id, $filters = array()) { return $this->query_owner()->fetch_participants_data($study_id, $filters); }

    /**
     * Stream participant CSV directly to an open file handle (e.g. php://output).
     *
     * @param int      $study_id
     * @param array    $filters
     * @param resource $output   Open file handle.
     */
    public function stream_participants_csv($study_id, $filters, $output) { return $this->file_owner()->stream_participants_csv($study_id, $filters, $output); }

    public function get_participants_preview($study_id, $filters = array(), $limit = 10) { return $this->file_owner()->get_participants_preview($study_id, $filters, $limit); }

    // =========================================================================
    // STATISTICS
    // =========================================================================

    /**
     * Get export statistics for a survey.
     *
     * @param int   $survey_id
     * @param array $filters   (unused – kept for BC)
     * @return array
     */
    public function get_export_statistics($survey_id, $filters = array()) { return $this->query_owner()->get_export_statistics($survey_id, $filters); }

    // =========================================================================
    // HELPER — available surveys / waves
    // =========================================================================

    /** Get list of active surveys for dropdown. */
    public function get_available_surveys() { return $this->query_owner()->get_available_surveys(); }

    /** Get wave indices for a survey. */
    public function get_survey_waves($survey_id) { return $this->query_owner()->get_survey_waves($survey_id); }

    // =========================================================================
    // WIDE FORMAT EXPORT (one row per participant)
    // =========================================================================


    /**
     * Export participant roster to Excel in WIDE format, returns filename.
     *
     * Estructura Wide (v2.1.3): una fila por participante con columnas por cada wave.
     * Incluye 19 columnas de metadatos por wave (7 básicas + 12 extendidas, sin fingerprint_id).
     *
     * @param int   $study_id
     * @param array $filters
     * @return string Filename (in exports/ directory)
     */
    public function export_participants_wide_excel($study_id, $filters = array()) { return $this->file_owner()->export_participants_wide_excel($study_id, $filters); }

    /**
     * Export participant roster to CSV in WIDE format.
     *
     * @param int      $study_id
     * @param array    $filters
     * @param resource $output   Open file handle (e.g., php://output).
     */
    public function stream_participants_wide_csv($study_id, $filters, $output) { return $this->file_owner()->stream_participants_wide_csv($study_id, $filters, $output); }

    /**
     * Return a lightweight preview (first N rows) of the participant WIDE export.
     *
     * Used by the AJAX preview endpoint in the UI.
     *
     * @param int   $study_id
     * @param array $filters
     * @param int   $limit    Max rows to return (default 10).
     * @return array { headers: string[], rows: array[], total: int }
     */
    public function get_participants_wide_preview($study_id, $filters = array(), $limit = 8) { return $this->file_owner()->get_participants_wide_preview($study_id, $filters, $limit); }


private $query_service; private $file_service;
private function query_owner(){if(!$this->query_service){$this->query_service=new EIPSI_Export_Query_Service();}return $this->query_service;}
private function file_owner(){if(!$this->file_service){$this->file_service=new EIPSI_Export_File_Service();}return $this->file_service;}

}
