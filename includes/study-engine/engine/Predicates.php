<?php
namespace EIPSI\StudyEngine\Engine;
use EIPSI\StudyEngine\Contracts\Shape;

final class PredicateEvaluator {
    public function evaluate(array $ast, callable $lookup): string {
        Shape::predicate($ast);
        return $this->visit($ast,$lookup);
    }
    private function visit(array $ast, callable $lookup): string {
        $op=$ast['op'];
        if ($op==='AND' || $op==='OR') {
            $unknown=false;
            foreach ($ast['args'] as $arg) {
                $value=$this->visit($arg,$lookup);
                if ($op==='AND' && $value==='FALSE') { return 'FALSE'; }
                if ($op==='OR' && $value==='TRUE') { return 'TRUE'; }
                $unknown=$unknown || $value==='UNKNOWN';
            }
            return $unknown?'UNKNOWN':($op==='AND'?'TRUE':'FALSE');
        }
        if ($op==='NOT') { $v=$this->visit($ast['arg'],$lookup); return $v==='UNKNOWN'?$v:($v==='TRUE'?'FALSE':'TRUE'); }
        if ($op==='EXISTS' || $op==='IS_MISSING') {
            $v=$lookup($ast['reference']); $exists=$v['present'];
            return ($op==='EXISTS'?$exists:!$exists)?'TRUE':'FALSE';
        }
        $left=$this->operand($ast['left'],$lookup); $right=$this->operand($ast['right'],$lookup);
        if (!$left['present'] || !$right['present']) { return 'UNKNOWN'; }
        $a=$left['value']; $b=$right['value'];
        if ($op==='EQ' || $op==='NEQ') { $equal=$a===$b; return ($op==='EQ'?$equal:!$equal)?'TRUE':'FALSE'; }
        $numeric=(is_int($a)||is_float($a)) && (is_int($b)||is_float($b));
        if (!$numeric && !(is_string($a)&&is_string($b))) { throw new \DomainException('PREDICATE_TYPE_CONFLICT'); }
        $cmp=$numeric?($a<=>$b):strcmp($a,$b);
        $result=array('GT'=>$cmp>0,'GTE'=>$cmp>=0,'LT'=>$cmp<0,'LTE'=>$cmp<=0);
        if (!array_key_exists($op,$result)) { throw new \DomainException('UNKNOWN_PREDICATE_OPERATOR'); }
        return $result[$op]?'TRUE':'FALSE';
    }
    private function operand(array $op, callable $lookup): array {
        return $op['kind']==='VALUE'?array('present'=>true,'value'=>$op['value']):$lookup($op['reference']);
    }
}

final class ConditionResolver {
    public function resolve(array $rules, string $trigger, callable $lookup, callable $actionable): array {
        usort($rules,static function($a,$b){return ($a['order']<=>$b['order'])?:strcmp($a['id'],$b['id']);});
        $evaluator=new PredicateEvaluator(); $evaluations=array(); $matches=array();
        foreach ($rules as $rule) {
            $truth=$evaluator->evaluate($rule['predicate'],$lookup);
            $valid=$truth==='TRUE'?$actionable($rule):null;
            $evaluations[]=array('rule_id'=>$rule['id'],'truth'=>$truth,'actionable'=>$valid,'reason'=>$truth==='TRUE'&&!$valid?'TARGET_NOT_ACTIONABLE':null);
            if ($truth==='TRUE' && $valid) {
                $matches[]=$rule;
                if ($trigger==='IMMEDIATE') { break; }
            }
        }
        if ($trigger==='ON_ASSESSMENT_COMPLETE') {
            $priority=array('TERMINATE'=>0,'WAIT'=>1,'JUMP'=>2,'CONTINUE'=>3);
            usort($matches,static function($a,$b)use($priority){return ($priority[$a['action']]<=>$priority[$b['action']])?:($a['order']<=>$b['order'])?:strcmp($a['id'],$b['id']);});
        }
        return array('evaluations'=>$evaluations,'winner'=>$matches[0]??null,'suppressed'=>array_column(array_slice($matches,1),'id'));
    }
}
