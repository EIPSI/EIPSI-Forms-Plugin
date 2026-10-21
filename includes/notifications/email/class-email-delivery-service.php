<?php
/** Notifications owner; public WordPress adapters retain existing contracts. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Notification_Email_Delivery_Service {

    /** Study Engine adapter: same SMTP/wp_mail owner; logs IDs/status, never bearer/body/PII. */
    public static function send_study_engine($to,$subject,$content,$intent_id) {
        try {
            $sender=array('from_name'=>'','from_email'=>'');
            if(is_array($content)){
                $dto=$content;if(array_diff(array_keys($dto),array('html','from_name','from_email'))||!is_string($dto['html']??null))return false;
                foreach($sender as $key=>$default){$value=$dto[$key]??'';if(!is_string($value)||strlen($value)>240||preg_match('/[\r\n\x00-\x1f]/',$value))return false;$sender[$key]=$value;}
                if($sender['from_email']!==''&&!is_email($sender['from_email']))return false;$content=$dto['html'];
            }
            $smtp=class_exists('EIPSI_SMTP_Service')?new EIPSI_SMTP_Service():null;
            $config=$smtp?$smtp->get_config():null;
            if($config){foreach($sender as $key=>$value)if($value!=='')$config[$key]=$value;$result=$smtp->send_message($to,$subject,$content,$config);$sent=!empty($result['success']);}
            else{$headers=array('Content-Type: text/html; charset=UTF-8');if($sender['from_email']!=='')$headers[]='From: '.($sender['from_name']!==''?'"'.addcslashes($sender['from_name'],"\\\"").'" ':'').'<'.$sender['from_email'].'>';$nameFilter=static function($name)use($sender){return $sender['from_name']!==''?$sender['from_name']:$name;};add_filter('wp_mail_from_name',$nameFilter,PHP_INT_MAX);try{$sent=wp_mail($to,$subject,$content,$headers);}finally{remove_filter('wp_mail_from_name',$nameFilter,PHP_INT_MAX);}}
            EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_email_delivery_service_L20'), 'debug');
            return (bool)$sent;
        }catch(Throwable $e){EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_email_delivery_service_L22'), 'debug');return false;}
    }

public static function send_email($survey_id, $participant_id, $to, $type, $subject, $content, $metadata = array()) {
        $headers = array('Content-Type: text/html; charset=UTF-8');

        try {
            // Check if SMTP is configured
            $smtp_service = class_exists('EIPSI_SMTP_Service') ? new EIPSI_SMTP_Service() : null;
            $smtp_config = $smtp_service ? $smtp_service->get_config() : null;

            if ($smtp_config) {
                // Use SMTP service
                EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_email_delivery_service_L35'), 'debug');
                $result = $smtp_service->send_message($to, $subject, $content, $smtp_config);

                if (!empty($result['success'])) {
                    $log_id = EIPSI_Notification_Email_Log_Service::log_email($survey_id, $participant_id, $type, 'sent', null, $subject, $metadata);
                    EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_email_delivery_service_L40'), 'debug');
                    return $log_id;
                }

                $error = $result['error'] ?? 'SMTP send failed';
                EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_email_delivery_service_L45'), 'debug');
                EIPSI_Notification_Email_Log_Service::log_email($survey_id, $participant_id, $type, 'failed', $error, $subject, $metadata);
                return false;
            }

            // Use wp_mail with enhanced error handling
            EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_email_delivery_service_L51'), 'debug');

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
                EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_email_delivery_service_L82'), 'debug');
                return $log_id;
            }

            // Get detailed error via wp_mail_failed hook
            $error_msg = $wp_mail_last_error ?? 'wp_mail returned false (no SMTP error captured)';
            EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_email_delivery_service_L88'), 'debug');
            EIPSI_Notification_Email_Log_Service::log_email($survey_id, $participant_id, $type, 'failed', $error_msg, $subject, $metadata);
            return false;

        } catch (Exception $e) {
            $error_msg = 'Exception: ' . $e->getMessage();
            EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_email_delivery_service_L94'), 'debug');
            EIPSI_Notification_Email_Log_Service::log_email($survey_id, $participant_id, $type, 'failed', $error_msg, $subject);
            return false;
        } catch (Error $e) {
            $error_msg = 'Fatal Error: ' . $e->getMessage();
            EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_email_delivery_service_L99'), 'debug');
            EIPSI_Notification_Email_Log_Service::log_email($survey_id, $participant_id, $type, 'failed', $error_msg, $subject);
            return false;
        }
    }
}
