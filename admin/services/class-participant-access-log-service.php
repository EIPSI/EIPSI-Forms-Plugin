<?php
/**
 * EIPSI_Participant_Access_Log_Service
 *
 * Gestiona el logging de acceso de participantes para compliance GDPR:
 * - Registro de logins, registros, accesos a waves
 * - Rate limiting
 * - Retención de datos
 *
 * @package EIPSI_Forms
 * @subpackage Services
 * @version 2.0.0
 * @since Phase 2 - Task 2C
 */

if (!defined('ABSPATH')) {
    exit;
}

class EIPSI_Participant_Access_Log_Service {
    
    /**
     * Log a participant access action.
     *
     * @param int    $participant_id ID del participante.
     * @param int    $study_id ID del estudio.
     * @param string $action_type Tipo de acción (registration, login, login_failed, magic_link_clicked, wave_started, wave_completed, logout).
     * @param array  $metadata Metadatos adicionales (opcional).
     * @return bool True si se logueó correctamente.
     * @since 2.0.0
     * @access public
     */
    public static function log($participant_id, $study_id, $action_type, $metadata = array()) {
        global $wpdb;
        
        // Validar action_type
        $valid_actions = array(
            'registration',
            'login',
            'login_failed',
            'magic_link_clicked',
            'magic_link_sent',
            'wave_started',
            'wave_completed',
            'logout',
            'session_expired',
            'password_reset_requested',
            'password_reset_completed'
        );
        
        if (!in_array($action_type, $valid_actions)) {
            error_log('EIPSI Access Log: Invalid action_type: ' . $action_type);
            return false;
        }
        
        // Obtener IP y User Agent
        $ip_address = self::get_client_ip();
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : 'unknown';
        
        require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/privacy-config.php';
        $config=get_global_privacy_defaults();
        if (empty($config['ip_address'])) { $ip_address=''; }
        if (empty($config['user_agent_full'])) { $user_agent=''; }
        $metadata=eipsi_filter_capture_data($metadata,$config);

        // Insertar en la tabla
        $table_name = $wpdb->prefix . 'survey_participant_access_log';
        
        $result = $wpdb->insert(
            $table_name,
            array(
                'participant_id' => $participant_id ?: 0,
                'study_id' => $study_id ?: 0,
                'action_type' => $action_type,
                'ip_address' => $ip_address,
                'user_agent' => substr($user_agent, 0, 500), // Limitar longitud
                'metadata' => !empty($metadata) ? wp_json_encode($metadata) : null,
                'created_at' => current_time('mysql')
            ),
            array('%d', '%d', '%s', '%s', '%s', '%s', '%s')
        );
        
        if ($result === false) {
            error_log('EIPSI Access Log: Failed to log action: ' . $wpdb->last_error);
            return false;
        }
        
        return true;
    }
    
    /**
     * Check rate limit for login attempts.
     *
     * @param string $email Email del participante.
     * @param int    $study_id ID del estudio.
     * @param int    $max_attempts Máximo de intentos (default 5).
     * @param int    $window_minutes Ventana de tiempo en minutos (default 15).
     * @return array { allowed: bool, attempts: int, remaining: int, reset_at: string }
     * @since 2.0.0
     * @access public
     */
    public static function check_rate_limit($email, $study_id, $max_attempts = 5, $window_minutes = 15) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'survey_participant_access_log';
        
        // Calcular tiempo de inicio de la ventana
        $window_start = date('Y-m-d H:i:s', strtotime("-{$window_minutes} minutes"));
        
        // Contar intentos fallidos en la ventana
        $attempts = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_name} 
             WHERE action_type = 'login_failed' 
             AND metadata LIKE %s 
             AND study_id = %d 
             AND created_at >= %s",
            '%' . $wpdb->esc_like($email) . '%',
            $study_id,
            $window_start
        ));
        
        $remaining = max(0, $max_attempts - (int) $attempts);
        $reset_at = date('Y-m-d H:i:s', strtotime("+{$window_minutes} minutes"));
        
        return array(
            'allowed' => $attempts < $max_attempts,
            'attempts' => (int) $attempts,
            'remaining' => $remaining,
            'reset_at' => $reset_at,
            'retry_after' => $attempts >= $max_attempts ? $window_minutes * 60 : 0
        );
    }
    
    /**
     * Get access log history for a participant.
     *
     * @param int $participant_id ID del participante.
     * @param int $limit Límite de registros (default 50).
     * @return array Array de logs.
     * @since 2.0.0
     * @access public
     */
    public static function get_participant_history($participant_id, $limit = 50) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'survey_participant_access_log';
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table_name} 
             WHERE participant_id = %d 
             ORDER BY created_at DESC 
             LIMIT %d",
            $participant_id,
            $limit
        ));
    }
    
    /**
     * Get access log history for a study.
     *
     * @param int $study_id ID del estudio.
     * @param int $limit Límite de registros (default 100).
     * @return array Array de logs.
     * @since 2.0.0
     * @access public
     */
    public static function get_study_history($study_id, $limit = 100) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'survey_participant_access_log';
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT al.*, p.email, p.first_name, p.last_name 
             FROM {$table_name} al
             LEFT JOIN {$wpdb->prefix}survey_participants p ON al.participant_id = p.id
             WHERE al.study_id = %d 
             ORDER BY al.created_at DESC 
             LIMIT %d",
            $study_id,
            $limit
        ));
    }
    
    /**
     * Purge old access logs based on retention policy.
     *
     * @param int $retention_days Días de retención (default 365).
     * @return int Número de registros eliminados.
     * @since 2.0.0
     * @access public
     */
    public static function purge_old_logs($retention_days = 365) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'survey_participant_access_log';
        $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$retention_days} days"));
        
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table_name} WHERE created_at < %s",
            $cutoff_date
        ));
        
        if ($deleted > 0) {
            error_log("EIPSI Access Log: Purged {$deleted} old records (retention: {$retention_days} days)");
        }
        
        return (int) $deleted;
    }
    
    /**
     * Get client IP address safely.
     *
     * @return string IP address.
     * @since 2.0.0
     * @access private
     */
    private static function get_client_ip() {
        $ip = '0.0.0.0';
        
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = sanitize_text_field($_SERVER['HTTP_CLIENT_IP']);
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            // Proxies can send multiple IPs, take the first one
            $ips = explode(',', sanitize_text_field($_SERVER['HTTP_X_FORWARDED_FOR']));
            $ip = trim($ips[0]);
        } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = sanitize_text_field($_SERVER['REMOTE_ADDR']);
        }
        
        // Validate IP format
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            $ip = '0.0.0.0';
        }
        
        return $ip;
    }
    
    /**
     * Get login statistics for a study.
     *
     * @param int $study_id ID del estudio.
     * @param int $days Días hacia atrás (default 30).
     * @return array Statistics array.
     * @since 2.0.0
     * @access public
     */
    public static function get_login_stats($study_id, $days = 30) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'survey_participant_access_log';
        $start_date = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                COUNT(CASE WHEN action_type = 'login' THEN 1 END) as total_logins,
                COUNT(CASE WHEN action_type = 'login_failed' THEN 1 END) as failed_logins,
                COUNT(CASE WHEN action_type = 'registration' THEN 1 END) as registrations,
                COUNT(CASE WHEN action_type = 'magic_link_clicked' THEN 1 END) as magic_link_uses,
                COUNT(DISTINCT participant_id) as unique_participants
             FROM {$table_name}
             WHERE study_id = %d AND created_at >= %s",
            $study_id,
            $start_date
        ), ARRAY_A);
        
        return $stats ?: array(
            'total_logins' => 0,
            'failed_logins' => 0,
            'registrations' => 0,
            'magic_link_uses' => 0,
            'unique_participants' => 0
        );
    }
}
