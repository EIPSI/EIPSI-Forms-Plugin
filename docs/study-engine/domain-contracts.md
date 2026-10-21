# Study Engine: contratos P1-B

Contratos aislados PHP **7.4+**, namespace `EIPSI\StudyEngine`. Diseño normativo:
[P1-A](../../../../informes/EIPSI-Forms/2026.10.07%20-%20P1-A%20Study%20Engine%20Domain%20Design.md).
Esta capa no se importa desde el plugin productivo. No contiene hooks, SQL,
repositories, resolver temporal, evaluator, random generator ni materializador.

## Uso y representación

```php
require_once 'includes/study-engine/autoload.php';
use EIPSI\StudyEngine\Contracts\StudyVersionDefinition;
$data = json_decode(file_get_contents('tests/study-engine/fixtures/valid/01-cross-sectional.json'), true);
$definition = new StudyVersionDefinition($data);
$normalized = $definition->normalize();
$hash = $definition->protocolHash();
```

Registros JSON-only inmutables por copia, constructores validantes y `toArray()`/
`jsonSerialize()`. Se rechazan campos desconocidos; extensión científica exige
versionar el contrato. `metadata`/`protocol_metadata` admiten JSON, no objetos PHP.
`ContractViolation::issues()` expone lista determinista de `code/path/message`.
No basta validar para publicar: las validaciones completas del compiler son P1-C.

`StudyVersionDefinition`: `contract_version=P1B_V1`, `study={logical_id,public_id}`,
`id`, `version_number`, `state`, `timezone={policy,default}`, `root_block_ids`,
`nodes`, `condition_rules`, `compiler_contract_version=P1C_V1`,
`protocol_metadata`, `closure`. `nodes` es un registro plano de un árbol:
`parent_id` y `position` conservan estructura/orden; `root_block_ids` identifica
los Macro directamente bajo version ID. No se duplica una segunda children list.
Los diez fixtures son ejemplos completos del formato, sin DB IDs.

## Jerarquía y contenido

```text
StudyVersion → Macro
Macro → Macro | Micro(ASSESSMENT)
Micro(ASSESSMENT/GROUP) → Micro(GROUP) | Question | Content | Directive
```

`BlockDefinition`: `kind=BLOCK`, `id/logical_id/version_id/parent_id`, `scope`,
`position`, `children_strategy`, `randomization`, `schedule`,
`completion_boundary`, `metadata`. Un mismo registro para ambos scopes.
Macros tienen frontera NONE; el primer Micro bajo Macro tiene ASSESSMENT;
Micros descendientes tienen GROUP y schedule null (heredan occurrence).
Assessment no es otra entidad; completion dispara una vez en la frontera.

`QuestionDefinition`: `kind=QUESTION`, IDs/version/parent/position,
`response_key`, `type`, `options=[{id,label}]`, `validation`, `required`,
`semantic_role`, `content={content_id,version,snapshot,sha256}`. Tipos iniciales:
INTEGER, NUMBER, BOOLEAN, CHOICE, TEXT. Son tipos de contrato, no adapters Gutenberg
ya implementados. Required se aplica cuando la pregunta es elegible; skip no crea
respuesta false/null. Role INFORMED_CONSENT no ejecuta conducta automáticamente.

`ContentNode`: `kind=CONTENT`, IDs/version/parent/position, TEXT/MEDIA, `content`
y `durable_reference` (null para texto; `{uri,sha256}` para media).
`FlowDirective`: `kind=DIRECTIVE`, IDs/version/parent/position, `directive_kind`,
`target/resume_target/on_target_unavailable`. PAGE_BREAK tiene esos tres campos null:
no value, response, BlockExecution ni Screen. Los otros kinds son JUMP/WAIT/TERMINATE.
Content SHA256 corresponde al snapshot exacto UTF-8, sin transformarlo.

IDs: strings lógicos ASCII `^[A-Za-z][A-Za-z0-9_.:-]{0,127}$`. No email, label o
entero autoincremental como identidad. Positions son enteros >=0 únicos por parent.
Question response keys únicas dentro de versión; instancia añade execution y
assessment occurrence. Todos los nodos/rules pertenecen a la misma versión.

## Value sets canónicos

En `domain/ValueSets.php` cada clase tiene constantes, `values()` y `contains()`;
no enums nativos ni cambio de versión mínima PHP.

| Clase | Valores |
|---|---|
| BlockScope | MACRO, MICRO |
| NodeKind | BLOCK, QUESTION, CONTENT, DIRECTIVE |
| ChildrenStrategy | SEQUENTIAL, RANDOM_ORDER, RANDOM_SELECT_ONE, RANDOM_SELECT_N, WEIGHTED_RANDOM, PARALLEL_AVAILABLE |
| StudyVersionState | DRAFT, PUBLISHED, RETIRED |
| StudyExecutionState | CREATED, ACTIVE, WAITING, COMPLETED, WITHDRAWN, TERMINATED_BY_RULE |
| BlockExecutionState | BLOCKED, SCHEDULED, AVAILABLE, IN_PROGRESS, COMPLETED, EXPIRED, SKIPPED_BY_RULE, NOT_SCHEDULED, WITHDRAWN, TERMINATED_BY_RULE |
| ConditionTrigger | IMMEDIATE, ON_ASSESSMENT_COMPLETE |
| TransitionAction | CONTINUE, JUMP, WAIT, TERMINATE |
| TimezonePolicy | PARTICIPANT_LOCAL, STUDY_TIMEZONE |
| Anchor | EXECUTION_CREATED, FIRST_ACCESS, BLOCK_AVAILABLE, BLOCK_STARTED, BLOCK_COMPLETED, SPECIFIC_BLOCK_COMPLETED, ABSOLUTE_DATETIME, PARTICIPANT_VERIFIED |
| TemporalRuleKind | IMMEDIATE, OFFSET, LOCAL_DAY_TIME, ABSOLUTE, RECURRENCE |
| OccurrenceSelectorKind | SINGLE, SAME_PARENT_OCCURRENCE, EXACT_OCCURRENCE, FIRST_COMPLETED, LAST_COMPLETED_BEFORE |
| PredicateOperator | AND, OR, NOT, EQ, NEQ, GT, GTE, LT, LTE, EXISTS, IS_MISSING |
| TruthValue | TRUE, FALSE, UNKNOWN |
| TargetKind | QUESTION, CONTENT_POSITION, MICRO_ENTRY, ASSESSMENT_ENTRY |
| RandomScope | EXECUTION, BLOCK_INSTANCE |
| CompletionBoundary | NONE, GROUP, ASSESSMENT |
| DirectiveKind | PAGE_BREAK, JUMP, WAIT, TERMINATE |
| NextActionKind | OPEN_ASSESSMENT, WAIT, REQUIRE_ACCESS_LINK, FINISHED, WITHDRAWN, TERMINATED, OPERATIONAL_ERROR |
| WithdrawalMode | RETAIN_FOR_ANALYSIS, DO_NOT_USE |
| CleanupState | NOT_REQUIRED, PENDING, COMPLETE_LOCAL, PARTIAL, FAILED |
| DurationKind | ELAPSED, CALENDAR |
| QuestionType | INTEGER, NUMBER, BOOLEAN, CHOICE, TEXT |
| ContentType | TEXT, MEDIA |
| ReferenceKind | QUESTION, BLOCK_STATUS, BLOCK_COMPLETED |
| SubjectKind | PARTICIPANT, ANONYMOUS |
| RecurrenceKind | DAILY_LOCAL, WEEKLY_LOCAL, ELAPSED |
| WindowKind | NO_DEADLINE, DURATION, ABSOLUTE, EVENT |
| RandomOrder | DEFINITION, DRAW |
| TypedValueKind | STRING, INTEGER, NUMBER, BOOLEAN, NULL |

## Schedule y occurrences

`ScheduleSpec`: `anchor`, `anchor_ref`, `occurrence_selector`, `temporal_rule`,
`timezone_policy`, `window`, `recurrence`, `dst_policy`. Anchors de bloque requieren
ref y selector; anchors globales usan null/null. No BASELINE_COMPLETE.

| temporal_rule | Payload |
|---|---|
| IMMEDIATE | `{kind}` |
| OFFSET | `{kind,duration}` |
| LOCAL_DAY_TIME | `{kind,day_index,time}`; day 1 = fecha local del anchor |
| ABSOLUTE | `{kind,at}`; anchor ABSOLUTE_DATETIME |
| RECURRENCE | `{kind}`; la regla está en `schedule.recurrence` |

Duration: `{kind:ELAPSED,seconds}` o `{kind:CALENDAR,amount,unit:DAY|WEEK|MONTH}`.
Offset permite cero; duración de ventana/recurrence debe ser positiva.
Absolute: `{basis:UTC,instant:YYYY-MM-DDTHH:MM:SSZ}` o
`{basis:LOCAL,local_datetime:YYYY-MM-DDTHH:MM:SS,timezone:IANA}`.
Los UTC timestamps de DTO usan precisión segundos y sufijo Z; no se resuelven
horarios locales ni DST aquí.

Recurrence DAILY_LOCAL: `{kind,days,times:[HH:MM…],start_day}` finitos; WEEKLY_LOCAL:
`{kind,weekdays:[MON…],time,inclusive_start,count?/until?}`; ELAPSED:
`{kind,interval:elapsed_duration,count?/until?}`. Al menos count/until no-null para
las últimas dos. Los slots de times y weekdays conservan orden publicado; no se
interpreta un array desordenado como una instrucción de sort automática.
Window: NO_DEADLINE; DURATION+duration; ABSOLUTE+at; EVENT+anchor/ref/selector.
Deadline se interpreta con ventana `[available_at,due_at)` en P1-C.

DST default: `{gap:SHIFT_FORWARD_BY_GAP,fold:EARLIER_INSTANT}`. Otras políticas
requieren una extensión versionada; P1-B no calcula ni ajusta tiempos.
Closure: `{kind:ALL_SELECTED_OPPORTUNITIES_TERMINAL}` o
`{kind:OVERALL_DURATION,duration}` desde execution creation.

Selector `{kind}` para SINGLE/SAME_PARENT_OCCURRENCE/FIRST_COMPLETED;
EXACT_OCCURRENCE añade `occurrence_key`; LAST_COMPLETED_BEFORE añade
`source_event_id`. SINGLE hacia definición recurrente o descendiente es ambiguo.
P1-C validará viabilidad de dependencias/eventos y selectores contextuales.

OccurrenceKey es una lista ordenada de segmentos `{block_id,slot}`. Slot es
`"single"`, `{day:1}`, `{day:1,slot:3}` o `{ordinal:1}`. Anidación conserva la
lista de ancestors; nunca timezone, timestamps o schedule revision en la key.
Para recurrencias DAILY_LOCAL los ejemplos cuentan `day` desde fecha local del
anchor (incluido start_day), y `slot` desde 1. Construir/serializar key no materializa.

## AST, rules y targets

Boolean AST: `{op:AND|OR,args:[predicate,…]}` mínimo dos; NOT+arg.
Comparación `{op:EQ|NEQ|GT|GTE|LT|LTE,left:operand,right:operand}`.
EXISTS/IS_MISSING+reference. Operand es `{kind:REFERENCE,reference}` o
`{kind:VALUE,type:TypedValueKind,value}`. Reference es
`{kind:QUESTION|BLOCK_STATUS|BLOCK_COMPLETED,node_id,occurrence_selector}`.
NEQ conserva tipos/UNKNOWN, no usa comparación PHP coercitiva. Ausente no equivale
a false; el evaluator trivaluado y compatibilidad de tipos efectiva serán P1-C.
Depth AST máximo 64; máximo canonical JSON depth 256.

ConditionRule: ID/version, trigger, source `{kind:QUESTION|BLOCK,node_id,occurrence_selector}`,
order, predicate, action, target, resume_target, on_target_unavailable, metadata.
IMMEDIATE: primera TRUE y válida por menor order. Orders duplicados por source
son error. Completion: mismo snapshot para todas y
`TERMINATE > WAIT > JUMP > CONTINUE`; igual tipo por order y luego ID estable.
Constantes del contrato expresan semántica; no hay evaluator/dispatched actions.

Target `{kind,node_id,occurrence_selector}`. QUESTION usa Question; CONTENT_POSITION
usa posición de Question/Content/Directive; MICRO_ENTRY usa Micro;
ASSESSMENT_ENTRY usa Micro frontera. JUMP no acepta Macro. WAIT exige
ASSESSMENT_ENTRY, optional typed resume descendiente y fallback
CONTINUE_AFTER_TARGET. CONTINUE/TERMINATE usan target/resume/fallback null.
Forward-only/acción disponible/WAIT imposible son validación compiler/runtime P1-C.

## Randomization y decisiones

Config embebida en Block: `{strategy,scope,candidates,select_n,draw_count,weights,
order,algorithm_version:EIPSI_RANDOM_V1,segments?}`. Fields no aplicables son
null/[] explícitos. N para RANDOM_SELECT_N; draw_count y weights solo WEIGHTED_RANDOM.
Pesos enteros >=0, al menos un positivo, total <=2147483647; cero excluye.
RENDER no existe en el set admitido. Scopes EXECUTION y BLOCK_INSTANCE congelan
la elección; reload no sortea. Algoritmo de P1-A se implementará en P1-C.

Sin directivas, hay un segmento lógico whole-owner. Con directivas se requieren
segments explícitos `[{id,candidates}]`, en orden de posición, que particionen los
hijos compatibles entre barreras sin cruzarlas. N/draw_count aplica por segmento.
Segment IDs son parte de la identity de RandomDecision, no IDs de assessment.
Question/Sub-Micro se mueven como piezas/unidades; una directiva no es candidate.

RandomDecision captura ID, study_execution_id, block_execution_id (null para
EXECUTION), strategy_id, scope, eligible_candidates, selection, seed_ref,
algorithm_version, decided_at y segment_id opcional. Selection guarda orden,
no solamente conjunto; seed_ref no revela seed secreta. No se implementa PRNG.

## Executions, NextAction y withdrawal

StudyExecution: id/study/version, subject `{kind,id}`, timezone IANA,
created_at/started_at/first_access_at, state, revision, wait_context, cursor.
Cursor `{assessment_execution_id,node_id}`. Wait context captura source event,
rule/source execution/time y target/resume/fallback tipados.
BlockExecution: id/study_execution/version/definition/parent_execution,
occurrence_key, state, available_at/due_at/started_at/completed_at, revision,
provenance, random_decision_id. Rule terminal requiere provenance
`{decision_id,rule_id,source_execution_id,source_node_id,decided_at,reason}`.
No repositorio, FK SQL ni transición de estados ejecutada. `missing` es
categoría analítica, nunca un enum que reemplace expired/skipped.

NextAction `{kind,payload}` tiene siete payloads exactos:

| kind | payload |
|---|---|
| OPEN_ASSESSMENT | assessment_execution_id, cursor |
| WAIT | until nullable, target nullable; al menos uno resoluble |
| REQUIRE_ACCESS_LINK | public_message_code; sin account_exists/PII |
| FINISHED | outcome |
| WITHDRAWN | mode, withdrawal_id |
| TERMINATED | reason, decision_id |
| OPERATIONAL_ERROR | code, retryable boolean |

WithdrawalRecord: id, study_execution_id, subject, mode, decided_at,
source SESSION/EMAIL_CAPABILITY/ADMIN_REQUEST, data_policy, cleanup_state,
coverage `{source,complete,not_covered}`. Mode/data_policy coinciden. Retención
requiere NOT_REQUIRED; DO_NOT_USE exige otro cleanup state. Complete no se puede
alegar con exclusiones ni PENDING/PARTIAL/FAILED. No invoca B1/B2 ni privacy.

## Normalización/hash

Map keys ordenadas lexicalmente, lists conservan orden semántico. Registry nodes y
rules se ordenan por ID; root IDs se ordenan (position conserva traversal).
`normalize()` incluye lifecycle state. `protocolHash()` SHA256 del JSON canónico
sin state: retirar versión no cambia protocolo. Incluye IDs/version, contenido,
schedules, condiciones y metadata publicada. No es hash universal de protocolos
científicamente equivalentes ni firma de autenticación. UTF-8 exacto; objetos,
resources y valores no finitos se rechazan. Empty map/list se representa `[]`
en este contrato PHP-array V1; no pasar objetos `{}` PHP para forzar otra forma.
Cambiar esa convención/numeric encoding exige contract version nueva.

## Fixtures, traces y tests

Runner: `php tests/run-study-engine-domain.php` sin WP, DB, Docker ni SMTP
necesarios si hay PHP 7.4+ en host. El gate proporciona PHP aislado en Docker si no
lo hay. 127 tests nuevos; stage `pure-php-run-study-engine-domain` en FULL/FAST/PHP.
Los 1041 anteriores se preservan: FULL suma 1168 únicos (1100 PHP+68 JS).

Fixtures `tests/study-engine/fixtures/valid/`: cross-sectional, consent+branch,
pre-post, diario7, EMA35, RCT A/B, RCT internal random, WAIT E1→E4→Micro,
Macros anidados, withdrawal follow-up. `fixtures/invalid/` incluye 21 negativos,
con `definition` completa y `expected_error` exacto.

`traces/` usa TRACE_V1: fixture_id, scenarios `{id,inputs,steps}`;
inputs fija timezone/created_at, y steps `event={kind,data},expected=[assertions]`.
Cada scenario empieza por inicialización de inputs; un EXECUTION_CREATED explícito
hace assertions de ese mismo inicio, no crea otra execution. CLOCK_ADVANCED cambia
reloj; otros eventos usan at explícito o current clock. ASSESSMENT_COMPLETED puede
aportar responses para un submit de fixture sin pasos de teclado redundantes.
Assertions son estados, ventanas, counts, coverage, cursor, random selection,
barrier, frozen states, stable history, data policy y cancelación. ExpectedTrace
valida shape y referencias, no ejecución. Las estructuras exactas están en su clase.

Las selecciones aleatorias de P1-A son **decisiones declaradas**, no seed vectors
calculados. Inputs lo marcan `DECLARED_DECISIONS_NOT_VERIFIED_SEED_VECTOR` y aportan
`declared_decisions`. P1-C usará esos oráculos para traversal con decisiones fijadas
y agregará vectores criptográficos separados para EIPSI_RANDOM_V1. No ajustar un
algoritmo para hacer que una seed arbitraria produzca el arm deseado.

## Límites explícitos para P1-C

Validation cubre schema, ID/parent/posición/ciclos, jerarquía, assessment boundary,
refs/tipos obvios/ambigüedad recurrente, AST shape, random config/segment y timezone.
No verifica alcanzabilidad general, forward graph, ciclos temporales indirectos,
conflictos semánticos, DST ni next-action resolution. Validate != compile/publish.
Acceso/email/session, notifications, Storage, Pages, migrations, consent y
withdrawal productivos permanecen fuera de esta capa.
