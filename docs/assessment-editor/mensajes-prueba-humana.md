# Assessment Editor: mensajes y navegación

Cambios implementados el 15 de octubre de 2026. La prueba humana sigue pendiente. Sin cambios de configuración del Study Engine.

Para probar contenido nuevo en la experiencia real: guardar la evaluación, publicar una versión y abrir un intento de esa versión. Una versión ya publicada conserva su contenido.

| Cambio | Dónde configurarlo | Prueba humana |
| --- | --- | --- |
| Obligatoriedad por pregunta | Seleccionar pregunta → Propiedades → Mensaje de obligatoriedad | Marcar obligatoria, personalizar el texto y avanzar sin responder. Debe aparecer el texto elegido. |
| Validación por pregunta | Seleccionar pregunta → Propiedades → Mensaje de validación | Configurar una restricción de formato o longitud y escribir una respuesta inválida. Debe aparecer el texto elegido. |
| VAS | Mensaje de obligatoriedad de la propia pregunta | Marcar obligatoria y avanzar sin tocar la escala. Usa el mensaje general de obligatoriedad, sin «Tocá la escala». Las instrucciones se escriben en el contenido de la pregunta. |
| Matriz | Propiedades de la matriz → los mismos mensajes | Dejar un ítem obligatorio vacío. El mensaje se aplica a los ítems de esa matriz. |
| Encabezado de matriz | Propiedades de la matriz → Encabezado de los ítems | Reemplazar «Item» y comprobar la cabecera en Preview y en la versión publicada. |
| Selector | Propiedades del selector → Placeholder, ya existente | Cambiar el texto inicial del desplegable; no se agregó otro campo duplicado. |
| Sesión vencida | Contenedor general de la evaluación → Propiedades → Mensajes de la evaluación | Personalizar «Sesión vencida» y «Sesión vencida con parcial guardado». Con una duración corta, salir de la pestaña, esperar el vencimiento y volver. Se inicia desde el comienzo y se muestra el aviso correspondiente. |
| Procesamiento y resultado | Mismo apartado → Procesando, Sesión actualizada, Respuestas recibidas | Personalizar textos y avanzar o terminar. El aviso de procesamiento puede durar muy poco si la conexión es rápida. |
| Errores | Mismo apartado → Error de respuestas, Error de envío | En una prueba descartable, interrumpir la conexión y avanzar. Debe mostrarse el mensaje elegido y conservarse el intento para reintentar con el botón original. |
| Descarte | Configuración → Permitir descartar respuesta; Mensajes → Botón descartar, Confirmación de descarte, Respuesta descartada | Activar, responder y descartar. Debe usar los tres textos elegidos, eliminar sólo ese intento y permitir comenzar desde cero. |
| Progreso | Configuración → Mostrar progreso; Mensajes → Texto de progreso y Etiqueta accesible del progreso | Usar `Paso [ACTUAL] / [TOTAL]` en una evaluación de varias páginas. Comprobar la sustitución de ambos valores. |
| Salir / volver manualmente | Eliminado del runtime real | No debe haber botones ni pantalla «Saliste del cuestionario» / «Volver al cuestionario». La recuperación automática y el envío periódico del estado continúan funcionando. |
| Reinicio explícito | Eliminado de las propiedades de pantalla final, de los botones de finalización y de la acción del servidor | Terminar: debe verse el contenido final sin botón para responder otra vez ni confirmación de reinicio. |
| Nueva respuesta al recargar | Entrada pública de una evaluación independiente completada | Recargar después de terminar. Debe abrirse otro intento vacío de la misma versión; la respuesta anterior permanece almacenada. Recargar un intento en curso debe recuperarlo. |
| Otro navegador | Entrada pública de una evaluación independiente | Abrir la URL del intento en una ventana privada. Debe redirigir automáticamente a otro intento vacío, sin mensaje de acceso ni respuestas del anterior. El navegador original conserva su intento. |
| Condiciones | Presentación del runtime de evaluación | El salto interno muestra la pregunta destino. Un salto con destino inmediato cambia directamente al destino. Una salida sin destino inmediato muestra la pantalla final configurada, sin «Continuar en el estudio» ni aviso de transición. |

Los mensajes vacíos de validación usan el texto predeterminado: borrar el texto no elimina la obligatoriedad ni la restricción. Los textos se insertan como texto, sin interpretar HTML.

Las sesiones asignadas a estudios conservan su autorización y restricciones: la recarga permite otro intento sólo cuando la política de repetición ya existente lo permite. Si no lo permite, conserva la finalización; no se crea una respuesta independiente para eludirla. Una URL inventada que no identifica ninguna evaluación no permite deducir qué evaluación abrir; la recuperación automática cubre los enlaces a intentos existentes de versiones publicadas.

## Verificación automatizada

- 605 pruebas JavaScript: sin fallos.
- 384 pruebas PHP de las suites afectadas y relacionadas: sin fallos en la ejecución conjunta final.
- Build y verificación de artefactos aprobados.
- RCT estricto aprobado.
- Instalación limpia, lifecycle, reactivación y smoke aprobados en contenedores descartables.
- La prueba concurrente de exportación de Gestión falló una vez y pasó tanto aislada como en la última ejecución conjunta. Se amplió únicamente su diagnóstico; no se cambió el código de Gestión.
- Prueba humana: pendiente. No se hicieron commits.

## Corrección de validaciones por tipo

- Cambiar «Contenido esperado» o «Presentación del texto» conserva los mensajes de obligatoriedad y validación. Para comprobarlo, escribir ambos mensajes y alternar libre, email, URL, teléfono, sólo letras, numérico y multilínea.
- «Mensaje de validación» se ofrece sólo en texto y sus formatos, incluido numérico. Teléfono no incorpora un patrón telefónico nuevo: valida únicamente la longitud configurada.
- Fecha conserva su comprobación interna, pero no muestra un campo de mensaje de validación. Likert, matriz, selector, selección única y VAS siguen usando obligatoriedad cuando corresponde.
- Selección múltiple tiene «Mensaje de mínimo de opciones» y «Mensaje de máximo de opciones». Aparecen al configurar el límite correspondiente. Admiten `[MINIMO]` y `[MAXIMO]`.
- Con mínimo 2, probar cero y una opción: ambos casos deben mostrar el mismo mensaje de mínimo. Con dos opciones, debe continuar. Con máximo 2, tres opciones deben mostrar el mensaje de máximo.
- Con mínimo 0 y «Respuesta obligatoria» activada, no elegir ninguna opción debe mostrar el mensaje de obligatoriedad.
- Dejar los mensajes personalizados vacíos conserva los mensajes automáticos y no desactiva las restricciones.

Verificación de esta corrección: 608 pruebas JavaScript y 385 pruebas PHP aprobadas, más build y RCT estricto. Sin commit; el recorrido humano queda pendiente.

## Texto numérico: obligatoriedad frente a formato inválido

Se corrigió la pérdida de entradas inválidas causada por el control numérico nativo del navegador. El campo conserva ahora la entrada para distinguir un error de formato de una respuesta ausente. Los valores válidos siguen convirtiéndose y almacenándose como números.

Prueba humana: configurar «Texto → Una línea → Numérico», activar obligatoriedad y escribir mensajes distintos para obligatoriedad y validación. Dejar vacío debe mostrar obligatoriedad; escribir `abc`, `-` o `1e` debe mostrar validación. Con límites configurados, un número fuera del rango o incompatible con el paso también debe mostrar validación. Un número válido debe permitir continuar. Desactivar obligatoriedad debe permitir vacío, pero seguir rechazando una entrada inválida con el mensaje de validación.

Un rechazo de validación durante el envío automático también conserva el campo editable para poder corregirlo. Verificación de la corrección numérica: 610 pruebas JavaScript y 386 PHP sin fallos; build y RCT estricto aprobados. Sin commit.

## Multilínea sólo libre

Al seleccionar «Presentación del texto → Multilínea», el contenido pasa automáticamente a «Libre», incluso si antes era email, numérico u otro formato. En multilínea no aparecen «Contenido esperado» ni «Mensaje de validación». La obligatoriedad sigue disponible. Para configurar otro formato, volver primero a «Una línea».

## Propiedades condicionales de descarte y progreso

Los tres mensajes de descarte están debajo de «Permitir descartar respuesta» y se ocultan al desmarcarlo, sin borrar su contenido. Los dos textos de progreso están debajo de «Mostrar progreso» y se ocultan al elegir «No», también conservando su contenido. La etiqueta accesible da nombre a la barra para los lectores de pantalla; no agrega texto visible y se usa cuando se muestra una barra de progreso.

## Orden de navegación y sesión

Debajo de «Permitir volver atrás» aparecen, en orden, «Texto del botón Atrás» (sólo cuando el check está activado), «Texto del botón Siguiente» y «Texto del botón Terminar». Al final de «Sesión del cuestionario y guardado parcial» aparecen «Sesión vencida», «Sesión vencida con parcial guardado», «Procesando» y «Sesión actualizada»; ya no están en el apartado general de mensajes.

## Rótulos de duración y visibilidad del progreso

Se quitaron los rótulos visibles «Duración» y «Unidad» de la fila de conservación de sesión, conservando sus nombres accesibles. «Texto de progreso — [ACTUAL] y [TOTAL]» aparece sólo con «Página X de Y» o «Barra + páginas»; no aparece con «No» ni «Barra».

### Textos todavía sin edición directa

La revisión no certifica que absolutamente todo el texto del participante sea editable. Quedan el placeholder visible de imagen sin asignar («▧ Imagen — placeholder v0»), avisos automáticos de longitud de multilínea sin campo propio en ese modo y errores internos de fecha, escala, opciones o claves de respuesta inválidas. El texto de confirmación de descarte es editable, pero sus botones Aceptar/Cancelar y otros textos del control de fecha pertenecen al navegador.

## Textos pendientes incorporados

Esta sección reemplaza la lista anterior de pendientes de edición.

| Texto | Ubicación exacta |
| --- | --- |
| Imagen sin asignar | Seleccionar la imagen → Propiedades → Texto cuando no hay imagen. El nombre accesible usa Texto alternativo y, si está vacío, el mismo texto de imagen sin asignar. |
| Longitud mínima/máxima de multilínea | Seleccionar Texto → Presentación del texto: Multilínea → Propiedades → Mensaje de longitud mínima / Mensaje de longitud máxima. Admiten `[MINIMO]` y `[MAXIMO]`. |
| Fecha inválida | Seleccionar Fecha → Propiedades → Respuesta → Mensaje de fecha inválida. |
| Valor inválido en VAS, selección única/múltiple, lista, Likert o matriz | Seleccionar la pregunta → Propiedades → Respuesta → Mensaje ante un valor no válido. Los límites de cantidad de selección múltiple mantienen sus campos separados. |
| Error por una clave de respuesta desconocida o datos malformados de multilínea | Contenedor general → Propiedades → Mensajes de la evaluación → Error de respuestas. |
| Aceptar/Cancelar el descarte | Contenedor general → Propiedades → Configuración → Permitir descartar respuesta → Botón confirmar descarte / Botón cancelar descarte. |
| Calendario | Seleccionar Fecha → Propiedades → Calendario. Allí se editan Texto de ejemplo de fecha, Botón abrir calendario, Botón cerrar calendario, Botón mes anterior, Botón mes siguiente, Meses y Días de domingo a sábado. Los nombres se separan con `|`: exactamente 12 meses o 7 días. |

El descarte utiliza ahora un diálogo del plugin, tanto en Preview como en la evaluación real. Cancelar o Escape no elimina respuestas. La pregunta y ambos botones usan los textos configurados.

Fecha utiliza un calendario del plugin para que los nombres y botones sean editables. Elegir un día produce el mismo evento de respuesta que escribir una fecha, y conserva el formato de datos `AAAA-MM-DD`. Cambiar el texto de ejemplo no cambia ese formato. Se puede navegar por meses, seleccionar una fecha o cerrar sin cambiar la respuesta.

Prueba humana: personalizar cada texto, guardar y publicar una versión para verificarla en una evaluación real; comparar con Preview. Probar el calendario en febrero de un año bisiesto, el cambio de mes y el cierre sin selección. Probar cancelar y confirmar descarte, incluidos sus textos personalizados. Probar multilinea con longitudes fuera de rango.

Verificación de los textos pendientes incorporados: 614 pruebas JavaScript y 387 PHP sin fallos; build, artefactos y RCT estricto aprobados. Calendario y confirmación de descarte comparten implementación entre Preview y evaluación real. Prueba humana pendiente; sin commit.

## Ajustes de imagen, límites, fechas y progreso — 15/10/2026

- Imagen → Propiedades: «Elegir imagen en WordPress» abre la biblioteca de medios y permite cargar una imagen nueva. La URL también puede editarse directamente.
- Texto de una línea, multilinea, email, teléfono y URL → Propiedades: «Mensaje de longitud — [MINIMO] y [MAXIMO]» sustituye los dos mensajes separados. El mensaje se usa para ambos límites, tanto en Preview como al validar en el servidor. Sin límite mínimo se muestra 0; sin límite máximo, ∞.
- Texto numérico → Propiedades: se elimina «Paso» y se incorpora «Mensaje de rango — [MINIMO] y [MAXIMO]». Los decimales dentro del rango se aceptan salvo que esté marcado «Sólo enteros». El mensaje de validación de formato sigue aplicando a entradas que no sean números. La escala VAS conserva su paso.
- Fecha → Calendario → Formato de fecha: AAAA-MM-DD, DD/MM/AAAA o MM/DD/AAAA. Cambia la presentación y la escritura; las respuestas siguen almacenándose como AAAA-MM-DD. La selección del calendario respeta el formato elegido y las fechas imposibles se rechazan.
- Configuración general → Mostrar progreso: «Texto de progreso — [ACTUAL] y [TOTAL]» sólo aparece en «Página X de Y» y «Barra + páginas». No aparece con «No» ni con «Barra».
- La descripción del plugin queda en inglés: “Create assessments and manage cross-sectional and longitudinal studies with randomization, conditional logic, email notifications, and data export.”

Prueba humana pendiente: seleccionar/subir una imagen; personalizar el mensaje combinado y comprobar ambos límites; probar un decimal, un número fuera del rango y una entrada no numérica; cambiar los tres formatos de fecha y elegir un día desde el calendario; alternar los cuatro modos de progreso.

Verificación automatizada de estos ajustes: 618 pruebas JavaScript (suite completa) y 388 PHP (suites seleccionadas de Assessment Editor y flujos relacionados), sin fallos. Build, comprobación de artefactos y gate RCT estricto aprobados. Sin commit.

## VAS: texto anterior a la respuesta — 16/10/2026

En Propiedades → Presentación, inmediatamente después de «Mostrar valor», aparece «Texto antes de responder», con «Sin responder» como valor predeterminado. El campo sólo aparece cuando «Mostrar valor» está marcado; ocultarlo conserva el texto configurado. Preview y runtime real comparten este texto. Al tocar la escala se muestra el número respondido.
