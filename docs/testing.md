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
