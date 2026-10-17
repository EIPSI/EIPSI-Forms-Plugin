# Study Engine P1-D: persistencia y Execution Owner

API interna PHP7.4+, import explícito `includes/study-engine/persistence.php`.
El bootstrap público solo reconoce el schema; no requiere esta API, registra
endpoints, UI, cron, auth ni email nuevos. Coexiste con Waves/Forms legacy sin
convertir datos. Usa íntegramente el compiler/runtime P1-C.

## API y ownership

```php
require_once 'includes/study-engine/persistence.php';
use EIPSI\StudyEngine\Persistence\Database;
use EIPSI\StudyEngine\Persistence\SeedVault;
use EIPSI\StudyEngine\Persistence\StudyVersionRepository;
use EIPSI\StudyEngine\Orchestration\ExecutionOwner;

$db = new Database($wpdb);
// Dedicated 32-byte key from server configuration/secret manager, never HTTP/DB.
$vault = new SeedVault(['ENGINE_KEY_1' => $serverKeyBytes], 'ENGINE_KEY_1');
$versions = new StudyVersionRepository($db);
$versionId = $versions->publish($validatedDefinition);
$owner = new ExecutionOwner($db, $vault);
$created = $owner->create($versionId, ['kind' => 'ANONYMOUS', 'id' => $identityRef],
    $internalRequestKey, ['timezone' => 'America/Argentina/Buenos_Aires']);
$view = $owner->view($created['execution_id']);
$result = $owner->dispatch($created['execution_id'], [
    'id' => $commandId, 'kind' => 'ANSWER', 'expected_revision' => $view['revision'],
    'data' => ['question_id' => 'Q_AGE', 'value' => 42],
]);
```

Caller is a trusted future orchestration adapter. This API does **not authenticate**
subjects; do not expose directly via HTTP. `created_at`, temporal commands and
limits are internal server context. No seed, execution ID, access flags or declared
random decisions are accepted by create. IDs SE_/SV_ use server random_bytes(16).
`subject` is a future scoped identity reference, never an email/fingerprint.
Existing Study can become administrative parent through an explicit adapter;
there is no automatic FK or enrollment into survey_studies.

One owner runs all transitions. Repositories are internal transaction primitives,
not an alternative domain engine. VersionRepository owns draft/publish/retire.
Separate repositories own execution, block, event, random, command, withdrawal,
intent and submission linkage. No generic setState endpoint or flag exists.

## Schema and constraints

Migration **v11** adds nine `{$wpdb->prefix}eipsi_engine_*` tables via existing
SchemaRegistry/Installer/Inspector/Repair. All require InnoDB. Registry/Inspector
recognize engine drift; Repair reports incompatible engine/types and only adds
safe missing columns/indexes, without silently converting populated structures.
Creation order includes all 35 local tables; the 26 legacy structures retain their
contracts. No new structures sync to external Storage.

| Table suffix | Authority / uniqueness |
|---|---|
| study_versions | Physical SV_ id; `(study_id,version_number)` unique; logical definition_id separate. Canonical protocol/graph LONGTEXT and hashes, compiler P1C_V1, lifecycle timestamps/audit metadata. |
| study_executions | Opaque SE_; version FK logical/pinned physical SV_; participant or anonymous reference; state/time/zone/revisions; checkpoint; encrypted seed; unique create_key/request hash/result. |
| block_executions | P1-C BE_ id; execution/definition/occurrence SHA256 unique, full canonical key retained and verified; parent, times/state/revisions/provenance and core snapshot. |
| execution_events | Primary `(execution_id,sequence)`; immutable domain snapshot + server/domain time and command envelope. |
| random_decisions | Unique `(execution,scope,scope_owner,owner,segment)`; scope_owner execution ID or block ID (never nullable uniqueness); ordered candidates/selection, weights, algorithm and seed_ref. |
| execution_commands | Primary `(execution,command_id)`; request hash, type, expected/committed revision, minimized result and timestamps. No request body/answers. |
| withdrawal_records | One decision/execution; subject refs, mode/data policy/time/source/cleanup/coverage; future privacy contract. |
| notification_intents | Unique `(execution,logical_key)`; trigger, target, revision, purpose/channel, state, capability placeholder null. |
| submission_journal | Stable SJ_; unique `(execution,block)`; version/occurrence, final response hash, local confirmation and optional replication status/destination/confirmation/attempts. |

IDs/indexed hashes use ASCII binary column collation: `Command_A` differs from
`COMMAND_A`. SHA256 occurrence indexing bounds SQL width; comparing retained JSON
and the execution checkpoint detects mismatched identity/corruption. No dependence
on timezone, available_at or SQL auto_increment. In the theoretical hash collision,
unique insertion fails closed instead of merging two occurrences.

Definition IDs are scoped to the protocol: all ten original fixtures use VERSION_1
in different Studies. Persistence allocates SV_ while preserving definition IDs,
bytes/hashes and P1-C random scope. Repositories find by physical ID or study/version;
create takes the physical published ID returned by publish. Block/journal relational
columns reference SV_, while immutable engine DTOs retain logical version_id.

Indexes cover study/version/state/subject, execution block/state/parent/occurrence,
event sequence/command, decision scope, command idempotency, outbox state/trigger/
revision and journal replication/updated time. No JSON indexes/per-question tables.

## Immutability, hashes and checkpoints

Draft can replace its scientific payload under a version row lock. Publish validates
and compiles once; published/retired cannot edit or republish different content.
Retirement records lifecycle metadata and stops new admissions, retaining original
protocol/graph JSON and hashes. Existing pinned executions continue.

Load verifies canonical JSON, protocol hash, graph hash, compiler contract, definition
binding and graph node contents. It constructs CompiledStudy from the stored graph,
**never recompiles** to reinterpret an old protocol. Missing/corrupt/incompatible
version/state/seed yields typed OPERATIONAL_ERROR; no automatic scientific repair.
Hashes detect corruption, not malicious DB administrators rewriting all hashes.

Simulator checkpoint/restore is additive and pure. It preserves private selectors,
instance insertion order, responses/coverage, cursor/pages, barrier, clock/zone,
limits, templates, offered/requested occurrences and skip intents. Restore bypasses
constructor, refresh and PRNG. Command cache is omitted; DB owns idempotency. Seed
is never in checkpoint. Blocks/decisions/events load in three batched queries;
checkpoint stores the remaining state and an integral hash of all portions. Indexed
projections are checked against the authoritative core snapshots.

Scientific answers occur **once**, in the execution checkpoint response section.
Events/commands/journal/outbox do not duplicate values or consent content. Domain
source labels SIMULATOR and simulation coverage stay as P1-C provenance; durable
withdrawal record has ADMIN_REQUEST / DURABLE_FOUNDATION with explicit exclusions.
These labels are not claims of production auth or privacy cleanup.

## Transaction, CAS, locks and retry

Database uses wpdb's mysqli connection/prepare but executes queries without wpdb's
automatic reconnect/retry: a lost connection cannot commit later statements in a
new autocommit connection. Errors expose generic codes, without SQL/answer logs.
Every transaction verifies nine tables exist and use InnoDB before starting.
Tested SQL environment: MariaDB11.8.9/WP7.1.2, PHP8.3.35; no universal certification
of other DB/server combinations. MariaDB `@@session.in_transaction` detects external
transactions. Nested transactions and implicit-commit DDL are rejected; repository
writes join the active owner transaction and cannot commit it.

Dispatch order: START → execution FOR UPDATE → existing command/hash or new claim →
hydrate pinned runtime → same Simulator dispatch → final response journal → changed
blocks (stable ID order) → immutable random decisions → appended events → withdrawal
→ invalidate/enqueue outbox → SQL CAS execution `WHERE id AND revision=expected` →
command result → COMMIT. All local scientific responses and metadata commit together.
No SMTP/external SQL/HTTP happens under the lock. View also uses a short consistent
execution row lock while assembling its batch snapshot, without advancing time.

Create locks the version first, serializing admission against retirement; inserts
one execution, eager occurrences, initial choices/events and encrypted seed in one
commit. Unique request key additionally protects cross-version retries. Repeating
create returns the original result, even after progress. Same key with changed
request conflicts; reenrollment needs a different explicit key.

Every mutating command requires expected_revision, increments exactly once, and
checks revision both in the engine and SQL. Read projections retain revision;
SQL no-op updates are accepted only if locked revision/checkpoint still match.
Same command+same full canonical request hash (including intents/destination) returns
the stored historical result before stale CAS checks; changed payload conflicts.
Cached NextAction is an idempotency receipt, never current authority: future adapters
must call view for the current projection before offering content. No MAX+1
sequence: the locked execution checkpoint/last event assigns the append range,
SQL primary key prevents duplicates. Terminal history is never reopened.

Transient errno1205/1213 retries the whole transaction at most **three attempts**,
with 20/40ms backoff. Other errors do not auto-retry. Connection loss/uncertain commit
returns operational error; caller retries the **same command ID** and observes
whether it committed. Failure-injection observer defaults to no-op; subclasses live
only in tests, with after_command_claim, after_random_decision, before_event_commit,
before_outbox_commit and before_commit. No public options/debug flags exist.

## Storage, outbox and privacy boundaries

Final assessment transition requires a local confirmed journal row and final
responses in the same durable checkpoint commit. A failed journal/block/event/outbox/
CAS write rolls back completion and all responses from that command. Partials remain
available in the checkpoint until final validated submit; no fabricated completion.
Existing Storage is not rewritten or activated for these executions. A future local
Storage adapter may join the SAME connection if its participating tables are
InnoDB and it has no implicit commits; other connections cannot share this boundary.

External destination is an optional internal logical reference, never a DB credential.
Local confirmation is authoritative for current P1-D completion. Journal marks
replication PENDING, stable submission ID, failure FAILED_RETRYABLE, eventual confirmed
external reference, bounded orchestration-controlled attempts. No network worker is
implemented. Reconciliation must use these IDs and read local payload by execution/
block/version; if a later product contract requires external confirmation before
completion, that adapter must withhold the submit command until confirmation. This
foundation does not claim distributed atomicity/external exactly-once.

Intents are explicitly supplied to a mutating internal command; no producer invents
notification schedules. Logical key includes target/purpose/channel/revision; repeated
payload is idempotent, altered payload conflicts. States:
PENDING, CLAIMED, SENT, CANCELLED, FAILED_RETRYABLE, FAILED_FINAL. No worker/SMTP.
Target closure, withdrawal/termination/execution closure or changed target schedule
revision cancels unsent intents atomically. Frozen active targets retain their own
revision. SENT cannot be unsent. Future P1-F worker must revalidate eligibility,
barrier, auth purpose, subject state and schedule revision before delivery; current
schema alone does not certify delivery semantics.

Withdrawal foundation persists RETAIN_FOR_ANALYSIS/NOT_REQUIRED or DO_NOT_USE/PENDING,
preserves completed historical blocks, closes future ones and cancels unsent intents.
No UI/B1/B2 handler, revocation, privacy cleanup/export is connected. Execution subject
refs and relational IDs locate future deletion scope. No indiscriminate SQL cascade
or FK into legacy identities: owner validates bindings, Inspector repairs structures,
future import/repair must reject orphans. P1-G must anonymize subject references,
delete responses and response-derived checkpoint portions, recompute authorized
checkpoint/tombstone hashes, remove journal links where policy requires, retain
minimized nonidentifying audit/version records and report external coverage. Event
append-only applies to normal runtime; explicit privacy maintenance is a separate
future owner. Direct destructive SQL is not an application API.

## Verification and limits

30 pure checkpoint/crypto tests (PHP7.4.33/8.3.35); 85 real DB integration tests;
M8's original77 preserved, with updated schema/latest-version expectations. New clean
structures are counted once in P1-D, not once again in M8. Existing127 P1-B +157 P1-C
remain pure. FULL **1440 unique: 1372 PHP +68 JS**; cross-version/reruns/RCT strict and
harness do not add functional cases.

Integration covers all ten original protocol/graph snapshots and complete flows,
WAIT/fallback, B1/B2 model states, partial/cursor recovery, hash/contract corruption,
all fault points, SQL constraints/CAS, outbox, local/external journal foundation,
bounded transient errors, actual killed DB connection, clean/v10 upgrade/repair and
six real two-process races. Ordered barriers hold the execution lock in process0
while process1 actually attempts a command; both submit-vs-withdraw orders tested.
No auth/capabilities/SMTP/privacy/export/Builder/public shell or P1-E activation.

Indicative local measurements (not benchmark): EMA35 has36 blocks; load+NextAction
uses9 queries regardless of event count, ~5ms. Create ~30–40ms/122 queries including
publish/idempotency checks; submit ~19ms/33 queries including helper reload; lazy
expand ~11ms/39 queries. Writes scale by changed blocks/events, not per Question.
Protocol/graph bytes: simple2857/3496, EMA2484/2758, RCT8702/10832; LONGTEXT sufficient
for these finite fixtures. Checkpoint response storage and whole-event batch reads
are bounded by P1-C caps; long executions may need pagination/compaction in a separately
validated contract. No universal institutional/performance certification.
