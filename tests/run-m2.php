<?php
/** Integration characterization: actual WordPress, MariaDB and HTTP cookies. */
require __DIR__ . '/m0/bootstrap.php';
require __DIR__ . '/m2/baseline-policy.php';
require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/services/class-email-confirmation-service.php';
$tests = array();
function m2_reset($data = array()) {
    global $wpdb;
    $_COOKIE = array();
    $wpdb->update($wpdb->prefix.'survey_participants', array_merge(array('is_active'=>1,'consent_decision'=>'accepted','status'=>'active'), $data), array('id'=>992207));
    eipsi_clear_login_rate_limit('m2-a@example.invalid', 992203);
}
function m2_session() {
    $result=EIPSI_Auth_Service::create_session(992207,992203);
    m0_assert($result['success'],'Session creation failed');
    return $result;
}
function m2_nonce($action) {
    wp_set_current_user(0); $nonce=wp_create_nonce($action); wp_set_current_user(1); return $nonce;
}
function m2_http($path, $body=null, $session=null) {
    // Reproduce the encoded Set-Cookie value a browser sends (tokens contain '+').
    $headers=array('Host'=>'127.0.0.1:18080');
    if ($session) { $headers['Cookie']=$session['cookie_name'].'='.rawurlencode($session['token']); }
    if (strpos($path,'rest_route=')!==false) { $headers['Content-Type']='application/json'; $body=wp_json_encode($body); }
    $result=wp_remote_request('http://127.0.0.1'.$path,array('method'=>$body===null?'GET':'POST','body'=>$body,'timeout'=>25,'redirection'=>0,'headers'=>$headers));
    m0_assert(!is_wp_error($result),'HTTP transport failed'); return $result;
}
function m2_ajax($action,$data=array(),$session=null,$nonce_action='eipsi_participant_auth') {
    return m2_http('/wp-admin/admin-ajax.php',array_merge(array('action'=>$action,'nonce'=>m2_nonce($nonce_action)),$data),$session);
}
function m2_json($response) {
    $json=json_decode(wp_remote_retrieve_body($response),true);
    m0_assert(is_array($json),'Non-JSON HTTP response, status '.wp_remote_retrieve_response_code($response)); return $json;
}
function m2_pool($params,$session=null) { return m2_http('/?rest_route=/eipsi/v1/pool-assign',$params,$session); }
function m2_login() {
    $response=m2_ajax('eipsi_participant_login',array('survey_id'=>992203,'email'=>'m2-a@example.invalid'));
    $json=m2_json($response); m0_assert($json['success'],'HTTP login failed');
    return array($response,array('token'=>$json['data']['session_token'],'cookie_name'=>$json['data']['cookie_name']));
}
$states=array(
    'accepted'=>array(array(),null),
    'inactive'=>array(array('is_active'=>0),'user_inactive'),
    'declined'=>array(array('consent_decision'=>'declined'),'consent_declined'),
    'withdrawn'=>array(array('consent_decision'=>'withdrawn'),'study_withdrawn'),
    'pending empty'=>array(array('consent_decision'=>''),null),
    'pending NULL'=>array(array('consent_decision'=>null),null),
    'invalid literal pending'=>array(array('consent_decision'=>'pending'),'invalid_consent_state'),
    'status withdrawn'=>array(array('status'=>'withdrawn'),'study_withdrawn'),
    'status consent_declined'=>array(array('status'=>'consent_declined'),'consent_declined'),
);
foreach ($states as $name=>$case) {
    $tests['P1-A equivalencia real: '.$name]=function()use($case){
        m2_reset($case[0]);
        $old=EIPSI_M2_Baseline_Policy::authorize_participant(992207,992203);
        $new=EIPSI_Authorization_Policy::authorize_participant(992207,992203);
        m0_assert($old==$new && $new['error']===$case[1],'Policy diverged from frozen P1-A');
        m0_assert(EIPSI_Auth_Service::authorize_participant(992207,992203)==$old,'Facade decision diverged');
        $auth=EIPSI_Auth_Service::authenticate_passwordless(992203,'m2-a@example.invalid');
        m0_assert($auth['success']===$new['success'] && $auth['error']===$new['error'],'Passwordless decision diverged');
    };
}
$tests['P1-A inexistente y pertenencia conservan errores']=function(){
    m0_assert(EIPSI_Auth_Service::authorize_participant(0,992203)['error']==='user_not_found','Missing participant allowed');
    m0_assert(EIPSI_Auth_Service::authorize_participant(992207,992204)['error']==='study_mismatch','Foreign study allowed');
};
$tests['Password: login válido y credencial incorrecta']=function(){
    m0_assert(EIPSI_Auth_Service::authenticate(992203,'m2-a@example.invalid','m2-valid-password')['success'],'Password rejected');
    m0_assert(EIPSI_Auth_Service::authenticate(992203,'m2-a@example.invalid','wrong')['error']==='invalid_credentials','Wrong password accepted');
};
$tests['Sesión: hash SHA256, cookie y duración siete días']=function(){global $wpdb;
    $session=m2_session(); $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}survey_sessions WHERE token=%s",hash('sha256',$session['token'])));
    m0_assert($row && $row->token!==$session['token'] && strlen($row->token)===64,'Plain token persisted');
    m0_assert($session['cookie_name']==='eipsi_session_token' && strlen($session['token'])===64,'Cookie/token format changed');
    m0_assert(abs(strtotime($row->expires_at)-time()-7*DAY_IN_SECONDS)<10,'Session duration changed');
    m0_assert(EIPSI_Auth_Service::get_current_participant()===992207 && EIPSI_Auth_Service::get_current_survey()===992203,'Canonical session unavailable');
};
$tests['Sesión: revocación elimina hash y rechaza cookie anterior']=function(){global $wpdb;
    $session=m2_session(); EIPSI_Auth_Service::destroy_session(); $_COOKIE[$session['cookie_name']]=$session['token'];
    m0_assert(EIPSI_Auth_Service::get_current_session()===null,'Revoked session valid');
    m0_assert(!$wpdb->get_var($wpdb->prepare("SELECT token FROM {$wpdb->prefix}survey_sessions WHERE token=%s",hash('sha256',$session['token']))),'Revoked token remains');
};
foreach (array('inactive'=>array('is_active'=>0),'declined'=>array('consent_decision'=>'declined'),'withdrawn'=>array('consent_decision'=>'withdrawn')) as $name=>$change) {
    $tests['Sesión previa invalidada: '.$name]=function()use($change){global $wpdb;$session=m2_session();$wpdb->update($wpdb->prefix.'survey_participants',$change,array('id'=>992207));
        m0_assert(EIPSI_Auth_Service::get_current_session()===null,'Changed participant still authorized');
        m0_assert(!$wpdb->get_var($wpdb->prepare("SELECT token FROM {$wpdb->prefix}survey_sessions WHERE token=%s",hash('sha256',$session['token']))),'Invalid session not revoked');
    };
}
$tests['Sesión vencida rechazada']=function(){global $wpdb;$session=m2_session();$wpdb->update($wpdb->prefix.'survey_sessions',array('expires_at'=>gmdate('Y-m-d H:i:s',time()-3600)),array('token'=>hash('sha256',$session['token'])));m0_assert(EIPSI_Auth_Service::get_current_session()===null,'Expired session valid');};
$tests['Contexto: A no opera como B ni en otro estudio']=function(){m2_session();foreach(array(array('participant_id'=>992208),array('study_id'=>992204),array('participant_id'=>array(992207))) as $claim){m0_assert(EIPSI_Auth_Service::authorize_session_context($claim)['error']==='session_context_mismatch','Client identity accepted');}};
$tests['Submit longitudinal deriva identidad; fingerprint no autoriza']=function(){
    m0_assert(!EIPSI_Auth_Service::authorize_form_operation('m2-long-form',array('participant_id'=>'m2-browser'))['success'],'Fingerprint authenticates');
    m2_session();$access=EIPSI_Auth_Service::authorize_form_operation('m2-long-form',array('participant_id'=>'m2-browser'),array(),'submit');
    m0_assert($access['success'] && $access['participant_id']===992207 && $access['study_id']===992203 && $access['wave_id']===992221,'Canonical submit changed');
    m0_assert(!EIPSI_Auth_Service::authorize_form_operation('m2-long-form',array('participant_id'=>992208))['success'],'Submit impersonation allowed');
};
$tests['Formulario anónimo no hereda sesión longitudinal']=function(){
    foreach(array(false,true) as $authenticated){if($authenticated){m2_session();}$result=EIPSI_Auth_Service::authorize_form_operation('m2-anonymous',array('participant_id'=>'m2-browser'));m0_assert($result['success'] && !$result['longitudinal'] && $result['participant_id']===0 && $result['study_id']===null,'Anonymous identity changed');}
};
$tests['Magic link: hash, 48 horas y API pública']=function(){global $wpdb;$token=EIPSI_MagicLinksService::generate_magic_link(992203,992207);$row=EIPSI_MagicLinksService::get_magic_link_by_token(hash('sha256',$token));m0_assert($row && $row->token_hash!==$token && abs(strtotime($row->expires_at)-time()-48*HOUR_IN_SECONDS)<10,'Magic hash/TTL changed');m0_assert(EIPSI_MagicLinksService::validate_magic_link($token)===EIPSI_Magic_Link_Service::validate_magic_link($token),'Magic facade changed');};
$tests['Magic link vencido rechazado']=function(){global $wpdb;$token=EIPSI_MagicLinksService::generate_magic_link(992203,992207);$wpdb->update($wpdb->prefix.'survey_magic_links',array('expires_at'=>gmdate('Y-m-d H:i:s',time()-3600)),array('token_hash'=>hash('sha256',$token)));m0_assert(EIPSI_MagicLinksService::validate_magic_link($token)['reason']==='expired','Expired magic valid');};
$tests['Magic link single-use y segundo consumo rechazado']=function(){$token=EIPSI_MagicLinksService::generate_magic_link(992203,992207);$link=EIPSI_MagicLinksService::validate_magic_link($token);m0_assert($link['valid'] && EIPSI_MagicLinksService::mark_magic_link_used($link['ml_id']),'Consumption failed');m0_assert(!EIPSI_MagicLinksService::mark_magic_link_used($link['ml_id']) && EIPSI_MagicLinksService::validate_magic_link($token)['reason']==='already_used','Link reused');};
foreach(array('inactive'=>array('is_active'=>0),'declined'=>array('consent_decision'=>'declined'),'withdrawn'=>array('consent_decision'=>'withdrawn')) as $name=>$change){$tests['Magic link aplica política: '.$name]=function()use($change){global $wpdb;$token=EIPSI_MagicLinksService::generate_magic_link(992203,992207);$wpdb->update($wpdb->prefix.'survey_participants',$change,array('id'=>992207));m0_assert(!EIPSI_MagicLinksService::validate_magic_link($token)['valid'],'Magic bypasses state');};}
$tests['HTTP passwordless: JSON, cookie HttpOnly SameSite y sesión posterior']=function(){global $wpdb;list($response,$session)=m2_login();$cookies=(array)wp_remote_retrieve_header($response,'set-cookie');$cookie=implode(';',$cookies);m0_assert(stripos($cookie,'HttpOnly')!==false && stripos($cookie,'SameSite=Lax')!==false && stripos($cookie,'path=/')!==false,'Cookie flags changed');$info=m2_json(m2_ajax('eipsi_participant_info',array(),$session));m0_assert($info['success'] && $info['data']['participant_id']===992207,'Browser cookie does not resolve identity');m0_assert($wpdb->get_var($wpdb->prepare("SELECT token FROM {$wpdb->prefix}survey_sessions WHERE token=%s",hash('sha256',$session['token'])))!==null,'Cookie hash not stored');};
$tests['HTTP login nonce inválido rechazado']=function(){$response=m2_http('/wp-admin/admin-ajax.php',array('action'=>'eipsi_participant_login','nonce'=>'invalid','survey_id'=>992203,'email'=>'m2-a@example.invalid'));m0_assert(wp_remote_retrieve_response_code($response)===403,'Invalid nonce allowed');};
foreach(array('inactive'=>array(array('is_active'=>0),'email_not_confirmed'),'declined'=>array(array('consent_decision'=>'declined'),'consent_declined'),'withdrawn'=>array(array('consent_decision'=>'withdrawn'),'study_withdrawn')) as $name=>$case){$tests['HTTP login rechazado: '.$name]=function()use($case){m2_reset($case[0]);$json=m2_json(m2_ajax('eipsi_participant_login',array('survey_id'=>992203,'email'=>'m2-a@example.invalid')));m0_assert(!$json['success'] && $json['data']['code']===$case[1],'HTTP state contract changed');};}
$tests['HTTP cookie vieja invalidada después de desactivar']=function(){global $wpdb;list(,$session)=m2_login();$wpdb->update($wpdb->prefix.'survey_participants',array('is_active'=>0),array('id'=>992207));$json=m2_json(m2_ajax('eipsi_participant_info',array(),$session));m0_assert(!$json['success'] && $json['data']['code']==='not_authenticated','Old browser cookie remains authorized');};
$tests['HTTP logout revoca sesión persistida']=function(){list(,$session)=m2_login();m0_assert(m2_json(m2_ajax('eipsi_participant_logout',array(),$session))['success'],'Logout failed');m0_assert(!m2_json(m2_ajax('eipsi_participant_info',array(),$session))['success'],'Logged out cookie still valid');};
$tests['HTTP consentimiento: rechaza B, deriva A y conserva B']=function(){global $wpdb;list(,$session)=m2_login();$wpdb->update($wpdb->prefix.'survey_participants',array('consent_decision'=>''),array('id'=>992208));$params=array('form_id'=>'m2-long-form','decision'=>'accepted','participant_id'=>992208);m0_assert(wp_remote_retrieve_response_code(m2_ajax('eipsi_save_consent_decision',$params,$session,'eipsi_forms_nonce'))===403,'Consent impersonation allowed');unset($params['participant_id']);$json=m2_json(m2_ajax('eipsi_save_consent_decision',$params,$session,'eipsi_forms_nonce'));m0_assert($json['success'],'Own consent failed');m0_assert($wpdb->get_var("SELECT consent_decision FROM {$wpdb->prefix}survey_participants WHERE id=992207")==='accepted' && EIPSI_Participant_Service::get_by_id(992208)->consent_decision==='','Consent identity wrong');};
$tests['HTTP pool: público 401, participante y estudio ajenos 403']=function(){list(,$session)=m2_login();m0_assert(wp_remote_retrieve_response_code(m2_pool(array('pool_id'=>992201)))===401,'Public pool allowed');foreach(array(array('participant_id'=>992208),array('study_id'=>992204)) as $claim){m0_assert(wp_remote_retrieve_response_code(m2_pool(array_merge(array('pool_id'=>992201),$claim),$session))===403,'Foreign pool identity allowed');}};
$tests['HTTP pool: asignación usa sesión y conserva 201/200']=function(){global $wpdb;list(,$session)=m2_login();$params=array('pool_id'=>992201);$response=m2_pool($params,$session);m0_assert(wp_remote_retrieve_response_code($response)===201 && m2_json($response)['success'],'Pool assignment failed');m0_assert((int)$wpdb->get_var("SELECT participant_id FROM {$wpdb->prefix}eipsi_pool_assignments WHERE pool_id=992201")===992207,'Pool did not use session');m0_assert(wp_remote_retrieve_response_code(m2_pool($params,$session))===200,'Existing pool contract changed');};
$tests['HTTP acceso a estudio: sesión válida, estudio ajeno y estado revocado']=function(){global $form,$wpdb;
    $own=wp_insert_post(array('post_type'=>'page','post_status'=>'publish','post_title'=>'M2 study access','post_content'=>'[eipsi_longitudinal_study id="992203"]'));
    $foreign=wp_insert_post(array('post_type'=>'page','post_status'=>'publish','post_title'=>'M2 foreign study','post_content'=>'[eipsi_longitudinal_study id="992204"]'));
    try{list(,$session)=m2_login();$query='&form_id='.$form.'&wave_id=992221';
        $valid=m2_http('/?page_id='.$own.$query,null,$session);m0_assert(wp_remote_retrieve_response_code($valid)===200 && strpos(wp_remote_retrieve_body($valid),'m2-answer')!==false,'Study form does not render for valid cookie');
        $denied=m2_http('/?page_id='.$foreign.$query,null,$session);m0_assert(strpos(wp_remote_retrieve_body($denied),'m2-answer')===false,'Foreign study renders private form');
        $wpdb->update($wpdb->prefix.'survey_participants',array('consent_decision'=>'withdrawn'),array('id'=>992207));
        $revoked=m2_http('/?page_id='.$own.$query,null,$session);m0_assert(strpos(wp_remote_retrieve_body($revoked),'m2-answer')===false,'Withdrawn cookie renders private form');
    }finally{wp_delete_post($own,true);wp_delete_post($foreign,true);}
};
$tests['HTTP magic link: redirect, sesión una hora y consumo único']=function(){global $wpdb;$token=EIPSI_MagicLinksService::generate_magic_link(992203,992207);$response=m2_http('/survey-access/?ml='.rawurlencode($token));m0_assert(wp_remote_retrieve_response_code($response)===302 && strpos(wp_remote_retrieve_header($response,'location'),'wave_id=992221')!==false,'Magic redirect changed');$session=null;foreach(wp_remote_retrieve_cookies($response) as $cookie){if($cookie->name==='eipsi_session_token'){$session=array('cookie_name'=>$cookie->name,'token'=>$cookie->value);}}m0_assert($session && m2_json(m2_ajax('eipsi_participant_info',array(),$session))['success'],'Magic cookie fails');$expiry=$wpdb->get_var($wpdb->prepare("SELECT expires_at FROM {$wpdb->prefix}survey_sessions WHERE token=%s",hash('sha256',$session['token'])));m0_assert(abs(strtotime($expiry)-time()-HOUR_IN_SECONDS)<10,'Magic session duration changed');$second=m2_http('/survey-access/?ml='.rawurlencode($token));m0_assert(wp_remote_retrieve_response_code($second)!==302,'Magic HTTP reused');};
$tests['HTTP registration sin double opt-in conserva auto-login']=function(){global $wpdb;$json=m2_json(m2_ajax('eipsi_participant_register',array('survey_id'=>992203,'email'=>'m2-register@example.invalid')));m0_assert($json['success'] && $json['data']['auto_login']===true && !empty($json['data']['session_token']),'Registration auto-login changed');$participant=EIPSI_Participant_Service::get_by_email(992203,'m2-register@example.invalid');m0_assert($participant && (int)$participant->is_active===1,'Registered participant missing');};
$tests['HTTP registration double opt-in requiere confirmación sin sesión']=function(){global $wpdb;$json=m2_json(m2_ajax('eipsi_participant_register',array('survey_id'=>992204,'email'=>'m2-confirm@example.invalid')));m0_assert($json['success'] && $json['data']['requires_confirmation']===true && $json['data']['auto_login']===false && !isset($json['data']['session_token']),'Double opt-in contract changed');$participant=EIPSI_Participant_Service::get_by_email(992204,'m2-confirm@example.invalid');m0_assert($participant && (int)$participant->is_active===0,'Double opt-in active too early');};
$tests['HTTP bulk admin conserva conteos y errores']=function(){$response=m0_http('/wp-admin/admin-ajax.php',array('action'=>'eipsi_add_participants_bulk','nonce'=>m0_admin_nonce('eipsi_admin_nonce'),'study_id'=>992203,'emails'=>"m2-bulk@example.invalid,invalid,m2-a@example.invalid"),true);$json=m2_json($response);m0_assert($json['success'] && $json['data']['success_count']===1 && $json['data']['failed_count']===2 && count($json['data']['errors'])===2,'Bulk contract changed');};
$tests['HTTP CSV import conserva filas y metadata']=function(){$response=m0_http('/wp-admin/admin-ajax.php',array('action'=>'eipsi_import_csv_participants','nonce'=>m0_admin_nonce('eipsi_study_dashboard_nonce'),'study_id'=>992203,'participants'=>array(array('status'=>'valid','email'=>'m2-import@example.invalid','first_name'=>'M2 imported','last_name'=>'Fixture'),array('status'=>'invalid','email'=>'invalid','first_name'=>'','last_name'=>''))),true);$json=m2_json($response);m0_assert($json['success'] && $json['data']['results']['imported']===1 && $json['data']['results']['failed']===0,'CSV import changed');m0_assert(EIPSI_Participant_Service::get_by_email(992203,'m2-import@example.invalid')->first_name==='M2 imported','Metadata lost');};
$tests['Lifecycle deactivate/reactivate preserva sesión y participante']=function(){global $wpdb;$session=m2_session();$hash=hash('sha256',$session['token']);$plugin=plugin_basename(EIPSI_FORMS_PLUGIN_FILE);try{deactivate_plugins($plugin);m0_assert($wpdb->get_var($wpdb->prepare("SELECT token FROM {$wpdb->prefix}survey_sessions WHERE token=%s",$hash))===$hash,'Lifecycle destroyed session');}finally{$result=activate_plugin($plugin);m0_assert(!is_wp_error($result),'Reactivation failed');}$_COOKIE[$session['cookie_name']]=$session['token'];m0_assert(EIPSI_Auth_Service::get_current_participant()===992207,'Reactivation changed identity');};
$tests['Facades públicas: repository, password, estado y helpers preservados']=function(){
    m0_assert(EIPSI_Participant_Service::get_by_id(992207)==EIPSI_Participant_Repository::get_by_id(992207),'Lookup facade changed');
    m0_assert(EIPSI_Participant_Service::verify_password(992207,'m2-valid-password'),'Password facade broken');
    m0_assert(class_exists('EIPSI_Participant_Auth_Handler') && is_callable(array('EIPSI_MagicLinksService','generate_and_create_page')) && function_exists('eipsi_check_consent_blocked'),'Legacy API removed');
    m0_assert(has_action('wp_ajax_nopriv_eipsi_participant_magic_link')!==false,'Active legacy adapter removed');
};
$tests['HTTP confirmation activa al propio participante']=function(){
    $created=EIPSI_Participant_Service::create_participant_with_status(992204,'m2-http-confirm@example.invalid',null,array(),false);
    m0_assert($created['success'],'Confirmation fixture failed');
    $token=EIPSI_Email_Confirmation_Service::generate_confirmation_token(992204,$created['participant_id'],'m2-http-confirm@example.invalid');
    m0_assert($token['success'],'Confirmation token failed');
    $response=m2_http('/?eipsi_confirm='.rawurlencode($token['token']));
    m0_assert(wp_remote_retrieve_response_code($response)<400 && (int)EIPSI_Participant_Service::get_by_id($created['participant_id'])->is_active===1,'Confirmation failed');
    m0_assert(!EIPSI_Email_Confirmation_Service::validate_confirmation_token($token['token'])['success'],'Confirmation token reused');
};
$tests['HTTP formulario anónimo carga shortcode y assets']=function(){
    $form=wp_insert_post(array('post_type'=>'eipsi_form_template','post_status'=>'publish','post_title'=>'M2 anonymous','post_content'=>'<!-- wp:eipsi/form-container {"formName":"m2-anonymous"} --><form class="eipsi-form"><input name="m2-anonymous-answer"></form><!-- /wp:eipsi/form-container -->'));
    $page=wp_insert_post(array('post_type'=>'page','post_status'=>'publish','post_title'=>'M2 anonymous page','post_content'=>'[eipsi_form id="'.$form.'"]'));
    try{$response=m2_http('/?page_id='.$page);$html=wp_remote_retrieve_body($response);m0_assert(wp_remote_retrieve_response_code($response)===200 && strpos($html,'m2-anonymous-answer')!==false && strpos($html,'assets/js/eipsi-forms.js')!==false,'Anonymous HTTP form failed');}finally{wp_delete_post($page,true);wp_delete_post($form,true);}
};
$tests['HTTP submit longitudinal persiste sesión e ignora email y metadata ajenos']=function(){global $wpdb;
    list(,$session)=m2_login();
    $params=array('form_id'=>'m2-long-form','participant_id'=>'m2-browser','session_id'=>'m2-browser-session','wave_id'=>992221,'email'=>'m2-992208@example.invalid','answer'=>'4','metadata'=>'{"participant_id":992208,"survey_id":992204}');
    $json=m2_json(m2_ajax('eipsi_forms_submit_form',$params,$session,'eipsi_forms_nonce'));
    m0_assert($json['success'] && empty($json['data']['emergency_mode']),'Normal longitudinal HTTP submit failed');
    $row=$wpdb->get_row("SELECT * FROM {$wpdb->prefix}vas_form_results WHERE participant_id='992207' ORDER BY id DESC LIMIT 1");
    m0_assert($row && (int)$row->survey_id===992203,'HTTP submit not attributed to session');
    m0_assert($wpdb->get_var("SELECT status FROM {$wpdb->prefix}survey_assignments WHERE id=992231")==='submitted','Own assignment not submitted');
};
$tests['HTTP submit anónimo conserva fingerprint y survey NULL']=function(){global $wpdb;
    $params=array('form_id'=>'m2-anonymous','participant_id'=>'m2-anonymous-browser','session_id'=>'m2-anonymous-session','email'=>'m2-992208@example.invalid','answer'=>'4');
    $json=m2_json(m2_ajax('eipsi_forms_submit_form',$params,null,'eipsi_forms_nonce'));
    m0_assert($json['success'] && empty($json['data']['emergency_mode']),'Anonymous HTTP submit failed');
    $row=$wpdb->get_row("SELECT * FROM {$wpdb->prefix}vas_form_results WHERE participant_id='m2-anonymous-browser' ORDER BY id DESC LIMIT 1");
    m0_assert($row && $row->survey_id===null,'Anonymous response acquired a study');
};
$tests['API pública: 37 firmas y defaults preservados']=function(){
    $baseline=json_decode(file_get_contents(__DIR__.'/m2/public-api.json'),true);
    foreach($baseline as $class=>$methods){foreach($methods as $method=>$expected){
        $reflection=new ReflectionMethod($class,$method);$actual=array();
        m0_assert($reflection->isPublic() && $reflection->isStatic(),'Public API visibility changed');
        foreach($reflection->getParameters() as $parameter){$row=array('name'=>$parameter->getName(),'optional'=>$parameter->isOptional());if($parameter->isDefaultValueAvailable()){$row['default']=$parameter->getDefaultValue();}$actual[]=$row;}
        m0_assert($actual===$expected,'Public signature changed: '.$class.'::'.$method);
    }}
};
$tests['Rate limit: facade conserva umbral cinco y expiración quince minutos']=function(){
    $email='m2-rate@example.invalid';eipsi_clear_login_rate_limit($email,992203);
    try{for($i=0;$i<5;$i++){m0_assert(eipsi_check_login_rate_limit($email,992203),'Rate limit premature');eipsi_record_failed_login($email,992203);}m0_assert(!eipsi_check_login_rate_limit($email,992203),'Rate limit missing');$key='eipsi_login_attempts_'.md5($email.'992203');m0_assert(abs((int)get_option('_transient_timeout_'.$key)-time()-15*MINUTE_IN_SECONDS)<10,'Rate limit TTL changed');}
    finally{eipsi_clear_login_rate_limit($email,992203);}m0_assert(eipsi_check_login_rate_limit($email,992203),'Rate limit reset failed');
};
$tests['HTTP magic request legacy activo conserva respuesta antienumeración']=function(){
    foreach(array('m2-a@example.invalid','m2-missing@example.invalid') as $email){$json=m2_json(m2_ajax('eipsi_participant_magic_link',array('study_code'=>'m2-992203','email'=>$email)));m0_assert($json['success'],'Legacy magic request broken');m0_assert(!isset($json['data']['token']) && !isset($json['data']['participant_id']),'Legacy request discloses identity/token');}
};
$tests['ParticipantState facade: desactivar/reactivar conserva decisión y revoca identidad']=function(){
    m2_session();m0_assert(EIPSI_Participant_Service::deactivate(992207,'M2 regression'),'Deactivate facade failed');
    m0_assert(EIPSI_Auth_Service::get_current_session()===null,'State service left session valid');
    m0_assert(EIPSI_Participant_Service::get_by_id(992207)->consent_decision==='accepted','State service changed consent');
    m0_assert(EIPSI_Participant_Service::set_active(992207,true),'Reactivation failed');
    m0_assert(EIPSI_Auth_Service::authenticate_passwordless(992203,'m2-a@example.invalid')['success'],'Reactivated participant denied');
};
$tests['Password facade: errores previos y reemplazo hash preservados']=function(){global $wpdb;
    m0_assert(EIPSI_Participant_Service::change_password(992207,'wrong','m2-new-password')['error']==='invalid_password','Old password bypassed');
    m0_assert(EIPSI_Participant_Service::change_password(992207,'m2-valid-password','short')['error']==='short_password','Minimum password changed');
    try{m0_assert(EIPSI_Participant_Service::change_password(992207,'m2-valid-password','m2-new-password')['success'],'Password change failed');m0_assert(EIPSI_Participant_Service::verify_password(992207,'m2-new-password') && !EIPSI_Participant_Service::verify_password(992207,'m2-valid-password'),'Password hash not replaced');}
    finally{$wpdb->update($wpdb->prefix.'survey_participants',array('password_hash'=>wp_hash_password('m2-valid-password')),array('id'=>992207));}
};
$tests['Session cleanup elimina vencidas y conserva token vigente']=function(){global $wpdb;
    $expired=m2_session();$wpdb->update($wpdb->prefix.'survey_sessions',array('expires_at'=>gmdate('Y-m-d H:i:s',time()-3600)),array('token'=>hash('sha256',$expired['token'])));
    $valid=m2_session();m0_assert(EIPSI_Auth_Service::cleanup_expired_sessions()>=1,'Expired cleanup failed');
    m0_assert(!$wpdb->get_var($wpdb->prepare("SELECT token FROM {$wpdb->prefix}survey_sessions WHERE token=%s",hash('sha256',$expired['token']))) && EIPSI_Auth_Service::get_current_participant()===992207,'Cleanup changed live session');
};
$failed=0;$posts=array();$mu=ABSPATH.'wp-content/mu-plugins/eipsi-m2-isolation.php';$owned=false;$fixtures_created=false;
try {
    foreach(array('survey_studies'=>array(992203,992204),'survey_participants'=>array(992207,992208,992209),'survey_waves'=>array(992221),'survey_assignments'=>array(992231),'eipsi_longitudinal_pools'=>array(992201)) as $table=>$ids){foreach($ids as $id){m0_assert(!$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}{$table} WHERE id=%d",$id)),'Fixture collision: '.$table);}}
    $fixtures_created=true;
    m0_assert(!file_exists($mu),'Existing isolation file would be overwritten');wp_mkdir_p(dirname($mu));m0_assert(copy(__DIR__.'/m2/mail-isolation.php',$mu),'Cannot isolate HTTP mail');$owned=true;
    foreach(array(992203,992204) as $id){m0_assert(!$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}survey_studies WHERE id=%d",$id)),'Fixture collision');m0_assert($wpdb->insert($wpdb->prefix.'survey_studies',array('id'=>$id,'study_code'=>'m2-'.$id,'study_name'=>'M2 fixture','created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql'),'config'=>$id===992203?'{"double_opt_in":false}':'{}'))!==false,$wpdb->last_error);}
    foreach(array(992207=>992203,992208=>992203,992209=>992204) as $id=>$study){m0_assert($wpdb->insert($wpdb->prefix.'survey_participants',array('id'=>$id,'survey_id'=>$study,'email'=>$id===992207?'m2-a@example.invalid':'m2-'.$id.'@example.invalid','first_name'=>'M2','password_hash'=>wp_hash_password('m2-valid-password'),'is_active'=>1,'consent_decision'=>'accepted','status'=>'active','created_at'=>current_time('mysql')))!==false,$wpdb->last_error);}
    $form=wp_insert_post(array('post_type'=>'eipsi_form_template','post_status'=>'publish','post_title'=>'M2 longitudinal','post_content'=>'<!-- wp:eipsi/form-container {"formName":"m2-long-form"} --><form class="eipsi-form"><input name="m2-answer"></form><!-- /wp:eipsi/form-container -->'));$posts[]=$form;update_post_meta($form,'_eipsi_form_name','m2-long-form');
    $wpdb->insert($wpdb->prefix.'survey_waves',array('id'=>992221,'study_id'=>992203,'wave_index'=>1,'name'=>'M2 T1','form_id'=>$form,'status'=>'active'));
    $wpdb->insert($wpdb->prefix.'survey_assignments',array('id'=>992231,'study_id'=>992203,'wave_id'=>992221,'participant_id'=>992207,'status'=>'pending'));
    $wpdb->insert($wpdb->prefix.'eipsi_longitudinal_pools',array('id'=>992201,'pool_name'=>'M2 fixture','status'=>'active','config'=>wp_json_encode(array('studies'=>array(array('id'=>992203,'probability'=>100)),'method'=>'seeded'))));
    foreach($tests as $name=>$test){try{m2_reset();$test();echo 'PASS '.$name."\n";}catch(Throwable $error){$failed++;echo 'FAIL '.$name.': '.$error->getMessage()."\n";}}
} catch(Throwable $error){$failed++;echo 'FAIL fixture: '.$error->getMessage()."\n";}
finally {
    if($owned){unlink($mu);}
    if ($fixtures_created) {
    // Owned fixtures only; bootstrap rejects every non-disposable database.
    foreach(array('survey_sessions'=>'survey_id','survey_magic_links'=>'survey_id','survey_email_log'=>'survey_id','survey_email_confirmations'=>'survey_id','survey_participant_access_log'=>'study_id','survey_assignments'=>'study_id','survey_waves'=>'study_id','survey_participants'=>'survey_id') as $table=>$column){
        if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->prefix.$table))){$columns=$wpdb->get_col('SHOW COLUMNS FROM '.$wpdb->prefix.$table);if(in_array($column,$columns,true)){$wpdb->query("DELETE FROM {$wpdb->prefix}{$table} WHERE {$column} IN (992203,992204)");}}
    }
    $wpdb->query("DELETE FROM {$wpdb->prefix}vas_form_results WHERE participant_id IN ('992207','m2-anonymous-browser')");
    $wpdb->delete($wpdb->prefix.'eipsi_pool_assignments',array('pool_id'=>992201));$wpdb->delete($wpdb->prefix.'eipsi_longitudinal_pools',array('id'=>992201));
    $wpdb->query("DELETE FROM {$wpdb->prefix}survey_studies WHERE id IN (992203,992204)");foreach($posts as $id){wp_delete_post($id,true);}
    }
    $_COOKIE=array();
}
echo count($tests).' tests, '.$failed." failures\n";ob_end_flush();exit($failed?1:0);
