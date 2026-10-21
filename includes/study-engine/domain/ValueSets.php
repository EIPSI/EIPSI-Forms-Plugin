<?php
/** Pure PHP 7.4 value sets. Not loaded by the legacy plugin bootstrap. */
namespace EIPSI\StudyEngine\Domain;

abstract class ValueSet {
    final public static function values(): array {
        return array_values((new \ReflectionClass(static::class))->getConstants());
    }
    final public static function contains($value): bool {
        return is_string($value) && in_array($value, static::values(), true);
    }
}

final class BlockScope extends ValueSet {
    public const MACRO = 'MACRO';
    public const MICRO = 'MICRO';
}

final class NodeKind extends ValueSet {
    public const BLOCK = 'BLOCK';
    public const QUESTION = 'QUESTION';
    public const CONTENT = 'CONTENT';
    public const DIRECTIVE = 'DIRECTIVE';
}

final class ChildrenStrategy extends ValueSet {
    public const SEQUENTIAL = 'SEQUENTIAL';
    public const RANDOM_ORDER = 'RANDOM_ORDER';
    public const RANDOM_SELECT_ONE = 'RANDOM_SELECT_ONE';
    public const RANDOM_SELECT_N = 'RANDOM_SELECT_N';
    public const WEIGHTED_RANDOM = 'WEIGHTED_RANDOM';
    public const PARALLEL_AVAILABLE = 'PARALLEL_AVAILABLE';
}

final class StudyVersionState extends ValueSet {
    public const DRAFT = 'DRAFT';
    public const PUBLISHED = 'PUBLISHED';
    public const RETIRED = 'RETIRED';
}

final class StudyExecutionState extends ValueSet {
    public const CREATED = 'CREATED';
    public const ACTIVE = 'ACTIVE';
    public const WAITING = 'WAITING';
    public const COMPLETED = 'COMPLETED';
    public const WITHDRAWN = 'WITHDRAWN';
    public const TERMINATED_BY_RULE = 'TERMINATED_BY_RULE';
}

final class BlockExecutionState extends ValueSet {
    public const BLOCKED = 'BLOCKED';
    public const SCHEDULED = 'SCHEDULED';
    public const AVAILABLE = 'AVAILABLE';
    public const IN_PROGRESS = 'IN_PROGRESS';
    public const COMPLETED = 'COMPLETED';
    public const EXPIRED = 'EXPIRED';
    public const SKIPPED_BY_RULE = 'SKIPPED_BY_RULE';
    public const NOT_SCHEDULED = 'NOT_SCHEDULED';
    public const WITHDRAWN = 'WITHDRAWN';
    public const TERMINATED_BY_RULE = 'TERMINATED_BY_RULE';
}

final class ConditionTrigger extends ValueSet {
    public const IMMEDIATE = 'IMMEDIATE';
    public const ON_ASSESSMENT_COMPLETE = 'ON_ASSESSMENT_COMPLETE';
}

final class TransitionAction extends ValueSet {
    public const CONTINUE = 'CONTINUE';
    public const JUMP = 'JUMP';
    public const WAIT = 'WAIT';
    public const TERMINATE = 'TERMINATE';
}

final class TimezonePolicy extends ValueSet {
    public const PARTICIPANT_LOCAL = 'PARTICIPANT_LOCAL';
    public const STUDY_TIMEZONE = 'STUDY_TIMEZONE';
}

final class Anchor extends ValueSet {
    public const EXECUTION_CREATED = 'EXECUTION_CREATED';
    public const FIRST_ACCESS = 'FIRST_ACCESS';
    public const BLOCK_AVAILABLE = 'BLOCK_AVAILABLE';
    public const BLOCK_STARTED = 'BLOCK_STARTED';
    public const BLOCK_COMPLETED = 'BLOCK_COMPLETED';
    public const SPECIFIC_BLOCK_COMPLETED = 'SPECIFIC_BLOCK_COMPLETED';
    public const ABSOLUTE_DATETIME = 'ABSOLUTE_DATETIME';
    public const PARTICIPANT_VERIFIED = 'PARTICIPANT_VERIFIED';
}

final class TemporalRuleKind extends ValueSet {
    public const IMMEDIATE = 'IMMEDIATE';
    public const OFFSET = 'OFFSET';
    public const LOCAL_DAY_TIME = 'LOCAL_DAY_TIME';
    public const ABSOLUTE = 'ABSOLUTE';
    public const RECURRENCE = 'RECURRENCE';
}

final class OccurrenceSelectorKind extends ValueSet {
    public const SINGLE = 'SINGLE';
    public const SAME_PARENT_OCCURRENCE = 'SAME_PARENT_OCCURRENCE';
    public const EXACT_OCCURRENCE = 'EXACT_OCCURRENCE';
    public const FIRST_COMPLETED = 'FIRST_COMPLETED';
    public const LAST_COMPLETED_BEFORE = 'LAST_COMPLETED_BEFORE';
}

final class PredicateOperator extends ValueSet {
    public const AND = 'AND';
    public const OR = 'OR';
    public const NOT = 'NOT';
    public const EQ = 'EQ';
    public const NEQ = 'NEQ';
    public const GT = 'GT';
    public const GTE = 'GTE';
    public const LT = 'LT';
    public const LTE = 'LTE';
    public const EXISTS = 'EXISTS';
    public const IS_MISSING = 'IS_MISSING';
}

final class TruthValue extends ValueSet {
    public const TRUE = 'TRUE';
    public const FALSE = 'FALSE';
    public const UNKNOWN = 'UNKNOWN';
}

final class TargetKind extends ValueSet {
    public const QUESTION = 'QUESTION';
    public const CONTENT_POSITION = 'CONTENT_POSITION';
    public const MICRO_ENTRY = 'MICRO_ENTRY';
    public const ASSESSMENT_ENTRY = 'ASSESSMENT_ENTRY';
}

final class RandomScope extends ValueSet {
    public const EXECUTION = 'EXECUTION';
    public const BLOCK_INSTANCE = 'BLOCK_INSTANCE';
}

final class CompletionBoundary extends ValueSet {
    public const NONE = 'NONE';
    public const GROUP = 'GROUP';
    public const ASSESSMENT = 'ASSESSMENT';
}

final class DirectiveKind extends ValueSet {
    public const PAGE_BREAK = 'PAGE_BREAK';
    public const JUMP = 'JUMP';
    public const WAIT = 'WAIT';
    public const TERMINATE = 'TERMINATE';
}

final class NextActionKind extends ValueSet {
    public const OPEN_ASSESSMENT = 'OPEN_ASSESSMENT';
    public const WAIT = 'WAIT';
    public const REQUIRE_ACCESS_LINK = 'REQUIRE_ACCESS_LINK';
    public const FINISHED = 'FINISHED';
    public const WITHDRAWN = 'WITHDRAWN';
    public const TERMINATED = 'TERMINATED';
    public const OPERATIONAL_ERROR = 'OPERATIONAL_ERROR';
}

final class WithdrawalMode extends ValueSet {
    public const RETAIN_FOR_ANALYSIS = 'RETAIN_FOR_ANALYSIS';
    public const DO_NOT_USE = 'DO_NOT_USE';
}

final class CleanupState extends ValueSet {
    public const NOT_REQUIRED = 'NOT_REQUIRED';
    public const PENDING = 'PENDING';
    public const COMPLETE_LOCAL = 'COMPLETE_LOCAL';
    public const PARTIAL = 'PARTIAL';
    public const FAILED = 'FAILED';
}

final class DurationKind extends ValueSet {
    public const ELAPSED = 'ELAPSED';
    public const CALENDAR = 'CALENDAR';
}

final class QuestionType extends ValueSet {
    public const INTEGER = 'INTEGER';
    public const NUMBER = 'NUMBER';
    public const BOOLEAN = 'BOOLEAN';
    public const CHOICE = 'CHOICE';
    public const TEXT = 'TEXT';
}

final class ContentType extends ValueSet {
    public const TEXT = 'TEXT';
    public const MEDIA = 'MEDIA';
}

final class ReferenceKind extends ValueSet {
    public const QUESTION = 'QUESTION';
    public const BLOCK_STATUS = 'BLOCK_STATUS';
    public const BLOCK_COMPLETED = 'BLOCK_COMPLETED';
}

final class SubjectKind extends ValueSet {
    public const PARTICIPANT = 'PARTICIPANT';
    public const ANONYMOUS = 'ANONYMOUS';
}

final class RecurrenceKind extends ValueSet {
    public const DAILY_LOCAL = 'DAILY_LOCAL';
    public const WEEKLY_LOCAL = 'WEEKLY_LOCAL';
    public const ELAPSED = 'ELAPSED';
}

final class WindowKind extends ValueSet {
    public const NO_DEADLINE = 'NO_DEADLINE';
    public const DURATION = 'DURATION';
    public const ABSOLUTE = 'ABSOLUTE';
    public const EVENT = 'EVENT';
}

final class RandomOrder extends ValueSet {
    public const DEFINITION = 'DEFINITION';
    public const DRAW = 'DRAW';
}

final class TypedValueKind extends ValueSet {
    public const STRING = 'STRING';
    public const INTEGER = 'INTEGER';
    public const NUMBER = 'NUMBER';
    public const BOOLEAN = 'BOOLEAN';
    public const NULL = 'NULL';
}
