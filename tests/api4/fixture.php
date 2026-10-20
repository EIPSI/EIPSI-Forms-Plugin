<?php
/** Synthetic fixture shared by disposable CI and API-4's isolated human project. */
function a4_fixture(): array {
 $GLOBALS['pf_now']=gmdate('Y-m-d\TH:i:s\Z');
 $p=d2_publish();
 for($i=0;$i<4;$i++)d2_finish($p,$i===0?"=CSV_FORMULA_TEST á\nsegunda línea":"INCLUDED_FINAL_".$i);
 $d=pe_definition('simple',$p['definition']['study']['logical_id'],$p['public'],2);$v2=d2_publish(false,$d);for($i=0;$i<4;$i++)d2_finish($v2,'INCLUDED_FINAL_V2_'.$i);
 $long=d2_publish(true);$yes=d2_finish($long,'INCLUDED_FINAL');$no=d2_finish($long,'EXCLUDED_DO_NOT_USE');$ret=d2_finish($long,'INCLUDED_RETAINED');d2_withdraw($no['frame'],'DO_NOT_USE');d2_withdraw($ret['frame'],'RETAIN_FOR_ANALYSIS');
 $f=pf_enroll($long);$block=$f['view']['next_action']['payload']['assessment_execution_id'];$graph=(new EIPSI\StudyEngine\Persistence\StudyVersionRepository(pf_db()))->compiled((new EIPSI\StudyEngine\Persistence\StudyVersionRepository(pf_db()))->find($long['version']));$answers=(new EIPSI\StudyEngine\Content\ResponseAdapter())->decode($long['definition'],array('R_q1'=>'INCLUDED_PARTIAL_ONLY_IF_TRUE'),$graph->descendants($f['view']['snapshot']['instances'][$block]['definition_id']));pf_dispatch($f,'SAVE_PAGE',array('block_execution_id'=>$block,'responses'=>$answers));
 $native=d2_native();
 return array('transversal'=>array('code'=>$p['public'],'study_id'=>$p['definition']['study']['logical_id'],'rows'=>8,'versions'=>2),'longitudinal'=>array('code'=>$long['public'],'study_id'=>$long['definition']['study']['logical_id'],'rows'=>2,'rows_with_partials'=>3),'native'=>array('code'=>$native['p']['public'],'study_id'=>$native['p']['definition']['study']['logical_id']),'sentinels'=>array('INCLUDED_FINAL','INCLUDED_RETAINED','EXCLUDED_DO_NOT_USE','INCLUDED_PARTIAL_ONLY_IF_TRUE','CSV_FORMULA_TEST'));
}
