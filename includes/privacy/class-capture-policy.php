<?php
/** M6 owner; existing public adapters retain their contracts. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Privacy_Capture_Policy {

public static function eipsi_filter_capture_data($value, $config) {
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
}
