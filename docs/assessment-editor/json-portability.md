# Importar y exportar evaluaciones JSON

Assessment Editor permite importar una evaluación desde su listado y exportar una evaluación guardada desde el editor. Exportar requiere guardar primero los cambios.

El archivo tiene `format: EIPSI_ASSESSMENT_JSON`, `version: 1` y `definition`. Contiene la definición completa de autoría: preguntas, variables, opciones, matrices, VAS, grupos, randomizaciones, páginas, diseño, navegación, sesión y pantalla final. No contiene respuestas de participantes, sesiones, ejecuciones ni publicaciones.

Importar valida el documento completo antes de guardar. Crea una evaluación independiente en borrador con IDs nuevos para evaluación, elementos, opciones, filas de matrices, etiquetas de VAS y páginas. Conserva los nombres de variables y el contenido. No sobrescribe una evaluación existente y no publica automáticamente. El tamaño máximo del archivo es 1 MB.

Las imágenes conservan sus URLs; el JSON no incluye archivos multimedia. Al trasladarlo a otra instalación esas URLs deben seguir siendo accesibles.

Sólo se admite este formato del editor actual. No hay importación de formatos del sistema anterior.

Form Library se retiró del menú administrativo y su interfaz de edición y exposición REST están deshabilitadas. Los contadores de respuestas y última respuesta no se incorporan a Assessment Editor: su lugar previsto es la auditoría por estudio.

## Preguntas individuales

Cada tarjeta de pregunta ofrece «Exportar pregunta JSON» cuando la evaluación está guardada. «Importar pregunta JSON» en la barra permite incorporar un archivo `EIPSI_QUESTION_JSON`, versión 1, a la evaluación abierta, incluso si tiene cambios pendientes.

Se inserta después del elemento seleccionado, dentro del grupo/randomización seleccionado o al final de la evaluación si no hay selección. La importación permanece como cambio pendiente hasta guardar la evaluación. Se conservan tipo, textos enriquecidos, opciones, valores, validaciones, estilos, filas de matriz y configuración VAS. Se generan identidades nuevas y se conserva el nombre de variable si está disponible; si colisiona con otra variable se asigna uno nuevo y se derivan las variables de sus filas de matriz.

El documento incluye sólo la pregunta, sin respuestas, sesiones ni configuración global de la evaluación. Sólo admite tipos de pregunta; no importa grupos, imágenes, instrucciones ni saltos de página como preguntas.
