<?php
/**
 * Longitudinal Study Admin Page
 *
 * Admin page with tabs:
 * - Create Study
 * - Dashboard Study
 * - Recordatorios y Notificaciones
 * - Email Log & Dropout
 * - Longitudinal Pools
 * - Pool Analytics
 * - Export
 *
 * @package EIPSI_Forms
 * @since 1.4.0
 * @since 2.1.2 - Removed Waves Manager tab (merged into Dashboard)
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handle pool deletion early via admin_init (before any output)
 * This avoids "headers already sent" errors
 */
add_action('admin_init', 'eipsi_handle_pool_deletion_early', 1);

function eipsi_handle_pool_deletion_early() { return; }

function eipsi_display_longitudinal_study_page() { wp_die('Esta sección fue retirada.',410); }
