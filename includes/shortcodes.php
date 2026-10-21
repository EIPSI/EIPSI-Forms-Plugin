<?php
/**
 * EIPSI Forms Shortcodes
 * 
 * Official shortcode to insert form templates anywhere
 * 
 * @package EIPSI_Forms
 * @since 1.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shortcode: [eipsi_form id="123"]
 * 
 * Display a form template by ID
 * 
 * @param array $atts Shortcode attributes
 * @return string Rendered HTML
 */
function eipsi_form_shortcode($atts) {
    // Guard Clause: Never render form in admin context
    // Prevents showing participant forms in WordPress admin panel
    if (is_admin()) {
        return '';
    }
    
    $atts = shortcode_atts(array(
        'id' => 0,
    ), $atts, 'eipsi_form');
    
    $template_id = absint($atts['id']);
    
    // Allow overriding from URL if id is 0 or not set, ONLY if authenticated
    // This allows a single /estudio/ page to serve multiple forms based on magic links
    if ($template_id === 0 && isset($_GET['form_id']) && function_exists('eipsi_is_participant_logged_in') && eipsi_is_participant_logged_in()) {
        $template_id = absint($_GET['form_id']);
    }
    
    // CRITICAL: Validate wave status before rendering form (prevent skipped/expired waves)
    if (isset($_GET['wave_id']) && function_exists('eipsi_is_participant_logged_in') && eipsi_is_participant_logged_in()) {
        global $wpdb;
        $wave_id = absint($_GET['wave_id']);
        $participant_id = EIPSI_Auth_Service::get_current_participant();
        
        if ($wave_id && $participant_id) {
            $assignment = $wpdb->get_row($wpdb->prepare(
                "SELECT a.id, a.study_id, a.status, a.available_at, a.due_at, w.window_minutes, w.wave_index
                 FROM {$wpdb->prefix}survey_assignments a
                 JOIN {$wpdb->prefix}survey_waves w ON a.wave_id = w.id
                 WHERE a.wave_id = %d AND a.participant_id = %d",
                $wave_id,
                $participant_id
            ));
            
            if ($assignment) {
                // Preserve the render guard using the same deadline as submit.
                $temporal = EIPSI_Longitudinal_Assignment_Transition_Service::precheck_submission(
                    $participant_id, $assignment->study_id, $wave_id
                );
                if (!$temporal['success'] && ($temporal['data']['code'] ?? '') === 'deadline_passed') {
                    if (EIPSI_Longitudinal_Assignment_Transition_Service::expire_snapshot($assignment, true) > 0) {
                        $assignment->status = 'expired';
                    }
                }

                $allowed_statuses = array('pending', 'in_progress');
                
                if (!in_array($assignment->status, $allowed_statuses)) {
                    error_log(sprintf('[EIPSI Form Render] Blocked form render for wave_id=%d, participant=%d, status=%s (not allowed)', 
                        $wave_id, $participant_id, $assignment->status));
                    
                    // Return user-friendly message based on status
                    $message = '';
                    $redirect_url = '';
                    
                    if ($assignment->status === 'skipped') {
                        $message = __('Esta toma ya no está disponible porque una toma posterior se ha vuelto disponible. Por favor, volvé al panel de estudio para continuar con la toma actual.', 'eipsi-forms');
                    } elseif ($assignment->status === 'expired') {
                        $message = __('Esta toma ha expirado. Por favor, volvé al panel de estudio.', 'eipsi-forms');
                    } elseif ($assignment->status === 'submitted') {
                        $message = __('Ya completaste esta toma. Por favor, volvé al panel de estudio.', 'eipsi-forms');
                    } else {
                        $message = sprintf(__('Esta toma no está disponible (estado: %s). Por favor, volvé al panel de estudio.', 'eipsi-forms'), $assignment->status);
                    }
                    
                    // Try to find the study dashboard URL
                    $study_id = $wpdb->get_var($wpdb->prepare(
                        "SELECT study_id FROM {$wpdb->prefix}survey_waves WHERE id = %d",
                        $wave_id
                    ));
                    
                    if ($study_id) {
                        $study = $wpdb->get_row($wpdb->prepare(
                            "SELECT study_code FROM {$wpdb->prefix}survey_studies WHERE id = %d",
                            $study_id
                        ));
                        
                        if ($study && !empty($study->study_code)) {
                            // Find page with [eipsi_longitudinal_study] shortcode
                            $pages = get_posts(array(
                                'post_type' => 'page',
                                'post_status' => 'publish',
                                'posts_per_page' => -1,
                                's' => '[eipsi_longitudinal_study',
                            ));
                            
                            foreach ($pages as $page) {
                                if (has_shortcode($page->post_content, 'eipsi_longitudinal_study')) {
                                    $redirect_url = add_query_arg('study_code', $study->study_code, get_permalink($page->ID));
                                    break;
                                }
                            }
                        }
                    }
                    
                    // Render error message with redirect button
                    ob_start();
                    ?>
                    <div class="eipsi-wave-status-error" style="max-width: 600px; margin: 40px auto; padding: 30px; background: #fff3cd; border: 2px solid #ffc107; border-radius: 8px; text-align: center;">
                        <div style="font-size: 48px; margin-bottom: 16px;">⚠️</div>
                        <h3 style="margin: 0 0 16px 0; color: #856404;"><?php _e('Toma no disponible', 'eipsi-forms'); ?></h3>
                        <p style="margin: 0 0 24px 0; color: #856404; line-height: 1.6;"><?php echo esc_html($message); ?></p>
                        <?php if ($redirect_url): ?>
                            <a href="<?php echo esc_url($redirect_url); ?>" class="button button-primary button-large" style="display: inline-block; padding: 12px 24px; background: #0284c7; color: white; text-decoration: none; border-radius: 6px; font-weight: 600;">
                                <?php _e('Volver al panel de estudio', 'eipsi-forms'); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                    <?php
                    return ob_get_clean();
                }
                
                error_log(sprintf('[EIPSI Form Render] Rendering form for wave_id=%d, participant=%d, status=%s (allowed)', 
                    $wave_id, $participant_id, $assignment->status));
            }
        }
    }
    
    // Use shared render helper
    return eipsi_render_form_shortcode_markup($template_id);
}
add_shortcode('eipsi_form', 'eipsi_form_shortcode');

/**
 * Shortcode: [eipsi_survey_login survey_id="123"]
 * 
 * Display a login/registration form for participants
 * 
 * @param array $atts Shortcode attributes
 * @return string Rendered HTML
 */
function eipsi_survey_login_shortcode($atts) {
    $atts = shortcode_atts(array(
        'survey_id' => 0,
        'study_code' => '',
        'redirect_url' => '', // opcional, redirect post-login
    ), $atts, 'eipsi_survey_login');
    
    return eipsi_render_survey_login_form($atts);
}
add_shortcode('eipsi_survey_login', 'eipsi_survey_login_shortcode');

/**
 * Resolve study context from explicit IDs, study codes, and redirect URLs.
 *
 * @param int    $survey_id    Survey/Study ID if already known.
 * @param string $study_code   Study code if already known.
 * @param string $redirect_url Redirect URL pointing to a study page.
 * @return array{survey_id:int,study_code:string,page_id:int}
 */
if (!function_exists('eipsi_resolve_survey_context')) {
    function eipsi_resolve_survey_context($survey_id = 0, $study_code = '', $redirect_url = '') {
        global $wpdb;

        $resolved = array(
            'survey_id' => absint($survey_id),
            'study_code' => sanitize_text_field($study_code),
            'page_id' => 0,
        );

        $lookup_survey_id_by_code = static function($code) use ($wpdb) {
            if (empty($code)) {
                return 0;
            }

            return (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}survey_studies WHERE study_code = %s",
                $code
            ));
        };

        $lookup_study_code_by_id = static function($id) use ($wpdb) {
            if (empty($id)) {
                return '';
            }

            return (string) $wpdb->get_var($wpdb->prepare(
                "SELECT study_code FROM {$wpdb->prefix}survey_studies WHERE id = %d",
                $id
            ));
        };

        if ($resolved['survey_id'] > 0 && empty($resolved['study_code'])) {
            $resolved['study_code'] = $lookup_study_code_by_id($resolved['survey_id']);
        }

        if (empty($resolved['survey_id']) && !empty($resolved['study_code'])) {
            $resolved['survey_id'] = $lookup_survey_id_by_code($resolved['study_code']);
        }

        if ($resolved['survey_id'] > 0 && !empty($resolved['study_code'])) {
            return $resolved;
        }

        $redirect_url = esc_url_raw($redirect_url);
        if (empty($redirect_url)) {
            return $resolved;
        }

        $parsed_url = wp_parse_url($redirect_url);
        $query_args = array();

        if (!empty($parsed_url['query'])) {
            parse_str($parsed_url['query'], $query_args);
        }

        if (empty($resolved['survey_id'])) {
            $resolved['survey_id'] = absint($query_args['eipsi_survey_id'] ?? ($query_args['survey_id'] ?? 0));
        }

        if (empty($resolved['study_code'])) {
            $resolved['study_code'] = sanitize_text_field($query_args['eipsi_study_code'] ?? ($query_args['study_code'] ?? ''));
        }

        if ($resolved['survey_id'] > 0 && empty($resolved['study_code'])) {
            $resolved['study_code'] = $lookup_study_code_by_id($resolved['survey_id']);
        }

        if (empty($resolved['survey_id']) && !empty($resolved['study_code'])) {
            $resolved['survey_id'] = $lookup_survey_id_by_code($resolved['study_code']);
        }

        $page_id = url_to_postid($redirect_url);

        if (empty($page_id) && !empty($parsed_url['path'])) {
            $redirect_path = trim($parsed_url['path'], '/');
            $redirect_page = get_page_by_path($redirect_path);

            if ($redirect_page) {
                $page_id = (int) $redirect_page->ID;
            }
        }

        if ($page_id > 0) {
            $resolved['page_id'] = $page_id;

            if (empty($resolved['survey_id'])) {
                $resolved['survey_id'] = absint(get_post_meta($page_id, 'eipsi_study_id', true));
            }

            if (empty($resolved['survey_id'])) {
                $resolved['survey_id'] = absint(get_post_meta($page_id, 'eipsi_survey_id', true));
            }

            if (empty($resolved['survey_id'])) {
                $resolved['survey_id'] = absint(get_post_meta($page_id, '_eipsi_survey_id', true));
            }

            if (empty($resolved['study_code'])) {
                $resolved['study_code'] = sanitize_text_field(get_post_meta($page_id, 'eipsi_study_code', true));
            }

            $content = (string) get_post_field('post_content', $page_id);

            if (empty($resolved['study_code']) && preg_match('/\[eipsi_longitudinal_study[^\]]*study_code=["\']([^"\']+)["\']/', $content, $matches)) {
                $resolved['study_code'] = sanitize_text_field($matches[1]);
            }

            if (empty($resolved['survey_id']) && preg_match('/\[eipsi_longitudinal_study[^\]]*id=["\'](\d+)["\']/', $content, $matches)) {
                $resolved['survey_id'] = absint($matches[1]);
            }
        }

        if (empty($resolved['survey_id']) && !empty($resolved['study_code'])) {
            $resolved['survey_id'] = $lookup_survey_id_by_code($resolved['study_code']);
        }

        if ($resolved['survey_id'] > 0 && empty($resolved['study_code'])) {
            $resolved['study_code'] = $lookup_study_code_by_id($resolved['survey_id']);
        }

        return $resolved;
    }
}

/**
 * Render helper for survey login form
 * 
 * @param array $atts Shortcode attributes
 * @return string Rendered HTML
 */
function eipsi_render_survey_login_form($atts) {
    // Prevent page caching — contains dynamic nonce
    if ( ! is_admin() ) {
        nocache_headers();
    }
    // Extract attributes
    $survey_id = isset($atts['survey_id']) ? absint($atts['survey_id']) : 0;
    $redirect_url = isset($atts['redirect_url']) ? esc_url_raw($atts['redirect_url']) : '';
    $study_code = isset($atts['study_code']) ? sanitize_text_field($atts['study_code']) : '';
    
    // Initialize participant variables
    $is_participant_logged_in = false;
    $current_participant_id = 0;
    
    // Handle redirect_to parameter from URL (for post-login redirect)
    $redirect_to = isset($_GET['redirect_to']) ? esc_url_raw(wp_unslash($_GET['redirect_to'])) : '';

    if (empty($redirect_to) && isset($atts['redirect_url'])) {
        $redirect_to = $atts['redirect_url'];
    }

    $query_survey_id = isset($_GET['eipsi_survey_id']) ? absint($_GET['eipsi_survey_id']) : 0;
    $query_study_code = isset($_GET['eipsi_study_code']) ? sanitize_text_field(wp_unslash($_GET['eipsi_study_code'])) : '';

    $resolved_context = eipsi_resolve_survey_context(
        $query_survey_id ?: $survey_id,
        !empty($query_study_code) ? $query_study_code : $study_code,
        $redirect_to
    );

    if (!empty($resolved_context['survey_id'])) {
        $survey_id = (int) $resolved_context['survey_id'];
        $atts['survey_id'] = $survey_id;
    }

    if (!empty($resolved_context['study_code'])) {
        $study_code = $resolved_context['study_code'];
        $atts['study_code'] = $study_code;
    }
    
    // Enqueue required assets

    // 1. Participant auth base script — MUST load first so window.eipsiAuth is defined
    wp_enqueue_script(
        'eipsi-participant-auth',
        EIPSI_FORMS_PLUGIN_URL . 'assets/js/participant-auth.js',
        array('jquery'),
        EIPSI_FORMS_VERSION,
        true
    );

    // Localize with nonce — do this immediately after registering the script
    // v2.5.3 - Agregar participantId para Save & Continue con usuarios autenticados
    wp_localize_script('eipsi-participant-auth', 'eipsiAuth', array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('eipsi_participant_auth'),
        'loginUrl' => get_permalink(),
        'participantId' => $current_participant_id,
        'strings' => array(
            'registering' => __('Registrando...', 'eipsi-forms'),
            'logging_in' => __('Ingresando...', 'eipsi-forms'),
            'loading' => __('Cargando...', 'eipsi-forms'),
            'confirm_logout' => __('¿Estás seguro de que quieres cerrar sesión?', 'eipsi-forms'),
            // Session timer strings
            'session_expires_in' => __('Tu sesión expira en', 'eipsi-forms'),
            'extend_session' => __('Extender sesión', 'eipsi-forms'),
            'hide_timer' => __('Ocultar', 'eipsi-forms'),
            'session_expiring_soon' => __('¡Tu sesión está por expirar!', 'eipsi-forms'),
            'session_expired_title' => __('Sesión Expirada', 'eipsi-forms'),
            'session_expired_message' => __('Tu sesión ha expirado por seguridad. Por favor, inicia sesión nuevamente para continuar.', 'eipsi-forms'),
            'login_again' => __('Iniciar sesión', 'eipsi-forms'),
            'session_extended' => __('¡Sesión extendida!', 'eipsi-forms'),
            'extend_error' => __('Error al extender la sesión', 'eipsi-forms'),
            'network_error' => __('Error de conexión. Intenta nuevamente.', 'eipsi-forms'),
            'minute' => __('minuto', 'eipsi-forms'),
            'minutes' => __('minutos', 'eipsi-forms'),
            'second' => __('segundo', 'eipsi-forms'),
            'seconds' => __('segundos', 'eipsi-forms')
        )
    ));
    
    // 2. Enhanced login UI script — depends on eipsi-participant-auth so it loads after
    wp_enqueue_style(
        'eipsi-survey-login-css',
        EIPSI_FORMS_PLUGIN_URL . 'assets/css/survey-login-enhanced.css',
        array(),
        EIPSI_FORMS_VERSION
    );
    wp_enqueue_script(
        'eipsi-survey-login-js',
        EIPSI_FORMS_PLUGIN_URL . 'assets/js/survey-login-enhanced.js',
        array('jquery', 'eipsi-participant-auth'), // explicit dep ensures correct load order
        EIPSI_FORMS_VERSION,
        true
    );

    if (!empty($redirect_to)) {
        wp_add_inline_script('eipsi-participant-auth', 
            'window.eipsiRedirectTo = "' . $redirect_to . '";', 
            'before'
        );
        
        // Ensure it's available for the template
        if (!is_array($atts)) {
            $atts = array();
        }
        $atts['redirect_url'] = $redirect_to;
    }
    
    ob_start();
    include EIPSI_FORMS_PLUGIN_DIR . 'includes/templates/survey-login-form.php';
    return ob_get_clean();
}

/**
 * Shortcode: [eipsi_participant_dashboard survey_id="123"]
 * 
 * Display participant dashboard with progress and next wave
 * 
 * @param array $atts Shortcode attributes
 * @return string Rendered HTML
 */
function eipsi_participant_dashboard_shortcode($atts) {
    // Guard Clause: Never render dashboard in admin context
    // Prevents showing participant dashboard in WordPress admin panel
    if (is_admin()) {
        return '';
    }
    
    // Auto-detect and flag the current page as having a dashboard (for redirect detection)
    // This enables automatic redirect detection in eipsi_get_participant_redirect_url()
    $current_page_id = get_the_ID();
    if ($current_page_id && !has_post_meta($current_page_id, '_eipsi_has_dashboard')) {
        update_post_meta($current_page_id, '_eipsi_has_dashboard', '1');
    }
    
    // Parse attributes first to check survey_id
    $atts = shortcode_atts(array(
        'survey_id' => 0,
    ), $atts, 'eipsi_participant_dashboard');
    
    // Check authentication
    if (!EIPSI_Auth_Service::is_authenticated()) {
        // Return login form if not authenticated
        return eipsi_render_survey_login_form(array(
            'survey_id' => $atts['survey_id'],
            'redirect_url' => get_permalink()
        ));
    }
    
    // Get current participant
    $participant_id = EIPSI_Auth_Service::get_current_participant();
    if (!$participant_id) {
        // Session expired or invalid
        EIPSI_Auth_Service::destroy_session();
        return eipsi_render_survey_login_form(array(
            'survey_id' => $atts['survey_id'],
            'redirect_url' => get_permalink()
        ));
    }
    
    // Get participant data
    $participant = EIPSI_Participant_Service::get_by_id($participant_id);
    if (!$participant) {
        EIPSI_Auth_Service::destroy_session();
        return eipsi_render_survey_login_form(array(
            'survey_id' => $atts['survey_id'],
            'redirect_url' => get_permalink()
        ));
    }
    
    // Get survey_id from participant or attribute
    $survey_id = !empty($participant->survey_id) ? (int) $participant->survey_id : absint($atts['survey_id']);
    if (empty($survey_id)) {
        return '<div class="eipsi-dashboard-error"><p>' . __('Error: No se encontró el estudio.', 'eipsi-forms') . '</p></div>';
    }
    
    // Enqueue session timer assets
    wp_enqueue_style(
        'eipsi-participant-portal-css',
        EIPSI_FORMS_PLUGIN_URL . 'assets/css/participant-portal.css',
        array(),
        EIPSI_FORMS_VERSION
    );
    
    wp_enqueue_script(
        'eipsi-participant-portal-js',
        EIPSI_FORMS_PLUGIN_URL . 'assets/js/participant-portal.js',
        array('jquery'),
        EIPSI_FORMS_VERSION,
        true
    );
    
    // Localize script with session timer strings (for authenticated pages)
    // v2.5.3 - Agregar participantId para Save & Continue
    wp_localize_script('eipsi-participant-portal-js', 'eipsiAuth', array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('eipsi_participant_auth'),
        'loginUrl' => eipsi_get_login_page_url($survey_id),
        'participantId' => $current_participant_id,
        'strings' => array(
            'session_expires_in' => __('Tu sesión expira en', 'eipsi-forms'),
            'extend_session' => __('Extender sesión', 'eipsi-forms'),
            'hide_timer' => __('Ocultar', 'eipsi-forms'),
            'session_expiring_soon' => __('¡Tu sesión está por expirar!', 'eipsi-forms'),
            'session_expired_title' => __('Sesión Expirada', 'eipsi-forms'),
            'session_expired_message' => __('Tu sesión ha expirado por seguridad. Por favor, inicia sesión nuevamente para continuar.', 'eipsi-forms'),
            'login_again' => __('Iniciar sesión', 'eipsi-forms'),
            'session_extended' => __('¡Sesión extendida!', 'eipsi-forms'),
            'extend_error' => __('Error al extender la sesión', 'eipsi-forms'),
            'network_error' => __('Error de conexión. Intenta nuevamente.', 'eipsi-forms'),
            'minute' => __('minuto', 'eipsi-forms'),
            'minutes' => __('minutos', 'eipsi-forms'),
            'second' => __('segundo', 'eipsi-forms'),
            'seconds' => __('segundos', 'eipsi-forms')
        )
    ));
    
    // Get all waves for the study (with assignments)
    $all_waves = eipsi_get_participant_waves_with_assignments($participant_id, $survey_id);
    
    // Find next wave (first pending/not started wave ordered by wave_index)
    $next_wave = null;
    foreach ($all_waves as $wave) {
        if (empty($wave['assignment']) || $wave['assignment']['status'] !== 'submitted') {
            $next_wave = $wave;
            break;
        }
    }
    
    // T1-Anchor: Calculate availability for next wave (offset from T1)
    $wave_locked = false;
    $available_timestamp = null;
    if ($next_wave && isset($next_wave['offset_minutes'])) {
        // Get T1 (first wave) to calculate offset
        $t1_wave = null;
        foreach ($all_waves as $wave) {
            if ($wave['wave_index'] == 1) {
                $t1_wave = $wave;
                break;
            }
        }
        
        if ($t1_wave && !empty($t1_wave['assignment']) && $t1_wave['assignment']['status'] === 'submitted') {
            $offset_minutes = intval($next_wave['offset_minutes']);
            
            if ($offset_minutes > 0) {
                // Calculate available date (T1 submission + offset_minutes)
                $t1_submitted_at = strtotime($t1_wave['assignment']['submitted_at']);
                $available_timestamp = $t1_submitted_at + ($offset_minutes * 60);
                $now = current_time('timestamp');
                
                if ($available_timestamp > $now) {
                    $wave_locked = true;
                }
            }
        }
        
        // Add flags to next_wave
        $next_wave['is_locked'] = $wave_locked;
        $next_wave['available_timestamp'] = $available_timestamp;
    }
    
    // Enqueue countdown script if wave is locked
    if ($wave_locked && $available_timestamp) {
        wp_enqueue_script(
            'eipsi-participant-countdown',
            EIPSI_FORMS_PLUGIN_URL . 'assets/js/participant-countdown.js',
            array('jquery'),
            EIPSI_FORMS_VERSION,
            true
        );
    }
    
    // Render dashboard template
    ob_start();
    include EIPSI_FORMS_PLUGIN_DIR . 'includes/templates/participant-dashboard.php';
    return ob_get_clean();
}
add_shortcode('eipsi_participant_dashboard', 'eipsi_participant_dashboard_shortcode');

/**
 * Helper function to get the login page URL for a study
 * 
 * @param int $survey_id Survey/Study ID
 * @return string Login page URL
 */
function eipsi_get_login_page_url($survey_id = 0) {
    // Try to find a page with the survey login shortcode for this survey
    if ($survey_id > 0) {
        $login_pages = get_posts(array(
            'post_type' => 'page',
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'meta_query' => array(
                array(
                    'key' => '_eipsi_survey_id',
                    'value' => $survey_id
                )
            )
        ));
        
        if (!empty($login_pages)) {
            return get_permalink($login_pages[0]->ID);
        }
    }
    
    // Try to find any page with the participant login shortcode
    $login_pages = get_posts(array(
        'post_type' => 'page',
        'post_status' => 'publish',
        'posts_per_page' => 1,
        's' => 'eipsi_survey_login'
    ));
    
    if (!empty($login_pages)) {
        return get_permalink($login_pages[0]->ID);
    }

    // Method 3: Check for a page with slug 'login'
    $login_page = get_page_by_path('login');
    if ($login_page) {
        return get_permalink($login_page->ID);
    }
    
    // Default fallback (v1.6.0) - try to use /login if it exists, otherwise home
    return home_url('/login');
}

/**
 * Helper function to generate wave form URL for participant
 * 
 * Fase 4 - Centraliza generación de URLs para responder waves
 * 
 * @param int $wave_id Wave ID
 * @param int $survey_id Study/Survey ID
 * @return string URL para responder la wave
 */
function eipsi_get_wave_form_url($wave_id, $survey_id) {
    return add_query_arg(
        array(
            'wave_id' => $wave_id,
            'survey_id' => $survey_id,
        ),
        home_url('/estudio/')
    );
}

/**
 * Helper function to get participant waves with assignment data
 * 
 * @param int $participant_id Participant ID
 * @param int $survey_id Survey ID
 * @return array Array of waves with assignment data
 */
function eipsi_get_participant_waves_with_assignments($participant_id, $survey_id) {
    global $wpdb;
    
    $waves = $wpdb->get_results($wpdb->prepare(
        "SELECT 
            w.id,
            w.wave_index,
            w.name,
            w.form_id,
            w.due_date,
            w.reminder_days,
            w.retry_enabled,
            w.retry_days,
            w.max_retries,
            w.status as wave_status,
            w.is_mandatory
         FROM {$wpdb->prefix}survey_waves w
         WHERE w.study_id = %d
         ORDER BY w.wave_index ASC",
        $survey_id
    ), ARRAY_A);
    
    if (empty($waves)) {
        return array();
    }
    
    // Get assignments for this participant
    $assignments = $wpdb->get_results($wpdb->prepare(
        "SELECT 
            a.wave_id,
            a.status,
            a.submitted_at,
            a.first_viewed_at,
            a.reminder_count,
            a.last_reminder_sent,
            fr.duration_seconds as submission_duration
         FROM {$wpdb->prefix}survey_assignments a
         LEFT JOIN {$wpdb->prefix}vas_form_results fr ON 
             fr.form_id = a.wave_id AND 
             fr.participant_id = %s
         WHERE a.participant_id = %d",
        $participant_id,
        $participant_id
    ), ARRAY_A);
    
    // Index assignments by wave_id
    $assignments_by_wave = array();
    foreach ($assignments as $assignment) {
        $assignments_by_wave[$assignment['wave_id']] = $assignment;
    }
    
    // Merge waves with assignments
    foreach ($waves as &$wave) {
        $wave['assignment'] = isset($assignments_by_wave[$wave['id']]) ? $assignments_by_wave[$wave['id']] : null;
    }
    
    return $waves;
}

/**
 * Add helpful information to shortcode in admin
 * Shows available forms when editing posts/pages
 */
function eipsi_shortcode_help_metabox() {
    $screens = array('post', 'page');
    
    foreach ($screens as $screen) {
        add_meta_box(
            'eipsi_shortcode_help',
            __('Shortcode de Formularios EIPSI', 'eipsi-forms'),
            'eipsi_shortcode_help_callback',
            $screen,
            'side',
            'default'
        );
    }
}
add_action('add_meta_boxes', 'eipsi_shortcode_help_metabox');

/**
 * Render shortcode help metabox
 */
function eipsi_shortcode_help_callback($post) {
    ?>
    <div class="eipsi-shortcode-help">
        <p style="font-size: 13px; margin: 0 0 12px;">
            <?php esc_html_e('Insertá formularios usando este formato:', 'eipsi-forms'); ?>
        </p>
        
        <code style="display: block; padding: 8px; background: #f0f0f1; border-radius: 3px; margin-bottom: 12px;">
            [eipsi_form id="<strong style="color: #2271b1;">123</strong>"]
        </code>
        
        <?php
        // Allow other shortcodes to add their help content
        do_action('eipsi_shortcode_help_metabox_content', $post);
        
        // Get available form templates
        $templates = get_posts(array(
            'post_type' => 'eipsi_form_template',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'orderby' => 'title',
            'order' => 'ASC',
        ));
        
        if (!empty($templates)) {
            ?>
            <p style="font-size: 13px; margin: 12px 0 8px; font-weight: 600;">
                <?php esc_html_e('Formularios disponibles:', 'eipsi-forms'); ?>
            </p>
            
            <div style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; border-radius: 3px;">
                <?php foreach ($templates as $template) : ?>
                    <div style="padding: 8px; border-bottom: 1px solid #f0f0f1; font-size: 12px;">
                        <div style="font-weight: 600; margin-bottom: 4px;">
                            <?php echo esc_html($template->post_title ?: __('(Sin título)', 'eipsi-forms')); ?>
                        </div>
                        <code style="background: #f0f0f1; padding: 2px 6px; border-radius: 2px; font-size: 11px; cursor: pointer;" 
                              onclick="navigator.clipboard.writeText('[eipsi_form id=&quot;<?php echo esc_attr($template->ID); ?>&quot;]'); this.style.background='#00a32a'; this.style.color='white'; setTimeout(() => { this.style.background='#f0f0f1'; this.style.color=''; }, 1000);" 
                              title="<?php esc_attr_e('Clic para copiar', 'eipsi-forms'); ?>">
                            [eipsi_form id="<?php echo esc_attr($template->ID); ?>"]
                        </code>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <p style="font-size: 11px; color: #666; margin: 8px 0 0;">
                <em><?php esc_html_e('💡 Clic en un shortcode para copiarlo', 'eipsi-forms'); ?></em>
            </p>
            <?php
        } else {
            ?>
            <p style="font-size: 13px; margin: 12px 0 0; padding: 8px; background: #f0f0f1; border-radius: 3px;">
                <?php esc_html_e('No hay formularios creados aún.', 'eipsi-forms'); ?>
                <br>
                <a href="<?php echo esc_url(admin_url('post-new.php?post_type=eipsi_form_template')); ?>" style="font-size: 13px;">
                    <?php esc_html_e('+ Crear tu primer formulario', 'eipsi-forms'); ?>
                </a>
            </p>
            <?php
        }
        ?>
    </div>
    
    <style>
        .eipsi-shortcode-help code:hover {
            background: #e5e7eb !important;
        }
    </style>
    <?php
}

/**
 * Add shortcode column to pages/posts list
 * Shows if the page/post contains EIPSI form shortcodes
 */
function eipsi_add_shortcode_indicator_column($columns) {
    $columns['eipsi_forms'] = '<span class="dashicons dashicons-feedback" title="' . esc_attr__('Formularios EIPSI', 'eipsi-forms') . '"></span>';
    return $columns;
}
add_filter('manage_posts_columns', 'eipsi_add_shortcode_indicator_column');
add_filter('manage_pages_columns', 'eipsi_add_shortcode_indicator_column');

/**
 * Show shortcode indicator in column
 */
function eipsi_shortcode_indicator_column_content($column, $post_id) {
    if ($column === 'eipsi_forms') {
        $content = get_post_field('post_content', $post_id);
        $has_shortcode = has_shortcode($content, 'eipsi_form');
        $has_block = has_block('eipsi/form-container', $content);

        if ($has_shortcode || $has_block) {
            echo '<span class="dashicons dashicons-yes-alt" style="color: #00a32a;" title="' . esc_attr__('Contiene formularios EIPSI', 'eipsi-forms') . '"></span>';
        } else {
            echo '<span style="color: #ddd;">—</span>';
        }
    }
}
add_action('manage_posts_custom_column', 'eipsi_shortcode_indicator_column_content', 10, 2);
add_action('manage_pages_custom_column', 'eipsi_shortcode_indicator_column_content', 10, 2);

/**
 * Shortcode: [eipsi_longitudinal_study study_code="STUDY_2025"] or [eipsi_longitudinal_study id="123"]
 * 
 * Display a longitudinal study configuration with waves, time limits, and settings.
 * The shortcode remains persistent regardless of study configuration changes.
 * 
 * SECURE: Use study_code instead of ID for better security.
 * Example: [eipsi_longitudinal_study study_code="ANSIEDAD_TCC_2025"]
 * 
 * @param array $atts Shortcode attributes
 * @return string Rendered HTML
 * 
 * @since 1.5.0
 * @since 1.6.0 - Added study_code support for secure shortcodes
 */
function eipsi_longitudinal_study_shortcode($atts) {
    // Parse attributes with defaults
    $atts = shortcode_atts(array(
        'study_code' => '',      // Study code (preferred, secure)
        'id' => 0,                // Study ID (deprecated, less secure)
        'wave' => 0,              // Specific wave to display (optional, 0 = all waves)
        'time_limit' => 0,        // Override time limit in minutes (optional, 0 = use study default)
        'show_config' => 'yes',   // Show study configuration details (yes/no)
        'show_waves' => 'yes',    // Show waves list (yes/no)
        'theme' => 'default',     // Theme: default, compact, card
        'view' => 'auto',         // View mode: auto, dashboard, participant, public
    ), $atts, 'eipsi_longitudinal_study');
        // Prevent page caching — this page contains dynamic authenticated content
    if ( ! is_admin() ) {
        nocache_headers();
    }
    $study_code = sanitize_text_field($atts['study_code']);
    $study_id = absint($atts['id']);
    $wave_index = absint($atts['wave']);
    $time_limit_override = absint($atts['time_limit']);
    $show_config = strtolower($atts['show_config']) === 'yes';
    $show_waves = strtolower($atts['show_waves']) === 'yes';
    $theme = sanitize_key($atts['theme']);
    $requested_view = sanitize_key($atts['view']);
    
    // ============================================================
    // CRITICAL: Authentication State Detection
    // Determines what view to show based on user context
    // ============================================================
    $view_mode = 'public'; // Default for non-authenticated users

    // Check if participant is already authenticated
    $is_participant_logged_in = false;
    $current_participant_id = 0;
    $current_survey_id = 0;

    if (class_exists('EIPSI_Auth_Service')) {
        $is_participant_logged_in = EIPSI_Auth_Service::is_authenticated();
        if ($is_participant_logged_in) {
            $current_participant_id = EIPSI_Auth_Service::get_current_participant();
            $current_survey_id = EIPSI_Auth_Service::get_current_survey();
        }
    }

    // ============================================================
    // CRITICAL: Direct Form Rendering (Fix for Task C)
    // If form_id is in URL and user is authenticated, render form directly
    // ============================================================
    if ($is_participant_logged_in && isset($_GET['form_id']) && !empty($_GET['form_id'])) {
        $form_id = intval($_GET['form_id']);

        // Validate that this form belongs to the study
        // Get study data first to validate form belongs to this study
        global $wpdb;

        if (!empty($study_code)) {
            $study = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}survey_studies WHERE study_code = %s",
                $study_code
            ));
        } elseif ($study_id > 0) {
            $study = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}survey_studies WHERE id = %d",
                $study_id
            ));
        }

        if ($study) {
            $actual_study_id = (int) $study->id;
            if ((int) $current_survey_id !== $actual_study_id) {
                return eipsi_longitudinal_study_error(__('Unauthorized', 'eipsi-forms'), __('Tu sesión pertenece a otro estudio.', 'eipsi-forms'));
            }


            // Check if form_id belongs to a wave in this study
            $form_belongs_to_study = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}survey_waves
                 WHERE study_id = %d AND form_id = %d",
                $actual_study_id,
                $form_id
            ));

            if ($form_belongs_to_study > 0) {
                // GAP 1 (Task C+Step5): Validate assignment window before rendering.
                // available_at lives ONLY on survey_assignments; survey_waves holds
                // just window_minutes. The window is ALWAYS enforced from the assignment.
                $participant_id = EIPSI_Auth_Service::get_current_participant();

                // Find wave that uses this form_id (window config only, no available_at)
                $wave = $wpdb->get_row($wpdb->prepare(
                    "SELECT id, wave_index, window_minutes
                     FROM {$wpdb->prefix}survey_waves
                     WHERE study_id = %d AND form_id = %d",
                    $actual_study_id,
                    $form_id
                ));

                if ($wave && $participant_id) {
                    $is_t1 = (intval($wave->wave_index) === 1);
                    // Check assignment for this participant
                    $wave_assignment = $wpdb->get_row($wpdb->prepare(
                        "SELECT id, study_id, status, available_at, due_at
                         FROM {$wpdb->prefix}survey_assignments
                         WHERE wave_id = %d AND participant_id = %d",
                        $wave->id,
                        $participant_id
                    ));

                    // No assignment for this participant => no window => do NOT render
                    if (!$wave_assignment) {
                        return eipsi_longitudinal_study_error(
                            __('Esta toma no está disponible.', 'eipsi-forms'),
                            __('No tenés una asignación para esta toma.', 'eipsi-forms')
                        );
                    }

                    $temporal = EIPSI_Longitudinal_Assignment_Transition_Service::precheck_submission(
                        $participant_id, $actual_study_id, $wave->id
                    );
                    if (!$temporal['success']) {
                        if (($temporal['data']['code'] ?? '') === 'deadline_passed') {
                            EIPSI_Longitudinal_Assignment_Transition_Service::expire_snapshot($wave_assignment, true);
                        }
                        return eipsi_longitudinal_study_error(
                            __('Esta toma no está disponible.', 'eipsi-forms'), $temporal['data']['message']
                        );
                    }

                }
                
                // Render the form directly
                return eipsi_render_form_shortcode_markup($form_id);
            }
        }
    }
    
    // Check for magic link token in URL for auto-login
    $magic_token = isset($_GET['eipsi_magic']) ? sanitize_text_field($_GET['eipsi_magic']) : '';
    $magic_link_login_success = false; // Track if magic link auto-login succeeded
    
    if (!$is_participant_logged_in && !empty($magic_token)) {
        // Attempt magic link auto-login
        if (class_exists('EIPSI_MagicLinksService')) {
            $validation = EIPSI_MagicLinksService::validate_magic_link($magic_token);
            
            if ($validation['valid'] && EIPSI_MagicLinksService::mark_magic_link_used($validation['ml_id'])) {
                // Token possession and its single-use claim were verified before session creation.
                $session_result = EIPSI_Auth_Service::create_session(
                    $validation['participant_id'],
                    $validation['survey_id']
                );
                
                if ($session_result['success']) {
                    // Update authentication state
                    $is_participant_logged_in = true;
                    $current_participant_id = $validation['participant_id'];
                    $current_survey_id = $validation['survey_id'];
                    $magic_link_login_success = true; // Mark as successful magic link login
                }
            }
        }
    }
    
    // ============================================================
    // LOGIN REDIRECT FLOW (v1.6.0)
    // If not logged in and not an admin, redirect to /login
    // ============================================================
    if (!$is_participant_logged_in && !current_user_can('manage_options') && $requested_view !== 'public' && empty($magic_token)) {
        $current_url = (is_ssl() ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        $login_url = eipsi_get_login_page_url();
        
        // Prevent infinite redirect if shortcode is placed on the login page
        $current_path = trim(wp_parse_url($current_url, PHP_URL_PATH), '/');
        $login_path = trim(wp_parse_url($login_url, PHP_URL_PATH), '/');
        
        if ($current_path !== $login_path) {
            $redirect_url = add_query_arg('redirect_to', urlencode($current_url), $login_url);
            
            // Use JavaScript redirect as headers are likely already sent in a shortcode
            return '<script>window.location.href="' . esc_url($redirect_url) . '";</script>';
        }
    }

    // Now determine view_mode based on authentication and context
    // CRITICAL: Magic link ALWAYS shows participant view, never admin/dashboard
    if ($magic_link_login_success) {
        // Magic link users ALWAYS see participant view
        $view_mode = 'participant';
    } elseif ($requested_view === 'dashboard') {
        // Dashboard view is ONLY for WordPress admins
        if (current_user_can('manage_options')) {
            $view_mode = 'dashboard';
        } else {
            // Non-admins requesting dashboard get redirected to participant view if logged in
            $view_mode = $is_participant_logged_in ? 'participant' : 'public';
        }
    } elseif ($requested_view === 'participant') {
        // Participant view requires authentication
        $view_mode = $is_participant_logged_in ? 'participant' : 'public';
    } elseif ($requested_view === 'public') {
        // Public view always shows login/register
        $view_mode = 'public';
    } else {
        // Auto mode (default): determine based on authentication state
        if ($is_participant_logged_in) {
            $view_mode = 'participant';
        } else {
            $view_mode = 'public';
        }
    }
    
    // Get study data - prefer study_code for security
    global $wpdb;
    
    if (!empty($study_code)) {
        // SECURE: Use study_code (prevents ID guessing)
        $study = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}survey_studies WHERE study_code = %s",
            $study_code
        ));
        
        if (!$study) {
            return eipsi_longitudinal_study_error(
                __('⚠️ Error: No se encontró el estudio con ese código.', 'eipsi-forms'),
                __('Verificá el código del estudio en el panel de administración.', 'eipsi-forms')
            );
        }
    } elseif ($study_id > 0) {
        // BACKWARD COMPATIBILITY: Use numeric ID (less secure)
        $study = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}survey_studies WHERE id = %d",
            $study_id
        ));
        
        if (!$study) {
            return eipsi_longitudinal_study_error(
                __('⚠️ Error: No se encontró el estudio con ese ID.', 'eipsi-forms'),
                __('Ejemplo: [eipsi_longitudinal_study study_code="ESTUDIO_2025"]', 'eipsi-forms')
            );
        }
    } else {
        return eipsi_longitudinal_study_error(
            __('⚠️ Error: Se requiere el código o ID del estudio.', 'eipsi-forms'),
            __('Ejemplo: [eipsi_longitudinal_study study_code="ESTUDIO_2025"]', 'eipsi-forms')
        );
    }
    
    // Check if study is active (or paused - allow viewing paused studies)
    if (!in_array($study->status, array('active', 'paused'))) {
        return eipsi_longitudinal_study_error(
            sprintf(__('ℹ️ El estudio "%s" no está disponible actualmente.', 'eipsi-forms'), esc_html($study->study_name)),
            __('Estado: ' . ucfirst($study->status), 'eipsi-forms')
        );
    }
    
    // Enqueue required assets
    wp_enqueue_style(
        'eipsi-longitudinal-study-css',
        EIPSI_FORMS_PLUGIN_URL . 'assets/css/longitudinal-study-shortcode.css',
        array('eipsi-theme-toggle-css'),
        EIPSI_FORMS_VERSION
    );
    
    wp_enqueue_script(
        'eipsi-longitudinal-study-js',
        EIPSI_FORMS_PLUGIN_URL . 'assets/js/longitudinal-study-shortcode.js',
        array('jquery'),
        EIPSI_FORMS_VERSION,
        true
    );
    
    wp_localize_script('eipsi-longitudinal-study-js', 'eipsiLongitudinalStudyL10n', array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('eipsi_longitudinal_study_nonce'),
        'strings' => array(
            'copied' => __('¡Shortcode copiado!', 'eipsi-forms'),
            'linkCopied' => __('¡Enlace copiado!', 'eipsi-forms'),
            'copyError' => __('Error al copiar', 'eipsi-forms'),
            'shareTitle' => __('Compartir Estudio', 'eipsi-forms'),
            'shareDescription' => __('Copia el shortcode o el enlace para compartir este estudio.', 'eipsi-forms')
        )
    ));
    
    // Get the actual study ID from the fetched study
    $actual_study_id = (int) $study->id;
    if ($is_participant_logged_in && (int) $current_survey_id !== $actual_study_id && $view_mode !== 'dashboard') {
        return eipsi_longitudinal_study_error(__('Unauthorized', 'eipsi-forms'), __('Tu sesión pertenece a otro estudio.', 'eipsi-forms'));
    }

    
    // Get waves for the study
    $waves = array();
    if ($show_waves) {
        if ($wave_index > 0) {
            // Get specific wave
            $waves = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}survey_waves 
                 WHERE study_id = %d AND wave_index = %d 
                 ORDER BY wave_index ASC",
                $actual_study_id,
                $wave_index
            ), ARRAY_A);
        } else {
            // Get all waves
            $waves = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}survey_waves 
                 WHERE study_id = %d 
                 ORDER BY wave_index ASC",
                $actual_study_id
            ), ARRAY_A);
        }
    }

    // Get participant count
    $participant_count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}survey_participants WHERE survey_id = %d",
        $actual_study_id
    ));

    // Get study configuration from JSON
    $study_config = !empty($study->config) ? json_decode($study->config, true) : array();
    
    // Get principal investigator
    $pi_name = '';
    if (!empty($study->principal_investigator_id)) {
        $pi_user = get_userdata($study->principal_investigator_id);
        if ($pi_user) {
            $pi_name = $pi_user->display_name;
        }
    }
    
    // Build shareable URL
    $current_url = get_permalink();
    $shareable_url = add_query_arg(array(
        'eipsi_study' => $study->study_code, // Use study_code for security
        'wave' => $wave_index > 0 ? $wave_index : '',
    ), $current_url);
    
    // Generate the shortcode string for copying (PREFER study_code for security)
    $shortcode_string = '[eipsi_longitudinal_study study_code="' . $study->study_code . '"';
    if ($wave_index > 0) {
        $shortcode_string .= ' wave="' . $wave_index . '"';
    }
    if ($time_limit_override > 0) {
        $shortcode_string .= ' time_limit="' . $time_limit_override . '"';
    }
    $shortcode_string .= ']';
    
    // Render the template
    ob_start();
    include EIPSI_FORMS_PLUGIN_DIR . 'includes/templates/longitudinal-study-display.php';
    $output = ob_get_clean();
    
    // Add JavaScript to clean URL after successful magic link login
    // This removes the token from the URL for security and cleaner UX
    if ($magic_link_login_success) {
        $clean_url = remove_query_arg('eipsi_magic', get_permalink());
        $output .= '<script>
            if (window.history && window.history.replaceState) {
                window.history.replaceState({}, document.title, "' . esc_url($clean_url) . '");
            }
        </script>';
    }
    
    return $output;
}
add_shortcode('eipsi_longitudinal_study', 'eipsi_longitudinal_study_shortcode');

/**
 * Generate error notice for longitudinal study shortcode
 * 
 * @param string $message Error message
 * @param string $help Optional help text
 * @return string HTML error notice
 */
function eipsi_longitudinal_study_error($message, $help = '') {
    $output = '<div class="eipsi-longitudinal-study-error" style="background: #ffebee; border-left: 4px solid #f44336; padding: 1rem; margin: 1rem 0; border-radius: 4px;">';
    $output .= '<p style="margin: 0; color: #c62828; font-weight: 500;">' . esc_html($message) . '</p>';
    if (!empty($help)) {
        $output .= '<p style="margin: 0.5rem 0 0 0; color: #d32f2f; font-size: 0.9rem;">' . esc_html($help) . '</p>';
    }
    $output .= '</div>';
    return $output;
}

/**
 * Helper function to get form title by ID
 * 
 * @param int $form_id Form post ID
 * @return string Form title or fallback
 */
function eipsi_get_form_title($form_id) {
    $form_post = get_post($form_id);
    if ($form_post) {
        return $form_post->post_title;
    }
    return __('Formulario no disponible', 'eipsi-forms');
}

/**
 * Helper function to format time limit
 * 
 * @param int $minutes Time limit in minutes
 * @return string Formatted time string
 */
function eipsi_format_time_limit($minutes) {
    if ($minutes <= 0) {
        return __('Ilimitado', 'eipsi-forms');
    }
    if ($minutes < 60) {
        return sprintf(__('%d minutos', 'eipsi-forms'), $minutes);
    }
    $hours = floor($minutes / 60);
    $mins = $minutes % 60;
    if ($mins === 0) {
        return sprintf(__('%d horas', 'eipsi-forms'), $hours);
    }
    return sprintf(__('%d horas %d minutos', 'eipsi-forms'), $hours, $mins);
}

/**
 * Helper function to get wave status label
 * 
 * @param string $status Wave status
 * @return string Status label
 */
function eipsi_get_wave_status_label($status) {
    $labels = array(
        'draft' => __('Borrador', 'eipsi-forms'),
        'active' => __('Activa', 'eipsi-forms'),
        'completed' => __('Completada', 'eipsi-forms'),
        'paused' => __('Pausada', 'eipsi-forms'),
    );
    return isset($labels[$status]) ? $labels[$status] : ucfirst($status);
}

/**
 * Add longitudinal study shortcode help to metabox
 * 
 * @since 1.5.0
 * @since 1.6.0 - Updated to use study_code for security
 */
function eipsi_add_longitudinal_study_to_metabox($post) {
    global $wpdb;

    // Get available longitudinal studies
    $studies = $wpdb->get_results(
        "SELECT id, study_name, study_code FROM {$wpdb->prefix}survey_studies
         WHERE status IN ('active', 'paused')
         ORDER BY created_at DESC"
    );

    if (!empty($studies)) {
        ?>
        <hr style="margin: 20px 0; border: none; border-top: 1px solid #ddd;">

        <p style="font-size: 13px; margin: 0 0 12px; font-weight: 600;">
            <?php esc_html_e('Estudios Longitudinales disponibles:', 'eipsi-forms'); ?>
        </p>

        <div style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; border-radius: 3px;">
            <?php foreach ($studies as $study) :
                $secure_shortcode = '[eipsi_longitudinal_study study_code="' . esc_attr($study->study_code) . '"]';
            ?>
                <div style="padding: 8px; border-bottom: 1px solid #f0f0f1; font-size: 12px;">
                    <div style="font-weight: 600; margin-bottom: 4px;">
                        <?php echo esc_html($study->study_name); ?>
                        <span style="color: #666; font-weight: normal;">(<?php echo esc_html($study->study_code); ?>)</span>
                    </div>
                    <!-- SECURE SHORTCODE -->
                    <code style="background: #e3f2fd; padding: 2px 6px; border-radius: 2px; font-size: 11px; cursor: pointer; border: 1px solid #2196f3; color: #1565c0;"
                          onclick="navigator.clipboard.writeText(<?php echo wp_json_encode($secure_shortcode); ?>); this.style.background='#00a32a'; this.style.color='white'; this.style.borderColor='#00a32a'; setTimeout(() => { this.style.background='#e3f2fd'; this.style.color='#1565c0'; this.style.borderColor='#2196f3'; }, 1000);"
                          title="<?php esc_attr_e('Clic para copiar shortcode seguro', 'eipsi-forms'); ?>">
                        🔒 [eipsi_longitudinal_study study_code="<?php echo esc_html($study->study_code); ?>"]
                    </code>
                </div>
            <?php endforeach; ?>
        </div>

        <p style="font-size: 11px; color: #666; margin: 8px 0 0;">
            <em>
                <strong><?php esc_html_e('🔒 Nuevo formato seguro:', 'eipsi-forms'); ?></strong>
                <?php esc_html_e('Usá study_code en lugar de ID para mayor seguridad.', 'eipsi-forms'); ?><br>
                <?php esc_html_e('Atributos opcionales: wave="1", time_limit="30", show_config="no", view="participant"', 'eipsi-forms'); ?>
            </em>
        </p>
        <?php
    }
}
add_action('eipsi_shortcode_help_metabox_content', 'eipsi_add_longitudinal_study_to_metabox', 10, 1);
