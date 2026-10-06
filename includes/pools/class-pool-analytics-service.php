<?php
/** M7 owner. Public compatibility adapters retain registration and signatures. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Pool_Analytics_Service {
private $pools_table; private $assignments_table; private $analytics_table; private $cookie_name='eipsi_pool_assignment'; private $cookie_ttl=2592000;
public function __construct() {
        global $wpdb;
        $this->pools_table       = $wpdb->prefix . 'eipsi_longitudinal_pools';
        $this->assignments_table = $wpdb->prefix . 'eipsi_pool_assignments';
        $this->analytics_table   = $wpdb->prefix . 'eipsi_pool_analytics';
    }

public function update_daily_analytics_completions( $pool_id, $study_id ) {
        global $wpdb;

        $today = current_time( 'Y-m-d' );

        // Total acumulado de asignaciones a este estudio en este pool
        $cumulative = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->assignments_table}
                 WHERE pool_id = %d AND study_id = %d",
                absint( $pool_id ),
                absint( $study_id )
            )
        );

        $result = $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$this->analytics_table}
                 (pool_id, date, study_id, completions, cumulative_assignments)
                 VALUES (%d, %s, %d, 1, %d)
                 ON DUPLICATE KEY UPDATE
                 completions = completions + 1,
                 cumulative_assignments = %d",
                absint( $pool_id ),
                $today,
                absint( $study_id ),
                intval( $cumulative ),
                intval( $cumulative )
            )
        );

        return $result !== false;
    }

public function update_daily_analytics_assignments( $pool_id, $study_id ) {
        global $wpdb;

        $today = current_time( 'Y-m-d' );

        // Total acumulado de asignaciones a este estudio en este pool
        $cumulative = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->assignments_table}
                 WHERE pool_id = %d AND study_id = %d",
                absint( $pool_id ),
                absint( $study_id )
            )
        );

        $result = $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$this->analytics_table}
                 (pool_id, date, study_id, assignments, cumulative_assignments)
                 VALUES (%d, %s, %d, 1, %d)
                 ON DUPLICATE KEY UPDATE
                 assignments = assignments + 1,
                 cumulative_assignments = %d",
                absint( $pool_id ),
                $today,
                absint( $study_id ),
                intval( $cumulative ),
                intval( $cumulative )
            )
        );

        return $result !== false;
    }

public function get_pool_stats( $pool_id ) {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT study_id, COUNT(*) as total
                 FROM {$this->assignments_table}
                 WHERE pool_id = %d
                 GROUP BY study_id",
                absint( $pool_id )
            ),
            ARRAY_A
        );

        $stats = array();
        $total = 0;

        foreach ( $rows as $row ) {
            $stats[ intval( $row['study_id'] ) ] = intval( $row['total'] );
            $total += intval( $row['total'] );
        }

        return array(
            'by_study' => $stats,
            'total'    => $total,
        );
    }
public function get_pool_analytics( $pool_id ) {
        $pool_id = absint( $pool_id );

        $pool_info = (new EIPSI_Pool_Dashboard_Query_Service())->get_pool_info( $pool_id );
        $total     = (new EIPSI_Pool_Dashboard_Query_Service())->get_total_assignments( $pool_id );
        $breakdown = (new EIPSI_Pool_Dashboard_Query_Service())->get_studies_breakdown( $pool_id, $total );

        return array(
            'pool_info'        => $pool_info,
            'total_assignments' => $total,
            'studies_breakdown' => $breakdown,
            'completion_rates' => $this->get_completion_rates( $breakdown ),
            'dropout_rates'    => $this->get_dropout_rates( $breakdown ),
            'recent_activity'  => (new EIPSI_Pool_Dashboard_Query_Service())->get_recent_activity( $pool_id ),
            'wave_analytics'   => (new EIPSI_Pool_Dashboard_Query_Service())->get_wave_analytics( $pool_id ),
        );
    }
public function get_completion_rates( $breakdown ) {
        $rates = array();
        foreach ( $breakdown as $row ) {
            $rates[ $row['study_id'] ] = $row['completion_rate'];
        }

        return $rates;
    }
public function get_dropout_rates( $breakdown ) {
        $rates = array();
        foreach ( $breakdown as $row ) {
            $total = (int) $row['assignments'];
            $rates[ $row['study_id'] ] = $total > 0 ? round( ( $row['dropped'] / $total ) * 100, 1 ) : 0;
        }

        return $rates;
    }

}
