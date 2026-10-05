<?php
/** M2 owner extracted from existing implementation; legacy APIs remain facades. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Participant_State_Service {

    public static function set_active($participant_id, $is_active) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'survey_participants';
        $result = EIPSI_Participant_Repository::update(
            array('is_active' => $is_active ? 1 : 0),
            array('id' => $participant_id),
            array('%d'),
            array('%d')
        );

        return $result !== false;
    }

    public static function deactivate($participant_id, $reason = '') {
        global $wpdb;

        $table_name = $wpdb->prefix . 'survey_participants';
        $result = EIPSI_Participant_Repository::update(
            array(
                'is_active' => 0,
                'updated_at' => current_time('mysql')
            ),
            array('id' => $participant_id),
            array('%d', '%s'),
            array('%d')
        );

        // Log de auditoría
        if ($result !== false) {
            eipsi_log_participant_audit($participant_id, 'deactivated', $reason);
        }

        return $result !== false;
    }
    public static function confirm_email($participant_id) {
        return EIPSI_Participant_Repository::update(
            array(
                'is_active'  => 1,
                'updated_at' => current_time( 'mysql' ),
            ),
            array( 'id' => $participant_id ),
            array( '%d', '%s' ),
            array( '%d' )
        );
    }

    public static function save_consent($participant_id, $study_id, $data) {
        return EIPSI_Participant_Repository::update($data, array('id'=>$participant_id, 'survey_id'=>$study_id));
    }

}
