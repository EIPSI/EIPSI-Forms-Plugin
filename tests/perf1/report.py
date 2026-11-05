#!/usr/bin/env python3
import csv,json,gzip,statistics,sys
from pathlib import Path
O=Path(sys.argv[1]);R=O.parent
read=lambda name,base=O:json.loads((base/name).read_text())
rows=lambda name:list(csv.DictReader((O/name).open()))
med=statistics.median
fmt=lambda x:f'{float(x):.3f}'
def table(headers,data):return '| '+' | '.join(headers)+' |\n|'+'|'.join('---' for _ in headers)+'|\n'+'\n'.join('| '+' | '.join(str(c) for c in row)+' |' for row in data)
d=rows('distributions.csv');mi=rows('microbenchmarks.csv');cg={s:rows(s.lower()+'-callgraph.csv') for s in ['P3','P10']};qf=rows('query-families.csv');qg=rows('query-groups.csv');tx=rows('transactions.csv');ctx=rows('compiled-contexts.csv');candidates=rows('candidates.csv')
get=lambda s,v:next(x for x in d if x['scenario']==s and x['variant']==v)
def m(s,tag):return next(x for x in cg[s] if tag in x['method'])
def micro(n):return next(x for x in mi if x['operation']==n)
metrics=table(['Camino','Variante','N','Mediana ms','p75','p95 exploratorio'],[[r['scenario'],r['variant'],r['N'],fmt(r['median']),fmt(r['p75']),fmt(r['p95'])] for r in d])
methods=table(['Camino','Método','Llamadas','Total instrumentado ms','Self instrumentado ms','SQL inclusive'],[[s,tag,m(s,tag)['calls'],fmt(m(s,tag)['total_ms']),fmt(m(s,tag)['self_ms']),m(s,tag)['db_statements']] for s,tags in [('P10',['NativeBridge::complete','NativeBridge::continuation','ExecutionOwner::hydrate','StudyVersionRepository::compiled','Identity::schema','NotificationScheduler::synchronize','NotificationScheduler::enqueue','Store::ready']),('P3',['Runtime::enroll','Runtime::definition','ExecutionOwner::hydrate','StudyVersionRepository::compiled','Identity::schema','NotificationScheduler::synchronize','NotificationScheduler::enqueue'])] for tag in tags])
transactions=table(['Camino','TX','SQL','Locks FOR UPDATE','Escrituras','BEGIN','COMMIT'],[[r['scenario'],r['transaction'],r['statements'],r['lock_reads'],r['writes'],r['begin'],r['commit']] for r in tx])
groups=[]
for s in ['P10','P3']:
 for g in sorted([x for x in qg if x['scenario']==s],key=lambda x:float(x['count']),reverse=True)[:8]:groups.append([s,g['caller'].split('@')[0],g['table'],g['count'],fmt(g['db_ms']),g['reads'],g['writes']])
groups=table(['Camino','Caller directo','Tabla/grupo','SQL mediana','DB wall ms instrumentados','Reads','Writes'],groups)
contexttable=table(['Camino','compiled()','Filas completas distintas','Repositorios distintos','Repeticiones por objeto'],[[r['scenario'],r['calls'],r['distinct_full_row_contexts'],r['distinct_objects'],r['calls_per_object_histogram']] for r in ctx])
cryptotable=table(['Microbenchmark original','N','Mediana ms','p75','p95'],[[r['operation'],r['N'],fmt(r['median']),fmt(r['p75']),fmt(r['p95'])] for r in mi])
ctable=table(['ID','Clasificación','Ganancia esperable','Decisión'],[[r['id'],r['classification'],r['expected_gain'],r['implemented']] for r in candidates])
original_totals=[json.loads(x) for x in (O/'control-original/http-totals.jsonl').read_text().splitlines()]
orig_p3=[x for x in original_totals if x['scenario']=='P3-control' and x['repetition']!='warmup']
orig_p10=[x for x in read('results.json',O/'control-original') if x['scenario']=='P10' and x['repetition']!='warmup']
source_sql={s:med(x['pfs']['count'] for x in orig_p3) if s=='P3' else med(x['db_statements'] for x in orig_p10) for s in ['P3','P10']}
source_db={s:med(x['pfs']['ms'] for x in orig_p3) if s=='P3' else med(x['db_ms'] for x in orig_p10) for s in ['P3','P10']}
# Exclusive scalar attribution to readiness/metadata, never sum nested method totals.
schema={s:{'count':sum(float(x['count']) for x in qf if x['scenario']==s and 'information_schema' in x['table']),'ms':sum(float(x['db_ms']) for x in qf if x['scenario']==s and 'information_schema' in x['table'])} for s in ['P3','P10']}
checks=read('checks.json');cplain=read('checks.json',O/'control-original')
report=f'''# PERF-1 P10-P3 Server Path Decomposition

Fecha: 2026-11-05. **PERF-1 P10/P3 decomposition completed — safe server optimization evidence established.**

**Outcome B — no se implementa una optimización productiva.** Se identificó reconstrucción inmutable repetida, pero la modificación local más sencilla ahorra menos de 1 ms; el ahorro material exige un contexto compartido entre owners y una prueba adicional de su ciclo de vida. Las consultas de autorización, locks, journals y snapshots mutables no quedaron demostradas como prescindibles. No se fuerza Outcome A.

## A. Executive summary

P10 cuesta **{fmt(get('P10','original')['median'])} ms** en el owner original local, DB **{fmt(source_db['P10'])} ms**, **{source_sql['P10']:.0f} sentencias físicas**. P3 cuesta **{fmt(get('P3','original')['median'])} ms HTTP** local, PHP **{fmt(med(x['php_ms'] for x in orig_p3))} ms**, DB **{fmt(source_db['P3'])} ms**, **{source_sql['P3']:.0f} sentencias físicas**. Son fixtures, no producción ni SLA.

P10 tiene **7 transacciones**: finalizar A, completar su oportunidad en Study, revalidar el estado, resolver B, asignar Native B, persistir la continuación y construir su frame bajo autorización. P3 tiene **5 transacciones**: admitir participante/ejecución/sesión, releer estado, confirmar primer acceso, releer y crear la asignación Native que se entrega al participante.

La causa computacional más clara es `StudyVersionRepository::compiled()`: P10 **28 llamadas, 1 fila completa, 27 objetos de repositorio**; P3 **21 llamadas, 1 fila completa, 18 objetos**. La caché de un solo repositorio casi no serviría. La caché compartida entre owners puede investigarse con lifetime explícito y guard exacto, pero no se agregó una static persistente, Redis, transient ni caché de autorización.

La introspección también existe en hot path: aproximadamente **{schema['P10']['count']:.0f} consultas de metadata en P10** y **{schema['P3']['count']:.0f} en P3** en esta serie. Son comprobaciones de engine/esquema transaccional, no evidencia automática de migraciones innecesarias. No se las eliminó sin reemplazo.

## B. Baseline

HEAD completo y worktree en `PERF-1-evidence/HEAD.txt` y `worktree-before.txt`. Los cambios previos de UX-F1A/F1B/F0 se preservaron. Hashes productivos fijados en `product-before.json`; no se reconstruyó un baseline desde producto modificado.

Build + check-build aprobados. Targeted inicial: UX-F1A 53 + UX-F1B 57 JS; PHP P3/P10 y guards: Native product 118, HTTP 16, longitudinal 181, scheduler 46, condiciones/seguridad 6 y estados públicos/producción 35, **402 PHP**. Evidencia `.cache/ci/20261105-013237-bf758993`, salida 0, 42,47 s.

FULL preflight: **4191 = 978 JS + 539 PHP puros + 2669 PHP WordPress + 5 multisite**, RCT estricto, build, clean/upgrade, smoke y lifecycle aprobados. `.cache/ci/20261105-013319-8a1c395a`, salida 0, 401,72 s. Sólo se midió después de ese PASS.

Se preparó una copia separada de archivos distribuibles bajo `.cache/perf1/instrumented-plugin`; el tokenizer PHP envolvió 2335 métodos/funciones en 342 archivos includes. Se conservan firmas, retornos y throws con try/finally. Ningún source productivo fue instrumentado. Se mantiene la exclusión del template histórico no utilizado que ya excluye el packaging, sin borrar el archivo original.

La serie original usa producto sin probes; la copia con probes apagados cuantifica overhead del wrapper; la serie trazada permite atribución. Cada una tiene warm-up excluido + N=20. p95 es exploratorio, interpolado, no un percentil institucional. Fixture WordPress 7.1.2, PHP 8.3.35, MariaDB 11.8.9, mismo stack/digests de CI, pruebas secuenciales. Relojes monotónicos hrtime; timestamps funcionales de fixture fijos a 2026-11-05T12:00:00Z. El collector CLI libera SAVEQUERIES entre repeticiones y dispone de más memoria sólo para retener el trazado; no es la configuración de producción.

## C. P10 call graph

Entrada real: `Transport::ajax → Owner::command`. La medida comparable con UX-F0 comienza en **Owner::command**, no incluye HTTP/admin bootstrap ni parseo del request. Se prepara el comando antes del cronómetro. No se incluye una llamada extra de helper a view dentro de P10.

```mermaid
flowchart TD
    E[Transport ajax: fuera del intervalo owner] --> A[Native Owner command]
    A --> T1[TX1: checked + Authorization + Native final/metadata + command receipt]
    T1 --> C[COMMIT A]
    C --> CR[Cleanup cron de sesión expirada futura]
    CR --> B[NativeBridge complete]
    B --> T2[TX2: ExecutionOwner admit/view/dispatch SUBMIT_ASSESSMENT]
    T2 --> P[persist: journal + blocks + events + intents + schedule + revision]
    P --> C2[COMMIT Study A]
    C2 --> S[Owner settledFrame]
    S --> B2[NativeBridge complete: guard idempotente]
    B2 --> T3[TX3: view actual: A ya completada]
    T3 --> N[NativeBridge continuation]
    N --> T4[TX4: view Study y prioridad/disponibilidad de B]
    T4 --> O[offer: versión Native y binding]
    O --> T5[TX5: studyAssignment B + guard + createAssignment]
    T5 --> T6[TX6: source lock + continuation_native_id]
    T6 --> T7[TX7: logicalResult B + autorización + frame]
    T7 --> ACK[ACK con B, sin pantalla final intermedia de A]
```

A se finaliza y persiste antes de resolver B. El Study execution y la identidad/sesión del Study siguen siendo los mismos; se crea o recupera la asignación Native de B. La sesión de respuestas de B se abre mediante su posterior comando open, no se confunde con emitir otra participación. `continuation()` exige que B esté disponible y que `available_at ≤ completed_at` de A; no concatena arbitrariamente una oportunidad que apareció después. El ordering procede del Study y sus condiciones/randomizaciones, no de una búsqueda informal de cualquier evaluación.

Las lecturas de `checked`, autorización, versión, participante, public study y parent execution no son una única carga de objeto: cruzan admisión, commit y reconstrucción del ACK. `continuation_native_id` es un binding durable para replay/concurrencia, no una caché cosmética eliminable.

`p10-callgraph.csv` enumera todos los métodos observados, incluso helpers pequeños; `p10-edges.csv` conserva todos los edges/conteos. El timeline representativo conserva owners/SQL y omite únicamente la expansión de cada llamada recursiva Canonical/Check/CompiledStudy, que sí está contada en los CSV. No hay truncamiento del timeline final. WordPress core no fue instrumentado por método: sus consultas/coste de bootstrap se distinguen aparte.

## D. P3 call graph

P3 se mide como POST HTTP real a la página pública, con un email sintético nuevo, entrada inmediata y verificación NEVER, igual que el fixture UX-F0. No equivale a un reingreso ni a todos los modos de validación. La oferta Native se construye después del frame del Runtime.

```mermaid
flowchart TD
    H[PHP / WordPress bootstrap] --> R[PublicEndpoint: Study + keyrings + Longitudinal Runtime]
    R --> I[Identity schema + recover + csrf/command validation]
    I --> E[Runtime enroll]
    E --> T1[TX1: admit + Study lock + receipt + byEmail FOR UPDATE]
    T1 --> D[definition/config + participant create]
    D --> X[ExecutionOwner create: version lock + SeedVault + Simulator]
    X --> P[persist: opportunities + events + random records + notification intents/jobs]
    P --> IS[Identity issue: credential hash + participant session + enrollment receipt]
    IS --> C[COMMIT admission]
    C --> REC[Recover credential + handle de la misma participación]
    REC --> T2[TX2: ExecutionOwner view]
    T2 --> T3[TX3: FIRST_ACCESS + persist/synchronize]
    T3 --> T4[TX4: view del estado ya actualizado]
    T4 --> F[Build portal/config/messages]
    F --> O[NativeBridge offer]
    O --> T5[TX5: Native studyAssignment + snapshot/binding guard]
    T5 --> ACK[HTTP OPEN_ASSESSMENT + iframe Native]
```

Se crean entidades distintas: participant, Study execution, participant session y asignación Native. La seed del Study se cifra y vincula a su execution; el credential de sesión se guarda como hash. Un capability de email **no se crea en este modo inmediato NEVER**; signing/mint/SMTP no deben contarse como trabajo ejecutado si el trace no los contiene. La evaluación existe como oportunidad del Study antes de su asignación Native. El comando open del cuestionario posterior pertenece a otra interacción, no a este POST.

No se fusionaron identidad, sesión, ejecución, scheduling o primera oportunidad. La recursión handle después de admitir establece el primer acceso, revisiones y supresión del aviso inicial según la política; no es sólo un render duplicado.

## E. Transaction boundaries

Muestra representativa repetición 1; T0 significa fuera de transacción explícita del owner, no ausencia de SQL:

{transactions}

P10 T1 mantiene locks de Native execution/version y guards de Study/session. T2 rehidrata y despacha la completitud, confirma el journal local y persiste oportunidades/eventos/intents en la misma transacción. T3 es una revalidación posterior al commit. T4 ve el estado y prioridad actuales. T5 protege la asignación única de B y su versión/binding. T6 serializa qué continuación queda asociada a A; T7 reconstruye B bajo autorización antes del ACK.

P3 T1 serializa admisión por Study, por email y por recibo; luego creación y persistencia se unen al owner de admisión. T2/T4 son views con locks/revalidaciones. T3 introduce FIRST_ACCESS y sincroniza el estado de avisos. T5 es la asignación Native, una entidad distinta del Study execution.

No se movió ninguna lectura fuera de su lock. Las revalidaciones después de commit no se clasifican como redundantes sólo por repetir el ID. La conexión y los números de transacción pertenecen a un request; los observers de Performance Schema usan otra conexión y no se atribuyen al producto.

## F. DB attribution

{groups}

La tabla muestra grupos seleccionados de mayor frecuencia; el inventario completo está en `query-groups.csv` y `query-families.csv`. Query count, reads, writes, control de transacción, locks y tiempo están separados.

La suma de familias es exclusiva por statement; los tiempos/SQL de métodos son **inclusivos** y no se suman entre padres e hijos. `db_ms` de las familias es wall time del driver directo o medición de wpdb; el tiempo Performance Schema es ejecución agregada del servidor, de cobertura más amplia de conexión. No son métricas idénticas. P3 tiene unas cinco sentencias de inicialización de conexión/core anteriores al punto de captura por wpdb, que explica la diferencia con el total físico; no se inventan callers para ellas.

El SQL directo del driver transaccional se captura en la copia, además del hook wpdb. No se guardan SQL raw, literales, tokens ni valores. El fingerprint deriva de forma normalizada con literales y números sustituidos. Coincidir en fingerprint significa misma familia, no misma fila; `same_context_repeats` usa comparación privada exacta, misma TX y ausencia de escrituras intermedias. Una repetición después de escritura/commit se cuenta por separado.

Hay introspección de InnoDB en `Identity::schema`, `PublicStudyRepository::__construct`, `Store::ready`, `Database::requireDurableTables` y `NotificationScheduler::enqueue`. En los caminos medidos no se demuestra una migración DDL por cada paso: son verificaciones de esquema/engine, cuya eliminación cambiaría el fallo ante tablas no transaccionales. No se agregó ningún índice ni se hizo EXPLAIN sobre una consulta no identificada.

En P10 medido se ejecuta el journal y la persistencia Native; no se observa `LocalResponseSink::store` ni un INSERT de Storage alternativo. No se atribuyó tiempo a un sink que no se llamó. Las respuestas finales están en el owner Native y su checkpoint/journal según el contrato vigente.

## G. Repeated logical reads

- **REQUIRED:** Native/Study/session ownership, version binding, estados bajo lock, receipt/command replay, journal, revision CAS y guards de privacidad/pausa. Quitar una lectura puede convertir un rechazo o retry en aceptación indebida.
- **PROBABLY REQUIRED:** comprobaciones de engine/esquema y revalidaciones que cruzan commits; la finalidad de seguridad/consistencia es concreta, pero esta serie no demuestra la mínima frecuencia necesaria.
- **REDUNDANT computacional:** reconstruir/validar el mismo row inmutable de StudyVersion una y otra vez con entradas idénticas. Esto no vuelve redundante la lectura de la fila ni su lock.
- **UNKNOWN:** snapshots/progress o familias de lectura repetidas cuya igualdad de estado útil no quedó demostrada. No se deduplicaron.

La clasificación figura en cada familia y método. No se supone que misma execution ID implique mismo revision, clock, policy o estado del participant.

## H. Repository call counts

{methods}

**Estos ms están instrumentados.** El coste del profiler recursivo es importante y no sirve para prometer el ahorro total de una optimización. Canonical normalize se llama muchas veces porque compiled valida y normaliza estructuras; medir cada invocación agrega overhead. Los conteos/relaciones son la evidencia principal; la estimación económica usa el microbenchmark sin probes.

Los self times se calculan restando tiempo inclusivo de hijos instrumentados; `self_php_ms` resta además DB atribuida directamente a ese método. Siguen incluyendo overhead del profiler y llamadas core no instrumentadas. No se atribuye todo PHP−DB al algoritmo científico.

## I. Snapshot/version work

{contexttable}

La comparación de contextos utiliza internamente la **fila completa**, no sólo id/protocol_hash. Sólo se exportan cantidades; no IDs ni hashes de datos privados. `compiled()` valida contrato/hashes, reconstruye CompiledStudy y normaliza arrays; no recompila un protocolo publicado. CompiledStudy tiene propiedades privadas y devuelve arrays, pero una reutilización exige todavía definir alcance y garantías del objeto retornado.

No existe un cache request-scoped compartido que pueda habilitarse sin definir lifetime entre Runtime, NativeBridge, Authorization y nuevos repositorios. 27/18 objetos explican por qué añadir un campo memo al repositorio ahorraría sólo 1/3 reconstrucciones. Un objeto puede reutilizarse en workers/CLI durante más de un request lógico; no se usa su lifetime como sustituto del request.

No son lo mismo el protocolo publicado pinneado y `InitialScreen::effectiveDefinition`: esta última capa lee la UI publicada vigente para aplicar los cambios de pantalla inicial aunque la participación conserve su protocolo. No se cachea esa composición dinámica por version ID del protocolo.

## J. Progress/conditions/randomization

`hydrate()` recupera seed/checkpoint, valida integridad y reconstruye el Simulator; `view()` pide snapshot, nextAction y presentación; `dispatch()` valida, ejecuta y produce estado/revisión nuevos. En P10 hay cuatro hydrate y en P3 cuatro, pero no son cuatro lecturas del mismo estado lógico: se intercalan finalización, FIRST_ACCESS, nuevas oportunidades y commits.

La doble invocación de NativeBridge.complete es observable: una aplica la transición y la otra encuentra el bloque completado al construir settledFrame. Sus views/guards están en transacciones distintas. No se quitó por conteo: se necesita demostrar equivalencia de todos los fallos/reautorización entre ambos puntos.

Las snapshots son arrays independientes para representar estado y validar historial; memoizar sólo por execution ID ignoraría revision/clock/respuestas/condiciones. No se cambió fórmula de progress, semilla, decisiones ni orden. El fixture de coste es pre/post sin conditions/randomization activas complejas; los contratos de esos casos adicionales quedaron cubiertos por targeted/FULL, no se extrapola su coste desde este fixture.

## K. Scheduling/notifications

P3 llama synchronize dos veces: persistencia inicial y FIRST_ACCESS. P10 una vez al persistir la completitud en Study. La llamada siguiente no tiene necesariamente el mismo input: cambia el snapshot/revisión y el estado que decide cancelación o supresión del aviso inicial.

En P3 hay diez llamadas enqueue y en P10 cinco en esta configuración; **llamadas no equivalen a diez/cinco nuevas entregas**. Repository enqueue es idempotente; ya existentes se consultan/reutilizan y la cola se enlaza por queue_job_id. Se miden consultas de intents, jobs y verificación de que la cola es InnoDB, dentro de la transacción de persistencia.

Se preserva el contrato horario: intent time UTC y clock local establecido de la cola al convertir scheduled_at. No se tocó cron, triggers, recordatorios ni envío. P10 limpia el cron de la sesión Native finalizada fuera de su transacción inicial; es housekeeping existente. Ningún email real se envió: pre_wp_mail interceptado en el fixture y ningún worker de entrega ejecutado.

## L. Crypto/config

{cryptotable}

RNG/HMAC/AES-GCM y parsing de keyring por sí solos son pequeños en este entorno. Se generan datos sintéticos en RAM y se descartan; no se guardan keys, ciphertext, nonce, token ni seed. No se debilita crypto.

El origen de los keyrings de la serie es CONSTANT de test. `SecretStore::read/decrypt` no participa y no se asigna falsamente coste cero a una ejecución que no ocurrió; su coste en otro modo queda no medido. Capability mint/sign de email tampoco se ejecuta en el alta inmediata NEVER de esta serie. El credential de sesión sí existe y su hash/binding se verifica.

La serialización JSON está presente y algunos conteos son altos por la reconstrucción repetida; no se confunde ese recuento con coste aislado de JSON. Los microbenchmarks de compiled incluyen validación/normalización, no sólo json_decode. Reducir el payload o el contrato de metadata no está justificado: **NOT-A-PRIORITY** en PERF-1.

## M. Candidate optimizations

{ctable}

Los techos de C2 son estimaciones optimistas de CPU `(calls−1) × compiled aislado`, no medidas de una implementación. No ahorran queries, locks ni rondas DB. En C3, el proxy de DB reparte el wall time de cada familia según sus repeticiones y toma la mediana de 20 requests; no es una medición de queries eliminadas. El guard futuro también tendría coste y puede reducir o cancelar ese techo. No se promete el tiempo inclusivo instrumentado (~{fmt(m('P10','StudyVersionRepository::compiled')['total_ms'])} ms) como mejora.

Una optimización O2 sólo puede pasar a implementable con el guard/invariant comprobado. C3 no lo tiene aún: verificar que todas las tablas inspeccionadas conservan engine durante el alcance de la reutilización, incluyendo DDL concurrente y transacciones Native/Study que se unen o se abren por separado. No se sustituye por una bandera global “schema checked”.

C2 requiere un contrato explícito de contexto/lifetime entre owners, invalidación por fila/contrato exactos, conservación de cada read/lock, y comparación fuerte de resultados/estado bajo fallo y concurrencia. No se creó una static implícita que se extienda a operaciones posteriores del worker.

## N. Rejected optimizations

- Quitar el segundo complete o fusionar transacciones por parecer duplicadas: cruza commits, reautorización y replay.
- Omitir guards de engine, journal, intents o CAS: cambia garantías de atomicidad/idempotencia.
- Reutilizar entryDefinition como dato siempre fijo: contiene overlay publicado dinámico; no basta demostrar igualdad en un fixture secuencial.
- Cachear execution/session/progress por ID: la revisión/clock/privacidad cambian y el retorno debe seguir vigente.
- Memo de keyrings/crypto para ahorrar microsegundos: no alcanza beneficio material y obliga a definir fuentes/invalidation.
- Cache por instancia de repo: ahorro estimado menor de 1 ms en ambos caminos, lifetime no equivale a request, no compensa complejidad.
- Redis/transients/caché persistente e índices por conteo: fuera del alcance sin una consulta/plan/selectividad específicos.

## O. Implemented SAFE optimizations

**Ninguna.** No apareció una modificación local con redundancia inequívoca, beneficio material, lifetime seguro y prueba de equivalencia completa de una implementación concreta. Se elige Outcome B permitido por el prompt. Esto no niega la repetición de compiled: la reclasifica como una oportunidad que necesita compartir contexto entre owners, no un borrado de validaciones.

No se modificó source productivo, scheduling, condiciones, randomization, persistencia, identidades, contratos de metadata, dataset ni comportamiento visible. No se solicita una revisión visual nueva.

## P. Before/after measurements

No hay A/B optimizado porque no se implementó optimización. La siguiente comparación es **overhead del instrumento**, no mejora:

{metrics}

El trace puede más que duplicar el tiempo de P10 por la instrumentación de helpers recursivos; ésa no es la latencia del producto. La copia con probes desactivados también tiene costo, y no se presenta como baseline original. Series warm-up + N=20, mismo stack/fixture/clock, ejecutadas secuencialmente; datos de series exploratorias no se mezclan. Los IDs y entropy de instancias nuevas son sintéticos y distintos entre repeticiones, como en UX-F0; no se pretende que esta calibración sea una comparación exacta de dos optimizaciones con el mismo seed.

## Q. Equivalence

En el producto aprobado, las pruebas nuevas verificaron:

- P3-E1–E8: participant activo/no verificado, asociación identity→execution, misma execution en portal, seed/key-ID semánticos mediante decrypt en RAM, binding del credential, schedule/intents invariantes ante replay y OPEN_ASSESSMENT inicial.
- P10-E1–E8: concatenación, siguiente E_POST, A completada, progress/nextAction correctos, un journal, response replay exacto, DB lógico sin duplicados y guards de revisión/concurrencia.

La comparación exacta de response se hace entre replay sin trazado y replay con trazado sobre **la misma DB, mismo command_id e IDs**. No se normalizan IDs/timestamps para declarar igualdad. Antes/después se comparan en RAM todas las filas de tablas engine y jobs, más cron; includes Native partial/final/metadata y study checkpoint/journal/intents. El export sanitizado guarda ordinales, estados, revisiones y presencia de foreign keys, nunca sus valores, emails, seeds o ciphertext. Los timestamps/IDs de las filas se comparan en RAM sin normalizar; el snapshot lógico no incluye contadores físicos de AUTO_INCREMENT ni buffers internos de MariaDB.

`logical-snapshots.json` contiene esos resúmenes before/after y booleans de igualdad privada exacta. No es un A/B de una optimización inexistente. No se publica un hash de secretos como reemplazo de la comparación. La referencia UX-F1A/F1B se conserva sin regenerarla; la integridad de source antes/después se comprueba por hashes públicos de archivos.

Hay **{len(checks)} aserciones de escenario aprobadas en la copia de medición** y **{len(cplain)} sobre producto original**; son repeticiones del contrato, no nuevos tests únicos añadidos al gate ni se suman al total histórico.

## R. Concurrency/failure paths

Dos procesos PHP reales, inputs sólo por pipes y barrera descartable: commands diferentes sobre la misma revisión → un ganador, un 409; mismo command duplicado → ambos ACK del mismo Native siguiente. No se guarda token en archivos del profiler ni en stdout publicado. Resultados en `checks.json`.

Se inyecta DatabaseFailure(2013) antes del commit Native y antes del commit de admisión: comparación privada exacta before/after confirma rollback lógico. Es **fault injection**, no un corte físico de red de MariaDB. Sesión Native expirada no acepta el submit final y devuelve reset. Revisión obsoleta se rechaza; P3 también prueba el conflicto por reutilizar command_id con otro email y comprueba que el DB lógico no cambie. FULL/targeted también cubren recuperación, metadata inválida, snapshots corruptos, enrollment race, workers/notificaciones y guards de privacidad.

Concurrencia con publicación/retirement, DDL y worker que invalida disponibilidad se analiza como riesgo para las propuestas de cache; no se afirma haber probado cada interleaving de una implementación que no existe. Por eso no se marca C2/C3 como listos para producción.

## S. Targeted

Preflight: 110 JS dirigidos y 402 PHP dirigidos, verdes. Harness nuevo: escenarios descritos y cinco invariantes independientes de Profiler (desactivado por defecto, nested/self DB, normalización sin literales, límites de TX/write y conteo de contextos/objetos sin IDs). En `profiler-tests.log` y `checks.json`; evidencia de privacidad e integridad en `verification.md`.

No se cargan helpers PERF-1 desde manifests ni desde producción; sólo la copia de test los conoce y requiere configuración explícita del fixture. SAVEQUERIES se libera entre repeticiones para no medir su acumulación como un problema del producto. Los scripts y la copia son removibles; no se agregó telemetría.

## T. FULL

FULL baseline verde: 4191, RCT estricto/build/check-build/clean/upgrade/smoke/lifecycle. Como no hubo cambios en source productivo ni en suites que participan del gate, no se requiere otro FULL para una optimización. La verificación final compara íntegro el producto aprobado, ejecuta los targeted del harness y comprueba el ZIP sin helpers. No se presenta un trace verde como sustituto de FULL.

Sin commit, push, tag, release ni deploy. No se usaron datos reales ni se accedió a la base principal.

## U. Remaining server bottlenecks

Prioridad de investigación pequeña:

1. **Contexto de reconstrucción inmutable compartido entre owners.** Es la repetición causal más clara; diseñar lifetime explícito, key/guard de fila exacta y comportamiento de clone, manteniendo todos los reads/locks. Ganancia potencial del orden de 12,7 ms P10 / 4,8 ms P3 en este fixture, techo optimista, no prometida.
2. **Comprobaciones de metadata transaccional.** Evaluar un guard acotado a conexión/transacción con estabilidad DDL demostrada, sin convertir un error actual en aceptación. No caché global de schema.

Las transacciones, journals, intents y reautorización explican buena parte del coste DB y tienen finalidad concreta. La fase no autoriza fusionarlas ni cambiar ACK/retries. No se reabre browser/matrix/imagen/CLS/fecha, debounce, spinner o UX de red.

**Resultado: Outcome B. P3/P10 descompuestos, coste explicado y candidatos clasificados; producto preservado.**
'''
(R/'PERF-1 P10-P3 Server Path Decomposition.md').write_text(report)
print('PASS report A–U generated from sanitized attribution')
