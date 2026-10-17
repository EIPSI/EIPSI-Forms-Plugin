<?php
if(!defined('ABSPATH'))exit;
require_once dirname(__DIR__).'/builder.php';
if(wp_doing_ajax()&&($_REQUEST['action']??'')==='eipsi_study_builder')add_action('wp_ajax_eipsi_study_builder',array('EIPSI\\StudyEngine\\Builder\\Admin','ajax'));

if(isset($_GET['eipsi_builder_source'])&&$_GET['eipsi_builder_source']==='1')add_action('enqueue_block_editor_assets',array('EIPSI\\StudyEngine\\Builder\\Admin','editorAssets'));

if(is_admin()&&in_array($_GET['page']??'',array('eipsi-study-builder','eipsi-email-templates'),true))add_action('admin_enqueue_scripts',array('EIPSI\\StudyEngine\\Builder\\Admin','assets'));
