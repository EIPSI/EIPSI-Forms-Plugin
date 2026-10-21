<?php
/** Read-only report for the administrator; no installers or repair operations. */
if (!defined('ABSPATH')) { exit; }
require_once __DIR__ . '/bootstrap.php';

final class EIPSI_Schema_Diagnostic {
    public static function report() {
        global $wpdb;
        $tables = array();
        foreach (EIPSI_Schema_Registry::get_table_creation_order() as $slug) {
            $inspection = EIPSI_Schema_Inspector::inspect($slug);
            $tables[$slug] = array(
                'scope' => 'columns_types_indexes_and_declared_engine',
                'status' => empty($inspection['issues']) ? 'ok' : 'error',
                'issues' => $inspection['issues'],
            );
        }
        // These tables belong to Native Store, outside the shared schema registry.
        // Match Store::ready(): presence and InnoDB, without invoking installation.
        foreach (array('versions', 'executions', 'commands') as $name) {
            $slug = 'eipsi_engine_native_' . $name;
            $engine = $wpdb->get_var($wpdb->prepare(
                'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',
                $wpdb->prefix . $slug
            ));
            $issues = array();
            if (!$engine) { $issues[] = array('kind' => 'missing_table'); }
            elseif (strcasecmp($engine, 'InnoDB') !== 0) { $issues[] = array('kind' => 'incompatible_engine'); }
            $tables[$slug] = array('scope' => 'existence_and_innodb', 'status' => $issues ? 'error' : 'ok', 'issues' => $issues);
        }
        $installed = (int) get_option('eipsi_migration_version', 0);
        $latest = EIPSI_Schema_Migration_Runner::LATEST_VERSION;
        $problems = array_filter($tables, static function ($table) { return $table['status'] !== 'ok'; });
        return array(
            'generated_at' => gmdate('c'),
            'plugin_version' => defined('EIPSI_FORMS_VERSION') ? EIPSI_FORMS_VERSION : '',
            'wordpress_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'status' => !$problems && $installed === $latest ? 'ok' : 'attention',
            'migrations' => array('installed' => $installed, 'latest' => $latest, 'pending' => max(0, $latest - $installed)),
            'summary' => array('total_tables' => count($tables), 'tables_with_issues' => count($problems)),
            'tables' => $tables,
        );
    }

    public static function describe(array $issue) {
        $labels = array(
            'missing_table' => eipsi_admin_translate('Table missing.'),
            'missing_column' => eipsi_admin_translate('Column missing'),
            'incompatible_type' => eipsi_admin_translate('Column type mismatch'),
            'missing_index' => eipsi_admin_translate('Index missing or definition mismatch'),
            'incompatible_engine' => eipsi_admin_translate('Storage engine does not match the requirement.'),
        );
        $text = $labels[$issue['kind'] ?? ''] ?? eipsi_admin_translate('The structure requires technical review.');
        $detail = $issue['column'] ?? $issue['index'] ?? '';
        if ($detail !== '') { $text .= ': ' . $detail . '.'; }
        if (isset($issue['expected'], $issue['actual'])) {
            $text .= eipsi_admin_format(' Expected: %1$s; actual: %2$s.',$issue['expected'],$issue['actual']);
        }
        return $text;
    }
}
