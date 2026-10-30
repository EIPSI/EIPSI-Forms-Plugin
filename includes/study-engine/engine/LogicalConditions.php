<?php
namespace EIPSI\StudyEngine\Engine;

require_once __DIR__.'/LogicalPast.php';

/** Versioned scientific rules, independent of track coordinates and WordPress. */
final class LogicalConditions {
    const ACTIONS=array('GO_QUESTION','END_ASSESSMENT','END_STUDY','JUMP','WAIT','CANCEL_BLOCK','RESTRICT_RANDOM','EXCLUDE_RANDOM','FORCE_RANDOM');
    public static function rules(array $d): array {return $d['protocol_metadata']['trajectory_conditions']??array();}
    public static function catalog(array $d): array {
        $out=array();foreach($d['nodes'] as $n){foreach($n['metadata']['native_questions']??array() as $q)$out[$n['id']][$q['id']]=$q;}
        return $out;
    }
    public static function diagnostics(array $d): array {
        $issues=array();if(isset($d['protocol_metadata']['execution_planning'])&&$d['protocol_metadata']['execution_planning']!=='DEPENDENCY_PLAN_V1')$issues[]=array('code'=>'INVALID_EXECUTION_PLANNING','node_id'=>$d['id'],'severity'=>'ERROR');$nodes=array_column($d['nodes'],null,'id');$questions=self::catalog($d);$ids=array();$edges=array();
        $error=function($code,$id)use(&$issues){$issues[]=array('code'=>$code,'node_id'=>$id,'severity'=>'ERROR');};
        $rules=self::rules($d);if(!is_array($rules)||array_values($rules)!==$rules||count($rules)>1000){$error('INVALID_TRAJECTORY_CONDITIONS',$d['id']);return $issues;}
        foreach($rules as $r){
            $id=$r['id']??$d['id'];try{
                self::fields($r,array('id','label','source','event','priority','predicate','actions'),array('trigger','reference_scope','action_scope'));
                self::id($id);if(isset($ids[$id]))throw new \DomainException('DUPLICATE_CONDITION');$ids[$id]=true;
                if(!is_string($r['label'])||strlen($r['label'])>200||!is_int($r['priority'])||$r['priority']<1||!in_array($r['event'],array('ANSWER','COMPLETE','RANDOM_RESOLVED','SCHEDULE','BLOCK_STATE'),true))throw new \DomainException('INVALID_CONDITION_POLICY');
                self::fields($r['source'],array('assessment_id'),array('question_id'));$source=$r['source']['assessment_id'];if(!isset($nodes[$source])||($nodes[$source]['completion_boundary']??null)!=='ASSESSMENT')throw new \DomainException('INVALID_CONDITION_SOURCE');
                if(isset($r['source']['question_id'])&&!isset($questions[$source][$r['source']['question_id']]))throw new \DomainException('INVALID_SOURCE_QUESTION');
                if(isset($r['trigger'])){self::fields($r['trigger'],array('node_id','state'));$triggerNode=$nodes[$r['trigger']['node_id']]??null;if($r['event']!=='BLOCK_STATE'||!$triggerNode||$triggerNode['kind']!=='BLOCK'||!\EIPSI\StudyEngine\Domain\BlockExecutionState::contains($r['trigger']['state']))throw new \DomainException('INVALID_STATE_TRIGGER');}
                if(($d['protocol_metadata']['execution_planning']??null)==='DEPENDENCY_PLAN_V1'&&($r['event']==='SCHEDULE'||$r['event']==='BLOCK_STATE'&&!isset($r['trigger'])))throw new \DomainException('EXPLICIT_STATE_TRIGGER_REQUIRED');
                if(isset($r['reference_scope'])&&$r['reference_scope']!=='TRACK_PAST_V1')throw new \DomainException('INVALID_REFERENCE_SCOPE');
                if(isset($r['action_scope'])&&$r['action_scope']!=='TRACK_FUTURE_V1')throw new \DomainException('INVALID_ACTION_SCOPE');
                self::expression($r['predicate'],$nodes,$questions);
                if(!is_array($r['actions'])||array_values($r['actions'])!==$r['actions']||!$r['actions']||count($r['actions'])>32)throw new \DomainException('INVALID_CONDITION_ACTIONS');
                foreach($r['actions'] as $a){
                    self::fields($a,array('type'),array('target_id','question_id','candidate_ids','randomization_id'));if(!in_array($a['type'],self::ACTIONS,true))throw new \DomainException('INVALID_CONDITION_ACTION');
                    if($a['type']==='END_STUDY'){self::fields($a,array('type'));continue;}
                    if($a['type']==='END_ASSESSMENT')continue;
                    $target=$a['target_id']??null;if(!isset($nodes[$target]))throw new \DomainException('INVALID_ACTION_TARGET');$n=$nodes[$target];
                    if(in_array($a['type'],array('GO_QUESTION','JUMP','WAIT'),true)){
                        if(($n['completion_boundary']??null)!=='ASSESSMENT')throw new \DomainException('TARGET_MUST_BE_ASSESSMENT');
                        if(isset($a['question_id'])&&!isset($questions[$target][$a['question_id']]))throw new \DomainException('INVALID_ENTRY_QUESTION');
                        if($a['type']==='GO_QUESTION'&&($target!==$source||!isset($a['question_id'])))throw new \DomainException('INVALID_INTRA_ASSESSMENT_TARGET');
                        if($a['type']==='WAIT'&&in_array($n['schedule']['anchor']??null,array('BLOCK_COMPLETED','SPECIFIC_BLOCK_COMPLETED'),true)&&($n['schedule']['anchor_ref']??null)===$source)throw new \DomainException('WAIT_DEPENDS_ON_SOURCE_NATURAL_COMPLETION');
                        $from=$source.'|'.($r['source']['question_id']??'');$to=$target.'|'.($a['question_id']??'');$edges[$from][]=$to;if($source!==$target)$edges[$source][]=$target;
                        if(($r['action_scope']??null)!=='TRACK_FUTURE_V1'&&$source===$target&&isset($r['source']['question_id'],$a['question_id'])){
                            $order=array_keys($questions[$source]);if(array_search($a['question_id'],$order,true)<=array_search($r['source']['question_id'],$order,true))throw new \DomainException('BACKWARD_QUESTION_JUMP');
                        }
                    }elseif($a['type']==='CANCEL_BLOCK'){
                        if($n['kind']!=='BLOCK')throw new \DomainException('TARGET_MUST_BE_BLOCK');
                    }else{
                        $native=isset($a['randomization_id'])?(array_column($n['metadata']['native_randomizations']??array(),null,'id')[$a['randomization_id']]??null):null;if(isset($a['randomization_id'])&&!$native)throw new \DomainException('INVALID_NATIVE_RANDOMIZATION_REFERENCE');$pool=$native['candidates']??($n['randomization']['candidates']??null);$selected=$a['candidate_ids']??null;
                        if(!$pool||!is_array($selected)||!$selected||array_values($selected)!==$selected||count(array_unique($selected))!==count($selected)||array_diff($selected,$pool))throw new \DomainException('INVALID_RANDOMIZATION_CANDIDATES');
                        if($a['type']==='FORCE_RANDOM'){$expected=$native?($native['mode']==='all'?count($pool):$native['count']):($n['randomization']['strategy']==='RANDOM_SELECT_N'?$n['randomization']['select_n']:($n['randomization']['strategy']==='WEIGHTED_RANDOM'?$n['randomization']['draw_count']:($n['randomization']['strategy']==='RANDOM_ORDER'?count($pool):1)));if(count($selected)!==$expected)throw new \DomainException('INVALID_FORCED_OUTCOME_SIZE');}
                        if($native&&$source===$target&&(!isset($r['source']['question_id'])||($questions[$source][$r['source']['question_id']]['page']??0)>=$native['page']))throw new \DomainException('RANDOMIZATION_ALREADY_ACTIVE_AT_SOURCE');for($p=$source;!$native&&isset($nodes[$p]);$p=$nodes[$p]['parent_id'])if($p===$target)throw new \DomainException('RANDOMIZATION_ALREADY_ACTIVE_AT_SOURCE');
                    }
                }
            }catch(\Throwable $e){$error($e->getMessage(),is_string($id)?$id:$d['id']);}
        }
        $visiting=array();$done=array();$visit=function($id)use(&$visit,&$visiting,&$done,$edges){if(isset($visiting[$id]))throw new \DomainException('TRAJECTORY_CYCLE');if(isset($done[$id]))return;$visiting[$id]=true;foreach($edges[$id]??array() as $to)$visit($to);unset($visiting[$id]);$done[$id]=true;};
        try{foreach(array_keys($edges) as $id)$visit($id);}catch(\Throwable $e){$error($e->getMessage(),$d['id']);}return array_merge($issues,self::unreachable($d,$nodes));
    }
    /** Conservative proofs only: unresolved answers never mean an impossible path. */
    private static function unreachable(array $d,array $nodes): array {
        $issues=array();$warn=static function($code,$id)use(&$issues){$issues[]=array('code'=>$code,'node_id'=>$id,'severity'=>'WARNING');};
        $desc=function($id)use(&$desc,$nodes){$out=array($id);foreach($nodes as $n)if($n['parent_id']===$id)$out=array_merge($out,$desc($n['id']));return $out;};$impossible=array();
        foreach($nodes as $n){$r=$n['randomization']??null;if(!$r||!isset($r['weights']))continue;foreach($r['weights'] as $candidate=>$weight)if($weight===0||$weight===0.0)foreach($desc($candidate) as $id)$impossible[$id]=true;}
        foreach(array_keys($impossible) as $id)if(($nodes[$id]['completion_boundary']??null)==='ASSESSMENT')$warn('UNREACHABLE_ASSESSMENT_ZERO_WEIGHT',$id);
        foreach($nodes as $n)foreach($n['metadata']['native_questions']??array() as $q){if(isset($impossible[$n['id']])){$warn('UNREACHABLE_QUESTION_ZERO_WEIGHT',$q['id']);continue;}$randoms=array_column($n['metadata']['native_randomizations']??array(),null,'id');foreach($q['randomization_path']??array() as $path){$r=$randoms[$path['randomization_id']]??array();if(isset($r['weights'][$path['candidate_id']])&&(float)$r['weights'][$path['candidate_id']]===0.0&&!in_array($path['candidate_id'],$r['always_show']??array(),true)&&!in_array($path['candidate_id'],$r['fixed']??array(),true)){$warn('UNREACHABLE_QUESTION_ZERO_WEIGHT',$q['id']);break;}}}
        $contradiction=function($p)use(&$contradiction){if(!is_array($p))return false;if(($p['op']??null)==='AND'){$atoms=array();$negated=array();$equal=array();foreach($p['args']??array() as $arg){if($contradiction($arg))return true;$key=json_encode($arg);$atoms[$key]=true;if(($arg['op']??null)==='NOT')$negated[json_encode($arg['arg'])]=true;if(($arg['op']??null)==='EQ'){$ref=json_encode($arg['reference']);$value=json_encode($arg['value']);if(isset($equal[$ref])&&$equal[$ref]!==$value)return true;$equal[$ref]=$value;}}return (bool)array_intersect_key($atoms,$negated);}if(($p['op']??null)==='OR'){foreach($p['args']??array() as $arg)if(!$contradiction($arg))return false;return !empty($p['args']);}return false;};
        foreach(self::rules($d) as $r)if(is_array($r)&&isset($r['id'])){if(isset($impossible[$r['source']['assessment_id']??'']))$warn('DEAD_CONDITION_UNREACHABLE_SOURCE',$r['id']);elseif($contradiction($r['predicate']??null))$warn('DEAD_CONDITION_CONTRADICTORY_PREDICATE',$r['id']);}
        return $issues;
    }
    private static function id($v): void {if(!is_string($v)||!preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,99}$/D',$v))throw new \DomainException('INVALID_LOGICAL_ID');}
    private static function fields($v,array $required,array $optional=array()): void {if(!is_array($v)||array_diff($required,array_keys($v))||array_diff(array_keys($v),array_merge($required,$optional)))throw new \DomainException('INVALID_LOGICAL_SHAPE');}
    private static function reference(array $ref,array $nodes,array $questions): string {
        self::fields($ref,array('kind'),array('assessment_id','question_id','randomization_id','candidate_id','node_id'));
        if(in_array($ref['kind'],array('ANSWER','QUESTION_STATE','QUESTION_SHOWN','QUESTION_SELECTED'),true)){
            $q=$questions[$ref['assessment_id']??''][$ref['question_id']??'']??null;if(!$q)throw new \DomainException('INVALID_LOGICAL_QUESTION_REFERENCE');
            return $ref['kind']==='QUESTION_STATE'?'STRING':($ref['kind']==='ANSWER'?$q['value_type']:'BOOLEAN');
        }
        if(in_array($ref['kind'],array('RANDOM_SELECTED','RANDOM_RESOLVED'),true)){
            $r=isset($ref['assessment_id'])?(array_column($nodes[$ref['assessment_id']]['metadata']['native_randomizations']??array(),null,'id')[$ref['randomization_id']??'']??null):($nodes[$ref['randomization_id']??'']['randomization']??null);if(!$r)throw new \DomainException('INVALID_LOGICAL_RANDOM_REFERENCE');
            if($ref['kind']==='RANDOM_SELECTED'&&!in_array($ref['candidate_id']??null,$r['candidates'],true))throw new \DomainException('INVALID_LOGICAL_CANDIDATE_REFERENCE');return 'BOOLEAN';
        }
        if($ref['kind']==='BLOCK_STATE'&&isset($nodes[$ref['node_id']??'']))return 'STRING';
        throw new \DomainException('INVALID_LOGICAL_REFERENCE');
    }
    private static function expression(array $p,array $nodes,array $questions,int $depth=0): void {
        if($depth>20)throw new \DomainException('LOGICAL_EXPRESSION_TOO_DEEP');$op=$p['op']??null;
        if(in_array($op,array('AND','OR'),true)){self::fields($p,array('op','args'));if(!is_array($p['args'])||count($p['args'])<2||count($p['args'])>32)throw new \DomainException('INVALID_LOGICAL_GROUP');foreach($p['args'] as $a)self::expression($a,$nodes,$questions,$depth+1);return;}
        if($op==='NOT'){self::fields($p,array('op','arg'));self::expression($p['arg'],$nodes,$questions,$depth+1);return;}
        self::fields($p,array('op','reference','value'));$type=self::reference($p['reference'],$nodes,$questions);$v=$p['value'];
        if(!in_array($op,array('EQ','NEQ','LT','LTE','GT','GTE','IN'),true))throw new \DomainException('INVALID_LOGICAL_OPERATOR');
        if(in_array($op,array('LT','LTE','GT','GTE'),true)&&!in_array($type,array('NUMBER','DATE'),true))throw new \DomainException('INCOMPATIBLE_LOGICAL_COMPARATOR');
        $values=$op==='IN'?$v:array($v);if(!is_array($values)||!$values||count($values)>200)throw new \DomainException('INVALID_LOGICAL_MEMBERSHIP');
        foreach($values as $value){if($type==='NUMBER'&&!(is_int($value)||is_float($value))||$type==='BOOLEAN'&&!is_bool($value)||in_array($type,array('STRING','DATE','SET'),true)&&!is_string($value))throw new \DomainException('LOGICAL_VALUE_TYPE_CONFLICT');if($type==='DATE'&&(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$value)||!checkdate((int)substr($value,5,2),(int)substr($value,8,2),(int)substr($value,0,4))))throw new \DomainException('INVALID_LOGICAL_DATE');}
        if($type==='SET'&&$op!=='IN')throw new \DomainException('MULTIPLE_RESPONSE_REQUIRES_MEMBERSHIP');
    }
    public static function evaluate(array $p,callable $lookup): string {
        $op=$p['op'];if($op==='NOT'){$v=self::evaluate($p['arg'],$lookup);return $v==='UNKNOWN'?$v:($v==='TRUE'?'FALSE':'TRUE');}
        if($op==='AND'||$op==='OR'){$unknown=false;foreach($p['args'] as $arg){$v=self::evaluate($arg,$lookup);if($op==='AND'&&$v==='FALSE'||$op==='OR'&&$v==='TRUE')return $v;$unknown=$unknown||$v==='UNKNOWN';}return $unknown?'UNKNOWN':($op==='AND'?'TRUE':'FALSE');}
        $source=$lookup($p['reference']);if(!($source['known']??false))return 'UNKNOWN';$a=$source['value'];$b=$p['value'];
        if($op==='IN')$yes=is_array($a)?count(array_intersect($a,$b))>0:in_array($a,$b,true);
        elseif($op==='EQ'||$op==='NEQ'){$yes=$a===$b;if($op==='NEQ')$yes=!$yes;}
        else{$cmp=$a<=>$b;$yes=($op==='LT'&&$cmp<0)||($op==='LTE'&&$cmp<=0)||($op==='GT'&&$cmp>0)||($op==='GTE'&&$cmp>=0);}
        return $yes?'TRUE':'FALSE';
    }
    /** Smaller ordinal wins; compatible actions survive. No hidden action-type precedence. */
    public static function resolve(array $rules,callable $lookup,?callable $actionAllowed=null): array {
        usort($rules,static function($a,$b){return ($a['priority']<=>$b['priority'])?:strcmp($a['id'],$b['id']);});$audit=array();$actions=array();$claims=array();$terminated=false;
        foreach($rules as $rule){$values=array();$truth=self::evaluate($rule['predicate'],function($ref)use($lookup,&$values,$rule){$v=$lookup($ref,$rule);$values[]=array('reference'=>$ref,'state'=>$v);return $v;});$record=array('condition_id'=>$rule['id'],'priority'=>$rule['priority'],'result'=>$truth,'sources'=>$values,'attempted'=>$rule['actions'],'applied'=>array(),'suppressed'=>array());
            if($truth==='TRUE')foreach($rule['actions'] as $index=>$action){if($terminated){$record['suppressed'][]=array('action'=>$action,'reason'=>'STUDY_TERMINATED');continue;}if($actionAllowed!==null&&!$actionAllowed($rule,$action)){$record['suppressed'][]=array('action'=>$action,'reason'=>'OUTSIDE_TRACK_FUTURE');continue;}$key=in_array($action['type'],array('GO_QUESTION','END_ASSESSMENT','END_STUDY','JUMP','WAIT'),true)?'FLOW':($action['type']==='FORCE_RANDOM'?'RANDOM:'.$action['target_id'].'|'.($action['randomization_id']??''):null);
                if($key!==null&&isset($claims[$key])&&$claims[$key]!==$rule['id']){$record['suppressed'][]=array('action'=>$action,'winner'=>$claims[$key]);continue;}
                if($key!==null)$claims[$key]=$rule['id'];$entry=array('condition_id'=>$rule['id'],'priority'=>$rule['priority'],'index'=>$index,'action'=>$action);$actions[]=$entry;$record['applied'][]=$action;if($action['type']==='END_STUDY')$terminated=true;
            }$audit[]=$record;
        }return array('actions'=>$actions,'audit'=>$audit);
    }
}
