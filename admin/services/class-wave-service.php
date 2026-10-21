<?php
/**
 * EIPSI_Wave_Service
 *
 * Gestiona waves (oleadas) en estudios longitudinales:
 * - CRUD operations
 * - Due date calculation
 * - Completion tracking
 * - Stats
 *
 * @package EIPSI_Forms
 * @subpackage Services
 * @version 1.4.2
 * @since 1.4.0
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/longitudinal/bootstrap.php';

class EIPSI_Wave_Service {

    /**
     * Create wave for survey.
     *
     * @param int   $study_id ID del estudio.
     * @param array $wave_data Datos de la wave.
     * @return int|WP_Error Wave ID insertado o WP_Error.
     * @since 1.4.0
     * @access public
     */
    public static function create_wave($study_id, $wave_data) {
        return EIPSI_Longitudinal_Wave_Definition_Service::create_wave($study_id, $wave_data);
    }

    /**
     * Get wave by ID.
     *
     * @param int $wave_id Wave ID.
     * @return object|null Wave data.
     * @since 1.4.0
     * @access public
     */
    public static function get_wave($wave_id) {
        return EIPSI_Longitudinal_Wave_Definition_Service::get_wave($wave_id);
    }

    /**
     * Get study waves.
     *
     * @param int         $study_id Study ID.
     * @param string|null $status Status filter.
     * @return array Waves list.
     * @since 1.4.0
     * @access public
     */
    public static function get_study_waves($study_id, $status = null) {
        return EIPSI_Longitudinal_Wave_Definition_Service::get_study_waves($study_id, $status);
    }

    /**
     * Update wave.
     *
     * @param int   $wave_id Wave ID.
     * @param array $wave_data Datos a actualizar.
     * @return bool|WP_Error True si actualiza o WP_Error si falla.
     * @since 1.4.0
     * @access public
     */
    public static function update_wave($wave_id, $wave_data) {
        return EIPSI_Longitudinal_Wave_Definition_Service::update_wave($wave_id, $wave_data);
    }

    /**
     * Get wave completion stats.
     *
     * @param int $wave_id Wave ID.
     * @return array Stats array.
     * @since 1.4.2
     * @access public
     */
    public static function get_wave_stats($wave_id) {
        return EIPSI_Longitudinal_Wave_Definition_Service::get_wave_stats($wave_id);
    }

    /**
     * Delete wave with validation.
     *
     * No permite borrar una wave con asignaciones ya submitted.
     *
     * @param int $wave_id Wave ID.
     * @return bool|WP_Error True si elimina o WP_Error si falla.
     * @since 1.4.0
     * @access public
     */
    public static function delete_wave($wave_id) {
        return EIPSI_Longitudinal_Wave_Definition_Service::delete_wave($wave_id);
    }

    /**
     * Calculate wave status based on dates.
     *
     * @param int|array $wave Wave ID or wave array.
     * @return string Status: upcoming, active, closed, overdue.
     * @since 1.7.1
     * @access public
     */
    public static function calculate_wave_status($wave) {
        return EIPSI_Longitudinal_Wave_Definition_Service::calculate_wave_status($wave);
    }

    /**
     * Validate wave dates before saving.
     *
     * @param array $wave_data Wave data to validate.
     * @param int   $study_id Study ID.
     * @param int   $exclude_wave_id Wave ID to exclude (for updates).
     * @return array {valid: bool, warnings: array, errors: array}
     * @since 1.7.1
     * @access public
     */
    public static function validate_wave_dates($wave_data, $study_id, $exclude_wave_id = 0) {
        return EIPSI_Longitudinal_Wave_Definition_Service::validate_wave_dates($wave_data, $study_id, $exclude_wave_id);
    }

    /**
     * Update wave status based on current dates.
     *
     * @param int $wave_id Wave ID.
     * @return bool True if updated.
     * @since 1.7.1
     * @access public
     */
    public static function update_wave_status($wave_id) {
        return EIPSI_Longitudinal_Wave_Definition_Service::update_wave_status($wave_id);
    }

    /**
     * Update all wave statuses for a study via cron.
     *
     * @param int $study_id Study ID. If 0, update all.
     * @return array Results.
     * @since 1.7.1
     * @access public
     */
    public static function update_all_wave_statuses($study_id = 0) {
        return EIPSI_Longitudinal_Wave_Definition_Service::update_all_wave_statuses($study_id);
    }

    /**
     * Get next pending wave (assignment) for a participant.
     *
     * Returns the wave row (OBJECT) so callers can read ->id and other fields.
     *
     * @param int $participant_id
     * @param int $study_id
     * @return object|null
     */
    public static function get_next_pending_wave($participant_id, $study_id) {
        return EIPSI_Longitudinal_Assignment_Repository::get_next_pending_wave_object($participant_id, $study_id);
    }

    /**
     * Calculate wave availability based on T1 completion (Phase 5 T1-Anchor)
     * 
     * For T1 (wave_index = 1): available from participant registration
     * For T2+: available from T1 completion + offset_minutes
     * 
     * @param int $participant_id Participant ID
     * @param int $study_id Study ID
     * @param object $wave Wave object with wave_index and offset_minutes
     * @return string|null DateTime string or null if not available yet
     * @since 2.6.0
     */
    public static function calculate_wave_availability($participant_id, $study_id, $wave) {
        return EIPSI_Longitudinal_T1_Anchor_Service::calculate_wave_availability($participant_id, $study_id, $wave);
    }

    /**
     * Recalculate wave availability after T1 completion (Phase 5 T1-Anchor)
     * 
     * Called when a participant completes T1. Updates available_at for all
     * subsequent waves (T2, T3, T4...) and reschedules their nudges.
     * 
     * @param int $participant_id Participant ID
     * @param int $study_id Study ID
     * @return int Number of waves recalculated
     * @since 2.6.0
     */
    public static function recalculate_after_t1($participant_id, $study_id) {
        return EIPSI_Longitudinal_T1_Recalculation_Service::recalculate_legacy_availability($participant_id, $study_id);
    }
}
