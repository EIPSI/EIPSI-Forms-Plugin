<?php
/**
 * Pool Studies REST API
 * Phase 1 of Pool Randomization System (v2.5.3)
 *
 * Provides REST endpoints for pool study detection, configuration,
 * participant assignment, and analytics.
 *
 * @package EIPSI_Forms
 * @since 2.5.3
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register Pool Studies REST API routes
 */
add_action('rest_api_init', 'eipsi_register_pool_rest_routes');

function eipsi_register_pool_rest_routes() {
    $namespace = 'eipsi/v1';

    // GET /eipsi/v1/pool-detect - Validate study IDs
    register_rest_route($namespace, '/pool-detect', array(
        'methods' => WP_REST_Server::READABLE,
        'callback' => 'eipsi_rest_pool_detect',
        'permission_callback' => function() {
            return current_user_can('manage_options');
        },
        'args' => array(
            'study_ids' => array(
                'required' => true,
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ),
        ),
    ));

    // POST /eipsi/v1/pool-config - Save pool configuration
    register_rest_route($namespace, '/pool-config', array(
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'eipsi_rest_pool_config',
        'permission_callback' => function() {
            return current_user_can('manage_options');
        },
    ));

    // POST /eipsi/v1/pool-assign - Assign participant to study
    register_rest_route($namespace, '/pool-assign', array(
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'eipsi_rest_pool_assign',
        'permission_callback' => 'eipsi_rest_pool_assign_permission',
    ));

    // GET /eipsi/v1/pool-analytics - Get pool analytics
    register_rest_route($namespace, '/pool-analytics', array(
        'methods' => WP_REST_Server::READABLE,
        'callback' => 'eipsi_rest_pool_analytics',
        'permission_callback' => function() {
            return current_user_can('manage_options');
        },
        'args' => array(
            'pool_id' => array(
                'required' => true,
                'type' => 'integer',
                'sanitize_callback' => 'absint',
            ),
        ),
    ));
}

/**
 * GET /eipsi/v1/pool-detect
 * Validate study IDs and return study details
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function eipsi_rest_pool_detect(WP_REST_Request $request) {
    global $wpdb;

    $study_ids_input = $request->get_param('study_ids');
    $study_ids = array_map('intval', explode(',', $study_ids_input));
    $study_ids = array_filter($study_ids); // Remove empty values

    if (empty($study_ids)) {
        return new WP_REST_Response(array(
            'valid' => array(),
            'invalid' => array(),
        ), 200);
    }

    $studies_table = $wpdb->prefix . 'survey_studies';
    $participants_table = $wpdb->prefix . 'survey_participants';
    $waves_table = $wpdb->prefix . 'survey_waves';

    $valid_studies = array();
    $invalid_studies = array();

    foreach ($study_ids as $study_id) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $study = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, study_code, study_name, status, config 
                 FROM {$studies_table} 
                 WHERE id = %d AND status = 'active'",
                $study_id
            ),
            ARRAY_A
        );

        if ($study) {
            // Get active participants count
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $active_participants = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$participants_table} 
                     WHERE survey_id = %d AND is_active = 1",
                    $study_id
                )
            );

            // Get waves count
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $waves_count = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$waves_table} WHERE study_id = %d",
                    $study_id
                )
            );

            $config = json_decode($study['config'], true) ?: array();

            $valid_studies[] = array(
                'id' => intval($study['id']),
                'name' => $study['study_name'],
                'code' => $study['study_code'],
                'active_participants' => intval($active_participants),
                'waves' => intval($waves_count),
                'shortcode_page_url' => $config['shortcode_page_url'] ?? '',
            );
        } else {
            $invalid_studies[] = $study_id;
        }
    }

    return new WP_REST_Response(array(
        'valid' => $valid_studies,
        'invalid' => $invalid_studies,
    ), 200);
}

/**
 * POST /eipsi/v1/pool-config
 * Save pool configuration
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function eipsi_rest_pool_config(WP_REST_Request $request) {
    global $wpdb;

    $params = $request->get_json_params();

    $pool_id = isset($params['pool_id']) ? intval($params['pool_id']) : 0;
    $studies = isset($params['studies']) ? $params['studies'] : array();
    $method = isset($params['method']) ? sanitize_text_field($params['method']) : 'seeded';
    $seed = isset($params['seed']) ? sanitize_text_field($params['seed']) : '';

    // Validate studies array
    if (!is_array($studies) || empty($studies)) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Se requiere al menos un estudio.',
        ), 400);
    }

    // Validate probability sum = 100
    $total_probability = array_sum(array_column($studies, 'probability'));
    if ($total_probability < 99.9 || $total_probability > 100.1) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => sprintf('La suma de probabilidades debe ser 100%% (±0.1%% tolerancia). Actual: %.2f%%', $total_probability),
        ), 400);
    }

    $pools_table = $wpdb->prefix . 'eipsi_longitudinal_pools';

    // Build config JSON
    $config = array(
        'studies' => $studies,
        'method' => $method,
        'seed' => $seed,
        'updated_at' => current_time('mysql'),
    );

    if ($pool_id > 0) {
        // -----------------------------------------------------------------
        // VALIDACIÓN: Verificar si hay asignaciones existentes antes de permitir
        // edición de campos críticos (estudios, probabilidades, método, seed)
        // -----------------------------------------------------------------
        $assignments_table = $wpdb->prefix . 'eipsi_pool_assignments';
        $existing_assignments = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$assignments_table} WHERE pool_id = %d",
            $pool_id
        ));
        
        if ($existing_assignments > 0) {
            // Obtener config actual para comparar
            $current_pool = $wpdb->get_row($wpdb->prepare(
                "SELECT config FROM {$pools_table} WHERE id = %d",
                $pool_id
            ));
            
            if ($current_pool && $current_pool->config) {
                $current_config = json_decode($current_pool->config, true);
                
                // Verificar si intentan cambiar campos críticos
                $critical_fields_changed = false;
                $changed_fields = array();
                
                // Comparar estudios (solo los IDs de estudio, no las probabilidades exactas)
                $current_studies = isset($current_config['studies']) ? array_column($current_config['studies'], 'id') : array();
                $new_studies = isset($config['studies']) ? array_column($config['studies'], 'id') : array();
                sort($current_studies);
                sort($new_studies);
                
                if ($current_studies !== $new_studies) {
                    $critical_fields_changed = true;
                    $changed_fields[] = 'studies';
                }
                
                // Comparar método de asignación
                if (isset($current_config['method']) && isset($config['method']) && 
                    $current_config['method'] !== $config['method']) {
                    $critical_fields_changed = true;
                    $changed_fields[] = 'method';
                }
                
                // Comparar seed (si cambia, la asignación ya no es reproducible)
                if (isset($current_config['seed']) && isset($config['seed']) && 
                    $current_config['seed'] !== $config['seed']) {
                    $critical_fields_changed = true;
                    $changed_fields[] = 'seed';
                }
                
                if ($critical_fields_changed) {
                    error_log("[EIPSI POOL EDIT] BLOQUEADO: Intentaron cambiar campos críticos (" . implode(', ', $changed_fields) . ") en pool {$pool_id} con {$existing_assignments} asignaciones existentes");
                    return new WP_REST_Response(array(
                        'success' => false,
                        'message' => 'No se pueden modificar los estudios, método de asignación o semilla aleatoria porque ya existen ' . $existing_assignments . ' participante(s) asignado(s). Estos campos afectan la integridad de las asignaciones existentes.',
                        'code' => 'assignments_exist',
                        'field_errors' => $changed_fields,
                    ), 409);
                }
            }
        }
        
        // Update existing pool (solo campos permitidos)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->update(
            $pools_table,
            array(
                'config' => wp_json_encode($config),
                'updated_at' => current_time('mysql'),
            ),
            array('id' => $pool_id),
            array('%s', '%s'),
            array('%d')
        );

        if ($result === false) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Error al actualizar el pool: ' . $wpdb->last_error,
            ), 500);
        }
        
        error_log("[EIPSI POOL EDIT] Pool {$pool_id} actualizado correctamente");

        return new WP_REST_Response(array(
            'success' => true,
            'pool_id' => $pool_id,
            'message' => 'Pool actualizado correctamente.',
        ), 200);
    } else {
        // Create new pool
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->insert(
            $pools_table,
            array(
                'name' => isset($params['name']) ? sanitize_text_field($params['name']) : 'Pool ' . wp_date('Y-m-d H:i'),
                'config' => wp_json_encode($config),
                'status' => 'active',
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ),
            array('%s', '%s', '%s', '%s', '%s')
        );

        if ($result === false) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Error al crear el pool: ' . $wpdb->last_error,
            ), 500);
        }

        $new_pool_id = $wpdb->insert_id;

        return new WP_REST_Response(array(
            'success' => true,
            'pool_id' => $new_pool_id,
            'message' => 'Pool creado correctamente.',
        ), 201);
    }
}

/**
 * POST /eipsi/v1/pool-assign
 * Assign participant to a study in the pool
 * Fase 3: Usa EIPSI_Pool_Assignment_Service para toda la lógica de asignación.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function eipsi_rest_pool_assign_permission(WP_REST_Request $request) {
    $access = EIPSI_Auth_Service::authorize_session_context((array) $request->get_json_params());
    if (!$access['success']) {
        return new WP_Error($access['error'], 'La sesión no autoriza esta asignación.', array(
            'status' => $access['error'] === 'authentication_required' ? 401 : 403,
        ));
    }
    return true;
}

function eipsi_rest_pool_assign(WP_REST_Request $request) {
    $params = $request->get_json_params();

    // Revalidate even when called directly: client IDs may only confirm the session.
    $access = EIPSI_Auth_Service::authorize_session_context((array) $params);
    if (!$access['success']) {
        return new WP_REST_Response(array('success' => false, 'message' => 'La sesión no autoriza esta asignación.'),
            $access['error'] === 'authentication_required' ? 401 : 403);
    }

    $pool_id        = isset($params['pool_id']) ? intval($params['pool_id']) : 0;
    $participant_id = (string) $access['participant_id'];

    if ($pool_id <= 0) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Se requiere pool_id.',
        ), 400);
    }

    // Usar el servicio de asignación (Fase 3)
    if (!class_exists('EIPSI_Pool_Assignment_Service')) {
        require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-pool-assignment-service.php';
    }

    $service = new EIPSI_Pool_Assignment_Service();

    // Obtener pool para saber el método
    $pool = $service->get_pool($pool_id);
    $method = 'seeded';
    if ($pool && !empty($pool->config)) {
        $config = json_decode($pool->config, true);
        $method = isset($config['method']) ? $config['method'] : 'seeded';
    }

    // Asignar participante
    $assignment = $service->assign_participant($pool_id, $participant_id, $method);

    if (!$assignment) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Error al asignar el participante al pool.',
        ), 500);
    }

    // Obtener URL del estudio
    $study_url = $service->get_study_url($assignment->study_id);

    return new WP_REST_Response(array(
        'success'      => true,
        'assignment_id'=> $assignment->id,
        'study_id'     => $assignment->study_id,
        'study_url'    => $study_url,
        'is_existing'  => $assignment->is_existing,
        'completed'    => $assignment->completed,
    ), $assignment->is_existing ? 200 : 201);
}

/**
 * Weighted random selection of study
 *
 * @param array $studies Array of studies with probability
 * @param string $participant_id For seeded random
 * @param string $method 'seeded' or 'pure-random'
 * @param string $seed Seed for seeded random
 * @return array|false Selected study or false on error
 */
function eipsi_weighted_random_select($studies, $participant_id, $method = 'seeded', $seed = '') {
    if (empty($studies)) {
        return false;
    }

    // If only one study, return it
    if (count($studies) === 1) {
        return $studies[0];
    }

    // For seeded method, use participant_id + seed to generate deterministic assignment
    if ($method === 'seeded' && !empty($seed)) {
        $hash = md5($participant_id . $seed);
        $random_value = hexdec(substr($hash, 0, 8)) / 0xFFFFFFFF;
    } else {
        // Pure random
        $random_value = mt_rand() / mt_getrandmax();
    }

    // Weighted random selection
    $cumulative = 0;
    $total_probability = array_sum(array_column($studies, 'probability'));

    foreach ($studies as $study) {
        $probability = isset($study['probability']) ? floatval($study['probability']) : 0;
        $cumulative += $probability / $total_probability;

        if ($random_value <= $cumulative) {
            return $study;
        }
    }

    // Fallback to last study
    return end($studies);
}

/**
 * GET /eipsi/v1/pool-analytics
 * Get pool analytics and distribution stats
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function eipsi_rest_pool_analytics(WP_REST_Request $request) {
    global $wpdb;

    $pool_id = $request->get_param('pool_id');

    $assignments_table = $wpdb->prefix . 'eipsi_pool_assignments';
    $studies_table = $wpdb->prefix . 'survey_studies';
    $pools_table = $wpdb->prefix . 'eipsi_longitudinal_pools';

    // Get pool config
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $pool = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT config FROM {$pools_table} WHERE id = %d",
            $pool_id
        ),
        ARRAY_A
    );

    if (!$pool) {
        return new WP_REST_Response(array(
            'success' => false,
            'message' => 'Pool no encontrado.',
        ), 404);
    }

    $config = json_decode($pool['config'], true) ?: array();
    $configured_studies = $config['studies'] ?? array();

    // Build expected percentages map
    $expected_percentages = array();
    foreach ($configured_studies as $study) {
        $expected_percentages[$study['id']] = floatval($study['probability']);
    }

    // Get total assignments
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $total_assignments = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$assignments_table} WHERE pool_id = %d",
            $pool_id
        )
    );

    // Get per-study stats
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $study_stats = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT 
                a.study_id,
                s.study_name,
                COUNT(*) as assignments,
                SUM(CASE WHEN a.completed = 1 THEN 1 ELSE 0 END) as completions,
                SUM(CASE WHEN a.completed = 0 THEN 1 ELSE 0 END) as in_progress
             FROM {$assignments_table} a
             LEFT JOIN {$studies_table} s ON a.study_id = s.id
             WHERE a.pool_id = %d
             GROUP BY a.study_id, s.study_name",
            $pool_id
        ),
        ARRAY_A
    );

    $studies_data = array();
    foreach ($study_stats as $stat) {
        $assignments = intval($stat['assignments']);
        $completions = intval($stat['completions']);
        $in_progress = intval($stat['in_progress']);

        $real_pct = $total_assignments > 0 ? round(($assignments / $total_assignments) * 100, 1) : 0;
        $completion_rate = $assignments > 0 ? round(($completions / $assignments) * 100, 1) : 0;

        $studies_data[] = array(
            'study_id' => intval($stat['study_id']),
            'name' => $stat['study_name'] ?: 'Estudio #' . $stat['study_id'],
            'expected_pct' => $expected_percentages[$stat['study_id']] ?? 0,
            'real_pct' => $real_pct,
            'assignments' => $assignments,
            'completions' => $completions,
            'completion_rate' => $completion_rate,
            'in_progress' => $in_progress,
            'dropouts' => 0, // Calculated separately if needed
        );
    }

    return new WP_REST_Response(array(
        'success' => true,
        'total_assignments' => intval($total_assignments),
        'studies' => $studies_data,
    ), 200);
}
