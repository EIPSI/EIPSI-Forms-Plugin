<?php
/** User-requested local disposable download demo. Uses product owners; no manual SQL or real mail. */
if(PHP_SAPI!=='cli')exit(1);
if(!defined('DISABLE_WP_CRON'))define('DISABLE_WP_CRON',true);
require dirname(__DIR__,5).'/wp-load.php';
if(!in_array(wp_parse_url(home_url(),PHP_URL_HOST),array('localhost','127.0.0.1'),true))throw new RuntimeException('Local WordPress required');
add_filter('pre_wp_mail',static function(){return false;},PHP_INT_MAX);
$admins=get_users(array('role'=>'administrator','fields'=>'ID','number'=>1));if(!$admins)throw new RuntimeException('Administrator required');wp_set_current_user((int)$admins[0]);
require_once EIPSI_FORMS_PLUGIN_DIR.'includes/study-engine/builder.php';
require_once EIPSI_FORMS_PLUGIN_DIR.'includes/assessment-editor/runtime/Owner.php';
require_once EIPSI_FORMS_PLUGIN_DIR.'includes/study-engine/runtime/NativeBridge.php';
use EIPSI\AssessmentEditor\Definition as D;
use EIPSI\AssessmentEditor\Repository;
use EIPSI\AssessmentEditor\Runtime\Owner;
use EIPSI\StudyEngine\Builder\Service;
use EIPSI\StudyEngine\Builder\Draft;
use EIPSI\StudyEngine\Persistence as P;
use EIPSI\StudyEngine\Runtime as R;
use EIPSI\StudyEngine\Management as M;
$option='eipsi_data1_download_review_v1';$old=get_option($option,null);
if($old){if(($old['state']??null)!=='READY')throw new RuntimeException('Existing incomplete demo: inspect its saved identifiers before retrying');echo wp_json_encode($old,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";exit;}
$config=defined('EIPSI_STUDY_ENGINE_KEYRING')?EIPSI_STUDY_ENGINE_KEYRING:getenv('EIPSI_STUDY_ENGINE_KEYRING');if(is_string($config))$config=json_decode($config,true);if(!is_array($config)||!isset($config['current'],$config['keys']))throw new RuntimeException('Configured Study keyring required');
$keys=array();foreach($config['keys']as$id=>$hex){if(!is_string($hex)||!preg_match('/^[a-f0-9]{64}$/D',$hex))throw new RuntimeException('Invalid configured keyring');$keys[$id]=hex2bin($hex);}
global $wpdb;$db=new P\Database($wpdb);$service=new Service($db);$clock=(object)array('now'=>time());$owner=new Owner(null,static function()use($clock){return $clock->now;});
$assessment='AE_DATA1_DOWNLOAD_DEMO_20261019';$elements=array();
$spec=array(
 array('text','comentario','Comentario',array('content_type'=>'free','max_length'=>200,'placeholder'=>'')),
 array('number','horas_sueno','Horas de sueño',array('min'=>0,'max'=>24,'placeholder'=>'','step'=>0.25)),
 array('date','fecha_referencia','Fecha de referencia',array()),
 array('likert','bienestar','Bienestar',array('min'=>1,'presentation'=>'horizontal')),
 array('vas','energia','Energía',array('min'=>0,'max'=>100,'step'=>1,'initial'=>50,'left_label'=>'Sin energía','right_label'=>'Mucha energía','show_value'=>true)),
 array('multiple_choice','actividades','Actividades realizadas',array('min_selected'=>0,'max_selected'=>3,'presentation'=>'horizontal'))
);
foreach($spec as[$type,$variable,$label,$properties]){
 $e=array('id'=>'EL_DEMO_'.$variable,'type'=>$type,'response_key'=>'R_'.$variable,'properties'=>array_merge(array('label'=>$label,'helper'=>'','variable_name'=>$variable,'required'=>false),$properties),'options'=>array(),'children'=>array());
 if($type==='likert')foreach(array('Muy bajo','Bajo','Intermedio','Alto','Muy alto')as$i=>$label)$e['options'][]=array('id'=>'O_BIENESTAR_'.($i+1),'label'=>$label,'value'=>$i+1);
 if($type==='multiple_choice')foreach(array('CAMINAR'=>'Caminar','LEER'=>'Leer','EJERCICIO'=>'Ejercicio')as$id=>$label)$e['options'][]=array('id'=>'O_'.$id,'label'=>$label);
 $elements[]=$e;
}
$d=D::validate(array('contract'=>D::CONTRACT,'id'=>$assessment,'title'=>'DESCARTABLE · Evaluación para probar descargas','elements'=>$elements));$d['flow']['pages']=array();$d['flow']['metadata_profile']='detailed';$d['flow']['save_continue']['enabled']=true;$d['flow']['save_continue']['window']=array('value'=>1,'unit'=>'minutes');$d['flow']['completion']['message_html']='<p>Respuesta ficticia registrada para probar descargas.</p>';$d=D::validate($d);
$state=array('state'=>'CREATING','name'=>'DESCARTABLE · Prueba de descargas DATA-1','assessment_id'=>$assessment,'created_at'=>gmdate('c'),'emails'=>'blocked; anonymous Study','synthetic_data'=>true);
update_option($option,$state,false);
try{
 $record=Repository::save($d,0);$version=$owner->publish($assessment,$record['revision']);$state['native_version_id']=$version['version_id'];update_option($option,$state,false);
 $study=$service->create(array('name'=>$state['name']),'DATA1_DOWNLOAD_DEMO_20261019');$state['study_id']=$study['study_id'];$state['study_version_id']=$study['id'];update_option($option,$state,false);
 $sd=$study['definition'];$node=Draft::block('E_DATA1_DOWNLOAD_DEMO',$sd['id'],$sd['root_block_ids'][0],'MICRO',0);$node['schedule']=Draft::schedule();$node['metadata']['native_version_id']=$version['version_id'];$sd['nodes'][]=$node;
 $study=$service->save($study['id'],$study['revision'],'DATA1_DOWNLOAD_DEMO_SAVE',$sd);$study=$service->publish($study['id'],$study['revision'],array());$study=$service->activate($study['id'],$study['revision'],'OPEN');$public=$study['definition']['study']['public_id'];
 $state['public_code']=$public;$state['public_url']=add_query_arg('eipsi_study',$public,home_url('/'));$state['admin_url']=admin_url('admin.php?page=eipsi-study-builder');$state['participations']=array();update_option($option,$state,false);
 $runtime=new R\CrossSectionalRuntime($db,new P\SeedVault($keys,$config['current']));
 foreach(range(0,6)as$i){
  $_SERVER['REMOTE_ADDR']='192.0.2.'.($i+10);$clock->now=time();$frame=$runtime->handle($public);$_COOKIE[R\AnonymousAccess::cookieName($public)]=$frame['token'];$assignment=R\NativeBridge::offer($frame);$id=$assignment['execution_id'];$f=$owner->view($id);$ms=time()*1000;
  $meta=array('contract'=>'NATIVE_PROCESS_V1','assessment_id'=>$assessment,'definition_ref'=>array(),'started_at'=>$ms-30000,'completed_at'=>null,'duration'=>0,'status'=>'active','recovery_used'=>false,'recovery_count'=>0,'pages'=>array(),'items'=>array(),'events'=>array(),'dropped_events'=>0,'context'=>array('device'=>$i%2?'touch-capable':'desktop-or-unknown','browser'=>'Synthetic Browser / DATA-1','os'=>'Synthetic OS','screen'=>array('width'=>1920,'height'=>1080),'viewport'=>array('width'=>1280,'height'=>720),'timezone'=>'UTC','language'=>'es','input_capabilities'=>array('touch'=>(bool)($i%2),'pointer'=>true),'webgl_renderer'=>'SYNTHETIC Vendor | Demo GPU','screen_depth'=>24,'pixel_ratio'=>$i%2?2:1,'cpu_cores'=>$i%2?4:8,'cookies_enabled'=>(bool)($i%2)));
  $request=function($action,$f,$answers,$touched,$meta){return array('action'=>$action,'command_id'=>'DEMO_'.bin2hex(random_bytes(16)),'revision'=>$f['revision'],'version_id'=>$f['version_id'],'csrf'=>$f['csrf'],'answers'=>$answers,'touched'=>$touched,'metadata'=>$meta);};
  $f=$owner->command($id,$request('open',$f,array(),array(),$meta));
  $comments=array('Dormí bien; energía estable.','Día tranquilo — lectura y paseo.',"Texto con coma, tilde y salto\nde línea.",'=SUM(1,2)','Hoy omití la fecha a propósito.','Respuesta ficticia número seis.','Parcial de prueba: evaluación sin terminar.');
  $answers=array('R_comentario'=>$comments[$i],'R_horas_sueno'=>array(7.5,6,8,5.5,7,9,6.5)[$i]);$touched=array();
  if($i<6){if($i!==4)$answers['R_fecha_referencia']='2026-10-'.(10+$i);$answers['R_bienestar']='O_BIENESTAR_'.(($i%5)+1);$answers['R_energia']=array(78,45,90,22,64,83)[$i];$touched['R_energia']=true;$answers['R_actividades']=$i%2?array('O_LEER'):array('O_CAMINAR','O_EJERCICIO');}
  foreach($d['elements']as$e)if(array_key_exists($e['response_key'],$answers))$meta['items'][$e['response_key']]=array('item_id'=>$e['id'],'response_key'=>$e['response_key'],'variable_name'=>$e['properties']['variable_name'],'presented_at'=>$ms-30000,'first_interaction_at'=>$ms-20000,'final_response_at'=>$ms,'time_to_first_interaction'=>10000,'time_to_final_response'=>30000,'interaction_count'=>2,'change_count'=>1,'focus_count'=>1,'focus_duration'=>5000,'visit_count'=>1,'revisit_count'=>0,'trajectory'=>array());
  $clock->now=time();$meta['duration']=30000;$meta['completed_at']=$i<6?$ms:null;$meta['status']=$i<6?'completed':'active';$f=$owner->command($id,$request($i<6?'submit':'partial',$f,$answers,$touched,$meta));
  if($i===6){$clock->now+=61;$owner->view($id);}
  $state['participations'][]=array('id'=>$frame['access']['execution_id'],'attempt_id'=>$id,'kind'=>$i<6?'final':'saved_partial');update_option($option,$state,false);if($i<5)usleep(1100000);
 }
 $exports=new M\Exports(new M\Query($state['study_id']));$state['exports']=array();
 foreach(array(false,true)as$partials){$job=$exports->create(array('include_partials'=>$partials,'annexes'=>array('data','dictionary','response_status','bindings','process_metadata','randomization','audit')),'canonical','DATA1_DEMO_EXPORT_'.($partials?'PARTIALS':'FINALS'));while(in_array($job['state'],array('PENDING','RUNNING'),true))$job=$exports->step($job['id']);if($job['state']!=='READY')throw new RuntimeException('Demo export failed: '.($job['error']??$job['state']));$data=json_decode($exports->download($job['id'],'json')['content'],true);$expected=$partials?7:6;if(count($data['data'])!==$expected)throw new RuntimeException('Unexpected demo row count');$exports->download($job['id'],'csv');$xlsx=$exports->download($job['id'],'xlsx');if(substr(base64_decode($xlsx['content'],true),0,2)!=='PK')throw new RuntimeException('Invalid demo workbook');$state['exports'][]=array('id'=>$job['id'],'rows'=>$expected,'include_partials'=>$partials,'expires_at'=>$job['expires_at']);}
 $state['variables']=array_column(array_column($d['elements'],'properties'),'variable_name');$state['state']='READY';$state['note']='All answers and client metadata are synthetic. 192.0.2.x are documentation IPs. Partial archived with fixture clock +61 seconds. Global metadata settings unchanged.';update_option($option,$state,false);
 echo wp_json_encode($state,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
}catch(Throwable $e){$state['state']='INCOMPLETE';$state['error']=$e->getMessage();update_option($option,$state,false);throw $e;}
