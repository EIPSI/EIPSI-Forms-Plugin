<?php
namespace EIPSI\StudyEngine\Management;
final class Schema {
    public static function definitions(): array {
        $id='varchar(128) CHARACTER SET ascii COLLATE ascii_bin';
        return array(
            'eipsi_engine_management_events'=>array('columns'=>array('id'=>'bigint unsigned NOT NULL AUTO_INCREMENT','study_id'=>"$id NOT NULL",'execution_id'=>"$id DEFAULT NULL",'entity_id'=>"$id DEFAULT NULL",'action'=>'varchar(64) NOT NULL','actor_id'=>'bigint unsigned NOT NULL DEFAULT 0','created_at'=>'datetime NOT NULL','details_json'=>'longtext NOT NULL'),'indices'=>array('PRIMARY KEY  (id)','KEY study_time (study_id,id)','KEY execution_time (execution_id,id)','KEY entity_time (study_id,entity_id,id)'),'engine'=>'InnoDB'),
            'eipsi_engine_management_exports'=>array('columns'=>array('id'=>"$id NOT NULL",'study_id'=>"$id NOT NULL",'actor_id'=>'bigint unsigned NOT NULL','request_id'=>"$id NOT NULL",'state'=>'varchar(24) NOT NULL','created_at'=>'datetime NOT NULL','expires_at'=>'datetime NOT NULL','spec_json'=>'longtext NOT NULL','plan_json'=>'longtext NOT NULL','cursor_position'=>'bigint unsigned NOT NULL DEFAULT 0','payload_json'=>'longtext DEFAULT NULL','manifest_json'=>'longtext DEFAULT NULL','error_code'=>'varchar(64) DEFAULT NULL'),'indices'=>array('PRIMARY KEY  (id)','UNIQUE KEY actor_request (actor_id,request_id)','KEY study_created (study_id,created_at)','KEY expiry (expires_at)'),'engine'=>'InnoDB')
        );
    }
    public static function nativeIndex(): void {
        global $wpdb;$table=$wpdb->prefix.'eipsi_engine_native_executions';
        if(!$wpdb->get_var($wpdb->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$table)))return;
        foreach(array('management_execution'=>'execution_id','management_opportunity'=>'block_execution_id') as $column=>$path){
            if(!$wpdb->get_var($wpdb->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND COLUMN_NAME=%s',$table,$column))){
                if($wpdb->query("ALTER TABLE `$table` ADD COLUMN $column varchar(128) CHARACTER SET ascii COLLATE ascii_bin GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(binding_json,'$.$path'))) STORED")===false)throw new \RuntimeException('MANAGEMENT_NATIVE_INDEX_FAILED');
            }
        }
        if(!$wpdb->get_var($wpdb->prepare('SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND INDEX_NAME=%s',$table,'management_binding'))&&$wpdb->query("ALTER TABLE `$table` ADD INDEX management_binding (management_execution,management_opportunity)")===false)throw new \RuntimeException('MANAGEMENT_NATIVE_INDEX_FAILED');
    }
}
