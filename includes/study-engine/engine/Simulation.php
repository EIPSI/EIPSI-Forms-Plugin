<?php
namespace EIPSI\StudyEngine\Engine;

use EIPSI\StudyEngine\Contracts\Canonical;
use EIPSI\StudyEngine\Contracts\Check;
use EIPSI\StudyEngine\Contracts\NextAction;
use EIPSI\StudyEngine\Contracts\Shape;
use EIPSI\StudyEngine\Domain\OccurrenceKey;

final class SimulationConflict extends \DomainException {}

/** Mutable state is owned exclusively by a Simulator; public snapshots are copies. */
final class SimulationState {
    public $execution=array();
    public $instances=array();
    public $responses=array();
    public $coverage=array();
    public $cursor=null;
    public $barrier=null;
    public $decisions=array();
    public $events=array();
    public $templates=array();
    public $scheduleRevision=0;
    public $verifiedAt=null;
    public $withdrawal=null;
    public $notificationsCancelled=false;
    public $pageCursor=0;
    public $offered=array();
    public $skipIntents=array();
    public $requestedOccurrences=array();
    public function snapshot(SimulationClock $clock): array {
        return Canonical::normalize(array('execution'=>$this->execution,'instances'=>$this->instances,'responses'=>$this->responses,'coverage'=>$this->coverage,'cursor'=>$this->cursor,'barrier'=>$this->barrier,'random_decisions'=>$this->decisions,'events'=>$this->events,'templates'=>$this->templates,'schedule_revision'=>$this->scheduleRevision,'verified_at'=>$this->verifiedAt,'withdrawal'=>$this->withdrawal,'notifications_cancelled'=>$this->notificationsCancelled,'clock'=>$clock->now(),'timezone'=>$clock->timezone(),'page_cursor'=>$this->pageCursor,'offered'=>$this->offered,'skip_intents'=>$this->skipIntents,'requested_occurrences'=>$this->requestedOccurrences));
    }
}

/** Traversal projection uses frozen decisions and ancestor gates, never draws randomness. */
final class TraversalResolver {
    public function children(CompiledStudy $graph, array $instance, array $decisions): array {
        $ids=$graph->children($instance['definition_id']);$node=$graph->node($instance['definition_id']);
        if ($node['randomization']===null) { return $ids; }
        $selections=array();
        foreach ($decisions as $decision) {
            if ($decision['owner_id']===$node['id'] && ($decision['block_execution_id']===null || $decision['block_execution_id']===$instance['id'])) { $selections[$decision['segment_id']]=$decision['selection']; }
        }
        $result=array();$consumed=array();
        foreach($ids as $id) {
            if($graph->node($id)['kind']==='DIRECTIVE'){$result[]=$id;continue;}
            foreach($graph->randomCandidates($node['id']) as $segment) {
                if(in_array($id,$segment['candidates'],true)&&!isset($consumed[$segment['id']])) {
                    $result=array_merge($result,$selections[$segment['id']]??$segment['candidates']);$consumed[$segment['id']]=true;
                }
            }
        }
        return $result;
    }
    public function eligible(CompiledStudy $graph, array $instance, array $instances, array $decisions): bool {
        $child=$instance;
        while($child['parent_execution_id']!==null) {
            $parent=$instances[$child['parent_execution_id']];
            if(!in_array($parent['state'],array('AVAILABLE','IN_PROGRESS'),true)) { return false; }
            $node=$graph->node($parent['definition_id']);
            if(empty($node['metadata']['independent_children']) && $node['children_strategy']!=='PARALLEL_AVAILABLE' && $node['completion_boundary']!=='ASSESSMENT' && $node['completion_boundary']!=='GROUP') {
                foreach($this->children($graph,$parent,$decisions) as $id) {
                    $siblings=array_values(array_filter($instances,static function($i)use($parent,$id){return $i['parent_execution_id']===$parent['id']&&$i['definition_id']===$id;}));
                    usort($siblings,static function($a,$b){return $a['ordinal']<=>$b['ordinal'];});
                    foreach($siblings as $sibling) {
                        if($sibling['id']===$child['id']) { break 2; }
                        if(!Simulator::terminal($sibling['state'])) { return false; }
                    }
                }
            }
            $child=$parent;
        }
        return true;
    }
}

/** In-memory reference executor: conceptual CAS/idempotency, no persistence guarantees. */
final class Simulator {
    use LogicalExecution;
    use ExecutionPlanning;
    private $graph;
    private $state;
    private $clock;
    private $context;
    private $cache=array();
    private $maxInstances;
    private $eagerLimit;
    private $horizonDays;
    private $traversal;
    private $selectors=array();
    public function __construct(CompiledStudy $graph, array $context) {
        $this->graph=$graph;$this->context=$context;
        $this->clock=new SimulationClock($context['created_at'],$context['timezone']??$graph->definition()['timezone']['default']);
        $this->state=new SimulationState();$this->traversal=new TraversalResolver();
        $this->maxInstances=$context['max_instances']??10000;$this->eagerLimit=$context['eager_limit']??256;$this->horizonDays=$context['horizon_days']??30;
        if(!is_int($this->maxInstances)||$this->maxInstances<1||!is_int($this->eagerLimit)||$this->eagerLimit<1||!is_int($this->horizonDays)||$this->horizonDays<1){throw new \InvalidArgumentException('INVALID_MATERIALIZATION_LIMIT');}
        // Validate seed even if this particular protocol has no random nodes.
        RandomStream::validateSeed($context['seed_hex']??str_repeat('00',32));
        $id=$context['execution_id']??'SIMULATION_1';Check::id($id,'execution_id');
        $this->state->execution=array('id'=>$id,'version_id'=>$graph->definition()['id'],'study_id'=>$graph->definition()['study']['logical_id'],'created_at'=>$this->clock->now(),'first_access_at'=>null,'started_at'=>null,'state'=>'ACTIVE','revision'=>0,'timezone'=>$this->clock->timezone(),'timezone_source'=>$context['timezone_source']??(isset($context['timezone'])?'simulation':'study_fallback'));
        $this->emit('EXECUTION_CREATED',null,null,'ACTIVE',null,null,'CREATE_EXECUTION');
        $this->refresh();
    }
    public static function terminal(string $state): bool { return in_array($state,array('COMPLETED','EXPIRED','SKIPPED_BY_RULE','NOT_SCHEDULED','WITHDRAWN','TERMINATED_BY_RULE'),true); }
    public function snapshot(): array { $s=$this->state->snapshot($this->clock);if($this->dependencyPlanning())$s['dependency_plan']=Canonical::normalize($this->context['dependency_plan']??array());if(LogicalConditions::rules($this->graph->definition())||$this->dependencyPlanning())$s['logical_trajectory']=Canonical::normalize($this->logicalState());return $s; }
    public function trace(): array { return $this->state->events; }
    public function compiled(): CompiledStudy { return $this->graph; }
    /** Internal durable adapter. Secrets and the command cache never enter storage. */
    public function checkpoint(): array {
        $context=$this->context;unset($context['seed_hex']);
        return Canonical::normalize(array('contract'=>'P1D_CHECKPOINT_V1','graph_hash'=>$this->graph->hash(),
            'state'=>get_object_vars($this->state),'instance_order'=>array_keys($this->state->instances),
            'context'=>$context,'selectors'=>$this->selectors,'clock'=>$this->clock->now(),'timezone'=>$this->clock->timezone()));
    }
    /** Restore without constructor/refresh/draw: decisions and selector history are authoritative. */
    public static function restore(CompiledStudy $graph,array $checkpoint,string $seedHex): self {
        Check::fields($checkpoint,array('contract','graph_hash','state','instance_order','context','selectors','clock','timezone'));
        if($checkpoint['contract']!=='P1D_CHECKPOINT_V1'||!hash_equals($graph->hash(),$checkpoint['graph_hash']))throw new \DomainException('CHECKPOINT_CONTRACT_MISMATCH');
        RandomStream::validateSeed($seedHex);
        $self=(new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $self->graph=$graph;$self->context=$checkpoint['context'];$self->context['seed_hex']=$seedHex;
        $self->state=new SimulationState();$fields=array_keys(get_object_vars($self->state));
        Check::fields($checkpoint['state'],$fields);
        foreach($fields as $field)$self->state->$field=$checkpoint['state'][$field];
        $ordered=array();foreach($checkpoint['instance_order'] as $id){if(!isset($self->state->instances[$id])||isset($ordered[$id]))throw new \DomainException('INVALID_INSTANCE_ORDER');$ordered[$id]=$self->state->instances[$id];}
        if(count($ordered)!==count($self->state->instances))throw new \DomainException('INVALID_INSTANCE_ORDER');
        $self->state->instances=$ordered;
        foreach($checkpoint['selectors'] as $id){if(!isset($ordered[$id]))throw new \DomainException('INVALID_SELECTOR_HISTORY');}
        $self->selectors=$checkpoint['selectors'];$self->cache=array();$self->traversal=new TraversalResolver();
        $self->clock=new SimulationClock($checkpoint['clock'],$checkpoint['timezone']);
        $self->maxInstances=$self->context['max_instances']??10000;$self->eagerLimit=$self->context['eager_limit']??256;$self->horizonDays=$self->context['horizon_days']??30;
        if(!is_int($self->maxInstances)||$self->maxInstances<1||!is_int($self->eagerLimit)||$self->eagerLimit<1||!is_int($self->horizonDays)||$self->horizonDays<1)throw new \DomainException('INVALID_MATERIALIZATION_LIMIT');
        return $self;
    }
    public function instance(string $definitionId, $slot=null): array {
        $matches=array_values(array_filter($this->state->instances,static function($i)use($definitionId,$slot){return $i['definition_id']===$definitionId && ($slot===null || Canonical::json($i['slot'])===Canonical::json($slot));}));
        if(count($matches)!==1) { throw new \DomainException('AMBIGUOUS_OR_MISSING_INSTANCE: '.$definitionId); } return $matches[0];
    }
    /** Dispatch rollback is a memory snapshot, not a SQL transaction. */
    public function dispatch(array $command): array {
        Check::fields($command,array('id','kind'),array('data','expected_revision'),'command');Check::id($command['id'],'command.id');
        $fingerprint=Canonical::json($command);
        if(isset($this->cache[$command['id']])) {
            if($this->cache[$command['id']]['fingerprint']!==$fingerprint){throw new SimulationConflict('IDEMPOTENCY_KEY_REUSED');}
            return $this->cache[$command['id']]['result'];
        }
        if(isset($command['expected_revision']) && $command['expected_revision']!==$this->state->execution['revision']) { throw new SimulationConflict('REVISION_CONFLICT'); }
        $old=clone $this->state;$oldClock=clone $this->clock;$oldSelectors=$this->selectors;$oldContext=$this->context;
        try {
            $kind=$command['kind'];$data=$command['data']??array();Canonical::json($data);
            $readOnly=in_array($kind,array('RELOAD','CAPABILITY_OPENED'),true);
            if($readOnly && isset($data['at']))throw new \DomainException('READ_ONLY_COMMAND_CANNOT_ADVANCE_CLOCK');
            if(isset($data['at'])){$this->clock->set($data['at']);$this->refresh();}
            if($kind==='CREATE_EXECUTION') { /* Already created by constructor; explicit command is idempotent projection. */ }
            elseif($kind==='FIRST_ACCESS') { if($this->state->execution['first_access_at']===null){$this->state->execution['first_access_at']=$this->clock->now();$this->emit('FIRST_ACCESS');} }
            elseif($kind==='VERIFY_PARTICIPANT') { if($this->state->verifiedAt===null){$this->state->verifiedAt=$this->clock->now();$this->emit('PARTICIPANT_VERIFIED');} }
            elseif($kind==='OPEN_ASSESSMENT') {$this->open($this->locate($data));}
            elseif($kind==='NATIVE_LOGIC') {$this->logicalEvent($data);}
            elseif($kind==='ANSWER') {$this->answer($data);}
            elseif($kind==='SAVE_PAGE') {$this->savePage($data);}
            elseif($kind==='NEXT_PAGE'&&isset($data['responses'])) {$this->navigatePage($data,1);}
            elseif($kind==='PREVIOUS_PAGE') {$this->navigatePage($data,-1);}
            elseif($kind==='SUBMIT_PAGE') {$this->savePage($data);if($this->state->execution['state']!=='TERMINATED_BY_RULE')$this->submit(array('block_execution_id'=>$this->locate($data)));}
            elseif($kind==='SUBMIT_ASSESSMENT') {$this->submit($data);}
            elseif($kind==='ADVANCE_TIME') {if(isset($data['seconds'])){$this->clock->advance($data['seconds']);}$this->emit('CLOCK_ADVANCED');}
            elseif($kind==='CHANGE_TIMEZONE') {$this->changeTimezone($data['timezone']);if(isset($data['timezone_source'])){if($data['timezone_source']!=='PARTICIPANT_LOCAL')throw new \DomainException('INVALID_TIMEZONE_SOURCE');$this->state->execution['timezone_source']=$data['timezone_source'];}}
            elseif($kind==='WITHDRAW_SIMULATION') {$this->withdraw($data['mode']);}
            elseif($kind==='WITHDRAW_EXECUTION') {$this->withdraw($data['mode'],true);$this->state->withdrawal['source']=$data['source'];}
            elseif($kind==='NEXT_PAGE') {$this->state->pageCursor++;$this->emit('PAGE_CURSOR_ADVANCED',null,null,null,null,null,'PRESENTATION');}
            elseif($kind==='RELOAD'||$kind==='CAPABILITY_OPENED') { /* GET is deliberately read-only, including withdrawal confirmation. */ }
            else {throw new \DomainException('UNKNOWN_COMMAND');}
            $this->refresh();if(!$readOnly && $kind!=='CREATE_EXECUTION'){$this->state->execution['revision']++;}
            if(!$readOnly && $kind!=='CREATE_EXECUTION')$this->emit('COMMAND_ACCEPTED',null,null,null,null,null,$kind,array('command_id'=>$command['id'],'revision'=>$this->state->execution['revision']));
            if(!$readOnly)$this->refresh();
            $result=array('revision'=>$this->state->execution['revision'],'next_action'=>$this->nextAction(),'event_count'=>count($this->state->events));
            $this->cache[$command['id']]=array('fingerprint'=>$fingerprint,'result'=>$result);return $result;
        } catch(\Throwable $e) { $this->state=$old;$this->clock=$oldClock;$this->selectors=$oldSelectors;$this->context=$oldContext;throw $e; }
    }
    private function locate(array $data): string {
        if(isset($data['block_execution_id'])){
            if(!isset($this->state->instances[$data['block_execution_id']]))throw new \DomainException('INSTANCE_NOT_FOUND');
            return $data['block_execution_id'];
        }
        return $this->instance($data['node_id'],$data['slot']??null)['id'];
    }
    private function emit(string $type, ?string $instance=null, ?string $old=null, ?string $new=null, ?string $rule=null, ?string $decision=null, ?string $reason=null, array $payload=array()): void {
        $this->state->events[]=array('sequence'=>count($this->state->events)+1,'simulated_at'=>$this->clock->now(),'type'=>$type,'source'=>'SIMULATOR','execution_id'=>$this->state->execution['id'],'block_execution_id'=>$instance,'old_state'=>$old,'new_state'=>$new,'rule_id'=>$rule,'decision_id'=>$decision,'reason'=>$reason,'payload'=>$payload);
    }
    private function transition(string $id,string $new,string $reason,?array $rule=null,?string $decision=null): void {
        $old=$this->state->instances[$id]['state'];if($old===$new)return;
        if(self::terminal($old)){throw new \DomainException('TERMINAL_INSTANCE_IMMUTABLE');}
        $this->state->instances[$id]['state']=$new;$this->state->instances[$id]['revision']++;
        if($new==='COMPLETED'){$this->state->instances[$id]['completed_at']=$this->clock->now();}
        if(self::terminal($new)&&$new!=='COMPLETED'){$this->state->instances[$id]['provenance']=array('reason'=>$reason,'rule_id'=>$rule['id']??null,'decision_id'=>$decision,'decided_at'=>$this->clock->now(),'source_node_id'=>$rule['source']['node_id']??null,'source_execution_id'=>$this->state->execution['id']);}
        $this->emit('BLOCK_STATE_CHANGED',$id,$old,$new,$rule['id']??null,$decision,$reason);
        if($this->dependencyPlanning())$this->context['logical_state_transitions'][]=array('instance_id'=>$id,'node_id'=>$this->state->instances[$id]['definition_id'],'state'=>$new);
        if($new==='COMPLETED')$this->state->instances[$id]['completion_sequence']=count($this->state->events);
    }
    private function zone(?array $schedule): string {
        return ($schedule['timezone_policy']??$this->graph->definition()['timezone']['policy'])==='STUDY_TIMEZONE'?$this->graph->definition()['timezone']['default']:$this->clock->timezone();
    }
    private function select(string $definitionId,array $selector,?array $context,string $purpose): ?array {
        $matches=array_values(array_filter($this->state->instances,static function($i)use($definitionId){return $i['definition_id']===$definitionId;}));
        $kind=$selector['kind'];
        if($kind==='EXACT_OCCURRENCE'){$matches=array_values(array_filter($matches,static function($i)use($selector){return Canonical::json($i['occurrence_key'])===Canonical::json($selector['occurrence_key']);}));}
        if($kind==='SAME_PARENT_OCCURRENCE'&&$context!==null) {
            $ctx=array();foreach($context['occurrence_key'] as $part){$ctx[$part['block_id']]=$part['slot'];}
            $matches=array_values(array_filter($matches,static function($i)use($ctx){foreach($i['occurrence_key'] as $part){if(isset($ctx[$part['block_id']])&&Canonical::json($ctx[$part['block_id']])!==Canonical::json($part['slot']))return false;}return true;}));
        }
        if($kind==='FIRST_COMPLETED'||$kind==='LAST_COMPLETED_BEFORE') {
            $cacheKey=($context['id']??'global').'|'.$purpose.'|'.$definitionId.'|'.Canonical::json($selector);
            if(isset($this->selectors[$cacheKey]))return $this->state->instances[$this->selectors[$cacheKey]];
            $before=$this->clock->now();$beforeSequence=null;
            if($kind==='LAST_COMPLETED_BEFORE') {
                $found=false;foreach($this->state->events as $event){if('EV_'.$event['sequence']===$selector['source_event_id']){$before=$event['simulated_at'];$beforeSequence=$event['sequence'];$found=true;break;}}
                if(!$found)return null;
            }
            $matches=array_values(array_filter($matches,static function($i)use($before,$kind,$beforeSequence){return $i['state']==='COMPLETED' && ($kind!=='LAST_COMPLETED_BEFORE'||$i['completed_at']<$before||($i['completed_at']===$before&&($i['completion_sequence']??PHP_INT_MAX)<$beforeSequence));}));
            usort($matches,static function($a,$b){return strcmp($a['completed_at'],$b['completed_at'])?:(($a['completion_sequence']??0)<=>($b['completion_sequence']??0))?:strcmp($a['id'],$b['id']);});
            if($kind==='LAST_COMPLETED_BEFORE')$matches=array_reverse($matches);
            if($matches){$this->selectors[$cacheKey]=$matches[0]['id'];return $matches[0];}return null;
        }
        if(count($matches)>1)throw new \DomainException('AMBIGUOUS_EFFECTIVE_OCCURRENCE: '.$definitionId);return $matches[0]??null;
    }
    private function anchor(array $schedule,?array $instance): ?string {
        $a=$schedule['anchor'];$e=$this->state->execution;
        if($a==='EXECUTION_CREATED')return $e['created_at'];
        if($a==='FIRST_ACCESS')return $e['first_access_at'];
        if($a==='PARTICIPANT_VERIFIED')return $this->state->verifiedAt;
        if($a==='ABSOLUTE_DATETIME')return TemporalResolver::absolute($schedule['temporal_rule']['at']);
        $ref=$this->select($schedule['anchor_ref'],$schedule['occurrence_selector'],$instance,'anchor');
        if($ref===null)return null;
        $fields=array('BLOCK_AVAILABLE'=>'available_at','BLOCK_STARTED'=>'started_at','BLOCK_COMPLETED'=>'completed_at','SPECIFIC_BLOCK_COMPLETED'=>'completed_at');return $ref[$fields[$a]];
    }
    private function refresh(bool $finish = true): void {
        if(in_array($this->state->execution['state'],array('WITHDRAWN','TERMINATED_BY_RULE','COMPLETED'),true))return;
        $last=null;
        for($pass=0;$pass<count($this->graph->order())+4;$pass++) {
            $fingerprint=Canonical::json(array($this->state->instances,$this->state->decisions,$this->state->templates));
            if($fingerprint===$last)break;$last=$fingerprint;
            $this->materialize();$this->logicalRefresh();$this->refreshDependencyPlan();
            foreach($this->state->skipIntents as $intent)$this->markSubtree($intent['definition_id'],$intent['context'],'SKIPPED_BY_RULE','RULE_WAIT',$intent['rule'],$intent['decision'],$intent['before_ordinal']??null);
            foreach(array_keys($this->state->instances) as $id) {
                $i=$this->state->instances[$id];$n=$this->graph->node($i['definition_id']);
                if(self::terminal($i['state']))continue;
                $parent=$i['parent_execution_id']===null?null:$this->state->instances[$i['parent_execution_id']];
                if($parent!==null&&self::terminal($parent['state'])&&$parent['state']!=='COMPLETED') {
                    $p=$parent['provenance'];$this->transition($id,$parent['state'],$p['reason']??'PARENT_TERMINAL',isset($p['rule_id'])?array('id'=>$p['rule_id'],'source'=>array('node_id'=>$p['source_node_id'])):null,$p['decision_id']??null);continue;
                }
                if($i['available_at']===null || in_array($i['state'],array('BLOCKED','SCHEDULED'),true)) {
                    $s=$n['schedule'];$zone=$this->zone($s);
                    $anchor=$s===null?($parent['available_at']??null):$this->anchor($s,$i);
                    if($s!==null&&$s['recurrence']!==null){$at=$i['available_at'];}
                    else{$at=$s===null?$anchor:TemporalResolver::availability($anchor,$s['temporal_rule'],$zone);}
                    $delivery=$n['metadata']['scheduled_interaction']??null;
                    if($delivery!==null&&$at!==null){
                        if(!isset($this->state->instances[$id]['delivery_offset_seconds'])){
                            $offset=0;if($delivery['delivery']==='RANDOM'){$stream=new RandomStream($this->context['seed_hex'],array('execution_id'=>$this->state->execution['id'],'version_id'=>$this->graph->definition()['id'],'owner_id'=>$n['id'],'segment_id'=>'DELIVERY_TIME','scope'=>'BLOCK_INSTANCE','occurrence_key'=>$i['occurrence_key']));$offset=$stream->integer($delivery['window_seconds']);}
                            $this->state->instances[$id]['delivery_offset_seconds']=$offset;
                        }
                        $at=gmdate('Y-m-d\TH:i:s\Z',strtotime($at)+$this->state->instances[$id]['delivery_offset_seconds']);
                    }
                    $due=$s===null?($parent['due_at']??null):TemporalResolver::deadline($at,$s['window'],$zone,function($window)use($i){return $this->anchor($window,$i);});
                    $this->state->instances[$id]['available_at']=$at;$this->state->instances[$id]['due_at']=$due;
                    $this->state->instances[$id]['timezone']=$zone;$this->state->instances[$id]['schedule_revision']=$this->state->scheduleRevision;
                }
                $i=$this->state->instances[$id];$new=TemporalResolver::state($this->clock->now(),$i['available_at'],$i['due_at']);
                if($n['schedule']!==null&&$n['schedule']['window']['kind']==='EVENT'&&$i['available_at']!==null){
                    $due=TemporalResolver::deadline($i['available_at'],$n['schedule']['window'],$i['timezone'],function($w)use($i){return $this->anchor($w,$i);});
                    $this->state->instances[$id]['due_at']=$due;$new=TemporalResolver::state($this->clock->now(),$i['available_at'],$due);
                }
                if($i['state']==='IN_PROGRESS' && $new==='AVAILABLE')$new='IN_PROGRESS';
                $traversalBlocked=$new==='AVAILABLE'&&(($this->dependencyPlanning()&&!$this->planningBranchSelected($i))||$this->logicalPaused($i)||!$this->traversal->eligible($this->graph,$i,$this->state->instances,$this->state->decisions));
                if($traversalBlocked)$new='BLOCKED';
                $this->transition($id,$new,$traversalBlocked?'TRAVERSAL_DEPENDENCY':'TIME_WINDOW');
                if(in_array($new,array('AVAILABLE','IN_PROGRESS'),true))$this->decide($id);
                if($new==='BLOCKED' && $n['schedule']!==null && in_array($n['schedule']['anchor'],array('BLOCK_COMPLETED','SPECIFIC_BLOCK_COMPLETED','BLOCK_STARTED'),true)) {
                    $ref=$this->select($n['schedule']['anchor_ref'],$n['schedule']['occurrence_selector'],$i,'anchor');
                    if($ref!==null&&self::terminal($ref['state'])&&$ref['state']!=='COMPLETED')$this->transition($id,'NOT_SCHEDULED','ANCHOR_NOT_REACHED');
                }
            }
            $this->closeGroups();
        }
        if(!empty($this->context['pending_logical_random'])){unset($this->context['pending_logical_random']);$this->logicalSystemEvent('RANDOM_RESOLVED');}$this->logicalSystemEvent('SCHEDULE');$this->logicalSystemEvent('BLOCK_STATE');$this->logicalStateTransitions();$this->refreshDependencyPlan();$this->logicalRefresh();$this->overall();$this->resolveBarrier();if($finish)$this->finish();
        if(in_array($this->state->execution['state'],array('ACTIVE','WAITING'),true)&&$this->state->barrier===null){
            $available=false;foreach($this->orderedAssessments() as $id){$i=$this->state->instances[$id];if(in_array($i['state'],array('AVAILABLE','IN_PROGRESS'),true)&&$this->traversal->eligible($this->graph,$i,$this->state->instances,$this->state->decisions)){$available=true;break;}}
            $new=$available?'ACTIVE':'WAITING';$old=$this->state->execution['state'];if($old!==$new){$this->state->execution['state']=$new;$this->emit('EXECUTION_STATE_CHANGED',null,$old,$new,null,null,'ACTION_AVAILABILITY');}
        }
    }
    private function materialize(): void {
        $materializer=new RecurrenceMaterializer($this->maxInstances);
        foreach($this->graph->order() as $definitionId) {
            $n=$this->graph->node($definitionId);if($n['kind']!=='BLOCK')continue;
            $parents=$n['parent_id']===$this->graph->definition()['id']?array(null):array_values(array_filter($this->state->instances,static function($i)use($n){return $i['definition_id']===$n['parent_id'];}));
            foreach($parents as $parent) {
                $path=$parent['occurrence_key']??array();$templateKey=$definitionId.'|'.Canonical::json($path);
                $s=$n['schedule'];$r=$s['recurrence']??null;$zone=$this->zone($s);
                $context=$parent; $anchor=$s===null?($parent['available_at']??null):$this->anchor($s,$context);
                $count=$r===null?1:($r['kind']==='DAILY_LOCAL'?$r['days']*count($r['times']):($r['count']??null));
                $lazy=$count===null||$count>$this->eagerLimit;
                foreach($this->state->skipIntents as $intent){if(!isset($intent['before_ordinal'])&&$intent['definition_id']===$definitionId && ($parent===null||$this->sameContext($parent,$intent['context'])))$lazy=false;}
                $horizon=$lazy?TemporalResolver::utc(TemporalResolver::epoch($this->clock->now())+$this->horizonDays*86400):null;
                foreach($this->state->skipIntents as $intent){if(isset($intent['before_ordinal'])&&$intent['definition_id']===$definitionId&&($parent===null||$this->sameContext($parent,$intent['context']))&&$horizon!==null){$targetAt=$this->state->instances[$intent['target_execution_id']]['available_at'];if($targetAt!==null&&$targetAt>$horizon)$horizon=$targetAt;}}
                // Unresolved large recurrence stays a pending template; no invented anchor.
                if($lazy&&$anchor===null){$this->state->templates[$templateKey]=array('pending'=>true,'definition_id'=>$definitionId,'parent_execution_id'=>$parent['id']??null,'last_ordinal'=>0);continue;}
                $lastOrdinal=0;$lastAt=null;
                $generation=$materializer->slots($r,$anchor,$zone,$horizon);
                foreach($generation as $slot) {
                    $lastOrdinal=$slot['ordinal'];$lastAt=$slot['available_at'];
                    $key=new OccurrenceKey(array_merge($path,array(array('block_id'=>$definitionId,'slot'=>$slot['slot']))));
                    $id='BE_'.substr(hash('sha256',$this->state->execution['id'].'|'.$key->key()),0,24);
                    if(!isset($this->state->instances[$id])) {
                        if(count($this->state->instances)>=$this->maxInstances)throw new \DomainException('INSTANCE_CAP');
                        $this->state->instances[$id]=array('id'=>$id,'study_execution_id'=>$this->state->execution['id'],'version_id'=>$this->graph->definition()['id'],'definition_id'=>$definitionId,'parent_execution_id'=>$parent['id']??null,'occurrence_key'=>$key->jsonSerialize(),'slot'=>$slot['slot'],'ordinal'=>$slot['ordinal'],'state'=>'BLOCKED','available_at'=>null,'due_at'=>null,'started_at'=>null,'completed_at'=>null,'revision'=>0,'provenance'=>null,'random_decision_id'=>null,'timezone'=>$zone,'schedule_revision'=>$this->state->scheduleRevision,'local_intent'=>null);
                        $this->emit('OCCURRENCE_MATERIALIZED',$id,null,'BLOCKED',null,null,'LOGICAL_SLOT',array('occurrence_key'=>$key->jsonSerialize()));
                    }
                    $i=$this->state->instances[$id];
                    if($r!==null&&in_array($i['state'],array('BLOCKED','SCHEDULED'),true)&&!isset($this->state->offered[$id])) {
                        $this->state->instances[$id]['available_at']=$slot['available_at'];
                        if($slot['available_at']!==null)$this->state->instances[$id]['local_intent']=$slot['resolution']['local_intent']??TemporalResolver::local($slot['available_at'],$zone);
                        $this->state->instances[$id]['temporal_resolution']=$slot['resolution'];
                    }
                }
                $pending=$lazy && !$generation->getReturn();
                if($parent!==null&&self::terminal($parent['state']))$pending=false;
                $this->state->templates[$templateKey]=array('pending'=>$pending,'definition_id'=>$definitionId,'parent_execution_id'=>$parent['id']??null,'last_ordinal'=>$lastOrdinal);
            }
        }
        foreach($this->state->requestedOccurrences as $key)$this->ensureOccurrence($key);
    }
    /** Materialize one explicitly selected future path, without expanding every earlier slot. */
    private function ensureOccurrence(array $path): void {
        new OccurrenceKey($path);$prefix=array();$parent=null;$previous=$this->graph->definition()['id'];
        foreach($path as $part) {
            $node=$this->graph->node($part['block_id']);
            if($node['kind']!=='BLOCK'||$node['parent_id']!==$previous)throw new \DomainException('INVALID_EXACT_OCCURRENCE_PATH');
            $prefix[]=$part;$previous=$node['id'];$key=new OccurrenceKey($prefix);$id='BE_'.substr(hash('sha256',$this->state->execution['id'].'|'.$key->key()),0,24);
            $r=$node['schedule']['recurrence']??null;$ordinal=1;$at=null;$resolution=null;
            if($r===null&&$part['slot']!=='single')throw new \DomainException('INVALID_EXACT_OCCURRENCE_SLOT');
            if($r!==null){
                if($r['kind']==='DAILY_LOCAL'){
                    if(!is_array($part['slot'])||!isset($part['slot']['day'],$part['slot']['slot'])||$part['slot']['day']<$r['start_day']||$part['slot']['day']>=$r['start_day']+$r['days']||$part['slot']['slot']<1||$part['slot']['slot']>count($r['times']))throw new \DomainException('INVALID_EXACT_OCCURRENCE_SLOT');
                    $ordinal=($part['slot']['day']-$r['start_day'])*count($r['times'])+$part['slot']['slot'];
                }else{if(!is_array($part['slot'])||!isset($part['slot']['ordinal']))throw new \DomainException('INVALID_EXACT_OCCURRENCE_SLOT');$ordinal=$part['slot']['ordinal'];}
                $anchor=$this->anchor($node['schedule'],$parent);$found=false;
                foreach((new RecurrenceMaterializer($this->maxInstances))->slots($r,$anchor,$this->zone($node['schedule']),null,$ordinal) as $slot){if($slot['ordinal']===$ordinal){$at=$slot['available_at'];$resolution=$slot['resolution'];$found=true;}break;}
                if(!$found)throw new \DomainException('EXACT_OCCURRENCE_OUTSIDE_RECURRENCE');
            }
            if(!isset($this->state->instances[$id])){
                if(count($this->state->instances)>=$this->maxInstances)throw new \DomainException('INSTANCE_CAP');
                $this->state->instances[$id]=array('id'=>$id,'study_execution_id'=>$this->state->execution['id'],'version_id'=>$this->graph->definition()['id'],'definition_id'=>$node['id'],'parent_execution_id'=>$parent['id']??null,'occurrence_key'=>$prefix,'slot'=>$part['slot'],'ordinal'=>$ordinal,'state'=>'BLOCKED','available_at'=>null,'due_at'=>null,'started_at'=>null,'completed_at'=>null,'revision'=>0,'provenance'=>null,'random_decision_id'=>null,'timezone'=>$this->zone($node['schedule']),'schedule_revision'=>$this->state->scheduleRevision,'local_intent'=>null);
                $this->emit('OCCURRENCE_MATERIALIZED',$id,null,'BLOCKED',null,null,'EXACT_TARGET_ON_DEMAND',array('occurrence_key'=>$prefix));
            }
            if($r!==null&&in_array($this->state->instances[$id]['state'],array('BLOCKED','SCHEDULED'),true)&&!isset($this->state->offered[$id])){
                $this->state->instances[$id]['available_at']=$at;$this->state->instances[$id]['temporal_resolution']=$resolution;$this->state->instances[$id]['local_intent']=$resolution['local_intent']??($at===null?null:TemporalResolver::local($at,$this->zone($node['schedule'])));
            }
            $parent=$this->state->instances[$id];
        }
    }
    private function decide(string $instanceId): void {
        $i=$this->state->instances[$instanceId];$n=$this->graph->node($i['definition_id']);$config=$n['randomization'];if($config===null)return;if($this->dependencyPlanning()&&($this->planningPending($n['id'],$i)||!$this->planningBranchSelected($i)))return;
        foreach($this->graph->randomCandidates($n['id']) as $segment) {
            $scope=array('execution_id'=>$this->state->execution['id'],'version_id'=>$this->graph->definition()['id'],'owner_id'=>$n['id'],'segment_id'=>$segment['id'],'scope'=>$config['scope'],'occurrence_key'=>$config['scope']==='EXECUTION'?null:$i['occurrence_key']);
            $decisionId='RD_'.substr(hash('sha256',Canonical::json($scope)),0,24);
            if(!isset($this->state->decisions[$decisionId])) {
                $drawConfig=$config;$drawConfig['candidates']=$segment['candidates'];$selection=null;
                $control=$this->logicalState()['random_controls'][$n['id']]??null;if($control){$drawConfig['candidates']=array_values(array_intersect($drawConfig['candidates'],$control['candidates']));if(!$drawConfig['candidates'])throw new \DomainException('LOGICAL_RANDOM_POOL_EMPTY');if($config['strategy']==='WEIGHTED_RANDOM')$drawConfig['weights']=array_intersect_key($config['weights'],array_flip($drawConfig['candidates']));if($control['forced']!==null)$selection=$control['forced'];}$source=$control&&$control['forced']!==null?'LOGICAL_FORCED_OUTCOME':'EIPSI_RANDOM_V1';
                foreach($selection===null?($this->context['declared_decisions']??array()):array() as $declared) {
                    if($declared['owner_id']===$n['id']&&($declared['segment_id']??'default')===$segment['id']){$selection=$declared['selection'];$source='DECLARED_DECISIONS_NOT_VERIFIED_SEED_VECTOR';break;}
                }
                if($selection!==null){
                    $expected=$config['strategy']==='RANDOM_ORDER'?count($drawConfig['candidates']):($config['strategy']==='RANDOM_SELECT_ONE'?1:($config['strategy']==='RANDOM_SELECT_N'?$config['select_n']:$config['draw_count']));
                    if(count($selection)!==$expected||count(array_unique($selection))!==count($selection)||array_diff($selection,$drawConfig['candidates']))throw new \DomainException('INVALID_DECLARED_DECISION');
                    foreach($selection as $selected){if($config['strategy']==='WEIGHTED_RANDOM'&&$config['weights'][$selected]===0)throw new \DomainException('INVALID_DECLARED_WEIGHT');}
                }else{$selection=(new RandomizationEngine())->draw($drawConfig,$scope,$this->context['seed_hex']??str_repeat('00',32));}
                $this->state->decisions[$decisionId]=array('id'=>$decisionId,'study_execution_id'=>$this->state->execution['id'],'block_execution_id'=>$config['scope']==='EXECUTION'?null:$instanceId,'owner_id'=>$n['id'],'scope'=>$config['scope'],'segment_id'=>$segment['id'],'eligible_candidates'=>$drawConfig['candidates'],'selection'=>$selection,'seed_ref'=>$this->context['seed_ref']??'SIMULATION_SEED','algorithm_version'=>'EIPSI_RANDOM_V1','source'=>$source,'decided_at'=>$this->clock->now());
                $this->emit('RANDOM_DECIDED',$instanceId,null,null,null,$decisionId,$source,array('selection'=>$selection,'scope'=>$scope));if(LogicalConditions::rules($this->graph->definition()))$this->context['pending_logical_random']=true;
            }
            $selection=$this->state->decisions[$decisionId]['selection'];
            foreach($segment['candidates'] as $child){if(!in_array($child,$selection,true))$this->markSubtree($child,$i,'NOT_SCHEDULED','RANDOM_NOT_SELECTED',null,$decisionId);}
        }
    }
    private function markSubtree(string $definitionId,array $parentContext,string $state,string $reason,?array $rule=null,?string $decision=null,?int $beforeOrdinal=null): void {
        $definitions=array_merge(array($definitionId),$this->graph->descendants($definitionId));
        foreach($this->state->instances as $id=>$instance) {
            if(!in_array($instance['definition_id'],$definitions,true)||self::terminal($instance['state']))continue;
            if(!$this->sameContext($instance,$parentContext))continue;
            if($beforeOrdinal!==null&&$this->occurrenceOrdinal($instance,$definitionId)>=$beforeOrdinal)continue;
            $this->transition($id,$state,$reason,$rule,$decision);
        }
        $owner=$this->graph->assessmentOwner($definitionId);
        if($owner!==null) {
            foreach($this->state->instances as $assessment){if($assessment['definition_id']===$owner&&($assessment['state']===$state||!self::terminal($assessment['state']))&&$this->sameContext($assessment,$parentContext)&&($beforeOrdinal===null||$assessment['ordinal']<$beforeOrdinal))foreach($definitions as $definition){if($this->graph->node($definition)['kind']==='QUESTION')$this->cover($assessment['id'],$definition,$state,$rule,$decision,$reason);}}
        }
    }
    private function occurrenceOrdinal(array $instance,string $definition): int {
        foreach($instance['occurrence_key'] as $part){if($part['block_id']!==$definition)continue;$slot=$part['slot'];if($slot==='single')return 1;if(isset($slot['ordinal']))return $slot['ordinal'];$r=$this->graph->node($definition)['schedule']['recurrence'];return ($slot['day']-$r['start_day'])*count($r['times'])+$slot['slot'];}throw new \DomainException('OCCURRENCE_CONTEXT_MISSING');
    }
    private function sameContext(array $instance,array $context): bool {
        $slots=array();foreach($context['occurrence_key'] as $part)$slots[$part['block_id']]=$part['slot'];
        foreach($instance['occurrence_key'] as $part){if(isset($slots[$part['block_id']])&&Canonical::json($slots[$part['block_id']])!==Canonical::json($part['slot']))return false;}return true;
    }
    private function cover(string $assessment,string $question,string $state,?array $rule,?string $decision,string $reason): void {
        if(isset($this->state->coverage[$assessment][$question]))return;
        $entry=array('state'=>$state,'rule_id'=>$rule['id']??null,'decision_id'=>$decision,'reason'=>$reason,'decided_at'=>$this->clock->now());
        if(($this->state->coverage[$assessment][$question]??null)===$entry)return;
        $this->state->coverage[$assessment][$question]=$entry;$this->emit('QUESTION_COVERAGE',$assessment,null,$state,$rule['id']??null,$decision,$reason,array('question_id'=>$question));
    }
    private function closeGroups(): void {
        foreach(array_reverse(array_keys($this->state->instances)) as $id) {
            $i=$this->state->instances[$id];$n=$this->graph->node($i['definition_id']);
            if(self::terminal($i['state'])||$n['completion_boundary']==='ASSESSMENT'||!in_array($i['state'],array('AVAILABLE','IN_PROGRESS'),true))continue;
            $children=array_values(array_filter($this->state->instances,static function($child)use($id){return $child['parent_execution_id']===$id;}));
            if($n['completion_boundary']==='GROUP')continue; // GROUP completion follows actual answers/submit, not an empty instance list.
            $pending=false;foreach($this->state->templates as $t){if($t['parent_execution_id']===$id&&$t['pending'])$pending=true;}
            if($children&&!$pending&&!array_filter($children,static function($child){return !self::terminal($child['state']);}))$this->transition($id,'COMPLETED','TRAVERSAL_CLOSED');
        }
    }
    private function leaves(string $instanceId): array {
        $instance=$this->state->instances[$instanceId];$out=array();
        foreach($this->traversal->children($this->graph,$instance,$this->state->decisions) as $child) {
            $n=$this->graph->node($child);
            if($n['kind']==='BLOCK') {
                foreach($this->state->instances as $i){if($i['parent_execution_id']===$instanceId&&$i['definition_id']===$child&&!in_array($i['state'],array('NOT_SCHEDULED','SKIPPED_BY_RULE','WITHDRAWN','TERMINATED_BY_RULE'),true))$out=array_merge($out,$this->leaves($i['id']));}
            }else{$out[]=$child;}
        }return $out;
    }
    public function presentation(string $instanceId): array {
        $pages=array(array());foreach($this->leaves($instanceId) as $id){$n=$this->graph->node($id);if($n['kind']==='DIRECTIVE'&&$n['directive_kind']==='PAGE_BREAK'){$pages[]=array();}else{$pages[count($pages)-1][]=$id;}}
        $page=$this->state->pageCursor;
        // A WAIT resume may point to a Micro entry beyond a PageBreak. Project its
        // first visible leaf without mutating the read-only scientific snapshot.
        if(($this->state->cursor['assessment_execution_id']??null)===$instanceId){$node=$this->state->cursor['node_id'];
            if($this->graph->node($node)['kind']==='BLOCK'&&$this->graph->node($node)['scope']==='MICRO'){
                $targets=$this->graph->descendants($node);foreach($pages as $index=>$items)if(array_intersect($items,$targets)){$page=$index;break;}
            }
        }
        return array('assessment_execution_id'=>$instanceId,'pages'=>$pages,'page_cursor'=>$page);
    }
    private function flow(): void {
        for($steps=0;$steps<count($this->graph->order());++$steps) {
            $cursor=$this->state->cursor;if($cursor===null)return;$node=$this->graph->node($cursor['node_id']);
            if($node['kind']!=='DIRECTIVE'||$node['directive_kind']==='PAGE_BREAK')return;
            $rule=array('id'=>$node['id'],'action'=>$node['directive_kind'],'source'=>array('kind'=>'QUESTION','node_id'=>$node['id']),'target'=>$node['target'],'resume_target'=>$node['resume_target'],'on_target_unavailable'=>$node['on_target_unavailable']);
            $source=$this->state->instances[$cursor['assessment_execution_id']];
            if(!$this->actionable($rule,$source))throw new \DomainException('DIRECTIVE_TARGET_NOT_ACTIONABLE');
            $decision='FD_'.count($this->state->events);$this->emit('FLOW_DIRECTIVE',$source['id'],null,null,$rule['id'],$decision,$rule['action']);$this->applyRule($rule,$source,$decision);
            if($rule['action']!=='JUMP')return;
        }
        throw new \DomainException('DIRECTIVE_STEP_CAP');
    }
    private function mandatory(array $instance): bool {
        $n=$this->graph->node($instance['definition_id']);return ($n['metadata']['mandatory']??$n['metadata']['scheduled_interaction']['mandatory']??false)===true;
    }
    private function requireOpen(string $id): void {
        $i=$this->state->instances[$id];$n=$this->graph->node($i['definition_id']);
        if(!in_array($this->state->execution['state'],array('ACTIVE','WAITING'),true)||$n['completion_boundary']!=='ASSESSMENT'||!in_array($i['state'],array('AVAILABLE','IN_PROGRESS'),true)||!$this->traversal->eligible($this->graph,$i,$this->state->instances,$this->state->decisions))throw new \DomainException('ASSESSMENT_NOT_ACTIONABLE');
        if(!$this->mandatory($i))foreach($this->state->instances as $other)if($this->mandatory($other)&&in_array($other['state'],array('AVAILABLE','IN_PROGRESS'),true)&&$this->traversal->eligible($this->graph,$other,$this->state->instances,$this->state->decisions))throw new \DomainException('MANDATORY_ASSESSMENT_REQUIRED');
        if($this->state->barrier!==null)throw new \DomainException('WAIT_BARRIER_ACTIVE');
        if(($this->context['access_valid']??true)!==true)throw new \DomainException('ACCESS_CONTEXT_REQUIRED');
    }
    private function open(string $id): void {
        $this->requireOpen($id);$i=$this->state->instances[$id];
        $this->state->offered[$id]=true;
        if($i['started_at']===null){$this->state->instances[$id]['started_at']=$this->clock->now();$this->transition($id,'IN_PROGRESS','OPEN_ASSESSMENT');}
        if($this->state->execution['started_at']===null)$this->state->execution['started_at']=$this->clock->now();
        $parent=$i['parent_execution_id'];while($parent!==null){if($this->state->instances[$parent]['started_at']===null)$this->state->instances[$parent]['started_at']=$this->clock->now();$parent=$this->state->instances[$parent]['parent_execution_id'];}
        if($this->state->cursor===null||$this->state->cursor['assessment_execution_id']!==$id){$leaves=$this->leaves($id);$this->state->cursor=array('assessment_execution_id'=>$id,'node_id'=>$leaves[0]??$i['definition_id']);$this->state->pageCursor=0;}
        $this->flow();
    }
    private function answer(array $data): void {
        $question=$data['question_id'];$n=$this->graph->node($question);if($n['kind']!=='QUESTION')throw new \DomainException('NOT_A_QUESTION');
        $owner=$this->graph->assessmentOwner($question);$id=isset($data['block_execution_id'])?$this->locate($data):$this->instance($owner,$data['slot']??null)['id'];
        if($this->state->instances[$id]['definition_id']!==$owner)throw new \DomainException('QUESTION_OCCURRENCE_MISMATCH');
        $this->open($id);
        if(!in_array($question,$this->leaves($id),true)||isset($this->state->coverage[$id][$question]))throw new \DomainException('QUESTION_NOT_ELIGIBLE');
        $value=$data['value'];$type=$n['type'];
        $valid=$type==='INTEGER'?is_int($value):($type==='NUMBER'?(is_int($value)||is_float($value)):($type==='BOOLEAN'?is_bool($value):is_string($value)));
        if(!$valid)throw new \DomainException('RESPONSE_TYPE');
        if($type==='CHOICE'&&!in_array($value,array_column($n['options'],'id'),true))throw new \DomainException('RESPONSE_CHOICE');
        if(isset($n['validation']['min'])&&$value<$n['validation']['min'])throw new \DomainException('RESPONSE_MIN');
        if(isset($n['validation']['max'])&&$value>$n['validation']['max'])throw new \DomainException('RESPONSE_MAX');
        if(isset($n['validation']['max_length'])&&preg_match_all('/./us',$value,$unused)>$n['validation']['max_length'])throw new \DomainException('RESPONSE_LENGTH');
        Canonical::json($value);
        $revision=($this->state->responses[$id][$question]['revision']??0)+1;
        $this->state->responses[$id][$question]=array('value'=>$value,'revision'=>$revision,'saved_at'=>$this->clock->now(),'final'=>false);
        $this->emit('RESPONSE_SAVED',$id,null,null,null,null,'VALIDATED_RESPONSE',array('question_id'=>$question,'response_revision'=>$revision));
        $this->progressGroups($id);
        $leaves=$this->leaves($id);$index=array_search($question,$leaves,true);$this->state->cursor=array('assessment_execution_id'=>$id,'node_id'=>$leaves[$index+1]??$question);
        $this->rules('IMMEDIATE',$question,$id);
        $this->flow();
    }
    /** Presentation commands reuse ANSWER/rules; no separate frontend semantics. */
    private function savePage(array $data): void {
        $id=$this->locate($data);$this->open($id);
        $presentation=$this->presentation($id);$pages=$presentation['pages'];$page=$presentation['page_cursor'];$this->state->pageCursor=$page;
        if(!isset($pages[$page]))throw new \DomainException('PAGE_CURSOR_INVALID');
        $responses=$data['responses']??array();if(!is_array($responses))throw new \DomainException('INVALID_RESPONSES');
        foreach($responses as $q=>$value)if(!in_array($q,$pages[$page],true)||$this->graph->node($q)['kind']!=='QUESTION'||isset($this->state->coverage[$id][$q]))throw new \DomainException('QUESTION_NOT_OFFERED');
        $eventStart=count($this->state->events);
        foreach($pages[$page] as $q){
            if(!array_key_exists($q,$responses)||isset($this->state->coverage[$id][$q]))continue;
            if(isset($this->state->responses[$id][$q])&&Canonical::json($this->state->responses[$id][$q]['value'])===Canonical::json($responses[$q]))continue;
            $this->answer(array('block_execution_id'=>$id,'question_id'=>$q,'value'=>$responses[$q]));
            if($this->state->execution['state']==='TERMINATED_BY_RULE')return;
        }
        foreach(array_slice($this->state->events,$eventStart) as $event)if($event['type']==='CURSOR_JUMPED'){
            foreach($pages as $index=>$nodes)if(in_array($this->state->cursor['node_id'],$nodes,true))$this->state->pageCursor=$index;
        }
    }
    private function navigatePage(array $data,int $direction): void {
        $id=$this->locate($data);$this->open($id);$before=$this->state->pageCursor;
        if($direction===1){$this->savePage($data);if($this->state->execution['state']==='TERMINATED_BY_RULE'||$this->state->pageCursor!==$before)return;}
        elseif(($this->graph->definition()['protocol_metadata']['allow_back']??true)!==true)throw new \DomainException('BACK_NOT_ALLOWED');
        $pages=$this->presentation($id)['pages'];
        if($direction===1)foreach($pages[$before] as $q){$n=$this->graph->node($q);if($n['kind']==='QUESTION'&&$n['required']&&!isset($this->state->coverage[$id][$q])&&!isset($this->state->responses[$id][$q]))throw new \DomainException('REQUIRED_RESPONSE_MISSING: '.$q);}
        $next=$before+$direction;
        if(!isset($pages[$next]))throw new \DomainException('PAGE_BOUNDARY');
        $this->state->pageCursor=$next;$nodes=$pages[$next];
        $this->state->cursor=array('assessment_execution_id'=>$id,'node_id'=>$nodes[0]??$this->state->instances[$id]['definition_id']);
        $this->emit($direction===1?'PAGE_CURSOR_ADVANCED':'PAGE_CURSOR_BACK',null,null,null,null,null,'PRESENTATION');
    }
    private function progressGroups(string $assessmentId): void {
        $assessment=$this->state->instances[$assessmentId];
        foreach(array_reverse(array_keys($this->state->instances)) as $id) {
            $i=$this->state->instances[$id];$n=$this->graph->node($i['definition_id']);
            if($n['completion_boundary']!=='GROUP'||self::terminal($i['state'])||!$this->sameContext($i,$assessment)||$this->graph->assessmentOwner($n['id'])!==$assessment['definition_id'])continue;
            $questions=array_values(array_filter($this->leaves($id),function($q){return $this->graph->node($q)['kind']==='QUESTION';}));$answered=false;$pending=false;
            foreach($questions as $q){$present=isset($this->state->responses[$assessmentId][$q]);$answered=$answered||$present;if($this->graph->node($q)['required']&&!$present&&!isset($this->state->coverage[$assessmentId][$q]))$pending=true;}
            if($answered){
                if($i['started_at']===null)$this->state->instances[$id]['started_at']=$this->clock->now();
                $this->transition($id,$pending?'IN_PROGRESS':'COMPLETED','GROUP_RESPONSE_PROGRESS');
            }
        }
    }
    private function submit(array $data): void {
        $id=$this->locate($data);$this->open($id);
        if($this->state->execution['state']==='TERMINATED_BY_RULE')return;
        // Bulk CLI inputs are real validated answers, not fabricated completion.
        foreach($data['responses']??array() as $question=>$value){$this->answer(array('question_id'=>$question,'value'=>$value,'block_execution_id'=>$id));if($this->state->execution['state']==='TERMINATED_BY_RULE')return;}
        $this->requireOpen($id);
        foreach($this->leaves($id) as $question){$n=$this->graph->node($question);if($n['kind']==='QUESTION'&&$n['required']&&!isset($this->state->coverage[$id][$question])&&!array_key_exists($question,$this->state->responses[$id]??array()))throw new \DomainException('REQUIRED_RESPONSE_MISSING: '.$question);}
        foreach($this->state->responses[$id]??array() as $question=>$response)$this->state->responses[$id][$question]['final']=true;
        foreach($this->state->instances as $childId=>$child){if($childId!==$id&&$this->sameContext($child,$this->state->instances[$id])&&in_array($child['definition_id'],$this->graph->descendants($this->state->instances[$id]['definition_id']),true)&&!self::terminal($child['state']))$this->transition($childId,'COMPLETED','ASSESSMENT_SUBMIT');}
        $this->transition($id,'COMPLETED','ASSESSMENT_SUBMIT');$this->state->cursor=null;$this->rules('ON_ASSESSMENT_COMPLETE',$this->state->instances[$id]['definition_id'],$id);
    }
    private function lookup(array $ref,array $context): array {
        $node=$this->graph->node($ref['node_id']);$definition=$node['kind']==='QUESTION'?$this->graph->assessmentOwner($node['id']):$node['id'];
        $i=$this->select($definition,$ref['occurrence_selector'],$context,'predicate');
        if($i===null)return array('present'=>false,'value'=>null);
        if($ref['kind']==='QUESTION') {
            $response=$this->state->responses[$i['id']][$node['id']]??null;return array('present'=>$response!==null,'value'=>$response['value']??null);
        }
        return array('present'=>true,'value'=>$ref['kind']==='BLOCK_COMPLETED'?$i['state']==='COMPLETED':$i['state']);
    }
    private function rules(string $trigger,string $source,string $instanceId): void {
        $rules=array_values(array_filter($this->graph->definition()['condition_rules'],static function($r)use($trigger,$source){return $r['trigger']===$trigger&&$r['source']['node_id']===$source;}));if(!$rules)return;
        $context=$this->state->instances[$instanceId];$sourceDefinition=$this->graph->node($source)['kind']==='QUESTION'?$this->graph->assessmentOwner($source):$source;
        $rules=array_values(array_filter($rules,function($rule)use($context,$sourceDefinition,$instanceId){$selected=$this->select($sourceDefinition,$rule['source']['occurrence_selector'],$context,'condition_source');return $selected!==null&&$selected['id']===$instanceId;}));
        if(!$rules)return;
        foreach($rules as $rule){foreach(array('target','resume_target') as $field){$target=$rule[$field];if($target!==null&&$target['occurrence_selector']['kind']==='EXACT_OCCURRENCE'){$key=$target['occurrence_selector']['occurrence_key'];$this->state->requestedOccurrences[Canonical::json($key)]=$key;$this->ensureOccurrence($key);}}}
        if($this->state->requestedOccurrences)$this->refresh(false);
        $context=$this->state->instances[$instanceId];
        $resolution=(new ConditionResolver())->resolve($rules,$trigger,function($ref)use($context){return $this->lookup($ref,$context);},function($rule)use($context){return $this->actionable($rule,$context);});
        $decision='CD_'.count($this->state->events);
        $this->emit('CONDITIONS_EVALUATED',$instanceId,null,null,$resolution['winner']['id']??null,$decision,'SAME_SNAPSHOT',array('evaluations'=>$resolution['evaluations'],'winner'=>$resolution['winner']['id']??null,'suppressed'=>$resolution['suppressed']));
        if($resolution['winner']!==null)$this->applyRule($resolution['winner'],$context,$decision);
    }
    private function actionable(array $rule,array $source): bool {
        if($rule['target']===null)return true;$target=$rule['target'];
        $i=$this->targetInstance($target,$source);if($i===null||self::terminal($i['state']))return false;
        if($i['definition_id']===$source['definition_id']&&$i['id']!==$source['id']&&$i['ordinal']<=$source['ordinal'])return false;
        if($rule['action']==='WAIT')return true;
        if(TemporalResolver::state($this->clock->now(),$i['available_at'],$i['due_at'])!=='AVAILABLE')return false;
        $leaves=$this->leaves($i['id']);$targetLeaves=in_array($target['kind'],array('MICRO_ENTRY','ASSESSMENT_ENTRY'),true)?$this->graph->descendants($target['node_id']):array($target['node_id']);
        $targetIndex=null;foreach($leaves as $index=>$leaf){if(in_array($leaf,$targetLeaves,true)){$targetIndex=$index;break;}}
        if($targetIndex===null)return false;
        if($i['id']===$source['id']&&$rule['source']['kind']==='QUESTION'){$sourceIndex=array_search($rule['source']['node_id'],$leaves,true);if($sourceIndex===false||$targetIndex<=$sourceIndex)return false;}
        return true;
    }
    private function targetInstance(array $target,array $source): ?array {
        $node=$this->graph->node($target['node_id']);$owner=$this->graph->assessmentOwner($node['id']);
        $definition=$node['kind']==='BLOCK'?$node['id']:$owner;
        $instance=$this->select($definition,$target['occurrence_selector'],$source,'target');
        if($instance===null||self::terminal($instance['state']))return null;
        if($definition===$owner)return $instance;
        return $this->select($owner,array('kind'=>'SAME_PARENT_OCCURRENCE'),$instance,'target_owner');
    }
    private function applyRule(array $rule,array $source,string $decision): void {
        if($rule['action']==='CONTINUE')return;
        if($rule['action']==='TERMINATE') {
            foreach($this->state->instances as $id=>$i)if(!self::terminal($i['state']))$this->transition($id,'TERMINATED_BY_RULE','RULE_TERMINATE',$rule,$decision);
            $old=$this->state->execution['state'];$this->state->execution['state']='TERMINATED_BY_RULE';$this->state->execution['terminal_decision']=$decision;$this->state->notificationsCancelled=true;$this->state->barrier=null;
            $this->emit('EXECUTION_TERMINATED',null,$old,'TERMINATED_BY_RULE',$rule['id'],$decision,'RULE_TERMINATE');return;
        }
        $target=$rule['target'];$i=$this->targetInstance($target,$source);
        $assessments=$this->orderedAssessments();$from=array_search($source['id'],$assessments,true);$to=array_search($i['id'],$assessments,true);
        for($index=$from+1;$index<$to;++$index){$skipped=$this->state->instances[$assessments[$index]];$this->markSubtree($skipped['definition_id'],$skipped,'SKIPPED_BY_RULE','RULE_'.$rule['action'],$rule,$decision);}
        if($rule['action']==='WAIT') {
            $rank=array_flip($this->graph->order());$startRank=$rank[$source['definition_id']];$endRank=$rank[$i['definition_id']];
            $context=$source;$context['occurrence_key']=array_slice($source['occurrence_key'],0,-1);
            foreach($this->graph->order() as $definition){$node=$this->graph->node($definition);if($node['kind']==='BLOCK'&&$node['completion_boundary']==='ASSESSMENT'&&$rank[$definition]>$startRank&&$rank[$definition]<$endRank)$this->state->skipIntents[]=array('definition_id'=>$definition,'context'=>$context,'rule'=>$rule,'decision'=>$decision);}
            if($i['ordinal']>1){$targetContext=$i;$targetContext['occurrence_key']=array_slice($i['occurrence_key'],0,-1);$this->state->skipIntents[]=array('definition_id'=>$i['definition_id'],'context'=>$targetContext,'rule'=>$rule,'decision'=>$decision,'before_ordinal'=>$i['ordinal'],'target_execution_id'=>$i['id']);}
            $this->state->barrier=array('rule_id'=>$rule['id'],'source_event_id'=>'EV_'.count($this->state->events),'source_execution_id'=>$source['id'],'created_at'=>$this->clock->now(),'target'=>$rule['target'],'resume_target'=>$rule['resume_target'],'on_target_unavailable'=>$rule['on_target_unavailable'],'target_execution_id'=>$i['id'],'decision_id'=>$decision,'rule'=>$rule);
            $this->state->execution['state']='WAITING';$this->emit('WAIT_BARRIER_CREATED',$i['id'],null,null,$rule['id'],$decision,'WAIT_TARGET');return;
        }
        if($source['id']!==$i['id']&&!self::terminal($source['state']))$this->markSubtree($source['definition_id'],$source,'SKIPPED_BY_RULE','JUMP_SOURCE_CLOSED',$rule,$decision);
        $this->resume($i,$target,$rule,$decision,$source['id']===$i['id']?$rule['source']['node_id']:null);
    }
    private function resume(array $assessment,array $target,array $rule,string $decision,?string $sourceNode=null): void {
        $leaves=$this->leaves($assessment['id']);$targets=in_array($target['kind'],array('MICRO_ENTRY','ASSESSMENT_ENTRY'),true)?$this->graph->descendants($target['node_id']):array($target['node_id']);
        $start=$sourceNode===null?0:(array_search($sourceNode,$leaves,true)+1);$end=null;
        foreach($leaves as $index=>$leaf){if(in_array($leaf,$targets,true)){$end=$index;break;}}
        if($end===null)throw new \DomainException('RESUME_TARGET_NOT_ACTIONABLE');
        $skip=array_slice($leaves,$start,max(0,$end-$start));
        foreach($skip as $question){if($this->graph->node($question)['kind']==='QUESTION')$this->cover($assessment['id'],$question,'SKIPPED_BY_RULE',$rule,$decision,'RULE_RESUME');}
        foreach($this->state->instances as $id=>$i){
            if($i['id']===$assessment['id']||self::terminal($i['state'])||!$this->sameContext($i,$assessment)||!in_array($i['definition_id'],$this->graph->descendants($assessment['definition_id']),true))continue;
            $content=array_values(array_filter($this->graph->descendants($i['definition_id']),function($leaf){return $this->graph->node($leaf)['kind']!=='BLOCK';}));
            if($content&&!array_diff($content,$skip))$this->transition($id,'SKIPPED_BY_RULE','RULE_RESUME',$rule,$decision);
        }
        $this->state->cursor=array('assessment_execution_id'=>$assessment['id'],'node_id'=>$target['node_id']);$this->emit('CURSOR_JUMPED',$assessment['id'],null,null,$rule['id'],$decision,'FORWARD_TARGET',array('node_id'=>$target['node_id']));
    }
    private function resolveBarrier(): void {
        $barrier=$this->state->barrier;if($barrier===null)return;$i=$this->state->instances[$barrier['target_execution_id']];
        if(in_array($i['state'],array('AVAILABLE','IN_PROGRESS'),true)){
            $this->state->barrier=null;$this->state->execution['state']='ACTIVE';$this->resume($i,$barrier['resume_target'],$barrier['rule'],$barrier['decision_id']);$this->emit('WAIT_RESOLVED',$i['id'],null,null,$barrier['rule_id'],$barrier['decision_id'],'TARGET_AVAILABLE');
        }elseif(self::terminal($i['state'])){
            $this->state->barrier=null;$this->state->execution['state']='ACTIVE';$this->emit('WAIT_RESOLVED',$i['id'],null,null,$barrier['rule_id'],$barrier['decision_id'],'CONTINUE_AFTER_TARGET');
        }
    }
    private function changeTimezone(string $zone): void {
        $this->refresh();$old=$this->clock->timezone();$eligible=array();
        foreach($this->state->instances as $id=>$i){if(in_array($i['state'],array('BLOCKED','SCHEDULED'),true)&&($i['available_at']===null||$i['available_at']>$this->clock->now())&&!isset($this->state->offered[$id]))$eligible[$id]=true;}
        $this->clock->changeTimezone($zone);$this->state->execution['timezone']=$zone;$this->state->execution['timezone_source']='simulation_change';$this->state->scheduleRevision++;
        $this->emit('TIMEZONE_CHANGED',null,null,null,null,null,'FUTURE_ONLY',array('old_timezone'=>$old,'timezone'=>$zone,'schedule_revision'=>$this->state->scheduleRevision));
        $this->refresh();
        foreach($eligible as $id=>$unused){if($this->state->instances[$id]['state']==='EXPIRED'){$this->state->instances[$id]['provenance']['reason']='TZ_WINDOW_ELAPSED';$this->emit('TZ_WINDOW_ELAPSED',$id,null,'EXPIRED',null,null,'TZ_WINDOW_ELAPSED');}}
    }
    private function overall(): void {
        $closure=$this->graph->definition()['closure'];if($closure['kind']!=='OVERALL_DURATION')return;
        $end=TemporalResolver::add($this->state->execution['created_at'],$closure['duration'],$this->graph->definition()['timezone']['default']);if($this->clock->now()<$end)return;
        foreach($this->state->instances as $id=>$i){if(!self::terminal($i['state']))$this->transition($id,$i['available_at']===null?'NOT_SCHEDULED':'EXPIRED',$i['available_at']===null?'ANCHOR_NOT_REACHED':'OVERALL_LIMIT');}
        foreach($this->state->templates as $key=>$template)$this->state->templates[$key]['pending']=false;
    }
    private function finish(): void {
        if($this->state->barrier!==null||$this->logicalState()['waits'])return;
        foreach($this->state->templates as $template)if($template['pending'])return;
        $assessments=$this->orderedAssessments();if(!$assessments)return;
        foreach($assessments as $id)if(!self::terminal($this->state->instances[$id]['state']))return;
        $old=$this->state->execution['state'];$this->state->execution['state']='COMPLETED';$this->state->cursor=null;$this->emit('EXECUTION_COMPLETED',null,$old,'COMPLETED',null,null,'ALL_SELECTED_OPPORTUNITIES_TERMINAL',$this->outcome());
    }
    private function withdraw(string $mode,bool $escalate=false): void {
        if(!in_array($mode,array('RETAIN_FOR_ANALYSIS','DO_NOT_USE'),true))throw new \DomainException('WITHDRAWAL_MODE');
        if($this->state->withdrawal!==null){
            if($this->state->withdrawal['mode']===$mode)return;
            if(!$escalate||$mode!=='DO_NOT_USE')throw new \DomainException('WITHDRAWAL_ALREADY_DECIDED');
            $this->state->withdrawal['mode']=$mode;$this->state->withdrawal['data_policy']=$mode;$this->state->withdrawal['cleanup_state']='PENDING';
            $this->emit('WITHDRAWAL_ESCALATED',null,null,null,null,$this->state->withdrawal['id'],$mode);return;
        }
        $withdrawalId='WD_'.substr(hash('sha256',$this->state->execution['id']),0,24);
        $this->state->withdrawal=array('id'=>$withdrawalId,'mode'=>$mode,'data_policy'=>$mode,'decided_at'=>$this->clock->now(),'cleanup_state'=>$mode==='DO_NOT_USE'?'PENDING':'NOT_REQUIRED','coverage'=>array('source'=>'SIMULATION_ONLY','complete'=>false,'not_covered'=>array('REAL_STORAGE','EXTERNAL_SYSTEMS')));
        foreach($this->state->instances as $id=>$i)if(!self::terminal($i['state']))$this->transition($id,'WITHDRAWN','VOLUNTARY_WITHDRAWAL',null,$withdrawalId);
        $old=$this->state->execution['state'];if(!in_array($old,array('COMPLETED','TERMINATED_BY_RULE'),true))$this->state->execution['state']='WITHDRAWN';
        $this->state->barrier=null;$this->state->notificationsCancelled=true;$this->emit('WITHDRAWAL_CONFIRMED',null,$old,$this->state->execution['state'],null,$withdrawalId,$mode);
    }
    private function orderedAssessments(): array {
        $result=array();$walk=function($id)use(&$walk,&$result){$i=$this->state->instances[$id];$n=$this->graph->node($i['definition_id']);if($n['completion_boundary']==='ASSESSMENT'){$result[]=$id;return;}
            $ids=$this->traversal->children($this->graph,$i,$this->state->decisions);
            // Include unselected tombstones in outcome, after selected children.
            $ids=array_values(array_unique(array_merge($ids,$this->graph->children($n['id']))));
            foreach($ids as $child){$instances=array_values(array_filter($this->state->instances,static function($j)use($id,$child){return $j['parent_execution_id']===$id&&$j['definition_id']===$child;}));usort($instances,static function($a,$b){return $a['ordinal']<=>$b['ordinal'];});foreach($instances as $j)$walk($j['id']);}
        };foreach($this->state->instances as $id=>$i)if($i['parent_execution_id']===null)$walk($id);return $result;
    }
    public function outcome(): array {
        $counts=array('completed'=>0,'expired'=>0,'skipped'=>0,'not_scheduled'=>0,'withdrawn'=>0,'terminated'=>0);$map=array('COMPLETED'=>'completed','EXPIRED'=>'expired','SKIPPED_BY_RULE'=>'skipped','NOT_SCHEDULED'=>'not_scheduled','WITHDRAWN'=>'withdrawn','TERMINATED_BY_RULE'=>'terminated');
        foreach($this->orderedAssessments() as $id){$s=$this->state->instances[$id]['state'];if(isset($map[$s]))$counts[$map[$s]]++;}return $counts;
    }
    /** A read-only projection: it does not materialize, expire, reserve or advance state. */
    public function nextAction(?array $access=null): array {
        $valid=$access['valid']??($this->context['access_valid']??true);
        if(!$valid)return (new NextAction(array('kind'=>'REQUIRE_ACCESS_LINK','payload'=>array('public_message_code'=>'ACCESS_REQUIRED'))))->toArray();
        if(isset($this->context['operational_error']))return (new NextAction(array('kind'=>'OPERATIONAL_ERROR','payload'=>$this->context['operational_error'])))->toArray();
        $state=$this->state->execution['state'];
        $waits=$this->logicalState()['waits'];
        if($state==='WITHDRAWN')$action=array('kind'=>'WITHDRAWN','payload'=>array('mode'=>$this->state->withdrawal['mode'],'withdrawal_id'=>$this->state->withdrawal['id']));
        elseif($state==='TERMINATED_BY_RULE')$action=array('kind'=>'TERMINATED','payload'=>array('reason'=>'RULE_TERMINATE','decision_id'=>$this->state->execution['terminal_decision']));
        elseif($state==='COMPLETED')$action=array('kind'=>'FINISHED','payload'=>array('outcome'=>$this->outcome()['expired']>0?'INCOMPLETE':'COMPLETED'));
        elseif($this->state->barrier!==null){$target=$this->state->instances[$this->state->barrier['target_execution_id']];$action=array('kind'=>'WAIT','payload'=>array('until'=>$target['available_at'],'target'=>$this->state->barrier['target']));}
        else {
            $candidates=array();$waiting=array();
            foreach($this->orderedAssessments() as $id){$i=$this->state->instances[$id];if(self::terminal($i['state'])||$this->logicalPaused($i))continue;$waiting[]=$i;if(in_array($i['state'],array('AVAILABLE','IN_PROGRESS'),true)&&$this->traversal->eligible($this->graph,$i,$this->state->instances,$this->state->decisions))$candidates[]=$i;}
            if($candidates) {
                usort($candidates,function($a,$b){return ((int)$this->mandatory($b)<=>(int)$this->mandatory($a))?:strcmp($a['available_at'],$b['available_at'])?:($this->graph->node($a['definition_id'])['position']<=>$this->graph->node($b['definition_id'])['position'])?:strcmp($a['id'],$b['id']);});$i=$candidates[0];$leaves=$this->leaves($i['id']);
                $cursor=$this->state->cursor!==null&&$this->state->cursor['assessment_execution_id']===$i['id']?$this->state->cursor:array('assessment_execution_id'=>$i['id'],'node_id'=>$leaves[0]??$i['definition_id']);
                $action=array('kind'=>'OPEN_ASSESSMENT','payload'=>array('assessment_execution_id'=>$i['id'],'cursor'=>$cursor));
            }elseif($waits){$wait=reset($waits);$target=$this->state->instances[$wait['target_execution_id']];$action=array('kind'=>'WAIT','payload'=>array('until'=>$target['available_at'],'target'=>array('kind'=>'ASSESSMENT_ENTRY','node_id'=>$wait['target_id'],'occurrence_selector'=>array('kind'=>'EXACT_OCCURRENCE','occurrence_key'=>$target['occurrence_key']))));}elseif($waiting){usort($waiting,static function($a,$b){return strcmp($a['available_at']??'9999',$b['available_at']??'9999')?:strcmp($a['id'],$b['id']);});$i=$waiting[0];$action=array('kind'=>'WAIT','payload'=>array('until'=>$i['available_at'],'target'=>array('kind'=>'ASSESSMENT_ENTRY','node_id'=>$i['definition_id'],'occurrence_selector'=>array('kind'=>'EXACT_OCCURRENCE','occurrence_key'=>$i['occurrence_key']))));}
            else{
                $pending=null;foreach($this->state->templates as $template){if($template['pending']){$pending=$template;break;}}
                if($pending!==null){$target=array('kind'=>'ASSESSMENT_ENTRY','node_id'=>$pending['definition_id'],'occurrence_selector'=>array('kind'=>'FIRST_COMPLETED'));$action=array('kind'=>'WAIT','payload'=>array('until'=>null,'target'=>$target));}
                else{$action=array('kind'=>'OPERATIONAL_ERROR','payload'=>array('code'=>'NO_ACTIONABLE_PATH','retryable'=>false));}
            }
        }
        return (new NextAction($action))->toArray();
    }
}
