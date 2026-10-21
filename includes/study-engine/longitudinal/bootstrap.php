<?php
if(!defined('ABSPATH'))exit;
require_once dirname(__DIR__).'/longitudinal.php';
// Existing event is already scheduled/owned/unscheduled by the central Cron Registry.
add_action('eipsi_process_nudge_jobs',array('EIPSI\\StudyEngine\\Longitudinal\\Worker','tick'),9);
