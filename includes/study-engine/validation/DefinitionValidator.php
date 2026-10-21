<?php
namespace EIPSI\StudyEngine\Validation;
use EIPSI\StudyEngine\Contracts as C;
use EIPSI\StudyEngine\Domain as D;

/** Deterministic structural errors only. No execution/compiler/simulator. */
final class DefinitionValidator {
    private $issues = array();
    public function validate(array $data): array {
        $this->issues = array();
        try {
            C\Check::fields($data,array('contract_version','study','id','version_number','state','timezone','root_block_ids','nodes','condition_rules','compiler_contract_version','protocol_metadata','closure'));
            if ($data['contract_version']!=='P1B_V1' || $data['compiler_contract_version']!=='P1C_V1') { C\Check::fail('INVALID_CONTRACT_VERSION','contract_version','Known contract required'); }
            C\Check::id($data['id'],'id'); C\Check::integer($data['version_number'],'version_number',1); C\Check::set($data['state'],D\StudyVersionState::class,'state');
            C\Check::fields($data['study'],array('logical_id','public_id')); C\Check::id($data['study']['logical_id'],'study.logical_id'); C\Check::id($data['study']['public_id'],'study.public_id');
            C\Check::fields($data['timezone'],array('policy','default')); C\Check::set($data['timezone']['policy'],D\TimezonePolicy::class,'timezone.policy'); C\Check::timezone($data['timezone']['default'],'timezone.default');
            foreach (array('nodes','condition_rules','root_block_ids') as $k) { C\Check::listOf($data[$k],$k); }
            if (!$data['nodes']||!$data['root_block_ids']) { C\Check::fail('EMPTY_STUDY','root_block_ids','At least one root Macro required'); }
            if (!is_array($data['protocol_metadata'])) { C\Check::fail('INVALID_METADATA','protocol_metadata','JSON metadata required'); }
            if(array_key_exists('content_language',$data['protocol_metadata'])&&!in_array($data['protocol_metadata']['content_language'],array('en','es'),true))C\Check::fail('INVALID_CONTENT_LANGUAGE','protocol_metadata.content_language','English or Spanish content language required');
            C\Check::fields($data['closure'],array('kind'),array('duration'));
            if ($data['closure']['kind']==='OVERALL_DURATION') { C\Check::fields($data['closure'],array('kind','duration')); C\Shape::duration($data['closure']['duration']); }
            elseif ($data['closure']['kind']!=='ALL_SELECTED_OPPORTUNITIES_TERMINAL' || isset($data['closure']['duration'])) { C\Check::fail('INVALID_CLOSURE','closure','Explicit structural or overall closure required'); }
        } catch (C\ContractViolation $e) { return $e->issues(); }
        $nodes=array(); $positions=array(); $rules=array(); $rootIds=$data['root_block_ids'];
        foreach ($rootIds as $id) { try { C\Check::id($id,'root_block_ids'); } catch (C\ContractViolation $e) { return $e->issues(); } }
        if (count(array_unique($rootIds,SORT_REGULAR))!==count($rootIds)) { $this->error('DUPLICATE_ROOT','root_block_ids'); }
        foreach ($data['nodes'] as $i=>$node) {
            try {
                if (!is_array($node)) { C\Check::fail('INVALID_NODE','nodes','Node record required'); }
                $classes=array('BLOCK'=>C\BlockDefinition::class,'QUESTION'=>C\QuestionDefinition::class,'CONTENT'=>C\ContentNode::class,'DIRECTIVE'=>C\FlowDirective::class);
                $kind=$node['kind']??null; C\Check::set($kind,D\NodeKind::class,'nodes.kind');
                if (!isset($classes[$kind??''])) { C\Check::fail('INVALID_NODE_KIND','nodes','Unknown node kind'); }
                new $classes[$kind]($node);
            } catch (C\ContractViolation $e) { $this->merge($e); continue; }
            $id=$node['id'];
            if ($id===$data['id']) { $this->error('DUPLICATE_ID',$id); }
            if (isset($nodes[$id])) { $this->error('DUPLICATE_ID',$id); continue; }
            $nodes[$id]=$node;
            if ($node['version_id']!==$data['id']) { $this->error('VERSION_MISMATCH',$id); }
            $key=$node['parent_id'].':'.$node['position'];
            if (isset($positions[$key])) { $this->error('DUPLICATE_SIBLING_POSITION',$id); } $positions[$key]=true;
        }
        foreach ($rootIds as $id) {
            if (!isset($nodes[$id])) { $this->error('INVALID_ROOT',$id); continue; }
            $n=$nodes[$id];
            if ($n['kind']!=='BLOCK'||$n['scope']!=='MACRO'||$n['parent_id']!==$data['id']) { $this->error('ROOT_NOT_MACRO',$id); }
        }
        $responseKeys=array();
        foreach ($nodes as $id=>$node) {
            $parent=$node['parent_id'];
            if ($parent===$data['id']) {
                if (!in_array($id,$rootIds,true)) { $this->error('UNLISTED_ROOT',$id); }
                if ($node['kind']!=='BLOCK'||($node['scope']??null)!=='MACRO') { $this->error('ROOT_NOT_MACRO',$id); }
            } elseif (!isset($nodes[$parent])||$nodes[$parent]['kind']!=='BLOCK') { $this->error('INVALID_PARENT',$id); }
            else {
                $p=$nodes[$parent];
                if ($p['scope']==='MACRO'&&$node['kind']!=='BLOCK') { $this->error('MACRO_DIRECT_CONTENT',$id); }
                if ($p['scope']==='MICRO'&&$node['kind']==='BLOCK'&&$node['scope']==='MACRO') { $this->error('MICRO_CONTAINS_MACRO',$id); }
                if ($node['kind']==='BLOCK'&&$node['scope']==='MICRO') {
                    $expected=$p['scope']==='MACRO'?'ASSESSMENT':'GROUP';
                    if ($node['completion_boundary']!==$expected) { $this->error('INVALID_ASSESSMENT_BOUNDARY',$id); }
                    if ($expected==='GROUP' && $node['schedule']!==null) { $this->error('INTERNAL_MICRO_SCHEDULE',$id); }
                }
            }
            $visited=array();$current=$id;
            while (isset($nodes[$current])) {
                if (isset($visited[$current])) { $this->error('PARENT_CYCLE',$id); break; }
                $visited[$current]=true; $current=$nodes[$current]['parent_id'];
            }
            if ($node['kind']==='BLOCK') {
                if ($node['scope']==='MACRO'&&$node['completion_boundary']!=='NONE') { $this->error('INVALID_ASSESSMENT_BOUNDARY',$id); }
                if (($node['scope']==='MACRO'||$node['completion_boundary']==='ASSESSMENT')&&$node['schedule']===null) { $this->error('MISSING_SCHEDULE',$id); }
                if ($node['schedule']!==null) { $this->scheduleRefs($node['schedule'],$nodes,$id); }
                if ($node['randomization']!==null) {
                    $candidates=$node['randomization']['candidates'];
                    foreach ($candidates as $child) {
                        if (!isset($nodes[$child])||$nodes[$child]['parent_id']!==$id||$nodes[$child]['kind']==='DIRECTIVE') { $this->error('INVALID_RANDOM_CANDIDATE',$child); }
                    }
                    $children=array_filter($nodes,static function($n)use($id){return $n['parent_id']===$id;});
                    $eligible=array_keys(array_filter($children,static function($n){return $n['kind']!=='DIRECTIVE';}));
                    if (array_diff($eligible,$candidates)) { $this->error('INCOMPLETE_RANDOM_CANDIDATES',$id); }
                    if (count($children)!==count($eligible) && !isset($node['randomization']['segments'])) { $this->error('RANDOM_DIRECTIVE_SEGMENT_AMBIGUOUS',$id); }
                    if (isset($node['randomization']['segments'])) {
                        $segments=$node['randomization']['segments'];
                        $ordered=array_values($children); usort($ordered,static function($a,$b){return $a['position']<=>$b['position'];});
                        $expected=array(); $part=array();
                        foreach ($ordered as $child) {
                            if ($child['kind']==='DIRECTIVE') { if ($part) { $expected[]=$part; $part=array(); } }
                            else { $part[]=$child['id']; }
                        }
                        if ($part) { $expected[]=$part; }
                        $actual=array(); foreach ($segments as $segment) { $members=$segment['candidates'];sort($members,SORT_STRING);$actual[]=$members; }
                        foreach ($expected as &$members) { sort($members,SORT_STRING); } unset($members);
                        if (C\Canonical::json($actual)!==C\Canonical::json($expected)) { $this->error('RANDOM_SEGMENT_CROSSES_DIRECTIVE',$id); }
                    }
                }
            } elseif ($node['kind']==='QUESTION') {
                if (isset($responseKeys[$node['response_key']])) { $this->error('DUPLICATE_RESPONSE_KEY',$id); } $responseKeys[$node['response_key']]=true;
            } elseif ($node['kind']==='DIRECTIVE') {
                if ($node['target']!==null) { $this->target($node['target'],$nodes,$id); }
                if ($node['resume_target']!==null) { $this->target($node['resume_target'],$nodes,$id); $this->resume($node['target'],$node['resume_target'],$nodes,$id); }
            }
        }
        $orders=array();
        foreach ($data['condition_rules'] as $rule) {
            try { if (!is_array($rule)) { C\Check::fail('INVALID_RULE','rules','Record required'); } new C\ConditionRule($rule); } catch(C\ContractViolation $e) { $this->merge($e); continue; }
            $id=$rule['id'];
            if (isset($nodes[$id])||isset($rules[$id])||$id===$data['id']) { $this->error('DUPLICATE_ID',$id); } $rules[$id]=true;
            if ($rule['version_id']!==$data['id']) { $this->error('VERSION_MISMATCH',$id); }
            $source=$rule['source']['node_id']; $n=$nodes[$source]??null;
            if (!$n||$n['kind']!==$rule['source']['kind']) { $this->error('INVALID_RULE_SOURCE',$id); }
            if ($rule['trigger']==='ON_ASSESSMENT_COMPLETE'&&(!$n||$n['kind']!=='BLOCK'||($n['completion_boundary']??null)!=='ASSESSMENT')) { $this->error('INVALID_COMPLETION_SOURCE',$id); }
            $key=$rule['trigger'].':'.$source.':'.$rule['order'];
            if ($rule['trigger']==='IMMEDIATE'&&isset($orders[$key])) { $this->error('DUPLICATE_IMMEDIATE_ORDER',$id); } $orders[$key]=true;
            $this->selectorFor($source,$rule['source']['occurrence_selector'],$nodes,$id);
            $this->predicateRefs($rule['predicate'],$nodes,$id);
            if ($rule['target']!==null) { $this->target($rule['target'],$nodes,$id); }
            if ($rule['resume_target']!==null) { $this->target($rule['resume_target'],$nodes,$id); $this->resume($rule['target'],$rule['resume_target'],$nodes,$id); }
        }
        usort($this->issues,static function($a,$b){return strcmp(C\Canonical::json($a),C\Canonical::json($b));});
        return $this->issues;
    }
    private function error(string $code,string $path): void { $this->issues[]=array('code'=>$code,'path'=>$path,'message'=>$code); }
    private function merge(C\ContractViolation $e): void { $this->issues=array_merge($this->issues,$e->issues()); }
    private function scheduleRefs(array $s,array $nodes,string $owner): void {
        if ($s['anchor_ref']!==null) {
            $id=$s['anchor_ref'];
            if (!isset($nodes[$id])||$nodes[$id]['kind']!=='BLOCK') { $this->error('INVALID_ANCHOR_REF',$owner); }
            if ($id===$owner) { $this->error('SELF_AVAILABILITY_ANCHOR',$owner); }
            $this->selectorFor($id,$s['occurrence_selector'],$nodes,$owner);
        }
        if ($s['window']['kind']==='EVENT'&&$s['window']['anchor_ref']!==null) {
            $id=$s['window']['anchor_ref']; if (!isset($nodes[$id])||$nodes[$id]['kind']!=='BLOCK') { $this->error('INVALID_ANCHOR_REF',$owner); }
            $this->selectorFor($id,$s['window']['occurrence_selector'],$nodes,$owner);
        }
    }
    private function selectorFor(string $id,array $selector,array $nodes,string $owner): void {
        $visited=array();$current=$id;$recurrent=false;
        while (isset($nodes[$current])&&!isset($visited[$current])) {
            $visited[$current]=true; $n=$nodes[$current];
            if ($n['kind']==='BLOCK'&&$n['schedule']!==null&&$n['schedule']['recurrence']!==null) { $recurrent=true; }
            $current=$n['parent_id'];
        }
        if ($recurrent&&$selector['kind']==='SINGLE') { $this->error('AMBIGUOUS_RECURRENT_REFERENCE',$owner); }
        if ($selector['kind']==='EXACT_OCCURRENCE') {
            $segments=$selector['occurrence_key'];$last=end($segments);
            $block=$id;
            while (isset($nodes[$block])&&$nodes[$block]['kind']!=='BLOCK') { $block=$nodes[$block]['parent_id']; }
            if ($last['block_id']!==$block) { $this->error('OCCURRENCE_TARGET_MISMATCH',$owner); }
        }
    }
    private function target(array $t,array $nodes,string $owner): void {
        $n=$nodes[$t['node_id']]??null;
        if (!$n) { $this->error('TARGET_NOT_FOUND',$owner); return; }
        $valid=($t['kind']==='QUESTION'&&$n['kind']==='QUESTION')||($t['kind']==='CONTENT_POSITION'&&in_array($n['kind'],array('QUESTION','CONTENT','DIRECTIVE'),true))||($t['kind']==='MICRO_ENTRY'&&$n['kind']==='BLOCK'&&$n['scope']==='MICRO')||($t['kind']==='ASSESSMENT_ENTRY'&&$n['kind']==='BLOCK'&&$n['scope']==='MICRO'&&$n['completion_boundary']==='ASSESSMENT');
        if (!$valid) { $this->error('INVALID_TARGET_TYPE',$owner); }
        $this->selectorFor($t['node_id'],$t['occurrence_selector'],$nodes,$owner);
    }
    private function resume(array $target,array $resume,array $nodes,string $owner): void {
        $current=$resume['node_id'];$seen=array();
        while (isset($nodes[$current])&&!isset($seen[$current])) {
            if ($current===$target['node_id']) { return; } $seen[$current]=true;$current=$nodes[$current]['parent_id'];
        }
        $this->error('INVALID_WAIT_RESUME',$owner);
    }
    private function predicateRefs(array $p,array $nodes,string $owner): void {
        if (isset($p['args'])) { foreach($p['args'] as $arg){$this->predicateRefs($arg,$nodes,$owner);} }
        if (isset($p['arg'])) { $this->predicateRefs($p['arg'],$nodes,$owner); }
        foreach(array('reference','left','right')as$key) {
            if (!isset($p[$key])) { continue; }
            $r=$key==='reference'?$p[$key]:($p[$key]['reference']??null);
            if ($r===null) { continue; } $n=$nodes[$r['node_id']]??null;
            if (!$n||($r['kind']==='QUESTION'&&$n['kind']!=='QUESTION')||($r['kind']!=='QUESTION'&&$n['kind']!=='BLOCK')) { $this->error('INVALID_PREDICATE_REFERENCE',$owner); }
            $this->selectorFor($r['node_id'],$r['occurrence_selector'],$nodes,$owner);
        }
    }
}
