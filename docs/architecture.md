# Arquitectura actual

## Bootstrap y dominios

[eipsi-forms.php](../eipsi-forms.php) conserva header/constantes y carga [bootstrap.php](../includes/bootstrap/bootstrap.php). `admin/` concentra pantallas, handlers y servicios; `includes/` contiene shortcodes, renderizado y recorridos del participante; `src/blocks/` genera `build/blocks/`. M1 separó composición y registros; M2 separó Auth/Participants y M3 separó Forms/Submit. Los módulos M5–M8 siguen pendientes.

Los dominios existentes son formularios/respuestas, estudios/waves, participantes/sesiones, Pools/asignación, correo/cron y administración/exportación. No son módulos aislados: comparten bootstrap, tablas y callbacks. El inventario ejecutable de [M0](../tests/m0/README.md) permite observar registros efectivos y declaraciones por perfil admin/frontend.

## Contratos estabilizados

Emergencia devuelve éxito solo tras persistencia confirmada y comunica el destino real. Diagnóstico parcial exige capability administrativa y nonce. Retiro y REST `/pool-assign` derivan identidad de la sesión y comprueban el contexto del estudio. Las pruebas P0/P1/M0/M1/M2 documentan los contratos y sus límites en [tests/README.md](../tests/README.md).

## Deuda activa para fases posteriores

M0 caracteriza nueve emisores sin handler exacto: `eipsi_export_participants_long_excel`, `eipsi_export_participants_long_csv`, `eipsi_send_individual_reminder`, `eipsi_recalculate_preview`, `eipsi_recalculate_waves`, `eipsi_rollback_recalculation`, `eipsi_load_form`, `eipsi_create_from_clinical_template`, `eipsi_get_participant_dashboard`. Hay emisores activos y otros dormidos; los handlers parecidos no garantizan equivalencia de contrato. No se restauraron ni eliminaron esas UI.

`SchemaManager::check_collation_issues` y `SchemaManager::execute_maintenance_sql` siguen ausentes: los recorridos identificados no demostraron un uso interno activo que justificara intervenir en M0. La reparación activa de tabla y el envío weekly T1 sí recibieron correcciones mínimas.

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
