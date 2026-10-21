<?php
/** SEC-0: real isolated WordPress, REST and authenticated HTTP authorization. */
define('DOING_CRON', true);
require __DIR__.'/m0/bootstrap.php';
$tests=array(); $failed=0; $posts=array(); $users=array(); $credentials=array();
function sec0_state(){
 global $wpdb;
 $state=array();
 foreach(array('postmeta','eipsi_randomization_configs','eipsi_randomization_assignments','eipsi_randomization_events','eipsi_randomization_logs','eipsi_manual_overrides') as $suffix){
  $table=$wpdb->prefix.$suffix;
  if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))===$table)$state[$suffix]=$wpdb->get_results("SELECT * FROM `$table`",ARRAY_A);
 }
 return $state;
}
function sec0_request($target,$sources,$route='randomization-config'){
 $r=new WP_REST_Request('POST','/eipsi/v1/'.$route);
 $r->set_param('post_id',$target);
 $r->set_param('formularios',array_map(fn($id)=>array('id'=>$id,'exists'=>true),$sources));
 $r->set_param('probabilidades',array($sources[0]=>100));
 $r->set_param('shortcodes_input',implode(' ',array_map(fn($id)=>'[eipsi_form id="'.$id.'"]',$sources)));
 return $r;
}
function sec0_http($user,$action,$data,$valid=true,$rest=false){
 global $credentials;
 wp_set_current_user($user); $_COOKIE=array(); $cookies=array();
 if($user){
  if(!isset($credentials[$user])){
   $token=WP_Session_Tokens::get_instance($user)->create(time()+3600);
   foreach(array(LOGGED_IN_COOKIE=>'logged_in',AUTH_COOKIE=>'auth')as$name=>$scheme)$credentials[$user][$name]=wp_generate_auth_cookie($user,time()+3600,$scheme,$token);
  }
  foreach($credentials[$user]as$name=>$value){$_COOKIE[$name]=$value;$cookies[]=new WP_Http_Cookie(array('name'=>$name,'value'=>$value));}
 }
 $nonce=$valid?wp_create_nonce($rest?'wp_rest':($action==='eipsi_save_randomization_config'?'eipsi_randomization_config':($action==='eipsi_random_assign'?'eipsi_random_nonce':'eipsi_admin_nonce'))):'invalid';
 $headers=array('Host'=>'127.0.0.1:18080');
 if($rest){$headers['X-WP-Nonce']=$nonce;$headers['Content-Type']='application/json';$body=wp_json_encode($data);$path='/?rest_route='.($rest===true?'/eipsi/v1/randomization-config':$rest);}
 else{$body=array_merge(array('action'=>$action,'nonce'=>$nonce),$data);$path='/wp-admin/admin-ajax.php';}
 $r=wp_remote_post('http://127.0.0.1'.$path,array('headers'=>$headers,'cookies'=>$cookies,'body'=>$body,'timeout'=>20));
 m0_assert(!is_wp_error($r),'HTTP transport');return array(wp_remote_retrieve_response_code($r),json_decode(wp_remote_retrieve_body($r),true));
}
try{
 foreach(array('author','author','subscriber','editor')as$i=>$role){$users[$i]=wp_insert_user(array('user_login'=>'sec0-'.$i.'-'.bin2hex(random_bytes(4)),'user_pass'=>wp_generate_password(),'role'=>$role));m0_assert(!is_wp_error($users[$i]),'User fixture');}
 [$author,$other,$subscriber,$editor]=$users;
 foreach(array('own'=>array('post','draft',$author),'foreign'=>array('page','private',1),'template'=>array('eipsi_form_template','draft',$author),'source'=>array('eipsi_form_template','private',$author),'secret'=>array('eipsi_form_template','private',$other),'public'=>array('eipsi_form_template','publish',$other),'trash'=>array('post','trash',$author),'wrong'=>array('attachment','inherit',$author))as$key=>$spec){$posts[$key]=wp_insert_post(array('post_type'=>$spec[0],'post_status'=>$spec[1],'post_author'=>$spec[2],'post_title'=>'SEC0 '.$key));m0_assert($posts[$key]>0,'Post fixture');}
 foreach(array('anonymous'=>array(0,'own',false),'subscriber'=>array($subscriber,'own',false),'author own'=>array($author,'own',true),'author foreign'=>array($author,'foreign',false),'author template'=>array($author,'template',true),'editor foreign page'=>array($editor,'foreign',true),'admin'=>array(1,'foreign',true),'missing'=>array($author,null,false),'wrong type'=>array($author,'wrong',false),'trashed'=>array($author,'trash',false))as$name=>$case){
  $tests['REST '.$name]=function()use($case,$posts){[$user,$key,$ok]=$case;wp_set_current_user($user);$before=sec0_state();$r=rest_do_request(sec0_request($key?$posts[$key]:999999999,array($posts['public'])));m0_assert($r->get_status()===($ok?200:403),'HTTP '.$r->get_status());if(!$ok)m0_assert(sec0_state()===$before,'Denied request mutated state');};
 }
 foreach(array('own'=>array('source'),'private foreign'=>array('secret'),'mixed'=>array('public','secret'),'public'=>array('public'))as$name=>$keys){foreach(array('randomization-config','randomization-detect')as$route){$tests[$route.' sources '.$name]=function()use($keys,$name,$posts,$author,$route){wp_set_current_user($author);$before=sec0_state();$r=rest_do_request(sec0_request($posts['own'],array_map(fn($k)=>$posts[$k],$keys),$route));$ok=in_array($name,array('own','public'),true);m0_assert($r->get_status()===($ok?200:403),'Source status '.$r->get_status());if(!$ok)m0_assert(sec0_state()===$before,'Source denial wrote');};}}
 foreach(array(true,false)as$rest){foreach(array(true,false)as$own){foreach(array(true,false)as$valid){$tests[($rest?'HTTP REST':'HTTP AJAX').' own='.intval($own).' nonce='.intval($valid)]=function()use($rest,$own,$valid,$posts,$author){$target=$posts[$own?'own':'foreign'];$before=sec0_state();[$status,$json]=sec0_http($author,'eipsi_save_randomization_config',array('post_id'=>$target,'formularios'=>array(array('id'=>$posts['public'],'exists'=>true)),'probabilidades'=>array($posts['public']=>100)),$valid,$rest);$ok=$own&&$valid;m0_assert($status===($ok?200:403),'HTTP status '.$status);if(!$ok)m0_assert(sec0_state()===$before,'HTTP denial mutated state');};}}}
 foreach(array(true,false)as$own){foreach(array(true,false)as$valid){$tests['Auth AJAX own='.intval($own).' nonce='.intval($valid)]=function()use($own,$valid,$posts,$author){$before=sec0_state();[$status,$json]=sec0_http($author,'eipsi_save_form_auth_config',array('template_id'=>$posts[$own?'template':'secret'],'require_login'=>1),$valid);m0_assert($status===($own&&$valid?200:403),'Auth status '.$status);if(!$own||!$valid)m0_assert(sec0_state()===$before,'Auth denial mutated state');};}}
 foreach(array('randomization-config','randomization-detect')as$route){$tests['Owner direct '.$route]=function()use($route,$author,$posts){wp_set_current_user($author);$before=sec0_state();$method=$route==='randomization-config'?'eipsi_randomization_config_rest_handler':'eipsi_randomization_detect_rest_handler';$r=EIPSI_Randomization_Config_Adapter::$method(sec0_request($posts['foreign'],array($posts['public']),$route));m0_assert(is_wp_error($r)&&$r->get_error_data()['status']===403,'Direct owner bypass');m0_assert(sec0_state()===$before,'Direct owner wrote');};}

 foreach(array('eipsi_random_assign','eipsi_send_reminder_manual','eipsi_get_anchored_timeline')as$action){
  $tests['Sibling author denied '.$action]=function()use($action,$author,$posts){$before=sec0_state();[$status,$json]=sec0_http($author,$action,array('form_id'=>$posts['secret'],'study_id'=>1,'participant_id'=>1,'email'=>'fixture@example.invalid'));m0_assert($status===403,'Sibling status '.$status);m0_assert(sec0_state()===$before,'Sibling denial mutated state');};
 }
 foreach(array($author,$editor,1)as$user){
  $tests['DB REST role '.$user]=function()use($user,$posts){$before=sec0_state();[$status,$json]=sec0_http($user,'',array('randomizationId'=>'rct_post_'.$posts['own'].'_eipsi','formularios'=>array(array('postId'=>$posts['public'],'porcentaje'=>100))),true,'/wp/v2/eipsi_randomization_config');m0_assert($status===($user===1?200:403),'DB REST status '.$status);if($user!==1)m0_assert(sec0_state()===$before,'DB REST denial wrote');};
 }
 $tests['DB owner direct denied']=function()use($author,$posts){wp_set_current_user($author);$before=sec0_state();$r=new WP_REST_Request('POST');$r->set_param('randomizationId','sec0-forbidden');$r->set_param('formularios',array(array('postId'=>$posts['public'],'porcentaje'=>100)));$result=EIPSI_Randomization_DB_Rest_Adapter::eipsi_rest_save_randomization_config($r);m0_assert(is_wp_error($result),'DB owner bypass');m0_assert(sec0_state()===$before,'DB owner wrote');};
 $tests['Timeline owner direct denied']=function()use($author){wp_set_current_user($author);$result=EIPSI_Longitudinal_T1_Anchor_Service::get_participant_anchored_timeline(1,1);m0_assert(!$result['success']&&strpos($result['error'],'permisos')!==false,'Timeline owner bypass');};
 $tests['Private template destination foreign']=function()use($author,$posts){wp_set_current_user($author);m0_assert(current_user_can('edit_post',$posts['secret']),'Historical CPT primitive shortcut changed');$before=sec0_state();$r=rest_do_request(sec0_request($posts['secret'],array($posts['public'])));m0_assert($r->get_status()===403,'CPT primitive bypass');m0_assert(sec0_state()===$before,'CPT denial wrote');};
 $tests['Uniform private source errors']=function()use($author,$posts){wp_set_current_user($author);$errors=array();foreach(array($posts['secret'],999999999,$posts['wrong'],$posts['trash'])as$id){$r=rest_do_request(sec0_request($posts['own'],array($id)));m0_assert($r->get_status()===403,'Source status');$errors[]=$r->get_data();}foreach($errors as$error)m0_assert($error===$errors[0],'Source error reveals existence');};

 foreach(array('private foreign'=>array('secret'),'mixed'=>array('public','secret'),'wrong type'=>array('wrong'),'missing'=>array(null))as$name=>$keys){
  $tests['AJAX sources '.$name]=function()use($name,$keys,$posts,$author){$before=sec0_state();$sources=array_map(fn($key)=>array('id'=>$key?$posts[$key]:999999999,'exists'=>true),$keys);[$status,$json]=sec0_http($author,'eipsi_save_randomization_config',array('post_id'=>$posts['own'],'formularios'=>$sources,'probabilidades'=>array($sources[0]['id']=>100)));m0_assert($status===403,'AJAX source status '.$status);m0_assert(sec0_state()===$before,'AJAX source denial mutated state');};
 }
 $tests['Auth AJAX wrong type']=function()use($author,$posts){$before=sec0_state();[$status]=sec0_http($author,'eipsi_save_form_auth_config',array('template_id'=>$posts['own'],'require_login'=>1));m0_assert($status===403,'Auth accepted ordinary post');m0_assert(sec0_state()===$before,'Auth wrong type wrote');};

 $tests['Zero ID cannot inherit global post']=function()use($author,$posts){wp_set_current_user($author);$previous=$GLOBALS['post']??null;$GLOBALS['post']=get_post($posts['template']);try{m0_assert(!EIPSI_Randomization_Resource_Policy::can_edit(0),'Zero destination inherited global post');m0_assert(!EIPSI_Randomization_Resource_Policy::can_use(0),'Zero source inherited global post');}finally{$GLOBALS['post']=$previous;}};
 $tests['Malformed ID cannot coerce authorized post']=function()use($author,$posts){wp_set_current_user($author);m0_assert(!EIPSI_Randomization_Resource_Policy::can_edit($posts['own'].'junk'),'Malformed destination accepted');m0_assert(!EIPSI_Randomization_Resource_Policy::can_use($posts['public'].'junk'),'Malformed source accepted');};

 $tests['Config read foreign denied']=function()use($author,$posts){$before=sec0_state();[$status]=sec0_http($author,'eipsi_get_randomization_config',array('form_id'=>$posts['secret']));m0_assert($status===403,'Config read status '.$status);m0_assert(sec0_state()===$before,'Read denial wrote');};
 $tests['Pages selector protects private sources']=function()use($author,$posts){wp_set_current_user(1);wp_update_post(array('ID'=>$posts['foreign'],'post_content'=>'[eipsi_randomized_form]'));update_post_meta($posts['foreign'],'_wp_page_template','custom.php');[$status,$json]=sec0_http($author,'eipsi_get_randomization_pages',array());m0_assert($status===200&&!in_array($posts['foreign'],array_column($json['data'],'id'),true),'Private page leaked');};
 $tests['Pages selector editor can read private pages']=function()use($editor,$posts){[$status,$json]=sec0_http($editor,'eipsi_get_randomization_pages',array());m0_assert($status===200&&in_array($posts['foreign'],array_column($json['data'],'id'),true),'Authorized private page missing');};
 $tests['Pages selector subscriber denied']=function()use($subscriber){$before=sec0_state();[$status]=sec0_http($subscriber,'eipsi_get_randomization_pages',array());m0_assert($status===403,'Subscriber read selector');m0_assert(sec0_state()===$before,'Subscriber denial wrote');};
 foreach($tests as$name=>$fn){try{$fn();echo 'PASS '.$name."\n";}catch(Throwable$e){$failed++;echo 'FAIL '.$name.': '.$e->getMessage()."\n";}}
}finally{
 wp_set_current_user(1);$_COOKIE=array();
 foreach($posts as$id){$wpdb->delete($wpdb->prefix.'eipsi_randomization_configs',array('randomization_id'=>'rct_post_'.$id.'_eipsi'));wp_delete_post($id,true);}
 require_once ABSPATH.'wp-admin/includes/user.php';foreach($users as$id)if(is_int($id))wp_delete_user($id);
}
echo count($tests).' tests, '.$failed." failures\n";ob_end_flush();exit($failed?1:0);
