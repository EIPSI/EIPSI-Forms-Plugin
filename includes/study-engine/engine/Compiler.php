<?php
namespace EIPSI\StudyEngine\Engine;
use EIPSI\StudyEngine\Contracts\StudyVersionDefinition;
use EIPSI\StudyEngine\Contracts\Canonical;

require_once __DIR__.'/StudyPolicy.php';
require_once __DIR__.'/LogicalConditions.php';
require_once __DIR__.'/TrajectoryCompiler.php';

final class CompilationException extends \DomainException {
    private $diagnostics;
    public function __construct(array $diagnostics) { $this->diagnostics=$diagnostics; parent::__construct(Canonical::json($diagnostics)); }
    public function diagnostics(): array { return $this->diagnostics; }
}

/** Immutable copied indexes; callers cannot mutate the compiler's graph. */
final class CompiledStudy {
    private $definition;
    private $graph;
    public function __construct(array $definition, array $graph) { $this->definition=Canonical::normalize($definition);$this->graph=Canonical::normalize($graph); }
    public function definition(): array { return $this->definition; }
    public function graph(): array { return $this->graph; }
    public function hash(): string { return hash('sha256',Canonical::json($this->graph)); }
    public function protocolHash(): string { return Canonical::protocolHash($this->definition); }
    public function node(string $id): array {
        if (!isset($this->graph['nodes'][$id])) { throw new \DomainException('NODE_NOT_FOUND: '.$id); } return $this->graph['nodes'][$id];
    }
    public function parent(string $id): string { return $this->node($id)['parent_id']; }
    public function children(string $id): array { return $this->graph['children'][$id]??array(); }
    public function descendants(string $id): array { return $this->graph['descendants'][$id]??array(); }
    public function assessmentOwner(string $id): ?string { return $this->graph['assessment_owner'][$id]??null; }
    public function order(): array { return $this->graph['order']; }
    public function nextStructural(string $id): ?string { $i=array_search($id,$this->order(),true);return $this->graph['order'][$i+1]??null; }
    public function target(array $target): array { return array('node'=>$this->node($target['node_id']),'assessment_owner'=>$this->assessmentOwner($target['node_id']),'selector'=>$target['occurrence_selector']); }
    public function dependencies(string $id): array { return $this->graph['temporal_dependencies'][$id]??array(); }
    public function randomCandidates(string $id): array { return $this->graph['segments'][$id]??array(); }
}

final class DefinitionCompiler {
    public function compile($input): CompiledStudy {
        $definition=new StudyVersionDefinition($input instanceof StudyVersionDefinition?$input->toArray():$input);
        $d=Canonical::definition($definition->toArray());$nodes=array();$children=array();$owners=array();$desc=array();$order=array();$edges=array('containment'=>array(),'traversal'=>array(),'temporal'=>array(),'condition'=>array(),'jump'=>array(),'wait'=>array(),'recurrence'=>array());$dependencies=array();$segments=array();$diagnostics=array();
        foreach ($d['nodes'] as $n) { $nodes[$n['id']]=$n;$children[$n['parent_id']][]=$n['id'];$edges['containment'][]=array($n['parent_id'],$n['id']); }
        foreach ($children as &$ids) { usort($ids,static function($a,$b)use($nodes){return ($nodes[$a]['position']<=>$nodes[$b]['position'])?:strcmp($a,$b);}); } unset($ids);
        $visit=function($id,$owner)use(&$visit,&$order,&$owners,&$desc,$nodes,$children){
            if ($nodes[$id]['kind']==='BLOCK' && $nodes[$id]['completion_boundary']==='ASSESSMENT') { $owner=$id; }
            $owners[$id]=$owner;$order[]=$id;$desc[$id]=array();
            foreach ($children[$id]??array() as $child) { $visit($child,$owner);$desc[$id]=array_merge($desc[$id],array($child),$desc[$child]); }
        };
        foreach ($children[$d['id']]??array() as $root) { $visit($root,null); }
        $rank=array_flip($order);
        $add=function($code,$id,$severity='ERROR')use(&$diagnostics){$diagnostics[]=array('code'=>$code,'node_id'=>$id,'severity'=>$severity);};
        $checkExact=function($selector,$expected,$source)use($nodes,$add,$d){
            if(($selector['kind']??null)!=='EXACT_OCCURRENCE')return;
            $previous=null;$last=null;
            foreach($selector['occurrence_key'] as $part){
                $id=$part['block_id'];$node=$nodes[$id]??null;
                if($node===null||$node['kind']!=='BLOCK'||($previous!==null&&$node['parent_id']!==$previous)){$add('INVALID_EXACT_OCCURRENCE_PATH',$source);return;}
                $r=$node['schedule']['recurrence']??null;$slot=$part['slot'];
                if($r===null&&$slot!=='single')$add('INVALID_EXACT_OCCURRENCE_SLOT',$source);
                if($r!==null&&($slot==='single'||($r['kind']==='DAILY_LOCAL'&&(!isset($slot['day'],$slot['slot'])||$slot['day']<$r['start_day']||$slot['day']>=$r['start_day']+$r['days']||$slot['slot']>count($r['times'])))||($r['kind']!=='DAILY_LOCAL'&&(!isset($slot['ordinal'])||(isset($r['count'])&&$slot['ordinal']>$r['count'])))))$add('INVALID_EXACT_OCCURRENCE_SLOT',$source);
                $previous=$id;$last=$id;
            }
            if($last!==$expected||$nodes[$selector['occurrence_key'][0]['block_id']]['parent_id']!==$d['id'])$add('INVALID_EXACT_OCCURRENCE_PATH',$source);
        };
        for ($i=1;$i<count($order);++$i) { $edges['traversal'][]=array($order[$i-1],$order[$i]); }
        foreach ($nodes as $id=>$n) {
            if (!isset($rank[$id])) { $add(($n['required']??false)?'UNREACHABLE_REQUIRED':'UNREACHABLE_OPTIONAL',$id,($n['required']??false)?'ERROR':'WARNING'); }
            if($n['kind']==='QUESTION'&&$n['semantic_role']==='INFORMED_CONSENT'&&!array_filter($d['condition_rules'],static function($rule)use($id){return $rule['source']['node_id']===$id&&$rule['action']==='TERMINATE';}))$add('CONSENT_WITHOUT_DECLINE_RULE',$id,'WARNING');
            if ($n['kind']!=='BLOCK') { continue; }
            if ($n['completion_boundary']==='ASSESSMENT') {
                foreach($desc[$id] as $child) {
                    $q=$nodes[$child];
                    if($q['kind']==='QUESTION' && isset($q['validation']['min'],$q['validation']['max']) && $q['validation']['min']>$q['validation']['max'])$add('IMPOSSIBLE_ASSESSMENT_CLOSURE',$child);
                }
            }
            $s=$n['schedule'];
            if ($n['completion_boundary']==='ASSESSMENT' && !array_filter($desc[$id]??array(),static function($child)use($nodes){return in_array($nodes[$child]['kind'],array('QUESTION','CONTENT'),true);})) { $add('IMPOSSIBLE_ASSESSMENT_CLOSURE',$id); }
            if ($s!==null) {
                if($s['anchor_ref']!==null)$checkExact($s['occurrence_selector'],$s['anchor_ref'],$id);
                if ($s['anchor_ref']!==null) { $dependencies[$id][]=$s['anchor_ref'];$edges['temporal'][]=array($s['anchor_ref'],$id,$s['anchor']); }
                if ($s['window']['kind']==='EVENT' && $s['window']['anchor_ref']!==null) { $edges['temporal'][]=array($s['window']['anchor_ref'],$id,'DEADLINE_EVENT'); }
                if ($s['recurrence']!==null) {
                    $edges['recurrence'][]=array($n['parent_id'],$id);
                    $p=$n['parent_id'];
                    while(isset($nodes[$p])) {
                        if (($nodes[$p]['schedule']['recurrence']??null)!==null && ($s['occurrence_selector']['kind']??null)!=='SAME_PARENT_OCCURRENCE') { $add('INVALID_NESTED_RECURRENCE',$id); }
                        $p=$nodes[$p]['parent_id'];
                    }
                }
                if ($s['temporal_rule']['kind']==='ABSOLUTE' && $s['window']['kind']==='ABSOLUTE' && TemporalResolver::absolute($s['window']['at'])<=TemporalResolver::absolute($s['temporal_rule']['at'])) { $add('IMPOSSIBLE_WINDOW',$id); }
                if (in_array($s['anchor'],array('BLOCK_COMPLETED','SPECIFIC_BLOCK_COMPLETED'),true) && isset($nodes[$s['anchor_ref']]) && in_array($s['anchor_ref'],$desc[$id]??array(),true)) { $add('IMPOSSIBLE_COMPLETION_DEPENDENCY',$id); }
            }
            if ($n['randomization']!==null) {
                $config=$n['randomization'];$pool=array_values(array_filter($children[$id]??array(),static function($child)use($nodes){return $nodes[$child]['kind']!=='DIRECTIVE';}));
                $segments[$id]=$config['segments']??array(array('id'=>'default','candidates'=>$pool));
                foreach($segments[$id] as &$segment){usort($segment['candidates'],static function($a,$b)use($rank){return $rank[$a]<=>$rank[$b];});}unset($segment);
            }
            foreach($children[$id]??array() as $index=>$child) {
                if($nodes[$child]['kind']==='DIRECTIVE'&&$nodes[$child]['directive_kind']==='TERMINATE') {
                    foreach(array_slice($children[$id],$index+1) as $after)foreach(array_merge(array($after),$desc[$after]) as $unreachable) {
                        if($nodes[$unreachable]['kind']==='QUESTION')$add(($nodes[$unreachable]['required']?'UNREACHABLE_REQUIRED':'UNREACHABLE_OPTIONAL'),$unreachable,$nodes[$unreachable]['required']?'ERROR':'WARNING');
                    }
                }
            }
        }
        $active=array();$done=array();
        $dag=function($id)use(&$dag,&$active,&$done,$dependencies,$nodes,$add){
            if(isset($active[$id])){$add(($nodes[$id]['schedule']['recurrence']??null)!==null?'RECURRENCE_DEPENDENCY_CYCLE':'TEMPORAL_CYCLE',$id);return;}
            if(isset($done[$id])){return;} $active[$id]=true;
            foreach($dependencies[$id]??array() as $ref){$dag($ref);} unset($active[$id]);$done[$id]=true;
        };foreach($nodes as $id=>$n){$dag($id);}
        $rules=$d['condition_rules'];
        $completionPredicates=array();
        foreach($rules as $rule){if($rule['trigger']==='ON_ASSESSMENT_COMPLETE'){$key=$rule['source']['node_id'].'|'.Canonical::json($rule['predicate']);if(isset($completionPredicates[$key]))$add('COMPLETION_RULES_OVERLAP',$rule['id'],'WARNING');$completionPredicates[$key]=true;}}
        foreach($nodes as $node){if($node['kind']==='DIRECTIVE'&&$node['directive_kind']!=='PAGE_BREAK')$rules[]=array('id'=>$node['id'],'source'=>array('kind'=>'QUESTION','node_id'=>$node['id']),'action'=>$node['directive_kind'],'target'=>$node['target'],'resume_target'=>$node['resume_target'],'predicate'=>array());}
        foreach ($rules as $rule) {
            $source=$rule['source']['node_id'];$edges['condition'][]=array($source,$rule['id']);
            $refs=$this->references($rule['predicate']);
            foreach($refs as $ref)$checkExact($ref['occurrence_selector'],$nodes[$ref['node_id']]['kind']==='QUESTION'?$owners[$ref['node_id']]:$ref['node_id'],$rule['id']);
            foreach(array('target','resume_target') as $field){if(($rule[$field]??null)!==null){$t=$rule[$field];$checkExact($t['occurrence_selector'],$nodes[$t['node_id']]['kind']==='BLOCK'?$t['node_id']:$owners[$t['node_id']],$rule['id']);}}
            foreach($refs as $ref){$edges['condition'][]=array($ref['node_id'],$rule['id']);}
            foreach($nodes as $owner=>$randomNode){if(($randomNode['randomization']??null)===null||$randomNode['children_strategy']==='RANDOM_ORDER')continue;foreach($refs as $ref){if(in_array($ref['node_id'],$desc[$owner],true)&&!in_array($source,$desc[$owner],true))$add('OPTIONAL_REFERENCE',$rule['id'],'WARNING');}}
            if ($rule['target']===null) { continue; }
            $target=$rule['target']['node_id'];$kind=$rule['action']==='WAIT'?'wait':'jump';$edges[$kind][]=array($source,$target,$rule['id']);
            if($rule['action']==='JUMP'&&$rule['target']['kind']==='ASSESSMENT_ENTRY'){
                $sourceSchedule=$nodes[$owners[$source]??$source]['schedule']??null;$targetSchedule=$nodes[$target]['schedule'];
                if($sourceSchedule!==null&&$sourceSchedule['window']['kind']==='ABSOLUTE'&&$targetSchedule['temporal_rule']['kind']==='ABSOLUTE'&&TemporalResolver::absolute($targetSchedule['temporal_rule']['at'])>=TemporalResolver::absolute($sourceSchedule['window']['at']))$add('TARGET_NOT_ACTIONABLE_BY_DEFINITION',$rule['id']);
            }
            $futureOccurrence=($owners[$source]??null)===$owners[$target]&&$rule['target']['kind']==='ASSESSMENT_ENTRY'&&$rule['target']['occurrence_selector']['kind']==='EXACT_OCCURRENCE';
            if ($rank[$target]<=$rank[$source]&&!$futureOccurrence) { $add('BACKWARD_'.$rule['action'],$rule['id']); }
            if($nodes[$target]['kind']==='BLOCK' && !array_filter($desc[$target],static function($id)use($nodes){return in_array($nodes[$id]['kind'],array('QUESTION','CONTENT'),true);}))$add('TARGET_NOT_ACTIONABLE_BY_DEFINITION',$rule['id']);
            if ($rule['action']==='WAIT') {
                $stack=$dependencies[$target]??array();$seen=array();
                while($stack){$ref=array_pop($stack);if(isset($seen[$ref]))continue;$seen[$ref]=true;
                    if($rank[$ref]>$rank[$source] && $rank[$ref]<$rank[$target] && in_array($nodes[$target]['schedule']['anchor'],array('BLOCK_COMPLETED','SPECIFIC_BLOCK_COMPLETED','BLOCK_STARTED'),true)){$add('WAIT_SKIPS_TARGET_DEPENDENCY',$rule['id']);}
                    $stack=array_merge($stack,$dependencies[$ref]??array());
                }
            }
            foreach ($nodes as $owner=>$randomNode) {
                if (($randomNode['randomization']??null)===null) { continue; }
                $inSource=in_array($source,$desc[$owner],true);$inTarget=in_array($target,$desc[$owner],true);
                if ($inSource && $inTarget && $randomNode['children_strategy']==='RANDOM_ORDER') { $add('AMBIGUOUS_EFFECTIVE_TARGET',$rule['id']); }
                if($inSource&&$inTarget&&$randomNode['children_strategy']==='RANDOM_SELECT_ONE') {
                    $branch=function($id)use($nodes,$owner){while($nodes[$id]['parent_id']!==$owner)$id=$nodes[$id]['parent_id'];return $id;};
                    if($branch($source)!==$branch($target))$add('INCOMPATIBLE_RANDOM_TARGET',$rule['id']);
                }
                if ($inTarget && !$inSource && $randomNode['children_strategy']!=='RANDOM_ORDER') { $add('RANDOM_TARGET_NOT_GUARANTEED',$rule['id']); }
                if(($rule['resume_target']??null)!==null&&in_array($rule['resume_target']['node_id'],$desc[$owner],true)&&$randomNode['children_strategy']!=='RANDOM_ORDER')$add('RANDOM_RESUME_TARGET_NOT_GUARANTEED',$rule['id']);
            }
            if ($rule['action']==='JUMP') { $add('REQUIRED_MAY_BE_SKIPPED',$rule['id'],'WARNING'); }
        }
        // Sequential traversal cannot reach an earlier assessment that requires a later completion.
        foreach($nodes as $id=>$node) {
            if($node['kind']!=='BLOCK'||$node['schedule']===null||!in_array($node['schedule']['anchor'],array('BLOCK_COMPLETED','SPECIFIC_BLOCK_COMPLETED','BLOCK_STARTED'),true))continue;
            $ref=$node['schedule']['anchor_ref'];$parent=$node['parent_id'];
            if(isset($nodes[$parent])&&$nodes[$parent]['children_strategy']==='SEQUENTIAL'&&$nodes[$ref]['parent_id']===$parent&&$nodes[$ref]['position']>$node['position'])$add('NO_TERMINAL_PATH',$id);
        }
        $assessments=array_filter($nodes,static function($n){return $n['kind']==='BLOCK'&&$n['completion_boundary']==='ASSESSMENT';});
        if (!$assessments) { $add('NO_TERMINAL_PATH',$d['id']); }
        $diagnostics=array_merge($diagnostics,StudyPolicy::diagnostics($d,$nodes),LogicalConditions::diagnostics($d));
        usort($diagnostics,static function($a,$b){return strcmp(Canonical::json($a),Canonical::json($b));});
        if(array_filter($diagnostics,static function($d){return $d['severity']==='ERROR';})){throw new CompilationException($diagnostics);}
        return new CompiledStudy($d,array('compiler_version'=>'P1C_V1','nodes'=>$nodes,'children'=>$children,'assessment_owner'=>$owners,'descendants'=>$desc,'order'=>$order,'edges'=>$edges,'temporal_dependencies'=>$dependencies,'segments'=>$segments,'diagnostics'=>$diagnostics));
    }
    private function references(array $ast): array {
        $refs=array(); if(isset($ast['reference'])){$refs[]=$ast['reference'];}
        foreach(array('left','right') as $side){if(isset($ast[$side]['reference'])){$refs[]=$ast[$side]['reference'];}}
        foreach($ast['args']??array() as $arg){$refs=array_merge($refs,$this->references($arg));}
        if(isset($ast['arg'])){$refs=array_merge($refs,$this->references($ast['arg']));}return $refs;
    }
}
