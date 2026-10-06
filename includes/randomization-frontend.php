<?php
/**
 * EIPSI Randomization Frontend - Procesamiento de Aleatorización
 * 
 * Maneja la lógica de aleatorización para el nuevo flujo manual de shortcodes.
 * 
 * @package EIPSI_Forms
 * @since 1.3.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
require_once EIPSI_FORMS_PLUGIN_DIR.'includes/randomization/bootstrap.php';


/**
 * Función para calcular asignación aleatoria basada en probabilidades
 * 
 * @param array $config Configuración de aleatorización
 * @param string $user_fingerprint Fingerprint del usuario
 * @return int Post ID del formulario asignado
 */
function eipsi_calculate_frontend_assignment( $config, $user_fingerprint ) { return EIPSI_Randomization_Frontend_Adapter::eipsi_calculate_frontend_assignment($config, $user_fingerprint); }

/**
 * Función para verificar asignaciones manuales (override ético)
 * 
 * @param array $config Configuración de aleatorización
 * @param string $user_fingerprint Fingerprint del usuario
 * @return int|null Post ID del formulario asignado o null
 */

/**
 * Registrar shortcode público para el nuevo flujo
 */
add_action( 'init', function() {
    add_shortcode( 'eipsi_randomization', 'eipsi_randomization_shortcode' );
} );

/**
 * Enqueue scripts necesarios para el frontend
 */
add_action( 'wp_enqueue_scripts', 'eipsi_randomization_frontend_scripts' );
function eipsi_randomization_frontend_scripts() { return EIPSI_Randomization_Frontend_Adapter::eipsi_randomization_frontend_scripts(); }

/**
 * Agregar datos necesarios para JavaScript
 */
add_action( 'wp_head', 'eipsi_randomization_inline_data' );
function eipsi_randomization_inline_data() { return EIPSI_Randomization_Frontend_Adapter::eipsi_randomization_inline_data(); }

/**
 * Handler AJAX para enviar fingerprint del usuario
 */
add_action( 'wp_ajax_eipsi_send_user_fingerprint', 'eipsi_handle_send_user_fingerprint' );
add_action( 'wp_ajax_nopriv_eipsi_send_user_fingerprint', 'eipsi_handle_send_user_fingerprint' );
function eipsi_handle_send_user_fingerprint() { return EIPSI_Randomization_Frontend_Adapter::eipsi_handle_send_user_fingerprint(); }

/**
 * Función helper para logging (desarrollo)
 */
function eipsi_randomization_log( $message ) { return EIPSI_Randomization_Frontend_Adapter::eipsi_randomization_log($message); }

function eipsi_load_form_handler() { EIPSI_Randomization_Form_Load_Adapter::load(); }
add_action('wp_ajax_eipsi_load_form', 'eipsi_load_form_handler');
add_action('wp_ajax_nopriv_eipsi_load_form', 'eipsi_load_form_handler');
function eipsi_randomization_form_load_data() { EIPSI_Randomization_Frontend_Adapter::localize_form_load(); }
add_action('wp_enqueue_scripts', 'eipsi_randomization_form_load_data', 100);
