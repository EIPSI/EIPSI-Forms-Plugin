<?php
namespace EIPSI\StudyEngine\Contracts;
use EIPSI\StudyEngine\Domain as D;

/** Copy-on-write immutable JSON records. Constructors validate, never execute. */
abstract class Record implements \JsonSerializable {
    private $data;
    final public function __construct(array $data) {
        static::validate($data);
        $this->data = Canonical::normalize($data);
    }
    abstract protected static function validate(array $data): void;
    final public function toArray(): array { return $this->data; }
    final public function jsonSerialize(): array { return $this->data; }
    final public function normalizedJson(): string { return Canonical::json($this->data); }
}

final class ScheduleSpec extends Record {
    protected static function validate(array $d): void { Shape::schedule($d); }
}
final class Predicate extends Record {
    protected static function validate(array $d): void { Shape::predicate($d); }
}
final class Target extends Record {
    protected static function validate(array $d): void { Shape::target($d); }
}
final class RandomizationDefinition extends Record {
    protected static function validate(array $d): void { Shape::randomization($d); }
}
final class BlockDefinition extends Record {
    protected static function validate(array $d): void {
        Check::fields($d,array('kind','id','logical_id','version_id','parent_id','scope','position','children_strategy','randomization','schedule','completion_boundary','metadata'));
        if ($d['kind']!==D\NodeKind::BLOCK) { Check::fail('INVALID_NODE_KIND','kind','Block kind required'); }
        foreach (array('id','logical_id','version_id','parent_id') as $k) { Check::id($d[$k],$k); }
        Check::integer($d['position'],'position'); Check::set($d['scope'],D\BlockScope::class,'scope');
        Check::set($d['children_strategy'],D\ChildrenStrategy::class,'children_strategy'); Check::set($d['completion_boundary'],D\CompletionBoundary::class,'completion_boundary');
        if (!is_array($d['metadata'])) { Check::fail('INVALID_METADATA','metadata','JSON metadata required'); }
        if ($d['schedule']!==null) { Shape::schedule($d['schedule']); }
        if(isset($d['metadata']['independent_children']))Check::boolean($d['metadata']['independent_children'],'independent_children');
        if(isset($d['metadata']['scheduled_interaction'])){
            $interaction=$d['metadata']['scheduled_interaction'];Check::fields($interaction,array('delivery','window_seconds','reminders'),array('mandatory','unique_response','timing'));foreach(array('mandatory','unique_response') as $flag)if(isset($interaction[$flag]))Check::boolean($interaction[$flag],$flag);if(isset($interaction['timing'])&&!in_array($interaction['timing'],array('FIXED','RELATIVE'),true))Check::fail('INVALID_INTERACTION_TIMING','timing','Unknown timing');
            if(!in_array($interaction['delivery'],array('START','RANDOM'),true))Check::fail('INVALID_DELIVERY','delivery','Unknown delivery mode');
            Check::integer($interaction['window_seconds'],'window_seconds',900);if($interaction['window_seconds']>86400||$interaction['window_seconds']%900!==0)Check::fail('INVALID_DELIVERY_WINDOW','window_seconds','15 minute delivery grid required');
            Check::listOf($interaction['reminders'],'reminders');$seen=array();foreach($interaction['reminders'] as $offset){Check::integer($offset,'reminders',1);if(isset($seen[$offset]))Check::fail('DUPLICATE_REMINDER','reminders','Unique offsets required');$seen[$offset]=true;if(($d['schedule']['window']['kind']??null)==='DURATION'&&($d['schedule']['window']['duration']['kind']??null)==='ELAPSED'&&$offset>=$d['schedule']['window']['duration']['seconds'])Check::fail('REMINDER_AFTER_DEADLINE','reminders','Reminder must precede expiry');}
        }

        $random=in_array($d['children_strategy'],array('RANDOM_ORDER','RANDOM_SELECT_ONE','RANDOM_SELECT_N','WEIGHTED_RANDOM'),true);
        if ($random) {
            Shape::randomization($d['randomization']);
            if ($d['randomization']['strategy']!==$d['children_strategy']) { Check::fail('STRATEGY_MISMATCH','randomization.strategy','Strategy must match block'); }
        } elseif ($d['randomization']!==null) { Check::fail('UNEXPECTED_RANDOMIZATION','randomization','Nonrandom strategy has no random config'); }
    }
}
final class QuestionDefinition extends Record {
    protected static function validate(array $d): void {
        Check::fields($d,array('kind','id','version_id','parent_id','position','response_key','type','options','validation','required','semantic_role','content'));
        if ($d['kind']!==D\NodeKind::QUESTION) { Check::fail('INVALID_NODE_KIND','kind','Question kind required'); }
        foreach (array('id','version_id','parent_id','response_key') as $k) { Check::id($d[$k],$k); }
        Check::integer($d['position'],'position'); Check::set($d['type'],D\QuestionType::class,'type'); Check::boolean($d['required'],'required');
        if ($d['semantic_role']!==null && $d['semantic_role']!=='INFORMED_CONSENT') { Check::fail('INVALID_SEMANTIC_ROLE','semantic_role','Unknown optional role'); }
        Check::listOf($d['options'],'options');
        $ids=array();
        foreach ($d['options'] as $option) {
            Check::fields($option,array('id','label')); Check::id($option['id'],'option.id'); Check::text($option['label'],'option.label');
            if (isset($ids[$option['id']])) { Check::fail('DUPLICATE_OPTION','options','Option IDs unique'); } $ids[$option['id']]=true;
        }
        if (($d['type']==='CHOICE')!==(!empty($d['options']))) { Check::fail('INVALID_OPTIONS','options','Choices require options; other types do not'); }
        Check::fields($d['validation'],array(),array('min','max','max_length'));
        foreach ($d['validation'] as $k=>$v) {
            if ($k==='max_length') { Check::integer($v,$k,1); }
            elseif (!is_int($v)&&!is_float($v)) { Check::fail('INVALID_QUESTION_VALIDATION',$k,'Numeric bound required'); }
        }
        if (isset($d['validation']['min'],$d['validation']['max']) && $d['validation']['min']>$d['validation']['max']) { Check::fail('INVALID_QUESTION_VALIDATION','validation','min exceeds max'); }
        self::content($d['content']);
    }
    public static function content($c): void {
        Check::fields($c,array('content_id','version','snapshot','sha256'));
        Check::id($c['content_id'],'content_id'); Check::integer($c['version'],'content.version',1); Check::text($c['snapshot'],'snapshot');
        if (!is_string($c['sha256']) || !hash_equals(hash('sha256',$c['snapshot']),$c['sha256'])) { Check::fail('CONTENT_HASH_MISMATCH','content.sha256','Snapshot hash mismatch'); }
    }
}
final class ContentNode extends Record {
    protected static function validate(array $d): void {
        Check::fields($d,array('kind','id','version_id','parent_id','position','type','content','durable_reference'));
        if ($d['kind']!==D\NodeKind::CONTENT) { Check::fail('INVALID_NODE_KIND','kind','Content kind required'); }
        foreach (array('id','version_id','parent_id') as $k) { Check::id($d[$k],$k); }
        Check::integer($d['position'],'position'); Check::set($d['type'],D\ContentType::class,'type'); QuestionDefinition::content($d['content']);
        if ($d['type']==='MEDIA') { Check::fields($d['durable_reference'],array('uri','sha256')); Check::text($d['durable_reference']['uri'],'media.uri'); if (!preg_match('/^[a-f0-9]{64}$/D',$d['durable_reference']['sha256'])) { Check::fail('INVALID_MEDIA_HASH','media.sha256','SHA256 required'); } }
        elseif ($d['durable_reference']!==null) { Check::fail('UNEXPECTED_MEDIA_REFERENCE','durable_reference','TEXT has embedded snapshot'); }
    }
}
final class FlowDirective extends Record {
    protected static function validate(array $d): void {
        Check::fields($d,array('kind','id','version_id','parent_id','position','directive_kind','target','resume_target','on_target_unavailable'));
        if ($d['kind']!==D\NodeKind::DIRECTIVE) { Check::fail('INVALID_NODE_KIND','kind','Directive kind required'); }
        foreach (array('id','version_id','parent_id') as $k) { Check::id($d[$k],$k); } Check::integer($d['position'],'position'); Check::set($d['directive_kind'],D\DirectiveKind::class,'directive_kind');
        if ($d['directive_kind']==='PAGE_BREAK') {
            if ($d['target']!==null||$d['resume_target']!==null||$d['on_target_unavailable']!==null) { Check::fail('INVALID_PAGE_BREAK','directive','PageBreak has no target/answer/execution'); }
        } else { Shape::action($d['directive_kind'],$d['target'],$d['resume_target'],$d['on_target_unavailable']); }
    }
}
final class ConditionRule extends Record {
    public const COMPLETION_PRECEDENCE = array('TERMINATE','WAIT','JUMP','CONTINUE');
    public const IMMEDIATE_RESOLUTION = 'FIRST_TRUE_VALID_BY_ORDER';
    public const COMPLETION_RESOLUTION = 'ALL_SAME_SNAPSHOT_THEN_PRECEDENCE_ORDER_ID';
    protected static function validate(array $d): void {
        Check::fields($d,array('id','version_id','trigger','source','order','predicate','action','target','resume_target','on_target_unavailable','metadata'));
        Check::id($d['id'],'rule.id'); Check::id($d['version_id'],'rule.version_id'); Check::set($d['trigger'],D\ConditionTrigger::class,'rule.trigger'); Check::integer($d['order'],'rule.order');
        Check::fields($d['source'],array('kind','node_id','occurrence_selector'));
        if (!in_array($d['source']['kind'],array('QUESTION','BLOCK'),true)) { Check::fail('INVALID_RULE_SOURCE','source.kind','QUESTION/BLOCK required'); }
        Check::id($d['source']['node_id'],'source.node_id'); Shape::selector($d['source']['occurrence_selector']);
        Shape::predicate($d['predicate']); Shape::action($d['action'],$d['target'],$d['resume_target'],$d['on_target_unavailable']);
        if (!is_array($d['metadata'])) { Check::fail('INVALID_METADATA','rule.metadata','JSON metadata required'); }
    }
}
final class StudyVersionDefinition extends Record {
    protected static function validate(array $d): void {
        $issues=(new \EIPSI\StudyEngine\Validation\DefinitionValidator())->validate($d);
        if ($issues) { throw new ContractViolation($issues); }
    }
    public function normalize(): array { return Canonical::definition($this->toArray()); }
    public function protocolHash(): string { return Canonical::protocolHash($this->toArray()); }
}
final class StudyExecution extends Record {
    protected static function validate(array $d): void {
        Check::fields($d,array('id','study_id','version_id','subject','timezone','created_at','started_at','first_access_at','state','revision','wait_context','cursor'));
        foreach (array('id','study_id','version_id') as $k) { Check::id($d[$k],$k); }
        Check::fields($d['subject'],array('kind','id')); Check::set($d['subject']['kind'],D\SubjectKind::class,'subject.kind'); Check::id($d['subject']['id'],'subject.id');
        Check::timezone($d['timezone'],'timezone'); Check::timestamp($d['created_at'],'created_at',false);
        Check::timestamp($d['started_at'],'started_at'); Check::timestamp($d['first_access_at'],'first_access_at'); Check::set($d['state'],D\StudyExecutionState::class,'state'); Check::integer($d['revision'],'revision');
        if ($d['cursor']!==null) { self::cursor($d['cursor']); }
        if ($d['wait_context']!==null) { self::wait($d['wait_context']); }
    }
    public static function cursor($c): void {
        Check::fields($c,array('assessment_execution_id','node_id')); Check::id($c['assessment_execution_id'],'cursor.assessment_execution_id'); Check::id($c['node_id'],'cursor.node_id');
    }
    public static function wait($w): void {
        Check::fields($w,array('source_event_id','rule_id','source_execution_id','created_at','target','resume_target','on_target_unavailable'));
        foreach (array('source_event_id','rule_id','source_execution_id') as $k) { Check::id($w[$k],$k); } Check::timestamp($w['created_at'],'wait.created_at',false);
        Shape::action('WAIT',$w['target'],$w['resume_target'],$w['on_target_unavailable']);
    }
}
final class BlockExecution extends Record {
    protected static function validate(array $d): void {
        Check::fields($d,array('id','study_execution_id','version_id','definition_id','parent_execution_id','occurrence_key','state','available_at','due_at','started_at','completed_at','revision','provenance','random_decision_id'));
        foreach (array('id','study_execution_id','version_id','definition_id') as $k) { Check::id($d[$k],$k); }
        if ($d['parent_execution_id']!==null) { Check::id($d['parent_execution_id'],'parent_execution_id'); }
        new D\OccurrenceKey($d['occurrence_key']); Check::set($d['state'],D\BlockExecutionState::class,'state'); Check::integer($d['revision'],'revision');
        foreach (array('available_at','due_at','started_at','completed_at') as $k) { Check::timestamp($d[$k],$k); }
        if ($d['available_at']!==null&&$d['due_at']!==null&&$d['due_at']<=$d['available_at']) { Check::fail('INVALID_WINDOW','due_at','Half-open positive window required'); }
        if ($d['state']==='COMPLETED' && $d['completed_at']===null) { Check::fail('MISSING_COMPLETION_TIME','completed_at','Completed has completion time'); }
        if ($d['random_decision_id']!==null) { Check::id($d['random_decision_id'],'random_decision_id'); }
        if ($d['provenance']!==null) { self::provenance($d['provenance']); }
        if (in_array($d['state'],array('SKIPPED_BY_RULE','TERMINATED_BY_RULE'),true) && ($d['provenance']===null||$d['provenance']['rule_id']===null)) { Check::fail('MISSING_RULE_PROVENANCE','provenance','Rule terminal needs rule provenance'); }
    }
    public static function provenance($p): void {
        Check::fields($p,array('decision_id','rule_id','source_execution_id','source_node_id','decided_at','reason'));
        foreach (array('decision_id','source_execution_id','source_node_id') as $k) { Check::id($p[$k],$k); }
        if ($p['rule_id']!==null) { Check::id($p['rule_id'],'rule_id'); } Check::timestamp($p['decided_at'],'decided_at',false); Check::text($p['reason'],'reason');
    }
}
final class RandomDecision extends Record {
    protected static function validate(array $d): void {
        Check::fields($d,array('id','study_execution_id','block_execution_id','strategy_id','scope','eligible_candidates','selection','seed_ref','algorithm_version','decided_at'),array('segment_id'));
        foreach (array('id','study_execution_id','strategy_id','seed_ref') as $k) { Check::id($d[$k],$k); }
        if (isset($d['segment_id'])) { Check::id($d['segment_id'],'segment_id'); }
        Check::set($d['scope'],D\RandomScope::class,'scope');
        if ($d['scope']==='BLOCK_INSTANCE') { Check::id($d['block_execution_id'],'block_execution_id'); }
        elseif ($d['block_execution_id']!==null) { Check::fail('INVALID_RANDOM_SCOPE','block_execution_id','Execution decision not keyed by instance'); }
        foreach (array('eligible_candidates','selection') as $k) {
            Check::listOf($d[$k],$k); if (!$d[$k]||count(array_unique($d[$k],SORT_REGULAR))!==count($d[$k])) { Check::fail('INVALID_RANDOM_DECISION',$k,'Unique nonempty IDs required'); }
            foreach ($d[$k] as $id) { Check::id($id,$k); }
        }
        if (array_diff($d['selection'],$d['eligible_candidates'])) { Check::fail('INVALID_RANDOM_DECISION','selection','Selection outside eligibility snapshot'); }
        if ($d['algorithm_version']!=='EIPSI_RANDOM_V1') { Check::fail('INVALID_RANDOM_ALGORITHM','algorithm_version','Approved algorithm required'); } Check::timestamp($d['decided_at'],'decided_at',false);
    }
}
final class WithdrawalRecord extends Record {
    protected static function validate(array $d): void {
        Check::fields($d,array('id','study_execution_id','subject','mode','decided_at','source','data_policy','cleanup_state','coverage'));
        Check::id($d['id'],'id'); Check::id($d['study_execution_id'],'study_execution_id'); Check::fields($d['subject'],array('kind','id')); Check::set($d['subject']['kind'],D\SubjectKind::class,'subject.kind'); Check::id($d['subject']['id'],'subject.id');
        Check::set($d['mode'],D\WithdrawalMode::class,'mode'); Check::set($d['data_policy'],D\WithdrawalMode::class,'data_policy'); Check::set($d['cleanup_state'],D\CleanupState::class,'cleanup_state'); Check::timestamp($d['decided_at'],'decided_at',false);
        if (!in_array($d['source'],array('SESSION','EMAIL_CAPABILITY','EMAIL_ENTRY','ADMIN_REQUEST'),true)) { Check::fail('INVALID_WITHDRAWAL_SOURCE','source','Explicit actor/purpose required'); }
        if ($d['mode']!==$d['data_policy'] || ($d['mode']==='RETAIN_FOR_ANALYSIS')!==($d['cleanup_state']==='NOT_REQUIRED')) { Check::fail('INVALID_WITHDRAWAL_POLICY','data_policy','Mode/data/cleanup do not agree'); }
        Check::fields($d['coverage'],array('source','complete','not_covered')); Check::text($d['coverage']['source'],'coverage.source'); Check::boolean($d['coverage']['complete'],'coverage.complete'); Check::listOf($d['coverage']['not_covered'],'coverage.not_covered');
        foreach ($d['coverage']['not_covered'] as $area) { Check::text($area,'coverage.not_covered'); }
        if ($d['coverage']['complete'] && ($d['coverage']['not_covered'] || in_array($d['cleanup_state'],array('PENDING','PARTIAL','FAILED'),true))) { Check::fail('INVALID_COVERAGE','coverage','Cannot claim complete cleanup with exclusions/pending/failure'); }
    }
}
final class NextAction extends Record {
    protected static function validate(array $d): void {
        Check::fields($d,array('kind','payload')); Check::set($d['kind'],D\NextActionKind::class,'kind'); $p=$d['payload'];
        switch ($d['kind']) {
            case 'OPEN_ASSESSMENT': Check::fields($p,array('assessment_execution_id','cursor')); Check::id($p['assessment_execution_id'],'assessment_execution_id'); StudyExecution::cursor($p['cursor']); if ($p['cursor']['assessment_execution_id']!==$p['assessment_execution_id']) { Check::fail('CURSOR_MISMATCH','cursor','Cursor belongs to target execution'); } break;
            case 'WAIT': Check::fields($p,array('until','target')); Check::timestamp($p['until'],'until'); if ($p['target']!==null) { Shape::target($p['target']); } if ($p['until']===null&&$p['target']===null) { Check::fail('EMPTY_WAIT','payload','Time or resolvable target required'); } break;
            case 'REQUIRE_ACCESS_LINK': Check::fields($p,array('public_message_code')); Check::text($p['public_message_code'],'public_message_code'); break;
            case 'FINISHED': Check::fields($p,array('outcome')); Check::text($p['outcome'],'outcome'); break;
            case 'WITHDRAWN': Check::fields($p,array('mode','withdrawal_id')); Check::set($p['mode'],D\WithdrawalMode::class,'mode'); Check::id($p['withdrawal_id'],'withdrawal_id'); break;
            case 'TERMINATED': Check::fields($p,array('reason','decision_id')); Check::text($p['reason'],'reason'); Check::id($p['decision_id'],'decision_id'); break;
            case 'OPERATIONAL_ERROR': Check::fields($p,array('code','retryable')); Check::text($p['code'],'code'); Check::boolean($p['retryable'],'retryable'); break;
        }
    }
}
