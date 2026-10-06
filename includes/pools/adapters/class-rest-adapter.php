<?php
/** M7 owner. Public compatibility adapters retain registration and signatures. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Pool_Rest_Adapter {

public static function eipsi_rest_pool_detect(WP_REST_Request $request) {
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

public static function eipsi_rest_pool_config(WP_REST_Request $request) {
    $result = EIPSI_Pool_Service::configure((array)$request->get_json_params());
    return new WP_REST_Response($result['data'], $result['status']);
}

public static function eipsi_rest_pool_assign_permission(WP_REST_Request $request) {
    $access = EIPSI_Auth_Service::authorize_session_context((array) $request->get_json_params());
    if (!$access['success']) {
        return new WP_Error($access['error'], 'La sesión no autoriza esta asignación.', array(
            'status' => $access['error'] === 'authentication_required' ? 401 : 403,
        ));
    }
    return true;
}

public static function eipsi_rest_pool_assign(WP_REST_Request $request) {
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

public static function eipsi_rest_pool_analytics(WP_REST_Request $request) {
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
}
