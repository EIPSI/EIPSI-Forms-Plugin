<?php
/** Creates a real network only in the disposable database; never writes wp-config. */
define('DOING_CRON',true);require dirname(__DIR__).'/m0/bootstrap.php';
require_once ABSPATH.'wp-admin/includes/schema.php';
foreach($wpdb->tables('ms_global') as$table=>$prefixed)$wpdb->$table=$prefixed;
install_network();$r=populate_network(1,'127.0.0.1:18080','m0@example.invalid','RBAC isolated network','/',false);
if(is_wp_error($r))throw new RuntimeException($r->get_error_code());
if(!$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->site}")||!$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->blogs}"))throw new RuntimeException('Network not populated');
echo "Disposable network prepared\n";ob_end_flush();
