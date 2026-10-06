<?php
/** M7 owner. Public compatibility adapters retain registration and signatures. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Pool_Longitudinal_Read_Adapter {
private $pools_table; private $assignments_table; private $analytics_table; private $cookie_name='eipsi_pool_assignment'; private $cookie_ttl=2592000;
public function __construct() {
        global $wpdb;
        $this->pools_table       = $wpdb->prefix . 'eipsi_longitudinal_pools';
        $this->assignments_table = $wpdb->prefix . 'eipsi_pool_assignments';
        $this->analytics_table   = $wpdb->prefix . 'eipsi_pool_analytics';
    }

public function get_study_url( $study_id ) {
        global $wpdb;

        $studies_table = $wpdb->prefix . 'survey_studies';

        $config = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT config FROM {$studies_table} WHERE id = %d",
                absint( $study_id )
            )
        );

        if ( ! $config ) {
            return '';
        }

        $config_array = json_decode( $config, true );
        return $config_array['shortcode_page_url'] ?? '';
    }

public function is_study_completed( $study_id, $participant_id ) {
        global $wpdb;

        $waves_table      = $wpdb->prefix . 'survey_waves';
        $assignments_table = $wpdb->prefix . 'survey_assignments';

        // Contar total de waves ACTIVAS del estudio
        $total_waves = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$waves_table}
                 WHERE study_id = %d AND status = 'active'",
                absint( $study_id )
            )
        );

        if ( ! $total_waves ) {
            return false;
        }

        // Contar assignments completados (waves con status 'submitted')
        $completed_waves = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$assignments_table} sa
                 JOIN {$waves_table} sw ON sw.id = sa.wave_id
                 WHERE sw.study_id = %d
                 AND sw.status = 'active'
                 AND sa.participant_id = %d
                 AND sa.status = 'submitted'",
                absint( $study_id ),
                absint( $participant_id )
            )
        );

        return intval( $completed_waves ) >= intval( $total_waves );
    }

public function get_study_name( $study_id ) {
        global $wpdb;

        $name = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT study_name FROM {$wpdb->prefix}survey_studies WHERE id = %d",
                absint( $study_id )
            )
        );

        return $name ?: sprintf( __( 'Estudio #%d', 'eipsi-forms' ), $study_id );
    }
}
