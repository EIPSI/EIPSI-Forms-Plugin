<?php
if (!defined('ABSPATH')) { exit; }
class EIPSI_Randomization_Form_Load_Adapter {
    public static function load() {
        if (!check_ajax_referer('eipsi_randomization_nonce', 'nonce', false)) {
            wp_send_json_error(__('Token de seguridad inválido.', 'eipsi-forms'), 403);
        }
        $input = $_POST['form_id'] ?? '';
        if (!is_scalar($input) || !ctype_digit((string)$input)) { wp_send_json_error(__('Formulario no disponible.', 'eipsi-forms'), 404); }
        $id = absint($input);
        $form = get_post($id);
        if (!$form || $form->post_type !== 'eipsi_form_template' || $form->post_status !== 'publish' || $form->post_password !== '') {
            wp_send_json_error(__('Formulario no disponible.', 'eipsi-forms'), 404);
        }
        $context = wp_unslash($_POST);
        $access = EIPSI_Authorization_Policy::authorize_form_operation((string)$id, $context, array(), 'load');
        if (!$access['success']) { wp_send_json_error(__('No tenés acceso a este formulario.', 'eipsi-forms'), $access['error'] === 'authentication_required' ? 401 : 403); }
        wp_send_json_success(EIPSI_Form_Renderer::eipsi_render_form_template_markup($id, 'randomization', $context));
    }
}
