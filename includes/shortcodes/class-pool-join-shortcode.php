<?php
if (!defined('ABSPATH')) { exit; }
require_once EIPSI_FORMS_PLUGIN_DIR.'includes/pools/bootstrap.php';
class EIPSI_Pool_Join_Shortcode {
public static function init() {
        add_shortcode( 'eipsi_pool_join', array( __CLASS__, 'render' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue_assets' ) );
    }
public static function maybe_enqueue_assets() { return EIPSI_Pool_Shortcode_Adapter::maybe_enqueue_assets(); }
public static function enqueue_assets() { return EIPSI_Pool_Shortcode_Adapter::enqueue_assets(); }
public static function render( $atts ) { return EIPSI_Pool_Shortcode_Adapter::render($atts); }
}


// Inicializar
EIPSI_Pool_Join_Shortcode::init();
