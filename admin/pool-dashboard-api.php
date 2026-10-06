<?php
/**
 * Pool Dashboard AJAX API.
 *
 * @package EIPSI_Forms
 * @since 2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
require_once EIPSI_FORMS_PLUGIN_DIR.'includes/pools/bootstrap.php';


/**
 * Get pool analytics data.
 */
function eipsi_get_pool_analytics() { return EIPSI_Pool_Dashboard_Adapter::eipsi_get_pool_analytics(); }
// Registration owned by the pool API's explicit contract dispatcher.

/**
 * Export pool assignments to CSV.
 */
function eipsi_export_pool_assignments() { return EIPSI_Pool_Dashboard_Adapter::eipsi_export_pool_assignments(); }
// Registration owned by the pool API's explicit contract dispatcher.
