<?php
namespace EIPSI\StudyEngine\Engine;

/** Past reads and future destinations along one selected path. */
final class LogicalPast {
    public static function allows(array $d,array $rule,array $ref,array $choices,array $native,array $times=array(),bool $future=false): bool {
        $nodes=array_column($d['nodes'],null,'id');$cells=array();$visited=array();
        $walk=function($id,$at=0)use(&$walk,&$cells,&$visited,$nodes,$choices,$times,$future){
            if(isset($visited[$id])||!isset($nodes[$id]))return;$visited[$id]=true;$n=$nodes[$id];$t=$n['schedule']['temporal_rule']??null;
            if(($t['kind']??null)==='LOCAL_DAY_TIME')$at=($t['day_index']-1)*86400+(int)substr($t['time'],0,2)*3600+(int)substr($t['time'],3,2)*60;
            elseif(isset($t['duration'])){$v=$t['duration'];$at+=$v['seconds']??(($v['amount']??0)*(array('MINUTE'=>60,'HOUR'=>3600,'DAY'=>86400,'WEEK'=>604800)[$v['unit']??'DAY']??86400));}
            if(($n['completion_boundary']??null)==='ASSESSMENT'){$cells[]=array('id'=>$id,'at'=>$times[$id]??$at,'order'=>count($cells));return;}
            $children=array_values(array_filter($nodes,static function($child)use($id){return $child['parent_id']===$id;}));usort($children,static function($a,$b){return ($a['position']<=>$b['position'])?:strcmp($a['id'],$b['id']);});
            $ids=($n['randomization']??null)!==null?($choices[$id]??($future?array_column($children,'id'):array())):array_column($children,'id');foreach($ids as $child)$walk($child,$at);
        };
        foreach($d['root_block_ids'] as $root)$walk($root);
        usort($cells,static function($a,$b){return ($a['at']<=>$b['at'])?:($a['order']<=>$b['order']);});
        $source=$rule['source']['assessment_id'];$order=array_column($cells,'id');$sourceIndex=array_search($source,$order,true);if($sourceIndex===false)return false;
        $target=$ref['assessment_id']??$ref['node_id']??$ref['randomization_id'];$targetIndex=array_search($target,$order,true);
        // Containers and external randomizations become part of the past on entering a descendant.
        if($targetIndex===false){$indices=array();foreach($cells as $i=>$cell){for($n=$nodes[$cell['id']];isset($nodes[$n['parent_id']]);$n=$nodes[$n['parent_id']])if($n['parent_id']===$target)$indices[]=$i;}if(!$indices)return false;if($future)return (!isset($ref['randomization_id'])||min($indices)>$sourceIndex)&&max($indices)>$sourceIndex;return min($indices)<=$sourceIndex;}
        if($targetIndex!==$sourceIndex)return $future?$targetIndex>$sourceIndex:$targetIndex<$sourceIndex;
        if(!isset($ref['question_id'])&&!isset($ref['randomization_id']))return !$future;
        $questions=$nodes[$source]['metadata']['native_questions']??array();
        $questions=array_values(array_filter($questions,static function($q)use($source,$native){foreach($q['randomization_path']??array() as $p){$chosen=$native[$source.'|'.$p['randomization_id']]??null;if($chosen!==null&&!in_array($p['candidate_id'],$chosen,true))return false;}return true;}));
        usort($questions,static function($a,$b)use($source,$native){$ap=$a['randomization_path']??array();$bp=$b['randomization_path']??array();for($i=0;$i<min(count($ap),count($bp));$i++){if($ap[$i]['randomization_id']!==$bp[$i]['randomization_id'])return 0;$selected=$native[$source.'|'.$ap[$i]['randomization_id']]??null;if($selected!==null&&$ap[$i]['candidate_id']!==$bp[$i]['candidate_id'])return array_search($ap[$i]['candidate_id'],$selected,true)<=>array_search($bp[$i]['candidate_id'],$selected,true);}return 0;});
        $questionIds=array_column($questions,'id');$point=$rule['event']==='COMPLETE'?count($questions):(isset($rule['source']['question_id'])?array_search($rule['source']['question_id'],$questionIds,true):-1);if($point===false)return false;
        if(isset($ref['question_id'])){$index=array_search($ref['question_id'],$questionIds,true);return $index!==false&&($future?$index>$point:$index<=$point);}
        foreach($questions as $index=>$q)foreach($q['randomization_path']??array() as $p)if($p['randomization_id']===$ref['randomization_id'])return $future?$index>$point:$index<=$point;
        return false;
    }
    public static function actionAllows(array $d,array $rule,array $action,array $choices,array $native,array $times=array()): bool {
        if($action['type']==='END_ASSESSMENT')return true;
        $target=$action['target_id'];$source=$rule['source']['assessment_id'];
        if(in_array($action['type'],array('JUMP','WAIT'),true)&&$target===$source)return false;
        if($action['type']==='GO_QUESTION'&&$target!==$source)return false;
        if($action['type']==='CANCEL_BLOCK'&&$target===$source)return false;
        $ref=array('node_id'=>$target);
        if(isset($action['question_id']))$ref=array('assessment_id'=>$target,'question_id'=>$action['question_id']);
        elseif(strpos($action['type'],'RANDOM')!==false)$ref=array('randomization_id'=>$action['randomization_id']??$target)+(isset($action['randomization_id'])?array('assessment_id'=>$target):array());
        return self::allows($d,$rule,$ref,$choices,$native,$times,true);
    }

}
