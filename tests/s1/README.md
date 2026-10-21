# S1 — Política temporal longitudinal

55 casos nuevos y 840 totales únicos (785 anteriores + 55 S1). Runners reales WP/MariaDB/HTTP; no ejecutar concurrentemente en una misma base. Se rechaza cualquier entorno distinto de DB eipsi-m0-db:3306/m0 con marker eipsi_m0_isolated_install. Correo de fixtures @example.invalid interceptado; MU-plugin temporal se retira en finally. Cron automático deshabilitado.

## Clean y upgrade reproducibles

Desde raíz del plugin, elegir projects/prefixes/puertos nuevos si ya existen. Reutilizar volúmenes no demuestra instalación limpia; no borrar entornos previos.

```sh
docker compose -p eipsi-s1-clean -f tests/s1/clean-compose.yml up -d
docker exec eipsi-s1-clean-wordpress php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/m0/install.php
docker exec eipsi-s1-clean-wordpress php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/run-s1.php
docker compose -p eipsi-s1-upgrade -f tests/s1/upgrade-compose.yml up -d
docker exec eipsi-s1-upgrade-wordpress php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/m8/install-upgrade.php
docker exec eipsi-s1-upgrade-wordpress php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/run-s1.php
```

Los compose aceptan EIPSI_S1_PREFIX/EIPSI_S1_PORT para nombres/puertos alternativos; usar también project nuevo. El upgrade exige WP no instalado, recrea DDL pre-M8, conserva IDs/valores de 26 dominios y verifica migración 9→10/schema actual. Para smoke repetir run-m0.php/run-m1.php, tests/purga-final/smoke.php, tests/m8/schema-capture.php y tests/m7/lifecycle.php; después run-s1.php nuevamente. Upgrade además ejecuta run-m3.php/run-m4.php/run-m5.php con los contratos S1.

Para todas las suites previas, copia temporal escribible y probe M6 seguir [M6](../m6/README.md). Ejecutar P0/P1/P1B/P1C/M0–M8/PURGA FINAL/S0/S1 y debug-off; suites live secuenciales. JS/build offline con node_modules ya disponible:

```sh
docker run --rm --network none --user 1000:1000 -e HOME=/tmp -v "$PWD:/app" -w /app node:22-bookworm sh -c 'set -e; node tests/m3/source-contracts.js; node tests/m3/runtime-dom.js; node tests/m7/frontend-load.js; npm run build'
```

## Semántica y cobertura

Owner: AssignmentTransitionService. pending/in_progress + study active/paused + availability/deadline. available_at <= reloj admite; NULL admite T1 inmediata, no T2/T3. due_at NULL no agrega deadline; due_at <= reloj rechaza. Clock del writer current_time(mysql), con evaluación pura de clock explícito para bordes. Fechas/client timestamps no autorizan; estados visuales wave no se reinterpretan como prohibición nueva.

Los 55 casos incluyen: las dos reproducciones originales servicio/HTTP, 12 availability T1/T2/T3, 4 deadlines exactos, 5 statuses, 3 estados study, HTTP T1/T2/T3 válido/futuro/vencido/NULL/retry/identidad/study/anónimo/render, expiration stale/actual, 6 carreras reales, Storage→Assignment con fila persistida y rechazo, completion/base-one, timestamps falsificados, offset WP no nulo, estudio completed/paused y deadline extendido más allá del window original respetado por render y submit.

concurrency.php mantiene el mismo acceso indexado a la fila que submit_locked, usa procesos PHP con conexiones independientes y verifica que no finalicen antes de COMMIT del parent. Barre al alcanzar available_at real y al cambiar due_at mientras submit espera. Deadline owner vs submit puede producir un deadlock InnoDB: un escritor falla seguro, sin estado final inválido. No se añade sistema de retry ni exactly-once global.

mail-isolation.php contiene únicamente fixtures: intercepción mail, observación completion y cambio de deadline durante el INSERT de respuesta para caracterizar la carrera tras precheck. No sustituye Storage ni transición; SQL real. El test demuestra explícitamente respuesta guardada + assignment pending + deadline_passed, y no cuenta eso como atomicidad global.

## Migración consciente de pruebas

- bootstrap-p1.php: fixture compartido T2 ahora tiene available_at actual para pruebas de identidad/registro/render/index. Misma cantidad de casos.
- run-p1b.php: caso unanchored declara NULL explícitamente, manteniendo su assertion de ausencia de deadline.
- m3/forms-cases.php: T2/T3 válido ahora declara ventana anclada; mantiene índice/identidad.
- m4/longitudinal-cases.php: submit T2/final T3 ahora disponibles; expiry snapshot tiene deadline vencido; assertions transacción/payload/T1 siguen iguales.
- m3/source-contracts.js: conserva cadena congelada M3→S0, agrega solo hash del guard S1 del shortcode.
- m5/notification-cases.php: hash de token previo permanece obligatorio; method-migrations.json declara únicamente snapshot/CAS del writer longitudinal deadline.
- run-purga-final.php: firmas públicas anteriores intactas más dos métodos explícitos, declarados en security-contracts.json.

No se reemplazan baselines ni se cuenta un caso renombrado como nuevo. Administración explícita de completion/anchor y APIs PHP confiables de escritura directa conservan sus contratos; S1 controla envío participante. Storage externo, entrega real SMTP, E2E visual y datasets históricos no caracterizados son límites posteriores.
