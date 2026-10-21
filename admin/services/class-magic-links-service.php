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
require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/auth/class-magic-link-service.php';

class EIPSI_MagicLinksService {

    /**
     * Generate a magic link token for survey access
     *
     * @param int $survey_id Survey ID from wp_survey_studies (longitudinal study)
     * @param int $participant_id Participant ID from wp_survey_participants
     * @return string|false Token plain text (UUID4) or false on failure
     */
    public static function generate_magic_link($survey_id, $participant_id) {
        return EIPSI_Magic_Link_Service::generate_magic_link($survey_id, $participant_id);
    }

    /**
     * Validate a magic link token
     *
     * @param string $token_plain The plain token from URL
     * @return array Validation result with status and data
     */
    public static function validate_magic_link($token_plain) {
        return EIPSI_Magic_Link_Service::validate_magic_link($token_plain);
    }

    /**
     * Mark a magic link as used
     *
     * @param int $ml_id Magic link ID
     * @return bool Success status
     */
    public static function mark_magic_link_used($ml_id) {
        return EIPSI_Magic_Link_Service::mark_magic_link_used($ml_id);
    }

    /**
     * Get magic link record by token hash
     *
     * @param string $token_hash SHA256 hash of the token
     * @return object|null Magic link record or null
     */
    public static function get_magic_link_by_token($token_hash) {
        return EIPSI_Magic_Link_Service::get_magic_link_by_token($token_hash);
    }

    /**
     * Clean up expired magic links (optional maintenance function)
     *
     * @return int Number of deleted rows
     */
    public static function cleanup_expired_magic_links() {
        return EIPSI_Magic_Link_Service::cleanup_expired_magic_links();
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

}
