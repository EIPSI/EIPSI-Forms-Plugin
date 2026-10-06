<?php
/** Exact algorithm captured from HEAD 8e36b1a. */
function m7_baseline_frontend( $config, $user_fingerprint ) {
    $formularios = $config['formularios'];
    $metodo = $config['metodo'] ?? 'pure-random';
    $seed = $config['seed'] ?? '';

    // Si es método seeded, usar hash del fingerprint como seed
    if ( $metodo === 'seeded' && ! empty( $seed ) ) {
        $final_seed = crc32( $user_fingerprint . $seed );
        mt_srand( $final_seed );
        error_log( "[EIPSI RCT] Método seeded - seed: {$final_seed}" );
    }

    // Crear array de probabilidades acumuladas
    $cumulative_probabilities = array();
    $cumulative = 0;

    foreach ( $formularios as $form ) {
        $form_id = $form['id'];
        $porcentaje = $config['probabilidades'][ $form_id ] ?? 0;
        
        $cumulative += $porcentaje;
        $cumulative_probabilities[] = array(
            'form_id' => $form_id,
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
            if ( $metodo === 'seeded' && ! empty( $seed ) ) {
                mt_srand();
            }
            error_log( "[EIPSI RCT] Formulario asignado: {$prob['form_id']}" );
            return intval( $prob['form_id'] );
        }
    }

    // Fallback (no debería llegar aquí)
    if ( $metodo === 'seeded' && ! empty( $seed ) ) {
        mt_srand();
    }
    error_log( '[EIPSI RCT] Fallback: usando primer formulario' );
    return intval( $formularios[0]['id'] );
}
