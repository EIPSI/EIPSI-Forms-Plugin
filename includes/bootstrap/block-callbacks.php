<?php
/** M1 bootstrap: preserve existing contracts and registration order. */
if (!defined('ABSPATH')) { exit; }

function eipsi_forms_register_blocks() {
    if (!function_exists('register_block_type')) {
        return;
    }

    // Pass admin nonce to block editor for AJAX calls (e.g., eipsi_get_forms_list)
    // Also pass permalink and postId for randomization link generation
    $current_post_id = isset($post) ? $post->ID : (isset($_GET['post']) ? intval($_GET['post']) : 0);
    $permalink = $current_post_id ? get_permalink($current_post_id) : '';

    // Register editor data script (for AJAX calls)
    wp_register_script(
        'eipsi-blocks-editor-data',
        '',
        array(),
        EIPSI_FORMS_VERSION,
        true
    );

    wp_localize_script('eipsi-blocks-editor-data', 'eipsiEditorData', array(
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('eipsi_admin_nonce'),
        'permalink' => $permalink,
        'postId' => $current_post_id,
    ));

    // Backward compatibility: also expose as window.eipsiAdminNonce
    wp_add_inline_script('eipsi-blocks-editor-data',
        'window.eipsiAdminNonce = eipsiEditorData?.nonce || "";',
        'after'
    );

    // Registrar automáticamente todos los bloques desde build/blocks/
    $blocks_dir = EIPSI_FORMS_PLUGIN_DIR . 'build/blocks';

    if (!is_dir($blocks_dir)) {
        return;
    }

    $block_folders = scandir($blocks_dir);

    foreach ($block_folders as $block_folder) {
        // Skip . and ..
        if ($block_folder === '.' || $block_folder === '..' || $block_folder === 'index') {
            continue;
        }

        $block_json_path = $blocks_dir . '/' . $block_folder . '/block.json';

        // Registrar bloque si existe su block.json
        if (file_exists($block_json_path)) {
            // Special handling for randomization block with render_callback
            if ($block_folder === 'randomization-block') {
                register_block_type($block_json_path, array(
                    'render_callback' => 'eipsi_render_randomization_block'
                ));
            }
            // Special handling for pool block with render_callback (v2.5.3)
            elseif ($block_folder === 'pool-block') {
                register_block_type($block_json_path, array(
                    'render_callback' => 'eipsi_render_pool_join_block'
                ));
            } else {
                register_block_type($block_json_path);
            }
        }
    }
}

function eipsi_forms_block_categories($block_categories, $editor_context) {
    if (!empty($editor_context->post)) {
        array_push(
            $block_categories,
            array(
                'slug' => 'eipsi-forms',
                'title' => __('EIPSI Forms', 'eipsi-forms'),
                'icon' => null,
            )
        );
    }
    return $block_categories;
}
