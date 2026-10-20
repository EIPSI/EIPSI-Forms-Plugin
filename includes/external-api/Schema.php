<?php
namespace EIPSI\ExternalApi;

final class Schema {
    public static function definitions(): array {
        return array(
            'eipsi_external_keys'=>array('engine'=>'InnoDB','columns'=>array(
                'id'=>'varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL',
                'name'=>'varchar(120) NOT NULL', 'secret_hash'=>'char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL',
                'display_suffix'=>'char(4) CHARACTER SET ascii COLLATE ascii_bin NOT NULL',
                'created_at'=>'bigint unsigned NOT NULL','created_by'=>'bigint unsigned NOT NULL',
                'last_used_at'=>'bigint unsigned DEFAULT NULL','revoked_at'=>'bigint unsigned DEFAULT NULL',
                'expires_at'=>'bigint unsigned DEFAULT NULL','scopes_json'=>'varchar(100) NOT NULL',
                'grant_mode'=>"varchar(10) NOT NULL DEFAULT 'NONE'",'revision'=>'bigint unsigned NOT NULL DEFAULT 1'
            ),'indices'=>array('PRIMARY KEY  (id)')),
            'eipsi_external_grants'=>array('engine'=>'InnoDB','columns'=>array(
                'key_id'=>'varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL',
                'study_id'=>'varchar(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL'
            ),'indices'=>array('PRIMARY KEY  (key_id,study_id)','KEY study_id (study_id)')),
            'eipsi_external_audit'=>array('engine'=>'InnoDB','columns'=>array(
                'id'=>'bigint unsigned NOT NULL AUTO_INCREMENT',
                'aggregate_key'=>'char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL',
                'export_id'=>"varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT ''",
                'event'=>'varchar(40) NOT NULL','key_id'=>"varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT ''",
                'actor_id'=>'bigint unsigned NOT NULL DEFAULT 0','request_id'=>'char(32) NOT NULL',
                'created_at'=>'bigint unsigned NOT NULL','last_at'=>'bigint unsigned NOT NULL',
                'event_count'=>'bigint unsigned NOT NULL DEFAULT 1','details_json'=>'text NOT NULL'
            ),'indices'=>array('PRIMARY KEY  (id)','UNIQUE KEY aggregate_key (aggregate_key)','KEY key_time (key_id,created_at)','KEY event_time (event,created_at)','KEY export_time (export_id,created_at)','KEY created_id (created_at,id)'))
        );
    }
    public static function install(): void {
        foreach(array_keys(self::definitions()) as $slug){
            $r=\EIPSI_Schema_Repair_Service::sync_local_table($slug);
            if(!$r['success'])throw new \RuntimeException('External API schema unavailable');
        }
        add_option(Store::ENABLED,'0','',false);
    }
}
