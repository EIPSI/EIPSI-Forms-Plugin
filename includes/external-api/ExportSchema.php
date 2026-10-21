<?php
namespace EIPSI\ExternalApi;
/** Derived, private infrastructure only. Installed by M8, never by a worker. */
final class ExportSchema {
    public static function definitions(): array {
        $id='varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL';
        return array(
            'eipsi_external_exports'=>array('engine'=>'InnoDB','columns'=>array(
                'id'=>'char(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL','key_id'=>'char(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL',
                'study_id'=>$id,'study_code'=>$id,'format'=>'varchar(4) NOT NULL','include_partials'=>'tinyint unsigned NOT NULL',
                'state'=>'varchar(12) NOT NULL','as_of'=>'varchar(30) NOT NULL','created_at'=>'bigint unsigned NOT NULL','expires_at'=>'bigint unsigned NOT NULL',
                'source_count'=>'bigint unsigned NOT NULL DEFAULT 0','next_source'=>'bigint unsigned NOT NULL DEFAULT 0',
                'row_count'=>'bigint unsigned NOT NULL DEFAULT 0','byte_count'=>'bigint unsigned NOT NULL DEFAULT 0','chunk_count'=>'bigint unsigned NOT NULL DEFAULT 0',
                'lease_token'=>'char(32) DEFAULT NULL','lease_until'=>'bigint unsigned NOT NULL DEFAULT 0','download_until'=>'bigint unsigned NOT NULL DEFAULT 0',
                'storage_bytes'=>'bigint unsigned NOT NULL DEFAULT 0','purged_at'=>'bigint unsigned DEFAULT NULL',
                'operations_json'=>'text DEFAULT NULL','last_progress_at'=>'bigint unsigned NOT NULL DEFAULT 0','failure_reason'=>'varchar(32) DEFAULT NULL',
                'failures'=>'int unsigned NOT NULL DEFAULT 0','error_code'=>'varchar(80) DEFAULT NULL','manifest_json'=>'longtext DEFAULT NULL'
            ),'indices'=>array('PRIMARY KEY  (id)','KEY owner_state (key_id,state)','KEY expiry (expires_at)','KEY study_state (study_id,state)')),
            'eipsi_external_export_sources'=>array('engine'=>'InnoDB','columns'=>array(
                'export_id'=>'char(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL','sequence'=>'bigint unsigned NOT NULL',
                'execution_id'=>$id,'references_json'=>'longtext NOT NULL'
            ),'indices'=>array('PRIMARY KEY  (export_id,sequence)')),
            'eipsi_external_export_chunks'=>array('engine'=>'InnoDB','columns'=>array(
                'export_id'=>'char(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL','sequence'=>'bigint unsigned NOT NULL','kind'=>'varchar(12) NOT NULL',
                'row_count'=>'bigint unsigned NOT NULL','byte_count'=>'bigint unsigned NOT NULL','sha256'=>'char(64) NOT NULL','payload'=>'longblob NOT NULL'
            ),'indices'=>array('PRIMARY KEY  (export_id,kind,sequence)'))
        );
    }
    public static function install(): void {
        foreach(array_keys(self::definitions())as$slug){$r=\EIPSI_Schema_Repair_Service::sync_local_table($slug);if(!$r['success'])throw new \RuntimeException('External export schema unavailable');}
    }
}
