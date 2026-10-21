<?php
/** A second isolated DB connection; no production endpoint or mail. */
define('DOING_CRON',true);require dirname(__DIR__,2).'/m0/bootstrap.php';require_once EIPSI_FORMS_PLUGIN_DIR.'includes/study-engine/builder.php';
$dir=$argv[1]??'';if(!preg_match('#^/tmp/eipsi-management-race-[a-f0-9]+$#D',$dir))exit(2);$input=json_decode(file_get_contents($dir.'/input.json'),true);
try{$q=new EIPSI\StudyEngine\Management\Query($input['study']);$x=new EIPSI\StudyEngine\Management\Exports($q);touch($dir.'/attempt');$x->download($input['job'],'json');$result=array('download'=>'ACCEPTED');}catch(Throwable $e){$result=array('error'=>$e->getMessage());}file_put_contents($dir.'/result.tmp',json_encode($result));rename($dir.'/result.tmp',$dir.'/result.json');
