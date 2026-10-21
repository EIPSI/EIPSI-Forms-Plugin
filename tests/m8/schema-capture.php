<?php
require __DIR__.'/../m0/bootstrap.php';
$map=EIPSI_Database_Schema_Manager::get_schema_map();$out=array('registry'=>$map,'tables'=>array(),'options'=>array());
foreach($wpdb->get_col('SHOW TABLES')as$table){if(strpos($table,$wpdb->prefix)!==0)continue;$slug=substr($table,strlen($wpdb->prefix));if(!isset($map[$slug])&&!preg_match('/^(survey_|eipsi_|vas_|T[0-9])/',$slug))continue;$out['tables'][$slug]=array('columns'=>$wpdb->get_results("SHOW FULL COLUMNS FROM `$table`",ARRAY_A),'indexes'=>$wpdb->get_results("SHOW INDEX FROM `$table`",ARRAY_A),'ddl'=>$wpdb->get_row("SHOW CREATE TABLE `$table`",ARRAY_N)[1],'rows'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM `$table`"));}
foreach(array('eipsi_migration_version','eipsi_db_schema_version','eipsi_autofix_schema_version','eipsi_fk_fix_version','eipsi_schema_revision')as$key)$out['options'][$key]=get_option($key,null);
ob_end_clean();echo wp_json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
