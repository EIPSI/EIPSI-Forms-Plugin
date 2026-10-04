# Interpretación de registros históricos de correo

Documento conservado por compatibilidad de datos, no como evidencia de una release ni de un deployment exitoso. Sustituye el procedimiento antiguo de subir un único PHP y hacer rollback masivo.

## Por qué se conserva

La transición histórica de `email_type` ENUM a VARCHAR puede haber dejado valores vacíos. Los avisos de disponibilidad también usan tipos `wave_availability_T<N>`. El servicio [Wave Availability](admin/services/class-wave-availability-email-service.php) conserva deduplicación con información legacy y metadatos `wave_id`, `wave_index` y `nudge_stage`. Estos antecedentes permiten interpretar registros existentes; no implica que una instalación concreta esté afectada.

## Diagnóstico y reparación de datos

Se conservan [consultas de diagnóstico](diagnostic-queries.sql), [SQL de reparación histórico](fix-email-loop-data-repair.sql) y [consultas de verificación](fix-email-loop-verification-tests.sql). Son herramientas existentes, no las 183 regresiones automatizadas actuales.

Antes de usar esos SQL, revisar su contenido frente al schema y prefijo reales: contienen nombres `wp_`, filtros y ejemplos de la instalación original. Primero identificar filas concretas con tipo vacío y metadatos suficientes; no reconstruir tipos cuando faltan datos para identificar la wave. Una eventual reparación requiere copia de las filas originales, revisión del conjunto afectado y verificación posterior. No ejecutar automáticamente como parte de instalación/build/tests.

No asumir reversibilidad por reemplazar todos los tipos nuevos por valores vacíos: eso perdería registros válidos. La recuperación debe usar los valores originales de las filas efectivamente modificadas. Las garantías actuales de envío y deduplicación están cubiertas por [regresiones P1-B](tests/README.md), con los límites que allí se detallan.
