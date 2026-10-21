<?php
if (!defined('ABSPATH')) {
    exit;
}

require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/export/bootstrap.php';


// Incluir la librería
if (!class_exists('\Shuchkin\SimpleXLSXGen')) {
    require_once EIPSI_FORMS_PLUGIN_DIR . 'lib/SimpleXLSXGen.php';
}
require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-export-service.php';

// Usar el namespace
use Shuchkin\SimpleXLSXGen;

function export_generate_stable_form_id($form_name) { return EIPSI_Raw_Export_Service::export_generate_stable_form_id($form_name); }

function export_get_form_initials($form_name) { return EIPSI_Raw_Export_Service::export_get_form_initials($form_name); }

function export_generateStableFingerprint($user_data) { return EIPSI_Raw_Export_Service::export_generateStableFingerprint($user_data); }

function export_normalizeName($name) { return EIPSI_Raw_Export_Service::export_normalizeName($name); }

/**
 * Sanitiza un valor de campo para exportación.
 * Elimina HTML, normaliza arrays y limpia whitespace.
 *
 * @param mixed $val Valor crudo del campo
 * @return string Valor limpio listo para exportar
 */
function eipsi_sanitize_export_value($val) { return EIPSI_Raw_Export_Service::eipsi_sanitize_export_value($val); }

function eipsi_export_to_excel() { return EIPSI_Raw_Export_Service::eipsi_export_to_excel(); }

function eipsi_export_to_csv() { return EIPSI_Raw_Export_Service::eipsi_export_to_csv(); }

// WordPress checks menu-page access before admin_init. The historical results
// slug is no longer a menu page; authenticate this exact file route first.
function eipsi_handle_legacy_participant_excel_request() {
    if (($_GET['page'] ?? '') === 'eipsi-results' &&
        ($_GET['action'] ?? '') === 'export_participants_excel') {
        eipsi_export_participants_to_excel();
    }
}
add_action('admin_menu', 'eipsi_handle_legacy_participant_excel_request', 0);

add_action('admin_init', function() {
    if (isset($_GET['page']) && $_GET['page'] === 'eipsi-results-experience') {
        if (isset($_GET['action']) && $_GET['action'] === 'export_excel') {
            eipsi_export_to_excel();
        } elseif (isset($_GET['action']) && $_GET['action'] === 'export_csv') {
            eipsi_export_to_csv();
        }
    }

    // Handle longitudinal export actions
    if (isset($_GET['page']) && $_GET['page'] === 'eipsi-export-longitudinal') {
        if (isset($_GET['action']) && $_GET['action'] === 'export_longitudinal_excel') {
            eipsi_export_longitudinal_to_excel();
        } elseif (isset($_GET['action']) && $_GET['action'] === 'export_longitudinal_csv') {
            eipsi_export_longitudinal_to_csv();
        }
    }

    // Handle participant export actions (new feature)
    if (isset($_GET['page']) && $_GET['page'] === 'eipsi-results') {
        if (isset($_GET['action']) && $_GET['action'] === 'export_participants_excel') {
            eipsi_export_participants_to_excel();
        } elseif (isset($_GET['action']) && $_GET['action'] === 'export_participants_csv') {
            eipsi_export_participants_to_csv();
        }
    }
});

function eipsi_export_longitudinal_to_excel() { return EIPSI_Raw_Export_Service::eipsi_export_longitudinal_to_excel(); }

function eipsi_export_longitudinal_to_csv() { return EIPSI_Raw_Export_Service::eipsi_export_longitudinal_to_csv(); }
/**
 * Export participants to Excel (.xlsx)
 *
 * Endpoint: admin.php?page=eipsi-results&action=export_participants_excel&study_id=N
 *
 * @since 1.8.0
 */
function eipsi_export_participants_to_excel() { return EIPSI_Raw_Export_Service::eipsi_export_participants_to_excel(); }

/**
 * Export participants to CSV (streaming, UTF-8 BOM for Excel)
 *
 * Endpoint: admin.php?page=eipsi-results&action=export_participants_csv&study_id=N
 *
 * @since 1.8.0
 */
function eipsi_export_participants_to_csv() { return EIPSI_Raw_Export_Service::eipsi_export_participants_to_csv(); }

/**
 * Helper: get study title safely.
 *
 * @param int $study_id
 * @return string
 */
function eipsi_get_study_title($study_id) { return EIPSI_Raw_Export_Service::eipsi_get_study_title($study_id); }

/**
 * ============================================================================
 * FASE 4 - EXPORTACIÓN CON CONTEXTO DE POOL
 * ============================================================================
 *
 * Incluye pool_code y pool_assignment info en las exportaciones de respuestas.
 * El investigador puede ver de qué pool vino cada participante.
 *
 * @since 2.5.4
 */

/**
 * Obtener pool assignments para un estudio específico
 *
 * @param string $study_id ID del estudio
 * @return array Array indexado por participant_id con info del pool
 */
function eipsi_get_pool_context_for_study($study_id) { return EIPSI_Raw_Export_Service::eipsi_get_pool_context_for_study($study_id); }

/**
 * Exportar respuestas con contexto de pool (FASE 5)
 *
 * Versión completa con LEFT JOIN que incluye pool_code y pool_assigned_at
 * directamente en la query SQL para máxima eficiencia.
 *
 * @param string $form_id ID del formulario
 * @param string $study_id ID del estudio longitudinal
 * @return array Array de objetos con respuestas + contexto de pool
 */
function eipsi_export_responses_with_pool_context($form_id, $study_id) { return EIPSI_Export_Query_Service::eipsi_export_responses_with_pool_context($form_id, $study_id); }

/**
 * Generar CSV de exportación con contexto de pool (FASE 5 - Helper para UI)
 *
 * @param string $form_id ID del formulario
 * @param string $study_id ID del estudio
 * @return string Contenido CSV
 */
function eipsi_generate_pool_context_csv($form_id, $study_id) { return EIPSI_Raw_Export_Service::eipsi_generate_pool_context_csv($form_id, $study_id); }
