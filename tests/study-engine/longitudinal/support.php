<?php
use EIPSI\StudyEngine\Longitudinal as L;
use EIPSI\StudyEngine\Persistence as P;
use EIPSI\StudyEngine\Runtime as R;
use EIPSI\StudyEngine\Contracts\Canonical;
use EIPSI\StudyEngine\Orchestration\ExecutionOwner;
function pf_clock(){return new L\Clock(static function(){return $GLOBALS['pf_now'];});}
function pf_db(){global $wpdb;static $connection,$db;if($connection!==$wpdb){$connection=$wpdb;$db=new P\Database($wpdb);}return $db;}
function pf_caps(){return new L\Capability(pf_db(),array('ACCESS_TEST'=>str_repeat('a',32)),'ACCESS_TEST',pf_clock());}
function pf_runtime($observer=null){return new L\Runtime(pf_db(),pe_vault(),pf_caps(),pf_clock(),$observer);}
function pf_owner($observer=null){return new ExecutionOwner(pf_db(),pe_vault(),$observer,new EIPSI\StudyEngine\Runtime\LocalResponseSink(),static function(){return $GLOBALS['pf_now'];});}
function pf_publish($fixture=null,$options=array(),$study=null,$public=null,$number=1){
 if($fixture===null)$d=pe_definition('simple',$study,$public,$number);
 else{$d=pd_definition($fixture);$d['study']=array('logical_id'=>$study??'STUDY_F_'.bin2hex(random_bytes(6)),'public_id'=>$public??'PUBLIC_F_'.bin2hex(random_bytes(6)));$d['version_number']=$number;$old=$d['id'];$d['id']='VERSION_'.$number;
  $json=Canonical::json($d);$json=str_replace('"'.$old.'"','"'.$d['id'].'"',$json);$d=json_decode($json,true);
  foreach($d['nodes'] as &$n)if($n['kind']==='QUESTION'){$n['content']['snapshot']=Canonical::json(array('label'=>$n['id'],'helper'=>'','format'=>'text','widget'=>'eipsi/campo-texto','step'=>null));$n['content']['sha256']=hash('sha256',$n['content']['snapshot']);}unset($n);
  $d['protocol_metadata']['content_adapter']='P1E_V1';$d['protocol_metadata']['storage_mode']='WORDPRESS_LOCAL';$d['protocol_metadata']['allow_back']=true;
 }
 $d=L\Configuration::apply($d,$options);if(!empty($GLOBALS['pf_native_transport']))$d=pe_native_transport_fixture($d);return pe_publish('simple',false,$d);
}
function pf_enroll(array $p,$email=null,$token=null,$id=null,$timezone=null){$entry=pf_runtime()->handle($p['public'],$token);$request=array('action'=>'enroll','command_id'=>$id??'ENROLL_'.bin2hex(random_bytes(6)),'csrf'=>$entry['csrf'],'email'=>$email??'p-'.bin2hex(random_bytes(6)).'@example.test');if($timezone!==null)$request['timezone']=$timezone;return pf_runtime()->handle($p['public'],$entry['token'],$request);}
function pf_intents($execution,$purpose=null){$all=(new P\NotificationIntentRepository(pf_db()))->list($execution);return $purpose===null?$all:array_values(array_filter($all,static function($r)use($purpose){return $r['purpose']===$purpose;}));}
function pf_participant($frame){return (new L\Identity(pf_db(),pf_clock()))->participant($frame['access']['participant_id']);}
function pf_mint(array $frame,$purpose='VERIFY_PARTICIPANT',$block=null){return pf_db()->transactional(function()use($frame,$purpose,$block){return pf_caps()->mint(pf_participant($frame),$frame['study'],(new P\StudyExecutionRepository(pf_db()))->find($frame['access']['execution_id']),$purpose,$block);});}
function pf_delivery($intent,$observer=null){global $wpdb;$job=$wpdb->get_row($wpdb->prepare('SELECT * FROM `'.$wpdb->prefix.'survey_nudge_jobs` WHERE id=%d',$intent['queue_job_id']));pe_assert($job!==null,'No job');$wpdb->query($wpdb->prepare('UPDATE `'.$wpdb->prefix.'survey_nudge_jobs` SET scheduled_at=%s,status=%s WHERE id=%d',current_time('mysql'),'pending',$job->id));pe_assert(EIPSI_Notification_Nudge_Queue_Service::mark_processing($job->id),'No claim');try{$r=(new L\Worker(pf_db(),pe_vault(),pf_caps(),pf_clock(),$observer))->execute($job);if($r['success'])pe_assert(EIPSI_Notification_Nudge_Queue_Service::mark_completed($job->id,$r['message']??null),'Completion failed');elseif(isset($r['defer_until']))pe_assert(EIPSI_Notification_Nudge_Queue_Service::postpone_processing($job->id,$r['defer_until']),'Deferral failed');else EIPSI_Notification_Nudge_Queue_Service::persist_retry_outcome($job->id,$r['error']);return $r;}finally{EIPSI_Notification_Nudge_Queue_Service::release_processing($job->id);}}
function pf_link($mail,$purpose=0){preg_match_all('/href="([^"]+)"/',$mail['message'],$matches);pe_assert(isset($matches[1][$purpose]),'No link');$url=html_entity_decode($matches[1][$purpose],ENT_QUOTES,'UTF-8');parse_str(parse_url($url,PHP_URL_QUERY),$query);pe_assert(isset($query['eipsi_cap']),'No capability');return $query['eipsi_cap'];}
function pf_dispatch($frame,$kind,$data=array(),$id=null){$view=pf_owner()->view($frame['access']['execution_id']);$r=pf_owner()->dispatch($frame['access']['execution_id'],array('id'=>$id??'F_CMD_'.bin2hex(random_bytes(6)),'kind'=>$kind,'expected_revision'=>$view['revision'],'data'=>$data));pe_assert(isset($r['revision']),Canonical::json($r));return $r;}
/** Follow the transparent cookie handoff by default; raw exchange remains available for assertions. */
function pf_http($public,$cookies=array(),$body=null,$cap=null,$headers=array(),$confirm=true){
 if(is_file(ABSPATH.'wp-content/sec1-test-clock'))file_put_contents(ABSPATH.'wp-content/sec1-test-clock',(string)strtotime($GLOBALS['pf_now']));
 if($cap===null)return pe_http($public,$cookies,$body,$headers);
 $r=wp_remote_get('http://127.0.0.1/?eipsi_study='.rawurlencode($public).'&eipsi_cap='.rawurlencode($cap),array('timeout'=>15,'redirection'=>0,'headers'=>array_merge(array('Host'=>'127.0.0.1:18080','Accept'=>'application/json'),$headers),'cookies'=>$cookies));pe_assert(!is_wp_error($r),'HTTP failed');
 $out=array('status'=>wp_remote_retrieve_response_code($r),'cookies'=>wp_remote_retrieve_cookies($r),'headers'=>wp_remote_retrieve_headers($r),'body'=>wp_remote_retrieve_body($r),'data'=>json_decode(wp_remote_retrieve_body($r),true));
 if(!$confirm||$out['status']!==303)return $out;
 $jar=array();foreach(array_merge($cookies,$out['cookies']) as $c)$jar[$c->name]=$c;$jar=array_values($jar);
 $landing=wp_remote_get('http://127.0.0.1/?eipsi_study='.rawurlencode($public).'&eipsi_confirm=1',array('timeout'=>15,'redirection'=>0,'headers'=>array('Host'=>'127.0.0.1:18080','Accept'=>'application/json'),'cookies'=>$jar));pe_assert(!is_wp_error($landing),'Confirmation HTTP');$out=array('status'=>wp_remote_retrieve_response_code($landing),'cookies'=>wp_remote_retrieve_cookies($landing),'headers'=>wp_remote_retrieve_headers($landing),'body'=>wp_remote_retrieve_body($landing),'data'=>json_decode(wp_remote_retrieve_body($landing),true));
 $out['cookies']=array_values(array_filter($out['cookies'],static function($c)use($public){return $c->name===L\Identity::cookieName($public)&&preg_match('/^[a-f0-9]{64}$/D',$c->value);}));return $out;
}

function pf_enrollment_race($public){$dir='/tmp/eipsi-p1f-race-'.bin2hex(random_bytes(6));mkdir($dir,0700);file_put_contents($dir.'/input.json',json_encode(array('public'=>$public,'token'=>L\Identity::token(),'clock'=>$GLOBALS['pf_now'])));$processes=array();$out=array();try{foreach(array(0,1) as $i){$pipes=array();$processes[$i]=proc_open(array(PHP_BINARY,__DIR__.'/enrollment-worker.php',$dir,(string)$i),array(0=>array('pipe','r'),1=>array('file',$dir.'/stdout'.$i,'w'),2=>array('file',$dir.'/stderr'.$i,'w')),$pipes);pe_assert(is_resource($processes[$i]),'Spawn failed');fclose($pipes[0]);}pd_wait($dir.'/ready0');pd_wait($dir.'/ready1');touch($dir.'/start0');pd_wait($dir.'/locked0');touch($dir.'/start1');pd_wait($dir.'/attempt1');usleep(100000);touch($dir.'/release0');foreach(array(0,1) as $i){pd_wait($dir.'/result'.$i.'.json');$out[]=json_decode(file_get_contents($dir.'/result'.$i.'.json'),true);pe_assert(proc_close($processes[$i])===0,'Worker failed');unset($processes[$i]);}return $out;}finally{touch($dir.'/release0');foreach($processes as $p){proc_terminate($p);proc_close($p);}foreach(glob($dir.'/*') as $f)unlink($f);rmdir($dir);}}
function pf_http_enroll($fixture=null,$options=array()){$oldNative=$GLOBALS['pf_native_transport']??false;$GLOBALS['pf_native_transport']=true;try{$p=pf_publish($fixture,$options);}finally{$GLOBALS['pf_native_transport']=$oldNative;}$e=pf_http($p['public']);pe_assert($e['status']===200,'HTTP entry');$r=pe_http_request($e,'enroll');$r['email']='http-'.bin2hex(random_bytes(5)).'@example.test';$h=pf_http($p['public'],$e['cookies'],$r);pe_assert($h['status']===200,substr($h['body'],0,400));$cookies=$h['cookies']?:$e['cookies'];return array($p,$h,$cookies,$r['email']);}
function pf_http_frame($p,$http,$cookies){$token=$cookies[0]->value;$s=(new L\Identity(pf_db(),new L\Clock()))->recover($p['public'],$token);pe_assert($s!==null,'No HTTP session');$v=(new P\StudyVersionRepository(pf_db()))->find($s['study_version_id']);return array('access'=>$s,'study'=>(new R\PublicStudyRepository(pf_db()))->find($p['public']),'token'=>$token,'definition'=>(new P\StudyVersionRepository(pf_db()))->compiled($v)->definition(),'view'=>pf_owner()->view($s['execution_id']),'csrf'=>L\Identity::csrf($p['public'],$token));}
function pf_http_cap(array $frame,$purpose='VERIFY_PARTICIPANT',$block=null){return pf_db()->transactional(function()use($frame,$purpose,$block){$p=(new L\Identity(pf_db(),new L\Clock()))->participant($frame['access']['participant_id']);return (new L\Capability(pf_db(),array('ACCESS_TEST'=>str_repeat('a',32)),'ACCESS_TEST'))->mint($p,$frame['study'],(new P\StudyExecutionRepository(pf_db()))->find($frame['access']['execution_id']),$purpose,$block);});}

/** Complete the Native participant, then read the public Study status. */
function pf_http_finish_native(array $p,array $http,array $cookies,array $domainValues): array {
 $priorKeyring=getenv('EIPSI_STUDY_ENGINE_KEYRING');putenv('EIPSI_STUDY_ENGINE_KEYRING='.json_encode(array('current'=>'HTTP_TEST','keys'=>array('HTTP_TEST'=>str_repeat('6b',32)))));try{
 $frame=pf_http_frame($p,$http,$cookies);$_COOKIE[L\Identity::cookieName($p['public'])]=$frame['token'];
 require_once EIPSI_FORMS_PLUGIN_DIR.'includes/study-engine/runtime/NativeBridge.php';$native=new EIPSI\AssessmentEditor\Runtime\Owner();$id=R\NativeBridge::offer($frame)['execution_id'];
 pf_native_fixture_command($native,$id,'open');
 // The engine fixture retains domain questions to exercise journal/export contracts.
 $block=$frame['view']['next_action']['payload']['assessment_execution_id'];$graph=(new P\StudyVersionRepository(pf_db()))->compiled((new P\StudyVersionRepository(pf_db()))->find($frame['access']['study_version_id']));$answers=(new EIPSI\StudyEngine\Content\ResponseAdapter())->decode($frame['definition'],$domainValues,$graph->descendants($frame['view']['snapshot']['instances'][$block]['definition_id']));pf_dispatch($frame,'SAVE_PAGE',array('block_execution_id'=>$block,'responses'=>$answers));
 $done=pf_native_fixture_command($native,$id,'submit');
 if(isset($done['email_confirmation_html']))return array('status'=>200,'data'=>array('state'=>'EMAIL_CONFIRMATION_REQUIRED','html'=>$done['email_confirmation_html']),'body'=>$done['email_confirmation_html'],'cookies'=>$cookies);
 return pf_http($p['public'],$cookies);
 }finally{putenv($priorKeyring===false?'EIPSI_STUDY_ENGINE_KEYRING':'EIPSI_STUDY_ENGINE_KEYRING='.$priorKeyring);}
}
function pf_native_fixture_command($owner,string $id,string $action,array $answers=array(),?array $request=null): array {
 $f=$owner->view($id);if($action==='submit'&&!$answers)foreach($f['definition']['elements'] as $element)if($element['response_key'])$answers[$element['response_key']]='Respuesta descartable de transporte';
 $metadata=$f['metadata']??null;if(!isset($metadata['contract']))$metadata=array('contract'=>'NATIVE_PROCESS_V1','assessment_id'=>$f['definition']['id'],'definition_ref'=>array(),'started_at'=>1000,'completed_at'=>null,'duration'=>0,'status'=>'active','recovery_used'=>false,'recovery_count'=>0,'pages'=>array(),'items'=>array(),'events'=>array(),'dropped_events'=>0);
 return $owner->command($id,$request??array('action'=>$action,'command_id'=>'NC_TRANSPORT_'.bin2hex(random_bytes(8)),'revision'=>$f['revision'],'version_id'=>$f['version_id'],'csrf'=>$f['csrf'],'answers'=>$answers,'touched'=>array(),'metadata'=>$metadata));
}
