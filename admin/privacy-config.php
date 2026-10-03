<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Obtiene defaults de privacidad
 * Browser/OS/Screen Width OFF por default (opcional)
 * IP ON por default (pero configurable)
 */
function get_privacy_defaults() {
    return array(
        // OBLIGATORIOS
        'form_id' => true,
        'participant_id' => true,
        'session_id' => true,
        'timestamps_basic' => true,

        // RECOMENDADOS - ON por default
        'device_type' => true,

        // AUDITORÍA CLÍNICA - ON por default (pero opcional)
        'ip_address' => true,
        'ip_storage' => 'plain_text',
        'ip_retention_days' => 90,

        // ✅ v1.5.4 - FINGERPRINT COMPLETO - ON por default
        'fingerprint_enabled' => true,

        // DISPOSITIVO - ON por default (user expectation matches UI)
        'browser' => true,
        'os' => true,
        'screen_width' => true,

        // EXCLUIDOS (por privacidad y alcance)
        'screen_size' => false,
        'browser_os' => false,
        'user_agent_full' => false,
        'ip_geo' => false,
        'connection_type' => false,
        'movement_tracking' => false,
        'mood_tracking' => false,
        'research_consent' => false,

        // v2.1.3 - Exportación de metadatos extendidos (opcional, OFF por default)
        'export_canvas_fingerprint' => false,
        'export_webgl_renderer' => false,
        'export_screen_resolution' => false,
        'export_screen_depth' => false,
        'export_pixel_ratio' => false,
        'export_timezone' => false,
        'export_language' => false,
        'export_cpu_cores' => false,
        'export_ram' => false,
        'export_plugins' => false,
        'export_touch_support' => false,
        'export_cookies_enabled' => false,
    );
}

/**
 * Obtiene la configuración global por defecto de privacidad
 */
function get_global_privacy_defaults() {
    $saved = get_option('eipsi_global_privacy_defaults');

    if (!$saved) {
        // Si no existe configuración global, usar defaults estándar
        return array(
            'ip_address' => true,
            'browser' => true,
            'os' => true,
            'screen_width' => true,
            'device_type' => true,
            'fingerprint_enabled' => true,  // ✅ v1.5.4 - ON por default
            // v2.1.3 - Metadatos extendidos ON por default (user request)
            'export_canvas_fingerprint' => true,
            'export_webgl_renderer' => true,
            'export_screen_resolution' => true,
            'export_screen_depth' => true,
            'export_pixel_ratio' => true,
            'export_timezone' => true,
            'export_language' => true,
            'export_cpu_cores' => true,
            'export_ram' => true,
            'export_plugins' => true,
            'export_touch_support' => true,
            'export_cookies_enabled' => true,
        );
    }

    return $saved;
}

/**
 * Guarda la configuración global de privacidad
 */
function save_global_privacy_defaults($config) {
    if (!current_user_can('manage_options')) {
        return false;
    }

    // Sanitizar configuración - SOLO toggles permitidos
    $sanitized = array();
    $allowed_toggles = array(
        'device_type',
        'browser',
        'os',
        'screen_width',
        'ip_address',
        'fingerprint_enabled',  // ✅ v1.5.4 - Toggle de fingerprint completo
        // v2.1.3 - Metadatos extendidos para exportación
        'export_canvas_fingerprint',
        'export_webgl_renderer',
        'export_screen_resolution',
        'export_screen_depth',
        'export_pixel_ratio',
        'export_timezone',
        'export_language',
        'export_cpu_cores',
        'export_ram',
        'export_plugins',
        'export_touch_support',
        'export_cookies_enabled',
    );

    foreach ($config as $key => $value) {
        if (in_array($key, $allowed_toggles)) {
            $sanitized[$key] = (bool) $value;
        }
    }

    return update_option('eipsi_global_privacy_defaults', $sanitized);
}

/**
 * Obtiene configuración de privacidad para un formulario específico
 *
 * @param string $form_id Form ID
 * @return array Privacy configuration
 */
function get_privacy_config($form_id = null) {
    // Si no hay form_id, devolver defaults generales
    if (!$form_id) {
        return get_privacy_defaults();
    }

    // Obtener configuración específica del formulario
    $saved = get_option("eipsi_privacy_config_{$form_id}");

    // Si no hay configuración específica, usar la global
    if (!$saved || !is_array($saved)) {
        return get_global_privacy_defaults();
    }

    // Devolver configuración específica del formulario
    return $saved;
}

/**
 * Guarda configuración de privacidad para un formulario específico
 *
 * @param string $form_id Form ID
 * @param array $config Privacy configuration
 */
function save_privacy_config($form_id, $config) {
    if (!current_user_can('manage_options')) {
        return false;
    }

    // Sanitizar configuración - SOLO toggles permitidos
    $sanitized = array();
    $allowed_toggles = array(
        'device_type',
        'browser',
        'os',
        'screen_width',
        'ip_address',
        'fingerprint_enabled',  // ✅ v1.5.4 - Toggle de fingerprint completo
        // v2.1.3 - Metadatos extendidos para exportación
        'export_canvas_fingerprint',
        'export_webgl_renderer',
        'export_screen_resolution',
        'export_screen_depth',
        'export_pixel_ratio',
        'export_timezone',
        'export_language',
        'export_cpu_cores',
        'export_ram',
        'export_plugins',
        'export_touch_support',
        'export_cookies_enabled',
    );

    foreach ($config as $key => $value) {
        if (in_array($key, $allowed_toggles)) {
            $sanitized[$key] = (bool) $value;
        }
    }

    return update_option("eipsi_privacy_config_{$form_id}", $sanitized);
}

/** Remove disabled technical categories recursively before any persistence path. */
function eipsi_filter_capture_data($value, $config) {
    if (!is_array($value)) { return $value; }
    $categories = array(
        'ip_address' => array('ip', 'ip_address', 'consent_ip', 'consent_ip_address', 'remote_addr'),
        'user_agent_full' => array('user_agent', 'user_agent_full', 'consent_user_agent', 'http_user_agent'),
        'device_type' => array('device', 'device_type'),
        'browser' => array('browser'), 'os' => array('os', 'platform'),
        'screen_width' => array('screen_width'),
        'fingerprint_enabled' => array('user_fingerprint', 'fingerprint', 'fingerprint_raw', 'eipsi_user_fingerprint', 'eipsi_fingerprint_raw', 'canvas_fingerprint', 'device_data', 'eipsi_device_data'),
        'movement_tracking' => array('movement_tracking', 'mouse_movements', 'mouse_tracking'),
        'mood_tracking' => array('mood_tracking'),
    );
    foreach (array('canvas_fingerprint','webgl_renderer','screen_resolution','screen_depth','pixel_ratio','timezone','language','cpu_cores','ram','plugins','touch_support','cookies_enabled') as $field) {
        $categories['export_' . $field] = array($field);
        $categories['fingerprint_enabled'][] = $field;
    }
    $categories['export_timezone'][] = 'timezone_offset';
    $categories['export_language'][] = 'languages';
    $categories['export_touch_support'][] = 'max_touch_points';
    $categories['export_ram'][] = 'ram_gb';
    $categories['export_plugins'][] = 'browser_plugins';
    $categories['fingerprint_enabled'] = array_merge($categories['fingerprint_enabled'], array('timezone_offset','languages','max_touch_points','ram_gb','browser_plugins'));
    foreach ($value as $key => $item) {
        $disabled = false;
        foreach ($categories as $toggle => $fields) {
            $default = in_array($toggle, array('ip_address','fingerprint_enabled'), true);
            if (in_array(strtolower((string) $key), $fields, true) && !($config[$toggle] ?? $default)) { $disabled = true; break; }
        }
        if ($disabled) { unset($value[$key]); }
        elseif (is_array($item)) { $value[$key] = eipsi_filter_capture_data($item, $config); }
        elseif (is_string($item) && in_array($key, array('metadata','form_responses','responses_json','form_data','eipsi_device_data','eipsi_fingerprint_raw'), true)) {
            $decoded = json_decode($item, true);
            if (is_array($decoded)) { $value[$key] = wp_json_encode(eipsi_filter_capture_data($decoded, $config)); }
        }
    }
    return $value;
}
