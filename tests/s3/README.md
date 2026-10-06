# S3 — Contratos funcionales P1

Fixtures y runners únicamente para DB_HOST=eipsi-m0-db:3306/DB_NAME=m0 y marker aislado. IDs propios999203/204, participantes999207–209, waves999221–224 y assignments999231–234; colisiones abortan. Cron se restaura, posts y registros propios se retiran. Correo de fixtures interceptado; no se prueba SMTP externo.

Desde la raíz del plugin:

```sh
docker compose -p eipsi-s3-clean -f tests/s3/clean-compose.yml up -d
docker exec eipsi-s3-clean-wordpress php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/m0/install.php
docker exec eipsi-s3-clean-wordpress chown www-data:www-data /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/exports
docker compose -p eipsi-s3-upgrade -f tests/s3/upgrade-compose.yml up -d
docker exec eipsi-s3-upgrade-wordpress php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/m8/install-upgrade.php
docker exec eipsi-s3-upgrade-wordpress chown www-data:www-data /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/exports
docker run --rm --network none --user 1000:1000 -e HOME=/tmp -v "$PWD:/app" -w /app node:22-bookworm node tests/s3/consumers.js
docker exec eipsi-s3-clean-wordpress php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/run-s3.php
docker exec eipsi-s3-upgrade-wordpress php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/run-s3.php
```

Los exports se escriben en un volumen Docker nuevo; código montado read-only. No usar chown sobre el código del host. Para repetir clean/upgrade usar proyecto/prefix/puerto nuevos, conservando volúmenes anteriores. Bootstrap limpio M0 admite reactivación; upgrade histórico rechaza bases instaladas. Para las 893 regresiones anteriores, ejecutar suites secuenciales P0--integration/P1/P1B/P1C/M0–M8/PURGA FINAL/S0/S1/S2/debug-off; copia escribible y probe temporal según tests/m6/README.md. No correr suites en paralelo sobre la misma DB.

Consumer tests ejecutan fuentes reales con un DOM mínimo y capturan requests. `consumer-requests.json` contiene esos requests sin tokens reales; PHP los ejecuta por HTTP real hasta owners y respuestas. Cubre polling wave, modal reminder, preview/apply y refresh dashboard. El GET legacy se verifica por HTTP hasta streaming ZIP, headers, columnas, filtros y errores. LONG/rollback no se implementan por analogía.

Concurrencia usa conexiones/procesos MariaDB independientes, barrier y lock retenido por padre. Apply vs submit y dos apply; estados terminales se releen tras el bloqueo. Fallos SQL de UPDATE/audit revierten; fallo de refresh después del commit expone `details.updated`. Preview es solo lectura, sin domain writes ni cron. El batch es por assignment, no transacción global ni garantía exactly-once.

Migración explícita de expectativas: cuatro casos M0 pasan de missing a handler exacto autorizado; M5 individual reminder pasa a admin/manual owner. M1/PURGA FINAL reconocen únicamente seis hooks nuevos y dos métodos públicos versionados en security-contracts.json; solo cambia el hash de study-dashboard.js con baseline original preservado. No reemplazar snapshots automáticamente.

Build y lifecycle:

```sh
docker run --rm --network none --user 1000:1000 -e HOME=/tmp -v "$PWD:/app" -w /app node:22-bookworm sh -c 'set -e; node tests/m3/source-contracts.js; node tests/m3/runtime-dom.js; node tests/m7/frontend-load.js; node tests/s3/consumers.js; npm run build'
docker exec eipsi-s3-clean-wordpress php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/purga-final/smoke.php
docker exec eipsi-s3-upgrade-wordpress php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/purga-final/smoke.php
docker exec eipsi-s3-clean-wordpress php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/m7/lifecycle.php
docker exec eipsi-s3-upgrade-wordpress php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/m7/lifecycle.php
```

Repetir S3 tras lifecycle. Counts finales se documentan en el informe, sin contar repeticiones clean/upgrade como tests nuevos.
