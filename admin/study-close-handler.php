<?php
/**
 * EIPSI Forms - Study Close & Anonymization Handler
 * 
 * Gestiona el cierre de estudios y anonimización ética de datos.
 * 
 * @package EIPSI_Forms
 * @since 1.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// AJAX Handler para cerrar estudio
// Legacy callable retained; the dashboard API owns the operative action.

/**
 * Handler para cerrar estudio y anonimizar datos
 * 
 * @since 1.3.0
 */
function eipsi_close_study_handler() {
    check_ajax_referer('eipsi_admin_nonce', 'nonce');
    
    if (!eipsi_user_can_manage_longitudinal()) {
        wp_send_json_error(array('message' => __('Sin permisos.', 'eipsi-forms')));
    }
    
    $form_id = isset($_POST['form_id']) ? intval($_POST['form_id']) : 0;
    
    if (!$form_id) {
        wp_send_json_error(array('message' => __('Form ID inválido.', 'eipsi-forms')));
    }
    
    // Verificar que el formulario existe
    if (get_post_type($form_id) !== 'eipsi_form') {
        wp_send_json_error(array('message' => __('Formulario no encontrado.', 'eipsi-forms')));
    }
    
    // Ejecutar anonimización
    $result = eipsi_anonymize_study_data($form_id);
    
    if (is_wp_error($result)) {
        wp_send_json_error(array('message' => $result->get_error_message()));
    }
    
    // Actualizar estado del estudio
    update_post_meta($form_id, '_eipsi_study_status', 'closed');
    update_post_meta($form_id, '_eipsi_study_closed_at', current_time('mysql'));
    
    wp_send_json_success(array(
        'message' => sprintf(
            __('Estudio cerrado exitosamente. %d participantes anonimizados.', 'eipsi-forms'),
            $result['participants_anonymized']
        ),
        'details' => $result,
    ));
}

/**
 * Anonimiza datos de un estudio
 * 
 * Borra:
 * - Tokens temporales
 * - Emails de participantes
 * - Metadata de temp login
 * 
 * Mantiene:
 * - Seeds (necesarios para reproducibilidad de asignación)
 * - form_id asignados
 * - Datos de respuestas (con participant_id anónimo)
 * 
 * @param int $form_id Form ID
 * @return array|WP_Error Resultado de la anonimización
 * @since 1.3.0
 */
function eipsi_anonymize_study_data($form_id) {
    global $wpdb;
    
    $participants_anonymized = 0;
    $tokens_deleted = 0;
    $emails_deleted = 0;
    
    // 1. Borrar tokens temporales de recordatorios
    $meta_table = $wpdb->postmeta;
    $deleted_tokens = $wpdb->query($wpdb->prepare(
        "DELETE FROM {$meta_table} 
        WHERE post_id = %d 
        AND (
            meta_key LIKE %s 
            OR meta_key LIKE %s
            OR meta_key LIKE %s
        )",
        $form_id,
        $wpdb->esc_like('_eipsi_reminder_token_') . '%',
        $wpdb->esc_like('_eipsi_reminder_sent_') . '%',
        $wpdb->esc_like('_eipsi_temp_login_') . '%'
    ));
    
    if ($deleted_tokens !== false) {
        $tokens_deleted = $deleted_tokens;
    }
    
    // 2. Anonimizar asignaciones de participantes (mantener seed, borrar email)
    // Buscar todas las asignaciones: _eipsi_toma_X_assign
    $assignments = $wpdb->get_results($wpdb->prepare(
        "SELECT meta_key, meta_value 
        FROM {$meta_table} 
        WHERE post_id = %d 
        AND meta_key LIKE %s",
        $form_id,
        $wpdb->esc_like('_eipsi_toma_') . '%' . $wpdb->esc_like('_assign')
    ));
    
    foreach ($assignments as $row) {
        $data = maybe_unserialize($row->meta_value);
        
        if (!is_array($data)) {
            continue;
        }
        
        // Si tiene email, anonimizar
        if (isset($data['email'])) {
            // Generar ID anónimo consistente (hash del email para mantener unicidad)
            $anon_id = 'ANON-' . substr(md5($data['email']), 0, 12);
            
            // Actualizar datos: mantener form_id y seed, reemplazar email con anon_id
            $new_data = array(
                'form_id' => $data['form_id'] ?? null,
                'seed' => $data['seed'] ?? null,
                'type' => $data['type'] ?? 'random',
                'timestamp' => $data['timestamp'] ?? current_time('mysql'),
                'status' => $data['status'] ?? 'pending',
                'anonymized' => true,
                'anonymized_at' => current_time('mysql'),
                'participant_anon_id' => $anon_id,
            );
            
            // Actualizar postmeta con datos anonimizados
            update_post_meta($form_id, $row->meta_key, $new_data);
            
            $participants_anonymized++;
        }
    }
    
    // 3. Anonimizar asignaciones principales (_eipsi_assign_{hash})
    $main_assignments = $wpdb->get_results($wpdb->prepare(
        "SELECT meta_key, meta_value 
        FROM {$meta_table} 
        WHERE post_id = %d 
        AND meta_key LIKE %s",
        $form_id,
        $wpdb->esc_like('_eipsi_assign_') . '%'
    ));
    
    foreach ($main_assignments as $row) {
        $data = maybe_unserialize($row->meta_value);
        
        if (!is_array($data)) {
            continue;
        }
        
        // Mantener seed, borrar metadata identificable
        $new_data = array(
            'form_id' => $data['form_id'] ?? null,
            'seed' => $data['seed'] ?? null,
            'type' => $data['type'] ?? 'random',
            'timestamp' => $data['timestamp'] ?? current_time('mysql'),
            'main_form_id' => $data['main_form_id'] ?? $form_id,
            'anonymized' => true,
            'anonymized_at' => current_time('mysql'),
        );
        
        update_post_meta($form_id, $row->meta_key, $new_data);
    }
    
    // 4. Borrar flags de unsubscribe (ya no son relevantes si estudio cerrado)
    $deleted_unsubs = $wpdb->query($wpdb->prepare(
        "DELETE FROM {$meta_table} 
        WHERE post_id = %d 
        AND meta_key LIKE %s",
        $form_id,
        $wpdb->esc_like('_eipsi_unsubscribed_') . '%'
    ));
    
    if ($deleted_unsubs !== false) {
        $emails_deleted += $deleted_unsubs;
    }
    
    // 5. Log de anonimización
    add_post_meta($form_id, '_eipsi_anonymization_log', array(
        'timestamp' => current_time('mysql'),
        'participants_anonymized' => $participants_anonymized,
        'tokens_deleted' => $tokens_deleted,
        'emails_deleted' => $emails_deleted,
        'admin_user' => get_current_user_id(),
    ));
    
    // Log para debug
    error_log("[EIPSI Forms] Study {$form_id} anonymized: {$participants_anonymized} participants");
    
    return array(
        'participants_anonymized' => $participants_anonymized,
        'tokens_deleted' => $tokens_deleted,
        'emails_deleted' => $emails_deleted,
        'success' => true,
    );
}

/**
 * Verifica si un estudio está cerrado
 * 
 * @param int $form_id Form ID
 * @return bool True si está cerrado
 * @since 1.3.0
 */
function eipsi_is_study_closed($form_id) {
    $status = get_post_meta($form_id, '_eipsi_study_status', true);
    return ($status === 'closed');
}

/**
 * Obtiene detalles de anonimización de un estudio
 * 
 * @param int $form_id Form ID
 * @return array|null Log de anonimización o null
 * @since 1.3.0
 */
function eipsi_get_anonymization_log($form_id) {
    return get_post_meta($form_id, '_eipsi_anonymization_log', true);
}
