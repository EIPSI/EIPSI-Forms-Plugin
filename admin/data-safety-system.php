<?php
/**
 * EIPSI Data Safety System
 * 
 * Sistema crítico de seguridad de datos para investigación clínica.
 * Garantiza: 0% pérdida de datos de formularios.
 * 
 * Múltiples capas de protección:
 * 1. Pre-flight validation
 * 2. LocalStorage backup (JavaScript)
 * 3. WordPress DB storage
 * 4. External DB storage
 * 5. Post-submit verification
 * 6. Emergency recovery
 * 
 * @package EIPSI_Forms
 * @since 2.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Pre-flight data validation
 * Verifica que todos los campos requeridos estén presentes antes de procesar
 */
function eipsi_safety_validate_submission($data) {
    $errors = array();
    $warnings = array();
    
    // Verificar campos críticos de identificación
    if (empty($data['form_id'])) {
        $errors[] = 'Missing critical: form_id';
    }
    
    if (empty($data['participant_id'])) {
        $warnings[] = 'Missing participant_id, will generate fingerprint';
    }
    
    // Verificar que form_responses no esté vacío si hay campos enviados
    if (isset($data['form_responses']) && empty($data['form_responses'])) {
        $warnings[] = 'Empty form_responses - possible field name issue';
    }
    
    // Log para auditoría
    if (!empty($warnings)) {
        error_log('[EIPSI SAFETY] Submission warnings: ' . implode(' | ', $warnings));
    }
    
    return array(
        'valid' => empty($errors),
        'errors' => $errors,
        'warnings' => $warnings,
        'timestamp' => current_time('mysql'),
    );
}

/**
 * Guardar submission con retry automático
 */
function eipsi_safety_save_with_retry($data, $max_retries = 3) {
    $attempt = 0;
    $last_error = null;
    
    while ($attempt < $max_retries) {
        $attempt++;
        
        try {
            $result = eipsi_safety_attempt_save($data);
            
            if ($result['success']) {
                // Log éxito
                error_log(sprintf(
                    '[EIPSI SAFETY] Submission saved successfully on attempt %d. ID: %s',
                    $attempt,
                    $result['insert_id'] ?? 'unknown'
                ));
                
                // ✅ AUTO-SYNC: Sincronizar campos del formulario a survey_participants
                eipsi_auto_sync_participant_fields($data, $result['insert_id']);
                
                return $result;
            }
            
            $last_error = $result['error'] ?? 'Unknown error';
            error_log(sprintf('[EIPSI SAFETY] Save attempt %d failed: %s', $attempt, $last_error));
            
            // Esperar antes de reintentar (backoff exponencial)
            if ($attempt < $max_retries) {
                usleep($attempt * 500000); // 0.5s, 1s, 1.5s
            }
            
        } catch (Exception $e) {
            $last_error = $e->getMessage();
            error_log('[EIPSI SAFETY] Exception on attempt ' . $attempt . ': ' . $last_error);
        }
    }
    
    // Todos los intentos fallaron - activar modo emergencia
    return eipsi_safety_emergency_save($data, $last_error);
}

/**
 * Intento de guardado normal
 */
function eipsi_safety_attempt_save($data) {
    global $wpdb;
    
    // Intentar DB externa primero si está configurada
    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/database.php';
    $db_helper = new EIPSI_External_Database();
    
    if ($db_helper->is_enabled()) {
        $result = $db_helper->insert_form_submission($data);
        if ($result['success']) {
            return array(
                'success' => true,
                'insert_id' => $result['insert_id'],
                'storage' => 'external_db',
                'timestamp' => current_time('mysql'),
            );
        }
    }
    
    // Fallback a WordPress DB
    $table_name = $wpdb->prefix . 'vas_form_results';
    
    // Internal authorization context is for participant sync, not a schema column.
    $submission_data = $data;
    unset($submission_data['longitudinal_participant_id']);
    $wpdb_result = $wpdb->insert($table_name, $submission_data);
    
    if ($wpdb_result !== false) {
        return array(
            'success' => true,
            'insert_id' => $wpdb->insert_id,
            'storage' => 'wordpress_db',
            'timestamp' => current_time('mysql'),
        );
    }
    
    return array(
        'success' => false,
        'error' => $wpdb->last_error,
        'error_code' => 'DB_INSERT_FAILED',
    );
}

/**
 * Modo emergencia: Guardar datos críticos cuando todo falla
 * Usa la base de datos configurada (WordPress o External según configuración del usuario)
 */
function eipsi_safety_emergency_save($data, $original_error) {
    global $wpdb;
    
    error_log('[EIPSI SAFETY] ACTIVATING EMERGENCY SAVE MODE');
    
    // Determinar qué base de datos usar según la configuración del usuario
    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/database.php';
    $db_helper = new EIPSI_External_Database();
    
    $using_external = $db_helper->is_enabled();
    $emergency_id = null;
    $emergency_table = $wpdb->prefix . 'eipsi_emergency_submissions';
    
    // Datos a guardar
    $emergency_data = array(
        'form_id' => $data['form_id'] ?? 'unknown',
        'participant_id' => $data['participant_id'] ?? null,
        'form_responses' => $data['form_responses'] ?? null,
        'metadata' => $data['metadata'] ?? null,
        'raw_post_data' => wp_json_encode($_POST),
        'error_message' => $original_error,
    );
    
    // Intentar guardar en la DB configurada (External o WordPress)
    $storage = null;
    $storage_errors = array();
    $mysqli = null;
    if ($using_external) {
        try {
            $mysqli = $db_helper->get_connection();
            if ($mysqli) {
                // Crear tabla de emergencia en DB externa
                $create_sql = "CREATE TABLE IF NOT EXISTS {$emergency_table} (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    form_id VARCHAR(50) NOT NULL,
                    participant_id VARCHAR(255),
                    form_responses LONGTEXT,
                    metadata LONGTEXT,
                    raw_post_data LONGTEXT,
                    error_message TEXT,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    resolved TINYINT DEFAULT 0,
                    resolved_at DATETIME NULL,
                    KEY form_id (form_id),
                    KEY created_at (created_at),
                    KEY resolved (resolved)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

                if ($mysqli->query($create_sql) === false) {
                    throw new RuntimeException('External emergency CREATE failed: ' . $mysqli->error);
                }

                // Insertar datos
                $stmt = $mysqli->prepare(
                    "INSERT INTO {$emergency_table}
                    (form_id, participant_id, form_responses, metadata, raw_post_data, error_message, created_at, resolved)
                    VALUES (?, ?, ?, ?, ?, ?, NOW(), 0)"
                );

                if ($stmt) {
                    $stmt->bind_param(
                        'ssssss',
                        $emergency_data['form_id'],
                        $emergency_data['participant_id'],
                        $emergency_data['form_responses'],
                        $emergency_data['metadata'],
                        $emergency_data['raw_post_data'],
                        $emergency_data['error_message']
                    );

                    if ($stmt->execute() && $stmt->affected_rows === 1 && $mysqli->insert_id > 0) {
                        $emergency_id = $mysqli->insert_id;
                        $storage = 'emergency_table_external';
                        error_log('[EIPSI SAFETY] Emergency data saved to EXTERNAL DB');
                    } else {
                        $storage_errors['external'] = $stmt->error ?: 'External emergency INSERT was not confirmed';
                    }
                    $stmt->close();
                } else {
                    $storage_errors['external'] = 'External emergency prepare failed: ' . $mysqli->error;
                }
            } else {
                $storage_errors['external'] = 'External emergency connection unavailable';
            }
        } catch (Throwable $e) {
            $storage_errors['external'] = $e->getMessage();
        } finally {
            if ($mysqli) {
                $mysqli->close();
            }
        }
    }

    // Fallback a WordPress DB si no se pudo guardar en externa o si no está configurada
    if (!$emergency_id) {
        try {
            $created = $wpdb->query("CREATE TABLE IF NOT EXISTS {$emergency_table} (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                form_id VARCHAR(50) NOT NULL,
                participant_id VARCHAR(255),
                form_responses LONGTEXT,
                metadata LONGTEXT,
                raw_post_data LONGTEXT,
                error_message TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                resolved TINYINT DEFAULT 0,
                resolved_at DATETIME NULL,
                KEY form_id (form_id),
                KEY created_at (created_at),
                KEY resolved (resolved)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            if ($created === false) {
                throw new RuntimeException('WordPress emergency CREATE failed: ' . $wpdb->last_error);
            }
            $inserted = $wpdb->insert($emergency_table, $emergency_data);
            if ($inserted === 1 && $wpdb->insert_id > 0) {
                $emergency_id = $wpdb->insert_id;
                $storage = 'emergency_table_wp';
                error_log('[EIPSI SAFETY] Emergency data saved to WORDPRESS DB');
            } else {
                $storage_errors['wordpress'] = $wpdb->last_error ?: 'WordPress emergency INSERT was not confirmed';
            }
        } catch (Throwable $e) {
            $storage_errors['wordpress'] = $e->getMessage();
        }
    }

    // Keep database diagnostics internal; the submit handler returns its existing failure response.
    $diagnostic_error = (string) $original_error;
    if ($storage_errors) {
        $diagnostic_error .= ' | Emergency storage: ' . wp_json_encode($storage_errors);
        error_log('[EIPSI SAFETY] ' . $diagnostic_error);
    }
    $db_type = $storage === 'emergency_table_external' ? 'external' : ($storage ? 'wordpress' : 'none');
    eipsi_safety_alert_admin_critical($emergency_id, $data, $diagnostic_error, $db_type);

    if (!$storage) {
        return array(
            'success' => false,
            'emergency_mode' => true,
            'emergency_id' => null,
            'storage' => null,
            'error' => $diagnostic_error,
            'error_code' => 'EMERGENCY_INSERT_FAILED',
        );
    }

    return array(
        'success' => true,
        'emergency_mode' => true,
        'emergency_id' => $emergency_id,
        'message' => __('Tu respuesta fue guardada en modo seguro. El administrador será notificado.', 'eipsi-forms'),
        'storage' => $storage,
    );
}

/**
 * Alerta crítica al administrador
 */
function eipsi_safety_alert_admin_critical($emergency_id, $data, $error, $db_type = 'wordpress') {
    $to = get_option('admin_email');
    $subject = sprintf(
        $emergency_id ? '[CRÍTICO] Pérdida de datos prevenida - Formulario: %s' : '[CRÍTICO] Respuesta NO guardada - Formulario: %s',
        $data['form_id'] ?? 'unknown'
    );

    if (!$emergency_id) {
        wp_mail($to, $subject, 'No se confirmó persistencia en ningún destino. Error: ' . $error);
        error_log('[EIPSI SAFETY] EMERGENCY SAVE FAILED - no confirmed storage');
        return;
    }

    $body = sprintf(
        "Se activó el modo de emergencia de datos.\n\n" .
        "ID de Emergencia: %d\n" .
        "Formulario: %s\n" .
        "Participante: %s\n" .
        "Error original: %s\n" .
        "Base de datos usada: %s\n" .
        "Fecha: %s\n\n" .
        "Los datos fueron guardados en la tabla: %s\n" .
        "Acción requerida: Verificar tabla de emergencias en %s y recuperar datos.",
        $emergency_id,
        $data['form_id'] ?? 'unknown',
        $data['participant_id'] ?? 'N/A',
        $error,
        $db_type === 'external' ? 'External DB (configurada por usuario)' : 'WordPress DB',
        current_time('mysql'),
        $GLOBALS['wpdb']->prefix . 'eipsi_emergency_submissions',
        $db_type === 'external' ? 'la base de datos externa configurada' : 'la base de datos de WordPress'
    );

    wp_mail($to, $subject, $body);

    // También loguear
    error_log(sprintf(
        '[EIPSI SAFETY] EMERGENCY ALERT SENT - ID: %d, Form: %s, DB: %s',
        $emergency_id,
        $data['form_id'] ?? 'unknown',
        $db_type
    ));
}

/**
 * Verificación post-submit: Confirmar que los datos realmente se guardaron
 */
function eipsi_safety_verify_submission($insert_id, $storage_type, $data) {
    global $wpdb;
    
    if ($storage_type === 'emergency_table') {
        // En modo emergencia, ya verificamos con el insert_id
        return true;
    }
    
    // Verificar que el registro existe
    if ($storage_type === 'wordpress_db') {
        $table = $wpdb->prefix . 'vas_form_results';
        $record = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE id = %d",
            $insert_id
        ));
        
        if ($record) {
            // Verificación adicional: comprobar que form_responses no está vacío
            $responses = $wpdb->get_var($wpdb->prepare(
                "SELECT form_responses FROM {$table} WHERE id = %d",
                $insert_id
            ));
            
            if (empty($responses) || $responses === '[]' || $responses === '{}') {
                error_log(sprintf(
                    '[EIPSI SAFETY] WARNING: Submission %d saved but form_responses is empty!',
                    $insert_id
                ));
                return false;
            }
            
            return true;
        }
    }
    
    return false;
}

/**
 * AUTO-SYNC: Sincroniza automáticamente campos del formulario a survey_participants
 * Detecta todos los campos del JSON y crea columnas dinámicas T{wave}_{field}
 */
function eipsi_auto_sync_participant_fields($data, $insert_id) {
    global $wpdb;
    
    // Anonymous answers/email must not mutate a longitudinal participant record.
    if (empty($data['longitudinal_participant_id'])) {
        return;
    }

    try {
        // ✅ DEBUG: Ver qué datos estamos recibiendo
        error_log('[EIPSI SYNC-DEBUG] Data received: participant_id=' . ($data['participant_id'] ?? 'NULL') . 
                  ', longitudinal_id=' . ($data['longitudinal_participant_id'] ?? 'NULL') . 
                  ', survey_id=' . ($data['survey_id'] ?? 'NULL'));
        
        // ✅ FIX: Usar longitudinal_participant_id (ID real de tabla) en lugar de participant_id (fingerprint)
        $participant_id = $data['longitudinal_participant_id'] ?? null;
        
        // Si no hay longitudinal ID, buscar por email en survey_participants
        $form_responses = json_decode($data['form_responses'] ?? '{}', true);
        $email = $form_responses['email'] ?? $form_responses['correo'] ?? $form_responses['correo_electronico'] ?? '';
        
        if (empty($participant_id) && !empty($email)) {
            $participant_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}survey_participants WHERE email = %s",
                sanitize_email($email)
            ));
            if ($participant_id) {
                error_log('[EIPSI SYNC] Found participant by email: ' . $participant_id);
            }
        }
        
        // Si aún no hay participant_id, intentar buscar por fingerprint (para formularios individuales)
        if (empty($participant_id) && !empty($data['participant_id'])) {
            $fingerprint = $data['participant_id']; // ej: p-71f2e28c2a47
            
            // Buscar si existe un participante con este fingerprint en alguna columna
            $participant_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}survey_participants WHERE fingerprint = %s OR participant_id = %s OR external_id = %s",
                $fingerprint, $fingerprint, $fingerprint
            ));
            
            // Si no existe y tenemos email, crear nuevo participante
            if (empty($participant_id) && !empty($email)) {
                $insert_result = $wpdb->insert(
                    $wpdb->prefix . 'survey_participants',
                    array(
                        'email' => sanitize_email($email),
                        'name' => $form_responses['nombre'] ?? $form_responses['name'] ?? '',
                        'fingerprint' => $fingerprint,
                        'created_at' => current_time('mysql'),
                        'updated_at' => current_time('mysql')
                    ),
                    array('%s', '%s', '%s', '%s', '%s')
                );
                
                if ($insert_result) {
                    $participant_id = $wpdb->insert_id;
                    error_log('[EIPSI SYNC] Created new participant with ID: ' . $participant_id);
                }
            }
        }
        
        if (empty($participant_id)) {
            error_log('[EIPSI SYNC] No participant_id found (tried longitudinal_id, email, fingerprint, and creation), skipping sync');
            return;
        }
        
        // Determinar wave desde wave_id o inferir desde el contexto
        $wave_id = $data['wave_id'] ?? null;
        $wave_number = 1; // Default a T1
        
        if ($wave_id) {
            // Buscar wave_index desde la tabla
            $wave_index = $wpdb->get_var($wpdb->prepare(
                "SELECT wave_index FROM {$wpdb->prefix}survey_waves WHERE id = %d",
                $wave_id
            ));
            if ($wave_index !== null) {
                $wave_number = intval($wave_index) + 1; // 0-based a 1-based
            }
        }
        
        // Parsear form_responses JSON
        $form_responses = $data['form_responses'] ?? '{}';
        $responses = json_decode($form_responses, true);
        if (json_last_error() !== JSON_ERROR_NONE || empty($responses)) {
            error_log('[EIPSI SYNC] Invalid form_responses JSON, skipping sync');
            return;
        }
        
        // Verificar que existe el participante
        $participant_table = $wpdb->prefix . 'survey_participants';
        $participant_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$participant_table} WHERE id = %d",
            $participant_id
        ));
        
        if (!$participant_exists) {
            error_log('[EIPSI SYNC] Participant not found: ' . $participant_id);
            return;
        }
        
        // Preparar columnas y valores a sincronizar
        $update_data = array();
        $column_types = array();
        
        foreach ($responses as $field_name => $field_value) {
            // Sanitizar nombre de campo (alphanumeric + underscore only)
            $safe_field = preg_replace('/[^a-zA-Z0-9_]/', '_', $field_name);
            $safe_field = substr($safe_field, 0, 50); // Limitar longitud
            
            // Construir nombre de columna: T1_email, T1_nombre, etc.
            $column_name = 'T' . $wave_number . '_' . $safe_field;
            
            // Asegurar que la columna existe (crear si no)
            eipsi_ensure_participant_column_exists($column_name);
            
            // Preparar valor
            if (is_array($field_value)) {
                $update_data[$column_name] = json_encode($field_value);
            } else {
                $update_data[$column_name] = sanitize_text_field($field_value);
            }
            
            $column_types[] = $column_name;
        }
        
        // Agregar campos de metadata del formulario
        $metadata_fields = array('device', 'browser', 'os', 'screen_width', 'duration', 'ip_address');
        foreach ($metadata_fields as $meta_field) {
            if (!empty($data[$meta_field])) {
                $column_name = 'T' . $wave_number . '_' . $meta_field;
                eipsi_ensure_participant_column_exists($column_name);
                $update_data[$column_name] = sanitize_text_field($data[$meta_field]);
            }
        }
        
        // Agregar timestamp de submission
        $submitted_at_column = 'T' . $wave_number . '_submitted_at';
        eipsi_ensure_participant_column_exists($submitted_at_column, 'DATETIME');
        $update_data[$submitted_at_column] = current_time('mysql');
        
        // Ejecutar UPDATE si hay datos
        if (!empty($update_data)) {
            $result = $wpdb->update(
                $participant_table,
                $update_data,
                array('id' => $participant_id),
                null, // WordPress determinará formatos
                array('%d')
            );
            
            if ($result !== false) {
                error_log(sprintf(
                    '[EIPSI SYNC] Successfully synced %d fields for participant %d (T%d)',
                    count($update_data),
                    $participant_id,
                    $wave_number
                ));
            } else {
                error_log('[EIPSI SYNC] Failed to update participant: ' . $wpdb->last_error);
            }
        }
        
    } catch (Exception $e) {
        error_log('[EIPSI SYNC] Exception during sync: ' . $e->getMessage());
    }
}

/**
 * Asegura que una columna exista en survey_participants (la crea si no existe)
 */
function eipsi_ensure_participant_column_exists($column_name, $data_type = 'TEXT') {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'survey_participants';
    
    // Verificar si la columna existe
    $column_exists = $wpdb->get_var($wpdb->prepare(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
         WHERE TABLE_NAME = %s AND COLUMN_NAME = %s",
        $table_name,
        $column_name
    ));
    
    if (!$column_exists) {
        // Crear la columna
        $sql = "ALTER TABLE {$table_name} ADD COLUMN {$column_name} {$data_type} NULL";
        $result = $wpdb->query($sql);
        
        if ($result !== false) {
            error_log("[EIPSI SYNC] Created column: {$column_name}");
        } else {
            error_log("[EIPSI SYNC] Failed to create column {$column_name}: " . $wpdb->last_error);
        }
    }
}

/**
 * Dashboard de salud para admin
 */
function eipsi_safety_get_health_status() {
    global $wpdb;
    
    $status = array(
        'healthy' => true,
        'issues' => array(),
        'stats' => array(),
    );
    
    // Verificar si hay emergencias sin resolver
    $emergency_table = $wpdb->prefix . 'eipsi_emergency_submissions';
    $unresolved = $wpdb->get_var("SELECT COUNT(*) FROM {$emergency_table} WHERE resolved = 0");
    
    if ($unresolved > 0) {
        $status['healthy'] = false;
        $status['issues'][] = sprintf(
            '%d submission(s) en modo emergencia sin resolver',
            $unresolved
        );
    }
    
    // Verificar submissions recientes con respuestas vacías
    $recent_empty = $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->prefix}vas_form_results 
         WHERE submitted_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
         AND (form_responses IS NULL OR form_responses = '[]' OR form_responses = '{}')"
    );
    
    if ($recent_empty > 0) {
        $status['healthy'] = false;
        $status['issues'][] = sprintf(
            '%d submission(s) recientes con respuestas vacías',
            $recent_empty
        );
    }
    
    $status['stats']['unresolved_emergencies'] = $unresolved;
    $status['stats']['recent_empty_responses'] = $recent_empty;
    
    // Verificar sincronizaciones recientes (silencioso - solo para diagnóstico)
    $last_sync_check = get_transient('eipsi_last_sync_check');
    if ($last_sync_check === false) {
        // Contar participantes con datos sincronizados recientemente
        $synced_count = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}survey_participants 
             WHERE updated_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        );
        set_transient('eipsi_last_sync_check', $synced_count, 300); // Cache 5 min
        $status['stats']['recent_syncs'] = intval($synced_count);
    } else {
        $status['stats']['recent_syncs'] = intval($last_sync_check);
    }
    
    return $status;
}

/**
 * AJAX handler: Verificación de salud del sistema
 */
function eipsi_safety_health_check_ajax() {
    check_ajax_referer('eipsi_safety_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Sin permisos'));
    }
    
    $health = eipsi_safety_get_health_status();
    
    wp_send_json_success(array(
        'healthy' => $health['healthy'],
        'issues' => $health['issues'],
        'stats' => $health['stats'],
        'timestamp' => current_time('mysql'),
    ));
}
add_action('wp_ajax_eipsi_safety_health_check', 'eipsi_safety_health_check_ajax');

/**
 * Desactiva validación estricta de bloques para permitir importación sin warnings
 * Esto evita mensajes de "contenido inesperado" al importar formularios con bloques
 * de versiones anteriores del plugin.
 */
add_filter('block_editor_settings_all', 'eipsi_disable_block_validation', 999);

function eipsi_disable_block_validation($settings) {
    // Solo aplicar en nuestros CPTs de formularios
    $screen = get_current_screen();
    if ($screen && ($screen->post_type === 'eipsi_form' || $screen->post_type === 'eipsi_wave')) {
        $settings['__experimentalDisableBlockValidation'] = true;
    }
    return $settings;
}
