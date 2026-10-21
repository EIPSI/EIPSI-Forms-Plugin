<?php
/**
 * EIPSI Forms - Shortcode [eipsi_pool_join]
 *
 * Permite a participantes unirse a un pool de asignación aleatoria
 * directamente desde cualquier página o entrada de WordPress.
 *
 * Uso: [eipsi_pool_join pool_id="123"]
 * Uso con redirect: [eipsi_pool_join pool_id="123" redirect="1" show_name="1"]
 *
 * Atributos:
 *   - pool_id    (requerido): ID del pool al que unirse.
 *   - show_name  (opcional, default "0"): Mostrar campo de nombre.
 *   - redirect   (opcional, default "1"): Redirigir automáticamente al magic link.
 *   - button_label (opcional): Texto del botón de envío.
 *
 * @package EIPSI_Forms
 * @since 2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Clase que encapsula el shortcode [eipsi_pool_join].
 */
class EIPSI_Pool_Shortcode_Adapter {

    /**
     * Registrar el shortcode y encolar assets.
     */
    

    /**
     * Encolar assets solo cuando el shortcode está presente en la página.
     */
    public static function maybe_enqueue_assets() {
        global $post;

        if ( ! is_singular() || ! $post ) {
            return;
        }

        if ( has_shortcode( $post->post_content, 'eipsi_pool_join' ) ) {
            self::enqueue_assets();
        }
    }

    /**
     * Encolar JS y CSS del formulario de pool join.
     */
    public static function enqueue_assets() {
        $plugin_url = defined( 'EIPSI_FORMS_PLUGIN_URL' ) ? EIPSI_FORMS_PLUGIN_URL : plugin_dir_url( __FILE__ ) . '../../';
        $plugin_dir = defined( 'EIPSI_FORMS_PLUGIN_DIR' ) ? EIPSI_FORMS_PLUGIN_DIR : plugin_dir_path( __FILE__ ) . '../../';
        $version    = defined( 'EIPSI_FORMS_VERSION' ) ? EIPSI_FORMS_VERSION : '2.1.0';

        // CSS
        $css_file = $plugin_dir . 'assets/css/eipsi-pool-join.css';
        if ( file_exists( $css_file ) ) {
            wp_enqueue_style(
                'eipsi-pool-join',
                $plugin_url . 'assets/css/eipsi-pool-join.css',
                array(),
                $version
            );
        }

        // JS
        $js_file = $plugin_dir . 'assets/js/eipsi-pool-join.js';
        if ( file_exists( $js_file ) ) {
            wp_enqueue_script(
                'eipsi-pool-join',
                $plugin_url . 'assets/js/eipsi-pool-join.js',
                array( 'jquery' ),
                $version,
                true
            );

            wp_localize_script(
                'eipsi-pool-join',
                'eipsiPoolJoin',
                array(
                    'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
                    'nonce'     => wp_create_nonce( 'eipsi_pool_access' ),
                    'i18n'      => array(
                        'loading'       => __( 'Asignando...', 'eipsi-forms' ),
                        'error_generic' => __( 'Ocurrió un error. Por favor, intentá de nuevo.', 'eipsi-forms' ),
                        'redirecting'   => __( 'Redirigiendo a tu estudio...', 'eipsi-forms' ),
                        'success_title' => __( '¡Listo!', 'eipsi-forms' ),
                    ),
                )
            );
        }
    }

    /**
     * Renderizar el shortcode.
     *
     * @param array $atts Atributos del shortcode.
     * @return string HTML del formulario.
     */
    public static function render( $atts ) {
        // No renderizar en el panel admin
        if ( is_admin() ) {
            return '';
        }

        $atts = shortcode_atts(
            array(
                'pool_id'      => 0,
                'show_name'    => '0',
                'redirect'     => '1',
                'button_label' => __( 'Comenzar mi participación', 'eipsi-forms' ),
            ),
            $atts,
            'eipsi_pool_join'
        );

        $pool_id   = absint( $atts['pool_id'] );
        $show_name = filter_var( $atts['show_name'], FILTER_VALIDATE_BOOLEAN );
        $redirect  = filter_var( $atts['redirect'], FILTER_VALIDATE_BOOLEAN );
        $btn_label = sanitize_text_field( $atts['button_label'] );

        if ( ! $pool_id ) {
            return sprintf(
                '<p class="eipsi-pool-error">%s</p>',
                esc_html__( 'Error: pool_id no especificado en el shortcode.', 'eipsi-forms' )
            );
        }

        // Verificar que el pool existe y está activo
        $pool_info = self::get_pool_info( $pool_id );
        if ( ! $pool_info ) {
            return sprintf(
                '<p class="eipsi-pool-error">%s</p>',
                esc_html__( 'El pool solicitado no existe o no está disponible.', 'eipsi-forms' )
            );
        }

        // Encolar assets si no se hizo automáticamente (e.g. en widgets)
        self::enqueue_assets();

        ob_start();
        self::render_form( $pool_id, $pool_info, $show_name, $redirect, $btn_label );
        return ob_get_clean();
    }

    /**
     * Renderizar el formulario HTML.
     *
     * @param int    $pool_id   ID del pool.
     * @param object $pool_info Datos del pool.
     * @param bool   $show_name Mostrar campo nombre.
     * @param bool   $redirect  Redirigir automáticamente.
     * @param string $btn_label Texto del botón.
     */
    private static function render_form( $pool_id, $pool_info, $show_name, $redirect, $btn_label ) {
        $form_id = 'eipsi-pool-join-' . $pool_id . '-' . uniqid();
        ?>
        <div class="eipsi-pool-join-wrap" id="<?php echo esc_attr( $form_id . '-wrap' ); ?>">

            <form
                class="eipsi-pool-join-form"
                id="<?php echo esc_attr( $form_id ); ?>"
                data-pool-id="<?php echo esc_attr( $pool_id ); ?>"
                data-redirect="<?php echo esc_attr( $redirect ? '1' : '0' ); ?>"
                novalidate
            >
                <?php if ( ! empty( $pool_info->pool_description ) ) : ?>
                    <p class="eipsi-pool-description">
                        <?php echo wp_kses_post( $pool_info->pool_description ); ?>
                    </p>
                <?php endif; ?>

                <div class="eipsi-pool-fields">
                    <?php if ( $show_name ) : ?>
                        <div class="eipsi-pool-field">
                            <label for="<?php echo esc_attr( $form_id . '-name' ); ?>">
                                <?php esc_html_e( 'Tu nombre (opcional)', 'eipsi-forms' ); ?>
                            </label>
                            <input
                                type="text"
                                id="<?php echo esc_attr( $form_id . '-name' ); ?>"
                                name="name"
                                class="eipsi-pool-input"
                                autocomplete="name"
                                placeholder="<?php esc_attr_e( 'Ej: María González', 'eipsi-forms' ); ?>"
                            >
                        </div>
                    <?php endif; ?>

                    <div class="eipsi-pool-field">
                        <label for="<?php echo esc_attr( $form_id . '-email' ); ?>">
                            <?php esc_html_e( 'Tu email', 'eipsi-forms' ); ?>
                            <span class="eipsi-required" aria-hidden="true">*</span>
                        </label>
                        <input
                            type="email"
                            id="<?php echo esc_attr( $form_id . '-email' ); ?>"
                            name="email"
                            class="eipsi-pool-input"
                            required
                            autocomplete="email"
                            placeholder="<?php esc_attr_e( 'tu@email.com', 'eipsi-forms' ); ?>"
                        >
                        <p class="eipsi-pool-field-hint">
                            <?php esc_html_e( 'Tu email se usa solo para enviarte el enlace de acceso al estudio.', 'eipsi-forms' ); ?>
                        </p>
                    </div>
                </div>

                <div class="eipsi-pool-message" role="alert" aria-live="polite" style="display:none;"></div>

                <div class="eipsi-pool-actions">
                    <button
                        type="submit"
                        class="eipsi-pool-submit-btn"
                        data-label-loading="<?php esc_attr_e( 'Asignando...', 'eipsi-forms' ); ?>"
                        data-label-default="<?php echo esc_attr( $btn_label ); ?>"
                    >
                        <?php echo esc_html( $btn_label ); ?>
                    </button>
                </div>

            </form>

            <div class="eipsi-pool-success" id="<?php echo esc_attr( $form_id . '-success' ); ?>" style="display:none;" role="status">
                <div class="eipsi-pool-success-icon" aria-hidden="true">✓</div>
                <h3 class="eipsi-pool-success-title">
                    <?php esc_html_e( '¡Todo listo!', 'eipsi-forms' ); ?>
                </h3>
                <p class="eipsi-pool-success-msg"></p>
                <a class="eipsi-pool-success-link" href="#" style="display:none;">
                    <?php esc_html_e( 'Ir a mi estudio →', 'eipsi-forms' ); ?>
                </a>
            </div>

        </div>
        <?php
    }

    /**
     * Obtener información básica del pool (nombre, descripción, estado).
     *
     * @param int $pool_id ID del pool.
     * @return object|null Datos del pool o null si no existe/inactivo.
     */
    private static function get_pool_info( $pool_id ) {
        global $wpdb;

        $pool = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, pool_name, pool_description, status
                 FROM {$wpdb->prefix}eipsi_longitudinal_pools
                 WHERE id = %d",
                absint( $pool_id )
            )
        );

        if ( ! $pool || 'active' !== $pool->status ) {
            return null;
        }

        return $pool;
    }
}
