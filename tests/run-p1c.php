<?php
if (PHP_SAPI !== 'cli') { exit; }
define('EIPSI_P1C_TESTS',true);
require __DIR__.'/bootstrap-p1.php';
require EIPSI_FORMS_PLUGIN_DIR.'admin/config/longitudinal-config.php';
if(!class_exists('WP_Error')){require ABSPATH.'wp-includes/class-wp-error.php';}
function sanitize_textarea_field($value){return sanitize_text_field($value);}
function eipsi_user_can_manage_longitudinal(){return $GLOBALS['p0_admin'];}
function get_current_user_id(){return $GLOBALS['p0_admin']?1:0;}
function wp_get_current_user(){return (object)array('ID'=>get_current_user_id(),'user_login'=>'fixture');}
function wp_create_nonce($action){return 'valid:'.$action;}
function admin_url($path){return 'https://example.invalid/wp-admin/'.$path;}
function wp_mkdir_p($path){return is_dir($path)||mkdir($path,0755,true);}
function get_temp_dir(){return $GLOBALS['p1c_temp'];}
function plugin_dir_path($file){return dirname($file).'/';}
function parse_blocks($value){return array();}
function date_i18n($format,$timestamp=null){return date($format,$timestamp??time());}
require EIPSI_FORMS_PLUGIN_DIR.'admin/services/class-export-service.php';
require EIPSI_FORMS_PLUGIN_DIR.'admin/services/class-participant-data-request-service.php';
require EIPSI_FORMS_PLUGIN_DIR.'admin/services/class-anonymize-service.php';
require EIPSI_FORMS_PLUGIN_DIR.'admin/services/class-participant-data-cleanup.php';
require EIPSI_FORMS_PLUGIN_DIR.'admin/ajax-phase3-handlers.php';
function p1c_seed_response($db){
    $data=array('form_id'=>generate_stable_form_id('long-form'),'form_name'=>'long-form','participant_id'=>'7','survey_id'=>3,'wave_index'=>2,
        'session_id'=>'browser-fixture','user_fingerprint'=>'fingerprint-fixture','form_responses'=>'{"email":"p7@example.invalid","answer":4,"password_hash":"secret","session_token":"secret"}',
        'metadata'=>'{"email":"p7@example.invalid","ip_address":"203.0.113.17"}','ip_address'=>'203.0.113.17','created_at'=>current_time('mysql'),'submitted_at'=>current_time('mysql'));
    p0_assert($db->insert($db->prefix.'vas_form_results',$data)!==false,'Response fixture failed');return (int)$db->insert_id;
}
function p1c_request($db){
    p1_session();$result=EIPSI_Participant_Data_Request_Service::submit_request(7,'export');
    p0_assert($result['success'],'Request fixture failed');
    return (int)$db->get_var("SELECT id FROM {$db->prefix}survey_data_requests WHERE participant_id=7");
}
function p1c_config($form='anonymous-form'){
    $config=get_privacy_defaults();foreach(array('ip_address','user_agent_full','device_type','browser','os','screen_width','fingerprint_enabled')as$key){$config[$key]=false;}
    $GLOBALS['p0_options']['eipsi_privacy_config_'.generate_stable_form_id($form)]=$config;return $config;
}
$tests=array();
$tests['Export longitudinal consulta schema real y respuestas locales']=function($db){
    p1c_seed_response($db);$svc=new EIPSI_Export_Service();$rows=$svc->export_longitudinal_data(3);
    p0_assert(count($rows)>=1 && !$db->last_error,'Longitudinal query invalid');
    $matched=array_filter($rows,function($row){return !empty($row->submission_id);});p0_assert(count($matched)===1,'Local answer lost');
    foreach($db->observed_queries as$q){p0_assert(strpos($q,'survey_responses')===false && strpos($q,'sp.is_anonymized')===false,'Legacy query');}
};
$tests['Export participantes usa participant_id real y no columna interna ficticia']=function($db){
    p1c_seed_response($db);$data=(new EIPSI_Export_Service())->fetch_participants_data(3);
    p0_assert(!$db->last_error && count($data['rows'])===2,'Roster query failed');
    foreach($db->observed_queries as$q){p0_assert(strpos($q,'r.longitudinal_participant_id')===false,'Nonexistent internal column');}
    $db->query("DROP TABLE {$db->prefix}survey_waves");$failed=false;
    try{(new EIPSI_Export_Service())->fetch_participants_data(3);}catch(RuntimeException$error){$failed=true;}
    p0_assert($failed,'Roster silently omitted failed waves read');
};
$tests['Export CSV local genera archivo confirmado']=function($db){
    p1c_seed_response($db);$svc=new EIPSI_Export_Service();$filename=$svc->export_to_csv($svc->export_longitudinal_data(3),3);
    $path=EIPSI_FORMS_PLUGIN_DIR.'exports/'.$filename;$GLOBALS['p1c_exports'][]=$path;
    p0_assert(is_file($path)&&filesize($path)>0,'Missing CSV');
};
$tests['Export fuente externa se identifica como no incluida en personal']=function($db){
    $id=p1c_request($db);$GLOBALS['p0_admin']=true;$result=EIPSI_Participant_Data_Request_Service::process_request($id,'approve');
    p0_assert($result['success'] && in_array('external_db',$result['data']['coverage']['not_covered'],true),'External coverage hidden');
};
$tests['Export personal excluye hashes tokens y sesiones incluso en respuestas']=function($db){
    p1c_seed_response($db);$id=p1c_request($db);$GLOBALS['p0_admin']=true;$result=EIPSI_Participant_Data_Request_Service::process_request($id,'approve');
    p0_assert($result['success'],'Personal export failed');$json=file_get_contents($result['data']['file_path']);
    p0_assert(strpos($json,'password_hash')===false && strpos($json,'session_token')===false && strpos($json,'survey_sessions')===false,'Secrets exported');
    p0_assert(strpos($json,'p7@example.invalid')!==false,'Appropriate personal data missing');
};
$tests['Export personal fallo de archivo no devuelve éxito']=function($db){
    $id=p1c_request($db);$GLOBALS['p0_admin']=true;$GLOBALS['p1c_temp']='/nonexistent-eipsi-private';
    $result=EIPSI_Participant_Data_Request_Service::process_request($id,'approve');p0_assert(!$result['success'],'Missing file success');
    p0_assert($db->get_var("SELECT status FROM {$db->prefix}survey_data_requests WHERE id=$id")==='pending','Request incorrectly completed');
};
$tests['Archivo personal privado aleatorio y descarga autorizada']=function($db){
    $id=p1c_request($db);$GLOBALS['p0_admin']=true;$result=EIPSI_Participant_Data_Request_Service::process_request($id,'approve');
    p0_assert($result['success'],'Export failed');$path=$result['data']['file_path'];
    p0_assert(strpos($path,ABSPATH)!==0 && (fileperms($path)&0777)===0600,'Public/loose file');
    p0_assert(strpos(basename($path),'data-export-7-')===false,'Predictable file');
    $GLOBALS['p0_admin']=false;p0_assert(EIPSI_Participant_Data_Request_Service::get_download($id,'valid:eipsi_data_download')['success'],'Own download denied');
};
$tests['Descarga de B con sesión A rechazada']=function($db){
    $id=p1c_request($db);$GLOBALS['p0_admin']=true;EIPSI_Participant_Data_Request_Service::process_request($id,'approve');
    $GLOBALS['p0_admin']=false;p1_session(8,3);p0_assert(!EIPSI_Participant_Data_Request_Service::get_download($id,'valid:eipsi_data_download')['success'],'Foreign download allowed');
};
$tests['Descarga nonce inválido y ruta fuera de privado rechazadas']=function($db){
    $id=p1c_request($db);$GLOBALS['p0_admin']=true;EIPSI_Participant_Data_Request_Service::process_request($id,'approve');
    p0_assert(!EIPSI_Participant_Data_Request_Service::get_download($id,'bad')['success'],'Bad nonce allowed');
    $db->update($db->prefix.'survey_data_requests',array('result_data'=>'{"file_path":"/etc/passwd","filename":"x"}'),array('id'=>$id));
    p0_assert(!EIPSI_Participant_Data_Request_Service::get_download($id,'valid:eipsi_data_download')['success'],'Unsafe file allowed');
};
$tests['Data request nonce inválido rechazado']=function($db){
    p1_session();$_POST=array('participant_nonce'=>'bad','participant_id'=>7,'request_type'=>'export');
    p0_assert(!p0_ajax('eipsi_ajax_submit_data_request')->success,'Presence-only nonce');
    p0_assert((int)$db->get_var("SELECT COUNT(*) FROM {$db->prefix}survey_data_requests")===0,'Request persisted');
};
$tests['Data request sesión A no solicita B']=function($db){
    p1_session();$_POST=array('participant_nonce'=>'valid:eipsi_participant_nonce','participant_id'=>8,'request_type'=>'export');
    p0_assert(!p0_ajax('eipsi_ajax_submit_data_request')->success,'Foreign request');
};
$tests['Data request autorizado funciona']=function($db){
    p1_session();$_POST=array('participant_nonce'=>'valid:eipsi_participant_nonce','participant_id'=>7,'request_type'=>'export');
    p0_assert(p0_ajax('eipsi_ajax_submit_data_request')->success,'Own request rejected');
};
$tests['Procesar request requiere administración']=function($db){$id=p1c_request($db);p0_assert(!EIPSI_Participant_Data_Request_Service::process_request($id,'approve')['success'],'Public approval');};
$tests['Privacidad desactivada no persiste IP metadata UA ni dispositivo']=function($db){
    p1c_config();$_POST=p1_submit_request('anonymous-form');$_POST['device']='mobile';$_POST['browser']='browser-secret';$_POST['os']='os-secret';$_POST['screen_width']='1920';
    $_POST['metadata']='{"ip_address":"203.0.113.17","nested":{"user_agent":"UA-secret","device_data":{"user_agent":"UA-secret","canvas_fingerprint":"canvas-secret"}}}';
    $_POST['eipsi_device_data']='{"user_agent":"UA-secret","canvas_fingerprint":"canvas-secret"}';
    p0_assert(p0_ajax('eipsi_forms_submit_form_handler')->success,'Anonymous submit failed');
    $row=$db->get_row("SELECT * FROM {$db->prefix}vas_form_results LIMIT 1",ARRAY_A);$json=json_encode($row);
    p0_assert(empty($row['ip_address'])&&empty($row['user_fingerprint'])&&empty($row['device']),'Disabled canonical capture');
    foreach(array('203.0.113.17','UA-secret','canvas-secret','browser-secret','os-secret')as$v){p0_assert(strpos($json,$v)===false,'Disabled metadata/answer reintroduced '.$v);}
    p0_assert((int)$db->get_var("SELECT COUNT(*) FROM {$db->prefix}eipsi_device_data")===0,'Disabled device captured');
};
$tests['Privacidad parcial respeta categorías al guardar']=function($db){
    p1c_config();p0_assert(EIPSI_Partial_Responses::save('anonymous-form','browser','session',1,array('ip_address'=>'203.0.113.17','user_agent'=>'UA-secret','answer'=>4))['success'],'Partial failed');
    $json=$db->get_var("SELECT responses_json FROM {$db->prefix}eipsi_partial_responses LIMIT 1");p0_assert(strpos($json,'203.0.113.17')===false&&strpos($json,'UA-secret')===false,'Partial bypass');
};
$tests['Privacidad events no guarda UA']=function($db){
    $GLOBALS['p0_options']['eipsi_privacy_config_f']=array('user_agent_full'=>false);$_POST=array('nonce'=>'valid:eipsi_tracking_nonce','form_id'=>'f','session_id'=>'session','event_type'=>'view','user_agent'=>'UA-secret');
    p0_assert(p0_ajax('eipsi_track_event_handler')->success,'Event failed');p0_assert(!$db->get_var("SELECT user_agent FROM {$db->prefix}vas_form_events LIMIT 1"),'Event UA bypass');
};
$tests['Privacidad emergencia respeta respuestas metadata y POST crudo']=function($db){
    p1c_config();$db->query("DROP TABLE {$db->prefix}eipsi_emergency_submissions");$_POST=array('ip_address'=>'203.0.113.17','metadata'=>'{"user_agent":"UA-secret"}','answer'=>4);
    $result=eipsi_safety_emergency_save(array('form_id'=>generate_stable_form_id('anonymous-form'),'form_responses'=>'{"ip_address":"203.0.113.17","answer":4}','metadata'=>'{"user_agent":"UA-secret"}'),'fixture failure');
    p0_assert($result['success'],'Emergency failed');$row=$db->get_row("SELECT * FROM {$db->prefix}eipsi_emergency_submissions LIMIT 1",ARRAY_A);
    p0_assert(strpos(json_encode($row),'203.0.113.17')===false&&strpos(json_encode($row),'UA-secret')===false,'Emergency bypass');
};
$tests['Hard delete PK correcta y conserva otro participante']=function($db){$result=EIPSI_Participant_Service::hard_delete(7);p0_assert($result['success'],'Hard delete failed: '.json_encode($result['errors']));p0_assert(!$db->get_var("SELECT id FROM {$db->prefix}survey_participants WHERE id=7")&&$db->get_var("SELECT id FROM {$db->prefix}survey_participants WHERE id=8"),'Wrong PK scope');};
$tests['Hard delete identifica sessions events y device antes de borrar']=function($db){
    p1_session();$sid=p1c_seed_response($db);$db->insert($db->prefix.'vas_form_events',array('form_id'=>'f','session_id'=>'browser-fixture','event_type'=>'view','created_at'=>current_time('mysql')));
    $db->insert($db->prefix.'eipsi_device_data',array('submission_id'=>$sid,'user_agent'=>'UA-secret','captured_at'=>current_time('mysql')));
    $result=EIPSI_Participant_Service::hard_delete(7);p0_assert($result['success'],'Cleanup failed: '.json_encode($result['errors']));
    p0_assert((int)$db->get_var("SELECT COUNT(*) FROM {$db->prefix}vas_form_events")===0&&(int)$db->get_var("SELECT COUNT(*) FROM {$db->prefix}eipsi_device_data")===0,'Sessions removed before linkage');
};
$tests['Hard delete audit no conserva original_email ni contenido PII']=function($db){
    $db->insert($db->prefix.'survey_email_log',array('participant_id'=>7,'survey_id'=>3,'email_type'=>'welcome','recipient_email'=>'p7@example.invalid','content'=>'Hello p7@example.invalid','sent_at'=>current_time('mysql')));
    $db->insert($db->prefix.'survey_audit_log',array('survey_id'=>3,'participant_id'=>7,'action'=>'fixture','metadata'=>'{"original_email":"p7@example.invalid"}','created_at'=>current_time('mysql')));
    $result=EIPSI_Participant_Service::hard_delete(7);p0_assert($result['success'],'Delete failed');
    foreach(array('survey_email_log','survey_audit_log')as$table){p0_assert(strpos(json_encode($db->get_results("SELECT * FROM {$db->prefix}{$table}")),'p7@example.invalid')===false,'Audit PII preserved');}
};
$tests['Hard delete fallo crítico reporta error y revierte']=function($db){
    $db->query("CREATE TRIGGER `{$db->prefix}reject_delete` BEFORE DELETE ON {$db->prefix}survey_participants FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture rejection'");
    $result=EIPSI_Participant_Service::hard_delete(7);p0_assert(!$result['success']&&$result['errors'],'Failed delete success');p0_assert($db->get_var("SELECT id FROM {$db->prefix}survey_participants WHERE id=7"),'Failed deletion not rolled back');
};
$tests['Anon usa columnas reales elimina PII objetivo y preserva respuesta']=function($db){
    $GLOBALS['p0_admin']=true;p1c_seed_response($db);$result=EIPSI_Anonymize_Service::anonymize_participant(7);p0_assert($result['success'],'Anon failed: '.($result['error']??''));
    $row=$db->get_row("SELECT * FROM {$db->prefix}survey_participants WHERE id=7");p0_assert($row->email!=='p7@example.invalid'&&empty($row->password_hash),'Participant PII preserved');
    $answer=$db->get_var("SELECT form_responses FROM {$db->prefix}vas_form_results LIMIT 1");p0_assert(strpos($answer,'p7@example.invalid')===false&&strpos($answer,'answer')!==false,'Wrong response semantics');
    foreach($db->observed_queries as$q){p0_assert(strpos($q,'JSON_SET(metadata')===false,'Absent metadata column');}
};
$tests['Anon fallo parcial no devuelve éxito total']=function($db){
    $GLOBALS['p0_admin']=true;$db->query("CREATE TRIGGER `{$db->prefix}reject_update` BEFORE UPDATE ON {$db->prefix}survey_participants FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture rejection'");
    $result=EIPSI_Anonymize_Service::anonymize_participant(7);p0_assert(!$result['success'],'Failed anonymization success');
};
$tests['B2 borra resultados mediante tablas reales y conserva retiro']=function($db){
    p1_session();p1c_seed_response($db);$_POST=array('participant_id'=>7,'study_id'=>3,'withdrawal_type'=>'b2','verification_text'=>'QUIERO QUE ELIMINEN MIS DATOS','nonce'=>'valid:eipsi_abandon_study');
    $result=p0_ajax('eipsi_abandon_study_handler');p0_assert($result->success,'B2 failed: '.json_encode($result->data));
    p0_assert((int)$db->get_var("SELECT COUNT(*) FROM {$db->prefix}vas_form_results")===0,'B2 left response');
    p0_assert($db->get_var("SELECT consent_decision FROM {$db->prefix}survey_participants WHERE id=7")==='withdrawn','Withdrawal lost');
    foreach($db->observed_queries as$q){p0_assert(strpos($q,'study_waves')===false&&strpos($q,'eipsi_form_events')===false,'Legacy tables used');}
};
$tests['B2 fallo de borrado no declara éxito']=function($db){
    p1_session();p1c_seed_response($db);$db->query("CREATE TRIGGER `{$db->prefix}reject_response` BEFORE DELETE ON {$db->prefix}vas_form_results FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture rejection'");
    $_POST=array('withdrawal_type'=>'b2','verification_text'=>'QUIERO QUE ELIMINEN MIS DATOS','nonce'=>'valid:eipsi_abandon_study');
    p0_assert(!p0_ajax('eipsi_abandon_study_handler')->success,'B2 ignored critical failure');
};
$tests['Export XLSX longitudinal genera archivo confirmado']=function($db){
    p1c_seed_response($db);$svc=new EIPSI_Export_Service();$filename=$svc->export_to_excel($svc->export_longitudinal_data(3),3);
    $path=EIPSI_FORMS_PLUGIN_DIR.'exports/'.$filename;$GLOBALS['p1c_exports'][]=$path;p0_assert(is_file($path)&&filesize($path)>0,'Missing XLSX');
};
$tests['Export wide CSV funciona sin método ficticio']=function($db){
    p1c_seed_response($db);$out=fopen('php://temp','w+');(new EIPSI_Export_Service())->stream_participants_wide_csv(3,array(),$out);rewind($out);$csv=stream_get_contents($out);fclose($out);p0_assert(strpos($csv,'p7@example.invalid')!==false,'Wide stream empty');
};
$tests['Export CSV rechaza fallo de escritura real']=function($db){
    $out=fopen('/dev/full','w');p0_assert($out!==false,'Missing controlled failure device');
    $failed=false;try{@EIPSI_Export_Service::write_csv_row($out,array('row'));}catch(RuntimeException$error){$failed=true;}fclose($out);p0_assert($failed,'CSV write failed silently');
};
$tests['Export XLSX no confirma archivo si destino no es escribible']=function($db){
    wp_mkdir_p(EIPSI_FORMS_PLUGIN_DIR.'exports');
    $path=EIPSI_FORMS_PLUGIN_DIR.'exports/longitudinal_export_3_'.date('Y-m-d_H-i-s').'.xlsx';p0_assert(!file_exists($path),'Fixture collision');mkdir($path);
    $failed=false;try{@(new EIPSI_Export_Service())->export_to_excel(array(),3);}catch(RuntimeException$error){$failed=true;}finally{rmdir($path);}p0_assert($failed,'XLSX failure declared success');
};
$tests['Privacidad filtro anterior a storage externo y fallback']=function($db){
    $config=p1c_config();$data=eipsi_filter_capture_data(array('form_responses'=>'{"ip_address":"203.0.113.17","answer":4}','metadata'=>'{"nested":{"user_agent":"UA-secret"}}','ip_address'=>'203.0.113.17','user_fingerprint'=>'fingerprint-secret'),$config);
    p0_assert(strpos(json_encode($data),'203.0.113.17')===false&&strpos(json_encode($data),'UA-secret')===false&&strpos(json_encode($data),'fingerprint-secret')===false,'Pre-storage bypass');
};
$tests['Hard delete purga jobs weekly parciales y emergency asociados']=function($db){
    p1c_seed_response($db);$aid=(int)$db->get_var("SELECT id FROM {$db->prefix}survey_assignments WHERE participant_id=7");
    $db->insert($db->prefix.'survey_nudge_jobs',array('job_type'=>'send_nudge_1','payload'=>json_encode(array('assignment_id'=>$aid,'participant_id'=>7))));
    $db->insert($db->prefix.'survey_weekly_reminders',array('assignment_id'=>$aid,'reminder_number'=>1,'sent_at'=>current_time('mysql')));
    EIPSI_Partial_Responses::save('long-form','browser-tracking','browser-fixture',1,array('answer'=>4));
    $db->insert($db->prefix.'eipsi_emergency_submissions',array('form_id'=>'f','participant_id'=>'7','form_responses'=>'{"email":"p7@example.invalid"}','created_at'=>current_time('mysql')));
    $result=EIPSI_Participant_Service::hard_delete(7);p0_assert($result['success'],'Hard delete failed');
    foreach(array('survey_nudge_jobs','survey_weekly_reminders','eipsi_partial_responses','eipsi_emergency_submissions')as$table){p0_assert((int)$db->get_var("SELECT COUNT(*) FROM {$db->prefix}{$table}")===0,'Associated copy left: '.$table);}
};
$tests['Anon limpia copias T dinámicas de email sin perder puntaje']=function($db){
    $GLOBALS['p0_admin']=true;$db->query("ALTER TABLE {$db->prefix}survey_participants ADD T2_email TEXT, ADD T2_score TEXT");
    $db->update($db->prefix.'survey_participants',array('T2_email'=>'p7@example.invalid','T2_score'=>'4'),array('id'=>7));
    p0_assert(EIPSI_Anonymize_Service::anonymize_participant(7)['success'],'Anon failed');
    $row=$db->get_row("SELECT T2_email,T2_score FROM {$db->prefix}survey_participants WHERE id=7");p0_assert(!$row->T2_email&&$row->T2_score==='4','Dynamic fields inconsistent');
};
$tests['Anon de estudio usa survey_studies y participantes inactivos']=function($db){
    $GLOBALS['p0_admin']=true;$db->update($db->prefix.'survey_assignments',array('status'=>'submitted'),array('study_id'=>3));
    $db->update($db->prefix.'survey_participants',array('is_active'=>0),array('id'=>8));
    $result=EIPSI_Anonymize_Service::anonymize_survey(3);p0_assert($result['success']&&$result['anonymized_count']===2,'Study anonymize incomplete: '.json_encode($result));
    p0_assert($db->get_var("SELECT email FROM {$db->prefix}survey_participants WHERE id=8")!=='p8@example.invalid','Inactive skipped');
};
$tests['Solicitud delete declara anonymize y preserva respuesta']=function($db){
    p1_session();p1c_seed_response($db);$request=EIPSI_Participant_Data_Request_Service::submit_request(7,'delete');p0_assert($request['success'],'Delete request failed');
    $GLOBALS['p0_admin']=true;$result=EIPSI_Participant_Data_Request_Service::process_request($request['request_id'],'approve');
    p0_assert($result['success']&&$result['data']['operation']==='anonymize','Delete falsely claims physical purge');
    p0_assert((int)$db->get_var("SELECT COUNT(*) FROM {$db->prefix}vas_form_results")===1,'Delete semantics forcibly unified');
};
$tests['Export personal lectura fallida no crea archivo ni completa solicitud']=function($db){
    $id=p1c_request($db);$GLOBALS['p0_admin']=true;$db->query("DROP TABLE {$db->prefix}survey_email_log");
    $result=EIPSI_Participant_Data_Request_Service::process_request($id,'approve');p0_assert(!$result['success'],'Incomplete read exported');p0_assert(!glob(get_temp_dir().'/*'),'Incomplete export file written');
};
$tests['Privacidad access logs respeta defaults globales']=function($db){
    require_once EIPSI_FORMS_PLUGIN_DIR.'admin/services/class-participant-access-log-service.php';
    $GLOBALS['p0_options']['eipsi_global_privacy_defaults']=array('ip_address'=>false,'user_agent_full'=>false);$_SERVER['HTTP_USER_AGENT']='UA-secret';
    p0_assert(EIPSI_Participant_Access_Log_Service::log(7,3,'login',array('ip_address'=>'203.0.113.17','user_agent'=>'UA-secret')),'Access log failed');
    $json=json_encode($db->get_results("SELECT * FROM {$db->prefix}survey_participant_access_log"));p0_assert(strpos($json,'UA-secret')===false&&strpos($json,'203.0.113.17')===false,'Access log bypass');
};
$tests['Anon informa explícitamente cobertura local incompleta']=function($db){
    $GLOBALS['p0_admin']=true;$result=EIPSI_Anonymize_Service::anonymize_participant(7);
    p0_assert($result['success']&&!$result['coverage']['complete']&&in_array('external_db',$result['coverage']['not_covered'],true),'False global anonymization');
};
$tests['Hard delete purga pools y randomización enlazados sin afectar otros']=function($db){
    p1c_seed_response($db);$db->insert($db->prefix.'eipsi_pool_assignments',array('pool_id'=>1,'participant_id'=>7,'study_id'=>3,'assigned_at'=>current_time('mysql')));
    $db->insert($db->prefix.'eipsi_randomization_assignments',array('randomization_id'=>'fixture','config_id'=>1,'user_fingerprint'=>'fingerprint-fixture','assigned_form_id'=>101));
    $result=EIPSI_Participant_Service::hard_delete(7);p0_assert($result['success'],'Cleanup failed');
    p0_assert((int)$db->get_var("SELECT COUNT(*) FROM {$db->prefix}eipsi_pool_assignments")===0&&(int)$db->get_var("SELECT COUNT(*) FROM {$db->prefix}eipsi_randomization_assignments")===0,'Linked assignment left');
};
$tests['Export personal fallo de estado final elimina archivo y no confirma']=function($db){
    $id=p1c_request($db);$GLOBALS['p0_admin']=true;
    $db->query("CREATE TRIGGER `{$db->prefix}reject_complete` BEFORE UPDATE ON {$db->prefix}survey_data_requests FOR EACH ROW BEGIN IF NEW.status='completed' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture rejection'; END IF; END");
    $result=EIPSI_Participant_Data_Request_Service::process_request($id,'approve');p0_assert(!$result['success']&&!glob(get_temp_dir().'/*'),'Final failure leaked file/success');
};
$tests['Device service directo filtra UA antes del INSERT']=function($db){
    require_once EIPSI_FORMS_PLUGIN_DIR.'admin/services/class-device-data-service.php';$sid=p1c_seed_response($db);
    $config=get_global_privacy_defaults();$config['user_agent_full']=false;$GLOBALS['p0_options']['eipsi_privacy_config_'.generate_stable_form_id('long-form')]=$config;
    $result=EIPSI_Device_Data_Service::save_device_data($sid,array('user_agent'=>'UA-secret','canvas_fingerprint'=>'allowed-fingerprint'));
    p0_assert($result!==false,'Device save failed');p0_assert(!$db->get_var("SELECT user_agent FROM {$db->prefix}eipsi_device_data WHERE submission_id=$sid"),'Direct service bypass');
};
$tests['Hard delete preserva registros con sesión y fingerprint compartidos']=function($db){
    p1c_seed_response($db);
    $db->insert($db->prefix.'vas_form_results',array('form_id'=>'f','form_name'=>'shared','participant_id'=>'8','session_id'=>'browser-fixture','user_fingerprint'=>'fingerprint-fixture','form_responses'=>'{"answer":8}','created_at'=>current_time('mysql')));
    EIPSI_Partial_Responses::save('shared','browser-tracking','browser-fixture',1,array('answer'=>8));
    $db->insert($db->prefix.'eipsi_randomization_assignments',array('randomization_id'=>'fixture','config_id'=>1,'user_fingerprint'=>'fingerprint-fixture','assigned_form_id'=>101));
    $result=EIPSI_Participant_Service::hard_delete(7);p0_assert($result['success'],'Cleanup failed');
    p0_assert((int)$db->get_var("SELECT COUNT(*) FROM {$db->prefix}eipsi_partial_responses")===1&&(int)$db->get_var("SELECT COUNT(*) FROM {$db->prefix}eipsi_randomization_assignments")===1,'Shared browser records erased');
    p0_assert(in_array('shared_session_id',$result['coverage']['not_covered'],true),'Ambiguity concealed');
};
$tests['Anon limpia logs de acceso con IP NOT NULL']=function($db){
    require_once EIPSI_FORMS_PLUGIN_DIR.'admin/services/class-participant-access-log-service.php';
    p0_assert(EIPSI_Participant_Access_Log_Service::log(7,3,'login',array('email'=>'p7@example.invalid')),'Log fixture failed');
    $GLOBALS['p0_admin']=true;$result=EIPSI_Anonymize_Service::anonymize_participant(7);p0_assert($result['success'],'Access log cleanup failed');
    $row=$db->get_row("SELECT * FROM {$db->prefix}survey_participant_access_log");p0_assert($row->ip_address===''&&$row->metadata==='{}','Access identifiers retained');
};
$tests['Export administrativo excluye credenciales en CSV y datos anidados']=function($db){
    p1c_seed_response($db);$svc=new EIPSI_Export_Service();$data=$svc->export_longitudinal_data(3);$filename=$svc->export_to_csv($data,3);
    $path=EIPSI_FORMS_PLUGIN_DIR.'exports/'.$filename;$GLOBALS['p1c_exports'][]=$path;$csv=file_get_contents($path);
    p0_assert(strpos($csv,'password_hash')===false&&strpos($csv,'session_token')===false&&strpos($csv,'secret')===false,'Credential export');
    p0_assert(EIPSI_Export_Service::strip_credentials(array('nested'=>array('password_hash'=>'secret','answer'=>4)))===array('nested'=>array('answer'=>4)),'Nested credentials');
};
$tests['Export respuestas con pool usa método de tabla real y filtra tokens']=function($db){
    require_once EIPSI_FORMS_PLUGIN_DIR.'admin/export.php';$GLOBALS['p0_admin']=true;p1c_seed_response($db);
    $csv=eipsi_generate_pool_context_csv(generate_stable_form_id('long-form'),3);
    p0_assert(is_string($csv)&&strpos($csv,'answer')!==false,'Pool query/export failed');
    p0_assert(strpos($csv,'password_hash')===false&&strpos($csv,'session_token')===false,'Pool credentials');
};
$tests['Fingerprint desactivado bloquea device directo aunque toggles técnicos estén activos']=function($db){
    require_once EIPSI_FORMS_PLUGIN_DIR.'admin/services/class-device-data-service.php';$sid=p1c_seed_response($db);
    $config=get_global_privacy_defaults();$config['fingerprint_enabled']=false;$GLOBALS['p0_options']['eipsi_privacy_config_'.generate_stable_form_id('long-form')]=$config;
    $saved=EIPSI_Device_Data_Service::save_device_data($sid,array('canvas_fingerprint'=>'secret','webgl_renderer'=>'secret','screen_resolution'=>'1920x1080','timezone'=>'secret'));
    p0_assert($saved===false&&(int)$db->get_var("SELECT COUNT(*) FROM {$db->prefix}eipsi_device_data")===0,'Fingerprint policy bypass');
};
$export_directory_existed=is_dir(EIPSI_FORMS_PLUGIN_DIR.'exports');
$failed=0;
foreach($tests as$name=>$test){
    $temp=sys_get_temp_dir().'/eipsi-p1c-'.bin2hex(random_bytes(6));mkdir($temp,0700);$GLOBALS['p1c_temp']=$temp;$GLOBALS['p1c_exports']=array();
    try{p1_fixture($test);echo "PASS $name\n";}catch(Throwable$error){$failed++;echo "FAIL $name: {$error->getMessage()}\n";}
    finally{foreach(glob($temp.'/*')as$file){unlink($file);}rmdir($temp);foreach($GLOBALS['p1c_exports']as$file){if(is_file($file)){unlink($file);}}}
}
if(!$export_directory_existed && is_dir(EIPSI_FORMS_PLUGIN_DIR.'exports')){rmdir(EIPSI_FORMS_PLUGIN_DIR.'exports');}
echo count($tests).' tests, '.$failed." failures\n";ob_end_flush();exit($failed?1:0);
