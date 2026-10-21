# M4: contratos longitudinales

Ejecutar después de preparar la instalación Docker descartable M0:

```sh
docker exec eipsi-m0-wordpress php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/run-m4.php
```

55 pruebas con WordPress/MariaDB reales, HTTP anónimo/administrativo y dos procesos PHP independientes para concurrencia. Los helpers M0 rechazan cualquier otra base y requieren la marca de instalación aislada. Se intercepta correo, se desactiva cron automático y se limpian únicamente fixtures propios. El runner restaura el cron anterior porque los recorridos de deadline/availability programan eventos reales. Ejecutar suites secuencialmente.

`contracts.json` congela firmas públicas y cuerpos críticos del HEAD previo a M4 (`7eabedf`), incluyendo las diez reglas de definición de Waves, ambos recalculadores y el cuerpo de entrega de notificaciones conservado. No regenerar ese baseline desde el resultado de una modificación.

`m3-boundary-migrations.json` permite únicamente cuatro modificaciones explícitas de límites M3: tres facades longitudinales y el fix `pool_name`. Conserva sus hashes M3 originales; nueve límites siguen idénticos. La prueba M3 que antes acreditaba el bug de completion ahora exige completion funcional por callback y helper. Los 39 casos PHP y 41 JS M3 se mantienen.

`purge-manifest.json` documenta siete métodos privados A eliminados después de migrar sus callers y obtener 348+54 pruebas verdes. Los owners conservan sus implementaciones. Facades C y notificaciones B permanecen; se añade una prueba de esta purga.

Concurrencia: barrera de dos workers con conexiones independientes, dos comandos sobre un assignment, dos compare-and-set y un lock de padre que impide finalizar hasta COMMIT. La prueba observa orden de finalización y estado/filas afectadas; no depende de un umbral arbitrario de rendimiento. No acredita exactly-once de respuesta Storage, correo, jobs, audit ni post-commit.

Límites caracterizados: el servicio de borrado bloquea respuestas submitted, no todos los assignments pending; skipping por cron protege T1, mientras auto-skip de expired conserva su política diferente. `eipsi_assignment_status_changed` conserva un listener T1 y no adquiere una emisión automática inexistente. La creación masiva continúa creando assignments para todas las waves del estudio pese al nombre legacy de su variable `active_waves`.
