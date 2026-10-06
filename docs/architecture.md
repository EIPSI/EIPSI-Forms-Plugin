# Arquitectura actual

## Bootstrap y dominios

[eipsi-forms.php](../eipsi-forms.php) conserva header/constantes y carga [bootstrap.php](../includes/bootstrap/bootstrap.php). `admin/` concentra pantallas, handlers y servicios; `includes/` contiene shortcodes, renderizado y recorridos del participante; `src/blocks/` genera `build/blocks/`. M1 separó composición y registros; M2 separó Auth/Participants y M3 separó Forms/Submit. M4–M7 tienen owners explícitos. M8 separa Schema/Migrations.

Los dominios existentes son formularios/respuestas, estudios/waves, participantes/sesiones, Pools/asignación, correo/cron y administración/exportación. No son módulos aislados: comparten bootstrap, tablas y callbacks. El inventario ejecutable de [M0](../tests/m0/README.md) permite observar registros efectivos y declaraciones por perfil admin/frontend.

## Contratos estabilizados

Emergencia devuelve éxito solo tras persistencia confirmada y comunica el destino real. Diagnóstico parcial exige capability administrativa y nonce. Retiro y REST `/pool-assign` derivan identidad de la sesión y comprueban el contexto del estudio. Las pruebas P0/P1/M0/M1/M2 documentan los contratos y sus límites en [tests/README.md](../tests/README.md).

## Deuda activa para fases posteriores

M0 caracterizó nueve emisores sin handler exacto: `eipsi_export_participants_long_excel`, `eipsi_export_participants_long_csv`, `eipsi_send_individual_reminder`, `eipsi_recalculate_preview`, `eipsi_recalculate_waves`, `eipsi_rollback_recalculation`, `eipsi_load_form`, `eipsi_create_from_clinical_template`, `eipsi_get_participant_dashboard`. Hay emisores activos y otros dormidos; los handlers parecidos no garantizan equivalencia de contrato. M7 restaura únicamente `eipsi_load_form`; las otras ocho discrepancias siguen pendientes.

`SchemaManager::check_collation_issues` delega ahora en Inspector read-only. `execute_maintenance_sql` sigue sin ejecutor genérico: su action administrativa responde 501 tras autorización; no hay un contrato seguro demostrado. La reparación activa de tabla y el envío weekly T1 sí recibieron correcciones mínimas.

Coexisten recordatorios legacy y actuales. El chequeo de salud puede reprogramar tareas con frecuencia horaria; M1 corrige la desactivación para retirar las variantes de argumentos de los 19 cron cuya propiedad está demostrada. Algunas exportaciones administrativas todavía generan archivos bajo el plugin. Estos riesgos pasan a fases posteriores; no se consideran resueltos por tests verdes.

## Datos y compatibilidad conservada

[T1 y fechas persistidas](T1-ANCHOR-SYSTEM.md) explica columnas y anclaje necesarios para interpretar estudios existentes. [Registros históricos de correo](../FIX-EMAIL-LOOP-DEPLOYMENT.md) explica tipos vacíos y metadatos de deduplicación. Los formatos JSON heredados siguen documentados en [templates](../templates/README.md).

La futura separación por dominios debe preservar estos contratos antes de reorganizar servicios. M2 conserva la política P1-A y los contratos de los dominios que todavía no se modularizaron.

## Componentes M1

- [Bootstrap](../includes/bootstrap/class-bootstrap.php): registro idempotente de la composición.
- [ServiceLoader](../includes/bootstrap/class-service-loader.php): manifest explícito, ordenado y con límites de inicialización. Los archivos admin que ya exponían handlers públicos siguen cargándose también en frontend; solo el helper de debug mantiene condición WP_DEBUG.
- [HookRegistry](../includes/bootstrap/class-hook-registry.php): secuencia de registros y delegación a owners específicos.
- [AssetRegistry](../includes/bootstrap/class-asset-registry.php) y [asset-callbacks](../includes/bootstrap/asset-callbacks.php): mismos handles, dependencias, condiciones y localizations.
- [BlockRegistry](../includes/bootstrap/class-block-registry.php) y [block-callbacks](../includes/bootstrap/block-callbacks.php): registro de los 13 manifests y categorías, sin cambiar bloques.
- [CronRegistry](../includes/bootstrap/class-cron-registry.php): schedules, trece programaciones de activación y catálogo explícito de 19 hooks poseídos.
- [Lifecycle](../includes/bootstrap/class-lifecycle.php): hooks ligados al archivo principal, schema/verification, invocación legacy de migración de Pools y desactivación sin borrar datos.
- [Compatibilidad global](../includes/compatibility/legacy-main-callbacks.php): implementaciones existentes reubicadas sin convertirlas en módulos de negocio; las dos funciones públicas de lifecycle delegan mediante [facades](../includes/bootstrap/lifecycle-callbacks.php).

Los requires se ejecutan en el mismo alcance del archivo de entrada. No se envolvieron en un método: eso alteraría el alcance de variables globales de archivos existentes. MigrationRunner y SurveyAccess conservan sus posiciones en la secuencia.

La desactivación usa `wp_unschedule_hook` para todas las variantes de argumentos de los hooks conocidos, incluidos jobs por estudio y eventos únicos de nudges/disponibilidad. No limpia por prefijo arbitrario, no borra tablas/opciones/datos y no cambia cron de terceros. Rewrites y transients mantienen el comportamiento previo. La activación conserva trece eventos periódicos; otros se programan contextualmente por sus owners existentes. El worker y los pipelines legacy/actuales no se consolidaron.

El fixture [baseline M1](../tests/m1/baseline.json) limita diferencias permitidas a archivos de extracción enumerados, ubicaciones de callbacks, identidad del archivo para traducciones, timestamps de petición ya existentes y limpieza de cron autorizada. Los registros públicos y 44 implementaciones globales se verifican automáticamente. La capa de compatibilidad sigue siendo grande; su separación funcional pertenece a fases posteriores.

## Auth y Participants: ownership M2

| Responsabilidad | Owner | Adapter/API conservada |
| --- | --- | --- |
| Decisión longitudinal P1-A e identidad canónica | [AuthorizationPolicy](../includes/auth/class-authorization-policy.php) | `EIPSI_Auth_Service::authorize_*` |
| Password/passwordless y transients de rate limit | [AuthenticationService](../includes/auth/class-authentication-service.php) | `EIPSI_Auth_Service::authenticate*`, helpers globales de rate limit |
| Creación, lookup, expiración, revocación, cookie y cleanup de sesiones | [SessionService](../includes/auth/class-session-service.php) | Métodos de sesión de `EIPSI_Auth_Service` |
| Generación/hash, validación, vencimiento y single-use de magic links | [MagicLinkService](../includes/auth/class-magic-link-service.php) | `EIPSI_MagicLinksService` |
| Lecturas/escrituras de participantes | [ParticipantRepository](../includes/participants/class-participant-repository.php) | `EIPSI_Participant_Service` |
| Creación, passwordless y coordinación de confirmación | [RegistrationService](../includes/participants/class-participant-registration-service.php) | AJAX de registro y facade Participant |
| Verificación/cambio de contraseña | [PasswordService](../includes/participants/class-participant-password-service.php) | Facade Participant |
| Activación, desactivación y persistencia de consentimiento | [StateService](../includes/participants/class-participant-state-service.php) | Consent AJAX, confirmación HTTP y facade Participant |
| Bulk/import y coordinación de notificaciones | [ImportService](../includes/participants/class-participant-import-service.php) | Adapters admin bulk y CSV |

La decisión autoritativa exige participante existente, pertenencia al estudio, ausencia de withdrawn/declined, `is_active=1` y consentimiento vacío/NULL o accepted. El consentimiento aún no decidido permite entrar para decidir. El literal `pending` conserva su rechazo. Las identidades del cliente deben concordar con la sesión; un fingerprint de formulario no autentica. Login, magic link, lectura de sesión, acceso, consentimiento, submit y Pools llegan a este mismo owner mediante las facades existentes. Las consultas de waves necesarias para autorizar conservan su implementación P1-A; no se modularizó Forms/Submit.

`survey_sessions.token` y `survey_magic_links.token_hash` almacenan SHA-256; el token claro solo se entrega al consumidor/cookie. Cookie `eipsi_session_token`, path `/`, HttpOnly, SameSite Lax y Secure según HTTPS. Sesión normal: 168 horas; sesión de `/survey-access/`: una hora; magic link: 48 horas. El auto-login `eipsi_magic` del shortcode conserva el TTL por defecto de siete días; el handler legacy conserva su parámetro remember. Se revalida estado al leer identidad y se revoca la sesión inválida. Desactivar/reactivar el plugin conserva sesiones persistidas. No hay migración de schema.

El login passwordless conserva la autenticación email-only del endpoint activo. M2 no añade verificación de control del email ni rediseña ese contrato.

Los adapters conservan actions, nonces, capabilities, JSON, códigos HTTP y redirects. Los nuevos owners se cargan desde los archivos de facade, en el orden de bootstrap existente. Las 37 firmas públicas de Auth/MagicLinks/Participant siguen disponibles. `EIPSI_Participant_Auth_Handler` se conserva: su action magic-link continúa activa, y sus métodos públicos no registrados pueden ser consumidos por extensiones. `generate_and_create_page`, lecturas de waves/historial, `has_active_session` y hard-delete continúan como interfaces de compatibilidad; no se absorbieron asignaciones, Pools, exportación ni eliminación P1-C.

`eipsi_check_consent_blocked` no tiene caller interno demostrado y permanece como helper legacy de información, no como autorización activa. Los métodos históricos de metadata/extensión de sesión conservan su contrato; no deben sustituir `get_current_session` para autorizar. Cleanup de magic links conserva su predicado heredado de igualdad de fecha: requiere revisión posterior. La purga M2 retira solo implementaciones sustituidas y el helper privado de diagnóstico de la facade MagicLinks, trasladado al owner; no elimina APIs públicas ni assets legacy.

## Forms/Submit: ownership M3

| Responsabilidad | Owner | Contrato conservado |
| --- | --- | --- |
| HTML, notices, acceso previo al render, Gutenberg/shortcodes | `EIPSI_Form_Renderer` | Funciones de `includes/form-template-render.php` |
| Identificadores estables y estado del estudio | `EIPSI_Form_Context` | Helpers globales originales |
| Respuestas, metadata, fingerprint y filtros previos a storage | `EIPSI_Form_Capture_Service` | Request histórico; helpers globales |
| Coordinación del envío | `EIPSI_Submit_Service` | `eipsi_forms_submit_form`, `eipsi_forms_nonce` |
| Resultados internos y adapter JSON | `EIPSI_Form_Response` | Payload/status existentes |
| Guardado/verificación/retry/fallback/emergency | `EIPSI_Form_Storage_Adapter` → Data Safety existente | Sin cambios a Storage |
| Coordinación longitudinal legacy después de persistencia | `EIPSI_Form_Longitudinal_Submit_Adapter` | Assignment/T1/recalculation/next wave/nudge/Pools existentes |
| Save/load/discard/completed/retención y claves | `EIPSI_Partial_Response_Service` | `EIPSI_Partial_Responses` y AJAX existentes; `create_table` queda intacto |
| Eventos y privacy | `EIPSI_Form_Tracking_Service` | `eipsi_track_event`, seis tipos existentes |
| Consentimiento backend | `EIPSI_Form_Consent_Service` → Auth Policy / ParticipantState | `eipsi_save_consent_decision` |

Submit sigue la secuencia autorización → Capture/privacy → Data Safety → verificación/device data → partial completed → actualización longitudinal → resultado/completion hook → JSON. En emergency confirmado, el retorno anticipado histórico no ejecuta el bloque longitudinal ni `eipsi_form_submitted`. Ese contrato se conserva, no se rediseña en M3. Para envíos longitudinales normales el hook sucede después del commit assignment/T1 y mantiene identidad canónica y wave_index de base uno.

Frontera Auth: `authorize_form_operation` decide participante/estudio/wave/assignment. Forms no duplica reglas ni identifica por email o metadata. Frontera Storage: `validate`, `save`, `verify` delegan a funciones Data Safety sin modificar destinos ni políticas. Frontera Longitudinal: el adapter M3 de seis argumentos delega en el comando M4. Longitudinal posee transacciones, SQL, recalculation y next-wave; Forms conserva su autorización, persistencia y contrato de respuesta.

Frontend: 18 fuentes internas ordenadas comparten el closure original: capture, conditional-logic, timing, runtime, branching-events, tracking, device-capture, consent-navigation, navigation-init, fields, navigation, validation, submit-client, messages, navigation-compatibility, completion, consent y bootstrap. El artifact público se genera en el build. Navigation gobierna páginas/next/back y campos deshabilitados; ConditionalNavigator evalúa branching, historial y páginas visitadas. Validation conserva required y reglas de text/textarea/select/radio/checkbox/VAS/email. Tracking delega en el cliente independiente activo de `assets/js/eipsi-tracking.js`; save/continue conserva el cliente independiente de `assets/js/eipsi-save-continue.js`.

La visibilidad caracterizada es la de páginas y su required-state; no se agregó un motor nuevo de visibilidad condicional por campo. Required, validez email y VAS tocado siguen siendo validaciones frontend. Backend conserva nonce, identidad/estado/assignment, estudio cerrado, campos mínimos de Data Safety, tipos de tracking, límites de parciales y filtrado. No se creó ValidationService artificial que simule reglas backend inexistentes.

Purga M3: únicamente `src/frontend/eipsi-save-continue.js`, copia alternativa sin requires/imports/entrada webpack. El cliente activo permanece idéntico. Facades render/helpers/partial y métodos JS públicos son compatibilidad C. Randomization/Pools, dashboard, servicios longitudinales y notificaciones quedan B para fases posteriores.

M3 caracterizó dos bugs de completion de Pools. M4 corrige únicamente esa frontera: el callback consulta `pool_name` y el helper compara el participant_id numérico; las regresiones ahora exigen completion funcional. Pools conserva su owner y contratos. Load/discard de parciales mantienen su contrato legacy por claves, sin agregar nonce/session auth en M3. La verificación genérica de Data Safety no reconoce todos los nombres de destino emergency/external aunque el guardado emergency confirma su INSERT. Required permanece frontend-only; ninguna de estas deudas se ocultó como funcionalidad nueva.


## Longitudinal: ownership M4

La composición de definiciones se carga en [bootstrap longitudinal](../includes/longitudinal/bootstrap.php), desde los adapters existentes; no registra hooks ni reordena el bootstrap WP.

| Responsabilidad | Owner bajo `includes/longitudinal/` | Compatibilidad |
|---|---|---|
| Lectura/persistencia, creación y estado de Studies | `studies/StudyRepository`, `StudyService` | Wizard y pause/resume |
| Overview y cierre | `studies/StudyDashboardService` | AJAX con nonce/capability originales |
| Settings y cron config | `studies/StudyConfigService` | Tab y programación originales |
| Definición Waves, CRUD, restricciones P1-B y unidades | `waves/WaveDefinitionService` | `EIPSI_Wave_Service`, normalización de `Wave_Service` |
| Lookup, creación individual, next-wave y selector de availability | `assignments/AssignmentRepository` | Facades públicas array/object conservadas |
| Creación masiva y primer evento availability | `assignments/AssignmentService` | Helper global/facade Assignment |
| Escrituras de estado, timestamps, submit/skip con locks | `assignments/AssignmentTransitionService` | Facades y comandos longitudinales |
| Selectores de expiration/skipping y sus efectos existentes | `AssignmentExpirationService`, `AssignmentLifecycleService` | Adapters cron conservados |
| Deadline manual y restauración de fechas | `assignments/AssignmentDeadlineService` | AJAX dashboard P1-B |
| T1 anchor, timeline y completed_at | `t1/T1AnchorService` | Callback original prioridad 5, status listener prioridad 10 |
| Recalculation T1, single wave y variante legacy | `t1/T1RecalculationService` | Ambos contratos históricos conservados |
| Coordinación después de persistir y next-wave payload | `LongitudinalSubmissionService` | Adapter M3 de seis argumentos |

Los nombres abreviados de la tabla corresponden a clases `EIPSI_Longitudinal_*` y archivos `class-*.php`. Las definiciones Waves tienen un único owner. `Wave_Service` continúa entregando next-wave array; `EIPSI_Wave_Service` conserva su objeto. No unificar esas respuestas incidentalmente.

Transition Service contiene START TRANSACTION, SELECT FOR UPDATE, revalidación pending/in_progress, escritura submitted y T1 assignment timestamp, COMMIT/ROLLBACK y el error 500 anterior. El skip con lock también vive allí. Expiration y auto-skip aplican compare-and-set al status leído: un snapshot obsoleto afecta cero filas y no ejecuta los efectos de una transición ganada. Los selectores temporales anteriores permanecen distintos: expiration service incluye pending/available con `<= NOW()`, cron incluye estados no terminales con `< current_time`; skipping protege T1 pero auto-skip expired mantiene su criterio previo.

Storage ya persistió antes de entrar al comando. Esa respuesta y Assignment **no son atómicos**; un error longitudinal puede dejar una respuesta guardada. Recalculation, Pool completion y next-wave se ejecutan post-COMMIT. Las llamadas de notificaciones que ya ocurrían dentro del lock conservan su posición; correo/jobs/audit y post-commit no tienen exactly-once global. Generic update, manual completion/expiration y forced anchor mantienen sus contratos administrativos, sin prometer la idempotencia del submit bloqueado.

Longitudinal decide available_at/due_at, offsets/window en minutos e índices T1=1/T2=2/T3=3. Consume Notifications mediante sus APIs existentes; no posee scheduler, queue, worker, templates ni weekly T1 delivery. `Wave_Service::maybe_send_immediate_wave_reminder` permanece intacto, accesible mediante el puente de compatibilidad `notify_submission`.

Eventos: se preservan emisores y orden existentes de `eipsi_wave_available`, `eipsi_t1_anchored`, `eipsi_wave_expired`, `eipsi_assignment_expired` y `eipsi_form_submitted`. `eipsi_assignment_status_changed` ya tenía un listener T1; el update genérico no lo emitía y M4 no inventa esa emisión. Los actions manuales M0 de recalculation sin handler siguen pendientes.

Purga M4: siete métodos privados duplicados, sin callers restantes en facades, documentados en [manifest](../tests/m4/purge-manifest.json). Las facades públicas son C; notificaciones son B; helpers fuera de la extracción sin prueba concluyente permanecen E.

Deuda conservada: `study_end_at` se escribe en el recalculador cuando hay un offset de cierre, pero no figura en el schema actual; la disponibilidad legacy busca `wave_index` en assignments aunque está en Waves. Settings usa el nombre legacy `name` en un recorrido, mientras schema define `study_name`. No se cambió schema ni se repararon estos contratos fuera del alcance M4. Revisión específica en fases posteriores; un test verde no acredita esos recorridos completos.

## M5 — Notifications

`includes/notifications/bootstrap.php` carga definiciones sin registrar hooks nuevos. Los archivos históricos mantienen sus firmas y callbacks; delegan a owners de Notifications. `email/` separa composición contextual, templates, transporte SMTP/wp_mail y email log. WaveAvailability coordina nudge0, dedupe y tres intentos con cooldown; la disponibilidad se consulta al contexto Longitudinal.

`nudges/` separa Policy, Schedule, Queue, Worker y Cache. Policy produce un plan de stages con offsets absolutos desde available_at, conversión de minutos/horas/días, exclusión de pasado, deadline y stages enviados. Schedule consume ese plan y persiste WP-Cron; un lock MariaDB por assignment serializa reconstrucciones y refresca el cache local del option cron. Catch-up usa el mismo plan absoluto. Queue conserva el claim SQL atómico pending→processing y el backoff existente. Worker entrega y actualiza estado sin poseer reglas de availability/deadline. Dos enqueues producen dos jobs distintos: no hay dedupe durable global ni garantía exactly-once.

`reminders/` conserva selección y política de los pipelines actuales, weekly T1 y dropout, además de un owner legacy para posts/meta y sus envíos directos wp_mail. Weekly T1 corre diariamente y aplica su intervalo lógico configurable, normalmente siete días; la transición a expired pertenece al owner Longitudinal existente. Los callbacks deprecated daily/weekly que solo loguean permanecen registrados. `adapters/` coordina cron y notificaciones de transiciones longitudinales.

Longitudinal conserva disponibilidad, offsets clínicos, due_at, configuración transaccional y transición T1. Notifications recibe contexto ya calculado. Se conservan los eventos eipsi_wave_available, eipsi_wave_available_retry, eipsi_assignment_deadline_changed, eipsi_t1_anchored y el callback eipsi_scheduled_nudge_event, con sus prioridades y argumentos. La cancelación admite IDs cron enteros y strings.

CronHealth repara wave/dropout con every_minute, la cadencia real de activación; purge conserva daily. Los jobs por estudio conservan argumentos y frecuencia existente; no se fabrica un job sin study_id. Sus observers de prioridad 999 siguen activos.

Se mantiene el envío dentro de START TRANSACTION/FOR UPDATE/COMMIT del nudge programado: rollback SQL no deshace un email. Submit y recalculation conservan sus efectos post-COMMIT y su manejo de errores. No se introduce outbox. El lock del scheduler protege un assignment, no todos los escritores del option cron ni el transporte SMTP. Permanecen la política legacy polling (incluida cuantización/cache e intervalo mínimo), la incompatibilidad legacy available/pending al reconstruir desde T1, helpers de cancelación que usan tablas/columnas antiguas y el handler individual ausente. Estas deudas requieren fases posteriores.


## M6 — Storage, Privacy y Export

`includes/storage/`, `includes/privacy/` e `includes/export/` contienen los owners de estas responsabilidades. Sus bootstraps cargan definiciones; los entry points, hooks, nonces y firmas públicas permanecen en las facades existentes. Forms conserva captura, identidad canónica y postcommit Longitudinal, y llama al StorageAdapter. No hay un Repository universal ni cambios de schema.

| Responsabilidad | Entrada / owner anterior | Writer o reader M6 | Destino / datos | Consumidor |
|---|---|---|---|---|
| Persistencia normal / retry / fallback | `eipsi_safety_*`, FormStorageAdapter | SubmissionStorageService + LocalSubmissionStore / ExternalSubmissionStore | `vas_form_results`; respuestas, identidad y metadata filtrada | SubmitService |
| Configuración/conexión externa | EIPSI_External_Database | Storage_External_Submission_Store; facade heredada | Options cifrados; conexión mysqli y tablas externas existentes | normal, emergency, events, raw export |
| Emergency / alerta | `eipsi_safety_emergency_save` | EmergencySubmissionStore | `eipsi_emergency_submissions`; respuestas, POST real filtrado, diagnóstico | retry y respuesta de emergencia |
| Verification | `eipsi_safety_verify_submission` | SubmissionVerificationService + StorageResult | local normal; ID y respuestas no vacías | Forms / diagnóstico |
| Parciales | EIPSI_Partial_Responses → PartialResponseService | Storage_Partial_Response_Store | `eipsi_partial_responses`; interacción filtrada | save/load/mark_completed y cron original |
| Events | FormTrackingService | Storage_Event_Store | `vas_form_events`, externo o local | tracking; envelope resiliente existente |
| Device | EIPSI_Device_Data_Service | Storage_Device_Data_Store | `eipsi_device_data`; solo IDs locales | Forms y enriquecimiento local de export |
| CapturePolicy | `eipsi_filter_capture_data` | Privacy_Capture_Policy | categorías y JSON reconocidos; no texto arbitrario | todos los writers, incluidos access logs |
| Cleanup / anonymization | ParticipantDataCleanup / AnonymizeService | Privacy_Data_Cleanup_Service / Anonymization_Service | tablas locales vinculables; respuestas y logs según operación | Participants, B2, data requests y admin |
| Data requests | ParticipantDataRequestService | Privacy_Data_Request_Service | `survey_data_requests`; claim pendiente → processing → completed/rejected | portal, aprobación y descarga personal |
| Dataset personal | antiguo método privado de DataRequest | PersonalExportQueryService + PersonalExportService | allowlist local; JSON privado 0600 | request aprobado |
| Downloads | handlers existentes + nuevo admin export | DownloadAuthorizationService | personal: ruta desde DB; admin: basename y realpath del directorio | participantes/admin autorizados |

Storage devuelve `success`, `destination`, `submission_id`, `insert_confirmed`, `verified`, `verification_supported`, `verification_status`, `fallback_used`, `error` y `source`, conservando las claves anteriores `storage`, `insert_id` o `emergency_id`. `success` requiere INSERT confirmado e ID positivo. `verified=false` puede significar respuesta vacía o verificación genérica no soportada: se distingue explícitamente. Una escritura de emergencia confirmada no se convierte en fallo por esa limitación. El DTO no consulta tablas locales para verificar un ID externo.

| Destino | Selección / confirmación | Verificación genérica | Fallback / cobertura |
|---|---|---|---|
| `external_db` | externo habilitado; execute, affected_rows=1, ID>0 | no soportada | no se duplica en local |
| `wordpress_db` | local, o externo falló; INSERT=1, ID>0 | existencia y respuestas no vacías | `fallback_used=true` y código de error primario si corresponde |
| `emergency_table_external` | agotados retries; emergency externo confirmado | no soportada | diagnóstico; no verifica por colisión de ID |
| `emergency_table_wp` | emergency local confirmado | no soportada | destino real y diagnóstico; sin éxito si ambos INSERT fallan |

El branch `fallback_used` anterior era latente: el fallback local no lo emitía, por lo que la referencia a `error_info` no se ejecutaba en ese recorrido. M6 informa el fallback real y usa un código primario seguro, con pruebas de fallback y emergencia. Se mantienen la selección de destino, retries y ausencia de dual-write.

### Semánticas y cobertura Privacy

| Operación | Owner / efecto local | Respuestas | Cobertura |
|---|---|---|---|
| deactivate / B1 | Participants/Auth y Consent existentes; desactivación/retiro | conservadas | no se presenta como eliminación |
| anonymize | Cleanup + Anonymization | conservadas, PII reconocida depurada | `source=wordpress_db`, `complete=false` |
| personal delete | DataRequest aprobado → anonymize | conservadas | informa explícitamente anonymize |
| hard delete | Cleanup; elimina PK, relaciones seguras, tokens/jobs/parciales y desvincula resultados | conservadas con identidad depurada | local, incompleta |
| B2 | Consent → Cleanup | eliminadas dentro de cobertura vinculable | participante retirado conservado; local incompleta |
| personal export | Query allowlist y archivo privado | propias y enlazadas localmente | excluye externo y emergency |

CoverageReport conserva `not_covered` P1-C y agrega `excluded`: DB externa, registros browser no enlazados con fiabilidad, exports históricos, backups, logs de servidor, correo ya entregado y texto libre identificatorio. No garantiza anonimización de texto libre ni borrado global. Identidades browser compartidas se excluyen antes de operar. Cleanup conserva la transacción local y rollback; un writer concurrente puede insertar después del snapshot/cleanup, por lo que no existe atomicidad global entre captura y eliminación. Las pruebas caracterizan ese límite.

### Matriz de exports

| Export | Reader / dataset | Generación | Fuente / autorización |
|---|---|---|---|
| raw | ExportQueryService::raw_responses; campos administrativos, distinta política de columnas | RawExportService; CSV/XLSX directo | externo configurado o fallback local; `X-EIPSI-Data-Source`; manage_options; GET legacy sin nonce específico |
| longitudinal | ExportQueryService; participante × toma, joins locales | ExportFileService CSV/XLSX | WordPress local; AJAX admin + nonce; GET legacy solo capability |
| participant / wide | QueryService::fetch_participants_data, waves/headers y respuestas canónicas | FileService; columnas por toma | WordPress local; no device enrichment de IDs externos |
| pool context | QueryService::eipsi_export_responses_with_pool_context | RawExportService CSV | local; capability conservada; no cambia asignaciones |
| pool roster dashboard | QueryService::pool_roster_dashboard | FileService::stream_pool_roster_dashboard | local; nonce dashboard + capability originales; 9 columnas |
| pool roster hub | QueryService::pool_roster_hub | FileService::stream_pool_roster_hub | local; nonces hub/admin; BOM y 10 columnas originales |
| access logs | AccessLogExportQueryService; filtros por participante/study/fecha | AccessLogExportService CSV/XLSX o stream | local; handler autorizado existente |
| personal | PersonalExportQueryService; allowlist de 5 datasets | PersonalExportService JSON aleatorio privado | local incompleta; sesión propia o admin + nonce; aprobación requerida |

Las dos variantes de roster se mantienen distintas. No se rediseña Pools/Randomization. SQL fallido no se transforma en dataset vacío exitoso. El dataset personal no usa SELECT *, elimina hashes/tokens/secretos también en respuestas JSON y no agrega sesiones/magic links ni raw metadata.

Los archivos administrativos conservan nombres/path bajo `exports/` y la respuesta `filename`, con `download_url` adicional. La UI usa el handler `eipsi_download_admin_export`: exige manage_options y nonce `eipsi_export_download`, extensión csv/xlsx, basename allowlist y realpath dentro del directorio. Se reserva el nombre con fopen(x); una colisión crea un sufijo aleatorio y preserva el archivo anterior. El directorio incluye `.htaccess` deny-all e index 403. En Apache se demostró antes HTTP 200 anónimo y después 403; download autenticado devuelve 200. Nginx u otros servidores requieren regla deny equivalente: `.htaccess` no aporta cobertura allí. No se eliminan exports históricos; su retención y copias fuera del directorio siguen fuera de coverage Privacy.

Personal conserva ruta desde DB, archivo fuera de ABSPATH, nombre aleatorio y permisos 0600. No se inventa expiración. Approval usa compare-and-set del estado pending: dos administradores concurrentes no procesan simultáneamente la misma solicitud.

LONG `eipsi_export_participants_long_excel/csv` conserva deuda: la UI activa las emite pero no hay handler. La etiqueta de formato LONG no define inequívocamente las columnas/filas esperadas frente a los datasets actuales; no se crea un alias a wide ni a raw. XLSX conserva una sola implementación canónica `lib/SimpleXLSXGen.php`; la copia de admin era idéntica y solo se purga después de validar los consumidores.

Facades públicas y helpers usados continúan clasificados C; legacy/ambigüedades pasan a fases posteriores. Purga A y hashes históricos se documentan en `tests/m6/purge-manifest.json`, `contracts.json` y `baseline-hashes.json`. M7/M8 quedan fuera de esta fase.

M6 también corrige la lectura de credenciales externas cuando el IV binario contiene `::` o termina en `:`. Se lee su longitud fija de 16 bytes; el formato almacenado, cifrado y API no cambian. Dos regresiones deterministas cubren el defecto previo.

Deuda Export adicional conservada: el GET legacy `page=eipsi-results&action=export_participants_excel` invoca `export_participants_to_excel`, método inexistente de la facade; la UI AJAX wide utiliza el método válido. Los GET procedurales legacy conservan capability pero no nonce específico. M6 no crea equivalencias de dataset para reparar esos recorridos.


## M7 — Pools y Randomization

`includes/pools/` owns pool configuration (`PoolService`), lookup/persistence (`PoolRepository`), assignment (`Pools_Assignment_Service`), algorithms (`PoolAlgorithmService`), completion (`PoolCompletionService`) and analytics aggregation (`PoolAnalyticsService`). Dashboard queries remain in `PoolDashboardQueryService`; adapters own AJAX/REST response formatting and shortcode/block rendering. Old admin files retain public callback names, registrations, priorities, nonces and signatures. The read adapter reads Longitudinal state; Longitudinal continues to own Studies/Waves/Assignments. M6 continues to own export queries/files, reached through existing read adapters.

`includes/randomization/` owns DB configuration (`RandomizationRepository`), postmeta configuration (`RandomizationConfigService`), algorithms, stable assignment/persistence and manual overrides. Adapters retain admin checks, AJAX/REST formatting, frontend markup, legacy block scanning and browser tracking keys. `_randomization_config_{id}` remains the shortcode's first source, with saved-block fallback; `_eipsi_random_config` remains a separate historical contract. No schema or distribution policy changed. The distinct RCT, frontend, submission and Pools algorithms retain their original seeds, inclusive boundaries and fallbacks; do not collapse them into a single statistical method.

Auth/session is the only authority for participant operations. Pool AJAX assignment/join/login reject anonymous or mismatched IDs/emails; email remains contact data. The service's historical email entry point requires a matching session and selects the participant ID from it. Registration is still a separate email-confirmation entry point, not proof of authentication. Existing numeric internal service entry points are trusted server calls, not public authorization boundaries. Randomization fingerprint is a browser tracking/config key; an authenticated session does not silently replace it. Fingerprints do not authorize longitudinal access.

Completion's conditional update is owned by one command. The direct helper preserves its historical total-wave eligibility check; the submitted hook preserves its active-wave check. Both call the same mutation, update analytics only for its winner and emit `eipsi_pool_study_completed` once per successful transition. The historical zero-wave direct predicate and the two eligibility policies remain documented debt. No global exactly-once guarantee: process failure after updating and before emitting can lose the event.

Pool assignment and Randomization resolve serialize read/select/insert using connection-scoped MariaDB `GET_LOCK`, released in `finally`; the existing unique keys remain the persistence constraint. Stable assignment takes precedence over a later override. Override creation and resolve use the same scoped lock. There is no global distributed transaction or new reassignment policy; the existing pool unique key may reject reassignment after completion.

`eipsi_load_form` now validates nonce `eipsi_randomization_nonce`, a published non-password-protected `eipsi_form_template`, and Auth's form/study authorization. M3 FormRenderer supplies HTML; AJAX returns `success.data` as a string. Public templates remain public; longitudinal templates require their matching session and assignment. Frontend uses a public localized nonce, initializes `EIPSIForms` and catches normal-load failures. Legacy configuration/assignment AJAX actions in that old frontend are not reconstructed by guessing.

The old anonymous email-only Pool login/join interface can no longer grant access. Authenticated self assignment/join works; public onboarding UI must adopt the existing Auth flow in a later phase before that interface is released. No new identity policy or Longitudinal membership migration was introduced.

## M8 — Schema y migraciones

Registry → Installer define/crea el estado actual; MigrationRunner conserva v1–v9 y converge en v10; Inspector lee y Repair solo añade estructura segura. ExternalSchemaAdapter tiene ownership separado. SchemaManager y los creadores públicos anteriores permanecen facades. Ver [contratos, versiones, DDL residual y límites](schema.md).

Se preservan uniques, datos dinámicos, collation histórica y callbacks. Las verificaciones periódicas no mutan estructura. Una instalación desde cero evita migraciones históricas; versiones históricas admitidas requieren precondiciones demostradas y checkpoints confirmados. El mapa previo y el informe M8 en informes institucionales documentan inventario completo y matriz global B/C/E para PURGA FINAL.
