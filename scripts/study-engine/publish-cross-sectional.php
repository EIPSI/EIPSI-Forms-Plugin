<?php
/** wp eval-file ... [template-id] [logical-study-id] [public-id] [version-number] */
if (!defined('WP_CLI') || !WP_CLI) { throw new RuntimeException('Use WP-CLI eval-file with an administrator-controlled template.'); }
require_once dirname(__DIR__,2).'/includes/study-engine/content.php';
$args=$args??array();
if(count($args)!==4){WP_CLI::error('Arguments: template-id logical-study-id public-id version-number');}
$template=(int)$args[0];$number=(int)$args[3];
$db=new \EIPSI\StudyEngine\Persistence\Database($GLOBALS['wpdb']);
$definition=(new \EIPSI\StudyEngine\Content\TemplateAdapter())->fromTemplate($template,$args[1],$args[2],$number);
$versions=new \EIPSI\StudyEngine\Persistence\StudyVersionRepository($db);
$draft=$versions->draft($definition);$version=$versions->publish($definition);
$public=(new \EIPSI\StudyEngine\Runtime\PublicStudyRepository($db))->activate($version,'OPEN',false);
WP_CLI::success('Published '.$version);WP_CLI::line(add_query_arg('eipsi_study',$public,home_url('/')));
