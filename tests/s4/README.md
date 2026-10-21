# S4 — Auditoría adversarial de cierre

No certifica GO: queda P1 confirmado en `eipsi_close_randomization_session`.

Requiere WordPress Docker aislado con DB_HOST `eipsi-m0-db:3306`, DB_NAME `m0` y marker M0. El runner aborta ante colisiones de IDs 999403/404, participantes999407–409, waves999421–424 y assignments999431–434. Intercepta solo correo de sus fixtures; restaura cron, elimina posts/filas propios y MU temporal. No ejecutar suites simultáneamente sobre una DB. El probe RCT usa un assignment moderno creado por su owner y lo limpia.

Desde la raíz del plugin, con Node22/node_modules disponibles:

```sh
docker run --rm --network none --user 1001:1001 -e HOME=/tmp -v "$PWD:/app" -w /app node:22-bookworm node tests/s4/consumers.js
docker exec eipsi-s4-final-clean-wordpress php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/run-s4.php
# Gate de seguridad estricto: debe fallar mientras siga abierto el P1 RCT.
docker exec -e EIPSI_S4_EXPECT_RCT_DENIAL=1 eipsi-s4-final-clean-wordpress php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/run-s4.php
```

25 casos PHP nuevos +3 consumidores JS. Uno caracteriza positivamente un ataque RCT todavía posible; no interpretarlo como invariante de seguridad verde. El modo estricto verifica rechazo y conservación del assignment, sin contar el mismo caso dos veces. Un consumidor caracteriza el ReferenceError de una variante inline de filtros (P2), sin simular que funciona.

Regresiones de parciales validan HTTP propio/ajeno/anónimo y persistencia, incluyendo temporalidad. Logout verifica revocación confirmada/error y reuse del token capturado; conserva cookie para retry si DELETE falla. Los errores SELECT durante refresh se inyectan en consultas reales y se comprueba `refresh_failed` con `updated=1` tras persistencia. La carrera apply/deadline usa dos procesos/conexiones MariaDB, barrier y lock retenido por padre. Repeated apply verifica fechas y auditoría por comando, sin prometer atomic batch ni exactly-once.

Compose y comandos reproducibles clean/upgrade, logs, inventarios y resultados originales S0–S3 están en `informes/EIPSI-Forms/2026.10.06 - Evidencias S4/`. Las 973 pruebas anteriores se mantienen; repeticiones clean/upgrade/lifecycle no incrementan el total.
