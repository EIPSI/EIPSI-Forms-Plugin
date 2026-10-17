# Pantalla Inicial longitudinal

Contrato opt-in versionado: `protocol_metadata.longitudinal.initial_screen`, `INITIAL_SCREEN_V2` (compatibilidad con `INITIAL_SCREEN_V1`). Guardar o clonar un draft longitudinal incorpora la configuración; publicar fija el contenido y las políticas para nuevos participantes. Las versiones publicadas anteriores no se reescriben.

## Autoría

Pestaña después de Condiciones Lógicas, sólo en Longitudinal. Visual fijo: título opcional, descripción opcional, autenticación email, footer opcional y abandono arriba/abajo, izquierda/derecha. Propiedades generales a la izquierda; propiedades del elemento seleccionado a la derecha. Se reutiliza el editor enriquecido del Assessment Editor, incluyendo HTML, enlaces, medios y formato. WordPress sanitiza el contenido al guardar y al renderizar.

Las reglas de Notificaciones se configuran dentro de Autenticación. Los tres templates se seleccionan dentro de Autenticación; remitente, asunto y cuerpo se editan exclusivamente en la biblioteca global Templates de Emails (ver `email-templates.md`). Templates editables: validación, evaluación disponible y recordatorio, con asunto y cuerpo editables. Los campos vacíos de un template seleccionado no se rellenan automáticamente. Variables permitidas: `{{study_name}}`, `{{assessment_name}}`, `{{available_time}}`, `{{due_time}}`, `{{direct_link}}`, `{{withdrawal_link}}`. Los enlaces de acceso y abandono sólo aparecen si están incluidos en el template. No se ejecutan PHP ni shortcodes.

## Autenticación

Email válido, normalizado a minúsculas. Una dirección existente nunca basta para recuperar una participación sin sesión válida. Los enlaces de acceso, disponibilidad y recordatorio, consumidos mediante confirmación explícita, verifican también el email en el nuevo contrato.

Política de la página principal:

- `IMMEDIATE`: verificar antes de comenzar la primera evaluación.
- `AFTER_FIRST`: si la primera oportunidad ya está disponible al inscribirse, puede completarla sin verificar y después revisa/corrige el email y solicita validación. Si todavía no está disponible, envía validación desde el inicio y conserva ese gate cuando llegue el horario. Se cuentan oportunidades completadas, no páginas, visitas ni días.
- `NEVER`: no exigir verificación para responder desde una sesión válida de la página principal. Recuperar una sesión perdida sigue requiriendo un enlace al email.

El gate se comprueba en el adapter longitudinal y en la autorización del endpoint de evaluaciones nativas. Las decisiones no dependen de ocultar botones en JavaScript. Una evaluación completada sigue siendo legible desde su sesión autorizada; el gate se aplica a la siguiente oportunidad.

Con validación obligatoria y email todavía no verificado, cada oportunidad tiene su intención de verificación idempotente, asociada a su ventana y revisión de calendario. El worker revalida disponibilidad, cancelación, privacidad y verificación antes del envío. Los avisos científicos normales conservan su configuración independiente.

Checkbox opcional de pertenencia:

- Marcado y email desconocido: mensaje de dirección no encontrada; no inscribe.
- Desmarcado y email conocido sin sesión: informa registro existente y solicita recuperación; no duplica.
- Sesión válida de ese participante: acceso directo al estado actual.

## Abandono

El botón pertenece a la Pantalla Inicial y también aparece en los footers de email. Se muestra incluso antes de inscribirse. Abrir el diálogo no modifica participación ni respuestas. Sin una participación, se informa que no hay nada que abandonar; no se crea una inscripción para procesar el abandono.

Sin acceso acreditado, se envía un enlace con propósito `WITHDRAWAL_OPTIONS`. Sólo su consumo permite abrir la confirmación autenticada. Las dos opciones conservan el contrato de privacidad existente:

- `RETAIN_FOR_ANALYSIS`: retiro, cancelación de avisos y revocación de acceso científico; conserva datos para análisis.
- `DO_NOT_USE`: exclusión de uso inmediata y solicitud de limpieza local, con confirmación adicional. No promete borrar backups, emails ya entregados, exports descargados o sistemas externos.

Los textos y el diseño son personalizables; los modos, la confirmación adicional y el alcance real de limpieza permanecen explícitos.

## Validación

Suites JavaScript/DOM y SQL/HTTP en harness descartable, con captura de correo. No se envían correos reales ni se modifican estudios del WordPress de trabajo para probar. La revisión humana del visual, modales y editor enriquecido en Firefox local queda separada de las pruebas automatizadas.

## Confirmación después de la primera evaluación

En V2, `AFTER_FIRST` y `NEVER` siempre muestran la revisión/corrección del email después de la primera oportunidad completada, incluso si es la única y el estudio ya terminó científicamente. `IMMEDIATE` puede activar adicionalmente esa revisión con `authentication.confirm_after_first` (default false).

- `AFTER_FIRST` (y revisión adicional de `IMMEDIATE`): la dirección nueva queda pendiente hasta consumir su enlace. Este mismo enlace verifica para las próximas oportunidades. La pantalla informa la solicitud de envío.
- `NEVER`: confirmar en pantalla aplica la corrección desde la sesión válida, sin enviar validación independiente ni afirmar que la nueva dirección fue verificada. Informa que recibirá próximos avisos. Un enlace futuro de evaluación realiza la verificación automáticamente.
- V1 conserva su opción anterior: si `confirm_after_first` está activo, siempre requiere un enlace, también en `NEVER`.

Se conserva el mismo participante, ejecución, respuesta inicial y calendario. Una dirección que ya pertenece a otra participación se rechaza sin unir historiales. Corregir invalida enlaces de la dirección anterior. En la corrección con prueba, las sesiones anteriores se revocan al consumir el enlace. En la corrección sin prueba, se conserva sólo la sesión que realizó la revisión, con acceso provisional; las otras sesiones se revocan y la dirección queda `UNVERIFIED`.

El estado y la idempotencia usan namespaces privados en el ledger transaccional de admisión (`enrollment_receipts`), ligados a participante y ejecución, sin migración ni campos en el protocolo científico. El estado `CONFIRMED` de este ledger representa la revisión finalizada; la prueba de propiedad se expresa exclusivamente en `participants.verification_state`. La limpieza de privacidad incluye ambos. Los envíos ajenos a la confirmación se difieren mientras falta la revisión o hay una corrección pendiente; el abandono permanece disponible.

## Matriz de admisión V2

| Política | Evaluación disponible al inscribirse | Todavía no disponible |
| --- | --- | --- |
| Inmediata | Envía un único acceso a la evaluación que también valida el email; muestra el mensaje de primera evaluación por email. | Envía validación independiente y explica los futuros avisos. |
| Desde la segunda | Abre la primera directamente; al completar revisa el email y solicita el enlace de validación. | Valida desde el inicio, conservando el gate al habilitarse la primera. |
| Nunca | Abre directamente; al completar revisa el email sin enlace obligatorio. | Espera sin exigir validación; los futuros avisos pueden llegar a la dirección todavía no verificada. |

La política histórica `verification_policy=VERIFIED_ONLY` no bloquea los avisos de V2: los modos de Pantalla Inicial gobiernan el gate y el enlace de evaluación acredita la dirección. La configuración de avisos del estudio se mantiene; el participante no puede desactivarlos ni reactivarlos. Los mensajes de validación se muestran también tras el intercambio HTTP, con URL limpia. Los scanners GET no consumen credenciales; se mantiene la confirmación explícita de acceso.

Los gatillos explícitos `PARTICIPANT_VERIFIED` conservan su significado: sólo se habilitan al acreditar la dirección mediante un enlace. No adelantan una validación en `AFTER_FIRST` si ya hay primera evaluación disponible, ni envían validación inicial en `NEVER`. Si todas las evaluaciones dependen de ese gatillo, `NEVER` permanece en espera hasta una prueba real (por ejemplo, un enlace solicitado para recuperar el acceso); revisar la dirección en pantalla no inventa ese evento. Para solicitar la validación automáticamente antes de ese primer acceso se usan `IMMEDIATE` o `AFTER_FIRST`.

Una evaluación vencida no invalida por sí sola la prueba de email: si su enlace sigue vigente y no está revocado, valida la dirección y muestra el estado actual, sin reabrir ni extender la ventana. Un token de seguridad expirado, revocado, de otro estudio o invalidado por una corrección no valida. La pantalla de acceso rechazado ofrece volver al inicio para solicitar un enlace nuevo. Se mantienen los controles de privacidad y acceso.

El enlace “Pantalla Inicial” permite regresar desde el estudio sin cerrar la sesión y conserva la versión fijada del participante.

## Resultado automatizado — 2026-10-12

`./tests/ci/run.sh full`: PASS, 2.720 tests (529 JavaScript, 428 PHP puro y 1.763 WordPress), cero fallos. Gate estricto RCT 1/1; build, clean/upgrade, smoke y lifecycle verdes. Evidencia: `.cache/ci/20261012-224708-1e447b38`. La revisión humana en Firefox local continúa pendiente. Sin commit, push ni envíos SMTP reales.

## Validación de V2 — 2026-10-12

`./tests/ci/run.sh full`: PASS, 2.733 pruebas (529 JavaScript, 428 PHP puro y 1.776 WordPress), cero fallos. RCT estricto 1/1; build, clean/upgrade, smoke y lifecycle verdes. Evidencia: `.cache/ci/20261012-233047-6e33e04c`.

Trece casos nuevos cubren la matriz de seis escenarios, expiración científica frente a expiración de credenciales, recuperación segura, corrección sin prueba, idempotencia, mensajes HTTP y gatillos explícitos de verificación. Los recorridos piloto, operaciones y autoría nativa realizan la revisión del email antes de avanzar. Los controles de autorización y verificación permanecen activos durante reintentos; no se repite el guardado científico.

Revisión humana en Firefox local pendiente; sin commit, push ni correo real. Para probar V2, guardar, publicar y activar una nueva versión del Study; las participaciones fijadas en versiones publicadas anteriores conservan su contrato.

## Ajustes de edición — 2026-10-13

La etiqueta de autenticación admite `authentication.label_html` y `label_visible`, ambos opcionales. Los protocolos anteriores conservan una etiqueta visible. Ocultarla conserva su contenido y el input mantiene un nombre accesible; las políticas de validación, correo, sesiones y admisión no cambian. HTML sanitizado al guardar y renderizar. La etiqueta se edita en el visual con el mismo editor enriquecido.

El editor de abandono aparece debajo de Pantalla Inicial, con visual propio para título, cuerpo, opciones y confirmación del modal; ambos visuales comparten un único par de paneles laterales. Seleccionar el botón en Pantalla Inicial muestra su texto, ubicación y diseño en las propiedades específicas de esa pantalla. Al trabajar en el modal, el panel general muestra exclusivamente el contrato de abandono, y el específico corresponde al campo seleccionado; no contiene propiedades del botón de Pantalla Inicial. Al volver a Pantalla Inicial recupera sus propiedades. La pantalla del participante conserva la ubicación arriba/abajo configurada para el botón y el proceso autenticado de abandono existente.

Los paneles de propiedades de Pantalla Inicial, Longitudinal y Transversal usan grupos plegables con tipografía/separadores de Assessment Editor. Los lápices de Pantalla Inicial ocupan una columna propia junto al contenido, sin superposición. Checkboxes de configuración se alinean con su texto; el CSS excluye preguntas, canvas y previews de evaluaciones.

Validación de estos ajustes: `./tests/ci/run.sh full` PASS, 2.753 tests (539 JavaScript, 428 PHP puro, 1.786 WordPress), cero fallos; RCT estricto 1/1, build/clean/upgrade/smoke/lifecycle verdes. Evidencia: `.cache/ci/20261013-103211-feaf1bd7`. La modificación del CSS histórico de administración queda acotada por hashes antes/después en `tests/study-engine/builder/presentation-assets.json`. Inspección visual humana pendiente; sin commit ni correos reales.

### Opciones de abandono y confirmación escrita

Los nuevos drafts y las ediciones de Pantalla Inicial incorporan `withdrawal.typed_confirmation`. Las publicaciones anteriores sin este campo conservan su flujo. El título del modal admite HTML enriquecido sanitizado; cada opción es un botón con texto y colores de fondo/letra propios.

Elegir una opción abre un segundo diálogo: muestra la decisión y exige escribir exactamente el texto del botón en mayúsculas. Cancelar no envía comandos ni modifica la participación. La acción final también valida en servidor la frase tomada de la versión fijada a esa participación. La exclusión de datos conserva su confirmación adicional. Sin autorización, el flujo solicita primero el enlace por email; no retira a nadie por conocer su dirección. Esta presentación con diálogos requiere JavaScript.

Cada panel de propiedades generales incluye su propia previsualización: Pantalla Inicial o modal de abandono. Se usa una copia de la configuración actual del draft, con controles interactivos y confirmación escrita simulada. No guarda cambios, crea participantes, envía emails ni ejecuta abandono real.

### Diseño de botones

Pantalla Inicial y abandono comparten opciones para los botones Continuar, Reenviar enlace, Abandono, conservar/eliminar datos, Confirmar, Cancelar y Volver: color de letra/fondo/borde, grosor, redondeo y tamaño de letra. Hover permite elegir colores de letra/fondo/borde; sin ajuste conserva los colores normales. Se guardan overrides opcionales en `button_styles`, preservando los valores históricos como fallback. Los estilos se aplican al editor, previsualización y render del participante. Las dos opciones de abandono pueden disponerse en columna o en paralelo; con poco ancho se acomodan en más de una fila.

El modal de confirmación tiene un tercer visual debajo del modal de abandono y su propio contexto de propiedades: título, mensaje e instrucciones enriquecidos, etiqueta/placeholder del campo, texto y diseño de Cancelar/Confirmar, colores/ancho y disposición. `[opcion]` inserta el nombre del botón elegido; la frase en mayúsculas se genera siempre y conserva la validación del servidor. La interfaz de previsualización usa cabecera/footer azulados fuera del recuadro blanco del participante; los mensajes de simulación quedan en ese footer. Hover sin override ahora cambia el fondo por defecto; los botones editables del canvas permiten pasar el mouse.

Abandono agrupa los dos modales dentro del mismo marco del editor. El primero incluye Volver, con texto/diseño editable; el segundo usa un único cuerpo enriquecido. `[OPCION]` inserta el texto de la opción y `[CONFIRMACION]` su versión en mayúsculas, manteniendo negrita/cursiva del contenedor. La validación escrita sigue siendo obligatoria. «Campo de confirmación» es un nombre del editor y un nombre accesible del input; no se muestra como etiqueta al participante. Las publicaciones con los antiguos message/instruction conservan un fallback de contenido.

Los tres visuales comparten bordes, radios, espaciado y tipografía de Assessment Editor. Los colores personalizados de cada pantalla/modal se conservan dentro del canvas. El listado de evaluaciones oculta sus columnas de Elementos y Propiedades vacías y las recupera al editar una evaluación.

En edición, los tres canvas ocupan el mismo ancho disponible. Los límites de ancho configurados se aplican únicamente a las previsualizaciones y al participante.

Pantalla Inicial y ambos modales comparten un único ancho máximo: el de Pantalla Inicial (720 px por defecto), limitado al viewport con márgenes de 20 px. La confirmación ya no tiene un selector de ancho independiente; los datos históricos de ese selector no alteran el ancho compartido. Los tres previews usan el mismo marco y espacio interior.

### Páginas públicas independientes

Las URLs `?eipsi_study=…` (incluidos aliases) y `?eipsi_native=…` se sirven como documentos propios del plugin. Pantalla Inicial, evaluación, espera/finalización, confirmación de enlaces y errores no llaman al tema, `wp_head`, `wp_footer` ni barra de administración; cargan sólo los assets necesarios del participante. Las respuestas JSON y la autorización conservan su contrato. No se modifica el tema del sitio ni las páginas administrativas.
