<?php
/**
 * EIPSI Forms - Randomization Database Setup
 * 
 * Crea las tablas necesarias para el sistema de aleatorización RCT:
 * - wp_eipsi_randomization_configs: Configuraciones de estudios
 * - wp_eipsi_randomization_assignments: Asignaciones de usuarios
 * 
 * @package EIPSI_Forms
 * @since 1.3.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
require_once EIPSI_FORMS_PLUGIN_DIR.'includes/randomization/bootstrap.php';


/**
 * Crear tabla de configuraciones de aleatorización
 * 
 * Almacena la configuración de cada estudio RCT:
 * - Qué formularios participan
 * - Probabilidades de asignación
 * - Método (seeded vs pure-random)
 * - Asignaciones manuales
 */
function eipsi_create_randomization_configs_table() {
    global $wpdb;

    $table_name      = $wpdb->prefix . 'eipsi_randomization_configs';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        randomization_id VARCHAR(255) NOT NULL,
        formularios LONGTEXT NOT NULL,
        probabilidades LONGTEXT,
        method VARCHAR(20) DEFAULT 'seeded',
        manual_assignments LONGTEXT,
        show_instructions TINYINT(1) DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY randomization_id (randomization_id),
        KEY method (method),
        KEY created_at (created_at)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );

    // Verificar si se creó correctamente
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table_name}'" );
    
    if ( $table_exists === $table_name ) {
        error_log( '[EIPSI Forms] Tabla creada: ' . $table_name );
        return true;
    } else {
        error_log( '[EIPSI Forms] ERROR: No se pudo crear tabla ' . $table_name );
        return false;
    }
}

/**
 * Crear tabla de asignaciones de usuarios
 * 
 * Almacena qué formulario fue asignado a cada usuario (por fingerprint):
 * - randomization_id: A qué estudio pertenece
 * - user_fingerprint: Identificador único del dispositivo/navegador
 * - assigned_form_id: Qué formulario le tocó
 * - Timestamps de acceso
 */
function eipsi_create_randomization_assignments_table() {
    global $wpdb;

    $table_name      = $wpdb->prefix . 'eipsi_randomization_assignments';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        randomization_id VARCHAR(255) NOT NULL,
        config_id VARCHAR(255) NOT NULL,
        user_fingerprint VARCHAR(255) NOT NULL,
        assigned_form_id BIGINT(20) UNSIGNED NOT NULL,
        assigned_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        last_access DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        access_count INT(11) DEFAULT 1,
        PRIMARY KEY  (id),
        UNIQUE KEY unique_assignment (randomization_id, config_id, user_fingerprint),
        KEY randomization_id (randomization_id),
        KEY config_id (config_id),
        KEY user_fingerprint (user_fingerprint),
        KEY assigned_form_id (assigned_form_id),
        KEY assigned_at (assigned_at)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );

    // Verificar si se creó correctamente
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table_name}'" );
    
    if ( $table_exists === $table_name ) {
        error_log( '[EIPSI Forms] Tabla creada: ' . $table_name );
        return true;
    } else {
        error_log( '[EIPSI Forms] ERROR: No se pudo crear tabla ' . $table_name );
        return false;
    }
}

/**
 * Crear tabla de asignaciones manuales (overrides)
 *
 * NOTA: Esta función está definida en admin/manual-overrides-table.php (v1.4.5)
 * Se mantiene la llamada aquí para compatibilidad con el flujo de activación.
 *
 * @see admin/manual-overrides-table.php
 */

/**
 * Crear ambas tablas en activación del plugin
 */
function eipsi_create_randomization_tables() {
    $configs_created         = eipsi_create_randomization_configs_table();
    $assignments_created     = eipsi_create_randomization_assignments_table();
    $overrides_created       = eipsi_create_manual_overrides_table();

    if ( $configs_created && $assignments_created && $overrides_created ) {
        error_log( '[EIPSI Forms] Sistema de aleatorización RCT configurado correctamente ✓' );
        update_option( 'eipsi_randomization_db_version', '1.3.1' );
        return true;
    } else {
        error_log( '[EIPSI Forms] ERROR: Fallo en configuración del sistema RCT' );
        return false;
    }
}

/**
 * Guardar o actualizar configuración de aleatorización
 * 
 * @param string $randomization_id ID único de la aleatorización
 * @param array  $config Configuración completa
 * @return bool True si se guardó correctamente
 */
function eipsi_save_randomization_config_to_db( $randomization_id, $config ) { return EIPSI_Randomization_Repository::eipsi_save_randomization_config_to_db($randomization_id, $config); }

/**
 * Obtener configuración de aleatorización desde DB
 * 
 * @param string $randomization_id ID único
 * @return array|null Configuración o null si no existe
 */
function eipsi_get_randomization_config_from_db( $randomization_id ) { return EIPSI_Randomization_Repository::eipsi_get_randomization_config_from_db($randomization_id); }

/**
 * Obtener todas las asignaciones de un estudio
 * 
 * @param string $randomization_id ID único del estudio
 * @return array Lista de asignaciones
 */
function eipsi_get_study_assignments( $randomization_id ) { return EIPSI_Randomization_Repository::eipsi_get_study_assignments($randomization_id); }

/**
 * Obtener estadísticas de un estudio RCT (v1.5.5)
 * 
 * AHORA usa datos de envíos reales (wp_vas_form_results) en lugar de
 * pre-asignaciones (wp_eipsi_randomization_assignments) para mostrar
 * "Total Completados" en lugar de "Total Asignados".
 * 
 * @param string $randomization_id ID único del estudio
 * @return array Estadísticas
 */
function eipsi_get_study_stats( $randomization_id ) { return EIPSI_Randomization_Repository::eipsi_get_study_stats($randomization_id); }

/**
 * Verificar si las tablas existen
 *
 * @return bool True si todas las tablas existen
 */
function eipsi_randomization_tables_exist() {
    global $wpdb;

    $configs_table     = $wpdb->prefix . 'eipsi_randomization_configs';
    $assignments_table = $wpdb->prefix . 'eipsi_randomization_assignments';
    $overrides_table   = $wpdb->prefix . 'eipsi_manual_overrides';

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $configs_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$configs_table}'" ) === $configs_table;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $assignments_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$assignments_table}'" ) === $assignments_table;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $overrides_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$overrides_table}'" ) === $overrides_table;

    return $configs_exists && $assignments_exists && $overrides_exists;
}

// Hook para activación
add_action( 'eipsi_forms_activation', 'eipsi_create_randomization_tables' );

// Verificar tablas en cada carga de admin (crear si faltan)
add_action( 'admin_init', function() {
    if ( ! eipsi_randomization_tables_exist() ) {
        error_log( '[EIPSI Forms] Tablas de aleatorización faltantes. Creando...' );
        eipsi_create_randomization_tables();
    }
} );

/**
 * Registrar endpoint REST API para guardar configuración desde el bloque
 */
function eipsi_register_randomization_rest_endpoint() {
    register_rest_route(
        'wp/v2',
        '/eipsi_randomization_config',
        array(
            'methods'             => 'POST',
            'callback'            => 'eipsi_rest_save_randomization_config',
            'permission_callback' => function() {
                return current_user_can( 'edit_posts' );
            },
        )
    );
}

add_action( 'rest_api_init', 'eipsi_register_randomization_rest_endpoint' );

/**
 * Callback del endpoint REST: guardar configuración
 * 
 * @param WP_REST_Request $request Request object
 * @return WP_REST_Response
 */
function eipsi_rest_save_randomization_config( $request ) { return EIPSI_Randomization_DB_Rest_Adapter::eipsi_rest_save_randomization_config($request); }

/**
 * Calculate RCT assignment at form submission time (v1.5.5)
 * 
 * Uses seeded randomization so the same fingerprint always gets the same variant.
 * This replaces the old page-load randomization to prevent bypass via
 * incognito mode, clearing fingerprint, or using different devices.
 * 
 * @param string $user_fingerprint User's fingerprint
 * @param int    $form_id          The form being submitted (to check for RCT config)
 * @param int    $timestamp        Submission timestamp for seeding
 * @return array|null Assignment data or null if no RCT config found
 */
function eipsi_calculate_submission_assignment( $user_fingerprint, $form_id, $timestamp ) { return EIPSI_Randomization_Submission_Algorithm_Service::eipsi_calculate_submission_assignment($user_fingerprint, $form_id, $timestamp); }

/**
 * Get RCT assignment for a specific submission from results table (v1.5.5)
 * 
 * @param int $result_id The submission result ID
 * @return array|null Assignment data or null
 */
function eipsi_get_submission_rct_assignment( $result_id ) { return EIPSI_Randomization_Repository::eipsi_get_submission_rct_assignment($result_id); }

/**
 * Update existing submissions with RCT assignment data (v1.5.5)
 * 
 * This is a migration function to populate rct_assigned_variant for submissions
 * that were made when RCT was active but assignment was only in metadata.
 * 
 * @return array Migration result
 */
function eipsi_migrate_submission_rct_assignments() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'vas_form_results';
    $results_table = $wpdb->prefix . 'vas_form_results';
    
    // Find submissions that have RCT data in metadata but no rct_assigned_variant
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $submissions = $wpdb->get_results(
        "SELECT id, metadata FROM {$results_table} 
        WHERE rct_assigned_variant IS NULL 
        AND metadata LIKE '%random_assignment%'
        LIMIT 1000",
        ARRAY_A
    );
    
    if ( empty( $submissions ) ) {
        return array(
            'success' => true,
            'migrated' => 0,
            'message' => 'No submissions to migrate'
        );
    }
    
    $migrated = 0;
    
    foreach ( $submissions as $submission ) {
        $metadata = json_decode( $submission['metadata'], true );
        
        if ( ! empty( $metadata['random_assignment']['form_id'] ) ) {
            $assigned_form_id = intval( $metadata['random_assignment']['form_id'] );
            $form_title = get_the_title( $assigned_form_id ) ?? 'Form ' . $assigned_form_id;
            
            // Extract randomization_id from config if available
            $randomization_id = ! empty( $metadata['random_assignment']['seed'] ) 
                ? substr( $metadata['random_assignment']['seed'], 0, 50 )
                : null;
            
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->update(
                $results_table,
                array(
                    'rct_assigned_variant' => $form_title,
                    'rct_randomization_id' => $randomization_id
                ),
                array( 'id' => $submission['id'] ),
                array( '%s', '%s' ),
                array( '%d' )
            );
            
            $migrated++;
        }
    }
    
    return array(
        'success' => true,
        'migrated' => $migrated,
        'message' => "Migrated {$migrated} submissions"
    );
}
