<?php
if (!defined('ABSPATH')) { exit; }
class EIPSI_Schema_Lock {
    public static function run($callback) {
        global $wpdb;
        $key = 'eipsi-schema-' . md5(DB_NAME . ':' . $wpdb->prefix);
        if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $key)) !== 1) { throw new RuntimeException('Schema operation busy; retry later'); }
        try { return $callback(); }
        finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $key)); }
    }
}
