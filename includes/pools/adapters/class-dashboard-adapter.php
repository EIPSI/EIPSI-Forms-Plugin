<?php
/** M7 owner. Public compatibility adapters retain registration and signatures. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Pool_Dashboard_Adapter {

public static function eipsi_get_pool_analytics() {
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

public static function eipsi_export_pool_assignments() {
    check_ajax_referer( 'eipsi_pool_dashboard_nonce', 'nonce' );

    if ( ! function_exists( 'eipsi_user_can_manage_longitudinal' ) || ! eipsi_user_can_manage_longitudinal() ) {
        wp_die( esc_html__( 'Unauthorized', 'eipsi-forms' ) );
    }

    $pool_id = isset( $_POST['pool_id'] ) ? absint( $_POST['pool_id'] ) : 0;
    if ( ! $pool_id ) {
        wp_die( esc_html__( 'Pool inválido.', 'eipsi-forms' ) );
    }

    require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/export/bootstrap.php';
    $dataset=EIPSI_Export_Query_Service::pool_roster_dashboard($pool_id);
    EIPSI_Export_File_Service::stream_pool_roster_dashboard($pool_id, $dataset);
    wp_die();
}
}
