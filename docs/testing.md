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

[run-m2.php](../tests/run-m2.php) rechaza otras bases mediante el bootstrap M0. Compara decisiones reales contra [P1-A congelado](../tests/m2/baseline-policy.php) y preserva [37 firmas públicas](../tests/m2/public-api.json). Cubre password y la API passwordless ahora fail-closed por S0, accepted/pending/inactive/declined/withdrawn, sesiones/hash/expiración/revocación, magic links/hash/48 horas/single-use, identidades ajenas, consentimiento, submit longitudinal y anónimo, Pools, registro/confirmación, bulk/import y deactivate/reactivate.

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

## M8 — Schema y upgrades

662 tests previos se mantienen y M8 añade 77 casos PHP reales: total 739 (695 PHP y 44 JS). Las ejecuciones repetidas de clean/upgrade no suman casos. Incluye 26 estructuras, tres estados históricos soportados, datos/relaciones preservados, drift aditivo, firmas públicas, JSON alias, settings legacy, study_end_at, SQL fallido/reintento, proceso interrumpido, permisos reales sin ALTER, dos carreras entre procesos y external create/insert/existing/unavailable.

Se usa un prefijo aleatorio con copia aislada de options; limpieza exclusivamente de tablas del fixture. Las FK físicas del fixture de prefijo no se fuerzan ante colisiones globales de nombres; su equivalencia se verifica en los Docker completos independientes. El upgrade separado recrea el schema capturado de HEAD 8b91fe1 antes de activar el plugin, conserva una fila histórica por cada uno de 26 dominios y ejecuta suites funcionales representativas.

[Comandos y guardas](../tests/m8/README.md). La comparación clean/upgrade exige tablas, tipos, nullability, defaults, índices y FK equivalentes; reporta collation histórica sin convertirla automáticamente. No representa cobertura de cualquier instalación arbitraria ni matriz MySQL/MariaDB completa.


## S0: regresión de prueba de posesión

Total S0: **785 casos únicos = 754 anteriores + 31 nuevos** (741 PHP, 44 JS). Los casos históricos corregidos/renombrados no se cuentan como nuevos. [Guía S0](../tests/s0/README.md) contiene comandos reproducibles y la migración de expectativas.

[run-s0.php](../tests/run-s0.php) conserva el ataque original: nonce extraído de HTML público → email conocido sin credencial → intento de sesión → participant info. Antes del cambio fallaba porque el endpoint protegido devolvía la víctima; ahora exige cero cookie/token/identidad y cero persistencia. Usa WordPress/MariaDB y HTTP reales, correo interceptado exclusivamente en fixtures `@example.invalid`, tokens extraídos del correo, hash/single-use reales y cookies como navegador. Cubre password correcto/incorrecto, ambos requests de magic link, token válido/expirado/usado/inválido, estados ineligible, registro con/sin opt-in, confirmación sin login, Pools autorizado/ataque denegado, cookie fabricada/logout, ambos límites, API legacy, fallo SQL de claim, fallo de entrega y extensión válida/ineligible.

P1-A deja de esperar éxito por email-only; las decisiones de Policy se prueban con password verificado. M2 cambia sus casos de login/registro inseguros y mantiene sus 54 casos. M3/M4/M5 usan credencial en sus helpers HTTP de preparación, conservando las assertions de negocio. Los snapshots originales siguen congelados: [security-contracts.json](../tests/s0/security-contracts.json) declara dos métodos añadidos y el hash del JS Auth; [boundary-migrations.json](../tests/s0/boundary-migrations.json) encadena el cambio de single-use del shortcode al hash anterior. Las 15 comparaciones de fuente y 15 de PURGA FINAL siguen exigiendo igualdad fuera de esas diferencias explícitas.

Build continúa generando 13 bloques y 18 fragmentos Forms. Clean install exige volúmenes nuevos, activación/schema y luego el runner HTTP S0; repetir después de desactivar/reactivar. Las pruebas no verifican entrega SMTP real, UI visual, concurrencia atómica de transients ni revocación retrospectiva de todas las sesiones anteriores al fix. No ejecutar suites live simultáneamente en una misma base.


## S1: ventanas, HTTP y concurrencia

Total: **840 casos únicos = 785 anteriores + 55 S1** (796 PHP, 44 JS). [Guía reproducible S1](../tests/s1/README.md). Los dos casos permanentes RED reprodujeron separadamente servicio y POST completo: T2 futura terminaba submitted; HTTP devolvía 200/success y guardaba una respuesta. Ambos están invertidos por el fix sin borrar la cadena de reproducción.

S1 cubre NULL/past/exact/future de available_at para T1/T2/T3 y de due_at con reloj explícito; statuses/study; HTTP válido/futuro/vencido, retry, identidad/study ajenos, formulario anónimo, render consistente y timestamps falsificados por cliente. MariaDB usa workers independientes y barreras para alcanzar disponibilidad bajo lock, submit/expiration, doble submit, deadline cambiado durante espera, deadline owner/submit y submit/skip. La carrera Storage→Assignment conserva su limitación como test positivo de caracterización. Incluye T1→T2→T3/base-one/completion hooks y reloj de writer con offset WP no nulo.

Ajustes históricos explícitos: fixture compartido P1 ahora ancla T2 disponible; caso P1-B unanchored declara NULL expresamente; M3 T2/T3 declara availability; M4 T2/final-wave/expire tienen precondiciones temporales. Assertions de identidad, índices, offsets, nudges, hooks y payloads se conservan. No se cuentan casos renombrados como nuevos. Baselines congelados: [boundary-migrations.json](../tests/s1/boundary-migrations.json) encadena el shortcode a S0, [method-migrations.json](../tests/s1/method-migrations.json) declara únicamente CAS de deadline, [security-contracts.json](../tests/s1/security-contracts.json) añade dos métodos del owner.

Clean install y upgrade se ejecutan en redes/volúmenes nuevos independientes; upgrade usa el DDL histórico M8 y verifica IDs/valores originales de 26 dominios. S1 se repite tras deactivate/reactivate. Build mantiene 13 bloques y 18 fragmentos Forms, con las 44 JS anteriores. No valida todos los datasets institucionales, SMTP real, E2E visual ni rollback coordinado con Storage externo.
