<?php
/** M7 owner. Public compatibility adapters retain registration and signatures. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Randomization_Assignment_Service {

public static function eipsi_get_existing_assignment( $config_id, $user_fingerprint ) {
    global $wpdb;

    $table_name = $wpdb->prefix . 'eipsi_randomization_assignments';

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $assignment = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM {$table_name} 
            WHERE randomization_id = %s 
            AND config_id = %s 
            AND user_fingerprint = %s
            LIMIT 1",
            $config_id,
            $config_id,
            $user_fingerprint
        ),
        ARRAY_A
    );

    return $assignment;
}

public static function eipsi_create_assignment( $config_id, $user_fingerprint, $assigned_form_id, $persistent_mode = true ) {
        global $wpdb;
        $key = 'eipsi-rct-' . md5($wpdb->prefix . ':' . $config_id . ':' . $user_fingerprint);
        if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $key)) !== 1) { return false; }
        try {
            if (self::eipsi_get_existing_assignment($config_id, $user_fingerprint)) { return true; }
            return self::create_locked($config_id, $user_fingerprint, $assigned_form_id, $persistent_mode);
        } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $key)); }
    }
    private static function create_locked( $config_id, $user_fingerprint, $assigned_form_id, $persistent_mode = true ) {
    global $wpdb;

    $table_name = $wpdb->prefix . 'eipsi_randomization_assignments';

    // Intentar insertar con la columna persistent_mode (si existe)
    // Si falla, la función autofix la creará automáticamente en la próxima ejecución
    $data = array(
        'randomization_id' => $config_id,
        'config_id' => $config_id,
        'user_fingerprint' => $user_fingerprint,
        'assigned_form_id' => $assigned_form_id,
        'assigned_at' => current_time( 'mysql' ),
        'last_access' => current_time( 'mysql' ),
        'access_count' => 1,
    );

    $format = array( '%s', '%s', '%s', '%d', '%s', '%s', '%d' );

    // Intentar agregar persistent_mode si la columna existe
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $column_check = $wpdb->get_results( "SHOW COLUMNS FROM {$table_name} LIKE 'persistent_mode'" );
    
    if ( ! empty( $column_check ) ) {
        $data['persistent_mode'] = $persistent_mode ? 1 : 0;
        $format[] = '%d';
        error_log( "[EIPSI RCT] Asignación creada con persistent_mode={$persistent_mode}" );
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
    $result = $wpdb->insert( $table_name, $data, $format );

    if ( $result === false ) {
        error_log( "[EIPSI RCT] ERROR al crear asignación: {$wpdb->last_error}" );
        return false;
    }

    return true;
}

public static function eipsi_update_assignment_full( $assignment_id, $assigned_form_id, $persistent_mode = true ) {
    global $wpdb;

    $table_name = $wpdb->prefix . 'eipsi_randomization_assignments';

    $data = array(
        'assigned_form_id' => $assigned_form_id,
        'last_access' => current_time( 'mysql' ),
        'access_count' => 1, // Reiniciamos contador en reasignación
    );
    
    $format = array( '%d', '%s', '%d' );

    // Intentar agregar persistent_mode si la columna existe
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $column_check = $wpdb->get_results( "SHOW COLUMNS FROM {$table_name} LIKE 'persistent_mode'" );
    
    if ( ! empty( $column_check ) ) {
        $data['persistent_mode'] = $persistent_mode ? 1 : 0;
        $format[] = '%d';
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
    $result = $wpdb->update( 
        $table_name, 
        $data, 
        array( 'id' => $assignment_id ), 
        $format, 
        array( '%d' ) 
    );

    return $result !== false;
}

public static function eipsi_update_assignment_access( $assignment_id ) {
    global $wpdb;

    $table_name = $wpdb->prefix . 'eipsi_randomization_assignments';

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $result = $wpdb->query(
        $wpdb->prepare(
            "UPDATE {$table_name} 
            SET last_access = %s,
            access_count = access_count + 1
            WHERE id = %d",
            current_time( 'mysql' ),
            $assignment_id
        )
    );

    return $result !== false;
}

/** Fingerprint locates a row; only an issued capability authorizes its reset. */
public static function eipsi_close_randomization_session( $config_id, $user_fingerprint ) {
    global $wpdb;
    $proof = $_POST['reset_capability'] ?? '';
    if (!is_string($proof) || strlen($proof) > 4096 || !$proof) { return false; }
    $proof = wp_unslash($proof);
    $key = 'eipsi-rct-' . md5($wpdb->prefix . ':' . $config_id . ':' . $user_fingerprint);
    if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $key)) !== 1) { return false; }
    try {
        $assignment = self::eipsi_get_existing_assignment($config_id, $user_fingerprint);
        if (!$assignment || $wpdb->last_error || !self::verify_reset_capability($proof, $assignment)) { return false; }
        $deleted = $wpdb->delete($wpdb->prefix . 'eipsi_randomization_assignments', array(
            'id' => (int)$assignment['id'], 'randomization_id' => $config_id,
            'config_id' => $config_id, 'user_fingerprint' => $user_fingerprint,
        ), array('%d', '%s', '%s', '%s'));
        if ($deleted !== 1) { return false; }
        $rotation_key = 'eipsi_rotation_' . $config_id;
        if (isset($_COOKIE[$rotation_key])) {
            setcookie($rotation_key, '', time() - 3600, '/');
            unset($_COOKIE[$rotation_key]);
        }
        return true;
    } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $key)); }
}
    private static function reset_claims($assignment) {
        global $wpdb;
        return array('version' => 1, 'site' => get_current_blog_id(), 'prefix' => $wpdb->prefix,
            'id' => (int)$assignment['id'], 'config' => $assignment['config_id'],
            'fingerprint' => $assignment['user_fingerprint'], 'assigned_at' => $assignment['assigned_at']);
    }
    private static function issue_reset_capability($assignment) {
        $claims = self::reset_claims($assignment);
        $claims['expires'] = time() + YEAR_IN_SECONDS;
        $claims['nonce'] = bin2hex(random_bytes(16));
        $payload = base64_encode(wp_json_encode($claims));
        return $payload . '.' . hash_hmac('sha256', $payload, wp_salt('auth'));
    }
    private static function verify_reset_capability($proof, $assignment) {
        $parts = explode('.', $proof);
        if (count($parts) !== 2 || !hash_equals(hash_hmac('sha256', $parts[0], wp_salt('auth')), $parts[1])) { return false; }
        $raw = base64_decode($parts[0], true);
        $claims = $raw === false ? null : json_decode($raw, true);
        if (!is_array($claims) || !isset($claims['expires'], $claims['nonce']) || !is_int($claims['expires']) || $claims['expires'] <= time()) { return false; }
        foreach (self::reset_claims($assignment) as $key => $value) {
            if (!array_key_exists($key, $claims) || $claims[$key] !== $value) { return false; }
        }
        return true;
    }
    public static function resolve($config_id, $config, $user_fingerprint) {
        global $wpdb;
        if (!$config_id || !$user_fingerprint || empty($config['formularios'])) { return new WP_Error('invalid_config', __('Configuración inválida.', 'eipsi-forms')); }
        $key = 'eipsi-rct-' . md5($wpdb->prefix . ':' . $config_id . ':' . $user_fingerprint);
        if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $key)) !== 1) { return new WP_Error('assignment_busy', __('Intentá nuevamente.', 'eipsi-forms')); }
        try { return self::resolve_locked($config_id, $config, $user_fingerprint); }
        finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $key)); }
    }
    private static function resolve_locked($config_id, $config, $user_fingerprint) {
        $persistent_mode = isset($config['persistent_mode']) ? (bool)$config['persistent_mode'] : true;
    // PASO 3: Buscar si ya existe una asignación previa para este usuario
    $existing_assignment = eipsi_get_existing_assignment( $config_id, $user_fingerprint );
    if ($GLOBALS['wpdb']->last_error) { return new WP_Error('assignment_failed', __('No se pudo leer la asignación.', 'eipsi-forms')); }
    
    $is_new_assignment = false;

    if ( $existing_assignment && $persistent_mode ) {
        // YA FUE ASIGNADO Y MODO PERSISTENTE - usar la asignación existente (persistencia)
        $assigned_form_id = (int) $existing_assignment['assigned_form_id'];

        // Actualizar timestamp y contador de accesos
        eipsi_update_assignment_access( $existing_assignment['id'] );

        error_log( "[EIPSI RCT] Usuario existente: {$user_fingerprint} → Formulario: {$assigned_form_id} (PERSISTENTE)" );
    } elseif ( ! $persistent_mode ) {
        // MODO NO PERSISTENTE (F5 = ROTACIÓN CÍCLICA DEL "SOMBRERO")
        // Cada F5 avanza una posición en el array de formularios
        
        // Obtener formularios disponibles
        $formularios_ids = array();
        foreach ( $config['formularios'] as $form ) {
            if ( isset( $form['id'] ) && $form['id'] ) {
                $formularios_ids[] = intval( $form['id'] );
            }
        }
        
        if ( empty( $formularios_ids ) ) {
            return new WP_Error('invalid_config', __('No hay formularios configurados.', 'eipsi-forms'));
        }
        
        // Calcular la posición actual (rotación cíclica basada en sesión/browser)
        $rotation_key = 'eipsi_rotation_' . $config_id;
        $current_position = 0;
        
        // Intentar obtener posición desde cookie primero (para F5 correcto)
        if ( isset( $_COOKIE[ $rotation_key ] ) ) {
            $current_position = intval( $_COOKIE[ $rotation_key ] );
        }
        
        // Obtener el formulario para esta posición
        $total_forms = count( $formularios_ids );
        $form_index = $current_position % $total_forms;
        $assigned_form_id = $formularios_ids[ $form_index ];
        
        // Actualizar cookie para el próximo F5 (avanzar una posición)
        $next_position = ( $current_position + 1 ) % $total_forms;
        setcookie( $rotation_key, $next_position, time() + 86400, '/' ); // 24 horas
        
        error_log( "[EIPSI RCT] F5 Rotation: position={$current_position}/{$total_forms} → form={$assigned_form_id}" );
        
        // Si ya existe una asignación previa, actualizar para tracking
        if ( $existing_assignment ) {
            eipsi_update_assignment_full( $existing_assignment['id'], $assigned_form_id, false );
        } else {
            // Crear nueva asignación para tracking
            if (!self::create_locked($config_id, $user_fingerprint, $assigned_form_id, false)) { return new WP_Error('assignment_failed', __('No se pudo guardar la asignación.', 'eipsi-forms')); }
        }
    } else {
        // NUEVA ASIGNACIÓN (primer acceso con persistent_mode=true)
        // Primero revisar asignaciones manuales desde DB (overrides)
        $assigned_form_id = eipsi_check_manual_override_db( $config_id, $user_fingerprint );

        if ( ! $assigned_form_id ) {
            // Calcular asignación aleatoria
            $assigned_form_id = eipsi_calculate_rct_assignment( $config, $user_fingerprint );
        }

        // Si ya existe una asignación pero estamos en modo test (persistent_mode=false), actualizamos en lugar de insertar
        if ( $existing_assignment ) {
            eipsi_update_assignment_full( $existing_assignment['id'], $assigned_form_id, $persistent_mode );
            error_log( "[EIPSI RCT] Usuario reasignado (test mode): {$user_fingerprint} → Formulario: {$assigned_form_id}" );
        } else {
            // Guardar nueva asignación en DB
            if (!self::create_locked($config_id, $user_fingerprint, $assigned_form_id, $persistent_mode)) { return new WP_Error('assignment_failed', __('No se pudo guardar la asignación.', 'eipsi-forms')); }
            $is_new_assignment = true; // MARK NEW ASSIGNMENT
            
            if ( $persistent_mode ) {
                error_log( "[EIPSI RCT] Nuevo usuario: {$user_fingerprint} → Formulario: {$assigned_form_id} (PERSISTENTE)" );
            } else {
                error_log( "[EIPSI RCT] Nuevo usuario: {$user_fingerprint} → Formulario: {$assigned_form_id} (TEST MODE)" );
            }
        }
    }


        $assignment = self::eipsi_get_existing_assignment($config_id, $user_fingerprint);
        if (!$assignment || $GLOBALS['wpdb']->last_error) { return new WP_Error('assignment_failed', __('No se pudo confirmar la asignación.', 'eipsi-forms')); }
        return array('assigned_form_id'=>$assigned_form_id, 'is_new_assignment'=>$is_new_assignment,
            'assignment_id'=>(int)$assignment['id'], 'reset_fingerprint'=>$assignment['user_fingerprint'],
            'reset_capability'=>!$existing_assignment ? self::issue_reset_capability($assignment) : '');
    }

}
