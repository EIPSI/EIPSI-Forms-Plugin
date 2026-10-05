<?php
/**
 * EIPSI Wave Recalculator Service
 *
 * Recalculates wave availability windows when T1 is completed.
 * Uses offset_minutes from wave configuration anchored to T1 completion.
 *
 * @package EIPSI_Forms
 * @since 2.5.0
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/longitudinal/bootstrap.php';

/**
 * Class EIPSI_Wave_Recalculator
 *
 * Handles recalculation of wave availability based on T1 completion timestamp.
 * All audit log entries use the existing survey_audit_log schema.
 */
class EIPSI_Wave_Recalculator {

    /**
     * Recalculate all wave availability for a participant after T1 completion
     *
     * @param int $participant_id Participant ID
     * @param int $study_id Study ID
     * @param string $t1_completed_at ISO datetime of T1 completion
     * @param string $triggered_by 'admin' or 'system'
     * @param int|null $user_id User ID if triggered by admin
     * @return array Result with status and affected waves
     */
    public static function recalculate_after_t1($participant_id, $study_id, $t1_completed_at, $triggered_by = 'system', $user_id = null) {
        return EIPSI_Longitudinal_T1_Recalculation_Service::recalculate_after_t1($participant_id, $study_id, $t1_completed_at, $triggered_by, $user_id);
    }

    /**
     * Recalculate for a single wave (useful for manual adjustments)
     *
     * @param int $participant_id Participant ID
     * @param int $wave_id Wave ID
     * @param string $t1_completed_at T1 completion timestamp
     * @param string $triggered_by 'admin' or 'system'
     * @param int|null $user_id User ID
     * @return bool Success
     */
    public static function recalculate_single_wave($participant_id, $wave_id, $t1_completed_at, $triggered_by = 'system', $user_id = null) {
        return EIPSI_Longitudinal_T1_Recalculation_Service::recalculate_single_wave($participant_id, $wave_id, $t1_completed_at, $triggered_by, $user_id);
    }
}
