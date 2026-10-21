<?php
/** Exact algorithm captured from HEAD 8e36b1a. */
function m7_baseline_rct( $config, $user_fingerprint ) {
    $formularios = $config['formularios'];
    $probabilidades = isset( $config['probabilidades'] ) ? $config['probabilidades'] : array();
    $method      = isset( $config['method'] ) ? $config['method'] : 'seeded';

    // Si es método seeded, usar hash del fingerprint como seed
    if ( $method === 'seeded' ) {
        $seed = crc32( $user_fingerprint . $config['config_id'] );
        mt_srand( $seed );
        error_log( "[EIPSI RCT] Método seeded - seed: {$seed}" );
    }

    // Crear array de probabilidades acumuladas
    $cumulative_probabilities = array();
    $cumulative               = 0;

    foreach ( $formularios as $form ) {
        $form_id = isset( $form['id'] ) ? $form['id'] : 0;
        $porcentaje = isset( $probabilidades[ $form_id ] ) ? intval( $probabilidades[ $form_id ] ) : 0;
        
        $cumulative += $porcentaje;
        $cumulative_probabilities[] = array(
            'postId'     => $form_id,
            'cumulative' => $cumulative,
        );
    }

    // Generar número aleatorio entre 0-100
    $random = mt_rand( 0, 100 );

    error_log( "[EIPSI RCT] Random generado: {$random} de 100" );

    // Encontrar el formulario correspondiente
    foreach ( $cumulative_probabilities as $prob ) {
        if ( $random <= $prob['cumulative'] ) {
            // Resetear seed si era seeded
            if ( $method === 'seeded' ) {
                mt_srand();
            }
            error_log( "[EIPSI RCT] Formulario asignado: {$prob['postId']}" );
            return intval( $prob['postId'] );
        }
    }

    // Fallback (no debería llegar aquí)
    if ( $method === 'seeded' ) {
        mt_srand();
    }
    error_log( '[EIPSI RCT] Fallback: usando primer formulario' );
    $first_form = reset( $formularios );
    return intval( isset( $first_form['id'] ) ? $first_form['id'] : 0 );
}
