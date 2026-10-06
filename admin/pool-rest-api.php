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
require_once EIPSI_FORMS_PLUGIN_DIR.'includes/pools/bootstrap.php';


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
function eipsi_rest_pool_detect(WP_REST_Request $request) { return EIPSI_Pool_Rest_Adapter::eipsi_rest_pool_detect($request); }

/**
 * POST /eipsi/v1/pool-config
 * Save pool configuration
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function eipsi_rest_pool_config(WP_REST_Request $request) { return EIPSI_Pool_Rest_Adapter::eipsi_rest_pool_config($request); }

/**
 * POST /eipsi/v1/pool-assign
 * Assign participant to a study in the pool
 * Fase 3: Usa EIPSI_Pool_Assignment_Service para toda la lógica de asignación.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function eipsi_rest_pool_assign_permission(WP_REST_Request $request) { return EIPSI_Pool_Rest_Adapter::eipsi_rest_pool_assign_permission($request); }

function eipsi_rest_pool_assign(WP_REST_Request $request) { return EIPSI_Pool_Rest_Adapter::eipsi_rest_pool_assign($request); }

/**
 * Weighted random selection of study
 *
 * @param array $studies Array of studies with probability
 * @param string $participant_id For seeded random
 * @param string $method 'seeded' or 'pure-random'
 * @param string $seed Seed for seeded random
 * @return array|false Selected study or false on error
 */
function eipsi_weighted_random_select($studies, $participant_id, $method = 'seeded', $seed = '') { return EIPSI_Pool_Algorithm_Service::eipsi_weighted_random_select($studies, $participant_id, $method, $seed); }

/**
 * GET /eipsi/v1/pool-analytics
 * Get pool analytics and distribution stats
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function eipsi_rest_pool_analytics(WP_REST_Request $request) { return EIPSI_Pool_Rest_Adapter::eipsi_rest_pool_analytics($request); }
