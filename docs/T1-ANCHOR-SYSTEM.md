# T1: interpretación de fechas y compatibilidad

Documento conservado porque el schema y los estudios existentes mantienen anclaje relativo a T1. No describe una release pública ni promete fechas inmutables.

## Datos persistidos

| Tabla sin prefijo | Columna | Significado |
|---|---|---|
| survey_participants | t1_completed_at | Fecha de finalización de T1 usada como ancla |
| survey_waves | offset_minutes | Desplazamiento de apertura respecto de T1 |
| survey_waves | window_minutes | Ventana explícita, cuando está configurada |
| survey_studies | study_end_offset_minutes | Desplazamiento del cierre del estudio |
| survey_assignments | available_at / due_at | Fechas absolutas calculadas para la asignación |

La implementación está en [class-t1-anchor-service.php](../admin/services/class-t1-anchor-service.php). Calcula aperturas desde T1 y determina cierres según ventana, siguiente wave o cierre/fallback del estudio. No interpretar un `NULL` como una duración universal. Las fechas pueden cambiar por recorridos explícitos de anclaje/recalculo; el texto anterior «persist forever» no era un contrato fiable.

## Migración y operación

Se conserva [migration-add-offset-columns.php](../scripts/migration-add-offset-columns.php) como herramienta histórica de schema. No ejecutarla automáticamente ni asumir que sustituye al instalador actual. Verificar columnas y datos existentes antes de una migración o un anclaje masivo.

Para interpretar un calendario existente, consultar primero `t1_completed_at`, configuración de la wave y fechas de su asignación. No reconstruir silenciosamente fechas desde el reloj actual. Los cron de disponibilidad/expiración y los recorridos de recalculo comparten estos datos; sus limitaciones actuales se describen en [arquitectura](architecture.md).
