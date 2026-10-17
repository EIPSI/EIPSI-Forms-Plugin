# Consulta y selección de versiones publicadas

En Assessment Editor, **Versiones publicadas** está disponible en cada evaluación del listado y en la barra del editor guardado. El historial muestra número de publicación, revisión del draft que la originó, fecha y estado. **Ver versión** abre el snapshot en el mismo visual, en modo de consulta: se pueden seleccionar preguntas, desplegar propiedades y previsualizar su contenido. Guardar, publicar, importar preguntas y los controles de edición quedan desactivados. **Volver al draft** recupera la definición mutable actual sin sustituirla por el snapshot anterior.

El número de versión identifica publicaciones de la misma evaluación, mientras que la revisión identifica guardados del draft. No son el mismo contador. El historial ordena por revisión de autoría, fecha e ID; dos publicaciones de la misma revisión y segundo tienen el mismo contenido y se distinguen por ID. No hay una migración de snapshots ni se modifica su contenido.

Las operaciones `versions` y `load_version` exigen capacidad de administración, nonce, POST y origen como el resto de Assessment Editor. La consulta de un snapshot exige que pertenezca a la evaluación indicada y verifica su hash. También pueden consultarse versiones retiradas; no pasan a estar disponibles para nuevas asignaciones.

En Study Engine, las evaluaciones se agrupan por su identidad. Transversal muestra una entrada por evaluación en Elementos; al seleccionarla, Propiedades permite elegir su versión. El buscador longitudinal devuelve una entrada por evaluación y después permite elegir la versión tanto en Assessment directo como en cada candidata de una randomización. El mismo formulario cubre las reglas de repetición.

Al añadir una evaluación se elige inicialmente la última publicación disponible. Al abrir una configuración existente se conserva la versión que ya tiene; publicar otra evaluación no cambia automáticamente los estudios. El cambio de versión se aplica al draft del estudio, con el snapshot, hash, preguntas y randomizaciones exactos de la publicación seleccionada. Los protocolos Study publicados y sus participantes mantienen sus bindings.

Una actualización dentro de la misma evaluación conserva referencias a preguntas que mantienen su identidad y contrato. Si cambia o desaparece una pregunta/opción utilizada por condiciones, se pide revisar esas condiciones antes de aplicar el cambio. La validación y compilación del Study siguen siendo obligatorias antes de publicar. Las condiciones entre evaluaciones pertenecen al protocolo Study; no se atribuyen al snapshot Native como si fueran parte de su autoría.

Pruebas: `tests/run-assessment-versions.php` (6 DB/API/HTTP) y `tests/assessment-editor/versions-dom.js` (6 DOM). Además se ejecutan las regresiones de Native, Builder, autoría y trayectorias. No sustituyen la revisión visual humana.

Validación de esta entrega: 604 pruebas JavaScript completas; 188 pruebas PHP de Native/Builder/autoría/versiones, más RCT estricto; build y comprobación de artefactos verdes. Después del último ajuste de aislamiento de vistas se repitieron las 118 pruebas DOM de versiones/producto/autoría. Sin commit ni push.
