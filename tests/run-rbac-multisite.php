<?php
if(PHP_SAPI!=='cli'||getenv('WORDPRESS_DB_NAME')!=='m0'||getenv('WORDPRESS_DB_HOST')!=='eipsi-m0-db:3306')exit(1);
// Network tables are prepared by a separate process; public WP configuration remains untouched.
if(!defined('MULTISITE'))define('MULTISITE',true);
if(!defined('SUBDOMAIN_INSTALL'))define('SUBDOMAIN_INSTALL',false);
define('DOMAIN_CURRENT_SITE','127.0.0.1:18080');define('PATH_CURRENT_SITE','/');define('SITE_ID_CURRENT_SITE',1);define('BLOG_ID_CURRENT_SITE',1);
define('DOING_CRON',true);require __DIR__.'/m0/bootstrap.php';
use EIPSI_Admin_Authorization_Policy as A;
$n=0;$failed=0;
function rm_case($name,$test){global$n,$failed;$n++;try{$test();echo "PASS $name\n";}catch(Throwable$e){$failed++;echo "FAIL $name: ".$e->getMessage()."\n";}}
rm_case('Real multisite bootstrap and super administrator capabilities',function(){m0_assert(is_multisite()&&is_super_admin(1),'Network super admin missing');wp_set_current_user(1);foreach(A::actions()as$action=>$cap)m0_assert(A::allows($action),$action);});
$site=wp_insert_site(array('domain'=>'127.0.0.1:18080','path'=>'/rbac-site/','network_id'=>1,'title'=>'RBAC site','user_id'=>1));if(is_wp_error($site))throw new RuntimeException($site->get_error_code());
rm_case('New site receives its own versioned roles after initialization',function()use($site){switch_to_blog($site);try{m0_assert((int)get_option('eipsi_rbac_version')===A::VERSION,'Version missing');foreach(A::roleActions()as$slug=>$actions)m0_assert(get_role($slug)!==null,$slug);}finally{restore_current_blog();}});
$uid=wp_insert_user(array('user_login'=>'rb_ms_'.bin2hex(random_bytes(4)),'user_pass'=>wp_generate_password(40),'role'=>'subscriber'));if(is_wp_error($uid))throw new RuntimeException($uid->get_error_code());add_user_to_blog($site,$uid,'administrator');
rm_case('Site administrator has EIPSI access without becoming super admin',function()use($site,$uid){switch_to_blog($site);try{wp_set_current_user($uid);m0_assert(!is_super_admin($uid),'Unexpected network privilege');foreach(A::actions()as$action=>$cap)m0_assert(A::allows($action),$action);}finally{wp_set_current_user(1);restore_current_blog();}});
rm_case('Capabilities are scoped to site and restored on switch',function()use($site,$uid){wp_set_current_user($uid);m0_assert(!A::allows('access'),'Main site subscriber gained access');switch_to_blog($site);try{m0_assert(A::allows('studies.edit'),'Subsite admin access missing');}finally{restore_current_blog();}m0_assert(!A::allows('access'),'Subsite capability leaked');wp_set_current_user(1);});
rm_case('Multisite scheduler still rejects shared wp-config mutation',function(){wp_set_current_user(1);$GLOBALS['_SERVER']['REQUEST_METHOD']='POST';$_SERVER['HTTP_ORIGIN']=admin_url();$request=array('operation'=>'toggle','disabled'=>true,'expected'=>str_repeat('a',64),'confirmed'=>true,'nonce'=>wp_create_nonce('eipsi_scheduler'));try{EIPSI\Scheduler\Admin::dispatch($request);}catch(Throwable$e){m0_assert($e->getCode()===403,'Wrong rejection '.$e->getCode());return;}throw new RuntimeException('Shared configuration write allowed');});
wp_set_current_user(1);echo "$n tests, $failed failures\n";ob_end_flush();exit($failed?1:0);
