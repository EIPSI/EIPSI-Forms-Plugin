<?php
// Dedicated new Docker only: builds the captured pre-M8 schema before activation.
if(PHP_SAPI!=='cli'||getenv('WORDPRESS_DB_HOST')!=='eipsi-m0-db:3306'||getenv('WORDPRESS_DB_NAME')!=='m0')exit(1);
define('WP_INSTALLING',true);$_SERVER['HTTP_HOST']='127.0.0.1:18080';$_SERVER['REQUEST_URI']='/';require '/var/www/html/wp-load.php';
require_once ABSPATH.'wp-admin/includes/upgrade.php';require_once ABSPATH.'wp-admin/includes/plugin.php';
add_filter('pre_wp_mail','__return_true');
if(is_blog_installed())throw new RuntimeException('Upgrade fixture requires a completely new WP database');
wp_install('EIPSI M8 historical fixture','m0admin','m0@example.invalid',true,'','m0-isolated-admin');update_option('eipsi_m0_isolated_install',true);wp_set_current_user(1);add_filter('pre_wp_mail','__return_true');
$fixture=json_decode(file_get_contents(__DIR__.'/fixtures/pre-m8-schema.json'),true);$pending=$fixture['tables'];$created=[];
while($pending){$progress=false;foreach($pending as$slug=>$table){preg_match_all('/REFERENCES `wp_([^`]+)`/',$table['ddl'],$m);if(array_diff($m[1],$created))continue;$sql=str_replace('`wp_','`'.$wpdb->prefix,$table['ddl']);$sql=preg_replace('/ AUTO_INCREMENT=\d+/','',$sql);if($wpdb->query($sql)===false)throw new RuntimeException($wpdb->last_error);$created[]=$slug;unset($pending[$slug]);$progress=true;}if(!$progress)throw new RuntimeException('Cyclic historical fixture');}
// Logical relationships use the same owned ID; required text fields and timestamps are deterministic.
$before=[];foreach($created as$slug){$row=['id'=>995801];$columns=$wpdb->get_results("SHOW COLUMNS FROM `{$wpdb->prefix}$slug`",ARRAY_A);foreach($columns as$c){$name=$c['Field'];$type=$c['Type'];if($name==='id')continue;if(preg_match('/^(survey_id|study_id|participant_id|wave_id|assignment_id|pool_id)$/',$name)&&preg_match('/^(bigint|int)/i',$type)){$row[$name]=995801;continue;}if($c['Null']==='YES'||$c['Default']!==null)continue;if(preg_match('/^(bigint|int|tinyint|decimal|float|double)/i',$type))$row[$name]=1;elseif(preg_match('/^(datetime|timestamp|date)/i',$type))$row[$name]='2020-02-03 04:05:06';elseif(preg_match("/^enum\('([^']+)'/i",$type,$m))$row[$name]=$m[1];else$row[$name]='hist';}if($slug==='survey_participants')$row['email']='m8-history@example.invalid';if(isset($fixture['registry'][$slug]['columns']['form_responses']))$row['form_responses']='{"answer":"M8 preserved historical data"}';if($wpdb->insert($wpdb->prefix.$slug,$row)===false)throw new RuntimeException('Seed '.$slug.': '.$wpdb->last_error);$before[$slug]=$wpdb->get_row("SELECT * FROM `{$wpdb->prefix}$slug` WHERE id=995801",ARRAY_A);}
update_option('eipsi_migration_version',9);
$r=activate_plugin('EIPSI-Forms-Plugin/eipsi-forms.php');if(is_wp_error($r))throw new RuntimeException($r->get_error_message());
$r=(new EIPSI_Migration_Runner())->run();if(!$r['success'])throw new RuntimeException(wp_json_encode($r));
foreach($before as$slug=>$row){$actual=$wpdb->get_row("SELECT * FROM `{$wpdb->prefix}$slug` WHERE id=995801",ARRAY_A);foreach($row as$key=>$value){if(!array_key_exists($key,$actual)||$actual[$key]!==$value)throw new RuntimeException('Data changed '.$slug.'.'.$key);}}
foreach(array_keys(EIPSI_Schema_Registry::get_schema_map())as$slug){$r=EIPSI_Schema_Inspector::inspect($slug);if($r['issues'])throw new RuntimeException(wp_json_encode($r));}
update_option('eipsi_m8_historical_fixture',true,false);
echo 'M8 separate historical upgrade OK: '.count($before)." domains preserved; migration version 10\n";
