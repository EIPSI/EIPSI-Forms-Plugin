<?php
require __DIR__.'/m0/bootstrap.php';
use EIPSI\Scheduler\Config;
use EIPSI\Scheduler\Heartbeat;
use EIPSI\Scheduler\Diagnostics;
use EIPSI\Scheduler\Admin;
$tests=array();$failed=0;
function sch_assert($value,$message='SCH-1 assertion failed'){m0_assert($value,$message);}
function sch_reject(callable $fn,?int $code=null){try{$fn();}catch(Throwable$e){if($code!==null)sch_assert($e->getCode()===$code,'Unexpected rejection code');return;}throw new RuntimeException('Expected rejection');}
function sch_config(string $middle=''):string{return "<?php\ndefine('DB_NAME','fixture');\n".$middle."\nrequire_once ABSPATH . 'wp-settings.php';\n";}
function sch_child(bool $cron):void {
    $source=($cron?"define('DOING_CRON',true);":'')."require ".var_export(__DIR__.'/m0/bootstrap.php',true).";do_action('eipsi_process_nudge_jobs');ob_end_flush();";
    exec(escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($source),$output,$code);sch_assert($code===0,'Cron child failed');
    wp_cache_delete(Heartbeat::OPTION,'options');wp_cache_delete('notoptions','options');
}
$dir=sys_get_temp_dir().'/eipsi-sch1-'.bin2hex(random_bytes(8));mkdir($dir,0700);$path=$dir.'/wp-config.php';
$prior=get_option(Heartbeat::OPTION,null);$priorApi=get_option(EIPSI\ExternalApi\Operations::STATE_OPTION,null);$server=$_SERVER;
$fixtures=array(
    'missing constant'=>array(sch_config(),'absent',null),
    'foreign true'=>array(sch_config("define('DISABLE_WP_CRON',true);"),'external',true),
    'foreign false'=>array(sch_config("define ( \"DISABLE_WP_CRON\" , false );"),'external',false),
    'comments ignored'=>array(sch_config("// define('DISABLE_WP_CRON',true);\n/* DISABLE_WP_CRON */"),'absent',null),
    'owned true'=>array(sch_config(Config::block(true)),'eipsi',true),
    'owned false'=>array(sch_config(Config::block(false)),'eipsi',false),
    'dynamic env'=>array(sch_config("define('DISABLE_WP_CRON',getenv('CRON_OFF'));"),'external',null),
    'dynamic constant name'=>array(sch_config("define(\$name,true);"),'external',null),
    'Docker eval'=>array(sch_config("eval(getenv('WORDPRESS_CONFIG_EXTRA'));"),'external',null),
    'Bedrock include'=>array(sch_config("require dirname(__DIR__).'/config/application.php';"),'external',null),
    'duplicate definition'=>array(sch_config(Config::block(true)."\ndefine('DISABLE_WP_CRON',false);"),'external',null),
    'altered owned block'=>array(sch_config(str_replace("true","getenv('CRON_OFF')",Config::block(true))),'external',null),
    'conditional owned block'=>array(sch_config("if(false){\n".Config::block(true)."\n}"),'external',true),
    'conditional require'=>array("<?php if(false) require_once ABSPATH . 'wp-settings.php';",'external',null),
    'malformed PHP'=>array("<?php define( ;",'external',null),
    'no settings anchor'=>array("<?php define('DB_NAME','fixture');",'external',null),
    'quoted comment marker'=>array(sch_config("/* ".Config::block(true)." */"),'external',null)
);
foreach($fixtures as$name=>$fixture)$tests['Config parser: '.$name]=static function()use($fixture){$r=Config::parse($fixture[0]);sch_assert($r['ownership']===$fixture[1],$r['ownership'].' / '.$r['reason']);if($fixture[2]!==null)sch_assert($r['value']===$fixture[2]);};
$tests['Atomic create and reversible owned toggle preserve all unrelated content']=function()use($path){
    $old=sch_config("// unrelated settings\ndefine('WP_DEBUG',false);");file_put_contents($path,$old);chmod($path,0640);
    $hash=hash_file('sha256',$path);Config::write($path,true,$hash);$new=file_get_contents($path);sch_assert(Config::parse($new)['value']===true);sch_assert(str_replace(Config::block(true)."\n",'',$new)===$old);sch_assert((fileperms($path)&0777)===0640);
    Config::write($path,false,hash_file('sha256',$path));sch_assert(Config::parse(file_get_contents($path))['value']===false);
    Config::write($path,true,hash_file('sha256',$path));sch_assert(substr_count(file_get_contents($path),Config::BEGIN)===1);
};
$tests['Atomic short write keeps original and removes temporary']=function()use($path,$dir){
    file_put_contents($path,sch_config());$old=file_get_contents($path);
    sch_reject(fn()=>Config::write($path,true,hash_file('sha256',$path),fn($h,$content)=>fwrite($h,substr($content,0,12))),409);
    sch_assert(file_get_contents($path)===$old);sch_assert(!glob($dir.'/.eipsi-config-*'));
};
$tests['Stale expected hash keeps original']=function()use($path){file_put_contents($path,sch_config());$old=file_get_contents($path);sch_reject(fn()=>Config::write($path,true,str_repeat('0',64)),409);sch_assert(file_get_contents($path)===$old);};
$tests['Foreign literal never overwritten']=function()use($path){$old=sch_config("define('DISABLE_WP_CRON',false);");file_put_contents($path,$old);sch_reject(fn()=>Config::write($path,true,hash_file('sha256',$path)),409);sch_assert(file_get_contents($path)===$old);};
$tests['Read-only mode provides fallback even under root tests']=function()use($path){file_put_contents($path,sch_config());chmod($path,0444);clearstatcache(true,$path);sch_assert(!Config::inspect($path)['writable']);sch_reject(fn()=>Config::write($path,true,hash_file('sha256',$path)),409);chmod($path,0640);};
$tests['Symlink config is not replaced']=function()use($path,$dir){$link=$dir.'/linked.php';symlink($path,$link);sch_assert(!Config::inspect($link)['writable']);sch_reject(fn()=>Config::write($link,true,hash_file('sha256',$path)),409);unlink($link);};
$tests['Concurrent replacement during staging preserves other writer']=function()use($path){
    file_put_contents($path,sch_config());$other=sch_config("// concurrent writer");
    sch_reject(fn()=>Config::write($path,true,hash_file('sha256',$path),function($h,$new)use($path,$other){file_put_contents($path,$other);return fwrite($h,$new);}),409);
    sch_assert(file_get_contents($path)===$other);
};
$tests['Normal WordPress load and manual maintenance never produce cron heartbeat']=function(){
    delete_option(Heartbeat::OPTION);sch_child(false);sch_assert(!get_option(Heartbeat::OPTION));
};
$tests['Real cron path records started completed result and external evidence']=function(){
    update_option(Heartbeat::OPTION,array('last_external_seen_at'=>time()-300),false);sch_child(true);
    $h=Heartbeat::state();sch_assert($h['last_scheduler_seen_at']>=time()-10);sch_assert($h['last_maintenance_started_at']>=time()-10);sch_assert($h['last_maintenance_completed_at']>=time()-10);sch_assert($h['result']==='COMPLETED');sch_assert(Heartbeat::health(true,$h,time())['external_confirmed']);
};
$tests['Verify is read-only and cannot manufacture external evidence']=function(){
    delete_option(Heartbeat::OPTION);$_SERVER['REQUEST_METHOD']='POST';unset($_SERVER['HTTP_ORIGIN']);
    $d=Admin::dispatch(array('operation'=>'verify','nonce'=>wp_create_nonce('eipsi_scheduler')));
    sch_assert(!$d['health']['external_confirmed']);sch_assert(!get_option(Heartbeat::OPTION));
};
$tests['One cron execution does not establish regularity']=function(){$h=Heartbeat::health(true,array('last_external_seen_at'=>1000,'last_scheduler_seen_at'=>1000),1100);sch_assert(!$h['external_confirmed']&&$h['state']==='WARNING');};
$tests['Disabled with stale heartbeat warns']=function(){$h=Heartbeat::health(true,array('last_external_seen_at'=>1000,'previous_external_seen_at'=>700,'last_scheduler_seen_at'=>1000),2000);sch_assert(!$h['external_confirmed']&&$h['state']==='WARNING');};
$tests['Disabled with two recent independent cron observations is healthy']=function(){$h=Heartbeat::health(true,array('last_external_seen_at'=>1000,'previous_external_seen_at'=>700,'last_scheduler_seen_at'=>1000,'result'=>'COMPLETED'),1100);sch_assert($h['external_confirmed']&&$h['state']==='OK');};
$tests['Enabled without any observation is not an immediate error']=function(){$h=Heartbeat::health(false,array(),1100);sch_assert(!$h['external_confirmed']&&$h['state']==='OK');};
$tests['Future timestamps and rapid repeated requests do not confirm regularity']=function(){
    foreach(array(array('last_external_seen_at'=>1200,'previous_external_seen_at'=>900),array('last_external_seen_at'=>1000,'previous_external_seen_at'=>999))as$s)sch_assert(!Heartbeat::health(true,$s,1100)['external_confirmed']);
};
$tests['Failure result warns despite detected external cron']=function(){$h=Heartbeat::health(true,array('last_external_seen_at'=>1000,'previous_external_seen_at'=>700,'result'=>'FAILED'),1100);sch_assert($h['state']==='WARNING');};
$tests['Overdue owned events counted without running callbacks or unrelated events']=function(){
    $prior=_get_cron_array();$ran=false;$cb=function()use(&$ran){$ran=true;};add_action('eipsi_send_manual_reminder',$cb);
    try {wp_schedule_single_event(time()-600,'eipsi_send_manual_reminder',array(987654));$d=Diagnostics::inspect();sch_assert($d['overdue']>=1&&!$ran);sch_assert(count($d['events'])<=20);}
    finally{_set_cron_array($prior);remove_action('eipsi_send_manual_reminder',$cb);}
};
$tests['Capability required']=function(){wp_set_current_user(0);try{sch_reject(fn()=>Admin::dispatch(array()),403);}finally{wp_set_current_user(1);}};
$tests['POST required']=function(){$_SERVER['REQUEST_METHOD']='GET';sch_reject(fn()=>Admin::dispatch(array()),405);};
$tests['Same origin required']=function(){$_SERVER['REQUEST_METHOD']='POST';$_SERVER['HTTP_ORIGIN']='https://foreign.invalid';sch_reject(fn()=>Admin::dispatch(array()),403);unset($_SERVER['HTTP_ORIGIN']);};
$tests['Nonce required']=function(){$_SERVER['REQUEST_METHOD']='POST';sch_reject(fn()=>Admin::dispatch(array('operation'=>'verify','nonce'=>'invalid')),403);};
$tests['Disable requires explicit confirmation']=function(){sch_reject(fn()=>Admin::dispatch(array('operation'=>'toggle','disabled'=>true,'expected'=>str_repeat('0',64),'nonce'=>wp_create_nonce('eipsi_scheduler'))),400);};
$tests['Unknown operation and string boolean rejected']=function(){
    $nonce=wp_create_nonce('eipsi_scheduler');sch_reject(fn()=>Admin::dispatch(array('operation'=>'run-cron','nonce'=>$nonce)),400);
    sch_reject(fn()=>Admin::dispatch(array('operation'=>'toggle','disabled'=>'true','expected'=>str_repeat('0',64),'nonce'=>$nonce)),400);
};
$tests['Real env-driven Docker config remains externally managed']=function(){
    $before=hash_file('sha256',Config::locate());sch_assert(Config::inspect()['ownership']==='external');
    sch_reject(fn()=>Admin::dispatch(array('operation'=>'toggle','disabled'=>false,'expected'=>$before,'nonce'=>wp_create_nonce('eipsi_scheduler'))),409);
    sch_assert(hash_file('sha256',Config::locate())===$before);
};
$tests['UI includes distinct controls instructions and disabled confirmation without API enablement']=function(){
    $before=get_option(EIPSI\ExternalApi\Store::ENABLED,null);update_option(EIPSI\ExternalApi\Store::ENABLED,false,false);ob_start();Admin::render();$html=ob_get_clean();
    if($before===null)delete_option(EIPSI\ExternalApi\Store::ENABLED);else update_option(EIPSI\ExternalApi\Store::ENABLED,$before,false);
    foreach(array(eipsi_admin_translate('Scheduler'),eipsi_admin_translate('Visit-triggered WP-Cron'),eipsi_admin_translate('External execution detected'),eipsi_admin_translate('Verify external cron'),'data-sch-confirmed','cPanel','Plesk','SSH / crontab','WP-CLI',eipsi_admin_translate('External service (HTTP)'),'data-sch-copy')as$fragment)sch_assert(strpos($html,$fragment)!==false,'Missing UI: '.$fragment);
    sch_assert(substr_count($html,'data-sch-tab="')===3&&substr_count($html,'data-sch-panel="')===3,'Expected three internal tabs and panels');
    foreach(array('status','activity','setup')as$id)sch_assert(strpos($html,'aria-labelledby="sch-tab-'.$id.'"')!==false,'Missing accessible tab association');
    sch_assert(strpos($html,'m0-isolated')===false,'Config credentials exposed');
};
$tests['Commands quote installation paths and contain no secrets']=function(){
    $commands=Diagnostics::commands();sch_assert(isset($commands['PHP CLI']));sch_assert(strpos($commands['PHP CLI'],escapeshellarg(realpath(ABSPATH).'/wp-cron.php'))!==false);
    foreach($commands as$c){sch_assert(strpos($c,'m0-isolated')===false);sch_assert(strpos($c,'Bearer')===false);}
    sch_assert(Diagnostics::executable('arbitrary;echo')===null);
};
$tests['All nineteen existing owners observed with no extra scheduled hook']=function(){
    sch_assert(count(EIPSI_Cron_Registry::owners())===19);
    foreach(EIPSI_Cron_Registry::owners()as$hook=>$owner){sch_assert(has_action($hook,array(Heartbeat::class,'begin'))===PHP_INT_MIN);sch_assert(has_action($hook,array(Heartbeat::class,'end'))===PHP_INT_MAX);}
    foreach((_get_cron_array()?:array())as$hooks)foreach(array_keys($hooks)as$hook)sch_assert(strpos($hook,'eipsi_scheduler')!==0);
};
$tests['API health reuses the same scheduler decision']=function(){
    $a=EIPSI\ExternalApi\ExternalApiHealth::inspect();$d=Diagnostics::inspect();sch_assert($a['checks']['cron']==array('state'=>$d['health']['state'],'message'=>Admin::healthMessage($d)));
};
$tests['Multisite cannot change installation-wide config from site settings']=function(){
    exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/sch1/multisite-boundary.php'),$out,$code);sch_assert($code===0);
};
try{foreach($tests as$name=>$test){try{$test();echo 'PASS '.$name."\n";}catch(Throwable$e){$failed++;echo 'FAIL '.$name.': '.$e->getMessage().' @'.basename($e->getFile()).':'.$e->getLine()."\n";}}}
finally{
    $_SERVER=$server;wp_set_current_user(1);if($prior===null)delete_option(Heartbeat::OPTION);else update_option(Heartbeat::OPTION,$prior,false);
    if($priorApi===null)delete_option(EIPSI\ExternalApi\Operations::STATE_OPTION);else update_option(EIPSI\ExternalApi\Operations::STATE_OPTION,$priorApi,false);
    foreach(glob($dir.'/*')as$f)unlink($f);rmdir($dir);
}
echo count($tests).' tests, '.$failed." failures\n";ob_end_flush();exit($failed?1:0);
