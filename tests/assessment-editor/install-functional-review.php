<?php
/** Explicit, idempotent local-only disposable authoring fixtures. Never publish or send mail. */
if(PHP_SAPI!=='cli')exit(1);
if(!defined('DISABLE_WP_CRON'))define('DISABLE_WP_CRON',true);
require dirname(__DIR__,5).'/wp-load.php';
if(!in_array(wp_parse_url(home_url(),PHP_URL_HOST),array('localhost','127.0.0.1'),true))throw new RuntimeException('Local WordPress required');
add_filter('pre_wp_mail',static function(){return false;},PHP_INT_MAX);
wp_set_current_user(1);
if(!current_user_can('manage_options'))throw new RuntimeException('Local administrator required');
require_once dirname(__DIR__,2).'/includes/assessment-editor/Definition.php';
require_once dirname(__DIR__,2).'/includes/assessment-editor/Repository.php';
use EIPSI\AssessmentEditor\Repository as R;
use EIPSI\AssessmentEditor\Definition as D;
$d=json_decode(file_get_contents(__DIR__.'/functional-fixture.json'),true);$d['id']='AE_FUNCTIONAL_REVIEW_20261009';$d['title']='DESCARTABLE · Functional Completion · revisión humana';$d=D::validate($d);
try{$record=R::load($d['id']);$created=false;}catch(Throwable $e){if($e->getCode()!==404)throw $e;$record=R::save($d,0);$created=true;}
echo wp_json_encode(array('native_id'=>$d['id'],'native_created'=>$created,'native_revision'=>$record['revision'],'emails'=>'blocked','published'=>false),JSON_PRETTY_PRINT)."\n";
