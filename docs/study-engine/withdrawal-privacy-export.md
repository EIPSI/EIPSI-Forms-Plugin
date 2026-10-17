# P1-G — retiro, privacidad y export del Study Engine

`WITHDRAWAL` es una decisión voluntaria. No equivale a TERMINATED_BY_RULE, unsubscribe ni consentimiento rechazado. Consentimiento sigue siendo Question + semantic_role INFORMED_CONSENT opcional + Condition explícita; no activa privacy por sí mismo. Legacy Forms/Waves mantienen sus adapters y comportamiento.

## B1 y B2

El comando de producto `ExecutionOwner::withdrawExecution()` requiere AccessContext, command ID y revisión. No admite SQL de estado desde un handler. Se serializa con submit/export mediante lock de StudyExecution; reautoriza Study/Participant/Execution/version/purpose bajo ese lock.

- **RETAIN_FOR_ANALYSIS (B1):** cierra participation abierta, cancela oportunidades y mensajes futuros, revoca acceso científico; conserva respuestas, Storage y provenance histórica. WithdrawalRecord queda NOT_REQUIRED y sus datos pueden incluirse analíticamente con la policy explícita de inclusión de retenidos.
- **DO_NOT_USE (B2):** aplica exclusión analítica desde el commit inicial, aun con cleanup PENDING o FAILED. Luego elimina/redacta las categorías locales vinculadas. No promete borrado universal.

ACTIVE/WAITING pasan a WITHDRAWN y los BlockExecutions no terminales también. COMPLETED, EXPIRED, SKIPPED_BY_RULE, NOT_SCHEDULED y TERMINATED_BY_RULE históricos se conservan en la transición. Una solicitud posterior a completion/termination no reescribe el motivo de cierre de la execution.

Mismo command ID/payload devuelve el receipt anterior; cambiar mode reutilizando ID produce conflicto. Repetir el mismo mode no duplica WithdrawalRecord/evento de retiro. B1→B2 escala el mismo record; B2→B1 se rechaza. No hay reinscripción automática ni restauración de datos borrados.

## Shell, confirmación y acceso

El footer longitudinal emite WITHDRAWAL_OPTIONS cerca del delivery. Canje GET valida single-use, emite sesión restringida y redirige 303 a URL sin bearer. GET sólo muestra opciones/outcome, nunca retira al participante.

POST requiere sesión WITHDRAWAL_OPTIONS, CSRF, command ID, mode y revisión. B2 exige además `confirm_privacy=1`, checkbox separado de la selección de mode. La validación es server-side; JS no fabrica la confirmación. Radios/labels/fieldset/legend funcionan sin JS; con JS el foco pasa a heading/outcome o alerta. La sesión restringida no permite assessments, respuestas, enrollment con otro email ni cambiar identidad.

Tras retiro se conserva una prueba hash restringida de outcome: sesión de retiro válida y capability de retiro ya consumida, hasta sus TTL. Reabrir el enlace requiere esa sesión correspondiente; el bearer solo no autentica un replay. Puede mostrar solicitud/cleanup o escalar B1→B2, nunca respuestas. Credenciales científicas y verification/access links quedan revocados. B2 conserva sólo estas pruebas mínimas vigentes; el batch elimina las vencidas. Cookies son HttpOnly/SameSite, Secure bajo HTTPS; rutas no-store/private y no-referrer.

Unsubscribe conserva participación/respuestas y acceso manual. Resubscribe explícito se mantiene para participantes activos; login/logout no lo activa. Ninguna preferencia reactiva una participation retirada.

## Transacción y cleanup recuperable

En el commit inicial se guardan WithdrawalRecord y data_policy, cierre científico aplicable, cancelación de intents/jobs, revocación de sesiones/capabilities científicas y evento mínimo. Las tablas afectadas deben ser InnoDB. Si el commit inicial falla, no queda un retiro parcial.

El owner existente `EIPSI_Privacy_Data_Cleanup_Service::run_study_engine()` delega en CleanupAdapter. No usa el adapter legacy numérico ni Wave/Assignment/FormConsent como autoridad. El cron existente `eipsi_process_nudge_jobs` procesa hasta cinco executions de cleanup por tick, antes de las tareas de correo. No requiere SMTP ni mantener una conexión HTTP abierta. Se reutilizan registry y lifecycle; no se crea un evento por persona.

Cada execution se limpia en una transacción local acotada, bajo el mismo lock SE. Fases internas storage→identidad→tombstone/coverage son atómicas: crash revierte ese intento y deja FAILED en una transacción posterior. La policy B2 inicial, las revocaciones y las cancelaciones permanecen. Retry converge; un COMPLETE_LOCAL no se repite. PARTIAL conserva exclusions no soportadas y requiere reconciliación, sin recrear datos.

Grafo de relaciones:

```text
Participant Study-scoped → StudyExecution → BlockExecution
                                    → SubmissionJournal → local_storage_ref → vas_form_results
                                                                       → device_data.submission_id
                                    → checkpoint (finales + partials + coverage)
                                    → exact session_id=Execution en events/partials/emergency
                                    → intents → queue_job_id
                                    → sessions/capabilities/enrollment receipts
```

Cada Storage ref se valida contra submission ID, execution, bloque y versión de su metadata antes de borrar. Una referencia incompatible falla cerrada. Nunca se seleccionan sujetos por fingerprint, IP, email substring ni IDs numéricos coincidentes. Records sin vínculo durable quedan fuera de coverage; no se adivina su pertenencia.

## Tombstone y PII

B2 elimina Storage ligado, partials, events/device data relacionados, responses/coverage/context del checkpoint, eventos operacionales, random decisions, seeds cifrados y receipts científicos. El checkpoint se reemplaza por `P1G_TOMBSTONE_V1` con hash recomputado: execution/version, estado histórico, revisión, withdrawal ID y DO_NOT_USE. Nunca se restaura el simulador desde ese tombstone; su corrupción falla cerrada.

Block tombstones conservan IDs opacos y estados mínimos, sin definición de rama, parentesco científico, occurrence calendario, provenance ni timestamps finos. La estructura no se ofrece a analytics B2. Intents SENT mantienen sólo auditoría mínima, sin payload de capability/PII; jobs se eliminan en cleanup. El delivery del engine no guarda cuerpos o direcciones en survey_email_log: llama al owner SMTP/wp_mail con logs mínimos por intent. Logs de proveedores/plugins ajenos no son controlables por este adapter.

Participant conserva ID opaco/Study/Execution y estado ANONYMIZED, con email NULL y verificación eliminada. Para impedir reinscripción accidental queda HMAC-SHA256 del email canonical y Study, usando el secreto WordPress de `wp_salt('auth')`, más identificador de clave. **Es pseudonimización, no anonimato absoluto**: una exposición del secreto permite ataques de diccionario. Cambiar auth salts impide recalcular esos pseudónimos; se detecta key mismatch y se bloquea admisión de esa Study hasta reconciliación explícita. No rotar salts ignorando esta dependencia ni llamar anónimo al HMAC.

Las pruebas hash de outcome constituyen una excepción mínima explícita a eliminar toda credencial: sólo autorizan el estado de privacy, vencen con TTL y no exponen información científica. No se retienen email inventado, browser fingerprint ni token raw.

## Coverage y estados

`P1G_COVERAGE_V1` enumera Participant, sesiones, capabilities, enrollment receipts, execution/bloques/checkpoint, respuestas/partials/events/device, journal, random decisions, intents/jobs, email logs y WithdrawalRecord. COMPLETE_LOCAL significa categorías locales cubiertas verificadas; `complete=false` permanece porque hay exclusions.

Exclusions incluyen DB externa, backups locales/institucionales, proxy/server logs, correos entregados, exports descargados/materializados antes, copias de navegador, analytics externo y texto/records no vinculados. Réplica externa o ref no soportada produce PARTIAL, no éxito de borrado externo. No se envía confirmación de privacy adicional en esta fase; el outcome web no depende de entrega SMTP.

## Export analítico y manifest

Única eligibility policy: `Privacy\Policy::usable()`. DO_NOT_USE siempre false desde commit, independientemente del estado científico o cleanup. Todo dashboard/modelo futuro debe usarla. B1 es includible por defecto y puede excluirse explícitamente.

`EIPSI_Export_Query_Service::study_engine()` es el adapter explícito y requiere manage_options. Carga executions bajo locks ordenados, definitions por versión, bloques, eventos y random en batch. Verifica protocolo/graph y hash reconstruido del checkpoint antes de materializar. El caso EMA completo (35 assessments, dos Questions cada una) usa nueve consultas para export; no hay consulta por Question.

Filas largas estables: Study público/lógico, StudyVersion física/número/protocol hash, Participant/Execution opacos, assessment/bloque, occurrence_key, Question ID/response_key, semantic_role/content hash, value/status, estado científico/data policy/withdrawal, fechas permitidas, provenance y random decision/selection/algorithm. No seed, seed_ref, keyring, email ni bearer.

Status distingue ANSWERED final, PARTIAL_ANSWER, NOT_YET_AVAILABLE, MISSING_ELIGIBLE, OPTIONAL_MISSING, EXPIRED, SKIPPED_BY_RULE, NOT_SCHEDULED, WITHDRAWN y TERMINATED_BY_RULE. Coverage por Question conserva rule/decision/reason/decided_at. Ramas RCT no elegidas no se consideran missing; WAIT skips no se convierten en dropout. Consent exporta semantic role, respuesta y hash/version sin workflow especial.

Manifest JSON por versión contiene ID/número/protocol hash, estado published/retired, Question IDs/labels/options/semantic roles/content hashes/parent y bloques. Versiones V1/V2 nunca se fusionan por labels. Datos permitidos de versiones retiradas siguen exportables. CSV usa el writer existente con protección de fórmulas añadida en el adapter del engine (prefijo apóstrofe ante =,+,-,@ incluso tras whitespace). Unicode y arrays se preservan; no se rediseña XLSX/UI legacy. Las filas engine quedan fuera del canal raw legacy y se exportan por este adapter versionado, evitando un bypass de B2.

Consistency: export materializa mientras mantiene locks SE; B2 committed antes de obtenerlos se excluye. Export que materializó primero puede contener datos previos y el retiro espera. Un archivo ya generado/descargado antes de B2 no puede retirarse; nuevos exports sí respetan B2. Carreras HTTP submit/withdraw y DB export/B2 se prueban con dos conexiones/procesos reales y ambas precedencias.

## Herramienta admin y smoke manual

Migración v14 aditiva, 41 dominios y 15 tablas del engine. Antes de uso, ejecute upgrade/repair por el mecanismo de schema del plugin. Los comandos siguientes requieren WP-CLI y un usuario admin; cambie los IDs por los de su Study, sin copiar credenciales de tests.

```sh
wp --user=ADMIN eval-file wp-content/plugins/EIPSI-Forms-Plugin/scripts/study-engine/privacy.php status STUDY_ID EXECUTION_ID
wp --user=ADMIN eval-file wp-content/plugins/EIPSI-Forms-Plugin/scripts/study-engine/privacy.php coverage STUDY_ID EXECUTION_ID
wp --user=ADMIN eval-file wp-content/plugins/EIPSI-Forms-Plugin/scripts/study-engine/privacy.php retry STUDY_ID EXECUTION_ID 'EXECUTION_ID|DO_NOT_USE'
wp --user=ADMIN eval-file wp-content/plugins/EIPSI-Forms-Plugin/scripts/study-engine/privacy.php export STUDY_ID > engine.csv
wp --user=ADMIN eval-file wp-content/plugins/EIPSI-Forms-Plugin/scripts/study-engine/privacy.php manifest STUDY_ID > engine-manifest.json
```

Retry exige Study+Execution concordantes y confirmación explícita; no acepta un ID destructivo suelto. No se certificó una instalación de WP-CLI en CI: el owner/adapter sí se ejecuta en los tests reales y el helper se comprueba por sintaxis.

Smoke B1: publique un Study longitudinal con helpers P1-F, responda una assessment y capture su footer. Abra retiro (GET sin efecto), elija conservar y confirme. Compruebe outcome, acceso científico viejo denegado, pending jobs cancelados y export con datos previos/marca RETAIN_FOR_ANALYSIS.

Smoke B2: misma preparación; elija no utilizar y marque la confirmación adicional. Compruebe DO_NOT_USE y export vacío **antes** del cleanup. Consulte status; ejecute cron o retry scoped. Verifique COMPLETE_LOCAL/PARTIAL, coverage honesto y ausencia de email/answers en tombstone. Reabrir con sesión de retiro muestra outcome; assessment link no abre. B1 puede escalar; B2 no puede volver a B1.

Anonymous cross-sectional usa el mismo owner con AccessContext recovery válido, sin email. Se soporta API/domain y retry restringido; no se agrega un footer público anónimo nuevo en esta fase.

## Validación y límites

P1-G agrega 106 tests únicos: 12 puros, 84 DB/HTTP y 10 DOM. FULL total verificado 1795: 1701 PHP +94 JS; FAST 1787. Repeticiones PHP7.4/8.3 y RCT rechecks no inflan conteos. Snapshots científicos P1-C originales permanecen byte a byte idénticos.

Deploy depende de InnoDB, upgrade v14, conservación/custodia de salts y keyrings, HTTPS/cache/logs, cron y validación de SMTP real de P1-F. No Builder, analytics, conversión de registros legacy ni P1-H. Cleanup por execution está acotado por los límites de materialización del engine; no se certifican datasets institucionales arbitrarios ni borrado de fuentes fuera de relaciones locales.
