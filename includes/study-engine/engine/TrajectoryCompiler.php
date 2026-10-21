<?php
namespace EIPSI\StudyEngine\Engine;
/** Compact symbolic trajectories: alternatives are references, never Cartesian tracks. */
final class TrajectoryCompiler {
    public static function compile(array $d): array {
        $nodes=array_column($d['nodes'],null,'id');$children=array();foreach($d['nodes'] as $n)$children[$n['parent_id']][]=$n;
        foreach($children as &$siblings)usort($siblings,static function($a,$b){return ($a['position']<=>$b['position'])?:strcmp($a['id'],$b['id']);});unset($siblings);
        $assessments=array();$randoms=array();$order=array();$walk=function($id,$context=null)use(&$walk,&$assessments,&$randoms,&$order,$nodes,$children){$n=$nodes[$id];if(($n['metadata']['role']??null)==='LONGITUDINAL_BLOCK')$context=$id;
            if(($n['completion_boundary']??null)==='ASSESSMENT'){$order[]=$id;$assessments[$id]=array('id'=>$id,'label'=>$n['metadata']['label']??$id,'block_id'=>$context,'schedule'=>$n['schedule'],'question_ids'=>array_column($n['metadata']['native_questions']??array(),'id'));}
            if($n['randomization']??null)$randoms[$id]=array('id'=>$id,'owner_id'=>$id,'kind'=>'STUDY','config'=>$n['randomization'],'block_id'=>$context);
            foreach($n['metadata']['native_randomizations']??array() as $r)$randoms[$id.'|'.$r['id']]=array('id'=>$r['id'],'owner_id'=>$id,'kind'=>'NATIVE','config'=>$r,'block_id'=>$context);
            foreach($children[$id]??array() as $child)$walk($child['id'],$context);
        };foreach($d['root_block_ids'] as $id)$walk($id);
        $branches=array();foreach(LogicalConditions::rules($d) as $r)$branches[$r['id']]=array('id'=>$r['id'],'label'=>$r['label'],'source'=>$r['source'],'priority'=>$r['priority'],'actions'=>$r['actions'],'event'=>$r['event']);
        return array('contract'=>'LOGICAL_TRAJECTORY_V1','time_direction'=>'LEFT_TO_RIGHT','shared_prefix'=>true,'visual_rejoin'=>false,'assessment_order'=>$order,'assessments'=>$assessments,'randomizations'=>$randoms,'branches'=>$branches,'expansion'=>'LAZY_PREFIX');
    }
}
