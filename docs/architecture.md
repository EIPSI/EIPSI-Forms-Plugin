# Arquitectura actual

## Bootstrap y dominios

[eipsi-forms.php](../eipsi-forms.php) conserva header/constantes y carga [bootstrap.php](../includes/bootstrap/bootstrap.php). `admin/` concentra pantallas, handlers y servicios; `includes/` contiene shortcodes, renderizado y recorridos del participante; `src/blocks/` genera `build/blocks/`. M1 separó composición y registros; los módulos funcionales M2–M8 siguen pendientes.

Los dominios existentes son formularios/respuestas, estudios/waves, participantes/sesiones, Pools/asignación, correo/cron y administración/exportación. No son módulos aislados: comparten bootstrap, tablas y callbacks. El inventario ejecutable de [M0](../tests/m0/README.md) permite observar registros efectivos y declaraciones por perfil admin/frontend.

## Contratos estabilizados

Emergencia devuelve éxito solo tras persistencia confirmada y comunica el destino real. Diagnóstico parcial exige capability administrativa y nonce. Retiro y REST `/pool-assign` derivan identidad de la sesión y comprueban el contexto del estudio. Las pruebas P0/P1/M0/M1 documentan los contratos y sus límites en [tests/README.md](../tests/README.md).

## Deuda activa para fases posteriores

M0 caracteriza nueve emisores sin handler exacto: `eipsi_export_participants_long_excel`, `eipsi_export_participants_long_csv`, `eipsi_send_individual_reminder`, `eipsi_recalculate_preview`, `eipsi_recalculate_waves`, `eipsi_rollback_recalculation`, `eipsi_load_form`, `eipsi_create_from_clinical_template`, `eipsi_get_participant_dashboard`. Hay emisores activos y otros dormidos; los handlers parecidos no garantizan equivalencia de contrato. No se restauraron ni eliminaron esas UI.

`SchemaManager::check_collation_issues` y `SchemaManager::execute_maintenance_sql` siguen ausentes: los recorridos identificados no demostraron un uso interno activo que justificara intervenir en M0. La reparación activa de tabla y el envío weekly T1 sí recibieron correcciones mínimas.

Coexisten recordatorios legacy y actuales. El chequeo de salud puede reprogramar tareas con frecuencia horaria; M1 corrige la desactivación para retirar las variantes de argumentos de los 19 cron cuya propiedad está demostrada. Algunas exportaciones administrativas todavía generan archivos bajo el plugin. Estos riesgos pasan a fases posteriores; no se consideran resueltos por tests verdes.

## Datos y compatibilidad conservada

[T1 y fechas persistidas](T1-ANCHOR-SYSTEM.md) explica columnas y anclaje necesarios para interpretar estudios existentes. [Registros históricos de correo](../FIX-EMAIL-LOOP-DEPLOYMENT.md) explica tipos vacíos y metadatos de deduplicación. Los formatos JSON heredados siguen documentados en [templates](../templates/README.md).

La futura separación por dominios debe preservar estos contratos antes de reorganizar servicios. M1 no modifica esos dominios ni ejecuta M2.

## Componentes M1

- [Bootstrap](../includes/bootstrap/class-bootstrap.php): registro idempotente de la composición.
- [ServiceLoader](../includes/bootstrap/class-service-loader.php): manifest explícito, ordenado y con límites de inicialización. Los archivos admin que ya exponían handlers públicos siguen cargándose también en frontend; solo el helper de debug mantiene condición WP_DEBUG.
- [HookRegistry](../includes/bootstrap/class-hook-registry.php): secuencia de registros y delegación a owners específicos.
- [AssetRegistry](../includes/bootstrap/class-asset-registry.php) y [asset-callbacks](../includes/bootstrap/asset-callbacks.php): mismos handles, dependencias, condiciones y localizations.
- [BlockRegistry](../includes/bootstrap/class-block-registry.php) y [block-callbacks](../includes/bootstrap/block-callbacks.php): registro de los 13 manifests y categorías, sin cambiar bloques.
- [CronRegistry](../includes/bootstrap/class-cron-registry.php): schedules, trece programaciones de activación y catálogo explícito de 19 hooks poseídos.
- [Lifecycle](../includes/bootstrap/class-lifecycle.php): hooks ligados al archivo principal, schema/verification, invocación legacy de migración de Pools y desactivación sin borrar datos.
- [Compatibilidad global](../includes/compatibility/legacy-main-callbacks.php): implementaciones existentes reubicadas sin convertirlas en módulos de negocio; las dos funciones públicas de lifecycle delegan mediante [facades](../includes/bootstrap/lifecycle-callbacks.php).

Los requires se ejecutan en el mismo alcance del archivo de entrada. No se envolvieron en un método: eso alteraría el alcance de variables globales de archivos existentes. MigrationRunner y SurveyAccess conservan sus posiciones en la secuencia.

La desactivación usa `wp_unschedule_hook` para todas las variantes de argumentos de los hooks conocidos, incluidos jobs por estudio y eventos únicos de nudges/disponibilidad. No limpia por prefijo arbitrario, no borra tablas/opciones/datos y no cambia cron de terceros. Rewrites y transients mantienen el comportamiento previo. La activación conserva trece eventos periódicos; otros se programan contextualmente por sus owners existentes. El worker y los pipelines legacy/actuales no se consolidaron.

El fixture [baseline M1](../tests/m1/baseline.json) limita diferencias permitidas a archivos de extracción enumerados, ubicaciones de callbacks, identidad del archivo para traducciones, timestamps de petición ya existentes y limpieza de cron autorizada. Los registros públicos y 44 implementaciones globales se verifican automáticamente. La capa de compatibilidad sigue siendo grande; su separación funcional pertenece a fases posteriores.
