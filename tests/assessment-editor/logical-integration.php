<?php
use EIPSI\AssessmentEditor\Definition as D;
use EIPSI\AssessmentEditor\Runtime\Owner;
/** Included by the Native product suite: real SQL and existing participant boundary. */
function pr_logic_fixture($action='JUMP',$responsive=null) {
    global $owner,$studyFixtures,$executions;
    $source=pr_new(true,'text',$responsive);$definition=pr_definition(true,'text');$prototype=$definition['elements'][0];$definition['elements']=array();
    for($i=1;$i<=3;$i++){$q=$prototype;$q['id']='LQ_'.$i;$q['response_key']='LR_'.$i;$q['properties']['variable_name']='logical_'.$i;$q['properties']['required']=true;$definition['elements'][]=$q;}$definition=D::validate($definition);$target=pr_new(true,'text',$definition);
    $d=pe_definition();$d['nodes']=array_values(array_filter($d['nodes'],static function($n){return $n['kind']==='BLOCK';}));$sourceNode=null;foreach($d['nodes'] as &$n)if($n['completion_boundary']==='ASSESSMENT'){$n['metadata']['native_version_id']=$source['version_id'];$n['metadata']['native_definition_hash']=$source['definition_hash'];$q=$source['definition']['elements'][0];$n['metadata']['native_questions']=array(array('id'=>$q['id'],'label'=>'Origen','response_key'=>$q['response_key'],'value_type'=>'STRING','options'=>array(),'page'=>0,'randomization_id'=>null));$sourceNode=$n;}unset($n);
    $targetNode=$sourceNode;$targetNode['id']='E_LOGICAL_TARGET';$targetNode['logical_id']=$targetNode['id'];$targetNode['position']++;$targetNode['metadata']=array('label'=>'Destino','native_version_id'=>$target['version_id'],'native_definition_hash'=>$target['definition_hash'],'native_questions'=>array());
    foreach($target['definition']['elements'] as $q)$targetNode['metadata']['native_questions'][]=array('id'=>$q['id'],'label'=>$q['id'],'response_key'=>$q['response_key'],'value_type'=>'STRING','options'=>array(),'page'=>0,'randomization_id'=>null);$d['nodes'][]=$targetNode;
    foreach(array($sourceNode,$targetNode) as $n){$json=\EIPSI\StudyEngine\Contracts\Canonical::json(array('label'=>'Native','html'=>''));$id='C_'.$n['id'];$d['nodes'][]=array('kind'=>'CONTENT','id'=>$id,'version_id'=>$d['id'],'parent_id'=>$n['id'],'position'=>0,'type'=>'TEXT','content'=>array('content_id'=>$id,'version'=>1,'snapshot'=>$json,'sha256'=>hash('sha256',$json)),'durable_reference'=>null);}
    $q=$source['definition']['elements'][0];$d['protocol_metadata']['trajectory_conditions']=array(array('id'=>'LC_SQL_JUMP','label'=>'Salto SQL','source'=>array('assessment_id'=>$sourceNode['id'],'question_id'=>$q['id']),'event'=>'ANSWER','priority'=>1,'predicate'=>array('op'=>'EQ','reference'=>array('kind'=>'ANSWER','assessment_id'=>$sourceNode['id'],'question_id'=>$q['id']),'value'=>'go'),'actions'=>array(array('type'=>$action,'target_id'=>$targetNode['id'],'question_id'=>'LQ_3'))));
    if($responsive!==null){
        require_once EIPSI_FORMS_PLUGIN_DIR.'includes/study-engine/builder.php';
        $d['protocol_metadata']['execution_planning']='DEPENDENCY_PLAN_V1';
        foreach(array('nativeQuestions'=>'native_questions','nativeRandomizations'=>'native_randomizations') as $method=>$key){$reflect=new \ReflectionMethod(\EIPSI\StudyEngine\Builder\Service::class,$method);$reflect->setAccessible(true);foreach($d['nodes'] as &$n)if($n['id']===$sourceNode['id'])$n['metadata'][$key]=$reflect->invoke(null,$responsive);unset($n);}
        $d['protocol_metadata']['trajectory_conditions']=array();
        foreach(array('go'=>'EL_Q0','no'=>'EL_Q1') as $value=>$candidate)$d['protocol_metadata']['trajectory_conditions'][]=array('id'=>'LC_RESP_'.$value,'label'=>'Responsive '.$value,'source'=>array('assessment_id'=>$sourceNode['id'],'question_id'=>$q['id']),'event'=>'ANSWER','priority'=>1,'predicate'=>array('op'=>'EQ','reference'=>array('kind'=>'ANSWER','assessment_id'=>$sourceNode['id'],'question_id'=>$q['id']),'value'=>$value),'actions'=>array(array('type'=>'FORCE_RANDOM','target_id'=>$sourceNode['id'],'randomization_id'=>'EL_RANDOM_TEST','candidate_ids'=>array($candidate))));
    }
    $p=pe_publish('simple',false,$d);$studyFixtures[]=$d['study']['logical_id'];$frame=pe_runtime()->handle($p['public']);$cookie=\EIPSI\StudyEngine\Runtime\AnonymousAccess::cookieName($p['public']);$_COOKIE[$cookie]=$frame['token'];
    $binding=array('kind'=>'STUDY_ANONYMOUS','public_id'=>$p['public'],'execution_id'=>$frame['access']['execution_id'],'study_version_id'=>$frame['access']['study_version_id'],'participant_id'=>$frame['access']['id'],'block_execution_id'=>$frame['view']['next_action']['payload']['assessment_execution_id']);
    $native=new Owner();$assigned=$native->studyAssignment($source['version_id'],$binding);$executions[]=$assigned['execution_id'];return array($native,$native->view($assigned['execution_id']),$binding,$cookie,$q['response_key']);
}
pr_test('Native cross-Assessment condition enters destination question with one atomic SQL transition',function(){
    $previous=getenv('EIPSI_STUDY_ENGINE_KEYRING');putenv('EIPSI_STUDY_ENGINE_KEYRING='.json_encode(array('current'=>'HTTP_TEST','keys'=>array('HTTP_TEST'=>bin2hex(str_repeat('k',32))))));$cookie=null;
    try{list($native,$f,$binding,$cookie,$key)=pr_logic_fixture();$request=pr_command($f,'logic',array($key=>'go'));$next=$native->command($f['execution_id'],$request);pr_assert($next['execution_id']!==$f['execution_id']&&$next['version_id']!==$f['version_id']);pr_assert($next['state']==='ACTIVE'&&$next['logical_skipped_ids']===array('LQ_1','LQ_2'));$source=$native->store->find('executions',$f['execution_id']);pr_assert($source['state']==='LOGICAL_EXIT'&&$source['final_json']!==null);$again=$native->command($f['execution_id'],$request);pr_assert($again['execution_id']===$next['execution_id']);$done=$native->command($next['execution_id'],pr_command($next,'submit',array('LR_3'=>'Destination answer')));pr_assert($done['state']==='COMPLETED');}finally{if($cookie)unset($_COOKIE[$cookie]);$previous===false?putenv('EIPSI_STUDY_ENGINE_KEYRING'):putenv('EIPSI_STUDY_ENGINE_KEYRING='.$previous);}
});
pr_test('Invalid Native metadata rolls back Study logical transition destination and audit',function(){
    $previous=getenv('EIPSI_STUDY_ENGINE_KEYRING');putenv('EIPSI_STUDY_ENGINE_KEYRING='.json_encode(array('current'=>'HTTP_TEST','keys'=>array('HTTP_TEST'=>bin2hex(str_repeat('k',32))))));$cookie=null;
    try{list($native,$f,$binding,$cookie,$key)=pr_logic_fixture();$before=$native->store->db->row($native->store->prepare('SELECT checkpoint_json FROM '.$native->store->db->table('study_executions').' WHERE id=%s',$binding['execution_id']));$q=pr_command($f,'logic',array($key=>'go'));$q['metadata']['forged']='invalid';pr_reject(function()use($native,$f,$q){$native->command($f['execution_id'],$q);});$after=$native->store->db->row($native->store->prepare('SELECT checkpoint_json FROM '.$native->store->db->table('study_executions').' WHERE id=%s',$binding['execution_id']));pr_assert($before===$after&&$native->store->find('executions',$f['execution_id'])['state']==='ACTIVE');}finally{if($cookie)unset($_COOKIE[$cookie]);$previous===false?putenv('EIPSI_STUDY_ENGINE_KEYRING'):putenv('EIPSI_STUDY_ENGINE_KEYRING='.$previous);}
});

pr_test('Responsive intra-assessment SQL navigation prunes excluded partials and reuses initial seed',function(){
    $previous=getenv('EIPSI_STUDY_ENGINE_KEYRING');putenv('EIPSI_STUDY_ENGINE_KEYRING='.json_encode(array('current'=>'HTTP_TEST','keys'=>array('HTTP_TEST'=>bin2hex(str_repeat('k',32))))));$cookie=null;
    try{
        $d=pr_definition(true,'text');$main=$d['elements'][0];$fixture=json_decode(file_get_contents(__DIR__.'/functional-fixture.json'),true);$break=array_values(array_filter($fixture['elements'],static function($e){return $e['type']==='page_break';}))[0];
        $random=pr_random_definition(1)['elements'][0];$random['children']=array_slice($random['children'],0,2);$d['elements']=array($main,$break,$random);$d=D::validate($d);
        list($native,$f,$binding,$cookie,$key)=pr_logic_fixture('JUMP',$d);
        $command=static function($frame,$action,$answers){$metadata=isset($frame['metadata']['contract'])?$frame['metadata']:pr_meta($frame);$metadata['randomization']=$frame['randomization'];if($action==='partial')$metadata['items']['R_Q0']=array('item_id'=>'EL_Q0','response_key'=>'R_Q0','trajectory'=>array(),'initial_state'=>null);return pr_command($frame,$action,$answers,array(),$metadata);};
        $forged=$command($f,'logic',array($key=>'go'));$forged['metadata']['randomization']['seed_ref']='FORGED';pr_reject(function()use($native,$f,$forged){$native->command($f['execution_id'],$forged);},409);pr_assert($native->view($f['execution_id'])['revision']===$f['revision'],'Forged provenance rolls back');
        $f=$native->command($f['execution_id'],$command($f,'logic',array($key=>'go')));pr_assert($f['randomization']['blocks']['EL_RANDOM_TEST']['order']===array('EL_Q0'),'First force');
        $f=$native->command($f['execution_id'],$command($f,'next',array($key=>'go')));
        $f=$native->command($f['execution_id'],$command($f,'partial',array($key=>'go','R_Q0'=>'Old answer')));
        $f=$native->command($f['execution_id'],$command($f,'back',array($key=>'go','R_Q0'=>'Old answer')));
        $f=$native->command($f['execution_id'],$command($f,'logic',array($key=>'no','R_Q0'=>'Old answer')));
        pr_assert($f['randomization']['blocks']['EL_RANDOM_TEST']['order']===array('EL_Q1'),'Changed force');pr_assert(!isset($f['session']['answers']['R_Q0']),'Excluded answer pruned');pr_assert(isset($f['metadata']['items']['R_Q0']),'Previously shown item history retained');
        $f=$native->view($f['execution_id']);pr_assert($f['randomization']['blocks']['EL_RANDOM_TEST']['order']===array('EL_Q1'),'Reload keeps selection');
        $f=$native->command($f['execution_id'],$command($f,'logic',array($key=>'go')));pr_assert($f['randomization']['blocks']['EL_RANDOM_TEST']['order']===array('EL_Q0'),'Back to original outcome');
        $f=$native->command($f['execution_id'],$command($f,'next',array($key=>'go')));
        $done=$native->command($f['execution_id'],$command($f,'submit',array($key=>'go','R_Q0'=>'New answer')));pr_assert($done['state']==='COMPLETED');
        $final=json_decode($native->store->find('executions',$f['execution_id'])['final_json'],true);pr_assert(!isset($final['answers']['R_Q1'])&&$final['answers']['R_Q0']==='New answer');
    }finally{if($cookie)unset($_COOKIE[$cookie]);$previous===false?putenv('EIPSI_STUDY_ENGINE_KEYRING'):putenv('EIPSI_STUDY_ENGINE_KEYRING='.$previous);}
});
