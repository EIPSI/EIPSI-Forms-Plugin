<?php
/** M3 owner; external contracts remain behind their existing facades. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Form_Context {
    public static function generate_stable_form_id($form_name) {
        global $wpdb;

        $initials = get_form_initials($form_name);

        if (strlen($initials) < 2) {
            $slug = sanitize_title($form_name);
            $initials = strtoupper(substr($slug, 0, 3));
        }

        $slug = sanitize_title($form_name);
        $hash = substr(md5($slug), 0, 6);
        $form_id = "{$initials}-{$hash}";

        $table_name = $wpdb->prefix . 'vas_form_results';
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE form_id = %s",
            $form_id
        ));

        if ($exists > 0) {
            return $form_id;
        }

        return $form_id;
    }
    public static function get_form_initials($form_name) {
        // Handle null or non-string input safely
        if (empty($form_name)) {
            return 'UNK';
        }

        $words = explode(' ', trim((string) $form_name));
        $initials = '';

        foreach ($words as $word) {
            // Limpiar caracteres especiales
            $clean_word = preg_replace('/[^a-zA-Z0-9]/', '', $word);

            if (!empty($clean_word)) {
                if (strlen($clean_word) >= 3) {
                    $initials .= strtoupper(substr($clean_word, 0, 3));
                } else {
                    $initials .= strtoupper($clean_word); // Palabra completa si < 3
                }

                if (strlen($initials) >= 3) break; // Máximo 3 caracteres total
            }
        }

        return !empty($initials) ? $initials : 'UNK'; // Fallback
    }
    public static function eipsi_get_study_status_for_form_name($form_name) {
        $form_name = sanitize_text_field($form_name);

        if (empty($form_name)) {
            return 'open';
        }

        $templates = get_posts(array(
            'post_type' => 'eipsi_form_template',
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_query' => array(
                array(
                    'key' => '_eipsi_form_name',
                    'value' => $form_name,
                    'compare' => '=',
                )
            ),
        ));

        if (empty($templates)) {
            return 'open';
        }

        $template_id = (int) $templates[0];
        $status = get_post_meta($template_id, '_eipsi_study_status', true);

        return ($status === 'closed') ? 'closed' : 'open';
    }
}
