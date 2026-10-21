<?php
if(!defined('ABSPATH'))exit;
foreach(array('Config','Heartbeat','Diagnostics','Admin')as$file)require_once __DIR__.'/'.$file.'.php';
unset($file);
foreach(array_keys(EIPSI_Cron_Registry::owners())as$eipsi_scheduler_hook) {
    add_action($eipsi_scheduler_hook,array('EIPSI\Scheduler\Heartbeat','begin'),PHP_INT_MIN,0);
    add_action($eipsi_scheduler_hook,array('EIPSI\Scheduler\Heartbeat','end'),PHP_INT_MAX,0);
}
unset($eipsi_scheduler_hook);
add_action('wp_ajax_eipsi_scheduler',array('EIPSI\Scheduler\Admin','ajax'));
