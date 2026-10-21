<?php
/** M1 bootstrap: preserve existing contracts and registration order. */
if (!defined('ABSPATH')) { exit; }

function eipsi_get_default_menu_capabilities() {
    return array(
        'main' => 'edit_posts',
        'results' => 'manage_options',
        'configuration' => 'manage_options',
        'longitudinal' => 'edit_posts',
        'form_library' => 'edit_posts'
    );
}

function eipsi_get_menu_capabilities() {
    return apply_filters('eipsi_forms_menu_capabilities', eipsi_get_default_menu_capabilities());
}

function eipsi_get_longitudinal_capability() {
    $capabilities = eipsi_get_menu_capabilities();
    $capability = isset($capabilities['longitudinal']) ? $capabilities['longitudinal'] : 'edit_posts';

    return apply_filters('eipsi_forms_longitudinal_capability', $capability);
}

function eipsi_user_can_manage_longitudinal() {
    return current_user_can(eipsi_get_longitudinal_capability());
}

function eipsi_create_shortcode_page( $title, $slug, $shortcode, $args = array() ) {
    // Wrap shortcode in proper Shortcode block format (not Classic block)
    $block_content = "<!-- wp:shortcode -->\n" . $shortcode . "\n<!-- /wp:shortcode -->";

    $default_args = array(
        'post_title'   => $title,
        'post_name'    => $slug,
        'post_content' => $block_content,
        'post_status'  => 'publish',
        'post_type'    => 'page',
    );

    $page_data = wp_parse_args( $args, $default_args );
    $page_id   = wp_insert_post( $page_data, true );

    return $page_id;
}

function eipsi_delete_associated_page( $page_id ) {
    if ( empty( $page_id ) || $page_id <= 0 ) {
        return false;
    }

    $page = get_post( $page_id );
    if ( ! $page || $page->post_type !== 'page' ) {
        return false;
    }

    // Force delete the page (skip trash)
    $result = wp_delete_post( $page_id, true );

    return $result !== false;
}

function eipsi_mail_from($from_email) {
    // If SMTP is configured, it will handle the sender
    if (class_exists('EIPSI_SMTP_Service')) {
        $smtp_service = new EIPSI_SMTP_Service();
        if ($smtp_service->is_enabled()) {
            return $from_email;
        }
    }
    
    // Use investigator email or fall back to admin email
    $investigator_email = get_option('eipsi_investigator_email', '');
    if (!empty($investigator_email) && is_email($investigator_email)) {
        return $investigator_email;
    }
    
    return $from_email;
}

function eipsi_mail_from_name($from_name) {
    // If SMTP is configured, it will handle the sender name
    if (class_exists('EIPSI_SMTP_Service')) {
        $smtp_service = new EIPSI_SMTP_Service();
        if ($smtp_service->is_enabled()) {
            return $from_name;
        }
    }
    
    // Use investigator name or fall back to site name
    $investigator_name = get_option('eipsi_investigator_name', '');
    if (!empty($investigator_name)) {
        return $investigator_name;
    }
    
    return $from_name;
}

function eipsi_set_html_content_type() {
    return 'text/html';
}

function eipsi_log_mail_error($wp_error) {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[EIPSI Forms] Email Error: ' . $wp_error->get_error_message());
    }
    return $wp_error;
}

function eipsi_wake_up_job_processor() {
    // Solo ejecutar en requests normales (no AJAX, no CRON, no REST API)
    if (defined('DOING_AJAX') && DOING_AJAX) return;
    if (defined('DOING_CRON') && DOING_CRON) return;
    if (defined('REST_REQUEST') && REST_REQUEST) return;
    
    // Verificar si la clase existe
    if (!class_exists('EIPSI_Nudge_Job_Queue')) {
        return;
    }
    
    // Contar jobs urgentes pendientes
    $pending_count = EIPSI_Nudge_Job_Queue::count_pending_urgent();
    
    // Solo procesar si hay jobs pendientes
    if ($pending_count > 0) {
        // Procesar máximo 2 jobs para no relentizar la página
        $stats = EIPSI_Nudge_Job_Queue::process_batch(2);
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[EIPSI WakeUp] Auto-processed jobs via page visit: %d completed, %d retried, %d failed',
                $stats['completed'],
                $stats['retried'],
                $stats['failed']
            ));
        }
    }
}

function eipsi_purge_access_logs_handler() {
    if (!class_exists('EIPSI_Participant_Access_Log_Service')) {
        require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-participant-access-log-service.php';
    }
    
    $retention_days = (int) get_option('eipsi_access_log_retention_days', 365);
    $deleted = EIPSI_Participant_Access_Log_Service::purge_old_logs($retention_days);
    
    if ($deleted > 0) {
        error_log("[EIPSI Forms] Purged {$deleted} old access log records (retention: {$retention_days} days)");
    }
}

function eipsi_handle_unsubscribe_request() {
    if (isset($_GET['eipsi_unsubscribe']) && $_GET['eipsi_unsubscribe'] === '1') {
        eipsi_unsubscribe_reminders_handler();
    }
}

function eipsi_render_randomization_block($attributes) {
    $shortcode = isset($attributes['generatedShortcode']) ? $attributes['generatedShortcode'] : '';

    if (empty($shortcode)) {
        return '<div class="eipsi-randomization-notice">' .
               '<p>' . esc_html__('Configurá el bloque de aleatorización para mostrar un formulario.', 'eipsi-forms') . '</p>' .
               '</div>';
    }

    // Enqueue assets necesarios para aleatorización
    eipsi_forms_enqueue_frontend_assets();

    // Procesar el shortcode
    return do_shortcode($shortcode);
}

function eipsi_render_pool_join_block($attributes) {
    // Usar la clase renderer para mantener consistencia
    if (!class_exists('EIPSI_Pool_Block_Renderer')) {
        return '<div class="eipsi-pool-error">' .
               '<p>' . esc_html__('Error: Pool Block Renderer no disponible.', 'eipsi-forms') . '</p>' .
               '</div>';
    }

    return EIPSI_Pool_Block_Renderer::render_block($attributes);
}

function eipsi_forms_render_form_block($attributes) {
    $form_id = isset($attributes['formId']) ? sanitize_text_field($attributes['formId']) : '';
    $show_title = isset($attributes['showTitle']) ? (bool) $attributes['showTitle'] : true;
    $class_name = isset($attributes['className']) ? sanitize_html_class($attributes['className']) : '';

    eipsi_forms_enqueue_frontend_assets();

    if (empty($form_id)) {
        return '<div class="eipsi-form-notice"><p>' . esc_html__('Please configure the form ID in block settings.', 'eipsi-forms') . '</p></div>';
    }

    $output = '<div class="eipsi-form ' . esc_attr($class_name) . '">';
    
    if ($show_title) {
        $output .= '<h3 class="form-title">' . esc_html(ucwords(str_replace('-', ' ', $form_id))) . '</h3>';
    }
    
    $output .= '<form class="vas-form" data-form-id="' . esc_attr($form_id) . '" data-current-page="1" data-total-pages="1">';
    $output .= '<input type="hidden" name="form_id" value="' . esc_attr($form_id) . '">';
    $output .= '<input type="hidden" name="form_action" value="eipsi_forms_submit_form">';
    $output .= '<input type="hidden" name="ip_address" class="eipsi-ip-placeholder" value="">';
    $output .= '<input type="hidden" name="device" class="eipsi-device-placeholder" value="">';
    $output .= '<input type="hidden" name="browser" class="eipsi-browser-placeholder" value="">';
    $output .= '<input type="hidden" name="os" class="eipsi-os-placeholder" value="">';
    $output .= '<input type="hidden" name="screen_width" class="eipsi-screen-placeholder" value="">';
    $output .= '<input type="hidden" name="form_start_time" class="eipsi-start-time" value="">';
    $output .= '<input type="hidden" name="form_end_time" class="eipsi-end-time" value="">';
    $output .= '<input type="hidden" name="current_page" class="eipsi-current-page" value="1">';
    $output .= '<div class="form-group">';
    $output .= '<label for="form-name" class="required">Nombre</label>';
    $output .= '<input type="text" id="form-name" name="name" required>';
    $output .= '<div class="form-error"></div>';
    $output .= '</div>';
    $output .= '<div class="form-group">';
    $output .= '<label for="form-email" class="required">Correo electrónico</label>';
    $output .= '<input type="email" id="form-email" name="email" required>';
    $output .= '<div class="form-error"></div>';
    $output .= '</div>';
    $output .= '<div class="form-group">';
    $output .= '<label for="form-message">Mensaje</label>';
    $output .= '<textarea id="form-message" name="message"></textarea>';
    $output .= '<div class="form-error"></div>';
    $output .= '</div>';
    $output .= '<div class="form-submit">';
    $output .= '<button type="submit">Enviar</button>';
    $output .= '</div>';
    $output .= '</form>';
    $output .= '</div>';

    return $output;
}

function eipsi_forms_load_textdomain() {
    load_plugin_textdomain(
        'eipsi-forms',
        false,
        dirname(plugin_basename(EIPSI_FORMS_PLUGIN_FILE)) . '/languages'
    );
}

function eipsi_smtp_configuration_notice() {
    // Only show to users who can manage options
    if (!current_user_can('manage_options')) {
        return;
    }
    
    // Only show on EIPSI admin pages
    $screen = get_current_screen();
    if (!$screen || strpos($screen->id, 'eipsi') === false) {
        return;
    }
    
    // Check if Double Opt-In is enabled
    $double_optin_enabled = defined('EIPSI_DOUBLE_OPTIN_ENABLED') ? EIPSI_DOUBLE_OPTIN_ENABLED : true;
    if (!$double_optin_enabled) {
        return;
    }
    
    // Check SMTP configuration
    if (!class_exists('EIPSI_SMTP_Service')) {
        require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-smtp-service.php';
    }
    
    $smtp_service = new EIPSI_SMTP_Service();
    if ($smtp_service->is_configured()) {
        return; // SMTP is configured, no notice needed
    }
    
    // Show warning notice
    $config_url = admin_url('admin.php?page=eipsi-configuration&tab=smtp');
    ?>
    <div class="notice notice-warning is-dismissible">
        <p>
            <strong><?php echo esc_html__('EIPSI Forms - Double Opt-In Requiere SMTP', 'eipsi-forms'); ?></strong>
        </p>
        <p>
            <?php echo esc_html__('El sistema Double Opt-In está habilitado pero SMTP no está configurado. Los participantes no recibirán emails de confirmación.', 'eipsi-forms'); ?>
        </p>
        <p>
            <a href="<?php echo esc_url($config_url); ?>" class="button button-primary">
                <?php echo esc_html__('Configurar SMTP Ahora', 'eipsi-forms'); ?>
            </a>
        </p>
    </div>
    <?php
}

function eipsi_handle_save_pool() {
    error_log('[EIPSI-POOL] Handler eipsi_handle_save_pool executing');
    
    // Verify nonce
    if (!wp_verify_nonce($_POST['pool_nonce'] ?? '', 'eipsi_save_pool_nonce')) {
        error_log('[EIPSI-POOL] Nonce verification failed');
        wp_die(__('Error de seguridad. Por favor, recargá la página.', 'eipsi-forms'));
    }
    
    // Check permissions
    if (!function_exists('eipsi_user_can_manage_longitudinal') || !eipsi_user_can_manage_longitudinal()) {
        error_log('[EIPSI-POOL] Permission check failed');
        wp_die(__('No tenés permisos para realizar esta acción.', 'eipsi-forms'));
    }
    
    global $wpdb;
    
    $pools_table = $wpdb->prefix . 'eipsi_longitudinal_pools';
    
    // Get form data
    $pool_id = intval($_POST['pool_id'] ?? 0);
    $pool_name = sanitize_text_field($_POST['pool_name'] ?? '');
    $pool_description = sanitize_textarea_field($_POST['pool_description'] ?? '');
    $pool_incentive = sanitize_textarea_field($_POST['pool_incentive'] ?? '');
    $method = sanitize_key($_POST['method'] ?? 'seeded');
    $notify_on_completion = !empty($_POST['notify_on_completion']);
    $studies_data = json_decode(stripslashes($_POST['pool_studies_data'] ?? '[]'), true);
    
    error_log('[EIPSI-POOL] Received data: pool_id=' . $pool_id . ', name=' . $pool_name . ', studies=' . count($studies_data));
    
    if (empty($pool_name)) {
        wp_die(__('El nombre del pool es obligatorio.', 'eipsi-forms'));
    }
    
    if (empty($pool_description)) {
        wp_die(__('La descripción del pool es obligatoria.', 'eipsi-forms'));
    }
    
    // Extract study IDs and probabilities
    $study_ids = array();
    $probabilities = array();
    $total_prob = 0;
    
    foreach ($studies_data as $item) {
        if (!empty($item['study_id']) && isset($item['probability'])) {
            $study_ids[] = intval($item['study_id']);
            $prob = floatval($item['probability']);
            $probabilities[] = $prob;
            $total_prob += $prob;
        }
    }
    
    error_log('[EIPSI-POOL] Processed ' . count($study_ids) . ' studies, total_prob=' . $total_prob);
    
    // Get redirect mode (default: transition)
    $redirect_mode = sanitize_key($_POST['redirect_mode'] ?? 'transition');
    if (!in_array($redirect_mode, ['transition', 'minimal'])) {
        $redirect_mode = 'transition';
    }
    
    // Build config JSON (Fase 4: incluye notify_on_completion, incentive, redirect_mode)
    $config = array(
        'studies' => $studies_data,
        'method' => $method,
        'notify_on_completion' => $notify_on_completion,
        'incentive_message' => $pool_incentive,
        'redirect_mode' => $redirect_mode,
        'updated_at' => current_time('mysql'),
    );

    // Validate total is 100% (with 0.1% tolerance for floating point precision)
    if (abs($total_prob - 100) > 0.1) {
        wp_die(sprintf(__('La suma de probabilidades debe ser 100%% (±0.1%% tolerancia). Actual: %.2f%%', 'eipsi-forms'), $total_prob));
    }

    $now = current_time('mysql');

    if ($pool_id > 0) {
        // Update existing pool
        $wpdb->update(
            $pools_table,
            array(
                'pool_name' => $pool_name,
                'pool_description' => $pool_description,
                'studies' => json_encode($study_ids),
                'probabilities' => json_encode($probabilities),
                'method' => $method,
                'config' => json_encode($config),
                'updated_at' => $now
            ),
            array('id' => $pool_id),
            array('%s', '%s', '%s', '%s', '%s', '%s'),
            array('%d')
        );
        error_log('[EIPSI-POOL] Updated existing pool ID: ' . $pool_id);
    } else {
        // Create new pool
        $wpdb->insert(
            $pools_table,
            array(
                'pool_name' => $pool_name,
                'pool_description' => $pool_description,
                'studies' => json_encode($study_ids),
                'probabilities' => json_encode($probabilities),
                'method' => $method,
                'config' => json_encode($config),
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );
        $pool_id = $wpdb->insert_id;
        error_log('[EIPSI-POOL] Created new pool ID: ' . $pool_id);
    }
    
    // Auto-create WordPress page for this pool
    $page_id = eipsi_create_pool_page($pool_id, $pool_name);
    $page_url = $page_id ? get_permalink($page_id) : null;
    
    error_log('[EIPSI-POOL] Page created: ' . ($page_id ? $page_id : 'failed'));
    
    // Redirect back with success message
    $message_type = $pool_id > 0 ? 'updated' : 'created';
    $redirect_url = admin_url('admin.php?page=eipsi-longitudinal-study&tab=pool-hub&message=pool_' . $message_type);
    if ($page_url) {
        $redirect_url .= '&page_url=' . urlencode($page_url);
    }
    
    error_log('[EIPSI-POOL] Redirecting to: ' . $redirect_url);
    
    wp_redirect($redirect_url);
    exit;
}

function eipsi_repair_missing_pool_pages() {
    global $wpdb;
    $pools_table = $wpdb->prefix . 'eipsi_longitudinal_pools';
    
    // Find pools without page_id
    $orphan_pools = $wpdb->get_results(
        "SELECT id, pool_name FROM {$pools_table} WHERE page_id IS NULL OR page_id = 0"
    );
    
    if (empty($orphan_pools)) {
        return;
    }
    
    error_log('[EIPSI-POOL-REPAIR] Found ' . count($orphan_pools) . ' pools without pages');
    
    foreach ($orphan_pools as $pool) {
        error_log('[EIPSI-POOL-REPAIR] Creating page for pool ID ' . $pool->id . ': ' . $pool->pool_name);
        $page_id = eipsi_create_pool_page($pool->id, $pool->pool_name);
        
        if ($page_id) {
            error_log('[EIPSI-POOL-REPAIR] Created page ID ' . $page_id . ' for pool ID ' . $pool->id);
        } else {
            error_log('[EIPSI-POOL-REPAIR] FAILED to create page for pool ID ' . $pool->id);
        }
    }
}

function eipsi_create_pool_page($pool_id, $pool_name) {
    $pool_slug = 'pool-' . sanitize_title($pool_name);
    
    // Check if page already exists
    $existing_page = get_page_by_path($pool_slug);
    
    if (!$existing_page) {
        $existing_pages = get_posts(array(
            'post_type' => 'page',
            'meta_key' => 'eipsi_pool_id',
            'meta_value' => $pool_id,
            'posts_per_page' => 1
        ));
        
        if (!empty($existing_pages)) {
            $existing_page = $existing_pages[0];
        }
    }
    
    if ($existing_page) {
        update_post_meta($existing_page->ID, 'eipsi_pool_id', $pool_id);
        return $existing_page->ID;
    }
    
    // Create new page
    $page_title = sprintf(__('Pool: %s', 'eipsi-forms'), $pool_name);
    $page_content = '<!-- wp:shortcode -->[eipsi_pool pool_id="' . esc_attr($pool_id) . '"]<!-- /wp:shortcode -->';
    
    $page_id = wp_insert_post(array(
        'post_title' => $page_title,
        'post_name' => $pool_slug,
        'post_content' => $page_content,
        'post_status' => 'publish',
        'post_type' => 'page',
        'meta_input' => array(
            'eipsi_pool_id' => $pool_id
        )
    ));
    
    if (is_wp_error($page_id)) {
        error_log('[EIPSI] Failed to create pool page: ' . $page_id->get_error_message());
        return false;
    }
    
    // Update pool with page_id
    global $wpdb;
    $pools_table = $wpdb->prefix . 'eipsi_longitudinal_pools';
    $updated = $wpdb->update(
        $pools_table,
        array('page_id' => $page_id),
        array('id' => $pool_id),
        array('%d'),
        array('%d')
    );
    
    if ($updated !== false) {
        error_log('[EIPSI] Pool ID ' . $pool_id . ' updated with page_id: ' . $page_id);
    } else {
        error_log('[EIPSI] ERROR: Could not update pool ID ' . $pool_id . ' with page_id');
    }
    
    return $page_id;
}

function eipsi_migrate_pools_to_v2() {
    // Solo correr una vez
    if ( get_option( 'eipsi_pools_migrated_v2' ) ) {
        return;
    }

    global $wpdb;

    $pools_table = $wpdb->prefix . 'eipsi_longitudinal_pools';

    // Verificar que la tabla existe
    $table_exists = $wpdb->get_var(
        $wpdb->prepare(
            "SHOW TABLES LIKE %s",
            $pools_table
        )
    );

    if ( ! $table_exists ) {
        error_log( '[EIPSI Pool] Migración v2: tabla de pools no existe, nada que migrar.' );
        update_option( 'eipsi_pools_migrated_v2', true );
        return;
    }

    $pools = $wpdb->get_results( "SELECT * FROM {$pools_table}" );

    if ( empty( $pools ) ) {
        error_log( '[EIPSI Pool] Migración v2: no hay pools para migrar.' );
        update_option( 'eipsi_pools_migrated_v2', true );
        return;
    }

    $migrated_count = 0;

    foreach ( $pools as $pool ) {
        // Obtener config vieja (puede estar en columna config o ser null)
        $old_config = json_decode( $pool->config ?? '{}', true );

        // Si ya tiene el formato nuevo (versión 2), saltear
        if ( isset( $old_config['version'] ) && $old_config['version'] >= 2 ) {
            continue;
        }

        // Construir array de estudios con probabilidades
        $studies_array = array();
        $old_studies   = json_decode( $pool->studies ?? '[]', true );
        $old_probs     = json_decode( $pool->probabilities ?? '[]', true );

        if ( is_array( $old_studies ) ) {
            foreach ( $old_studies as $index => $study_id ) {
                $studies_array[] = array(
                    'study_id'    => intval( $study_id ),
                    'probability' => isset( $old_probs[ $index ] ) ? floatval( $old_probs[ $index ] ) : 0,
                );
            }
        }

        // Construir nuevo formato de config
        $new_config = array(
            'studies'              => $studies_array,
            'method'               => $old_config['method'] ?? 'seeded',
            'allow_reassignment'   => $old_config['allow_reassignment'] ?? false,
            'notify_on_completion' => $old_config['notify_on_completion'] ?? false,
            'paused_message'       => $old_config['paused_message'] ?? __( 'Este estudio no está disponible en este momento.', 'eipsi-forms' ),
            'migrated_at'          => current_time( 'mysql' ),
            'version'              => 2,
        );

        // Actualizar el pool con el nuevo config
        $updated = $wpdb->update(
            $pools_table,
            array( 'config' => wp_json_encode( $new_config ) ),
            array( 'id'     => $pool->id ),
            array( '%s' ),
            array( '%d' )
        );

        if ( $updated !== false ) {
            $migrated_count++;
            error_log( "[EIPSI Pool] Migrado pool ID {$pool->id} a formato v2" );
        } else {
            error_log( "[EIPSI Pool] ERROR: Falló migración del pool ID {$pool->id}: " . $wpdb->last_error );
        }
    }

    update_option( 'eipsi_pools_migrated_v2', true );
    error_log( "[EIPSI Pool] Migración v2 completada. {$migrated_count} pools migrados." );
}

function eipsi_register_pool_rewrite_rules() {
    // Pattern: /pool/POOL_CODIGO/
    add_rewrite_rule(
        '^pool/([^/]+)/?$',
        'index.php?eipsi_pool_code=$matches[1]',
        'top'
    );
    
    // También: /estudio/ESTUDIO_CODIGO/ (para estudios individuales)
    add_rewrite_rule(
        '^estudio/([^/]+)/?$',
        'index.php?eipsi_study_code=$matches[1]',
        'top'
    );
    
    // Registrar query vars
    add_filter('query_vars', 'eipsi_add_pool_query_vars');
}

function eipsi_add_pool_query_vars($vars) {
    $vars[] = 'eipsi_pool_code';
    $vars[] = 'eipsi_study_code';
    return $vars;
}

function eipsi_handle_pool_access() {
    $pool_code = get_query_var('eipsi_pool_code');
    $study_code = get_query_var('eipsi_study_code');
    
    // Cargar helpers si no están cargados
    if (!function_exists('eipsi_get_valid_pool')) {
        $helpers_file = EIPSI_FORMS_PLUGIN_DIR . 'includes/helpers/pool-helpers.php';
        if (file_exists($helpers_file)) {
            require_once $helpers_file;
        }
    }
    
    // CASO A: Acceso a Pool
    if (!empty($pool_code)) {
        // Verificar que el pool existe y está activo
        $pool_data = eipsi_get_valid_pool($pool_code);
        
        if (!$pool_data) {
            // Pool no existe o no está activo - mostrar error 404
            wp_die(
                __('El pool solicitado no existe o no está disponible.', 'eipsi-forms'),
                __('Pool no encontrado', 'eipsi-forms'),
                array('response' => 404)
            );
        }
        
        // Verificar si el participante ya tiene asignación
        $participant_id = eipsi_get_participant_id();
        
        if ($participant_id) {
            $assignment = eipsi_get_pool_assignment($pool_code, $participant_id);
            
            if ($assignment) {
                // Ya asignado - redirigir al estudio
                $study_url = eipsi_get_study_url($assignment['study_id']);
                wp_redirect($study_url);
                exit;
            }
        }
        
        // Mostrar interfaz de acceso al pool
        eipsi_render_pool_access_page($pool_code, $pool_data);
        exit;
    }
    
    // CASO B: Acceso a Estudio Individual (placeholder para Fase 2)
    if (!empty($study_code)) {
        // Por ahora, dejar que el sistema existente maneje esto
    }
}

function eipsi_handle_pool_email_confirmation() {
    error_log("[EIPSI POOL CONFIRM] === INICIO confirmación de email ===");
    
    // Verificar si es una petición de confirmación
    if ( ! isset( $_GET['eipsi_action'] ) || $_GET['eipsi_action'] !== 'pool_confirm' ) {
        return;
    }

    $participant_id = isset( $_GET['participant_id'] ) ? absint( $_GET['participant_id'] ) : 0;
    $token          = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
    
    error_log("[EIPSI POOL CONFIRM] Datos recibidos: participant_id={$participant_id}, token=" . substr($token, 0, 10) . "...");

    if ( ! $participant_id || ! $token ) {
        error_log("[EIPSI POOL CONFIRM] ERROR: participant_id o token faltantes");
        wp_die(
            __( 'Enlace de confirmación inválido. Por favor solicitá un nuevo enlace.', 'eipsi-forms' ),
            __( 'Confirmación inválida', 'eipsi-forms' ),
            array( 'response' => 400 )
        );
    }

    // Verificar token
    $stored_data = get_transient( 'eipsi_pool_confirm_' . $participant_id );

    if ( ! $stored_data || ! is_array( $stored_data ) ) {
        error_log("[EIPSI POOL CONFIRM] ERROR: Transient no encontrado o inválido para participant_id={$participant_id}");
        wp_die(
            __( 'El enlace de confirmación expiró o ya fue utilizado. Por favor registrate nuevamente.', 'eipsi-forms' ),
            __( 'Enlace expirado', 'eipsi-forms' ),
            array( 'response' => 410 )
        );
    }
    
    error_log("[EIPSI POOL CONFIRM] Transient encontrado: pool_id={$stored_data['pool_id']}");

    if ( ! hash_equals( $stored_data['token'], $token ) ) {
        error_log("[EIPSI POOL CONFIRM] ERROR: Token no coincide");
        wp_die(
            __( 'Enlace de confirmación inválido.', 'eipsi-forms' ),
            __( 'Token inválido', 'eipsi-forms' ),
            array( 'response' => 403 )
        );
    }
    
    error_log("[EIPSI POOL CONFIRM] Token válido - procediendo a confirmar email");

    // Token válido → confirmar email
    global $wpdb;
    $participants_table = $wpdb->prefix . 'survey_participants';
    $pools_table = $wpdb->prefix . 'eipsi_longitudinal_pools';

    // Actualizar estado de confirmación
    $result = $wpdb->update(
        $participants_table,
        array( 'is_active' => 1 ),
        array( 'id' => $participant_id ),
        array( '%d' ),
        array( '%d' )
    );
    
    $pool_id = $stored_data["pool_id"] ?? 0;
    if ($result === false) {
        error_log("[EIPSI POOL CONFIRM] ERROR: Falló actualización de is_active en DB");
    } else {
        error_log("[EIPSI POOL CONFIRM] Email confirmado OK para participant_id={$participant_id}");
        // Registrar la confirmación exitosa
        $wpdb->insert(
            $wpdb->prefix . "eipsi_pool_email_log",
            array(
                "pool_id"        => $pool_id,
                "participant_id" => $participant_id,
                "email"          => $stored_data["email"] ?? "",
                "action"         => "confirmed",
                "created_at"     => current_time( "mysql" ),
            ),
            array( "%d", "%d", "%s", "%s", "%s" )
        );

    }
    // Eliminar transient
    delete_transient( 'eipsi_pool_confirm_' . $participant_id );
    error_log("[EIPSI POOL CONFIRM] Transient eliminado");

    // Obtener pool_id para redirección
    $pool_id = $stored_data['pool_id'] ?? 0;

    // Obtener página del pool
    if ( $pool_id ) {
        $pool = $wpdb->get_row( $wpdb->prepare(
            "SELECT page_id FROM {$pools_table} WHERE id = %d",
            $pool_id
        ) );

        if ( $pool && $pool->page_id ) {
            $pool_url = get_permalink( $pool->page_id );
            
            // Generar magic link al dashboard
            $dashboard_url = eipsi_generate_pool_dashboard_link( $participant_id, $pool_id );
            error_log("[EIPSI POOL CONFIRM] Redirigiendo al dashboard: {$dashboard_url}");
            
            // Redirigir al dashboard del pool
            wp_redirect( $dashboard_url );
            exit;
        }
    }

    // Fallback: redirigir a home con mensaje
    wp_redirect( add_query_arg( 'pool_confirmed', '1', home_url( '/' ) ) );
    exit;
}

function eipsi_handle_pool_join() {
    // Verificar nonce
    if (!wp_verify_nonce($_POST['pool_nonce'] ?? '', 'eipsi_pool_access')) {
        wp_die(__('Error de seguridad. Por favor, recargá la página.', 'eipsi-forms'));
    }
    
    // Obtener datos
    $pool_code = sanitize_text_field($_POST['pool_code'] ?? '');
    $email = sanitize_email($_POST['participant_email'] ?? '');
    $consent = !empty($_POST['pool_consent']);
    
    if (empty($pool_code) || empty($email) || !$consent) {
        wp_die(__('Por favor completá todos los campos y aceptá el consentimiento.', 'eipsi-forms'));
    }
    
    // Cargar helpers
    if (!function_exists('eipsi_get_valid_pool')) {
        require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/helpers/pool-helpers.php';
    }
    
    // Verificar pool
    $pool_data = eipsi_get_valid_pool($pool_code);
    if (!$pool_data) {
        wp_die(__('El pool no está disponible.', 'eipsi-forms'));
    }
    
    // Obtener modo de redirección (default: transition)
    $redirect_mode = isset($pool_data['redirect_mode']) ? $pool_data['redirect_mode'] : 'transition';
    
    // Crear participante (usar email como ID para MVP)
    $participant_id = 'email_' . md5($email);
    eipsi_set_participant_cookie($participant_id);
    
    // Verificar si ya tiene asignación (doble check)
    $assignment = eipsi_get_pool_assignment($pool_code, $participant_id);
    
    if ($assignment) {
        // Ya asignado - redirigir directo al estudio (sin importar modo)
        wp_redirect(eipsi_get_study_url($assignment['study_id']));
        exit;
    }
    
    // Guardar email para referencia (en sesión o cookie adicional)
    setcookie('eipsi_participant_email', $email, time() + 365 * 24 * 60 * 60, '/');
    
    // EJECUTAR ALEATORIZACIÓN (Fase 2)
    $assignment = eipsi_pool_randomize($pool_code, $participant_id, $pool_data);
    
    if (!$assignment) {
        // Error en aleatorización (pool saturado o error BD)
        wp_die(__('No se pudo realizar la asignación. El pool puede estar saturado o ocurrió un error. Por favor, intentá más tarde o contactá al investigador.', 'eipsi-forms'));
    }
    
    // SEGÚN MODO DE REDIRECCIÓN:
    if ($redirect_mode === 'minimal') {
        // MODO MÍNIMO (1 click): Redirigir INMEDIATAMENTE al estudio asignado
        error_log('[EIPSI-POOL] Modo minimal: redirigiendo inmediatamente al estudio ' . $assignment['study_id']);
        wp_redirect(eipsi_get_study_url($assignment['study_id']));
        exit;
        
    } else {
        // MODO TRANSICIÓN (default): Mostrar página de "Asignación exitosa"
        error_log('[EIPSI-POOL] Modo transición: mostrando página de confirmación para estudio ' . $assignment['study_id']);
        eipsi_render_pool_assigned_page($pool_code, $participant_id, $assignment);
    }
}

function eipsi_run_partial_cleanup() {
    global $wpdb;
    $table = $wpdb->prefix . 'eipsi_partial_responses';
    
    // Eliminar incompletos de más de 30 días
    $deleted_incomplete = $wpdb->query($wpdb->prepare(
        "DELETE FROM {$table} WHERE updated_at < %s AND completed = 0",
        date('Y-m-d H:i:s', strtotime('-30 days'))
    ));
    
    // Eliminar completados de más de 90 días
    $deleted_complete = $wpdb->query($wpdb->prepare(
        "DELETE FROM {$table} WHERE updated_at < %s AND completed = 1",
        date('Y-m-d H:i:s', strtotime('-90 days'))
    ));
    
    // Eliminar sesiones huérfanas — mismo participante/form con más de 3 sesiones,
    // conservar solo las 3 más recientes
    $wpdb->query("
        DELETE p1 FROM {$table} p1
        INNER JOIN (
            SELECT form_id, participant_id, session_id,
                   ROW_NUMBER() OVER (
                       PARTITION BY form_id, participant_id
                       ORDER BY updated_at DESC
                   ) as rn
            FROM {$table}
            WHERE completed = 0
        ) p2 ON p1.form_id = p2.form_id
             AND p1.participant_id = p2.participant_id
             AND p1.session_id = p2.session_id
        WHERE p2.rn > 3
    ");
    
    error_log("[EIPSI Save&Continue] Cleanup: {$deleted_incomplete} incompletos y {$deleted_complete} completados eliminados.");
}

function eipsi_get_client_ip() {
    $ip_keys = array(
        'HTTP_CLIENT_IP',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_FORWARDED',
        'HTTP_X_CLUSTER_CLIENT_IP',
        'HTTP_FORWARDED_FOR',
        'HTTP_FORWARDED',
        'REMOTE_ADDR'
    );
    
    foreach ($ip_keys as $key) {
        if (array_key_exists($key, $_SERVER) === true) {
            foreach (explode(',', $_SERVER[$key]) as $ip) {
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                    return $ip;
                }
            }
        }
    }
    
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function eipsi_get_study_id_for_form($form_id) {
    global $wpdb;
    
    // Resolve form ID if it's a slug
    $template_id = is_numeric($form_id) ? intval($form_id) : 0;
    if (!$template_id) {
        $template_id = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type IN ('eipsi_form_template', 'eipsi_form', 'page') LIMIT 1",
            $form_id
        ));
    }
    
    if (!$template_id) {
        return null;
    }

    // Try to find study that contains this survey in survey_waves
    $study_id = $wpdb->get_var($wpdb->prepare(
        "SELECT study_id FROM {$wpdb->prefix}survey_waves WHERE form_id = %d LIMIT 1",
        $template_id
    ));
    
    return $study_id ? intval($study_id) : null;
}

function eipsi_check_consent_blocked($participant_id, $context, $type = 'study') {
    global $wpdb;
    
    $result = array(
        'blocked' => false,
        'reason' => null,
        'decision' => null,
        'decided_at' => null,
    );
    
    $table = $wpdb->prefix . 'survey_participants';
    
    // Resolve numeric context ID if it's a slug
    $context_id = is_numeric($context) ? intval($context) : 0;
    if (!$context_id) {
        $context_id = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type IN ('eipsi_form_template', 'eipsi_form', 'page') LIMIT 1",
            $context
        ));
    }
    
    if (!$context_id) {
        $context_id = $context;
    }

    // Both longitudinal studies and standalone forms now use survey_participants for consent
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT consent_decision, consent_decided_at, consent_blocked_survey_id, status 
         FROM {$table} 
         WHERE survey_id = %d AND id = %d 
         LIMIT 1",
        $context_id,
        $participant_id
    ));
    
    if ($row && in_array($row->consent_decision, array('declined', 'withdrawn'), true)) {
        $result['blocked'] = true;
        $result['reason'] = ($row->consent_decision === 'declined') ? 'consent_declined' : 'study_withdrawn';
        $result['decision'] = $row->consent_decision;
        $result['decided_at'] = $row->consent_decided_at;
    } elseif ($row && isset($row->status) && $row->status === 'withdrawn') {
        $result['blocked'] = true;
        $result['reason'] = 'study_withdrawn';
        $result['decision'] = 'withdrawn';
        $result['decided_at'] = $row->consent_decided_at;
    }
    
    return $result;
}

function eipsi_log_audit($event_type, $data) {
    // Log to error log for now (can be extended to database table)
    error_log(sprintf(
        '[EIPSI Audit] %s | Data: %s',
        $event_type,
        json_encode($data)
    ));
}
