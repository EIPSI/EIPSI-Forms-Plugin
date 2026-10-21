<?php
/**
 * EIPSI_Cron_Health_Service
 *
 * Monitorea y reporta la salud de los trabajos cron de EIPSI Forms.
 * Incluye indicadores visuales de estado y alertas.
 *
 * @package EIPSI_Forms
 * @subpackage Services
 * @version 2.1.0
 * @since Phase 3 - Task 3B.6
 */

if (!defined('ABSPATH')) {
    exit;
}

class EIPSI_Cron_Health_Service {

    /**
     * Cron job definitions and their expected intervals.
     */
    const CRON_JOBS = array(
        'eipsi_send_wave_reminders_hourly' => array(
            'name' => 'Recordatorios de Ondas',
            'interval' => 60, // Declared every_minute cadence
            'description' => 'Envía recordatorios automáticos a participantes con ondas pendientes',
            'critical' => true
        ),
        'eipsi_send_dropout_recovery_hourly' => array(
            'name' => 'Recuperación de Abandono',
            'interval' => 60, // Declared every_minute cadence
            'description' => 'Envía emails de recuperación a participantes que abandonaron',
            'critical' => true
        ),
        'eipsi_purge_access_logs_daily' => array(
            'name' => 'Purgado de Logs (GDPR)',
            'interval' => 86400, // 24 hours
            'description' => 'Elimina logs de acceso antiguos según política de retención',
            'critical' => false
        ),
        'eipsi_study_cron_job' => array(
            'name' => 'Tareas de Estudio',
            'interval' => 86400, // 24 hours
            'description' => 'Ejecuta tareas programadas de estudios activos',
            'critical' => false
        )
    );

    /**
     * Get comprehensive cron health status.
     *
     * @return array Health status for all cron jobs
     */
    public static function get_cron_health() {
        $health = array(
            'timestamp' => current_time('mysql'),
            'system_status' => self::check_wp_cron_system(),
            'jobs' => array(),
            'overall_status' => 'ok',
            'issues' => array()
        );

        foreach (self::CRON_JOBS as $hook => $config) {
            $job_health = self::get_job_health($hook, $config);
            $health['jobs'][$hook] = $job_health;

            // Check for issues
            if ($job_health['status'] === 'error') {
                $health['issues'][] = array(
                    'job' => $hook,
                    'severity' => $config['critical'] ? 'critical' : 'warning',
                    'message' => $config['name'] . ' no se ha ejecutado en el tiempo esperado'
                );
            } elseif ($job_health['status'] === 'warning') {
                $health['issues'][] = array(
                    'job' => $hook,
                    'severity' => 'warning',
                    'message' => $config['name'] . ' tiene retrasos en su ejecución'
                );
            }
        }

        // Determine overall status
        $critical_errors = array_filter($health['issues'], function($i) {
            return $i['severity'] === 'critical';
        });

        if (!empty($critical_errors)) {
            $health['overall_status'] = 'critical';
        } elseif (!empty($health['issues'])) {
            $health['overall_status'] = 'warning';
        }

        return $health;
    }

    /**
     * Check WP Cron system status.
     */
    private static function check_wp_cron_system() {
        $status = array(
            'enabled' => true,
            'spawn_method' => 'unknown',
            'issues' => array()
        );

        // Check if DISABLE_WP_CRON is defined
        if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
            $status['enabled'] = false;
            $status['issues'][] = 'WP Cron está deshabilitado. Se requiere cron externo.';
        }

        // Check ALTERNATE_WP_CRON
        if (defined('ALTERNATE_WP_CRON') && ALTERNATE_WP_CRON) {
            $status['spawn_method'] = 'alternate';
        }

        // Check if cron can spawn
        if (!wp_next_scheduled('wp_version_check')) {
            $status['issues'][] = 'No se detectaron tareas cron de WordPress core. Verificar configuración.';
        }

        return $status;
    }

    /**
     * Get health for a specific cron job.
     */
    private static function get_job_health($hook, $config) {
        $next_run = wp_next_scheduled($hook);
        $last_run = get_transient("eipsi_cron_last_run_{$hook}");

        $health = array(
            'name' => $config['name'],
            'description' => $config['description'],
            'critical' => $config['critical'],
            'scheduled' => false,
            'last_run' => $last_run,
            'next_run' => null,
            'status' => 'unknown',
            'overdue_by' => 0
        );

        if ($next_run) {
            $health['scheduled'] = true;
            $health['next_run'] = gmdate('Y-m-d H:i:s', $next_run);

            $now = current_time('timestamp');
            $time_until = $next_run - $now;

            if ($time_until < 0) {
                // Job is overdue
                $health['overdue_by'] = abs($time_until);

                if (abs($time_until) > ($config['interval'] * 2)) {
                    $health['status'] = 'error';
                } else {
                    $health['status'] = 'warning';
                }
            } else {
                $health['status'] = 'ok';
            }
        } else {
            // Not scheduled - check if it was recently unscheduled intentionally
            if ($last_run) {
                $last_run_ts = strtotime($last_run);
                $hours_since = (current_time('timestamp') - $last_run_ts) / 3600;

                if ($hours_since > 24) {
                    $health['status'] = 'error';
                } else {
                    $health['status'] = 'warning';
                }
            } else {
                $health['status'] = 'error';
            }
        }

        return $health;
    }

    /**
     * Record cron job execution.
     *
     * @param string $hook Cron hook name
     * @param array $metadata Optional metadata about execution
     */
    public static function record_execution($hook, $metadata = array()) {
        $data = array(
            'executed_at' => current_time('mysql'),
            'metadata' => $metadata
        );

        set_transient("eipsi_cron_last_run_{$hook}", $data, WEEK_IN_SECONDS);

        // Also log to database for history
        self::log_cron_execution($hook, $data);
    }

    /**
     * Log cron execution to database.
     */
    private static function log_cron_execution($hook, $data) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'survey_cron_log';

        // Check if table exists, create if not
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
            self::create_cron_log_table();
        }

        $wpdb->insert(
            $table_name,
            array(
                'cron_hook' => $hook,
                'executed_at' => $data['executed_at'],
                'metadata' => !empty($data['metadata']) ? wp_json_encode($data['metadata']) : null
            ),
            array('%s', '%s', '%s')
        );
    }

    /**
     * Create cron log table.
     */
    private static function create_cron_log_table() { require_once EIPSI_FORMS_PLUGIN_DIR.'includes/schema/bootstrap.php'; $result = EIPSI_Schema_Repair_Service::sync_local_table('survey_cron_log');  }

    /**
     * Get recent cron execution history.
     *
     * @param string $hook Optional hook filter
     * @param int $limit Number of entries
     * @return array Execution history
     */
    public static function get_execution_history($hook = '', $limit = 20) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'survey_cron_log';

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
            return array();
        }

        $where = '';
        $params = array();

        if (!empty($hook)) {
            $where = 'WHERE cron_hook = %s';
            $params[] = $hook;
        }

        $params[] = $limit;

        $query = "SELECT * FROM {$table_name} {$where} ORDER BY executed_at DESC LIMIT %d";

        if (!empty($where)) {
            $query = $wpdb->prepare($query, $params);
        } else {
            $query = $wpdb->prepare($query, $limit);
        }

        return $wpdb->get_results($query);
    }

    /**
     * Force run a cron job immediately.
     *
     * @param string $hook Cron hook to run
     * @return array Result
     */
    public static function force_run_cron($hook) {
        if (!isset(self::CRON_JOBS[$hook])) {
            return array(
                'success' => false,
                'message' => 'Trabajo cron no reconocido'
            );
        }

        // Check if job is already running
        if (get_transient("eipsi_cron_running_{$hook}")) {
            return array(
                'success' => false,
                'message' => 'Este trabajo ya está en ejecución'
            );
        }

        // Set transient to prevent concurrent runs
        set_transient("eipsi_cron_running_{$hook}", true, 300); // 5 minutes

        try {
            do_action($hook);
            self::record_execution($hook, array('forced' => true));

            delete_transient("eipsi_cron_running_{$hook}");

            return array(
                'success' => true,
                'message' => 'Trabajo ejecutado exitosamente'
            );
        } catch (Exception $e) {
            delete_transient("eipsi_cron_running_{$hook}");

            return array(
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            );
        }
    }

    /**
     * Reschedule a cron job.
     *
     * @param string $hook Cron hook
     * @return array Result
     */
    public static function reschedule_cron($hook) {
        if (!isset(self::CRON_JOBS[$hook])) {
            return array(
                'success' => false,
                'message' => 'Trabajo cron no reconocido'
            );
        }

        $defaults=array('eipsi_send_wave_reminders_hourly'=>'every_minute','eipsi_send_dropout_recovery_hourly'=>'every_minute','eipsi_purge_access_logs_daily'=>'daily');
        $events=array();
        foreach(_get_cron_array() as $rows){foreach(($rows[$hook]??array()) as $event){$events[md5(serialize($event['args']))]=$event;}}
        if(!$events){
            if(!isset($defaults[$hook])){return array('success'=>false,'message'=>'El cron de estudio requiere una programación con argumentos existente.');}
            $events[]=array('args'=>array(),'schedule'=>$defaults[$hook]);
        }
        $success=true;
        foreach($events as $event){
            $schedule=$defaults[$hook]??$event['schedule'];
            wp_clear_scheduled_hook($hook,$event['args']);
            $result=wp_schedule_event(time(),$schedule,$hook,$event['args']);
            if($result===false || is_wp_error($result)){$success=false;}
        }
        return array('success'=>$success,'message'=>$success?'Trabajo reprogramado exitosamente':'Error al reprogramar el trabajo');
    }

    /**
     * Get dashboard widget data.
     *
     * @return array Widget data
     */
    public static function get_dashboard_widget_data() {
        $health = self::get_cron_health();

        $widget = array(
            'overall_status' => $health['overall_status'],
            'last_check' => $health['timestamp'],
            'total_jobs' => count($health['jobs']),
            'ok_jobs' => 0,
            'warning_jobs' => 0,
            'error_jobs' => 0,
            'critical_issues' => array()
        );

        foreach ($health['jobs'] as $hook => $job) {
            switch ($job['status']) {
                case 'ok':
                    $widget['ok_jobs']++;
                    break;
                case 'warning':
                    $widget['warning_jobs']++;
                    break;
                case 'error':
                    $widget['error_jobs']++;
                    if ($job['critical']) {
                        $widget['critical_issues'][] = $job['name'];
                    }
                    break;
            }
        }

        return $widget;
    }

    /**
     * Check if cron system needs attention.
     *
     * @return bool
     */
    public static function needs_attention() {
        $health = self::get_cron_health();
        return $health['overall_status'] !== 'ok';
    }
}

// Register hooks to record cron execution
add_action('eipsi_send_wave_reminders_hourly', function() {
    EIPSI_Cron_Health_Service::record_execution('eipsi_send_wave_reminders_hourly');
}, 999);

add_action('eipsi_send_dropout_recovery_hourly', function() {
    EIPSI_Cron_Health_Service::record_execution('eipsi_send_dropout_recovery_hourly');
}, 999);

add_action('eipsi_purge_access_logs_daily', function() {
    EIPSI_Cron_Health_Service::record_execution('eipsi_purge_access_logs_daily');
}, 999);

add_action('eipsi_study_cron_job', function() {
    EIPSI_Cron_Health_Service::record_execution('eipsi_study_cron_job');
}, 999);
