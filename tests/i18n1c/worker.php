<?php
/** Included inside the existing disposable longitudinal fixture: real outbox/worker, captured SMTP. */
foreach(array('en','es') as $contentLanguage)pf_case('I18N-1C real notification worker pins '.$contentLanguage.' despite opposite admin and site locale',function()use($contentLanguage){
 $user=get_current_user_id();$old=get_option('eipsi_forms_language',null);$opposite=$contentLanguage==='en'?'es':'en';$locale=static function()use($opposite){return $opposite==='es'?'es_ES':'en_US';};add_filter('locale',$locale);
 try{
  update_option('eipsi_forms_language',$contentLanguage);
  $d=pe_definition();$d['protocol_metadata']['content_language']=$contentLanguage;$d['protocol_metadata']['public_messages']=\EIPSI\I18n\ContentDefaults::get('public_messages',$contentLanguage);$screen=\EIPSI\StudyEngine\Longitudinal\InitialScreen::defaults($contentLanguage);$screen['authentication']['verification']='IMMEDIATE';$d=\EIPSI\StudyEngine\Longitudinal\Configuration::apply($d,array('initial_screen'=>$screen));$p=pe_publish('simple',false,$d);
  $before=(new \EIPSI\StudyEngine\Persistence\StudyVersionRepository(pf_db()))->find($p['version']);
  update_option('eipsi_forms_language',$opposite);$f=pf_enroll($p);$intents=pf_intents($f['access']['execution_id'],'VERIFY_PARTICIPANT');pe_assert(count($intents)>0,'Missing real verification intent');pe_assert(pf_delivery($intents[0])['success'],'Worker delivery failed');$mail=end($GLOBALS['m0_mail']);
  $expected=\EIPSI\StudyEngine\Longitudinal\EmailTemplate::defaults($contentLanguage);pe_assert(in_array($mail['subject'],array($expected['VERIFY_PARTICIPANT']['subject'],$expected['ASSESSMENT_AVAILABLE']['subject']),true),'Worker selected admin language');pe_assert(strpos($mail['message'],$contentLanguage==='en'?'Leave study':'Abandonar estudio')!==false,'Worker body context changed');
  $d['protocol_metadata']['content_language']=$opposite;
  $after=(new \EIPSI\StudyEngine\Persistence\StudyVersionRepository(pf_db()))->find($p['version']);pe_assert($after['protocol_json']===$before['protocol_json']&&$after['protocol_hash']===$before['protocol_hash'],'Study snapshot changed');
 }finally{remove_filter('locale',$locale);if($old===null)delete_option('eipsi_forms_language');else update_option('eipsi_forms_language',$old,false);}
});
