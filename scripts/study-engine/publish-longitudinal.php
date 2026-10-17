<?php
/** Internal explicit publishing API; WP-CLI only, never a public endpoint. */
if(!defined('WP_CLI')||!WP_CLI)throw new RuntimeException('WP_CLI_REQUIRED');
require_once dirname(__DIR__,2).'/includes/study-engine/longitudinal.php';
use EIPSI\StudyEngine\Persistence as P;
use EIPSI\StudyEngine\Longitudinal as L;
use EIPSI\StudyEngine\Content\TemplateAdapter;
use EIPSI\StudyEngine\Runtime\PublicStudyRepository;
if(count($args??array())!==5)throw new InvalidArgumentException('template_id study_id public_id version_number reminder_minutes');
if(!ctype_digit((string)$args[0])||!ctype_digit((string)$args[3]))throw new InvalidArgumentException('INTEGER_IDS_REQUIRED');
global $wpdb;$db=new P\Database($wpdb);L\Capability::configured($db);
$d=(new TemplateAdapter())->fromTemplate((int)$args[0],$args[1],$args[2],(int)$args[3]);
$d=L\Configuration::apply($d,array('reminder_offsets'=>L\Configuration::minutes($args[4])));
$v=(new P\StudyVersionRepository($db))->publish($d);$public=(new PublicStudyRepository($db))->activate($v);
echo wp_json_encode(array('study_version_id'=>$v,'enrollment_mode'=>'EMAIL_LONGITUDINAL','url'=>add_query_arg('eipsi_study',$public,home_url('/'))),JSON_PRETTY_PRINT)."\n";
