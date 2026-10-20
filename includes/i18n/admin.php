<?php
/** Plugin-wide language for administration and new content; never changes WordPress locale. */
if (!defined('ABSPATH')) { exit; }

function eipsi_forms_language() {
    $value = get_option('eipsi_forms_language', 'en');
    return in_array($value, array('en', 'es'), true) ? $value : 'en';
}

function eipsi_admin_language() { return eipsi_forms_language(); }

function eipsi_admin_catalog() {
    static $catalog = null;
    if ($catalog === null) {
        $catalog = json_decode(file_get_contents(__DIR__ . '/admin-catalog.json'), true);
        if (!is_array($catalog)) { throw new RuntimeException('EIPSI_ADMIN_CATALOG_INVALID'); }
    }
    return $catalog;
}

/** Explicit helper: no gettext filter, DOM rewriting, or participant/content processing. */
function eipsi_admin_translate($english) {
    if (eipsi_admin_language() !== 'es') { return $english; }
    $entry = eipsi_admin_catalog()[$english] ?? $english;
    return is_array($entry) ? $entry['one'] : $entry;
}

/** English and Spanish both use singular only for one. No locale-global plural rules. */
function eipsi_admin_plural($singular, $count, ...$args) {
    $entry = eipsi_admin_catalog()[$singular] ?? null;
    if (!is_array($entry)) { throw new RuntimeException('EIPSI_ADMIN_PLURAL_MISSING'); }
    $text = eipsi_admin_language() === 'es'
        ? ($count == 1 ? $entry['one'] : $entry['other'])
        : ($count == 1 ? $singular : $entry['plural']);
    return sprintf($text, $count, ...$args);
}

function eipsi_admin_format($english, ...$args) {
    return sprintf(eipsi_admin_translate($english), ...$args);
}

/** Administrative field names only; public message defaults are never passed here. */
function eipsi_admin_fields() {
    static $fields = null;
    if ($fields === null) { $fields = json_decode(file_get_contents(__DIR__ . '/admin-fields.json'), true); }
    return $fields;
}
function eipsi_admin_field($key) {
    return eipsi_admin_translate(eipsi_admin_fields()[$key] ?? $key);
}

/** Resolve an administrative exception label without changing its domain value. */
function eipsi_admin_error($message) {
    $english = eipsi_admin_fields()['admin_error_'.$message] ?? $message;
    return eipsi_admin_translate($english);
}

function eipsi_admin_elapsed($seconds) {
    $seconds=max(0,(int)$seconds);
    foreach(array('year'=>31536000,'month'=>2592000,'week'=>604800,'day'=>86400,'hour'=>3600,'minute'=>60,'second'=>1) as $unit=>$size) {
        if($seconds >= $size || $unit==='second') { return eipsi_admin_plural('%d '.$unit,max(1,(int)round($seconds/$size))); }
    }
}

function eipsi_admin_language_save() {
    if (!isset($_POST['eipsi_save_admin_language'])) { return ''; }
    if (!current_user_can('manage_options') || !get_current_user_id()) {
        wp_die('EIPSI_LANGUAGE_FORBIDDEN', '', array('response' => 403));
    }
    check_admin_referer('eipsi_admin_language');
    $value = isset($_POST['eipsi_admin_language']) ? wp_unslash($_POST['eipsi_admin_language']) : '';
    if (!is_string($value) || !in_array($value, array('en', 'es'), true)) {
        return eipsi_admin_translate('Choose English or Spanish.');
    }
    update_option('eipsi_forms_language', $value, false);
    // POST/redirect/GET refreshes the menu too; never replay unrelated settings forms.
    wp_safe_redirect(admin_url('admin.php?page=eipsi-configuration&eipsi_language_saved=1'));
    exit;
}

/** Handle before admin-header.php sends output; a page callback is too late to redirect. */
function eipsi_admin_language_submit() {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !isset($_POST['eipsi_save_admin_language'])) { return; }
    if (!isset($_GET['page']) || !is_string($_GET['page']) || $_GET['page'] !== 'eipsi-configuration') { return; }
    $GLOBALS['eipsi_admin_language_notice'] = eipsi_admin_language_save();
}

function eipsi_admin_language_form($notice = '') {
    if (isset($_GET['eipsi_language_saved'])) { $notice = eipsi_admin_translate('Plugin language saved.'); }
    if ($notice !== '') { echo '<div class="notice notice-info"><p>' . esc_html($notice) . '</p></div>'; }
    echo '<form method="post" class="eipsi-admin-language">';
    wp_nonce_field('eipsi_admin_language');
    echo '<div class="eipsi-language-heading"><span class="dashicons dashicons-translation" aria-hidden="true"></span><div><h2>Language / Idioma</h2><p>' . esc_html(eipsi_admin_translate('Choose the language for EIPSI Forms and new content. Existing content keeps its original language and text.')) . '</p></div></div><div class="eipsi-language-controls"><label class="screen-reader-text" for="eipsi-admin-language">Language / Idioma</label> ';
    echo '<select id="eipsi-admin-language" name="eipsi_admin_language">';
    foreach (array('en' => 'English', 'es' => 'Español') as $value => $label) {
        echo '<option value="' . esc_attr($value) . '"' . selected(eipsi_admin_language(), $value, false) . '>' . esc_html($label) . '</option>';
    }
    echo '</select> <button class="button button-primary" type="submit" name="eipsi_save_admin_language" value="1">' . esc_html(eipsi_admin_translate('Save language')) . '</button>';
    echo '</div></form>';
}

/** Shared PHP/JS catalog, registered only for EIPSI administrative pages. */
function eipsi_admin_i18n_assets() {
    $page = isset($_GET['page']) && is_string($_GET['page']) ? sanitize_key($_GET['page']) : '';
    if (!in_array($page, array('eipsi-configuration', 'eipsi-email-templates', 'eipsi-assessment-editor', 'eipsi-study-builder'), true)) { return; }
    $file = EIPSI_FORMS_PLUGIN_DIR . 'assets/js/admin-i18n.js';
    wp_enqueue_script('eipsi-admin-i18n', EIPSI_FORMS_PLUGIN_URL . 'assets/js/admin-i18n.js', array(), (string) filemtime($file), false);
    wp_add_inline_script('eipsi-admin-i18n', 'window.eipsiAdminLanguage=' . wp_json_encode(array('language' => eipsi_admin_language(), 'messages' => eipsi_admin_catalog(), 'fields' => eipsi_admin_fields())) . ';', 'before');
}
if (function_exists('add_action')) {
    add_action('admin_init', 'eipsi_admin_language_submit', 0);
    add_action('admin_enqueue_scripts', 'eipsi_admin_i18n_assets', 0);
}
