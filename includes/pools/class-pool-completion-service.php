<?php
/** M7 owner. Public compatibility adapters retain registration and signatures. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Pool_Completion_Service {
private $pools_table; private $assignments_table; private $analytics_table; private $cookie_name='eipsi_pool_assignment'; private $cookie_ttl=2592000;
public function __construct() {
        global $wpdb;
        $this->pools_table       = $wpdb->prefix . 'eipsi_longitudinal_pools';
        $this->assignments_table = $wpdb->prefix . 'eipsi_pool_assignments';
        $this->analytics_table   = $wpdb->prefix . 'eipsi_pool_analytics';
    }

public function mark_completed( $pool_id, $participant_id, $completion_form_id = '' ) {
        global $wpdb;

        // Obtener study_id antes de marcar completada (para analytics)
        $assignment = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, study_id FROM {$this->assignments_table}
                 WHERE pool_id = %d AND participant_id = %s AND completed = 0",
                absint( $pool_id ),
                sanitize_text_field( $participant_id )
            )
        );

        $study_id = $assignment ? $assignment->study_id : 0;

        $result = $wpdb->update(
            $this->assignments_table,
            array(
                'completed'          => 1,
                'completed_at'       => current_time( 'mysql' ),
                'completion_form_id' => sanitize_text_field( $completion_form_id ),
            ),
            array(
                'pool_id'        => absint( $pool_id ),
                'participant_id' => sanitize_text_field( $participant_id ),
                'completed'      => 0,
            ),
            array( '%d', '%s', '%s' ),
            array( '%d', '%s', '%d' )
        );

        if ( $result !== false && $result > 0 && $study_id ) {
            error_log( "[EIPSI] Pool {$pool_id} marcado como completado por participante {$participant_id}, estudio {$study_id}" );
            // Actualizar analytics diarios de completions
            (new EIPSI_Pool_Analytics_Service())->update_daily_analytics_completions( $pool_id, $study_id );
            $pool = (new EIPSI_Pool_Repository())->get_pool($pool_id);
            do_action('eipsi_pool_study_completed', array('pool_id'=>absint($pool_id), 'participant_id'=>sanitize_text_field($participant_id), 'study_id'=>$study_id, 'assignment_id'=>$assignment->id, 'pool_name'=>$pool->pool_name ?? '', 'form_id'=>sanitize_text_field($completion_form_id)));

            return true;
        }

        if ( $result === false ) {
            error_log( "[EIPSI] ERROR: Falló al marcar completado para participante {$participant_id} en pool {$pool_id}: " . $wpdb->last_error );
        }

        return $result !== false && $result > 0;
    }
}
