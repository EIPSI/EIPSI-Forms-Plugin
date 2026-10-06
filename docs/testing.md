# Testing

La suite anterior a M3 tiene 268 pruebas: P0 22, P1-A 40, P1-B 40, P1-C 45, M0 36, M1 31 (29 contratos/lifecycle y 2 sin WP_DEBUG) y M2 54. Las 214 anteriores se mantienen.

## Ejecutar las regresiones

Desde un workspace con los contenedores de desarrollo iniciados:

```sh
docker exec wp-eco-wordpress-1 php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/run-p0.php --integration
docker exec wp-eco-wordpress-1 php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/run-p1.php
docker exec wp-eco-wordpress-1 php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/run-p1b.php
docker exec wp-eco-wordpress-1 php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/run-p1c.php
```

Cada runner devuelve código distinto de cero si falla. Los contratos, fixtures SQL y dobles están documentados en [tests/README.md](../tests/README.md).

## M0 y clean install

Seguir [tests/m0/README.md](../tests/m0/README.md) para build, creación de la instalación descartable, activación, schema, smoke HTTP e inventario ejecutable. Con ese entorno preparado:

```sh
docker exec eipsi-m0-wordpress php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/run-m0.php
```

Reactivar una instalación existente no constituye instalación limpia: esta requiere volúmenes nuevos. M0 rechaza otras bases, intercepta correo y desactiva cron automático. No cambiar esas protecciones para ejecutar contra datos reales.

## Límites y comprobaciones documentales

Nueve tests M0 verifican emisores UI sin handler: son caracterización de deuda. La suite no sustituye E2E, matriz de compatibilidad, CI ni evaluación de todos los cron. Tras cambios documentales ejecutar las suites P0/P1/M0/M1/M2, `npm run build`, `git diff --check` y comprobar links y referencias eliminadas. Las regresiones existentes no se deben reducir para conseguir un resultado verde.

## M1: contratos y lifecycle

Después de preparar M0 y ejecutar su suite, usando exclusivamente su instalación descartable:

```sh
docker exec eipsi-m0-wordpress php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/run-m1.php
docker exec -e WORDPRESS_DEBUG=0 eipsi-m0-wordpress php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/m1/debug-off.php
```

M1 compara inventarios reales admin/frontend con [baseline.json](../tests/m1/baseline.json), capturado antes de la extracción. Comprueba includes/orden, hooks/AJAX/prioridades/argumentos, ubicaciones permitidas, shortcodes, REST, cron, assets y bloques. Normaliza únicamente valores declarados en la allowlist: el timestamp de petición de `eipsi-forms-js` ya era dinámico. Las líneas de fuente y los timestamps cron no son contratos estables.

También verifica hashes de tokens de 44 funciones globales, localizations/nonces, schema, identidad de lifecycle y bootstrap idempotente. Programa eventos fixture con dos variantes de argumentos por cada owner y dos hooks ajenos, desactiva/reactiva el plugin y confirma limpieza selectiva y preservación de datos. No envía correo ni ejecuta esos cron fixture; conserva las protecciones M0. El runner restaura activación y retira los hooks ajenos fixture al finalizar ese test.

Para clean install, repetir los pasos de [M0](../tests/m0/README.md) con volúmenes nuevos, luego ejecutar M0, M1 y debug-off. El smoke M0 cubre formulario/admin/assets y endpoints; M1 repite admin/REST/assets después de reactivar. Pasar estas pruebas no valida el envío de correo real ni corrige las nueve discrepancias UI→handler.

## M2: Auth/Participants sobre WordPress real

Sobre la instalación descartable M0 preparada y activada:

```sh
docker exec eipsi-m0-wordpress php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/run-m2.php
```

[run-m2.php](../tests/run-m2.php) rechaza otras bases mediante el bootstrap M0. Compara decisiones reales contra [P1-A congelado](../tests/m2/baseline-policy.php) y preserva [37 firmas públicas](../tests/m2/public-api.json). Cubre password/passwordless, accepted/pending/inactive/declined/withdrawn, sesiones/hash/expiración/revocación, magic links/hash/48 horas/single-use, identidades ajenas, consentimiento, submit longitudinal y anónimo, Pools, registro/confirmación, bulk/import y deactivate/reactivate.

Los recorridos críticos usan HTTP real, nonce y cookies codificadas como las devuelve `Set-Cookie`. Las cookies de sesión contienen caracteres especiales: enviar el valor sin codificar mediante el cliente HTTP de WordPress no reproduce necesariamente al navegador. Ninguna corrección productiva de cookies fue necesaria. Las pruebas interceptan correo instalando temporalmente [mail-isolation.php](../tests/m2/mail-isolation.php) como MU-plugin, restringido a la base descartable; lo eliminan en `finally`. Rechazan colisiones de IDs y limpian únicamente sus fixtures. No ejecutarlas simultáneamente en la misma base.

Para verificar una instalación realmente nueva, ejecutar `docker compose -p eipsi-m0 -f tests/m0/docker-compose.yml down -v`, `up -d` y el helper `tests/m0/install.php` antes de M0/M1/M2. Esto elimina exclusivamente datos del entorno descartable M0.

Las pruebas P1-C generan exportaciones bajo el plugin. Si el bind de M0 está en modo lectura, ejecutar las 147 pruebas P0/P1 desde una copia temporal escribible del mismo código, dentro de ese contenedor:

```sh
docker exec eipsi-m0-wordpress sh -c 'mkdir -p /tmp/eipsi-regression-source && tar -C /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin --exclude=node_modules --exclude=.git --exclude=build -cf - . | tar -C /tmp/eipsi-regression-source -xf -'
docker exec eipsi-m0-wordpress php /tmp/eipsi-regression-source/tests/run-p0.php --integration
docker exec eipsi-m0-wordpress php /tmp/eipsi-regression-source/tests/run-p1.php
docker exec eipsi-m0-wordpress php /tmp/eipsi-regression-source/tests/run-p1b.php
docker exec eipsi-m0-wordpress php /tmp/eipsi-regression-source/tests/run-p1c.php
```

M0/M1/M2 se ejecutan contra el plugin activo del bind real. No sustituir un fallo operativo por un éxito basado únicamente en dobles. La suite sigue sin equivaler a E2E visual, CI ni validación de todas las políticas legacy de cron.

## M3: Forms, submit y runtime

M3 agrega 80 pruebas: 39 de WordPress/MariaDB/HTTP, 26 de DOM (cada una contra runtime anterior y generado) y 15 de límites/compilación/purga. Total: **348**, conservando las 268 anteriores. Después de instalar M0:

```sh
docker exec eipsi-m0-wordpress php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/run-m3.php
docker run --rm -v "$PWD:/app" -w /app node:22 node tests/m3/source-contracts.js
docker run --rm -v "$PWD:/app" -w /app node:22 node tests/m3/runtime-dom.js
```

El runner PHP rechaza toda base que no sea M0; reserva IDs 9922xx, detecta colisiones, instala temporalmente un mu-plugin para interceptar mail/observar completion HTTP y borra fixtures al salir. No ejecutar simultáneamente M2 y M3: usan el mismo rango de fixtures. Los fallos de INSERT se inyectan mediante `query` solo en el proceso CLI de la instalación descartable; Storage no se sustituye. El fallback externo se prueba con credenciales inválidas únicamente contra el DB host del propio Docker. Parciales prueban nonce/payload de 50KB, claves, completed, privacy y recuperación de página. T1/T2/T3 prueban identidad y wave_index, y observan el hook después del commit.

La suite DOM usa jsdom ya presente en el lockfile, con fixtures de layout y red controlada. Ejecuta required, controles, VAS, páginas/historial, branching, consentimiento, restore del cliente parcial real, contrato de submit/fallo de transporte y completion. No es navegador headless ni E2E de render visual, IndexedDB real o compatibilidad móvil. El hash de la composición prueba que se conserva el código anterior; las assertions DOM comprueban efectos observables además del hash.

El caso de Pools es caracterización explícita de deuda (`p.name`/`pool_name` y búsqueda legacy por email), no prueba de completion exitoso. Las reglas backend frontend-only y las limitaciones de verificación de Storage están documentadas en arquitectura. Las 15 comprobaciones de fuente congelan archivos de frontera y el artifact clásico; no sustituyen las pruebas HTTP.

Para ejecutar P0/P1 en M0 con el plugin montado read-only, copiar la fuente exacta dentro del contenedor a `/tmp/eipsi-m3-source` (excluyendo `.git`, `node_modules`, `build`) y ejecutar allí esos cuatro runners: P1-C crea un destino de exportación de prueba. M0/M1/M2/M3 se ejecutan sobre el plugin activo montado. Para instalación realmente limpia recrear exclusivamente el proyecto `eipsi-m0` según `tests/m0/README.md`, construir y ejecutar nuevamente todas las suites.


## M4: Longitudinal y concurrencia

Total tras M4: **403 pruebas** = 348 previas + 55 nuevas. Preparar M0 con volúmenes nuevos para validar clean install y ejecutar:

```sh
docker exec eipsi-m0-wordpress php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/run-m4.php
```

[Detalle de fixtures, contratos y límites M4](../tests/m4/README.md). Incluye Studies, Wave CRUD/restricciones, Assignments/retry, T1, recalculation en minutos, payloads, rollback real, deadlines HTTP, expiration/skipping, hooks, Pools y tres pruebas concurrentes. Dos workers PHP usan conexiones independientes: un solo submit gana, compare-and-set devuelve 1/0 y FOR UPDATE bloquea finalización hasta COMMIT del padre.

Se conservan las 39 pruebas PHP y 41 JS M3. El caso que documentaba el bug Pool ahora exige completion real; cuatro hashes de facades/límite Pools tienen migración M4 explícita, manteniendo sus hashes históricos y cobertura independiente de firmas/cuerpos críticos. La allowlist M1 añade solamente quince includes de definición enumerados.

Los runners P0/P1-C requieren una copia escribible del código dentro del contenedor por sus exports temporales. Se puede copiar con tar excluyendo `.git`, `node_modules` y `build`; no cambiar el mount read-only del plugin activo. M0/M1/M2/M3/M4 se ejecutan contra el plugin montado. Las suites son secuenciales; M4 limpia fixtures propios y restaura el cron previo.

No inferir atomicidad de Storage+Assignment ni exactly-once de mail/audit/jobs a partir de la concurrencia de estados. El rollback inyecta un error SQL únicamente en una escritura T1 del fixture descartable; el schema no se altera.

## M5 — Notifications

Total M5: **507 pruebas** = 403 previas + 104 nuevas. Las 403 pruebas anteriores siguen siendo obligatorias. Ejecutar secuencialmente, después de preparar el Docker descartable M0:

```sh
docker exec eipsi-m0-wordpress php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/run-m5.php
docker run --rm --user "$(id -u):$(id -g)" -v "$PWD:/work" -w /work node:22-bookworm node --test tests/m3/source-contracts.js tests/m3/runtime-dom.js
```

[Fixtures y cobertura M5](../tests/m5/README.md). Los casos usan WordPress/MariaDB/WP-Cron reales y verifican absolute offsets, pasado/deadline/stages enviados, refresh/cancelación, failure después de persistencia, nudge0/cooldown, retry/backoff, entrega/logs/headers, weekly T1, dropout, manual, legacy, cadencias CronHealth, lifecycle y orden transaccional. Templates y firmas se comparan con contratos congelados antes de M5; cuerpos de claim/retry/config se verifican por tokens.

Concurrencia: dos procesos sobre un job, claim SQL 1/0, dos schedulers por assignment, configs concurrentes con lectura fresca y dos jobs de stages diferentes que convergen con retry. No prueba exactly-once entre jobs diferentes del mismo stage ni entrega SMTP externa. La purga se ejecuta únicamente después de obtener todas las regresiones verdes y se verifica otra vez con la instalación limpia.

## M6 — Storage / Privacy / Export

Se mantienen las 507 pruebas anteriores y se agregan 82 casos del harness M6 y 8 casos WordPress real/HTTP/concurrencia: total 597. Véanse [comandos, guardas y límites](../tests/m6/README.md). El harness usa tablas MariaDB reales aisladas con stubs WP y una copia escribible; el runner live prueba autorización y workers contra WordPress completo. No ejecutar suites live en paralelo.

Se cubren local/external/fallback/emergency, confirmación versus verification unsupported, CapturePolicy en writers, cleanup/B2/rollback, coverage local incompleta, aprobación/identidad/nonce/archivos privados, datasets separados, CSV/XLSX, SQL y generación fallida, autorización de downloads y deuda LONG. Firmas públicas se comparan con el snapshot M5 y los hashes históricos permanecen registrados. Hay dos regresiones deterministas del IV binario externo que conservan el formato cifrado existente.

Las carreras prueban un ganador para approval, filenames sin overwrite, writers de fallback/emergency con IDs diferentes y cleanup concurrente sin afirmar atomicidad global. Anonymous HTTP directo al directorio exports devolvía 200 y ahora 403 en Apache; el download admin autorizado devuelve 200. Nginx necesita su propia regla deny; no inferir esa cobertura de .htaccess.

La instalación limpia usa nuevos volúmenes Docker y valida activación/schema, 13 bloques, submit anónimo y Longitudinal, administración, shortcodes, assets y endpoints. Los tests M1 también cubren deactivate/reactivate y cron. Las pruebas no certifican borrado externo, eliminación de backups/exports históricos/correo entregado ni anonimización de texto libre.


## M7 — Pools / Randomization

597 previous tests remain. `tests/run-m7.php` adds 62 real WordPress/MariaDB/HTTP tests, including four barrier-controlled two-process races; `tests/m7/frontend-load.js` adds three executable JS regressions. Total: 662. M0's existing form-loader debt characterization is explicitly updated to assert both restored hooks; no test is removed. M1 permits only new definition includes and three explicit new hooks, and M3 retains the complete hash history through the M7 completion-facade migration.

Run only against the guarded disposable Docker described in [M7](../tests/m7/README.md). Fixtures use 994701/994703/994704/994707/994708, abort on collisions and delete only owned records/posts. `baseline-m7_baseline_*.php` captures pre-M7 algorithms from 8e36b1a; seeded fixtures compare actual outputs. Signature fixtures cover loaded callbacks and contextual UI definitions.

The race tests cover one pool identity, one completion winner, stable random variant and concurrent first override/assignment. They do not cover worker death between persistence/analytics/event or guarantee global exactly-once. Saved institutional content is not present in these isolated databases; absence of records cannot justify legacy deletion.
