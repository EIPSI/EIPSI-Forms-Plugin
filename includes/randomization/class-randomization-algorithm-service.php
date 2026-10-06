<?php
/** M7 owner. Public compatibility adapters retain registration and signatures. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Randomization_Algorithm_Service {

public static function eipsi_calculate_rct_assignment( $config, $user_fingerprint ) {
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
public static function eipsi_calculate_frontend_assignment( $config, $user_fingerprint ) {
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

}
