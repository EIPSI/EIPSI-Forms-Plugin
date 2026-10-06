<?php
/** M7 owner. Public compatibility adapters retain registration and signatures. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Pool_Repository {
private $pools_table; private $assignments_table; private $analytics_table; private $cookie_name='eipsi_pool_assignment'; private $cookie_ttl=2592000;
public function __construct() {
        global $wpdb;
        $this->pools_table       = $wpdb->prefix . 'eipsi_longitudinal_pools';
        $this->assignments_table = $wpdb->prefix . 'eipsi_pool_assignments';
        $this->analytics_table   = $wpdb->prefix . 'eipsi_pool_analytics';
    }

public function get_pool( $pool_id ) {
        global $wpdb;

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->pools_table} WHERE id = %d",
                absint( $pool_id )
            )
        );
    }

public function get_existing_assignment( $pool_id, $participant_id ) {
        global $wpdb;

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->assignments_table}
                 WHERE pool_id = %d AND participant_id = %s
                 ORDER BY assigned_at DESC
                 LIMIT 1",
                absint( $pool_id ),
                sanitize_text_field( $participant_id )
            )
        );
    }

public function create_assignment( $pool_id, $participant_id, $study_id ) {
        global $wpdb;

        $result = $wpdb->insert(
            $this->assignments_table,
            array(
                'pool_id'        => absint( $pool_id ),
                'participant_id' => sanitize_text_field( $participant_id ),
                'study_id'       => absint( $study_id ),
                'assigned_at'    => current_time( 'mysql' ),
                'first_access'   => current_time( 'mysql' ),
                'last_access'    => current_time( 'mysql' ),
                'access_count'   => 1,
                'completed'      => 0,
            ),
            array( '%d', '%s', '%d', '%s', '%s', '%s', '%d', '%d' )
        );

        if ( $result === false ) {
            error_log( '[EIPSI-POOL] Error creando asignación: ' . $wpdb->last_error );
            return false;
        }

        $assignment_id = (int) $wpdb->insert_id;

        error_log( "[EIPSI] Asignación persistida: ID {$assignment_id}, participante {$participant_id}, pool {$pool_id}, estudio {$study_id}" );

        // Actualizar analytics diarios de assignments (Fase 4)
        (new EIPSI_Pool_Analytics_Service())->update_daily_analytics_assignments( $pool_id, $study_id );

        return $assignment_id;
    }

public function record_access( $assignment_id ) {
        global $wpdb;

        $result = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->assignments_table}
                 SET last_access = NOW(),
                     access_count = access_count + 1,
                     first_access = IFNULL(first_access, NOW())
                 WHERE id = %d",
                absint( $assignment_id )
            )
        );

        return $result !== false;
    }

public function get_existing_assignment_by_email( $pool_id, $participant_email ) {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT pa.*
                 FROM {$this->assignments_table} pa
                 INNER JOIN {$wpdb->prefix}survey_participants sp ON sp.id = pa.participant_id
                 WHERE pa.pool_id = %d
                   AND sp.email = %s
                 ORDER BY pa.assigned_at DESC
                 LIMIT 1",
                absint( $pool_id ),
                sanitize_email( $participant_email )
            )
        );

        return $row ?: null;
    }
}
