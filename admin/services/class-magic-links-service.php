<?php
/**
 * EIPSI Forms Magic Links Service
 * Handles magic link token generation, validation, and management
 *
 * @package EIPSI_Forms
 * @since 1.4.1
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Magic Links Service Class
 * Provides secure token generation and validation for survey access
 */
class EIPSI_MagicLinksService {

    /**
     * Generate a magic link token for survey access
     *
     * @param int $survey_id Survey ID from wp_survey_studies (longitudinal study)
     * @param int $participant_id Participant ID from wp_survey_participants
     * @return string|false Token plain text (UUID4) or false on failure
     */
    public static function generate_magic_link($survey_id, $participant_id) {
        global $wpdb;

        // Validate inputs
        $survey_id = intval($survey_id);
        $participant_id = intval($participant_id);

        if ($survey_id <= 0 || $participant_id <= 0) {
            error_log('[EIPSI MagicLinksService] Invalid survey_id or participant_id: survey_id=' . $survey_id . ', participant_id=' . $participant_id);
            return false;
        }

        // Check if participant exists
        $participant_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}survey_participants WHERE id = %d",
            $participant_id
        ));

        if (!$participant_exists) {
            // SISTEMA DE DIAGNÓSTICO: Detectar si se pasó assignment_id en lugar de participant_id
            $diagnostic_info = self::diagnose_participant_id_issue($participant_id, $survey_id);
            error_log(sprintf(
                '[EIPSI MagicLinksService] Participant not found: %d. Diagnóstico: %s',
                $participant_id,
                $diagnostic_info['message']
            ));
            
            // Si detectamos el participant_id correcto, usarlo
            if ($diagnostic_info['correct_participant_id']) {
                error_log(sprintf(
                    '[EIPSI MagicLinksService] AUTO-CORRECCIÓN: Usando participant_id=%d en lugar de %d',
                    $diagnostic_info['correct_participant_id'],
                    $participant_id
                ));
                $participant_id = $diagnostic_info['correct_participant_id'];
                $participant_exists = true;
            } else {
                return false;
            }
        }

        // Validate survey_id exists in wp_survey_studies (longitudinal study table)
        // survey_id references survey_studies.id, NOT wp_posts.ID
        $study_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}survey_studies WHERE id = %d",
            $survey_id
        ));

        if (!$study_exists) {
            error_log('[EIPSI MagicLinksService] Study not found in survey_studies for survey_id: ' . $survey_id);
            return false;
        }

        // Delete any existing unused tokens for this participant in this survey
        $deleted = $wpdb->delete(
            "{$wpdb->prefix}survey_magic_links",
            array(
                'survey_id' => $survey_id,
                'participant_id' => $participant_id,
                'used_at' => null
            ),
            array('%d', '%d', '%s')
        );

        if ($deleted !== false && $deleted > 0) {
            error_log('[EIPSI MagicLinksService] Deleted ' . $deleted . ' old unused tokens for participant ' . $participant_id);
        }

        // Generate UUID4 token
        $token_plain = wp_generate_uuid4();
        $token_hash = hash('sha256', $token_plain);

        // Calculate expiration (48 hours from now)
        $expires_at = current_time('mysql', 1); // GMT
        $expires_at = date('Y-m-d H:i:s', strtotime($expires_at . ' +48 hours'));

        // Insert into database
        $inserted = $wpdb->insert(
            "{$wpdb->prefix}survey_magic_links",
            array(
                'survey_id' => $survey_id,
                'participant_id' => $participant_id,
                'token_hash' => $token_hash,
                'token_plain' => $token_plain, // For debugging, will be removed in production
                'expires_at' => $expires_at,
                'used_at' => null,
                'created_at' => current_time('mysql')
            ),
            array('%d', '%d', '%s', '%s', '%s', '%s', '%s')
        );

        if ($inserted === false) {
            error_log('[EIPSI MagicLinksService] Failed to insert magic link: ' . $wpdb->last_error);
            return false;
        }

        $ml_id = $wpdb->insert_id;
        error_log('[EIPSI MagicLinksService] Generated magic link ID ' . $ml_id . ' for participant ' . $participant_id);

        return $token_plain;
    }

    /**
     * Validate a magic link token
     *
     * @param string $token_plain The plain token from URL
     * @return array Validation result with status and data
     */
    public static function validate_magic_link($token_plain) {
        global $wpdb;

        if (empty($token_plain)) {
            return array('valid' => false, 'reason' => 'empty_token');
        }

        // Hash the token for lookup
        $token_hash = hash('sha256', $token_plain);

        // Query the database
        $magic_link = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}survey_magic_links WHERE token_hash = %s",
            $token_hash
        ));

        if (!$magic_link) {
            return array('valid' => false, 'reason' => 'not_found');
        }

        // Check if already used
        if ($magic_link->used_at !== null) {
            return array(
                'valid' => false,
                'reason' => 'already_used',
                'used_at' => $magic_link->used_at
            );
        }

        // Check expiration
        $now = current_time('mysql', 1); // GMT
        if ($magic_link->expires_at < $now) {
            return array(
                'valid' => false,
                'reason' => 'expired',
                'expired_at' => $magic_link->expires_at
            );
        }

        if (!class_exists('EIPSI_Auth_Service')) {
            require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-auth-service.php';
        }
        $access = EIPSI_Auth_Service::authorize_participant($magic_link->participant_id, $magic_link->survey_id);
        if (!$access['success']) {
            return array('valid' => false, 'reason' => $access['error']);
        }

        // Valid token
        return array(
            'valid' => true,
            'ml_id' => $magic_link->id,
            'survey_id' => $magic_link->survey_id,
            'participant_id' => $magic_link->participant_id,
            'expires_at' => $magic_link->expires_at
        );
    }

    /**
     * Mark a magic link as used
     *
     * @param int $ml_id Magic link ID
     * @return bool Success status
     */
    public static function mark_magic_link_used($ml_id) {
        global $wpdb;

        $ml_id = intval($ml_id);

        if ($ml_id <= 0) {
            error_log('[EIPSI MagicLinksService] Invalid ml_id: ' . $ml_id);
            return false;
        }

        $updated = $wpdb->update(
            "{$wpdb->prefix}survey_magic_links",
            array('used_at' => current_time('mysql')),
            array('id' => $ml_id, 'used_at' => null),
            array('%s'),
            array('%d', '%s')
        );

        if ($updated === false) {
            error_log('[EIPSI MagicLinksService] Failed to mark magic link as used: ' . $wpdb->last_error);
            return false;
        }

        if ($updated === 0) {
            error_log('[EIPSI MagicLinksService] Magic link already used or not found: ' . $ml_id);
            return false;
        }

        error_log('[EIPSI MagicLinksService] Marked magic link ' . $ml_id . ' as used');
        return true;
    }

    /**
     * Get magic link record by token hash
     *
     * @param string $token_hash SHA256 hash of the token
     * @return object|null Magic link record or null
     */
    public static function get_magic_link_by_token($token_hash) {
        global $wpdb;

        if (empty($token_hash)) {
            return null;
        }

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}survey_magic_links WHERE token_hash = %s",
            $token_hash
        ));
    }

    /**
     * Clean up expired magic links (optional maintenance function)
     *
     * @return int Number of deleted rows
     */
    public static function cleanup_expired_magic_links() {
        global $wpdb;

        $now = current_time('mysql', 1); // GMT

        $deleted = $wpdb->delete(
            "{$wpdb->prefix}survey_magic_links",
            array(
                'expires_at' => $now,
                'used_at' => null
            ),
            array('%s', '%s')
        );

        if ($deleted > 0) {
            error_log('[EIPSI MagicLinksService] Cleaned up ' . $deleted . ' expired magic links');
        }

        return $deleted;
    }

    /**
     * Generate magic link and auto-create WordPress page with study shortcode
     *
     * Creates a WordPress page automatically when generating magic links for a study.
     * Checks for existing pages to avoid duplicates.
     *
     * @param int    $survey_id Survey post ID
     * @param int    $participant_id Participant ID
     * @param string $study_code Study code (e.g., "STUDY_2025")
     * @param string $study_name Study name
     * @return array { success: bool, token: string|false, page_url: string|null, error: string|null }
     */
    public static function generate_and_create_page($survey_id, $participant_id, $study_code, $study_name = '') {
        global $wpdb;

        // Validate inputs
        $survey_id = intval($survey_id);
        $participant_id = intval($participant_id);
        $study_code = sanitize_title($study_code); // Sanitize for URL use

        if ($survey_id <= 0 || $participant_id <= 0 || empty($study_code)) {
            error_log('[EIPSI MagicLinksService] Invalid parameters for generate_and_create_page');
            return array(
                'success' => false,
                'token' => false,
                'page_url' => null,
                'error' => 'invalid_parameters'
            );
        }

        // Check if page already exists for this study
        $existing_page = get_page_by_path('study-' . $study_code);

        if (!$existing_page) {
            // Check by meta field as well
            $existing_pages = get_posts(array(
                'post_type' => 'page',
                'meta_key' => 'eipsi_study_code',
                'meta_value' => $study_code,
                'posts_per_page' => 1
            ));

            if (!empty($existing_pages)) {
                $existing_page = $existing_pages[0];
            }
        }

        $page_url = '';

        if ($existing_page) {
            // Page exists, use it
            $page_url = get_permalink($existing_page->ID);
            error_log('[EIPSI MagicLinksService] Using existing page for study ' . $study_code . ': ' . $page_url);
        } else {
            // Create new page
            $page_title = !empty($study_name) ? sprintf(__('Estudio: %s', 'eipsi-forms'), $study_name) : __('Estudio', 'eipsi-forms');
            $page_slug = 'study-' . $study_code;
            $page_content = '[eipsi_longitudinal_study study_code="' . esc_attr($study_code) . '"]';

            $page_id = wp_insert_post(array(
                'post_title' => $page_title,
                'post_name' => $page_slug,
                'post_content' => $page_content,
                'post_status' => 'publish',
                'post_type' => 'page',
                'meta_input' => array(
                    'eipsi_study_code' => $study_code,
                    'eipsi_survey_id' => $survey_id
                )
            ));

            if (is_wp_error($page_id)) {
                error_log('[EIPSI MagicLinksService] Failed to create page: ' . $page_id->get_error_message());
                // Continue anyway, generate magic link without page
            } else {
                $page_url = get_permalink($page_id);
                error_log('[EIPSI MagicLinksService] Created page for study ' . $study_code . ': ' . $page_url);
            }
        }

        // Generate magic link token
        $token = self::generate_magic_link($survey_id, $participant_id);

        if (!$token) {
            return array(
                'success' => false,
                'token' => false,
                'page_url' => null,
                'error' => 'token_generation_failed'
            );
        }

        return array(
            'success' => true,
            'token' => $token,
            'page_url' => $page_url,
            'error' => null
        );
    }

    /**
     * SISTEMA DE DIAGNÓSTICO: Detectar cuando se pasa assignment_id en lugar de participant_id
     * 
     * Este sistema detecta y auto-corrige el bug donde el código pasa assignment.id 
     * en lugar de assignment.participant_id al generar magic links.
     * 
     * @param int $wrong_id El ID que se intentó usar (probablemente assignment_id)
     * @param int $study_id El ID del estudio
     * @return array Diagnóstico con mensaje y participant_id correcto si se detectó
     */
    private static function diagnose_participant_id_issue($wrong_id, $study_id) {
        global $wpdb;
        
        $result = array(
            'message' => 'ID no encontrado en ninguna tabla',
            'correct_participant_id' => null,
            'issue_type' => 'unknown'
        );
        
        // Verificar si el ID es un assignment_id válido
        $assignment_data = $wpdb->get_row($wpdb->prepare(
            "SELECT id, participant_id, study_id, wave_id, status 
             FROM {$wpdb->prefix}survey_assignments 
             WHERE id = %d",
            $wrong_id
        ));
        
        if ($assignment_data) {
            $result['issue_type'] = 'assignment_id_passed';
            $result['correct_participant_id'] = intval($assignment_data->participant_id);
            $result['message'] = sprintf(
                'Se pasó assignment_id=%d en lugar de participant_id=%d. Assignment: study_id=%d, wave_id=%d, status=%s',
                $wrong_id,
                $assignment_data->participant_id,
                $assignment_data->study_id,
                $assignment_data->wave_id,
                $assignment_data->status
            );
            return $result;
        }
        
        // Verificar si es un wave_id (otro error común)
        $wave_data = $wpdb->get_row($wpdb->prepare(
            "SELECT id, study_id, name, wave_index 
             FROM {$wpdb->prefix}survey_waves 
             WHERE id = %d",
            $wrong_id
        ));
        
        if ($wave_data) {
            $result['issue_type'] = 'wave_id_passed';
            $result['message'] = sprintf(
                'Se pasó wave_id=%d (study_id=%d, name=%s) en lugar de participant_id',
                $wrong_id,
                $wave_data->study_id,
                $wave_data->name
            );
            return $result;
        }
        
        // Verificar si el participante existe pero fue borrado lógicamente
        $deleted_participant = $wpdb->get_row($wpdb->prepare(
            "SELECT id, email, is_active, deleted_at 
             FROM {$wpdb->prefix}survey_participants 
             WHERE id = %d AND (is_active = 0 OR deleted_at IS NOT NULL)",
            $wrong_id
        ));
        
        if ($deleted_participant) {
            $result['issue_type'] = 'participant_inactive';
            $result['message'] = sprintf(
                'Participante %d existe pero está inactivo/borrado: email=%s, is_active=%d',
                $wrong_id,
                $deleted_participant->email,
                $deleted_participant->is_active
            );
            return $result;
        }
        
        // Verificar si el ID es de otra tabla
        $tables_to_check = array(
            'survey_studies' => 'study',
            'survey_responses' => 'response',
            'survey_email_log' => 'email_log',
            'survey_magic_links' => 'magic_link'
        );
        
        foreach ($tables_to_check as $table => $type) {
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}{$table} WHERE id = %d",
                $wrong_id
            ));
            if ($exists) {
                $result['issue_type'] = 'wrong_table_id';
                $result['message'] = sprintf(
                    'Se pasó un %s_id=%d en lugar de participant_id',
                    $type,
                    $wrong_id
                );
                return $result;
            }
        }
        
        // El ID no existe en ninguna tabla conocida
        $result['message'] = sprintf(
            'ID=%d no existe en ninguna tabla del sistema (ni participants, assignments, waves, studies, etc.)',
            $wrong_id
        );
        
        return $result;
    }
}
