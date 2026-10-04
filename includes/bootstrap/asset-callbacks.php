<?php
/** M1 bootstrap: preserve existing contracts and registration order. */
if (!defined('ABSPATH')) { exit; }

function eipsi_enqueue_randomization_assets($hook) {
    $page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';
    $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : '';

    if ($page === 'eipsi-results-experience') {
        $active_tab = $active_tab ?: 'submissions';

        if ($active_tab === 'randomization') {
            wp_enqueue_style(
                'eipsi-randomization-css',
                EIPSI_FORMS_PLUGIN_URL . 'assets/css/randomization.css',
                array(),
                EIPSI_FORMS_VERSION
            );

            wp_enqueue_script(
                'eipsi-randomization-js',
                EIPSI_FORMS_PLUGIN_URL . 'assets/js/randomization.js',
                array('jquery'),
                EIPSI_FORMS_VERSION,
                true
            );

            wp_localize_script('eipsi-randomization-js', 'eipsiRandomization', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('eipsi_randomization_nonce'),
                'adminUrl' => admin_url(),
                'strings' => array(
                    'loading' => __('Cargando datos...', 'eipsi-forms'),
                    'error' => __('Error al cargar datos', 'eipsi-forms'),
                    'success' => __('Actualizado correctamente', 'eipsi-forms'),
                    'confirmDelete' => __('¿Estás seguro de que quieres eliminar esta aleatorización?', 'eipsi-forms'),
                    'copied' => __('ID copiado al portapapeles', 'eipsi-forms')
                )
            ));
        }

        return;
    }

    if ($page === 'eipsi-longitudinal-study') {
        $active_tab = $active_tab ?: 'dashboard-study';

        if ($active_tab === 'waves-manager') {
            wp_enqueue_style(
                'eipsi-waves-manager-css',
                EIPSI_FORMS_PLUGIN_URL . 'assets/css/waves-manager.css',
                array(),
                EIPSI_FORMS_VERSION
            );

            wp_enqueue_script(
                'eipsi-waves-manager-js',
                EIPSI_FORMS_PLUGIN_URL . 'assets/js/waves-manager.js',
                array('jquery'),
                EIPSI_FORMS_VERSION,
                true
            );

            wp_localize_script('eipsi-waves-manager-js', 'eipsiWavesManager', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('eipsi_waves_nonce'),
                'strings' => array(
                    'loading' => __('Cargando...', 'eipsi-forms'),
                    'error' => __('Ha ocurrido un error', 'eipsi-forms'),
                    'success' => __('Operación exitosa', 'eipsi-forms'),
                    'confirmDelete' => __('¿Estás seguro de que quieres eliminar esta onda? Esta acción es irreversible.', 'eipsi-forms'),
                    'confirmAssign' => __('¿Estás seguro de asignar los participantes seleccionados?', 'eipsi-forms'),
                    'noParticipants' => __('Por favor, selecciona al menos un participante.', 'eipsi-forms')
                )
            ));
        }

        if ($active_tab === 'dashboard-study') {
            wp_enqueue_style(
                'eipsi-study-dashboard-css',
                EIPSI_FORMS_PLUGIN_URL . 'assets/css/study-dashboard.css',
                array('eipsi-tokens'),
                EIPSI_FORMS_VERSION
            );

            wp_enqueue_script(
                'eipsi-study-dashboard',
                EIPSI_FORMS_PLUGIN_URL . 'assets/js/study-dashboard.js',
                array('jquery'),
                EIPSI_FORMS_VERSION,
                true
            );

            wp_localize_script('eipsi-study-dashboard', 'eipsiStudyDash', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('eipsi_study_dashboard_nonce'),
                'strings' => array(
                    'confirmReminder' => __('¿Estás seguro de enviar recordatorios manuales?', 'eipsi-forms'),
                    'confirmClose' => __('¿Estás seguro de que quieres cerrar este estudio? Esta acción es irreversible.', 'eipsi-forms'),
                    'success' => __('Operación exitosa', 'eipsi-forms'),
                    'error' => __('Ha ocurrido un error', 'eipsi-forms')
                )
            ));
        }
    }
}

function eipsi_enqueue_admin_light_theme($hook) {
    $page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';

    // List of EIPSI admin pages
    $eipsi_pages = array(
        'eipsi-results-experience',
        'eipsi-configuration',
        'eipsi-longitudinal-study'
    );

    if (in_array($page, $eipsi_pages, true)) {
        // Enqueue design tokens FIRST (single source of truth)
        wp_enqueue_style(
            'eipsi-tokens',
            EIPSI_FORMS_PLUGIN_URL . 'assets/css/eipsi-tokens.css',
            array(),
            EIPSI_FORMS_VERSION
        );

        // Enqueue the unified admin light theme
        wp_enqueue_style(
            'eipsi-admin-light-theme',
            EIPSI_FORMS_PLUGIN_URL . 'assets/css/admin-light-theme.css',
            array('eipsi-tokens'),
            EIPSI_FORMS_VERSION
        );

        // Also enqueue the existing admin styles as dependencies
        wp_enqueue_style(
            'eipsi-admin-style',
            EIPSI_FORMS_PLUGIN_URL . 'assets/css/admin-style.css',
            array('eipsi-tokens', 'eipsi-admin-light-theme'),
            EIPSI_FORMS_VERSION
        );

        // Configuration panel styles for the configuration page
        if ($page === 'eipsi-configuration') {
            wp_enqueue_style(
                'eipsi-configuration-panel',
                EIPSI_FORMS_PLUGIN_URL . 'assets/css/configuration-panel.css',
                array('eipsi-tokens', 'eipsi-admin-light-theme'),
                EIPSI_FORMS_VERSION
            );

            // Enqueue configuration panel JavaScript
            wp_enqueue_script(
                'eipsi-configuration-panel',
                EIPSI_FORMS_PLUGIN_URL . 'assets/js/configuration-panel.js',
                array('jquery'),
                EIPSI_FORMS_VERSION,
                true
            );

            // Localize configuration panel script
            wp_localize_script('eipsi-configuration-panel', 'eipsiConfigL10n', array(
                'fillAllFields' => __('Por favor completa todos los campos requeridos.', 'eipsi-forms'),
                'connectionError' => __('Error de conexión al probar la conexión.', 'eipsi-forms'),
                'testFirst' => __('Por favor prueba la conexión primero.', 'eipsi-forms'),
                'saveError' => __('Error al guardar la configuración.', 'eipsi-forms'),
                'disableExternal' => __('Deshabilitar Base de Datos Externa', 'eipsi-forms'),
                'loading' => __('Cargando...', 'eipsi-forms')
            ));

            // Get active tab
            $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'database';

            // Enqueue email test JavaScript for SMTP tab
            if ($active_tab === 'smtp') {
                wp_enqueue_script(
                    'eipsi-email-test',
                    EIPSI_FORMS_PLUGIN_URL . 'assets/js/email-test.js',
                    array('jquery'),
                    EIPSI_FORMS_VERSION,
                    true
                );
            }
        }
    }
}

function eipsi_enqueue_setup_wizard_assets($hook) {
    $page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';
    $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : '';

    if ($page === 'eipsi-longitudinal-study' && $active_tab === 'create-study') {
        wp_enqueue_style(
            'eipsi-longitudinal-studies-ui-css',
            EIPSI_FORMS_PLUGIN_URL . 'assets/css/longitudinal-studies-ui.css',
            array(),
            EIPSI_FORMS_VERSION
        );

        wp_enqueue_style(
            'eipsi-setup-wizard-css',
            EIPSI_FORMS_PLUGIN_URL . 'assets/css/setup-wizard.css',
            array(),
            EIPSI_FORMS_VERSION
        );

        wp_enqueue_script(
            'eipsi-setup-wizard-js',
            EIPSI_FORMS_PLUGIN_URL . 'assets/js/setup-wizard.js',
            array('jquery'),
            EIPSI_FORMS_VERSION,
            true
        );

        $available_forms = eipsi_get_available_forms_for_wizard();

        wp_localize_script('eipsi-setup-wizard-js', 'eipsiWizard', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('eipsi_wizard_action'),
            'adminUrl' => admin_url(),
            'availableForms' => $available_forms,
            'strings' => array(
                'loading' => __('Guardando...', 'eipsi-forms'),
                'error' => __('Error al guardar', 'eipsi-forms'),
                'success' => __('Guardado correctamente', 'eipsi-forms'),
                'confirmActivation' => __('¿Estás seguro de activar este estudio?', 'eipsi-forms'),
                'validationError' => __('Por favor, revisa los campos requeridos', 'eipsi-forms')
            )
        ));
    }
}

function eipsi_enqueue_participant_auth_assets() {
    // Solo enqueue si hay autenticación de participantes en esta página
    global $post;
    if (!is_a($post, 'WP_Post')) return;

    // Check if page has participant-related shortcodes
    $has_participant_shortcode = has_shortcode($post->post_content, 'eipsi_survey_login') ||
                                  has_shortcode($post->post_content, 'eipsi_participant_dashboard') ||
                                  has_shortcode($post->post_content, 'eipsi_form');

    if (!$has_participant_shortcode) return;

    wp_enqueue_script(
        'eipsi-participant-auth',
        EIPSI_FORMS_PLUGIN_URL . 'assets/js/participant-auth.js',
        array('jquery'),
        EIPSI_FORMS_VERSION,
        true
    );

    // v2.5.3 - Definir eipsiAuth para evitar ReferenceError
    wp_localize_script('eipsi-participant-auth', 'eipsiAuth', array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('eipsi_participant_auth'),
        'loginUrl' => get_permalink(),
        'participantId' => null, // Se actualizará desde shortcodes si hay sesión
        'strings' => array(
            'checking' => __('Verificando sesión...', 'eipsi-forms'),
            'sessionExpired' => __('Tu sesión expiró. Por favor, iniciá sesión nuevamente.', 'eipsi-forms'),
            'unauthorized' => __('No tenés acceso a esta encuesta.', 'eipsi-forms'),
            'sessionContinued' => __('Sesión activa · Continuando...', 'eipsi-forms'),
            'technicalError' => __('Error técnico. Intentá de nuevo.', 'eipsi-forms'),
            'magicLinkSent' => __('¡Listo! Revisá tu email.', 'eipsi-forms'),
            'magicLinkError' => __('Error al enviar el link. Intentá de nuevo.', 'eipsi-forms'),
            'invalidEmail' => __('Por favor, ingresá un email válido.', 'eipsi-forms'),
            'enterEmail' => __('Ingresá tu email para comenzar', 'eipsi-forms'),
            'magicLinkButton' => __('Enviarme link mágico ✨', 'eipsi-forms'),
            'checkingEmail' => __('Enviando link...', 'eipsi-forms'),
            'welcomeBack' => __('¡Te extrañamos! Continuemos...', 'eipsi-forms'),
            'loginRequired' => __('Iniciá sesión para participar', 'eipsi-forms')
        )
    ));
}

function eipsi_enqueue_survey_login_assets() {
    // Detectar si hay shortcode [eipsi_survey_login] en la página actual
    global $post;
    if (!is_a($post, 'WP_Post')) return;

    if (has_shortcode($post->post_content, 'eipsi_survey_login') ||
        has_shortcode($post->post_content, 'eipsi_participant_dashboard')) {

        // Enhanced login styles
        wp_enqueue_style(
            'eipsi-survey-login-css',
            EIPSI_FORMS_PLUGIN_URL . 'assets/css/survey-login-enhanced.css',
            array('eipsi-theme-toggle-css'),
            EIPSI_FORMS_VERSION
        );

        // Enhanced login scripts
        wp_enqueue_script(
            'eipsi-survey-login-js',
            EIPSI_FORMS_PLUGIN_URL . 'assets/js/survey-login-enhanced.js',
            array('jquery', 'eipsi-participant-auth'),
            EIPSI_FORMS_VERSION,
            true
        );

        // Participant dashboard styles
        wp_enqueue_style(
            'eipsi-participant-dashboard-css',
            EIPSI_FORMS_PLUGIN_URL . 'assets/css/participant-dashboard.css',
            array('eipsi-theme-toggle-css'),
            EIPSI_FORMS_VERSION
        );

        // Participant dashboard scripts
        wp_enqueue_script(
            'eipsi-participant-dashboard-js',
            EIPSI_FORMS_PLUGIN_URL . 'assets/js/participant-dashboard.js',
            array('jquery', 'eipsi-participant-auth'),
            EIPSI_FORMS_VERSION,
            true
        );

        // Localize dashboard script
        wp_localize_script('eipsi-participant-dashboard-js', 'eipsiParticipantDashboardL10n', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('eipsi_participant_dashboard'),
            'strings' => array(
                'confirm_logout' => __('¿Estás seguro de que quieres cerrar sesión?', 'eipsi-forms'),
                'logging_out' => __('Cerrando sesión...', 'eipsi-forms'),
                'logout_success' => __('Sesión cerrada correctamente', 'eipsi-forms'),
                'logout_error' => __('Error al cerrar sesión', 'eipsi-forms')
            )
        ));
    }
}

function eipsi_enqueue_participant_ux_assets() {
    // Detectar si hay formularios EIPSI en la página
    global $post;
    if (!is_a($post, 'WP_Post')) return;

    $has_eipsi_form = has_shortcode($post->post_content, 'eipsi_form') ||
                      has_shortcode($post->post_content, 'eipsi_survey_form') ||
                      has_shortcode($post->post_content, 'eipsi_longitudinal_study') ||
                      has_shortcode($post->post_content, 'eipsi_survey_login') ||
                      has_shortcode($post->post_content, 'eipsi_participant_dashboard');

    if (!$has_eipsi_form) {
        return;
    }

    // Participant UX Enhanced Styles
    wp_enqueue_style(
        'eipsi-participant-ux-css',
        EIPSI_FORMS_PLUGIN_URL . 'assets/css/participant-ux-enhanced.css',
        array('eipsi-theme-toggle-css'),
        EIPSI_FORMS_VERSION
    );

    // Participant UX Enhanced Scripts
    wp_enqueue_script(
        'eipsi-participant-ux-js',
        EIPSI_FORMS_PLUGIN_URL . 'assets/js/participant-ux-enhanced.js',
        array('jquery'),
        EIPSI_FORMS_VERSION,
        true
    );

    // Auto-Refresh Script (for longitudinal studies and dashboards)
    if (has_shortcode($post->post_content, 'eipsi_longitudinal_study') ||
        has_shortcode($post->post_content, 'eipsi_participant_dashboard')) {
        wp_enqueue_script(
            'eipsi-auto-refresh-js',
            EIPSI_FORMS_PLUGIN_URL . 'assets/js/participant-dashboard-auto-refresh.js',
            array('jquery'),
            EIPSI_FORMS_VERSION,
            true
        );

        // Localize auto-refresh script
        wp_localize_script('eipsi-auto-refresh-js', 'eipsiAutoRefresh', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('eipsi_auto_refresh'),
        ));
    }

    // Localize script con strings traducibles
    wp_localize_script('eipsi-participant-ux-js', 'eipsiParticipantUX', array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('eipsi_participant_ux'),
        'strings' => array(
            'progress' => __('Progreso', 'eipsi-forms'),
            'questionsAnswered' => __('preguntas respondidas', 'eipsi-forms'),
            'of' => __('de', 'eipsi-forms'),
            'celebrate' => __('¡Genial!', 'eipsi-forms'),
            'complete' => __('¡Felicidades! Completaste todas las preguntas', 'eipsi-forms'),
            'sectionComplete' => __('¡Excelente! Completaste esta sección', 'eipsi-forms'),
            'helpTitle' => __('¿Por qué preguntamos esto?', 'eipsi-forms'),
            'expandHelp' => __('Ver más contexto', 'eipsi-forms'),
            'collapseHelp' => __('Mostrar menos', 'eipsi-forms')
        )
    ));
}

function eipsi_forms_enqueue_admin_assets($hook) {
    if (strpos($hook, 'eipsi') === false && strpos($hook, 'form-results') === false && strpos($hook, 'eipsi-db-config') === false) {
        return;
    }

    wp_enqueue_style(
        'eipsi-admin-style',
        EIPSI_FORMS_PLUGIN_URL . 'assets/css/admin-style.css',
        array(),
        EIPSI_FORMS_VERSION
    );

    wp_enqueue_script(
        'eipsi-admin-script',
        EIPSI_FORMS_PLUGIN_URL . 'assets/js/admin-script.js',
        array('jquery'),
        EIPSI_FORMS_VERSION,
        true
    );

    wp_localize_script('eipsi-admin-script', 'eipsiAdminConfig', array(
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('eipsi_forms_nonce'),
        'adminNonce' => wp_create_nonce('eipsi_admin_nonce')
    ));

    // Enqueue configuration panel assets
    if (strpos($hook, 'eipsi-db-config') !== false) {
        wp_enqueue_style(
            'eipsi-config-panel-style',
            EIPSI_FORMS_PLUGIN_URL . 'assets/css/configuration-panel.css',
            array(),
            EIPSI_FORMS_VERSION
        );

        wp_enqueue_script(
            'eipsi-config-panel-script',
            EIPSI_FORMS_PLUGIN_URL . 'assets/js/configuration-panel.js',
            array('jquery'),
            EIPSI_FORMS_VERSION,
            true
        );

        // Enqueue email test script
        wp_enqueue_script(
            'eipsi-email-test-script',
            EIPSI_FORMS_PLUGIN_URL . 'assets/js/email-test.js',
            array('jquery'),
            EIPSI_FORMS_VERSION,
            true
        );

        wp_localize_script('eipsi-config-panel-script', 'eipsiConfigL10n', array(
            'connected' => __('Connected', 'eipsi-forms'),
            'disconnected' => __('Disconnected', 'eipsi-forms'),
            'currentDatabase' => __('Current Database:', 'eipsi-forms'),
            'records' => __('Records:', 'eipsi-forms'),
            'noExternalDB' => __('No external database configured. Form submissions will be stored in the WordPress database.', 'eipsi-forms'),
            'fillAllFields' => __('Please fill in all required fields.', 'eipsi-forms'),
            'connectionError' => __('Connection test failed. Please check your credentials.', 'eipsi-forms'),
            'testFirst' => __('Please test the connection before saving.', 'eipsi-forms'),
            'saveError' => __('Failed to save configuration.', 'eipsi-forms'),
            'disableError' => __('Failed to disable external database.', 'eipsi-forms'),
            'confirmDisable' => __('Are you sure you want to disable the external database? Form submissions will be stored in the WordPress database.', 'eipsi-forms'),
            'disableExternal' => __('Disable External Database', 'eipsi-forms'),
            'confirmDeleteTitle' => __('⚠️ Delete All Clinical Data?', 'eipsi-forms'),
            'confirmDeleteMessage' => __('This action will PERMANENTLY delete all form responses, session data, and event logs from EIPSI Forms.\n\nThis CANNOT be undone.\n\nAre you absolutely sure?', 'eipsi-forms'),
            'confirmDeleteYes' => __('Yes, delete all data', 'eipsi-forms'),
            'confirmDeleteNo' => __('Cancel', 'eipsi-forms'),
            'deleteSuccess' => __('All clinical data has been successfully deleted.', 'eipsi-forms'),
            'deleteError' => __('Failed to delete data. Please check the error logs.', 'eipsi-forms'),
            'smtpFillAllFields' => __('Completa servidor, puerto y usuario SMTP.', 'eipsi-forms'),
            'smtpTestError' => __('Error al probar SMTP. Verificá las credenciales.', 'eipsi-forms'),
            'smtpTestFirst' => __('Probá el SMTP antes de guardar.', 'eipsi-forms'),
            'smtpSaveError' => __('No se pudo guardar la configuración SMTP.', 'eipsi-forms'),
            'smtpDisableError' => __('No se pudo desactivar el SMTP.', 'eipsi-forms'),
            'smtpConfirmDisable' => __('¿Seguro que querés desactivar el SMTP? Se usará wp_mail().', 'eipsi-forms'),
            'smtpDisableLabel' => __('Desactivar SMTP', 'eipsi-forms'),
            'smtpActive' => __('SMTP activo', 'eipsi-forms'),
            'smtpInactive' => __('SMTP inactivo', 'eipsi-forms'),
            'smtpNoConfig' => __('No hay configuración SMTP activa. Los correos se enviarán con wp_mail().', 'eipsi-forms'),
            'smtpHostLabel' => __('Servidor:', 'eipsi-forms'),
            'smtpPortLabel' => __('Puerto:', 'eipsi-forms'),
            'smtpUserLabel' => __('Usuario:', 'eipsi-forms'),
            'smtpEncryptionLabel' => __('Seguridad:', 'eipsi-forms')
        ));
    }

    // Enqueue Privacy Dashboard assets (Smart Save Button)
    if (strpos($hook, 'eipsi') !== false && isset($_GET['tab']) && $_GET['tab'] === 'privacy') {
        wp_enqueue_script(
            'eipsi-privacy-dashboard',
            EIPSI_FORMS_PLUGIN_URL . 'admin/js/privacy-dashboard.js',
            array('jquery'),
            filemtime(EIPSI_FORMS_PLUGIN_DIR . 'admin/js/privacy-dashboard.js'),
            true
        );

        // Make ajaxurl available (jQuery already has it via eipsiAdminConfig object)
        // No additional localization needed
    }
}

function eipsi_forms_enqueue_block_editor_assets() {
    // === CARGAR TOKENS PRIMERO (single source of truth) ===
    wp_enqueue_style(
        'eipsi-tokens',
        EIPSI_FORMS_PLUGIN_URL . 'assets/css/eipsi-tokens.css',
        array(),
        EIPSI_FORMS_VERSION
    );

    // === CARGAR CSS PRINCIPALES ===
    // 1. CSS del formulario principal - CONSUME las CSS variables
    wp_enqueue_style(
        'eipsi-forms-styles',
        EIPSI_FORMS_PLUGIN_URL . 'assets/css/eipsi-forms.css',
        array('eipsi-tokens'),
        EIPSI_FORMS_VERSION
    );

    // 2. Estilos de admin (para coherencia visual en el editor)
    wp_enqueue_style(
        'eipsi-admin-style',
        EIPSI_FORMS_PLUGIN_URL . 'assets/css/admin-style.css',
        array('eipsi-tokens'),
        EIPSI_FORMS_VERSION
    );

    // 3. CSS de tema (para dark mode en editor)
    wp_enqueue_style(
        'eipsi-theme-toggle',
        EIPSI_FORMS_PLUGIN_URL . 'assets/css/theme-toggle.css',
        array('eipsi-tokens'),
        EIPSI_FORMS_VERSION
    );

    // 4. CSS de aleatorización (para randomization controls)
    wp_enqueue_style(
        'eipsi-randomization',
        EIPSI_FORMS_PLUGIN_URL . 'assets/css/eipsi-randomization.css',
        array('eipsi-tokens'),
        EIPSI_FORMS_VERSION
    );
}

function eipsi_forms_enqueue_frontend_assets() {
    // Solo en frontend, NO en admin
    if (is_admin()) {
        return;
    }

    // Enqueue design tokens FIRST (single source of truth)
    wp_enqueue_style(
        'eipsi-tokens',
        EIPSI_FORMS_PLUGIN_URL . 'assets/css/eipsi-tokens.css',
        array(),
        EIPSI_FORMS_VERSION
    );

    // Enqueue main form CSS (no longer uses build/style-index.css - removed in v1.3.10 CSS refactor)
    wp_enqueue_style(
        'eipsi-forms-css',
        EIPSI_FORMS_PLUGIN_URL . 'assets/css/eipsi-forms.css',
        array('eipsi-tokens'),  // Depends on tokens
        EIPSI_FORMS_VERSION
    );

    // Dark mode theme toggle styles - CRITICAL for all form fields
    wp_enqueue_style(
        'eipsi-theme-toggle-css',
        EIPSI_FORMS_PLUGIN_URL . 'assets/css/theme-toggle.css',
        array('eipsi-tokens', 'eipsi-forms-css'),
        EIPSI_FORMS_VERSION
    );

    // Fingerprinting script para aleatorización RCT (v1.3.1)
    wp_enqueue_script(
        'eipsi-fingerprint-js',
        EIPSI_FORMS_PLUGIN_URL . 'assets/js/eipsi-fingerprint.js',
        array(),
        EIPSI_FORMS_VERSION,
        true
    );

    wp_enqueue_script(
        'eipsi-tracking-js',
        EIPSI_FORMS_PLUGIN_URL . 'assets/js/eipsi-tracking.js',
        array(),
        EIPSI_FORMS_VERSION,
        true
    );

    wp_localize_script('eipsi-tracking-js', 'eipsiTrackingConfig', array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('eipsi_tracking_nonce'),
    ));

    wp_enqueue_script(
        'eipsi-forms-js',
        EIPSI_FORMS_PLUGIN_URL . 'assets/js/eipsi-forms.js',
        array('eipsi-tracking-js'),
        EIPSI_FORMS_VERSION . '.' . time(),
        true
    );

    wp_localize_script('eipsi-forms-js', 'eipsiFormsConfig', array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('eipsi_forms_nonce'),
        'savePartialNonce' => wp_create_nonce('eipsi_save_partial'),
        'strings' => array(
            'requiredField' => 'Este campo es obligatorio.',
            'sliderRequired' => 'Por favor, interactúe con la escala para continuar.',
            'invalidEmail' => 'Por favor, introduzca una dirección de correo electrónico válida.',
            'submitting' => 'Enviando...',
            'submit' => 'Enviar',
            'error' => 'Ocurrió un error. Por favor, inténtelo de nuevo.',
            'success' => '¡Formulario enviado correctamente!',
            'studyClosedMessage' => __('Este estudio está cerrado y no acepta más respuestas. Contacta al investigador si tienes dudas.', 'eipsi-forms'),
        ),
        'settings' => array(
            'debug' => apply_filters('eipsi_forms_debug_mode', defined('WP_DEBUG') && WP_DEBUG),
            'enableAutoScroll' => apply_filters('eipsi_forms_enable_auto_scroll', true),
            'scrollOffset' => apply_filters('eipsi_forms_scroll_offset', 20),
            'validateOnBlur' => apply_filters('eipsi_forms_validate_on_blur', true),
            'smoothScroll' => apply_filters('eipsi_forms_smooth_scroll', true),
        ),
    ));

    // === SAVE & CONTINUE SYSTEM (v1.3.15 - CRITICAL FIX) ===
    // CSS del modal de recuperación de sesión
    wp_enqueue_style(
        'eipsi-save-continue-css',
        EIPSI_FORMS_PLUGIN_URL . 'assets/css/eipsi-save-continue.css',
        array('eipsi-forms-css'),
        EIPSI_FORMS_VERSION
    );

    // JS de Save & Continue: autosave + IndexedDB + modal de recuperación
    wp_enqueue_script(
        'eipsi-save-continue-js',
        EIPSI_FORMS_PLUGIN_URL . 'assets/js/eipsi-save-continue.js',
        array('eipsi-forms-js'),
        EIPSI_FORMS_VERSION,
        true
    );
    if (current_user_can('manage_options')) {
        wp_localize_script('eipsi-save-continue-js', 'eipsiPartialDebugConfig', array(
            'nonce' => wp_create_nonce('eipsi_admin_nonce'),
        ));
    }
    // === FIN SAVE & CONTINUE ===

    // Enqueue Randomization Public System styles (Fase 3)
    wp_enqueue_style(
        'eipsi-randomization-css',
        EIPSI_FORMS_PLUGIN_URL . 'assets/css/eipsi-randomization.css',
        array('eipsi-theme-toggle-css'),
        EIPSI_FORMS_VERSION
    );

    // Enqueue Randomization Public System script (Fase 3)
    wp_enqueue_script(
        'eipsi-randomization-js',
        EIPSI_FORMS_PLUGIN_URL . 'assets/js/eipsi-randomization.js',
        array('eipsi-forms-js'),
        EIPSI_FORMS_VERSION,
        true
    );

    // === LOGIN GATE SYSTEM (Task 1.3) ===
    // CSS del login gate
    wp_enqueue_style(
        'eipsi-login-gate-css',
        EIPSI_FORMS_PLUGIN_URL . 'assets/css/login-gate.css',
        array('eipsi-theme-toggle-css'),
        EIPSI_FORMS_VERSION
    );

    // JS del login gate
    wp_enqueue_script(
        'eipsi-login-gate-js',
        EIPSI_FORMS_PLUGIN_URL . 'assets/js/login-gate.js',
        array('jquery'),
        EIPSI_FORMS_VERSION,
        true
    );
}

function eipsi_forms_enqueue_block_assets($content) {
    $blocks = array(
        'eipsi/form-container',
        'eipsi/consent-block',
        'eipsi/campo-texto',
        'eipsi/campo-textarea',
        'eipsi/campo-descripcion',
        'eipsi/campo-select',
        'eipsi/campo-radio',
        'eipsi/campo-multiple',
        'eipsi/campo-likert',
        'eipsi/vas-slider',
        'eipsi/pool-join', // v2.5.3 - Pool de Estudios
    );

    foreach ($blocks as $block) {
        if (has_block($block, $content)) {
            eipsi_forms_enqueue_frontend_assets();
            break;
        }
    }

    return $content;
}
