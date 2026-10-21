<?php
/** M6 owner; existing public adapters retain their contracts. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Emergency_Submission_Store {

public static function eipsi_safety_emergency_save($data, $original_error) {
    require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/privacy-config.php';
    $data = eipsi_filter_capture_data($data, get_privacy_config($data['form_id'] ?? null));

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
        'raw_post_data' => wp_json_encode(eipsi_filter_capture_data($_POST, get_privacy_config($data['form_id'] ?? null))),
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

public static function eipsi_safety_alert_admin_critical($emergency_id, $data, $error, $db_type = 'wordpress') {
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
public static function save($data,$original_error) { return EIPSI_Storage_Result::normalize(self::eipsi_safety_emergency_save($data,$original_error)); }

}
