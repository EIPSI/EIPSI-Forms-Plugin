from pathlib import Path
import csv,json,statistics
R=Path(__file__).resolve().parents[2];O=Path('/home/m2u/eipsi/wp-eco/informes/EIPSI-Forms/PERF-2-evidence')
with (O/'distributions.csv').open() as f:dist=list(csv.DictReader(f))
with (O/'paired-deltas.csv').open() as f:paired=list(csv.DictReader(f))
with (O/'candidate-a.csv').open() as f:arows=list(csv.DictReader(f))
with (O/'candidate-b.csv').open() as f:brows=list(csv.DictReader(f))
def metric(s,v,k,field='median'):return float(next(r[field] for r in dist if r['scenario']==s and r['variant']==v and r['metric']==k))
def num(n):return f'{n:.3f}'
def table(s):
 lines=['| Variante | Total mediana / p75 / p95 exploratorio (ms) | PHP mediana (ms) | DB mediana (ms) | Sentencias físicas PFS |','|---|---:|---:|---:|---:|']
 for v in ['original','baseline','A1','B1']:
  lines.append(f'| {v} | '+ ' / '.join(num(metric(s,v,'total_ms',f)) for f in ['median','p75','p95_exploratory'])+f" | {num(metric(s,v,'php_ms'))} | {num(metric(s,v,'db_ms'))} | {int(metric(s,v,'db_statements'))} |")
 return '\n'.join(lines)
def savings(s,v):return num(statistics.median(float(r['total_ms_saved']) for r in paired if r['scenario']==s and r['variant']==v))
full=json.loads((O/'baseline-full-summary.json').read_text());head=(O/'HEAD.txt').read_text().strip();eq=json.loads((O/'equivalence.json').read_text());strategies=json.loads((O/'experiment/strategies.json').read_text())
report=f'''# PERF-2 — Shared Context and Schema Guard

Fecha: 2026-11-05 · America/Cayenne. HEAD: `{head}`. Sin commit, push, tag, release ni deploy.

## A. Executive summary

**Outcome C. A-REJECT-RISK / B-REJECT-RISK. Ninguna optimización incorporada al producto.**

A1 demuestra ahorro de reconstrucción, pero su guard de dominio añade dependencia de una conexión viva a una operación originalmente pura con una fila ya leída. Tras cerrar mysqli, el original reconstruye correctamente; A1 lanza `Error: mysqli object is already closed`. Es una regresión del prototipo, no un fallo nuevo del producto aprobado. No se promueve una variante cuya equivalencia de error falla.

B1 reduce introspecciones, pero misma conexión + misma transacción no demuestra estabilidad de tablas consultadas sólo a través de metadata. Una segunda conexión pudo cambiar engine, renombrar y borrar la tabla; B1 devolvía el resultado anterior. También pudo ocultar una pérdida real de conexión remota. No se promueve.

El camino normal conserva respuesta y estado exactos; eso no anula los contraejemplos. Los 679 archivos registrados al iniciar PERF-2 no cambiaron. También permanecen idénticos los 790 archivos de la referencia aprobada PERF-1. FULL baseline: **4.191 tests**, RCT estricto y build/clean/upgrade/smoke/lifecycle verdes. No hubo cambios visibles ni nueva revisión humana visual.

## B. Baseline

Se registraron HEAD, worktree y hashes antes de experimentar. Se conservó el worktree previo, sin reset ni regeneración de la referencia científica. El FULL ejecuta build y check-build antes de sus suites; los targeted relevantes se verificaron como etapas de ese mismo FULL, antes de iniciar los prototipos.

FULL: `.cache/ci/20261105-090629-511f2adf`, exit 0, {full['seconds']} s. Stack fijado: PHP 8.3.35, WordPress 7.1.2, MariaDB 11.8.9, Node 22.23.3; imágenes exactas en `environment.json`.

El control `original` usa el producto aprobado. `baseline` usa la copia con composición de contexto y contadores, sin reutilización. A1 y B1 se activan individualmente en esa copia. No se aplicó A+B. Las copias se generaron con el allowlist de distribución; los archivos de pruebas se montaron aparte.

P3 de esta corrida contabiliza 287 sentencias físicas frente a las 258 registradas en PERF-1; las tres variantes contemporáneas sin guard B registran las mismas 287. No se atribuye esa diferencia entre fases a optimización ni se mezclan sus series. P10 conserva 327. La comparación válida es la contemporánea de PERF-2, con fixture/clock/seed iguales.

Metodología: warm-up descartado + 20 repeticiones por escenario y variante; orden `baseline → A1 → B1` alternado con su inverso en repeticiones pares. El control original se ejecutó después, sin fixtures de medición propios compitiendo por CPU. No se usó el profiler recursivo de PERF-1. Se midieron pared externa, PHP y statements/timers físicos por Performance Schema; los contadores específicos identifican reconstrucciones y metadata.

`total` P10 abarca `NativeOwner::command` y su continuación; preparación, snapshots y checks quedan fuera. P3 es HTTP real interno del fixture; PHP incluye bootstrap y handler. DB ms son inclusivos de ambas métricas y no se suman a ellas. `PHP - DB` es sólo una aproximación al trabajo fuera de DB, no un perfil CPU exacto. P95 con N=20 es exploratorio. No son medidas de producción ni SLA.

## C. Candidate A architecture

Objeto explícito `Perf2Context`, creado únicamente en la copia experimental. Mantiene fila completa y `CompiledStudy` validados, más contadores sólo de test. Los artefactos compartidos no contienen execution/session/progress/revision ni resultados mutables de condiciones, clock o privacidad.

La clave experimental combina contrato local `PERF2_A1_V1`, contrato de compilación, dominio de conexión/prefix y la fila completa serializada. La comparación estricta `$cachedRow === $row` es la autoridad adicional ante coincidencia del digest. Un cambio de cualquier campo produce miss; errores de validación no se cachean. Hashes/filas no salen del proceso.

La fila incluye `compiler_contract_version`, protocolo, grafo, hashes, bindings, versión y metadata de lifecycle. No basta un `study_version_id`. Se mantienen los checks originales en cada miss y todas las lecturas/locks previas a cada reconstrucción, tanto en hit como miss.

La fila durable puede cambiar de lifecycle: sólo se reutiliza ante igualdad completa. El contexto no cachea `InitialScreen::effectiveDefinition`, la definición de entrada dinámica ni otros resultados mutables. El overlay publicado de la pantalla inicial sigue leyendo su owner como antes.

## D. Candidate A lifetime

P10: `Owner::command` crea un contexto por invocación, compone temporalmente un Store/Database con él y lo pasa explícitamente a complete, progress, continuation, offer y owners anidados. `finally` restaura el Store previo y cierra el contexto, también al lanzar una excepción.

P3: el callback de la ruta crea un contexto, lo pasa al Database/Runtime y a los owners que renderizan/ofrecen la evaluación. Un callback de shutdown captura esa instancia y la cierra. La copia usa un campo interno del frame para llevar la instancia al renderer; los checks verifican que no se serializa en el cuerpo público. Esta técnica experimental no queda aprobada como nueva API productiva.

Cerrar elimina entradas, filas y referencias a repositorios; usar un contexto cerrado se rechaza en test. No hay variable global, singleton, propiedad static con cache, transient, Redis ni wp_cache cross-request. Los flags de variante y los contadores son herramientas del fixture, no una configuración disponible en el producto.

Cada command, retry/replay y nuevo job recibe un contexto nuevo; la recursión propia de un Runtime conserva el de su operación. Las dos conexiones concurrentes no comparten instancia. El contexto no se une a un batch completo. Los workers productivos permanecen sin modificar.

**Guard insuficiente descubierto:** el dominio experimental consulta `mysqli_thread_id` al reconstruir. La disponibilidad de esa conexión no es un input de `compiled(row)` original. Además, el dominio no representa explícitamente una selección distinta de base sobre la misma conexión. No se considera demostrado un scope completo para una adopción productiva.

## E. Candidate A mutability audit

Auditoría de todo `CompiledStudy`, `includes/study-engine/engine/Compiler.php`: dos propiedades privadas, definition y graph, normalizadas con Canonical a datos JSON. No hay setter, método que modifique esas propiedades, getter por referencia, memoización ni reloj interno. Canonical rechaza objetos/resources y valores no finitos; el camino publicado reconstruye a partir de JSON.

`definition`, `graph`, `node`, `children`, `descendants`, `order`, `target`, `dependencies` y `randomCandidates` devuelven arrays por valor. `parent`, `assessmentOwner`, hashes y `nextStructural` devuelven escalares derivados. No se localizaron consumers que muten referencias internas o dependan de identidad de instancia. Reflection arbitraria no forma parte del contrato.

Pruebas: mutar los arrays devueltos no cambia la instancia; clone conserva grafo y separación de identidad; reconstruir desde los arrays conserva contenido. Se probaron campos de contrato/JSON/hash/binding alterados y se conservaron sus errores mientras la conexión estaba disponible.

Microbenchmark aislado del fixture 01, mediana ms/operación: misma instancia {strategies['same']:.8f}; clone {strategies['clone']:.8f}; reconstrucción de representación {strategies['representation']:.8f}. Son primitivas de ese fixture, no tiempos de P3/P10. Compartir la instancia es la estrategia A1 por su contrato de arrays privados. Clone no arregla el fallo del guard. No se avanzó a A2.

## F. Candidate A experimental results

P10: **28 solicitudes → 1 construcción, 27 hits**, 27 repositorios. P3: **21 → 1, 20 hits**, 18 repositorios. Fila completa coincidente; ningún guard de igualdad estricta encontró colisión/mismatch en estas mediciones. Cambiar metadata u otro dominio fuerza una entrada distinta en pruebas.

P10 conserva 43 lecturas StudyVersion, 79 lecturas `FOR UPDATE`, 327 statements físicos y 7 transacciones. P3 conserva 30 lecturas StudyVersion, 44 `FOR UPDATE`, 287 statements físicos y 5 transacciones. Esos contadores corresponden al wrapper directo del engine; Performance Schema incluye también WordPress. El CSV distingue `owner_direct_statements` de `db_statements` físicos totales.

El digest privado de la secuencia completa de SQL directo, incluidos locks y valores de fixture, coincide entre baseline/A1 desde el mismo estado restaurado. Las respuestas, tablas y metadata también. No se publican SQL ni digests privados.

Ahorro mediano de diferencias emparejadas de total: P10 **{savings('P10','A1')} ms**, P3 **{savings('P3','A1')} ms**. El beneficio es medible en el prototipo; no se transforma en una mejora productiva aprobada.

## G. Candidate A decision

**A-REJECT-RISK.** Reproducción existente: `tests/perf2/guard-failures.php` obtiene la fila por una conexión real, reconstruye, cierra esa conexión y vuelve a solicitar la misma fila. Control original: `SUCCESS_SAME_GRAPH`. A1: `Error`, mysqli cerrado. El resultado completo sanitizado está en `failure-paths.json`.

El wrapper baseline del prototipo también incorpora el sondeo de dominio; por eso no es un sustituto conductual del original y se ejecutó el control original separado. Se rechaza esta composición/guard de A1, no se afirma que toda reutilización inmutable sea imposible. Un dominio que no cambie la semántica de una reconstrucción pura —o un fallback explícito sin reutilización ante pérdida de dominio— necesitaría otro prototipo y repetir prueba/medición. No se degrada silenciosamente el guard para declarar aceptación.

## H. Candidate B architecture

B1 comparte resultados exactos de `SELECT ENGINE ... information_schema.TABLES` sólo por la instancia explícita de contexto, identidad del objeto mysqli/thread, generación de transacción y SQL/invariant exacto. Cada query identifica tablas esperadas; los owners mantienen su comprobación de cantidad y engine esperado.

Sólo cachea dentro de START/COMMIT observados; no conserva resultados a través de COMMIT, ROLLBACK, cambio de conexión, error SQL observado o DDL/SET observado. La primera verificación sigue siendo física. No hay `schema_checked=true` global. No se añaden locks, índices ni transacciones.

Esto es un guard **experimental insuficiente**: observar límites a través de este wrapper no demuestra todos los límites reales, y consultar metadata no adquiere automáticamente el MDL de cada tabla objetivo.

B2, misma conexión/request pero distinta transacción: no se reutiliza; tests de COMMIT y ROLLBACK exigen miss. B3, distintas conexiones: miss. Reconnect cambia generación/identidad y exige miss. No se adoptaron B2 ni B3.

## I. MariaDB DDL/concurrency semantics

Se verificó con dos conexiones reales al MariaDB fijado y tablas sintéticas, nunca sobre la base principal:

- Sólo consultar ENGINE en `information_schema` dejó que la otra conexión ejecutara ALTER ENGINE, RENAME y DROP durante la transacción de A. La segunda lectura física detectó el cambio; el cache devolvió el estado anterior.
- Leer la tabla objetivo con SELECT sí mantuvo su metadata lock: las tres DDL de B expiraron por lock timeout 1205. Tras COMMIT pudieron completarse.
- ALTER en la misma conexión produjo implicit commit: un INSERT previo persistió aun después del ROLLBACK posterior.
- Tras KILL CONNECTION, B1 pudo devolver la metadata cacheada mientras la consulta real ya fallaba. Un error observado e invalidado y un reconnect no reutilizaron el resultado.
- Quitar una columna no lo detecta una comprobación sólo de ENGINE: al usar esa columna se conserva el fallo 1054. No se presenta ENGINE-readiness como auditoría completa de columnas.

Estas observaciones concuerdan con [MariaDB: Metadata Locking](https://mariadb.com/docs/server/reference/sql-statements/transactions/metadata-locking) y [MariaDB: Implicit Commit](https://mariadb.com/docs/server/reference/sql-statements/transactions/sql-statements-that-cause-an-implicit-commit). MySQL documenta también locks de metadata hasta el fin de la transacción al usar objetos: [MySQL 8.4: Metadata Locking](https://dev.mysql.com/doc/refman/8.4/en/metadata-locking.html). No se ejecutó una matriz MySQL: las pruebas reales son MariaDB 11.8.9.

No se asume que otro plugin abstenga de DDL. WPDB u otro owner pueden usar la conexión fuera del wrapper; una identidad/generación local no prueba que no ocurrió implicit commit. Adquirir MDL extra o revalidar estado de conexión cambiaría locks/consultas/coste y requeriría otra investigación, no queda incluido bajo una cache aparentemente inocua.

## J. Candidate B experimental results

P10: 51 solicitudes de metadata, **21 físicas / 30 hits**; total 327 → 297 statements. P3: 34 solicitudes, **21 físicas / 13 hits**; 287 → 274. No se eliminan lecturas de versión ni locks de filas. Permanecen 7 y 5 transacciones respectivamente; 14/10 boundaries START+COMMIT y 15/11 invalidaciones incluyen la inicial de conexión.

Ahorro mediano emparejado de total: P10 **{savings('P10','B1')} ms**, P3 **{savings('P3','B1')} ms**. Ver `candidate-b.csv`, no tratarlo como ahorro seguro. Los contraejemplos prueban que esas omisiones no son universalmente equivalentes.

## K. Candidate B decision

**B-REJECT-RISK.** Misma conexión/transacción y una inspección previa no bastan. Missing/renamed table o wrong engine pueden pasar con metadata vieja; una conexión caída también puede parecer disponible. Los guards de readiness productivos quedan todos activos.

El error 1054 de columna ausente sigue siendo un error al uso, no un éxito; eso no repara los otros falsos pass. Invalidar en límites observados es necesario pero insuficiente. No se añadió una prueba MDL/locking nueva al producto.

## L. Productive changes, if any

**Ninguna.** Sólo `tests/perf2/`, copia en `.cache/perf2/`, informe y evidencia. No se tocó source, UI, scheduling, condiciones, journals, ACK, retry, debounce, contrato metadata, SMTP ni lógica científica.

No se hizo commit/push/tag/release/deploy. El ZIP construido es sólo una validación local del paquete aprobado, no un release. 633 archivos, versión existente 1.0.0. Tests y prototipos quedan excluidos.

## M. P10 before/after

{table('P10')}

No hay “after” productivo: A1/B1 son experimentos rechazados. El coste de composición/contadores se ve comparando original con baseline; no se resta como una ganancia implementada. La lectura correcta del efecto aislado usa baseline contra cada variante, con orden equilibrado y sin combinar A+B.

## N. P3 before/after

{table('P3')}

P3 usa HTTP local real; no incluye navegador/layout ni red externa. Los 287/274 statements son totales de PFS, no sólo los 228/215 del wrapper directo. Estas diferencias de alcance quedan separadas en CSV.

## O. Equivalence

P3/P10 E1–E8 se reutilizaron en todas las variantes, warm-up incluido para validación pero excluido de distribuciones. Total de assertions registradas en las cohortes: **1.380** (345 original, 1.035 copia), con repeticiones; no se suman al total de tests funcionales FULL.

Adicionalmente, **14 assertions de igualdad exacta** del camino normal partieron del mismo estado restaurado del fixture y la misma entropía controlada. P3 compara body HTTP completo JSON/HTML, identidad, ejecución, seed/ciphertext, sesiones, programación, intents/jobs y cron durable. P10 compara ACK, final/parcial/metadata, journal, progreso, siguiente Native, demás tablas y cron. No se normalizaron IDs ni timestamps.

Se restauraron también secuencias AUTO_INCREMENT y se recomputaron columnas generadas en el fixture; el contraste es del contenido lógico completo, no de metadata física del motor. Se leyó cron directamente de su opción durable para no confundirlo con una caché de opciones del proceso. Sólo se aceptaron pares dentro del mismo segundo real; los intentos que cruzaron el límite se descartaron antes de contrastar. No se exportan filas privadas.

En A1, secuencia de SQL directo/locks idéntica. En B1, sólo boundaries idénticos: cambia deliberadamente la secuencia al omitir metadata. El total PFS cubre también el SQL WordPress; no se afirma disponer de una traza individual completa de todo SQL WP.

**Equivalencia de caminos normales: demostrada en los fixtures. Equivalencia ante todos los fallos: refutada para A1 y B1.** Equivalencia del producto entregado con el aprobado: hashes idénticos. Esto permite rechazar candidatos, no certificarlos.

## P. Failure/concurrency

Dos procesos PHP y conexiones independientes: comandos distintos con misma revisión → un ganador y conflicto 409; comando duplicado → ambos ACK al mismo próximo Native. El caso representa concurrencia de dos tabs a nivel owner; no se simularon dos UIs de navegador.

Alcance del replay: el de P10 alterna baseline/variante sobre el mismo owner con contextos nuevos. El replay P3 de la serie usa el helper CLI sin cache compartida; no se presenta como prueba de replay HTTP A1 con contexto activo. P3 A1 sí tiene primer ingreso HTTP, comparación HTTP exacta y race con contexto explícito. Esa limitación no permite aceptar A1, y no modifica el rechazo ya demostrado por conexión cerrada.

Race de enrollment: una identidad/ejecución, una respuesta de acceso y otra de entrada sin autenticar al segundo request. Replay conserva respuesta y estado exactos. Comando reutilizado con otro email se rechaza sin mutar. Stale revision rechazada y sesión vencida no finaliza ni envía respuestas.

Fallos antes del commit Native y de enrollment: `DatabaseFailure(2013)` inyectado con observer y rollback exacto de tablas/cron. Se comprobó que el observer se activó. La conexión cerrada después de la lectura y el KILL de DDL-test son fallos de conexión reales separados; no se afirma haber cortado TCP en medio de una escritura.

`failure-paths.json` separa contraejemplos esperados de checks exitosos. Un PASS de “COUNTEREXAMPLE” significa que se reprodujo el motivo de rechazo; no significa que ese candidato sea seguro.

## Q. Security/multisite/workers

El cache sólo contiene una fila de definición inmutable y su CompiledStudy; nada de identidad participante, respuestas, session credentials, capabilities ni seed plaintext. Los valores privados usados para comparar estado permanecieron en memoria/volúmenes descartables. Evidencia: contadores, tiempos y booleans; no filas, SQL, tokens, correos o hashes privados de SQL.

Separación probada en unit tests: otro prefix/dominio y otra conexión no reutilizan una entrada; cerrar un contexto impide reutilizarlo; crear el del siguiente job arranca frío. El FULL original incluye **5 tests multisite**. No se presenta esa suite como una validación multisite de los prototipos rechazados: el scope completo de selección de DB/site de A1 no quedó aprobado. Tampoco se extrapola el fixture local a una instalación institucional.

No hubo cambios en workers; no hay cache global que pueda sobrevivir jobs. Los procesos concurrentes fueron independientes. Cada proyecto Docker propio se dio de baja con sus volúmenes mediante Gate. No se hizo cleanup global ni se tocó la instalación principal.

## R. Targeted

Antes de prototipos, las etapas pertinentes del FULL cubrieron `study-engine-persistence` (StudyVersionRepository, transacciones, idempotency/concurrency), cross-sectional/longitudinal (Runtime enroll, Identity, scheduler, notificaciones, workers), readiness, assessment-product/http (NativeBridge/P3/P10), sec0/sec1/sec2/sec3/sec3-logical, sch1 y multisite. Counts/estados exactos constan por etapa en `baseline-full-summary.json`.

Prototipos: 16 invariantes de contexto/mutabilidad/row guard + microbenchmark de tres estrategias; 21 assertions DDL/connection; 14 assertions exactas; 1.380 assertions de cohortes repetidas; dos verificaciones del comportamiento original/A1 tras cierre. Son mediciones/pruebas auxiliares, no miles de casos funcionales nuevos.

Validación de evidencia: 10 checks. Distribución: 4 checks. `git diff --check` verde. Los scripts/harness quedaron documentados en `tests/perf2/README.md`; `prepare.py` reproduce sólo la copia experimental y está separado del paquete.

## S. FULL

**4.191 tests: 978 JS + 539 PHP puro + 2.669 WordPress + 5 multisite.** RCT estricto verde, build/check-build y clean/upgrade/smoke/lifecycle verdes. Ver `baseline-full-summary.json`.

No hubo cambio productivo posterior al baseline. Según §52 del prompt, FULL baseline + targeted experimentales y hashes iguales bastan; no se repite un FULL del mismo source para aparentar un after. No se modificó el golden científico UX-F1A (`2f66abcc690211e0dde4ace6287a200dc17d6650f16344e97e1409147bb64173`).

## T. Remaining optimization opportunities

1. Reformular A para que su dominio/lifetime no vuelva dependiente de una conexión viva a la reconstrucción pura. Tratar pérdida/cambio de conexión y cambio de base explícitamente, sin permitir reutilización entre operaciones/jobs/sites. Repetir igualdad exacta, errores, mediciones y cobertura antes de cualquier ACCEPT.
2. Para B, estudiar sólo subconjuntos con MDL ya demostrado por el camino existente y con conexión/transacción observables de forma fiable. Nunca adquirir locks extra ni sondear estado silenciosamente bajo este resultado. El caso general “misma transacción” está refutado.
3. Si una nueva variante llega a ACCEPT, portarla por separado y ejecutar targeted, before/after, concurrency y FULL posterior. No extrapolar los ahorros de estas copias a source aún no implementado.

Los tamaños de prototipo, overhead y cobertura insuficiente de guards son parte de la decisión; no se acepta código sólo por elegancia. No se abrió una fase nueva ni se alteraron índices o contratos.

**PERF-2 shared-context/schema-guard investigation completed — optimization candidates rejected by evidence.**
'''
(O.parent/'PERF-2 Shared Context and Schema Guard.md').write_text(report)
print('Report written')
