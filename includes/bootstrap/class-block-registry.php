<?php
/** M1 bootstrap: preserve existing contracts and registration order. */
if (!defined('ABSPATH')) { exit; }

final class EIPSI_Block_Registry {
    public static function register() {
        add_action('init', 'eipsi_forms_register_blocks');
        add_filter('block_categories_all', 'eipsi_forms_block_categories', 10, 2);
    }
}
