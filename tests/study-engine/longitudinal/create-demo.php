<?php
/** Opt-in fixtures for local manual review, never run by ordinary activation/requests. */
if(!defined('WP_CLI')||!WP_CLI)throw new RuntimeException('WP_CLI_REQUIRED');
require_once dirname(__DIR__,3).'/includes/study-engine/longitudinal.php';
use EIPSI\StudyEngine\Persistence as P;
use EIPSI\StudyEngine\Longitudinal as L;
use EIPSI\StudyEngine\Contracts\Canonical;
use EIPSI\StudyEngine\Runtime\PublicStudyRepository;
$names=array('prepost'=>'03','daily'=>'04','ema'=>'05','rct'=>'06','wait'=>'08');$name=$args[0]??'prepost';if(!isset($names[$name]))throw new InvalidArgumentException('prepost daily ema rct wait');
$files=glob(dirname(__DIR__).'/fixtures/valid/'.$names[$name].'*.json');$d=json_decode(file_get_contents($files[0]),true);$suffix=bin2hex(random_bytes(6));$d['study']=array('logical_id'=>'DEMO_F_'.$suffix,'public_id'=>'DEMO_PUBLIC_F_'.$suffix);
foreach($d['nodes'] as &$n)if($n['kind']==='QUESTION'){$n['content']['snapshot']=Canonical::json(array('label'=>$n['id'],'helper'=>'','format'=>'text','widget'=>'eipsi/campo-texto','step'=>null));$n['content']['sha256']=hash('sha256',$n['content']['snapshot']);}unset($n);
$d['protocol_metadata']['content_adapter']='P1E_V1';$d['protocol_metadata']['storage_mode']='WORDPRESS_LOCAL';$d['protocol_metadata']['allow_back']=true;$d=L\Configuration::apply($d);
global $wpdb;$db=new P\Database($wpdb);L\Capability::configured($db);$v=(new P\StudyVersionRepository($db))->publish($d);$public=(new PublicStudyRepository($db))->activate($v);
echo wp_json_encode(array('fixture'=>$name,'study_version_id'=>$v,'url'=>add_query_arg('eipsi_study',$public,home_url('/'))),JSON_PRETTY_PRINT)."\n";
