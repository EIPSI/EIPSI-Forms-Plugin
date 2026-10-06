<?php
/**
 * EIPSI Randomization Config Handler - FLUJO MANUAL
 * 
 * Maneja el guardado de configuración de aleatorización y generación de shortcode único
 * para el flujo manual basado en shortcodes de formularios.
 * 
 * @package EIPSI_Forms
 * @since 1.3.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
require_once EIPSI_FORMS_PLUGIN_DIR.'includes/randomization/bootstrap.php';


/**
 * Obtener configuración existente por config_id (helper function)
 * 
 * @param int $post_id Template post ID
 * @param string $config_id Config ID (ej: 'rct_post_456_eipsi')
 * @return array|false Configuración o false si no existe
 * @since 1.3.19
 */
function eipsi_get_randomization_config_by_id( $post_id, $config_id ) { return EIPSI_Randomization_Config_Service::eipsi_get_randomization_config_by_id($post_id, $config_id); }

/**
 * Endpoint AJAX para guardar configuración de aleatorización
 * 
 * @since 1.3.4
 */
function eipsi_save_randomization_config() { return EIPSI_Randomization_Config_Adapter::eipsi_save_randomization_config(); }

// Registrar endpoint AJAX para usuarios logueados
add_action( 'wp_ajax_eipsi_save_randomization_config', 'eipsi_save_randomization_config' );

/**
 * Registrar endpoint REST para compatibilidad con el bloque Gutenberg
 * 
 * @since 1.3.4
 */
function eipsi_register_randomization_config_rest() {
    register_rest_route( 'eipsi/v1', '/randomization-config', array(
        'methods' => 'POST',
        'callback' => 'eipsi_randomization_config_rest_handler',
        'permission_callback' => function() {
            return current_user_can( 'edit_posts' );
        },
        'args' => array(
            'post_id' => array(
                'required' => true,
                'type' => 'integer',
            ),
            'shortcodes' => array(
                'required' => false,
                'type' => 'array',
            ),
            'formularios' => array(
                'required' => true,
                'type' => 'array',
            ),
            'probabilidades' => array(
                'required' => true,
                'type' => 'object',
            ),
            'metodo' => array(
                'required' => false,
                'type' => 'string',
                'default' => 'pure-random',
            ),
            'seed' => array(
                'required' => false,
                'type' => 'string',
                'default' => '',
            ),
            'permitirOverride' => array(
                'required' => false,
                'type' => 'boolean',
                'default' => true,
            ),
            'registrarAsignaciones' => array(
                'required' => false,
                'type' => 'boolean',
                'default' => true,
            ),
            'persistent_mode' => array(
                'required' => false,
                'type' => 'boolean',
                'default' => true,
            ),
        ),
    ) );
}
add_action( 'rest_api_init', 'eipsi_register_randomization_config_rest' );

/**
 * Handler para endpoint REST
 * 
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function eipsi_randomization_config_rest_handler( $request ) { return EIPSI_Randomization_Config_Adapter::eipsi_randomization_config_rest_handler($request); }

/**
 * Registrar endpoint REST para detectar formularios (KISS flow)
 * 
 * @since 1.3.5
 */
function eipsi_register_randomization_detect_rest() {
    register_rest_route( 'eipsi/v1', '/randomization-detect', array(
        'methods' => 'POST',
        'callback' => 'eipsi_randomization_detect_rest_handler',
        'permission_callback' => function() {
            return current_user_can( 'edit_posts' );
        },
        'args' => array(
            'post_id' => array(
                'required' => true,
                'type' => 'integer',
            ),
            'shortcodes_input' => array(
                'required' => true,
                'type' => 'string',
            ),
        ),
    ) );
}
add_action( 'rest_api_init', 'eipsi_register_randomization_detect_rest' );

/**
 * Handler para endpoint de detección de formularios
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function eipsi_randomization_detect_rest_handler( $request ) { return EIPSI_Randomization_Config_Adapter::eipsi_randomization_detect_rest_handler($request); }

/**
 * Parsear shortcodes desde input de texto
 * 
 * @param string $input Input de texto con shortcodes (uno por línea)
 * @return array Array de formularios detectados
 */
function eipsi_parse_shortcodes_input( $input ) { return EIPSI_Randomization_Config_Service::eipsi_parse_shortcodes_input($input); }

/**
 * Función para obtener configuración de aleatorización desde post meta
 * 
 * @param int $post_id Template ID
 * @param string $config_id Config ID
 * @return array|null
 */
function eipsi_get_randomization_config_from_post_meta( $post_id, $config_id ) { return EIPSI_Randomization_Config_Service::eipsi_get_randomization_config_from_post_meta($post_id, $config_id); }