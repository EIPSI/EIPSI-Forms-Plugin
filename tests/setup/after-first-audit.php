<?php
/** Real worker/link roundtrip after a direct initial take; all email is intercepted by M0. */
function er_audit_after_first($db,string $public,string $token,string $execution): void {
    global $wpdb;
    $seed=EIPSI\StudyEngine\Configuration\KeyringResolver::binary('seed');
    $vault=new EIPSI\StudyEngine\Persistence\SeedVault($seed['keys'],$seed['current']);
    $owner=new EIPSI\StudyEngine\Orchestration\ExecutionOwner($db,$vault);$view=$owner->view($execution);$now=$view['snapshot']['clock'];
    $clock=new EIPSI\StudyEngine\Longitudinal\Clock(static function()use($now){return $now;});$caps=EIPSI\StudyEngine\Longitudinal\Capability::configured($db,$clock);
    $runtime=new EIPSI\StudyEngine\Longitudinal\Runtime($db,$vault,$caps,$clock);
    $review=$runtime->handle($public,$token);su_assert(isset($review['email_confirmation']),'Missing first-take email review');
    $result=$runtime->handle($public,$token,array('action'=>'confirm_email','command_id'=>'AUDIT_AFTER_FIRST_REVIEW','csrf'=>$review['csrf'],'expected_revision'=>$review['view']['revision'],'email'=>'reviewed@example.test','confirm_email_change'=>'1'));
    su_assert(!empty($result['verification_required']),'Review incorrectly validated email or opened next assessment');
    $identity=new EIPSI\StudyEngine\Longitudinal\Identity($db,$clock);$participant=$identity->byEmail($result['study']['study_id'],'reviewed@example.test');su_assert($participant&&$participant['verification_state']==='UNVERIFIED','Review is not separate from verification');
    $target=null;foreach($view['snapshot']['instances'] as $i)if($i['definition_id']==='E_FUTURE')$target=$i['id'];
    $intents=(new EIPSI\StudyEngine\Persistence\NotificationIntentRepository($db))->list($execution);$intent=null;foreach($intents as $candidate)if($candidate['block_execution_id']===$target&&$candidate['purpose']==='VERIFY_PARTICIPANT')$intent=$candidate;su_assert($intent!==null,'Required future verification was not queued with notices disabled');
    $job=$wpdb->get_row($wpdb->prepare('SELECT * FROM `'.$wpdb->prefix.'survey_nudge_jobs` WHERE id=%d',$intent['queue_job_id']));$wpdb->query($wpdb->prepare('UPDATE `'.$wpdb->prefix.'survey_nudge_jobs` SET scheduled_at=%s,status=%s WHERE id=%d',current_time('mysql'),'pending',$job->id));su_assert(EIPSI_Notification_Nudge_Queue_Service::mark_processing($job->id),'No verification job claim');
    $before=count($GLOBALS['m0_mail']??array());$delivered=(new EIPSI\StudyEngine\Longitudinal\Worker($db,$vault,$caps,$clock))->execute($job);su_assert($delivered['success']&&count($GLOBALS['m0_mail'])===$before+1,'Required validation email was not delivered');
    $mail=end($GLOBALS['m0_mail']);su_assert(strpos(json_encode($mail['to']),'reviewed@example.test')!==false,'Verification sent to the uncorrected address');
    $dom=new DOMDocument();@$dom->loadHTML($mail['message']);$cap=null;foreach($dom->getElementsByTagName('a') as $link){parse_str(parse_url(html_entity_decode($link->getAttribute('href')),PHP_URL_QUERY)??'',$query);if(isset($query['eipsi_cap'])){$cap=$query['eipsi_cap'];break;}}su_assert($cap!==null,'Verification email has no access link');
    $redeemed=$runtime->redeem($public,$cap,$token);$opened=$runtime->handle($public,$redeemed['token']);su_assert($opened['view']['next_action']['kind']==='OPEN_ASSESSMENT','Validated link did not open next opportunity');su_assert($identity->participant($participant['id'])['verification_state']==='VERIFIED','Link did not verify the participant');
}
