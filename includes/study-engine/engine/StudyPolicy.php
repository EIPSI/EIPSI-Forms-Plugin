<?php
namespace EIPSI\StudyEngine\Engine;
/** Explicit new-product constraints; historical immutable protocols retain their original contracts. */
final class StudyPolicy {
    public static function diagnostics(array $d,array $nodes): array {
        $type=$d['protocol_metadata']['study_type']??null;if($type===null)return array();$issues=array();
        $add=static function($code,$id)use(&$issues){$issues[]=array('code'=>$code,'node_id'=>$id,'severity'=>'ERROR');};
        if(!in_array($type,array('TRANSVERSAL','LONGITUDINAL'),true))$add('INVALID_STUDY_TYPE',$d['id']);
        if(isset($d['protocol_metadata']['unique_response'])&&!is_bool($d['protocol_metadata']['unique_response']))$add('INVALID_UNIQUE_RESPONSE',$d['id']);
        $assessments=array_filter($nodes,static function($n){return ($n['completion_boundary']??null)==='ASSESSMENT';});
        if($type==='TRANSVERSAL'){
            if(($d['protocol_metadata']['enrollment_mode']??null)!=='ANONYMOUS')$add('TRANSVERSAL_ACCESS_REQUIRED',$d['id']);
            $groups=array_filter($nodes,static function($n){return ($n['metadata']['role']??null)==='TRANSVERSAL_RANDOMIZATION';});$group=$groups?reset($groups):null;
            if($group===null){if(count($assessments)!==1)$add('TRANSVERSAL_EXACTLY_ONE_ASSESSMENT',$d['id']);foreach($assessments as $assessment)if(!in_array($assessment['parent_id'],$d['root_block_ids'],true))$add('TRANSVERSAL_STRUCTURE_NOT_ALLOWED',$assessment['id']);}
            else {if(count($groups)!==1||!count($assessments)||!in_array($group['parent_id'],$d['root_block_ids'],true))$add('TRANSVERSAL_SELECTION_REQUIRED',$group['id']);$r=$group['randomization'];if($group['children_strategy']!=='WEIGHTED_RANDOM'||$r===null||$r['strategy']!=='WEIGHTED_RANDOM'||$r['draw_count']!==1||$r['scope']!=='EXECUTION'||$r['order']!=='DEFINITION')$add('TRANSVERSAL_SELECT_EXACTLY_ONE',$group['id']);if($r!==null)foreach($r['weights'] as $weight)if($weight<=0)$add('POSITIVE_WEIGHT_REQUIRED',$group['id']);foreach($assessments as $assessment)if($assessment['parent_id']!==$group['id'])$add('TRANSVERSAL_STRUCTURE_NOT_ALLOWED',$assessment['id']);}

            foreach($nodes as $n)if($n['kind']==='BLOCK'){
                if(($n['randomization']!==null&&$n['id']!==($group['id']??null))||($n['scope']==='MACRO'&&!in_array($n['id'],$d['root_block_ids'],true)&&$n['id']!==($group['id']??null)))$add('TRANSVERSAL_STRUCTURE_NOT_ALLOWED',$n['id']);
                if(($n['schedule']['anchor']??null)!=='EXECUTION_CREATED'||($n['schedule']['temporal_rule']['kind']??null)!=='IMMEDIATE'||($n['schedule']['recurrence']??null)!==null)$add('TRANSVERSAL_MUST_BE_IMMEDIATE',$n['id']);
            }
        }elseif($type==='LONGITUDINAL'){
            if(($d['protocol_metadata']['enrollment_mode']??null)!=='EMAIL_LONGITUDINAL'||!isset($d['protocol_metadata']['longitudinal']))$add('LONGITUDINAL_EMAIL_REQUIRED',$d['id']);
            foreach($nodes as $n){if($n['kind']!=='BLOCK')continue;$role=$n['metadata']['role']??null;$parent=$nodes[$n['parent_id']]??null;$parentRole=$parent['metadata']['role']??null;$root=in_array($n['id'],$d['root_block_ids'],true);
                if($root||in_array($role,array('LONGITUDINAL_BLOCK','BLOCK_RANDOMIZATION','LOCAL_RANDOMIZATION'),true)){if(empty($n['metadata']['independent_children']))$add('LONGITUDINAL_SEQUENTIAL_GATE_NOT_ALLOWED',$n['id']);}
                if(!$root&&$n['scope']==='MACRO'&&!in_array($role,array('LONGITUDINAL_BLOCK','BLOCK_RANDOMIZATION','LOCAL_RANDOMIZATION'),true))$add('INVALID_LONGITUDINAL_NESTING',$n['id']);
                if($role==='LONGITUDINAL_BLOCK'&&!array_filter($nodes,static function($child)use($n){return $child['parent_id']===$n['id']&&$child['kind']==='BLOCK';}))$add('LONGITUDINAL_BLOCK_EMPTY',$n['id']);
                if($role==='LONGITUDINAL_BLOCK'&&!in_array($n['parent_id'],$d['root_block_ids'],true)&&$parentRole!=='BLOCK_RANDOMIZATION')$add('INVALID_LONGITUDINAL_NESTING',$n['id']);
                if($role==='BLOCK_RANDOMIZATION'&&!in_array($n['parent_id'],$d['root_block_ids'],true))$add('INVALID_LONGITUDINAL_NESTING',$n['id']);
                if($role==='LOCAL_RANDOMIZATION'&&$parentRole!=='LONGITUDINAL_BLOCK')$add('INVALID_LOCAL_RANDOMIZATION_NESTING',$n['id']);
                if($n['completion_boundary']==='ASSESSMENT'&&!in_array($parentRole,array('LONGITUDINAL_BLOCK','LOCAL_RANDOMIZATION'),true))$add('INVALID_ASSESSMENT_NESTING',$n['id']);
                if(in_array($role,array('LOCAL_RANDOMIZATION','BLOCK_RANDOMIZATION'),true)){
                    $r=$n['randomization'];if($r===null||$r['strategy']!=='WEIGHTED_RANDOM'||$r['scope']!=='EXECUTION'||$r['order']!=='DEFINITION')$add('INVALID_PRODUCT_RANDOMIZATION',$n['id']);
                    elseif($role==='LOCAL_RANDOMIZATION'&&$r['draw_count']!==1)$add('LOCAL_RANDOMIZATION_EXACTLY_ONE',$n['id']);
                    if($r!==null)foreach($r['weights'] as $weight)if($weight<=0)$add('POSITIVE_WEIGHT_REQUIRED',$n['id']);
                }
                if($n['completion_boundary']==='ASSESSMENT'||$role==='LOCAL_RANDOMIZATION'){
                    if($parentRole!=='LOCAL_RANDOMIZATION'&&!isset($n['metadata']['scheduled_interaction']))$add('SCHEDULED_INTERACTION_REQUIRED',$n['id']);
                    $s=$n['schedule'];$window=$n['metadata']['scheduled_interaction']['window_seconds']??null;if($window!==null&&($s['temporal_rule']['kind']??null)==='LOCAL_DAY_TIME'){$parts=explode(':',$s['temporal_rule']['time']);if(((int)$parts[0]*60+(int)$parts[1])*60+$window>86400)$add('DELIVERY_WINDOW_OUTSIDE_DAY',$n['id']);}
                    if(!in_array($s['anchor'],array('EXECUTION_CREATED','SPECIFIC_BLOCK_COMPLETED','BLOCK_AVAILABLE','PARTICIPANT_VERIFIED'),true)||$s['recurrence']!==null)$add('INVALID_PRODUCT_SCHEDULE',$n['id']);
                    if($s['anchor']==='BLOCK_AVAILABLE'&&$parentRole!=='LOCAL_RANDOMIZATION')$add('INVALID_PRODUCT_SCHEDULE',$n['id']);
                    if(($n['metadata']['scheduled_interaction']['timing']??null)==='RELATIVE'){if(!in_array($s['anchor'],array('EXECUTION_CREATED','PARTICIPANT_VERIFIED'),true)||$s['temporal_rule']['kind']!=='OFFSET'||$s['temporal_rule']['duration']['kind']!=='ELAPSED')$add('INVALID_RELATIVE_TRIGGER',$n['id']);}
                    elseif($s['anchor']==='EXECUTION_CREATED'&&($s['temporal_rule']['kind']!=='LOCAL_DAY_TIME'||(int)substr($s['temporal_rule']['time'],-2)%15!==0))$add('RELATIVE_CALENDAR_GRID_REQUIRED',$n['id']);
                    if($s['anchor']==='SPECIFIC_BLOCK_COMPLETED'&&($s['temporal_rule']['kind']!=='OFFSET'||$s['temporal_rule']['duration']['kind']!=='CALENDAR'||$s['temporal_rule']['duration']['unit']!=='DAY'||($nodes[$s['anchor_ref']]['completion_boundary']??null)!=='ASSESSMENT'))$add('INVALID_COMPLETION_ANCHOR',$n['id']);
                }
            }
        }
        foreach($assessments as $n)if(empty($n['metadata']['native_version_id'])||empty($n['metadata']['native_definition_hash']))$add('NATIVE_ASSESSMENT_REQUIRED',$n['id']);
        return $issues;
    }
}
