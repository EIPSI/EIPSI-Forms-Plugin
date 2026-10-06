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

require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/storage/bootstrap.php';


/**
 * Pre-flight data validation
 * Verifica que todos los campos requeridos estén presentes antes de procesar
 */
function eipsi_safety_validate_submission($data) { return EIPSI_Submission_Storage_Service::eipsi_safety_validate_submission($data); }

/**
 * Guardar submission con retry automático
 */
function eipsi_safety_save_with_retry($data, $max_retries = 3) { return EIPSI_Submission_Storage_Service::eipsi_safety_save_with_retry($data, $max_retries); }

/**
 * Intento de guardado normal
 */
function eipsi_safety_attempt_save($data) { return EIPSI_Submission_Storage_Service::attempt($data); }

/**
 * Modo emergencia: Guardar datos críticos cuando todo falla
 * Usa la base de datos configurada (WordPress o External según configuración del usuario)
 */
function eipsi_safety_emergency_save($data, $original_error) { return EIPSI_Emergency_Submission_Store::save($data, $original_error); }

/**
 * Alerta crítica al administrador
 */
function eipsi_safety_alert_admin_critical($emergency_id, $data, $error, $db_type = 'wordpress') { return EIPSI_Emergency_Submission_Store::eipsi_safety_alert_admin_critical($emergency_id, $data, $error, $db_type); }

/**
 * Verificación post-submit: Confirmar que los datos realmente se guardaron
 */
function eipsi_safety_verify_submission($insert_id, $storage_type, $data) { return EIPSI_Submission_Verification_Service::eipsi_safety_verify_submission($insert_id, $storage_type, $data); }

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
        $wave_number = isset($data['wave_index']) && (int) $data['wave_index'] > 0 ? (int) $data['wave_index'] : 1; // Validated one-based submission context
        
        if ($wave_id) {
            // Buscar wave_index desde la tabla
            $wave_index = $wpdb->get_var($wpdb->prepare(
                "SELECT wave_index FROM {$wpdb->prefix}survey_waves WHERE id = %d",
                $wave_id
            ));
            if ($wave_index !== null) {
                $wave_number = intval($wave_index); // Persisted convention: T1=1, T2=2, T3=3
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
function eipsi_safety_get_health_status() { return EIPSI_Submission_Storage_Service::eipsi_safety_get_health_status(); }

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
