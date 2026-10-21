<?php
/**
 * EIPSI_Email_Service
 *
 * Servicio de emails transaccionales para estudios longitudinales:
 * - Templates HTML
 * - Magic links
 * - Logging
 * - Rate limiting
 *
 * @package EIPSI_Forms
 * @subpackage Services
 * @version 1.4.1
 * @since 1.4.0
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/notifications/bootstrap.php';


class EIPSI_Email_Service {

    /**
     * Generate magic link URL.
     *
     * Uses the study page URL if available, otherwise falls back to site_url.
     *
     * @param int $survey_id Survey ID.
     * @param int $participant_id Participant ID.
     * @return string|false Full URL or false on failure.
     * @since 1.4.1
     * @access public
     */
    public static function generate_magic_link_url($survey_id, $participant_id) {
        return EIPSI_Notification_Email_Message_Service::generate_magic_link_url($survey_id, $participant_id);
    }



    /**
     * Send magic link email on demand.
     *
     * Template: includes/emails/magic-link.php
     *
     * @param int    $survey_id Survey ID.
     * @param int    $participant_id Participant ID.
     * @param string $custom_message Optional custom message.
     * @return array {success: bool, magic_link: string|null, error: string|null}
     * @since 1.5.3
     * @access public
     */
    public static function send_magic_link_email($survey_id, $participant_id, $custom_message = '') {
        return EIPSI_Notification_Email_Message_Service::send_magic_link_email($survey_id, $participant_id, $custom_message);
    }

    /**
     * Get magic link email preview without sending.
     *
     * @param int $survey_id Survey ID.
     * @param int $participant_id Participant ID.
     * @return array Preview data.
     * @since 1.7.1
     * @access public
     */
    public static function get_magic_link_preview($survey_id, $participant_id) {
        return EIPSI_Notification_Email_Message_Service::get_magic_link_preview($survey_id, $participant_id);
    }

    /**
     * Extend magic link expiry without invalidating the token.
     *
     * @param int $survey_id Survey ID.
     * @param int $participant_id Participant ID.
     * @param int $hours Extra hours to add.
     * @return array Result with status and new expiry.
     * @since 1.7.1
     * @access public
     */
    public static function extend_magic_link_expiry($survey_id, $participant_id, $hours = 48) {
        return EIPSI_Notification_Email_Message_Service::extend_magic_link_expiry($survey_id, $participant_id, $hours);
    }

    /**
     * Send welcome email con magic link.
     *
     * Template: includes/emails/welcome.php
     *
     * @param int $survey_id Survey ID.
     * @param int $participant_id Participant ID.
     * @return bool True si enviado, false si error.
     * @since 1.4.1
     * @access public
     */
    public static function send_welcome_email($survey_id, $participant_id) {
        return EIPSI_Notification_Email_Message_Service::send_welcome_email($survey_id, $participant_id);
    }

    /**
     * Send email confirmation request (double opt-in).
     *
     * Template: includes/emails/email-confirmation.php
     *
     * @param int    $survey_id Survey ID.
     * @param int    $participant_id Participant ID.
     * @param string $confirmation_token Confirmation token.
     * @return bool True if sent, false on error.
     * @since 1.5.0
     * @access public
     */
    public static function send_confirmation_email($survey_id, $participant_id, $confirmation_token) {
        return EIPSI_Notification_Email_Message_Service::send_confirmation_email($survey_id, $participant_id, $confirmation_token);
    }

    /**
     * Get confirmation email preview without sending.
     *
     * @param int $survey_id Survey ID.
     * @param int $participant_id Participant ID.
     * @return array Preview data.
     * @since 1.5.0
     * @access public
     */
    public static function get_confirmation_email_preview($survey_id, $participant_id) {
        return EIPSI_Notification_Email_Message_Service::get_confirmation_email_preview($survey_id, $participant_id);
    }

    /**
     * Send magic link after email confirmation.
     *
     * This is called when participant confirms their email.
     *
     * @param int    $survey_id Survey ID.
     * @param int    $participant_id Participant ID.
     * @return bool True if sent, false on error.
     * @since 1.5.0
     * @access public
     */
    public static function send_welcome_after_confirmation($survey_id, $participant_id) {
        return EIPSI_Notification_Email_Message_Service::send_welcome_after_confirmation($survey_id, $participant_id);
    }

    /**
     * Send wave reminder email (nudge system).
     *
     * Templates: includes/emails/wave-nudge-0.php to wave-nudge-4.php
     *
     * @param int        $survey_id Survey ID.
     * @param int        $participant_id Participant ID.
     * @param int|object $wave Wave object or ID.
     * @param int        $nudge_stage Nudge stage (0-4), default 0.
     * @return bool True si enviado, false si error.
     * @since 1.4.1
     * @access public
     */
    public static function send_wave_reminder_email($survey_id, $participant_id, $wave, $nudge_stage = 0) {
        return EIPSI_Notification_Email_Message_Service::send_wave_reminder_email($survey_id, $participant_id, $wave, $nudge_stage);
    }

    /**
     * Send wave confirmation email.
     *
     * Template: includes/emails/wave-confirmation.php
     *
     * @param int             $survey_id Survey ID.
     * @param int             $participant_id Participant ID.
     * @param int|object      $wave Wave completed.
     * @param int|object|null $next_wave Next wave object (optional).
     * @return bool True si enviado, false si error.
     * @since 1.4.1
     * @access public
     */
    public static function send_wave_confirmation_email($survey_id, $participant_id, $wave, $next_wave = null) {
        return EIPSI_Notification_Email_Message_Service::send_wave_confirmation_email($survey_id, $participant_id, $wave, $next_wave);
    }

    /**
     * Send dropout recovery email.
     *
     * Template: includes/emails/dropout-recovery.php
     *
     * @param int        $survey_id Survey ID.
     * @param int        $participant_id Participant ID.
     * @param int|object $wave Missed wave.
     * @return bool True si enviado, false si error.
     * @since 1.4.1
     * @access public
     */
    public static function send_dropout_recovery_email($survey_id, $participant_id, $wave) {
        return EIPSI_Notification_Email_Message_Service::send_dropout_recovery_email($survey_id, $participant_id, $wave);
    }













    /**
     * Send email and log result (helper).
     *
     * @param int    $survey_id Survey ID.
     * @param int    $participant_id Participant ID.
     * @param string $to Email recipient.
     * @param string $type Email type.
     * @param string $subject Email subject.
     * @param string $content Email HTML content.
     * @return bool True si enviado.
     * @since 1.4.1
     * @access public
     */
    public static function send_email($survey_id, $participant_id, $to, $type, $subject, $content, $metadata = array()) {
        return EIPSI_Notification_Email_Delivery_Service::send_email($survey_id, $participant_id, $to, $type, $subject, $content, $metadata);
    }

    /**
     * Log email sent/failed.
     *
     * @param int    $survey_id Survey ID.
     * @param int    $participant_id Participant ID.
     * @param string $type Type (welcome, reminder, confirmation, recovery).
     * @param string $status Status (sent, failed, pending).
     * @param string|null $error_message Optional error message.
     * @return void
     * @since 1.4.1
     * @access public
     */
    public static function log_email($survey_id, $participant_id, $type, $status, $error_message = null, $subject = '', $metadata = array()) {
        return EIPSI_Notification_Email_Log_Service::log_email($survey_id, $participant_id, $type, $status, $error_message, $subject, $metadata);
    }



    /**
     * Get email history for survey.
     *
     * @param int $survey_id Survey ID.
     * @param int $limit Límite de registros.
     * @return array Email logs.
     * @since 1.4.1
     * @access public
     */
    public static function get_email_history($survey_id, $limit = 100) {
        return EIPSI_Notification_Email_Log_Service::get_email_history($survey_id, $limit);
    }

    /**
     * Get email log entries with filters (dashboard).
     *
     * @param int   $survey_id ID del estudio (opcional).
     * @param array $filters Filtros {type, status, date_from, date_to}.
     * @param int   $limit Límite de resultados.
     * @param int   $offset Offset para paginación.
     * @return array {logs, total}
     * @since 1.4.1
     * @access public
     */
    public static function get_email_log_entries($survey_id = 0, $filters = array(), $limit = 20, $offset = 0) {
        return EIPSI_Notification_Email_Log_Service::get_email_log_entries($survey_id, $filters, $limit, $offset);
    }

    /**
     * Get email log details.
     *
     * @param int $email_log_id ID del log.
     * @return object|null Email log details.
     * @since 1.4.1
     * @access public
     */
    public static function get_email_details($email_log_id) {
        return EIPSI_Notification_Email_Log_Service::get_email_details($email_log_id);
    }

    /**
     * Resend failed email.
     *
     * @param int $email_log_id ID del log original.
     * @return array {success, message, new_log_id}
     * @since 1.4.1
     * @access public
     */
    public static function resend_email($email_log_id) {
        return EIPSI_Notification_Email_Message_Service::resend_email($email_log_id);
    }

    /**
     * Send manual reminders to participants.
     *
     * @param int   $survey_id Survey ID.
     * @param array $participant_ids Array of participant IDs.
     * @param int   $wave_id Wave ID (optional).
     * @param string $custom_message Custom message (optional).
     * @return array {sent_count, failed_count, total_count, errors}
     * @since 1.4.4
     * @access public
     */
    public static function send_manual_reminders($survey_id, $participant_ids, $wave_id = null, $custom_message = null) {
        return EIPSI_Notification_Email_Message_Service::send_manual_reminders($survey_id, $participant_ids, $wave_id, $custom_message);
    }



    /**
     * Resend specific email type to a participant.
     *
     * @param int    $participant_id Participant ID.
     * @param string $email_type Email type (welcome, reminder, magic_link, confirmation, recovery).
     * @param int    $survey_id Survey ID (optional, will be fetched if not provided).
     * @param int    $wave_id Wave ID (optional, required for reminder/confirmation).
     * @return array {success: bool, message: string, error: string|null}
     * @since 1.5.3
     * @access public
     */
    public static function resend_participant_email($participant_id, $email_type, $survey_id = 0, $wave_id = null) {
        return EIPSI_Notification_Email_Message_Service::resend_participant_email($participant_id, $email_type, $survey_id, $wave_id);
    }





    /**
     * Get participants who haven't completed a wave.
     *
     * @param int $survey_id Survey ID.
     * @param int $wave_id Wave ID.
     * @return array Participant IDs who haven't submitted.
     * @since 1.4.4
     * @access public
     */
    public static function get_pending_participants($survey_id, $wave_id) {
        return EIPSI_Notification_Email_Message_Service::get_pending_participants($survey_id, $wave_id);
    }

    /**
     * Send a test email to verify the email system is working.
     *
     * @param string|null $to Email address to send test to. If null, uses investigator or admin email.
     * @return array {success: bool, message: string, details: string}
     * @since 1.5.5
     * @access public
     */
    public static function send_test_email($to = null) {
        return EIPSI_Notification_Email_Message_Service::send_test_email($to);
    }

    /**
     * Diagnóstico básico del sistema de email
     *
     * @return array {status: string, issues: array, recommendations: array}
     * @since 1.5.4
     * @access public
     */
    public static function diagnose_email_system() {
        return EIPSI_Notification_Email_Message_Service::diagnose_email_system();
    }



    /**
     * Get email deliverability statistics.
     *
     * @return array Statistics about email delivery.
     * @since 1.5.5
     * @access public
     */
    public static function get_email_deliverability_stats() {
        return EIPSI_Notification_Email_Log_Service::get_email_deliverability_stats();
    }

    /**
     * Get wave email preview without sending.
     *
     * @param int    $survey_id Survey ID.
     * @param int    $wave_id Wave ID.
     * @param int    $participant_id Participant ID.
     * @param string $email_type Email type (reminder, welcome, confirmation, recovery, manual).
     * @return array Preview data.
     * @since 1.7.1
     * @access public
     */
    public static function get_wave_email_preview($survey_id, $wave_id, $participant_id, $email_type = 'reminder') {
        return EIPSI_Notification_Email_Message_Service::get_wave_email_preview($survey_id, $wave_id, $participant_id, $email_type);
    }

    /**
     * Get sample email preview with placeholder data.
     *
     * @param int    $survey_id Survey ID.
     * @param int    $wave_id Wave ID.
     * @param string $email_type Email type.
     * @return array Sample preview data.
     * @since 1.7.1
     * @access public
     */
    public static function get_sample_email_preview($survey_id, $wave_id, $email_type = 'reminder') {
        return EIPSI_Notification_Email_Message_Service::get_sample_email_preview($survey_id, $wave_id, $email_type);
    }
}
