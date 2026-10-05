<?php
/** Loaded only by the disposable-database runner. */
if (PHP_SAPI !== 'cli' || !function_exists('m0_assert')) { exit; }
function m3_submit($extra=array(),$session=null) {
    return m3_ajax('eipsi_forms_submit_form',array_merge(array('form_id'=>'m3-anonymous','participant_id'=>'m3-anonymous','session_id'=>'m3-anonymous-session','answer'=>'4'),$extra),$session,'eipsi_forms_nonce');
}
function m3_draft($action,$extra=array()) {
    return m3_ajax($action,array_merge(array('form_id'=>'m3-draft-form','participant_id'=>'m3-draft','session_id'=>'m3-draft-session','page_index'=>2,'responses'=>array('answer'=>'4')),$extra),null,'eipsi_save_partial');
}
$tests['Renderer: escaped attributes and legacy facade']=function(){
    $attrs=array('data-id'=>'<"x','empty'=>'','null'=>null);
    m0_assert(eipsi_build_html_attributes($attrs)===EIPSI_Form_Renderer::eipsi_build_html_attributes($attrs),'Render facade differs');
    m0_assert(strpos(eipsi_build_html_attributes($attrs),'&lt;')!==false && strpos(eipsi_build_html_attributes($attrs),'empty')===false,'Attribute escaping changed');
};
$tests['Renderer: nonexistent template remains an error notice']=function(){m0_assert(is_wp_error(eipsi_get_form_template(0)),'Missing form accepted');m0_assert(strpos(eipsi_render_form_template_markup(0),'eipsi-form-notice-error')!==false,'Notice missing');};
foreach(array('simple'=>1,'multipage'=>3) as $label=>$pages){$tests['HTTP render '.$label.' shortcode and Gutenberg']=function()use($pages){
    $markup='<!-- wp:eipsi/form-container {"formName":"m3-render"} --><div class="eipsi-form"><form>';
    for($i=1;$i<=$pages;$i++){$markup.='<div class="eipsi-page" data-page="'.$i.'"><input name="m3-render-'.$i.'"></div>';}
    $markup.='</form></div><!-- /wp:eipsi/form-container -->';
    $template=wp_insert_post(array('post_type'=>'eipsi_form_template','post_status'=>'publish','post_title'=>'M3 render','post_content'=>$markup));
    $page=wp_insert_post(array('post_type'=>'page','post_status'=>'publish','post_title'=>'M3 render page','post_content'=>'[eipsi_form id="'.$template.'"]'));
    try{$render=eipsi_render_form_template_markup($template);m0_assert(substr_count($render,'class="eipsi-page"')===$pages,'Gutenberg markup lost pages');
        m0_assert(strpos(do_shortcode('[eipsi_form id="'.$template.'"]'),'m3-render-1')!==false,'Shortcode did not render');
        $http=m3_http('/?page_id='.$page);$html=wp_remote_retrieve_body($http);m0_assert(wp_remote_retrieve_response_code($http)===200 && strpos($html,'m3-render-'.$pages)!==false && strpos($html,'assets/js/eipsi-forms.js')!==false,'HTTP render/assets failed');
    }finally{wp_delete_post($page,true);wp_delete_post($template,true);}
};}
$tests['HTTP frontend JS assets return 200']=function(){foreach(array('eipsi-forms.js','eipsi-tracking.js','eipsi-save-continue.js') as $file){$r=m3_http('/wp-content/plugins/EIPSI-Forms-Plugin/assets/js/'.$file);m0_assert(wp_remote_retrieve_response_code($r)===200 && strlen(wp_remote_retrieve_body($r))>100,'Missing runtime asset '.$file);}};
$tests['HTTP submit invalid nonce rejected before persistence']=function(){global $wpdb;$before=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}vas_form_results WHERE participant_id='m3-anonymous'");$r=m3_submit(array('nonce'=>'invalid'));m0_assert(wp_remote_retrieve_response_code($r)===403,'Nonce allowed');m0_assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}vas_form_results WHERE participant_id='m3-anonymous'")===$before,'Invalid nonce persisted');};
$tests['HTTP anonymous submit persists and repeated request creates another response']=function(){global $wpdb;
    $before=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}vas_form_results WHERE participant_id='m3-anonymous'");
    foreach(array(1,2) as $n){$json=m3_json(m3_submit());m0_assert($json['success'] && empty($json['data']['emergency_mode']) && $json['data']['storage_type']==='wordpress_db','Normal submit failed');}
    m0_assert((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}vas_form_results WHERE participant_id='m3-anonymous'")===$before+2,'Legacy anonymous repeat behavior changed');
    $row=$wpdb->get_row("SELECT * FROM {$wpdb->prefix}vas_form_results WHERE participant_id='m3-anonymous' ORDER BY id DESC LIMIT 1");m0_assert($row->survey_id===null,'Anonymous form acquired study');
};
$tests['HTTP anonymous form remains anonymous with a participant session']=function(){global $wpdb;$session=m3_session();$j=m3_json(m3_submit(array('email'=>'m2-992208@example.invalid','metadata'=>'{"participant_id":992208,"survey_id":992204}'),$session));m0_assert($j['success'],'Session prevented anonymous form');$row=$wpdb->get_row("SELECT * FROM {$wpdb->prefix}vas_form_results WHERE participant_id='m3-anonymous' ORDER BY id DESC LIMIT 1");m0_assert($row && $row->survey_id===null,'Participant session leaked into anonymous form');};
$tests['HTTP longitudinal public request rejected']=function(){$r=m3_submit(array('form_id'=>'m2-long-form','wave_id'=>992221));m0_assert(wp_remote_retrieve_response_code($r)===403 && !m3_json($r)['success'],'Longitudinal form anonymous access');};
$tests['HTTP longitudinal client participant cannot impersonate B']=function(){global $wpdb;$session=m3_session();$r=m3_submit(array('form_id'=>'m2-long-form','wave_id'=>992221,'participant_id'=>992208),$session);m0_assert(wp_remote_retrieve_response_code($r)===403,'Foreign identity accepted');m0_assert($wpdb->get_var("SELECT status FROM {$wpdb->prefix}survey_assignments WHERE id=992231")==='pending','Foreign submit changed assignment');};
foreach(array('declined','withdrawn') as $state){$tests['HTTP submit rejects '.$state.' session']=function()use($state){global $wpdb;$session=m3_session();$wpdb->update($wpdb->prefix.'survey_participants',array('consent_decision'=>$state),array('id'=>992207));$r=m3_submit(array('form_id'=>'m2-long-form','wave_id'=>992221),$session);m0_assert(wp_remote_retrieve_response_code($r)===403 && !m3_json($r)['success'],'Revoked state accepted');};}
$tests['HTTP partial save/load restores responses and current page']=function(){global $wpdb;$j=m3_json(m3_draft('eipsi_save_partial_response'));m0_assert($j['success'],'Save failed');$j=m3_json(m3_draft('eipsi_load_partial_response'));m0_assert($j['success'] && $j['data']['found'] && $j['data']['partial']['page_index']===2 && $j['data']['partial']['responses']['answer']==='4','Restore contract changed');};
$tests['Partial owner and legacy facade share stored keys']=function(){m0_assert(EIPSI_Partial_Responses::load('m3-draft-form','m3-draft','m3-draft-session')===EIPSI_Partial_Response_Service::load('m3-draft-form','m3-draft','m3-draft-session'),'Facade mismatch');m0_assert(EIPSI_Partial_Responses::load('m3-draft-form','other','m3-draft-session')===null,'Foreign key loaded');};
$tests['HTTP partial invalid nonce returns 403']=function(){m0_assert(wp_remote_retrieve_response_code(m3_draft('eipsi_save_partial_response',array('nonce'=>'invalid')))===403,'Invalid nonce accepted');};
$tests['HTTP partial payload over 50KB rejected']=function(){m0_assert(wp_remote_retrieve_response_code(m3_draft('eipsi_save_partial_response',array('responses'=>str_repeat('x',51201))))===400,'Payload guard lost');};
$tests['Partial privacy strips IP and sensitive metadata recursively']=function(){ $key='eipsi_privacy_config_'.generate_stable_form_id('m3-draft-form');$old=get_option($key,null);update_option($key,array('ip_address'=>false,'user_agent_full'=>false));try{EIPSI_Partial_Responses::save('m3-draft-form','m3-draft','m3-draft-session',3,array('answer'=>'4','ip_address'=>'192.0.2.3','metadata'=>array('user_agent'=>'private','ip_address'=>'192.0.2.4')));$r=EIPSI_Partial_Responses::load('m3-draft-form','m3-draft','m3-draft-session');m0_assert(!isset($r['responses']['ip_address']) && !isset($r['responses']['metadata']['ip_address']) && $r['responses']['answer']==='4','Privacy filtering lost');}finally{if($old===null){delete_option($key);}else{update_option($key,$old);}}};
$tests['HTTP partial discard removes only matching draft']=function(){m0_assert(m3_json(m3_draft('eipsi_discard_partial_response'))['success'],'Discard failed');m0_assert(EIPSI_Partial_Responses::load('m3-draft-form','m3-draft','m3-draft-session')===null,'Discarded draft returned');};
$tests['Completed partial no longer offered for recovery']=function(){EIPSI_Partial_Responses::save('m3-draft-form','m3-draft','m3-draft-session',2,array('answer'=>'4'));m0_assert(EIPSI_Partial_Responses::mark_completed('m3-draft-form','m3-draft','m3-draft-session'),'Mark completed failed');m0_assert(EIPSI_Partial_Responses::load('m3-draft-form','m3-draft','m3-draft-session')===null,'Completed draft recovered');};
foreach(array('view','start','page_change','submit','abandon','branch_jump') as $event){$tests['HTTP tracking event '.$event.' persists with privacy']=function()use($event){global $wpdb;$sid='m3-tracking-'.$event;$r=m3_ajax('eipsi_track_event',array('event_type'=>$event,'form_id'=>'m3-tracking-form','session_id'=>$sid,'page_number'=>2,'from_page'=>1,'to_page'=>2,'field_id'=>'answer','matched_value'=>'private','user_agent'=>'private'),null,'eipsi_tracking_nonce');$j=m3_json($r);m0_assert($j['success'] && $j['data']['tracked'],'Tracking failed');$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}vas_form_events WHERE session_id=%s ORDER BY id DESC LIMIT 1",$sid));m0_assert($row && $row->event_type===$event && empty($row->user_agent),'Tracking/privacy contract changed');};}
$tests['HTTP tracking invalid type, missing session and nonce rejected']=function(){foreach(array(array('event_type'=>'unknown','session_id'=>'s'),array('event_type'=>'view'),array('event_type'=>'view','session_id'=>'s','nonce'=>'invalid')) as $params){$r=m3_ajax('eipsi_track_event',$params,null,'eipsi_tracking_nonce');m0_assert(!m3_json($r)['success'] && wp_remote_retrieve_response_code($r)>=400,'Invalid tracking accepted');}};
$tests['HTTP pending consent can accept own identity; B unchanged']=function(){global $wpdb;$session=m3_session();$wpdb->update($wpdb->prefix.'survey_participants',array('consent_decision'=>''),array('id'=>992207));$r=m3_ajax('eipsi_save_consent_decision',array('form_id'=>'m2-long-form','decision'=>'accepted'),$session,'eipsi_forms_nonce');m0_assert(m3_json($r)['success'],'Own consent failed');m0_assert($wpdb->get_var("SELECT consent_decision FROM {$wpdb->prefix}survey_participants WHERE id=992207")==='accepted','Consent not persisted');};
$tests['HTTP longitudinal T1 and completion hook after assignment update']=function(){global $wpdb;$session=m3_session();m0_assert(m3_json(m3_pool(array('pool_id'=>992201),$session))['success'],'Pool fixture assignment failed');delete_option('eipsi_m3_completion_trace');$r=m3_submit(array('form_id'=>'m2-long-form','wave_id'=>992221,'participant_id'=>'m2-browser','email'=>'m2-992208@example.invalid','metadata'=>'{"participant_id":992208,"survey_id":992204}'),$session);$j=m3_json($r);m0_assert($j['success'],'Longitudinal submit failed');$row=$wpdb->get_row("SELECT * FROM {$wpdb->prefix}vas_form_results WHERE participant_id='992207' ORDER BY id DESC LIMIT 1");m0_assert($row && (int)$row->survey_id===992203,'Canonical identity lost');wp_cache_delete('eipsi_m3_completion_trace','options');$trace=get_option('eipsi_m3_completion_trace');m0_assert(is_array($trace) && count($trace)===2 && $trace[0]['priority']===0 && $trace[1]['priority']===99,'Completion hook changed');foreach($trace as $step){m0_assert($step['assignment']==='submitted' && !empty($step['t1']) && (int)$step['data']['participant_id']===992207 && (int)$step['data']['wave_index']===1,'Completion preceded longitudinal state');}};
$tests['HTTP repeated longitudinal submit rejected after assignment completed']=function(){$session=m3_session();$r=m3_submit(array('form_id'=>'m2-long-form','wave_id'=>992221,'participant_id'=>'m2-browser'),$session);m0_assert(wp_remote_retrieve_response_code($r)===403 && !m3_json($r)['success'],'Completed assignment submitted twice');};

$tests['Pool completion hook uses schema pool_name and canonical numeric participant']=function(){global $wpdb;
    m0_assert(has_action('eipsi_form_submitted','eipsi_check_pool_completion_on_submit')===10,'Pool callback lost');
    m0_assert((int)$wpdb->get_var("SELECT completed FROM {$wpdb->prefix}eipsi_pool_assignments WHERE pool_id=992201 AND participant_id=992207")===1,'Completion hook did not persist completion');
    $columns=$wpdb->get_col('SHOW COLUMNS FROM '.$wpdb->prefix.'eipsi_longitudinal_pools');
    m0_assert(in_array('pool_name',$columns,true) && !in_array('name',$columns,true),'Pool schema premise changed; review legacy p.name query');
    $wpdb->update($wpdb->prefix.'eipsi_pool_assignments',array('completed'=>0),array('pool_id'=>992201,'participant_id'=>992207));
    m0_assert(eipsi_check_and_mark_pool_completion(992207,992203,'m2-long-form'),'Legacy facade failed to resolve canonical numeric identity');
    m0_assert((int)$wpdb->get_var("SELECT completed FROM {$wpdb->prefix}eipsi_pool_assignments WHERE pool_id=992201 AND participant_id=992207")===1,'Completion was not persisted');
};
foreach(array(2,3) as $index){$tests['HTTP longitudinal T'.$index.' retains wave index']=function()use($index){global $wpdb,$posts;
    $form=wp_insert_post(array('post_type'=>'eipsi_form_template','post_status'=>'publish','post_title'=>'M3 T'.$index,'post_content'=>'<!-- wp:eipsi/form-container {"formName":"m3-t'.$index.'"} --><form><input name="answer"></form><!-- /wp:eipsi/form-container -->'));$posts[]=$form;update_post_meta($form,'_eipsi_form_name','m3-t'.$index);
    $wave=992220+$index;$assignment=992230+$index;
    m0_assert($wpdb->insert($wpdb->prefix.'survey_waves',array('id'=>$wave,'study_id'=>992203,'wave_index'=>$index,'name'=>'M3 T'.$index,'form_id'=>$form,'status'=>'active'))!==false,'Wave fixture failed');
    m0_assert($wpdb->insert($wpdb->prefix.'survey_assignments',array('id'=>$assignment,'study_id'=>992203,'wave_id'=>$wave,'participant_id'=>992207,'status'=>'pending'))!==false,'Assignment fixture failed');
    $session=m3_session();$j=m3_json(m3_submit(array('form_id'=>'m3-t'.$index,'wave_id'=>$wave,'participant_id'=>'m2-browser'),$session));m0_assert($j['success'],'T'.$index.' submit failed');
    $row=$wpdb->get_row("SELECT * FROM {$wpdb->prefix}vas_form_results WHERE participant_id='992207' ORDER BY id DESC LIMIT 1");m0_assert((int)$row->wave_index===$index && (int)$row->survey_id===992203,'T'.$index.' index changed');
    m0_assert($wpdb->get_var($wpdb->prepare("SELECT status FROM {$wpdb->prefix}survey_assignments WHERE id=%d",$assignment))==='submitted','T'.$index.' assignment pending');
};}
$tests['HTTP declined consent saves decision and revokes session']=function(){global $wpdb;$session=m3_session();$j=m3_json(m3_ajax('eipsi_save_consent_decision',array('form_id'=>'m2-long-form','decision'=>'declined'),$session,'eipsi_forms_nonce'));m0_assert($j['success'] && $wpdb->get_var("SELECT consent_decision FROM {$wpdb->prefix}survey_participants WHERE id=992207")==='declined','Declined decision changed');m0_assert(!m3_json(m3_ajax('eipsi_participant_info',array(),$session))['success'],'Declined session remains valid');};
$tests['Capture device metadata respects per-form policy']=function(){
    $key='eipsi_privacy_config_'.generate_stable_form_id('m3-anonymous');$old=get_option($key,null);update_option($key,array('ip_address'=>false,'device_type'=>false,'browser'=>false,'os'=>false,'screen_width'=>false,'fingerprint_enabled'=>false,'user_agent_full'=>false));
    try{$context=EIPSI_Auth_Service::authorize_form_operation('m3-anonymous',array('participant_id'=>'m3-anonymous'));
        $r=EIPSI_Form_Capture_Service::capture(array('form_id'=>'m3-anonymous','participant_id'=>'m3-anonymous','session_id'=>'m3-policy','answer'=>'4','metadata'=>'{"device_data":{"user_agent":"secret","canvas_fingerprint":"secret"}}'),array('REMOTE_ADDR'=>'192.0.2.3','HTTP_USER_AGENT'=>'secret'),'m3-anonymous',$context);
        m0_assert(empty($r['data']['ip_address']) && strpos(wp_json_encode($r['metadata_array']),'secret')===false,'Disabled metadata captured');
    }finally{if($old===null){delete_option($key);}else{update_option($key,$old);}}
};
function m3_storage_data(){require_once EIPSI_FORMS_PLUGIN_DIR.'admin/data-safety-system.php';return array('form_id'=>'m3-storage','participant_id'=>'m3-anonymous','session_id'=>'m3-storage','form_responses'=>'{"answer":4}','submitted_at'=>current_time('mysql'));}
$tests['Storage adapter validates existing rules, not a new field engine']=function(){m3_storage_data();m0_assert(!EIPSI_Form_Storage_Adapter::validate(array())['valid'],'Missing form id accepted');m0_assert(EIPSI_Form_Storage_Adapter::validate(m3_storage_data())['valid'],'Historical submission rejected');};
$tests['Storage external failure falls back to confirmed local row']=function(){global $wpdb;
    $names=array('enabled','host','user','password','name','last_updated','last_error','last_error_code','last_error_time');$old=array();foreach($names as $n){$old[$n]=get_option('eipsi_external_db_'.$n,null);}
    // Only the isolated Docker DB is contacted, with deliberately invalid credentials.
    (new EIPSI_External_Database())->save_credentials('eipsi-m0-db','m3-refused-user','m3-invalid-password','m0');
    try{$data=m3_storage_data();$r=EIPSI_Form_Storage_Adapter::save($data,1);m0_assert($r['success'] && $r['storage']==='wordpress_db' && EIPSI_Form_Storage_Adapter::verify($r['insert_id'],$r['storage'],$data),'Fallback not persisted/verified');}
    finally{foreach($old as $n=>$v){if($v===null){delete_option('eipsi_external_db_'.$n);}else{update_option('eipsi_external_db_'.$n,$v);}}}
};
$tests['Storage emergency succeeds only with actual emergency INSERT']=function(){global $wpdb;
    $filter=function($sql)use($wpdb){if(strpos($sql,'INSERT INTO `'.$wpdb->prefix.'vas_form_results`')===0){return 'INSERT INTO m3_nonexistent_table VALUES (1)';}return $sql;};add_filter('query',$filter);
    try{$r=EIPSI_Form_Storage_Adapter::save(m3_storage_data(),1);m0_assert($r['success'] && $r['emergency_mode'] && $r['storage']==='emergency_table_wp','Emergency destination changed');m0_assert((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}eipsi_emergency_submissions WHERE id=%d",$r['emergency_id']))===1,'Emergency success without row');}
    finally{remove_filter('query',$filter);$wpdb->delete($wpdb->prefix.'eipsi_emergency_submissions',array('participant_id'=>'m3-anonymous'));}
};
$tests['Submit all INSERT failures return error, never success']=function(){global $wpdb;$_COOKIE=array();
    $filter=function($sql)use($wpdb){if(strpos($sql,'INSERT INTO `'.$wpdb->prefix.'vas_form_results`')===0 || strpos($sql,'INSERT INTO `'.$wpdb->prefix.'eipsi_emergency_submissions`')===0){return 'INSERT INTO m3_nonexistent_table VALUES (1)';}return $sql;};add_filter('query',$filter);
    try{$r=EIPSI_Submit_Service::submit(array('form_id'=>'m3-anonymous','participant_id'=>'m3-anonymous','session_id'=>'m3-failure','answer'=>'4'),array(),array());m0_assert(!$r['success'] && $r['status']===500 && $r['data']['error_code']==='SAFETY_SYSTEM_FAILURE','All failed inserts confirmed success');}
    finally{remove_filter('query',$filter);}
};

$tests['Public Forms facade signatures and defaults preserved']=function(){
    $baseline=json_decode(file_get_contents(__DIR__.'/contracts.json'),true);
    foreach($baseline['public_api'] as $name=>$expected){
        $fn=new ReflectionFunction($name);$actual=array();
        foreach($fn->getParameters() as $parameter){$row=array('name'=>$parameter->getName(),'optional'=>$parameter->isOptional());if($parameter->isDefaultValueAvailable()){$row['default']=$parameter->getDefaultValue();}$actual[]=$row;}
        m0_assert($actual===$expected,'Public signature/default changed: '.$name);
    }
};
