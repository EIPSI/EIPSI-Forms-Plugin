<?php
require __DIR__.'/../includes/study-engine/contracts/Canonical.php';require __DIR__.'/../includes/dataset/CanonicalStudyDataset.php';require __DIR__.'/../includes/dataset/Transport.php';
use EIPSI\Dataset\CanonicalStudyDataset as D;use EIPSI\Dataset\Conflict;
$tests=array();$failed=0;
function d1_assert($v){if(!$v)throw new RuntimeException('Assertion failed');}
function d1_error($fn,$code){try{$fn();}catch(Conflict$e){d1_assert($e->getMessage()===$code);return;}throw new RuntimeException('Expected '.$code);}
function d1_row($name='ansiedad',$value=4,$attempt='NX_A',$at=1000,$type='number') {return array('study_id'=>'STUDY_A','cycle'=>1,'study_version_id'=>'VERSION_A','participation_id'=>'SUBJECT_A','opportunity_id'=>'OP_A','occurrence'=>array(),'assessment_id'=>'ASSESSMENT_A','native_version_id'=>null,'attempt_id'=>$attempt,'attempt_state'=>'COMPLETED','saved_at'=>$at,'item_id'=>'ITEM_'.$name,'response_key'=>'R_'.$name,'variable_name'=>$name,'label'=>$name,'type'=>$type,'response_status'=>$value===null?'NO_VALUE_RECORDED':'VALUE_RECORDED','value'=>$value,'selected_option_id'=>null,'definition_hash'=>str_repeat('a',64),'data_policy'=>'USE_ALLOWED');}
function d1_dict($rows){return array_map(static function($r){return array_intersect_key($r,array_flip(array('study_version_id','assessment_id','native_version_id','definition_hash','item_id','response_key','variable_name','label','type')))+array('options'=>array(),'nullable'=>true,'validation'=>array());},$rows);}
function d1_project($rows,$f=array(),$attempts=array()){return D::project($rows,d1_dict($rows),$attempts,$f,'PUBLIC_A');}
$tests['Three questions produce one observation and three variables']=function(){$r=d1_project(array(d1_row('a'),d1_row('b'),d1_row('c')));d1_assert(count($r['data'])===1&&count($r['columns'])===count(D::CONTEXT)+3);};
$tests['Same variable across time uses one column']=function(){$r=d1_project(array(d1_row('ansiedad',4),d1_row('ansiedad',7,'NX_B',1001)));d1_assert(count($r['data'])===2&&count($r['columns'])===count(D::CONTEXT)+1);};
$tests['Different attempts at same second block instead of merging']=function(){d1_error(fn()=>d1_project(array(d1_row('ansiedad',4),d1_row('ansiedad',7,'NX_B'))),'RESPONSE_TIME_COLLISION');};
$tests['Different attempts same second same value also conflict']=function(){d1_error(fn()=>d1_project(array(d1_row(),d1_row('ansiedad',4,'NX_B'))),'RESPONSE_TIME_COLLISION');};
$tests['Exact same source duplicate is deduplicated']=function(){$r=d1_project(array(d1_row(),d1_row()));d1_assert(count($r['data'])===1&&count($r['bindings'])===1);};
$tests['Same source different value is a real conflict']=function(){d1_error(fn()=>d1_project(array(d1_row(),d1_row('ansiedad',7))),'VARIABLE_VALUE_COLLISION');};
$tests['Same value different provenance within observation conflicts']=function(){$a=d1_row();$b=$a;$b['item_id']='OTHER_ITEM';d1_error(fn()=>d1_project(array($a,$b)),'VARIABLE_VALUE_COLLISION');};
$tests['Historical incompatible types are not coerced']=function(){d1_error(fn()=>d1_project(array(d1_row(),d1_row('ansiedad','cuatro','NX_B',1001,'text'))),'VARIABLE_TYPE_CONFLICT');};
$tests['Historical labels remain separate dictionary variants with warning']=function(){$a=d1_row();$b=d1_row('ansiedad',5,'NX_B',1001);$b['label']='Otra etiqueta';$b['definition_hash']=str_repeat('b',64);$r=d1_project(array($a,$b));d1_assert(count($r['dictionary'])===2&&$r['warnings'][0]['code']==='HISTORICAL_LABEL_CHANGED');};
$tests['Partials excluded by default']=function(){$a=d1_row();$a['attempt_state']='PARTIAL_SAVED';d1_assert(d1_project(array($a))['data']===array());};
$tests['Partials retain independent saved moments']=function(){$a=d1_row('ansiedad',2,'NP_A',999);$a['attempt_state']='PARTIAL_SAVED';$r=d1_project(array($a,d1_row()),array('include_partials'=>true));d1_assert(count($r['data'])===2&&$r['data'][0]['response_kind']==='partial');};
$tests['Confirmed completed_at overrides source display time for final']=function(){$r=d1_project(array(d1_row()),array(),array('NX_A'=>array('completed_at'=>1005,'id'=>'NX_A','assessment_id'=>'ASSESSMENT_A','version_id'=>'NATIVE_A','definition_hash'=>'hash','metadata'=>array())));d1_assert($r['data'][0]['response_at']==='1970-01-01 00:16:45');};
$tests['Full UTC datetime never integer display']=function(){d1_assert(D::datetime(1000)==='1970-01-01 00:16:40');d1_assert(D::datetime('2026-10-19T13:00:00-03:00')==='2026-10-19 16:00:00');};
$tests['Missing authoritative time blocks']=function(){d1_error(fn()=>d1_project(array(d1_row('x',4,'NX_A',null))),'MISSING_RESPONSE_AT');};
$tests['Response range filters response instead of enrollment']=function(){$r=d1_project(array(d1_row(),d1_row('ansiedad',7,'NX_B',1001)),array('response_from'=>'1970-01-01 00:16:41'));d1_assert(count($r['data'])===1&&$r['data'][0]['ansiedad']===7);};
$tests['DO_NOT_USE never enters data or annexes']=function(){$a=d1_row();$a['data_policy']='DO_NOT_USE';$r=d1_project(array($a));d1_assert(!$r['data']&&!$r['dictionary']&&!$r['bindings']);};
$tests['Retained is configurable consistently']=function(){$a=d1_row();$a['data_policy']='RETAIN_FOR_ANALYSIS';d1_assert(count(d1_project(array($a))['data'])===1);d1_assert(!d1_project(array($a),array('include_retained'=>false))['data']);};
$tests['Missing dictionary blocks']=function(){d1_error(fn()=>D::project(array(d1_row()),array()),'MISSING_DICTIONARY');};
$tests['Reserved context variable cannot overwrite subject identity']=function(){d1_error(fn()=>d1_project(array(d1_row('participation_id'))),'VARIABLE_NAME_RESERVED');};
$tests['Multiple choice remains an array']=function(){$r=d1_project(array(d1_row('multi',array('A','B'),'NX_A',1000,'multiple_choice')));d1_assert($r['data'][0]['multi']===array('A','B'));};
$tests['Absence statuses survive separately from null']=function(){$a=d1_row('x',null);$a['response_status']='NOT_SELECTED_BY_RANDOMIZATION';$r=d1_project(array($a));d1_assert($r['data'][0]['x']===null&&$r['response_status'][0]['status']==='NOT_SELECTED_BY_RANDOMIZATION');};
$tests['Observation identifier deterministic across same-source exports']=function(){d1_assert(d1_project(array(d1_row()))['data'][0]['observation_id']===d1_project(array(d1_row()))['data'][0]['observation_id']);};
$tests['CSV preserves typed number strings arrays Unicode multiline and guards formulas']=function(){
 $r=d1_project(array(d1_row('x',"=SUM(1,2) á\nline",'NX_A',1000,'text'),d1_row('n',4),d1_row('m',array('A','B'),'NX_A',1000,'multiple_choice')));$manifest=D::manifest($r,array(),array('data'),'fixture');$csv=EIPSI\Dataset\Transport::file($r,$manifest,array('data'),'csv')['content'];d1_assert(strpos($csv,"'=SUM")!==false&&strpos($csv,'á')!==false&&strpos($csv,'[""A"",""B""]')!==false);
};
$tests['JSON preserves null numbers arrays and original text without formula rewriting']=function(){
 $r=d1_project(array(d1_row('x','=1+1','NX_A',1000,'text'),d1_row('n',4),d1_row('z',null)));$file=EIPSI\Dataset\Transport::file($r,D::manifest($r,array(),array('data'),'fixture'),array('data'),'json');$d=json_decode($file['content'],true);d1_assert($d['data'][0]['n']===4&&$d['data'][0]['z']===null&&$d['data'][0]['x']==='=1+1');
};
$tests['XLSX is valid typed XML and participant strings never become formulas']=function(){
 $s=EIPSI\Dataset\Transport::workbook(array('data'=>array('columns'=>array('number','text','array','bool','empty'),'rows'=>array(array('number'=>4.5,'text'=>"=SUM(1,2) á\nline",'array'=>array('A','B'),'bool'=>true,'empty'=>null)))));$file=tempnam(sys_get_temp_dir(),'data1-unit-');try{file_put_contents($file,stream_get_contents($s));$zip=new ZipArchive();d1_assert($zip->open($file)===true);$xml=$zip->getFromName('xl/worksheets/sheet1.xml');d1_assert(simplexml_load_string($xml)!==false&&strpos($xml,'<f>')===false&&strpos($xml,'t="inlineStr"')!==false&&strpos($xml,'<v>4.5</v>')!==false&&strpos($xml,'=SUM(1,2)')!==false);$zip->close();}finally{fclose($s);unlink($file);}
};
$tests['Incremental XLSX writer accepts generator without row materialization']=function(){
 $before=memory_get_peak_usage(true);$rows=(static function(){for($i=0;$i<5000;$i++)yield array('n'=>$i,'text'=>'á '.str_repeat('x',200));})();$s=EIPSI\Dataset\Transport::workbook(array('data'=>array('columns'=>array('n','text'),'rows'=>$rows)));d1_assert(fstat($s)['size']>1000&&memory_get_peak_usage(true)-$before<16777216);fclose($s);
};
$tests['Invalid calendar date cannot normalize silently']=function(){d1_error(fn()=>D::datetime('2026-02-30 10:00:00'),'INVALID_RESPONSE_AT');};
require __DIR__.'/../includes/dataset/EffectiveMetadataPolicy.php';
use EIPSI\Dataset\EffectiveMetadataPolicy as P;
$tests['Detailed cannot bypass globally disabled browser']=function(){$p=P::defaults();$p['client_browser']=false;d1_assert(!in_array('browser',P::resolve('detailed',$p)['client'],true));};
$tests['Basic does not request browser even globally enabled']=function(){d1_assert(!in_array('browser',P::resolve('basic',P::defaults())['client'],true));};
$tests['Detailed requests globally enabled browser']=function(){d1_assert(in_array('browser',P::resolve('detailed',P::defaults())['client'],true));};
$tests['Profile intersection never enables an unknown global field']=function(){$p=array_fill_keys(array_keys(P::defaults()),false);foreach(P::resolve('detailed',$p)as$fields)d1_assert($fields===array());};
$tests['Backend filters Basic context and detailed-only fields']=function(){$m=array('contract'=>'NATIVE_PROCESS_V1','context'=>array('browser'=>'Firefox'),'items'=>array('R'=>array('item_id'=>'I','response_key'=>'R','variable_name'=>'x','trajectory'=>array(1),'focus_count'=>2)),'events'=>array(1));$r=P::filter($m,'basic');d1_assert(!isset($r['context'])&&!isset($r['items']['R']['trajectory'])&&!isset($r['items']['R']['focus_count'])&&$r['events']===array());};
$tests['Consent preserves a boolean family instead of pretending it is text']=function(){$r=d1_project(array(d1_row('consent',true,'NX_A',1000,'consent')));d1_assert($r['data'][0]['consent']===true&&$r['dictionary'][0]['value_family']==='boolean');};
$tests['Broken Native attempt provenance blocks the projection']=function(){$r=d1_row();$r['native_version_id']='NATIVE_A';d1_error(fn()=>d1_project(array($r)),'MISSING_ATTEMPT_PROVENANCE');};
$tests['XLSX never silently removes XML forbidden text characters']=function(){d1_error(fn()=>EIPSI\Dataset\Transport::workbook(array('data'=>array('columns'=>array('text'),'rows'=>array(array('text'=>"a\x01b"))))),'XLSX_INVALID_XML_CHARACTER');};
$tests['XLSX blocks numbers that Excel cannot preserve at fifteen significant digits']=function(){foreach(array(1234567890123456,0.12345678901234567)as$value)d1_error(fn()=>EIPSI\Dataset\Transport::workbook(array('data'=>array('columns'=>array('n'),'rows'=>array(array('n'=>$value))))),'XLSX_NUMERIC_PRECISION_LIMIT');};
foreach($tests as$name=>$fn){try{$fn();echo 'PASS '.$name."\n";}catch(Throwable$e){$failed++;echo 'FAIL '.$name.': '.$e->getMessage()."\n";}}echo count($tests).' tests, '.$failed." failures\n";exit($failed?1:0);
