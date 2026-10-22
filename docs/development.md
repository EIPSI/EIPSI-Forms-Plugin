# Desarrollo

## Gate local y CI T0

Instalar Docker/Compose con acceso al daemon, Bash y Python 3.9+. Ejecutar `./tests/ci/run.sh fast` o `./tests/ci/run.sh full` desde el plugin; FULL es el modo por defecto. El wrapper usa imágenes fijadas, instala npm desde el lockfile si faltan dependencias y crea bases descartables independientes del WordPress de desarrollo. Tras modificar el lockfile, ejecutar nuevamente npm ci según [setup T0](../tests/ci/README.md).

FAST ejecuta 1936 tests (965 PHP legacy + 127 de contratos + 157 compiler/simulator + 30 de checkpoint/crypto + 85 de persistencia DB + 110 de Content/runtime + 123 longitudinal + 84 privacy DB + 12 privacy puros + 51 Builder DB + 33 Builder puros + 25 Pilot/operacionales + 134 JS), gate estricto RCT y build. FULL añade seis tests smoke sobre otra instalación nueva, con deactivate/reactivate: total T0 1037. B0 agrega activación inicial y reactivación admin con cero bytes inesperados: total B0 1039. Dos regresiones de guardado de storage llevan el total anterior a P1-B a 1041 (973 PHP + 68 JS). P1-B suma 127 tests de contratos puros: total P1-B 1168 (1100 PHP + 68 JS). P1-C añade 157 tests nuevos: total P1-C 1325 (1257 PHP + 68 JS). P1-D suma 115 tests: total P1-D 1440 (1372 PHP + 68 JS). P1-E añade 120 tests: total P1-E 1560 (1482 PHP +78 JS). P1-F añade 129: total P1-F 1689 (1605 PHP +84 JS). P1-G agrega 106: total P1-G 1795 (1701 PHP +94 JS). Los fallos muestran etapa/comando/exit code y guardan logs en `.cache/ci/`. GitHub ejecuta el gate técnico y la matriz de compatibilidad en PR/push a develop y cachea únicamente npm; no publica builds/releases ni necesita secretos institucionales. Requisitos y versiones canónicas, tiempos, checks recomendados y límites: [T0](../tests/ci/README.md).

El gate no certifica SMTP, Nginx, datasets institucionales, E2E visual, performance, exactly-once ni atomicidad global. El upgrade histórico completo sigue siendo check manual/pre-release con los fixtures existentes.

## Compatibilidad e instalación vigentes

Requisito WordPress: **7.1**. PHP declarado: **7.4**, sin elevarlo por usar 8.3 en CI. La matriz, cobertura por versión y límites están en [compatibilidad](compatibility.md). El [procedimiento de instalación y packaging](installation.md) distingue ZIP fuente del paquete con build. Las secciones de fases y conteos inferiores son históricas; `tests/ci/gate.json` registra el gate actual.

## Instalación y build

Desde la raíz del plugin, con Node compatible con el lockfile:

```sh
npm ci --no-audit --no-fund
npm run build
```

No ejecutar scripts históricos de automatización que hagan commits/push como parte de este procedimiento. El script npm de build produce 13 bloques en `build/blocks/`; mantener `package-lock.json` sin regenerarlo incidentalmente. Para trabajar en el editor, `npm start` inicia el watcher.

Ubicar el plugin en `wp-content/plugins/EIPSI-Forms-Plugin`, generar el build y activar desde WordPress. La instalación limpia reproducible está en [M0](../tests/m0/README.md); usa una base descartable distinta del entorno de desarrollo. El compose M0 es un fixture local, no una configuración de despliegue institucional.

## Fuentes de verdad

Metadatos y atributos de bloques: `src/blocks/*/block.json`. Tokens CSS: [eipsi-tokens.css](../assets/css/eipsi-tokens.css). Importación/exportación JSON: [form-library-tools.php](../admin/form-library-tools.php). No se mantiene una tabla duplicada de atributos o colores.

Correo: los servicios bajo `admin/services/` usan el transporte de WordPress y su configuración. No inferir entregabilidad por un log ni ejecutar cron de estudios reales para probar el transporte; las pruebas usan dobles/interceptación. Los recorridos legacy siguen activos y deben caracterizarse antes de migrarlos.

## Versión del código y distribución

La versión de fuente está en el header de `eipsi-forms.php`; actualmente coincide con su constante y los metadatos npm. Los `@since` y los contratos JSON no son versiones de distribución. El packager deriva la versión del header y no publica releases. No se añade un badge de release ni se infiere certificación a partir de ese número. Ver [instalación](installation.md).

## Alcance actual

Trabajar sobre `develop`; `origin/main` es la referencia de integración. `feature/*` es una convención propuesta, no una enumeración de ramas certificadas. M0–M8 y normalización S0–S4/S4.1 están completados en el alcance auditado. T0 añade CI mínimo; no hay E2E visual completo ni certificación institucional. Consultar [arquitectura](architecture.md) y [testing](testing.md) antes de modificar contratos.

## Cambiar el bootstrap

Mantener el manifest de ServiceLoader en orden y ejecutar sus requires desde la composición global. Las entradas `:migration` y `:survey-access` documentan límites de inicialización, no archivos. No convertir cargas actualmente universales en admin-only sin caracterización nueva.

Registrar mediante el owner adecuado y preservar callback, prioridad, accepted args y orden respecto de otros registros. Las funciones globales de compatibilidad no deben retirarse por existir una clase. Lifecycle usa `EIPSI_FORMS_PLUGIN_FILE` para conservar la identidad de los hooks de activación y la ruta de traducciones.

Los cron por estudio/asignación continúan programándose en sus recorridos de dominio. Actualizar el catálogo explícito de CronRegistry cuando se demuestre un nuevo hook propietario; no limpiar todos los nombres con prefijo `eipsi_`. [Pruebas de contratos y ciclo de vida](testing.md).

## Cambiar Auth/Participants

La semántica P1-A vive en `EIPSI_Authorization_Policy`. No agregar decisiones alternativas en handlers, shortcodes o servicios: usar sus facades de autorización y derivar participante/estudio de la sesión. Los owners Auth gestionan exclusivamente autenticación, sesiones y magic links; ParticipantRepository está limitado a participantes y consultas de contexto específicas, no es un repositorio universal.

Mantener las facades en `admin/services/`: sus firmas forman API compatible, incluso cuando no aparece un caller interno. Los adapters WP siguen validando request/nonce/capability y formando respuestas; Registration/Import/State ejecutan operaciones del dominio. Los servicios de correo, asignaciones, anonimización y Pools continúan siendo dependencias externas. No cambiar durations, cookies, tablas, columnas, action names o redirects incidentalmente.

Ejecutar las 214 regresiones anteriores y la suite M2 real descrita en [testing](testing.md). El baseline de APIs M2 procede de `develop` en `2775faa`; no regenerarlo desde el resultado de un cambio para ocultar una regresión. La allowlist M1 añade únicamente los nueve includes M2 enumerados; no excluye hooks/REST/AJAX de la comparación.

## Cambiar Forms/Submit

El adapter AJAX conserva nonce y request de WordPress (incluido su slashing). `EIPSI_Submit_Service::submit($request, $query, $server)` devuelve un resultado interno; `EIPSI_Form_Response::emit` conserva los JSON/status públicos. Capture mantiene filtros P1-C antes de persistir. El servicio no vuelve a inferir identidad: delega en la facade Auth y su Policy; el flujo anónimo mantiene identidad longitudinal cero.

El adapter Storage llama las tres funciones originales de Data Safety. El adapter longitudinal en `admin/services/` delega los seis argumentos históricos a un contexto del comando M4. No extender reglas ni SQL longitudinal en Forms. No borrar facades globales ni `EIPSI_Partial_Responses` por no encontrar callers internos.

Editar las fuentes ordenadas en [manifest.json](../src/frontend/forms/manifest.json), no el artifact generado. Los archivos `.js.inc` son fragmentos del mismo closure clásico; algunos contienen métodos de un objeto compartido y no son scripts independientes. No cambiar orden, globals, nombres, selectores ni convertir a imports sin caracterización adicional. El build usa terser con `compress:false` y `mangle:false`; conserva la URL/handle y localizations existentes. `npm run start` sigue siendo el watcher histórico de Gutenberg: después de editar Forms ejecutar `node scripts/build-form-runtime.js` o `npm run build`.

La fixture `tests/m3/runtime-baseline.js` es exclusivamente de pruebas; no restaurarla como fuente de producto. La composición M3 debe reproducir su hash hasta que se autorice un cambio funcional. Las pruebas comparan DOM anterior/generado y congelan límites críticos. Actualizar baselines requiere explicar qué contrato cambia; no regenerarlos para hacer pasar una regresión.


## Cambiar Longitudinal

M4 incorpora `includes/longitudinal/`: Studies, Wave definitions, Assignments, T1 y el comando de submit. Consultar el mapa de owners en [arquitectura](architecture.md). Los adapters WP validan nonce/capability; facades públicas mantienen firmas y formatos. Definition bootstrap carga solo clases, sin nuevas programaciones.

Mantener índices base uno y fechas/offsets/window en minutos. No convertir los distintos selectores cron en una nueva regla clínica. Usar AssignmentTransitionService para status/timestamps y locks; los cron seleccionan candidatos y delegan. Si un compare-and-set pierde, no ejecutar efectos como si hubiera ganado. Preservar el límite Storage ya confirmado → transacción Assignment → trabajo post-commit.

No mover Notifications ni Pools a este dominio. El puente de entrega conservado en Wave_Service es compatibilidad temporal para M5. No restaurar UI de recalculation sin definir previamente un contrato. Mantener las 348 regresiones previas y ejecutar las 55 M4, incluidas conexiones MariaDB concurrentes; ejecutar suites secuencialmente en M0.

La purga asociada elimina exclusivamente siete métodos privados A trasladados; las facades C siguen siendo API compatible aunque un caller interno desaparezca. No reutilizar esa clasificación para eliminar métodos o archivos adicionales. Los baselines de contratos proceden del HEAD anterior `7eabedf`; las cuatro excepciones M3 están registradas y conservan hashes históricos.

## Desarrollo de Notifications (M5)

Modificar política en `includes/notifications/nudges/class-nudge-policy-service.php`; scheduler en `class-nudge-schedule-service.php`; persistencia en Queue; consumo en Worker; transporte y logs en `email/`. Las facades históricas siguen siendo contratos públicos. El estado temporal clínico se modifica únicamente mediante owners Longitudinal. No agregar SQL de schema ni trasladar Pools/Storage/Privacy/Export a Notifications.

Los templates se mantienen intactos; cambios de contenido deben ser una tarea explícita. No eliminar pipelines por su nombre legacy: posts/meta aún pueden activar envíos. Las purgas M5 se documentan en `tests/m5/purge-manifest.json`, con hash y prueba de sustitución. La migración de hashes M3 usa una allowlist explícita y conserva sus hashes previos; no regenerar baselines históricos desde el código modificado.

Los fixtures M5 solo admiten la DB descartable M0, correo interceptado y cron automático deshabilitado. Los workers concurrentes declaran DOING_CRON para impedir que el wake-up de visitas consuma el job antes de la barrera. El runner restaura cron, elimina IDs propios y no usa la base del workspace.


## Desarrollo M6

Las APIs anteriores conservan firmas y registros. Nuevas reglas de persistencia van en `includes/storage/`; CapturePolicy/cleanup/coverage en `includes/privacy/`; datasets y archivos/download en `includes/export/`. Forms conserva identidad/autorización y comandos Longitudinal. No enlazar device local mediante un ID externo ni añadir SELECT * al dataset personal.

Storage success significa INSERT confirmado; leer `verification_status` para distinguir unsupported de not_verified. Un fallback local conserva destino real y código primario; no hacer dual-write. Los helpers DDL y schema actuales permanecen sin cambios. La creación legacy de una tabla externa vacía omite columnas utilizadas por INSERT (browser/os/screen_width); se caracteriza como deuda previa y puede caer a local. La fixture externa viable se prepara con el schema ya estabilizado, sin migración de producción.

No inferir cobertura global del success de cleanup. Backups, servidor, email entregado, exports históricos, externo, enlaces ambiguos y texto libre requieren políticas futuras. Una solicitud delete usa anonymize y retiene respuestas; B2 sí elimina respuestas vinculables. Approval aplica CAS; no promete exactly-once frente a fallos de proceso después de reclamar processing.

Nuevos archivos admin bajo exports requieren deny HTTP. Apache usa los archivos de control versionados; configurar deny equivalente en Nginx. La UI consume download_url autenticada, manteniendo filename. Los archivos históricos no se borran. Personal sigue fuera del webroot, 0600 y sin expiración inventada.

Para cambios posteriores usar los comandos y guardas de [pruebas M6](../tests/m6/README.md). No alterar snapshots históricos M3/M4/M5 para ocultar cambios: `boundary-migrations.json` registra la transición M6 explícita y M1 solo permite owners de definición, dos nuevos hooks y la migración de path XLSX.

M6 también corrige la lectura de credenciales externas cuando el IV binario contiene `::` o termina en `:`. Se lee su longitud fija de 16 bytes; el formato almacenado, cifrado y API no cambian. Dos regresiones deterministas cubren el defecto previo.


## M7 boundaries

Implement pool domain rules in `includes/pools/`, random configuration/assignment/algorithm/override rules in `includes/randomization/`. Keep public callbacks in their historical files as compatibility facades; register them only there. Definition owners must not register copied hooks during require. REST and AJAX keep different DTO/status contracts. Configuration results are transport-neutral; the REST adapter constructs WP_REST_Response.

Use Auth session identity for participant-facing pool mutations, never email/fingerprint/client participant_id. Administrative IDs require the existing capability and nonce. Use M3 FormRenderer for form loading and M6 export query/file owners for roster exports; do not migrate those services into M7. Preserve postmeta and old JS pending saved-content evidence. The purge manifest records four removed private/copy implementations; no legacy JS file was deleted.

Do not change RNG bounds, fallback, seed source or weighting when extending tests. Existing assignment precedes late override; a first override precedes algorithm. Assignment locking has a five-second timeout and must release on errors. The current schema's unique pool participant key and lack of unique daily analytics key remain unchanged and require future policy decisions.

## M8 — Cambios de schema

Usar Registry para definición, Installer para creación, migrations para transformaciones conocidas y Repair para drift aditivo. No escribir otro schema map ni añadir DDL incidental al submit. No borrar fallbacks históricos antes de demostrar convergencia. Añadir migraciones con SQL comprobado y checkpoints por versión; instrucciones completas en [schema.md](schema.md).

M8 no cambia policy de uniques, tipos de IDs, estados de estudios ni una versión pública. Una forma de identidad desconocida debe fallar explícitamente y conservar datos. GET_LOCK serializa exclusivamente la misma instancia DB/prefix. Los scripts históricos no son automáticamente descartables.

## Study Engine P1-D

Persistencia interna y Execution Owner: [contrato técnico](study-engine/persistence-execution.md). Nuevos runners `tests/run-study-engine-persistence-unit.php` (30 puros) y `tests/run-study-engine-persistence.php` (85 MariaDB). Cierre histórico P1-D: **1440 únicos (1372 PHP + 68 JS)**; FAST P1-D: 1432. Sin endpoints/UI/SMTP ni conversión legacy.

## Study Engine P1-E

Slice público/anónimo opt-in: [Content Adapter y runtime cross-sectional](study-engine/content-cross-sectional.md). Snapshot pinneado, recuperación HttpOnly, páginas/conditions/order del engine y Storage local+journal en la misma transacción. 44 tests de contenido,66 HTTP/DB y10 DOM nuevos; FULL1560 únicos (1482 PHP+78 JS), FAST1552. Sin migración legacy, emails, workflow longitudinal ni Builder.

## Study Engine P1-F

[Acceso longitudinal y notificaciones](study-engine/longitudinal-access-notifications.md): EMAIL_LONGITUDINAL opt-in, identidad Study-scoped, sesiones provisionales/autenticadas, capabilities de un uso, verificación y outbox integrado a S2. P1-F agrega 123 DB/HTTP y 6 DOM; FULL 1689 únicos (1605 PHP +84 JS), FAST 1681. Retiro completo y Builder quedan fuera de esta fase. Deploy requiere keyrings, HTTPS, cron y validación operacional de SMTP.

## Study Engine P1-G

[Retiro, privacidad y export](study-engine/withdrawal-privacy-export.md): B1/B2 con AccessContext, exclusión analítica inmediata y cleanup local recuperable. Adapter de export versionado, coverage y tombstones explícitos. Agrega 106 únicos (12 PHP puros +84 DB/HTTP +10 DOM); FULL 1795 (1701 PHP +94 JS), FAST 1787. Sin Builder/P1-H; deploy condicionado a upgrade v14, secrets, HTTPS y cron.

## P1-H — Visual Study Builder

El Builder administrativo añade 124 casos (51 DB/API/HTTP, 33 puros, 40 DOM). Total vigente FULL: **1919 únicos: 1785 PHP +134 JS**; FAST: **1911**. Gate estricto RCT, build, clean smoke y lifecycle verdes. Los totales anteriores documentan fases históricas. [Arquitectura, uso, límites y verificación](study-engine/study-builder.md). Sin migración legacy automática ni certificación institucional/SMTP real.

## P1-I — piloto y operación (abierto)

FULL vigente **1944 únicos:1810 PHP +134 JS**, FAST1936; añade25 casos de integración (10 Builder→runtime→export,5 canje HTTP,10 operación). GET de email ya no consume la capability: requiere confirmación POST explícita tras redirect limpio. Gate/RCT/build/smoke/lifecycle verdes. **P1-I no cerrado: smoke humano Builder/participante/accesibilidad/visual pendiente por decisión del responsable.** Readiness: CONDITIONAL GO sólo tras checklist operacional y humano; no deploy institucional certificado. [Evidencias de alcance, runbook y límites](study-engine/pilot-readiness.md). Sin commit/push/tag/release.
