<?php
/** M7 owner. Public compatibility adapters retain registration and signatures. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Randomization_Frontend_Adapter {

public static function eipsi_calculate_frontend_assignment($config, $user_fingerprint) { return EIPSI_Randomization_Algorithm_Service::eipsi_calculate_frontend_assignment($config, $user_fingerprint); }

public static function eipsi_randomization_frontend_scripts() {
    // Solo cargar si hay un shortcode en la página
    global $post;
    if ( ! is_a( $post, 'WP_Post' ) || ! has_shortcode( $post->post_content, 'eipsi_randomization' ) ) {
        return;
    }

    // Enqueue script para fingerprinting (si no está ya cargado)
    if ( ! wp_script_is( 'eipsi-fingerprint', 'enqueued' ) ) {
        wp_enqueue_script(
            'eipsi-fingerprint',
            EIPSI_FORMS_PLUGIN_URL . 'assets/js/eipsi-fingerprint.js',
            array(),
            EIPSI_FORMS_VERSION,
            true
        );
    }

    // Enqueue CSS for Randomization UX
    wp_enqueue_style(
        'eipsi-randomization-css',
        EIPSI_FORMS_PLUGIN_URL . 'assets/css/eipsi-randomization.css',
        array(),
        EIPSI_FORMS_VERSION
    );

    // Enqueue JS for Randomization UX
    wp_enqueue_script(
        'eipsi-randomization-ux',
        EIPSI_FORMS_PLUGIN_URL . 'assets/js/eipsi-randomization-ux.js',
        array(),
        EIPSI_FORMS_VERSION,
        true
    );
}

public static function eipsi_randomization_inline_data() {
    global $post;
    if ( ! is_a( $post, 'WP_Post' ) || ! has_shortcode( $post->post_content, 'eipsi_randomization' ) ) {
        return;
    }
    ?>
    <script type="text/javascript">
    window.eipsiRandomization = {
        ajaxUrl: '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>',
        nonce: '<?php echo esc_js( wp_create_nonce( 'eipsi_randomization_nonce' ) ); ?>',
        strings: {
            loading: '<?php echo esc_js( __( 'Cargando...', 'eipsi-forms' ) ); ?>',
            error: '<?php echo esc_js( __( 'Error al cargar formulario', 'eipsi-forms' ) ); ?>'
        }
    };
    </script>
    <?php
}

public static function eipsi_handle_send_user_fingerprint() {
    check_ajax_referer( 'eipsi_randomization_nonce', 'nonce' );
    
    $fingerprint = sanitize_text_field( $_POST['fingerprint'] ?? '' );
    $template_id = intval( $_POST['template_id'] ?? 0 );
    $config_id = sanitize_text_field( $_POST['config_id'] ?? '' );
    
    if ( empty( $fingerprint ) || empty( $template_id ) || empty( $config_id ) ) {
        wp_send_json_error( array( 'message' => 'Datos incompletos' ) );
    }
    
    // Guardar fingerprint en sesión/temporal para uso posterior
    set_transient( 'eipsi_user_fingerprint_' . $template_id . '_' . $config_id, $fingerprint, HOUR_IN_SECONDS );
    
    wp_send_json_success( array( 'message' => 'Fingerprint recibido' ) );
}

public static function eipsi_randomization_log( $message ) {
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( '[EIPSI RCT] ' . $message );
    }
}
public static function localize_form_load() {
    if (wp_script_is('eipsi-randomization-js', 'enqueued')) {
        wp_localize_script('eipsi-randomization-js', 'eipsiRandomizationFormLoad', array('ajaxUrl'=>admin_url('admin-ajax.php'), 'nonce'=>wp_create_nonce('eipsi_randomization_nonce')));
    }
}

}
