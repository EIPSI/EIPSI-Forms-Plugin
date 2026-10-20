<?php
if(!defined('ABSPATH'))exit;
require_once __DIR__.'/Transport.php';
add_action('wp_ajax_eipsi_native_participant',array('EIPSI\\AssessmentEditor\\Runtime\\Transport','ajax'));
add_action('wp_ajax_nopriv_eipsi_native_participant',array('EIPSI\\AssessmentEditor\\Runtime\\Transport','ajax'));
if(isset($_GET['eipsi_native']))add_action('template_redirect',array('EIPSI\\AssessmentEditor\\Runtime\\Transport','page'),1);

if(isset($_GET['eipsi_native_asset']))add_action('template_redirect',array('EIPSI\\AssessmentEditor\\Runtime\\PrivateMedia','page'),0);
