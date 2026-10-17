<?php
namespace EIPSI\StudyEngine\Engine;
use EIPSI\StudyEngine\Contracts\Canonical;

/** A persisted dependency plan. Dates, branch decisions and availability are independent. */
trait ExecutionPlanning {
    private function dependencyPlanning(): bool {return ($this->graph->definition()['protocol_metadata']['execution_planning']??null)==='DEPENDENCY_PLAN_V1';}
    private function planningRulePending(array $rule,array $instance): bool {
        $state=$this->logicalState();$found=false;
        foreach($this->state->instances as $source){
            if($source['definition_id']!==$rule['source']['assessment_id'])continue;$found=true;
            if(!isset($rule['_intra'])&&!self::terminal($source['state'])&&!isset($state['completed_conditions'][$source['id'].'|'.$rule['id']]))return true;
            if(isset($state['completed_conditions'][$source['id'].'|'.$rule['id']])||isset($state['applied'][$source['id'].'|'.$rule['id']])||isset($state['settled'][$source['id'].'|'.$rule['id']])||self::terminal($source['state']))continue;
            return true;
        }
        if($found)return false;
        // A source behind an unresolved anchor or recurrence is still a dependency.
        return true;
    }
    private function planningPending(string $target,array $instance,?string $native=null): array {
        $pending=array();foreach(LogicalConditions::rules($this->graph->definition()) as $rule){foreach($rule['actions'] as $action){
            if(!in_array($action['type'],array('RESTRICT_RANDOM','EXCLUDE_RANDOM','FORCE_RANDOM'),true)||($action['target_id']??null)!==$target||($action['randomization_id']??null)!==$native)continue;
            if($native!==null&&$rule['source']['assessment_id']===$target)continue;
            if($this->planningRulePending($rule,$instance))$pending[]=$rule['id'];
        }}return array_values(array_unique($pending));
    }
    private function planningIntraAction(array $action,string $assessment): bool {return in_array($action['type'],array('RESTRICT_RANDOM','EXCLUDE_RANDOM','FORCE_RANDOM'),true)&&($action['target_id']??null)===$assessment&&isset($action['randomization_id']);}
    private function planningExternalAction(array $action,string $assessment): bool {return in_array($action['type'],array('RESTRICT_RANDOM','EXCLUDE_RANDOM','FORCE_RANDOM'),true)&&!$this->planningIntraAction($action,$assessment);}
    /** Rebuild current intra-assessment eligibility from the original pool, never from yesterday's restriction. */
    private function responsiveNativeControls(array $source,string $event): void {
        if(!$this->dependencyPlanning()||self::terminal($source['state']))return;$assessment=$source['definition_id'];$rules=array();$keys=array();
        foreach(LogicalConditions::rules($this->graph->definition()) as $rule){if($rule['source']['assessment_id']!==$assessment||!in_array($rule['event'],array('ANSWER',$event),true))continue;$actions=array();foreach($rule['actions'] as $action)if($this->planningIntraAction($action,$assessment)){$actions[]=$action;$keys[$assessment.'|'.$action['randomization_id']]=$action['randomization_id'];}if($actions){$rule['actions']=$actions;$rules[]=$rule;}}
        if(!$keys)return;$state=$this->logicalState();$before=array();foreach($keys as $key=>$random){$before[$key]=$state['random_controls'][$key]??null;unset($state['random_controls'][$key]);}$this->context['logical_state']=$state;
        $resolution=LogicalConditions::resolve($rules,function($ref,$rule)use($state){return $this->logicalScopedLookup($ref,$state,$rule);},function($rule,$action)use($state){return $this->logicalFutureAllows($rule,$action,$state);});
        foreach($resolution['actions'] as $entry)$this->logicalApply($entry+array('responsive_native'=>true),$source);
        $state=$this->logicalState();foreach($keys as $key=>$random){$old=$before[$key];$new=$state['random_controls'][$key]??null;$pick=static function($control){return $control===null?null:array('candidates'=>$control['candidates'],'forced'=>$control['forced']);};
            if(Canonical::json($pick($old))===Canonical::json($pick($new))){if($old!==null)$state['random_controls'][$key]=$old;continue;}
            $catalog=$this->graph->node($assessment)['metadata']['native_randomizations']??array();$invalid=array($random);foreach($catalog as $config)foreach($config['randomization_path']??array() as $path)if(in_array($path['randomization_id'],$invalid,true))$invalid[]=$config['id'];
            foreach($invalid as $id){unset($state['native_plans'][$source['id']][$id],$state['native_randomizations'][$assessment]['blocks'][$id]);}
            $this->emit('NATIVE_RANDOM_ELIGIBILITY_CHANGED',$source['id'],null,null,$new['condition_id']??null,null,'RESPONSIVE_SAME_SEED',array('randomization_id'=>$random,'before'=>$pick($old),'after'=>$pick($new)));
        }$this->context['logical_state']=$state;
    }
    private function planningBranchSelected(array $instance): bool {
        $child=$instance;while($child['parent_execution_id']!==null){$parent=$this->state->instances[$child['parent_execution_id']];if(self::terminal($parent['state'])&&$parent['state']!=='COMPLETED')return false;
            $node=$this->graph->node($parent['definition_id']);if($node['randomization']!==null){$known=false;$selected=false;foreach($this->state->decisions as $decision){if($decision['owner_id']===$node['id']&&($decision['block_execution_id']===null||$decision['block_execution_id']===$parent['id'])){if(in_array($child['definition_id'],$decision['eligible_candidates'],true)||in_array($child['definition_id'],$node['randomization']['candidates'],true)){$known=true;$selected=$selected||in_array($child['definition_id'],$decision['selection'],true);}}}if(!$known||!$selected)return false;}$child=$parent;
        }return true;
    }
    private function refreshDependencyPlan(): void {
        if(!$this->dependencyPlanning())return;
        $plan=array('contract'=>'DEPENDENCY_PLAN_V1','randomizations'=>array(),'opportunities'=>array());
        foreach($this->graph->order() as $definition){$node=$this->graph->node($definition);if($node['kind']!=='BLOCK')continue;
            foreach($this->state->instances as $id=>$instance){if($instance['definition_id']!==$definition)continue;
                if($node['randomization']!==null&&!self::terminal($instance['state'])&&$this->planningBranchSelected($instance)){
                    $pending=$this->planningPending($definition,$instance);$plan['randomizations'][$id]=array('owner_id'=>$definition,'pending_conditions'=>$pending,'status'=>$pending?'PENDING_CONDITION':'RESOLVED');if(!$pending)$this->decide($id);
                }
                if($node['completion_boundary']==='ASSESSMENT'){
                    $selected=$this->planningBranchSelected($instance);$current=$this->state->instances[$id];$plan['opportunities'][$id]=array('definition_id'=>$definition,'available_at'=>$current['available_at'],'due_at'=>$current['due_at'],'status'=>self::terminal($current['state'])?$current['state']:(!$selected?'PENDING_BRANCH':($current['available_at']===null?'PENDING_ANCHOR':'PLANNED')));
                    if($selected&&!self::terminal($current['state']))$this->planNativeRandomizations($node,$current,$plan);
                }
            }
        }
        $this->context['dependency_plan']=$plan;
    }
    private function planNativeRandomizations(array $node,array $instance,array &$plan): void {
        $state=$this->logicalState();$records=$state['native_plans'][$instance['id']]??array();
        foreach($node['metadata']['native_randomizations']??array() as $r){
            $key=$instance['id'].'|'.$r['id'];if(!isset($this->context['native_plan_seeds'][$key]))$this->context['native_plan_seeds'][$key]=hash_hmac('sha256','DEPENDENCY_PLAN_V1|'.$key,$this->context['seed_hex']??str_repeat('00',32));$pending=$this->planningPending($node['id'],$instance,$r['id']);$ancestry=true;
            foreach($r['randomization_path']??array() as $path){if(!isset($records[$path['randomization_id']])||!in_array($path['candidate_id'],$records[$path['randomization_id']]['order'],true)){$ancestry=false;break;}}
            if(isset($records[$r['id']])){$plan['randomizations'][$key]=array('owner_id'=>$node['id'],'randomization_id'=>$r['id'],'pending_conditions'=>array(),'status'=>'RESOLVED');continue;}
            $plan['randomizations'][$key]=array('owner_id'=>$node['id'],'randomization_id'=>$r['id'],'pending_conditions'=>$pending,'status'=>!$ancestry?'PENDING_BRANCH':($pending?'PENDING_CONDITION':'RESOLVED'));
            if($pending||!$ancestry)continue;
            require_once dirname(__DIR__,2).'/assessment-editor/Randomization.php';
            $seed=$this->context['native_plan_seeds'][$key];
            $control=$state['random_controls'][$node['id'].'|'.$r['id']]??null;
            $priorRevision=$state['native_plan_revisions'][$instance['id']][$r['id']]??0;
            $records[$r['id']]=\EIPSI\AssessmentEditor\Randomization::plan($r,$seed,strtotime($this->clock->now())*1000,$control);
            $records[$r['id']]['plan_revision']=$priorRevision+1;$state['native_plan_revisions'][$instance['id']][$r['id']]=$priorRevision+1;
            $this->emit('NATIVE_RANDOM_PLANNED',$instance['id'],null,null,null,null,'DEPENDENCY_PLAN_V1',array('randomization_id'=>$r['id'],'order'=>$records[$r['id']]['order']));$this->context['pending_logical_random']=true;
        }
        if(!isset($state['current_occurrence'][$node['id']])||$state['current_occurrence'][$node['id']]===$instance['id'])foreach($node['metadata']['native_questions']??array() as $question){$selected=true;$known=true;foreach($question['randomization_path']??array() as $path){if(!isset($records[$path['randomization_id']])){$known=false;break;}if(!in_array($path['candidate_id'],$records[$path['randomization_id']]['order'],true)){$selected=false;break;}}
            if(!$known)continue;$id=$question['id'];$state['question_presence'][$node['id']][$id]['selected']=$selected;
            if(!$selected){unset($state['answers'][$node['id']][$id]);$state['question_states'][$node['id']][$id]='not_selected_by_randomization';}
            elseif(($state['question_states'][$node['id']][$id]??null)==='not_selected_by_randomization')$state['question_states'][$node['id']][$id]='not_reached';
        }
        $state['native_plans'][$instance['id']]=$records;
        // Definition references point at the active opportunity, never overwrite its history.
        if(!isset($state['current_occurrence'][$node['id']]))$state['current_occurrence'][$node['id']]=$instance['id'];if($state['current_occurrence'][$node['id']]===$instance['id'])$state['native_randomizations'][$node['id']]=array('blocks'=>$records);
        $this->context['logical_state']=$state;
    }
}
