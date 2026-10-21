<?php
/**
 * EIPSI_T1_Anchor_Service
 *
 * Phase 2 of the Longitudinal Timeline Roadmap.
 * Implements the T1-Anchor pattern: when a participant completes T1 (wave_index=1),
 * all future wave dates are calculated and persisted as absolute timestamps.
 *
 * Key benefits:
 * - Determinism: Dates calculated once and stored
 * - Auditability: Each participant has their timeline recorded
 * - Scalability: Cron only does simple comparisons (NOW() > due_at)
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

class EIPSI_T1_Anchor_Service {

    /**
     * Hook into the form submission to detect T1 completion.
     * This is called after a form is successfully submitted.
     *
     * @since 2.6.0
     */
    public static function init() {
        // Hook after form submission to check for T1 completion
        add_action('eipsi_form_submitted', array(__CLASS__, 'on_form_submitted'), 5, 1);

        // Also hook into assignment status change
        add_action('eipsi_assignment_status_changed', array(__CLASS__, 'on_assignment_status_changed'), 10, 3);
    }

    /**
     * Handler for form submission.
     * Checks if this is a T1 submission and anchors the timeline.
     *
     * @param array $data Submission data: survey_id, participant_id, wave_index, form_id, insert_id.
     * @since 2.6.0
     */
    public static function on_form_submitted($data) {
        return EIPSI_Longitudinal_T1_Anchor_Service::on_form_submitted($data);
    }

    /**
     * Handler for assignment status change.
     * Alternative hook point for T1 completion detection.
     *
     * @param int    $wave_id        Wave ID.
     * @param int    $participant_id Participant ID.
     * @param string $new_status     New status.
     * @since 2.6.0
     */
    public static function on_assignment_status_changed($wave_id, $participant_id, $new_status) {
        return EIPSI_Longitudinal_T1_Anchor_Service::on_assignment_status_changed($wave_id, $participant_id, $new_status);
    }

    /**
     * Anchor the participant's timeline.
     *
     * When T1 is completed:
     * 1. Record t1_completed_at on the participant
     * 2. Calculate available_at and due_at for all future waves
     * 3. Persist these dates to survey_assignments
     *
     * @param int $study_id       Study ID.
     * @param int $participant_id Participant ID.
     * @return array|WP_Error Result with anchored waves or error.
     * @since 2.6.0
     */
    public static function anchor_participant_timeline($study_id, $participant_id) {
        return EIPSI_Longitudinal_T1_Anchor_Service::anchor_participant_timeline($study_id, $participant_id);
    }

    /**
     * Get the anchored timeline for a participant.
     *
     * @param int $study_id       Study ID.
     * @param int $participant_id Participant ID.
     * @return array Timeline data.
     * @since 2.6.0
     */
    public static function get_participant_anchored_timeline($study_id, $participant_id) {
        return EIPSI_Longitudinal_T1_Anchor_Service::get_participant_anchored_timeline($study_id, $participant_id);
    }

    /**
     * Manually anchor a participant's timeline (admin action).
     *
     * Used when T1 was completed before the anchor system was in place,
     * or for manual corrections.
     *
     * @param int         $study_id        Study ID.
     * @param int         $participant_id  Participant ID.
     * @param string|null $t1_timestamp    Optional T1 timestamp to use. Defaults to assignment's submitted_at.
     * @param bool        $force           Force re-anchoring even if already anchored.
     * @return array|WP_Error Result.
     * @since 2.6.0
     */
    public static function manual_anchor($study_id, $participant_id, $t1_timestamp = null, $force = false) {
        return EIPSI_Longitudinal_T1_Anchor_Service::manual_anchor($study_id, $participant_id, $t1_timestamp, $force);
    }

    /**
     * Batch anchor all participants who have completed T1 but aren't anchored.
     *
     * Useful for migrating existing data to the new anchor system.
     *
     * @param int $study_id Study ID. If 0, process all studies.
     * @return array Results with counts.
     * @since 2.6.0
     */
    public static function batch_anchor_existing_participants($study_id = 0) {
        return EIPSI_Longitudinal_T1_Anchor_Service::batch_anchor_existing_participants($study_id);
    }
}

// Initialize hooks
EIPSI_T1_Anchor_Service::init();
