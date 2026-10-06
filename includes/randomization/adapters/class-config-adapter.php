<?php
/** M7 owner. Public compatibility adapters retain registration and signatures. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Randomization_Config_Adapter {

public static function eipsi_save_randomization_config() {
    // Verificar nonce de seguridad
    if ( ! wp_verify_nonce( $_POST['nonce'] ?? '', 'eipsi_randomization_config' ) ) {
        wp_send_json_error( array( 'message' => 'Token de seguridad inválido' ) );
    }

    // Verificar permisos del usuario
    if ( ! current_user_can( 'edit_posts' ) ) {
        wp_send_json_error( array( 'message' => 'No tienes permisos para realizar esta acción' ) );
    }

    // Obtener y sanitizar datos
    $post_id = intval( $_POST['post_id'] ?? 0 );
    $shortcodes = isset( $_POST['shortcodes'] ) ? array_map( 'sanitize_text_field', $_POST['shortcodes'] ) : array();
    $formularios = isset( $_POST['formularios'] ) ? $_POST['formularios'] : array();
    $probabilidades = isset( $_POST['probabilidades'] ) ? array_map( 'intval', $_POST['probabilidades'] ) : array();
    $metodo = sanitize_text_field( $_POST['metodo'] ?? 'pure-random' );
    $seed = sanitize_text_field( $_POST['seed'] ?? '' );
    $permitirOverride = isset( $_POST['permitirOverride'] ) ? (bool) $_POST['permitirOverride'] : true;
    $registrarAsignaciones = isset( $_POST['registrarAsignaciones'] ) ? (bool) $_POST['registrarAsignaciones'] : true;
    $persistentMode = isset( $_POST['persistent_mode'] ) ? (bool) $_POST['persistent_mode'] : true;

    // Validaciones
    if ( ! $post_id ) {
        wp_send_json_error( array( 'message' => 'ID del template requerido' ) );
    }

    if ( ! is_array( $formularios ) || empty( $formularios ) ) {
        wp_send_json_error( array( 'message' => 'Se requiere al menos un formulario' ) );
    }

    if ( count( $formularios ) < 1 ) {
        wp_send_json_error( array( 'message' => 'La aleatorización requiere al menos 1 formulario configurado' ) );
    }

    // Validar que probabilidades sumen 100%
    $totalProbabilidades = array_sum( $probabilidades );
    if ( $totalProbabilidades !== 100 ) {
        wp_send_json_error( array( 
            'message' => sprintf( 'Las probabilidades deben sumar 100%%. Total actual: %d%%', $totalProbabilidades )
        ) );
    }

    // Validar que todos los formularios existan
    foreach ( $formularios as $formulario ) {
        if ( ! isset( $formulario['exists'] ) || ! $formulario['exists'] ) {
            wp_send_json_error( array( 'message' => 'Algunos formularios no existen. Verificá los IDs ingresados.' ) );
        }
    }

    // ✅ v1.3.19 - Config ID estable y determinístico (basado SOLO en post_id)
    // ANTES: 'config_456_1706270400_aB3Cd' (cambiaba cada save por time())
    // AHORA: 'rct_post_456_eipsi' (SIEMPRE el mismo para post_id 456)
    $config_id = 'rct_post_' . intval( $post_id ) . '_eipsi';

    // Preparar configuración
    $config = array(
        'config_id' => $config_id,
        'post_id' => $post_id,
        'shortcodes' => $shortcodes,
        'formularios' => $formularios,
        'probabilidades' => $probabilidades,
        'metodo' => $metodo,
        'seed' => $seed,
        'permitirOverride' => $permitirOverride,
        'registrarAsignaciones' => $registrarAsignaciones,
        'persistent_mode' => $persistentMode,
        'created_at' => current_time( 'mysql' ),
        'created_by' => get_current_user_id(),
        'version' => '1.3.19'
    );

    // ✅ v1.3.19 - Buscar si YA existe este config (para UPDATE en lugar de siempre INSERT)
    $meta_key = '_randomization_config_' . $config_id;
    $existing_config = get_post_meta( $post_id, $meta_key, true );

    // ✅ v1.3.19 - UPDATE si existe, INSERT si no existe
    if ( $existing_config ) {
        // YA EXISTE → UPDATE (mantiene config_id estable)
        $result = update_post_meta( $post_id, $meta_key, $config );
        $action = 'updated';
        error_log( "[EIPSI RCT v1.3.19] Config actualizada: {$config_id}" );
    } else {
        // NO EXISTE → INSERT (primera vez)
        $result = add_post_meta( $post_id, $meta_key, $config, true );
        $action = 'created';
        error_log( "[EIPSI RCT v1.3.19] Config creada: {$config_id}" );
    }

    if ( ! $result && $action === 'created' ) {
        // Solo error si era INSERT y falló (UPDATE puede retornar false si no hay cambios)
        wp_send_json_error( array( 'message' => 'Error guardando configuración en la base de datos' ) );
    }

    if ( function_exists( 'eipsi_save_randomization_config_to_db' ) ) {
        eipsi_save_randomization_config_to_db(
            $config_id,
            array(
                'formularios' => $formularios,
                'probabilidades' => $probabilidades,
                'method' => $metodo,
                'manualAssignments' => array(),
                'showInstructions' => false,
            )
        );
    }

    // ✅ v1.3.19 - Shortcode NUNCA cambia (generado UNA SOLA VEZ basado en config_id estable)
    $shortcode = sprintf( '[eipsi_randomization template="%d" config="%s"]', $post_id, $config_id );

    // Respuesta exitosa
    wp_send_json_success( array(
        'config_id' => $config_id,
        'shortcode' => $shortcode,
        'action' => $action, // 'created' o 'updated'
        'message' => 'Configuración guardada exitosamente'
    ) );
}

public static function eipsi_randomization_config_rest_handler( $request ) {
    $post_id = $request->get_param( 'post_id' );
    $shortcodes = $request->get_param( 'shortcodes' ) ?: array();
    $formularios = $request->get_param( 'formularios' ) ?: array();
    $probabilidades = $request->get_param( 'probabilidades' ) ?: array();
    $metodo = $request->get_param( 'metodo' ) ?: 'pure-random';
    $seed = $request->get_param( 'seed' ) ?: '';
    $permitirOverride = $request->get_param( 'permitirOverride' ) ?: true;
    $registrarAsignaciones = $request->get_param( 'registrarAsignaciones' ) ?: true;
    $persistentMode = $request->get_param( 'persistent_mode' ) ?: true;

    // Validaciones
    if ( ! $post_id ) {
        return new WP_REST_Response( array(
            'success' => false,
            'message' => 'ID del template requerido'
        ), 400 );
    }

    if ( empty( $formularios ) ) {
        return new WP_REST_Response( array(
            'success' => false,
            'message' => 'Se requiere al menos un formulario'
        ), 400 );
    }

    if ( count( $formularios ) < 1 ) {
        return new WP_REST_Response( array(
            'success' => false,
            'message' => 'La aleatorización requiere al menos 1 formulario configurado'
        ), 400 );
    }

    // Validar que probabilidades sumen 100%
    $totalProbabilidades = array_sum( $probabilidades );
    if ( $totalProbabilidades !== 100 ) {
        return new WP_REST_Response( array(
            'success' => false,
            'message' => sprintf( 'Las probabilidades deben sumar 100%%. Total actual: %d%%', $totalProbabilidades )
        ), 400 );
    }

    // Validar que todos los formularios existan (backend validation)
    foreach ( $formularios as $formulario ) {
        $form_id = intval( $formulario['id'] ?? 0 );
        $post = get_post( $form_id );

        // Validar tipo y estado (permite draft, private, pending, etc., pero no trash)
        if ( ! $post || $post->post_type !== 'eipsi_form_template' || $post->post_status === 'trash' ) {
            return new WP_REST_Response( array(
                'success' => false,
                'message' => sprintf( 'El formulario con ID %d no existe o fue eliminado.', $form_id )
            ), 400 );
        }
    }

    // ✅ v1.3.19 - Config ID estable y determinístico (basado SOLO en post_id)
    // ANTES: 'config_456_1706270400_aB3Cd' (cambiaba cada save por time())
    // AHORA: 'rct_post_456_eipsi' (SIEMPRE el mismo para post_id 456)
    $config_id = 'rct_post_' . intval( $post_id ) . '_eipsi';

    // Preparar configuración
    $config = array(
        'config_id' => $config_id,
        'post_id' => $post_id,
        'shortcodes' => $shortcodes,
        'formularios' => $formularios,
        'probabilidades' => $probabilidades,
        'metodo' => $metodo,
        'seed' => $seed,
        'permitirOverride' => $permitirOverride,
        'registrarAsignaciones' => $registrarAsignaciones,
        'persistent_mode' => $persistentMode,
        'created_at' => current_time( 'mysql' ),
        'created_by' => get_current_user_id(),
        'version' => '1.3.19'
    );

    // ✅ v1.3.19 - Buscar si YA existe este config (para UPDATE en lugar de siempre INSERT)
    $meta_key = '_randomization_config_' . $config_id;
    $existing_config = get_post_meta( $post_id, $meta_key, true );

    // ✅ v1.3.19 - UPDATE si existe, INSERT si no existe
    if ( $existing_config ) {
        // YA EXISTE → UPDATE (mantiene config_id estable)
        $result = update_post_meta( $post_id, $meta_key, $config );
        $action = 'updated';
        error_log( "[EIPSI RCT v1.3.19] Config actualizada (REST): {$config_id}" );
    } else {
        // NO EXISTE → INSERT (primera vez)
        $result = add_post_meta( $post_id, $meta_key, $config, true );
        $action = 'created';
        error_log( "[EIPSI RCT v1.3.19] Config creada (REST): {$config_id}" );
    }

    if ( ! $result && $action === 'created' ) {
        // Solo error si era INSERT y falló (UPDATE puede retornar false si no hay cambios)
        return new WP_REST_Response( array(
            'success' => false,
            'message' => 'Error guardando configuración en la base de datos'
        ), 500 );
    }

    if ( function_exists( 'eipsi_save_randomization_config_to_db' ) ) {
        eipsi_save_randomization_config_to_db(
            $config_id,
            array(
                'formularios' => $formularios,
                'probabilidades' => $probabilidades,
                'method' => $metodo,
                'manualAssignments' => array(),
                'showInstructions' => false,
            )
        );
    }

    // ✅ v1.3.19 - Shortcode NUNCA cambia (generado UNA SOLA VEZ basado en config_id estable)
    $shortcode = sprintf( '[eipsi_randomization template="%d" config="%s"]', $post_id, $config_id );

    // Respuesta exitosa
    return new WP_REST_Response( array(
        'success' => true,
        'config_id' => $config_id,
        'shortcode' => $shortcode,
        'action' => $action, // 'created' o 'updated'
        'message' => 'Configuración guardada exitosamente'
    ), 200 );
}

public static function eipsi_randomization_detect_rest_handler( $request ) {
    $post_id = $request->get_param( 'post_id' );
    $shortcodes_input = $request->get_param( 'shortcodes_input' );

    if ( empty( $shortcodes_input ) ) {
        return new WP_REST_Response( array(
            'success' => false,
            'message' => 'Ingresá al menos un shortcode.'
        ), 400 );
    }

    // Parsear shortcodes
    $formularios = eipsi_parse_shortcodes_input( $shortcodes_input );

    if ( count( $formularios ) < 1 ) {
        return new WP_REST_Response( array(
            'success' => false,
            'message' => 'No se detectaron shortcodes válidos. Formato: [eipsi_form id="XXXX"]'
        ), 400 );
    }

    // Validar que los formularios existan
    $formularios_validados = array();
    foreach ( $formularios as $formulario ) {
        $post = get_post( $formulario['id'] );

        // Debug logging (only when WP_DEBUG is enabled)
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf(
                '[EIPSI RCT Debug] Validando form ID %d: type=%s, status=%s, exists=%s',
                $formulario['id'],
                $post ? $post->post_type : 'null',
                $post ? $post->post_status : 'null',
                $post ? 'true' : 'false'
            ) );
        }

        // Validar tipo y estado (permite draft, private, pending, etc., pero no trash)
        if ( ! $post || $post->post_type !== 'eipsi_form_template' || $post->post_status === 'trash' ) {
            return new WP_REST_Response( array(
                'success' => false,
                'message' => sprintf( 'El formulario con ID %d no existe o fue eliminado.', $formulario['id'] )
            ), 400 );
        }

        $formularios_validados[] = array(
            'id' => $formulario['id'],
            'name' => $post->post_title,
            'shortcode' => $formulario['shortcode'],
        );
    }

    return new WP_REST_Response( array(
        'success' => true,
        'formularios' => $formularios_validados,
        'message' => sprintf( '%d formularios detectados exitosamente.', count( $formularios_validados ) )
    ), 200 );
}
}
