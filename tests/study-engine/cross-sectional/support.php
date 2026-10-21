<?php
use EIPSI\StudyEngine\Content\TemplateAdapter;
use EIPSI\StudyEngine\Content\AssessmentRenderer;
use EIPSI\StudyEngine\Runtime as R;
use EIPSI\StudyEngine\Persistence as P;
use EIPSI\StudyEngine\Contracts\Canonical;

function pe_assert($condition,$message='Assertion failed'){if(!$condition)throw new RuntimeException($message);}
function pe_equal($a,$b,$label='Mismatch'){pe_assert(Canonical::json($a)===Canonical::json($b),$label);}
function pe_reject($fn,$code){try{$fn();}catch(Throwable $e){pe_assert(strpos($e->getMessage(),$code)!==false,'Wrong error '.$e->getMessage());return;}throw new RuntimeException('Expected '.$code);}
function pe_block($name,$attrs=array(),$children=array(),$html=''){return array('blockName'=>$name,'attrs'=>$attrs,'innerBlocks'=>$children,'innerHTML'=>$html,'innerContent'=>$children?array_fill(0,count($children),null):array($html));}
function pe_question($key,$type='eipsi/campo-texto',$attrs=array()){return pe_block($type,array_merge(array('fieldName'=>$key,'label'=>'Pregunta '.$key,'required'=>true),$attrs));}
function pe_blocks($variant='simple'){
    $first=array(pe_question('q1'),pe_question('q3','eipsi/campo-radio',array('options'=>'Sí;No')));
    $second=array(pe_question('q4'),pe_question('q5'),pe_question('q8'));
    if($variant==='simple')$first=array(pe_question('q1'));
    if($variant==='consent')$first=array(pe_question('consent','eipsi/consent-block',array('contenido'=>'<p>Consentimiento de prueba.</p>')),pe_question('follow'));
    if($variant==='random')$first=array(pe_block('core/group',array('anchor'=>'random'),array(pe_question('r1'),pe_question('r2'),pe_question('r3'))));
    $children=in_array($variant,array('pages','branch'),true)?array(pe_block('eipsi/form-page',array(),$first),pe_block('eipsi/form-page',array(),$second)):$first;
    return array(pe_block('eipsi/form-container',array('allowBackwardsNav'=>true,'completionTitle'=>'Prueba completada'),$children));
}
function pe_rule($version,$source,$action,$target=null,$value=null){return array('id'=>'RULE_'.$source,'version_id'=>$version,'trigger'=>'IMMEDIATE','source'=>array('kind'=>'QUESTION','node_id'=>$source,'occurrence_selector'=>array('kind'=>'SINGLE')),'order'=>0,'predicate'=>array('op'=>'EQ','left'=>array('kind'=>'REFERENCE','reference'=>array('kind'=>'QUESTION','node_id'=>$source,'occurrence_selector'=>array('kind'=>'SINGLE'))),'right'=>array('kind'=>'VALUE','type'=>is_bool($value)?'BOOLEAN':'STRING','value'=>$value)),'action'=>$action,'target'=>$target===null?null:array('kind'=>'QUESTION','node_id'=>$target,'occurrence_selector'=>array('kind'=>'SINGLE')),'resume_target'=>null,'on_target_unavailable'=>null,'metadata'=>array());}
function pe_definition($variant='simple',$study=null,$public=null,$number=1){
    $study=$study??'STUDY_'.bin2hex(random_bytes(8));$public=$public??'PUBLIC_'.bin2hex(random_bytes(8));$options=array();
    if($variant==='branch')$options['condition_rules']=array(pe_rule('VERSION_'.$number,'Q_q3','JUMP','Q_q8','O_'.substr(hash('sha256','No'),0,24)));
    if($variant==='consent')$options['condition_rules']=array(pe_rule('VERSION_'.$number,'Q_consent','TERMINATE',null,false));
    if($variant==='random')$options['random_groups']=array('G_random'=>array('strategy'=>'RANDOM_ORDER','scope'=>'EXECUTION','candidates'=>array('Q_r1','Q_r2','Q_r3'),'weights'=>array(),'select_n'=>null,'draw_count'=>null,'order'=>'DRAW','algorithm_version'=>'EIPSI_RANDOM_V1'));
    return (new TemplateAdapter())->fromBlocks(pe_blocks($variant),$study,$public,$number,$options);
}
function pe_vault(){return new P\SeedVault(array('HTTP_TEST'=>str_repeat('k',32)),'HTTP_TEST');}
function pe_runtime($observer=null){global $wpdb;return new R\CrossSectionalRuntime(new P\Database($wpdb),pe_vault(),$observer);}
function pe_publish($variant='simple',$restart=false,$definition=null){global $wpdb;$d=$definition??pe_definition($variant);$db=new P\Database($wpdb);$id=(new P\StudyVersionRepository($db))->publish($d);(new R\PublicStudyRepository($db))->activate($id,'OPEN',$restart);return array('definition'=>$d,'version'=>$id,'public'=>$d['study']['public_id']);}
function pe_request(array $frame,$action,$responses=array(),$command=null){$r=array('action'=>$action,'command_id'=>$command??'HTTP_'.bin2hex(random_bytes(8)),'expected_revision'=>$frame['view']['revision'],'csrf'=>$frame['csrf'],'execution_id'=>$frame['access']['execution_id']);if(!in_array($action,array('restart','refresh'),true))$r['block_execution_id']=$frame['view']['next_action']['payload']['assessment_execution_id'];if($responses)$r['responses']=$responses;return $r;}
function pe_http($public,$cookies=array(),$body=null,$extra=array()){
    $url=home_url('/?eipsi_study='.rawurlencode($public));$headers=array_merge(array('Host'=>'127.0.0.1:18080','Accept'=>'application/json'),$extra);
    $args=array('timeout'=>20,'redirection'=>0,'headers'=>$headers,'cookies'=>$cookies);
    if($body!==null){$args['method']='POST';$args['body']=$body;}
    $response=wp_remote_request('http://127.0.0.1/?eipsi_study='.rawurlencode($public),$args);pe_assert(!is_wp_error($response),is_wp_error($response)?$response->get_error_message():'HTTP failure');
    return array('status'=>wp_remote_retrieve_response_code($response),'data'=>json_decode(wp_remote_retrieve_body($response),true),'cookies'=>wp_remote_retrieve_cookies($response),'headers'=>wp_remote_retrieve_headers($response),'body'=>wp_remote_retrieve_body($response));
}
function pe_http_request($response,$action,$values=array(),$command=null){
    $html=$response['data']['html'];$r=array('action'=>$action);
    foreach(array('csrf','command_id','expected_revision','execution_id','block_execution_id') as $key)if(preg_match('/name="'.$key.'" value="([^"]*)"/',$html,$m))$r[$key]=html_entity_decode($m[1],ENT_QUOTES,'UTF-8');
    if($command!==null)$r['command_id']=$command;if($values)$r['responses']=$values;return $r;
}

/** Native participant fixture for transport tests; domain fixtures keep their engine nodes. */
function pe_native_transport_fixture(array $d): array {
 require_once EIPSI_FORMS_PLUGIN_DIR.'includes/assessment-editor/runtime/Owner.php';
 static $version;
 $owner=new EIPSI\AssessmentEditor\Runtime\Owner();
 if($version===null||!$owner->store->find('versions',$version['id'])){
  $owner->store->install();$native=json_decode(file_get_contents(__DIR__.'/../../assessment-editor/functional-fixture.json'),true);$native['id']='AE_TRANSPORT_'.bin2hex(random_bytes(8));$native['elements']=array_values(array_filter($native['elements'],static function($e){return $e['type']==='text';}));$native['flow']['pages']=array();$native=EIPSI\AssessmentEditor\Definition::validate($native);$json=EIPSI\AssessmentEditor\Definition::json($native);$version=array('id'=>'NV_TRANSPORT_'.bin2hex(random_bytes(8)),'assessment_id'=>$native['id'],'author_revision'=>1,'definition_json'=>$json,'definition_hash'=>hash('sha256',$json),'state'=>'PUBLISHED','actor_id'=>1,'created_at'=>time());$owner->store->transaction(function()use($owner,$version){$owner->store->insert('versions',$version);});
 }
 foreach($d['nodes'] as &$node)if(($node['completion_boundary']??null)==='ASSESSMENT'){$node['metadata']['native_version_id']=$version['id'];$node['metadata']['native_definition_hash']=$version['definition_hash'];}unset($node);return $d;
}
