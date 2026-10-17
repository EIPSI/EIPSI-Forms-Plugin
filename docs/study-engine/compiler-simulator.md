# Study Engine P1-C: compiler y simulator puros

Capa opt-in PHP 7.4+, compatible con los contratos [P1-B](domain-contracts.md).
`includes/study-engine/engine.php` requiere los owners explícitamente; el bootstrap
WordPress no lo carga. No hay IO de producto, DB, hooks, HTTP, emails ni navegador.

## API reusable

```php
require_once 'includes/study-engine/engine.php';
use EIPSI\StudyEngine\Engine\DefinitionCompiler;
use EIPSI\StudyEngine\Engine\Simulator;

$compiled = (new DefinitionCompiler())->compile($definitionArray);
$sim = new Simulator($compiled, [
    'execution_id' => 'SIMULATION_1',
    'created_at' => '2026-10-12T11:00:00Z',
    'timezone' => 'America/Argentina/Buenos_Aires',
    'seed_hex' => '000102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f',
]);
$result = $sim->dispatch([
    'id' => 'OPEN_1',
    'kind' => 'OPEN_ASSESSMENT',
    'expected_revision' => 0,
    'data' => ['node_id' => 'E_SIMPLE'],
]);
$next = $sim->nextAction();
$snapshot = $sim->snapshot();
$trace = $sim->trace();
```

El contexto inyecta instante/zona/seed; `SimulationClock` ofrece set absoluto
monotónico y advance en segundos/minutos/horas/días sin reloj global. La API no
depende de los adapters CLI/TraceOracle. El mismo input produce los mismos IDs y
trace. `nextAction()` es lectura pura; dispatch avanza el estado explícitamente.

## Pipeline y graph

Compiler vuelve a construir StudyVersionDefinition para validar todo P1-B,
normaliza registries, ordena children por position/ID y genera ownership de
assessment, descendants, traversal y edges containment/traversal/temporal/
condition/JUMP/WAIT/recurrence. Los segmentos random conservan las barreras.
CompiledStudy guarda arrays privados y devuelve copias. Protocol hash P1-B y
graph SHA256 canónicos son independientes de insertion order.

Comprobaciones semánticas decidibles: ciclos temporales/recurrence, dependencia
de completar un descendiente que todavía necesita su contenedor, WAIT que omite
su anchor, JUMP backward, targets vacíos/incompatibles/no garantizados por random,
ambigüedad de orden aleatorio, paths/slots EXACT inválidos, recurrence anidada sin
selector de parent, assessment sin contenido respondible/presentable, due absoluto
<= disponibilidad, ausencia de assessments terminales y dependencia sequential
de un sibling posterior. Un required después de TERMINATE incondicional es ERROR.
Warnings incluyen required omitibles por branch, references a candidates
opcionales, consent anotado sin regla y overlap completion demostrable.

No es una prueba general de satisfacibilidad de todos los AST/responses o tiempos
dependientes de eventos. Casos no decidibles quedan protegidos por guards runtime;
UNKNOWN no se interpreta como respuesta ni como error de infraestructura oculto.
Warnings no agregan una UI de publicación en esta fase.

## Tiempo, DST y materialización

Los ocho anchors P1-B usan eventos explícitos. GROUP progresa por respuestas
requeridas elegibles, sin disparar completion de assessment. Los cinco selectors
se resuelven contra occurrences: SINGLE exige una; SAME_PARENT conserva el path;
EXACT valida ancestors/slots; FIRST_COMPLETED fija la primera; LAST_COMPLETED_BEFORE
usa `EV_<sequence>` y orden de evento para empates de UTC, y no vuelve a elegir.
Si el source event aún no existe, el anchor queda BLOCKED.

Elapsed suma segundos UTC. Calendar DAY/WEEK/MONTH conserva wall time IANA;
MONTH ajusta al último día válido del mes destino. LOCAL_DAY_TIME cuenta día 1
desde la fecha local del anchor. Absolutos UTC o LOCAL+IANA son explícitos.
Ventanas `[available,due)`: igualdad al inicio habilita; igualdad al deadline
expira. NO_DEADLINE no inventa un límite. EVENT deadline se vuelve a resolver al
ocurrir el evento, incluso en AVAILABLE/IN_PROGRESS; conflictos no abren ventanas
inválidas y rechazan el comando, conservando el snapshot previo.

Wall time se resuelve enumerando offsets IANA alrededor de la intención local.
Fold elige el menor UTC (EARLIER_INSTANT). Gap desplaza exactamente por el salto
(SHIFT_FORWARD_BY_GAP), conserva intención y registra effective_local/adjustment.
Berlin 2026-03-29 02:30 -> 01:30Z/03:30 local; 2026-10-25 02:30 -> 00:30Z.
Cada slot lógico genera una occurrence, incluso en gap/fold.

DAILY_LOCAL, WEEKLY_LOCAL y ELAPSED generan keys por path de slots, sin duplicar
definitions ni introducir timezone/revision en la identity. Default eager hasta
256 slots por template; mayores usan horizonte de 30 días. `max_instances=10000`,
`eager_limit` y `horizon_days` son configurables en context. Una plantilla pendiente
impide concluir prematuramente. El generator anuncia fin/horizonte explícitamente;
cap de expansión/trabajo causa error, nunca truncamiento científico silencioso.
EXACT targets pueden materializar solo el path requerido fuera del horizonte.
WAIT guarda skip intents de prefijos/definitions; omisiones completas materializan
tombstones bounded por cap, y prefijos recurrentes conservan la occurrence target
y posteriores. No se inventa una completion para un anchor nunca alcanzado.

Cambio de IANA primero procesa el reloj antiguo. AVAILABLE/IN_PROGRESS/terminal
y occurrences ofrecidas quedan congeladas; solo BLOCKED/SCHEDULED futuros cambian.
STUDY_TIMEZONE mantiene el default publicado. Futures que ya vencen en la nueva
zona reciben EXPIRED/TZ_WINDOW_ELAPSED. Se preservan keys, respuestas y decisiones.

## Predicados y transición

AST validado, AND/OR/NOT Kleene, EXISTS/IS_MISSING explícitos, comparaciones de
tipos estrictos; no coerción PHP ni eval. Errores de tipo/dependencia se propagan.
Immediate usa primera TRUE+acción válida por order/ID, audita target inválido y
continúa. Completion evalúa el mismo snapshot y registra matches, ganadora y
suprimidas: TERMINATE > WAIT > JUMP > CONTINUE, luego order/ID.

JUMP solo forward efectivo y compatible/accionable. Omisiones tienen coverage y
provenance; no responses null ficticias. Source abierto saltado hacia otra
assessment queda SKIPPED_BY_RULE conservando partials, sin completion rules.
WAIT bloquea oferta intermedia, conserva target/resume/fallback y omite prefijo;
al estar disponible revalida y entra al Micro/Question definido. Si venció,
CONTINUE_AFTER_TARGET resuelve la barrier y nunca reabre. TERMINATE preserva
terminales anteriores, cierra pendientes por regla y no crea WithdrawalRecord.
PageBreak es proyección de pages/page_cursor, sin BlockExecution ni respuesta.

## EIPSI_RANDOM_V1 exacto

Seed: 32 bytes expresados como 64 dígitos hex. Scope tiene exactamente seis campos:
execution_id, version_id, owner_id, segment_id, scope, occurrence_key. Para
EXECUTION occurrence_key es null; para BLOCK_INSTANCE es el path completo del owner.
IDs ASCII P1-B; JSON canónico UTF-8, mapas ordenados y arrays conservados, sin
espacios, sin escapes de slash/unicode y con floats preservados (scope usa ints).

Mensaje: ASCII `EIPSI_RANDOM_V1` + `00` byte NUL + longitud JSON uint32 big-endian
+ JSON + contador uint32 big-endian desde 0. Cada bloque es HMAC-SHA256 binario
con la seed como key; se concatenan los bloques sin reiniciar al leer por chunks.
Un word es uint32 big-endian. Para rango n, limit=floor(2^32/n)*n; rechazar word
>=limit, devolver word%n. n debe estar entre 1 y 2147483647. Counter nunca wrappea.
La implementación exige enteros PHP de 64 bits explícitamente, no aproximaciones.

Pool inicial compilado por position/ID. Fisher–Yates descendente i=last..1,
swap con integer(i+1); RANDOM_SELECT_ONE/N toma prefijo sin reemplazo. Weighted
acumula pesos enteros, integer(total) y el primer acumulado >pick; retira elegido y
repite. Cero excluye. DRAW conserva sorteo; DEFINITION reordena seleccionados según
pool, salvo RANDOM_ORDER, que siempre conserva la permutación. SEQUENTIAL/PARALLEL
no consumen bytes. Segments tienen streams distintos por ID y no cruzan directivas.

EXECUTION guarda una elección por owner/segment; BLOCK_INSTANCE una por occurrence.
Refresh/replay no sortea. No seleccionados son NOT_SCHEDULED/RANDOM_NOT_SELECTED.
PARALLEL permite disponibles simultáneos; next elige available_at/position/ID.
Decisiones declaradas de P1-B usan provider separado y etiqueta explícita; nunca
se presentan como outputs del PRNG. Siete vectores oficiales y un vector entero
con cinco rechazos se verifican con `reference_vectors.py`, sin importar PHP.

## Comandos, trace y errores

Dispatch requiere id/kind; data y expected_revision opcionales. IDs repetidos con
mismo payload devuelven el mismo resultado; payload distinto ->
IDEMPOTENCY_KEY_REUSED; otra ID con revision vieja -> REVISION_CONFLICT. No se
duplican eventos/submits/random. Rechazo restaura memoria/reloj/selectors/context.

| Comando | Data principal |
|---|---|
| CREATE_EXECUTION | {}: proyecta la ejecución ya construida, no crea otra |
| FIRST_ACCESS / VERIFY_PARTICIPANT | at opcional; fija primer evento |
| OPEN_ASSESSMENT | node_id+slot o block_execution_id |
| ANSWER | question_id, value, slot/block_execution_id opcional |
| SUBMIT_ASSESSMENT | target, responses opcionales (validación real) |
| ADVANCE_TIME | at o seconds |
| CHANGE_TIMEZONE | timezone, at opcional |
| WITHDRAW_SIMULATION | mode, at opcional |
| RELOAD / CAPABILITY_OPENED | lectura; GET no retira ni avanza reloj |
| NEXT_PAGE | avance de page_cursor, sin entidad Screen |

Trace incluye sequence, simulated_at, type, source, execution/block/rule/decision,
old/new state, reason y payload operacional sin copiar valores respondidos ni seed.
COMMAND_ACCEPTED conserva ID/revision. `outcome()` cuenta completed/expired/skipped/
not_scheduled/withdrawn/terminated; FINISHED tiene outcome string según P1-B.

NextAction implementa las siete variantes del contrato. Context access_valid false
induce REQUIRE_ACCESS_LINK y rechaza respuestas; operational_error permite simular
dependencia no disponible. Estos flags no implementan sesiones/auth reales.
DO_NOT_USE registra policy inmediata y cleanup PENDING simulado; RETAIN no limpia.
Retiro posterior a cierre registra policy sin reescribir el motivo terminal.

## Ejecución de pruebas y límites

`php tests/run-study-engine-simulator.php`: 157 tests nuevos; 21 negativos P1-B se
revalidan aparte. `--fixture=08`, `--trace`, `--snapshot`; mismatch devuelve nonzero.
FULL vigente: 1325 tests únicos (1257 PHP + 68 JS). Cross-version compara snapshots
enteros, hashes, keys, siete vectores, 16 traces declarativos y diez recorridos
completos mediante los dos digests fijados; no cuenta repeticiones como tests nuevos.

Engine en memoria: no certifica SQL/atomicidad/concurrencia real, WordPress,
sesiones/capabilities, SMTP/delivery, privacy cleanup, exports, UI, migrations legacy
ni deploy institucional. CAS/idempotency son modelos de comportamiento. Production
durability, transaction boundaries, outbox y adapters requieren revisión en P1-D,
que no se inicia aquí. No es un benchmark formal ni prueba universal de protocolos.
