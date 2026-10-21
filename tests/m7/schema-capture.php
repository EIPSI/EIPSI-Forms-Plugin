<?php
require __DIR__.'/../m0/bootstrap.php';
$out=array('schema'=>array(),'postmeta_counts'=>array(),'saved_content'=>array());
foreach(array('eipsi_longitudinal_pools','eipsi_pool_assignments','eipsi_pool_analytics','eipsi_randomization_configs','eipsi_randomization_assignments','eipsi_manual_overrides')as$t){$row=$wpdb->get_row("SHOW CREATE TABLE {$wpdb->prefix}{$t}",ARRAY_N);$out['schema'][$t]=preg_replace('/AUTO_INCREMENT=\d+ /','',$row[1]);}
$out['postmeta_counts']=$wpdb->get_results("SELECT meta_key,COUNT(*) AS records FROM {$wpdb->postmeta} WHERE meta_key LIKE '%random%' GROUP BY meta_key",ARRAY_A);
$out['saved_content']=$wpdb->get_results("SELECT ID,post_type,post_status FROM {$wpdb->posts} WHERE post_content LIKE '%eipsi_randomization%' OR post_content LIKE '%eipsi-random%' OR post_content LIKE '%eipsi/pool%'",ARRAY_A);
ob_end_clean();echo wp_json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
