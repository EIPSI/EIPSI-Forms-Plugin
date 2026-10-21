<?php
if (!defined('ABSPATH')) { exit; }
class EIPSI_Schema_Repair_Service {
    public static function sync_local_table($slug) {
        try { return EIPSI_Schema_Lock::run(function() use($slug) { return self::sync_locked($slug); }); }
        catch (Throwable $e) { return array('success'=>false,'exists'=>false,'created'=>false,'columns_added'=>array(),'error'=>$e->getMessage()); }
    }
    private static function sync_locked($slug) {
        global $wpdb;
        $map = EIPSI_Schema_Registry::get_schema_map();
        if (!isset($map[$slug])) { throw new RuntimeException('Unknown schema table'); }
        $table = $wpdb->prefix . $slug;
        $before = EIPSI_Schema_Inspector::inspect($slug);
        $result = array('success'=>false,'exists'=>$before['exists'],'created'=>false,'columns_added'=>array(),'error'=>null);
        if (!$before['exists']) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            $wpdb->last_error = '';
            dbDelta(EIPSI_Schema_Registry::get_table_sql($slug));
            if ($wpdb->last_error) { throw new RuntimeException($wpdb->last_error); }
            $result['created'] = true;
        } else {
            // Additive drift repair only. Type conversions belong to known migrations.
            foreach ($before['missing_columns'] as $column) {
                $definition = $map[$slug]['columns'][$column];
                if (stripos($definition,'NOT NULL') !== false && stripos($definition,'DEFAULT') === false && (int)$wpdb->get_var("SELECT COUNT(*) FROM `$table`") > 0) {
                    throw new RuntimeException('Unsafe required-column backfill: '.$slug.'.'.$column);
                }
                if ($wpdb->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition") === false) { throw new RuntimeException($wpdb->last_error ?: 'Column repair failed'); }
                $result['columns_added'][] = $column;
            }
            foreach ($before['missing_indexes'] as $index) {
                if ($wpdb->query("ALTER TABLE `$table` ADD $index") === false) { throw new RuntimeException($wpdb->last_error ?: 'Index repair failed'); }
            }
        }
        $after = EIPSI_Schema_Inspector::inspect($slug);
        $result['exists'] = $after['exists'];
        $result['success'] = $after['exists'] && !$after['missing_columns'] && !$after['missing_indexes'] && !$after['incompatible_types'];
        $result['error'] = $result['success'] ? null : 'Schema verification failed: '.wp_json_encode($after['issues']);
        return $result;
    }
    public static function fix_collations() {
        // Explicit report; never perform an unproven mass charset conversion.
        $issues = EIPSI_Schema_Inspector::check_collation_issues();
        return array('success'=>!$issues['issues'],'total_fixed'=>0,'issues'=>$issues['issues'],'requires_manual_review'=>!empty($issues['issues']));
    }
}
