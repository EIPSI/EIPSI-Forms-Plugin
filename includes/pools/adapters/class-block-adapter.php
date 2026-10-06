<?php
/**
 * EIPSI Forms - Pool Block Renderer
 *
 * Renderiza el bloque Gutenberg "Pool de Estudios" y el shortcode [eipsi_pool].
 * Maneja la asignación de participantes a estudios longitudinales.
 *
 * @package EIPSI_Forms
 * @since 2.5.3
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Clase que renderiza el bloque Pool y el shortcode [eipsi_pool].
 */
class EIPSI_Pool_Block_Adapter {

    /**
     * Inicializar el shortcode y bloque.
     */
    

    /**
     * Render callback para el bloque Gutenberg.
     *
     * @param array $attributes Atributos del bloque.
     * @return string HTML renderizado.
     */
    public static function render_block($attributes) {
        $pool_id = sanitize_text_field($attributes['poolId'] ?? '');
        $method = sanitize_text_field($attributes['method'] ?? 'seeded');
        $button_text = sanitize_text_field($attributes['buttonText'] ?? __('Unirse al estudio', 'eipsi-forms'));
        $redirect_mode = sanitize_text_field($attributes['redirectMode'] ?? 'auto');

        if (!$pool_id) {
            return '<p class="eipsi-pool-error">' . esc_html__('Configurá el bloque seleccionando estudios.', 'eipsi-forms') . '</p>';
        }

        return self::render_pool_content($pool_id, $method, $button_text, $redirect_mode);
    }

    /**
     * Shortcode [eipsi_pool pool_id="X"]
     *
     * @param array $atts Atributos del shortcode.
     * @return string HTML renderizado.
     */
    public static function render_shortcode($atts) {
        // No renderizar en el panel admin
        if (is_admin()) {
            return '';
        }

        $atts = shortcode_atts(
            array(
                'pool_id'       => 0,
                'method'        => 'seeded',
                'button_text'   => __('Unirse al estudio', 'eipsi-forms'),
                'redirect_mode' => 'auto',
            ),
            $atts,
            'eipsi_pool'
        );

        $pool_id = absint($atts['pool_id']);
        $method = sanitize_text_field($atts['method']);
        $button_text = sanitize_text_field($atts['button_text']);
        $redirect_mode = sanitize_text_field($atts['redirect_mode']);

        if (!$pool_id) {
            return sprintf(
                '<p class="eipsi-pool-error">%s</p>',
                esc_html__('Error: pool_id no especificado.', 'eipsi-forms')
            );
        }

        return self::render_pool_content($pool_id, $method, $button_text, $redirect_mode);
    }

    /**
     * Encolar assets necesarios para el pool (login, dashboard, asignación).
     *
     * @since 2.3.0
     */
    private static function enqueue_pool_assets() {
        // Verificar si ya fueron encolados
        if ( wp_script_is( 'eipsi-pool-join', 'enqueued' ) ) {
            return;
        }

        // Encolar jQuery (ya debería estar, pero por si acaso)
        wp_enqueue_script( 'jquery' );

        // Encolar el JS del pool
        wp_enqueue_script(
            'eipsi-pool-join',
            plugin_dir_url( __DIR__ ) . 'assets/js/eipsi-pool-join.js',
            array( 'jquery' ),
            '2.3.0',
            true
        );

        // Localizar variables para AJAX
        wp_localize_script(
            'eipsi-pool-join',
            'eipsiPoolJoin',
            array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'eipsi_pool_access' ),
            )
        );
    }

    /**
     * Shortcode de compatibilidad [eipsi_pool_join pool_id="X"]
     * Alias del shortcode nuevo para mantener backward compatibility.
     *
     * @param array $atts Atributos del shortcode.
     * @return string HTML renderizado.
     */
    public static function render_shortcode_compat($atts) {
        if (is_admin()) {
            return '';
        }

        $atts = shortcode_atts(
            array(
                'pool_id'       => 0,
                'show_name'     => '0',
                'redirect'      => '1',
                'button_text'   => '',  // Atributo viejo
                'button_label'  => __('Comenzar mi participación', 'eipsi-forms'), // Atributo viejo alternativo
            ),
            $atts,
            'eipsi_pool_join'
        );

        $pool_id = absint($atts['pool_id']);
        if (!$pool_id) {
            return sprintf(
                '<p class="eipsi-pool-error">%s</p>',
                esc_html__('Error: pool_id no especificado.', 'eipsi-forms')
            );
        }

        // Determinar el texto del botón (soporta ambos atributos viejos)
        $button_text = !empty($atts['button_text'])
            ? $atts['button_text']
            : $atts['button_label'];

        // Convertir a atributos del nuevo formato
        $new_atts = array(
            'pool_id'       => $pool_id,
            'method'        => 'seeded',
            'button_text'   => sanitize_text_field($button_text),
            'redirect_mode' => filter_var($atts['redirect'], FILTER_VALIDATE_BOOLEAN) ? 'auto' : 'embed',
        );

        return self::render_shortcode($new_atts);
    }

    /**
     * Renderizar el contenido del pool.
     *
     * @param int    $pool_id       ID del pool.
     * @param string $method        Método de asignación.
     * @param string $button_text   Texto del botón.
     * @param string $redirect_mode Modo de redirección.
     * @return string HTML renderizado.
     */
    private static function render_pool_content($pool_id, $method, $button_text, $redirect_mode) {
        global $wpdb;
        
        error_log("[EIPSI POOL] === INICIO render_pool_content === pool_id={$pool_id}, method={$method}");

        // Encolar assets necesarios para el pool (login, dashboard, etc.)
        self::enqueue_pool_assets();

        // Verificar que el pool existe y está activo
        $pool = self::get_pool_info($pool_id);
        error_log("[EIPSI POOL] Pool info: " . ($pool ? "ENCONTRADO (status={$pool->status})" : "NO ENCONTRADO"));
        if (!$pool) {
            return sprintf(
                '<div class="eipsi-pool-wrapper"><div class="eipsi-pool-error">' .
                '<div class="eipsi-pool-error-icon">⚠️</div>' .
                '<div class="eipsi-pool-error-message">%s</div></div></div>',
                esc_html__('El pool solicitado no existe o no está disponible.', 'eipsi-forms')
            );
        }

        // Verificar si el pool está pausado
        if ($pool->status === 'paused') {
            return sprintf(
                '<div class="eipsi-pool-wrapper"><div class="eipsi-pool-paused">' .
                '<div class="eipsi-pool-paused-icon">⏸️</div>' .
                '<div class="eipsi-pool-paused-message">%s</div></div></div>',
                esc_html__('Este pool está temporalmente pausado.', 'eipsi-forms')
            );
        }

        // Verificar autenticación del participante
        $participant_id = 0;
        $current_study_id = 0;
        
        if (class_exists('EIPSI_Auth_Service')) {
            $participant_id = EIPSI_Auth_Service::get_current_participant();
            $current_study_id = EIPSI_Auth_Service::get_current_survey();
        }
        
        error_log("[EIPSI POOL] Auth check: participant_id={$participant_id}, current_study_id={$current_study_id}");

        // Si no está autenticado, mostrar formulario de login
        if (!$participant_id) {
            error_log("[EIPSI POOL] Usuario NO autenticado → mostrando login");
            return self::render_login_required($pool_id);
        }
        
        error_log("[EIPSI POOL] Usuario autenticado: participant_id={$participant_id}");

        // Verificar si ya tiene asignación existente
        error_log("[EIPSI POOL] Verificando asignación existente...");
        $assignment = self::get_existing_assignment($pool_id, $participant_id);
        
        if ($assignment) {
            error_log("[EIPSI POOL] Asignación EXISTENTE encontrada: study_id={$assignment['study_id']}, completed=" . ($assignment['completed'] ? 'SÍ' : 'NO'));
        } else {
            error_log("[EIPSI POOL] NO tiene asignación existente");
        }

        // Si ya tiene asignación y está completada
        if ($assignment && $assignment['completed']) {
            return sprintf(
                '<div class="eipsi-pool-wrapper"><div class="eipsi-pool-completed">' .
                '<div class="eipsi-pool-completed-icon">✅</div>' .
                '<div class="eipsi-pool-completed-message">%s</div></div></div>',
                esc_html__('¡Ya completaste tu participación en este pool!', 'eipsi-forms')
            );
        }

        // Si ya tiene asignación activa, mostrar estado
        if ($assignment) {
            return self::render_assigned_state($assignment, $button_text, $redirect_mode);
        }

        // No tiene asignación - mostrar dashboard del pool para solicitar asignación
        return self::render_pool_dashboard($pool_id, $participant_id, $button_text);
    }

    /**
     * Obtener información del pool.
     *
     * @param int $pool_id ID del pool.
     * @return object|null Datos del pool o null si no existe.
     */
    private static function get_pool_info($pool_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'eipsi_longitudinal_pools';

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE id = %d",
                $pool_id
            )
        );
    }

    /**
     * Obtener o crear asignación de participante usando el servicio.
     *
     * @param int    $pool_id       ID del pool.
     * @param int    $participant_id ID del participante.
     * @param string $method        Método de asignación.
     * @return array|null Datos de la asignación o null.
     */
    

    /**
     * Obtener asignación EXISTENTE del participante (sin crear nueva).
     *
     * @param int $pool_id        ID del pool.
     * @param int $participant_id ID del participante.
     * @return array|null Datos de la asignación o null si no existe.
     */
    private static function get_existing_assignment($pool_id, $participant_id) {
        global $wpdb;
        $assignments_table = $wpdb->prefix . 'eipsi_pool_assignments';
        $service = new EIPSI_Pool_Assignment_Service();

        error_log("[EIPSI POOL] Buscando asignación existente: pool_id={$pool_id}, participant_id={$participant_id}");

        $assignment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$assignments_table} 
             WHERE pool_id = %d AND participant_id = %d
             ORDER BY assigned_at DESC
             LIMIT 1",
            $pool_id,
            $participant_id
        ));

        if (!$assignment) {
            error_log("[EIPSI POOL] No se encontró asignación existente");
            return null;
        }

        error_log("[EIPSI POOL] Asignación encontrada: assignment_id={$assignment->id}, study_id={$assignment->study_id}, completed={$assignment->completed}");

        return array(
            'study_id'    => $assignment->study_id,
            'is_existing' => true,
            'completed'   => $assignment->completed,
            'study_url'   => $service->get_study_url($assignment->study_id),
        );
    }

    /**
     * Renderizar estado de asignación existente.
     *
     * @param array  $assignment   Datos de la asignación.
     * @param string $button_text  Texto del botón.
     * @param string $redirect_mode Modo de redirección.
     * @return string HTML.
     */
    private static function render_assigned_state($assignment, $button_text, $redirect_mode) {
        $study_name = self::get_study_name($assignment['study_id']);

        // Si es modo auto, redirigir inmediatamente
        if ($redirect_mode === 'auto' && !empty($assignment['study_url'])) {
            wp_redirect($assignment['study_url']);
            exit;
        }

        return sprintf(
            '<div class="eipsi-pool-wrapper">
                <div class="eipsi-pool-assigned">
                    <div class="eipsi-pool-assigned-badge">
                        <span>✓</span> %s
                    </div>
                    <div class="eipsi-pool-study-name">%s</div>
                    <div class="eipsi-pool-status">%s</div>
                    <a href="%s" class="eipsi-pool-join-button" style="margin-top: 1rem; display: inline-block;">
                        %s
                    </a>
                </div>
            </div>',
            esc_html__('Ya tenés un estudio asignado', 'eipsi-forms'),
            esc_html($study_name),
            esc_html__('Hacé clic para continuar con tu estudio', 'eipsi-forms'),
            esc_url($assignment['study_url']),
            esc_html($button_text)
        );
    }

    /**
     * Renderizar botón para unirse.
     *
     * @param array  $assignment   Datos de la asignación.
     * @param string $button_text  Texto del botón.
     * @param string $redirect_mode Modo de redirección.
     * @return string HTML.
     */
    private static function render_join_button($assignment, $button_text, $redirect_mode) {
        // Si es modo auto, redirigir inmediatamente
        if ($redirect_mode === 'auto' && !empty($assignment['study_url'])) {
            wp_redirect($assignment['study_url']);
            exit;
        }

        $study_name = self::get_study_name($assignment['study_id']);

        return sprintf(
            '<div class="eipsi-pool-wrapper">
                <div class="eipsi-pool-join">
                    <p style="margin-bottom: 1rem;">%s <strong>%s</strong></p>
                    <a href="%s" class="eipsi-pool-join-button">
                        %s
                    </a>
                </div>
            </div>',
            esc_html__('Te hemos asignado al estudio:', 'eipsi-forms'),
            esc_html($study_name),
            esc_url($assignment['study_url']),
            esc_html($button_text)
        );
    }

    /**
     * Renderizar login requerido.
     *
     * @param int $pool_id ID del pool.
     * @return string HTML.
     */
    private static function render_login_required($pool_id) {
        global $wpdb;
        $pools_table = $wpdb->prefix . 'eipsi_longitudinal_pools';

        // Get pool data
        $pool = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$pools_table} WHERE id = %d",
            $pool_id
        ), ARRAY_A);

        if (!$pool) {
            return '<div class="eipsi-pool-wrapper"><p>' . esc_html__('Pool no encontrado.', 'eipsi-forms') . '</p></div>';
        }

        $pool_name = $pool['name'] ?? 'Pool';
        $description = $pool['description'] ?? '';
        $incentive = $pool['incentive'] ?? '';

        // Pool-specific login template (different from survey login)
        ob_start();
        ?>
        <div class="eipsi-pool-wrapper">
            <div class="eipsi-pool-access-container">
                <div class="eipsi-pool-header">
                    <div class="eipsi-pool-icon">🏊</div>
                    <h2 class="eipsi-pool-title"><?php echo esc_html($pool_name); ?></h2>
                    <?php if ($description): ?>
                        <p class="eipsi-pool-description"><?php echo esc_html($description); ?></p>
                    <?php endif; ?>
                    <?php if ($incentive): ?>
                        <div class="eipsi-pool-incentive">
                            <span class="incentive-icon">🎁</span>
                            <span class="incentive-text"><?php echo esc_html($incentive); ?></span>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="eipsi-pool-login-box">
                    <!-- Header -->
                    <div class="eipsi-pool-login-header">
                        <h3><?php esc_html_e('Acceder al Pool de Estudios', 'eipsi-forms'); ?></h3>
                        <p class="eipsi-pool-login-subtitle"><?php esc_html_e('Ingresá con tu email o creá una cuenta para participar.', 'eipsi-forms'); ?></p>
                    </div>
                    
                    <!-- Tabs -->
                    <div class="eipsi-pool-login-tabs">
                        <button type="button" class="eipsi-pool-tab active" data-tab="login">
                            <?php esc_html_e('Ingresar', 'eipsi-forms'); ?>
                        </button>
                        <button type="button" class="eipsi-pool-tab" data-tab="register">
                            <?php esc_html_e('Crear cuenta', 'eipsi-forms'); ?>
                        </button>
                    </div>
                    
                    <!-- Login Pane -->
                    <div class="eipsi-pool-tab-content active" data-pane="login">
                        <form id="eipsi-pool-login-form-<?php echo esc_attr($pool_id); ?>" 
                              class="eipsi-pool-auth-form eipsi-pool-login-form" 
                              data-pool-id="<?php echo esc_attr($pool_id); ?>"
                              data-action="login">
                            
                            <div class="eipsi-pool-form-group">
                                <label for="pool-login-email-<?php echo esc_attr($pool_id); ?>">
                                    <?php esc_html_e('Tu email', 'eipsi-forms'); ?>
                                    <span class="required">*</span>
                                </label>
                                <input type="email" 
                                       id="pool-login-email-<?php echo esc_attr($pool_id); ?>" 
                                       name="email" 
                                       required
                                       placeholder="<?php esc_attr_e('tu@email.com', 'eipsi-forms'); ?>"
                                       autocomplete="email">
                            </div>
                            
                            <button type="submit" class="eipsi-pool-submit-btn">
                                <span class="btn-text"><?php esc_html_e('Ingresar', 'eipsi-forms'); ?></span>
                                <span class="btn-spinner" style="display:none;">⏳</span>
                            </button>
                            
                            <p class="eipsi-pool-form-footer">
                                <?php esc_html_e('¿No tenés cuenta?', 'eipsi-forms'); ?> 
                                <a href="#" class="eipsi-pool-switch-tab" data-target="register"><?php esc_html_e('Creá una aquí', 'eipsi-forms'); ?></a>
                            </p>
                        </form>
                    </div>
                    
                    <!-- Register Pane -->
                    <div class="eipsi-pool-tab-content" data-pane="register">
                        <form id="eipsi-pool-register-form-<?php echo esc_attr($pool_id); ?>" 
                              class="eipsi-pool-auth-form eipsi-pool-register-form" 
                              data-pool-id="<?php echo esc_attr($pool_id); ?>"
                              data-action="register">
                            
                            <div class="eipsi-pool-form-group">
                                <label for="pool-register-email-<?php echo esc_attr($pool_id); ?>">
                                    <?php esc_html_e('Tu email', 'eipsi-forms'); ?>
                                    <span class="required">*</span>
                                </label>
                                <input type="email" 
                                       id="pool-register-email-<?php echo esc_attr($pool_id); ?>" 
                                       name="email" 
                                       required
                                       placeholder="<?php esc_attr_e('tu@email.com', 'eipsi-forms'); ?>"
                                       autocomplete="email">
                                <small class="field-hint"><?php esc_html_e('Te enviaremos un enlace de confirmación.', 'eipsi-forms'); ?></small>
                            </div>
                            
                            <div class="eipsi-pool-form-options">
                                <label class="eipsi-pool-checkbox-label">
                                    <input type="checkbox" name="accept_terms" value="1" required>
                                    <span><?php 
                                        printf(
                                            wp_kses(
                                                __('Acepto los <a href="%1$s" target="_blank">términos y condiciones</a> y la <a href="%2$s" target="_blank">política de privacidad</a>', 'eipsi-forms'),
                                                array('a' => array('href' => array(), 'target' => array()))
                                            ),
                                            esc_url(get_privacy_policy_url() ?: '#'),
                                            esc_url(get_privacy_policy_url() ?: '#')
                                        );
                                    ?></span>
                                </label>
                            </div>
                            
                            <button type="submit" class="eipsi-pool-submit-btn">
                                <span class="btn-text"><?php esc_html_e('Crear cuenta', 'eipsi-forms'); ?></span>
                                <span class="btn-spinner" style="display:none;">⏳</span>
                            </button>
                            
                            <p class="eipsi-pool-form-footer">
                                <?php esc_html_e('¿Ya tenés cuenta?', 'eipsi-forms'); ?> 
                                <a href="#" class="eipsi-pool-switch-tab" data-target="login"><?php esc_html_e('Ingresá aquí', 'eipsi-forms'); ?></a>
                            </p>
                        </form>
                    </div>
                    
                    <!-- Messages -->
                    <div class="eipsi-pool-login-message" style="display:none;" role="alert"></div>
                    
                    <!-- Success State (hidden initially) -->
                    <div class="eipsi-pool-success-state" style="display:none;" role="status">
                        <div class="eipsi-pool-success-icon">✓</div>
                        <h4><?php esc_html_e('¡Listo!', 'eipsi-forms'); ?></h4>
                        <p class="eipsi-pool-success-text"></p>
                        <a href="#" class="eipsi-pool-redirect-link" style="display:none;">
                            <?php esc_html_e('Ir a mi estudio →', 'eipsi-forms'); ?>
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <style>
            .eipsi-pool-access-container {
                max-width: 600px;
                margin: 0 auto;
                padding: 2rem 1rem;
            }
            .eipsi-pool-header {
                text-align: center;
                margin-bottom: 2rem;
            }
            .eipsi-pool-icon {
                font-size: 48px;
                margin-bottom: 0.5rem;
            }
            .eipsi-pool-title {
                font-size: 24px;
                font-weight: 600;
                color: #1e293b;
                margin: 0 0 1rem 0;
            }
            .eipsi-pool-description {
                font-size: 16px;
                color: #64748b;
                margin: 0 0 1rem 0;
                line-height: 1.5;
            }
            .eipsi-pool-incentive {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                background: #fef3c7;
                padding: 12px 16px;
                border-radius: 8px;
                margin-top: 1rem;
            }
            .eipsi-pool-incentive .incentive-icon {
                font-size: 20px;
            }
            .eipsi-pool-incentive .incentive-text {
                font-size: 14px;
                color: #92400e;
                font-weight: 500;
            }
            .eipsi-pool-login-box {
                background: #ffffff;
                border: 1px solid #e2e8f0;
                border-radius: 12px;
                padding: 2rem;
                box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            }
            .eipsi-pool-login-box h3 {
                font-size: 18px;
                font-weight: 600;
                color: #1e293b;
                margin: 0 0 0.5rem 0;
                text-align: center;
            }
            .eipsi-pool-login-subtitle {
                font-size: 14px;
                color: #64748b;
                margin: 0 0 1.5rem 0;
                text-align: center;
            }
            .eipsi-pool-form-group {
                margin-bottom: 1.25rem;
            }
            .eipsi-pool-form-group label {
                display: block;
                font-size: 14px;
                font-weight: 500;
                color: #374151;
                margin-bottom: 0.5rem;
            }
            .eipsi-pool-form-group .required {
                color: #ef4444;
                margin-left: 2px;
            }
            .eipsi-pool-form-group input[type="email"] {
                width: 100%;
                padding: 0.75rem 1rem;
                font-size: 16px;
                border: 1px solid #d1d5db;
                border-radius: 8px;
                box-sizing: border-box;
                transition: border-color 0.2s, box-shadow 0.2s;
            }
            .eipsi-pool-form-group input[type="email"]:focus {
                outline: none;
                border-color: #3b82f6;
                box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
            }
            .eipsi-pool-form-group .field-hint {
                display: block;
                font-size: 12px;
                color: #6b7280;
                margin-top: 0.375rem;
            }
            .eipsi-pool-checkbox-label {
                display: flex;
                align-items: flex-start;
                gap: 0.5rem;
                font-size: 13px;
                color: #4b5563;
                cursor: pointer;
            }
            .eipsi-pool-checkbox-label input {
                margin-top: 2px;
            }
            .eipsi-pool-checkbox-label a {
                color: #3b82f6;
                text-decoration: underline;
            }
            .eipsi-pool-submit-btn {
                width: 100%;
                padding: 0.875rem 1.5rem;
                margin-top: 1rem;
                font-size: 16px;
                font-weight: 600;
                color: #ffffff;
                background: #3b82f6;
                border: none;
                border-radius: 8px;
                cursor: pointer;
                transition: background 0.2s, transform 0.1s;
            }
            .eipsi-pool-submit-btn:hover {
                background: #2563eb;
            }
            .eipsi-pool-submit-btn:active {
                transform: translateY(1px);
            }
            .eipsi-pool-submit-btn:disabled {
                opacity: 0.7;
                cursor: not-allowed;
            }
            .eipsi-pool-login-message {
                margin-top: 1rem;
                padding: 0.875rem 1rem;
                border-radius: 8px;
                font-size: 14px;
                text-align: center;
            }
            .eipsi-pool-login-message.success {
                background: #d1fae5;
                color: #065f46;
                border: 1px solid #a7f3d0;
            }
            .eipsi-pool-login-message.error {
                background: #fee2e2;
                color: #991b1b;
                border: 1px solid #fecaca;
            }
            /* Login Form Specific Styles */
            .eipsi-pool-login-header {
                text-align: center;
                margin-bottom: 1.5rem;
            }
            .eipsi-pool-login-header h3 {
                margin: 0 0 0.5rem 0;
                font-size: 1.25rem;
                color: #1e293b;
            }
            .eipsi-pool-login-tabs {
                display: flex;
                gap: 0.5rem;
                margin-bottom: 1.5rem;
                border-bottom: 2px solid #e5e7eb;
            }
            .eipsi-pool-tab {
                flex: 1;
                padding: 0.75rem 1rem;
                font-size: 14px;
                font-weight: 500;
                color: #6b7280;
                background: none;
                border: none;
                border-bottom: 2px solid transparent;
                cursor: pointer;
                transition: all 0.2s;
            }
            .eipsi-pool-tab.active {
                color: #3b82f6;
                border-bottom-color: #3b82f6;
            }
            .eipsi-pool-tab:hover:not(.active) {
                color: #4b5563;
                background: #f9fafb;
            }
            .eipsi-pool-tab-content {
                display: none;
            }
            .eipsi-pool-tab-content.active {
                display: block;
            }
            .eipsi-pool-form-group {
                margin-bottom: 1.25rem;
            }
            .eipsi-pool-form-group label {
                display: block;
                font-size: 14px;
                font-weight: 500;
                color: #374151;
                margin-bottom: 0.5rem;
            }
            .eipsi-pool-form-group .required {
                color: #ef4444;
                margin-left: 2px;
            }
            .eipsi-pool-form-group input[type="email"] {
                width: 100%;
                padding: 0.75rem 1rem;
                font-size: 16px;
                border: 1px solid #d1d5db;
                border-radius: 8px;
                box-sizing: border-box;
                transition: border-color 0.2s, box-shadow 0.2s;
            }
            .eipsi-pool-form-group input[type="email"]:focus {
                outline: none;
                border-color: #3b82f6;
                box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
            }
            .eipsi-pool-form-group .field-hint {
                display: block;
                font-size: 12px;
                color: #6b7280;
                margin-top: 0.375rem;
            }
            .eipsi-pool-checkbox-label {
                display: flex;
                align-items: flex-start;
                gap: 0.5rem;
                font-size: 13px;
                color: #4b5563;
                cursor: pointer;
            }
            .eipsi-pool-checkbox-label input {
                margin-top: 2px;
            }
            .eipsi-pool-checkbox-label a {
                color: #3b82f6;
                text-decoration: underline;
            }
            .eipsi-pool-form-footer {
                margin-top: 1rem;
                text-align: center;
                font-size: 13px;
                color: #6b7280;
            }
            .eipsi-pool-form-footer a {
                color: #3b82f6;
                text-decoration: none;
            }
            .eipsi-pool-form-footer a:hover {
                text-decoration: underline;
            }
            /* Success State */
            .eipsi-pool-success-state {
                text-align: center;
                padding: 2rem;
            }
            .eipsi-pool-success-icon {
                width: 64px;
                height: 64px;
                margin: 0 auto 1rem;
                background: #10b981;
                color: white;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 32px;
            }
            .eipsi-pool-success-state h4 {
                margin: 0 0 0.5rem 0;
                font-size: 1.25rem;
                color: #1e293b;
            }
            .eipsi-pool-success-text {
                color: #6b7280;
                margin-bottom: 1.5rem;
            }
            .eipsi-pool-redirect-link {
                display: inline-block;
                padding: 0.75rem 1.5rem;
                background: #3b82f6;
                color: white;
                text-decoration: none;
                border-radius: 8px;
                font-weight: 500;
                transition: background 0.2s;
            }
            .eipsi-pool-redirect-link:hover {
                background: #2563eb;
            }
            /* Pool Dashboard */
            .eipsi-pool-dashboard {
                max-width: 600px;
                margin: 0 auto;
                padding: 2rem;
            }
            .eipsi-pool-dashboard-content {
                margin-top: 2rem;
            }
            .eipsi-pool-status-box {
                background: #f8fafc;
                border: 1px solid #e5e7eb;
                border-radius: 12px;
                padding: 1.5rem;
                margin-bottom: 1.5rem;
                text-align: center;
            }
            .eipsi-pool-status-icon {
                font-size: 2.5rem;
                margin-bottom: 0.5rem;
            }
            .eipsi-pool-status-box h3 {
                margin: 0 0 0.75rem 0;
                font-size: 1.25rem;
                color: #1e293b;
            }
            .eipsi-pool-status-box p {
                margin: 0;
                color: #6b7280;
                line-height: 1.5;
            }
            .eipsi-pool-request-assignment-btn {
                width: 100%;
                padding: 1rem 1.5rem;
                background: linear-gradient(135deg, #3b82f6, #2563eb);
                color: white;
                border: none;
                border-radius: 10px;
                font-size: 1.1rem;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.2s;
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 0.5rem;
            }
            .eipsi-pool-request-assignment-btn:hover {
                background: linear-gradient(135deg, #2563eb, #1d4ed8);
                transform: translateY(-1px);
                box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
            }
            .eipsi-pool-request-assignment-btn:disabled {
                background: #94a3b8;
                cursor: not-allowed;
                transform: none;
                box-shadow: none;
            }
            .eipsi-pool-assignment-message {
                margin-top: 1rem;
                padding: 1rem;
                border-radius: 8px;
                text-align: center;
                display: none;
            }
            .eipsi-pool-assignment-message.success {
                background: #d1fae5;
                color: #065f46;
                border: 1px solid #a7f3d0;
            }
            .eipsi-pool-assignment-message.error {
                background: #fee2e2;
                color: #991b1b;
                border: 1px solid #fecaca;
            }
        </style>
        <?php
        return ob_get_clean();
    }

    /**
     * Obtener nombre del estudio.
     *
     * @param int $study_id ID del estudio.
     * @return string Nombre del estudio.
     */
    private static function get_study_name($study_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'survey_studies';

        $name = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT study_name FROM {$table} WHERE id = %d",
                $study_id
            )
        );

        return $name ?: sprintf(__('Estudio #%d', 'eipsi-forms'), $study_id);
    }
    private static function render_pool_dashboard($pool_id, $participant_id, $button_text) {
        wp_enqueue_script('eipsi-pool-join', EIPSI_FORMS_PLUGIN_URL.'assets/js/eipsi-pool-join.js', array('jquery'), EIPSI_FORMS_VERSION, true);
        wp_localize_script('eipsi-pool-join', 'eipsiPoolJoin', array('ajaxUrl'=>admin_url('admin-ajax.php'), 'nonce'=>wp_create_nonce('eipsi_pool_access')));
        return '<div class="eipsi-pool-dashboard-content"><button type="button" class="eipsi-pool-request-assignment-btn" data-pool-id="'.esc_attr($pool_id).'" data-participant-id="'.esc_attr($participant_id).'">'.esc_html($button_text).'</button><div class="eipsi-pool-assignment-message"></div></div>';
    }

}
