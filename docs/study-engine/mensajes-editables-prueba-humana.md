# Dónde editar los textos públicos de Study Engine

Implementación del 16/10/2026. No se creó una página nueva. Los textos propios de una evaluación permanecen en Assessment Editor y los emails en Templates de Emails.

## Guardado y publicación

Los campos muestran su texto predeterminado o el texto guardado. Son texto plano; las variables indicadas se reemplazan al mostrarlos. Un campo vacío se conserva vacío. Editar no modifica condiciones, sesiones, validaciones ni políticas. Para cambiar la experiencia pública, guardar el draft, publicar su versión y activarla para los nuevos ingresos según el control de estado. Las participaciones existentes conservan su versión; no se reescriben snapshots publicados.

## Ubicación de cada campo

### Ingreso y validación del email

Study Engine → abrir estudio → Pantalla Inicial → seleccionar Autenticación → Propiedades → Validación del email → Ingreso y validación del email.

| Campo | Texto predeterminado |
| --- | --- |
| Email no encontrado | No encontramos ese email en este estudio. Revisá la dirección o desmarcá «[PERTENENCIA]» para inscribirte. |
| Email ya registrado | Ese email ya está registrado. Te enviamos un enlace para validar tu email; no creamos otra inscripción. |
| Solicitud de validación | Si corresponde, te enviamos un enlace para validar tu email. |
| Envío con primera evaluación disponible | Te enviamos por email el acceso a tu primera evaluación. El enlace confirma tu dirección y abre la evaluación si sigue disponible. |
| Envío sin evaluación disponible | Te enviamos un enlace para validar tu email. Después de validarlo recibirás por email los avisos de las próximas evaluaciones. |
| Email validado | Tu email está validado. Te enviaremos por email los avisos de las próximas evaluaciones. |
| Próximos avisos sin validar | Te enviaremos por email los avisos de las próximas evaluaciones. Al abrir uno de esos enlaces se validará tu dirección. |
| Enlace de evaluación vencida con otra disponible | Email validado. El período de la evaluación de este enlace terminó. Ahora podés responder la siguiente evaluación disponible. |
| Enlace de evaluación vencida sin otra disponible | La evaluación de este enlace ya venció. Tu email quedó validado; te enviaremos por email los avisos de las próximas evaluaciones. |
| Email obligatorio | Ingresá tu email. |
| Formato de email inválido | Ingresá un email válido. |

### Confirmación después de la primera evaluación

Study Engine → abrir estudio → Pantalla Inicial → seleccionar Autenticación → Propiedades → Validación del email → Confirmación después de la primera evaluación.

| Campo | Texto predeterminado |
| --- | --- |
| Título de confirmación | Confirmá tu email |
| Explicación de confirmación | Tu primera evaluación quedó guardada. Volvé a escribir tu dirección. Si hubo un error de tipeo, podés corregirla sin perder tus respuestas. |
| Etiqueta del email de confirmación | Email |
| Botón confirmar dirección | Confirmar dirección |
| Botón enviar validación, cuando se exige enlace | Enviar enlace de validación |
| Validación pendiente — [EMAIL] | Solicitamos el envío del email de validación a [EMAIL]. Abrilo para confirmar la dirección. También podés corregirla y solicitar otro enlace. |
| Aviso de validación al terminar | Enviamos el enlace de validación. Abrilo para confirmar tu dirección y acceder a las próximas evaluaciones. |
| Aviso al confirmar sin enlace adicional | Te enviaremos por email los avisos de las próximas evaluaciones. Al abrir uno de esos enlaces se validará tu dirección. |

### Cambio de correo

Study Engine → abrir estudio → Pantalla Inicial → seleccionar Autenticación → Propiedades → Validación del email → Cambio de correo.

| Campo | Texto predeterminado |
| --- | --- |
| Nombre accesible del modal | Confirmar cambio de correo |
| Pregunta de cambio de correo | El email es diferente al introducido anteriormente. ¿Estás seguro de que querés actualizar el correo? |
| Botón cancelar cambio | Cancelar |
| Botón actualizar correo | Actualizar correo |

### Mensajes de confirmación de abandono

Study Engine → abrir estudio → Pantalla Inicial → Abandono del estudio → seleccionar el visual Modal de confirmación → Propiedades → Mensajes de confirmación de abandono.

| Campo | Texto predeterminado |
| --- | --- |
| Frase de confirmación incorrecta | Escribí exactamente el nombre indicado en mayúsculas. |
| Nombre accesible del campo | Campo de confirmación |
| Aviso sin JavaScript | Active JavaScript para usar la confirmación escrita de abandono. |

### Espera de evaluaciones

Study Engine → abrir estudio → Configuración General → Espera de evaluaciones.

| Campo | Texto predeterminado |
| --- | --- |
| Título de espera | Todavía no hay evaluación disponible |
| Descripción de espera | Podés volver a consultar la disponibilidad. |
| Texto anterior a la próxima disponibilidad | Próxima disponibilidad: |
| Botón consultar disponibilidad — transversal | Consultar disponibilidad |

### Finalización y estado del estudio

Study Engine → abrir estudio → Configuración General → Finalización y estado del estudio.

| Campo | Texto predeterminado |
| --- | --- |
| Título al completar el estudio | Gracias por participar |
| Mensaje al completar el estudio | Tus respuestas quedaron guardadas. |
| Título al terminar por una condición | Participación finalizada |
| Mensaje al terminar por una condición | Esta evaluación terminó según las reglas del estudio. |
| Título del estado retirado | Participación retirada |
| Mensaje del estado retirado | No hay evaluaciones disponibles. |
| Título de estudio finalizado | Estudio finalizado |
| Mensaje de estudio finalizado | Este estudio ha finalizado. |
| Botón nueva participación, cuando está permitida | Iniciar una nueva participación |

### Errores de acceso y operación

Study Engine → abrir estudio → Configuración General → Errores de acceso y operación.

| Campo | Texto predeterminado |
| --- | --- |
| Conflicto entre pestañas | La evaluación cambió en otra pestaña. Revisá el estado actualizado. |
| Acceso no autorizado | No se puede acceder a esta evaluación. |
| Límite temporal de solicitudes | Esperá un momento antes de volver a intentar. |
| Cambio de email sin confirmar | El email es diferente al introducido anteriormente. Confirmá que querés actualizarlo. |
| Respuesta ya registrada desde esta IP | Ya se registró una respuesta desde esta IP para este estudio. |
| Email de otra participación | Ese email ya pertenece a otra participación. No se mezclaron tus respuestas; revisá la dirección o usá otra. |
| Campos o respuestas inválidos | Revisá las respuestas y los campos obligatorios antes de continuar. |
| Error de operación | No pudimos guardar o cargar la evaluación. Intentá nuevamente. |
| Título de evaluación no disponible | Evaluación no disponible |
| Reintento posterior | Intentá nuevamente más tarde. |
| Error al cargar contenido publicado | No pudimos cargar el contenido publicado. |
| Título de error del recorrido | No pudimos cargar la evaluación |
| Título de la página y del marco de evaluación | Evaluación |

### Estado de operaciones del estudio

Study Engine → abrir estudio → Configuración General → Estado de operaciones del estudio.

| Campo | Texto predeterminado |
| --- | --- |
| Procesando una operación | Guardando… |
| Operación confirmada | Avance guardado. |
| Operación no confirmada | No se confirmó el guardado. Intentá nuevamente con Guardar avance o recargá el estado. |
| Botón guardar avance del formulario del estudio | Guardar avance |
| Botón anterior del formulario del estudio | Anterior |
| Botón siguiente del formulario del estudio | Siguiente |
| Botón enviar del formulario del estudio | Enviar respuestas |
| Progreso del formulario del estudio — [ACTUAL] y [TOTAL] | Página [ACTUAL] de [TOTAL] |

## Variables y casos condicionados

- `[PERTENENCIA]`: toma el texto del checkbox de pertenencia configurado en ese mismo estudio.
- `[EMAIL]`: muestra el email al que corresponde la confirmación pendiente.
- `[ACTUAL]` y `[TOTAL]`: valores calculados del progreso del formulario del estudio.
- La próxima disponibilidad conserva su fecha/hora calculada; se edita el texto que la precede.
- Los campos de validación por enlace después de la primera evaluación sólo se usan si ese contrato exige dicho enlace. No activan ese flujo ni agregan un reenvío. El flujo actual de revisión directa sigue sin exigir un enlace adicional.
- Los botones de consulta, nueva participación y del formulario alternativo sólo aparecen cuando el recorrido ya los permite. Estos campos no agregan botones.
- El mensaje de estudio finalizado es distinto de la terminación por reglas y del agradecimiento de una evaluación.

## Errores sin un estudio identificable

EIPSI Forms → Configuración → desplegar «Mensajes públicos sin estudio identificable».

Allí se encuentran los mismos 13 campos de «Errores de acceso y operación», con el botón «Guardar mensajes de respaldo». Se usan únicamente si no puede cargarse la definición de ningún estudio; no sustituyen los textos configurados dentro de un estudio conocido. El formulario requiere permisos de configuración y nonce.

## Cambios en el recorrido simulado

Las pantallas HTML compartidas y los avisos de validación usan los campos del estudio. Se quitó el diálogo extra «¿Iniciar una respuesta independiente?» de la repetición simulada: conserva el botón de la evaluación y su política de repetición. Los mensajes técnicos de reloj, seed, trazas y controles de simulación pertenecen a las herramientas del investigador, no al contenido del participante.

## Comprobación humana pendiente

1. Personalizar un campo de cada sección con un texto reconocible, guardar y volver a abrir.
2. Publicar una versión de prueba y contrastar un ingreso nuevo con una participación anterior que conserve su versión.
3. Comprobar acceso desde email, dirección incorrecta, confirmación tras la primera evaluación y cambio de correo con Cancelar/Actualizar.
4. Comprobar espera, próxima disponibilidad, fin del recorrido y estudio finalizado.
5. Comprobar modal de abandono y su confirmación, sin cambiar políticas por personalizar los textos.
6. Usar un estudio descartable para las acciones que cambian estado o eliminan datos. No se realizó este recorrido humano como parte de las pruebas automatizadas.

## Verificación automatizada

- 625 pruebas JavaScript de la suite completa, sin fallos en la comprobación final; build y comprobación de artefactos aprobados (`.cache/ci/20261016-093142-e9d3b205` contiene esos resultados; su prueba HTTP posterior usaba un fixture que luego se corrigió).
- 451 pruebas PHP relacionadas aprobadas entre las corridas finales: transversal/longitudinal (`.cache/ci/20261016-093351-1ba8a3b9`), cuestionario/HTTP, mensajes, autoría y templates (`.cache/ci/20261016-093617-dc6e057d`), y experiencia/lifecycle (`.cache/ci/20261016-093707-826c679c`). Cada suite contabilizada terminó con cero fallos en su última ejecución. RCT estricto aprobado.
- Se verificó por HTTP local la personalización de errores y del estudio finalizado; se comprobó que editar un draft posterior no reescriba la configuración publicada.
- Una corrida anterior del test existente de navegación intraevaluación falló por disponibilidad temporal; las posteriores pasaron sin cambiar su implementación ni su test. Las expectativas antiguas del dummy sobre el texto de enlaces vencidos se actualizaron al catálogo compartido; se conservaron las comprobaciones de validación y acceso.
- Los fixtures fueron contenedores descartables. No se ejecutó un recorrido humano ni se modificaron los estudios del WordPress local del usuario. Sin commit.


## Visibilidad según validación — ajuste del 16/10/2026

Los grupos de mensajes de autenticación están dentro de «Validación del email», junto al selector y al checkbox de confirmación; ya no quedan dentro de «General».

| Configuración | Campos visibles |
| --- | --- |
| Antes de la primera evaluación | Avisos de envío de acceso/validación inicial. Se ocultan los grupos de revisión y cambio de correo y el aviso de próximos emails sin validar. |
| Desde la segunda oportunidad | Avisos de envío de acceso/validación inicial y próximos avisos sin validar. Revisión y cambio de correo sólo si está marcado «Confirmar email después de la primera evaluación». |
| No exigir validación | Se ocultan los dos avisos de envío inicial obligatorio; se conserva el aviso de próximos emails sin validar. Revisión y cambio de correo sólo si está marcado el checkbox. |

Recuperación mediante validación de un email registrado, validación exitosa y enlaces de evaluación vencida siguen disponibles en los tres modos: también aplican a enlaces de evaluaciones futuras. Los textos de confirmación que requieren un enlace adicional no aparecen en el contrato actual INITIAL_SCREEN_V2. Ocultar un campo conserva su contenido.

Verificación: 79 pruebas JavaScript seleccionadas sin fallos (incluidas las seis combinaciones de modo/checkbox, ubicación y conservación del texto), build y artefactos aprobados. No se modificó el comportamiento del participante. Sin commit.

## Acceso directo y errores — ajuste del 16/10/2026

Se eliminó la pantalla «Confirmar acceso»: abrir el enlace del email realiza el acceso automáticamente, sin botón intermedio. Se conserva un intercambio HTTP transparente para retirar la credencial de la URL antes de mostrar el contenido. Los campos de título, explicación y botón de esa pantalla ya no aparecen en el editor.

Se eliminó el enlace «Volver al inicio y solicitar un nuevo enlace» y su campo de edición. Ante un error de acceso a un estudio longitudinal conocido, se redirige automáticamente a una URL limpia de Pantalla Inicial y se muestra arriba el aviso rojo «Evaluación no disponible»; no hace falta refrescar. Si no existe o no puede identificarse el estudio, no hay una pantalla inicial individual que recuperar.

El vencimiento del período de una evaluación y el vencimiento de su credencial son cosas diferentes. Una credencial aún vigente puede validar el email aunque la evaluación haya terminado. Las credenciales inválidas, vencidas, revocadas o reutilizadas sin su sesión autorizada no dan acceso. El estudio finalizado conserva su pantalla propia. Los errores de carga utilizan el título genérico con un mensaje distinto.

Verificación del acceso directo: 103 pruebas JavaScript seleccionadas y 196 PHP relacionadas aprobadas (174 longitudinales y 22 de acceso/publicación/mensajes, en corridas separadas), build, artefactos y RCT estricto aprobados. Se comprobaron el ingreso automático sin formulario intermedio, el rechazo de credenciales inválidas/reutilizadas sin sesión, el vencimiento de la evaluación independiente del enlace, la ausencia del enlace de regreso y la URL de recuperación preparada para refrescar. Prueba humana pendiente; sin commit.


## Pantalla Inicial con aviso de indisponibilidad — comportamiento vigente

Ante un acceso no disponible a un estudio longitudinal existente, el navegador va automáticamente a Pantalla Inicial. Encima del título, del contenido y del botón de abandono aparece un aviso rojo con fondo rojo tenue: «Evaluación no disponible». Las peticiones del formulario con JavaScript presentan esa misma pantalla y aviso. No se muestra una pantalla de error separada ni un enlace de regreso.

El aviso usa el texto ya editable en Study Engine → abrir estudio → Configuración General → Errores de acceso y operación → «Título de evaluación no disponible». Al continuar normalmente se limpia el aviso de la URL. El estudio finalizado conserva su cierre, y un estudio inexistente no tiene Pantalla Inicial propia.

Verificación: 103 pruebas JavaScript y 196 PHP seleccionadas sin fallos, build, artefactos y RCT estricto aprobados en `.cache/ci/20261016-103122-bd96cbe2`. Incluye redirección HTTP limpia, aviso y campo de email presentes y conservación de las restricciones de acceso. Sin commit. Prueba humana pendiente.

## Avisos independientes sobre Pantalla Inicial — 16 de octubre

La Pantalla Inicial longitudinal contiene ahora los avisos del estudio, encima de su contenido configurado. La espera, finalización, error de acceso, validación del email y revisión de dirección ya no usan una página de estado separada. La evaluación conserva su recorrido y su cierre propios. Un estudio inexistente devuelve 404 sin contenido del plugin.

Los avisos son piezas independientes: «Email validado.», «El período de esta evaluación terminó.», «Evaluación no disponible.», los avisos de próximas evaluaciones y la próxima fecha pueden coexistir. El mismo aviso no se duplica. Validación usa verde; indisponibilidad/vencimiento, rojo; información y fecha, azul. El párrafo «Podés volver a consultar la disponibilidad» deja de mostrarse. La fecha respeta la zona del participante y la opción Mostrar próxima disponibilidad.

Edición: Configuración General → Espera de evaluaciones contiene el aviso de evaluación no disponible, período vencido y etiqueta de próxima disponibilidad. Pantalla Inicial → campo de autenticación → Validación del email contiene «Email validado» y los avisos de envío. Los textos de cierre siguen en Configuración General. Los textos configurados del estudio se conservan; no se reescriben versiones publicadas. La simulación usa el mismo renderizador para estas vistas.

Prueba humana: abrir un enlace aún válido de una evaluación cuyo período terminó, sin otra evaluación abierta. Deben verse los avisos separados arriba de la Pantalla Inicial, con su autenticación y abandono habituales. Si ya existe otra evaluación disponible, se abre esa evaluación. Refrescar conserva la navegación normal. Revisar también estudio finalizado: misma Pantalla Inicial, aviso de cierre y controles deshabilitados.

Verificación de este ajuste: 628 pruebas JavaScript y 451 PHP relacionadas aprobadas, en corridas complementarias; build y comprobación de artefactos aprobados; RCT estricto 1/1. Evidencia: `.cache/ci/20261016-104756-07af81de` (JavaScript/build, runtime longitudinal y transversal) y `.cache/ci/20261016-104927-1152389d` (resto de PHP y RCT). La expectativa del caso simulado sin más oportunidades se corrigió para comprobar cierre, no una fecha futura inexistente. Prueba humana pendiente. Sin commit.

### Ajuste: validación como evento y avisos fuera de la tarjeta

«Email validado» se muestra únicamente al pasar de email no validado a validado al consumir el enlace. No se vuelve a mostrar al refrescar, reutilizar un enlace con sesión autorizada ni abrir otro enlace de una dirección ya validada. La decisión se toma bajo el bloqueo de identidad del participante; la simulación aplica la misma regla. Los avisos están sobre la tarjeta de Pantalla Inicial, fuera de su fondo configurado, con texto pequeño, señalador izquierdo y fondo tenue del color correspondiente.

Verificación del ajuste: 628 pruebas JS y 451 PHP aprobadas; build, artefactos y RCT estricto verdes (`.cache/ci/20261016-121232-4dd8999c`). Se comprueban primera validación, refresco, reutilización del enlace y otro enlace para una dirección ya validada. Estilos comprobados en real y simulación. Prueba humana pendiente; sin commit.

La revisión posterior de edición de textos, diferencias de estilos y email incorrecto en abandono está en [Revisión de Pantalla Inicial y abandono](revision-textos-pantalla-inicial-abandono.md). Incluye ubicaciones de edición, defectos corregidos, textos transversales pendientes y recorrido humano.


## Identificación por email y reingreso validado — comportamiento vigente

La portada del estudio no recupera identidad, avisos o disponibilidad desde la cookie. Al introducir un email ya validado, se consulta directamente su estado: evaluación disponible o mensaje «No hay evaluaciones disponibles» con próxima fecha cuando corresponda. No se vuelve a pedir validación ni a mostrar el aviso de validación por ese reingreso. El abandono resuelve el email del estudio, sin compararlo con una sesión del navegador, conforme a la confirmación expresa del usuario.

El detalle de impactos y verificación está en [Identificación del estudio por email](identidad-email-impacto-y-correccion.md). Este comportamiento sustituye las referencias anteriores de este informe a coincidencia del email con una sesión para abandonar o a exigir un nuevo enlace para una dirección ya validada.


### Retiro del formulario genérico de Study Engine — 2026-10-16

Las evaluaciones públicas se presentan exclusivamente mediante el runtime de Assessment Editor. Se eliminó el renderer alternativo de preguntas, contenido, páginas y controles del formulario genérico; no se convierte ni se recupera ese formulario como fallback. Una oportunidad sin asignación Native válida falla sin mostrar preguntas genéricas.

Se retiraron del catálogo y del transporte `saving`, `saved`, `save_failed`, `save_button`, `back_button`, `next_button`, `submit_button` y `page_progress`. Configuración general ya no contiene «Estado de operaciones del estudio». Las configuraciones correspondientes de Assessment Editor siguen siendo las que usa la evaluación.

El JavaScript del estudio conserva autenticación por email y abandono. No guarda respuestas automáticamente ni navega páginas de cuestionarios. La ruta pública del estudio rechaza los envíos del antiguo formulario; las respuestas se envían mediante el endpoint Native.

Verificación humana: abrir un longitudinal y un transversal, responder mediante Assessment Editor y comprobar que no se agregan controles ni mensajes de guardado del estudio. Verificar por separado el ingreso por email, la confirmación/corrección del email y el abandono.

Las pruebas HTTP que dependían de inputs y páginas del renderer eliminado se reemplazaron por verificaciones de asignación Native y rechazo de ese transporte. Los contratos de admisión, persistencia, exportación y concurrencia del motor siguen probándose por separado.

### Disponibilidad del estudio y acceso vencido

- Configuración general → Disponibilidad del estudio: «Mensaje de estudio pausado» y «Mensaje de estudio finalizado», para transversal y longitudinal. Un estudio pausado o finalizado muestra su mensaje sin abrir evaluaciones ni admitir participaciones.
- Pantalla Inicial → Propiedades generales → Errores de acceso y operación: «Campos o respuestas inválidos», para los datos del ingreso/operaciones longitudinales. Ya no se usa para indicar una pausa del estudio.
- Retirados de la edición y del runtime: «Acceso no autorizado», «Reintento posterior» y «Título de error del recorrido».
- Al abrir el enlace transversal con una credencial anónima vencida o inválida, se inicia una participación nueva sin aviso de autorización; no se recuperan respuestas de la participación anterior. Se mantienen la restricción por IP y las comprobaciones de propiedad al enviar respuestas.

### Formato de fecha del estudio y limpieza de pantallas

- Study Engine → Configuración general → Formato de fecha: AAAA-MM-DD, DD/MM/AAAA o MM/DD/AAAA. Se aplica a próxima disponibilidad, fechas de los emails y preguntas de fecha del participante y de la simulación. Horario y zona se conservan; almacenamiento y respuestas continúan en ISO. El formato pertenece a la versión publicada del estudio.
- Assessment Editor → pregunta Fecha: eliminado el selector individual de formato. Su calendario y mensajes siguen siendo editables.
- Las preguntas obligatorias muestran el asterisco al final del título, dentro de su texto enriquecido.
- El documento vacío de fallo de carga del Assessment usa «EIPSI Forms» exclusivamente como título de pestaña.
- Eliminadas las variantes antiguas de ingreso/confirmación/abandono que renderizaban formularios alternativos y explicaciones fijas sobre retiro y limpieza; se utiliza la Pantalla Inicial común. Eliminado el enlace residual de finalización que no tenía productor activo.

### Avisos excepcionales y presentación de estados

- Los errores excepcionales se presentan con el mismo aviso pequeño, fondo tenue y línea izquierda que los mensajes de disponibilidad; se retiró su encabezado de página independiente.
- En solicitudes 429/503 se mantiene el contenido ya visible y los valores ingresados. En una visita directa se muestra el contenido autorizado recuperable; si no se puede cargar, queda el aviso sin un título adicional.
- Pausado y finalizado, en transversal y longitudinal, muestran únicamente su mensaje editable en gris y tamaño pequeño, centrado sobre una página blanca. No se cambia la URL ni se muestran campos, evaluaciones o botones en esos estados.
