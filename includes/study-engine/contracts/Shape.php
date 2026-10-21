<?php
namespace EIPSI\StudyEngine\Contracts;
use EIPSI\StudyEngine\Domain as D;

/** Shapes only: no condition evaluator, clocks, draws or state transitions. */
final class Shape {
    public static function selector($s): void {
        Check::fields($s, array('kind'), array('occurrence_key','source_event_id'), 'selector');
        Check::set($s['kind'], D\OccurrenceSelectorKind::class, 'selector.kind');
        if ($s['kind'] === D\OccurrenceSelectorKind::EXACT_OCCURRENCE) {
            if (!isset($s['occurrence_key'])) { Check::fail('MISSING_OCCURRENCE_KEY','selector','EXACT requires key'); }
            new D\OccurrenceKey($s['occurrence_key']);
        } elseif (isset($s['occurrence_key'])) { Check::fail('INVALID_SELECTOR','selector','Unexpected occurrence key'); }
        if ($s['kind'] === D\OccurrenceSelectorKind::LAST_COMPLETED_BEFORE) {
            Check::id($s['source_event_id'] ?? null, 'selector.source_event_id');
        } elseif (isset($s['source_event_id'])) { Check::fail('INVALID_SELECTOR','selector','Unexpected source event'); }
    }
    public static function duration($d, bool $positive = true): void {
        Check::fields($d, array('kind'), array('seconds','amount','unit'), 'duration');
        Check::set($d['kind'], D\DurationKind::class, 'duration.kind');
        if ($d['kind'] === D\DurationKind::ELAPSED) {
            Check::fields($d, array('kind','seconds'), array(), 'duration');
            Check::integer($d['seconds'], 'duration.seconds', $positive ? 1 : 0);
        } else {
            Check::fields($d, array('kind','amount','unit'), array(), 'duration');
            Check::integer($d['amount'], 'duration.amount', $positive ? 1 : 0);
            if (!in_array($d['unit'], array('DAY','WEEK','MONTH'), true)) { Check::fail('INVALID_CALENDAR_UNIT','duration.unit','DAY/WEEK/MONTH required'); }
        }
    }
    public static function recurrence($r): void {
        Check::fields($r, array('kind'), array('days','times','start_day','weekdays','time','inclusive_start','interval','count','until'), 'recurrence');
        Check::set($r['kind'], D\RecurrenceKind::class, 'recurrence.kind');
        if ($r['kind'] === D\RecurrenceKind::DAILY_LOCAL) {
            Check::fields($r, array('kind','days','times','start_day'), array(), 'recurrence');
            Check::integer($r['days'], 'recurrence.days', 1); Check::integer($r['start_day'], 'recurrence.start_day', 1);
            Check::listOf($r['times'], 'recurrence.times');
            if (!$r['times'] || count(array_unique($r['times'], SORT_REGULAR)) !== count($r['times'])) { Check::fail('INVALID_RECURRENCE_TIMES','recurrence.times','Nonempty unique slots required'); }
            foreach ($r['times'] as $time) { Check::time($time, 'recurrence.times'); }
            return;
        }
        if (!isset($r['count']) && !isset($r['until'])) { Check::fail('UNBOUNDED_RECURRENCE','recurrence','Explicit termination required'); }
        if (isset($r['count'])) { Check::integer($r['count'], 'recurrence.count', 1); }
        if (isset($r['until'])) { Check::timestamp($r['until'], 'recurrence.until', false); }
        if ($r['kind'] === D\RecurrenceKind::ELAPSED) {
            Check::fields($r, array('kind','interval'), array('count','until'), 'recurrence');
            self::duration($r['interval']);
            if ($r['interval']['kind'] !== D\DurationKind::ELAPSED) { Check::fail('INVALID_INTERVAL','recurrence.interval','Elapsed recurrence requires elapsed duration'); }
        } else {
            Check::fields($r, array('kind','weekdays','time','inclusive_start'), array('count','until'), 'recurrence');
            Check::listOf($r['weekdays'], 'recurrence.weekdays');
            if (!$r['weekdays'] || count(array_unique($r['weekdays'], SORT_REGULAR)) !== count($r['weekdays'])) { Check::fail('INVALID_WEEKDAYS','recurrence.weekdays','Unique weekdays required'); }
            foreach ($r['weekdays'] as $day) { if (!in_array($day,array('MON','TUE','WED','THU','FRI','SAT','SUN'),true)) { Check::fail('INVALID_WEEKDAY','recurrence','Invalid weekday'); } }
            Check::time($r['time'], 'recurrence.time'); Check::boolean($r['inclusive_start'], 'recurrence.inclusive_start');
        }
    }
    public static function temporal($r): void {
        Check::fields($r, array('kind'), array('duration','day_index','time','at'), 'temporal_rule');
        Check::set($r['kind'], D\TemporalRuleKind::class, 'temporal_rule.kind');
        switch ($r['kind']) {
            case D\TemporalRuleKind::OFFSET: Check::fields($r,array('kind','duration')); self::duration($r['duration'],false); break;
            case D\TemporalRuleKind::LOCAL_DAY_TIME: Check::fields($r,array('kind','day_index','time')); Check::integer($r['day_index'],'day_index',1); Check::time($r['time'],'time'); break;
            case D\TemporalRuleKind::ABSOLUTE: Check::fields($r,array('kind','at')); self::absolute($r['at']); break;
            default: Check::fields($r,array('kind'));
        }
    }
    public static function absolute($a): void {
        Check::fields($a,array('basis'),array('instant','local_datetime','timezone'),'absolute');
        if ($a['basis']==='UTC') { Check::fields($a,array('basis','instant')); Check::timestamp($a['instant'],'absolute.instant',false); }
        elseif ($a['basis']==='LOCAL') {
            Check::fields($a,array('basis','local_datetime','timezone')); Check::timezone($a['timezone'],'absolute.timezone');
            Check::timestamp($a['local_datetime'].'Z','absolute.local_datetime',false);
        } else { Check::fail('INVALID_ABSOLUTE','absolute','UTC or LOCAL required'); }
    }
    public static function window($w): void {
        Check::fields($w,array('kind'),array('duration','at','anchor','anchor_ref','occurrence_selector'),'window');
        Check::set($w['kind'],D\WindowKind::class,'window.kind');
        if ($w['kind']===D\WindowKind::DURATION) { Check::fields($w,array('kind','duration')); self::duration($w['duration']); }
        elseif ($w['kind']===D\WindowKind::ABSOLUTE) { Check::fields($w,array('kind','at')); self::absolute($w['at']); }
        elseif ($w['kind']===D\WindowKind::EVENT) {
            Check::fields($w,array('kind','anchor','anchor_ref','occurrence_selector'));
            self::anchor($w['anchor'],$w['anchor_ref'],$w['occurrence_selector']);
        } else { Check::fields($w,array('kind')); }
    }
    public static function anchor($a,$ref,$selector): void {
        Check::set($a,D\Anchor::class,'anchor');
        $block=in_array($a,array(D\Anchor::BLOCK_AVAILABLE,D\Anchor::BLOCK_STARTED,D\Anchor::BLOCK_COMPLETED,D\Anchor::SPECIFIC_BLOCK_COMPLETED),true);
        if ($block) { Check::id($ref,'anchor_ref'); self::selector($selector); }
        elseif ($ref!==null || $selector!==null) { Check::fail('UNEXPECTED_ANCHOR_REF','anchor_ref','Non-block anchor has no block selector'); }
    }
    public static function schedule($s): void {
        Check::fields($s,array('anchor','anchor_ref','occurrence_selector','temporal_rule','timezone_policy','window','recurrence','dst_policy'),array(),'schedule');
        self::anchor($s['anchor'],$s['anchor_ref'],$s['occurrence_selector']); self::temporal($s['temporal_rule']);
        Check::set($s['timezone_policy'],D\TimezonePolicy::class,'schedule.timezone_policy'); self::window($s['window']);
        Check::fields($s['dst_policy'],array('gap','fold'));
        if ($s['dst_policy']!==array('gap'=>'SHIFT_FORWARD_BY_GAP','fold'=>'EARLIER_INSTANT') && Canonical::json($s['dst_policy'])!==Canonical::json(array('gap'=>'SHIFT_FORWARD_BY_GAP','fold'=>'EARLIER_INSTANT'))) { Check::fail('INVALID_DST_POLICY','dst_policy','P1-A default policies required'); }
        if ($s['temporal_rule']['kind']===D\TemporalRuleKind::RECURRENCE) { self::recurrence($s['recurrence']); }
        elseif ($s['recurrence']!==null) { Check::fail('INVALID_RECURRENCE_PLACEMENT','schedule','Recurrence only with RECURRENCE temporal rule'); }
        if (($s['anchor']===D\Anchor::ABSOLUTE_DATETIME)!==($s['temporal_rule']['kind']===D\TemporalRuleKind::ABSOLUTE)) { Check::fail('INVALID_ABSOLUTE_ANCHOR','schedule','Absolute anchor and rule must agree'); }
    }
    public static function target($t): void {
        Check::fields($t,array('kind','node_id','occurrence_selector'),array(),'target');
        Check::set($t['kind'],D\TargetKind::class,'target.kind'); Check::id($t['node_id'],'target.node_id'); self::selector($t['occurrence_selector']);
    }
    public static function reference($r): void {
        Check::fields($r,array('kind','node_id','occurrence_selector'),array(),'reference');
        Check::set($r['kind'],D\ReferenceKind::class,'reference.kind'); Check::id($r['node_id'],'reference.node_id'); self::selector($r['occurrence_selector']);
    }
    public static function operand($o): void {
        Check::fields($o,array('kind'),array('reference','type','value'),'operand');
        if ($o['kind']==='REFERENCE') { Check::fields($o,array('kind','reference')); self::reference($o['reference']); return; }
        if ($o['kind']!=='VALUE') { Check::fail('INVALID_OPERAND','operand','REFERENCE or VALUE required'); }
        Check::fields($o,array('kind','type','value')); Check::set($o['type'],D\TypedValueKind::class,'operand.type');
        $v=$o['value']; $valid=($o['type']==='STRING' && is_string($v)) || ($o['type']==='INTEGER' && is_int($v)) || ($o['type']==='NUMBER' && (is_int($v)||is_float($v))) || ($o['type']==='BOOLEAN'&&is_bool($v)) || ($o['type']==='NULL'&&$v===null);
        if (!$valid) { Check::fail('INVALID_TYPED_VALUE','operand.value','Value does not match type'); }
    }
    public static function predicate($p,int $depth=0): void {
        if ($depth>64) { Check::fail('AST_DEPTH','predicate','Maximum structural depth is 64'); }
        Check::fields($p,array('op'),array('args','arg','left','right','reference'),'predicate'); Check::set($p['op'],D\PredicateOperator::class,'predicate.op');
        if (in_array($p['op'],array('AND','OR'),true)) {
            Check::fields($p,array('op','args')); Check::listOf($p['args'],'predicate.args');
            if (count($p['args'])<2) { Check::fail('INVALID_AST_ARITY','predicate.args','AND/OR requires at least two predicates'); }
            foreach ($p['args'] as $arg) { self::predicate($arg,$depth+1); }
        } elseif ($p['op']==='NOT') { Check::fields($p,array('op','arg')); self::predicate($p['arg'],$depth+1); }
        elseif (in_array($p['op'],array('EXISTS','IS_MISSING'),true)) { Check::fields($p,array('op','reference')); self::reference($p['reference']); }
        else { Check::fields($p,array('op','left','right')); self::operand($p['left']); self::operand($p['right']); }
    }
    public static function action($action,$target,$resume,$fallback): void {
        Check::set($action,D\TransitionAction::class,'action');
        if (in_array($action,array('JUMP','WAIT'),true)) { self::target($target); }
        elseif ($target!==null) { Check::fail('UNEXPECTED_TARGET','target','CONTINUE/TERMINATE do not target content'); }
        if ($action==='WAIT') {
            if ($target['kind']!=='ASSESSMENT_ENTRY') { Check::fail('INVALID_WAIT_TARGET','target','WAIT requires assessment occurrence'); }
            if ($resume!==null) { self::target($resume); }
            if ($fallback!=='CONTINUE_AFTER_TARGET') { Check::fail('INVALID_WAIT_FALLBACK','on_target_unavailable','Explicit P1-A fallback required'); }
        } elseif ($resume!==null || $fallback!==null) { Check::fail('UNEXPECTED_WAIT_CONTEXT','action','Resume/fallback only for WAIT'); }
    }
    public static function randomization($r): void {
        Check::fields($r,array('strategy','scope','candidates','select_n','draw_count','weights','order','algorithm_version'),array('segments'),'randomization');
        Check::set($r['strategy'],D\ChildrenStrategy::class,'strategy'); Check::set($r['scope'],D\RandomScope::class,'scope'); Check::set($r['order'],D\RandomOrder::class,'order');
        if (!in_array($r['strategy'],array('RANDOM_ORDER','RANDOM_SELECT_ONE','RANDOM_SELECT_N','WEIGHTED_RANDOM'),true)) { Check::fail('INVALID_RANDOM_STRATEGY','strategy','Random strategy required'); }
        Check::listOf($r['candidates'],'candidates');
        if (!$r['candidates'] || count(array_unique($r['candidates'],SORT_REGULAR))!==count($r['candidates'])) { Check::fail('INVALID_CANDIDATES','candidates','Nonempty unique IDs required'); }
        foreach ($r['candidates'] as $id) { Check::id($id,'candidate'); }
        if (isset($r['segments'])) {
            Check::listOf($r['segments'],'segments');
            if (!$r['segments']) { Check::fail('INVALID_RANDOM_SEGMENTS','segments','Explicit segments cannot be empty'); }
            $ids=array(); $members=array();
            foreach ($r['segments'] as $segment) {
                Check::fields($segment,array('id','candidates')); Check::id($segment['id'],'segment.id'); Check::listOf($segment['candidates'],'segment.candidates');
                if (isset($ids[$segment['id']]) || !$segment['candidates']) { Check::fail('INVALID_RANDOM_SEGMENTS','segments','Unique nonempty segments required'); } $ids[$segment['id']]=true;
                foreach ($segment['candidates'] as $id) { Check::id($id,'segment.candidate'); if (isset($members[$id])) { Check::fail('INVALID_RANDOM_SEGMENTS','segments','Candidates cannot occur in two segments'); } $members[$id]=true; }
                if ($r['strategy']==='RANDOM_SELECT_N' && (!is_int($r['select_n']) || $r['select_n']>count($segment['candidates']))) { Check::fail('RANDOM_N_EXCEEDS_CHILDREN','segment','N applies separately to each segment'); }
                if ($r['strategy']==='WEIGHTED_RANDOM') {
                    $positive=0; foreach ($segment['candidates'] as $id) { if (($r['weights'][$id]??0)>0) { $positive++; } }
                    if (!is_int($r['draw_count']) || $r['draw_count']>$positive) { Check::fail('INVALID_WEIGHTS','segment','Draw count exceeds segment positive pool'); }
                }
            }
            if (array_diff(array_keys($members),$r['candidates']) || array_diff($r['candidates'],array_keys($members))) { Check::fail('INVALID_RANDOM_SEGMENTS','segments','Segments must partition candidates'); }
        }
        if ($r['algorithm_version']!=='EIPSI_RANDOM_V1') { Check::fail('INVALID_RANDOM_ALGORITHM','algorithm_version','Approved contract required'); }
        if ($r['strategy']==='RANDOM_SELECT_N') {
            Check::integer($r['select_n'],'select_n',1);
            if ($r['select_n']>count($r['candidates'])) { Check::fail('RANDOM_N_EXCEEDS_CHILDREN','select_n','N exceeds static candidates'); }
        } elseif ($r['select_n']!==null) { Check::fail('UNEXPECTED_SELECT_N','select_n','Only RANDOM_SELECT_N has N'); }
        if ($r['strategy']==='WEIGHTED_RANDOM') {
            Check::integer($r['draw_count'],'draw_count',1);
            if (!is_array($r['weights']) || array_diff(array_keys($r['weights']),$r['candidates']) || array_diff($r['candidates'],array_keys($r['weights']))) { Check::fail('INVALID_WEIGHTS','weights','One integer weight per candidate'); }
            $positive=0;
            foreach ($r['weights'] as $weight) { if (!is_int($weight)||$weight<0||$weight>2147483647) { Check::fail('INVALID_WEIGHTS','weights','Bounded nonnegative integer weights required'); } if ($weight>0) { $positive++; } }
            if (!$positive || $r['draw_count']>$positive || array_sum($r['weights'])>2147483647) { Check::fail('INVALID_WEIGHTS','weights','Positive pool/count/total bounds violated'); }
        } elseif ($r['draw_count']!==null || $r['weights']!==array()) { Check::fail('UNEXPECTED_WEIGHTS','weights','Only WEIGHTED_RANDOM has weights/draw_count'); }
    }
}
