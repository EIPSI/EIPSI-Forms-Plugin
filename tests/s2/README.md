# S2 — Integridad de jobs Notifications

53 casos nuevos: 893 únicos = 840 anteriores + 53 S2 (849 PHP + 44 JS). Tests con WP/MariaDB real, correo interceptado y conexiones/procesos independientes. No ejecutar suites concurrentemente en una misma base.

## Entornos reproducibles

Desde raíz del plugin, con imágenes locales MariaDB11/WordPress y node_modules disponible:

```sh
docker compose -p eipsi-s2-clean -f tests/s2/clean-compose.yml up -d
docker exec eipsi-s2-clean-wordpress php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/m0/install.php
docker exec eipsi-s2-clean-wordpress php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/run-s2.php
EIPSI_S2_PREFIX=eipsi-s2-upgrade-final EIPSI_S2_PORT=18237 docker compose -p eipsi-s2-upgrade-final -f tests/s2/upgrade-compose.yml up -d
docker exec eipsi-s2-upgrade-final-wordpress php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/m8/install-upgrade.php
docker exec eipsi-s2-upgrade-final-wordpress php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/run-s2.php
```

Usar project/prefix/puerto nuevos para repetir una instalación limpia; los helpers rechazan bases ya instaladas. No eliminar volúmenes anteriores. Fixtures IDs 998203/998207/998221/998231 y tabla legacy sintética únicamente si no existe. Bootstrap rechaza DB distinta de eipsi-m0-db:3306/m0 sin marker aislado. Selectores de test se limitan a assignment998231 para no consumir jobs históricos; writes/claims/transiciones/delivery son los owners reales. La assertion final verifica byte a byte los valores de jobs preexistentes.

Smoke: tests/purga-final/smoke.php; lifecycle: tests/m7/lifecycle.php; schema: tests/m8/schema-capture.php. Repetir run-s2.php después del lifecycle. Clean ejecuta M0/M1/M3; upgrade M3/M4/M5. Para las suites anteriores usar copia escribible y probe M6 según [M6](../m6/README.md), secuencialmente P0/P1/P1B/P1C/M0–M8/PURGA FINAL/S0/S1/S2 y debug-off.

```sh
docker run --rm --network none --user 1000:1000 -e HOME=/tmp -v "$PWD:/app" -w /app node:22-bookworm sh -c 'set -e; node tests/m3/source-contracts.js; node tests/m3/runtime-dom.js; node tests/m7/frontend-load.js; npm run build'
```

## Cobertura y aislamiento

La reproducción original inyecta únicamente un UPDATE completed inválido después de claim y correo controlado. La inyección posterior distingue SET de WHERE para no fallar accidentalmente el claim cuando se desea fallar el retry. Otros tests cubren INSERT/claim/completed/failed/retry/cancel/recovery SQL failures; conflictos0; backoff; max5; lease reciente/vencido/igualdad/NULL; cancelación payload/stages/legacy; cron/cadencia; estado real en stats.

Concurrencia: dos workers un pending y un correo interceptado, worker vivo vs recovery, dos recoverers, worker vs cancel, retry vs cancel, completion simultáneo rechazado para conexiones sin ownership. Crash se modela con proceso que termina sin terminal después del claim/correo; la conexión real libera el mutex. También se cierra/reabre la conexión real después de mail y se vuelve inaccesible temporalmente la tabla canónica en el entorno aislado, restaurándola en finally.

El correo siempre se intercepta; no se certifica SMTP ni entrega externa. La prueba de crash no afirma que ya no pueda duplicarse un correo. La tabla sintética legacy y directorios race propios se retiran; fixtures de dominio/jobs, cache y cron se limpian/restauran.

## Migraciones explícitas de contratos

M5 conserva 104 casos: el test de agotamiento adquiere claim y avanza scheduled_at del fixture antes de cada retry; el caso de stages concurrentes también habilita explícitamente el retry del fixture; cuatro cuerpos congelados Queue (claim/enqueue/pending selector/retry) permiten solo hashes S2 versionados en method-migrations.json, conservando hash M5 original. PURGA FINAL conserva 15 casos y admite únicamente tres métodos nuevos del owner declarados en security-contracts.json. No se sobrescriben snapshots, plantillas, facades ni deltas S0/S1.

## Política

Lease fijo15min por processed_at, fallback updated_at si NULL, vencido <=cutoff. El mutex advisory por job/conexión protege delivery y terminal; el recovery solo actúa si está libre y el snapshot coincide. Fresh processing nunca se recupera. Recovery incrementa retries y conserva backoff5/15/45/120/360min y máximo5 (al quinto pasa failed, por lo que360min no se programa en operación normal). completed/failed/retry requieren processing poseído y una fila afectada. Repetir completed devuelve false/conflicto, no otra transición.

Garantía: best-effort con reintentos acotados y posible entrega duplicada; exactly-once no garantizado. Sin outbox/schema nuevo/estado nuevo. Jobs duplicados assignment/stage aún pueden existir; count/cache/logs previos no se reinterpretan como dedupe global. API directa mark_processing mantiene mutex hasta terminal/retry/release_processing o cierre de conexión. Cancel moderno solo pending; processing/completed no se cancelan. La ruta Scheduler también preserva cancel pending opcional en survey_job_queue si existe.

S2 hace explícita la página de estudio en fixtures M3/M5 aislados (sin cambiar código de envío/URL); evita dependencia de páginas que una suite anterior creó. P0 se ejecuta con --integration para mantener sus 22 casos.
