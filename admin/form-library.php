<?php
/**
 * Form Library Management
 * 
 * Handles the Form Library admin page where clinicians can:
 * - View all saved form templates
 * - Copy shortcodes for reuse
 * - Create, edit, and delete form templates
 * 
 * @package EIPSI_Forms
 * @since 1.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register Custom Post Type for Form Templates
 */
function eipsi_register_form_template_cpt() {
    $labels = array(
        'name'                  => __('Form Templates', 'eipsi-forms'),
        'singular_name'         => __('Form Template', 'eipsi-forms'),
        'menu_name'             => __('Form Library', 'eipsi-forms'),
        'add_new'               => __('Add New', 'eipsi-forms'),
        'add_new_item'          => __('Add New Form Template', 'eipsi-forms'),
        'edit_item'             => __('Edit Form Template', 'eipsi-forms'),
        'new_item'              => __('New Form Template', 'eipsi-forms'),
        'view_item'             => __('View Form Template', 'eipsi-forms'),
        'search_items'          => __('Search Form Templates', 'eipsi-forms'),
        'not_found'             => __('No form templates found', 'eipsi-forms'),
        'not_found_in_trash'    => __('No form templates found in trash', 'eipsi-forms'),
        'all_items'             => __('All Forms', 'eipsi-forms'),
    );

    $args = array(
        'labels'              => $labels,
        'public'              => false,
        'show_ui'             => false,
        'show_in_menu'        => false,
        'show_in_rest'        => false,
        'capability_type'     => 'post',
        'capabilities'        => array(
            'edit_post'          => 'edit_posts',         // Clínicos pueden crear/editar formularios propios
            'edit_posts'         => 'edit_posts',         // Clínicos pueden ver lista de formularios
            'edit_others_posts'  => 'manage_options',      // Solo admin puede editar de otros (seguridad)
            'publish_posts'      => 'manage_options',      // Solo admin puede publicar (seguridad ética)
            'read_post'          => 'read',               // Cualquiera con acceso puede ver formularios
            'read_private_posts' => 'manage_options',      // Solo admin puede ver privados
            'delete_post'        => 'manage_options',      // Solo admin puede borrar (seguridad ética)
        ),
        'hierarchical'        => false,
        'supports'            => array('title', 'editor', 'custom-fields'),
        'has_archive'         => false,
        'rewrite'             => false,
        'query_var'           => false,
        'menu_icon'           => 'dashicons-feedback',
    );

    register_post_type('eipsi_form_template', $args);
}
add_action('init', 'eipsi_register_form_template_cpt');



/**
 * Customize columns in Form Library list table
 */
function eipsi_form_library_columns($columns) {
    $new_columns = array(
        'cb'                => $columns['cb'],
        'title'             => __('Form Name', 'eipsi-forms'),
        'study_status'      => __('Estado', 'eipsi-forms'),
        'shortcode'         => __('Shortcode', 'eipsi-forms'),
        'last_response'     => __('Last Response', 'eipsi-forms'),
        'total_responses'   => __('Total Responses', 'eipsi-forms'),
        'date'              => __('Created', 'eipsi-forms'),
    );
    
    return $new_columns;
}
add_filter('manage_eipsi_form_template_posts_columns', 'eipsi_form_library_columns');

/**
 * Check if a column exists in a given database table
 * 
 * Uses INFORMATION_SCHEMA to safely query column existence
 * without generating errors if the column is missing.
 * 
 * @param string $table_name Table name (with or without wp_ prefix)
 * @param string $column_name Column name to check
 * @return bool True if column exists, false otherwise
 */
function eipsi_column_exists_in_table($table_name, $column_name) {
    global $wpdb;
    
    $result = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
            DB_NAME,
            $table_name,
            $column_name
        )
    );
    
    return !empty($result);
}

/**
 * Populate custom columns in Form Library list table
 */
function eipsi_form_library_column_content($column, $post_id) {
    switch ($column) {
        case 'study_status':
            $status = get_post_meta($post_id, '_eipsi_study_status', true);
            $status = ($status === 'closed') ? 'closed' : 'open';

            $label = ($status === 'closed')
                ? __('🔴 Cerrado', 'eipsi-forms')
                : __('🟢 Abierto', 'eipsi-forms');

            printf(
                '<span class="eipsi-study-status-badge eipsi-study-status-%1$s">%2$s</span>',
                esc_attr($status),
                esc_html($label)
            );
            break;

        case 'shortcode':
            $shortcode = '[eipsi_form id="' . $post_id . '"]';
            echo '<code class="eipsi-shortcode-display">' . esc_html($shortcode) . '</code>';
            echo '<button type="button" class="button button-small eipsi-copy-shortcode" data-shortcode="' . esc_attr($shortcode) . '" style="margin-left: 8px;">';
            echo '<span class="dashicons dashicons-clipboard" style="vertical-align: middle; margin-top: 3px;"></span>';
            echo '</button>';
            break;
            
        case 'last_response':
            global $wpdb;
            $table_name = $wpdb->prefix . 'vas_form_results';
            
            // Get form_name from post meta (stored when form is used)
            $form_name = get_post_meta($post_id, '_eipsi_form_name', true);
            
            if (!$form_name) {
                echo '<span style="color: #999;">—</span>';
                break;
            }
            
            // Use created_at instead of legacy submitted_at
            $last_response = $wpdb->get_var($wpdb->prepare(
                "SELECT MAX(created_at) FROM {$table_name} WHERE form_name = %s",
                $form_name
            ));
            
            if ($last_response) {
                $time_diff = human_time_diff(strtotime($last_response), current_time('timestamp'));
                echo '<span title="' . esc_attr($last_response) . '">' . sprintf(__('%s ago', 'eipsi-forms'), $time_diff) . '</span>';
            } else {
                echo '<span style="color: #999;">' . __('Never', 'eipsi-forms') . '</span>';
            }
            break;
            
        case 'total_responses':
            global $wpdb;
            $table_name = $wpdb->prefix . 'vas_form_results';
            
            $form_name = get_post_meta($post_id, '_eipsi_form_name', true);
            
            if (!$form_name) {
                echo '<span style="color: #999;">0</span>';
                break;
            }
            
            // Verify that the table exists and is accessible before querying
            // (COUNT(*) should work, but we validate for safety)
            if (!eipsi_column_exists_in_table($table_name, 'form_name')) {
                echo '<span style="color: #999;">—</span>';
                // Log the issue for diagnostic purposes
                error_log(sprintf(
                    'EIPSI Forms: Table %s or column form_name does not exist',
                    $table_name
                ));
                break;
            }
            
            $count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table_name} WHERE form_name = %s",
                $form_name
            ));
            
            echo '<strong>' . number_format_i18n((int)$count) . '</strong>';
            break;
    }
}
add_action('manage_eipsi_form_template_posts_custom_column', 'eipsi_form_library_column_content', 10, 2);

/**
 * Make shortcode and responses columns sortable
 */
function eipsi_form_library_sortable_columns($columns) {
    $columns['total_responses'] = 'total_responses';
    $columns['last_response'] = 'last_response';
    return $columns;
}
add_filter('manage_edit-eipsi_form_template_sortable_columns', 'eipsi_form_library_sortable_columns');

/**
 * Add admin CSS for Form Library
 */
function eipsi_form_library_admin_styles() {
    $screen = get_current_screen();
    
    if (!$screen || $screen->post_type !== 'eipsi_form_template') {
        return;
    }
    ?>
    <style>
        .eipsi-shortcode-display {
            background: #f0f0f1;
            padding: 4px 8px;
            border-radius: 3px;
            font-family: Consolas, Monaco, monospace;
            font-size: 12px;
            color: #2271b1;
        }
        
        .eipsi-copy-shortcode {
            vertical-align: middle;
            cursor: pointer;
            padding: 2px 8px;
            height: 24px;
        }
        
        .eipsi-copy-shortcode:hover {
            background: #2271b1;
            color: white;
            border-color: #2271b1;
        }
        
        .eipsi-copy-shortcode .dashicons {
            font-size: 16px;
        }

        .eipsi-study-status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
            line-height: 1.2;
            border: 1px solid transparent;
        }

        .eipsi-study-status-open {
            background: rgba(0, 163, 42, 0.12);
            color: #0f5132;
            border-color: rgba(0, 163, 42, 0.25);
        }

        .eipsi-study-status-closed {
            background: rgba(220, 38, 38, 0.10);
            color: #842029;
            border-color: rgba(220, 38, 38, 0.25);
        }

        .column-study_status {
            width: 120px;
        }
        
        .column-shortcode {
            width: 280px;
        }
        
        .column-last_response {
            width: 120px;
        }
        
        .column-total_responses {
            width: 100px;
            text-align: center;
        }
        
        /* Success message after copy */
        .eipsi-copy-success {
            position: fixed;
            top: 32px;
            right: 20px;
            background: #00a32a;
            color: white;
            padding: 12px 20px;
            border-radius: 4px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
            z-index: 999999;
            animation: slideIn 0.3s ease-out;
        }
        
        @keyframes slideIn {
            from {
                transform: translateX(400px);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
    </style>
    <?php
}
add_action('admin_head', 'eipsi_form_library_admin_styles');

/**
 * Add admin JS for copying shortcodes
 */
function eipsi_form_library_admin_scripts() {
    $screen = get_current_screen();
    
    if (!$screen || $screen->post_type !== 'eipsi_form_template') {
        return;
    }
    ?>
    <script>
    jQuery(document).ready(function($) {
        // Copy shortcode to clipboard
        $(document).on('click', '.eipsi-copy-shortcode', function(e) {
            e.preventDefault();
            
            const button = $(this);
            const shortcode = button.data('shortcode');
            
            // Create temporary textarea to copy from
            const temp = $('<textarea>');
            $('body').append(temp);
            temp.val(shortcode).select();
            
            try {
                document.execCommand('copy');
                
                // Show success message
                const message = $('<div class="eipsi-copy-success">✓ Shortcode copiado</div>');
                $('body').append(message);
                
                setTimeout(() => {
                    message.fadeOut(300, function() {
                        $(this).remove();
                    });
                }, 2000);
                
            } catch (err) {
                alert('Error al copiar. Por favor, copie manualmente: ' + shortcode);
            }
            
            temp.remove();
        });
    });
    </script>
    <?php
}
add_action('admin_footer', 'eipsi_form_library_admin_scripts');

/**
 * REMOVED: Old clinical templates section (PHQ-9, GAD-7, etc.)
 * These templates did not use real EIPSI Gutenberg blocks.
 * New demo templates will be added via the Form Container directly.
 * 
 * @deprecated 1.3.0
 */

/**
 * Add helpful notice at the top of Form Library
 */
function eipsi_form_library_admin_notice() {
    $screen = get_current_screen();
    
    if (!$screen || ($screen->post_type !== 'eipsi_form_template' && $screen->base !== 'post')) {
        return;
    }
    
    if ($screen->base === 'edit') {
        ?>
        <div class="notice notice-info" style="margin-top: 20px;">
            <p>
                <strong><?php _e('¿Cómo usar la librería de formularios?', 'eipsi-forms'); ?></strong><br>
                1. Hacé clic en <strong>"Añadir nuevo"</strong> para crear un formulario reutilizable.<br>
                2. Usá el bloque <strong>"EIPSI Form Container"</strong> para armar tu formulario con páginas y campos.<br>
                3. Una vez guardado, copiá el <strong>shortcode</strong> para insertarlo en cualquier página.
            </p>
        </div>
        <?php
    }
}
add_action('admin_notices', 'eipsi_form_library_admin_notice');

/**
 * Extract form_name from form-container block when saving a template
 * This allows us to track responses for this form
 */
function eipsi_extract_form_name_on_save($post_id, $post, $update) {
    // Only for our CPT
    if ($post->post_type !== 'eipsi_form_template') {
        return;
    }
    
    // Avoid infinite loops
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    
    // Parse blocks to find form-container
    $blocks = parse_blocks($post->post_content);
    $form_name = '';
    $study_status = 'open';
    $has_container = false;
    
    foreach ($blocks as $block) {
        if ($block['blockName'] === 'eipsi/form-container') {
            $has_container = true;
            $form_name = isset($block['attrs']['formId']) ? $block['attrs']['formId'] : '';
            $study_status = isset($block['attrs']['studyStatus']) ? $block['attrs']['studyStatus'] : 'open';
            break;
        }
    }

    // Normalize status (safety)
    $study_status = ($study_status === 'closed') ? 'closed' : 'open';
    
    // Save form_name as post meta for easy querying
    if ($form_name) {
        update_post_meta($post_id, '_eipsi_form_name', sanitize_text_field($form_name));
    } else {
        delete_post_meta($post_id, '_eipsi_form_name');
    }

    // Persist study status for admin + ethical guard
    if ($has_container) {
        update_post_meta($post_id, '_eipsi_study_status', $study_status);
    } else {
        delete_post_meta($post_id, '_eipsi_study_status');
    }
}
add_action('save_post', 'eipsi_extract_form_name_on_save', 10, 3);

/**
 * Limit which blocks can be inserted based on post type context
 *
 * This ensures that:
 * - Form Container + campos solo aparecen en la Form Library (CPT eipsi_form_template)
 *
 * @param array|bool $allowed_block_types Array of allowed block types, or true to allow all.
 * @param object     $editor_context The current editor context.
 * @return array|bool Modified array of allowed block types.
 */
function eipsi_limit_blocks_by_context($allowed_block_types, $editor_context) {
    if (!isset($editor_context->post)) {
        return $allowed_block_types;
    }

    $post = $editor_context->post;
    $post_type = $post->post_type;

    if ($allowed_block_types === true || !is_array($allowed_block_types)) {
        $registered_blocks = \WP_Block_Type_Registry::get_instance()->get_all_registered();
        $allowed_block_types = array_keys($registered_blocks);
    }

    // Fuera de la Form Library: PERMITIR SIEMPRE todos los bloques de construcción
    // Los psicólogos pueden crear formularios desde cero en cualquier página
    // No ocultamos nada para eliminar fricción y "zero excusas"

    return array_values($allowed_block_types);
}
add_filter('allowed_block_types_all', 'eipsi_limit_blocks_by_context', 10, 2);

/**
 * Pre-populate new form templates with default EIPSI form container block
 * Fixes the "Add New" button creating empty pages
 */
function eipsi_set_default_form_content($post_id, $post, $update) {
    // Only for our CPT and only on initial creation (not updates)
    if ($post->post_type !== 'eipsi_form_template' || $update) {
        return;
    }
    
    // Avoid infinite loops
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    
    // Only if content is empty
    if (!empty($post->post_content)) {
        return;
    }
    
    // Generate unique form ID
    $form_id = 'form_' . time();
    
    // Build default content with EIPSI form container and one empty page
    $default_content = '<!-- wp:eipsi/form-container {"formId":"' . $form_id . '","preset":"clinical-blue"} -->
<div class="wp-block-eipsi-form-container eipsi-form" data-preset="Clinical Blue"><form class="vas-form eipsi-form-element" data-form-id="' . $form_id . '"><input type="hidden" name="form_id" value="' . $form_id . '"/><input type="hidden" name="form_action" value="eipsi_forms_submit_form"/><input type="hidden" name="eipsi_nonce" value=""/><div class="eipsi-form eipsi-form-content"><!-- wp:eipsi/form-page {"title":"Página 1"} -->
<div class="wp-block-eipsi-form-page eipsi-page" data-page="1" data-page-type="standard"><div class="page-header"><div class="page-header-content"><h3 class="page-header-title">Página 1</h3></div></div><div class="eipsi-page-content"><!-- wp:paragraph {"placeholder":"Agregá tu primer campo aquí..."} -->
<p></p>
<!-- /wp:paragraph --></div></div>
<!-- /wp:eipsi/form-page --></div><div class="form-navigation"><div class="form-nav-left"><button type="button" class="eipsi-prev-button is-hidden">Anterior</button></div><div class="form-nav-right"><button type="button" class="eipsi-next-button is-hidden">Siguiente</button><button type="submit" class="eipsi-submit-button">Enviar</button></div></div></form></div>
<!-- /wp:eipsi/form-container -->';
    
    // Update the post with default content
    wp_update_post(array(
        'ID' => $post_id,
        'post_content' => $default_content,
    ));
}
add_action('wp_insert_post', 'eipsi_set_default_form_content', 10, 3);
