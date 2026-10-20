<?php
namespace EIPSI\ExternalApi;
final class OperationsSchema {
    public static function definitions(): array {return array('eipsi_external_metrics'=>array('engine'=>'InnoDB','columns'=>array(
        'day'=>'date NOT NULL','category'=>'varchar(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL',
        'requests'=>'bigint unsigned NOT NULL DEFAULT 0','failures'=>'bigint unsigned NOT NULL DEFAULT 0',
        'duration_ms'=>'bigint unsigned NOT NULL DEFAULT 0','generated_bytes'=>'bigint unsigned NOT NULL DEFAULT 0',
        'downloaded_bytes'=>'bigint unsigned NOT NULL DEFAULT 0','rate_limits'=>'bigint unsigned NOT NULL DEFAULT 0',
        'cleanup_count'=>'bigint unsigned NOT NULL DEFAULT 0','worker_failures'=>'bigint unsigned NOT NULL DEFAULT 0'
    ),'indices'=>array('PRIMARY KEY  (day,category)')));}
    public static function install(): void {
        Schema::install();ExportSchema::install();foreach(array_keys(self::definitions())as$slug){$r=\EIPSI_Schema_Repair_Service::sync_local_table($slug);if(!$r['success'])throw new \RuntimeException('API operations schema unavailable');}
    }
}
