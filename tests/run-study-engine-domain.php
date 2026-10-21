<?php
/** Pure P1-B runner: php tests/run-study-engine-domain.php. No WP bootstrap. */
$importOutput = '';
ob_start();
require_once dirname(__DIR__) . '/includes/study-engine/autoload.php';
$importOutput = ob_get_clean();

use EIPSI\StudyEngine\Contracts as C;
use EIPSI\StudyEngine\Domain as D;
use EIPSI\StudyEngine\Validation\DefinitionValidator;

$tests = array();
function domain_test(string $name, callable $test): void { global $tests; $tests[$name] = $test; }
function domain_assert(bool $value, string $message = 'Contract assertion failed'): void { if (!$value) { throw new RuntimeException($message); } }
function domain_reject(callable $operation, string $code): void {
    try { $operation(); } catch (C\ContractViolation $e) {
        domain_assert(in_array($code,array_column($e->issues(),'code'),true),'Expected '.$code.'; got '.$e->getMessage()); return;
    }
    throw new RuntimeException('Expected rejection '.$code);
}
function domain_json(string $file): array { return json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR); }
function domain_node(array $definition,string $id): array { foreach ($definition['nodes'] as $node) { if ($node['id']===$id) { return $node; } } throw new RuntimeException('Node missing '.$id); }
$fixturePath = __DIR__ . '/study-engine/fixtures';
$fixtures = array();
foreach (glob($fixturePath.'/valid/*.json') as $file) {
    $id = basename($file,'.json'); $fixtures[$id] = domain_json($file);
    domain_test('Fixture valid: '.$id,static function()use($file){new C\StudyVersionDefinition(domain_json($file));});
    domain_test('Trace typed/referenced: '.$id,static function()use($file,$id){
        C\ExpectedTrace::validate(domain_json(__DIR__.'/study-engine/traces/'.$id.'.json'),new C\StudyVersionDefinition(domain_json($file)));
    });
}
foreach (glob($fixturePath.'/invalid/*.json') as $file) {
    domain_test('Fixture invalid: '.basename($file,'.json'),static function()use($file){
        $f = domain_json($file); $v = new DefinitionValidator();
        $a = $v->validate($f['definition']); $b = $v->validate($f['definition']);
        domain_assert($a === $b,'Nondeterministic diagnostics');
        domain_reject(static function()use($f){new C\StudyVersionDefinition($f['definition']);},$f['expected_error']);
    });
}
$sets = array(
    D\BlockScope::class => array('MACRO','MICRO'),
    D\ChildrenStrategy::class => array('SEQUENTIAL','RANDOM_ORDER','RANDOM_SELECT_ONE','RANDOM_SELECT_N','WEIGHTED_RANDOM','PARALLEL_AVAILABLE'),
    D\StudyVersionState::class => array('DRAFT','PUBLISHED','RETIRED'),
    D\StudyExecutionState::class => array('CREATED','ACTIVE','WAITING','COMPLETED','WITHDRAWN','TERMINATED_BY_RULE'),
    D\BlockExecutionState::class => array('BLOCKED','SCHEDULED','AVAILABLE','IN_PROGRESS','COMPLETED','EXPIRED','SKIPPED_BY_RULE','NOT_SCHEDULED','WITHDRAWN','TERMINATED_BY_RULE'),
    D\ConditionTrigger::class => array('IMMEDIATE','ON_ASSESSMENT_COMPLETE'),
    D\TransitionAction::class => array('CONTINUE','JUMP','WAIT','TERMINATE'),
    D\TimezonePolicy::class => array('PARTICIPANT_LOCAL','STUDY_TIMEZONE'),
    D\Anchor::class => array('EXECUTION_CREATED','FIRST_ACCESS','BLOCK_AVAILABLE','BLOCK_STARTED','BLOCK_COMPLETED','SPECIFIC_BLOCK_COMPLETED','ABSOLUTE_DATETIME','PARTICIPANT_VERIFIED'),
    D\TemporalRuleKind::class => array('IMMEDIATE','OFFSET','LOCAL_DAY_TIME','ABSOLUTE','RECURRENCE'),
    D\OccurrenceSelectorKind::class => array('SINGLE','SAME_PARENT_OCCURRENCE','EXACT_OCCURRENCE','FIRST_COMPLETED','LAST_COMPLETED_BEFORE'),
    D\TruthValue::class => array('TRUE','FALSE','UNKNOWN'),
    D\RandomScope::class => array('EXECUTION','BLOCK_INSTANCE'),
    D\NextActionKind::class => array('OPEN_ASSESSMENT','WAIT','REQUIRE_ACCESS_LINK','FINISHED','WITHDRAWN','TERMINATED','OPERATIONAL_ERROR'),
    D\WithdrawalMode::class => array('RETAIN_FOR_ANALYSIS','DO_NOT_USE'),
    D\TargetKind::class => array('QUESTION','CONTENT_POSITION','MICRO_ENTRY','ASSESSMENT_ENTRY'),
    D\PredicateOperator::class => array('AND','OR','NOT','EQ','NEQ','GT','GTE','LT','LTE','EXISTS','IS_MISSING'),
    D\CompletionBoundary::class => array('NONE','GROUP','ASSESSMENT'),
    D\DirectiveKind::class => array('PAGE_BREAK','JUMP','WAIT','TERMINATE'),
);
foreach ($sets as $class=>$expected) {
    domain_test('Value set: '.$class,static function()use($class,$expected){domain_assert($class::values()===$expected);domain_assert(!$class::contains('bogus')&&!$class::contains(0));});
}
$simple = $fixtures['01-cross-sectional'];
$consent = $fixtures['02-consent-branching'];
$wait = $fixtures['08-wait-resume-micro'];
$ema = $fixtures['05-ema-thirty-five'];
$single = array('kind'=>'SINGLE');
$occurrence = array(array('block_id'=>'M_ROOT','slot'=>'single'),array('block_id'=>'E_EMA','slot'=>array('day'=>1,'slot'=>3)));
$timestamp = '2026-10-12T11:00:00Z';

domain_test('Import without WP/DB emits no output',static function()use($importOutput){domain_assert($importOutput==='');domain_assert(!defined('ABSPATH')&&!function_exists('add_action')&&!isset($GLOBALS['wpdb']));});
domain_test('Ten complete definitions and ten oracles',static function()use($fixtures){domain_assert(count($fixtures)===10&&count(glob(__DIR__.'/study-engine/traces/*.json'))===10);});
domain_test('Registry and map insertion order do not affect protocol hash',static function()use($simple){
    $d=$simple;$d['nodes']=array_reverse($d['nodes']);$d=array_reverse($d,true);
    foreach($d['nodes'] as &$n){$n=array_reverse($n,true);}unset($n);
    $a=new C\StudyVersionDefinition($simple);$b=new C\StudyVersionDefinition($d);
    domain_assert($a->normalize()===$b->normalize()&&$a->protocolHash()===$b->protocolHash());
});
domain_test('Sibling position is scientific order and changes hash',static function()use($simple){$d=$simple;$d['nodes'][2]['position']=1;$d['nodes'][3]['position']=0;domain_assert((new C\StudyVersionDefinition($d))->protocolHash()!==(new C\StudyVersionDefinition($simple))->protocolHash());});
domain_test('Question snapshot alteration changes protocol hash',static function()use($simple){$d=$simple;$d['nodes'][2]['content']['snapshot']='Nueva pregunta';$d['nodes'][2]['content']['sha256']=hash('sha256','Nueva pregunta');domain_assert((new C\StudyVersionDefinition($d))->protocolHash()!==(new C\StudyVersionDefinition($simple))->protocolHash());});
domain_test('Lifecycle retirement preserves scientific protocol hash',static function()use($simple){$d=$simple;$d['state']='RETIRED';domain_assert((new C\StudyVersionDefinition($d))->protocolHash()===(new C\StudyVersionDefinition($simple))->protocolHash());});
domain_test('Immutable copies prevent published mutation through input/output',static function()use($simple){$d=$simple;$v=new C\StudyVersionDefinition($d);$before=$v->protocolHash();$d['nodes'][0]['id']='M_CHANGED';$out=$v->toArray();$out['nodes'][0]['id']='M_CHANGED';domain_assert($v->protocolHash()===$before);});
domain_test('JSON roundtrip has stable normalized protocol',static function()use($ema){$a=new C\StudyVersionDefinition($ema);$b=new C\StudyVersionDefinition(json_decode(C\Canonical::json($a->normalize()),true,512,JSON_THROW_ON_ERROR));domain_assert($a->protocolHash()===$b->protocolHash());});
domain_test('Rule registry order irrelevant; explicit order matters',static function()use($consent){$a=new C\StudyVersionDefinition($consent);$d=$consent;$d['condition_rules']=array_reverse($d['condition_rules']);domain_assert($a->protocolHash()===(new C\StudyVersionDefinition($d))->protocolHash());$d['condition_rules'][0]['order']=30;domain_assert($a->protocolHash()!==(new C\StudyVersionDefinition($d))->protocolHash());});
domain_test('Canonical rejects objects/resources/non-finite numbers',static function(){foreach(array(new stdClass(),INF,NAN)as$v){try{C\Canonical::json($v);throw new RuntimeException('Accepted non-JSON');}catch(InvalidArgumentException $e){}}});
domain_test('Hash is SHA256 and deterministic repeated construction',static function()use($simple){$a=new C\StudyVersionDefinition($simple);domain_assert(strlen($a->protocolHash())===64&&$a->protocolHash()===(new C\StudyVersionDefinition($simple))->protocolHash());});
domain_test('Boundary outer Micro is assessment; nested Micro stays group',static function()use($consent){domain_assert(domain_node($consent,'E_ENTRY')['completion_boundary']==='ASSESSMENT'&&domain_node($consent,'m_CONSENT')['completion_boundary']==='GROUP');});
domain_test('Completion source cannot be a nested Micro group',static function()use($consent,$single){$d=$consent;$r=$d['condition_rules'][0];$r['trigger']='ON_ASSESSMENT_COMPLETE';$r['source']=array('kind'=>'BLOCK','node_id'=>'m_CONSENT','occurrence_selector'=>$single);$d['condition_rules']=array($r);domain_reject(static function()use($d){new C\StudyVersionDefinition($d);},'INVALID_COMPLETION_SOURCE');});
domain_test('PageBreak forbids value/response/own execution',static function()use($simple){$p=domain_node($simple,'D_PAGE_1');foreach(array('value','response','execution_id')as$key){$d=$p;$d[$key]='fake';domain_reject(static function()use($d){new C\FlowDirective($d);},'UNKNOWN_FIELD');}});
domain_test('Content and questions coexist with stable positions',static function()use($consent){new C\ContentNode(domain_node($consent,'C_CONSENT'));new C\QuestionDefinition(domain_node($consent,'Q_CONSENT'));domain_assert(domain_node($consent,'C_CONSENT')['position']===0&&domain_node($consent,'Q_CONSENT')['position']===1);});
domain_test('Occurrence identity independent of JSON map order',static function()use($occurrence){$b=$occurrence;$b[1]['slot']=array('slot'=>3,'day'=>1);domain_assert((new D\OccurrenceKey($occurrence))->key()===(new D\OccurrenceKey($b))->key());});
domain_test('Occurrence slots/parent scopes distinguish identities',static function()use($occurrence){$b=$occurrence;$b[1]['slot']['slot']=4;$c=$occurrence;$c[0]['slot']=array('ordinal'=>2);domain_assert((new D\OccurrenceKey($occurrence))->key()!==(new D\OccurrenceKey($b))->key()&&(new D\OccurrenceKey($occurrence))->key()!==(new D\OccurrenceKey($c))->key());});
domain_test('Occurrence does not accept timezone/schedule revision identity',static function()use($occurrence){$d=$occurrence;$d[1]['slot']['timezone']='Asia/Tokyo';domain_reject(static function()use($d){new D\OccurrenceKey($d);},'UNKNOWN_FIELD');});
domain_test('Elapsed 24h and calendar day remain distinct contracts',static function(){C\Shape::duration(array('kind'=>'ELAPSED','seconds'=>86400));C\Shape::duration(array('kind'=>'CALENDAR','amount'=>1,'unit'=>'DAY'));domain_assert(C\Canonical::json(array('kind'=>'ELAPSED','seconds'=>86400))!==C\Canonical::json(array('kind'=>'CALENDAR','amount'=>1,'unit'=>'DAY')));});
$sch=domain_node($simple,'E_SIMPLE')['schedule'];
foreach(array('FIRST_ACCESS','PARTICIPANT_VERIFIED')as$anchor){domain_test('Optional global anchor '.$anchor,static function()use($sch,$anchor){$s=$sch;$s['anchor']=$anchor;$s['anchor_ref']=null;$s['occurrence_selector']=null;new C\ScheduleSpec($s);});}
domain_test('Day 3 09:00 remains a local-calendar rule',static function()use($sch){$s=$sch;$s['temporal_rule']=array('kind'=>'LOCAL_DAY_TIME','day_index'=>3,'time'=>'09:00');new C\ScheduleSpec($s);});
domain_test('Bounded six-hour elapsed recurrence',static function()use($sch){$s=$sch;$s['temporal_rule']=array('kind'=>'RECURRENCE');$s['recurrence']=array('kind'=>'ELAPSED','interval'=>array('kind'=>'ELAPSED','seconds'=>21600),'count'=>28);new C\ScheduleSpec($s);});
domain_test('Bounded Monday calendar recurrence',static function()use($sch){$s=$sch;$s['temporal_rule']=array('kind'=>'RECURRENCE');$s['recurrence']=array('kind'=>'WEEKLY_LOCAL','weekdays'=>array('MON'),'time'=>'08:00','inclusive_start'=>true,'count'=>4);new C\ScheduleSpec($s);});
domain_test('Absolute IANA local intent stays unresolved',static function()use($sch){$s=$sch;$s['anchor']='ABSOLUTE_DATETIME';$s['anchor_ref']=null;$s['occurrence_selector']=null;$s['temporal_rule']=array('kind'=>'ABSOLUTE','at'=>array('basis'=>'LOCAL','local_datetime'=>'2026-10-25T02:30:00','timezone'=>'Europe/Berlin'));new C\ScheduleSpec($s);});
domain_test('Optional event deadline has explicit anchor and selector',static function()use($sch,$single){$s=$sch;$s['window']=array('kind'=>'EVENT','anchor'=>'SPECIFIC_BLOCK_COMPLETED','anchor_ref'=>'E_OTHER','occurrence_selector'=>$single);new C\ScheduleSpec($s);});
domain_test('Invalid local clock fails rather than resolving',static function()use($sch){$s=$sch;$s['temporal_rule']=array('kind'=>'LOCAL_DAY_TIME','day_index'=>3,'time'=>'25:00');domain_reject(static function()use($s){new C\ScheduleSpec($s);},'INVALID_LOCAL_TIME');});
domain_test('SAME_PARENT/FIRST/LAST selectors preserve context',static function(){foreach(array(array('kind'=>'SAME_PARENT_OCCURRENCE'),array('kind'=>'FIRST_COMPLETED'),array('kind'=>'LAST_COMPLETED_BEFORE','source_event_id'=>'EVENT_1'))as$s){C\Shape::selector($s);}});
domain_test('EXACT selector needs complete occurrence key',static function()use($occurrence){C\Shape::selector(array('kind'=>'EXACT_OCCURRENCE','occurrence_key'=>$occurrence));domain_reject(static function(){C\Shape::selector(array('kind'=>'LAST_COMPLETED_BEFORE'));},'INVALID_ID');});
$predicate=$consent['condition_rules'][0]['predicate'];
domain_test('Nested AND/OR/NOT typed AST construction',static function()use($predicate){new C\Predicate(array('op'=>'OR','args'=>array(array('op'=>'AND','args'=>array($predicate,$predicate)),array('op'=>'AND','args'=>array($predicate,array('op'=>'NOT','arg'=>$predicate))))));});
domain_test('Absence represented by EXISTS/IS_MISSING; UNKNOWN is explicit',static function()use($predicate){$r=$predicate['left']['reference'];new C\Predicate(array('op'=>'EXISTS','reference'=>$r));new C\Predicate(array('op'=>'IS_MISSING','reference'=>$r));domain_assert(D\TruthValue::UNKNOWN!==D\TruthValue::FALSE);});
domain_test('Typed scalar false differs from missing/null',static function(){C\Shape::operand(array('kind'=>'VALUE','type'=>'BOOLEAN','value'=>false));C\Shape::operand(array('kind'=>'VALUE','type'=>'NULL','value'=>null));domain_reject(static function(){C\Shape::operand(array('kind'=>'VALUE','type'=>'BOOLEAN','value'=>null));},'INVALID_TYPED_VALUE');});
domain_test('AST rejects eval/callables and malformed arity',static function(){domain_reject(static function(){new C\Predicate(array('op'=>'EVAL','code'=>'phpinfo()'));},'UNKNOWN_FIELD');domain_reject(static function(){new C\Predicate(array('op'=>'AND','args'=>array()));},'INVALID_AST_ARITY');});
domain_test('Completion precedence separate from immediate first-match contract',static function(){domain_assert(C\ConditionRule::COMPLETION_PRECEDENCE===array('TERMINATE','WAIT','JUMP','CONTINUE'));domain_assert(C\ConditionRule::IMMEDIATE_RESOLUTION==='FIRST_TRUE_VALID_BY_ORDER');});
domain_test('WAIT targets assessment and internal typed resume',static function()use($wait){new C\ConditionRule($wait['condition_rules'][0]);domain_assert($wait['condition_rules'][0]['target']['kind']==='ASSESSMENT_ENTRY'&&$wait['condition_rules'][0]['resume_target']['kind']==='MICRO_ENTRY');});
domain_test('WAIT requires explicit expired-target fallback',static function()use($wait){$r=$wait['condition_rules'][0];$r['on_target_unavailable']=null;domain_reject(static function()use($r){new C\ConditionRule($r);},'INVALID_WAIT_FALLBACK');});
domain_test('WAIT resume cannot leave target assessment',static function()use($wait){$d=$wait;$d['condition_rules'][0]['resume_target']['node_id']='E_FOLLOWUP_2';domain_reject(static function()use($d){new C\StudyVersionDefinition($d);},'INVALID_WAIT_RESUME');});
domain_test('TERMINATE does not carry content target',static function()use($consent){$r=$consent['condition_rules'][0];$r['target']=$consent['condition_rules'][1]['target'];domain_reject(static function()use($r){new C\ConditionRule($r);},'UNEXPECTED_TARGET');});
domain_test('Experimental randomization is execution scope; no RENDER',static function()use($fixtures){$r=domain_node($fixtures['06-rct-arms'],'M_ARM')['randomization'];domain_assert($r['scope']==='EXECUTION');$r['scope']='RENDER';domain_reject(static function()use($r){new C\RandomizationDefinition($r);},'INVALID_ENUM');});
domain_test('Zero weights exclude candidates but cannot empty weighted pool',static function()use($fixtures){$r=domain_node($fixtures['06-rct-arms'],'M_ARM')['randomization'];$r['weights']['M_ARM_B']=0;new C\RandomizationDefinition($r);$r['weights']['M_ARM_A']=0;domain_reject(static function()use($r){new C\RandomizationDefinition($r);},'INVALID_WEIGHTS');});
domain_test('EMA is one definition with 35 logical slots, not copied blocks',static function()use($ema){$assessments=array_filter($ema['nodes'],static function($n){return $n['kind']==='BLOCK'&&$n['completion_boundary']==='ASSESSMENT';});$r=domain_node($ema,'E_EMA')['schedule']['recurrence'];domain_assert(count($assessments)===1&&$r['days']*count($r['times'])===35);});
$studyExecution=array('id'=>'EXEC_1','study_id'=>'STUDY_1','version_id'=>'VERSION_1','subject'=>array('kind'=>'ANONYMOUS','id'=>'ANON_1'),'timezone'=>'America/Argentina/Buenos_Aires','created_at'=>$timestamp,'started_at'=>null,'first_access_at'=>null,'state'=>'CREATED','revision'=>0,'wait_context'=>null,'cursor'=>null);
domain_test('Anonymous and participant execution DTOs have pinned version',static function()use($studyExecution){new C\StudyExecution($studyExecution);$s=$studyExecution;$s['subject']=array('kind'=>'PARTICIPANT','id'=>'PARTICIPANT_1');new C\StudyExecution($s);domain_assert($s['version_id']==='VERSION_1');});
domain_test('Execution context captures exact WAIT decision provenance',static function()use($studyExecution,$wait,$timestamp){$s=$studyExecution;$s['state']='WAITING';$s['wait_context']=array('source_event_id'=>'EVENT_1','rule_id'=>'R_WAIT_FOUR','source_execution_id'=>'BLOCK_1','created_at'=>$timestamp,'target'=>$wait['condition_rules'][0]['target'],'resume_target'=>$wait['condition_rules'][0]['resume_target'],'on_target_unavailable'=>'CONTINUE_AFTER_TARGET');new C\StudyExecution($s);});
$blockExecution=array('id'=>'BLOCK_1','study_execution_id'=>'EXEC_1','version_id'=>'VERSION_1','definition_id'=>'E_EMA','parent_execution_id'=>'MACRO_EXEC_1','occurrence_key'=>$occurrence,'state'=>'BLOCKED','available_at'=>null,'due_at'=>null,'started_at'=>null,'completed_at'=>null,'revision'=>0,'provenance'=>null,'random_decision_id'=>null);
domain_test('Blocked occurrence keeps unknown times null',static function()use($blockExecution){$e=new C\BlockExecution($blockExecution);domain_assert($e->toArray()['available_at']===null&&$e->toArray()['due_at']===null);});
domain_test('Skipped execution requires rule/source/time provenance',static function()use($blockExecution,$timestamp){$s=$blockExecution;$s['state']='SKIPPED_BY_RULE';domain_reject(static function()use($s){new C\BlockExecution($s);},'MISSING_RULE_PROVENANCE');$s['provenance']=array('decision_id'=>'DECISION_1','rule_id'=>'RULE_1','source_execution_id'=>'BLOCK_0','source_node_id'=>'E_FIRST','decided_at'=>$timestamp,'reason'=>'WAIT_OMISSION');new C\BlockExecution($s);});
domain_test('Block positive deadline and completion timestamp enforced',static function()use($blockExecution,$timestamp){$s=$blockExecution;$s['available_at']=$timestamp;$s['due_at']=$timestamp;domain_reject(static function()use($s){new C\BlockExecution($s);},'INVALID_WINDOW');$s=$blockExecution;$s['state']='COMPLETED';domain_reject(static function()use($s){new C\BlockExecution($s);},'MISSING_COMPLETION_TIME');});
$randomDecision=array('id'=>'RANDOM_1','study_execution_id'=>'EXEC_1','block_execution_id'=>null,'strategy_id'=>'M_ARM','scope'=>'EXECUTION','eligible_candidates'=>array('M_ARM_A','M_ARM_B'),'selection'=>array('M_ARM_A'),'seed_ref'=>'SEED_1','algorithm_version'=>'EIPSI_RANDOM_V1','decided_at'=>$timestamp);
domain_test('RandomDecision stores eligible snapshot and persistent choice',static function()use($randomDecision){$r=new C\RandomDecision($randomDecision);domain_assert($r->toArray()['selection']===array('M_ARM_A'));$d=$randomDecision;$d['selection']=array('M_ARM_C');domain_reject(static function()use($d){new C\RandomDecision($d);},'INVALID_RANDOM_DECISION');});
domain_test('Block random decision requires instance key',static function()use($randomDecision){$d=$randomDecision;$d['scope']='BLOCK_INSTANCE';domain_reject(static function()use($d){new C\RandomDecision($d);},'INVALID_ID');$d['block_execution_id']='BLOCK_1';new C\RandomDecision($d);});
$nexts=array(
'OPEN_ASSESSMENT'=>array('assessment_execution_id'=>'BLOCK_1','cursor'=>array('assessment_execution_id'=>'BLOCK_1','node_id'=>'Q_AGE')),
'WAIT'=>array('until'=>$timestamp,'target'=>null), 'REQUIRE_ACCESS_LINK'=>array('public_message_code'=>'CHECK_EMAIL'), 'FINISHED'=>array('outcome'=>'INCOMPLETE'),
'WITHDRAWN'=>array('mode'=>'RETAIN_FOR_ANALYSIS','withdrawal_id'=>'WITHDRAWAL_1'), 'TERMINATED'=>array('reason'=>'SCREEN_FAILED','decision_id'=>'DECISION_1'), 'OPERATIONAL_ERROR'=>array('code'=>'STORAGE_PENDING','retryable'=>true));
foreach($nexts as $kind=>$payload){domain_test('NextAction typed payload '.$kind,static function()use($kind,$payload){new C\NextAction(array('kind'=>$kind,'payload'=>$payload));});}
domain_test('NextAction payload cannot silently change variant',static function()use($nexts){domain_reject(static function()use($nexts){new C\NextAction(array('kind'=>'FINISHED','payload'=>$nexts['OPERATIONAL_ERROR']));},'MISSING_FIELD');});
$withdrawal=array('id'=>'WITHDRAWAL_1','study_execution_id'=>'EXEC_1','subject'=>array('kind'=>'PARTICIPANT','id'=>'PARTICIPANT_1'),'mode'=>'RETAIN_FOR_ANALYSIS','decided_at'=>$timestamp,'source'=>'EMAIL_CAPABILITY','data_policy'=>'RETAIN_FOR_ANALYSIS','cleanup_state'=>'NOT_REQUIRED','coverage'=>array('source'=>'wordpress_db','complete'=>false,'not_covered'=>array('external_db')));
domain_test('B1 withdrawal retains data without cleanup request',static function()use($withdrawal){new C\WithdrawalRecord($withdrawal);domain_assert(D\StudyExecutionState::WITHDRAWN!==D\StudyExecutionState::TERMINATED_BY_RULE);});
domain_test('B2 withdraw immediately excludes use with pending coverage',static function()use($withdrawal){$d=$withdrawal;$d['mode']=$d['data_policy']='DO_NOT_USE';$d['cleanup_state']='PENDING';new C\WithdrawalRecord($d);$d['coverage']['complete']=true;domain_reject(static function()use($d){new C\WithdrawalRecord($d);},'INVALID_COVERAGE');});
domain_test('Withdrawal mode/data policy cannot contradict',static function()use($withdrawal){$d=$withdrawal;$d['data_policy']='DO_NOT_USE';domain_reject(static function()use($d){new C\WithdrawalRecord($d);},'INVALID_WITHDRAWAL_POLICY');});
domain_test('Trace unknown node rejected, not blindly trusted oracle',static function()use($simple){$t=domain_json(__DIR__.'/study-engine/traces/01-cross-sectional.json');$t['scenarios'][0]['steps'][0]['expected'][0]['node_id']='E_MISSING';domain_reject(static function()use($t,$simple){C\ExpectedTrace::validate($t,new C\StudyVersionDefinition($simple));},'INVALID_TRACE_REFERENCE');});
domain_test('Trace seeds explicitly remain declared decisions pending P1-C',static function()use($fixtures){$t=domain_json(__DIR__.'/study-engine/traces/06-rct-arms.json');domain_assert($t['scenarios'][0]['inputs']['random_oracle']==='DECLARED_DECISIONS_NOT_VERIFIED_SEED_VECTOR');$t['scenarios'][0]['inputs']['random_oracle']='VERIFIED';domain_reject(static function()use($t,$fixtures){C\ExpectedTrace::validate($t,new C\StudyVersionDefinition($fixtures['06-rct-arms']));},'UNVERIFIED_RANDOM_ORACLE');});
domain_test('Bad content hash cannot enter published protocol',static function()use($simple){$q=domain_node($simple,'Q_AGE');$q['content']['snapshot']='changed';domain_reject(static function()use($q){new C\QuestionDefinition($q);},'CONTENT_HASH_MISMATCH');});
domain_test('Dates reject impossible calendar values, not just regex shape',static function(){domain_reject(static function(){C\Check::timestamp('2026-02-30T00:00:00Z','date',false);},'INVALID_TIMESTAMP');});

domain_test('Malformed node kind and root IDs produce diagnostics, never PHP type errors',static function()use($simple){$d=$simple;$d['nodes'][0]['kind']=array('bad');domain_reject(static function()use($d){new C\StudyVersionDefinition($d);},'INVALID_ENUM');$d=$simple;$d['root_block_ids']=array(array('bad'));domain_reject(static function()use($d){new C\StudyVersionDefinition($d);},'INVALID_ID');});
domain_test('Version ID cannot collide with node identity',static function()use($simple){$d=$simple;$d['nodes'][0]['id']=$d['id'];domain_reject(static function()use($d){new C\StudyVersionDefinition($d);},'DUPLICATE_ID');});
domain_test('Non-JSON metadata rejected as domain diagnostic',static function()use($simple){$d=$simple;$d['protocol_metadata']['unsafe']=new stdClass();domain_reject(static function()use($d){new C\StudyVersionDefinition($d);},'INVALID_JSON');});
domain_test('Random segments preserve PageBreak barriers in the common block',static function()use($simple){
    $d=$simple;foreach($d['nodes']as&$n){if($n['id']==='E_SIMPLE'){$n['children_strategy']='RANDOM_ORDER';$n['randomization']=array('strategy'=>'RANDOM_ORDER','scope'=>'BLOCK_INSTANCE','candidates'=>array('Q_AGE','Q_WELLBEING','Q_COMMENT'),'select_n'=>null,'draw_count'=>null,'weights'=>array(),'order'=>'DRAW','algorithm_version'=>'EIPSI_RANDOM_V1','segments'=>array(array('id'=>'SEGMENT_BEFORE','candidates'=>array('Q_AGE','Q_WELLBEING')),array('id'=>'SEGMENT_AFTER','candidates'=>array('Q_COMMENT'))));}}unset($n);new C\StudyVersionDefinition($d);
    foreach($d['nodes']as&$n){if($n['id']==='E_SIMPLE'){$n['randomization']['segments'][0]['candidates']=array('Q_AGE','Q_COMMENT');$n['randomization']['segments'][1]['candidates']=array('Q_WELLBEING');}}unset($n);domain_reject(static function()use($d){new C\StudyVersionDefinition($d);},'RANDOM_SEGMENT_CROSSES_DIRECTIVE');
});

$failed = 0;
set_error_handler(static function($severity,$message,$file,$line){throw new ErrorException($message,0,$severity,$file,$line);});
foreach ($tests as $name=>$test) {
    try { $test(); echo 'PASS '.$name."\n"; }
    catch (Throwable $e) { $failed++; echo 'FAIL '.$name.': '.$e->getMessage()."\n"; }
}
restore_error_handler();
echo count($tests).' tests, '.$failed." failures\n";
exit($failed ? 1 : 0);
