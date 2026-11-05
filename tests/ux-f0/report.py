#!/usr/bin/env python3
"""Evidence tables + report. Does not execute or edit product code."""
import csv,json,statistics,math,sys
from pathlib import Path
out=Path(sys.argv[1]);reports=out.parent
load=lambda n:json.loads((out/n).read_text())
def med(v):return statistics.median(v)
def pct(v,p):
 v=sorted(v);i=(len(v)-1)*p;return v[math.floor(i)]+(v[math.ceil(i)]-v[math.floor(i)])*(i-math.floor(i))
def stats(v):return [len(v),med(v),pct(v,.75),pct(v,.95) if len(v)>=20 else 'NA']
def table(headers,rows):return '| '+' | '.join(headers)+' |\n|'+'|'.join('---' for _ in headers)+'|\n'+'\n'.join('| '+' | '.join(str(x) for x in row)+' |' for row in rows)
def f(x):return f'{x:.2f}' if isinstance(x,(int,float)) else str(x)
summary=list(csv.DictReader((out/'summary.csv').open()));summarymap={(r['scenario'],r['environment']):r for r in summary};query_records=load('server-queries.json')
def count(s):return med(x['query_count'] for x in query_records if x['scenario']==s and x['repetition']!='warmup')
http=[json.loads(x) for x in (out/'server-http.jsonl').read_text().splitlines() if x.strip() and json.loads(x)['repetition']!='warmup'];owner=[r for r in load('server-records.json') if r['environment']=='local-wordpress-owner' and r['repetition']!='warmup'];client=[r for r in load('client-scenarios.json') if r['repetition']!='warmup']
def sv(s,env):return float(summarymap[(s,env)]['median'])
httpenv='local-wordpress-http';ownerenv='local-wordpress-owner';jsenv='synthetic-jsdom'
rows=[]
for s in ['P1','P2','P3','P4','P7','P8','P9','P11','P12','P12-expired','P13','P16','P17']:
 q=[x for x in http if x['scenario']==s];r=summarymap[(s,httpenv)];rows.append([s,r['N'],f(float(r['median'])),f(float(r['p75'])),f(float(r['p95'])),f(med(x['php_ms'] for x in q)),f(med(x['pfs']['ms'] for x in q)),f(med(x['pfs']['count'] for x in q))])
httptable=table(['Operación HTTP','N','Total mediana ms','p75','p95','PHP ms','DB ms','Sentencias físicas'],rows)
ownerrows=[]
for s in ['P6','P10','P14','P15','P18','ENGINE-simple','ENGINE-branch','ENGINE-random','ENGINE-complex','REPO-reconstruct','SER-json']:
 a=[x for x in owner if x['scenario']==s];r=summarymap[(s,ownerenv)];ownerrows.append([s,r['N'],f(float(r['median'])),f(float(r['p75'])),f(float(r['p95'])),f(med(x['db_ms'] for x in a))])
ownertable=table(['Owner/función local','N','Mediana ms','p75','p95','DB ms'],ownerrows)
clienttable=table(['Operación JS/DOM','N','Mediana ms','p75','p95'],[[r['scenario'],r['N'],f(float(r['median'])),f(float(r['p75'])),f(float(r['p95']))] for r in summary if r['environment']==jsenv])
renders=[r for r in load('render-trace.json') if r['repetition']!='warmup'];rendermedian=med(sum(r['invocations']) for r in renders)
extra=[r for r in load('client-extra.json') if r['repetition']!='warmup'];queue=[r for r in extra if r['kind']=='queue']
sertable=table(['Preguntas','N','stringify ms','parse ms','clone ms','snapshot ms','Bytes totales','Bytes metadata'],[[n,20,*[f(med(x[k] for x in extra if x.get('questions')==n)) for k in ['stringify_ms','parse_ms','clone_ms','snapshot_ms','total_bytes','metadata_bytes']]] for n in [1,20,50]])
wait=load('transport-experiments.json');waittable=table(['Demora añadida ms','N medido','Feedback síncrono ms','Resolución ACK ms','p75 ACK ms','p95'],[[delay,3,f(med(x['feedback_sync_ms'] for x in wait if x.get('delay_ms')==delay and x['repetition']!='warmup')),f(med(x['ack_ms'] for x in wait if x.get('delay_ms')==delay and x['repetition']!='warmup')),f(pct([x['ack_ms'] for x in wait if x.get('delay_ms')==delay and x['repetition']!='warmup'],.75)),'NA (N=3)'] for delay in [100,500,1000,3000,8000]])
mat=load('matrices.json');mattable=table(['Opciones','Filas','N por labels','Nodos','Construcción con labels largos ms'],[[n,mat[0]['rows'],20,next(x['nodes'] for x in mat if x['options']==n),f(med(x['construction_ms'] for x in mat if x['options']==n and x['labels']=='long' and x['repetition']!='warmup'))] for n in [3,5,7,11]])
details=load('client-details.json');datetable=table(['Formato','N','10 prefijos + validación ms'],[[form,20,f(med(x['total_ms'] for x in details if x['scenario']=='DATE-'+form and x['repetition']!='warmup'))] for form in ['YYYY-MM-DD','DD/MM/YYYY','MM/DD/YYYY']])
wire=[x for x in load('server-payloads.json') if x['repetition']!='warmup']
wiretable=table(['Operación','Body request bytes','Body response bytes','Metadata request JSON bytes','Definition response JSON bytes','Session response JSON bytes','TTFB ms','Después TTFB ms'],[[scenario,*[f(med(x[key] for x in wire if x['scenario']==scenario)) for key in ['request_body_bytes','response_body_bytes','request_metadata_json_bytes','response_definition_json_bytes','response_session_json_bytes','ttfb_ms','after_ttfb_ms']]] for scenario in ['P3','P4','P7','P9','P12']])
assets=list(csv.DictReader((out/'assets.csv').open()));assettable=table(['Asset observado','Bytes sin comprimir','Notas'],[[a['asset'],a['size'],a['notes']] for a in assets])
# An additive partition only within the same HTTP sample; PFS observing overhead is in residual.
parts=[]
for scenario in ['P3','P7','P11']:
 a=[x for x in http if x['scenario']==scenario];records=[x for x in load('server-records.json') if x['scenario']==scenario and x['environment']==httpenv and x['repetition']!='warmup'];byrep={str(x['repetition']):x for x in records}
 for label,values in [('DB (PFS)',[x['pfs']['ms'] for x in a]),('PHP sin DB',[x['php_ms']-x['pfs']['ms'] for x in a]),('Residual HTTP/Apache/profiler',[byrep[str(x['repetition'])]['total_ms']-x['php_ms'] for x in a])]:parts.append([scenario,label,*[f(y) for y in stats(values)],f(100*med(values)/sv(scenario,httpenv))+'%'])
parttable=table(['Operación','Segmento','N','Mediana ms','p75','p95','Fracción aproximada de total mediano'],parts)
hotspots=[
 ['F0-01','NativeBridge/owners','P10','MEASURED-HOT',f(sv('P10',ownerenv))+' ms',f(100*med(x['db_ms'] for x in owner if x['scenario']=='P10')/sv('P10',ownerenv))+'% DB; resto PHP','20 repeticiones locales','NEEDS-EQUIVALENCE-PROOF','Priorizar una investigación de llamadas/lecturas y reconstrucción en esta transición; no eliminar verificaciones ni locks por conteo.'],
 ['F0-02','Longitudinal enrollment','P3','MEASURED-HOT',f(sv('P3',httpenv))+' ms','Mayor HTTP medido; DB '+f(med(x['pfs']['ms'] for x in http if x['scenario']=='P3'))+' ms','20 repeticiones locales','NEEDS-EQUIVALENCE-PROOF','Revisar composición del alta y planificación; exigir equivalencia de journal, intents, ACK y snapshots.'],
 ['F0-03','Client logical queue','change + Next','MEASURED-HOT',f(med(x['logic_debounce_ms'] for x in queue))+' ms debounce','Cola con retención artificial de 100 ms: '+f(med(x['queue_ms'] for x in queue))+' ms','20 repeticiones; delay intencional','NEEDS-EQUIVALENCE-PROOF','Determinar en browser si el debounce afecta al recorrido antes de proponer cambios; mantener semántica actual.'],
 ['F0-04','Transport','delayed ACK','MEASURED-HOT','100–8000 ms inyectados','Demora impuesta, no red real','3 repeticiones por demora + warmup','OUT-OF-SCOPE','Sin timeout automático ni cambio de reintento. Medir hosting/red real segura antes de atribuir lentitud de producción.'],
 ['F0-05','Native opening renders','P4','MEASURED-MINOR',f(rendermedian)+' ms total de 3 renders','Sólo DOM simulado; no comparable como share de PHP','20 repeticiones','NEEDS-EQUIVALENCE-PROOF','Deferir consolidación; no parece el principal coste en este fixture.'],
 ['F0-06','JSON/clone','50 questions','MEASURED-MINOR',f(med(x['clone_ms'] for x in extra if x.get('questions')==50))+' ms clone','186968 bytes de fixture total, no request real','20 repeticiones','NEEDS-EQUIVALENCE-PROOF','No priorizar eliminación de copias o reducción del contrato por este resultado.'],
 ['F0-07','Authored images','image load','STRUCTURAL-RISK','NA','Geometría y red no medidas','Inspección de markup','SAFE-CANDIDATE','Reservar dimensiones sólo si un caso con browser y contenido real descartable demuestra CLS; no cambio global.'],
 ['F0-08','Dense matrix','3/5/7/11 options','CANNOT-MEASURE','NA geometría','Construcción sí; overflow/targets no','20 repeticiones por combinación de options/labels','SAFE-CANDIDATE','Ejecutar matriz browser 320/360/390/desktop antes de decidir breakpoint.'],
 ['F0-09','Manual date input','3 formats','MEASURED-MINOR','0.25–0.36 ms por secuencia','No teclado físico ni paint','20 repeticiones/formato','SAFE-CANDIDATE','Sin optimización de parseo; requiere confirmación humana/dispositivo para teclado.'],
 ['F0-10','Assets','public shell','STRUCTURAL-RISK','161413 bytes JS Native','Gzip estimado 42828 bytes; sin waterfall real','HTML de 20 repeticiones','SAFE-CANDIDATE','Verificar compresión/cache en hosting y cobertura CSS antes de proponer partición de bundles.'],
 ['F0-11','Default feedback','click to pending DOM','NOT-A-PROBLEM','<1 ms mediana en delays','Paint no medido','3 repeticiones por demora','OUT-OF-SCOPE','No añadir nuevas pantallas ni cambiar etiquetas por esta evidencia.'],
]
with (out/'hotspots.csv').open('w',newline='') as h:
 w=csv.writer(h);w.writerow(['id','component','operation','evidence_class','median_cost','relative_cost','reproducibility','candidate_class','recommendation']);w.writerows(hotspots)
report=f'''# UX-F0 Performance and Runtime Profiling

Fecha: 2026-11-05. Alcance participant-facing del worktree local; UX-F1A y UX-F1B aprobadas humanamente. Esta fase no implementa optimizaciones.

**UX-F0 performance and runtime profiling completed — evidence ready for optimization selection.** El cierre corresponde al alcance medible; layout/paint/CLS y dispositivos permanecen explícitamente sin medir.

## A. Executive summary

La evidencia del fixture apunta a composición de operaciones PHP/DB y esperas de transporte/cola antes que a parseo numérico/fecha o serialización de objetos pequeños. La transición concatenada P10 cuesta **{f(sv('P10',ownerenv))} ms** de owner local, con **{f(med(x['db_ms'] for x in owner if x['scenario']=='P10'))} ms DB** y {f(count('P10'))} sentencias. El alta longitudinal P3 cuesta **{f(sv('P3',httpenv))} ms HTTP**, de los cuales {f(med(x['pfs']['ms'] for x in http if x['scenario']=='P3'))} ms son DB. Son costes relativos a este stack, no tiempos de producción ni prueba de que esas consultas sean innecesarias.

La apertura simple ejecuta tres renders, con **{f(rendermedian)} ms** totales de render en jsdom. El clone de un fixture de 50 preguntas tarda alrededor de **0,61 ms**. No hay base para priorizar eliminar renders, copias o campos científicos antes de investigar las operaciones mayores.

No se encontró navegador ejecutable ni en host ni en el contenedor Node. **Paint, layout real, CLS, waterfall, long tasks del browser, teclado físico y geometría móvil: CANNOT-MEASURE**. Se dejaron scripts; no se descargaron dependencias pesadas. No se midió producción ni se accedió a participantes reales.

## B. Baseline integrity

Worktree inicial registrado en `UX-F0-evidence/preflight-worktree.txt`; último commit observado `9a6501c`. Se conservaron los cambios no comiteados de fases anteriores. El delta posterior al último FULL previo a esta tarea es la limpieza de errores visibles en `product-runtime.js`, sus bundles generados, una regresión JS y el conteo 4191. `post-last-full-change.diff` reconstruye ese delta de fuente a partir del bloque anterior documentado; no es un checkout del filesystem histórico completo. `preflight-product-runtime-worktree.diff` muestra el delta acumulado contra HEAD, que incluye otras fases y no debe confundirse con ese último ajuste.

Preflight: build + check-build + targeted fecha/número (14), UX-F1A (53) y UX-F1B (57), todos verdes. FULL baseline **4191 = 978 JS + 539 PHP puros + 2669 PHP WordPress + 5 multisite**, RCT estricto, smoke, instalación, upgrade y lifecycle verdes. Evidencia: `.cache/ci/20261105-004108-c6ad70cf`, 412,05 s, salida 0. No se iniciaron mediciones antes de ese PASS.

La referencia científica `tests/ux-f1a/before-contract.json` no se regeneró. `equivalence.json` registra su SHA-256 y comparación exacta con instrumentación. `product-before.json` fija hashes de src/includes/assets/admin/templates/lib/build; se verifica que no hayan cambiado durante esta fase. FULL final **4191 casos, salida 0**, `.cache/ci/20261105-005905-b6771390`, 397,13 s. Nueve invariantes instrumentales y cuatro checks de distribución aprobados; no se suman a los 4191 casos históricos. Hashes productivos antes/después idénticos. Detalle y limitaciones en `UX-F0-evidence/verification.md`.

## C. Methodology

Relojes monotónicos: `performance.now()` en Node/V8, `window.performance.now()` en jsdom y `hrtime(true)` en PHP. Los timestamps funcionales de fixtures no se utilizan para duraciones ni se modificaron en el producto.

Cada serie principal tiene un warm-up excluido y **N=20** muestras medidas. Se reportan mediana, p75 y p95 empíricos interpolados; con N=20 el p95 es exploratorio, no una estimación robusta de cola de producción. Los cinco delays tienen un warm-up y **N=3** cada uno; no se les atribuye p95. Mínimo/máximo quedan en `summary.csv`. No se usa promedio como conclusión.

Instrumentación JS: copia efímera del source bajo `.cache/ux-f0/instrumented`, con `try/finally` alrededor de render/restore/request/showPending y contadores externos de widget/metadata/copy. No se escribió source productivo. PHP: wp-config y MU helper dentro de un volumen descartable, SAVEQUERIES y MariaDB Performance Schema agregado por hilo desde una conexión observadora separada. No se instalaron APM ni telemetría de participantes. Los helpers sólo se ejecutan con opt-in de test; el ZIP no los incluye.

Tiempos anidados **no se suman**: render contiene construcción de widgets; request contiene snapshot/copy; PHP contiene DB. La respuesta HTTP y el owner CLI se publican por separado. El muestreo de feedback mide mutación DOM síncrona, no momento de paint.

## D. Environments

- Synthetic/local: Node 22.23.3 fijado por digest, jsdom existente, sin red; fixtures y reloj funcional deterministas del soporte UX-F1A. No es un browser.
- Local WordPress: instalación nueva WordPress 7.1.2, PHP 8.3.35, MariaDB 11.8.9, Apache/HTTP interno Docker, WP_DEBUG de test, sin cron automático ni SMTP real. Performance Schema está habilitado sólo en este proyecto de profiling.
- Hardware visible en Node: AMD Ryzen 7 9700X, x64, 16 CPU lógicas. Host compartido; no se fija afinidad ni se calibra overhead. Registro en `tooling-environment.log`.
- Browser: intento real de ejecución terminó con código 2 por ejecutable ausente; `browser-attempt.log`.
- Production-like/producción: no disponibles para esta tarea; sin datos ni acceso a instalación institucional.

P1 «cold» significa primera petición de HTML a un estudio recién publicado en el fixture. No implica reiniciar PHP/opcache, cache del SO ni red. P2 repite HTML con cookie. P1-json/P2-json son rutas auxiliares para iniciar la prueba HTTP; no se mezclan con HTML. Cada repetición crea fixtures independientes donde hace falta una acción destructiva o una primera participación.

## E. Measurement limitations

El overhead de instrumentación no fue calibrado; las cifras son locales e instrumentadas y no se convierten en SLA. No existen medidas de A input físico→handler, K paint estable, salida/retorno de red separados, TLS, dispositivo, IME o CLS. Se marcan NA. El residual curl−PHP incluye Apache, instrumentación, copia y transferencia local; **no es latencia pura de red**. No se suman latencias Node y PHP como si pertenecieran a una única navegación.

P14/P15, P10, P18 y P6 están medidos como owners reales contra MariaDB; carecen de red/HTML/paint final en esa serie. La ruta HTTP de navegación Native sí se ejerce en P4/P7/P8/P9/P12/P13. El fixture de Native usa asignación WordPress autenticada; el flujo longitudinal usa su cookie real. La concatenación P10 usa NativeBridge real y verifica que cambia de evaluación.

SAVEQUERIES captura sólo una parte: el driver transaccional usa `mysqli_query` directo. Por ello la evidencia final utiliza **Performance Schema por conexión** para tiempo/count DB completo. `wpdb_logged_queries`, `wpdb_db_ms`, duplicados exactos y callers describen únicamente el subconjunto wpdb. PFS cuenta también sentencias físicas de inicialización de conexión; no debe equipararse uno a uno a `$wpdb->num_queries`.

Se conservaron sólo agregados de profiling: sin valores de respuestas, emails, tokens, URLs de acceso ni textos SQL. Las consultas y los payloads de fixtures sólo existen transitoriamente en memoria para ejecutar/verificar los caminos. No se recogieron participantes reales.

## F. Participant operations

Series HTTP reales; tiempos en ms. La columna PHP contiene DB y no se suma con ella.

{httptable}

Series de owner/función; no comparar el total con un request completo ni con paint:

{ownertable}

Cliente + DOM simulado:

{clienttable}

Cobertura crítica: P1 cold public HTML; P2 warm; P3 alta longitudinal; P4 apertura Native; P5 respuesta simple; P6 respuesta con condition/projection del motor; P7 Next; P8 Back; P9 submit; P10 concatenación; P11 retorno por email/cookie válido; P12 reload y caso adicional expirado; P13 fallo validación; P14 confirmación mismo email; P15 corrección; P16 lookup abandono; P17 confirmación abandono; P18 portal resuelve evaluación disponible. P5 tiene cero llamadas de red en esa interacción, no cero coste PHP supuesto. Los pasos preparatorios quedan fuera del intervalo medido. Las peticiones Native HTTP llevan metadata mínima válida del harness; la serie JS de 1/20/50 preguntas cubre crecimiento detallado de metadata por separado. No se extrapola ese fixture HTTP a una evaluación larga ya respondida.

## G. Client queue/preprocessing

Respuesta simple P5: {f(sv('P5',jsenv))} ms de mediana JS/DOM, sin request ni reconstrucción completa. La edición no permite concluir nada de input-to-paint en un dispositivo.

En 20 repeticiones, change→inicio del transporte logic mide **{f(med(x['logic_debounce_ms'] for x in queue))} ms**, consistente con el debounce existente de 250 ms. Al pulsar Next mientras logic queda retenido 100 ms, la cola hasta iniciar Next mide **{f(med(x['queue_ms'] for x in queue))} ms**; feedback síncrono {f(med(x['click_feedback_sync_ms'] for x in queue))} ms. No son 100 ms de cómputo del motor: se inyectó esa espera expresamente. Heartbeat invocado durante busy no genera otra petición flush; Next sale después de logic. No se cambió debounce, frecuencia ni cola.

Serialización explícita, N=20 por tamaño. Bytes de un objeto fixture con definition+answers+touched+metadata, **no** request real de ese tamaño:

{sertable}

El fixture de 50 preguntas dedica 102225 bytes a metadata; reducirlo cambiaría el contrato y no está justificado por un stringify de ~0,35 ms. `payloads.json` y `server-payloads.json` dan tamaños separados, sin guardar los valores.

## H. Network

{waittable}

Estos son delays sintéticos, no una captura de red. `ack_ms` mide la resolución del transporte de test. `settled_ms` es observación posterior con espera de muestreo (~15 ms extra), no ACK exacto ni paint. Los botones quedan disabled y host busy inmediatamente; el feedback se produce antes de resolver el transporte. No existe timeout nuevo.

Desglose de payload HTTP del fixture, N=20 por operación:

{wiretable}

Los tamaños de componentes son JSON lógico, no la contribución URL-encoded exacta a la red. Session contiene metadata y no se suma con ella. Cero indica campo ausente en ese envelope, no ausencia de un recurso posterior del iframe. `payload-sizes.csv` contiene más campos, incluidos answers y compresión estimada. Después de TTFB es transferencia/recepción restante local; no una medida aislada de internet.

Respuesta retenida: se observa un único submit, controles bloqueados y heartbeat no superpuesto antes de liberar la respuesta. Fallo antes de respuesta y fallo tras posible commit: el reintento conserva exactamente el payload completo, incluido command_id, answers y metadata. El mock no certifica idempotencia SQL; esa garantía se contrasta con las suites existentes del FULL.

Compresión de requests/respuestas y assets: tamaños gzip calculados **como estimación**, no bytes negociados en una captura browser. El HTTP interno medido no permite inferir TLS, CDN, cache institucional ni rendimiento de internet.

## I. PHP

El coste mayor aislado es P10; P3 es el request HTTP mayor. P14/P15 cuestan alrededor de 20–21 ms de owner y no muestran una explosión por cambiar el email en este fixture. P13 inválido se devuelve correctamente como HTTP 400 y se mide aparte de una respuesta válida.

Partición por muestra de tres operaciones, con fracciones de medianas aproximadas (pueden no sumar exactamente 100%):

{parttable}

La frontera bootstrap se toma antes del handler público/admin_init; incluye core/plugins hasta ese punto, no sólo EIPSI. La ejecución PHP completa comienza al cargar el wp-config temporal, por lo que excluye una pequeña parte inicial de Apache/PHP. El profiler agregado se consulta después de fijar el final PHP: su coste queda principalmente en el residual HTTP, no en el driver de producto. Pico de memoria por request disponible en `queries.csv`; no es un leak ni heap JS.

## J. Database

P10: **{f(count('P10'))} sentencias, DB {f(med(x['db_ms'] for x in owner if x['scenario']=='P10'))} ms**. P3: **{f(med(x['pfs']['count'] for x in http if x['scenario']=='P3'))} sentencias físicas de mediana**, DB {f(med(x['pfs']['ms'] for x in http if x['scenario']=='P3'))} ms. P11: **{f(med(x['pfs']['count'] for x in http if x['scenario']=='P11'))} sentencias de mediana**, DB {f(med(x['pfs']['ms'] for x in http if x['scenario']=='P11'))} ms. Los recuentos del subconjunto wpdb son muy inferiores, y atribuir sólo esos tiempos a DB habría sido incorrecto.

Los duplicados exactos del subconjunto wpdb y los callers están en `queries.csv`. No se interpreta una lectura repetida como redundante: puede ser revalidación tras un lock o una escritura. No se recogió SQL directo en texto/digests, de modo que no se puede dar una lista completa de consultas repetidas ni recomendar un índice concreto.

PFS permite contar begin/commit por request en `server-http.jsonl`; el código conserva los límites transaccionales del owner. Esta ejecución es secuencial: **lock waits bajo concurrencia no medidos**, no se simula que sean cero. No hay evidencia suficiente de missing indexes o de una query individual responsable del tiempo completo. El slowest_query de wpdb es sólo un límite inferior de cobertura, no el máximo de todas las consultas.

## K. Scientific engine

Comparación de aperturas nuevas de engine: simple {f(sv('ENGINE-simple',ownerenv))} ms; una condition {f(sv('ENGINE-branch',ownerenv))} ms; randomization {f(sv('ENGINE-random',ownerenv))} ms; 30 reglas ordenadas {f(sv('ENGINE-complex',ownerenv))} ms. Los fixtures difieren en estructura, por lo que no se atribuye toda la diferencia exclusivamente a conditions. El ensayo complejo mide apertura; no es una afirmación sobre 30 respuestas sucesivas ni todas las topologías posibles.

`StudyVersionRepository::compiled()` **no recompila** el protocolo publicado: decodifica, valida hashes/contrato y reconstruye CompiledStudy. En el fixture de 30 reglas cuesta {f(sv('REPO-reconstruct',ownerenv))} ms sin queries. JSON encode+decode PHP aislado cuesta {f(sv('SER-json',ownerenv))} ms. Es una señal de coste de verificación/reconstrucción, no autorización para omitir integridad ni cachear estado mutable.

## L. Restore

Retorno por email/cookie a participación válida (P11): {f(sv('P11',httpenv))} ms HTTP. Reload de Native válido (P12): {f(sv('P12',httpenv))} ms HTTP. Sesión Native expirada (P12-expired): {f(sv('P12-expired',httpenv))} ms HTTP y se verifica session_reset. Se preservan las políticas actuales de reinicio/parciales; no se añadieron anclas.

Reconstrucción + render Node/jsdom: P11 {f(sv('P11',jsenv))} ms, P12 {f(sv('P12',jsenv))} ms. Son otro entorno; no se suman al HTTP anterior. Same-tab sin cambio de página se observó mediante input y heartbeat/queue; no existe una medida física de voltar a una pestaña, foco, scroll real o paint. Reopened URL válida se aproxima por el GET real de Native con la cookie ya existente; no se simuló restauración de un navegador cerrado completo.

## M. Rendering/DOM

Apertura simple: tres invocaciones por repetición, total mediano {f(rendermedian)} ms; medianas por ordinal {', '.join(f(med(r['invocations'][i] for r in renders))+' ms' for i in range(3))}. Los dos primeros outputs se descartan posteriormente. Se construyen tres widgets en ese fixture de una pregunta visible. El trace registra nodos de subárboles de widgets creados y elementos descartados (no es un contador completo de todas las asignaciones DOM) y dos restore/presentation invocations; **no se debe eliminar un restore por contar renders**, ya que la presentación/metadata está asociada a la reconstrucción de sesión.

La identidad de ejecución/versión/página de la apertura permanece igual; la revisión cambia tras el ACK. No se publican sus IDs. V8 sampling del workload de 30 ciclos (20 textos+matriz) confirma coste de DOM simulado, CSSStyleDeclaration, GC y copias; los porcentajes de `cpu-samples.json` son muestras del workload sintético, no flamegraph browser ni una auditoría general de memoria. No se atribuye el GC del fixture a un leak del producto.

No se detectaron modificaciones instrumentales de metadata, commands, responses, randomization, conditions o navigation contra la referencia. Layout reads/writes de VAS/iframe/observers se inspeccionan como riesgo estructural; su coste real no se infiere de rectángulos vacíos de jsdom. Cleanup de observers tiene cobertura funcional previa, pero no se midió detached DOM real.

## N. Assets/images

{assettable}

Las páginas públicas observadas no cargan `assessment-editor.js`, `study-builder.js`, wp-admin, Gutenberg ni theme assets. El nombre `assessment-editor.css` corresponde a una hoja compartida que contiene estilos de editor y participante; **no prueba por sí solo que se cargue el editor JS**. La partición del participante reutiliza módulos de modelo/validación/metadata, legítimos en ese contexto. No hay duplicación de un mismo src/href dentro de los documentos del fixture; solicitudes repetidas en documentos/iframes independientes no prueban duplicación indebida y la cache browser no está medida.

Imágenes: los fixtures HTTP principales no contienen imágenes participant-facing descargables; tamaño de recurso, cache hit/miss, decode y load timing **NA**. El widget image emite src/alt y max-width/height:auto, sin width/height explícitos. La imagen de finalización tiene el mismo riesgo. El rich text puede incluir imágenes con atributos propios; requiere inventario del contenido específico. Clasificación: **potential CLS / STRUCTURAL-RISK**, sin CLS observado ni prueba de heavy payload. No se modifica markup global.

## O. Matrices/date controls

Fixtures con 3/5/7/11 opciones, labels cortos/largos, construcción DOM real del widget y 20 repeticiones por combinación:

{mattable}

El script opcional de browser cubre 320/360/390/1440 px. Sin ejecutable, available width, cell width real, wrapping, overflow, rendered height, líneas y target size permanecen **NA**, no se inventa un umbral de densidad por una simple división de ancho.

Entrada manual de fecha: widget text con inputmode numeric, parseo al ISO del contrato, tres formatos y calendario con selector de año existente. Se introducen diez prefijos y se valida cada paso:

{datetable}

Esto respalda que el parseo CPU no es costoso en el fixture; no valida el teclado de iOS/Android ni garantiza fluidez en otro hardware. No se cambiaron inputmode, formato ni validación.

## P. Layout/paint

CANNOT-MEASURE: no navegador ejecutable. First content presentation, interactive state de browser, paint, CLS, imagen decodificada, scroll físico, safe area/teclado e interacción real con matriz no tienen números. Los scripts no equivalen a evidencia visual. No se confundió `view.ready` con first paint; sólo es un hito de ACK/restauración del runtime.

## Q. UX-01 verdict

**defer**. Tres renders están confirmados, pero el coste conjunto es {f(rendermedian)} ms en el fixture Node, frente a operaciones server mayores en su propio entorno. No hay prueba de layout/paint costoso. Consolidarlos puede acoplarse a restore/metadata: NEEDS-EQUIVALENCE-PROOF. No se justifica como primera optimización con esta evidencia.

## R. UX-04 verdict

Separar cómputo local (operaciones en decenas de ms, concatenación {f(sv('P10',ownerenv))} ms), demora de transporte impuesta (100–8000 ms), estado ambiguo de request (pending + payload idéntico) y feedback (mutación DOM síncrona sub-ms mediana; paint NA). La red/hosting real no fue medida. **No introducir timeout automático** ni modificar ACK/retries como conclusión de profiling.

## S. UX-07 verdict

En el fixture, el retorno P11 tiene más coste server/DB que la reconstrucción JS simple; no se demuestra que el problema principal sea DOM. Viewport/percepción real no medidos. Prioridad: investigar composición del camino de recuperación/lecturas si se selecciona optimización; no añadir anclas o políticas nuevas. SAME-TAB, reload y expiración tienen alcances distintos y no se fusionan en una única cifra.

## T. UX-09 verdict

**Insufficient evidence de coste o CLS real**. Sólo riesgo estructural por dimensiones no reservadas en widgets image/final. No hay imagen pesada demostrada en estos fixtures. No aplicar width/height global automáticamente.

## U. UX-11 verdict

**MEASURED-MINOR** para parseo/validación manual en Node; **requires human/device confirmation** para teclado y calendario móvil. Las correcciones de fechas ya aprobadas no se reabren por intuición ni se cambia inputmode.

## V. UX-12 verdict

**CANNOT-MEASURE el umbral real de densidad** sin layout browser. El coste de construir la matriz aumenta de forma acotada en esta prueba, pero ni número de nodos ni ms de jsdom indican si una etiqueta se solapa. Ejecutar fixtures de viewport antes de elegir breakpoint.

## W. UX-19 verdict

Top contributors respaldados en el entorno local: (1) composición PHP/DB de P10/P3/P11; (2) espera de transporte cuando existe (ensayo controlado, no red institucional); (3) cola/demora lógica bajo condiciones concretas; (4) reconstrucción/DOM/serialización simples, menores aquí. Transferencia inicial de assets y layout real siguen sin atribución temporal.

No existe una cifra única de “latencia del plugin” ni un waterfall end-to-end real. `latency-breakdown.csv` separa segmentos por escenario/entorno con NA explícitos. Relevancia de producción: DB/PHP dependen de hosting, complejidad y concurrencia; render depende del browser/dispositivo; red depende de conectividad/cache/compresión; las condiciones y el payload dependen del protocolo.

## X. Ranked bottlenecks

`hotspots.csv` contiene clasificación MEASURED-HOT / MEASURED-MINOR / STRUCTURAL-RISK / NOT-A-PROBLEM / CANNOT-MEASURE y candidatura independiente. Los mayores costes locales reproducidos son P10, P3 y P11. Un conteo alto de consultas no demuestra redundancia, y los resultados no justifican eliminar checks de integridad.

Tier 1 (alto impacto + SAFE-CANDIDATE): **ninguna optimización demostrada todavía con ambas propiedades**. Los mayores candidatos pueden tocar ciencia/transacciones y necesitan equivalencia. Esto es una conclusión válida del profiling.

Tier 2, lista pequeña para selección posterior:
1. Investigar composición de P10 y P3, capturando frecuencia de reconstrucción/lecturas dentro de la misma operación. NEEDS-EQUIVALENCE-PROOF; preservar snapshots, locks, intents, ACK y journal. La medición justifica investigarlo, no una implementación específica.
2. Validar en browser si la cola lógica y assets afectan a percepción/primer contenido. Debounce actual tiene coste observable pero finalidad funcional; no es un bug confirmado.

Tier 3: renders redundantes, clone/JSON y parseo de fecha simple; no prioridad con los números actuales.

## Y. Rejected hypotheses

- «Tres renders son necesariamente el cuello principal»: no respaldado por el fixture; deferir.
- «Serialización es la causa dominante»: no respaldado en los tamaños medidos.
- «Randomization siempre es cara»: no respaldado por simple vs random locales; no extrapolar a protocolos enormes.
- «Si llega a 8 s es PHP»: falso en el ensayo; se inyecta transporte, no cómputo.
- «SAVEQUERIES mide todo el motor»: rechazado por inspección y recuentos PFS.
- «assessment-editor.css implica que se carga el editor completo»: no respaldado por el HTML observado.
- «No hay CLS/matrices densas porque tests DOM pasan»: no evaluable sin layout real.
- «Hay que cachear todo o agregar índices»: no hay evidencia individual suficiente y podría romper integridad.

No se definen budgets arbitrarios. Antes de fijar límites numéricos hacen falta distribuciones en el browser/hosting objetivo y varios tamaños de estudio; este baseline sirve como referencia reproducible, no como SLA universal.

## Z. Recommended next optimization phase

La evidencia está lista para **seleccionar**, no para implementar automáticamente, una fase posterior de optimización basada en evidencia. Propuesta: limitarla a composición de P10/P3 y sólo después de pruebas de equivalencia del contrato; opcionalmente completar medición browser en dispositivos disponibles. No se abre ni implementa esa fase en esta tarea.

Instrumentación removible en `tests/ux-f0`; artefactos sanitizados en `UX-F0-evidence`. No source productivo modificado, no telemetría, no datos reales, no commit/push/tag/release/deploy. Cierre técnico y hashes finales en `verification.md`.
'''
(reports/'UX-F0 Performance and Runtime Profiling.md').write_text(report)
print('PASS report A–Z and evidence-ranked hotspots generated')
