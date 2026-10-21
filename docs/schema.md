# Schema y upgrades — M8

El schema actual reside en `includes/schema/class-schema-registry.php`: 26 tablas locales, declaraciones SQL de columnas e índices y orden de creación. No es historial de migraciones ni ORM. Los datos dinámicos T* son columnas `T[0-9]+_` de participants; se conservan fuera de la definición estática. No se encontró un creador activo de tablas llamadas T*.

## Responsabilidades

- Registry define la estructura actual. Mantiene nombres, tipos y uniques previos; incorpora manual_overrides y las columnas nullable utilizadas por recalculation/settings.
- Installer crea tablas ausentes con dbDelta y verifica después; en tablas existentes delega únicamente reparación aditiva. Conserva las FK físicas históricas y su instalación best effort: no elimina huérfanos para forzar FK.
- MigrationRunner conserva v1–v9 y añade v10 para convergencia. El nombre histórico EIPSI_Migration_Runner hereda del owner explícito. Versiones negativas/futuras y Randomization sin user_fingerprint se rechazan; no se inventa un mapeo participant_id→fingerprint.
- Inspector solo lee estructura; detecta tablas, columnas, tipos e índices ausentes/incompatibles. JSON de MariaDB requiere LONGTEXT con CHECK JSON_VALID sobre esa columna. Collation se reporta por separado.
- Repair añade tablas/columnas/índices canónicos; nunca convierte tipos, borra columnas o convierte collation masivamente. Una columna requerida sin default en una tabla poblada se rechaza. Reintentos después de DDL parcial son seguros.
- ExternalSchemaAdapter posee CREATE/ADD externos. Su creador de resultados conserva el contrato externo y añade browser/os/screen_width, necesarios para el INSERT actual; su preparación aditiva cubre también estas columnas en tablas existentes. No constituye un migrador de cualquier schema externo.

## Estado y ejecución

`eipsi_migration_version` es un checkpoint entero, actualmente 10. Se persiste después de cada migración terminada y se verifica mediante lectura directa de wp_options. La fecha se guarda en eipsi_migration_date. v10 también mueve la conversión histórica email_type ENUM→VARCHAR(100) desde dbDelta a una transformación explícita, preservando filas y atributos y rechazando anchos incompatibles. v1–v9 mantienen numeración; v1 no estrecha el tipo de template_id al renombrar y v2 bloquea identidades históricas no mapeables.

`eipsi_schema_revision` es SHA-256 de la definición canónica, escrito solo después de verificar Installer. `eipsi_db_schema_version=2.6.1` permanece como marcador de compatibilidad de versión interna del plugin; no representa schema ni release pública. No cambian versión pública, package.json ni migraciones previas por renumeración.

Activación ejecuta Installer; en una base local nueva marca migration version 10 y evita migraciones históricas. Carga administrativa comprueba MigrationRunner. plugins_loaded/admin_init conservan registro/prioridad, pero su verificación periódica inspecciona sin DDL y guarda eipsi_schema_inspection_issues/eipsi_schema_last_inspected. Repair explícito sigue disponible detrás de las capabilities/nonces existentes.

Migration/Installer/Repair comparten GET_LOCK sobre DB_NAME+prefix. Es una exclusión en la misma instancia MySQL/MariaDB, con espera de 5 segundos y RELEASE_LOCK en finally; no es un lock distribuido. ALTER no es transaccional: una interrupción deja checkpoint previo y cambios parciales verificables; el reintento completa la misma migración.

## Añadir una migración

Modificar Registry únicamente si cambia el contrato demostrado. Añadir migrate_vN al owner, incrementar LATEST_VERSION sin renumerar y declarar precondiciones explícitas. Cada SQL crítico debe comprobar false y lanzar error; nunca actualizar checkpoint dentro de una transformación incompleta. No sustituir datos ambiguos por valores inventados. Agregar fixtures con filas, IDs, relaciones, valores y timestamps; probar SQL fallido, reintento y concurrencia. Mantener firmas de facades, hooks y handlers.

## Límites preservados

form_data y raw_post_data de emergency son campos distintos: ambos permanecen y el fixture comprueba preservación del payload histórico. No se asume que sean intercambiables ni se sobrescribe uno con otro. El fallback DDL de emergency y el creador dinámico T* durante submit se mantienen por seguridad de instalaciones no caracterizadas.

El handler de settings usa ahora study_name; start_date/end_date son nullable. Su contrato exige draft, mientras el enum canónico no incluye draft: se prueba con fixture legacy compatible, sin añadir un nuevo estado funcional. Availability activa consulta wave_index en waves; se caracteriza sin duplicarlo en assignments.

Collations históricas unicode_ci y nuevas unicode_520_ci se conservan y reportan; tipos, nullability/defaults, índices y FK de la instalación histórica capturada convergen con clean. No convertir datos automáticamente para forzar una igualdad de collation. execute_maintenance_sql no se implementa: la action administrativa conservada responde 501 después de capability/nonce. Scripts operativos históricos permanecen.

## Verificación operativa

Ver [tests/m8/README.md](../tests/m8/README.md). Ejecutar únicamente en Docker descartable. Antes de tocar una instalación real, capturar estructura y respaldar datos por el procedimiento institucional; M8 no ejecuta despliegues ni migraciones en producción.
