# Arquitectura actual

## Bootstrap y dominios

[eipsi-forms.php](../eipsi-forms.php) registra includes, hooks, assets y bloques. `admin/` concentra pantallas, handlers y servicios; `includes/` contiene shortcodes, renderizado y recorridos del participante; `src/blocks/` genera `build/blocks/`. No existe todavía la modularización M1–M8.

Los dominios existentes son formularios/respuestas, estudios/waves, participantes/sesiones, Pools/asignación, correo/cron y administración/exportación. No son módulos aislados: comparten bootstrap, tablas y callbacks. El inventario ejecutable de [M0](../tests/m0/README.md) permite observar registros efectivos y declaraciones por perfil admin/frontend.

## Contratos estabilizados

Emergencia devuelve éxito solo tras persistencia confirmada y comunica el destino real. Diagnóstico parcial exige capability administrativa y nonce. Retiro y REST `/pool-assign` derivan identidad de la sesión y comprueban el contexto del estudio. Las pruebas P0/P1/M0 documentan los contratos y sus límites en [tests/README.md](../tests/README.md).

## Deuda activa para fases posteriores

M0 caracteriza nueve emisores sin handler exacto: `eipsi_export_participants_long_excel`, `eipsi_export_participants_long_csv`, `eipsi_send_individual_reminder`, `eipsi_recalculate_preview`, `eipsi_recalculate_waves`, `eipsi_rollback_recalculation`, `eipsi_load_form`, `eipsi_create_from_clinical_template`, `eipsi_get_participant_dashboard`. Hay emisores activos y otros dormidos; los handlers parecidos no garantizan equivalencia de contrato. No se restauraron ni eliminaron esas UI.

`SchemaManager::check_collation_issues` y `SchemaManager::execute_maintenance_sql` siguen ausentes: los recorridos identificados no demostraron un uso interno activo que justificara intervenir en M0. La reparación activa de tabla y el envío weekly T1 sí recibieron correcciones mínimas.

Coexisten recordatorios legacy y actuales. El chequeo de salud puede reprogramar tareas con frecuencia horaria; la desactivación no retira todos los hooks. Algunas exportaciones administrativas todavía generan archivos bajo el plugin. Estos riesgos pasan a fases posteriores; no se consideran resueltos por tests verdes.

## Datos y compatibilidad conservada

[T1 y fechas persistidas](T1-ANCHOR-SYSTEM.md) explica columnas y anclaje necesarios para interpretar estudios existentes. [Registros históricos de correo](../FIX-EMAIL-LOOP-DEPLOYMENT.md) explica tipos vacíos y metadatos de deduplicación. Los formatos JSON heredados siguen documentados en [templates](../templates/README.md).

La futura separación por dominios debe preservar estos contratos antes de reorganizar servicios. Esta documentación no autoriza ni ejecuta M1.
