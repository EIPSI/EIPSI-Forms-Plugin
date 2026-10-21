<?php
namespace EIPSI\StudyEngine\Contracts;
use EIPSI\StudyEngine\Domain as D;

/** Declarative P1-C oracles. Validates references/payloads, never simulates. */
final class ExpectedTrace {
    public static function validate(array $trace, StudyVersionDefinition $definition): void {
        Check::fields($trace, array('contract_version','fixture_id','scenarios'));
        if ($trace['contract_version'] !== 'TRACE_V1') { Check::fail('INVALID_TRACE_VERSION','trace','TRACE_V1 required'); }
        Check::text($trace['fixture_id'],'fixture_id'); Check::listOf($trace['scenarios'],'scenarios');
        if (!$trace['scenarios']) { Check::fail('EMPTY_TRACE','scenarios','At least one scenario required'); }
        $nodes = array(); $rules = array();
        foreach ($definition->toArray()['nodes'] as $node) { $nodes[$node['id']] = $node; }
        foreach ($definition->toArray()['condition_rules'] as $rule) { $rules[$rule['id']] = $rule; }
        $ids = array();
        foreach ($trace['scenarios'] as $scenario) {
            Check::fields($scenario,array('id','inputs','steps')); Check::id($scenario['id'],'scenario.id');
            if (isset($ids[$scenario['id']])) { Check::fail('DUPLICATE_SCENARIO','scenario.id','Unique scenario IDs'); } $ids[$scenario['id']] = true;
            $inputs = $scenario['inputs'];
            Check::fields($inputs,array('timezone','created_at'),array('seed_ref','random_oracle','declared_decisions'));
            Check::timezone($inputs['timezone'],'inputs.timezone'); Check::timestamp($inputs['created_at'],'inputs.created_at',false);
            if (isset($inputs['seed_ref']) || isset($inputs['declared_decisions']) || isset($inputs['random_oracle'])) {
                Check::id($inputs['seed_ref'] ?? null,'inputs.seed_ref');
                if (($inputs['random_oracle'] ?? null) !== 'DECLARED_DECISIONS_NOT_VERIFIED_SEED_VECTOR') { Check::fail('UNVERIFIED_RANDOM_ORACLE','random_oracle','Do not claim computed seed vectors'); }
                Check::listOf($inputs['declared_decisions'] ?? null,'declared_decisions');
                foreach ($inputs['declared_decisions'] as $decision) { Check::fields($decision,array('owner_id','selection'),array('segment_id')); self::random($decision,$nodes); }
            }
            Check::listOf($scenario['steps'],'steps');
            if (!$scenario['steps']) { Check::fail('EMPTY_TRACE','steps','At least one step required'); }
            foreach ($scenario['steps'] as $step) {
                Check::fields($step,array('event','expected')); self::event($step['event'],$nodes);
                Check::listOf($step['expected'],'expected');
                foreach ($step['expected'] as $expected) { self::assertion($expected,$nodes,$rules); }
            }
        }
    }
    private static function node($id,array $nodes,string $kind = ''): array {
        Check::id($id,'trace.node_id');
        if (!isset($nodes[$id]) || ($kind !== '' && $nodes[$id]['kind'] !== $kind)) { Check::fail('INVALID_TRACE_REFERENCE','trace.node_id','Referenced node/type does not exist'); }
        return $nodes[$id];
    }
    private static function slot($slot): void {
        new D\OccurrenceKey(array(array('block_id'=>'BLOCK_SLOT','slot'=>$slot)));
    }
    private static function random(array $d,array $nodes): void {
        $owner = self::node($d['owner_id'],$nodes,'BLOCK');
        if ($owner['randomization'] === null) { Check::fail('INVALID_TRACE_RANDOM','owner_id','Random owner required'); }
        Check::listOf($d['selection'],'selection');
        if (!$d['selection'] || count(array_unique($d['selection'],SORT_REGULAR)) !== count($d['selection'])) { Check::fail('INVALID_TRACE_RANDOM','selection','Unique nonempty selection required'); }
        foreach ($d['selection'] as $id) { Check::id($id,'selection'); }
        if (array_diff($d['selection'],$owner['randomization']['candidates'])) { Check::fail('INVALID_TRACE_RANDOM','selection','Selection outside candidate pool'); }
        $r = $owner['randomization'];
        if (isset($r['segments'])) {
            Check::id($d['segment_id'] ?? null,'segment_id');
            $found=null; foreach ($r['segments'] as $segment) { if ($segment['id']===$d['segment_id']) { $found=$segment; } }
            if ($found===null || array_diff($d['selection'],$found['candidates'])) { Check::fail('INVALID_TRACE_RANDOM','segment_id','Selection must belong to explicit segment'); }
            $r['candidates']=$found['candidates'];
        } elseif (isset($d['segment_id'])) { Check::fail('INVALID_TRACE_RANDOM','segment_id','No segment declared by owner'); }

        $count = $r['strategy'] === 'RANDOM_SELECT_ONE' ? 1 : ($r['strategy'] === 'RANDOM_SELECT_N' ? $r['select_n'] : ($r['strategy'] === 'WEIGHTED_RANDOM' ? $r['draw_count'] : count($r['candidates'])));
        if (count($d['selection']) !== $count) { Check::fail('INVALID_TRACE_RANDOM','selection','Selection cardinality violates strategy'); }
        if (isset($d['scope']) && $d['scope'] !== $r['scope']) { Check::fail('INVALID_TRACE_RANDOM','scope','Scope differs from owner'); }
    }
    private static function event($event,array $nodes): void {
        Check::fields($event,array('kind','data')); $data = $event['data'];
        switch ($event['kind']) {
            case 'EXECUTION_CREATED': case 'RELOAD': Check::fields($data,array()); break;
            case 'RESPONSE_SAVED': Check::fields($data,array('question_id','value')); self::node($data['question_id'],$nodes,'QUESTION'); break;
            case 'ASSESSMENT_OPENED': Check::fields($data,array('node_id')); self::assessment($data['node_id'],$nodes); break;
            case 'ASSESSMENT_COMPLETED':
                Check::fields($data,array('node_id'),array('at','slot','responses')); self::assessment($data['node_id'],$nodes);
                if (isset($data['slot'])) { self::slot($data['slot']); }
                if (isset($data['at'])) { Check::timestamp($data['at'],'event.at',false); }
                if (isset($data['responses'])) { if (!is_array($data['responses'])) { Check::fail('INVALID_TRACE_RESPONSES','responses','Map required'); } foreach ($data['responses'] as $id=>$value) { self::node($id,$nodes,'QUESTION'); } }
                break;
            case 'CLOCK_ADVANCED': Check::fields($data,array('at')); Check::timestamp($data['at'],'event.at',false); break;
            case 'TIMEZONE_CHANGED': Check::fields($data,array('timezone','at')); Check::timezone($data['timezone'],'event.timezone'); Check::timestamp($data['at'],'event.at',false); break;
            case 'WITHDRAWAL_CONFIRMED': Check::fields($data,array('mode','at')); Check::set($data['mode'],D\WithdrawalMode::class,'mode'); Check::timestamp($data['at'],'event.at',false); break;
            case 'CAPABILITY_OPENED':
                Check::fields($data,array('purpose','method'),array('target_id'));
                if (!in_array($data['purpose'],array('ACCESS','WITHDRAWAL_CONFIRM'),true) || $data['method'] !== 'GET') { Check::fail('INVALID_TRACE_CAPABILITY','event','Explicit GET capability purpose required'); }
                if (isset($data['target_id'])) { self::node($data['target_id'],$nodes,'BLOCK'); } break;
            default: Check::fail('INVALID_TRACE_EVENT','event.kind','Unknown trace event');
        }
    }
    private static function assessment($id,array $nodes): void {
        $n = self::node($id,$nodes,'BLOCK');
        if ($n['completion_boundary'] !== 'ASSESSMENT') { Check::fail('INVALID_TRACE_ASSESSMENT','node_id','Assessment boundary required'); }
    }
    private static function assertion($a,array $nodes,array $rules): void {
        if (!is_array($a)) { Check::fail('INVALID_TRACE_ASSERTION','expected','Record required'); }
        switch ($a['kind'] ?? null) {
            case 'BLOCK_STATE': Check::fields($a,array('kind','node_id','slot','state')); self::node($a['node_id'],$nodes,'BLOCK'); self::slot($a['slot']); Check::set($a['state'],D\BlockExecutionState::class,'state'); break;
            case 'EXECUTION_STATE': Check::fields($a,array('kind','state')); Check::set($a['state'],D\StudyExecutionState::class,'state'); break;
            case 'RESPONSE_COVERAGE':
                Check::fields($a,array('kind','question_ids','state')); Check::listOf($a['question_ids'],'question_ids');
                foreach ($a['question_ids'] as $id) { self::node($id,$nodes,'QUESTION'); } Check::set($a['state'],D\BlockExecutionState::class,'state'); break;
            case 'WINDOW':
                Check::fields($a,array('kind','node_id','slot','available_at','due_at')); self::node($a['node_id'],$nodes,'BLOCK'); self::slot($a['slot']); Check::timestamp($a['available_at'],'available_at',false); Check::timestamp($a['due_at'],'due_at');
                if ($a['due_at'] !== null && $a['due_at'] <= $a['available_at']) { Check::fail('INVALID_TRACE_WINDOW','window','Positive window required'); } break;
            case 'CURSOR': Check::fields($a,array('kind','node_id')); self::node($a['node_id'],$nodes); break;
            case 'RANDOM_SELECTION': Check::fields($a,array('kind','owner_id','scope','selection'),array('segment_id')); Check::set($a['scope'],D\RandomScope::class,'scope'); self::random($a,$nodes); break;
            case 'WAIT_BARRIER':
                Check::fields($a,array('kind','rule_id','target','resume_target')); Check::id($a['rule_id'],'rule_id');
                if (!isset($rules[$a['rule_id']]) || $rules[$a['rule_id']]['action'] !== 'WAIT' || Canonical::json($rules[$a['rule_id']]['target']) !== Canonical::json($a['target']) || Canonical::json($rules[$a['rule_id']]['resume_target']) !== Canonical::json($a['resume_target'])) { Check::fail('INVALID_TRACE_BARRIER','rule_id','Barrier must match rule'); } break;
            case 'ASSESSMENT_COUNT': Check::fields($a,array('kind','node_id','count')); self::assessment($a['node_id'],$nodes); Check::integer($a['count'],'count',1); break;
            case 'STABLE_HISTORY':
                Check::fields($a,array('kind','fields')); Check::listOf($a['fields'],'fields');
                foreach ($a['fields'] as $field) { if (!in_array($field,array('execution_id','occurrence_ids','responses','cursor','random_decisions'),true)) { Check::fail('INVALID_TRACE_HISTORY','fields','Unknown stable field'); } } break;
            case 'FROZEN_STATES': Check::fields($a,array('kind','states')); Check::listOf($a['states'],'states'); foreach ($a['states'] as $state) { Check::set($state,D\BlockExecutionState::class,'state'); } break;
            case 'DATA_POLICY': Check::fields($a,array('kind','mode')); Check::set($a['mode'],D\WithdrawalMode::class,'mode'); break;
            case 'NO_WITHDRAWAL_RECORD': case 'NOTIFICATIONS_CANCELLED': Check::fields($a,array('kind')); break;
            default: Check::fail('INVALID_TRACE_ASSERTION','expected.kind','Unknown expected assertion');
        }
    }
}
