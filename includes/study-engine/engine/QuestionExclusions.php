<?php
namespace EIPSI\StudyEngine\Engine;

/** Responsive eligibility for Native questions/groups; never changes random decisions. */
final class QuestionExclusions {
    public const ACTION='EXCLUDE_ELEMENT';
    public static function targets(array $node): array {
        $out=array();foreach($node['metadata']['native_questions']??array() as $q)$out[$q['id']]=array('id'=>$q['id'],'label'=>$q['label'],'kind'=>'QUESTION','page'=>$q['page'],'question_ids'=>array($q['id']),'element_ids'=>array($q['id']));
        foreach($node['metadata']['native_groups']??array() as $g)$out[$g['id']]=$g;
        return $out;
    }
    /** A shared randomization may reverse authored siblings; runtime still checks the actual track. */
    public static function mayReorder(array $a,array $b): bool {
        foreach($a['randomization_path']??array() as $left)foreach($b['randomization_path']??array() as $right)if($left['randomization_id']===$right['randomization_id']&&$left['candidate_id']!==$right['candidate_id'])return true;
        return false;
    }
    public static function present(array $definition,?string $assessment=null): bool {
        foreach($definition['protocol_metadata']['trajectory_conditions']??array() as $r)foreach($r['actions'] as $a)if($a['type']===self::ACTION&&($assessment===null||$a['target_id']===$assessment))return true;
        return false;
    }
    public static function resolve(array $definition,string $assessment,callable $lookup,callable $allowed): array {
        $nodes=array_column($definition['nodes'],null,'id');$targets=self::targets($nodes[$assessment]);$elements=$questions=$conditions=array();
        foreach($definition['protocol_metadata']['trajectory_conditions']??array() as $rule){
            $actions=array_values(array_filter($rule['actions'],static function($a)use($assessment){return $a['type']===self::ACTION&&$a['target_id']===$assessment;}));if(!$actions)continue;
            $truth=LogicalConditions::evaluate($rule['predicate'],static function($ref)use($lookup,$rule){return $lookup($ref,$rule);});
            foreach($actions as $action){if(!$allowed($rule,$action)||$truth==='FALSE')continue;$target=$targets[$action['element_id']];$status=$truth==='TRUE'?'excluded_by_condition':'pending_condition';
                foreach($target['element_ids'] as $id)$elements[$id]=true;
                foreach($target['question_ids'] as $id)if(($questions[$id]??null)!=='excluded_by_condition')$questions[$id]=$status;
                $conditions[$rule['id']]=$truth;
            }
        }
        return array('elements'=>array_keys($elements),'questions'=>$questions,'conditions'=>$conditions);
    }
    public static function definition(array $definition,array $ids): array {
        if(!$ids)return $definition;$hidden=array_fill_keys($ids,true);
        $walk=function($items)use(&$walk,$hidden){$out=array();foreach($items as $e){if(isset($hidden[$e['id']]))continue;if($e['type']==='matrix'){$e['properties']['matrix_rows']=array_values(array_filter($e['properties']['matrix_rows'],static function($r)use($hidden){return !isset($hidden[$r['id']]);}));if(!$e['properties']['matrix_rows'])continue;}$e['children']=$walk($e['children']);if(in_array($e['type'],array('group','randomization'),true)&&!$e['children'])continue;$out[]=$e;}return $out;};
        $definition['elements']=$walk($definition['elements']);return $definition;
    }
    public static function pages(array $definition): array {
        $page=0;$pages=array();foreach($definition['elements'] as $e){if($e['type']==='page_break')$page++;else $pages[$page]=true;}return array_keys($pages);
    }
}
