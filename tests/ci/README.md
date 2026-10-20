# T0 — Gate técnico mínimo

Conteo vigente verificado en el cierre I18N-1D: **3831 funcionales** = 2479 PHP WordPress + 525 PHP puros + 827 JS, según `gate.json`. Los conteos inferiores describen baselines históricos. RCT strict, smoke, activación y harness se reportan aparte; los casos repetidos no se suman dos veces.

Un único entorno Linux con Docker Engine, Docker Compose v2.20+ (soporte `up --wait`), Bash y Python 3.9+. El wrapper usa solo la biblioteca estándar Python. Referencia local: Python 3.12.3 / Compose 5.3.1. No exige PHP, Node ni WordPress instalados en el host.

## Setup y comando local

Desde la raíz del plugin:

```sh
./tests/ci/run.sh fast
./tests/ci/run.sh full
```

El modo por defecto es FULL. El wrapper resuelve sus paths desde su archivo, por lo que puede invocarse desde otro directorio. Docker debe ser accesible al usuario; no insertar `sudo` dentro del script. El primer uso descarga las imágenes fijadas y, si falta `node_modules/.package-lock.json`, ejecuta `npm ci --no-audit --no-fund` en Node Docker. Después de cambiar el lockfile, volver a instalar con:

```sh
docker run --rm --user "$(id -u):$(id -g)" -e HOME=/tmp -v "$PWD:/app" -w /app node@sha256:363e1587494626837fa7f9a23bdb453d13b0ff3c67c705c2805cfc69c2d2fad7 npm ci --no-audit --no-fund
```

FAST ejecuta los 134 JS, build real/validación de artefactos, 359 PHP puros (127 contratos + 157 compiler/simulator + 30 checkpoint/crypto +12 privacy +33 Builder) y los 965 PHP legacy + 85 de persistencia DB + 110 Content/runtime + 123 longitudinal +84 privacy DB/HTTP +51 Builder DB/API/HTTP +25 Pilot/readiness/operaciones, más el gate estricto RCT. PHP necesita WordPress/MariaDB por sus integraciones reales: FAST también instala un fixture nuevo. Build se hace antes de PHP porque `build/` está ignorado por Git y varias pruebas HTTP necesitan esos assets.

FULL añade **otra instalación nueva**, seis smoke tests representativos, dos casos de activación limpia y deactivate/reactivate con repetición del mismo smoke. No vuelve a ejecutar las suites exhaustivas. Con imágenes/dependencias locales, FAST cuesta unos 3 minutos y FULL añade unos 15 segundos en el equipo de referencia; primer download y runners remotos pueden tardar más.

Los modos `php`, `js`, `build` y `smoke` son entradas de los cuatro jobs CI, disponibles para diagnosticar una etapa. `php` y `smoke` generan primero el build que necesitan; no comparten ni publican artefactos entre jobs. Es una pequeña repetición deliberada del build, sin packaging/release.

## Versiones y aislamiento

| Componente | Versión canónica |
|---|---|
| PHP | 8.3.35, con mysqli en imagen WordPress |
| WordPress | 7.1.2 |
| MariaDB | 11.8.9 |
| Node | 22.23.3, npm 10.9.9 |
| Plugin/schema | EIPSI_FORMS_VERSION vigente; migration12,37 dominios |

Los tres digests Docker y los conteos están en [gate.json](gate.json). El wrapper comprueba las versiones reales y evita tags `latest` mutables. No es una matriz de compatibilidad con los mínimos históricos PHP 7.4 / WordPress 5.8 del header.

Cada etapa WordPress crea un project Compose aleatorio con volúmenes nuevos, sin puertos publicados, y destruye exclusivamente ese project al finalizar, incluso tras fallo. El código host se monta RO; las suites que escriben fixtures trabajan en una copia temporal. Exports se guarda en su propio volumen. La red es interna: HTTP del fixture y DB están disponibles, sin salida a servicios institucionales. Se reutilizan instalador/guarda M0, auxiliares M6/M8, probe de export M6 y lifecycle M7.

La subred local por defecto es `10.236.0.0/24`, para no depender del pool automático agotado en algunos workspaces. Si ya está ocupada o se lanzan gates locales concurrentes, usar una red libre, por ejemplo `EIPSI_CI_SUBNET=10.236.1.0/24 ./tests/ci/run.sh full`. No se borra ninguna red ajena. En GitHub cada job tiene su propio runner. Credenciales `m0-*` y `m8-noalter` son públicas y exclusivamente descartables. No hay Composer ni secretos institucionales.

`mariadb-client.cnf` desactiva TLS únicamente para clientes administrativos del fixture que usan su socket Unix local. Evita una carrera reproducida del entrypoint con un certificado recién generado todavía no válido. No cambia TLS del servidor ni constituye configuración de despliegue. Las conexiones de producto y assertions SQL mantienen el protocolo de los fixtures anteriores.

## Suites y conteo

PHP, orden estable: P0 con `--integration`, P1, P1B, P1C, M0–M8 (M6-live después de M6), PURGA FINAL, S0, S1, S2, S3, S4, S4.1 y M1 debug-off. **965 PHP** (963 anteriores + dos regresiones de guardado AJAX de storage). El gate estricto selecciona S4-03 del runner S4 y lo repite, sin agregar otro caso al conteo.

M1 valida que la versión de `eipsi-privacy-dashboard` sea exactamente el `filemtime` de `admin/js/privacy-dashboard.js` en el checkout actual. Solo ese valor se representa con un marcador en el baseline: Git no preserva timestamps entre checkouts. Los demás campos del asset se comparan sin cambios.

JS: M3 source-contracts 15, M3 runtime-dom 26, M7 frontend-load 3, S3 consumers 13, S4 consumers 3, S4.1 consumers 8. **68 JS legacy +10 Study runtime DOM +6 longitudinal DOM =84 JS; +10 privacy DOM =94 JS; +40 Builder DOM =134 JS**, sin reemplazarlos por lint.

Build: `npm run build`, 13 bloques con archivos/refs válidos y runtime Forms equivalente a los 18 fragmentos del manifest. El verificador es infraestructura y no suma tests de dominio.

Clean smoke añade **6 tests de integración reales**: plugin/schema/version; carga pública/admin; shortcode/bloque Forms válidos; página Forms/runtime HTTP; submit anónimo con persistencia SQL; servicio RCT con reset propio. Se repiten después del lifecycle y no se cuentan dos veces. Baseline preservado: **1031 tests únicos**. Total con T0: **1037 (969 PHP + 68 JS)**. B0 añade dos casos reales: primera activación y reactivación admin desde procesos nuevos, con bootstrap completo y exactamente cero bytes inesperados. B0 deja **1039 (971 PHP + 68 JS)**. Las dos regresiones posteriores de guardado AJAX de storage llevan el total anterior a P1-B a **1041 (973 PHP + 68 JS)**. El fixture smoke usa `WP_DEBUG=true` y `WP_DEBUG_DISPLAY=true` para que los errores de activación sean detectables; el fixture PHP conserva su configuración anterior. Scripts, validadores de artefactos y ensayos de propagación de errores no aumentan el conteo. Al agregar casos reales a una suite, actualizar su conteo en gate.json; no reducir assertions para satisfacerlo.

## CI y fallos

[technical-gate.yml](../../.github/workflows/technical-gate.yml) corre en pushes a `develop`, PRs hacia `develop` y dispatch manual. Checks independientes: **PHP tests**, **JS tests**, **Build**, **Clean smoke**. Todos ejecutan el mismo wrapper local. GitHub instala dependencias con `npm ci` y usa caché npm estándar; no cachea node_modules, WordPress, bases ni resultados. Solo permiso `contents: read`; sin deploy, publicación o cambios de protección de rama.

Fail-fast por etapa/suite: imprime comando, etapa y exit code, más las últimas 60 líneas cuando falla. stdout (`<etapa>.log`), stderr (`<etapa>.stderr.log`) y `summary.json` quedan en `.cache/ci/<run>/`. Solo stdout se interpreta como resultado del comando: la descarga inicial de Docker en stderr no contamina `node --version`. Antes de las etapas se ejecutan tres regresiones del harness que cubren descarga fría, versión incorrecta y exit code fallido; se reportan aparte de los 1944 tests funcionales. Un resumen ausente, conteo inesperado, versión/artefacto inválido, timeout o teardown fallido también da nonzero. No se usa `continue-on-error`. Ante fallo WordPress se conservan logs básicos de containers antes del teardown. No se suben builds ni releases. En GitHub el diagnóstico queda en el log del job; no se persisten artefactos remotos de esta fase.

Recomendar como required checks de `develop`: **PHP tests**, **JS tests**, **Build**, **Clean smoke**. No se modificaron settings remotos; comprobar los nombres en la primera ejecución GitHub antes de configurar branch protection.

## Upgrade y límites

Conservar [fixtures/comandos M8](../m8/README.md) y las validaciones históricas S4.1 como check manual/pre-release del dataset aplicable. La suite M8 de 77 casos sí forma parte de los 965 PHP diarios; el upgrade histórico completo separado no se ejecuta en cada push.

Este CI mínimo no certifica deploy institucional, SMTP real, Nginx, datasets históricos externos, themes/extensions, E2E visual, performance, exactly-once ni atomicidad global Storage↔Assignment. No audita todas las 165 actions ni modifica funcionalidad de producto.

## P1-B — Dominio puro del Study Engine

Se añade `php tests/run-study-engine-domain.php`: **127 tests nuevos**, 10 protocolos
válidos, 21 fixtures inválidos y 10 suites de traces declarativos. No arranca WP ni
MariaDB, no usa SQL, SMTP o cron. Un host con PHP 7.4+ puede ejecutarlo directamente;
el gate lo ejecuta con CLI aislado en la imagen PHP ya fijada, filesystem RO y sin red.
FAST/FULL/PHP ejecutan la etapa `pure-php-run-study-engine-domain`, separada de las
965 pruebas PHP legacy. FULL conserva smoke y activation: **1168 tests únicos
(1100 PHP + 68 JS)**. RCT estricto y smoke repetido no vuelven a contarse.

Contratos y límites: [domain-contracts.md](../../docs/study-engine/domain-contracts.md).
Los expected traces son oráculos declarativos para P1-C, no recorridos ejecutados
por un simulator. El dominio no está conectado al bootstrap productivo.

## Study Engine P1-C — compiler/simulator puro

`php tests/run-study-engine-simulator.php` añade **157 tests nuevos** y revalida los 21 negativos P1-B, reportados aparte. FULL: **1325 únicos (1257 PHP + 68 JS)**; FAST: 1317. No necesita WordPress/DB/SMTP. Usa el stage pure PHP ya existente; no modifica suites legacy. `--fixture=08`, `--trace` y `--snapshot` permiten inspección. Contrato técnico: [compiler/simulator](../../docs/study-engine/compiler-simulator.md).

Cross-version fuera del gate ordinario: `python3 tests/study-engine/simulator/cross-version.py --output /tmp/p1c-cross-version`; ejecuta los dos digests PHP7.4/8.3 fijados, sin red, y exige snapshots byte a byte iguales. `python3 tests/study-engine/simulator/reference_vectors.py` verifica los siete vectores criptográficos mediante implementación independiente. Estas repeticiones no agregan tests únicos.

## Study Engine P1-D

Persistencia interna y Execution Owner: [contrato técnico](../../docs/study-engine/persistence-execution.md). Nuevos runners `tests/run-study-engine-persistence-unit.php` (30 puros) y `tests/run-study-engine-persistence.php` (85 MariaDB). Cierre histórico P1-D: **1440 únicos (1372 PHP + 68 JS)**; FAST P1-D: 1432. Sin endpoints/UI/SMTP ni conversión legacy.

## Study Engine P1-E

Slice público/anónimo opt-in: [Content Adapter y runtime cross-sectional](../../docs/study-engine/content-cross-sectional.md). Snapshot pinneado, recuperación HttpOnly, páginas/conditions/order del engine y Storage local+journal en la misma transacción. 44 tests de contenido,66 HTTP/DB y10 DOM nuevos; FULL1560 únicos (1482 PHP+78 JS), FAST1552. Sin migración legacy, emails, workflow longitudinal ni Builder.

## Study Engine P1-F

[Acceso longitudinal y notificaciones](../../docs/study-engine/longitudinal-access-notifications.md): EMAIL_LONGITUDINAL opt-in, identidad Study-scoped, sesiones provisionales/autenticadas, capabilities de un uso, verificación y outbox integrado a S2. P1-F agrega 123 DB/HTTP y 6 DOM; FULL 1689 únicos (1605 PHP +84 JS), FAST 1681. Retiro completo y Builder quedan fuera de esta fase. Deploy requiere keyrings, HTTPS, cron y validación operacional de SMTP.

## Study Engine P1-G

[Retiro, privacidad y export](../../docs/study-engine/withdrawal-privacy-export.md): B1/B2 con AccessContext, exclusión analítica inmediata y cleanup local recuperable. Adapter de export versionado, coverage y tombstones explícitos. Agrega 106 únicos (12 PHP puros +84 DB/HTTP +10 DOM); FULL 1795 (1701 PHP +94 JS), FAST 1787. Sin Builder/P1-H; deploy condicionado a upgrade v14, secrets, HTTPS y cron.

## P1-H — Visual Study Builder

El Builder administrativo añade 124 casos (51 DB/API/HTTP, 33 puros, 40 DOM). Total vigente FULL: **1919 únicos: 1785 PHP +134 JS**; FAST: **1911**. Gate estricto RCT, build, clean smoke y lifecycle verdes. Los totales anteriores documentan fases históricas. [Arquitectura, uso, límites y verificación](../../docs/study-engine/study-builder.md). Sin migración legacy automática ni certificación institucional/SMTP real.

## P1-I — piloto y operación (abierto)

FULL vigente **1944 únicos:1810 PHP +134 JS**, FAST1936; añade25 casos de integración (10 Builder→runtime→export,5 canje HTTP,10 operación). GET de email ya no consume la capability: requiere confirmación POST explícita tras redirect limpio. Gate/RCT/build/smoke/lifecycle verdes. **P1-I no cerrado: smoke humano Builder/participante/accesibilidad/visual pendiente por decisión del responsable.** Readiness: CONDITIONAL GO sólo tras checklist operacional y humano; no deploy institucional certificado. [Evidencias de alcance, runbook y límites](../../docs/study-engine/pilot-readiness.md). Sin commit/push/tag/release.


## DATA-1 — canonical dataset and effective metadata policy

Permanent suites: `tests/run-data1-unit.php` (36), `tests/run-data1.php` (18), `tests/data1/metadata-dom.js` (7) and `tests/data1/management-dom.js` (3). They cover wide observations, provenance/collisions, historical dictionary/types, UTC/partials, manual/live/snapshot parity, typed secure XLSX, private binary percent preservation, Range/withdrawal/retained semantics and global metadata intersection with historical preservation. API-4 additionally records HTTP V2 payloads and validates them against the versioned OpenAPI schemas.

Current configured suites: **3,564 cases** (2,421 WordPress/PHP + 464 pure PHP + 679 JS). FULL also runs strict RCT, observed OpenAPI validation, build, clean activation, smoke, lifecycle and cold reactivation; repeated smoke/RCT do not inflate the suite count. DATA-1 final evidence is documented in the project report. No human review is implied by automated PASS.
