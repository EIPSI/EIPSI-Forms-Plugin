<?php
/** M7 owner. Public compatibility adapters retain registration and signatures. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Randomization_DB_Rest_Adapter {

public static function eipsi_rest_save_randomization_config( $request ) {
    $randomization_id   = $request->get_param( 'randomizationId' );
    $formularios        = $request->get_param( 'formularios' );
    $method             = $request->get_param( 'method' );
    $manual_assignments = $request->get_param( 'manualAssignments' );
    $show_instructions  = $request->get_param( 'showInstructions' );

    if ( empty( $randomization_id ) || empty( $formularios ) ) {
        return new WP_REST_Response(
            array(
                'success' => false,
                'message' => 'Missing required parameters',
            ),
            400
        );
    }

    // Construir array de probabilidades
    $probabilidades = array();
    foreach ( $formularios as $form ) {
        if ( isset( $form['postId'] ) && isset( $form['porcentaje'] ) ) {
            $probabilidades[ $form['postId'] ] = $form['porcentaje'];
        }
    }

    $config = array(
        'formularios'        => $formularios,
        'probabilidades'     => $probabilidades,
        'method'             => $method ?? 'seeded',
        'manualAssignments'  => $manual_assignments ?? array(),
        'showInstructions'   => $show_instructions ?? false,
    );

    $result = eipsi_save_randomization_config_to_db( $randomization_id, $config );

    if ( $result ) {
        return new WP_REST_Response(
            array(
                'success' => true,
                'message' => 'Configuration saved successfully',
            ),
            200
        );
    } else {
        return new WP_REST_Response(
            array(
                'success' => false,
                'message' => 'Failed to save configuration',
            ),
            500
        );
    }
}
}
