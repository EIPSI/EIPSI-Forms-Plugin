<?php
/** S2 real isolated WordPress/MariaDB job integrity regression. */
define('DOING_CRON', true);
require __DIR__.'/m0/bootstrap.php';
$tests=array();
$jobs=array();
$s2_existing_jobs=$wpdb->get_results("SELECT * FROM {$wpdb->prefix}survey_nudge_jobs",ARRAY_A);
// Recovery/cron selectors must not consume historical upgrade seed jobs.
$s2_selector_scope=function($sql)use($wpdb){
    if (strpos($sql,'SELECT')===0 && strpos($sql,'FROM '.$wpdb->prefix.'survey_nudge_jobs')!==false) {
        $sql=preg_replace("/WHERE\\s+status\\s*=\\s*'(pending|processing)'/", "WHERE JSON_EXTRACT(payload,'$.assignment_id')=998231 AND status='$1'", $sql);
    }
    return $sql;
};
add_filter('query',$s2_selector_scope);
function s2_job($type='send_nudge_1', $payload=null) {
    $id=EIPSI_Notification_Nudge_Queue_Service::enqueue($type,$payload??array('assignment_id'=>998231,'participant_id'=>998207,'wave_id'=>998221,'study_id'=>998203));
    m0_assert($id>0,'Enqueue failed'); $GLOBALS['jobs'][]=$id; return $id;
}
function s2_row($id) { global $wpdb; return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}survey_nudge_jobs WHERE id=%d",$id)); }
function s2_fail_update($id,$status,$callback) {
    global $wpdb;
    $filter=function($sql)use($id,$status,$wpdb){
        if(strpos($sql,'UPDATE `'.$wpdb->prefix.'survey_nudge_jobs`')===0 && strpos(explode(" WHERE ",$sql)[0],"'".$status."'")!==false && preg_match('/`id`\s*=\s*[\"\']?'.intval($id).'\b/',$sql)) { return 'UPDATE eipsi_s2_nonexistent_table SET status=1'; }
        return $sql;
    };
    add_filter('query',$filter);$previous=$wpdb->suppress_errors(true);
    try { return $callback(); } finally { remove_filter('query',$filter);$wpdb->suppress_errors($previous); }
}
$tests['Delivered job must not report completed when terminal UPDATE fails']=function(){
    global $wpdb; $id=s2_job();m0_assert(EIPSI_Notification_Nudge_Queue_Service::mark_processing($id),'Claim failed');
    $mail=function(){return true;}; add_filter('pre_wp_mail',$mail,PHP_INT_MAX);
    try { m0_assert(wp_mail('s2@example.invalid','S2 controlled','fixture'),'Controlled delivery failed'); }
    finally { remove_filter('pre_wp_mail',$mail,PHP_INT_MAX); }
    $result=s2_fail_update($id,'completed',function()use($id){return EIPSI_Notification_Nudge_Queue_Service::mark_completed($id,'controlled delivery');});
    $error=$wpdb->last_error;$row=s2_row($id);$pending=EIPSI_Notification_Nudge_Queue_Service::get_pending_jobs(100);
    echo 'DIAGNOSTIC '.wp_json_encode(array('returned'=>$result,'sql_error'=>$error,'status'=>$row->status,'selected'=>in_array($id,array_map(function($j){return(int)$j->id;},$pending),true)))."\n";
    m0_assert($result===false && $error!=='' && $row->status==='processing','Unconfirmed completed reported success');
};
require __DIR__.'/s2/cases.php';
$cron_before=get_option('cron');
$failed=0;$fixture=false;
try {
    foreach(array('survey_studies'=>998203,'survey_participants'=>998207,'survey_waves'=>998221,'survey_assignments'=>998231) as $table=>$id){m0_assert(! $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}{$table} WHERE id=%d",$id)),'Fixture collision');}
    $fixture=true;
    m0_assert($wpdb->insert($wpdb->prefix.'survey_studies',array('id'=>998203,'study_code'=>'s2-998203','study_name'=>'S2 fixture','status'=>'active'))===1,'Study insert');
    m0_assert($wpdb->insert($wpdb->prefix.'survey_participants',array('id'=>998207,'survey_id'=>998203,'email'=>'s2@example.invalid','is_active'=>1,'consent_decision'=>'accepted','created_at'=>current_time('mysql')))===1,'Participant insert');
    m0_assert($wpdb->insert($wpdb->prefix.'survey_waves',array('id'=>998221,'study_id'=>998203,'wave_index'=>1,'name'=>'S2 T1','status'=>'active'))===1,'Wave insert');
    m0_assert($wpdb->insert($wpdb->prefix.'survey_assignments',array('id'=>998231,'participant_id'=>998207,'study_id'=>998203,'wave_id'=>998221,'status'=>'pending'))===1,'Assignment insert');
    foreach($tests as $name=>$test){try{s2_reset();$test();echo 'PASS '.$name."\n";}catch(Throwable $e){$failed++;echo 'FAIL '.$name.': '.$e->getMessage()."\n";}} }
catch(Throwable $e){$failed++;echo 'FAIL fixtures: '.$e->getMessage()."\n";}
finally {
    foreach($jobs as $id){EIPSI_Notification_Nudge_Queue_Service::release_processing($id);$wpdb->delete($wpdb->prefix.'survey_nudge_jobs',array('id'=>$id));}
    if ($fixture) {
        foreach(array('survey_email_log'=>'survey_id','survey_magic_links'=>'survey_id','survey_sessions'=>'survey_id','survey_participant_access_log'=>'study_id','survey_assignments'=>'study_id','survey_waves'=>'study_id','survey_participants'=>'survey_id','survey_studies'=>'id') as $table=>$column){$wpdb->delete($wpdb->prefix.$table,array($column=>998203));}
        EIPSI_Nudge_Cache::invalidate_assignment_cache(998231);
        foreach(array('eipsi_nudge_0_sent_','eipsi_wave_email_retries_') as $key){delete_transient($key.'998207_998221');}
    }
    remove_filter('query',$s2_selector_scope);
    update_option('cron',$cron_before);
}
echo count($tests).' tests, '.$failed." failures\n";ob_end_flush();exit($failed?1:0);
