<?php
// Included only by a temporary wp-config in a new isolated Docker fixture.
if(getenv('WORDPRESS_DB_HOST')!=='eipsi-m0-db:3306'||getenv('WORDPRESS_DB_NAME')!=='m0')return;
if(!defined('SAVEQUERIES'))define('SAVEQUERIES',true);
$GLOBALS['ux_f0_start']=hrtime(true);

function ux_f0_db_snapshot(){
 global $wpdb;static $observer;if(!isset($wpdb->dbh))return null;
 if(!$observer)$observer=new mysqli('eipsi-m0-db',DB_USER,DB_PASSWORD,'performance_schema',3306);
 $thread=(int)mysqli_thread_id($wpdb->dbh);$rows=$observer->query('SELECT EVENT_NAME,COUNT_STAR,SUM_TIMER_WAIT FROM performance_schema.events_statements_summary_by_thread_by_event_name WHERE THREAD_ID=(SELECT THREAD_ID FROM performance_schema.threads WHERE PROCESSLIST_ID='.$thread.') AND COUNT_STAR>0')->fetch_all(MYSQLI_ASSOC);$count=0;$ms=0;$classes=array();foreach($rows as $row){$count+=(int)$row['COUNT_STAR'];$ms+=(float)$row['SUM_TIMER_WAIT']/1000000000;$classes[$row['EVENT_NAME']]=(int)$row['COUNT_STAR'];}return array('count'=>$count,'ms'=>$ms,'classes'=>$classes);
}
if(PHP_SAPI==='cli')return;
$scenario=$_SERVER['HTTP_X_UX_F0_SCENARIO']??'';$repeat=$_SERVER['HTTP_X_UX_F0_REPETITION']??'';
if(!preg_match('/^P[0-9]{1,2}(?:-[a-z]+)?$/D',$scenario)||!preg_match('/^(?:warmup|[0-9]{1,3})$/D',$repeat))return;
ob_start(function($body)use($scenario,$repeat){
 global $wpdb;$end=hrtime(true);$queries=$wpdb->queries??array();$db=0;$counts=array();$classes=array();$calls=array();$slow=0;
 foreach($queries as $q){$db+=$q[1]*1000;$slow=max($slow,$q[1]*1000);$hash=hash('sha256',$q[0]);$counts[$hash]=($counts[$hash]??0)+1;$type=strtoupper(strtok(ltrim($q[0])," \t\r\n"));if(!in_array($type,array('SELECT','INSERT','UPDATE','DELETE','START','COMMIT','ROLLBACK','SET','SHOW'),true))$type='OTHER';$classes[$type]=($classes[$type]??0)+1;
 // Retain only known plugin method names, never SQL, arguments, identifiers or values.
 foreach(explode(', ',$q[2]??'') as $caller)if(strpos($caller,'EIPSI\\')===0||strpos($caller,'EIPSI_')===0){$calls[$caller]=($calls[$caller]??0)+1;break;}}
 $pfs=ux_f0_db_snapshot();$record=array('pfs'=>$pfs,'wpdb_num_queries'=>(int)$wpdb->num_queries,'scenario'=>$scenario,'repetition'=>$repeat,'php_ms'=>($end-$GLOBALS['ux_f0_start'])/1e6,'bootstrap_ms'=>isset($GLOBALS['ux_f0_route'])?($GLOBALS['ux_f0_route']-$GLOBALS['ux_f0_start'])/1e6:null,'query_count'=>count($queries),'db_ms'=>$db,'duplicate_exact_queries'=>array_sum(array_map(static function($n){return max(0,$n-1);},$counts)),'slowest_query_ms'=>$slow,'query_classes'=>$classes,'query_callers'=>$calls,'peak_bytes'=>memory_get_peak_usage(true),'response_bytes'=>strlen($body));
 file_put_contents('/tmp/ux-f0-server.jsonl',json_encode($record)."\n",FILE_APPEND|LOCK_EX);return $body;
});
