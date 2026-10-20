<?php
use EIPSI\ExternalApi as A;
use EIPSI\StudyEngine\Management as M;
use EIPSI\Dataset\CanonicalStudyDataset as CD;
use EIPSI\Dataset\Source as DS;
use EIPSI\Dataset\Transport as DT;
function data1_job($k,$p,$format='json',$annexes=array(),$filters=array()){
 $api=new A\DatasetSnapshots();$job=$api->create($k['principal'],$p['public'],array('contract'=>CD::CONTRACT,'format'=>$format,'filters'=>$filters,'annexes'=>$annexes));
 for($i=0;$i<100&&$job['status']==='PREPARING';$i++){$api->work($job['export_id']);$job=$api->status($k['principal'],$job['export_id']);}d2_assert($job['status']==='READY','V2 export failed: '.json_encode($job));return $job;
}
function data1_bytes($k,$id){$d=(new A\DatasetSnapshots())->download($k['principal'],$id);try{return stream_get_contents($d->stream);}finally{$d->close();}}
$cases['Manual canonical export uses same observations and dictionary as API V2']=function(){
 $p=$GLOBALS['p'];$q=new M\Query($p['definition']['study']['logical_id']);$x=new M\Exports($q);$job=$x->create(array(),'canonical','DATA1_MANUAL_'.bin2hex(random_bytes(8)));while(in_array($job['state'],array('PENDING','RUNNING'),true))$job=$x->step($job['id']);d2_assert($job['state']==='READY','Manual V2 not ready: '.json_encode($job));$manual=json_decode($x->download($job['id'],'json')['content'],true);$key=d2_key();$live=d2_read($key,array('contract'=>CD::CONTRACT));d2_assert(EIPSI\StudyEngine\Contracts\Canonical::json($manual['data'])===EIPSI\StudyEngine\Contracts\Canonical::json($live['items']),'Manual/live V2 difference');$snapshot=data1_job($key,$p);$saved=json_decode(data1_bytes($key,$snapshot['export_id']),true);d2_assert(EIPSI\StudyEngine\Contracts\Canonical::json($saved['data'])===EIPSI\StudyEngine\Contracts\Canonical::json($manual['data'])&&EIPSI\StudyEngine\Contracts\Canonical::json($saved['dictionary'])===EIPSI\StudyEngine\Contracts\Canonical::json($manual['dictionary']),'Snapshot/manual difference');
};
$cases['Native multiple questions pivot to one row with historical typed dictionary']=function(){
 $n=d2_native();$p=$n['p'];$k=d2_key(array($p['definition']['study']['logical_id']));$live=d2_read($k,array('contract'=>CD::CONTRACT),$p);d2_assert(count($live['items'])===1&&count($live['columns'])>count(CD::CONTEXT)+2,'Native pivot rows='.count($live['items']).' columns='.count($live['columns']));$job=data1_job($k,$p);$saved=json_decode(data1_bytes($k,$job['export_id']),true);d2_assert(EIPSI\StudyEngine\Contracts\Canonical::json($saved['data'])===EIPSI\StudyEngine\Contracts\Canonical::json($live['items']),'Native snapshot/live difference');d2_assert(count($saved['response_status'])>=3);
};
$cases['XLSX snapshot is a valid private workbook with data dictionary manifest']=function(){
 $p=$GLOBALS['p'];$k=d2_key();$job=data1_job($k,$p,'xlsx');$bytes=data1_bytes($k,$job['export_id']);d2_assert(substr($bytes,0,2)==='PK');$file=tempnam(sys_get_temp_dir(),'data1-xlsx-');try{file_put_contents($file,$bytes);$zip=new ZipArchive();d2_assert($zip->open($file)===true);$book=$zip->getFromName('xl/workbook.xml');d2_assert(strpos($book,'name="data"')!==false&&strpos($book,'name="dictionary"')!==false&&strpos($book,'name="manifest"')!==false);d2_assert(simplexml_load_string($zip->getFromName('xl/worksheets/sheet1.xml'))!==false);$zip->close();}finally{unlink($file);}
};
$cases['CSV manual and snapshot have identical columns and cells']=function(){
 $p=$GLOBALS['p'];$k=d2_key();$live=d2_read($k,array('contract'=>CD::CONTRACT,'format'=>'csv'));$job=data1_job($k,$p,'csv');d2_assert(data1_bytes($k,$job['export_id'])===$live['csv']);
};
$cases['Protected identities cannot be requested using an external key']=function(){d2_error(fn()=>(new A\DatasetSnapshots())->create(d2_key()['principal'],$GLOBALS['p']['public'],array('contract'=>CD::CONTRACT,'format'=>'json','annexes'=>array('identities'))),400,'eipsi_external_invalid_filters');};
$cases['V2 response datetime range uses confirmed completion']=function(){
 $key=d2_key();$r=d2_read($key,array('contract'=>CD::CONTRACT,'response_from'=>'2099-01-01 00:00:00'));d2_assert($r['items']===array());
};
$cases['V2 live cursors bind filters and cannot be accepted as V1 cursors']=function(){
 $p=d2_publish();d2_finish($p,'one');d2_finish($p,'two');$key=d2_key(array($p['definition']['study']['logical_id']));$r=d2_read($key,array('contract'=>CD::CONTRACT,'limit'=>'1'),$p);d2_assert($r['next_cursor']!==null);d2_error(fn()=>d2_read($key,array('cursor'=>$r['next_cursor']),$p),400,'eipsi_external_invalid_cursor');d2_error(fn()=>d2_read($key,array('contract'=>CD::CONTRACT,'cursor'=>$r['next_cursor'],'include_retained'=>'false'),$p),400,'eipsi_external_invalid_cursor');
};
$cases['Empty V2 snapshot remains a valid dataset with contextual columns']=function(){
 $p=d2_publish();$k=d2_key(array($p['definition']['study']['logical_id']));$job=data1_job($k,$p);$data=json_decode(data1_bytes($k,$job['export_id']),true);d2_assert($data['data']===array()&&$data['manifest']['rows']===0&&$data['manifest']['columns']===CD::CONTEXT);
};
$cases['Global policy enforced by Native ingestion and preserves historical disabled fields']=function(){
 $class=EIPSI\Dataset\EffectiveMetadataPolicy::class;$before=get_option($class::OPTION,null);
 try{
  $n=d2_native();$d=$n['definition'];$d['flow']['metadata_profile']='detailed';$d['elements'][]=array('id'=>'EL_DATA1_CONSENT','type'=>'consent','response_key'=>'R_DATA1_CONSENT','properties'=>array('variable_name'=>'consent'),'children'=>array(),'options'=>array());
  $meta=array('contract'=>'NATIVE_PROCESS_V1','assessment_id'=>$d['id'],'status'=>'active','started_at'=>1000,'pages'=>array(),'items'=>array(),'events'=>array(),'context'=>array('browser'=>'Firefox'));
  foreach($d['elements']as$element)if($element['type']==='consent')$meta['items'][$element['response_key']]=array('item_id'=>$element['id'],'response_key'=>$element['response_key'],'variable_name'=>$element['properties']['variable_name'],'final_state'=>true,'trajectory'=>array());$on=$class::defaults();$class::save($on);$old=EIPSI\AssessmentEditor\Runtime\Metadata::validate($meta,$d,'VERSION_TEST');d2_assert($old['context']['browser']==='Firefox');
  $off=$on;$off['client_browser']=false;$class::save($off);$filtered=EIPSI\AssessmentEditor\Runtime\Metadata::validate($meta,$d,'VERSION_TEST');d2_assert(!isset($filtered['context']['browser']),'Forbidden browser persisted');d2_assert(!isset($class::legacyMetadata(array('browser'=>'Firefox','fingerprint'=>'private','user_agent'=>'private'))['browser']));
  $frozen=$class::preserve($filtered,$old,'detailed');d2_assert($frozen['context']['browser']==='Firefox','Historical browser deleted');
  $meta['context']['browser']='Changed';$next=EIPSI\AssessmentEditor\Runtime\Metadata::validate($meta,$d,'VERSION_TEST');d2_assert(!isset($next['context']['browser'])&&$class::preserve($next,$old,'detailed')['context']['browser']==='Firefox');
 }finally{if($before===null)delete_option($class::OPTION);else update_option($class::OPTION,$before,false);}
};
$cases['Privacy UI is global has one valid form and matches authoritative defaults']=function(){
 require_once EIPSI_FORMS_PLUGIN_DIR.'admin/privacy-dashboard.php';$class=EIPSI\Dataset\EffectiveMetadataPolicy::class;ob_start();render_privacy_dashboard();$html=ob_get_clean();
 d2_assert(substr_count($html,'<form ')===1&&substr_count($html,'</form>')===1&&strpos($html,'form_id')===false&&strpos($html,'export_')===false&&strpos($html,'7 años')===false);
 foreach($class::global()as$key=>$value){$pattern='/name="metadata\['.preg_quote($key,'/').'\]"[^>]*>/';preg_match($pattern,$html,$match);d2_assert(isset($match[0])&&((strpos($match[0],'checked=')!==false)===$value),'UI/default mismatch '.$key);}
};
$cases['Binary storage keeps percent bytes intact across private database chunks']=function(){
 $s=new A\Store();$bytes="PK\x00%foo%_%s\xff";$sql=$s->prepare('SELECT %s AS payload',$bytes);$row=$s->row($sql);d2_assert($row['payload']===$bytes,'Raw SQL percent escape corruption');
};
$cases['Explicit Native partials keep their saved moment and are absent by default']=function(){
 $n=d2_native(false);$n['clock']->now+=86400*31;$n['owner']->view($n['id']);$k=d2_key(array($n['p']['definition']['study']['logical_id']));d2_assert(d2_read($k,array('contract'=>CD::CONTRACT),$n['p'])['items']===array());$rows=d2_read($k,array('contract'=>CD::CONTRACT,'include_partials'=>'true'),$n['p'])['items'];d2_assert(count($rows)===1&&$rows[0]['response_kind']==='partial'&&preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D',$rows[0]['response_at']));$job=data1_job($k,$n['p'],'json',array(),array('include_partials'=>true));$data=json_decode(data1_bytes($k,$job['export_id']),true);d2_assert(EIPSI\StudyEngine\Contracts\Canonical::json($data['data'])===EIPSI\StudyEngine\Contracts\Canonical::json($rows));
};
$cases['V2 XLSX checksum Range and dictionary agree with the private representation']=function(){
 $p=$GLOBALS['p'];$k=d2_key();$job=data1_job($k,$p,'xlsx');$api=new A\DatasetSnapshots();$bytes=data1_bytes($k,$job['export_id']);$manifest=$api->manifest($k['principal'],$job['export_id']);d2_assert(hash('sha256',$bytes)===$manifest['sha256']&&$manifest['study_code']===$p['public']&&$manifest['study_id']===$p['definition']['study']['logical_id']);$part=$api->download($k['principal'],$job['export_id'],'bytes=0-19');try{d2_assert(fread($part->stream,20)===substr($bytes,0,20));}finally{$part->close();}$dict=$api->dictionary($k['principal'],$job['export_id']);d2_assert($dict['contract']==='EIPSI_EXTERNAL_DICTIONARY_V2'&&count($dict['items'])===$manifest['variables']);
};
$cases['DO_NOT_USE excludes V2 live manual annexes and revokes existing workbook']=function(){
 $p=d2_publish(true);$f=d2_finish($p);$k=d2_key(array($p['definition']['study']['logical_id']));$job=data1_job($k,$p,'xlsx',array('data','dictionary','audit','randomization','process_metadata'));d2_withdraw($f['frame'],'DO_NOT_USE');$api=new A\DatasetSnapshots();d2_assert($api->status($k['principal'],$job['export_id'])['status']==='REVOKED');d2_error(fn()=>data1_bytes($k,$job['export_id']),410,'eipsi_external_snapshot_invalidated');d2_assert(d2_read($k,array('contract'=>CD::CONTRACT),$p)['items']===array());
 $q=new M\Query($p['definition']['study']['logical_id']);$x=new M\Exports($q);$j=$x->create(array('annexes'=>array('data','dictionary','audit','randomization','process_metadata')),'canonical','DATA1_WITHDRAW_'.bin2hex(random_bytes(8)));while(in_array($j['state'],array('PENDING','RUNNING'),true))$j=$x->step($j['id']);d2_assert($j['state']==='READY');$out=json_decode($x->download($j['id'],'json')['content'],true);foreach(array('data','dictionary','audit','randomization','process_metadata')as$key)d2_assert($out[$key]===array(),'Withdrawn data leaked into '.$key);
};
$cases['V2 retained observations follow the same live snapshot and manual filter']=function(){
 $p=d2_publish(true);$f=d2_finish($p);d2_withdraw($f['frame'],'RETAIN_FOR_ANALYSIS');$k=d2_key(array($p['definition']['study']['logical_id']));d2_assert(count(d2_read($k,array('contract'=>CD::CONTRACT),$p)['items'])===1);d2_assert(d2_read($k,array('contract'=>CD::CONTRACT,'include_retained'=>'false'),$p)['items']===array());$job=data1_job($k,$p,'json',array(),array('include_retained'=>false));d2_assert(json_decode(data1_bytes($k,$job['export_id']),true)['data']===array());
};
$cases['Six added characteristics are captured with trusted IP and exported through process metadata']=function(){
 $class=EIPSI\Dataset\EffectiveMetadataPolicy::class;$before=get_option($class::OPTION,null);$server=$_SERVER;
 try{
  $class::save($class::defaults());$_SERVER['REMOTE_ADDR']='2001:db8::123';$_SERVER['HTTP_X_FORWARDED_FOR']='198.51.100.9';
  $client=array('ip_address'=>'198.51.100.99','webgl_renderer'=>'Vendor | GPU','screen_depth'=>24,'pixel_ratio'=>1.5,'cpu_cores'=>8,'cookies_enabled'=>false);
  $n=d2_native(true,false,false,'detailed',$client);$attempt=$n['owner']->store->find('executions',$n['id']);$saved=json_decode($attempt['metadata_json'],true)['context'];
  d2_assert($saved['ip_address']==='2001:db8::123'&&$saved['webgl_renderer']==='Vendor | GPU'&&$saved['screen_depth']===24&&$saved['pixel_ratio']===1.5&&$saved['cpu_cores']===8&&$saved['cookies_enabled']===false,'Client types or trusted peer IP lost');
  $key=d2_key(array($n['p']['definition']['study']['logical_id']));$job=data1_job($key,$n['p'],'json',array('data','dictionary','process_metadata'));$bundle=json_decode(data1_bytes($key,$job['export_id']),true);$context=array();foreach($bundle['process_metadata']as$row)if($row['level']==='client')$context[$row['field']]=$row['value'];foreach($saved as$field=>$value)d2_assert(($context[$field]??null)===$value,'Missing exported client field '.$field);
  $off=$class::defaults();foreach(array_keys($client)as$field)$off['client_'.$field]=false;$class::save($off);
  $meta=array('contract'=>'NATIVE_PROCESS_V1','assessment_id'=>$n['definition']['id'],'status'=>'active','pages'=>array(),'items'=>array(),'events'=>array(),'context'=>$client);$filtered=EIPSI\AssessmentEditor\Runtime\Metadata::validate($meta,$n['definition'],'V_TEST');foreach(array_keys($client)as$field)d2_assert(!array_key_exists($field,$filtered['context']??array()),'Disabled metadata persisted '.$field);
  $historical=json_decode($n['owner']->store->find('executions',$n['id'])['metadata_json'],true)['context'];d2_assert($historical===$saved,'Policy update erased history');
  $n['definition']['flow']['metadata_profile']='basic';unset($meta['context']);$basic=EIPSI\AssessmentEditor\Runtime\Metadata::validate($meta,$n['definition'],'V_TEST');d2_assert(!isset($basic['context']));
 }finally{$_SERVER=$server;if($before===null)delete_option($class::OPTION);else update_option($class::OPTION,$before,false);}
};
$cases['Added client metadata rejects malformed values and keeps cookie secrets redacted']=function(){
 $n=d2_native();$d=$n['definition'];$d['flow']['metadata_profile']='standard';
 $meta=array('contract'=>'NATIVE_PROCESS_V1','assessment_id'=>$d['id'],'status'=>'active','pages'=>array(),'items'=>array(),'events'=>array());
 foreach(array('webgl_renderer'=>array('unexpected'),'screen_depth'=>24.5,'pixel_ratio'=>-1,'cpu_cores'=>'8','cookies_enabled'=>'false')as$field=>$value){$invalid=$meta;$invalid['context']=array($field=>$value);$denied=false;try{EIPSI\AssessmentEditor\Runtime\Metadata::validate($invalid,$d,'V_TEST');}catch(InvalidArgumentException $e){$denied=true;}d2_assert($denied,'Malformed field accepted '.$field);}
 $safe=EIPSI\StudyEngine\Management\ScientificReadQuery::redact(array('cookies_enabled'=>false,'cookie'=>'SECRET','session_cookie'=>'SECRET'));d2_assert($safe===array('cookies_enabled'=>false));$unsafe=EIPSI\StudyEngine\Management\ScientificReadQuery::redact(array('cookies_enabled'=>'SECRET'));d2_assert($unsafe===array());
};
$cases['Management accepts browser annex download requests and rejects invalid or misplaced annex fields']=function(){
 $study=$GLOBALS['p']['definition']['study']['logical_id'];$job=M\Admin::handle(array('operation'=>'manage_export','study_id'=>$study,'kind'=>'canonical','request_id'=>'DATA1_ADMIN_'.bin2hex(random_bytes(8)),'filters'=>array('annexes'=>array('data','dictionary','process_metadata'))));while(in_array($job['state'],array('PENDING','RUNNING'),true))$job=M\Admin::handle(array('operation'=>'manage_export_step','study_id'=>$study,'entity'=>$job['id']));d2_assert($job['state']==='READY');
 $request=array('operation'=>'manage_export_download','study_id'=>$study,'entity'=>$job['id'],'annex'=>'data');foreach(array('csv','json','xlsx')as$format){$file=M\Admin::handle($request+array('format'=>$format));d2_assert(is_string($file['content'])&&strlen($file['content'])>0);if($format==='xlsx')d2_assert(substr(base64_decode($file['content'],true),0,2)==='PK');}
 $dictionary=M\Admin::handle(array_replace($request,array('annex'=>'dictionary','format'=>'csv')));d2_assert(strpos($dictionary['content'],'variable_name')!==false);
 foreach(array(array_replace($request,array('annex'=>array('data'))),array_replace($request,array('annex'=>'unknown')),array('operation'=>'manage','study_id'=>$study,'annex'=>'data'),$request+array('unexpected'=>true))as$bad){$denied=false;try{M\Admin::handle($bad);}catch(DomainException $e){d2_assert($e->getMessage()==='MANAGEMENT_REQUEST_INVALID');$denied=true;}d2_assert($denied,'Invalid management request accepted');}
};
