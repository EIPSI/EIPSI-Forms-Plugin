<?php
if (!defined('ABSPATH')) { exit; }
require_once EIPSI_FORMS_PLUGIN_DIR.'includes/pools/bootstrap.php';
class EIPSI_Pool_Block_Renderer {
public static function init() {
        add_shortcode('eipsi_pool', array(__CLASS__, 'render_shortcode'));
        add_shortcode('eipsi_pool_join', array(__CLASS__, 'render_shortcode_compat'));
    }
public static function render_block($attributes) { return EIPSI_Pool_Block_Adapter::render_block($attributes); }
public static function render_shortcode($atts) { return EIPSI_Pool_Block_Adapter::render_shortcode($atts); }
public static function render_shortcode_compat($atts) { return EIPSI_Pool_Block_Adapter::render_shortcode_compat($atts); }
}


// Inicializar
add_action('init', array('EIPSI_Pool_Block_Renderer', 'init'));
