<?php
/** Deliberate local/admin fixture: wp eval-file this-file.php branch restart */
if(!defined('WP_CLI')||!WP_CLI)throw new RuntimeException('WP-CLI administrator context required');
require_once dirname(__DIR__,3).'/includes/study-engine/content.php';
require_once __DIR__.'/support.php';
$variant=$args[0]??'branch';if(!in_array($variant,array('simple','pages','branch','consent','random'),true))WP_CLI::error('Variant: simple, pages, branch, consent, random');
$restart=($args[1]??null)==='restart';
$template=wp_insert_post(array('post_type'=>'eipsi_form_template','post_title'=>'Study Engine demo '.$variant,'post_status'=>'publish','post_content'=>serialize_blocks(pe_blocks($variant))));
if(is_wp_error($template)||!$template)WP_CLI::error('Cannot create demo template');
$definition=pe_definition($variant);$options=array('condition_rules'=>$definition['condition_rules'],'random_groups'=>array());
foreach($definition['nodes'] as $n)if($n['kind']==='BLOCK'&&$n['randomization']!==null)$options['random_groups'][$n['id']]=$n['randomization'];
$adapter=new \EIPSI\StudyEngine\Content\TemplateAdapter();$definition=$adapter->fromTemplate($template,$definition['study']['logical_id'],$definition['study']['public_id'],1,$options);
$db=new \EIPSI\StudyEngine\Persistence\Database($GLOBALS['wpdb']);$versions=new \EIPSI\StudyEngine\Persistence\StudyVersionRepository($db);$versions->draft($definition);$version=$versions->publish($definition);
$public=(new \EIPSI\StudyEngine\Runtime\PublicStudyRepository($db))->activate($version,'OPEN',$restart);
WP_CLI::success('Demo published from template '.$template);WP_CLI::line('Version: '.$version);WP_CLI::line(add_query_arg('eipsi_study',$public,home_url('/')));
