<?php
/**
 * EIPSI_Participant_Service
 *
 * Gestiona participantes y su ciclo de vida en estudios longitudinales:
 * - CRUD de participantes
 * - Status tracking (active/inactive)
 * - Password management
 *
 * @package EIPSI_Forms
 * @subpackage Services
 * @version 1.4.2
 * @since 1.4.0
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/participants/class-participant-repository.php';
require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/participants/class-participant-registration-service.php';
require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/participants/class-participant-password-service.php';
require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/participants/class-participant-state-service.php';

require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/participants/class-participant-import-service.php';

class EIPSI_Participant_Service {
    
    /**
     * Create participant for survey.
     *
     * Valida email, password (opcional) y crea registro en wp_survey_participants.
     *
     * @param int    $survey_id ID del survey.
     * @param string $email Email del participante (será sanitizado).
     * @param string|null $password Password en texto plano (opcional, null para passwordless).
     * @param array  $metadata Datos adicionales (first_name, last_name).
     * @return array { success: bool, participant_id: int|null, error: string|null }
     * @since 1.4.0
     * @access public
     */
    public static function create_participant($survey_id, $email, $password = null, $metadata = array()) {
        return EIPSI_Participant_Registration_Service::create_participant($survey_id, $email, $password, $metadata);
    }

    /**
     * Create participant with explicit active status.
     *
     * This is used for double opt-in flow where participant starts as inactive.
     *
     * @param int         $survey_id ID del survey.
     * @param string      $email Email del participante.
     * @param string|null $password Password en texto plano (opcional).
     * @param array       $metadata Datos adicionales (first_name, last_name).
     * @param bool        $is_active Initial active status (default true).
     * @return array { success: bool, participant_id: int|null, error: string|null }
     * @since 1.5.7
     * @access public
     */
    public static function create_participant_with_status($survey_id, $email, $password = null, $metadata = array(), $is_active = true) {
        return EIPSI_Participant_Registration_Service::create_participant_with_status($survey_id, $email, $password, $metadata, $is_active);
    }
    
    /**
     * Get participant by email.
     *
     * @param int    $survey_id ID del survey.
     * @param string $email Email del participante.
     * @return object|null Fila de wp_survey_participants.
     * @since 1.4.0
     * @access public
     */
    public static function get_by_email($survey_id, $email) {
        return EIPSI_Participant_Repository::get_by_email($survey_id, $email);
    }
    
    /**
     * Get participant by ID.
     *
     * @param int $participant_id ID del participante.
     * @return object|null Participante o null si no existe.
     * @since 1.4.0
     * @access public
     */
    public static function get_by_id($participant_id) {
        return EIPSI_Participant_Repository::get_by_id($participant_id);
    }
    
    /**
     * Verify participant password.
     *
     * @param int    $participant_id ID del participante.
     * @param string $plain_password Password en texto plano.
     * @return bool True si el password es válido.
     * @since 1.4.0
     * @access public
     */
    public static function verify_password($participant_id, $plain_password) {
        return EIPSI_Participant_Password_Service::verify_password($participant_id, $plain_password);
    }
    
    /**
     * Update last login timestamp.
     *
     * @param int $participant_id ID del participante.
     * @return bool True si actualizó correctamente.
     * @since 1.4.0
     * @access public
     */
    public static function update_last_login($participant_id) {
        return EIPSI_Participant_Repository::update_last_login($participant_id);
    }
    
    /**
     * Set active/inactive participant status.
     *
     * @param int  $participant_id ID del participante.
     * @param bool $is_active Estado (true = activo, false = inactivo).
     * @return bool True si se actualizó correctamente.
     * @since 1.4.0
     * @access public
     */
    public static function set_active($participant_id, $is_active) {
        return EIPSI_Participant_State_Service::set_active($participant_id, $is_active);
    }
    
    /**
     * Change participant password.
     *
     * @param int    $participant_id ID del participante.
     * @param string $password_old Password actual.
     * @param string $password_new Password nuevo.
     * @return array { success: bool, error: string|null }
     * @since 1.4.0
     * @access public
     */
    public static function change_password($participant_id, $password_old, $password_new) {
        return EIPSI_Participant_Password_Service::change_password($participant_id, $password_old, $password_new);
    }
    
    /**
     * List participants with pagination and filters.
     *
     * @param int   $survey_id ID del survey.
     * @param int   $page Página (default 1).
     * @param int   $per_page Registros por página (default 50).
     * @param array $filters Filtros: status, search.
     * @return array { total, participants, page, per_page, pages }
     * @since 1.4.0
     * @access public
     */
    public static function list_participants($survey_id, $page = 1, $per_page = 50, $filters = array()) {
        return EIPSI_Participant_Repository::list_participants($survey_id, $page, $per_page, $filters);
    }

    /**
     * Get participant's wave completion history.
     *
     * @param int $participant_id ID del participante.
     * @param int $study_id ID del estudio.
     * @return array Array de completaciones de wave.
     * @since 1.6.0
     * @access public
     */
    public static function get_wave_completions($participant_id, $study_id) {
        global $wpdb;
        
        $query = "
            SELECT 
                a.id as assignment_id,
                a.wave_id,
                a.status,
                a.started_at,
                a.completed_at,
                a.submitted_at,
                w.name as wave_name,
                w.wave_index,
                f.post_title as form_title
            FROM {$wpdb->prefix}survey_assignments a
            LEFT JOIN {$wpdb->prefix}survey_waves w ON a.wave_id = w.id
            LEFT JOIN {$wpdb->prefix}posts f ON w.form_id = f.ID
            WHERE a.participant_id = %d AND a.study_id = %d
            ORDER BY w.wave_index ASC
        ";
        
        return $wpdb->get_results($wpdb->prepare($query, $participant_id, $study_id));
    }

    /**
     * Get participant's magic link history.
     *
     * @param int $participant_id ID del participante.
     * @return array Array de magic links.
     * @since 1.6.0
     * @access public
     */
    public static function get_magic_link_history($participant_id) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'survey_magic_links';
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name WHERE participant_id = %d ORDER BY created_at DESC LIMIT 20",
            $participant_id
        ));
    }

    /**
     * Check if participant has active session.
     *
     * @param int $participant_id ID del participante.
     * @return bool True if has active session.
     * @since 1.6.0
     * @access public
     */
    public static function has_active_session($participant_id) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'survey_sessions';
        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE participant_id = %d AND expires_at > NOW() LIMIT 1",
            $participant_id
        ));
        
        return !empty($session);
    }

    /**
     * Deactivate participant (soft delete).
     *
     * @param int $participant_id ID del participante.
     * @param string $reason Razón de desactivación.
     * @return bool True if success.
     * @since 1.6.0
     * @access public
     */
    public static function deactivate($participant_id, $reason = '') {
        return EIPSI_Participant_State_Service::deactivate($participant_id, $reason);
    }

    /**
     * Remove the local participant row and linked operational copies.
     * 
     * Preserves scrubbed responses and audit trails. Returns explicit coverage;
     * external storage and unlinked historical copies are not included.
     *
     * @param int    $participant_id ID del participante.
     * @param string $reason Razón de eliminación.
     * @return array { success: bool, deleted: array, anonymized: array, errors: array }
     * @since 1.6.0
     * @access public
     */
    public static function hard_delete($participant_id, $reason = '') {
        require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-participant-data-cleanup.php';
        return EIPSI_Participant_Data_Cleanup::run($participant_id, 'hard_delete', $reason);
    }

    /**
     * Create or get participant for magic link flow (email only).
     *
     * Este método permite crear un participante con solo email para el flujo
     * de magic links, sin requerir contraseña ni nombre/apellido.
     *
     * @param int    $survey_id ID del survey.
     * @param string $email Email del participante.
     * @return array { success: bool, participant_id: int|null, is_new: bool, error: string|null }
     * @since 1.7.0
     * @access public
     */
    public static function create_or_get_for_magic_link($survey_id, $email) {
        return EIPSI_Participant_Registration_Service::create_or_get_for_magic_link($survey_id, $email);
    }
}

/**
 * Log participant audit action.
 *
 * @param int    $participant_id ID del participante.
 * @param string $action Acción realizada.
 * @param string $reason Razón (opcional).
 * @since 1.6.0
 */
function eipsi_log_participant_audit($participant_id, $action, $reason = '') {
    global $wpdb;
    
    $current_user = wp_get_current_user();
    $user_id = $current_user->ID ?? 0;
    $user_name = $current_user->user_login ?? 'system';
    
    $wpdb->insert(
        $wpdb->prefix . 'survey_email_log',
        array(
            'survey_id' => 0,
            'participant_id' => $participant_id,
            'email_type' => 'audit_log',
            'recipient_email' => $user_name . '@admin',
            'subject' => 'Audit: ' . $action,
            'content' => json_encode(array(
                'action' => $action,
                'reason' => $reason,
                'performed_by' => $user_name,
                'user_id' => $user_id,
                'timestamp' => current_time('mysql')
            )),
            'status' => 'audit',
            'sent_at' => current_time('mysql')
        ),
        array('%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s')
    );
}
