# Revisión de textos, Pantalla Inicial y abandono — 16 de octubre de 2026

**Actualización de identidad:** las comprobaciones de coincidencia con sesión descritas en esta revisión fueron sustituidas por identificación por email del estudio, según la confirmación posterior del usuario. El comportamiento vigente y sus pruebas están en [Identidad por email](identidad-email-impacto-y-correccion.md). Los apartados sobre la restricción de sesión se conservan sólo como registro del defecto detectado.

Alcance: avisos actuales del Study Engine, Pantalla Inicial, revisión de email y abandono. Se distingue lo que llega al participante de los controles del editor y del simulador. No se auditan como actuales las ramas sin Pantalla Inicial que no utiliza el contrato de autoría actual. Sin cambios en datos del WordPress local, envíos SMTP reales ni commits.

## Edición de los mensajes actuales

Todos estos lugares están dentro de Study Engine → abrir estudio.

| Texto o componente expuesto | Lugar de edición |
| --- | --- |
| Email validado; aviso de futuras evaluaciones; envío de evaluación o validación | Pantalla Inicial → seleccionar autenticación → Propiedades → Validación del email → Ingreso y validación del email |
| Email obligatorio y formato inválido, incluido el campo heredado del abandono | Mismo lugar; campos Email obligatorio y Formato de email inválido |
| Email ya registrado, no encontrado y solicitud de validación | Mismo lugar |
| Confirmación de email después de la primera evaluación y cambio de dirección | Mismo lugar; secciones Confirmación después de la primera evaluación y Cambio de correo, visibles cuando corresponde según la configuración |
| Evaluación no disponible durante espera; período vencido; etiqueta de próxima disponibilidad | Configuración General → Espera de evaluaciones |
| Indisponibilidad por error de acceso | Configuración General → Errores de acceso y operación → Título de evaluación no disponible |
| Mensaje de participación completada, terminada por condición y estudio finalizado | Configuración General → Finalización y estado del estudio |
| Errores de carga, solicitudes, colisión de email y operación | Configuración General → Errores de acceso y operación |
| Estado de procesamiento y fallo de transporte | Configuración General → Estado de operaciones del estudio |
| Título, descripción y footer de Pantalla Inicial | Pantalla Inicial → visual correspondiente y sus Propiedades |
| Etiqueta, placeholder y botón de autenticación | Pantalla Inicial → seleccionar autenticación → Propiedades |
| Botón Abandono del estudio | Pantalla Inicial → seleccionar ese botón → Propiedades |
| Título y contenido del modal de abandono | Pantalla Inicial → Abandono del estudio → Modal de abandono → edición visual |
| Opciones de conservar/eliminar y botón Volver | Seleccionar cada botón en ese modal → Propiedades |
| Email ajeno a la sesión y ausencia de sesión | Seleccionar Modal de abandono → Propiedades → Email de abandono; campos Email que no corresponde a la participación y Abandono sin sesión de participación |
| Título, contenido con variables, placeholder, botones y validación escrita de confirmación | Seleccionar Modal de confirmación → visual y Propiedades; Mensajes de confirmación de abandono |
| Mensaje final de abandono | Mensaje después de abandono → texto enriquecido; admite las variables de la decisión |

Se corrigió la etiqueta del campo de futuras evaluaciones: ya no dice «sin validar», porque el aviso también corresponde a direcciones validadas. Los textos nuevos de error de abandono quedan en las propiedades del modal de abandono, separados de los errores de la frase de confirmación.

Las versiones publicadas contienen su propio snapshot de textos. Editar el draft no reescribe el contenido de una versión ya publicada. Por tanto, comprobar el editor de un draft y un enlace público de otra versión no demuestra que estén usando el mismo contenido.

## Defectos corregidos

1. **Separación distinta en real y simulación.** El formulario de la simulación tenía `display:flex`, disposición vertical y separación de 12 px sólo en `study-builder.css`; el participante real no recibía esas reglas. Se trasladó la disposición a los estilos públicos compartidos. Se normalizaron también dimensiones de los campos, márgenes del email del abandono, separación de confirmación y tipografía del contenido. Los previews estáticos usan ahora el mismo contexto de estilos y clase de formulario.
2. **Email inexistente avanzaba al segundo modal.** El cliente sólo validaba el formato. Ahora compara con la dirección de la participación de la sesión antes de abrir la confirmación y muestra el error en el mismo modal. No realiza búsquedas públicas de otros participantes.
3. **Error del servidor perdía el contexto.** El servidor ya rechazaba un email no vinculado a la sesión antes de retirar datos. Ahora ese rechazo usa un error específico editable; la respuesta HTTP conserva el email introducido y vuelve a abrir el modal de abandono para corregirlo. Una solicitud manipulada sigue siendo rechazada por el servidor.
4. **Sin sesión.** El modal no avanza si no existe una participación vinculada a esa sesión. Esto presenta una restricción que el servidor ya tenía; no crea una autorización para retirar participantes sólo con conocer su dirección.

## Puntos pendientes de conversación

- **Abandono desde un navegador sin sesión.** El botón puede verse, pero escribir un email existente no acredita control de esa participación. El comportamiento actual requiere la sesión de esa participación. No se añadieron enlaces por email ni otro mecanismo de autenticación. Falta decidir la experiencia deseada para ese caso.
- **Títulos en estados transversales.** `finished_title`, `terminated_title` y `finalized_title` están marcados como retirados en el catálogo y ocultos por el editor, aunque el renderizador transversal usa los títulos de cierre y el cierre de estudio transversal usa `finalized_title`. No corresponde afirmar que todos los textos de todo Study Engine sean editables: hay que decidir si esos estados deben conservar título editable o adaptar su presentación. No se cambió el flujo transversal en este ajuste.
- **Formato de fecha y zona.** La etiqueta «Próxima disponibilidad» es editable; la fecha se genera con formato `Y-m-d H:i T`. No existe un selector para ese formato. Es una presentación de datos, no una frase oculta añadida al template.
- **Frase de confirmación.** Se genera desde el nombre editable de la opción, en mayúsculas. No hay otra frase independiente: cambiar el botón cambia la frase. El texto que explica qué escribir y su formato sí se edita.
- **Interfaz propia del navegador.** La presentación de los globos nativos de validación, controles y sus acciones pertenece al navegador. Los mensajes de obligatoriedad/formato enviados por el plugin sí usan los campos editables indicados arriba.

## Comprobación humana pendiente

Usar la misma versión de estudio para el enlace público y la prueba. Con una participación descartable y sesión válida: abrir abandono, escribir una dirección inexistente y elegir una opción. Debe permanecer en el primer modal con error; no debe abrir confirmación ni registrar abandono. Corregir a la dirección de esa sesión, con diferencias sólo de mayúsculas si se desea: debe abrir confirmación. Cancelar y volver no modifica datos. Comprobar también el email sin `@` y una pantalla de revisión de email. Repetir en móvil o ventana estrecha para revisar separación y ajuste de botones.

## Verificación automatizada

632 pruebas JavaScript y 452 PHP relacionadas aprobadas; build, comprobación de artefactos y RCT estricto 1/1 aprobados. Evidencia: `.cache/ci/20261016-124308-7e8128f2`. Incluye envío HTTP de un email ajeno, conservación del modal y la dirección al rechazarlo, ausencia de retiro y de nuevos emails, corrección posterior y abandono válido. Las pruebas DOM comprueban bloqueo antes del segundo modal, ausencia de sesión y coincidencia de separación/dimensiones entre los estilos públicos y la simulación. La comparación humana del layout en Firefox sigue pendiente.
