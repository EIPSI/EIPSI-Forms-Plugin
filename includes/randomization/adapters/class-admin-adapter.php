<?php
/** M7 owner. Public compatibility adapters retain registration and signatures. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Randomization_Admin_Adapter {

public static function eipsi_check_config_exists($config_id) {
    global $wpdb;

    $config_id = sanitize_text_field($config_id);
    if (empty($config_id)) {
        return false;
    }

    $query = "
        SELECT COUNT(*)
        FROM {$wpdb->prefix}eipsi_randomization_configs
        WHERE randomization_id = %s
    ";

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $count = $wpdb->get_var($wpdb->prepare($query, $config_id));
    return intval($count) > 0;
}

public static function eipsi_normalize_randomization_config( $config, $meta_key ) {
    if ( ! is_array( $config ) || empty( $config ) ) {
        return null;
    }

    $config_id = $config['config_id'] ?? '';
    if ( empty( $config_id ) && ! empty( $meta_key ) ) {
        $config_id = str_replace( '_randomization_config_', '', $meta_key );
    }

    if ( empty( $config_id ) ) {
        return null;
    }

    $formularios_raw = $config['formularios'] ?? array();
    $formularios = array();

    foreach ( (array) $formularios_raw as $formulario ) {
        if ( is_array( $formulario ) ) {
            $form_id = $formulario['id'] ?? $formulario['form_id'] ?? $formulario['formId'] ?? null;
            if ( $form_id ) {
                $formulario['id'] = intval( $form_id );
                $formularios[] = $formulario;
            }
        } else {
            $form_id = intval( $formulario );
            if ( $form_id ) {
                $formularios[] = array( 'id' => $form_id );
            }
        }
    }

    $probabilidades_raw = $config['probabilidades'] ?? array();
    $probabilidades = is_array( $probabilidades_raw ) ? $probabilidades_raw : array();

    if ( array_values( $probabilidades ) === $probabilidades && ! empty( $formularios ) ) {
        $probabilidades_mapeadas = array();
        foreach ( $formularios as $index => $formulario ) {
            if ( isset( $probabilidades[ $index ] ) ) {
                $probabilidades_mapeadas[ $formulario['id'] ] = floatval( $probabilidades[ $index ] );
            }
        }
        $probabilidades = $probabilidades_mapeadas;
    }

    return array(
        'randomization_id' => $config_id,
        'formularios' => $formularios,
        'probabilidades' => $probabilidades,
        'method' => $config['method'] ?? $config['metodo'] ?? 'pure-random',
        'manual_assignments' => $config['manualAssignments'] ?? $config['manualAssigns'] ?? array(),
        'show_instructions' => ! empty( $config['showInstructions'] ) || ! empty( $config['show_instructions'] ),
        'created_at' => $config['created_at'] ?? null,
        'updated_at' => $config['updated_at'] ?? null,
    );
}

public static function eipsi_get_randomization_configs_from_post_meta() {
    global $wpdb;

    $meta_key_like = $wpdb->esc_like( '_randomization_config_' ) . '%';
    $post_types = array( 'eipsi_form_template', 'eipsi_form' );
    $placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );

    $query = $wpdb->prepare(
        "SELECT pm.post_id, pm.meta_key, pm.meta_value, p.post_date, p.post_modified
         FROM {$wpdb->postmeta} pm
         INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
         WHERE pm.meta_key LIKE %s
           AND p.post_type IN ($placeholders)
           AND p.post_status != 'trash'",
        array_merge( array( $meta_key_like ), $post_types )
    );

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $rows = $wpdb->get_results( $query );

    if ( empty( $rows ) ) {
        return array();
    }

    $configs = array();

    foreach ( $rows as $row ) {
        $config = maybe_unserialize( $row->meta_value );
        $normalized = eipsi_normalize_randomization_config( $config, $row->meta_key );

        if ( ! $normalized ) {
            continue;
        }

        if ( empty( $normalized['created_at'] ) ) {
            $normalized['created_at'] = $row->post_date;
        }

        if ( empty( $normalized['updated_at'] ) ) {
            $normalized['updated_at'] = $row->post_modified;
        }

        $configs[] = $normalized;
    }

    return $configs;
}

public static function eipsi_get_randomization_assignment_stats( $randomization_id ) {
    global $wpdb;

    $query = "
        SELECT
            COUNT(DISTINCT user_fingerprint) as total_assigned,
            COUNT(CASE WHEN last_access IS NOT NULL THEN 1 END) as completed_count,
            MAX(assigned_at) as last_assignment,
            AVG(access_count) as avg_access_count,
            AVG(DATEDIFF(CURDATE(), DATE(assigned_at))) as avg_days
        FROM {$wpdb->prefix}eipsi_randomization_assignments
        WHERE randomization_id = %s
    ";

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $stats = $wpdb->get_row( $wpdb->prepare( $query, $randomization_id ) );

    return $stats ?: (object) array(
        'total_assigned' => 0,
        'completed_count' => 0,
        'last_assignment' => null,
        'avg_access_count' => 0,
        'avg_days' => 0,
    );
}

public static function eipsi_get_randomizations() {
    // Verificar nonce y permisos
    if (!wp_verify_nonce($_POST['nonce'], 'eipsi_randomization_nonce') || !current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized', 403);
        return;
    }

    // Verificar si es un refresh forzado (desde el botón Actualizar)
    $force_refresh = isset($_POST['force_refresh']) && $_POST['force_refresh'] === '1';

    global $wpdb;

    try {
        // Query principal: listar aleatorizaciones con estadísticas
        $query = "
            SELECT 
                rc.randomization_id,
                rc.formularios,
                rc.probabilidades,
                rc.method,
                rc.created_at,
                rc.updated_at,
                rc.show_instructions,
                COUNT(DISTINCT ra.user_fingerprint) as total_assigned,
                COUNT(CASE WHEN ra.last_access IS NOT NULL THEN 1 END) as completed_count,
                MAX(ra.assigned_at) as last_assignment,
                AVG(ra.access_count) as avg_access_count,
                AVG(DATEDIFF(CURDATE(), DATE(ra.assigned_at))) as avg_days
            FROM {$wpdb->prefix}eipsi_randomization_configs rc
            LEFT JOIN {$wpdb->prefix}eipsi_randomization_assignments ra 
                ON rc.randomization_id = ra.randomization_id
            GROUP BY rc.randomization_id
            ORDER BY rc.created_at DESC
        ";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $results = $wpdb->get_results($query);

        if ($results === false) {
            wp_send_json_error('Error en consulta SQL');
            return;
        }

        $randomizations = array();

        $configs_from_meta = eipsi_get_randomization_configs_from_post_meta();
        $results_map = array();

        foreach ( $results as $row ) {
            $results_map[ $row->randomization_id ] = $row;
        }

        foreach ( $configs_from_meta as $meta_config ) {
            if ( isset( $results_map[ $meta_config['randomization_id'] ] ) ) {
                continue;
            }

            $row = (object) array(
                'randomization_id' => $meta_config['randomization_id'],
                'formularios' => wp_json_encode( $meta_config['formularios'] ),
                'probabilidades' => wp_json_encode( $meta_config['probabilidades'] ),
                'method' => $meta_config['method'],
                'created_at' => $meta_config['created_at'] ?: current_time( 'mysql' ),
                'updated_at' => $meta_config['updated_at'] ?: current_time( 'mysql' ),
                'show_instructions' => $meta_config['show_instructions'] ? 1 : 0,
                'total_assigned' => null,
                'completed_count' => null,
                'last_assignment' => null,
                'avg_access_count' => null,
                'avg_days' => null,
            );

            $results[] = $row;
            $results_map[ $row->randomization_id ] = $row;

            if ( function_exists( 'eipsi_save_randomization_config_to_db' ) ) {
                eipsi_save_randomization_config_to_db(
                    $row->randomization_id,
                    array(
                        'formularios' => $meta_config['formularios'],
                        'probabilidades' => $meta_config['probabilidades'],
                        'method' => $meta_config['method'],
                        'manualAssignments' => $meta_config['manual_assignments'],
                        'showInstructions' => $meta_config['show_instructions'],
                    )
                );
            }
        }

        usort(
            $results,
            function ( $a, $b ) {
                return strtotime( $b->created_at ?? '' ) <=> strtotime( $a->created_at ?? '' );
            }
        );

        foreach ($results as $row) {
            // Decodificar JSON de formularios
            $formularios = json_decode($row->formularios, true);
            if (!$formularios) $formularios = array();

            // Decodificar JSON de probabilidades
            $probabilidades = json_decode($row->probabilidades, true);
            if (!$probabilidades) $probabilidades = array();

            $stats = $row->total_assigned === null
                ? eipsi_get_randomization_assignment_stats( $row->randomization_id )
                : (object) array(
                    'total_assigned' => $row->total_assigned,
                    'completed_count' => $row->completed_count,
                    'last_assignment' => $row->last_assignment,
                    'avg_access_count' => $row->avg_access_count,
                    'avg_days' => $row->avg_days,
                );

            // Obtener distribución real por formulario (solo formularios con asignaciones)
            $distribution_query = "
                SELECT 
                    ra.assigned_form_id,
                    COUNT(*) as count,
                    COUNT(CASE WHEN ra.last_access IS NOT NULL THEN 1 END) as completed_count,
                    AVG(ra.access_count) as avg_access_count,
                    AVG(DATEDIFF(CURDATE(), DATE(ra.assigned_at))) as avg_days
                FROM {$wpdb->prefix}eipsi_randomization_assignments ra
                WHERE ra.randomization_id = %s
                GROUP BY ra.assigned_form_id
            ";

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $distribution_raw = $wpdb->get_results($wpdb->prepare($distribution_query, $row->randomization_id));

            // Crear mapa de distribución para lookup rápido
            $distribution_map = array();
            foreach ($distribution_raw as $dist) {
                $distribution_map[$dist->assigned_form_id] = array(
                    'count' => intval($dist->count),
                    'completed_count' => intval($dist->completed_count),
                    'avg_access_count' => round($dist->avg_access_count, 1),
                    'avg_days' => round($dist->avg_days, 1)
                );
            }

            // CREAR DISTRIBUCIÓN COMPLETA: Incluir TODOS los formularios definidos
            // incluso si no tienen asignaciones (count = 0)
            $formatted_distribution = array();
            $total_assigned = intval( $stats->total_assigned );

            foreach ($formularios as $form_config) {
                $form_id = $form_config['id'];

                // Obtener datos reales o defaults si no hay asignaciones
                $dist_data = isset($distribution_map[$form_id]) 
                    ? $distribution_map[$form_id] 
                    : array('count' => 0, 'completed_count' => 0, 'avg_access_count' => 0, 'avg_days' => 0);

                // Obtener probabilidad teórica
                $theoretical_probability = isset($probabilidades[$form_id]) 
                    ? floatval($probabilidades[$form_id]) 
                    : 0;

                // Calcular porcentaje: usar real si hay datos, teórico si no
                $percentage = 0;
                if ($total_assigned > 0) {
                    $percentage = round(($dist_data['count'] / $total_assigned) * 100, 1);
                } else {
                    $percentage = $theoretical_probability;
                }

                // Obtener título del formulario
                $form_title = get_the_title($form_id);
                if (!$form_title) {
                    $form_title = "Formulario ID: {$form_id}";
                }

                $formatted_distribution[] = array(
                    'form_id' => $form_id,
                    'form_title' => $form_title,
                    'count' => $dist_data['count'],
                    'completed_count' => $dist_data['completed_count'],
                    'avg_access_count' => $dist_data['avg_access_count'],
                    'avg_days' => $dist_data['avg_days'],
                    'percentage' => $percentage,
                    'theoretical_probability' => $theoretical_probability
                );
            }

            // Determinar si está activa (tiene asignaciones recientes)
            $is_active = false;
            if ($stats->last_assignment) {
                $days_since_last = (time() - strtotime($stats->last_assignment)) / (60 * 60 * 24);
                $is_active = $days_since_last <= 30; // Activa si hay actividad en los últimos 30 días
            }

            // Formatear fechas
            $created_formatted = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($row->created_at));
            $last_assignment_formatted = $stats->last_assignment ? 
                human_time_diff(strtotime($stats->last_assignment)) . ' ago' : 
                'Nunca';

            $randomizations[] = array(
                'randomization_id' => $row->randomization_id,
                'method' => $row->method,
                'is_active' => $is_active,
                'created_at' => $row->created_at,
                'created_formatted' => $created_formatted,
                'last_assignment' => $stats->last_assignment,
                'last_assignment_formatted' => $last_assignment_formatted,
                'total_assigned' => intval( $stats->total_assigned ),
                'completed_count' => intval( $stats->completed_count ),
                'avg_access_count' => round( $stats->avg_access_count, 1 ),
                'avg_days' => round( $stats->avg_days, 1 ),
                'distribution' => $formatted_distribution,
                'formularios' => $formularios,
                'probabilidades' => $probabilidades
            );
        }

        wp_send_json_success(array(
            'randomizations' => $randomizations,
            'total_count' => count($randomizations)
        ));

    } catch (Exception $e) {
        error_log('[EIPSI Randomization] Error: ' . $e->getMessage());
        wp_send_json_error('Error interno del servidor');
    }
}

public static function eipsi_get_randomization_details() {
    // Verificar nonce y permisos
    if (!wp_verify_nonce($_POST['nonce'], 'eipsi_randomization_nonce') || !current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized', 403);
        return;
    }

    $randomization_id = sanitize_text_field($_POST['randomization_id'] ?? '');
    if (empty($randomization_id)) {
        wp_send_json_error('ID de aleatorización requerido');
        return;
    }

    global $wpdb;

    try {
        // Obtener configuración básica
        $config_query = "
            SELECT 
                rc.*,
                COUNT(DISTINCT ra.user_fingerprint) as total_assigned,
                COUNT(CASE WHEN ra.last_access IS NOT NULL THEN 1 END) as completed_count,
                MAX(ra.assigned_at) as last_assignment,
                MIN(ra.assigned_at) as first_assignment,
                AVG(ra.access_count) as avg_access_count,
                AVG(DATEDIFF(CURDATE(), DATE(ra.assigned_at))) as avg_days
            FROM {$wpdb->prefix}eipsi_randomization_configs rc
            LEFT JOIN {$wpdb->prefix}eipsi_randomization_assignments ra 
                ON rc.randomization_id = ra.randomization_id
            WHERE rc.randomization_id = %s
            GROUP BY rc.id
        ";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $config = $wpdb->get_row($wpdb->prepare($config_query, $randomization_id));

        if (!$config) {
            $configs_from_meta = eipsi_get_randomization_configs_from_post_meta();
            foreach ( $configs_from_meta as $meta_config ) {
                if ( $meta_config['randomization_id'] === $randomization_id ) {
                    $config = (object) array(
                        'randomization_id' => $meta_config['randomization_id'],
                        'formularios' => wp_json_encode( $meta_config['formularios'] ),
                        'probabilidades' => wp_json_encode( $meta_config['probabilidades'] ),
                        'method' => $meta_config['method'],
                        'created_at' => $meta_config['created_at'],
                        'updated_at' => $meta_config['updated_at'],
                        'show_instructions' => $meta_config['show_instructions'] ? 1 : 0,
                        'total_assigned' => null,
                        'completed_count' => null,
                        'last_assignment' => null,
                        'first_assignment' => null,
                        'avg_access_count' => null,
                        'avg_days' => null,
                    );

                    if ( function_exists( 'eipsi_save_randomization_config_to_db' ) ) {
                        eipsi_save_randomization_config_to_db(
                            $meta_config['randomization_id'],
                            array(
                                'formularios' => $meta_config['formularios'],
                                'probabilidades' => $meta_config['probabilidades'],
                                'method' => $meta_config['method'],
                                'manualAssignments' => $meta_config['manual_assignments'],
                                'showInstructions' => $meta_config['show_instructions'],
                            )
                        );
                    }
                    break;
                }
            }
        }

        if (!$config) {
            wp_send_json_error('Aleatorización no encontrada');
            return;
        }

        if ( $config->total_assigned === null ) {
            $stats_query = "
                SELECT 
                    COUNT(DISTINCT user_fingerprint) as total_assigned,
                    COUNT(CASE WHEN last_access IS NOT NULL THEN 1 END) as completed_count,
                    MAX(assigned_at) as last_assignment,
                    MIN(assigned_at) as first_assignment,
                    AVG(access_count) as avg_access_count,
                    AVG(DATEDIFF(CURDATE(), DATE(assigned_at))) as avg_days
                FROM {$wpdb->prefix}eipsi_randomization_assignments
                WHERE randomization_id = %s
            ";

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $stats = $wpdb->get_row( $wpdb->prepare( $stats_query, $randomization_id ) );

            if ( $stats ) {
                $config->total_assigned = $stats->total_assigned;
                $config->completed_count = $stats->completed_count;
                $config->last_assignment = $stats->last_assignment;
                $config->first_assignment = $stats->first_assignment;
                $config->avg_access_count = $stats->avg_access_count;
                $config->avg_days = $stats->avg_days;
            }
        }

        // Decodificar configuración
        $formularios = json_decode($config->formularios, true) ?: array();
        $probabilidades = json_decode($config->probabilidades, true) ?: array();

        // Obtener distribución real por formulario (solo formularios con asignaciones)
        $distribution_query = "
            SELECT 
                ra.assigned_form_id,
                COUNT(*) as total_assigned,
                COUNT(CASE WHEN ra.last_access IS NOT NULL THEN 1 END) as completed_count,
                COUNT(CASE WHEN ra.last_access IS NULL THEN 1 END) as dropout_count,
                AVG(ra.access_count) as avg_access_count,
                AVG(DATEDIFF(CURDATE(), DATE(ra.assigned_at))) as avg_days,
                MIN(ra.assigned_at) as first_assignment,
                MAX(ra.assigned_at) as last_assignment
            FROM {$wpdb->prefix}eipsi_randomization_assignments ra
            WHERE ra.randomization_id = %s
            GROUP BY ra.assigned_form_id
        ";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $distribution_raw = $wpdb->get_results($wpdb->prepare($distribution_query, $randomization_id));

        // Crear mapa de distribución para lookup rápido
        $distribution_map = array();
        foreach ($distribution_raw as $dist) {
            $distribution_map[$dist->assigned_form_id] = array(
                'total_assigned' => intval($dist->total_assigned),
                'completed_count' => intval($dist->completed_count),
                'dropout_count' => intval($dist->dropout_count),
                'avg_access_count' => round($dist->avg_access_count, 1),
                'avg_days' => round($dist->avg_days, 1),
                'first_assignment' => $dist->first_assignment,
                'last_assignment' => $dist->last_assignment
            );
        }

        // CREAR DISTRIBUCIÓN COMPLETA: Incluir TODOS los formularios definidos
        $distribution = array();
        foreach ($formularios as $form_config) {
            $form_id = $form_config['id'];

            // Obtener datos reales o defaults si no hay asignaciones
            $dist_data = isset($distribution_map[$form_id]) 
                ? $distribution_map[$form_id] 
                : array(
                    'total_assigned' => 0, 
                    'completed_count' => 0, 
                    'dropout_count' => 0,
                    'avg_access_count' => 0, 
                    'avg_days' => 0,
                    'first_assignment' => null,
                    'last_assignment' => null
                );

            $form_title = get_the_title($form_id) ?: "Formulario ID: {$form_id}";

            // Calcular tasas
            $completion_rate = $dist_data['total_assigned'] > 0 ? 
                round(($dist_data['completed_count'] / $dist_data['total_assigned']) * 100, 1) : 0;

            // Calcular porcentaje: usar real si hay datos, teórico si no
            $total_assigned_global = intval($config->total_assigned);
            $theoretical_probability = isset($probabilidades[$form_id]) 
                ? floatval($probabilidades[$form_id]) 
                : 0;

            $percentage = 0;
            if ($total_assigned_global > 0) {
                $percentage = round(($dist_data['total_assigned'] / $total_assigned_global) * 100, 1);
            } else {
                $percentage = $theoretical_probability;
            }

            $distribution[] = array(
                'form_id' => $form_id,
                'form_title' => $form_title,
                'total_assigned' => $dist_data['total_assigned'],
                'completed_count' => $dist_data['completed_count'],
                'dropout_count' => $dist_data['dropout_count'],
                'completion_rate' => $completion_rate,
                'avg_access_count' => $dist_data['avg_access_count'],
                'avg_days' => $dist_data['avg_days'],
                'first_assignment' => $dist_data['first_assignment'],
                'last_assignment' => $dist_data['last_assignment'],
                'percentage' => $percentage,
                'theoretical_probability' => $theoretical_probability
            );
        }

        // Calcular estadísticas generales
        $total_assigned = intval($config->total_assigned);
        $completed_count = intval($config->completed_count);
        $completion_rate = $total_assigned > 0 ? round(($completed_count / $total_assigned) * 100, 1) : 0;
        $dropout_count = $total_assigned - $completed_count;
        $dropout_rate = $total_assigned > 0 ? round(($dropout_count / $total_assigned) * 100, 1) : 0;

        // Formatear fechas
        $created_formatted = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($config->created_at));
        $first_assignment_formatted = $config->first_assignment ? 
            date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($config->first_assignment)) : 
            'N/A';
        $last_assignment_formatted = $config->last_assignment ? 
            human_time_diff(strtotime($config->last_assignment)) . ' ago' : 
            'Nunca';

        // Determinar estado
        $is_active = false;
        if ($config->last_assignment) {
            $days_since_last = (time() - strtotime($config->last_assignment)) / (60 * 60 * 24);
            $is_active = $days_since_last <= 30;
        }

        wp_send_json_success(array(
            'randomization_id' => $randomization_id,
            'created_at' => $config->created_at,
            'created_formatted' => $created_formatted,
            'method' => $config->method,
            'show_instructions' => (bool)$config->show_instructions,
            'is_active' => $is_active,
            'formularios' => $formularios,
            'probabilidades' => $probabilidades,
            
            // Estadísticas generales
            'total_assigned' => $total_assigned,
            'completed_count' => $completed_count,
            'dropout_count' => $dropout_count,
            'completion_rate' => $completion_rate,
            'dropout_rate' => $dropout_rate,
            'avg_access_count' => round($config->avg_access_count, 1),
            'avg_days' => round($config->avg_days, 1),
            
            // Distribución por formulario
            'distribution' => $distribution,
            
            // Timeline
            'first_assignment' => $config->first_assignment,
            'first_assignment_formatted' => $first_assignment_formatted,
            'last_assignment' => $config->last_assignment,
            'last_assignment_formatted' => $last_assignment_formatted
        ));

    } catch (Exception $e) {
        error_log('[EIPSI Randomization] Error en detalles: ' . $e->getMessage());
        wp_send_json_error('Error interno del servidor');
    }
}

public static function eipsi_get_randomization_users() {
    // Verificar nonce y permisos
    if (!wp_verify_nonce($_POST['nonce'], 'eipsi_randomization_nonce') || !current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized', 403);
        return;
    }

    $randomization_id = sanitize_text_field($_POST['randomization_id'] ?? '');
    $page = intval($_POST['page'] ?? 1);
    $limit = intval($_POST['limit'] ?? 50);
    $offset = ($page - 1) * $limit;

    if (empty($randomization_id)) {
        wp_send_json_error('ID de aleatorización requerido');
        return;
    }

    global $wpdb;

    try {
        // Query principal
        $query = "
            SELECT 
                ra.user_fingerprint,
                ra.assigned_form_id,
                ra.assigned_at,
                ra.last_access,
                ra.access_count,
                CASE WHEN ra.last_access IS NOT NULL THEN 'Sí' ELSE 'No' END as completado,
                DATEDIFF(CURDATE(), DATE(ra.assigned_at)) as dias_transcurridos
            FROM {$wpdb->prefix}eipsi_randomization_assignments ra
            WHERE ra.randomization_id = %s
            ORDER BY ra.assigned_at DESC
            LIMIT %d OFFSET %d
        ";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $results = $wpdb->get_results($wpdb->prepare($query, $randomization_id, $limit, $offset));

        // Query para total
        $count_query = "
            SELECT COUNT(*) as total
            FROM {$wpdb->prefix}eipsi_randomization_assignments
            WHERE randomization_id = %s
        ";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $total = $wpdb->get_var($wpdb->prepare($count_query, $randomization_id));

        $users = array();
        foreach ($results as $row) {
            $form_title = get_the_title($row->assigned_form_id) ?: "Formulario ID: {$row->assigned_form_id}";
            
            // Anonimizar fingerprint
            $fingerprint_short = substr($row->user_fingerprint, 0, 8) . '...' . substr($row->user_fingerprint, -6);

            // Formatear fecha de asignación
            $assigned_formatted = date_i18n('j M, H:i', strtotime($row->assigned_at));

            // Formatear último acceso
            $last_access_formatted = $row->last_access ? 
                human_time_diff(strtotime($row->last_access)) . ' ago' : 
                'Nunca';

            $users[] = array(
                'fingerprint' => $fingerprint_short,
                'fingerprint_full' => $row->user_fingerprint,
                'form_id' => $row->assigned_form_id,
                'form_title' => $form_title,
                'assigned_at' => $row->assigned_at,
                'assigned_formatted' => $assigned_formatted,
                'last_access' => $row->last_access,
                'last_access_formatted' => $last_access_formatted,
                'access_count' => intval($row->access_count),
                'completado' => $row->completado,
                'dias_transcurridos' => intval($row->dias_transcurridos)
            );
        }

        wp_send_json_success(array(
            'users' => $users,
            'total' => intval($total),
            'page' => $page,
            'limit' => $limit,
            'total_pages' => ceil($total / $limit)
        ));

    } catch (Exception $e) {
        error_log('[EIPSI Randomization] Error en usuarios: ' . $e->getMessage());
        wp_send_json_error('Error interno del servidor');
    }
}

public static function eipsi_get_distribution_stats() {
    // Verificar nonce y permisos
    if (!wp_verify_nonce($_POST['nonce'], 'eipsi_randomization_nonce') || !current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized', 403);
        return;
    }

    $randomization_id = sanitize_text_field($_POST['randomization_id'] ?? '');
    $format = sanitize_text_field($_POST['format'] ?? 'json');

    if (empty($randomization_id)) {
        wp_send_json_error('ID de aleatorización requerido');
        return;
    }

    global $wpdb;

    try {
        // Obtener configuración de aleatorización
        $config_query = "
            SELECT 
                rc.randomization_id,
                rc.formularios,
                rc.probabilidades,
                rc.created_at,
                COUNT(DISTINCT ra.user_fingerprint) as total_assigned,
                COUNT(CASE WHEN ra.last_access IS NOT NULL THEN 1 END) as completed_count
            FROM {$wpdb->prefix}eipsi_randomization_configs rc
            LEFT JOIN {$wpdb->prefix}eipsi_randomization_assignments ra 
                ON rc.randomization_id = ra.randomization_id
            WHERE rc.randomization_id = %s
            GROUP BY rc.id
        ";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $config = $wpdb->get_row($wpdb->prepare($config_query, $randomization_id));

        if (!$config) {
            wp_send_json_error('Aleatorización no encontrada');
            return;
        }

        // Decodificar configuración
        $formularios = json_decode($config->formularios, true) ?: array();
        $probabilidades = json_decode($config->probabilidades, true) ?: array();

        $total_assigned = intval($config->total_assigned);
        $completed_count = intval($config->completed_count);

        // Obtener distribución real por formulario
        $distribution_query = "
            SELECT 
                ra.assigned_form_id,
                COUNT(*) as assigned_count,
                COUNT(CASE WHEN ra.last_access IS NOT NULL THEN 1 END) as completed_count,
                AVG(ra.access_count) as avg_access_count,
                AVG(DATEDIFF(CURDATE(), DATE(ra.assigned_at))) as avg_days_to_complete
            FROM {$wpdb->prefix}eipsi_randomization_assignments ra
            WHERE ra.randomization_id = %s
            GROUP BY ra.assigned_form_id
        ";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $distribution_raw = $wpdb->get_results($wpdb->prepare($distribution_query, $randomization_id));

        // Crear mapa de distribución real
        $real_distribution = array();
        foreach ($distribution_raw as $dist) {
            $real_distribution[$dist->assigned_form_id] = array(
                'assigned_count' => intval($dist->assigned_count),
                'completed_count' => intval($dist->completed_count),
                'avg_access_count' => round($dist->avg_access_count, 1),
                'avg_days_to_complete' => round($dist->avg_days_to_complete, 1)
            );
        }

        // Procesar cada formulario configurado
        $formularios_stats = array();
        $total_drift_sum = 0;
        $max_drift = 0;
        $max_drift_form_id = null;

        foreach ($formularios as $form_config) {
            $form_id = intval($form_config['id']);
            $theoretical_probability = floatval($probabilidades[$form_id] ?? 0);

            // Obtener datos reales para este formulario
            $real_data = $real_distribution[$form_id] ?? array(
                'assigned_count' => 0,
                'completed_count' => 0,
                'avg_access_count' => 0,
                'avg_days_to_complete' => 0
            );

            // Calcular porcentajes reales
            $assigned_count = $real_data['assigned_count'];
            $assigned_percentage = $total_assigned > 0 ? 
                round(($assigned_count / $total_assigned) * 100, 1) : 0;

            // Calcular drift (diferencia entre real y teórico)
            $drift_percentage = 0;
            $drift_status = 'ok';

            if ($theoretical_probability > 0) {
                $drift = $assigned_percentage - $theoretical_probability;
                $drift_percentage = round(($drift / $theoretical_probability) * 100, 1);

                // Determinar status basado en thresholds
                if (abs($drift_percentage) <= 3) {
                    $drift_status = 'ok';
                } elseif (abs($drift_percentage) <= 5) {
                    $drift_status = 'warning';
                } else {
                    $drift_status = 'alert';
                }
            }

            // Calcular tasas de completado
            $completion_rate = $assigned_count > 0 ? 
                round(($real_data['completed_count'] / $assigned_count) * 100, 1) : 0;
            $dropout_rate = 100 - $completion_rate;

            // Obtener título del formulario
            $form_title = get_the_title($form_id) ?: "Formulario ID: {$form_id}";

            // Determinar indicador de estado
            $status_indicator = '✅';
            if ($drift_status === 'warning') {
                $status_indicator = '⚠️';
            } elseif ($drift_status === 'alert') {
                $status_indicator = '🔴';
            }

            $form_stats = array(
                'form_id' => $form_id,
                'form_title' => $form_title,
                'probability_theoretical' => $theoretical_probability,
                'assigned_count' => $assigned_count,
                'assigned_percentage' => $assigned_percentage,
                'completed_count' => $real_data['completed_count'],
                'completion_rate' => $completion_rate,
                'dropout_rate' => $dropout_rate,
                'drift_percentage' => $drift_percentage,
                'drift_status' => $drift_status,
                'avg_access_count' => $real_data['avg_access_count'],
                'avg_days_to_complete' => $real_data['avg_days_to_complete'],
                'status_indicator' => $status_indicator
            );

            $formularios_stats[] = $form_stats;

            // Actualizar estadísticas globales
            $total_drift_sum += abs($drift_percentage);
            if (abs($drift_percentage) > $max_drift) {
                $max_drift = abs($drift_percentage);
                $max_drift_form_id = $form_id;
            }
        }

        // Calcular resumen global
        $avg_drift = count($formularios_stats) > 0 ? $total_drift_sum / count($formularios_stats) : 0;
        $overall_completion_rate = $total_assigned > 0 ? 
            round(($completed_count / $total_assigned) * 100, 1) : 0;

        // Calcular health score (0-100)
        $health_score = calculate_health_score($avg_drift, $overall_completion_rate, $total_assigned);

        // Determinar estado general
        $overall_status = 'ok';
        if ($max_drift > 5) {
            $overall_status = 'alert';
        } elseif ($max_drift > 3) {
            $overall_status = 'warning';
        }

        // Generar recomendación
        $recommendation = generate_distribution_recommendation($overall_status, $max_drift, $max_drift_form_id, $formularios);

        // Calcular margen de error (95% CI)
        $margin_error = calculate_margin_error($total_assigned);

        // Respuesta
        $response = array(
            'success' => true,
            'randomization_id' => $randomization_id,
            'created_at' => $config->created_at,
            'total_assigned' => $total_assigned,
            'completed_count' => $completed_count,
            'formularios' => $formularios_stats,
            'summary' => array(
                'total_drift' => round($total_drift_sum, 1),
                'max_drift' => $max_drift,
                'max_drift_form_id' => $max_drift_form_id,
                'overall_status' => $overall_status,
                'health_score' => $health_score,
                'recommendation' => $recommendation
            ),
            'metadata' => array(
                'calculation_timestamp' => time(),
                'sample_size_note' => $total_assigned . ' asignaciones (±' . $margin_error . '% error margin)'
            )
        );

        wp_send_json_success($response);

    } catch (Exception $e) {
        error_log('[EIPSI Distribution Stats] Error: ' . $e->getMessage());
        wp_send_json_error('Error interno del servidor');
    }
}

public static function calculate_health_score($avg_drift, $completion_rate, $sample_size) {
    // Base score
    $score = 100;
    
    // Penalizar drift alto
    $drift_penalty = min($avg_drift * 2, 30); // Max 30 puntos por drift
    $score -= $drift_penalty * 0.5;
    
    // Penalizar completado bajo
    $completion_penalty = max(0, 100 - $completion_rate);
    $score -= $completion_penalty * 0.4;
    
    // Penalizar muestra pequeña
    $min_sample_size = 100; // Tamaño mínimo para análisis confiable
    $sample_penalty = max(0, (1 - ($sample_size / $min_sample_size)) * 20);
    $score -= $sample_penalty;
    
    return max(0, min(100, round($score)));
}

public static function generate_distribution_recommendation($overall_status, $max_drift, $max_drift_form_id, $formularios) {
    if ($overall_status === 'ok') {
        return 'Distribución saludable. Los desbalances están dentro del rango estadísticamente esperado.';
    }
    
    if ($overall_status === 'warning') {
        $form_title = 'Formulario desconocido';
        foreach ($formularios as $form) {
            if (intval($form['id']) === $max_drift_form_id) {
                $form_title = get_the_title($form['id']) ?: 'Formulario ID: ' . $form['id'];
                break;
            }
        }
        
        return "Monitorear {$form_title} (drift: {$max_drift}%). Verificar que no haya sesgos sistemáticos.";
    }
    
    if ($overall_status === 'alert') {
        $form_title = 'Formulario desconocido';
        foreach ($formularios as $form) {
            if (intval($form['id']) === $max_drift_form_id) {
                $form_title = get_the_title($form['id']) ?: 'Formulario ID: ' . $form['id'];
                break;
            }
        }
        
        $actions = array();
        
        if ($max_drift > 10) {
            $actions[] = 'Verificar configuración de probabilidades';
            $actions[] = 'Revisar algoritmo de seed';
            $actions[] = 'Validar persistencia de asignaciones';
        }
        
        $actions[] = 'Aumentar tamaño de muestra';
        $actions[] = 'Investigar posibles sesgos en fingerprinting';
        
        return "ALERTA: {$form_title} muestra drift crítico ({$max_drift}%). " . implode('. ', $actions) . '.';
    }
    
    return 'Revisar configuración de distribución.';
}

public static function calculate_margin_error($n) {
    if ($n <= 0) return 100;

    // Fórmula: 1.96 * sqrt(p(1-p)/n) * 100
    // Asumiendo p=0.5 (peor caso)
    $margin = 1.96 * sqrt(0.5 * 0.5 / $n) * 100;

    return round($margin, 1);
}

public static function eipsi_get_manual_overrides() {
    // Verificar nonce y permisos
    if (!wp_verify_nonce($_POST['nonce'], 'eipsi_randomization_nonce') || !current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized', 403);
        return;
    }

    $randomization_id = sanitize_text_field($_POST['randomization_id'] ?? '');
    if (empty($randomization_id)) {
        wp_send_json_error('ID de aleatorización requerido');
        return;
    }

    // Verificar que la configuración existe
    if (!function_exists('eipsi_check_config_exists') || !eipsi_check_config_exists($randomization_id)) {
        wp_send_json_error('Configuración no encontrada');
        return;
    }

    global $wpdb;

    try {
        $table_name = $wpdb->prefix . 'eipsi_manual_overrides';

        // Query para obtener overrides
        $query = "
            SELECT
                id,
                randomization_id,
                user_fingerprint,
                assigned_form_id,
                reason,
                created_by,
                created_at,
                updated_at,
                status,
                expires_at
            FROM {$table_name}
            WHERE randomization_id = %s
            ORDER BY created_at DESC
        ";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $results = $wpdb->get_results($wpdb->prepare($query, $randomization_id));

        $overrides = array();
        foreach ($results as $row) {
            // Obtener título del formulario
            $form_title = get_the_title($row->assigned_form_id);
            if (!$form_title) {
                $form_title = "Formulario ID: {$row->assigned_form_id}";
            }

            // Obtener nombre del creador
            $creator = get_userdata($row->created_by);
            $creator_name = $creator ? $creator->display_name : 'Desconocido';

            // Anonimizar fingerprint (8 chars + ...)
            $fingerprint_short = substr($row->user_fingerprint, 0, 8) . '...' . substr($row->user_fingerprint, -6);

            // Formatear fechas
            $created_formatted = date_i18n('j M, H:i', strtotime($row->created_at));
            $expires_formatted = $row->expires_at ? date_i18n('j M, H:i', strtotime($row->expires_at)) : 'Nunca';

            // Determinar si está expirado
            $is_expired = false;
            if ($row->expires_at && strtotime($row->expires_at) < time()) {
                $is_expired = true;
            }

            $overrides[] = array(
                'id' => $row->id,
                'randomization_id' => $row->randomization_id,
                'fingerprint' => $fingerprint_short,
                'fingerprint_full' => $row->user_fingerprint,
                'assigned_form_id' => $row->assigned_form_id,
                'form_title' => $form_title,
                'reason' => $row->reason,
                'created_by' => $row->created_by,
                'creator_name' => $creator_name,
                'created_at' => $row->created_at,
                'created_formatted' => $created_formatted,
                'status' => $row->status,
                'expires_at' => $row->expires_at,
                'expires_formatted' => $expires_formatted,
                'is_expired' => $is_expired
            );
        }

        wp_send_json_success(array(
            'overrides' => $overrides,
            'total' => count($overrides)
        ));

    } catch (Exception $e) {
        error_log('[EIPSI Manual Overrides] Error: ' . $e->getMessage());
        wp_send_json_error('Error interno del servidor');
    }
}

public static function eipsi_create_manual_override() {
    // Verificar nonce y permisos
    if (!wp_verify_nonce($_POST['nonce'], 'eipsi_randomization_nonce') || !current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized', 403);
        return;
    }

    $randomization_id = sanitize_text_field($_POST['randomization_id'] ?? '');
    $user_fingerprint = sanitize_text_field($_POST['user_fingerprint'] ?? '');
    $assigned_form_id = intval($_POST['assigned_form_id'] ?? 0);
    $reason = sanitize_textarea_field($_POST['reason'] ?? '');
    $expires_days = intval($_POST['expires_days'] ?? 0);

    // Validaciones
    if (empty($randomization_id) || empty($user_fingerprint) || empty($assigned_form_id)) {
        wp_send_json_error('Faltan parámetros requeridos');
        return;
    }

    // Verificar que la configuración existe
    if (!function_exists('eipsi_check_config_exists') || !eipsi_check_config_exists($randomization_id)) {
        wp_send_json_error('Configuración no encontrada');
        return;
    }

    // Verificar que el formulario existe
    $form = get_post($assigned_form_id);
    if (!$form || $form->post_status !== 'publish') {
        wp_send_json_error('Formulario no encontrado');
        return;
    }

    global $wpdb;

    try {
        $table_name = $wpdb->prefix . 'eipsi_manual_overrides';
        $current_user_id = get_current_user_id();

        // Calcular expires_at si se especifica días
        $expires_at = null;
        if ($expires_days > 0) {
            $expires_at = date('Y-m-d H:i:s', strtotime("+{$expires_days} days"));
        }

        // Usar ON DUPLICATE KEY UPDATE para INSERT o UPDATE
        // La UNIQUE KEY (randomization_id, user_fingerprint) garantiza 1 override por usuario/config
        $result = EIPSI_Randomization_Override_Service::save($randomization_id, $user_fingerprint, $assigned_form_id, $reason, $current_user_id, $expires_at);

        if ($result === false) {
            throw new Exception($wpdb->last_error);
        }

        error_log("[EIPSI Manual Overrides] Override creado/actualizado: {$randomization_id} / {$user_fingerprint} → Form {$assigned_form_id}");

        wp_send_json_success(array(
            'message' => 'Asignación manual guardada correctamente',
            'randomization_id' => $randomization_id,
            'user_fingerprint' => $user_fingerprint,
            'assigned_form_id' => $assigned_form_id
        ));

    } catch (Exception $e) {
        error_log('[EIPSI Manual Overrides] Error al crear: ' . $e->getMessage());
        wp_send_json_error('Error al guardar asignación manual: ' . $e->getMessage());
    }
}

public static function eipsi_revoke_manual_override() {
    // Verificar nonce y permisos
    if (!wp_verify_nonce($_POST['nonce'], 'eipsi_randomization_nonce') || !current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized', 403);
        return;
    }

    $override_id = intval($_POST['override_id'] ?? 0);
    if (empty($override_id)) {
        wp_send_json_error('ID de override requerido');
        return;
    }

    global $wpdb;

    try {
        $table_name = $wpdb->prefix . 'eipsi_manual_overrides';

        // Actualizar status a revoked
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $result = $wpdb->update(
            $table_name,
            array('status' => 'revoked'),
            array('id' => $override_id),
            array('%s'),
            array('%d')
        );

        if ($result === false) {
            throw new Exception($wpdb->last_error);
        }

        error_log("[EIPSI Manual Overrides] Override revocado: ID {$override_id}");

        wp_send_json_success(array(
            'message' => 'Asignación manual revocada correctamente',
            'override_id' => $override_id
        ));

    } catch (Exception $e) {
        error_log('[EIPSI Manual Overrides] Error al revocar: ' . $e->getMessage());
        wp_send_json_error('Error al revocar asignación manual: ' . $e->getMessage());
    }
}

public static function eipsi_delete_manual_override() {
    // Verificar nonce y permisos
    if (!wp_verify_nonce($_POST['nonce'], 'eipsi_randomization_nonce') || !current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized', 403);
        return;
    }

    $override_id = intval($_POST['override_id'] ?? 0);
    if (empty($override_id)) {
        wp_send_json_error('ID de override requerido');
        return;
    }

    global $wpdb;

    try {
        $table_name = $wpdb->prefix . 'eipsi_manual_overrides';

        // DELETE permanente
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $result = $wpdb->delete(
            $table_name,
            array('id' => $override_id),
            array('%d')
        );

        if ($result === false) {
            throw new Exception($wpdb->last_error);
        }

        error_log("[EIPSI Manual Overrides] Override eliminado: ID {$override_id}");

        wp_send_json_success(array(
            'message' => 'Asignación manual eliminada correctamente',
            'override_id' => $override_id
        ));

    } catch (Exception $e) {
        error_log('[EIPSI Manual Overrides] Error al eliminar: ' . $e->getMessage());
        wp_send_json_error('Error al eliminar asignación manual: ' . $e->getMessage());
    }
}
}
