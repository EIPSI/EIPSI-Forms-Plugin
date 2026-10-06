<?php
/** M7 owner. Public compatibility adapters retain registration and signatures. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Randomization_Config_Service {

public static function eipsi_get_randomization_config_by_id( $post_id, $config_id ) {
    $meta_key = '_randomization_config_' . $config_id;
    return get_post_meta( $post_id, $meta_key, true );
}

public static function eipsi_parse_shortcodes_input( $input ) {
    $formularios = array();
    
    // Usar regex global para encontrar TODOS los shortcodes (no solo por línea)
    // Soporta: espacios, saltos de línea, sin espacios
    // Formato: [eipsi_form id="2482"], [eipsi_form id='2482'], [eipsi_form id=2482]
    
    $pattern = '/\[eipsi_form\s+id\s*=\s*["\']?(\d+)["\']?\]/i';
    
    if ( preg_match_all( $pattern, $input, $matches ) ) {
        // $matches[1] contiene todos los form_ids encontrados
        foreach ( $matches[1] as $match ) {
            $form_id = intval( $match );
            if ( $form_id > 0 && ! isset( $formularios[ $form_id ] ) ) {
                // Evitar duplicados
                $formularios[ $form_id ] = array(
                    'id' => $form_id,
                    'shortcode' => '[eipsi_form id="' . $form_id . '"]',
                );
            }
        }
    }

    return array_values( $formularios );
}

public static function eipsi_get_randomization_config_from_post_meta( $post_id, $config_id ) {
    $meta_key = '_randomization_config_' . $config_id;
    $config = get_post_meta( $post_id, $meta_key, true );
    
    if ( ! $config || empty( $config ) ) {
        return null;
    }

    return $config;
}
}
