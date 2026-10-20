# Regresiones P0

Suite mínima sin dependencias de PHPUnit ni bootstrap de WordPress. Ejecuta las
funciones y handlers PHP reales con dobles explícitos de WordPress. Solo admite CLI.
No envía emails ni modifica opciones o participantes reales.

```bash
php tests/run-p0.php
```

Dentro del contenedor de este proyecto:

```bash
docker exec wp-eco-wordpress-1 php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/run-p0.php --integration
```

`--integration` requiere mysqli y las variables `WORDPRESS_DB_HOST`,
`WORDPRESS_DB_NAME`, `WORDPRESS_DB_USER` y `WORDPRESS_DB_PASSWORD`. Crea tablas con
prefijo aleatorio `eipsi_p0_test_*`, las elimina en `finally` y prueba los handlers
contra MariaDB usando un adaptador SQL. La identidad, los permisos, los nonces y
el correo siguen siendo dobles controlados: no sustituye una prueba HTTP completa
con cookies reales. La conexión externa se prueba contra la misma MariaDB de
Docker, con tablas aisladas; también se prueba una conexión rechazada y su fallback.

## Flujos y contratos revisados

| P0 | Entrada y callback | Nonce e identidad | Tablas | Consumidores y resultado |
| --- | --- | --- | --- | --- |
| Emergency storage | `eipsi_forms_submit_form` → `eipsi_forms_submit_form_handler` → `eipsi_safety_save_with_retry` → `eipsi_safety_emergency_save` | `eipsi_forms_nonce` en submit; metadata de respuesta en storage | `eipsi_emergency_submissions`, externa o local | Handler de submit y alerta admin. Éxito conserva `emergency_id`, `emergency_mode`, mensaje y `storage`. Fallo devuelve `success=false`; submit utiliza su error existente `SAFETY_SYSTEM_FAILURE`, HTTP 500. |
| Partial debug | `wp_ajax[_nopriv]_eipsi_debug_partial_response` → `eipsi_debug_partial_response_handler` | Ahora `manage_options` + `eipsi_admin_nonce` | `eipsi_partial_responses` | SEC-1 retiró el endpoint y el helper de consola sin consumidor de producto. No se exponen respuestas raw ni se emite su nonce. La retirada se verifica en SEC-1. |
| Retiro | `wp_ajax[_nopriv]_eipsi_abandon_study` → `eipsi_abandon_study_handler` | Conserva `eipsi_abandon_study`; identidad/estudio desde Auth Service, pertenencia comprobada en DB | Principal `survey_participants`; B2 conserva su tratamiento existente de resultados, parciales, dispositivos, logs, links y sesiones | `includes/templates/withdrawal-modals.php`: payload y respuesta normal conservados. IDs ajenos/sin sesión se rechazan antes de escribir; retiro propio conserva estado, logout y redirect. |

## Cobertura

- INSERT local exitoso, fallido con ID previo, CREATE fallido.
- Persistencia real local/externa, rechazo SQL, fallback tras excepción externa.
- Diagnóstico público denegado, admin autorizado, nonce ausente/inválido.
- Retiro de otro participante (B2) denegado sin escrituras.
- Retiro propio B1, falta de sesión, estudio ajeno, pertenencia inconsistente,
  nonce inválido e identidad derivada de sesión sin IDs enviados.

Para contrastar con el código anterior se puede proporcionar
`EIPSI_P0_SOURCE_DIR` apuntando a copias de los dos handlers de una revisión previa.
La suite no genera ni modifica esas copias.

## Límites de esta fase

No cambia la reconciliación de emergencia ni la cobertura del borrado B2.
No prueba el bootstrap completo del plugin, UI de navegador, transporte de email,
WordPress HTTP/nonces reales o administración de cookies. El `phpunit.xml`
histórico no es el runner de estas pruebas; estas se ejecutan con el comando anterior.

## Regresiones P1-A: autorización e identidad longitudinal

```bash
docker exec wp-eco-wordpress-1 php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/run-p1.php
```

Las 40 pruebas usan los servicios de autenticación, participantes y magic links,
los handlers de consentimiento/submit y el renderer reales. SQL se ejecuta mediante
`wpdb` de WordPress contra MariaDB, con las definiciones actuales de SchemaManager.
Cada caso crea tablas `eipsi_p1_test_*` con prefijo aleatorio y las elimina en
`finally`. Requiere WordPress en `/var/www/html` y las mismas variables de DB del
runner P0. No usa participantes reales ni envía correo. Nonces, posts/metadatos,
hooks y funciones auxiliares de WordPress son dobles explícitos; no sustituye una
prueba HTTP completa del plugin con navegador.

### Política final

`EIPSI_Auth_Service::authorize_participant()` exige participante existente,
pertenencia al estudio y `is_active=1`. Rechaza `declined`, `withdrawn` y decisiones
de consentimiento desconocidas, comprobando también los estados equivalentes.
Permite consentimiento pendiente (NULL/vacío) para conservar el acceso previo a
la decisión; esta fase no impone una nueva obligatoriedad global de consentimiento.

`get_current_session()` deriva participante y estudio del mismo token vigente y
revalida la política en cada lectura. Si el estado cambió, revoca esa sesión.
`authorize_session_context()` rechaza IDs cliente diferentes. Para submit,
`authorize_form_operation()` exige además formulario/wave/assignment propios,
con assignment pendiente o en progreso. Email y fingerprint no autorizan identidad
longitudinal. Formularios publicados vinculados a waves requieren sesión incluso
si el cliente omite contexto; los independientes siguen siendo anónimos salvo su
bandera de login. El fingerprint conserva su función de tracking de parciales.

### Recorridos auditados antes y después

| Recorrido | Antes | Después |
| --- | --- | --- |
| Login → participante → sesión → cookie → lectura | Contraseña comprobaba activo/rechazo; passwordless agregaba retiro. La lectura de sesión no cubría todos los cambios de estado. | Ambos logins y creación/lectura de sesión aplican la política común; token hash y duración conservados. |
| Magic link → token → participante → sesión → estudio | Token/uso/vencimiento sin política uniforme del participante. | Mantiene esas comprobaciones y agrega política/pertenencia antes de crear sesión; el caller solo consume el enlace si la creación tiene éxito. |
| Sesión existente → participante → formulario | Helpers legacy podían leer IDs de cookies; acceso al formulario podía omitir pertenencia. | Token autoritativo y revalidación; formulario longitudinal y shortcode exigen el estudio de la sesión. |
| Consentimiento → IDs → sesión → estado | ID del cliente podía prevalecer; sesión era fallback. | Nonce `eipsi_forms_nonce` conservado, identidad de sesión y comprobación de IDs/formulario antes de cambiar estado. Aceptar/rechazar propios y redirect se conservan. |
| Submit → identidad → study → wave → assignment → persistencia | Podía resolver participante por email/fingerprint y contexto cliente. | Nonce original conservado; sesión → participante autorizado → estudio → wave/formulario → assignment antes de guardar. Metadata y respuesta usan identidad canónica. |

Las tablas involucradas son `survey_participants`, `survey_sessions`,
`survey_magic_links`, `survey_waves`, `survey_assignments`, `vas_form_results`
y `eipsi_partial_responses`; no se modifica schema. El contexto interno
`longitudinal_participant_id` se excluye del INSERT local y se conserva solo para
la sincronización autorizada. Envíos anónimos ya no sincronizan participantes por
email. Los consumidores siguen usando los callbacks/APIs y respuestas existentes;
las nuevas denegaciones de consentimiento/submit devuelven error antes de persistir.

### Cobertura y límites

Incluye los 14 escenarios solicitados, contraseña/passwordless, estado modificado
después del login, pertenencia cambiada, sesión vencida/participante eliminado,
links usados/vencidos, consentimiento propio/ajeno, submit con email de B bajo
sesión A, contexto falsificado, assignment ausente/cerrado, persistencia real y
tracking de parciales. También verifica render y formularios anónimos con y sin
una sesión longitudinal abierta. Ejecutar asimismo las 22 pruebas P0.

Fuera de este bloque: autenticación passwordless inicial por email conserva su
contrato; no se resuelve concurrencia entre validación/consumo de magic links,
atomicidad entre guardar respuesta y actualizar assignment, ventanas temporales,
waves/nudges, pools ni exports. Los hooks se aíslan: esos subsistemas no quedan
validados por esta suite. Los helpers legacy restantes no se eliminan y otros
endpoints no se consideran auditados por estas pruebas.

## Regresiones P1-B

```bash
docker exec wp-eco-wordpress-1 php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/run-p1b.php
```

40 casos de contratos AJAX, persistencia/read-only de waves, copia T1/T2/T3 y
nudges. Reutiliza las tablas aisladas y `wpdb` real del runner P1-A, agrega la cola
`survey_nudge_jobs` y auditoría con schema actual. Dos casos inyectan rechazo SQL
mediante triggers de fixture, eliminados al borrar sus tablas. WP-Cron es un doble
que registra eventos y permite simular fallos; no programa eventos reales ni envía
correo. `EIPSI_TEST_FILTER` permite seleccionar casos por parte del nombre.

Mantener verdes los 22 P0, 40 P1-A y 40 P1-B: 102 casos. La auditoría, matrices de
campos/consumidores y riesgos están en
`wp-eco/informes/EIPSI-Forms/2026.10.03 - Estabilización P1-B.md`.


## Regresiones P1-C: exports y privacidad

```bash
docker exec wp-eco-wordpress-1 php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/run-p1c.php
```

45 casos nuevos con `wpdb` y MariaDB reales, tablas de schema actual con prefijo
aleatorio y archivos temporales aislados. Cubre longitudinal/roster/wide/pool,
CSV/XLSX y errores de escritura; export personal y descargas autorizadas;
privacidad en submit, metadata, parciales, events, emergencia, acceso y device;
anonimización, columnas T*, hard delete y B2, con fallos SQL mediante triggers.
Comprueba también cobertura externa incompleta y preservación de registros con
identificadores de navegador compartidos. No envía correo ni toca participantes
reales. El estado de WordPress, permisos, nonces y cookies sigue siendo un doble:
no reemplaza una prueba HTTP del plugin completo.

Las 102 pruebas previas permanecen sin cambios de escenarios. Total: **147 casos**.
El inventario de destinos, la semántica final, los archivos modificados y los
límites se documentan en `informes/EIPSI-Forms/2026.10.03 - Estabilización P1-C.md`.
El límite de B2 descrito en la sección histórica P0 corresponde a aquella fase;
P1-C corrige ahora su limpieza local y la comprobación de resultados.

## M0: caracterización post-estabilización

[M0 sobre WordPress real](m0/README.md) documenta el entorno Docker aislado, el build limpio, la suite de 36 casos, el inventario ejecutable y el manifiesto de PURGA 1. Conserva los 147 casos anteriores: total **183**. Las nueve pruebas de deuda UI caracterizan handlers ausentes; no equivalen a flujos funcionales restaurados.

## PURGA FINAL post M1–M8

[Fixtures, comandos y contratos congelados](purga-final/README.md). Conserva 739 casos previos y añade 15 regresiones de purga: **754** únicos. Clean install y upgrade histórico separados; 401 verificaciones repetidas sobre upgrade no inflan el total. No se implementan features ni se hace release.

SEC-3 experimental confidentiality: `run-sec3.php` covers actual participant HTML/AJAX, V1/V2/V3 seed/candidate minimization, current-page projection, forged future answers, options, media permissions and history. `run-sec3-logical.php` covers real Study/Native GO_QUESTION (including same-page), Jump, Wait and forged transitions through the participant boundaries. `assessment-editor/sec3-dom.js` covers server-loaded page navigation, no client draw/storage, initial condition check, scientific completion metadata and same-page projection updates. They run through the existing `php`, `js` and `full` CI gates. The human helper and its isolated Docker/loopback setup live outside the plugin under `informes/EIPSI-Forms/SEC-3-evidence/human`.

### API-0 — External API Foundation

`python3 tests/api0/run.py` runs the permanent API-0 integration suite using the same pinned disposable Docker infrastructure as CI. `./tests/ci/run.sh full` also runs API-0, its four admin DOM regressions, M8 migration/upgrade checks, security suites, RCT and lifecycle/smoke. The suite creates and cleans its own keys, Studies and temporary HTTP environment; no human-review project or private token file is required. API credentials are never passed on command lines by test workers.

### External API — Study Discovery (API-1)

`python3 tests/api1/run.py` runs the permanent 56-case discovery suite against disposable CI containers. `./tests/ci/run.sh full` includes API-1 and API-0 alongside SEC-0/1/2/3, Study Engine, strict RCT, build/check-build, clean install, upgrade, lifecycle and smoke. The API-1 suite exercises HTTP Bearer access, current scope/grants, logical Study DTO, opaque key-bound cursors, controlled administrative clocks, 525 Studies and independent-process races. It creates and removes its own keys and Studies, and opens no host ports or human-review panel.

### External API — Study Data Export (API-2A)

`python3 tests/api2a/run.py` runs Management (25 cases) and the permanent 62-case API-2A suite in disposable CI containers. It checks JSON/CSV/dictionary, version/cycle history, policy/partials, Native types and randomization statuses, Management parity, live cursors, key/grant/privacy races, moderate load and explicit capacity failures. Synthetic response examples are archived in the runner's `api2a-examples.log`; no real participant data or API credentials are included. FULL includes API-2A, API-1/API-0, security and strict RCT. No human-review environment or host port is created.

### API-2B — immutable datasets and scalable delivery

`python3 tests/api2b/run.py` runs Management plus the permanent API-2B suite in
an isolated disposable WordPress/MariaDB environment. FULL also runs API-2B.
It covers scientific parity with API-2A/Management, source manifests, immutable
pages/dictionaries, CSV/NDJSON, Range, quotas, expiry, ownership and current
scope/grants, privacy/hard deletion, corrupt/missing chunks, real process races,
SIGKILL recovery, cleanup safety and additive migration 18 → 19. The permanent
capacity fixture exceeds 10 MiB and verifies API-2A's explicit 422 versus an
intact API-2B download.

`python3 tests/api2b/performance.py` runs the separate 50,000-row performance
fixture (250 completions × 200 questions) and records creation/materialization,
memory, download integrity/throughput and cleanup metrics. It does not inflate
FULL's count or claim an institutional benchmark. Both runners use the existing
private CI infrastructure, capture mail locally, and remove their containers and
volumes. No human fixture, published port or bridge is created. Child-process
credentials are passed through stdin; temporary barrier directories are 0700.

### API-3 — operations and deployment hardening

`python3 tests/api3/run.py` runs Management (25 cases) and API-3 (46 cases),
including bounded settings, administrative authorization/CSRF/origin, health,
retention, disable semantics, terminal artifact operations, offline TLS/proxy,
CLI adapter, upgrade 19 → 20 and 100 keys / 1,000 exports / 12,000 audit events.
The runner archives `php-api3-performance.log`. Six administrative DOM cases
run through the JS/FULL gate. All fixtures use disposable CI databases; no
human-review panel or published host port is created. The WP-CLI adapter is
tested with a stub; production WP-CLI installation is an API-4 deployment check.

### API-4 — documentation and runtime parity

`python3 tests/api4/run.py` runs eight permanent PHP contracts plus Management
and an AJV check of observed HTTP responses. `tests/api4/openapi.js` adds six
static OpenAPI/YAML/JSON-schema checks to JS/FULL; FULL also validates the
observed schemas after the API suites. Exact WordPress routes/methods, columns,
query/body parameters, errors/private headers, snapshot Range and executable
examples are tested. Python runs in the pinned WP container if available;
otherwise that tooling limit is printed, and the host example is exercised
against the isolated human fixture. Rscript is not installed here; R execution
is not claimed. No new dependency is required for OpenAPI: existing js-yaml/AJV.
Human helpers and approval records are outside the plugin in the API-4 report
evidence; they are not a substitute for the permanent regression suite.

## SCH-1 — Scheduler / WP-Cron Management

Targeted: `python3 tests/sch1/run.py` en WordPress/DB descartables; DOM:
`node tests/sch1/admin-dom.js` con el contenedor Node del gate. Ambas suites están
incorporadas a FULL. Cubren parser/ownership, escritura atómica y fallos, cron
real frente a carga normal, diagnóstico de sólo lectura, health, permisos,
frontera multisitio, comandos, UI y owners existentes. No modifican la config
principal ni el crontab del host.

Guía: [Scheduler and WP-Cron Guide](../docs/scheduler/Scheduler%20and%20WP-Cron%20Guide.md).
