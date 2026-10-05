<?php
/** Notifications owner; public WordPress adapters retain existing contracts. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Notification_Email_Delivery_Service {

public static function send_email($survey_id, $participant_id, $to, $type, $subject, $content, $metadata = array()) {
        $headers = array('Content-Type: text/html; charset=UTF-8');

        try {
            // Check if SMTP is configured
            $smtp_service = class_exists('EIPSI_SMTP_Service') ? new EIPSI_SMTP_Service() : null;
            $smtp_config = $smtp_service ? $smtp_service->get_config() : null;

            if ($smtp_config) {
                // Use SMTP service
                error_log("[EIPSI Email] Sending via SMTP to: $to");
                $result = $smtp_service->send_message($to, $subject, $content, $smtp_config);

                if (!empty($result['success'])) {
                    $log_id = EIPSI_Notification_Email_Log_Service::log_email($survey_id, $participant_id, $type, 'sent', null, $subject, $metadata);
                    error_log("[EIPSI Email] SMTP send successful to: $to (log_id={$log_id})");
                    return $log_id;
                }

                $error = $result['error'] ?? 'SMTP send failed';
                error_log("[EIPSI Email] SMTP send failed: $error");
                EIPSI_Notification_Email_Log_Service::log_email($survey_id, $participant_id, $type, 'failed', $error, $subject, $metadata);
                return false;
            }

            // Use wp_mail with enhanced error handling
            error_log("[EIPSI Email] Sending via wp_mail to: $to");

            // Set default From name and email if not already set
            add_filter('wp_mail_from_name', function($name) {
                $investigator_name = get_option('eipsi_investigator_name', '');
                return !empty($investigator_name) ? $investigator_name : $name;
            }, 99);

            add_filter('wp_mail_from', function($email) {
                $investigator_email = get_option('eipsi_investigator_email', '');
                return !empty($investigator_email) && is_email($investigator_email)
                    ? $investigator_email
                    : $email;
            }, 99);

            // Capture wp_mail_failed WP hook for error detail
            $wp_mail_last_error = null;
            $wp_mail_failed_listener = function( $wp_error ) use ( &$wp_mail_last_error ) {
                if ( $wp_error instanceof WP_Error ) {
                    $wp_mail_last_error = $wp_error->get_error_message();
                }
            };
            add_action( 'wp_mail_failed', $wp_mail_failed_listener );

            // Try to send the email
            $sent = wp_mail($to, $subject, $content, $headers);

            remove_action( 'wp_mail_failed', $wp_mail_failed_listener );

            if ($sent) {
                $log_id = EIPSI_Notification_Email_Log_Service::log_email($survey_id, $participant_id, $type, 'sent', null, $subject, $metadata);
                error_log("[EIPSI Email] wp_mail successful to: $to (log_id={$log_id})");
                return $log_id;
            }

            // Get detailed error via wp_mail_failed hook
            $error_msg = $wp_mail_last_error ?? 'wp_mail returned false (no SMTP error captured)';
            error_log("[EIPSI Email] wp_mail failed to $to: $error_msg");
            EIPSI_Notification_Email_Log_Service::log_email($survey_id, $participant_id, $type, 'failed', $error_msg, $subject, $metadata);
            return false;

        } catch (Exception $e) {
            $error_msg = 'Exception: ' . $e->getMessage();
            error_log("[EIPSI Email] Exception during send: $error_msg");
            EIPSI_Notification_Email_Log_Service::log_email($survey_id, $participant_id, $type, 'failed', $error_msg, $subject);
            return false;
        } catch (Error $e) {
            $error_msg = 'Fatal Error: ' . $e->getMessage();
            error_log("[EIPSI Email] Fatal error during send: $error_msg");
            EIPSI_Notification_Email_Log_Service::log_email($survey_id, $participant_id, $type, 'failed', $error_msg, $subject);
            return false;
        }
    }
}
