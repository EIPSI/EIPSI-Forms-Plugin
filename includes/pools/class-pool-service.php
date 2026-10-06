<?php
/** M7 owner. Public compatibility adapters retain registration and signatures. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Pool_Service {

public static function eipsi_detect_access_type() {
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $query_code = $_GET['code'] ?? '';
    
    // Patrón: /pool/POOL_CODIGO/
    if (preg_match('/\/pool\/([^\/\?]+)/', $uri, $matches)) {
        return array(
            'type' => 'pool',
            'code' => sanitize_text_field($matches[1])
        );
    }
    
    // Patrón: /estudio/ESTUDIO_CODIGO/
    if (preg_match('/\/estudio\/([^\/\?]+)/', $uri, $matches)) {
        return array(
            'type' => 'study',
            'code' => sanitize_text_field($matches[1])
        );
    }
    
    // Fallback a query param ?code=XXX&type=pool
    if ($query_code && isset($_GET['type']) && $_GET['type'] === 'pool') {
        return array(
            'type' => 'pool',
            'code' => sanitize_text_field($query_code)
        );
    }
    
    return array('type' => 'unknown', 'code' => '');
}

public static function eipsi_get_valid_pool($pool_code) {
    global $wpdb;
    
    if (empty($pool_code)) {
        return false;
    }
    
    // Buscar en la tabla de pools
    $pools_table = $wpdb->prefix . 'eipsi_longitudinal_pools';
    $pool = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$pools_table} WHERE pool_name = %s OR id = %d",
        $pool_code,
        is_numeric($pool_code) ? intval($pool_code) : 0
    ));
    
    if (!$pool) {
        return false;
    }
    
    // Verificar estado
    if ($pool->status !== 'active') {
        return false;
    }
    
    // Decodificar config JSON
    $config = json_decode($pool->config, true) ?: array();
    
    // Preparar datos normalizados
    $pool_data = array(
        'id' => $pool->id,
        'code' => $pool->pool_name,
        'title' => $pool->pool_name,
        'description' => $pool->pool_description,
        'incentive_message' => $config['incentive_message'] ?? '',
        'redirect_mode' => $config['redirect_mode'] ?? 'transition',
        'status' => $pool->status,
        'method' => $pool->method,
        'notify_on_completion' => $config['notify_on_completion'] ?? false,
        'config' => $config,
        'created_at' => $pool->created_at,
        'updated_at' => $pool->updated_at
    );
    
    return $pool_data;
}

public static function eipsi_get_pool_assignment($pool_code, $participant_id) {
    global $wpdb;
    
    $table = $wpdb->prefix . 'eipsi_pool_assignments';
    
    // Verificar si tabla existe
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'") === $table;
    
    if (!$table_exists) {
        // Tabla no existe aún, retornar false
        return false;
    }
    
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table} WHERE pool_code = %s AND participant_id = %s",
        $pool_code, $participant_id
    ));
    
    if ($row) {
        return array(
            'assignment_id' => $row->id,
            'study_id' => $row->study_id,
            'assigned_at' => $row->assigned_at,
            'pool_code' => $row->pool_code
        );
    }
    
    return false;
}

public static function eipsi_get_pool_url($pool_code) {
    return home_url('/pool/' . sanitize_title($pool_code) . '/');
}

public static function eipsi_get_study_url($study_code) {
    return home_url('/estudio/' . sanitize_title($study_code) . '/');
}

public static function eipsi_get_participant_id() {
    // Primero verificar si hay usuario logueado de WordPress
    if (is_user_logged_in()) {
        return 'wp_' . get_current_user_id();
    }
    
    // Verificar cookie existente
    if (isset($_COOKIE['eipsi_participant_id'])) {
        return sanitize_text_field($_COOKIE['eipsi_participant_id']);
    }
    
    // Verificar sessionStorage via AJAX (no disponible en PHP directamente)
    // Retornar null para indicar que se necesita identificación
    return null;
}

public static function eipsi_set_participant_cookie($participant_id) {
    if (empty($participant_id)) {
        return false;
    }
    
    // Cookie por 1 año
    setcookie('eipsi_participant_id', $participant_id, time() + 365 * 24 * 60 * 60, '/');
    
    return true;
}

public static function eipsi_render_pool_access_page($pool_code, $pool_data) {
    // Verificar si el participante ya tiene asignación
    $participant_id = eipsi_get_participant_id();
    
    if ($participant_id) {
        $assignment = eipsi_get_pool_assignment($pool_code, $participant_id);
        
        if ($assignment) {
            // Ya asignado, redirigir al estudio
            $study_url = eipsi_get_study_url($assignment['study_id']);
            wp_redirect($study_url);
            exit;
        }
    }
    
    // Mostrar interfaz de acceso
    $template_path = EIPSI_FORMS_PLUGIN_DIR . 'includes/templates/pool-access.php';
    
    if (file_exists($template_path)) {
        include $template_path;
    } else {
        // Fallback si no existe el template
        wp_die(__('Error: Template de pool no encontrado.', 'eipsi-forms'));
    }
    
    exit;
}

public static function eipsi_pool_randomize($pool_code, $participant_id, $pool_data) {
    global $wpdb;
    
    // Verificar si pool está activo
    if (!$pool_data || $pool_data['status'] !== 'active') {
        error_log('[EIPSI-POOL] Pool no activo: ' . $pool_code);
        return false;
    }
    
    // Verificar si ya tiene asignación (idempotencia)
    $existing = eipsi_get_pool_assignment($pool_code, $participant_id);
    if ($existing) {
        error_log('[EIPSI-POOL] Participante ya asignado: ' . $participant_id);
        return $existing;
    }
    
    // Obtener estudios del config
    $studies = $pool_data['config']['studies'] ?? array();
    if (empty($studies)) {
        error_log('[EIPSI-POOL] No hay estudios configurados: ' . $pool_code);
        return false;
    }
    
    // Filtrar estudios disponibles (que no alcanzaron su target)
    $available_studies = array_filter($studies, function($study) use ($pool_data) {
        $current_count = $study['current_count'] ?? 0;
        $target_count = $study['target_count'] ?? PHP_INT_MAX;
        return $current_count < $target_count;
    });
    
    // Re-indexar array
    $available_studies = array_values($available_studies);
    
    if (empty($available_studies)) {
        // Pool saturado
        error_log('[EIPSI-POOL] Pool saturado: ' . $pool_code);
        do_action('eipsi_pool_saturated', $pool_code, $participant_id);
        return false;
    }
    
    // ALEATORIZACIÓN SIMPLE EQUIPROBABLE
    // Cada estudio tiene probabilidad 1/n donde n = cantidad de estudios disponibles
    $random_index = array_rand($available_studies);
    $assigned_study = $available_studies[$random_index];
    
    $study_id = $assigned_study['study_id'];
    $study_code = is_array($study_id) ? ($study_id['code'] ?? $study_id['id']) : $study_id;
    
    // Guardar asignación en BD
    $assignment = array(
        'pool_code' => $pool_code,
        'participant_id' => $participant_id,
        'study_id' => $study_code,
        'assigned_at' => current_time('mysql'),
        'assignment_method' => 'simple_equiprobable',
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
    );
    
    $result = eipsi_save_pool_assignment($assignment);
    
    if (!$result) {
        error_log('[EIPSI-POOL] Error guardando asignación');
        return false;
    }
    
    // Incrementar contador del estudio (en config)
    eipsi_increment_study_count($pool_code, $study_code);
    
    // Log para auditoría
    error_log(sprintf(
        '[EIPSI-POOL] Asignación exitosa: pool=%s, participant=%s, study=%s, method=%s',
        $pool_code,
        $participant_id,
        $study_code,
        'simple_equiprobable'
    ));
    
    return array(
        'assignment_id' => $result,
        'study_id' => $study_code,
        'assigned_at' => $assignment['assigned_at'],
        'pool_code' => $pool_code,
        'method' => 'simple_equiprobable'
    );
}

public static function eipsi_save_pool_assignment($assignment) {
    global $wpdb;
    
    $table = $wpdb->prefix . 'eipsi_pool_assignments';
    
    $result = $wpdb->insert(
        $table,
        array(
            'pool_id' => $assignment['pool_code'],  // Guardamos el código como ID
            'participant_id' => $assignment['participant_id'],
            'study_id' => $assignment['study_id'],
            'assigned_at' => $assignment['assigned_at'],
            'ip_address' => $assignment['ip_address'],
            'user_agent' => substr($assignment['user_agent'] ?? '', 0, 255)
        ),
        array('%s', '%s', '%s', '%s', '%s', '%s')
    );
    
    if ($result === false) {
        error_log('[EIPSI-POOL] Error insertando asignación: ' . $wpdb->last_error);
        return false;
    }
    
    return $wpdb->insert_id;
}

public static function eipsi_increment_study_count($pool_code, $study_id) {
    global $wpdb;
    
    // Obtener pool actual
    $pools_table = $wpdb->prefix . 'eipsi_longitudinal_pools';
    $pool = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$pools_table} WHERE pool_name = %s",
        $pool_code
    ));
    
    if (!$pool) {
        return false;
    }
    
    // Decodificar config
    $config = json_decode($pool->config, true) ?: array();
    $studies = $config['studies'] ?? array();
    
    // Encontrar y actualizar el estudio
    foreach ($studies as &$study) {
        $study_code = is_array($study['study_id']) ? ($study['study_id']['code'] ?? $study['study_id']['id']) : $study['study_id'];
        if ($study_code == $study_id) {
            $study['current_count'] = ($study['current_count'] ?? 0) + 1;
            break;
        }
    }
    unset($study); // Romper referencia
    
    // Guardar config actualizado
    $config['studies'] = $studies;
    
    $wpdb->update(
        $pools_table,
        array('config' => json_encode($config)),
        array('id' => $pool->id),
        array('%s'),
        array('%d')
    );
    
    return true;
}

public static function eipsi_render_pool_assigned_page($pool_code, $participant_id, $assignment) {
    $template_path = EIPSI_FORMS_PLUGIN_DIR . 'includes/templates/pool-assigned.php';
    
    // Variables para el template
    $study_id = $assignment['study_id'];
    $study_url = eipsi_get_study_url($study_id);
    
    if (file_exists($template_path)) {
        include $template_path;
    } else {
        // Fallback: redirigir directo
        wp_redirect($study_url);
        exit;
    }
    
    exit;
}

public static function eipsi_get_pool_stats($pool_code) {
    global $wpdb;
    
    $pool_data = eipsi_get_valid_pool($pool_code);
    if (!$pool_data) {
        return false;
    }
    
    $assignments_table = $wpdb->prefix . 'eipsi_pool_assignments';
    
    // Total asignaciones
    $total_assignments = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$assignments_table} WHERE pool_id = %s",
        $pool_code
    ));
    
    // Asignaciones por estudio
    $by_study = $wpdb->get_results($wpdb->prepare(
        "SELECT study_id, COUNT(*) as count FROM {$assignments_table} WHERE pool_id = %s GROUP BY study_id",
        $pool_code
    ), OBJECT_K);
    
    // Distribución con porcentajes
    $studies = $pool_data['config']['studies'] ?? array();
    $distribution = array();
    
    foreach ($studies as $study) {
        $study_id = is_array($study['study_id']) ? ($study['study_id']['code'] ?? $study['study_id']['id']) : $study['study_id'];
        $count = isset($by_study[$study_id]) ? intval($by_study[$study_id]->count) : 0;
        $target = $study['target_count'] ?? 0;
        
        $distribution[] = array(
            'study_id' => $study_id,
            'name' => $study_id, // TODO: obtener nombre real del estudio
            'count' => $count,
            'target' => $target,
            'percentage' => $total_assignments > 0 ? round(($count / $total_assignments) * 100, 1) : 0,
            'fill_percentage' => $target > 0 ? round(($count / $target) * 100, 1) : 0
        );
    }
    
    return array(
        'pool_code' => $pool_code,
        'status' => $pool_data['status'],
        'total_assignments' => intval($total_assignments),
        'studies_count' => count($studies),
        'distribution' => $distribution,
        'created_at' => $pool_data['created_at'],
        'updated_at' => $pool_data['updated_at']
    );
}

public static function eipsi_export_pool_assignments_csv($pool_code) {
    global $wpdb;
    
    $table = $wpdb->prefix . 'eipsi_pool_assignments';
    
    $assignments = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$table} WHERE pool_id = %s ORDER BY assigned_at DESC",
        $pool_code
    ));
    
    if (empty($assignments)) {
        return false;
    }
    
    // Headers CSV
    $csv = "participant_id,study_id,assigned_at,ip_address,user_agent\n";
    
    foreach ($assignments as $row) {
        $csv .= sprintf(
            "%s,%s,%s,%s,%s\n",
            $row->participant_id,
            $row->study_id,
            $row->assigned_at,
            $row->ip_address,
            str_replace(array("\n", "\r", ","), array(" ", " ", " "), $row->user_agent)
        );
    }
    
    return $csv;
}

public static function eipsi_change_pool_status($pool_code, $new_status) {
    global $wpdb;
    
    $valid_statuses = array('active', 'paused', 'closed');
    if (!in_array($new_status, $valid_statuses)) {
        return false;
    }
    
    $table = $wpdb->prefix . 'eipsi_longitudinal_pools';
    
    $result = $wpdb->update(
        $table,
        array('status' => $new_status),
        array('pool_name' => $pool_code),
        array('%s'),
        array('%s')
    );
    
    return $result !== false;
}

public static function eipsi_pause_pool($pool_code) {
    return eipsi_change_pool_status($pool_code, 'paused');
}

public static function eipsi_close_pool($pool_code) {
    return eipsi_change_pool_status($pool_code, 'closed');
}

public static function eipsi_activate_pool($pool_code) {
    return eipsi_change_pool_status($pool_code, 'active');
}
public static function configure($params) {
    global $wpdb;



    $pool_id = isset($params['pool_id']) ? intval($params['pool_id']) : 0;
    $studies = isset($params['studies']) ? $params['studies'] : array();
    $method = isset($params['method']) ? sanitize_text_field($params['method']) : 'seeded';
    $seed = isset($params['seed']) ? sanitize_text_field($params['seed']) : '';

    // Validate studies array
    if (!is_array($studies) || empty($studies)) {
        return self::configuration_result(array(
            'success' => false,
            'message' => 'Se requiere al menos un estudio.',
        ), 400);
    }

    // Validate probability sum = 100
    $total_probability = array_sum(array_column($studies, 'probability'));
    if ($total_probability < 99.9 || $total_probability > 100.1) {
        return self::configuration_result(array(
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
                    return self::configuration_result(array(
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
            return self::configuration_result(array(
                'success' => false,
                'message' => 'Error al actualizar el pool: ' . $wpdb->last_error,
            ), 500);
        }
        
        error_log("[EIPSI POOL EDIT] Pool {$pool_id} actualizado correctamente");

        return self::configuration_result(array(
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
                'pool_name' => isset($params['name']) ? sanitize_text_field($params['name']) : 'Pool ' . wp_date('Y-m-d H:i'),
                'config' => wp_json_encode($config),
                'status' => 'active',
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ),
            array('%s', '%s', '%s', '%s', '%s')
        );

        if ($result === false) {
            return self::configuration_result(array(
                'success' => false,
                'message' => 'Error al crear el pool: ' . $wpdb->last_error,
            ), 500);
        }

        $new_pool_id = $wpdb->insert_id;

        return self::configuration_result(array(
            'success' => true,
            'pool_id' => $new_pool_id,
            'message' => 'Pool creado correctamente.',
        ), 201);
    }

}
private static function configuration_result($data, $status) { return array('data'=>$data, 'status'=>$status); }

}
