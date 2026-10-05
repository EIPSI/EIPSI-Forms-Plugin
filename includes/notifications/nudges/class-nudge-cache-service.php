<?php
/** Notifications owner; public WordPress adapters retain existing contracts. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Notification_Nudge_Cache_Service {
const CACHE_PREFIX = 'eipsi_nudge_';
const DEFAULT_TTL = DAY_IN_SECONDS;
const SHORT_TTL = 300;
public static function get_trigger_timestamp($assignment_id, $stage, $assignment = null, $wave = null) {
        $cache_key = self::get_cache_key($assignment_id, $stage, 'trigger_ts');

        // Intentar obtener de cache
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf(
                    '[EIPSI NudgeCache] CACHE HIT: trigger_ts for assignment %d, stage %d = %s',
                    $assignment_id,
                    $stage,
                    date('Y-m-d H:i:s', $cached)
                ));
            }
            return intval($cached);
        }

        // Calcular
        $timestamp = self::calculate_trigger_timestamp($assignment_id, $stage, $assignment, $wave);

        if ($timestamp !== false) {
            // Guardar en cache (TTL largo porque el cálculo nunca cambia)
            set_transient($cache_key, $timestamp, self::DEFAULT_TTL);

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf(
                    '[EIPSI NudgeCache] CACHE MISS: Calculated and cached trigger_ts for assignment %d, stage %d = %s',
                    $assignment_id,
                    $stage,
                    date('Y-m-d H:i:s', $timestamp)
                ));
            }
        }

        return $timestamp;
    }

public static function is_nudge_sent($assignment_id, $stage) {
        $cache_key = self::get_cache_key($assignment_id, $stage, 'sent');

        $cached = get_transient($cache_key);

        if ($cached === '1') {
            return true;
        } elseif ($cached === '0') {
            return false;
        }

        // No hay cache, devolver null para indicar que hay que consultar BD
        return null;
    }

public static function mark_nudge_sent($assignment_id, $stage) {
        $cache_key = self::get_cache_key($assignment_id, $stage, 'sent');
        set_transient($cache_key, '1', self::DEFAULT_TTL);

        // Invalidar cache de "debería enviarse" si existe
        $should_send_key = self::get_cache_key($assignment_id, $stage, 'should_send');
        delete_transient($should_send_key);
    }

public static function cache_should_send($assignment_id, $stage, $result, $ttl = self::SHORT_TTL) {
        $cache_key = self::get_cache_key($assignment_id, $stage, 'should_send');
        set_transient($cache_key, $result ? '1' : '0', $ttl);
    }

public static function get_cached_should_send($assignment_id, $stage) {
        $cache_key = self::get_cache_key($assignment_id, $stage, 'should_send');
        $cached = get_transient($cache_key);

        if ($cached === '1') return true;
        if ($cached === '0') return false;
        return null;
    }

public static function invalidate_assignment_cache($assignment_id) {
        // WordPress no permite listar transients por patrón, así que
        // usamos un enfoque de "versión de cache"
        $version_key = self::CACHE_PREFIX . "assignment_{$assignment_id}_version";
        $current_version = get_transient($version_key);
        $new_version = $current_version ? $current_version + 1 : 1;

        set_transient($version_key, $new_version, self::DEFAULT_TTL);

        // También invalidar cálculos específicos
        for ($stage = 0; $stage <= 4; $stage++) {
            delete_transient(self::get_cache_key($assignment_id, $stage, 'trigger_ts'));
            delete_transient(self::get_cache_key($assignment_id, $stage, 'sent'));
            delete_transient(self::get_cache_key($assignment_id, $stage, 'should_send'));
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[EIPSI NudgeCache] Invalidated all cache for assignment %d (version: %d)',
                $assignment_id,
                $new_version
            ));
        }
    }

private static function calculate_trigger_timestamp($assignment_id,$stage,$assignment=null,$wave=null) { return EIPSI_Notification_Nudge_Policy_Service::cached_trigger_timestamp($assignment_id,$stage,$assignment,$wave); }

private static function get_cache_key($assignment_id, $stage, $type) {
        // Incluir versión de cache en la clave para invalidación eficiente
        $version_key = self::CACHE_PREFIX . "assignment_{$assignment_id}_version";
        $version = get_transient($version_key);
        $version_suffix = $version ? "_v{$version}" : "_v0";

        return self::CACHE_PREFIX . "a{$assignment_id}_s{$stage}_{$type}{$version_suffix}";
    }

private static function convert_to_seconds($value, $unit) {
        switch ($unit) {
            case 'minutes':
                return $value * 60;
            case 'hours':
                return $value * 3600;
            case 'days':
                return $value * 86400;
            default:
                return $value * 3600;
        }
    }

public static function get_stats() {
        global $wpdb;

        // WordPress no expone estadísticas de transients fácilmente
        // Esta función puede extenderse con un plugin de cache externo

        return array(
            'hits' => get_transient(self::CACHE_PREFIX . 'stats_hits') ?: 0,
            'misses' => get_transient(self::CACHE_PREFIX . 'stats_misses') ?: 0,
            'note' => 'Stats require external cache implementation for accurate tracking'
        );
    }

public static function clear_all_cache() {
        global $wpdb;

        // Eliminar todos los transients con nuestro prefijo
        // Nota: esto puede ser lento en sitios grandes
        $option_names = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                '_transient_' . self::CACHE_PREFIX . '%'
            )
        );

        foreach ($option_names as $option_name) {
            $transient_name = str_replace('_transient_', '', $option_name);
            delete_transient($transient_name);
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf('[EIPSI NudgeCache] Cleared %d cached entries', count($option_names)));
        }

        return count($option_names);
    }
}
