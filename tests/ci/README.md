# T0 — Gate técnico mínimo

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

FAST ejecuta los 68 JS, build real/validación de artefactos y los 963 PHP más el gate estricto RCT. PHP necesita WordPress/MariaDB por sus integraciones reales: FAST también instala un fixture nuevo. Build se hace antes de PHP porque `build/` está ignorado por Git y varias pruebas HTTP necesitan esos assets.

FULL añade **otra instalación nueva**, seis smoke tests representativos y deactivate/reactivate con repetición del mismo smoke. No vuelve a ejecutar las suites exhaustivas. Con imágenes/dependencias locales, FAST cuesta unos 3 minutos y FULL añade unos 15 segundos en el equipo de referencia; primer download y runners remotos pueden tardar más.

Los modos `php`, `js`, `build` y `smoke` son entradas de los cuatro jobs CI, disponibles para diagnosticar una etapa. `php` y `smoke` generan primero el build que necesitan; no comparten ni publican artefactos entre jobs. Es una pequeña repetición deliberada del build, sin packaging/release.

## Versiones y aislamiento

| Componente | Versión canónica |
|---|---|
| PHP | 8.3.35, con mysqli en imagen WordPress |
| WordPress | 7.1.2 |
| MariaDB | 11.8.9 |
| Node | 22.23.3, npm 10.9.9 |
| Plugin/schema | EIPSI_FORMS_VERSION vigente; migration 10, 26 dominios |

Los tres digests Docker y los conteos están en [gate.json](gate.json). El wrapper comprueba las versiones reales y evita tags `latest` mutables. No es una matriz de compatibilidad con los mínimos históricos PHP 7.4 / WordPress 5.8 del header.

Cada etapa WordPress crea un project Compose aleatorio con volúmenes nuevos, sin puertos publicados, y destruye exclusivamente ese project al finalizar, incluso tras fallo. El código host se monta RO; las suites que escriben fixtures trabajan en una copia temporal. Exports se guarda en su propio volumen. La red es interna: HTTP del fixture y DB están disponibles, sin salida a servicios institucionales. Se reutilizan instalador/guarda M0, auxiliares M6/M8, probe de export M6 y lifecycle M7.

La subred local por defecto es `10.236.0.0/24`, para no depender del pool automático agotado en algunos workspaces. Si ya está ocupada o se lanzan gates locales concurrentes, usar una red libre, por ejemplo `EIPSI_CI_SUBNET=10.236.1.0/24 ./tests/ci/run.sh full`. No se borra ninguna red ajena. En GitHub cada job tiene su propio runner. Credenciales `m0-*` y `m8-noalter` son públicas y exclusivamente descartables. No hay Composer ni secretos institucionales.

`mariadb-client.cnf` desactiva TLS únicamente para clientes administrativos del fixture que usan su socket Unix local. Evita una carrera reproducida del entrypoint con un certificado recién generado todavía no válido. No cambia TLS del servidor ni constituye configuración de despliegue. Las conexiones de producto y assertions SQL mantienen el protocolo de los fixtures anteriores.

## Suites y conteo

PHP, orden estable: P0 con `--integration`, P1, P1B, P1C, M0–M8 (M6-live después de M6), PURGA FINAL, S0, S1, S2, S3, S4, S4.1 y M1 debug-off. **963 PHP**. El gate estricto selecciona S4-03 del runner S4 y lo repite, sin agregar otro caso al conteo.

M1 valida que la versión de `eipsi-privacy-dashboard` sea exactamente el `filemtime` de `admin/js/privacy-dashboard.js` en el checkout actual. Solo ese valor se representa con un marcador en el baseline: Git no preserva timestamps entre checkouts. Los demás campos del asset se comparan sin cambios.

JS: M3 source-contracts 15, M3 runtime-dom 26, M7 frontend-load 3, S3 consumers 13, S4 consumers 3, S4.1 consumers 8. **68 JS**, todos con los runners existentes, sin reemplazarlos por lint.

Build: `npm run build`, 13 bloques con archivos/refs válidos y runtime Forms equivalente a los 18 fragmentos del manifest. El verificador es infraestructura y no suma tests de dominio.

Clean smoke añade **6 tests de integración reales**: plugin/schema/version; carga pública/admin; shortcode/bloque Forms válidos; página Forms/runtime HTTP; submit anónimo con persistencia SQL; servicio RCT con reset propio. Se repiten después del lifecycle y no se cuentan dos veces. Baseline preservado: **1031 tests únicos**. Total con T0: **1037 (969 PHP + 68 JS)**. Scripts, validadores de artefactos y ensayos de propagación de errores no aumentan el conteo. Al agregar casos reales a una suite, actualizar su conteo en gate.json; no reducir assertions para satisfacerlo.

## CI y fallos

[technical-gate.yml](../../.github/workflows/technical-gate.yml) corre en pushes a `develop`, PRs hacia `develop` y dispatch manual. Checks independientes: **PHP tests**, **JS tests**, **Build**, **Clean smoke**. Todos ejecutan el mismo wrapper local. GitHub instala dependencias con `npm ci` y usa caché npm estándar; no cachea node_modules, WordPress, bases ni resultados. Solo permiso `contents: read`; sin deploy, publicación o cambios de protección de rama.

Fail-fast por etapa/suite: imprime comando, etapa y exit code, más las últimas 60 líneas cuando falla. stdout (`<etapa>.log`), stderr (`<etapa>.stderr.log`) y `summary.json` quedan en `.cache/ci/<run>/`. Solo stdout se interpreta como resultado del comando: la descarga inicial de Docker en stderr no contamina `node --version`. Antes de las etapas se ejecutan tres regresiones del harness que cubren descarga fría, versión incorrecta y exit code fallido; se reportan aparte de los 1037 tests funcionales. Un resumen ausente, conteo inesperado, versión/artefacto inválido, timeout o teardown fallido también da nonzero. No se usa `continue-on-error`. Ante fallo WordPress se conservan logs básicos de containers antes del teardown. No se suben builds ni releases. En GitHub el diagnóstico queda en el log del job; no se persisten artefactos remotos de esta fase.

Recomendar como required checks de `develop`: **PHP tests**, **JS tests**, **Build**, **Clean smoke**. No se modificaron settings remotos; comprobar los nombres en la primera ejecución GitHub antes de configurar branch protection.

## Upgrade y límites

Conservar [fixtures/comandos M8](../m8/README.md) y las validaciones históricas S4.1 como check manual/pre-release del dataset aplicable. La suite M8 de 77 casos sí forma parte de los 963 PHP diarios; el upgrade histórico completo separado no se ejecuta en cada push.

Este CI mínimo no certifica deploy institucional, SMTP real, Nginx, datasets históricos externos, themes/extensions, E2E visual, performance, exactly-once ni atomicidad global Storage↔Assignment. No audita todas las 165 actions ni modifica funcionalidad de producto.
