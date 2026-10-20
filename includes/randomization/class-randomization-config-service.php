<?php
/** M7 owner. Public compatibility adapters retain registration and signatures. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Randomization_Config_Service {

public static function eipsi_get_randomization_config_by_id( $post_id, $config_id ) {
    $meta_key = '_randomization_config_' . $config_id;
    return get_post_meta( $post_id, $meta_key, true );
}

public static function eipsi_parse_shortcodes_input( $input ) {
    $formularios = array();
    
    // Usar regex global para encontrar TODOS los shortcodes (no solo por línea)
    // Soporta: espacios, saltos de línea, sin espacios
    // Formato: [eipsi_form id="2482"], [eipsi_form id='2482'], [eipsi_form id=2482]
    
    $pattern = '/\[eipsi_form\s+id\s*=\s*["\']?(\d+)["\']?\]/i';
    
    if ( preg_match_all( $pattern, $input, $matches ) ) {
        // $matches[1] contiene todos los form_ids encontrados
        foreach ( $matches[1] as $match ) {
            $form_id = intval( $match );
            if ( $form_id > 0 && ! isset( $formularios[ $form_id ] ) ) {
                // Evitar duplicados
                $formularios[ $form_id ] = array(
                    'id' => $form_id,
                    'shortcode' => '[eipsi_form id="' . $form_id . '"]',
                );
            }
        }
    }

    return array_values( $formularios );
}

public static function eipsi_get_randomization_config_from_post_meta( $post_id, $config_id ) {
    $meta_key = '_randomization_config_' . $config_id;
    $config = get_post_meta( $post_id, $meta_key, true );
    
    if ( ! $config || empty( $config ) ) {
        return null;
    }

    return $config;
}
}

/** Authorization for configuration resources, separate from scientific assignment. */
class EIPSI_Randomization_Resource_Policy {
    public static function denied() {
        return new WP_Error('eipsi_resource_forbidden', 'No tienes permisos para realizar esta acción.', array('status' => 403));
    }
    public static function can_edit($id, $types = array('post', 'page', 'eipsi_form_template')) {
        $id = filter_var($id, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)));
        if ($id === false) { return false; }
        $post = get_post($id);
        if (!$post || !in_array($post->post_type, $types, true)
            || !in_array($post->post_status, array('auto-draft', 'draft', 'pending', 'private', 'publish', 'future'), true)
            || !current_user_can('edit_post', $post->ID)) {
            return false;
        }
        // The historical Form CPT maps edit_post to edit_posts. Enforce ownership
        // explicitly instead of inheriting that primitive capability shortcut.
        $type = get_post_type_object($post->post_type);
        return (int) $post->post_author === get_current_user_id()
            || ($type && current_user_can($type->cap->edit_others_posts));
    }
    public static function can_use($id, $types = array('eipsi_form_template')) {
        $id = filter_var($id, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)));
        if ($id === false) { return false; }
        $post = get_post($id);
        if (!$post || !in_array($post->post_type, $types, true)
            || !in_array($post->post_status, array('draft', 'pending', 'private', 'publish', 'future'), true)) {
            return false;
        }
        if ($post->post_status === 'publish' && $post->post_password === '') {
            return true;
        }
        if (self::can_edit($id, $types)) {
            return true;
        }
        $type = get_post_type_object($post->post_type);
        return $post->post_status === 'private' && $type
            && current_user_can('read_post', $post->ID)
            && current_user_can($type->cap->read_private_posts);
    }
    public static function permission($request) {
        return self::can_edit($request->get_param('post_id')) ? true : self::denied();
    }
    public static function sources($forms, $key = 'id') {
        foreach ((array) $forms as $form) {
            if (!is_array($form) || !self::can_use($form[$key] ?? 0)) {
                return self::denied();
            }
        }
        return true;
    }
}
