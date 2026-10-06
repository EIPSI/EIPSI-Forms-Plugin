<?php
/** M7 owner. Public compatibility adapters retain registration and signatures. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Randomization_Repository {

public static function eipsi_save_randomization_config_to_db( $randomization_id, $config ) {
    global $wpdb;

    $table_name = $wpdb->prefix . 'eipsi_randomization_configs';

    // Preparar datos
    $data = array(
        'randomization_id'   => $randomization_id,
        'formularios'        => wp_json_encode( $config['formularios'] ?? array() ),
        'probabilidades'     => wp_json_encode( $config['probabilidades'] ?? array() ),
        'method'             => $config['method'] ?? 'seeded',
        'manual_assignments' => wp_json_encode( $config['manualAssignments'] ?? array() ),
        'show_instructions'  => ! empty( $config['showInstructions'] ) ? 1 : 0,
        'updated_at'         => current_time( 'mysql' ),
    );

    // Verificar si ya existe
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $existing = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT id FROM {$table_name} WHERE randomization_id = %s",
            $randomization_id
        )
    );

    if ( $existing ) {
        // Actualizar
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->update(
            $table_name,
            $data,
            array( 'randomization_id' => $randomization_id ),
            array( '%s', '%s', '%s', '%s', '%s', '%d', '%s' ),
            array( '%s' )
        );

        error_log( "[EIPSI Forms] Config actualizada: {$randomization_id}" );
        return $result !== false;
    } else {
        // Insertar nueva
        $data['created_at'] = current_time( 'mysql' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $result = $wpdb->insert(
            $table_name,
            $data,
            array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
        );

        error_log( "[EIPSI Forms] Config creada: {$randomization_id}" );
        return $result !== false;
    }
}

public static function eipsi_get_randomization_config_from_db( $randomization_id ) {
    global $wpdb;

    $table_name = $wpdb->prefix . 'eipsi_randomization_configs';

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE randomization_id = %s",
            $randomization_id
        ),
        ARRAY_A
    );

    if ( ! $row ) {
        return null;
    }

    // Decodificar JSON fields
    return array(
        'randomizationId'    => $row['randomization_id'],
        'formularios'        => json_decode( $row['formularios'], true ) ?? array(),
        'probabilidades'     => json_decode( $row['probabilidades'], true ) ?? array(),
        'method'             => $row['method'],
        'manualAssignments'  => json_decode( $row['manual_assignments'], true ) ?? array(),
        'showInstructions'   => (bool) $row['show_instructions'],
        'created_at'         => $row['created_at'],
        'updated_at'         => $row['updated_at'],
    );
}

public static function eipsi_get_study_assignments( $randomization_id ) {
    global $wpdb;

    $table_name = $wpdb->prefix . 'eipsi_randomization_assignments';

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $results = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$table_name} 
            WHERE randomization_id = %s 
            ORDER BY assigned_at DESC",
            $randomization_id
        ),
        ARRAY_A
    );

    return $results ?? array();
}

public static function eipsi_get_study_stats( $randomization_id ) {
    global $wpdb;

    $results_table = $wpdb->prefix . 'vas_form_results';
    $assignments_table = $wpdb->prefix . 'eipsi_randomization_assignments';

    // v1.5.5: Total de submissions REALES (no pre-asignaciones)
    // Esto muestra "Total Completados" en lugar de "Total Asignados"
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $total_completados = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$results_table} 
            WHERE rct_randomization_id = %s 
            AND rct_assigned_variant IS NOT NULL",
            $randomization_id
        )
    );

    // v1.5.5: Distribución por variante desde submissions reales
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $distribution = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT rct_assigned_variant as assigned_form_id, COUNT(*) as count 
            FROM {$results_table} 
            WHERE rct_randomization_id = %s 
            AND rct_assigned_variant IS NOT NULL
            GROUP BY rct_assigned_variant",
            $randomization_id
        ),
        ARRAY_A
    );

    // v1.5.5: Deprecated - total de pre-asignaciones (para compatibilidad hacia atrás)
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $total_asignados_legacy = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$assignments_table} WHERE randomization_id = %s",
            $randomization_id
        )
    );

    return array(
        'total_completados' => (int) $total_completados,
        'total_asignados_legacy' => (int) $total_asignados_legacy,
        'distribution'       => $distribution,
        'method'            => 'submission_based',
    );
}

public static function eipsi_get_submission_rct_assignment( $result_id ) {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'vas_form_results';
    
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT rct_assigned_variant, rct_randomization_id FROM {$table_name} WHERE id = %d",
            $result_id
        ),
        ARRAY_A
    );
    
    if ( ! $row || empty( $row['rct_assigned_variant'] ) ) {
        return null;
    }
    
    return array(
        'assigned_variant' => $row['rct_assigned_variant'],
        'randomization_id' => $row['rct_randomization_id']
    );
}
}
