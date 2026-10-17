<?php
if(!defined('ABSPATH')) exit;
require_once __DIR__.'/Definition.php';
require_once __DIR__.'/Repository.php';
require_once __DIR__.'/Admin.php';
add_action('wp_ajax_eipsi_assessment_editor',array('EIPSI\\AssessmentEditor\\Admin','ajax'));

add_action('admin_enqueue_scripts',array('EIPSI\\AssessmentEditor\\Admin','assets'));
