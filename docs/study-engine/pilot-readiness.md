# Study Engine — pilot readiness (P1-I)

**CONDITIONAL GO para un piloto interno controlado, sólo después del checklist operacional y smoke humano. P1-I continúa abierto.** El responsable aplazó el smoke humano de Builder/participante el 2026-10-09; no se sustituye por HTTP/DOM automatizado. No hay autorización de reclutamiento ni deploy global/institucional.

## Estado y entorno probado

P1-A–H integrados: contratos/compiler/simulator puros, ExecutionOwner transaccional, contenido snapshot, acceso longitudinal, outbox/worker, privacidad/export y Builder. Legacy Forms/Study/Waves conserva sus rutas y datos; no existe migración automática ni deprecación nueva.

FULL inicial1919; FULL final **1944 únicos =1810 PHP +134 JS**, exit0,232.39s. Incremento25:10 pilotos,5 regresiones de canje y10 operacionales. Incluye RCT estricto, build reproducible, suites legacy/P1-B–H, schema clean/upgrade/repair, smoke y activación/lifecycle. Los probes de restore/upgrade/volumen no inflan este total.

| Componente | Evidencia real |
|---|---|
| WordPress |7.1.2; mínimo declarado5.8 sin certificar en esta fase |
| PHP |8.3.35 DB/HTTP;7.4 lint78 archivos y359 pruebas puras |
| MariaDB |11.8.9-MariaDB-ubu2404; MySQL no ejecutado |
| Node |22.23.3, imagen fijada por digest del gate |
| OS |host Linux; WordPress Debian13.7 trixie |
| Schema |migration14; schema fingerprint en environment.json |
| Theme/plugin del fixture |Twenty Twenty-Five; sólo EIPSI Forms activo |
| Navegador humano |ninguno; desktop768/narrow/keyboard pendientes |
| Automatización |HTTP real dentro Docker y DOM JSDOM, sin certificación visual |

El inventario de archivos del contenedor de desarrollo mostró Twenty Twenty-Three/Four/Five; no acredita cuál está activo ni compatibilidad visual. No se cargó ese WordPress, no se leyó SMTP ni se tocó su DB. No se certifican extensiones externas ausentes del fixture.

## Matriz del piloto automatizado

Todos los estudios se originan en API administrativa Builder create/save/import/validate/simulate/publish/activate. Assessment se importa desde posts Form Library; no se insertan StudyVersionDefinition/Published directamente ni se usan fixtures para construir los pilotos. Fixtures previos siguen oráculos del motor en FULL. Runtime/Storage/export son reales del fixture; correos capturados, reloj controlado para longitudinal. Esto no es smoke humano.

| Área | Configuración | Runtime | Recovery | Export | Privacy |
|---|---|---|---|---|---|
| Transversal anónimo |contenido, required/optional, PageBreak |partial/next/submit |refresh/reopen finished, una execution/journal |3 filas, IDs/hash |boundary legacy verificada por suitesG |
| Consent/branching |semantic_role, TERMINATE/JUMP |decline y ruta skip |reentrada coherente |8 filas de dos recorridos, provenance |terminate no withdrawal |
| Pre/post |Post+24h Pre completion |clock, aviso y enlace Post |sesión verificada |2 occurrences |política comúnG |
| Diary |7 slots de una definición |responder/expirar/continuar |clock/reload |7 identities |política comúnG |
| EMA |5×7 |preview/runtime35,70 reminders |sesión/clock |35 identities |probe densoB2 |
| RCT |Macro WEIGHTED_RANDOM A/B |sólo arm seleccionado, avisos filtrados |refresh sin redraw |arm y NOT_SCHEDULED |política comúnG |
| Longitudinal/internal |Micro select2/3 + PageBreak |orden persistido, multi-device |partial/CAS/login/logout suitesF |non-selected no missing |sesiones por propósito |
| WAIT |E1→E4 resume Micro |E2/E3 skipped, avisos cancelados |enlace viejo muestra WAIT, E4 resume |skip provenance |sin bypass por link viejo |
| Nested Macro |Macro→Macro→Assessment |traversal real |reload coherente |jerarquía manifest |política comúnG |
| B1/B2 |dos participantes email |retain/do-not-use por shell/POST |old access bloqueado, cleanup retry |B1 retained/B2 excluido inmediato |COMPLETE_LOCAL |

V1/V2 adicional: A pinneadoV1, B enV2; misma URL; retireV1 conserva hash/ejecución/export combinado. NotificationIntent se vincula a versión a través de Execution, no tiene columna study_version_id propia.

## Corrección del canje y riesgo de escáner

P1-I-01, P1 confirmado y corregido: antes, scanner GET→303 consumía ACCESS y otro navegador→403. Ahora GET valida y coloca la capability en cookie pendiente HttpOnly/SameSite=Lax (Secure bajo HTTPS), con expiración de navegador600s; redirige a URL limpia `eipsi_confirm=1`. GET de confirmación no consume ni verifica. HTML no contiene bearer. POST “Continuar” exige cookie/CSRF/Origin y canjea atómicamente; elimina pendiente y redirige al estudio. Uso único/replay con sesión y propósitos restringidos se conservan.

El TTL criptográfico/server-side sigue siendo el de la capability original, máximo48h;600s es duración de la cookie del navegador, no otro TTL científico. Un scanner que deliberadamente envíe formularios aún puede consumir un bearer. El destinatario debe disponer del enlace; conocer email no autentica una execution existente. El primer request puede aparecer en logs del servidor: redactar query strings; no afirmar que redirect borra logs previos.

## Semántica operacional

OPEN admite y entrega avisos elegibles. PAUSED bloquea nuevos ingresos, conserva sesiones existentes y pospone delivery sin consumir retries; no congela deadlines. CLOSED bloquea nuevos ingresos y nuevas entregas elegibles según Worker; no borra executions ni reescribe protocolo. Los participantes existentes siguen sometidos a ventanas originales. Retire impide nuevas admisiones a esa versión; executions pinneadas continúan. Publicar no activa automáticamente.

Cron deshabilitado retrasa mail/catch-up, pero access/submit aplican el reloj científico y rechazan respuestas fuera de ventana. Al restablecer cron, el worker avanza executions y revalida intents; no envía oportunidades expiradas/canceladas. Lease/clave lógica evitan procesamiento simultáneo y reenvío de SENT. No hay exactly-once SMTP después de aceptación remota y caída antes de persistir.

## Secrets y rotación

| Material | Fuente/almacenamiento | Backup/rotación/fallo |
|---|---|---|
| Seed vault |EIPSI_STUDY_ENGINE_KEYRING en wp-config/entorno; DB guarda key_id+ciphertext AES-GCM |Añadir nueva key/current, retener viejas mientras haya executions. Sin vieja key: fail closed, nunca redraw |
| Access/withdrawal/preferences signing |EIPSI_STUDY_ENGINE_ACCESS_KEYRING independiente; DB sólo hash/scope/key_id |Retener viejas durante TTL o revocar deliberadamente. Pérdida niega canje; restaurar key o emitir nuevo enlace autorizado |
| Recovery sesiones |token aleatorio cliente HttpOnly, hash DB |Backup DB; cookies de dispositivos no se reconstruyen desde hash. Nuevos enlaces pueden recuperar tras restore con keyring |
| Cookie pendiente |capability firmada en HttpOnly corta duración |No guardar en reportes/logs/analytics; queda cubierta por TTL/uso único original |
| Email tombstoneB2 |HMAC Study+email, wp_salt(auth), fingerprint de key en DB |Conservar auth salts con backup. Rotación detecta mismatch y bloquea admisión de todo Study afectado |
| CSRF/abuse |wp_salt(nonce) y derivación vinculada a token |Rotación invalida formularios; recargar/reautenticar, no deshabilitar CSRF |
| SMTP |configuración del proveedor fuera del protocolo |No exportar ni imprimir. Backup cifrado con permisos restringidos |

No se incluye ningún valor secreto real en evidencia. No existe migración dedicada de tombstones: después de eliminar email no se pueden recalcular HMAC con una key nueva. Si auth salt se perdió, no forzar admisión ni borrar tombstones: recuperar secreto previo o resolver la política institucional mediante intervención auditada. Restaurar salts antiguos puede afectar otras sesiones WordPress; coordinar el mantenimiento del sitio completo. No rotar casualmente esa dependencia.

## Backup, restore, upgrade y rollback

Backup consistente: DB completa con InnoDB/single-transaction, código/build exactos, uploads/snapshots de media y configuración/keyrings/salts custodiados por separado. Detener publicación/ingreso/worker durante la ventana de corte cuando se requiera consistencia entre DB y filesystem. Registrar versión/digests/schema y probar restore antes de admitir participantes.

Se ejecutó dump de DB sintética y restore en segundo proyecto Docker. Restaurar configuración/salts y proveer los mismos keyrings de prueba permitió comparar snapshot/export/random por hash, canjear credencial y cargar Published en Builder. El código se montó desde el mismo árbol congelado; no fue backup integral de filesystem institucional ni prueba de media binaria. Conservar uploads y snapshot directory es obligatorio: DB sola no basta.

| Upgrade | Resultado | Límite |
|---|---|---|
| Fresh install |FULL + Builder→participante→export |fixture actual |
| v13→v14 reconstruido |columna nullable removida; fallo SQL dejó marker13; retry convergió14 y preservó legacy |no old-binary snapshot |
| Pre-engine v10→14 reconstruido |sin tablasEngine; migración creó schema; Builder/runtime/export posteriores pasan |legacy de fixture actual, no certificación de release histórica |
| Pre-M8 |FULL M8 prueba shapes anteriores, interrupción/concurrencia/retry |sin instalación histórica completa archivada; gap institucional explícito |
| Schema drift |FULL repara nullable/index; detecta MyISAM y rechaza payload/type unsafe |no recompilar/reparar ciencia corrupta |

Rollback: cerrar ingresos/pausar entregas, guardar incidente y snapshot de estado, decidir restauración de conjunto DB+código+media+secrets compatible. No ejecutar código antiguo sobre schema/data nuevos asumiendo compatibilidad. Restaurar backup anterior puede perder ingresos/respuestas posteriores: reconciliar con responsable y política de datos antes de hacerlo. No downgrade automático ni eliminación de executions nuevas. Preferir fix forward si conserva datos.

## Failure matrix

| Failure | Datos científicos | UX participante | Retry | Acción admin |
|---|---|---|---|---|
| DB unavailable |sin éxito confirmado |503 genérico |tras recuperar DB, mismo command ID |restaurar DB/InnoDB; inspeccionar logs |
| Storage failure |owner/journal no confirman falso éxito |error/reintento |mismo comando |revisar storage/journal; no insertar respuestas a mano |
| Missing seed key |sin redraw |operational error |tras restaurar key |recuperar key_id exacto |
| SMTP failure |evaluación persiste |acceso actual sigue; mail demorado |outbox/queue retry |corregir proveedor, inspeccionar FAILED_RETRYABLE/FINAL |
| Cron down |deadlines siguen |espera/expiración correcta |catch-up al volver |restaurar cadence, revisar backlog |
| Corrupt protocol/graph/hash |fail closed, sin recompilación |503/error |no retry ciego |restaurar backup correcto, comparar hashes |
| Corrupt checkpoint |sin overwrite |operational error |sólo tras reparación autorizada |backup/restore, conservar evidencia |
| Stale revision |sin duplicado/sobrescritura |409 recargar estado |formulario vigente |explicar pestaña/dispositivo |
| Missing media |sin reemplazo silencioso |contenido no disponible |tras recuperar snapshot |restaurar archivo/hash/original requerido |
| Cleanup failureB2 |exclusión analítica inmediata |outcome pendiente |owner cleanup idempotente |revisar coverage/relation/engines, retry |
| Expired capability |sin reabrir oportunidad |acceso denegado |pedir nuevo enlace |no extender deadline ni bajar autorización |
| Rotated auth saltB2 |tombstone no eludido |admisión bloqueada |tras reconciliación |recuperar secreto/política, no borrar protección |

## Observabilidad y health

Correlacionar Study/Execution/command/job opacos, sin email/respuestas/bearer. Publish/retire/activate: metadata lifecycle audit; ciencia: execution_events/random_decisions/journal; delivery: notification_intents+survey_nudge_jobs; privacidad: withdrawal_records/coverage/cleanup_state. Logs sanitizados `[EIPSI STUDY]` y `[EIPSI STUDY EMAIL]` informan operación/resultado. No se añadió plataforma de monitoring ni dashboard nuevo; diagnóstico de corrupción en Builder sigue requiriendo revisar owners/logs, clasificadoP2.

Health previo al piloto: migration14 y fingerprint esperado; tablas de registry sin issues, InnoDB en Engine/Storage/queue; ambos keyrings/current y viejos requeridos disponibles sin imprimir valores; cron eipsi_process_nudge_jobs registrado y Worker::tick conectado con prioridad9; URL dedicada responde sin cache; uploads/snapshots persistentes; backups restaurables. Repair de schema no repara protocolo/checkpoint científico.

## Runbook

1. **Publicar:** guardar Draft, validar, resolver errores/media, revisar resumen/diff, aceptar warnings conscientes, publicar. Revisar versión/hash/audit. Activar OPEN explícitamente y copiar URL pública.
2. **Pausar:** activar PAUSED para la versión actual. Verificar que nuevos ingresos no se admitan y avisos se pospongan; deadlines no se congelan. Volver OPEN al resolver incidente; CLOSED para detener nuevos ingresos de forma operativa.
3. **Mail fallido:** localizar intent/job por execution opaca; distinguir CANCELLED, FAILED_RETRYABLE y FAILED_FINAL. Corregir cron/proveedor/config. Usar retry del owner/queue conforme a su estado; no cambiar SENT a pending indiscriminadamente. Capturar destinatario controlado sólo con autorización.
4. **CleanupB2:** comprobar DO_NOT_USE y exclusión export primero. Revisar cleanup_state/coverage/error; reparar dependencia local y volver a invocar cleanup por execution mediante herramienta administrativa autorizada. PARTIAL mantiene exclusiones externas/backups explícitas; no anunciar borrado global.
5. **Rotar keyring:** backup cifrado, añadir nueva key_id, cambiar current, conservar anteriores; verificar acceso/export/random de una execution previa. Nunca eliminar vieja seed key que aún referencia DB. Auth salt requiere plan separado por tombstones.
6. **Restore:** detener ingreso/worker, recuperar DB+código+snapshots+secrets compatibles; comprobar schema, Published hash, execution/random, acceso, export y Builder; sólo entonces reabrir. No usar backup de ejemplo ni keys de tests en sitio real.
7. **Link expirado:** solicitar nuevo enlace sin revelar si existe email; verificar reloj/TTL/revocación. Usuario pulsa Continuar en la confirmación. Un nuevo enlace no cambia ventana científica.
8. **Export:** usar owner autorizado `EIPSI_Export_Query_Service::study_engine(study_id, include_retained)` y `EIPSI_Raw_Export_Service::study_engine_csv(dataset, stream)`. Ambos requieren manage_options; administrador puede ejecutar desde WP-CLI autenticado al entorno. Conservar manifest separado con versiones/IDs/opciones/jerarquía. No exportar Engine por raw legacy ni inventar botón/XLSX Engine inexistente.
9. **Retire:** confirmar versión sin nuevas admisiones, retirar mediante Builder; conservar datos/hash/export y acceso de executions pinneadas conforme a política.

Ejemplo técnico para administrador (reemplazar ID, proteger directorio de salida y usar usuario autorizado):

```sh
wp --user=ADMIN_ID eval '$d=EIPSI_Export_Query_Service::study_engine("STUDY_ID",true); $s=fopen("/PRIVATE_PATH/study.csv","w"); EIPSI_Raw_Export_Service::study_engine_csv($d,$s); fclose($s); file_put_contents("/PRIVATE_PATH/manifest.json",wp_json_encode($d["manifest"],JSON_PRETTY_PRINT));'
```

WP-CLI no fue dependencia ni comando ejecutado del harness; el mismo owner fue probado con PHP autorizado. La ausencia de descarga Engine específica en UI/XLSX se registraP2 con workaround administrativo, no se agrega una feature en P1-I.

## Volumen y export

100 participantes:100 filas,9 queries export,~8.3s para enrolar/completar/exportar en fixture, peak~62.5MiB. B2 denso:35 occurrences×25preguntas=875 filas;175 respuestas,288 eventos,106 intents; exclusión antes de cleanup, COMPLETE_LOCAL y retry idempotente (~0.027s). Worker procesa múltiples batches≤5, omite cancelados y no repite SENT; segundo proceso no adquiere lease poseído por primero.

Queries de un caso simple: email entry4, enrolamiento+verificación244, assessment load24, exchange42, Builder load1, submit87, export9. Son counts de owners con validación transaccional, no un benchmark HTTP/navegador; instrumentación admite profiling futuroP2. EXPLAIN usa índice recovery (const1 fila) y binding Study (ref1 fila); no se añadieron índices especulativos.

CSV complejo branching:8 filas/25 columnas, UTF-8 leído por csv.DictReader Python, headers únicos y sin celdas desplazadas. Formula safety permanece probada por suiteG. Manifest distingue versión, question/option IDs, response keys, semantic_role y bloques. No se ejecutó R ni se certificó XLSX Engine.

## Defaults visibles

Draft: ANONYMOUS, UTC/STUDY_TIMEZONE, back permitido, cierre ALL_SELECTED_OPPORTUNITIES_TERMINAL. Macro SEQUENTIAL/NONE; Micro ASSESSMENT; anchor raíz EXECUTION_CREATED e hijos BLOCK_AVAILABLE; IMMEDIATE; NO_DEADLINE; sin recurrence/random por defecto. DST gapSHIFT_FORWARD_BY_GAP/foldEARLIER_INSTANT.

Al elegir email en Builder: reminders15/30min, VERIFIED_ONLY, provisional24h, session30d, capability48h, SUPPRESS_WHILE_PRESENT, show_next_time true. Helper backend P1-F tiene defaults15/30/60/120, diferencia explícita entre helper interno y UI; cada versión persiste valores efectivos. Random scope MacroEXECUTION, MicroBLOCK_INSTANCE, algoritmoEIPSI_RANDOM_V1. Sin baseline/T1 científico, Screen, WP Page protocol authority, email-only auth ni workflow especial de consent; semantic_role+condiciones son la autoridad.

## Checklist operacional antes del piloto

- [ ] Backup/restore propio probado, snapshot de código/build y migración.
- [ ] InnoDB/schema14/repair, storage local y volumen estimado.
- [ ] Keyrings/current+viejas/salts respaldados y acceso mínimo.
- [ ] HTTPS real, proxy reconoce scheme correcto, cookies Secure/HttpOnly/SameSite/Path.
- [ ] Excluir de cache/CDN todas las URLs eipsi_study, cap/confirm/withdrawal y admin; no-store/private/VaryCookie,Accept comprobados en origen.
- [ ] Redactar bearer query strings en access/error/proxy/analytics; no-referrer en longitudinal; no registrar POST/respuestas.
- [ ] Cron cadence y catch-up, leases y alertas de backlog.
- [ ] SMTP/remitente/proveedor y SPF/DKIM/DMARC; tres mensajes controlados verification/available/reminder sin reclutamiento.
- [ ] Persistencia/backups de uploads/snapshots y restauración de media.
- [ ] Política de backups/logs/exports/B1/B2, acceso de investigadores y retención.
- [ ] Smoke humano Builder/participante desktop768/narrow, teclado/focus, Gutenberg y dos pestañas.
- [ ] Theme/plugins reales y upgrade de instalación destino, no sólo fixture.

## Smoke humano pendiente — registro para el responsable

Anotar entorno (OS/navegador/version/theme/viewport), pasos, resultado e incidencia/severidad. No pegar tokens, emails reales ni respuestas privadas.

Builder: create→Macro/Micro→move/parent con drag y teclado→Gutenberg save/import→PageBreak→schedule elapsed/calendar/timezone→recurrence EMA→condición/rand→errors/warnings→simulate→publish→clone→activate. Dos tabs del Draft: guardarA e intentarB, conservar local/conflicto/reload. Caso50Blocks100Questions: expand/collapse/select/save/validate y percepción de lentitud.

Participante: anonymous y email/direct link/Continuar; required error y foco; PageBreak/back/refresh; branching/WAIT/final/terminated; multi-tab y dos dispositivos; withdrawal retain/do-not-use; teclado/labels/legend/status/errores; desktop768/narrow. Sin JS: submit/save/validación por formulario/redirect; Builder requiereJS. Verificar theme header/footer y controles number/select/VAS sin overflow. SMTP humano, si se habilita, usa un buzón de prueba expresamente autorizado.

## Plan de piloto y release

Primero interno, no crítico, N pequeño, transversal o longitudinal simple, DB WordPress, equipo capaz de recuperar backup, mail monitoreado. No ejecutar recruitment en esta fase. Inspeccionar admissions/execution errors/mail/overdue/duplicados/Storage/withdrawals/export/cron diariamente. Al terminar revisar logs sanitizados, respuestas faltantes, reclamos, fallos SMTP, timezone/usabilidad/export y decidir siguiente alcance.

No bump automático: header actual2.6.1 se conserva y no acredita release estable. Proponer prerelease interno con identificador de código/schema y notas: Builder, transversal/longitudinal, scheduling/branching/random/reminders, B1/B2/export, coexistencia legacy, nuevo pasoContinuar y requisitos operacionales. No tag/release/publicación.

DeudaP2/P3: diagnósticos Builder más accionables, algunos términos técnicos/TTL, profiling de admissions, exportUI/XLSX específico, paginación/Undo y defaults helper/UI documentados. Pendientes de certificación: humano/a11y/visual, WP5.8/MySQL, themes/extensions institucionales, SMTP/HTTPS real y snapshot histórico completo. No elevan por sí solos la normalización cerrada; sí condicionan este piloto/deploy. Sin P0/P1 confirmado de código abierto tras la corrección, sujeto al alcance automatizado descrito.

## Notas de release — borrador interno, no publicado

- Nuevo Builder de Study Engine separado de la administración legacy, con snapshots, validación, simulación y versiones inmutables.
- Participación transversal y longitudinal por URL estable; acceso mediante sesión o enlace confirmado, sin autenticación por conocer email.
- Scheduling/calendar/timezone, recurrencias diary/EMA y ventanas versionadas.
- Branching JUMP/WAIT/TERMINATE y randomización general para RCT e interna.
- Avisos/reminders con outbox, leases, retries y catálogo fijo de email.
- Retiro B1/B2, exclusión analítica inmediata y cleanup recuperable con coverage.
- Export explícito con manifest/IDs/occurrences; legacy mantiene sus owners y formatos.
- Corrección P1-I: el scanner GET ya no consume el enlace; el participante pulsa Continuar.
- Requiere InnoDB/schema14, keyrings y salts respaldados, media persistente, HTTPS/cache exclusions/cron/SMTP y smoke humano.
- Sin migración legacy automática, tag/release ni certificación institucional; P1-I sigue abierto.
