<?php
/**
 * EIPSI Wave Expiration Service
 *
 * Phase 2 of the T1-Anchor Roadmap: Automatización y Caducidad
 * 
 * Handles automatic expiration of waves when due_at is reached.
 * Runs hourly via WordPress cron to transition assignments to 'expired' status.
 *
 * Key features:
 * - Automatic status transition (pending/available → expired)
 * - Cancels pending nudges for expired waves
 * - Audit logging for all expirations
 * - Hook system for extensibility
 *
 * @package EIPSI_Forms
 * @subpackage Services
 * @version 2.6.0
 * @since Phase 2 - T1-Anchor
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/longitudinal/bootstrap.php';

class EIPSI_Wave_Expiration_Service {

    /**
     * Process all expired waves.
     * 
     * This is the main cron job handler that runs hourly.
     * Finds all assignments where NOW() >= due_at and transitions them to expired.
     *
     * @return array Results with counts.
     * @since 2.6.0
     */
    public static function process_expirations() {
        return EIPSI_Longitudinal_Assignment_Expiration_Service::process_expirations();
    }

    /**
     * Check if a specific assignment is expired (on-demand check).
     *
     * Used by the dashboard to show real-time status without waiting for cron.
     * This is the "Filtro de Visualización Instantánea" from the roadmap.
     *
     * @param object|array $assignment Assignment data.
     * @return bool True if expired.
     * @since 2.6.0
     */
    public static function is_assignment_expired($assignment) {
        return EIPSI_Longitudinal_Assignment_Expiration_Service::is_assignment_expired($assignment);
    }

    /**
     * Get visual status for an assignment (includes real-time expiration check).
     *
     * This ensures the dashboard always shows correct status even if cron hasn't run yet.
     *
     * @param object|array $assignment Assignment data.
     * @return string Visual status: 'submitted', 'expired', 'available', 'pending', 'in_progress'.
     * @since 2.6.0
     */
    public static function get_visual_status($assignment) {
        return EIPSI_Longitudinal_Assignment_Expiration_Service::get_visual_status($assignment);
    }

    /**
     * Manually expire a specific assignment (admin action).
     *
     * @param int $assignment_id Assignment ID.
     * @return bool|WP_Error True on success, WP_Error on failure.
     * @since 2.6.0
     */
    public static function manual_expire($assignment_id) {
        return EIPSI_Longitudinal_Assignment_Expiration_Service::manual_expire($assignment_id);
    }

    /**
     * Get expiration statistics for a study.
     *
     * @param int $study_id Study ID.
     * @return array Statistics.
     * @since 2.6.0
     */
    public static function get_study_expiration_stats($study_id) {
        return EIPSI_Longitudinal_Assignment_Expiration_Service::get_study_expiration_stats($study_id);
    }
}
