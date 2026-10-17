# Study Engine — Visual Study Builder (P1-H)

Entrada administrativa: **EIPSI Forms → Study Engine**. Este editor crea definiciones del Study Engine; la administración legacy Study/Wave continúa disponible por separado. No migra estudios legacy ni crea participantes al simular.

## Arquitectura y contratos

`builder.php` compone Draft, Assessment, Preview, Service y Admin sobre los contratos P1-B, compiler/simulator P1-C, repositorios P1-D, contenido P1-E y configuración P1-F. El bootstrap carga esta composición únicamente en la pantalla Builder, su acción AJAX autenticada o el editor Gutenberg de una fuente vinculada. El motor científico puro P1-A–G conserva sus archivos y semántica.

El frontend usa módulos DOM de `src/admin/study-builder/`, sin framework ni dependencia nueva. El build genera `assets/js/study-builder.js` y `study-builder-source.js`; `check-build.js` verifica ambos artefactos. El CSS se limita a esta pantalla.

| Control | Contrato persistido |
|---|---|
| Study, nombre/contacto/identificador público | StudyVersion + metadata.study_admin; PublicStudy al activar |
| Árbol | nodes, parent_id, position, root_block_ids |
| Inspector Macro/Micro | Block y strategy existentes |
| Assessment/Gutenberg | snapshot de contenido, Question/Option, no referencia mutable publicada |
| Anchor, regla temporal y ventana | ScheduleSpec existente |
| Condiciones | condition_rules y AST tipado existente |
| Randomización | configuración de estrategia y owner existente |
| Notificaciones | metadata/configuración longitudinal P1-F |
| Validar/publicar | DefinitionValidator + DefinitionCompiler + snapshots/hashes |
| Preview | Simulator P1-C, contexto y commands efímeros |

No hay tabla builder_config, entidad Screen, resolver científico JS ni migración DDL nueva. La revisión de autoría vive en metadata_json y es independiente de la revisión de Execution.

## Crear, guardar y versionar

La lista muestra versiones Draft/Published/Retired, versión activa y URL pública cuando existe. Crear pide nombre, contacto e identificador público. El nuevo Draft contiene un Macro raíz; puede guardarse incompleto, pero siempre debe tener estructura, identidad, ownership y referencias válidos. Publish exige cierre semántico completo.

Autosave espera 900 ms y serializa peticiones. Cada guardado usa revisión esperada y save ID/hash; el servidor bloquea la fila y aplica CAS. Un conflicto conserva los cambios locales, detiene los reintentos y permite recargar con confirmación. Un reintento de red conserva la misma identidad de petición. Un replay antiguo devuelve la versión actual y nunca revierte una edición posterior. No existe merge automático entre pestañas ni historial Undo completo.

Published es read-only. Clone crea la siguiente versión Draft, conserva identidades científicas y cambia el binding de versión. Sólo se admite un Draft por Study. Publish valida, verifica media, compila, exige acknowledgements exactos de warnings y almacena snapshot/hash inmutables. Su audit registra actor, fecha, versión, protocol hash y acknowledgements, sin duplicar contenido. Retire registra actor/fecha/versión sin cambiar el hash.

Activar enrolamiento es una acción explícita separada: OPEN, PAUSED o CLOSED. Registra el cambio y mantiene la URL pública estable. Las executions existentes siguen pinneadas a su versión. Publicar por sí solo no activa enrolamiento. Discard elimina solamente el Draft con CAS; las fuentes Gutenberg creadas permanecen en Form Library.

## Árbol, Macro/Micro y PageBreak

Macro admite anidamiento Macro/Micro según el contrato. Micro GROUP permite composición interna; Micro ASSESSMENT contiene preguntas/contenido. El editor ofrece añadir, duplicar, mover, cambiar parent y borrar. Drag tiene alternativa por botones de movimiento y selector de parent. Rename/move conserva IDs; duplicar genera IDs nuevos y retargetea referencias internas. Borrar elementos referenciados o hacer un movimiento incompatible se rechaza de forma visible.

PageBreak es un separador `PAGE_BREAK_DIRECTIVE`, nunca una entidad Screen. Delimita segmentos que las estrategias internas deben respetar. Los pools de randomización se actualizan al modificar la estructura; el compiler sigue siendo la autoridad final. Los IDs técnicos quedan en opciones avanzadas.

## Assessment y Gutenberg

“Editar en Gutenberg” guarda primero el Draft pendiente, abre una fuente privada `eipsi_form_template` y conserva un enlace alternativo si el navegador bloquea la ventana. El bridge nativo usa wp.blocks y core/block-editor para reconstruir los bloques editables; Gutenberg continúa siendo el propietario de serialización/save del post. Guardar la fuente no modifica automáticamente el StudyVersion: hay que aplicar/importar el snapshot.

La fuente de una versión clonada se reconstruye desde su snapshot, evitando incorporar cambios recientes de una fuente de V1. El adaptador reutiliza TemplateAdapter P1-E, añade PageBreak desde core/separator y preserva grupos anidados. Preguntas existentes conservan ID/response_key por su field key; opciones compatibles conservan identidad. Cambios que invaliden referencias se rechazan antes de guardar. No se promete preservar IDs de contenido reemplazado sin identidad equivalente.

Un bloque no soportado informa nombre, ubicación y acción de reemplazo/eliminación. Media se valida por existencia/hash. Reconstruir una fuente con imágenes necesita el attachment original disponible: se informa error si falta, aunque un snapshot publicado anterior siga siendo independiente. No hay limpieza automática de fuentes/assets huérfanos.

## Schedule, timezone y recurrence

Anchor y temporal_rule se editan por separado. Los anchors referenciados exigen selector explícito cuando corresponde: SINGLE, SAME_PARENT, FIRST_COMPLETED, EXACT o LAST_COMPLETED_BEFORE. EXACT permite occurrence path; el último selector requiere el event ID definido por el contrato, sin “latest” implícito.

ELAPSED convierte minutos/horas/días de 24 h/semanas de 168 h a segundos. CALENDAR conserva DAY/WEEK/MONTH y timezone IANA. OFFSET, LOCAL_DAY_TIME, ABSOLUTE y RECURRENCE producen el ScheduleSpec canónico. La política de timezone incluye default/fallback y DST; cambiar la política general propaga schedules que usaban la política anterior, conservando overrides explícitos distintos.

DAILY_LOCAL permite varios horarios y días; WEEKLY_LOCAL días de semana/horario; ELAPSED intervalos. Las recurrencias exigen count o until. EMA cinco horarios por siete días se materializa en 35 occurrences en el Simulator. Availability window admite duración, fecha absoluta, evento o ninguna según el contrato. La vista temporal deriva del preview del servidor, sin motor temporal paralelo en JS.

El límite de preview es 10000 instancias y horizonte 366 días; las definiciones con count tienen comprobación estática de expansión anidada. Until-only se controla al materializar con el contexto real. Un exceso informa error, no una muestra truncada presentada como completa.

## Condiciones y randomización

El editor crea AST AND/OR/NOT y comparaciones tipadas sobre respuestas, estado o finalización de bloques. IMMEDIATE conserva first-true en orden; completion conserva la precedencia del motor. JUMP limita targets compatibles; WAIT exige target/resume y conserva los skips/provenance del motor. TERMINATE significa terminación por regla, distinto del withdrawal voluntario P1-G.

Las seis estrategias existentes se configuran en su owner original. RCT se expresa con el motor general de randomización de Macro; la randomización interna usa el mismo owner/contrato. La UI diferencia scope EXECUTION y BLOCK_INSTANCE, selección N, pesos, draw count y orden DEFINITION/DRAW. PageBreak queda fuera del pool y separa segmentos. La UI no implementa PRNG ni reinterpreta estrategias.

## Notificaciones y simulación

El editor configura el contrato P1-F: enrolamiento/verificación, disponibilidad inmediata, próximo horario, TTL y una lista dinámica de reminders positivos, únicos y anteriores al deadline cuando éste puede validarse estáticamente. No hay un número fijo de reminders en la UI.

El catálogo de email P1-F es fijo. Preview usa placeholders example.invalid y no envía SMTP; no existe un editor libre de asuntos/cuerpos que el sender no pueda consumir. La simulación muestra offsets de reminders como referencia, no ejecuta un delivery plan SMTP ni garantiza exactly-once.

Preview llama exclusivamente al Simulator P1-C. Permite inicio, timezone, seed, respuestas/submit, FIRST_ACCESS, VERIFY_PARTICIPANT, avance temporal y cambio de timezone. Muestra NextAction, occurrences, availability/deadline/status, eventos, skips/barriers y random provenance. Rechaza comandos productivos como withdrawal. No persiste participantes/respuestas, no escribe Storage y no envía email. Es una vista administrativa del motor; no una réplica visual completa del renderer público.

## Validación, permisos y límites

Errores bloquean Publish; warnings requieren aceptación explícita auditada. La pantalla muestra ubicación, resumen y diff por estructura/contenido/schedule/random/conditions/timezone/reminders/enrolamiento. Algunos diagnósticos avanzados mantienen el código del compiler como fallback.

Todas las operaciones AJAX exigen sesión con manage_options y nonce `eipsi_study_builder`; los helpers de servicio también verifican capability. Fuente/import exigen además edit_posts/edit_post. No hay endpoint nopriv de Builder ni URL pública del Draft. DTO/revisión/payload se validan y las respuestas inesperadas no exponen detalles internos.

Límites: payload Draft 2 MiB, 2000 nodos, 2000 receipts por Draft, lista 500 versiones y biblioteca 100 fuentes. Alcanzar el límite de receipts requiere continuar mediante un ciclo de versión sobre el último estado guardado; no ofrece pruning/rotación automática. No hay paginación completa, Undo global, presets científicos nuevos, migración legacy automática ni certificación WCAG. Responsive usa tres columnas, dos bajo 1100 px y stack bajo 720 px; botones/textos/focus complementan drag/color.

## Verificación y smoke de revisión

FULL final: **1919 únicos = 1785 PHP +134 JS**, exit 0 (231.08 s), incluyendo gate estricto RCT, build, instalación limpia, smoke y lifecycle. P1-H añade 124: 51 DB/API/HTTP, 33 puros y 40 DOM. PHP 7.4 pasa sintaxis final y los 33 puros; el FULL usa PHP 8.3. Los fixtures válidos 01–10 pasan roundtrip canónico/UI, validación, simulación diferencial y publish. El E2E HTTP crea, guarda, valida, simula, publica, activa y abre la URL pública. Los contratos M1/Purga admiten solamente el nuevo hook administrativo exacto; no se reescribieron baselines históricos.

El caso de 50 bloques/100 preguntas envía 84352 bytes y usa 9 consultas para save+validate (0.0151 s en este fixture). DOM JSDOM renderiza el árbol en milisegundos; estas observaciones locales no son un benchmark de navegador/producción.

Para smoke visual humano pendiente: abrir Study Engine, crear/editar con teclado y drag, editar/importar Gutenberg, comprobar columnas en móvil, validar errores/warnings, simular EMA/WAIT, publicar, clonar y activar V2 verificando un participante V1. No se declara ejecutado ese recorrido manual en navegador. SMTP real, Nginx, upgrades/datasets/themes institucionales, backups/keyring y accesibilidad asistiva deben verificarse en su entorno de deploy. No se inició P1-I.
