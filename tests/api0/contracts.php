<?php
/** Only the explicitly added API-0/API-1 surface is excluded from historical contracts. */
require_once dirname(__DIR__).'/sch1/contracts.php';
function api0_hook($r){
 if(sch1_hook($r))return true;
 $map=array('EIPSI\\ExternalApi\\Rest::register'=>array('rest_api_init',11,1),'EIPSI\\ExternalApi\\Rest::preDispatch'=>array('rest_pre_dispatch',1,3),'EIPSI\\ExternalApi\\Rest::headers'=>array('rest_post_dispatch',20,3),'EIPSI\\ExternalApi\\Rest::noCors'=>array('rest_pre_serve_request',PHP_INT_MAX,4),'EIPSI\\ExternalApi\\Admin::ajax'=>array('wp_ajax_eipsi_external_api',10,1));
 $cb=$r['callback']??'';if(!isset($map[$cb]))return false;$v=$map[$cb];m0_assert($r['hook']===$v[0]&&$r['priority']===$v[1]&&$r['accepted_args']===$v[2],'API0 hook contract');return true;
}
function api0_include($f){if(sch1_include($f))return true;if(in_array($f,array_map(static function($n){return 'includes/dataset/'.$n.'.php';},array('bootstrap','CanonicalStudyDataset','Source','Transport','SnapshotV2','EffectiveMetadataPolicy')),true))return true;if(in_array($f,array('includes/study-engine/management/ScientificReadQuery.php','includes/study-engine/management/ScientificDatasetOwner.php'),true))return true;return in_array($f,array_map(static function($n){return 'includes/external-api/'.$n.'.php';},array('bootstrap','Schema','Store','Keys','Authorization','Cursor','StudyDiscoveryQuery','DatasetCursor','StudyDataExport','ExportSchema','ExportStorage','SnapshotCursor','ExportDownload','DatasetSnapshots','OperationsSchema','OperationsSettings','OperationsMetrics','Operations','ExternalApiHealth','OperationsAdmin','Rest','Admin')),true);}
