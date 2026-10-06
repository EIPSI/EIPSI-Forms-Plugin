<?php
/**
 * EIPSI Randomization Shortcode Handler - RCT System
 * 
 * Procesa el shortcode [eipsi_randomization id="xyz"]
 * con fingerprinting robusto y persistencia completa.
 * 
 * Features:
 * - Fingerprinting basado en canvas+device+browser
 * - Persistencia de asignaciones en DB
 * - Respeta asignaciones previas (F5 sin cambio)
 * - Asignaciones manuales (override ético)
 * - Método seeded (reproducible) o pure-random
 * - Tracking completo de accesos
 * 
 * @package EIPSI_Forms
 * @since 1.3.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
require_once EIPSI_FORMS_PLUGIN_DIR.'includes/randomization/bootstrap.php';


/**
 * Shortcode: [eipsi_randomization template="2400" config="abc123xyz"]
 * 
 * @param array $atts Atributos del shortcode
 * @return string HTML output
 */
function eipsi_randomization_shortcode( $atts ) { return EIPSI_Randomization_Shortcode_Adapter::eipsi_randomization_shortcode($atts); }

add_shortcode( 'eipsi_randomization', 'eipsi_randomization_shortcode' );

/**
 * Verificar si existe un override manual en DB para un usuario
 *
 * @param string $randomization_id ID de la configuración
 * @param string $user_fingerprint Fingerprint del usuario
 * @return int|null Form ID asignado manualmente o null
 */
function eipsi_check_manual_override_db( $randomization_id, $user_fingerprint ) { return EIPSI_Randomization_Override_Service::eipsi_check_manual_override_db($randomization_id, $user_fingerprint); }

/**
 * Buscar el post que contiene la configuración de aleatorización
 * 
 * @param string $randomization_id ID de aleatorización
 * @return WP_Post|null
 */
function eipsi_get_randomization_config_post( $randomization_id ) { return EIPSI_Randomization_Legacy_Config_Adapter::eipsi_get_randomization_config_post($randomization_id); }

/**
 * Extraer configuración de aleatorización del post
 * 
 * @param int    $post_id Post ID
 * @param string $randomization_id Randomization ID
 * @return array|null
 */
function eipsi_extract_randomization_config( $post_id, $randomization_id ) { return EIPSI_Randomization_Legacy_Config_Adapter::eipsi_extract_randomization_config($post_id, $randomization_id); }

/**
 * Obtener fingerprint del usuario
 * 
 * Prioridad:
 * 1. Fingerprint desde POST (enviado por JS)
 * 2. Fingerprint desde cookie
 * 3. Email desde URL param (?email=) - para asignaciones manuales
 * 4. Generar fingerprint en servidor (fallback débil)
 * 
 * @return string
 */
function eipsi_get_user_fingerprint() { return EIPSI_Randomization_Tracking_Key_Adapter::eipsi_get_user_fingerprint(); }

/**
 * Generar fingerprint en el servidor (fallback)
 * Combina User Agent + IP + Accept-Language
 * 
 * @return string
 */
function eipsi_generate_server_fingerprint() { return EIPSI_Randomization_Tracking_Key_Adapter::eipsi_generate_server_fingerprint(); }

/**
 * Obtener IP del cliente
 * 
 * @return string
 */
if ( ! function_exists( 'eipsi_get_client_ip' ) ) {
    function eipsi_get_client_ip() {
        $ip = '';

        if ( isset( $_SERVER['HTTP_CLIENT_IP'] ) ) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif ( isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } elseif ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }

        return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
    }
}

/**
 * Calcular asignación aleatoria basada en probabilidades
 * 
 * @param array  $config Configuración de aleatorización
 * @param string $user_fingerprint Fingerprint del usuario
 * @return int Post ID del formulario asignado
 */
function eipsi_calculate_rct_assignment( $config, $user_fingerprint ) { return EIPSI_Randomization_Algorithm_Service::eipsi_calculate_rct_assignment($config, $user_fingerprint); }

/**
 * Función legacy removida - usar versión actualizada en línea 523
 * Con el nuevo esquema: template_id + config_id
 */

/**
 * Función para actualizar una asignación existente (usada en test mode)
 * 
 * @param int $assignment_id ID de la asignación en la DB
 * @param int $assigned_form_id Nuevo formulario asignado
 * @param bool $persistent_mode Nuevo estado del modo persistente
 * @return bool True si se actualizó correctamente
 */
function eipsi_update_assignment_full( $assignment_id, $assigned_form_id, $persistent_mode = true ) { return EIPSI_Randomization_Assignment_Service::eipsi_update_assignment_full($assignment_id, $assigned_form_id, $persistent_mode); }

/**
 * Actualizar timestamp y contador de accesos
 * 
 * @param int $assignment_id ID de la asignación
 * @return bool True si se actualizó correctamente
 */
function eipsi_update_assignment_access( $assignment_id ) { return EIPSI_Randomization_Assignment_Service::eipsi_update_assignment_access($assignment_id); }

/**
 * Generar notice de error
 * 
 * @param string $message Mensaje de error
 * @return string HTML
 */
function eipsi_randomization_error_notice( $message ) { return EIPSI_Randomization_Shortcode_Adapter::eipsi_randomization_error_notice($message); }

/**
 * Hook para manejar query param ?eipsi_rand=xyz
 * Permite acceso directo sin necesidad de shortcode
 * 
 * @since 1.3.4 - Actualizado para nuevo flujo
 */
function eipsi_handle_randomization_query_param() { return EIPSI_Randomization_Shortcode_Adapter::eipsi_handle_randomization_query_param(); }

add_action( 'template_redirect', 'eipsi_handle_randomization_query_param' );

/**
 * Función para obtener asignación existente (actualizada para nuevo flujo)
 * 
 * @param string $config_id Config ID (randomization_id)
 * @param string $user_fingerprint Fingerprint del usuario
 * @return array|null Array con datos de asignación o null
 */
function eipsi_get_existing_assignment( $config_id, $user_fingerprint ) { return EIPSI_Randomization_Assignment_Service::eipsi_get_existing_assignment($config_id, $user_fingerprint); }

/**
 * Función para crear nueva asignación (actualizada para nuevo flujo)
 * 
 * @param string $config_id Config ID (randomization_id)
 * @param string $user_fingerprint Fingerprint del usuario
 * @param int $assigned_form_id Post ID del formulario asignado
 * @param bool $persistent_mode Modo persistente (true/false)
 * @return bool True si se creó correctamente
 */
function eipsi_create_assignment( $config_id, $user_fingerprint, $assigned_form_id, $persistent_mode = true ) { return EIPSI_Randomization_Assignment_Service::eipsi_create_assignment($config_id, $user_fingerprint, $assigned_form_id, $persistent_mode); }

/**
 * Función para cerrar sesión de aleatorización (persistent_mode=OFF)
 * 
 * Elimina la asignación del usuario de la tabla y borra la cookie de rotación.
 * Esto permite que el próximo F5/reload asigne un nuevo formulario en la rotación cíclica.
 * 
 * @param string $config_id Config ID (randomization_id)
 * @param string $user_fingerprint Fingerprint del usuario
 * @return bool True si se cerró correctamente
 */
function eipsi_close_randomization_session( $config_id, $user_fingerprint ) { return EIPSI_Randomization_Assignment_Service::eipsi_close_randomization_session($config_id, $user_fingerprint); }

/* 
 * EIPSI Randomization Shortcode Handler - END OF FILE
 * Todos los comentarios están correctamente cerrados.
 * Última línea: 523
 */
