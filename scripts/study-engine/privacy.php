<?php
/** Internal admin-scoped tool. No public handler and no raw destructive ID alone. */
if(!defined('WP_CLI')||!WP_CLI||!current_user_can('manage_options'))throw new RuntimeException('PRIVACY_ADMIN_REQUIRED');
require_once dirname(__DIR__,2).'/includes/study-engine/privacy.php';
use EIPSI\StudyEngine\Persistence as P;
use EIPSI\StudyEngine\Privacy as G;
$operation=$args[0]??'';$study=$args[1]??'';EIPSI\StudyEngine\Contracts\Check::id($study,'study');
if(in_array($operation,array('export','manifest'),true)){
    $dataset=EIPSI_Export_Query_Service::study_engine($study);
    if($operation==='manifest')echo wp_json_encode($dataset['manifest'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
    else{$stream=fopen('php://output','w');EIPSI_Raw_Export_Service::study_engine_csv($dataset,$stream);fclose($stream);}return;
}
$id=$args[2]??'';global $wpdb;$db=new P\Database($wpdb);$row=(new P\StudyExecutionRepository($db))->find($id);
if(!$row||$row['study_id']!==$study)throw new RuntimeException('PRIVACY_SCOPE_REQUIRED');
if($operation==='retry'){
    if(($args[3]??null)!==$id.'|DO_NOT_USE')throw new RuntimeException('EXPLICIT_B2_CONFIRMATION_REQUIRED');
    $result=EIPSI_Privacy_Data_Cleanup_Service::run_study_engine($db,$id);
}elseif(in_array($operation,array('status','coverage'),true))$result=G\Policy::outcome($db,$id);
else throw new InvalidArgumentException('status|coverage|retry|export|manifest STUDY_ID [EXECUTION_ID] [EXECUTION_ID|DO_NOT_USE]');
echo wp_json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
