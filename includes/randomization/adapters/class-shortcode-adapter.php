<?php
/** M7 owner. Public compatibility adapters retain registration and signatures. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Randomization_Shortcode_Adapter {

public static function eipsi_randomization_shortcode( $atts ) {
    $atts = shortcode_atts(
        array(
            'template' => '', // Template ID del Form Library
            'config' => '',   // Config ID único
        ),
        $atts,
        'eipsi_randomization'
    );

    $template_id = intval( $atts['template'] );
    $config_id = sanitize_text_field( $atts['config'] );

    if ( empty( $template_id ) || empty( $config_id ) ) {
        return eipsi_randomization_error_notice(
            __( '⚠️ Error: Faltan parámetros requeridos (template y config).', 'eipsi-forms' )
        );
    }

    // PASO 1: Obtener configuración desde post meta (nuevo flujo)
    $config = eipsi_get_randomization_config_from_post_meta( $template_id, $config_id );
    
    if ( ! $config ) {
        // Fallback: buscar configuración legacy en blocks (backwards compatibility)
        $config_post = eipsi_get_randomization_config_post( $template_id );

        if ( ! $config_post ) {
            return eipsi_randomization_error_notice(
                sprintf(
                    __( '⚠️ Error: No se encontró configuración para template %d y config %s.', 'eipsi-forms' ),
                    $template_id,
                    esc_html( $config_id )
                )
            );
        }

        $config = eipsi_extract_randomization_config( $config_post->ID, $config_id );
    }

    // ✅ v1.3.19 - Obtener persistent_mode desde la configuración (default: true)
    // - true (default): Cada usuario asignado UNA VEZ, luego persistente
    // - false: Cada F5/reload = rotación cíclica (TESTING MODE)
    $persistent_mode = isset( $config['persistent_mode'] ) ? (bool) $config['persistent_mode'] : true;

    if ( ! $config || empty( $config['formularios'] ) ) {
        return eipsi_randomization_error_notice(
            __( 'ℹ️ Esta configuración de aleatorización no tiene formularios asignados.', 'eipsi-forms' )
        );
    }

    if ( count( $config['formularios'] ) < 1 ) {
        return eipsi_randomization_error_notice(
            __( 'ℹ️ La aleatorización requiere al menos 1 formulario configurado.', 'eipsi-forms' )
        );
    }

    // PASO 2: Obtener fingerprint del usuario (desde POST/AJAX o generar en servidor)
    $user_fingerprint = eipsi_get_user_fingerprint();

    $resolved = EIPSI_Randomization_Assignment_Service::resolve($config_id, $config, $user_fingerprint);
    if (is_wp_error($resolved)) { return eipsi_randomization_error_notice($resolved->get_error_message()); }
    $assigned_form_id = $resolved['assigned_form_id'];
    $is_new_assignment = $resolved['is_new_assignment'];

    // Determine Group Name
    $group_name = get_the_title( $assigned_form_id );
    
    // Check if there is a custom label in config (optional optimization)
    if ( ! empty( $config['formularios'] ) ) {
        foreach ( $config['formularios'] as $form ) {
            if ( isset( $form['id'] ) && intval( $form['id'] ) === $assigned_form_id ) {
                 if ( ! empty( $form['label'] ) ) {
                     $group_name = $form['label'];
                 }
                 break;
            }
        }
    }

    // PASO 4: Renderizar el formulario asignado
    ob_start();
    ?>
    <div class="eipsi-randomization-container" 
         data-randomization-id="<?php echo esc_attr( $config_id ); ?>"
         data-assigned-form="<?php echo esc_attr( $assigned_form_id ); ?>"
         data-show-modal="<?php echo $is_new_assignment ? 'true' : 'false'; ?>">
        
        <?php 
        // 1. MODAL (Only if new assignment)
        if ( $is_new_assignment ) : ?>
            <div class="eipsi-rct-modal-overlay">
                <div class="eipsi-rct-modal">
                    <h3><?php esc_html_e( 'Estudio de Investigación', 'eipsi-forms' ); ?></h3>
                    <p>
                        <?php 
                        printf( 
                            esc_html__( 'Te hemos asignado al grupo: %s. Por favor completa el siguiente formulario.', 'eipsi-forms' ), 
                            '<strong>' . esc_html( $group_name ) . '</strong>'
                        ); 
                        ?>
                    </p>
                    <button class="eipsi-rct-modal-btn"><?php esc_html_e( 'Comenzar', 'eipsi-forms' ); ?></button>
                </div>
            </div>
        <?php endif; ?>

        <?php 
        // 2. BADGE (Always visible)
        ?>
        <div class="eipsi-rct-badge">
            <span class="icon">🔖</span>
            <span class="text">
                <?php printf( esc_html__( 'Grupo: %s', 'eipsi-forms' ), esc_html( $group_name ) ); ?>
            </span>
        </div>

        <?php if ( ! empty( $config['showInstructions'] ) ) : ?>
        <div class="randomization-notice" style="background: #e3f2fd; border-left: 4px solid #2196F3; padding: 1rem; margin-bottom: 1.5rem; border-radius: 4px;">
            <p style="margin: 0; color: #0d47a1; font-weight: 500;">
                ℹ️ <?php esc_html_e( 'Este estudio utiliza aleatorización: cada participante recibe un formulario asignado aleatoriamente.', 'eipsi-forms' ); ?>
            </p>
            <p style="margin: 0.5rem 0 0 0; color: #1565c0; font-size: 0.9rem;">
                <?php
                if ( $persistent_mode ) {
                    esc_html_e( '✅ Su asignación es PERSISTENTE. En futuras sesiones recibirá el mismo formulario.', 'eipsi-forms' );
                } else {
                    esc_html_e( '⚠️ MODO TEST: Su asignación cambia en cada visita (para validar el funcionamiento).', 'eipsi-forms' );
                }
                ?>
            </p>
        </div>
        <?php endif; ?>

        <?php
        // Renderizar el formulario usando el template de EIPSI Forms
        if ( function_exists( 'eipsi_render_form_template' ) ) {
            echo eipsi_render_form_template( $assigned_form_id );
        } else {
            // Fallback: usar shortcode estándar
            echo do_shortcode( '[eipsi_form id="' . $assigned_form_id . '"]' );
        }
        ?>
    </div>
    <?php
    return ob_get_clean();
}

public static function eipsi_randomization_error_notice( $message ) {
    return sprintf(
        '<div style="background: #ffebee; border-left: 4px solid #f44336; padding: 1rem; margin: 1rem 0; border-radius: 4px;">
            <p style="margin: 0; color: #c62828; font-weight: 500;">%s</p>
        </div>',
        wp_kses_post( $message )
    );
}

public static function eipsi_handle_randomization_query_param() {
    if ( ! isset( $_GET['eipsi_rand'] ) ) {
        return;
    }

    $randomization_id = sanitize_text_field( $_GET['eipsi_rand'] );

    // Si el parámetro incluye template y config (nuevo formato)
    if ( strpos( $randomization_id, '_' ) !== false ) {
        // Formato: template_configID (ej: 2400_config_123456)
        $parts = explode( '_', $randomization_id, 2 );
        if ( count( $parts ) === 2 ) {
            $template_id = intval( $parts[0] );
            $config_id = $parts[1];
            
            $config = eipsi_get_randomization_config_from_post_meta( $template_id, $config_id );
            if ( $config ) {
                // Redirigir a la página con el shortcode correspondiente
                $shortcode = sprintf( '[eipsi_randomization template="%d" config="%s"]', $template_id, $config_id );
                wp_safe_redirect( add_query_arg( 'eipsi_rand_shortcode', base64_encode( $shortcode ), home_url() ) );
                exit;
            }
        }
    }

    // Fallback: buscar página que contenga este shortcode o bloque (legacy)
    $config_post = eipsi_get_randomization_config_post( $randomization_id );

    if ( $config_post ) {
        // Redirigir a la página con el bloque
        wp_safe_redirect( get_permalink( $config_post->ID ) );
        exit;
    }

    // Si no se encuentra, mostrar error
    wp_die(
        eipsi_randomization_error_notice(
            sprintf(
                __( '⚠️ No se encontró configuración de aleatorización para ID: %s', 'eipsi-forms' ),
                esc_html( $randomization_id )
            )
        ),
        __( 'Error de Aleatorización', 'eipsi-forms' ),
        array( 'response' => 404 )
    );
}
}
