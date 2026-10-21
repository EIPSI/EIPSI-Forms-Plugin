<?php
/**
 * EIPSI Forms - Pool Assignment AJAX API
 *
 * Handlers para que participantes se unan a pools de asignación aleatoria.
 * Funciona tanto para usuarios logueados como anónimos (nopriv).
 *
 * @package EIPSI_Forms
 * @since 2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
require_once EIPSI_FORMS_PLUGIN_DIR.'includes/pools/bootstrap.php';


/**
 * Handler AJAX: unirse a un pool (usuarios logueados y anónimos).
 *
 * Inputs POST esperados:
 *   - eipsi_pool_join_nonce : nonce de seguridad
 *   - pool_id               : int
 *   - email                 : string (email del participante)
 *   - name                  : string (opcional)
 *
 * Respuesta JSON:
 *   { success: bool, data: { magic_link_url, study_name, pool_name, is_new_assignment } }
 *   { success: false, data: { message: string } }
 */
function eipsi_ajax_join_pool() { return EIPSI_Pool_Ajax_Adapter::eipsi_ajax_join_pool(); }

add_action( 'wp_ajax_eipsi_join_pool', 'eipsi_ajax_join_pool' );
add_action( 'wp_ajax_nopriv_eipsi_join_pool', 'eipsi_ajax_join_pool' );

/**
 * Handler AJAX: autenticación en pool (login o register).
 *
 * Inputs POST esperados:
 *   - nonce      : string (nonce de seguridad)
 *   - pool_id    : int
 *   - email      : string
 *   - auth_action: 'login' | 'register'
 *
 * Respuesta JSON para login:
 *   { success: true, data: { 
 *       redirect_url: string|null,  // Si tiene estudio asignado
 *       magic_link_url: string|null, // Si no tiene estudio
 *       message: string 
 *   }}
 *
 * Respuesta JSON para register:
 *   { success: true, data: { 
 *       confirmation_sent: true,
 *       message: string 
 *   }}
 *
 * @since 2.3.0
 */
function eipsi_ajax_pool_auth() { return EIPSI_Pool_Ajax_Adapter::eipsi_ajax_pool_auth(); }

add_action( 'wp_ajax_eipsi_pool_auth', 'eipsi_ajax_pool_auth' );
add_action( 'wp_ajax_nopriv_eipsi_pool_auth', 'eipsi_ajax_pool_auth' );

/**
 * Handler AJAX: obtener estadísticas de un pool (solo admins).
 *
 * Inputs POST esperados:
 *   - eipsi_pool_stats_nonce : nonce de seguridad
 *   - pool_id                : int
 *
 * Respuesta JSON:
 *   { success: bool, data: { by_study: {...}, total: int } }
 */
function eipsi_ajax_get_pool_stats() { return EIPSI_Pool_Ajax_Adapter::eipsi_ajax_get_pool_stats(); }

add_action( 'wp_ajax_eipsi_get_pool_stats', 'eipsi_ajax_get_pool_stats' );

/**
 * Handler AJAX: obtener resumen de todos los pools (para Overview).
 *
 * @since 2.5.3 - Fase 5: Pool Hub Dashboard
 */
function eipsi_ajax_get_all_pools_summary() { return EIPSI_Pool_Ajax_Adapter::eipsi_ajax_get_all_pools_summary(); }
add_action('wp_ajax_eipsi_get_all_pools_summary', 'eipsi_ajax_get_all_pools_summary');

/**
 * Handler AJAX: obtener logs de emails de un pool.
 *
 * @since 2.5.4
 */
function eipsi_ajax_get_pool_email_logs() { return EIPSI_Pool_Ajax_Adapter::eipsi_ajax_get_pool_email_logs(); }
add_action('wp_ajax_eipsi_get_pool_email_logs', 'eipsi_ajax_get_pool_email_logs');

/**
 * Handler AJAX: reenviar email de confirmación de pool.
 *
 * @since 2.5.4
 */
function eipsi_ajax_resend_pool_confirmation() { return EIPSI_Pool_Ajax_Adapter::eipsi_ajax_resend_pool_confirmation(); }
add_action('wp_ajax_eipsi_resend_pool_confirmation', 'eipsi_ajax_resend_pool_confirmation');

/**
 * Handler AJAX: toggle estado activo/pausado de un pool.
 *
 * @since 2.5.3 - Fase 5: Pool Hub Dashboard
 */
function eipsi_ajax_toggle_pool_status() { return EIPSI_Pool_Ajax_Adapter::eipsi_ajax_toggle_pool_status(); }
add_action('wp_ajax_eipsi_toggle_pool_status', 'eipsi_ajax_toggle_pool_status');

/**
 * Handler AJAX: obtener analytics detallados de un pool.
 *
 * @since 2.5.3 - Fase 5: Pool Hub Dashboard
 */
function eipsi_ajax_get_pool_analytics() { return EIPSI_Pool_Ajax_Adapter::eipsi_ajax_get_pool_analytics(); }
add_action('wp_ajax_eipsi_get_pool_analytics', 'eipsi_get_pool_analytics_dispatch');
function eipsi_get_pool_analytics_dispatch() { return EIPSI_Pool_Ajax_Adapter::eipsi_get_pool_analytics_dispatch(); }

/**
 * Handler AJAX: exportar asignaciones de pool a CSV.
 *
 * @since 2.5.3 - Fase 5: Pool Hub Dashboard
 */
function eipsi_ajax_export_pool_assignments() { return EIPSI_Pool_Ajax_Adapter::eipsi_ajax_export_pool_assignments(); }
add_action('wp_ajax_eipsi_export_pool_assignments', 'eipsi_export_pool_assignments_dispatch');
function eipsi_export_pool_assignments_dispatch() { return EIPSI_Pool_Ajax_Adapter::eipsi_export_pool_assignments_dispatch(); }

// =========================================================================
// HELPER FUNCTIONS FOR POOL AUTHENTICATION
// =========================================================================

/**
 * Verificar si un participante tiene un estudio activo asignado en un pool.
 *
 * @param int $participant_id ID del participante.
 * @param int $pool_id         ID del pool.
 * @return object|false Objeto con study_id y study_name, o false si no tiene.
 */
function eipsi_participant_has_active_pool_study( $participant_id, $pool_id ) { return EIPSI_Pool_Ajax_Adapter::eipsi_participant_has_active_pool_study($participant_id, $pool_id); }

/**
 * Generar magic link para un participante hacia un estudio.
 *
 * @param int $participant_id ID del participante.
 * @param int $study_id         ID del estudio.
 * @return string URL del magic link.
 */
function eipsi_generate_participant_magic_link( $participant_id, $study_id ) { return EIPSI_Pool_Ajax_Adapter::eipsi_generate_participant_magic_link($participant_id, $study_id); }

/**
 * Generar link al dashboard del pool para un participante.
 *
 * @param int $participant_id ID del participante.
 * @param int $pool_id         ID del pool.
 * @return string URL del dashboard del pool.
 */
function eipsi_generate_pool_dashboard_link( $participant_id, $pool_id ) { return EIPSI_Pool_Ajax_Adapter::eipsi_generate_pool_dashboard_link($participant_id, $pool_id); }

/**
 * Enviar email de confirmación para registro en pool.
 *
 * @param int    $participant_id ID del participante.
 * @param string $email            Email del participante.
 * @param int    $pool_id          ID del pool.
 * @param string $action           Acción a registrar ('sent' o 'resent').
 * @return bool True si se envió correctamente.
 */
function eipsi_send_pool_email_confirmation( $participant_id, $email, $pool_id, $action = 'sent' ) { return EIPSI_Pool_Ajax_Adapter::eipsi_send_pool_email_confirmation($participant_id, $email, $pool_id, $action); }

// ============================================================================
// AJAX: SOLICITUD DE ASIGNACIÓN DESDE DASHBOARD DEL POOL
// ============================================================================

/**
 * Handler AJAX: solicitar asignación de estudio desde el dashboard del pool.
 *
 * Este endpoint es llamado cuando un participante logueado y confirmado
 * hace clic en "Asignarme un estudio" desde el dashboard del pool.
 *
 * Inputs POST esperados:
 *   - nonce         : string (nonce de seguridad)
 *   - pool_id       : int
 *   - participant_id: int
 *
 * Respuesta JSON:
 *   { success: true, data: { 
 *       assignment_id: int,
 *       study_id: int,
 *       study_name: string,
 *       redirect_url: string,  // Magic link al estudio
 *       message: string
 *   }}
 *
 * @since 2.3.0
 */
function eipsi_ajax_request_pool_assignment() { return EIPSI_Pool_Ajax_Adapter::eipsi_ajax_request_pool_assignment(); }

add_action( 'wp_ajax_eipsi_request_pool_assignment', 'eipsi_ajax_request_pool_assignment' );
add_action( 'wp_ajax_nopriv_eipsi_request_pool_assignment', 'eipsi_ajax_request_pool_assignment' );
