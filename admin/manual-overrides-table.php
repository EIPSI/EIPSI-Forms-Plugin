<?php
/**
 * EIPSI Forms - Manual Overrides Table Setup
 *
 * Crea la tabla wp_eipsi_manual_overrides para asignaciones manuales de randomización
 *
 * @package EIPSI_Forms
 * @since 1.4.5
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Crear tabla wp_eipsi_manual_overrides
 */
function eipsi_create_manual_overrides_table() { require_once EIPSI_FORMS_PLUGIN_DIR.'includes/schema/bootstrap.php'; $result = EIPSI_Schema_Repair_Service::sync_local_table('eipsi_manual_overrides');  }

// Hook para ejecutar creación de tabla al activar el plugin
add_action('admin_init', 'eipsi_ensure_manual_overrides_table');

function eipsi_ensure_manual_overrides_table() {
    // Verificar si la tabla existe
    global $wpdb;
    $table_name = $wpdb->prefix . 'eipsi_manual_overrides';

    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'");

    if (!$table_exists) {
        eipsi_create_manual_overrides_table();
    }
}
