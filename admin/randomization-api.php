<?php
/**
 * EIPSI Forms - Randomization API
 * 
 * Maneja los endpoints AJAX para el Randomization Dashboard
 * 
 * @package EIPSI_Forms
 * @since 1.3.2
 */

if (!defined('ABSPATH')) {
    exit;
}
require_once EIPSI_FORMS_PLUGIN_DIR.'includes/randomization/bootstrap.php';


/**
 * Verificar si un config_id (randomization_id) existe en la base de datos
 *
 * @param string $config_id Randomization ID
 * @return bool
 */
function eipsi_check_config_exists($config_id) { return EIPSI_Randomization_Admin_Adapter::eipsi_check_config_exists($config_id); }


/**
 * Normalizar configuración de aleatorización desde post meta
 *
 * @param array  $config Config raw desde post meta.
 * @param string $meta_key Meta key para fallback de config_id.
 * @return array|null
 */
function eipsi_normalize_randomization_config( $config, $meta_key ) { return EIPSI_Randomization_Admin_Adapter::eipsi_normalize_randomization_config($config, $meta_key); }

/**
 * Obtener configuraciones de aleatorización guardadas en post meta.
 *
 * @return array
 */
function eipsi_get_randomization_configs_from_post_meta() { return EIPSI_Randomization_Admin_Adapter::eipsi_get_randomization_configs_from_post_meta(); }

/**
 * Obtener estadísticas agregadas de asignaciones para una aleatorización
 *
 * @param string $randomization_id Config ID.
 * @return object
 */
function eipsi_get_randomization_assignment_stats( $randomization_id ) { return EIPSI_Randomization_Admin_Adapter::eipsi_get_randomization_assignment_stats($randomization_id); }

/**
 * Registrar los endpoints AJAX
 */
function eipsi_register_randomization_endpoints() {
    // Para usuarios logueados
    add_action('wp_ajax_eipsi_get_randomizations', 'eipsi_get_randomizations');
    add_action('wp_ajax_eipsi_get_randomization_details', 'eipsi_get_randomization_details');
    add_action('wp_ajax_eipsi_get_randomization_users', 'eipsi_get_randomization_users');
    add_action('wp_ajax_eipsi_get_distribution_stats', 'eipsi_get_distribution_stats');

    // Endpoints para asignaciones manuales
    add_action('wp_ajax_eipsi_get_manual_overrides', 'eipsi_get_manual_overrides');
    add_action('wp_ajax_eipsi_create_manual_override', 'eipsi_create_manual_override');
    add_action('wp_ajax_eipsi_revoke_manual_override', 'eipsi_revoke_manual_override');
    add_action('wp_ajax_eipsi_delete_manual_override', 'eipsi_delete_manual_override');
}
add_action('init', 'eipsi_register_randomization_endpoints');

/**
 * Obtener lista de aleatorizaciones para el dashboard
 */
function eipsi_get_randomizations() { return EIPSI_Randomization_Admin_Adapter::eipsi_get_randomizations(); }

/**
 * Obtener detalles específicos de una aleatorización
 */
function eipsi_get_randomization_details() { return EIPSI_Randomization_Admin_Adapter::eipsi_get_randomization_details(); }

/**
 * Obtener lista de usuarios de una aleatorización
 */
function eipsi_get_randomization_users() { return EIPSI_Randomization_Admin_Adapter::eipsi_get_randomization_users(); }

/**
 * Generar y descargar CSV de asignaciones
 */
function eipsi_download_assignments_csv() {
    // Verificar nonce y permisos
    if (!wp_verify_nonce($_POST['nonce'], 'eipsi_randomization_nonce') || !current_user_can('manage_options')) {
        wp_die('No autorizado', '', array('response' => 403));
    }

    $randomization_id = sanitize_text_field($_POST['randomization_id'] ?? '');
    $form_id = isset($_POST['form_id']) ? intval($_POST['form_id']) : null;

    if (empty($randomization_id)) {
        wp_die('ID de aleatorización requerido');
    }

    global $wpdb;

    try {
        // Query base
        $query = "
            SELECT 
                ra.randomization_id,
                ra.user_fingerprint,
                ra.assigned_form_id,
                ra.assigned_at,
                ra.last_access,
                ra.access_count
            FROM {$wpdb->prefix}eipsi_randomization_assignments ra
            WHERE ra.randomization_id = %s
        ";
        
        $params = array($randomization_id);
        
        // Filtro opcional por formulario
        if ($form_id) {
            $query .= " AND ra.assigned_form_id = %d";
            $params[] = $form_id;
        }
        
        $query .= " ORDER BY ra.assigned_at DESC";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $results = $wpdb->get_results($wpdb->prepare($query, $params));

        if (empty($results)) {
            wp_die('No hay asignaciones para esta aleatorización' . ($form_id ? ' y formulario' : ''));
        }

        // Preparar nombre del archivo
        $filename = $randomization_id . '_assignments' . ($form_id ? '_form_' . $form_id : '_complete') . '.csv';

        // Headers para descarga
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($filename) . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        // Abrir output stream
        $output = fopen('php://output', 'w');

        // UTF-8 BOM para Excel compatibility
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        // Headers
        $headers = array(
            'randomization_id',
            'user_fingerprint',
            'assigned_form_id',
            'assigned_form_name',
            'assigned_at',
            'last_access',
            'access_count',
            'days_since_assignment',
            'completed_status'
        );
        fputcsv($output, $headers);

        // Procesar cada fila
        foreach ($results as $row) {
            // Obtener nombre del formulario
            $form = get_post($row->assigned_form_id);
            $form_name = $form ? $form->post_title : 'Desconocido';

            // Calcular días desde asignación
            $assigned_date = new DateTime($row->assigned_at, wp_timezone());
            $today = new DateTime('now', wp_timezone());
            $days_diff = $today->diff($assigned_date)->days;

            // Determinar status con reglas especificadas
            $completed_status = 'No Iniciado';
            if ($row->last_access) {
                $access_count = intval($row->access_count);
                if ($access_count >= 3) {
                    $completed_status = 'Completado';
                } elseif ($access_count >= 1) {
                    $completed_status = 'Parcial (' . $access_count . ' acceso' . ($access_count > 1 ? 's' : '') . ')';
                } else {
                    $completed_status = 'Abandonado (0 accesos)';
                }
            }

            // Anonimizar fingerprint (primeros 8 + ... + últimos 8)
            $full_fp = $row->user_fingerprint;
            $anon_fp = 'fp_' . substr($full_fp, 0, 8) . '...' . substr($full_fp, -8);

            // Formatear fechas en ISO 8601
            $assigned_at = wp_date('Y-m-d H:i:s', strtotime($row->assigned_at));
            $last_access = $row->last_access ? wp_date('Y-m-d H:i:s', strtotime($row->last_access)) : '';

            // Crear fila
            $csv_row = array(
                $row->randomization_id,
                $anon_fp,
                $row->assigned_form_id,
                $form_name,
                $assigned_at,
                $last_access,
                $row->access_count,
                $days_diff,
                $completed_status
            );

            fputcsv($output, $csv_row);
        }

        fclose($output);
        exit;

    } catch (Exception $e) {
        error_log('[EIPSI Randomization] Error en descarga CSV: ' . $e->getMessage());
        wp_die('Error generando CSV: ' . $e->getMessage());
    }
}
add_action('wp_ajax_eipsi_download_assignments_csv', 'eipsi_download_assignments_csv');

/**
 * Generar y descargar Excel de asignaciones
 */
function eipsi_download_assignments_excel() {
    // Verificar nonce y permisos
    if (!wp_verify_nonce($_POST['nonce'], 'eipsi_randomization_nonce') || !current_user_can('manage_options')) {
        wp_die('No autorizado', '', array('response' => 403));
    }

    $randomization_id = sanitize_text_field($_POST['randomization_id'] ?? '');
    $form_id = isset($_POST['form_id']) ? intval($_POST['form_id']) : null;

    if (empty($randomization_id)) {
        wp_die('ID de aleatorización requerido');
    }

    global $wpdb;

    try {
        // v2.1.3: Enhanced query with submission data from vas_form_results
        $query = "
            SELECT
                ra.randomization_id,
                ra.user_fingerprint,
                ra.assigned_form_id,
                ra.assigned_at,
                ra.last_access,
                ra.access_count,
                r.id as submission_id,
                r.submitted_at,
                r.duration_seconds,
                r.form_responses,
                r.duration
            FROM {$wpdb->prefix}eipsi_randomization_assignments ra
            LEFT JOIN {$wpdb->prefix}vas_form_results r 
                ON r.participant_id = ra.user_fingerprint 
                AND r.form_id = ra.assigned_form_id
            WHERE ra.randomization_id = %s
        ";

        $params = array($randomization_id);

        // Filtro opcional por formulario
        if ($form_id) {
            $query .= " AND ra.assigned_form_id = %d";
            $params[] = $form_id;
        }

        $query .= " ORDER BY ra.assigned_at DESC";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $results = $wpdb->get_results($wpdb->prepare($query, $params));

        if (empty($results)) {
            wp_die('No hay asignaciones para esta aleatorización' . ($form_id ? ' y formulario' : ''));
        }

        // Generar HTML para Excel
        $html = '<table border="1">';

        // Headers - v2.1.3: Enhanced with submission data
        $html .= '<thead><tr>';
        $headers = array(
            'Randomization ID',
            'User Fingerprint',
            'Form ID',
            'Form Name',
            'Assigned At',
            'Last Access',
            'Access Count',
            'Days Since',
            'Status',
            'Submission ID',
            'Completed At',
            'Duration (seconds)',
            'Form Data' // v2.1.3: Key form responses
        );
        foreach ($headers as $header) {
            $html .= '<th style="background-color:#4CAF50;color:white;padding:10px;">' . esc_html($header) . '</th>';
        }
        $html .= '</tr></thead>';

        // Body
        $html .= '<tbody>';
        foreach ($results as $row) {
            // Obtener nombre del formulario
            $form = get_post($row->assigned_form_id);
            $form_name = $form ? $form->post_title : 'Desconocido';

            // Calcular días desde asignación
            $assigned_date = new DateTime($row->assigned_at, wp_timezone());
            $today = new DateTime('now', wp_timezone());
            $days_diff = $today->diff($assigned_date)->days;

            // Determinar status
            $completed_status = 'No Iniciado';
            if ($row->last_access) {
                $access_count = intval($row->access_count);
                if ($access_count >= 3) {
                    $completed_status = 'Completado';
                } elseif ($access_count >= 1) {
                    $completed_status = 'Parcial (' . $access_count . ' acceso' . ($access_count > 1 ? 's' : '') . ')';
                } else {
                    $completed_status = 'Abandonado (0 accesos)';
                }
            }

            // Anonimizar fingerprint
            $full_fp = $row->user_fingerprint;
            $anon_fp = 'fp_' . substr($full_fp, 0, 8) . '...' . substr($full_fp, -8);

            // Formatear fechas
            $assigned_at = wp_date('Y-m-d H:i:s', strtotime($row->assigned_at));
            $last_access = $row->last_access ? wp_date('Y-m-d H:i:s', strtotime($row->last_access)) : '';
            $completed_at = $row->submitted_at ? wp_date('Y-m-d H:i:s', strtotime($row->submitted_at)) : '';

            // v2.1.3: Extract key form responses (first 5 fields max to avoid clutter)
            $form_data_summary = '';
            if (!empty($row->form_responses)) {
                $form_responses = json_decode($row->form_responses, true);
                if (is_array($form_responses)) {
                    // Filter out internal fields and limit to first 5
                    $internal_fields = array('action', 'eipsi_nonce', 'start_time', 'end_time', 'form_start_time', 'form_end_time', 'nonce', 'form_action', 'ip_address', 'device', 'browser', 'os', 'screen_width', 'current_page', 'form_id', 'eipsi_consent_accepted');
                    $filtered = array_diff_key($form_responses, array_flip($internal_fields));
                    $limited = array_slice($filtered, 0, 5, true);
                    $parts = array();
                    foreach ($limited as $key => $value) {
                        $clean_key = str_replace('_', ' ', $key);
                        $clean_value = is_array($value) ? json_encode($value) : substr($value, 0, 50);
                        $parts[] = $clean_key . ': ' . $clean_value;
                    }
                    $form_data_summary = implode(' | ', $parts);
                    if (count($filtered) > 5) {
                        $form_data_summary .= ' ... (' . (count($filtered) - 5) . ' more)';
                    }
                }
            }

            // Duration: prefer duration_seconds, fallback to duration field
            $duration = !empty($row->duration_seconds) ? intval($row->duration_seconds) : (!empty($row->duration) ? intval($row->duration) : '');

            $html .= '<tr>';
            $html .= '<td style="padding:8px;">' . esc_html($row->randomization_id) . '</td>';
            $html .= '<td style="padding:8px;monospace;">' . esc_html($anon_fp) . '</td>';
            $html .= '<td style="padding:8px;">' . intval($row->assigned_form_id) . '</td>';
            $html .= '<td style="padding:8px;">' . esc_html($form_name) . '</td>';
            $html .= '<td style="padding:8px;">' . esc_html($assigned_at) . '</td>';
            $html .= '<td style="padding:8px;">' . esc_html($last_access) . '</td>';
            $html .= '<td style="padding:8px;text-align:center;">' . intval($row->access_count) . '</td>';
            $html .= '<td style="padding:8px;text-align:center;">' . $days_diff . '</td>';
            $html .= '<td style="padding:8px;">' . esc_html($completed_status) . '</td>';
            // v2.1.3: New columns
            $html .= '<td style="padding:8px;text-align:center;">' . ($row->submission_id ? intval($row->submission_id) : '-') . '</td>';
            $html .= '<td style="padding:8px;">' . esc_html($completed_at) . '</td>';
            $html .= '<td style="padding:8px;text-align:center;">' . ($duration !== '' ? $duration : '-') . '</td>';
            $html .= '<td style="padding:8px;font-size:11px;max-width:300px;overflow:hidden;text-overflow:ellipsis;" title="' . esc_attr($form_data_summary) . '">' . esc_html($form_data_summary) . '</td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';

        // Preparar nombre del archivo
        $filename = $randomization_id . '_assignments' . ($form_id ? '_form_' . $form_id : '_complete') . '.xls';

        // Headers para descarga
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($filename) . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        // Meta tag para codificación UTF-8 en Excel
        echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
        echo '<head><meta http-equiv="Content-Type" content="text/html; charset=utf-8">';
        echo '<style>
            table { border-collapse: collapse; }
            td, th { border: 1px solid #ddd; }
        </style>';
        echo '</head><body>';
        echo $html;
        echo '</body></html>';
        exit;

    } catch (Exception $e) {
        error_log('[EIPSI Randomization] Error en descarga Excel: ' . $e->getMessage());
        wp_die('Error generando Excel: ' . $e->getMessage());
    }
}
add_action('wp_ajax_eipsi_download_assignments_excel', 'eipsi_download_assignments_excel');

/**
 * Obtener estadísticas de distribución: Teórico vs Real
 * 
 * Compara la distribución configurada (teórica) vs la distribución actual (real)
 * para detectar desbalances, sesgos o errores en la aleatorización
 * 
 * @param string $randomization_id ID de la configuración de aleatorización
 * @param string $format Formato de respuesta: 'json' | 'summary'
 * @return array Estadísticas de distribución con drift analysis
 */
function eipsi_get_distribution_stats() { return EIPSI_Randomization_Admin_Adapter::eipsi_get_distribution_stats(); }

/**
 * Calcular health score basado en drift promedio, tasa de completado y tamaño de muestra
 * 
 * @param float $avg_drift Drift promedio absoluto
 * @param float $completion_rate Tasa de completado general
 * @param int $sample_size Tamaño de muestra
 * @return int Health score (0-100)
 */
function calculate_health_score($avg_drift, $completion_rate, $sample_size) { return EIPSI_Randomization_Admin_Adapter::calculate_health_score($avg_drift, $completion_rate, $sample_size); }

/**
 * Generar recomendación basada en el análisis de distribución
 * 
 * @param string $overall_status Estado general
 * @param float $max_drift Máximo drift encontrado
 * @param int $max_drift_form_id ID del formulario con máximo drift
 * @param array $formularios Configuración de formularios
 * @return string Recomendación en español
 */
function generate_distribution_recommendation($overall_status, $max_drift, $max_drift_form_id, $formularios) { return EIPSI_Randomization_Admin_Adapter::generate_distribution_recommendation($overall_status, $max_drift, $max_drift_form_id, $formularios); }

/**
 * Calcular margen de error para el tamaño de muestra dado (95% CI)
 *
 * @param int $n Tamaño de muestra
 * @return float Margen de error en porcentaje
 */
function calculate_margin_error($n) { return EIPSI_Randomization_Admin_Adapter::calculate_margin_error($n); }

/**
 * ========================================
 * ASIGNACIONES MANUALES (OVERRIDES)
 * ========================================
 */

/**
 * Obtener lista de asignaciones manuales para una configuración
 */
function eipsi_get_manual_overrides() { return EIPSI_Randomization_Admin_Adapter::eipsi_get_manual_overrides(); }

/**
 * Crear o actualizar una asignación manual
 */
function eipsi_create_manual_override() { return EIPSI_Randomization_Admin_Adapter::eipsi_create_manual_override(); }

/**
 * Revocar una asignación manual (soft delete - marca como revoked)
 */
function eipsi_revoke_manual_override() { return EIPSI_Randomization_Admin_Adapter::eipsi_revoke_manual_override(); }

/**
 * Eliminar permanentemente una asignación manual
 */
function eipsi_delete_manual_override() { return EIPSI_Randomization_Admin_Adapter::eipsi_delete_manual_override(); }
?>