<?php
/** Real WP users/endpoints in the disposable M0 database only. */
define('DOING_CRON',true);
require __DIR__.'/m0/bootstrap.php';
require_once EIPSI_FORMS_PLUGIN_DIR.'includes/study-engine/builder.php';
require_once EIPSI_FORMS_PLUGIN_DIR.'includes/assessment-editor/runtime/Owner.php';
require_once ABSPATH.'wp-admin/includes/user.php';
use EIPSI_Admin_Authorization_Policy as A;
use EIPSI\AssessmentEditor as N;
use EIPSI\StudyEngine\Builder as B;
use EIPSI\StudyEngine\Management as M;
$tests=0;$failures=0;$users=array();$created=array();$rolesBefore=get_option($wpdb->prefix.'user_roles');$versionBefore=get_option('eipsi_rbac_version',null);
$publicConfig=ABSPATH.'wp-content/mu-plugins/eipsi-rbac-config.php';if(file_exists($publicConfig))throw new RuntimeException('Fixture collision');wp_mkdir_p(dirname($publicConfig));copy(__DIR__.'/rbac/public-config.php',$publicConfig);
function rb_assert($ok,$message='Assertion failed'){if(!$ok)throw new RuntimeException($message);}
function rb_case($name,callable $test){global $tests,$failures;$tests++;try{$test();echo "PASS $name\n";}catch(Throwable $e){$failures++;echo "FAIL $name: ".$e->getMessage()."\n";}}
function rb_denied(callable $test){try{$test();}catch(Throwable $e){rb_assert((int)$e->getCode()===403||$e->getMessage()==='BUILDER_FORBIDDEN','Wrong rejection '.$e->getMessage());return;}throw new RuntimeException('Access unexpectedly allowed');}
function rb_http(int $user,string $path,?array $body=null,string $nonceAction=''){
 wp_set_current_user($user);$cookies=array();
 if($user){$token=WP_Session_Tokens::get_instance($user)->create(time()+600);foreach(array(LOGGED_IN_COOKIE=>'logged_in',AUTH_COOKIE=>'auth')as$name=>$scheme){$value=wp_generate_auth_cookie($user,time()+600,$scheme,$token);$_COOKIE[$name]=$value;$cookies[]=new WP_Http_Cookie(array('name'=>$name,'value'=>$value));}}
 if($body!==null&&$nonceAction!=='')$body['nonce']=wp_create_nonce($nonceAction);
 if(isset($body['payload'])&&is_array($body['payload'])){$body['payload']['nonce']=$body['nonce']??'';$body['payload']=wp_json_encode($body['payload']);unset($body['nonce']);}
 $r=wp_remote_request('http://127.0.0.1'.$path,array('method'=>$body===null?'GET':'POST','body'=>$body,'cookies'=>$cookies,'timeout'=>25,'redirection'=>0,'headers'=>array('Host'=>'127.0.0.1:18080','Origin'=>'http://127.0.0.1:18080')));
 rb_assert(!is_wp_error($r),'HTTP transport failed');return array('status'=>wp_remote_retrieve_response_code($r),'body'=>wp_remote_retrieve_body($r));
}
function rb_ajax($user,$op,$extra=array(),$nonce=true){return rb_http($user,'/wp-admin/admin-ajax.php',array('action'=>'eipsi_assessment_editor','operation'=>$op)+$extra,$nonce?'eipsi_native_assessment':'');}
try{
 A::upgrade();
 foreach(array('administrator','eipsi_administrator','eipsi_researcher','eipsi_data_manager','eipsi_viewer','subscriber')as$role){$u=wp_insert_user(array('user_login'=>'rb_'.bin2hex(random_bytes(5)),'user_pass'=>wp_generate_password(40),'role'=>$role));rb_assert(!is_wp_error($u));$users[$role]=$u;$created[]=$u;}
 wp_set_current_user(1);$definition=json_decode(file_get_contents(__DIR__.'/assessment-editor/functional-fixture.json'),true);$definition['id']='AE_RBAC_'.bin2hex(random_bytes(6));$record=N\Repository::save($definition,0);$native=(new N\Runtime\Owner())->publish($definition['id'],1);
 $service=new B\Service(new EIPSI\StudyEngine\Persistence\Database($wpdb));$study=$service->create(array('name'=>'RBAC disposable'),'RBAC_CREATE_'.bin2hex(random_bytes(5)));
 foreach($users as $role=>$uid){
  $expected=$role==='administrator'?array_keys(A::actions()):(A::roleActions()[$role]??array());
  rb_case($role.' capability matrix and WordPress least privilege',function()use($uid,$role,$expected){wp_set_current_user($uid);foreach(A::actions()as$action=>$cap)rb_assert(A::allows($action)===in_array($action,$expected,true),'Mismatch '.$action);rb_assert(eipsi_user_can_manage_longitudinal()===in_array('schema.manage',$expected,true),'Retired mixed management bypass');if($role!=='administrator'){rb_assert(!current_user_can('manage_options'));rb_assert(!current_user_can('promote_users'));rb_assert(!current_user_can('activate_plugins'));}});
  rb_case($role.' Assessment page direct HTTP',function()use($uid,$expected){$r=rb_http($uid,'/wp-admin/admin.php?page=eipsi-assessment-editor');rb_assert($r['status']===(in_array('assessments.view',$expected,true)?200:403),'Status '.$r['status']);});
  rb_case($role.' Study page direct HTTP',function()use($uid,$expected){$r=rb_http($uid,'/wp-admin/admin.php?page=eipsi-study-builder');rb_assert($r['status']===(in_array('studies.view',$expected,true)?200:403),'Status '.$r['status']);});
  rb_case($role.' nonce-valid Assessment list endpoint',function()use($uid,$expected){$r=rb_ajax($uid,'list');rb_assert($r['status']===(in_array('assessments.view',$expected,true)?200:403),'Status '.$r['status']);});
  rb_case($role.' nonce-valid Assessment mutation endpoint',function()use($uid,$expected,$definition){$d=$definition;$d['id']='AE_RBAC_'.bin2hex(random_bytes(6));$r=rb_ajax($uid,'save',array('definition'=>wp_json_encode($d),'revision'=>'0'));$allowed=in_array('assessments.edit',$expected,true);rb_assert($r['status']===($allowed?200:403),'Status '.$r['status']);if($allowed){wp_set_current_user(1);N\Repository::delete($d['id'],1);}});
  rb_case($role.' server-side configuration tabs and menu visibility',function()use($uid,$expected){$r=rb_http($uid,'/wp-admin/admin.php?page=eipsi-configuration&tab=external-api');rb_assert($r['status']===(in_array('api.manage',$expected,true)?200:403),'Status '.$r['status']);if(in_array('studies.view',$expected,true)&&!in_array('api.manage',$expected,true)){$s=rb_http($uid,'/wp-admin/admin.php?page=eipsi-study-builder');rb_assert(strpos($s['body'],'admin.php?page=eipsi-configuration')===false,'Restricted config menu visible');}});
  foreach(array(
   array('eipsi_external_api','eipsi_external_api','api.manage',array('operation'=>'ops_health')),
   array('eipsi_scheduler','eipsi_scheduler','scheduler.manage',array('operation'=>'verify')),
   array('eipsi_get_db_status','eipsi_admin_nonce','schema.manage',null)
  )as$surface)rb_case($role.' real AJAX '.$surface[0],function()use($uid,$expected,$surface){
    $body=array('action'=>$surface[0]);if($surface[3]!==null)$body['payload']=$surface[3];
    $r=rb_http($uid,'/wp-admin/admin-ajax.php',$body,$surface[1]);$allowed=in_array($surface[2],$expected,true);
    rb_assert($r['status']===($allowed?200:403),'Unexpected status '.$r['status']);$j=json_decode($r['body'],true);rb_assert(($j['success']??null)===$allowed,'Wrong authorization response');
  });

 }
 $researcher=$users['eipsi_researcher'];$data=$users['eipsi_data_manager'];$viewer=$users['eipsi_viewer'];
 rb_case('Correct capability with invalid nonce is denied',function()use($researcher){$r=rb_ajax($researcher,'list',array('nonce'=>'invalid'),false);rb_assert($r['status']===403);});
 rb_case('Unknown operation fails closed for Administrator',function(){wp_set_current_user(1);rb_denied(function(){A::demand(A::studyOperation('unknown'));});});
 rb_case('Researcher owner creates and publishes Assessment',function()use($researcher,$definition){wp_set_current_user($researcher);$d=$definition;$d['id']='AE_RBAC_R_'.bin2hex(random_bytes(5));N\Repository::save($d,0);$v=(new N\Runtime\Owner())->publish($d['id'],1);rb_assert($v['version_id']!=='');global $extraAssessment;$extraAssessment=$d['id'];});
 rb_case('Researcher owner edits Study without schema/settings privileges',function()use($researcher,$service,&$study,$native){wp_set_current_user($researcher);$d=$study['definition'];$d['protocol_metadata']['study_name']='Updated by researcher';$n=B\Draft::block('RBAC_ASSESSMENT',$d['id'],$d['root_block_ids'][0],'MICRO',0);$n['metadata']['native_version_id']=$native['version_id'];$n['schedule']=B\Draft::schedule();$d['nodes'][]=$n;$study=$service->save($study['id'],0,'RBAC_SAVE',$d);rb_assert($study['revision']===1);});

 rb_case('Researcher publishes and activates Study through administrative owner',function()use($researcher,$service,&$study){wp_set_current_user($researcher);$issues=$service->validate($study['id'])['issues'];$acks=array();foreach($issues as$i)if($i['severity']==='WARNING')$acks[]=$i['code'].'|'.($i['node_id']??$i['path']??'');$study=$service->publish($study['id'],$study['revision'],$acks);$study=$service->activate($study['id'],$study['revision'],'OPEN');rb_assert($study['state']==='PUBLISHED');});
 rb_case('Anonymous participant opens Researcher-published Study without WordPress role',function()use(&$study){$r=rb_http(0,'/?eipsi_study='.rawurlencode($study['definition']['study']['public_id']));rb_assert($r['status']===200,'Status '.$r['status']);rb_assert(strpos($r['body'],'eipsi-native-assessment-frame')!==false,'Assessment did not open');});
 foreach(array('schema.manage','scheduler.manage','api.manage','privacy.manage','email.manage','settings.manage','identities.view')as$action)rb_case('Researcher denied '.$action,function()use($researcher,$action){wp_set_current_user($researcher);rb_denied(function()use($action){A::demand($action);});});
 rb_case('Data Manager cannot save Study through owner',function()use($data,$service,$study){wp_set_current_user($data);rb_denied(function()use($service,$study){$service->save($study['id'],1,'RBAC_BAD',$study['definition']);});});
 rb_case('Data Manager cannot delete Assessment through owner',function()use($data,$definition){wp_set_current_user($data);rb_denied(function()use($definition){N\Repository::delete($definition['id'],1);});});
 rb_case('Viewer progress endpoint and response denial',function()use($viewer,$study){wp_set_current_user($viewer);$q=new M\Query($study['study_id']);rb_assert(isset($q->summary(array())['context']));rb_denied(function()use($q){$q->attempts(array());});});
 rb_case('Data Manager response query and identity denial',function()use($data,$study){wp_set_current_user($data);$q=new M\Query($study['study_id']);rb_assert(isset($q->attempts(array())['items']));rb_denied(function()use($q){$q->identity('NONEXISTENT');});});
 rb_case('Viewer cannot create exports through owner',function()use($viewer,$study){wp_set_current_user($viewer);rb_denied(function()use($study){$x=new M\Exports(new M\Query($study['study_id']));$x->create(array(),'responses','RBAC_VIEWER_EXPORT');});});
 rb_case('Data Manager creates and downloads own dataset job',function()use($data,$study){wp_set_current_user($data);$x=new M\Exports(new M\Query($study['study_id']));$j=$x->create(array(),'responses','RBAC_DATA_EXPORT');$j=$x->step($j['id']);rb_assert($j['state']==='READY');$r=$x->download($j['id'],'csv');rb_assert(is_array($r));global $dataJob;$dataJob=$j['id'];});
 rb_case('Data Manager cannot export identities',function()use($data,$study){wp_set_current_user($data);$x=new M\Exports(new M\Query($study['study_id']));rb_denied(function()use($x){$x->create(array(),'identities','RBAC_IDENTITIES');});});
 rb_case('Export revocation applies to an existing job download',function()use($data,$study){global $dataJob;$u=new WP_User($data);$u->add_cap(A::capability('data.export'),false);wp_set_current_user(0);wp_set_current_user($data);try{$x=new M\Exports(new M\Query($study['study_id']));rb_denied(function()use($x,$dataJob){$x->download($dataJob,'csv');});}finally{$u->remove_cap(A::capability('data.export'));}});
 rb_case('Custom role works by capability, never slug',function()use($definition,$service,$study){add_role('rb_custom','RB custom',array('read'=>true,A::capability('access')=>true,A::capability('assessments.view')=>true,A::capability('studies.view')=>true,A::capability('studies.edit')=>true));$u=get_user_by('id',get_current_user_id());$u=new WP_User($u->ID);$before=$u->roles;$u->add_role('rb_custom');wp_set_current_user(0);wp_set_current_user($u->ID);rb_assert(A::allows('assessments.view'));foreach($before as$role)$u->remove_role($role);wp_set_current_user(0);wp_set_current_user($u->ID);rb_assert(N\Repository::load($definition['id'])['revision']===1);rb_assert(A::allows('studies.edit')&&!A::allows('studies.publish'));rb_denied(function()use($service,$study){$service->archiveStudy($study['id'],$study['revision']);});rb_denied(function()use($definition){N\Repository::save($definition,1);});$u->remove_role('rb_custom');foreach($before as$role)$u->add_role($role);remove_role('rb_custom');});
 rb_case('Multiple roles combine without reassignment',function()use($viewer){$u=new WP_User($viewer);$u->add_role('eipsi_researcher');wp_set_current_user(0);wp_set_current_user($viewer);rb_assert(A::allows('assessments.edit')&&in_array('eipsi_viewer',wp_get_current_user()->roles,true));$u->remove_role('eipsi_researcher');});
 rb_case('Current version performs no role option writes',function(){wp_set_current_user(1);global $wpdb;$before=$wpdb->num_queries;A::upgrade();rb_assert($wpdb->num_queries-$before<=1,'Unnecessary upgrade queries');});
 rb_case('Reactivation preserves customized role capabilities',function(){wp_set_current_user(1);$role=get_role('eipsi_viewer');$role->add_cap('rb_custom_cap');$role->remove_cap(A::capability('progress.view'));A::upgrade();rb_assert(get_role('eipsi_viewer')->has_cap('rb_custom_cap')&&!get_role('eipsi_viewer')->has_cap(A::capability('progress.view')));$role->remove_cap('rb_custom_cap');$role->add_cap(A::capability('progress.view'));});
 rb_case('Upgrade grants WP administrator caps without reassigning users',function()use($users){delete_option('eipsi_rbac_version');$before=array();foreach($users as$id)$before[$id]=(new WP_User($id))->roles;A::upgrade();foreach(A::actions()as$cap)rb_assert(get_role('administrator')->has_cap($cap));foreach($before as$id=>$roles)rb_assert((new WP_User($id))->roles===$roles);});
 rb_case('Deactivation keeps role assignments and capabilities',function()use($users){wp_set_current_user(1);EIPSI_Lifecycle::deactivate();foreach($users as$role=>$id)rb_assert(in_array($role,(new WP_User($id))->roles,true));rb_assert(get_role('eipsi_researcher')->has_cap(A::capability('studies.edit')));EIPSI_Cron_Registry::schedule_activation();});
 rb_case('No WP user gets participant authorization implicitly',function(){wp_set_current_user(0);rb_assert(!A::allows('access'));rb_denied(function(){N\Repository::listing();});});
}finally{
 if(is_file($publicConfig))unlink($publicConfig);
 wp_set_current_user(1);if(isset($study))$service->deleteStudy($study['study_id'],'ELIMINAR '.$study['study_id']);
 foreach(array_filter(array($definition['id']??null,$extraAssessment??null))as$id){$db=new EIPSI\StudyEngine\Persistence\Database($wpdb);$db->query($db->prepare('DELETE FROM '.$db->table('native_versions').' WHERE assessment_id=%s',$id));delete_option(N\Repository::PREFIX.$id);}
 foreach($created as$id)wp_delete_user($id);
 update_option($wpdb->prefix.'user_roles',$rolesBefore);wp_roles()->for_site();if($versionBefore===null)delete_option('eipsi_rbac_version');else update_option('eipsi_rbac_version',$versionBefore);wp_set_current_user(0);wp_set_current_user(1);
}
echo "$tests tests, $failures failures\n";ob_end_flush();exit($failures?1:0);
