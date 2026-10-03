<?php
/**
 * EIPSI Forms - Pool Assignment AJAX API
 *
 * Handlers para que participantes se unan a pools de asignación aleatoria.
 * Funciona tanto para usuarios logueados como anónimos (nopriv).
 *
 * @package EIPSI_Forms
 * @since 2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handler AJAX: unirse a un pool (usuarios logueados y anónimos).
 *
 * Inputs POST esperados:
 *   - eipsi_pool_join_nonce : nonce de seguridad
 *   - pool_id               : int
 *   - email                 : string (email del participante)
 *   - name                  : string (opcional)
 *
 * Respuesta JSON:
 *   { success: bool, data: { magic_link_url, study_name, pool_name, is_new_assignment } }
 *   { success: false, data: { message: string } }
 */
function eipsi_ajax_join_pool() {
    // -----------------------------------------------------------------
    // 1. Verificar nonce
    // -----------------------------------------------------------------
    $nonce = isset( $_POST['eipsi_pool_join_nonce'] )
        ? sanitize_text_field( wp_unslash( $_POST['eipsi_pool_join_nonce'] ) )
        : '';

    if ( ! wp_verify_nonce( $nonce, 'eipsi_pool_access' ) ) {
        wp_send_json_error(
            array( 'message' => __( 'Token de seguridad inválido. Recargá la página e intentá de nuevo.', 'eipsi-forms' ) ),
            403
        );
    }

    // -----------------------------------------------------------------
    // 2. Sanitizar inputs
    // -----------------------------------------------------------------
    $pool_id = isset( $_POST['pool_id'] ) ? absint( $_POST['pool_id'] ) : 0;
    $email   = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
    $name    = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : null;

    if ( ! $pool_id ) {
        wp_send_json_error(
            array( 'message' => __( 'ID de pool inválido.', 'eipsi-forms' ) ),
            400
        );
    }

    if ( ! is_email( $email ) ) {
        wp_send_json_error(
            array( 'message' => __( 'Por favor ingresá un email válido.', 'eipsi-forms' ) ),
            400
        );
    }

    // -----------------------------------------------------------------
    // 3. Llamar al servicio de asignación
    // -----------------------------------------------------------------
    if ( ! class_exists( 'EIPSI_Pool_Assignment_Service' ) ) {
        require_once plugin_dir_path( __FILE__ ) . 'services/class-pool-assignment-service.php';
    }

    $service = new EIPSI_Pool_Assignment_Service();
    $result  = $service->assign_participant_to_pool( $pool_id, $email, $name );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error(
            array( 'message' => $result->get_error_message() ),
            400
        );
    }

    // -----------------------------------------------------------------
    // 4. Respuesta de éxito
    // -----------------------------------------------------------------
    wp_send_json_success(
        array(
            'magic_link_url'    => esc_url( $result['magic_link_url'] ),
            'study_name'        => esc_html( $result['study_name'] ),
            'pool_name'         => esc_html( $result['pool_name'] ),
            'is_new_assignment' => (bool) $result['is_new_assignment'],
            'message'           => $result['is_new_assignment']
                ? __( '¡Listo! Te asignamos a tu estudio. Serás redirigido en un momento...', 'eipsi-forms' )
                : __( 'Ya tenés una asignación en este pool. Preparando tu acceso...', 'eipsi-forms' ),
        )
    );
}

add_action( 'wp_ajax_eipsi_join_pool', 'eipsi_ajax_join_pool' );
add_action( 'wp_ajax_nopriv_eipsi_join_pool', 'eipsi_ajax_join_pool' );

/**
 * Handler AJAX: autenticación en pool (login o register).
 *
 * Inputs POST esperados:
 *   - nonce      : string (nonce de seguridad)
 *   - pool_id    : int
 *   - email      : string
 *   - auth_action: 'login' | 'register'
 *
 * Respuesta JSON para login:
 *   { success: true, data: { 
 *       redirect_url: string|null,  // Si tiene estudio asignado
 *       magic_link_url: string|null, // Si no tiene estudio
 *       message: string 
 *   }}
 *
 * Respuesta JSON para register:
 *   { success: true, data: { 
 *       confirmation_sent: true,
 *       message: string 
 *   }}
 *
 * @since 2.3.0
 */
function eipsi_ajax_pool_auth() {
    error_log("[EIPSI POOL AUTH] === INICIO AJAX pool_auth ===");
    
    // -----------------------------------------------------------------
    // 1. Verificar nonce
    // -----------------------------------------------------------------
    $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
    $pool_id     = isset( $_POST['pool_id'] ) ? absint( $_POST['pool_id'] ) : 0;
    $email       = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
    $auth_action = isset( $_POST['auth_action'] ) ? sanitize_text_field( wp_unslash( $_POST['auth_action'] ) ) : '';

    if ( ! wp_verify_nonce( $nonce, 'eipsi_pool_access' ) ) {
        error_log( sprintf(
            "[EIPSI POOL AUTH] ERROR: Nonce inválido. Pool ID: %d, Email: %s, Action: %s, Nonce: %s",
            $pool_id,
            $email,
            $auth_action,
            $nonce
        ) );
        wp_send_json_error( array( 'message' => __( 'Token de seguridad inválido.', 'eipsi-forms' ) ), 403 );
    }
    
    error_log("[EIPSI POOL AUTH] Nonce verificado OK");

    // -----------------------------------------------------------------
    // 2. Sanitizar inputs (ya realizados arriba para el log)
    // -----------------------------------------------------------------
    
    error_log("[EIPSI POOL AUTH] Inputs: pool_id={$pool_id}, email={$email}, action={$auth_action}");

    if ( ! $pool_id ) {
        error_log("[EIPSI POOL AUTH] ERROR: ID de pool inválido");
        wp_send_json_error( array( 'message' => __( 'ID de pool inválido.', 'eipsi-forms' ) ), 400 );
    }

    if ( ! is_email( $email ) ) {
        error_log("[EIPSI POOL AUTH] ERROR: Email inválido: {$email}");
        wp_send_json_error( array( 'message' => __( 'Por favor ingresá un email válido.', 'eipsi-forms' ) ), 400 );
    }

    if ( ! in_array( $auth_action, array( 'login', 'register' ), true ) ) {
        error_log("[EIPSI POOL AUTH] ERROR: Acción inválida: {$auth_action}");
        wp_send_json_error( array( 'message' => __( 'Acción inválida.', 'eipsi-forms' ) ), 400 );
    }

    global $wpdb;
    $participants_table = $wpdb->prefix . 'survey_participants';

    // -----------------------------------------------------------------
    // 3. Buscar participante por email
    // -----------------------------------------------------------------
    error_log("[EIPSI POOL AUTH] Buscando participante por email: {$email}");
    $participant = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$participants_table} WHERE email = %s",
        $email
    ) );
    
    if ( $participant ) {
        error_log("[EIPSI POOL AUTH] Participante ENCONTRADO: ID={$participant->id}");
    } else {
        error_log("[EIPSI POOL AUTH] Participante NO encontrado para email: {$email}");
    }

    // -----------------------------------------------------------------
    // 4. LOGIN: Participante debe existir
    // -----------------------------------------------------------------
    if ( $auth_action === 'login' ) {
        error_log("[EIPSI POOL AUTH] Procesando LOGIN");
        
        if ( ! $participant ) {
            error_log("[EIPSI POOL AUTH] LOGIN ERROR: Participante no existe");
            wp_send_json_error( array( 
                'message' => __( 'Email no registrado. Por favor creá una cuenta primero.', 'eipsi-forms' ),
                'code'    => 'user_not_found'
            ), 404 );
        }

        // Verificar si tiene estudio asignado activo
        error_log("[EIPSI POOL AUTH] Verificando asignación activa para participant_id={$participant->id}, pool_id={$pool_id}");
        $has_study = eipsi_participant_has_active_pool_study( $participant->id, $pool_id );

        if ( $has_study ) {
            error_log("[EIPSI POOL AUTH] LOGIN: Tiene estudio asignado: study_id={$has_study->study_id}, study_name={$has_study->study_name}");
            // Tiene estudio asignado → enviar magic link directo al estudio
            $magic_link = eipsi_generate_participant_magic_link( $participant->id, $has_study->study_id );
            error_log("[EIPSI POOL AUTH] Magic link generado: {$magic_link}");
            
            wp_send_json_success( array(
                'redirect_url'   => $magic_link,
                'study_name'   => $has_study->study_name,
                'message'      => __( 'Preparando tu acceso al estudio...', 'eipsi-forms' )
            ) );
        } else {
            error_log("[EIPSI POOL AUTH] LOGIN: NO tiene estudio asignado → redirigiendo a dashboard");
            // No tiene estudio asignado → enviar magic link al dashboard del pool
            $magic_link = eipsi_generate_pool_dashboard_link( $participant->id, $pool_id );
            error_log("[EIPSI POOL AUTH] Dashboard link generado: {$magic_link}");
            
            wp_send_json_success( array(
                'magic_link_url' => $magic_link,
                'message'        => __( 'Ingresá a tu dashboard del pool para asignarte un estudio.', 'eipsi-forms' )
            ) );
        }
    }

    // -----------------------------------------------------------------
    // 5. REGISTER: Crear nuevo participante
    // -----------------------------------------------------------------
    if ( $auth_action === 'register' ) {
        error_log("[EIPSI POOL AUTH] Procesando REGISTER");
        
        if ( $participant ) {
            error_log("[EIPSI POOL AUTH] REGISTER ERROR: Email ya existe");
            wp_send_json_error( array( 
                'message' => __( 'Este email ya está registrado. Por favor ingresá con tu cuenta.', 'eipsi-forms' ),
                'code'    => 'email_exists'
            ), 409 );
        }

        // Crear nuevo participante
        error_log("[EIPSI POOL AUTH] Creando nuevo participante...");
        $result = $wpdb->insert(
            $participants_table,
            array(
                'email'         => $email,
                'created_at'    => current_time( 'mysql' ),
            ),
            array( '%s', '%s' )
        );

        if ( $result === false ) {
            error_log("[EIPSI POOL AUTH] REGISTER ERROR: Falló inserción en DB");
            wp_send_json_error( array( 'message' => __( 'Error al crear la cuenta. Por favor intentá de nuevo.', 'eipsi-forms' ) ), 500 );
        }

        $participant_id = $wpdb->insert_id;
        error_log("[EIPSI POOL AUTH] Participante creado: participant_id={$participant_id}");

        // Enviar email de confirmación
        error_log("[EIPSI POOL AUTH] Enviando email de confirmación...");
        $confirmation_sent = eipsi_send_pool_email_confirmation( $participant_id, $email, $pool_id );

        if ( $confirmation_sent ) {
            error_log("[EIPSI POOL AUTH] Email de confirmación enviado OK");
            wp_send_json_success( array(
                'confirmation_sent' => true,
                'message'           => __( '¡Listo! Te enviamos un email de confirmación. Revisá tu bandeja de entrada (y spam) para activar tu cuenta.', 'eipsi-forms' )
            ) );
        } else {
            error_log("[EIPSI POOL AUTH] REGISTER ERROR: Falló envío de email");
            wp_send_json_error( array( 'message' => __( 'Error al enviar el email de confirmación. Por favor intentá de nuevo.', 'eipsi-forms' ) ), 500 );
        }
    }
}

add_action( 'wp_ajax_eipsi_pool_auth', 'eipsi_ajax_pool_auth' );
add_action( 'wp_ajax_nopriv_eipsi_pool_auth', 'eipsi_ajax_pool_auth' );

/**
 * Handler AJAX: obtener estadísticas de un pool (solo admins).
 *
 * Inputs POST esperados:
 *   - eipsi_pool_stats_nonce : nonce de seguridad
 *   - pool_id                : int
 *
 * Respuesta JSON:
 *   { success: bool, data: { by_study: {...}, total: int } }
 */
function eipsi_ajax_get_pool_stats() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'Sin permisos.', 'eipsi-forms' ) ), 403 );
    }

    $nonce = isset( $_POST['eipsi_pool_stats_nonce'] )
        ? sanitize_text_field( wp_unslash( $_POST['eipsi_pool_stats_nonce'] ) )
        : '';

    if ( ! wp_verify_nonce( $nonce, 'eipsi_pool_stats' ) ) {
        wp_send_json_error( array( 'message' => __( 'Token inválido.', 'eipsi-forms' ) ), 403 );
    }

    $pool_id = isset( $_POST['pool_id'] ) ? absint( $_POST['pool_id'] ) : 0;

    if ( ! $pool_id ) {
        wp_send_json_error( array( 'message' => __( 'ID de pool inválido.', 'eipsi-forms' ) ), 400 );
    }

    if ( ! class_exists( 'EIPSI_Pool_Assignment_Service' ) ) {
        require_once plugin_dir_path( __FILE__ ) . 'services/class-pool-assignment-service.php';
    }

    $service = new EIPSI_Pool_Assignment_Service();
    $stats   = $service->get_pool_stats( $pool_id );

    wp_send_json_success( $stats );
}

add_action( 'wp_ajax_eipsi_get_pool_stats', 'eipsi_ajax_get_pool_stats' );

/**
 * Handler AJAX: obtener resumen de todos los pools (para Overview).
 *
 * @since 2.5.3 - Fase 5: Pool Hub Dashboard
 */
function eipsi_ajax_get_all_pools_summary() {
    // Accept both nonces for backward compatibility
    $nonce_ok = false;
    if (isset($_POST['nonce']) && wp_verify_nonce($_POST['nonce'], 'eipsi_pool_hub')) {
        $nonce_ok = true;
    } elseif (isset($_POST['nonce']) && wp_verify_nonce($_POST['nonce'], 'eipsi_admin_nonce')) {
        $nonce_ok = true;
    }
    
    if (!$nonce_ok) {
        wp_send_json_error(array('message' => __('Token inválido.', 'eipsi-forms'), 'debug' => 'nonce failed'), 403);
    }
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('Sin permisos.', 'eipsi-forms')), 403);
    }

    global $wpdb;
    $pools_table = $wpdb->prefix . 'eipsi_longitudinal_pools';
    $assignments_table = $wpdb->prefix . 'eipsi_pool_assignments';

    $pools = $wpdb->get_results("SELECT * FROM {$pools_table} ORDER BY created_at DESC", ARRAY_A);

    $pools_summary = array();

    foreach ($pools as $pool) {
        $pool_id = intval($pool['id']);
        $studies = json_decode($pool['studies'] ?? '[]', true);
        $probabilities = json_decode($pool['probabilities'] ?? '[]', true);
        $config = json_decode($pool['config'] ?? '{}', true);

        $total_assignments = intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$assignments_table} WHERE pool_id = %d",
            $pool_id
        )));

        $completed_assignments = intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$assignments_table} WHERE pool_id = %d AND completed = 1",
            $pool_id
        )));

        $completion_rate = $total_assignments > 0 ? round(($completed_assignments / $total_assignments) * 100, 1) : 0;

        $distribution = array();
        $total_deviation = 0;

        foreach ($studies as $index => $study_id) {
            $expected_prob = isset($probabilities[$index]) ? floatval($probabilities[$index]) : 0;
            $actual_count = intval($wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$assignments_table} WHERE pool_id = %d AND study_id = %d",
                $pool_id, $study_id
            )));

            $actual_pct = $total_assignments > 0 ? round(($actual_count / $total_assignments) * 100, 1) : 0;
            $deviation = abs($actual_pct - $expected_prob);
            $total_deviation += $deviation;

            $study_name = $wpdb->get_var($wpdb->prepare(
                "SELECT study_name FROM {$wpdb->prefix}survey_studies WHERE id = %d",
                $study_id
            ));

            $distribution[] = array(
                'study_id' => $study_id,
                'study_name' => $study_name,
                'expected' => $expected_prob,
                'actual' => $actual_pct,
                'count' => $actual_count,
                'deviation' => $deviation
            );
        }

        $balance_score = count($studies) > 0 ? round(100 - ($total_deviation / count($studies)), 1) : 100;

        // Email counts (v2.5.4)
        $emails_sent = intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT email) FROM {$wpdb->prefix}eipsi_pool_email_log WHERE pool_id = %d AND action IN ('sent', 'resent')",
            $pool_id
        )));

        $emails_confirmed = intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT email) FROM {$wpdb->prefix}eipsi_pool_email_log WHERE pool_id = %d AND action = 'confirmed'",
            $pool_id
        )));

        // Get pool page URL - use page_id from pool table directly (more reliable than meta query)
        $page_id = !empty($pool['page_id']) ? intval($pool['page_id']) : 0;
        $page_url = $page_id ? get_permalink($page_id) : null;

        $pools_summary[] = array(
            'id' => $pool_id,
            'name' => $pool['pool_name'],
            'description' => $pool['pool_description'],
            'status' => $pool['status'],
            'method' => $pool['method'],
            'studies_count' => count($studies),
            'total_assignments' => $total_assignments,
            'completed_assignments' => $completed_assignments,
            'completion_rate' => $completion_rate,
            'emails_sent' => $emails_sent,
            'emails_confirmed' => $emails_confirmed,
            'balance_score' => $balance_score,
            'distribution' => $distribution,
            'config' => $config,
            'created_at' => $pool['created_at'],
            'page_url' => $page_url
        );
    }

    error_log('[EIPSI-POOL-AJAX] Sending ' . count($pools_summary) . ' pools to frontend');
    wp_send_json_success(array('pools' => $pools_summary));
}
add_action('wp_ajax_eipsi_get_all_pools_summary', 'eipsi_ajax_get_all_pools_summary');

/**
 * Handler AJAX: obtener logs de emails de un pool.
 *
 * @since 2.5.4
 */
function eipsi_ajax_get_pool_email_logs() {
    // Accept both nonces for backward compatibility
    $nonce_ok = false;
    if (isset($_GET['nonce']) && wp_verify_nonce($_GET['nonce'], 'eipsi_pool_hub')) {
        $nonce_ok = true;
    } elseif (isset($_GET['nonce']) && wp_verify_nonce($_GET['nonce'], 'eipsi_admin_nonce')) {
        $nonce_ok = true;
    }
    
    if (!$nonce_ok) {
        wp_send_json_error(array('message' => __('Token inválido.', 'eipsi-forms')), 403);
    }
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('Sin permisos.', 'eipsi-forms')), 403);
    }

    $pool_id = isset($_GET['pool_id']) ? absint($_GET['pool_id']) : 0;

    if (!$pool_id) {
        wp_send_json_error(array('message' => __('ID de pool inválido.', 'eipsi-forms')), 400);
    }

    global $wpdb;
    $log_table = $wpdb->prefix . 'eipsi_pool_email_log';

    // Get latest log for each email in this pool
    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT l1.* 
         FROM {$log_table} l1
         INNER JOIN (
            SELECT email, MAX(created_at) as latest 
            FROM {$log_table} 
            WHERE pool_id = %d 
            GROUP BY email
         ) l2 ON l1.email = l2.email AND l1.created_at = l2.latest
         WHERE l1.pool_id = %d
         ORDER BY l1.created_at DESC",
        $pool_id, $pool_id
    ));

    $logs = array();
    foreach ($results as $row) {
        $status = 'pending';
        $status_label = '⏳ ' . __('Pendiente', 'eipsi-forms');
        
        if ($row->action === 'confirmed') {
            $status = 'confirmed';
            $status_label = '✅ ' . __('Confirmado', 'eipsi-forms');
        } else {
            // Check for expiration (24h)
            $created_time = strtotime($row->created_at);
            if (time() - $created_time > 86400) {
                $status = 'expired';
                $status_label = '⚪ ' . __('Expirado', 'eipsi-forms');
            }
        }

        $logs[] = array(
            'email' => $row->email,
            'status' => $status,
            'status_label' => $status_label,
            'participant_id' => $row->participant_id,
            'created_at' => $row->created_at
        );
    }

    wp_send_json_success(array('logs' => $logs));
}
add_action('wp_ajax_eipsi_get_pool_email_logs', 'eipsi_ajax_get_pool_email_logs');

/**
 * Handler AJAX: reenviar email de confirmación de pool.
 *
 * @since 2.5.4
 */
function eipsi_ajax_resend_pool_confirmation() {
    // Accept both nonces for backward compatibility
    $nonce_ok = false;
    if (isset($_POST['nonce']) && wp_verify_nonce($_POST['nonce'], 'eipsi_pool_hub')) {
        $nonce_ok = true;
    } elseif (isset($_POST['nonce']) && wp_verify_nonce($_POST['nonce'], 'eipsi_admin_nonce')) {
        $nonce_ok = true;
    }
    
    if (!$nonce_ok) {
        wp_send_json_error(array('message' => __('Token inválido.', 'eipsi-forms')), 403);
    }
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('Sin permisos.', 'eipsi-forms')), 403);
    }

    $pool_id = isset($_POST['pool_id']) ? absint($_POST['pool_id']) : 0;
    $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
    $participant_id = isset($_POST['participant_id']) ? absint($_POST['participant_id']) : 0;

    if (!$pool_id || !$email || !$participant_id) {
        wp_send_json_error(array('message' => __('Parámetros inválidos.', 'eipsi-forms')), 400);
    }

    global $wpdb;
    $log_table = $wpdb->prefix . 'eipsi_pool_email_log';

    // Rate Limit: Max 3 records with action='resent' for that participant_id in the last 24 hours. (v2.5.5)
    $recent_resends = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$log_table} 
         WHERE participant_id = %d 
         AND action = 'resent' 
         AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)",
        $participant_id
    ));

    if ($recent_resends >= 3) {
        wp_send_json_error(array('message' => __('Límite de 3 reenvíos alcanzado para este participante en 24 horas.', 'eipsi-forms')), 429);
    }

    // Verify status (v2.5.5)
    $latest_log = $wpdb->get_row($wpdb->prepare(
        "SELECT action, created_at FROM {$log_table} 
         WHERE participant_id = %d AND pool_id = %d 
         ORDER BY created_at DESC LIMIT 1",
        $participant_id, $pool_id
    ));

    if ($latest_log && $latest_log->action === 'confirmed') {
        wp_send_json_error(array('message' => __('El participante ya ha confirmado su email.', 'eipsi-forms')), 400);
    }

    // Call the function to send email with action 'resent'
    $sent = eipsi_send_pool_email_confirmation($participant_id, $email, $pool_id, 'resent');

    if ($sent) {
        wp_send_json_success(array('message' => __('Email reenviado correctamente.', 'eipsi-forms')));
    } else {
        wp_send_json_error(array('message' => __('Error al enviar el email.', 'eipsi-forms')));
    }
}
add_action('wp_ajax_eipsi_resend_pool_confirmation', 'eipsi_ajax_resend_pool_confirmation');

/**
 * Handler AJAX: toggle estado activo/pausado de un pool.
 *
 * @since 2.5.3 - Fase 5: Pool Hub Dashboard
 */
function eipsi_ajax_toggle_pool_status() {
    // Accept both nonces for backward compatibility
    $nonce_ok = false;
    if (isset($_POST['nonce']) && wp_verify_nonce($_POST['nonce'], 'eipsi_pool_hub')) {
        $nonce_ok = true;
    } elseif (isset($_POST['nonce']) && wp_verify_nonce($_POST['nonce'], 'eipsi_admin_nonce')) {
        $nonce_ok = true;
    }
    
    if (!$nonce_ok) {
        wp_send_json_error(array('message' => __('Token inválido.', 'eipsi-forms'), 'debug' => 'nonce failed'), 403);
    }
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('Sin permisos.', 'eipsi-forms')), 403);
    }

    $pool_id = isset($_POST['pool_id']) ? absint($_POST['pool_id']) : 0;
    $status = isset($_POST['status']) ? sanitize_key($_POST['status']) : '';

    if (!$pool_id || !in_array($status, array('active', 'paused'))) {
        wp_send_json_error(array('message' => __('Parámetros inválidos.', 'eipsi-forms')), 400);
    }

    global $wpdb;
    $pools_table = $wpdb->prefix . 'eipsi_longitudinal_pools';

    $result = $wpdb->update(
        $pools_table,
        array('status' => $status),
        array('id' => $pool_id),
        array('%s'),
        array('%d')
    );

    if ($result === false) {
        wp_send_json_error(array('message' => __('Error al actualizar estado.', 'eipsi-forms')), 500);
    }

    wp_send_json_success(array(
        'pool_id' => $pool_id,
        'status' => $status,
        'message' => $status === 'active' ? __('Pool activado.', 'eipsi-forms') : __('Pool pausado.', 'eipsi-forms')
    ));
}
add_action('wp_ajax_eipsi_toggle_pool_status', 'eipsi_ajax_toggle_pool_status');

/**
 * Handler AJAX: obtener analytics detallados de un pool.
 *
 * @since 2.5.3 - Fase 5: Pool Hub Dashboard
 */
function eipsi_ajax_get_pool_analytics() {
    // Accept both nonces for backward compatibility
    $nonce_ok = false;
    if (isset($_GET['nonce']) && wp_verify_nonce($_GET['nonce'], 'eipsi_pool_hub')) {
        $nonce_ok = true;
    } elseif (isset($_GET['nonce']) && wp_verify_nonce($_GET['nonce'], 'eipsi_admin_nonce')) {
        $nonce_ok = true;
    }
    
    if (!$nonce_ok) {
        wp_send_json_error(array('message' => __('Token inválido.', 'eipsi-forms'), 'debug' => 'nonce failed'), 403);
    }
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('Sin permisos.', 'eipsi-forms')), 403);
    }

    $pool_id = isset($_GET['pool_id']) ? absint($_GET['pool_id']) : 0;

    if (!$pool_id) {
        wp_send_json_error(array('message' => __('ID de pool inválido.', 'eipsi-forms')), 400);
    }

    global $wpdb;
    $pools_table = $wpdb->prefix . 'eipsi_longitudinal_pools';
    $assignments_table = $wpdb->prefix . 'eipsi_pool_assignments';
    $participants_table = $wpdb->prefix . 'survey_participants';
    $studies_table = $wpdb->prefix . 'survey_studies';

    $pool = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$pools_table} WHERE id = %d",
        $pool_id
    ), ARRAY_A);

    if (!$pool) {
        wp_send_json_error(array('message' => __('Pool no encontrado.', 'eipsi-forms')), 404);
    }

    $studies = json_decode($pool['studies'] ?? '[]', true);
    $probabilities = json_decode($pool['probabilities'] ?? '[]', true);

    $total_assignments = intval($wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$assignments_table} WHERE pool_id = %d",
        $pool_id
    )));

    $completed = intval($wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$assignments_table} WHERE pool_id = %d AND completed = 1",
        $pool_id
    )));

    $dropouts = intval($wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$assignments_table}
         WHERE pool_id = %d AND completed = 0
         AND last_access < DATE_SUB(NOW(), INTERVAL 7 DAY)",
        $pool_id
    )));

    $completion_rate = $total_assignments > 0 ? round(($completed / $total_assignments) * 100, 1) : 0;
    $dropout_rate = $total_assignments > 0 ? round(($dropouts / $total_assignments) * 100, 1) : 0;

    $study_breakdown = array();
    $total_deviation = 0;

    foreach ($studies as $index => $study_id) {
        $expected = isset($probabilities[$index]) ? floatval($probabilities[$index]) : 0;
        $study_name = $wpdb->get_var($wpdb->prepare(
            "SELECT study_name FROM {$studies_table} WHERE id = %d",
            $study_id
        ));

        $assigned = intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$assignments_table} WHERE pool_id = %d AND study_id = %d",
            $pool_id, $study_id
        )));

        $study_completed = intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$assignments_table} WHERE pool_id = %d AND study_id = %d AND completed = 1",
            $pool_id, $study_id
        )));

        $in_progress = $assigned - $study_completed;
        $actual_pct = $total_assignments > 0 ? round(($assigned / $total_assignments) * 100, 1) : 0;
        $delta = round($actual_pct - $expected, 1);
        $total_deviation += abs($delta);
        $study_completion_rate = $assigned > 0 ? round(($study_completed / $assigned) * 100, 1) : 0;

        $study_breakdown[] = array(
            'study_id' => $study_id,
            'study_name' => $study_name,
            'assigned' => $assigned,
            'expected_pct' => $expected,
            'actual_pct' => $actual_pct,
            'delta' => $delta,
            'completed' => $study_completed,
            'in_progress' => $in_progress,
            'completion_rate' => $study_completion_rate
        );
    }

    $balance_score = count($studies) > 0 ? round(100 - ($total_deviation / count($studies)), 1) : 100;

    $recent_activity = $wpdb->get_results($wpdb->prepare(
        "SELECT a.*, p.email, s.study_name
         FROM {$assignments_table} a
         LEFT JOIN {$participants_table} p ON a.participant_id = p.id
         LEFT JOIN {$studies_table} s ON a.study_id = s.id
         WHERE a.pool_id = %d
         ORDER BY a.assigned_at DESC
         LIMIT 10",
        $pool_id
    ), ARRAY_A);

    $activity_formatted = array();
    foreach ($recent_activity as $item) {
        $activity_formatted[] = array(
            'participant_email' => $item['email'] ? substr($item['email'], 0, strpos($item['email'], '@') + 1) . '***' : '***',
            'study_name' => $item['study_name'],
            'assigned_at' => mysql2date('d/m/Y H:i', $item['assigned_at']),
            'status' => $item['completed'] ? 'completado' : 'asignado'
        );
    }

    wp_send_json_success(array(
        'pool' => array(
            'id' => $pool_id,
            'name' => $pool['pool_name'],
            'status' => $pool['status']
        ),
        'metrics' => array(
            'total_assignments' => $total_assignments,
            'completion_rate' => $completion_rate,
            'balance_score' => $balance_score,
            'dropout_rate' => $dropout_rate
        ),
        'study_breakdown' => $study_breakdown,
        'recent_activity' => $activity_formatted
    ));
}
add_action('wp_ajax_eipsi_get_pool_analytics', 'eipsi_get_pool_analytics_dispatch');
function eipsi_get_pool_analytics_dispatch() {
    $nonce = $_POST['nonce'] ?? '';
    if (is_string($nonce) && wp_verify_nonce($nonce, 'eipsi_pool_dashboard_nonce')) {
        eipsi_get_pool_analytics();
        return;
    }
    eipsi_ajax_get_pool_analytics();
}

/**
 * Handler AJAX: exportar asignaciones de pool a CSV.
 *
 * @since 2.5.3 - Fase 5: Pool Hub Dashboard
 */
function eipsi_ajax_export_pool_assignments() {
    // Accept both nonces for backward compatibility
    $nonce_ok = false;
    if (isset($_GET['nonce']) && wp_verify_nonce($_GET['nonce'], 'eipsi_pool_hub')) {
        $nonce_ok = true;
    } elseif (isset($_GET['nonce']) && wp_verify_nonce($_GET['nonce'], 'eipsi_admin_nonce')) {
        $nonce_ok = true;
    }
    
    if (!$nonce_ok) {
        wp_die(__('Token inválido.', 'eipsi-forms'));
    }
    if (!current_user_can('manage_options')) {
        wp_die(__('Sin permisos.', 'eipsi-forms'));
    }

    $pool_id = isset($_GET['pool_id']) ? absint($_GET['pool_id']) : 0;

    if (!$pool_id) {
        wp_die(__('ID de pool inválido.', 'eipsi-forms'));
    }

    global $wpdb;
    $assignments_table = $wpdb->prefix . 'eipsi_pool_assignments';
    $participants_table = $wpdb->prefix . 'survey_participants';
    $studies_table = $wpdb->prefix . 'survey_studies';

    $pool_name = $wpdb->get_var($wpdb->prepare(
        "SELECT pool_name FROM {$wpdb->prefix}eipsi_longitudinal_pools WHERE id = %d",
        $pool_id
    ));

    $assignments = $wpdb->get_results($wpdb->prepare(
        "SELECT a.*, p.email, p.name as participant_name, s.study_name
         FROM {$assignments_table} a
         LEFT JOIN {$participants_table} p ON a.participant_id = p.id
         LEFT JOIN {$studies_table} s ON a.study_id = s.id
         WHERE a.pool_id = %d
         ORDER BY a.assigned_at DESC",
        $pool_id
    ), ARRAY_A);

    $filename = sanitize_file_name('pool-' . $pool_name . '-assignments-' . date('Y-m-d') . '.csv');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    fputcsv($output, array(
        'participant_id', 'email', 'participant_name', 'study_id', 'study_name',
        'assigned_at', 'last_access', 'access_count', 'completed', 'completed_at'
    ));

    foreach ($assignments as $row) {
        fputcsv($output, array(
            $row['participant_id'],
            $row['email'],
            $row['participant_name'],
            $row['study_id'],
            $row['study_name'],
            $row['assigned_at'],
            $row['last_access'],
            $row['access_count'],
            $row['completed'] ? '1' : '0',
            $row['completed_at']
        ));
    }

    fclose($output);
    exit;
}
add_action('wp_ajax_eipsi_export_pool_assignments', 'eipsi_export_pool_assignments_dispatch');
function eipsi_export_pool_assignments_dispatch() {
    $nonce = $_POST['nonce'] ?? '';
    if (is_string($nonce) && wp_verify_nonce($nonce, 'eipsi_pool_dashboard_nonce')) {
        eipsi_export_pool_assignments();
        return;
    }
    eipsi_ajax_export_pool_assignments();
}

// =========================================================================
// HELPER FUNCTIONS FOR POOL AUTHENTICATION
// =========================================================================

/**
 * Verificar si un participante tiene un estudio activo asignado en un pool.
 *
 * @param int $participant_id ID del participante.
 * @param int $pool_id         ID del pool.
 * @return object|false Objeto con study_id y study_name, o false si no tiene.
 */
function eipsi_participant_has_active_pool_study( $participant_id, $pool_id ) {
    global $wpdb;
    
    $assignments_table = $wpdb->prefix . 'eipsi_pool_assignments';
    $studies_table = $wpdb->prefix . 'survey_studies';
    
    $assignment = $wpdb->get_row( $wpdb->prepare(
        "SELECT pa.study_id, ps.study_name 
         FROM {$assignments_table} pa
         JOIN {$studies_table} ps ON pa.study_id = ps.id
         WHERE pa.participant_id = %s 
         AND pa.pool_id = %d
         AND pa.completed = 0
         ORDER BY pa.assigned_at DESC
         LIMIT 1",
        $participant_id,
        $pool_id
    ) );
    
    return $assignment ? $assignment : false;
}

/**
 * Generar magic link para un participante hacia un estudio.
 *
 * @param int $participant_id ID del participante.
 * @param int $study_id         ID del estudio.
 * @return string URL del magic link.
 */
function eipsi_generate_participant_magic_link( $participant_id, $study_id ) {
    $token = wp_create_nonce( 'eipsi_magic_' . $participant_id . '_' . $study_id );
    $expires = time() + ( 24 * 60 * 60 ); // 24 horas
    
    // Guardar token en meta para validación
    update_user_meta( $participant_id, 'eipsi_magic_token_' . $study_id, array(
        'token'   => $token,
        'expires' => $expires,
    ) );
    
    $study_url = get_permalink( $study_id );
    $magic_url = add_query_arg( array(
        'magic_login'    => '1',
        'participant_id' => $participant_id,
        'study_id'       => $study_id,
        'token'          => $token,
    ), $study_url );
    
    return $magic_url;
}

/**
 * Generar link al dashboard del pool para un participante.
 *
 * @param int $participant_id ID del participante.
 * @param int $pool_id         ID del pool.
 * @return string URL del dashboard del pool.
 */
function eipsi_generate_pool_dashboard_link( $participant_id, $pool_id ) {
    // Obtener la página del pool
    global $wpdb;
    $pools_table = $wpdb->prefix . 'eipsi_longitudinal_pools';
    
    $pool = $wpdb->get_row( $wpdb->prepare(
        "SELECT page_id FROM {$pools_table} WHERE id = %d",
        $pool_id
    ) );
    
    if ( $pool && $pool->page_id ) {
        $pool_url = get_permalink( $pool->page_id );
    } else {
        // Fallback: buscar página con el shortcode del pool
        $pool_url = home_url( '/pool-dashboard/' );
    }
    
    $token = wp_create_nonce( 'eipsi_pool_access_' . $participant_id . '_' . $pool_id );
    
    $dashboard_url = add_query_arg( array(
        'pool_access'    => '1',
        'participant_id' => $participant_id,
        'pool_id'        => $pool_id,
        'token'          => $token,
    ), $pool_url );
    
    return $dashboard_url;
}

/**
 * Enviar email de confirmación para registro en pool.
 *
 * @param int    $participant_id ID del participante.
 * @param string $email            Email del participante.
 * @param int    $pool_id          ID del pool.
 * @param string $action           Acción a registrar ('sent' o 'resent').
 * @return bool True si se envió correctamente.
 */
function eipsi_send_pool_email_confirmation( $participant_id, $email, $pool_id, $action = 'sent' ) {
    // Incluir el servicio de confirmación si existe
    if ( ! class_exists( 'EIPSI_Email_Confirmation_Service' ) ) {
        require_once dirname( __FILE__ ) . '/services/class-email-confirmation-service.php';
    }
    
    // Generar token de confirmación
    $token = wp_create_nonce( 'eipsi_pool_confirm_' . $participant_id . '_' . time() );
    
    // Guardar token en la tabla de participantes (o crear tabla de tokens si no existe)
    global $wpdb;
    $participants_table = $wpdb->prefix . 'survey_participants';
    
    // Guardar token en meta temporal (usando transient o opción)
    set_transient( 'eipsi_pool_confirm_' . $participant_id, array(
        'token'     => $token,
        'pool_id'   => $pool_id,
        'email'     => $email,
        'expires'   => time() + ( 24 * 60 * 60 ), // 24 horas
    ), 24 * 60 * 60 );
    
    // Obtener info del pool
    $pools_table = $wpdb->prefix . 'eipsi_longitudinal_pools';
    $pool = $wpdb->get_row( $wpdb->prepare(
        "SELECT name, page_id FROM {$pools_table} WHERE id = %d",
        $pool_id
    ) );
    
    $pool_name = $pool ? $pool->name : __( 'Pool de Estudios', 'eipsi-forms' );
    
    // Construir URL de confirmación
    $confirm_url = add_query_arg( array(
        'eipsi_action'     => 'pool_confirm',
        'participant_id'  => $participant_id,
        'token'           => $token,
    ), home_url( '/' ) );
    
    // Asunto y mensaje
    $subject = sprintf( __( 'Confirmá tu email para participar en %s', 'eipsi-forms' ), $pool_name );
    
    $message = sprintf(
        __( "Hola,

Para completar tu registro en %s, por favor confirmá tu email haciendo clic en el siguiente enlace:

%s

Este enlace expira en 24 horas.

Si no solicitaste este registro, podés ignorar este mensaje.

Saludos,
El equipo de EIPSI", 'eipsi-forms' ),
        $pool_name,
        $confirm_url
    );
    
    $headers = array( 'Content-Type: text/plain; charset=UTF-8' );
    
    $sent = wp_mail( $email, $subject, $message, $headers );
    
    if ( $sent ) {
        error_log( "[EIPSI Pool] Email de confirmación enviado a {$email} para pool {$pool_id}" );
        
        // Registrar el envío inicial
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'eipsi_pool_email_log',
            array(
                'pool_id'        => $pool_id,
                'participant_id' => $participant_id,
                'email'          => $email,
                'action'         => $action,
                'created_at'     => current_time( 'mysql' ),
            ),
            array( '%d', '%d', '%s', '%s', '%s' )
        );
    } else {
        error_log( "[EIPSI Pool] ERROR al enviar email de confirmación a {$email}" );
    }
    
    return $sent;
}

// ============================================================================
// AJAX: SOLICITUD DE ASIGNACIÓN DESDE DASHBOARD DEL POOL
// ============================================================================

/**
 * Handler AJAX: solicitar asignación de estudio desde el dashboard del pool.
 *
 * Este endpoint es llamado cuando un participante logueado y confirmado
 * hace clic en "Asignarme un estudio" desde el dashboard del pool.
 *
 * Inputs POST esperados:
 *   - nonce         : string (nonce de seguridad)
 *   - pool_id       : int
 *   - participant_id: int
 *
 * Respuesta JSON:
 *   { success: true, data: { 
 *       assignment_id: int,
 *       study_id: int,
 *       study_name: string,
 *       redirect_url: string,  // Magic link al estudio
 *       message: string
 *   }}
 *
 * @since 2.3.0
 */
function eipsi_ajax_request_pool_assignment() {
    error_log("[EIPSI POOL ASSIGN] === INICIO AJAX request_pool_assignment ===");
    
    // -----------------------------------------------------------------
    // 1. Verificar nonce
    // -----------------------------------------------------------------
    $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
    $pool_id        = isset( $_POST['pool_id'] ) ? absint( $_POST['pool_id'] ) : 0;
    $participant_id = isset( $_POST['participant_id'] ) ? sanitize_text_field( wp_unslash( $_POST['participant_id'] ) ) : '';

    if ( ! wp_verify_nonce( $nonce, 'eipsi_pool_access' ) ) {
        error_log( sprintf(
            "[EIPSI POOL ASSIGN] ERROR: Nonce inválido. Pool ID: %d, Participant ID: %d, Nonce: %s",
            $pool_id,
            $participant_id,
            $nonce
        ) );
        wp_send_json_error( array( 'message' => __( 'Token de seguridad inválido.', 'eipsi-forms' ) ), 403 );
    }

    error_log("[EIPSI POOL ASSIGN] Nonce verificado OK");

    // -----------------------------------------------------------------
    // 2. Sanitizar inputs
    // -----------------------------------------------------------------
    
    error_log("[EIPSI POOL ASSIGN] Inputs: pool_id={$pool_id}, participant_id={$participant_id}");

    if ( ! $pool_id || ! $participant_id ) {
        error_log("[EIPSI POOL ASSIGN] ERROR: Datos inválidos");
        wp_send_json_error( array( 'message' => __( 'Datos inválidos.', 'eipsi-forms' ) ), 400 );
    }

    // -----------------------------------------------------------------
    // 3. Verificar que el participante no tenga asignación existente
    // -----------------------------------------------------------------
    global $wpdb;
    $assignments_table = $wpdb->prefix . 'eipsi_pool_assignments';

    error_log("[EIPSI POOL ASSIGN] Verificando asignación existente...");
    $existing = $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM {$assignments_table} 
         WHERE pool_id = %d AND participant_id = %s AND completed = 0
         LIMIT 1",
        $pool_id,
        $participant_id
    ) );

    if ( $existing ) {
        error_log("[EIPSI POOL ASSIGN] ERROR: Ya tiene asignación existente (assignment_id={$existing})");
        wp_send_json_error( array( 
            'message' => __( 'Ya tenés un estudio asignado activo.', 'eipsi-forms' ),
            'code'    => 'already_assigned'
        ), 409 );
    }
    error_log("[EIPSI POOL ASSIGN] No tiene asignación existente - OK");

    // -----------------------------------------------------------------
    // 4. Obtener método de asignación del pool
    // -----------------------------------------------------------------
    $pools_table = $wpdb->prefix . 'eipsi_longitudinal_pools';
    $pool = $wpdb->get_row( $wpdb->prepare(
        "SELECT method FROM {$pools_table} WHERE id = %d",
        $pool_id
    ) );

    if ( ! $pool ) {
        error_log("[EIPSI POOL ASSIGN] ERROR: Pool no encontrado");
        wp_send_json_error( array( 'message' => __( 'Pool no encontrado.', 'eipsi-forms' ) ), 404 );
    }

    $method = $pool->method ?: 'seeded';
    error_log("[EIPSI POOL ASSIGN] Método de asignación: {$method}");

    // -----------------------------------------------------------------
    // 5. Crear asignación usando el servicio
    // -----------------------------------------------------------------
    error_log("[EIPSI POOL ASSIGN] Creando asignación...");
    $service = new EIPSI_Pool_Assignment_Service();
    $assignment = $service->assign_participant( $pool_id, $participant_id, $method );

    if ( ! $assignment || is_wp_error( $assignment ) ) {
        $error_message = is_wp_error( $assignment ) ? $assignment->get_error_message() : __( 'Error al asignar estudio.', 'eipsi-forms' );
        error_log("[EIPSI POOL ASSIGN] ERROR: Falló asignación - {$error_message}");
        wp_send_json_error( array( 'message' => $error_message ), 500 );
    }
    
    error_log("[EIPSI POOL ASSIGN] Asignación creada: assignment_id={$assignment->assignment_id}, study_id={$assignment->study_id}");

    // -----------------------------------------------------------------
    // 6. Enviar email de nudge0 (bienvenida al estudio)
    // -----------------------------------------------------------------
    $study_name = $service->get_study_name( $assignment->study_id );
    error_log("[EIPSI POOL ASSIGN] Study name: {$study_name}");
    
    // Trigger para enviar nudge0
    error_log("[EIPSI POOL ASSIGN] Trigger eipsi_pool_assignment_created");
    do_action( 'eipsi_pool_assignment_created', $participant_id, $pool_id, $assignment->study_id );

    // -----------------------------------------------------------------
    // 7. Generar magic link al estudio
    // -----------------------------------------------------------------
    $magic_link = eipsi_generate_participant_magic_link( $participant_id, $assignment->study_id );
    error_log("[EIPSI POOL ASSIGN] Magic link generado: {$magic_link}");

    // -----------------------------------------------------------------
    // 8. Responder éxito
    // -----------------------------------------------------------------
    error_log("[EIPSI POOL ASSIGN] === ASIGNACIÓN COMPLETADA ===");
    wp_send_json_success( array(
        'assignment_id' => $assignment->assignment_id,
        'study_id'        => $assignment->study_id,
        'study_name'      => $study_name,
        'redirect_url'    => $magic_link,
        'message'         => sprintf( __( '¡Te asignamos a: %s!', 'eipsi-forms' ), $study_name )
    ) );
}

add_action( 'wp_ajax_eipsi_request_pool_assignment', 'eipsi_ajax_request_pool_assignment' );
add_action( 'wp_ajax_nopriv_eipsi_request_pool_assignment', 'eipsi_ajax_request_pool_assignment' );
