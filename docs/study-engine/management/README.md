# Gestión y auditoría por estudio

Acceso: **EIPSI Forms → Study Engine → Gestionar**, desde la tarjeta del estudio o desde su editor. Requiere `manage_options` y nonce de Builder. No requiere publicar un nuevo protocolo para consultar datos ya persistidos.

## Vistas

- **Resumen:** participaciones, inicio, completitud científica, cierre administrativo, oportunidades, intentos finales, parciales, tasa y última respuesta. Los indicadores analíticos excluyen `DO_NOT_USE`. Los operacionales permiten localizar el retiro sin recuperar contenido excluido.
- **Participaciones:** versión, ciclo, estado y fechas. La identidad se consulta explícitamente y deja una evidencia administrativa. No se asume una persona común entre estudios o ciclos.
- **Evaluaciones y respuestas:** oportunidades programadas e intentos Native, respuestas tipadas, matriz por ítem, diccionario de la versión publicada, sesión persistida separada de parcial guardado, metadatos sin seeds ni credenciales.
- **Comunicaciones:** sólo en longitudinal; intención, cola y cada intento instrumentado. Aceptación por transporte no acredita entrega ni lectura. No existe reenvío administrativo implícito.
- **Auditoría:** secuencia científica por ejecución, operaciones administrativas, comandos Native y decisiones de randomización. Sin reconstrucción retrospectiva del historial no registrado.
- **Datos y exportación:** respuestas, progreso, diccionario, auditoría, randomizaciones e identidades separadas; CSV y JSON con manifiesto.
- **Diagnóstico:** esquema, vínculos, cola, confirmaciones de almacenamiento, muestra de integridad y heartbeat. Cada muestra informa su límite; condiciones no medidas no se presentan como sanas.

Las consultas no llaman `view`, `tick`, expiración ni envío del runtime. La vista administrativa puede mostrar un vencimiento pendiente de procesar sin producirlo.

## Contrato de exportación

El contexto une estudio → ciclo → versión Study → participación → oportunidad → versión Native → intento → variable/ítem. El diccionario se obtiene de snapshots publicados, incluso sin respuestas. No usa el draft mutable. Las preguntas no seleccionadas, omitidas por condición, sin registro y sin valor se distinguen mediante `response_status`; nunca se infiere un «no» de un dato ausente.

Los valores numéricos siguen siendo números en JSON. CSV usa columnas explícitas y escapa las cadenas que puedan convertirse en fórmulas, conservando números negativos. Las identidades no se añaden al dataset de respuestas.

El job fija IDs y huellas de los casos al solicitarse; nuevos ingresos quedan fuera de ese corte. Procesa 20 participaciones por petición, admite continuar tras salir de la pantalla y revalida todas las huellas antes de habilitar o descargar el resultado. Si un caso cambia, el artefacto se invalida; no combina estados de momentos diferentes. No es un proceso de exportación autónomo por cron: la interfaz hace avanzar sus peticiones asíncronas.

Artefactos privados en base de datos, ligados al administrador solicitante y al estudio; sin archivos públicos ni URLs de descarga portadoras de permisos. Caducan para acceso a la hora; una nueva solicitud limpia los payloads caducados. El límite de contenido es 32 MiB: superar ese límite genera un fallo explícito que pide reducir el filtro. Los bloqueos finales siguen el orden de las ejecuciones antes del job. Retiro, descarte y finalización borran/inutilizan payloads en su propia transacción. Los archivos descargados anteriormente quedan fuera del sistema.

## Persistencia y auditoría

Migración 16: dos tablas InnoDB (`management_events`, `management_exports`) e índice generado `management_binding` en Native para evitar búsquedas JSON sin índice. Las copias de intentos omiten las columnas generadas. La reinstalación es idempotente.

Los registros nuevos de autoría, publicación, lifecycle, Native, identidad, exportación y transporte se incorporan a sus transacciones existentes. La auditoría no copia respuestas, direcciones, cuerpos de email, tokens ni seeds. La eliminación de contenido también limpia sus evidencias asociadas; se conserva únicamente un recibo estructural de eliminación del estudio.

## Retirada anterior

Las entradas Cross-sectional Study y Longitudinal Study y sus pantallas dejaron de ofrecerse; la entrada principal abre Study Engine. El inventario `retirement-inventory.json` identifica las rutas administrativas restantes por archivo de origen.

**El bloqueo conjunto de esos AJAX no se aplicó.** La revisión automática lo rechazó dos veces porque no consideró demostrada la ausencia de consumidores operativos para todas las rutas. Requiere aprobación explícita adicional. No se borraron datos ni se sustituyeron servicios compartidos de SMTP, almacenamiento o privacidad. Esto es una retirada pendiente, no una capa de compatibilidad nueva.

## Validación

`tests/run-study-management.php`: permisos/CSRF, límites entre estudios, no mutación, políticas, Native real, números, diccionario, exportaciones, invalidación, descarte, retiro y cierre. `tests/study-engine/builder/management-dom.js`: navegación, aislamiento, paginación, escape y fallos visibles.

`tests/study-engine/management/volume.php` mide lecturas a 100/1.000/10.000 participaciones sintéticas con dos oportunidades/intentos Native por caso y revierte toda la carga. Es un benchmark SQL de consultas, no una certificación de 10.000 recorridos clínicos, SMTP o capacidad institucional. Los casos funcionales ejecutan los owners reales por separado.

Pendiente revisión humana del recorrido lista → gestionar → respuesta/versionado → comunicaciones → exportación. No se afirma una revisión visual real del navegador a partir de pruebas DOM.
