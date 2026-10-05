# M5: Notifications

104 pruebas M5; total 507 con las 403 previas. Ejecutar `tests/run-m5.php` en el Docker descartable descrito en [M0](../m0/README.md). Guardas obligatorias: CLI, DB_HOST/DB_NAME M0 y marca isolated_install. Correo interceptado; no modificar schema. No ejecutar suites concurrentemente: comparten instalación y el runner restaura el option cron.

Contratos: `contracts.json` congela firmas públicas, 17 templates y cinco cuerpos críticos del HEAD e715155. `boundary-migrations.json` explica únicamente cambios de fronteras autorizados M5; los baselines M3/M4 mantienen sus hashes históricos. M4 recompone el cuerpo original de Wave desde contexto Longitudinal y adapter Notifications para comparar sus tokens.

Concurrencia real usa dos procesos PHP, barrera y conexiones independientes. DOING_CRON evita el wake-up de visitas antes de la barrera. Se prueba un solo claim del mismo job, dos schedulers por assignment, refresh concurrente y jobs distintos con retry. La persistencia del queue no deduplica enqueues; no afirmar exactly-once.

Los tests conservan deliberadamente una deuda: el listener T1 cancela pending, pero reconstruye solo available mientras sequence selecciona pending. El template manual original también omite la sección custom_message por su variable local; M5 no cambia templates. Lifecycle, admin HTTP, entrega legacy y orden dentro/post transacción se caracterizan sin introducir reglas nuevas.

`purge-manifest.json` identifica únicamente métodos privados duplicados sustituidos por owners. Las facades públicas y callbacks registrados permanecen. Instalar con volúmenes nuevos según M0, ejecutar P0/P1 en copia escribible del código y M0–M5 sobre plugin montado; construir con Node 22 y comprobar build/blocks.
