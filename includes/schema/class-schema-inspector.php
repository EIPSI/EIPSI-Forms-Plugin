<?php
/** M8 definition owner; historical public contracts remain facades. */
if (!defined('ABSPATH')) { exit; }

class EIPSI_Schema_Inspector {
public static function get_verification_status() {

        $last_verified = get_option( 'eipsi_schema_last_verified', null );

        return array(

            'last_verified' => $last_verified,

            'last_sync_result' => get_option( 'eipsi_schema_last_sync_result', null ),

            'needs_verification' => empty( $last_verified ) || ( current_time( 'timestamp' ) - strtotime( $last_verified ) ) > 86400,

        );

    }

public static function get_all_tables_status() {

        $order = EIPSI_Schema_Registry::get_table_creation_order();

        $status = array();

        foreach ( $order as $slug ) {

            $status[$slug] = self::get_detailed_table_status( $slug );

        }

        return $status;

    }

public static function get_detailed_table_status( $slug ) {

        global $wpdb;

        $full_table_name = $wpdb->prefix . $slug;

        $result = array(

            'table_name' => $slug,

            'full_table_name' => $full_table_name,

            'exists' => self::local_table_exists( $full_table_name ),

            'row_count' => 0,

            'status' => 'ok',

            'issues' => array(),

            'columns' => array(),

            'required_columns' => array(),

            'missing_columns' => array(),

            'indexes' => array(),

            'size_mb' => 0

        );

        if ( ! $result['exists'] ) {

            $result['status'] = 'error';

            $result['issues'][] = 'Table does not exist';

            return $result;

        }

        $result['row_count'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $full_table_name" );

        

        // Get table size

        $table_status = $wpdb->get_row( $wpdb->prepare( "SHOW TABLE STATUS LIKE %s", $full_table_name ), ARRAY_A );

        if ( $table_status ) {

            $result['size_mb'] = round( ( $table_status['Data_length'] + $table_status['Index_length'] ) / 1024 / 1024, 2 );

        }

        

        // Get columns

        $columns = $wpdb->get_results( "SHOW COLUMNS FROM $full_table_name", ARRAY_A );

        if ( $columns ) {

            foreach ( $columns as $col ) {

                $result['columns'][] = $col['Field'];

            }

        }

        

        // Get indexes

        $indexes = $wpdb->get_results( "SHOW INDEX FROM $full_table_name", ARRAY_A );

        if ( $indexes ) {

            $index_names = array();

            foreach ( $indexes as $idx ) {

                $index_names[] = $idx['Key_name'];

            }

            $result['indexes'] = array_unique( $index_names );

        }

        

        $inspection = self::inspect($slug);
        $result['required_columns'] = array_keys(EIPSI_Schema_Registry::get_schema_map()[$slug]['columns']);
        $result['missing_columns'] = $inspection['missing_columns'];
        $result['issues'] = array_map('wp_json_encode', $inspection['issues']);
        if ($result['issues']) { $result['status'] = 'warning'; }
        return $result;

    }

public static function get_schema_health_summary() {

        $all_tables = self::get_all_tables_status();

        $summary = array(

            'total_tables' => count( $all_tables ),

            'healthy_tables' => 0,

            'warning_tables' => 0,

            'error_tables' => 0,

            'total_rows' => 0,

            'total_size_mb' => 0,

            'last_verified' => get_option( 'eipsi_schema_last_verified', null ),

        );

        foreach ( $all_tables as $table ) {

            if ( $table['status'] === 'ok' ) {

                $summary['healthy_tables']++;

                $summary['total_rows'] += $table['row_count'];

                $summary['total_size_mb'] += $table['size_mb'];

            } elseif ($table['status'] === 'warning') {
                $summary['warning_tables']++;
            } else {

                $summary['error_tables']++;

            }

        }

        $summary['health_score'] = $summary['total_tables'] > 0 ? round( ( $summary['healthy_tables'] / $summary['total_tables'] ) * 100 ) : 0;

        return $summary;

    }

public static function local_table_exists( $table_name ) {

        global $wpdb;

        return $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $wpdb->esc_like($table_name) ) ) === $table_name;

    }
    public static function normalized_type($type) {
        $type = strtolower(preg_replace('/\s+/', ' ', trim($type)));
        $type = preg_replace('/\s*,\s*/', ',', $type);
        return preg_replace('/\b(tinyint|smallint|mediumint|int|bigint)\(\d+\)/', '$1', $type);
    }
    public static function inspect($slug) {
        global $wpdb;
        $map = EIPSI_Schema_Registry::get_schema_map();
        if (!isset($map[$slug])) { throw new InvalidArgumentException('Unknown table'); }
        $table = $wpdb->prefix.$slug;
        $result = array('table'=>$slug,'exists'=>self::local_table_exists($table),'missing_columns'=>array(),'missing_indexes'=>array(),'incompatible_types'=>array(),'issues'=>array());
        if (!$result['exists']) { $result['missing_columns']=array_keys($map[$slug]['columns']);$result['issues'][]=array('kind'=>'missing_table');return $result; }
        $columns = $wpdb->get_results("SHOW FULL COLUMNS FROM `$table`",ARRAY_A);
        $actual = array_column($columns,null,'Field');
        $create = null;
        foreach($map[$slug]['columns'] as $name=>$definition) {
            if(!isset($actual[$name])) { $result['missing_columns'][]=$name;$result['issues'][]=array('kind'=>'missing_column','column'=>$name);continue; }
            preg_match('/^([a-z]+(?:\([^)]*\))?(?: unsigned)?)/i',$definition,$match);
            $compatible = self::normalized_type($match[1])===self::normalized_type($actual[$name]['Type']);
            // MariaDB implements JSON as LONGTEXT plus a JSON_VALID constraint.
            // Plain LONGTEXT must still be reported as incompatible.
            if (!$compatible && strtolower($match[1]) === 'json' && strtolower($actual[$name]['Type']) === 'longtext') {
                if ($create === null) { $row = $wpdb->get_row("SHOW CREATE TABLE `$table`", ARRAY_N); $create = $row[1] ?? ''; }
                $compatible = (bool) preg_match('/json_valid\\s*\\(\\s*`'.preg_quote($name, '/').'`\\s*\\)/i', $create);
            }
            if(!$compatible) {
                $result['incompatible_types'][]=$name;$result['issues'][]=array('kind'=>'incompatible_type','column'=>$name,'expected'=>$match[1],'actual'=>$actual[$name]['Type']);
            }
        }
        $indexes = $wpdb->get_results("SHOW INDEX FROM `$table`",ARRAY_A);$keys=array();
        foreach($indexes as $index) { $keys[$index['Key_name']]['columns'][]=$index['Column_name'];$keys[$index['Key_name']]['unique']=!(int)$index['Non_unique']; }
        foreach($map[$slug]['indices'] as $definition) {
            preg_match('/^(PRIMARY KEY|UNIQUE KEY|KEY)\s*(\w+)?\s*\(([^)]+)\)/i',$definition,$m);
            $name=strtoupper($m[1])==='PRIMARY KEY'?'PRIMARY':$m[2];$expected=array_map('trim',explode(',',$m[3]));
            if(!isset($keys[$name])||$keys[$name]['columns']!==$expected||$keys[$name]['unique']!==(strtoupper($m[1])!=='KEY')) {
                $result['missing_indexes'][]=$definition;$result['issues'][]=array('kind'=>'missing_index','index'=>$name);
            }
        }
        return $result;
    }
    public static function check_collation_issues() {
        global $wpdb;
        $issues=array();$expected=$wpdb->collate ?: 'utf8mb4_unicode_ci';
        foreach(array_keys(EIPSI_Schema_Registry::get_schema_map()) as $slug) {
            $status=$wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS LIKE %s',$wpdb->esc_like($wpdb->prefix.$slug)),ARRAY_A);
            if($status && $status['Collation']!==$expected) { $issues[]=array('table'=>$slug,'expected'=>$expected,'actual'=>$status['Collation']); }
        }
        return array('issues'=>$issues,'has_issues'=>!empty($issues),'expected_collation'=>$expected);
    }

    public static function periodic_inspection() {
        $last = (int) get_option('eipsi_schema_last_inspected', 0);
        if (time() - $last < DAY_IN_SECONDS) { return; }
        $report = array();
        foreach (array_keys(EIPSI_Schema_Registry::get_schema_map()) as $slug) {
            $inspection = self::inspect($slug);
            if ($inspection['issues']) { $report[$slug] = $inspection['issues']; }
        }
        update_option('eipsi_schema_inspection_issues', $report, false);
        update_option('eipsi_schema_last_inspected', time(), false);
    }

}
