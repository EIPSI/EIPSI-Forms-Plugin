<?php
/**
 * Nudge Cache Service
 *
 * Sistema de cache para cálculos de nudges usando transients de WordPress.
 * Evita recalcular timestamps inmutables en cada ejecución del cron.
 *
 * @package EIPSI_Forms
 * @since 2.5.0
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once EIPSI_FORMS_PLUGIN_DIR . 'includes/notifications/bootstrap.php';


/**
 * Cache para cálculos de nudges
 */
class EIPSI_Nudge_Cache {

    /**
     * Prefijo para las claves de cache
     */
    const CACHE_PREFIX = 'eipsi_nudge_';

    /**
     * TTL por defecto: 24 horas (cálculos inmutables)
     */
    const DEFAULT_TTL = DAY_IN_SECONDS;

    /**
     * TTL corto: 5 minutos (datos que pueden cambiar)
     */
    const SHORT_TTL = 300;

    /**
     * Obtener timestamp de trigger para un nudge (con cache)
     *
     * @param int $assignment_id ID de la asignación
     * @param int $stage Número de nudge (0-4)
     * @param object|null $assignment Objeto de asignación (opcional, para evitar query)
     * @param object|null $wave Objeto de wave (opcional)
     * @return int|false Timestamp o false si no calculable
     */
    public static function get_trigger_timestamp($assignment_id, $stage, $assignment = null, $wave = null) {
        return EIPSI_Notification_Nudge_Cache_Service::get_trigger_timestamp($assignment_id, $stage, $assignment, $wave);
    }

    /**
     * Verificar si un nudge ya fue enviado (con cache)
     *
     * @param int $assignment_id ID de la asignación
     * @param int $stage Número de nudge
     * @return bool|null true=enviado, false=no enviado, null=desconocido
     */
    public static function is_nudge_sent($assignment_id, $stage) {
        return EIPSI_Notification_Nudge_Cache_Service::is_nudge_sent($assignment_id, $stage);
    }

    /**
     * Marcar nudge como enviado en cache
     *
     * @param int $assignment_id ID de la asignación
     * @param int $stage Número de nudge
     */
    public static function mark_nudge_sent($assignment_id, $stage) {
        return EIPSI_Notification_Nudge_Cache_Service::mark_nudge_sent($assignment_id, $stage);
    }

    /**
     * Cachear resultado de should_send_nudge
     *
     * @param int $assignment_id ID de la asignación
     * @param int $stage Número de nudge
     * @param bool $result Resultado
     * @param int $ttl Tiempo de vida (default: corto porque puede cambiar)
     */
    public static function cache_should_send($assignment_id, $stage, $result, $ttl = self::SHORT_TTL) {
        return EIPSI_Notification_Nudge_Cache_Service::cache_should_send($assignment_id, $stage, $result, $ttl);
    }

    /**
     * Obtener cache de should_send_nudge
     *
     * @param int $assignment_id ID de la asignación
     * @param int $stage Número de nudge
     * @return bool|null true/false o null si no hay cache
     */
    public static function get_cached_should_send($assignment_id, $stage) {
        return EIPSI_Notification_Nudge_Cache_Service::get_cached_should_send($assignment_id, $stage);
    }

    /**
     * Invalidar todo el cache para una asignación
     * Útil cuando el participante completa una toma o cambia estado
     *
     * @param int $assignment_id ID de la asignación
     */
    public static function invalidate_assignment_cache($assignment_id) {
        return EIPSI_Notification_Nudge_Cache_Service::invalidate_assignment_cache($assignment_id);
    }







    /**
     * Obtener estadísticas de cache (para debugging)
     */
    public static function get_stats() {
        return EIPSI_Notification_Nudge_Cache_Service::get_stats();
    }

    /**
     * Limpiar todo el cache de nudges (mantenimiento)
     */
    public static function clear_all_cache() {
        return EIPSI_Notification_Nudge_Cache_Service::clear_all_cache();
    }
}
