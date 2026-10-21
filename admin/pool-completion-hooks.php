<?php
/**
 * EIPSI Forms - Pool Completion Hooks
 *
 * Fase 4 del roadmap "Pool de Estudios → Nivel Randomization".
 * Maneja el tracking de completitud de estudios en pools.
 *
 * @package EIPSI_Forms
 * @since 2.5.3
 */

if (!defined('ABSPATH')) {
    exit;
}
require_once EIPSI_FORMS_PLUGIN_DIR.'includes/pools/bootstrap.php';


// ============================================================================
// HOOK PRINCIPAL: Verificar completitud de pool al submit de formulario
// ============================================================================

/**
 * Handler para el hook 'eipsi_form_submitted'.
 *
 * Verifica si el participante completó todas las waves del estudio
 * y marca la asignación del pool como completada si corresponde.
 *
 * @param array $data Datos del submit: survey_id, participant_id, wave_index, form_id, insert_id.
 */
function eipsi_check_pool_completion_on_submit($data) { return EIPSI_Pool_Completion_Adapter::eipsi_check_pool_completion_on_submit($data); }
add_action('eipsi_form_submitted', 'eipsi_check_pool_completion_on_submit', 10, 1);

// ============================================================================
// NOTIFICACIÓN AL INVESTIGADOR (Webhook opcional)
// ============================================================================

/**
 * Notificar al investigador cuando un participante completa un estudio del pool.
 *
 * Solo envía notificación si el pool tiene 'notify_on_completion' habilitado en su config.
 *
 * @param array $data Datos de completitud: pool_id, participant_id, study_id, assignment_id, pool_name, form_id.
 */
function eipsi_notify_researcher_on_pool_completion($data) { return EIPSI_Pool_Completion_Adapter::eipsi_notify_researcher_on_pool_completion($data); }
add_action('eipsi_pool_study_completed', 'eipsi_notify_researcher_on_pool_completion', 10, 1);
