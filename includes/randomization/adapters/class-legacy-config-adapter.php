<?php
/** M7 owner. Public compatibility adapters retain registration and signatures. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Randomization_Legacy_Config_Adapter {

public static function eipsi_get_randomization_config_post( $randomization_id ) {
    // Buscar en posts/páginas que contengan bloques de aleatorización
    $args = array(
        'post_type'      => array( 'post', 'page' ),
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        's'              => $randomization_id, // Buscar en contenido
    );

    $query = new WP_Query( $args );

    if ( ! $query->have_posts() ) {
        return null;
    }

    // Buscar el post que contenga el bloque con este randomizationId
    foreach ( $query->posts as $post ) {
        $blocks = parse_blocks( $post->post_content );
        foreach ( $blocks as $block ) {
            if ( $block['blockName'] === 'eipsi/randomization' &&
                 isset( $block['attrs']['randomizationId'] ) &&
                 $block['attrs']['randomizationId'] === $randomization_id ) {
                return $post;
            }
        }
    }

    return null;
}

public static function eipsi_extract_randomization_config( $post_id, $randomization_id ) {
    $post = get_post( $post_id );
    if ( ! $post ) {
        return null;
    }

    $blocks = parse_blocks( $post->post_content );

    foreach ( $blocks as $block ) {
        if ( $block['blockName'] === 'eipsi/randomization' &&
             isset( $block['attrs']['randomizationId'] ) &&
             $block['attrs']['randomizationId'] === $randomization_id ) {
            return $block['attrs'];
        }
    }

    return null;
}
}
