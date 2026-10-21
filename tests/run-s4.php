<?php
/** Real UI-request contract tests; guarded isolated WP/MariaDB only. */
define('DOING_CRON',true);
require __DIR__.'/m0/bootstrap.php';
$tests=array();$posts=array();$fixture=false;$mu=ABSPATH.'wp-content/mu-plugins/eipsi-s4-isolation.php';$owned=false;$cron=get_option('cron');
function s4_session($id=999407,$study=999403){$r=EIPSI_Auth_Service::create_session($id,$study);m0_assert($r['success'],'Session');return $r;}
function s4_request($action,$data=array(),$admin=true,$session=null,$nonce_action='eipsi_study_dashboard_nonce'){
    if($admin){$nonce=m0_admin_nonce($nonce_action);$cookies=m0_admin_cookies();}else{wp_set_current_user(0);$nonce=wp_create_nonce($nonce_action);wp_set_current_user(1);$cookies=array();}
    $headers=array('Host'=>'127.0.0.1:18080');$values=array();foreach($cookies as $key=>$value){$values[]=$key.'='.$value;}if($session){$values[]=$session['cookie_name'].'='.rawurlencode($session['token']);}if($values){$headers['Cookie']=implode('; ',$values);}
    $r=wp_remote_post('http://127.0.0.1/wp-admin/admin-ajax.php',array('headers'=>$headers,'body'=>array_merge(array('action'=>$action,'nonce'=>$nonce),$data),'timeout'=>30,'redirection'=>0));m0_assert(!is_wp_error($r),'HTTP transport');return $r;
}
function s4_json($r){$j=json_decode(wp_remote_retrieve_body($r),true);m0_assert(is_array($j),'JSON expected: '.substr(wp_remote_retrieve_body($r),0,200));return $j;}
function s4_reset(){global $wpdb;$_COOKIE=array();delete_option('eipsi_s4_logout_sql_failure');delete_option('eipsi_s4_export_sql_failure');delete_option('eipsi_s4_mail_failure');delete_option('eipsi_s4_trace');delete_option('eipsi_s4_mail');$anchor=date('Y-m-d H:i:s',current_time('timestamp')-86400);$GLOBALS['s4_anchor']=$anchor;
 $wpdb->update($wpdb->prefix.'survey_participants',array('is_active'=>1,'consent_decision'=>'accepted','t1_completed_at'=>$anchor),array('id'=>999407));
 foreach(array(999421,999422,999423)as$i=>$wave){$wpdb->update($wpdb->prefix.'survey_waves',array('status'=>'active','offset_minutes'=>$i*2880,'window_minutes'=>30),array('id'=>$wave));$wpdb->update($wpdb->prefix.'survey_assignments',array('status'=>$i===0?'submitted':'pending','available_at'=>current_time('mysql'),'due_at'=>date('Y-m-d H:i:s',current_time('timestamp')+86400),'submitted_at'=>$i===0?$anchor:null,'t1_completed_at'=>$i===0?$anchor:null,'reminder_count'=>1),array('wave_id'=>$wave,'participant_id'=>999407));}
 $wpdb->delete($wpdb->prefix.'survey_email_log',array('survey_id'=>999403));$wpdb->delete($wpdb->prefix.'survey_audit_log',array('survey_id'=>999403));
}
if(is_file(__DIR__.'/s4/cases.php')){require __DIR__.'/s4/cases.php';}
if(getenv('EIPSI_S4_EXPECT_RCT_DENIAL')){$tests=array_filter($tests,function($name){return strpos($name,'S4-03 RCT')!==false;},ARRAY_FILTER_USE_KEY);}
$failed=0;
try{
 foreach(array('survey_studies'=>array(999403,999404),'survey_participants'=>array(999407,999408,999409),'survey_waves'=>array(999421,999422,999423,999424),'survey_assignments'=>array(999431,999432,999433,999434))as$table=>$ids){foreach($ids as$id){m0_assert(!$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}{$table} WHERE id=%d",$id)),'Fixture collision');}}
 $fixture=true;
 foreach(array(999403,999404)as$id){m0_assert($wpdb->insert($wpdb->prefix.'survey_studies',array('id'=>$id,'study_code'=>'s4-'.$id,'study_name'=>'S4 fixture','status'=>'active','config'=>'{}'))===1,'Study');}
 foreach(array(999407=>999403,999408=>999403,999409=>999404)as$id=>$study){m0_assert($wpdb->insert($wpdb->prefix.'survey_participants',array('id'=>$id,'survey_id'=>$study,'email'=>'s4-'.$id.'@example.invalid','is_active'=>1,'consent_decision'=>'accepted','created_at'=>current_time('mysql')))===1,'Participant');}
 $form=wp_insert_post(array('post_type'=>'eipsi_form_template','post_status'=>'publish','post_title'=>'S4 form','post_content'=>'<!-- wp:eipsi/form-container {"formName":"s4-form"} --><form class="eipsi-form"><input name="s4-answer"></form><!-- /wp:eipsi/form-container -->'));$posts[]=$form;
 foreach(array(999421,999422,999423,999424)as$i=>$wave){$study=$i===3?999404:999403;m0_assert($wpdb->insert($wpdb->prefix.'survey_waves',array('id'=>$wave,'study_id'=>$study,'wave_index'=>$i===3?1:$i+1,'name'=>'S4 T'.($i+1),'form_id'=>$form,'status'=>'active'))===1,'Wave');m0_assert($wpdb->insert($wpdb->prefix.'survey_assignments',array('id'=>999431+$i,'study_id'=>$study,'wave_id'=>$wave,'participant_id'=>$i===3?999409:999407,'status'=>'pending'))===1,'Assignment');}
 if(is_file(__DIR__.'/s4/mail-isolation.php')){m0_assert(!file_exists($mu),'MU collision');wp_mkdir_p(dirname($mu));m0_assert(copy(__DIR__.'/s4/mail-isolation.php',$mu),'MU copy');$owned=true;}
 foreach($tests as$name=>$test){try{s4_reset();$test();echo 'PASS '.$name."\n";}catch(Throwable$e){$failed++;echo 'FAIL '.$name.': '.$e->getMessage()."\n";}}
}catch(Throwable$e){$failed++;echo 'FAIL fixtures: '.$e->getMessage()."\n";}
finally{
 if($owned){unlink($mu);}foreach($posts as$id){wp_delete_post($id,true);}
 if($fixture){foreach(array('survey_sessions'=>'survey_id','survey_magic_links'=>'survey_id','survey_email_log'=>'survey_id','survey_audit_log'=>'survey_id','survey_participant_access_log'=>'study_id','survey_assignments'=>'study_id','survey_waves'=>'study_id','survey_participants'=>'survey_id','survey_studies'=>'id')as$table=>$column){$wpdb->query("DELETE FROM {$wpdb->prefix}{$table} WHERE {$column} IN(999403,999404)");}$wpdb->query("DELETE FROM {$wpdb->prefix}eipsi_partial_responses WHERE form_id='s4-partial-fixture' OR (participant_id='999407' AND session_id='s4-future-partial')");$wpdb->query("DELETE FROM {$wpdb->prefix}survey_nudge_jobs WHERE JSON_EXTRACT(payload,'$.assignment_id') IN(999431,999432,999433)");}
 foreach(array('eipsi_s4_logout_sql_failure','eipsi_s4_export_sql_failure','eipsi_s4_mail_failure','eipsi_s4_trace','eipsi_s4_mail')as$key){delete_option($key);}update_option('cron',$cron);$_COOKIE=array();
}
if(isset($GLOBALS['s4_rct_evidence'])){file_put_contents('/tmp/s4-rct-evidence.json',wp_json_encode($GLOBALS['s4_rct_evidence'],JSON_PRETTY_PRINT));}
echo count($tests).' tests, '.$failed." failures\n";ob_end_flush();exit($failed?1:0);
