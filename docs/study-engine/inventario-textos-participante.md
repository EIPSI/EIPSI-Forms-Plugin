# Inventario de textos públicos de Study Engine

Revisión estática del 16/10/2026.
Estado posterior: los campos y ubicaciones implementados se documentan en [mensajes-editables-prueba-humana.md](mensajes-editables-prueba-humana.md). Este archivo conserva los hallazgos previos como inventario, no como lista vigente de pendientes.
 Alcance: contenido que ve el participante en el portal real transversal/longitudinal, incluidos mensajes que Study Engine inserta al terminar una evaluación. No incluye textos administrativos ni herramientas externas al recorrido dummy. No se modificó comportamiento ni se certificó prueba humana.

«Editable» significa que existe un control de autoría, no simplemente una clave en el protocolo. Los textos predeterminados de un campo editable no se cuentan como deuda. No se interpretan las excepciones internas del servidor como mensajes visibles: el transporte traduce muchas de ellas a un mensaje genérico.

## 1. Contenido ya editable

| Contenido | Dónde se edita |
| --- | --- |
| Título, descripción y footer de ingreso | Study → Pantalla Inicial → editor visual |
| Etiqueta de autenticación, texto enriquecido, visibilidad, placeholder y botón Continuar | Pantalla Inicial → Autenticación → Propiedades |
| Checkbox de pertenencia y su texto | Pantalla Inicial → Autenticación → Pertenencia al estudio |
| Texto del botón Abandonar estudio | Pantalla Inicial → propiedades del botón de abandono |
| Título y descripción del modal de abandono | Editor visual de Abandono del estudio |
| Nombres de las opciones conservar/eliminar y botón Volver | Modal de abandono → propiedades del elemento |
| Título y cuerpo de confirmación; variables [OPCION] y [CONFIRMACION] | Modal de confirmación → editor visual |
| Placeholder de confirmación y botones Cancelar/Confirmar | Modal de confirmación → propiedades |
| Mensaje después del abandono, incluidas sus variables | Mensaje después de Abandono → editor visual |
| Remitente nominal, asunto, cuerpo, enlaces y footer de los tres emails | Templates de Emails; selección/ajustes por estudio |
| Contenido y finalización de cada evaluación nativa | Assessment Editor: pertenece a la evaluación, no al mensaje del estudio |

Los valores de las variables (email, nombre de evaluación, fechas, nombre de opción y frase en mayúsculas) son datos generados. El texto que los rodea puede ser editable aunque el valor no sea un mensaje de autoría.

## 2. Textos fijos del flujo actual

### Acceso mediante enlace: página propia

Fuente: `includes/study-engine/longitudinal/CapabilityLanding.php` y `runtime/route.php`.

- Título visible y de documento: «Confirmar acceso».
- «Presioná Continuar para abrir el enlace de tu estudio.»
- Botón «Continuar». Este botón es independiente del configurable en Pantalla Inicial.

### Ingreso, pertenencia y validación: avisos dentro de otras pantallas

Fuente: `includes/study-engine/longitudinal/Runtime.php`.

- «No encontramos ese email en este estudio. Revisá la dirección o desmarcá «Ya participo» para inscribirte.» El fragmento «Ya participo» está fijo aunque se personalice el checkbox.
- «Ese email ya está registrado. Te enviamos un enlace para validar tu email; no creamos otra inscripción.»
- «Si corresponde, te enviamos un enlace para validar tu email.»
- «Te enviamos por email el acceso a tu primera evaluación. El enlace confirma tu dirección y abre la evaluación si sigue disponible.»
- «Te enviamos un enlace para validar tu email. Después de validarlo recibirás por email los avisos de las próximas evaluaciones.»
- «Tu email está validado. Te enviaremos por email los avisos de las próximas evaluaciones.»
- «Te enviaremos por email los avisos de las próximas evaluaciones. Al abrir uno de esos enlaces se validará tu dirección.»
- Enlace de evaluación vencida con otra disponible: «Email validado. El período de la evaluación de este enlace terminó. Ahora podés responder la siguiente evaluación disponible.»
- Enlace de evaluación vencida sin otra disponible: «La evaluación de este enlace ya venció. Tu email quedó validado; te enviaremos por email los avisos de las próximas evaluaciones.»

Son textos de la página web, no del email; editar Templates de Emails no los cambia. Que se construya un aviso tampoco garantiza que se dibuje: por ejemplo, un OPEN_ASSESSMENT retorna directamente el iframe antes de la inserción del status en EmailTemplate.

### Revisión del email después de la primera evaluación: pantalla propia/incrustada

Fuente: `includes/study-engine/longitudinal/EmailTemplate.php`. Assessment runtime reutiliza esta pantalla mediante `includes/assessment-editor/runtime/Owner.php`.

- «Confirmá tu email».
- «Tu primera evaluación quedó guardada. Volvé a escribir tu dirección. Si hubo un error de tipeo, podés corregirla sin perder tus respuestas.»
- Etiqueta «Email»: no hereda la etiqueta configurable de autenticación en esta pantalla.
- Botón «Confirmar dirección».
- Variante con enlace: «Enviar enlace de validación».
- Aviso pendiente: «Solicitamos el envío del email de validación a [email]. Abrilo para confirmar la dirección. También podés corregirla y solicitar otro enlace.»
- Aviso agregado a la finalización con validación pendiente: «Enviamos el enlace de validación. Abrilo para confirmar tu dirección y acceder a las próximas evaluaciones.»

Los últimos tres textos existen en ramas condicionadas a `pending/requires_link`. La revisión del flujo actual sin enlace adicional no debe confundirse con esas ramas.

### Cambio de correo: modal propio

Fuente: `src/frontend/study/interactions.js`, compartido por portal, evaluación nativa y simulación.

- Nombre accesible del diálogo: «Confirmar cambio de correo».
- «El email es diferente al introducido anteriormente. ¿Estás seguro de que querés actualizar el correo?»
- «Cancelar».
- «Actualizar correo».

### Espera y estados del estudio: páginas propias

Fuente: `includes/study-engine/content/AssessmentRenderer.php`.

- WAIT: «Todavía no hay evaluación disponible» / «Podés volver a consultar la disponibilidad.»
- Prefijo «Próxima disponibilidad:», seguido de fecha/hora y zona horaria.
- TERMINATED: «Participación finalizada» / «Esta evaluación terminó según las reglas del estudio.»
- WITHDRAWN: «Participación retirada» / «No hay evaluaciones disponibles.» Este estado genérico es distinto del mensaje de abandono configurable mostrado por el flujo de abandono.
- OPERATIONAL_ERROR: «No pudimos cargar la evaluación» / «Intentá nuevamente más tarde.»
- FINISHED: valores `completion_title` y `completion_message` del protocolo; defaults «Gracias por participar» / «Tus respuestas quedaron guardadas.». No encontré campos actuales de autoría para editar este cierre del estudio; no confundir con la finalización de una evaluación.
- Transversal en WAIT: botón «Consultar disponibilidad».
- Si `allow_restart` habilita reinicio del estudio: «Iniciar una nueva participación». Es del estudio, no el reinicio eliminado de Assessment Editor.

Fuente: `includes/study-engine/runtime/PublicStudyRepository.php`.

- Estudio FINALIZED: «Estudio finalizado» / «Este estudio ha finalizado.»

### Abandono: textos auxiliares fijos

Fuentes: `EmailTemplate.php` e `interactions.js`.

- Validación escrita: «Escribí exactamente el nombre indicado en mayúsculas.» Se asigna como validez nativa; el botón permanece deshabilitado mientras no coincida, por lo que no es un aviso ordinariamente dibujado.
- Nombre accesible «Campo de confirmación»: el atributo está fijo; existe una propiedad `input_label` en configuración pero este renderer no la usa.
- Sin JavaScript: «Active JavaScript para usar la confirmación escrita de abandono.»
- Los mensajes nativos de formato/obligatoriedad del email de abandono y autenticación dependen del navegador; no hay mensajes propios configurables para ellos.

### Errores públicos y recuperación

Fuente: `includes/study-engine/runtime/route.php`.

- Conflicto: «La evaluación cambió en otra pestaña. Revisá el estado actualizado.»
- Acceso: «No se puede acceder a esta evaluación.»
- Finalizado: «Este estudio ha finalizado.»
- Límite de solicitudes: «Esperá un momento antes de volver a intentar.»
- Cambio de email sin confirmar: «El email es diferente al introducido anteriormente. Confirmá que querés actualizarlo.»
- Única respuesta transversal: «Ya se registró una respuesta desde esta IP para este estudio.»
- Colisión de email: «Ese email ya pertenece a otra participación. No se mezclaron tus respuestas; revisá la dirección o usá otra.»
- Validación genérica: «Revisá las respuestas y los campos obligatorios antes de continuar.»
- Error de operación: «No pudimos guardar o cargar la evaluación. Intentá nuevamente.»
- Pantalla sin frame: «Evaluación no disponible» / «Intentá nuevamente más tarde.» o el error anterior que corresponda.
- Error al renderizar: «Evaluación no disponible» / «No pudimos cargar el contenido publicado.»
- Acceso longitudinal denegado sin frame: enlace «Volver al inicio y solicitar un nuevo enlace».
- Título de documento genérico «Evaluación»; título accesible del iframe «Evaluación».

Fuente: `src/frontend/study/runtime.js`, dentro de formularios con `role=status`.

- «Guardando…».
- «Avance guardado.»
- «No se confirmó el guardado. Intentá nuevamente con Guardar avance o recargá el estado.»

Estos tres no deben contarse como siempre visibles en el recorrido nativo: los formularios iniciales no generan ese status y la evaluación nativa tiene su propio runtime editable. Sí permanecen en el transporte compartido y en el renderer alternativo descrito abajo.

## 3. Ramas alternativas: no atribuirlas a los estudios actuales sin comprobar su contrato

### Evaluación sin vínculo nativo

`AssessmentRenderer::render` retorna el iframe cuando NativeBridge encuentra `native_version_id`. El Builder actual exige/puebla ese vínculo. Sólo si no existe entra al formulario alternativo que incluye:

- «Evaluación»; «Página [actual] de [total]».
- «Guardar avance», «Anterior», «Siguiente», «Enviar respuestas».
- Opciones booleanas «Sí» / «No» y etiqueta accesible «obligatorio».
- Validaciones nativas del navegador.

No son los controles del Assessment Editor actual. No se propone conservarlos ni construir compatibilidad.

### Configuración inicial ausente o contrato anterior

`EmailTemplate` conserva ramas sin `initial_screen`, sin `typed_confirmation`, o Runtime sin INITIAL_SCREEN_V2. Incluyen:

- «Participar en el estudio», «Email», «Continuar» como ingreso fijo.
- «Confirmá tu email con el enlace recibido para comenzar esta evaluación.»
- «Opciones de retiro».
- «Esta página no retiró tu participación. Confirmá una opción para abandonar el estudio.»
- «Elegí qué debe ocurrir con tus datos».
- «Abandonar y permitir conservar los datos aportados para análisis».
- «Abandonar y solicitar que mis datos no se utilicen».
- «La segunda opción excluye tus datos inmediatamente y solicita su limpieza local. No podemos borrar backups, emails entregados, archivos ya descargados ni sistemas externos.»
- «Confirmar retiro».
- «Tu solicitud fue registrada.» y estados de limpieza: «Los datos previos se conservan para análisis.»; «La exclusión de uso ya está vigente. La limpieza local está pendiente.»; «La limpieza local cubierta se completó. No incluye copias externas, backups, correos entregados ni exports descargados.»; «La limpieza local se realizó con categorías pendientes o no cubiertas.»; «La exclusión de uso sigue vigente. La limpieza local requiere reintento.»; «Consultá el estado de la solicitud.»

Con pantalla inicial configurada y retiro realizado, el renderer devuelve antes el mensaje de abandono configurable. Estas ramas son inventario residual, no evidencia de que el usuario actual vea los mensajes anteriores. Su eliminación sería una decisión posterior, no trabajo de compatibilidad.

## 4. Ubicación coherente para completar la edición

Sin crear una página nueva:

- Pantalla Inicial → Autenticación: avisos de ingreso/pertenencia/validación; pantalla de confirmación del enlace; revisión/cambio de email y su modal; mensajes propios de email obligatorio/inválido si se desea sustituir los del navegador.
- Abandono del estudio → Modal de confirmación: mensaje de frase incorrecta, nombre accesible del campo y aviso sin JavaScript.
- Dentro de cada estudio: mensajes de espera/próxima disponibilidad; finalización del recorrido, terminación por condiciones y estudio finalizado; errores de acceso, límites, conflictos, única respuesta y carga. Los estados sin un estudio identificable necesitan un valor global de respaldo; la URL inválida no permite recuperar una configuración individual.
- Templates de Emails: conservar allí el texto de emails. No colocar en templates los avisos web.
- Assessment Editor: conservar allí los mensajes propios del cuestionario. No duplicarlos en el estudio.

Esta es una propuesta de ubicación, no una implementación ni autorización asumida para cambiar textos o recorridos.

## 5. Simulación: diferencias detectadas en los textos

La simulación reutiliza parte de EmailTemplate y los controles de evaluación, pero no todos los avisos proceden del runtime público. `builder/Experience.php` construye sus propios textos de email enviado/validado, vencimiento y corrección. `experience.js` coloca `result.notice` fuera de la superficie, debajo de las pestañas: no son mensajes confirmados del portal real.

Entre esas variantes fijas están «Te enviamos por email el enlace para validar tu dirección e ingresar a la primera evaluación.», «Te enviamos un email para validar tu dirección.», «Email validado. Recibirás por email avisos de las próximas evaluaciones.», «El período de esa evaluación terminó; el enlace igualmente validó tu email.», «Recibirás por email avisos de las próximas evaluaciones.», «El enlace de acceso venció. Solicitá uno nuevo desde la pantalla inicial.», y los avisos de sesión «La sesión anterior quedó guardada como parcial. Empezá desde el inicio.» / «La sesión venció. Empezá desde el inicio.». Son mensajes del motor dummy, no campos de autoría.

Hay además un `window.confirm('¿Iniciar una respuesta independiente?')` al repetir una evaluación cuando `completion_can_repeat` lo permite. Ese diálogo sí interrumpe la superficie simulada y no tiene campo editable; no es un mensaje del runtime real equivalente. No se cambió.

Las etiquetas de reloj, seed, bandeja, trazas, estado simulado y errores de herramientas pertenecen a la interfaz del investigador; no se cuentan como contenido del participante. Por ejemplo, la cadena residual sobre preferencias de comunicaciones está en el manejador de enlaces ajenos a las rutas dummy: su presencia no demuestra que exista esa pantalla en el recorrido público actual.
