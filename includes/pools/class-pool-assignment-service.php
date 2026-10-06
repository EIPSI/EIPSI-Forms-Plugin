<?php
/** M7 owner. Public compatibility adapters retain registration and signatures. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Pools_Assignment_Service {
private $pools_table; private $assignments_table; private $analytics_table; private $cookie_name='eipsi_pool_assignment'; private $cookie_ttl=2592000;
public function __construct() {
        global $wpdb;
        $this->pools_table       = $wpdb->prefix . 'eipsi_longitudinal_pools';
        $this->assignments_table = $wpdb->prefix . 'eipsi_pool_assignments';
        $this->analytics_table   = $wpdb->prefix . 'eipsi_pool_analytics';
    }

public function assign_participant( $pool_id, $participant_id, $method = 'seeded' ) {
        global $wpdb;
        $key = 'eipsi-pool-' . md5($wpdb->prefix . ':' . $pool_id . ':' . $participant_id);
        if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $key)) !== 1) { return false; }
        try { return $this->assign_locked($pool_id, $participant_id, $method); }
        finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $key)); }
    }
    private function assign_locked( $pool_id, $participant_id, $method = 'seeded' ) {
        global $wpdb;

        $pool_id        = absint( $pool_id );
        $participant_id = sanitize_text_field( $participant_id );
        $method         = in_array( $method, array( 'seeded', 'pure-random' ), true ) ? $method : 'seeded';

        // Verificar que el pool existe y está activo
        $pool = (new EIPSI_Pool_Repository())->get_pool( $pool_id );
        if ( ! $pool || $pool->status !== 'active' ) {
            error_log( "[EIPSI-POOL] Pool {$pool_id} no existe o no está activo" );
            return false;
        }

        // Obtener config del pool
        $config = json_decode( $pool->config, true );
        if ( empty( $config['studies'] ) ) {
            error_log( "[EIPSI-POOL] Pool {$pool_id} no tiene estudios configurados" );
            return false;
        }

        $studies = $config['studies'];

        // Verificar allow_reassignment
        $allow_reassignment = ! empty( $config['allow_reassignment'] );

        // Buscar asignación existente
        $existing = (new EIPSI_Pool_Repository())->get_existing_assignment( $pool_id, $participant_id );

        if ( $existing ) {
            // Si no permite re-asignación o no completó, devolver existente
            if ( ! $allow_reassignment || ! $existing->completed ) {
                error_log( "[EIPSI] Usando asignación existente para participante {$participant_id} en pool {$pool_id}, estudio {$existing->study_id}" );
                (new EIPSI_Pool_Repository())->record_access( $existing->id );
                return $this->format_assignment( $existing, true );
            }
            // Si permite re-asignación y completó, continuar para crear nueva
            error_log( "[EIPSI] Re-asignando participante {$participant_id} en pool {$pool_id} (estudio previo {$existing->study_id} completado)" );
        }

        // Weighted random assignment
        $selected_study = $this->weighted_random_assign( $pool_id, $participant_id, $method, $studies );

        if ( ! $selected_study ) {
            error_log( "[EIPSI-POOL] Error en weighted random para pool {$pool_id}" );
            return false;
        }

        $study_id = $selected_study['id'];

        // Crear nueva asignación
        $assignment_id = (new EIPSI_Pool_Repository())->create_assignment( $pool_id, $participant_id, $study_id );

        if ( ! $assignment_id ) {
            error_log( "[EIPSI] ERROR: Falló creación de asignación para participante {$participant_id} en pool {$pool_id}" );
            return false;
        }

        error_log( "[EIPSI] Asignación creada exitosamente: ID {$assignment_id}, participante {$participant_id}, pool {$pool_id}, estudio {$study_id}" );

        // Obtener la asignación recién creada
        $assignment = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->assignments_table} WHERE id = %d",
                $assignment_id
            )
        );

        // Setear cookie para participantes anónimos (no autenticados)
        if ( ! $this->is_authenticated_participant( $participant_id ) ) {
            $this->set_assignment_cookie( $pool_id, $assignment_id );
        }

        return $this->format_assignment( $assignment, false );
    }

public function weighted_random_assign( $pool_id, $participant_id, $method, $studies ) { return EIPSI_Pool_Algorithm_Service::weighted_random_assign($pool_id, $participant_id, $method, $studies); }

public function set_assignment_cookie( $pool_id, $assignment_id ) {
        $cookie_value = wp_json_encode( array(
            'pool_id'       => $pool_id,
            'assignment_id' => $assignment_id,
        ) );

        setcookie(
            $this->cookie_name . '_' . $pool_id,
            $cookie_value,
            array(
                'expires'  => time() + $this->cookie_ttl,
                'path'     => '/',
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            )
        );
    }

public function get_assignment_from_cookie( $pool_id ) {
        $cookie_name = $this->cookie_name . '_' . $pool_id;

        if ( ! isset( $_COOKIE[ $cookie_name ] ) ) {
            return null;
        }

        $cookie_value = sanitize_text_field( wp_unslash( $_COOKIE[ $cookie_name ] ) );
        $data = json_decode( $cookie_value, true );

        if ( ! is_array( $data ) || empty( $data['assignment_id'] ) ) {
            return null;
        }

        return $data;
    }

public function is_authenticated_participant( $participant_id ) {
        // Si es un email_id (contiene @) o fingerprint (hash), no está autenticado vía Auth Service
        // Los participantes autenticados vía EIPSI_Auth_Service usan el ID numérico directo
        return is_numeric( $participant_id ) && intval( $participant_id ) > 0;
    }

public function assign_participant_to_pool( $pool_id, $participant_email, $participant_name = null ) {
        // Buscar o crear participante para obtener su ID
        $access = EIPSI_Authorization_Policy::authorize_session_context();
        if (!$access['success']) { return new WP_Error($access['error'], __('Se requiere una sesión participante válida.', 'eipsi-forms')); }
        $record = EIPSI_Participant_Repository::get_by_id($access['participant_id']);
        if (!$record || strcasecmp($record->email, $participant_email) !== 0) { return new WP_Error('session_context_mismatch', __('La identidad no coincide con la sesión.', 'eipsi-forms')); }
        $participant = array('participant_id'=>$access['participant_id'], 'is_new'=>false);

        if ( is_wp_error( $participant ) ) {
            return $participant;
        }

        $participant_id = $participant['participant_id'];

        // Obtener pool para el método
        $pool = (new EIPSI_Pool_Repository())->get_pool( $pool_id );
        $method = $pool && isset( $pool->method ) ? $pool->method : 'seeded';

        // Usar el nuevo método de asignación
        $assignment = $this->assign_participant( $pool_id, $participant_id, $method );

        if ( ! $assignment ) {
            return new WP_Error( 'assignment_failed', __( 'No se pudo asignar el participante.', 'eipsi-forms' ) );
        }

        // Generar magic link
        $magic_link_url = $this->generate_magic_link_for_study( $assignment->study_id, $participant_id );

        if ( is_wp_error( $magic_link_url ) ) {
            return $magic_link_url;
        }

        return array(
            'success'           => true,
            'study_id'          => $assignment->study_id,
            'participant_id'    => $participant_id,
            'magic_link_url'    => $magic_link_url,
            'is_new_assignment' => ! $assignment->is_existing,
            'study_name'        => (new EIPSI_Pool_Longitudinal_Read_Adapter())->get_study_name( $assignment->study_id ),
            'pool_name'         => $pool ? $pool->pool_name : '',
        );
    }

private function format_assignment( $assignment, $is_existing = false ) {
        return (object) array(
            'id'            => intval( $assignment->id ),
            'pool_id'       => intval( $assignment->pool_id ),
            'participant_id'=> $assignment->participant_id,
            'study_id'      => intval( $assignment->study_id ),
            'assigned_at'   => $assignment->assigned_at,
            'first_access'  => $assignment->first_access,
            'last_access'   => $assignment->last_access,
            'access_count'  => intval( $assignment->access_count ),
            'completed'     => (bool) $assignment->completed,
            'completed_at'  => $assignment->completed_at,
            'is_existing'   => $is_existing,
        );
    }

private function generate_magic_link_for_study( $study_id, $participant_id ) {
        $url = EIPSI_Email_Service::generate_magic_link_url($study_id, $participant_id);
        return $url ?: new WP_Error('magic_link_failed', __('No se pudo generar el acceso.', 'eipsi-forms'));
    }



public function get_pool( $pool_id ) { return (new EIPSI_Pool_Repository())->get_pool($pool_id); }

public function get_existing_assignment( $pool_id, $participant_id ) { return (new EIPSI_Pool_Repository())->get_existing_assignment($pool_id, $participant_id); }

public function create_assignment( $pool_id, $participant_id, $study_id ) { return (new EIPSI_Pool_Repository())->create_assignment($pool_id, $participant_id, $study_id); }

public function record_access( $assignment_id ) { return (new EIPSI_Pool_Repository())->record_access($assignment_id); }

public function get_existing_assignment_by_email( $pool_id, $participant_email ) { return (new EIPSI_Pool_Repository())->get_existing_assignment_by_email($pool_id, $participant_email); }

public function mark_completed( $pool_id, $participant_id, $completion_form_id = '' ) { return (new EIPSI_Pool_Completion_Service())->mark_completed($pool_id, $participant_id, $completion_form_id); }

public function update_daily_analytics_completions( $pool_id, $study_id ) { return (new EIPSI_Pool_Analytics_Service())->update_daily_analytics_completions($pool_id, $study_id); }

public function update_daily_analytics_assignments( $pool_id, $study_id ) { return (new EIPSI_Pool_Analytics_Service())->update_daily_analytics_assignments($pool_id, $study_id); }

public function get_pool_stats( $pool_id ) { return (new EIPSI_Pool_Analytics_Service())->get_pool_stats($pool_id); }

public function get_study_url( $study_id ) { return (new EIPSI_Pool_Longitudinal_Read_Adapter())->get_study_url($study_id); }

public function is_study_completed( $study_id, $participant_id ) { return (new EIPSI_Pool_Longitudinal_Read_Adapter())->is_study_completed($study_id, $participant_id); }

public function get_study_name( $study_id ) { return (new EIPSI_Pool_Longitudinal_Read_Adapter())->get_study_name($study_id); }
}
