<?php
if(!defined('ABSPATH'))exit;
require_once dirname(__DIR__).'/study-engine/configuration/ServerKeyrings.php';
require_once __DIR__.'/SetupStatus.php';
require_once __DIR__.'/SetupAdmin.php';
add_action('admin_post_eipsi_setup_keys',array('EIPSI\Setup\SetupAdmin','post'));
add_action('admin_notices',array('EIPSI\Setup\SetupAdmin','notice'),20);
