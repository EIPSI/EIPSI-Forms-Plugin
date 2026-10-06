<?php
/**
 * EIPSI Forms - Pool Helpers
 *
 * Funciones auxiliares para el sistema Pool de Estudios
 * - Detección de tipo de acceso (pool vs study individual)
 * - Validación de pools
 * - Helpers para redirección y manejo de asignaciones
 *
 * @package EIPSI_Forms
 * @since 2.5.4
 */

if (!defined('ABSPATH')) {
    exit;
}
require_once EIPSI_FORMS_PLUGIN_DIR.'includes/pools/bootstrap.php';


/**
 * Detectar tipo de recurso desde URL o parámetro
 *
 * @return array ['type' => 'pool'|'study'|'unknown', 'code' => string]
 */
function eipsi_detect_access_type() { return EIPSI_Pool_Service::eipsi_detect_access_type(); }

/**
 * Verificar si un código es un pool válido y activo
 *
 * @param string $pool_code Código del pool
 * @return array|false Datos del pool o false si no existe/no está activo
 */
function eipsi_get_valid_pool($pool_code) { return EIPSI_Pool_Service::eipsi_get_valid_pool($pool_code); }

/**
 * Verificar si un participante ya tiene asignación en un pool
 *
 * @param string $pool_code Código del pool
 * @param string $participant_id ID del participante (email o ID)
 * @return array|false Datos de la asignación o false
 */
function eipsi_get_pool_assignment($pool_code, $participant_id) { return EIPSI_Pool_Service::eipsi_get_pool_assignment($pool_code, $participant_id); }

/**
 * Generar URL de acceso al pool
 *
 * @param string $pool_code Código del pool
 * @return string URL completa
 */
function eipsi_get_pool_url($pool_code) { return EIPSI_Pool_Service::eipsi_get_pool_url($pool_code); }

/**
 * Generar URL de estudio individual
 *
 * @param string $study_code Código del estudio
 * @return string URL completa
 */
function eipsi_get_study_url($study_code) { return EIPSI_Pool_Service::eipsi_get_study_url($study_code); }

/**
 * Obtener o crear ID de participante desde cookie/session
 *
 * @return string ID del participante
 */
function eipsi_get_participant_id() { return EIPSI_Pool_Service::eipsi_get_participant_id(); }

/**
 * Crear nueva cookie de participante
 *
 * @param string $participant_id ID a guardar
 * @return bool
 */
function eipsi_set_participant_cookie($participant_id) { return EIPSI_Pool_Service::eipsi_set_participant_cookie($participant_id); }

/**
 * Renderizar página de acceso al pool (interfaz minimalista)
 *
 * @param string $pool_code Código del pool
 * @param array $pool_data Datos del pool
 * @return void
 */
function eipsi_render_pool_access_page($pool_code, $pool_data) { return EIPSI_Pool_Service::eipsi_render_pool_access_page($pool_code, $pool_data); }

/**
 * ============================================================================
 * FASE 2 - ALEATORIZACIÓN SIMPLE EQUIPROBABLE
 * ============================================================================
 *
 * Algoritmo de asignación aleatoria simple equiprobable para pools.
 * Cada estudio disponible tiene la misma probabilidad de ser asignado.
 *
 * @since 2.5.4
 */

/**
 * Realizar aleatorización simple equiprobable
 *
 * @param string $pool_code Código del pool
 * @param string $participant_id ID del participante
 * @param array $pool_data Datos del pool (con estudios y probabilidades)
 * @return array|false Estudio asignado o false si error/pool saturado
 */
function eipsi_pool_randomize($pool_code, $participant_id, $pool_data) { return EIPSI_Pool_Service::eipsi_pool_randomize($pool_code, $participant_id, $pool_data); }

/**
 * Guardar asignación de pool en la base de datos
 *
 * @param array $assignment Datos de la asignación
 * @return int|false ID de la asignación o false si error
 */
function eipsi_save_pool_assignment($assignment) { return EIPSI_Pool_Service::eipsi_save_pool_assignment($assignment); }

/**
 * Incrementar contador de participantes para un estudio en el pool
 *
 * @param string $pool_code Código del pool
 * @param string $study_id ID del estudio
 * @return bool
 */
function eipsi_increment_study_count($pool_code, $study_id) { return EIPSI_Pool_Service::eipsi_increment_study_count($pool_code, $study_id); }

/**
 * Renderizar página de asignación exitosa (página de transición)
 *
 * @param string $pool_code Código del pool
 * @param string $participant_id ID del participante
 * @param array $assignment Datos de la asignación
 * @return void
 */
function eipsi_render_pool_assigned_page($pool_code, $participant_id, $assignment) { return EIPSI_Pool_Service::eipsi_render_pool_assigned_page($pool_code, $participant_id, $assignment); }

/**
 * ============================================================================
 * FASE 3 - DASHBOARD DE ADMINISTRACIÓN DEL POOL
 * ============================================================================
 *
 * Funciones para el panel de control del investigador:
 * - Estadísticas de asignaciones
 * - Distribución por estudio
 * - Exportación CSV
 * - Acciones (pausar, cerrar)
 *
 * @since 2.5.4
 */

/**
 * Obtener estadísticas de un pool
 *
 * @param string $pool_code Código del pool
 * @return array Estadísticas del pool
 */
function eipsi_get_pool_stats($pool_code) { return EIPSI_Pool_Service::eipsi_get_pool_stats($pool_code); }

/**
 * Exportar asignaciones de un pool a CSV
 *
 * @param string $pool_code Código del pool
 * @return string Contenido CSV
 */
function eipsi_export_pool_assignments_csv($pool_code) { return EIPSI_Pool_Service::eipsi_export_pool_assignments_csv($pool_code); }

/**
 * Cambiar estado de un pool
 *
 * @param string $pool_code Código del pool
 * @param string $new_status Nuevo estado (active, paused, closed)
 * @return bool
 */
function eipsi_change_pool_status($pool_code, $new_status) { return EIPSI_Pool_Service::eipsi_change_pool_status($pool_code, $new_status); }

/**
 * Pausar un pool (no acepta nuevas asignaciones)
 *
 * @param string $pool_code Código del pool
 * @return bool
 */
function eipsi_pause_pool($pool_code) { return EIPSI_Pool_Service::eipsi_pause_pool($pool_code); }

/**
 * Cerrar un pool (definitivo)
 *
 * @param string $pool_code Código del pool
 * @return bool
 */
function eipsi_close_pool($pool_code) { return EIPSI_Pool_Service::eipsi_close_pool($pool_code); }

/**
 * Reactivar un pool pausado
 *
 * @param string $pool_code Código del pool
 * @return bool
 */
function eipsi_activate_pool($pool_code) { return EIPSI_Pool_Service::eipsi_activate_pool($pool_code); }
