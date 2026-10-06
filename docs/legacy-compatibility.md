# Compatibilidad y deuda conservadas después de PURGA FINAL

Owners M1–M8 son canónicos; los símbolos públicos anteriores siguen disponibles. No se retiraron B/C/D/E/F, no se cambiaron collations ni se inventó una migración de identidad. Esta guía describe únicamente elementos que siguen presentes.

## Matriz de elementos conservados

| ID | Elemento | Clase | Owner | Motivo y criterio de retiro |
|---|---|---|---|---|
| 1 | Funciones globales de bootstrap | C | HookRegistry/AssetRegistry + owners de dominio | Callbacks globales enlazados por WordPress; catálogo individual de símbolos y prioridades. Requiere decisión explícita de versionado público y auditoría de integraciones antes de retirar. |
| 2 | Doble enqueue frontend activo | B | AssetRegistry | Dos registros prioridad 10 invocan el mismo enqueue; WP deduplica handles. Retirar solo tras demostrar cobertura de render y localización equivalente. Retiro condicionado a la evidencia de convergencia/datos indicada. |
| 3 | Scheduling contextual/guardas init legacy | B | CronRegistry/Notifications | Scheduling contextual en init y guardas mantiene eventos; retirar al demostrar convergencia de todas las instalaciones y lifecycle. Retiro condicionado a la evidencia de convergencia/datos indicada. |
| 4 | MigrationRunner/creators/ExternalDatabase/schema legacy | C | Registry/Installer/Inspector/MigrationRunner/ExternalSchemaAdapter | Facades públicas vigentes. Tres wrappers privados del SchemaManager se desglosan como A; API pública intacta. Requiere decisión explícita de versionado público y auditoría de integraciones antes de retirar. |
| 6 | AuthService/MagicLinksService/ParticipantService APIs | C | Auth/Participants | Sesiones, magic links, registro y estado usados por Forms/Pools/Notifications; firmas individuales congeladas. Requiere decisión explícita de versionado público y auditoría de integraciones antes de retirar. |
| 7 | EIPSI_Participant_Auth_Handler | C | Auth adapters | AJAX login/registro/retiro públicos y autorizados; hooks ejecutables. Requiere decisión explícita de versionado público y auditoría de integraciones antes de retirar. |
| 8 | Helpers globales rate-limit | C | Auth rate limiter | Contrato de límites compartido por autenticación; nombres públicos preservados. Requiere decisión explícita de versionado público y auditoría de integraciones antes de retirar. |
| 9 | Export/cleanup/anonymize/delete, assignments y Pools | C | Export/Privacy/Longitudinal/Pools | Facades separadas inventariadas por clase y método; dispatcher y callers preservados. Requiere decisión explícita de versionado público y auditoría de integraciones antes de retirar. |
| 10 | Render/helpers/Partial facades | C | Forms | Shortcodes, render y guardado parcial activos; APIs y callbacks públicos. Requiere decisión explícita de versionado público y auditoría de integraciones antes de retirar. |
| 11 | navigation-compatibility/window.EIPSIForms | C | Forms frontend | window.EIPSIForms es frontera JS; composición y carga ejecutable probadas. Requiere decisión explícita de versionado público y auditoría de integraciones antes de retirar. |
| 12 | save/continue/tracking/login assets | C | AssetRegistry | Handles, deps, URL y orden inventariados; fuentes y artefactos cumplen roles distintos. Requiere decisión explícita de versionado público y auditoría de integraciones antes de retirar. |
| 13 | randomization/Pools y src/frontend/eipsi-random.js | B | Randomization/Pools adapters | RCT legacy depende de saved content y postmeta posibles; retiro requiere dataset institucional y transición explícita. Retiro condicionado a la evidencia de convergencia/datos indicada. |
| 14 | Bloque longitudinal legacy y servicios de correo | C | Longitudinal/Notifications | APIs históricas delegan a owners; múltiples consumidores y callbacks activos. Requiere decisión explícita de versionado público y auditoría de integraciones antes de retirar. |
| 15 | clinical template sin botón actual | E | Form library tools | Binding privado delegado sin botón interno ni handler; nonce/strings se publican en eipsiFormTools. No se puede excluir productor DOM en extensión institucional sin inventario de extensiones. Se necesita inventario institucional de extensiones, consumidores o datos para decidir. |
| 16 | Wave_Service | C | Longitudinal WaveDefinition/Assignment/NotificationContext | Wave_Service: todos los métodos públicos y referencias constan en catálogo; mantener boundary. Requiere decisión explícita de versionado público y auditoría de integraciones antes de retirar. |
| 17 | EIPSI_Wave_Service | C | Longitudinal | EIPSI_Wave_Service: facade de compatibilidad incluida y métodos reflejados. Requiere decisión explícita de versionado público y auditoría de integraciones antes de retirar. |
| 18 | EIPSI_Assignment_Service | C | Longitudinal Assignment | Asignación y transiciones públicas activas. Requiere decisión explícita de versionado público y auditoría de integraciones antes de retirar. |
| 19 | EIPSI_T1_Anchor_Service | C | Longitudinal T1Anchor | Anclaje T1 público usado en submits y recalculación. Requiere decisión explícita de versionado público y auditoría de integraciones antes de retirar. |
| 20 | EIPSI_Wave_Recalculator | C | Longitudinal T1Recalculation | Preview/recalculate/rollback públicos preservados; nombres de UI distintos no prueban equivalencia de contrato. Requiere decisión explícita de versionado público y auditoría de integraciones antes de retirar. |
| 21 | EIPSI_Wave_Expiration_Service | C | Longitudinal AssignmentExpiration | Expiración usada por cron y consumers Longitudinal. Requiere decisión explícita de versionado público y auditoría de integraciones antes de retirar. |
| 22 | EIPSI_Form_Longitudinal_Submit_Adapter | C | LongitudinalSubmission | Adaptador público en submit conserva orden y efectos. Requiere decisión explícita de versionado público y auditoría de integraciones antes de retirar. |
| 23 | AJAX/global cron adapters | C | Longitudinal adapters/CronRegistry | Callbacks AJAX/cron individualmente capturados; no retirar nombres usados por WP. Requiere decisión explícita de versionado público y auditoría de integraciones antes de retirar. |
| 24 | Wave_Service::maybe_send_immediate_wave_reminder | C | Longitudinal NotificationContext/Notifications | Método privado invocado por frontera pública de notificación; no es wrapper muerto. Requiere decisión explícita de versionado público y auditoría de integraciones antes de retirar. |
| 25 | Notifications scheduler/queue/templates/weekly delivery | C | Notifications | Scheduler/queue/templates/weekly tienen facades y distintas condiciones de entrega; catálogo individual. Requiere decisión explícita de versionado público y auditoría de integraciones antes de retirar. |
| 26 | Pools ownership | C | Pools | Ownership implementado por owners M7; contrato público conservado. Requiere decisión explícita de versionado público y auditoría de integraciones antes de retirar. |
| 27 | unproven progression/old backend helpers outside relocated methods | E | Longitudinal | Etiqueta original amplia sin lista de helpers ni evidencia de consumer externo. Catálogo completo de métodos/refs disponible; no usar ausencia interna para retirar símbolos públicos/contextuales. Se necesita inventario institucional de extensiones, consumidores o datos para decidir. |
| 28 | all public facades | C | Notifications | Cada facade pública M5 y método inventariados por separado; delegates no invalidan contrato. Requiere decisión explícita de versionado público y auditoría de integraciones antes de retirar. |
| 29 | scheduler init and hook registrations | C | CronRegistry/Notifications | Hooks init y jobs son contratos WordPress activos, con callbacks ejecutables. Requiere decisión explícita de versionado público y auditoría de integraciones antes de retirar. |
| 30 | deprecated daily/weekly log callbacks | C | Notifications Cron adapters | Daily/weekly DEPRECATED solo loguean, pero sus hooks siguen programados: boundary cron real, no pipeline de entrega. Requiere decisión explícita de versionado público y auditoría de integraciones antes de retirar. |
| 31 | private Wave facade used by public notify | C | Longitudinal NotificationContext | Helper privado conserva recorrido public notify; uso real, no eliminar. Requiere decisión explícita de versionado público y auditoría de integraciones antes de retirar. |
| 32 | legacy posts/meta pipelines | B | Notifications LegacyReminder | Entrega daily/weekly lee _eipsi_random_config y _eipsi_toma_*_assign, crea token postmeta; diferente a assignments SQL. Requiere auditoría de datos antes de retiro. Retiro condicionado a la evidencia de convergencia/datos indicada. |
| 33 | legacy cancellation table contracts | F | Notifications NudgeSchedule | Cancelación pública escribe survey_job_queue; owner canónico usa survey_nudge_jobs. Callers de transición reales; contrato incompleto, no basura. Decisión funcional o de producto posterior; no eliminar como basura. |
| 34 | legacy polling timing profile | B | Notifications | Polling wave/dropout y worker batch=1 tienen perfil/config diferente a eventos; no equivalencia completa. Retiro exige caracterizar tiempos y filas institucionales. Retiro condicionado a la evidencia de convergencia/datos indicada. |
| 35 | templates without proven production consumer | E | Notifications EmailTemplate | render_template acepta nombre público y resuelve dinámicamente includes/emails/*.php. No se conoce catálogo de nombres usado por integraciones; conservar templates sin caller demostrado. Se necesita inventario institucional de extensiones, consumidores o datos para decidir. |
| 36 | all public Safety/Privacy/Export facades | C | Storage/Privacy/Export | Facades públicas M6 inventariadas separadamente; endpoints y consumers conservados. Requiere decisión explícita de versionado público y auditoría de integraciones antes de retirar. |
| 37 | inherited ExternalDatabase and Device APIs | C | ExternalSubmissionStore/DeviceDataStore | Métodos heredados forman API real; reflection registra declaring owner y parámetros. Requiere decisión explícita de versionado público y auditoría de integraciones antes de retirar. |
| 38 | Forms partial interaction facade | C | Forms/PartialResponseStore | Interacción partial pública y callbacks con autorización conservados. Requiere decisión explícita de versionado público y auditoría de integraciones antes de retirar. |
| 39 | personal/access download handlers | C | Privacy/Export | Downloads personales y logs preservan capability, token y nonce. Requiere decisión explícita de versionado público y auditoría de integraciones antes de retirar. |
| 40 | CapturePolicy config helpers | C | Privacy CapturePolicy | Configuración de captura activa; API pública con consumers. Requiere decisión explícita de versionado público y auditoría de integraciones antes de retirar. |
| 41 | canonical participant auto-sync helpers | C | Storage/Participants | Auto-sync longitudinal tras persistencia conserva consumers y diagnóstico. Requiere decisión explícita de versionado público y auditoría de integraciones antes de retirar. |
| 42 | Pool export entry points and dispatcher | C | Export/Pools | Entradas Pool y dispatcher mantienen contratos diferenciados. Requiere decisión explícita de versionado público y auditoría de integraciones antes de retirar. |
| 43 | legacy external table creator missing browser/os/screen_width | C | ExternalSchemaAdapter | Creator corregido M8 añade browser/os/screen_width; facade pública vigente. Requiere decisión explícita de versionado público y auditoría de integraciones antes de retirar. |
| 44 | legacy raw storage truncation API | C | ExternalSubmissionStore | Delete all data está expuesto en configuración y AJAX eipsi_delete_all_data con capability/nonce. Truncation API activa, no orphan. Requiere decisión explícita de versionado público y auditoría de integraciones antes de retirar. |
| 45 | non-A exports legacy routines | F | RawExportService | Desglosar GET legacy: routings con API pública conservada, nonce ausente en routing y XLSX participant con método inexistente. No retirar ni restaurar por analogía. Decisión funcional o de producto posterior; no eliminar como basura. |
| 46 | LONG actions dataset contract; buttons still have no handler | F | Export UI | LONG Excel/CSV son botones visibles con acciones no registradas; deuda funcional de dataset/contrato. Decisión funcional o de producto posterior; no eliminar como basura. |
| 47 | coverage of external deployed servers and historical export copies | E | Privacy CoverageReport/Export | No acceso a servidores externos desplegados/copias históricas; fixture no demuestra ausencia institucional. Se necesita inventario institucional de extensiones, consumidores o datos para decidir. |
| 48 | GET legacy participant XLSX calls missing export_participants_to_excel; dataset equivalence undefined | F | Export | GET participant XLSX llama export_participants_to_excel inexistente; intención dataset no definida. Contrato roto alcanzable. Decisión funcional o de producto posterior; no eliminar como basura. |
| 49 | assets/js/eipsi-random.js | B | Randomization frontend legacy | JS legacy ligado a config/shortcode guardado; retiro condicionado a dataset institucional. Retiro condicionado a la evidencia de convergencia/datos indicada. |
| 50 | src/frontend/eipsi-random.js | B | Randomization source legacy | Fuente histórica distinta del bundle; build/source consumers y contenido institucional impiden asumir duplicado. Retiro condicionado a la evidencia de convergencia/datos indicada. |
| 51 | assets/js/eipsi-randomization-shortcode.php | B | Randomization LegacyConfig/Shortcode | PHP lee _eipsi_random_config y registra shortcode; metadata histórica posible. Retiro condicionado a la evidencia de convergencia/datos indicada. |
| 52 | admin/pool-rest-api.php | C | Pools REST adapter | REST pool-assign usa sesión participante; action/response públicas preservadas. Requiere decisión explícita de versionado público y auditoría de integraciones antes de retirar. |
| 53 | admin/pool-assignment-api.php | C | Pools AJAX adapter | AJAX públicos con contratos distintos; no sustitución ciega por REST. Requiere decisión explícita de versionado público y auditoría de integraciones antes de retirar. |
| 54 | includes/helpers/pool-helpers.php | C | Pools Helpers/Adapters | Funciones globales y caminos por code son boundary público; catálogo por función. Requiere decisión explícita de versionado público y auditoría de integraciones antes de retirar. |
| 55 | admin/randomization-page.php | C | Randomization Admin/Config | Página admin activa y configuración de shortcode guardada. Requiere decisión explícita de versionado público y auditoría de integraciones antes de retirar. |
| 56 | admin/tabs/pool-hub-v2.php | C | Pools admin/dashboard | UI activa eipsi_render_pool_hub_v2; versión en nombre no implica obsolescencia. Requiere decisión explícita de versionado público y auditoría de integraciones antes de retirar. |
| 57 | admin/pool-hub/components/pool-sidebar.php | E | Pools Dashboard | No include interno literal ni carga runtime. PHP template ejecutable con variables de contexto y servicio público; integración externa puede incluir por path. Requiere inventario de themes/extensiones institucionales. Se necesita inventario institucional de extensiones, consumidores o datos para decidir. |
| 58 | includes/class-eipsi-migration-runner.php | C | MigrationRunner | Clase histórica hereda API del owner; preservar símbolo y métodos. Requiere decisión explícita de versionado público y auditoría de integraciones antes de retirar. |
| 59 | includes/migrations/class-migration-runner.php | B | MigrationRunner v1-v10 | Upgrade histórico probado todavía necesita migraciones. Retiro solo al definir mínima versión/datasets soportados; no compactar. Retiro condicionado a la evidencia de convergencia/datos indicada. |
| 60 | scripts/migration-add-offset-columns.php | D | Operación manual/Schema | Script manual de offset, no bootstrap automático; recuperación histórica. Retirar únicamente cuando se retire formalmente el runbook de recuperación. |
| 61 | admin/database-schema-repair.php | B | Schema Repair/Installer | Hook before dbDelta y reparación autorizada de índices históricos; retiro exige convergencia de instalaciones reales. Retiro condicionado a la evidencia de convergencia/datos indicada. |
| 62 | admin/data-safety-system.php | B | Storage/Longitudinal schema | ALTER columnas T* dinámicas en submissions; schema canónico no enumera todos los takes históricos. Retiro requiere transición datos. Retiro condicionado a la evidencia de convergencia/datos indicada. |
| 63 | includes/storage/class-emergency-submission-store.php | B | EmergencySubmissionStore/SchemaInstaller | CREATE de emergencia permite recovery en instalaciones incompletas; no retirar por preferencia arquitectónica. Retiro condicionado a la evidencia de convergencia/datos indicada. |
| 64 | admin/ajax-handlers.php::eipsi_execute_maintenance_sql_handler | E | Schema admin adapter | Action administrativa publicada, capability manage_options + nonce, respuesta fail-closed 501 probada M8. Sin UI interna; consumidores externos desconocidos. No habilitar ejecutor SQL. Se necesita inventario institucional de extensiones, consumidores o datos para decidir. |
| 65 | fix-email-loop-data-repair.sql | D | Operación manual Notifications | SQL de reparación histórica explícita, no cargado en runtime. Retirar únicamente cuando se retire formalmente el runbook de recuperación. |
| 66 | fix-email-loop-verification-tests.sql | D | Operación manual Notifications | SQL verificador manual de recuperación; no bootstrap. Retirar únicamente cuando se retire formalmente el runbook de recuperación. |
| 67 | diagnostic-queries.sql | D | Operación manual Schema/Notifications | Consultas de diagnóstico; conservar recuperación manual, no ejecución automática. Retirar únicamente cuando se retire formalmente el runbook de recuperación. |
| 68 | Randomization histórico sin fingerprint | F | MigrationRunner/Randomization | Identidad histórica sin fingerprint bloquea upgrade; no diseñar mapping sin dataset institucional. Datos se conservan. Decisión funcional o de producto posterior; no eliminar como basura. |
| 69 | Settings draft vs enum canónico | F | Longitudinal StudyConfig/SchemaRegistry | Writer exige draft pero enum canónico no contiene draft. Decisión de producto pendiente, sin cambiar schema. Decisión funcional o de producto posterior; no eliminar como basura. |
| 70 | Collation histórica unicode_ci | B | SchemaInspector | unicode_ci histórico es estado de datos, no código muerto. Retiro/conversión requiere plan de datos explícito; reporting se conserva. Retiro condicionado a la evidencia de convergencia/datos indicada. |

## Contratos públicos

[Firmas individuales](../tests/purga-final/public-contracts-before.json) y [referencias por facade y método](../tests/purga-final/public-consumers.json) incluyen Auth, Participants, Forms, Longitudinal, Notifications, Storage, Privacy, Export, Pools, Randomization y Schema. Los callers calificados se separan de candidatos dinámicos que requieren resolver tipos; ausencia de caller interno no autoriza borrar una API pública. Las funciones globales trasladadas en M1 permanecen como callbacks WordPress, APIs o facades.

## UI y deuda funcional

| Action | Clase | Situación |
|---|---|---|
| eipsi_export_participants_long_excel | F | Botón visible LONG Excel |
| eipsi_export_participants_long_csv | F | Botón visible LONG CSV |
| eipsi_send_individual_reminder | F | Modal incluido por longitudinal-studies-tab |
| eipsi_recalculate_preview | F | Preview en modal activo |
| eipsi_recalculate_waves | F | Aplicación en modal activo; recalculation-panel también emite |
| eipsi_rollback_recalculation | E | Panel sin include interno/runtime demostrado; contrato contextual no identificado |
| eipsi_create_from_clinical_template | E | Binding privado delegado sin botón interno ni handler; nonce/strings se publican en eipsiFormTools. No se puede excluir productor DOM en extensión institucional sin inventario de extensiones. |
| eipsi_get_participant_dashboard | F | Script registrado en survey login/dashboard; refresh apunta a endpoint ausente |
| eipsi_load_form | C | Resuelto M7: action/nopriv autenticadas y form load válido |

Login/join Pools por email: **F**. El acceso histórico por email está bloqueado y requiere sesión; integrar con Auth antes de publicar. No reintroducir email como identidad.

Cancelación legacy: el recorrido público escribe `survey_job_queue`; el owner de queue usa `survey_nudge_jobs`. Exports GET/LONG y estados `draft` conservan inconsistencias descritas; no se restauraron por analogía. Identidad sin fingerprint requiere datos institucionales antes de diseñar transición.

## Notificaciones

Daily/weekly de posts-meta entregan según `_eipsi_random_config` y `_eipsi_toma_*_assign` y generan tokens históricos. Son distintos de asignaciones SQL modernas. Los callbacks daily/weekly deprecated de cron-reminders-handler solo loguean y conservan hooks programados. Wave availability y dropout todavía entregan según condiciones propias; no se demostró duplicación completa. Weekly T1, nudges, polling y eventos mantienen tiempos/batches existentes. `render_template` acepta nombres y resuelve archivos dinámicamente; templates sin caller probado se conservan E.

## Runtime y datos

Worker de nudges se programa mediante `wp`: desactivar/reactivar elimina sus eventos y la siguiente visita frontend vuelve a programarlo cada cinco minutos. La activación por sí sola programa 13 eventos; tras esa visita hay 14. Es un consumer contextual B preservado, no un cambio de PURGA FINAL.

Doble enqueue frontend utiliza el mismo handle y WordPress deduplica la cola; dos invocaciones no prueban doble ejecución. El inventario conserva prioridad/orden, URLs, dependencias y hashes. Fuentes RCT y artifacts tienen hashes diferentes y no son duplicados intercambiables.

Recovery DDL de emergencia, T* dinámicos, schema externo y reparación manual se conservan hasta caracterizar instalaciones admitidas. Migraciones v1–v10 y scripts SQL manuales permanecen. Collation histórica es estado de datos; Inspector sigue informando drift.

Clinical publica nonce y strings en `eipsiFormTools`, aunque el binding privado no tiene productor DOM interno demostrado. Sidebar PHP admite inclusión por path con contexto de variables; rollback está en un panel sin caller interno probado. No se dispone del inventario de themes/extensiones institucionales para excluir esos consumidores externos. Maintenance SQL conserva action autorizada y respuesta fail-closed 501; ningún ejecutor genérico se habilitó.

[Matriz definitiva](../tests/m8/global-retained-matrix.json) y [comandos de verificación](../tests/purga-final/README.md).


## Excepción de seguridad S0: autenticación email-only

Se conservan facades, nombres de actions, nonce y envelopes seguros. `authenticate_passwordless(survey_id,email)` deja explícitamente de autenticar: devuelve `success=false`, `participant_id=null`, `error=proof_required`. Los callers públicos sin password ahora solicitan el magic link existente con respuesta genérica, sin sesión. Registro sin double opt-in tampoco hace auto-login; confirmación sigue activando sin login. Las extensiones que asumían email como credencial deben adoptar password o consumo de magic token.

El delta es revisable en [security-contracts.json](../tests/s0/security-contracts.json) y [boundary-migrations.json](../tests/s0/boundary-migrations.json), sin reemplazar baselines anteriores. SessionService conserva su API, con precondición documentada de prueba de posesión ya validada por el caller. Pools continúa exigiendo sesión canónica: no se habilita el antiguo login/join por email. Las otras deudas B/C/D/E/F permanecen.
