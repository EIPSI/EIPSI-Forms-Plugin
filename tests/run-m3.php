<?php
/** M3 regression: real WordPress/MariaDB and anonymous/participant HTTP. */
require __DIR__.'/m0/bootstrap.php';
$tests=array();
function m3_reset($data = array()) {
    global $wpdb;
    $_COOKIE = array();
    $wpdb->update($wpdb->prefix.'survey_participants', array_merge(array('is_active'=>1,'consent_decision'=>'accepted','status'=>'active'), $data), array('id'=>992207));
    eipsi_clear_login_rate_limit('m2-a@example.invalid', 992203);
    delete_transient('eipsi_auth_origin_'.md5('127.0.0.1'));
    delete_transient('eipsi_auth_email_'.md5('m2-a@example.invalid:992203'));
}
function m3_session() {
    $result=EIPSI_Auth_Service::create_session(992207,992203);
    m0_assert($result['success'],'Session creation failed');
    return $result;
}
function m3_nonce($action) {
    wp_set_current_user(0); $nonce=wp_create_nonce($action); wp_set_current_user(1); return $nonce;
}
function m3_http($path, $body=null, $session=null) {
    // Reproduce the encoded Set-Cookie value a browser sends (tokens contain '+').
    $headers=array('Host'=>'127.0.0.1:18080');
    if ($session) { $headers['Cookie']=$session['cookie_name'].'='.rawurlencode($session['token']); }
    if (strpos($path,'rest_route=')!==false) { $headers['Content-Type']='application/json'; $body=wp_json_encode($body); }
    $result=wp_remote_request('http://127.0.0.1'.$path,array('method'=>$body===null?'GET':'POST','body'=>$body,'timeout'=>25,'redirection'=>0,'headers'=>$headers));
    m0_assert(!is_wp_error($result),'HTTP transport failed'); return $result;
}
function m3_ajax($action,$data=array(),$session=null,$nonce_action='eipsi_participant_auth') {
    return m3_http('/wp-admin/admin-ajax.php',array_merge(array('action'=>$action,'nonce'=>m3_nonce($nonce_action)),$data),$session);
}
function m3_json($response) {
    $json=json_decode(wp_remote_retrieve_body($response),true);
    m0_assert(is_array($json),'Non-JSON HTTP response, status '.wp_remote_retrieve_response_code($response)); return $json;
}
function m3_pool($params,$session=null) { return m3_http('/?rest_route=/eipsi/v1/pool-assign',$params,$session); }
function m3_login() {
    $response=m3_ajax('eipsi_participant_login',array('survey_id'=>992203,'email'=>'m2-a@example.invalid','password'=>'m2-valid-password'));
    $json=m3_json($response); m0_assert($json['success'],'HTTP login failed');
    return array($response,array('token'=>$json['data']['session_token'],'cookie_name'=>$json['data']['cookie_name']));
}
require __DIR__.'/m3/forms-cases.php';
$failed=0;$posts=array();$mu=ABSPATH.'wp-content/mu-plugins/eipsi-m3-isolation.php';$owned=false;$fixtures_created=false;
try {
    foreach(array('survey_studies'=>array(992203,992204),'survey_participants'=>array(992207,992208,992209),'survey_waves'=>array(992221),'survey_assignments'=>array(992231),'eipsi_longitudinal_pools'=>array(992201)) as $table=>$ids){foreach($ids as $id){m0_assert(!$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}{$table} WHERE id=%d",$id)),'Fixture collision: '.$table);}}
    $fixtures_created=true;
    m0_assert(!file_exists($mu),'Existing isolation file would be overwritten');wp_mkdir_p(dirname($mu));m0_assert(copy(__DIR__.'/m3/mail-isolation.php',$mu),'Cannot isolate HTTP mail');$owned=true;
    foreach(array(992203,992204) as $id){m0_assert(!$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}survey_studies WHERE id=%d",$id)),'Fixture collision');m0_assert($wpdb->insert($wpdb->prefix.'survey_studies',array('id'=>$id,'study_code'=>'m2-'.$id,'study_name'=>'M2 fixture','created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql'),'config'=>$id===992203?'{"double_opt_in":false}':'{}'))!==false,$wpdb->last_error);}
    foreach(array(992207=>992203,992208=>992203,992209=>992204) as $id=>$study){m0_assert($wpdb->insert($wpdb->prefix.'survey_participants',array('id'=>$id,'survey_id'=>$study,'email'=>$id===992207?'m2-a@example.invalid':'m2-'.$id.'@example.invalid','first_name'=>'M2','password_hash'=>wp_hash_password('m2-valid-password'),'is_active'=>1,'consent_decision'=>'accepted','status'=>'active','created_at'=>current_time('mysql')))!==false,$wpdb->last_error);}
    $form=wp_insert_post(array('post_type'=>'eipsi_form_template','post_status'=>'publish','post_title'=>'M2 longitudinal','post_content'=>'<!-- wp:eipsi/form-container {"formName":"m2-long-form"} --><form class="eipsi-form"><input name="m2-answer"></form><!-- /wp:eipsi/form-container -->'));$posts[]=$form;update_post_meta($form,'_eipsi_form_name','m2-long-form');
    $wpdb->insert($wpdb->prefix.'survey_waves',array('id'=>992221,'study_id'=>992203,'wave_index'=>1,'name'=>'M2 T1','form_id'=>$form,'status'=>'active'));
    $wpdb->insert($wpdb->prefix.'survey_assignments',array('id'=>992231,'study_id'=>992203,'wave_id'=>992221,'participant_id'=>992207,'status'=>'pending'));
    $wpdb->insert($wpdb->prefix.'eipsi_longitudinal_pools',array('id'=>992201,'pool_name'=>'M2 fixture','status'=>'active','config'=>wp_json_encode(array('studies'=>array(array('id'=>992203,'probability'=>100)),'method'=>'seeded'))));
    foreach($tests as $name=>$test){try{m3_reset();$test();echo 'PASS '.$name."\n";}catch(Throwable $error){$failed++;echo 'FAIL '.$name.': '.$error->getMessage()."\n";}}
} catch(Throwable $error){$failed++;echo 'FAIL fixture: '.$error->getMessage()."\n";}
finally {
    if($owned){unlink($mu);}
    delete_option('eipsi_m3_completion_trace');
    $wpdb->delete($wpdb->prefix.'eipsi_partial_responses',array('participant_id'=>'m3-draft'));
    $wpdb->query("DELETE FROM {$wpdb->prefix}vas_form_events WHERE session_id LIKE 'm3-tracking-%'");
    $wpdb->query("DELETE FROM {$wpdb->prefix}vas_form_results WHERE participant_id='m3-anonymous'");
    delete_transient('eipsi_sc_rl_'.md5('sid:m3-draft-session'));
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
