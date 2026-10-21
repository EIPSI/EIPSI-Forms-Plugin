<?php
/** M3 owner; external contracts remain behind their existing facades. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Form_Renderer {
    public static function eipsi_build_html_attributes($attributes = array()) {
        $pairs = array();

        foreach ($attributes as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $pairs[] = sprintf('%s="%s"', esc_attr($key), esc_attr($value));
        }

        return implode(' ', $pairs);
    }
    public static function eipsi_render_form_notice($message, $type = 'info') {
        $colors = array(
            'info' => array('#e0f2fe', '#0369a1', '#38bdf8'),
            'warning' => array('#fef3c7', '#92400e', '#fbbf24'),
            'error' => array('#fee2e2', '#b91c1c', '#f87171'),
        );

        $palette = isset($colors[$type]) ? $colors[$type] : $colors['info'];

        return sprintf(
            '<div class="eipsi-form-notice eipsi-form-notice-%1$s" style="margin: 20px 0; padding: 16px 18px; border: 2px solid %3$s; border-radius: 8px; background: %2$s; color: %4$s; font-size: 14px; line-height: 1.5;">
                <strong style="display: block; margin-bottom: 6px; text-transform: uppercase; font-size: 12px; letter-spacing: 0.5px;">%5$s</strong>
                <span>%6$s</span>
            </div>',
            esc_attr($type),
            esc_attr($palette[0]),
            esc_attr($palette[2]),
            esc_attr($palette[1]),
            esc_html__('EIPSI Forms', 'eipsi-forms'),
            wp_kses_post($message)
        );
    }
    public static function eipsi_get_form_template($template_id) {
        if (!$template_id) {
            return new WP_Error('eipsi_missing_form', __('Por favor, seleccioná un formulario válido.', 'eipsi-forms'));
        }

        $template = get_post($template_id);

        if (!$template || $template->post_type !== 'eipsi_form_template' || $template->post_status === 'trash') {
            return new WP_Error('eipsi_form_not_found', __('El formulario seleccionado no existe o fue eliminado.', 'eipsi-forms'));
        }

        return $template;
    }
    public static function eipsi_render_form_template_markup($template_id, $context = 'block', $options = array()) {
        $template = eipsi_get_form_template($template_id);

        if (is_wp_error($template)) {
            return eipsi_render_form_notice($template->get_error_message(), 'error');
        }

        // A template attached to a study must use that study's participant session.
        global $wpdb;
        $is_longitudinal = (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}survey_waves WHERE form_id = %d", $template_id
        ));
        if (eipsi_form_requires_login($template_id) || $is_longitudinal) {
            // If NOT authenticated → show login gate
            if (!eipsi_is_participant_logged_in()) {
                // Enqueue login gate styles
                wp_enqueue_style(
                    'eipsi-login-gate-css',
                    EIPSI_FORMS_PLUGIN_URL . 'assets/css/login-gate.css',
                    array('eipsi-theme-toggle-css'),
                    EIPSI_FORMS_VERSION
                );

                ob_start();
                include EIPSI_FORMS_PLUGIN_DIR . 'includes/templates/login-gate.php';
                return ob_get_clean();
            }
        }

        if ($is_longitudinal) {
            $access = EIPSI_Auth_Service::authorize_session_context($options);
            $belongs_to_study = $access['success'] ? $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}survey_waves WHERE form_id = %d AND study_id = %d",
                $template_id, $access['study_id']
            )) : 0;
            if (!$belongs_to_study) {
                return eipsi_render_form_notice(__('Unauthorized', 'eipsi-forms'), 'error');
            }
        }

        if ($is_longitudinal) {
            $temporal_context = EIPSI_Auth_Service::authorize_form_operation((string) $template_id, $options, $_GET, 'render');
            if (!$temporal_context['success']) { return eipsi_render_form_notice(__('Esta toma no está disponible.', 'eipsi-forms'), 'error'); }
            $temporal = EIPSI_Longitudinal_Assignment_Transition_Service::precheck_submission(
                $temporal_context['participant_id'], $temporal_context['study_id'], $temporal_context['wave_id']
            );
            if (!$temporal['success']) { return eipsi_render_form_notice($temporal['data']['message'], 'error'); }
        }

        // Ensure frontend assets are loaded
        eipsi_forms_enqueue_frontend_assets();

        // Render Gutenberg blocks contained in the template
        $content = do_blocks($template->post_content);

        $wrapper_attributes = eipsi_build_html_attributes(array(
            'class' => 'eipsi-form-template-wrapper',
            'data-template-id' => $template_id,
            'data-render-source' => $context,
        ));

        return sprintf('<div %s>%s</div>', $wrapper_attributes, $content);
    }
    public static function eipsi_render_form_shortcode_markup($template_id) {
        if (!$template_id) {
            return eipsi_render_form_notice(
                __('Recordá pasar el atributo id: [eipsi_form id="123"].', 'eipsi-forms'),
                'warning'
            );
        }

        return eipsi_render_form_template_markup($template_id, 'shortcode');
    }
    public static function eipsi_form_requires_login($template_id) {
        $require_login = get_post_meta($template_id, '_eipsi_require_login', true);
        return (bool) $require_login;
    }
}
