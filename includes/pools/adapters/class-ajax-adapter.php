<?php
/** M7 owner. Public compatibility adapters retain registration and signatures. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Pool_Ajax_Adapter {

public static function eipsi_ajax_join_pool() {
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

    $access = EIPSI_Authorization_Policy::authorize_session_context(wp_unslash($_POST));
    if (!$access['success']) {
        wp_send_json_error(array('message' => __('Se requiere una sesión participante válida.', 'eipsi-forms'), 'code' => $access['error']), $access['error'] === 'authentication_required' ? 401 : 403);
    }
    $participant_id = (int) $access['participant_id'];
    // 3. Llamar al servicio de asignación
    // -----------------------------------------------------------------
    if ( ! class_exists( 'EIPSI_Pool_Assignment_Service' ) ) {
        require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-pool-assignment-service.php';
    }

    $service = new EIPSI_Pool_Assignment_Service();
    $participant = EIPSI_Participant_Repository::get_by_id($participant_id);
    if (!$participant || strcasecmp($participant->email, $email) !== 0) { wp_send_json_error(array('message' => __('La identidad no coincide con la sesión.', 'eipsi-forms')), 403); }
    $result = $service->assign_participant_to_pool($pool_id, $participant->email, $name);

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

public static function eipsi_ajax_pool_auth() {
    EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_ajax_adapter_L85'), 'debug');
    
    // -----------------------------------------------------------------
    // 1. Verificar nonce
    // -----------------------------------------------------------------
    $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
    $pool_id     = isset( $_POST['pool_id'] ) ? absint( $_POST['pool_id'] ) : 0;
    $email       = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
    $auth_action = isset( $_POST['auth_action'] ) ? sanitize_text_field( wp_unslash( $_POST['auth_action'] ) ) : '';

    if ( ! wp_verify_nonce( $nonce, 'eipsi_pool_access' ) ) {
        EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_ajax_adapter_L96'), 'debug');
        wp_send_json_error( array( 'message' => __( 'Token de seguridad inválido.', 'eipsi-forms' ) ), 403 );
    }
    
    EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_ajax_adapter_L106'), 'debug');

    // -----------------------------------------------------------------
    // 2. Sanitizar inputs (ya realizados arriba para el log)
    // -----------------------------------------------------------------
    
    EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_ajax_adapter_L112'), 'debug');

    if ( ! $pool_id ) {
        EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_ajax_adapter_L115'), 'debug');
        wp_send_json_error( array( 'message' => __( 'ID de pool inválido.', 'eipsi-forms' ) ), 400 );
    }

    if ( ! is_email( $email ) ) {
        EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_ajax_adapter_L120'), 'debug');
        wp_send_json_error( array( 'message' => __( 'Por favor ingresá un email válido.', 'eipsi-forms' ) ), 400 );
    }

    if ( ! in_array( $auth_action, array( 'login', 'register' ), true ) ) {
        EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_ajax_adapter_L125'), 'debug');
        wp_send_json_error( array( 'message' => __( 'Acción inválida.', 'eipsi-forms' ) ), 400 );
    }

    global $wpdb;
    $participants_table = $wpdb->prefix . 'survey_participants';

    // -----------------------------------------------------------------
    // 3. Buscar participante por email
    // -----------------------------------------------------------------
    EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_ajax_adapter_L135'), 'debug');
    $participant = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$participants_table} WHERE email = %s",
        $email
    ) );
    
    if ( $participant ) {
        EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_ajax_adapter_L142'), 'debug');
    } else {
        EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_ajax_adapter_L144'), 'debug');
    }

    // -----------------------------------------------------------------
    // 4. LOGIN: Participante debe existir
    // -----------------------------------------------------------------
    if ( $auth_action === 'login' ) {
    $access = EIPSI_Authorization_Policy::authorize_session_context(wp_unslash($_POST));
    if (!$access['success']) {
        wp_send_json_error(array('message' => __('Se requiere una sesión participante válida.', 'eipsi-forms'), 'code' => $access['error']), $access['error'] === 'authentication_required' ? 401 : 403);
    }
    $participant_id = (int) $access['participant_id'];

        if (!$participant || (int)$participant->id !== $participant_id) { wp_send_json_error(array('message'=>__('La identidad no coincide con la sesión.', 'eipsi-forms')),403); }

        EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_ajax_adapter_L159'), 'debug');
        
        if ( ! $participant ) {
            EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_ajax_adapter_L162'), 'debug');
            wp_send_json_error( array( 
                'message' => __( 'Email no registrado. Por favor creá una cuenta primero.', 'eipsi-forms' ),
                'code'    => 'user_not_found'
            ), 404 );
        }

        // Verificar si tiene estudio asignado activo
        EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_ajax_adapter_L170'), 'debug');
        $has_study = eipsi_participant_has_active_pool_study( $participant->id, $pool_id );

        if ( $has_study ) {
            EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_ajax_adapter_L174'), 'debug');
            // Tiene estudio asignado → enviar magic link directo al estudio
            $magic_link = eipsi_generate_participant_magic_link( $participant->id, $has_study->study_id );
            
            wp_send_json_success( array(
                'redirect_url'   => $magic_link,
                'study_name'   => $has_study->study_name,
                'message'      => __( 'Preparando tu acceso al estudio...', 'eipsi-forms' )
            ) );
        } else {
            EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_ajax_adapter_L184'), 'debug');
            // No tiene estudio asignado → enviar magic link al dashboard del pool
            $magic_link = eipsi_generate_pool_dashboard_link( $participant->id, $pool_id );
            
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
        EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_ajax_adapter_L199'), 'debug');
        
        if ( $participant ) {
            EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_ajax_adapter_L202'), 'debug');
            wp_send_json_error( array( 
                'message' => __( 'Este email ya está registrado. Por favor ingresá con tu cuenta.', 'eipsi-forms' ),
                'code'    => 'email_exists'
            ), 409 );
        }

        // Crear nuevo participante
        EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_ajax_adapter_L210'), 'debug');
        $result = $wpdb->insert(
            $participants_table,
            array(
                'email'         => $email,
                'created_at'    => current_time( 'mysql' ),
            ),
            array( '%s', '%s' )
        );

        if ( $result === false ) {
            EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_ajax_adapter_L221'), 'debug');
            wp_send_json_error( array( 'message' => __( 'Error al crear la cuenta. Por favor intentá de nuevo.', 'eipsi-forms' ) ), 500 );
        }

        $participant_id = $wpdb->insert_id;
        EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_ajax_adapter_L226'), 'debug');

        // Enviar email de confirmación
        EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_ajax_adapter_L229'), 'debug');
        $confirmation_sent = eipsi_send_pool_email_confirmation( $participant_id, $email, $pool_id );

        if ( $confirmation_sent ) {
            EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_ajax_adapter_L233'), 'debug');
            wp_send_json_success( array(
                'confirmation_sent' => true,
                'message'           => __( '¡Listo! Te enviamos un email de confirmación. Revisá tu bandeja de entrada (y spam) para activar tu cuenta.', 'eipsi-forms' )
            ) );
        } else {
            EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_ajax_adapter_L239'), 'debug');
            wp_send_json_error( array( 'message' => __( 'Error al enviar el email de confirmación. Por favor intentá de nuevo.', 'eipsi-forms' ) ), 500 );
        }
    }
}

public static function eipsi_ajax_get_pool_stats() {
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
        require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-pool-assignment-service.php';
    }

    $service = new EIPSI_Pool_Assignment_Service();
    $stats   = $service->get_pool_stats( $pool_id );

    wp_send_json_success( $stats );
}

public static function eipsi_ajax_get_all_pools_summary() {
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

    EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_ajax_adapter_L382'), 'debug');
    wp_send_json_success(array('pools' => $pools_summary));
}

public static function eipsi_ajax_get_pool_email_logs() {
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

public static function eipsi_ajax_resend_pool_confirmation() {
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

public static function eipsi_ajax_toggle_pool_status() {
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

public static function eipsi_ajax_get_pool_analytics() {
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

public static function eipsi_get_pool_analytics_dispatch() {
    $nonce = $_POST['nonce'] ?? '';
    if (is_string($nonce) && wp_verify_nonce($nonce, 'eipsi_pool_dashboard_nonce')) {
        eipsi_get_pool_analytics();
        return;
    }
    eipsi_ajax_get_pool_analytics();
}

public static function eipsi_ajax_export_pool_assignments() {
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

    require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/export/bootstrap.php';
    $dataset=EIPSI_Export_Query_Service::pool_roster_hub($pool_id);
    EIPSI_Export_File_Service::stream_pool_roster_hub($pool_id, $dataset);
    exit;
}

public static function eipsi_export_pool_assignments_dispatch() {
    $nonce = $_POST['nonce'] ?? '';
    if (is_string($nonce) && wp_verify_nonce($nonce, 'eipsi_pool_dashboard_nonce')) {
        eipsi_export_pool_assignments();
        return;
    }
    eipsi_ajax_export_pool_assignments();
}

public static function eipsi_participant_has_active_pool_study( $participant_id, $pool_id ) {
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

public static function eipsi_generate_participant_magic_link($participant_id, $study_id) { return EIPSI_Email_Service::generate_magic_link_url($study_id, $participant_id); }

public static function eipsi_generate_pool_dashboard_link( $participant_id, $pool_id ) {
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

public static function eipsi_send_pool_email_confirmation( $participant_id, $email, $pool_id, $action = 'sent' ) {
    // Incluir el servicio de confirmación si existe
    if ( ! class_exists( 'EIPSI_Email_Confirmation_Service' ) ) {
        require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-email-confirmation-service.php';
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
        EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_ajax_adapter_L862'), 'debug');
        
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
        EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_ajax_adapter_L878'), 'debug');
    }
    
    return $sent;
}

public static function eipsi_ajax_request_pool_assignment() {
    EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_ajax_adapter_L885'), 'debug');
    
    // -----------------------------------------------------------------
    // 1. Verificar nonce
    // -----------------------------------------------------------------
    $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
    $pool_id        = isset( $_POST['pool_id'] ) ? absint( $_POST['pool_id'] ) : 0;
    $participant_id = isset( $_POST['participant_id'] ) ? sanitize_text_field( wp_unslash( $_POST['participant_id'] ) ) : '';

    if ( ! wp_verify_nonce( $nonce, 'eipsi_pool_access' ) ) {
        EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_ajax_adapter_L895'), 'debug');
        wp_send_json_error( array( 'message' => __( 'Token de seguridad inválido.', 'eipsi-forms' ) ), 403 );
    }


    $access = EIPSI_Authorization_Policy::authorize_session_context(wp_unslash($_POST));
    if (!$access['success']) {
        wp_send_json_error(array('message' => __('Se requiere una sesión participante válida.', 'eipsi-forms'), 'code' => $access['error']), $access['error'] === 'authentication_required' ? 401 : 403);
    }
    $participant_id = (int) $access['participant_id'];
    EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_ajax_adapter_L910'), 'debug');

    // -----------------------------------------------------------------
    // 2. Sanitizar inputs
    // -----------------------------------------------------------------
    
    EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_ajax_adapter_L916'), 'debug');

    if ( ! $pool_id || ! $participant_id ) {
        EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_ajax_adapter_L919'), 'debug');
        wp_send_json_error( array( 'message' => __( 'Datos inválidos.', 'eipsi-forms' ) ), 400 );
    }

    // -----------------------------------------------------------------
    // 3. Verificar que el participante no tenga asignación existente
    // -----------------------------------------------------------------
    global $wpdb;
    $assignments_table = $wpdb->prefix . 'eipsi_pool_assignments';

    EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_ajax_adapter_L929'), 'debug');
    $existing = $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM {$assignments_table} 
         WHERE pool_id = %d AND participant_id = %s AND completed = 0
         LIMIT 1",
        $pool_id,
        $participant_id
    ) );

    if ( $existing ) {
        EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_ajax_adapter_L939'), 'debug');
        wp_send_json_error( array( 
            'message' => __( 'Ya tenés un estudio asignado activo.', 'eipsi-forms' ),
            'code'    => 'already_assigned'
        ), 409 );
    }
    EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_ajax_adapter_L945'), 'debug');

    // -----------------------------------------------------------------
    // 4. Obtener método de asignación del pool
    // -----------------------------------------------------------------
    $pools_table = $wpdb->prefix . 'eipsi_longitudinal_pools';
    $pool = $wpdb->get_row( $wpdb->prepare(
        "SELECT method FROM {$pools_table} WHERE id = %d",
        $pool_id
    ) );

    if ( ! $pool ) {
        EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_ajax_adapter_L957'), 'debug');
        wp_send_json_error( array( 'message' => __( 'Pool no encontrado.', 'eipsi-forms' ) ), 404 );
    }

    $method = $pool->method ?: 'seeded';
    EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_ajax_adapter_L962'), 'debug');

    // -----------------------------------------------------------------
    // 5. Crear asignación usando el servicio
    // -----------------------------------------------------------------
    EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_ajax_adapter_L967'), 'debug');
    $service = new EIPSI_Pool_Assignment_Service();
    $assignment = $service->assign_participant( $pool_id, $participant_id, $method );

    if ( ! $assignment || is_wp_error( $assignment ) ) {
        $error_message = is_wp_error( $assignment ) ? $assignment->get_error_message() : __( 'Error al asignar estudio.', 'eipsi-forms' );
        EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_ajax_adapter_L973'), 'debug');
        wp_send_json_error( array( 'message' => $error_message ), 500 );
    }
    
    EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_ajax_adapter_L977'), 'debug');

    // -----------------------------------------------------------------
    // 6. Enviar email de nudge0 (bienvenida al estudio)
    // -----------------------------------------------------------------
    $study_name = $service->get_study_name( $assignment->study_id );
    EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_ajax_adapter_L983'), 'debug');
    
    // Trigger para enviar nudge0
    EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_ajax_adapter_L986'), 'debug');
    do_action( 'eipsi_pool_assignment_created', $participant_id, $pool_id, $assignment->study_id );

    // -----------------------------------------------------------------
    // 7. Generar magic link al estudio
    // -----------------------------------------------------------------
    $magic_link = eipsi_generate_participant_magic_link( $participant_id, $assignment->study_id );

    // -----------------------------------------------------------------
    // 8. Responder éxito
    // -----------------------------------------------------------------
    EIPSI_Security_Log::event('LEGACY_DIAGNOSTIC', array('code'=>'class_ajax_adapter_L997'), 'debug');
    wp_send_json_success( array(
        'assignment_id' => $assignment->id,
        'study_id'        => $assignment->study_id,
        'study_name'      => $study_name,
        'redirect_url'    => $magic_link,
        'message'         => sprintf( __( '¡Te asignamos a: %s!', 'eipsi-forms' ), $study_name )
    ) );
}
}
