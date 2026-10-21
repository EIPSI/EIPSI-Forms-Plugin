<?php
/** SCH-1 additions validated independently of the preserved historical boundaries. */
function sch1_hook($row) {
    $cb=$row['callback']??'';
    if(!in_array($cb,array('EIPSI\\Scheduler\\Heartbeat::begin','EIPSI\\Scheduler\\Heartbeat::end','EIPSI\\Scheduler\\Admin::ajax'),true))return false;
    if($cb==='EIPSI\\Scheduler\\Admin::ajax') {
        m0_assert($row['hook']==='wp_ajax_eipsi_scheduler'&&$row['priority']===10&&$row['accepted_args']===1,'SCH1 admin boundary');
    } else {
        m0_assert(isset(EIPSI_Cron_Registry::owners()[$row['hook']])&&$row['priority']===($cb==='EIPSI\\Scheduler\\Heartbeat::begin'?PHP_INT_MIN:PHP_INT_MAX)&&$row['accepted_args']===0,'SCH1 observer boundary');
    }
    return true;
}
function sch1_include($file) {
    return in_array($file,array_map(static function($name){return 'includes/scheduler/'.$name.'.php';},array('bootstrap','Config','Heartbeat','Diagnostics','Admin')),true);
}
