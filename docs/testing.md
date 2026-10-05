# Testing

La suite actual tiene 268 pruebas: P0 22, P1-A 40, P1-B 40, P1-C 45, M0 36, M1 31 (29 contratos/lifecycle y 2 sin WP_DEBUG) y M2 54. Las 214 anteriores se mantienen.

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
