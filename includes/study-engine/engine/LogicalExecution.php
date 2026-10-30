<?php
namespace EIPSI\StudyEngine\Engine;
use EIPSI\StudyEngine\Contracts\Canonical;

/** Included in Simulator: all mutations share its rollback/checkpoint boundary. */
trait LogicalExecution {
    private function logicalState(): array {return array_replace(array('answers'=>array(),'question_states'=>array(),'applied'=>array(),'audit'=>array(),'random_controls'=>array(),'cancelled_blocks'=>array(),'cancelled_opportunities'=>array(),'navigation'=>null,'waits'=>array(),'question_presence'=>array(),'occurrences'=>array(),'native_randomizations'=>array(),'current_occurrence'=>array(),'native_plans'=>array(),'settled'=>array(),'completed_conditions'=>array(),'action_receipts'=>array(),'native_plan_revisions'=>array()),$this->context['logical_state']??array());}
    private function logicalLookup(array $ref,array $state): array {
        $kind=$ref['kind'];$known=false;$value=null;
        if(in_array($kind,array('ANSWER','QUESTION_STATE','QUESTION_SHOWN','QUESTION_SELECTED'),true)){$assessment=$ref['assessment_id'];$question=$ref['question_id'];$status=$state['question_states'][$assessment][$question]??null;if($kind==='QUESTION_STATE'){$known=$status!==null;$value=$status;}elseif($kind==='QUESTION_SELECTED'){$value=$state['question_presence'][$assessment][$question]['selected']??null;$known=$value!==null;}elseif($kind==='QUESTION_SHOWN'){$known=$status!==null;$value=$state['question_presence'][$assessment][$question]['shown']??in_array($status,array('answered','unanswered'),true);}else{$known=$status==='answered'&&array_key_exists($question,$state['answers'][$assessment]??array());$value=$known?$state['answers'][$assessment][$question]:null;}}
        elseif($kind==='BLOCK_STATE'){foreach($this->state->instances as $i)if($i['definition_id']===$ref['node_id']){$known=true;$value=$i['state'];}}
        elseif(isset($ref['assessment_id'])){$record=$state['native_randomizations'][$ref['assessment_id']]['blocks'][$ref['randomization_id']]??null;if($record){$known=true;$value=$kind==='RANDOM_RESOLVED'?true:in_array($ref['candidate_id'],$record['order'],true);}}
        else{foreach($this->state->decisions as $decision)if($decision['owner_id']===$ref['randomization_id']){$known=true;$value=$kind==='RANDOM_RESOLVED'?true:in_array($ref['candidate_id'],$decision['selection'],true);}}
        return array('known'=>$known,'value'=>$value);
    }
    private function logicalPathContext(array $rule,array $state): array {
        $sourceId=$state['current_occurrence'][$rule['source']['assessment_id']]??null;$ancestors=array();$cursor=$this->state->instances[$sourceId]??null;
        while($cursor){$ancestors[$cursor['definition_id']]=$cursor['id'];$cursor=$this->state->instances[$cursor['parent_execution_id']]??null;}
        $choices=array();foreach($this->state->decisions as $decision){if(isset($ancestors[$decision['owner_id']])&&$decision['block_execution_id']!==null&&$decision['block_execution_id']!==$ancestors[$decision['owner_id']])continue;$choices[$decision['owner_id']]=array_values(array_unique(array_merge($choices[$decision['owner_id']]??array(),$decision['selection'])));}
        $native=array();foreach($state['native_randomizations'] as $assessment=>$record)foreach($record['blocks']??array() as $id=>$block)$native[$assessment.'|'.$id]=$block['order'];
        $times=array();$start=strtotime($this->state->execution['created_at']);foreach($this->state->instances as $i){$preferred=$state['current_occurrence'][$i['definition_id']]??null;if(!isset($times[$i['definition_id']])||$preferred===$i['id'])$times[$i['definition_id']]=$i['available_at']===null?PHP_INT_MAX:strtotime($i['available_at'])-$start;}
        return array($choices,$native,$times);
    }
    private function logicalPastAllows(array $rule,array $ref,array $state): bool {
        if(($rule['reference_scope']??null)!=='TRACK_PAST_V1')return true;
        return LogicalPast::allows($this->graph->definition(),$rule,$ref,...$this->logicalPathContext($rule,$state));
    }
    private function logicalFutureAllows(array $rule,array $action,array $state): bool {
        if(($rule['action_scope']??null)!=='TRACK_FUTURE_V1')return true;
        return LogicalPast::actionAllows($this->graph->definition(),$rule,$action,...$this->logicalPathContext($rule,$state));
    }
    private function logicalScopedLookup(array $ref,array $state,array $rule): array {
        if(!$this->logicalPastAllows($rule,$ref,$state))return array('known'=>false,'value'=>null,'reason'=>'OUTSIDE_TRACK_PAST');
        return $this->logicalLookup($ref,$state);
    }
    private function logicalEvent(array $data): void {
        $instance=$data['block_execution_id']??null;if(isset($data['transition_id']))$this->context['logical_trigger_transition']=$data['transition_id'];if(!isset($this->state->instances[$instance]))throw new \DomainException('LOGICAL_SOURCE_UNAVAILABLE');
        $source=$this->state->instances[$instance];$node=$this->graph->node($source['definition_id']);$catalog=array_column($node['metadata']['native_questions']??array(),null,'id');
        $state=$this->logicalState();if(isset($data['native_randomizations'])){if(Canonical::json($state['native_randomizations'])!==Canonical::json($data['native_randomizations']))$this->context['pending_logical_random']=true;$state['native_randomizations']=array_replace($state['native_randomizations'],$data['native_randomizations']);}$assessment=$source['definition_id'];if(($state['current_occurrence'][$assessment]??null)!==$instance){$saved=$state['occurrences'][$instance]??array();$state['answers'][$assessment]=$saved['answers']??array();$state['question_states'][$assessment]=$saved['question_states']??array();$state['question_presence'][$assessment]=$saved['question_presence']??array();if(isset($state['native_plans'][$instance]))$state['native_randomizations'][$assessment]=array('blocks'=>$state['native_plans'][$instance]);$state['current_occurrence'][$assessment]=$instance;}
        foreach($data['question_states']??array() as $id=>$status){if(!isset($catalog[$id])||!in_array($status,array('answered','unanswered','not_reached','skipped_by_logic','not_selected_by_randomization'),true))throw new \DomainException('INVALID_NATIVE_QUESTION_STATE');$state['question_states'][$assessment][$id]=$status;}
        foreach($data['question_presence']??array() as $id=>$presence){if(!isset($catalog[$id])||!array_key_exists('selected',$presence)||!array_key_exists('shown',$presence)||($presence['selected']!==null&&!is_bool($presence['selected']))||!is_bool($presence['shown']))throw new \DomainException('INVALID_QUESTION_PRESENCE');$presence['shown']=$presence['shown']||($state['question_presence'][$assessment][$id]['shown']??false);$state['question_presence'][$assessment][$id]=$presence;}
        foreach($data['answers']??array() as $id=>$value){if(!isset($catalog[$id]))throw new \DomainException('INVALID_NATIVE_LOGICAL_ANSWER');$state['answers'][$assessment][$id]=$value;}
        if($this->dependencyPlanning()){foreach($state['question_states'][$assessment]??array() as $id=>$status)if($status==='not_selected_by_randomization')unset($state['answers'][$assessment][$id]);$this->context['logical_state']=$state;$this->responsiveNativeControls($source,$data['event']??'ANSWER');$state=$this->logicalState();}
        $rules=array_values(array_filter(LogicalConditions::rules($this->graph->definition()),function($r)use($assessment,$data,$state,$instance){return $r['source']['assessment_id']===$assessment&&(!isset($r['trigger'])||$this->logicalPastAllows($r,array('kind'=>'BLOCK_STATE','node_id'=>$r['trigger']['node_id']),$state))&&($r['event']===($data['event']??'ANSWER')||$this->dependencyPlanning()&&($data['event']??'ANSWER')==='COMPLETE'&&array_filter($r['actions'],function($action)use($assessment){return $this->planningExternalAction($action,$assessment);}))&&(!isset($r['trigger'])||($data['state_transition']['node_id']??null)===$r['trigger']['node_id']&&($data['state_transition']['state']??null)===$r['trigger']['state'])&&($this->dependencyPlanning()||!isset($state['applied'][$instance.'|'.$r['id']]))&&(!isset($r['trigger'])||!isset($state['settled'][$instance.'|'.$r['id']]))&&(!isset($r['source']['question_id'])||!in_array($r['event'],array('ANSWER','COMPLETE'),true)||in_array($r['source']['question_id'],$data['changed_questions']??array(),true));}));
        $resolution=LogicalConditions::resolve($rules,function($ref,$rule)use($state){return $this->logicalScopedLookup($ref,$state,$rule);},function($rule,$action)use($state){return $this->logicalFutureAllows($rule,$action,$state);});
        $this->context['logical_state']=$state;
        foreach($resolution['actions'] as $entry){if($this->state->execution['state']==='TERMINATED_BY_RULE')break;if($this->dependencyPlanning()){
            $action=$entry['action'];$receipt=$instance.'|'.$entry['condition_id'].'|'.$entry['index'];
            $reason=$this->planningIntraAction($action,$assessment)?'RESPONSIVE_NATIVE_EVALUATION':($this->planningExternalAction($action,$assessment)&&($data['event']??'ANSWER')!=='COMPLETE'?'DEFERRED_UNTIL_COMPLETION':(isset($this->logicalState()['action_receipts'][$receipt])?'ALREADY_APPLIED':null));
            if($reason!==null){foreach($resolution['audit'] as &$record)if($record['condition_id']===$entry['condition_id']){$record['applied']=array_values(array_filter($record['applied'],static function($a)use($action){return Canonical::json($a)!==Canonical::json($action);}));$record['suppressed'][]=array('action'=>$action,'reason'=>$reason);}unset($record);continue;}
        }$entry['transition_id']=$data['transition_id']??null;$applied=$this->logicalApply($entry,$source);if(!$applied)foreach($resolution['audit'] as &$record)if($record['condition_id']===$entry['condition_id']){$record['applied']=array_values(array_filter($record['applied'],static function($a)use($entry){return Canonical::json($a)!==Canonical::json($entry['action']);}));$record['suppressed'][]=array('action'=>$entry['action'],'reason'=>'RANDOMIZATION_FROZEN');}unset($record);$state=$this->logicalState();$state['applied'][$instance.'|'.$entry['condition_id']]=true;if($this->dependencyPlanning())$state['action_receipts'][$receipt]=true;$this->context['logical_state']=$state;}
        $state=$this->logicalState();$state['occurrences'][$instance]=array('answers'=>$state['answers'][$assessment]??array(),'question_states'=>$state['question_states'][$assessment]??array(),'question_presence'=>$state['question_presence'][$assessment]??array());foreach($resolution['audit'] as $record){$record['at']=$this->clock->now();$record['source_execution_id']=$instance;if($this->dependencyPlanning()&&($data['event']??'ANSWER')==='COMPLETE')$state['completed_conditions'][$instance.'|'.$record['condition_id']]=true;if($record['result']!=='UNKNOWN'&&(($data['event']??'ANSWER')==='COMPLETE'||isset($data['state_transition'])))$state['settled'][$instance.'|'.$record['condition_id']]=$record['result'];$state['audit'][]=$record;$this->emit('LOGICAL_CONDITION_EVALUATED',$instance,null,null,$record['condition_id'],null,$record['result'],$record);}$this->context['logical_state']=$state;
    }
    private function logicalInstance(string $definition): ?array {foreach($this->state->instances as $i)if($i['definition_id']===$definition&&!self::terminal($i['state']))return $i;return null;}
    private function logicalActivatePath(array $destination,array $rule): void {
        $path=array();$cursor=$destination;while($cursor['parent_execution_id']!==null){$cursor=$this->state->instances[$cursor['parent_execution_id']];array_unshift($path,$cursor['id']);}
        foreach($path as $id){$i=$this->state->instances[$id];if(self::terminal($i['state']))throw new \DomainException('LOGICAL_TARGET_BRANCH_CLOSED');if(!in_array($i['state'],array('AVAILABLE','IN_PROGRESS'),true)){$n=$this->graph->node($i['definition_id']);$this->state->instances[$id]['available_at']=$this->clock->now();$this->state->instances[$id]['due_at']=TemporalResolver::deadline($this->clock->now(),$n['schedule']['window']??array('kind'=>'NO_DEADLINE'),$i['timezone'],function(){return null;});$this->transition($id,'AVAILABLE','LOGICAL_JUMP_BRANCH_ACTIVATION',$rule);}$this->decide($id);}
        if(self::terminal($this->state->instances[$destination['id']]['state']))throw new \DomainException('LOGICAL_TARGET_NOT_SELECTED');
    }
    private function logicalApply(array $entry,array $source): bool {
        $action=$entry['action'];$type=$action['type'];$state=$this->logicalState();$rule=array('id'=>$entry['condition_id'],'source'=>array('node_id'=>$source['definition_id']));$target=$action['target_id']??null;
        if(in_array($type,array('RESTRICT_RANDOM','EXCLUDE_RANDOM','FORCE_RANDOM'),true)){
            $nativeId=$action['randomization_id']??null;$controlKey=$target.($nativeId?'|'.$nativeId:'');$native=$nativeId?(array_column($this->graph->node($target)['metadata']['native_randomizations']??array(),null,'id')[$nativeId]??null):null;
            if($nativeId&&empty($entry['responsive_native'])&&isset($state['native_randomizations'][$target]['blocks'][$nativeId])){$this->emit('LOGICAL_ACTION_SUPPRESSED',$source['id'],null,null,$rule['id'],null,'RANDOMIZATION_FROZEN',$action);return false;}
            foreach($this->state->decisions as $decision)if(!$nativeId&&$decision['owner_id']===$target){$this->emit('LOGICAL_ACTION_SUPPRESSED',$source['id'],null,null,$rule['id'],null,'RANDOMIZATION_FROZEN',$action);return false;}
            $pool=$state['random_controls'][$controlKey]['candidates']??($native['candidates']??$this->graph->node($target)['randomization']['candidates']);$pool=$type==='EXCLUDE_RANDOM'?array_values(array_diff($pool,$action['candidate_ids'])):array_values(array_intersect($pool,$action['candidate_ids']));
            if(!$pool)throw new \DomainException('LOGICAL_RANDOM_POOL_EMPTY');$config=$this->graph->node($target)['randomization'];$required=$native?($native['mode']==='all'?count($pool):$native['count']):($config['select_n']??$config['draw_count']??1);if(count($pool)<$required)throw new \DomainException('LOGICAL_RANDOM_POOL_TOO_SMALL');$priorForced=$state['random_controls'][$controlKey]['forced']??null;if($priorForced&&$type!=='FORCE_RANDOM'&&array_diff($priorForced,$pool))throw new \DomainException('LOGICAL_FORCED_POOL_CONFLICT');$state['random_controls'][$controlKey]=array('candidates'=>$pool,'forced'=>$type==='FORCE_RANDOM'?$action['candidate_ids']:($state['random_controls'][$controlKey]['forced']??null),'condition_id'=>$rule['id'],'at'=>$this->clock->now());
        }elseif($type==='CANCEL_BLOCK'){
            if($this->dependencyPlanning()&&$this->graph->node($target)['completion_boundary']==='ASSESSMENT'){
                $destination=$target===$source['definition_id']?$source:$this->logicalInstance($target);
                if($destination)$state['cancelled_opportunities'][$destination['id']]=array('definition_id'=>$target,'condition_id'=>$rule['id'],'at'=>$this->clock->now());
            }else $state['cancelled_blocks'][$target]=array('condition_id'=>$rule['id'],'at'=>$this->clock->now());
        }
        elseif($type==='END_STUDY'){
            $decision='CD_'.count($this->state->events);
            $this->applyRule(array('id'=>$rule['id'],'action'=>'TERMINATE'),$source,$decision);
            $state['navigation']=array('type'=>'END_STUDY','source_execution_id'=>$source['id'],'condition_id'=>$rule['id']);
        }
        elseif($type==='END_ASSESSMENT'){$state['navigation']=array('type'=>'END_ASSESSMENT','source_execution_id'=>$source['id'],'condition_id'=>$rule['id']);if(!self::terminal($this->state->instances[$source['id']]['state']))$this->transition($source['id'],'SKIPPED_BY_RULE','LOGICAL_ASSESSMENT_EXIT',$rule);}
        else{
            $destination=$this->logicalInstance($target);if(!$destination)throw new \DomainException('LOGICAL_TARGET_UNAVAILABLE');
            if($type==='WAIT'){$state['waits'][$source['id']]=array('target_execution_id'=>$destination['id'],'target_id'=>$target,'question_id'=>$action['question_id']??null,'condition_id'=>$rule['id'],'at'=>$this->clock->now());$state['navigation']=array('type'=>'WAIT','source_execution_id'=>$source['id'],'target_id'=>$target,'condition_id'=>$rule['id']);$this->transition($source['id'],'SKIPPED_BY_RULE','LOGICAL_WAIT_EXIT',$rule);}
            else{
                if(!$this->activationAllowed($destination['id'])){
                    $state['waits'][$source['id']]=array('target_execution_id'=>$destination['id'],'target_id'=>$target,'question_id'=>$action['question_id']??null,'condition_id'=>$rule['id'],'at'=>$this->clock->now(),'pause_deferred_jump'=>true);
                    $state['navigation']=array('type'=>'WAIT','source_execution_id'=>$source['id'],'target_id'=>$target,'condition_id'=>$rule['id'],'transition_id'=>$entry['transition_id']??null);
                    if(!self::terminal($this->state->instances[$source['id']]['state']))$this->transition($source['id'],'SKIPPED_BY_RULE','LOGICAL_WAIT_EXIT',$rule);
                    $this->context['logical_state']=$state;$this->emit('LOGICAL_ACTION_DEFERRED',$source['id'],null,null,$rule['id'],null,'ADMISSION_PAUSED',$action);return true;
                }
                $this->logicalActivatePath($destination,$rule);$activationAt=$entry['deferred_at']??$this->clock->now();$original=$destination['available_at'];if($destination['available_at']===null||$destination['available_at']>$this->clock->now()){if(isset($entry['deferred_at'])){$this->state->scheduleRevision++;$this->state->instances[$destination['id']]['schedule_revision']=$this->state->scheduleRevision;} $this->state->instances[$destination['id']]['available_at']=$activationAt;$this->state->instances[$destination['id']]['due_at']=TemporalResolver::deadline($activationAt,$this->graph->node($target)['schedule']['window'],$destination['timezone'],function(){return null;});$this->transition($destination['id'],'AVAILABLE','LOGICAL_JUMP_ACTIVATION',$rule);}
                if(($state['current_occurrence'][$target]??null)!==$destination['id']){$prior=$state['occurrences'][$destination['id']]??array();$state['answers'][$target]=$prior['answers']??array();$state['question_states'][$target]=$prior['question_states']??array();$state['question_presence'][$target]=$prior['question_presence']??array();$state['current_occurrence'][$target]=$destination['id'];}
                $catalog=array_column($this->graph->node($target)['metadata']['native_questions']??array(),null,'id');$ids=array_keys($catalog);$question=$action['question_id']??($ids[0]??null);$end=$question===null?0:array_search($question,$ids,true);
                if($end===false)throw new \DomainException('LOGICAL_ENTRY_UNAVAILABLE');foreach(array_slice($ids,0,$end) as $id)if(($state['question_states'][$target][$id]??null)!=='answered')$state['question_states'][$target][$id]='skipped_by_logic';
                $state['navigation']=array('type'=>$source['id']===$destination['id']?'GO_QUESTION':$type,'source_execution_id'=>$source['id'],'target_execution_id'=>$destination['id'],'target_id'=>$target,'question_id'=>$question,'entry_page'=>$question===null?0:$catalog[$question]['page'],'condition_id'=>$rule['id'],'original_available_at'=>$original);
                $this->state->cursor=array('assessment_execution_id'=>$destination['id'],'node_id'=>$target);
                if($source['id']!==$destination['id']&&!self::terminal($this->state->instances[$source['id']]['state']))$this->transition($source['id'],'SKIPPED_BY_RULE','LOGICAL_TRANSITION_EXIT',$rule);
                // Bypass within the affected longitudinal branch; parallel blocks stay intact.
                $siblings=$this->graph->children($this->graph->node($source['definition_id'])['parent_id']);$from=array_search($source['definition_id'],$siblings,true);$to=array_search($target,$siblings,true);
                if($from!==false&&$to!==false&&$to>$from)foreach(array_slice($siblings,$from+1,$to-$from-1) as $id)$this->markSubtree($id,$source,'SKIPPED_BY_RULE','BYPASSED_BY_JUMP',$rule);
            }
        }
        if(in_array($type,array('GO_QUESTION','END_ASSESSMENT','END_STUDY','JUMP','WAIT'),true))$state['navigation']['transition_id']=$entry['transition_id']??null;
        $this->context['logical_state']=$state;$this->emit('LOGICAL_ACTION_APPLIED',$source['id'],null,null,$rule['id'],null,$type,$action);return true;
    }
    private function logicalSystemEvent(string $event): void {
        if(!array_filter(LogicalConditions::rules($this->graph->definition()),static function($r)use($event){return $r['event']===$event&&!isset($r['trigger']);}))return;
        $fingerprint=hash('sha256',Canonical::json($event==='RANDOM_RESOLVED'?array($this->state->decisions,$this->logicalState()['native_randomizations']):array_map(static function($i){return array($i['state'],$i['available_at'],$i['due_at']);},$this->state->instances)));if(($this->context['logical_event_fingerprints'][$event]??null)===$fingerprint)return;$this->context['logical_event_fingerprints'][$event]=$fingerprint;
        foreach($this->state->instances as $i)if($this->graph->node($i['definition_id'])['completion_boundary']==='ASSESSMENT'&&!self::terminal($i['state']))$this->logicalEvent(array('block_execution_id'=>$i['id'],'event'=>$event,'transition_id'=>$this->context['logical_trigger_transition']??null));
    }
    private function logicalStateTransitions(): void {
        if(!$this->dependencyPlanning())return;
        $cursor=$this->context['logical_state_transition_cursor']??0;$events=$this->context['logical_state_transitions']??array();
        foreach(array_slice($events,$cursor) as $event){$this->context['logical_state_transition_cursor']=++$cursor;$dispatched=array();
            foreach(LogicalConditions::rules($this->graph->definition()) as $rule){if(($rule['trigger']['node_id']??null)!==$event['node_id']||$rule['trigger']['state']!==$event['state'])continue;
                foreach($this->state->instances as $source)if($source['definition_id']===$rule['source']['assessment_id']&&(!self::terminal($source['state'])||$source['id']===$event['instance_id'])&&!isset($dispatched[$source['id']])){$dispatched[$source['id']]=true;$this->logicalEvent(array('block_execution_id'=>$source['id'],'event'=>'BLOCK_STATE','state_transition'=>$event));}
            }
        }
    }
    private function logicalPaused(array $instance): bool {
        foreach($this->logicalState()['waits'] as $source=>$wait){$cursor=$instance;$targetBranch=false;while($cursor){if($cursor['id']===$wait['target_execution_id']){$targetBranch=true;break;}$cursor=$cursor['parent_execution_id']===null?null:($this->state->instances[$cursor['parent_execution_id']]??null);}if($targetBranch)continue;$origin=$this->state->instances[$source]??null;if(!$origin)continue;$parent=$origin['parent_execution_id'];$cursor=$instance;while($cursor['parent_execution_id']!==null){if($cursor['parent_execution_id']===$parent)return true;$cursor=$this->state->instances[$cursor['parent_execution_id']];}}return false;
    }
    private function logicalRefresh(): void {
        if(!LogicalConditions::rules($this->graph->definition()))return;$state=$this->logicalState();
        foreach($state['cancelled_opportunities'] as $id=>$reason){$i=$this->state->instances[$id]??null;if($i&&!self::terminal($i['state'])&&$i['state']!=='IN_PROGRESS')$this->transition($id,'NOT_SCHEDULED','OPPORTUNITY_CANCELLED',array('id'=>$reason['condition_id'],'source'=>array('node_id'=>$reason['definition_id'])));}
        foreach($state['cancelled_blocks'] as $definition=>$reason){$desc=array_merge(array($definition),$this->graph->descendants($definition));foreach($this->state->instances as $id=>$i){if(!in_array($i['definition_id'],$desc,true)||self::terminal($i['state'])||$i['state']==='IN_PROGRESS')continue;$hasCurrent=false;foreach($this->state->instances as $child)if($child['state']==='IN_PROGRESS'&&in_array($child['definition_id'],$this->graph->descendants($i['definition_id']),true))$hasCurrent=true;if(!$hasCurrent)$this->transition($id,'NOT_SCHEDULED','BLOCK_CANCELLED',array('id'=>$reason['condition_id'],'source'=>array('node_id'=>$definition)));}foreach($this->state->templates as $key=>$template)if(in_array($template['definition_id']??null,$desc,true))$this->state->templates[$key]['pending']=false;}
        foreach($state['waits'] as $source=>$wait){$target=$this->state->instances[$wait['target_execution_id']]??null;if($target&&self::terminal($target['state'])){unset($state['waits'][$source]);$this->emit('LOGICAL_WAIT_TARGET_UNAVAILABLE',$source,null,null,$wait['condition_id'],null,$target['state']);continue;}if($target&&$this->activationScope===null&&(!empty($wait['pause_deferred_jump'])||in_array($target['state'],array('AVAILABLE','IN_PROGRESS'),true))||$target&&$this->activationScope!==null&&$this->activationAllowed($target['id'])&&in_array($target['state'],array('AVAILABLE','IN_PROGRESS'),true)){$this->logicalApply(array('condition_id'=>$wait['condition_id'],'priority'=>1,'deferred_at'=>!empty($wait['pause_deferred_jump'])?$wait['at']:null,'action'=>array('type'=>'JUMP','target_id'=>$wait['target_id'])+($wait['question_id']===null?array():array('question_id'=>$wait['question_id']))),$this->state->instances[$source]);$state=$this->logicalState();unset($state['waits'][$source]);$this->emit('LOGICAL_WAIT_RESOLVED',$source,null,null,$wait['condition_id'],null,'NATURAL_SCHEDULE');}}
        $this->context['logical_state']=$state;
    }
}
