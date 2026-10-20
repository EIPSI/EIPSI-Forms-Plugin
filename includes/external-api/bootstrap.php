<?php
if(!defined('ABSPATH'))exit;
foreach(array('Schema','Store','Keys','Authorization','Cursor','StudyDiscoveryQuery','DatasetCursor','StudyDataExport','ExportSchema','ExportStorage','SnapshotCursor','ExportDownload','DatasetSnapshots','OperationsSchema','OperationsSettings','OperationsMetrics','Operations','ExternalApiHealth','OperationsAdmin','Rest','Admin') as $file)require_once __DIR__.'/'.$file.'.php';
unset($file);
$eipsi_external_rest=new \EIPSI\ExternalApi\Rest();
add_action('rest_api_init',array($eipsi_external_rest,'register'),11);
add_filter('rest_pre_dispatch',array($eipsi_external_rest,'preDispatch'),1,3);
add_filter('rest_post_dispatch',array($eipsi_external_rest,'headers'),20,3);
add_filter('rest_pre_serve_request',array($eipsi_external_rest,'noCors'),PHP_INT_MAX,4);
add_action('wp_ajax_eipsi_external_api',array('EIPSI\\ExternalApi\\Admin','ajax'));
if(defined('WP_CLI')&&WP_CLI)require_once __DIR__.'/OperationsCli.php';
