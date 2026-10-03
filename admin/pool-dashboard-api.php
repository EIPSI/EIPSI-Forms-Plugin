<?php
/**
 * Pool Dashboard AJAX API.
 *
 * @package EIPSI_Forms
 * @since 2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Get pool analytics data.
 */
function eipsi_get_pool_analytics() {
    check_ajax_referer( 'eipsi_pool_dashboard_nonce', 'nonce' );

    if ( ! function_exists( 'eipsi_user_can_manage_longitudinal' ) || ! eipsi_user_can_manage_longitudinal() ) {
        wp_send_json_error( array( 'message' => __( 'Unauthorized', 'eipsi-forms' ) ), 403 );
    }

    $pool_id = isset( $_POST['pool_id'] ) ? absint( $_POST['pool_id'] ) : 0;
    if ( ! $pool_id ) {
        wp_send_json_error( array( 'message' => __( 'Pool inválido.', 'eipsi-forms' ) ), 400 );
    }

    if ( ! class_exists( 'EIPSI_Pool_Dashboard_Service' ) ) {
        wp_send_json_error( array( 'message' => __( 'Servicio no disponible.', 'eipsi-forms' ) ), 500 );
    }

    $service = new EIPSI_Pool_Dashboard_Service();
    $analytics = $service->get_pool_analytics( $pool_id );

    wp_send_json_success( $analytics );
}
// Registration owned by the pool API's explicit contract dispatcher.

/**
 * Export pool assignments to CSV.
 */
function eipsi_export_pool_assignments() {
    check_ajax_referer( 'eipsi_pool_dashboard_nonce', 'nonce' );

    if ( ! function_exists( 'eipsi_user_can_manage_longitudinal' ) || ! eipsi_user_can_manage_longitudinal() ) {
        wp_die( esc_html__( 'Unauthorized', 'eipsi-forms' ) );
    }

    $pool_id = isset( $_POST['pool_id'] ) ? absint( $_POST['pool_id'] ) : 0;
    if ( ! $pool_id ) {
        wp_die( esc_html__( 'Pool inválido.', 'eipsi-forms' ) );
    }

    global $wpdb;

    $assignments_table  = $wpdb->prefix . 'eipsi_pool_assignments';
    $participants_table = $wpdb->prefix . 'survey_participants';
    $studies_table      = $wpdb->prefix . 'survey_studies';
    $pools_table        = $wpdb->prefix . 'eipsi_longitudinal_pools';

    $pool_name = $wpdb->get_var(
        $wpdb->prepare( "SELECT pool_name FROM {$pools_table} WHERE id = %d", $pool_id )
    );

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT a.id AS assignment_id,
                a.participant_id,
                a.study_id as assigned_study_id,
                a.completed,
                a.assigned_at,
                p.email,
                p.first_name,
                p.last_name,
                s.study_name,
                s.study_code
            FROM {$assignments_table} a
            LEFT JOIN {$participants_table} p ON a.participant_id = p.id
            LEFT JOIN {$studies_table} s ON a.study_id = s.id
            WHERE a.pool_id = %d
            ORDER BY a.assigned_at DESC",
            $pool_id
        ),
        ARRAY_A
    );

    $filename = sprintf( 'pool-%d-assignments.csv', $pool_id );

    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename=' . $filename );

    $output = fopen( 'php://output', 'w' );

    fputcsv( $output, array(
        'Pool',
        'Assignment ID',
        'Participant ID',
        'Participant Name',
        'Participant Email',
        'Study Name',
        'Study Code',
        'Status',
        'Assigned At',
    ) );

    foreach ( $rows as $row ) {
        $participant_name = trim( sprintf( '%s %s', $row['first_name'], $row['last_name'] ) );
        if ( '' === $participant_name ) {
            $participant_name = $row['email'];
        }

        fputcsv( $output, array(
            $pool_name,
            $row['assignment_id'],
            $row['participant_id'],
            $participant_name,
            $row['email'],
            $row['study_name'],
            $row['study_code'],
            $row['completed'] ? 'completed' : 'assigned',
            $row['assigned_at'],
        ) );
    }

    fclose( $output );
    wp_die();
}
// Registration owned by the pool API's explicit contract dispatcher.
