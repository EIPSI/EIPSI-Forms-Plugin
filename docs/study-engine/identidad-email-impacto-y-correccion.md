# Identificación del estudio por email — corrección del 16 de octubre de 2026

## Error confirmado y contrato vigente

La cookie del navegador se estaba usando como login persistente del estudio: recuperaba una participación al visitar su portada y restringía el abandono al email de esa participación. Ese comportamiento no correspondía al contrato pedido.

La identidad se resuelve por el email introducido o por el enlace que se abre. La Pantalla Inicial no elige participante desde una cookie. Las credenciales técnicas que vinculan una evaluación a sus respuestas siguen comprobándose dentro del runtime de evaluaciones.

El usuario confirmó expresamente que el abandono se ejecuta desde cualquier navegador con el email registrado y la frase escrita, sin sesión de participante ni enlace de autorización, incluidas ambas políticas de datos. No se añadió ningún correo de abandono ni de recuperación.

## Cambios e impacto

| Componente | Comportamiento corregido |
| --- | --- |
| Portada pública del contrato actual de Pantalla Inicial | Abrir la URL normal o refrescar vuelve a una pantalla vacía de identidad; no recupera email, disponibilidad ni avisos desde la cookie |
| Avisos de espera | Son el resultado de la consulta/ingreso que acaba de realizarse; no vuelven a aparecer por recuperar al participante de una cookie |
| Abandono: primer modal | Consulta el email normalizado dentro del estudio antes de permitir la confirmación; un email inexistente permanece en el modal con error editable |
| Abandono: servidor | Resuelve el destino por estudio y email; no usa la participación de la cookie ni acepta IDs de participante/ejecución enviados por el navegador |
| Abandono: atomicidad | Revalida estudio, email, ejecución y estado bajo el bloqueo de esa ejecución; mantiene comprobación de revisión y frase de confirmación |
| Errores de abandono | Permanecen en el contexto del email introducido; no recuperan disponibilidad o identidad de otra persona a partir de la cookie |
| Registro de privacidad | Registra `EMAIL_ENTRY` como origen explícito de la decisión; el resto del procesamiento de exclusión, cancelación de avisos y limpieza conserva su contrato |
| Reingreso por email existente | Si el email ya está validado, consulta directamente su participación y estado actual sin otro enlace. Si todavía no está validado, conserva el recorrido de validación pendiente. La cookie no selecciona la identidad y no se crea otra participación |
| Simulación | Portada limpia al volver, consulta de email de la participación ficticia y abandono sin exigir su sesión; reingreso por el enlace simulado |
| Finalización/reapertura | No identifica participantes históricos con emails archivados; rechaza ejecuciones finalizadas y mantiene separados los ciclos |
| Avisos programados por email | Su elegibilidad ya depende del participante, verificación, planificación y política de datos, no de que el navegador tenga una cookie. No se alteró esa programación |
| Evaluación en curso y confirmación de dirección al terminarla | Conservan comprobaciones de propiedad, versión, oportunidad, CSRF y respuestas; no sirven para identificar silenciosamente la portada |

## Textos de abandono

Se retiraron de la interfaz los campos «Email que no corresponde a la participación» y «Abandono sin sesión de participación». El aviso vigente es «No encontramos ese email en este estudio». Se edita en Study Engine → Pantalla Inicial → seleccionar Modal de abandono → Propiedades → Email de abandono → Email no registrado para abandonar.

## Casos de aceptación

1. Cookie de A y email B existente: se consulta y retira B, no A.
2. Sin sesión de participante y email B existente: se permite el mismo recorrido con las dos políticas de datos.
3. Email inexistente o sólo registrado en otro estudio: se rechaza sin crear participantes ni enviar correos.
4. Cancelar: sin cambios.
5. Confirmar: afecta sólo a la participación identificada y mantiene los datos de otras personas/estudios.
6. Mayúsculas y espacios: normalización habitual del email.
7. ID arbitrario de participante o ejecución en la solicitud: rechazado.
8. Revisión desactualizada: rechazada sin retirar a nadie ni mostrar los datos de la persona de la cookie.
9. Reingreso concurrente: una sola participación y ejecución; compartir cookie no valida un email que todavía no fue validado.
10. Refrescar o volver a Pantalla Inicial en real y simulación: email vacío y sin avisos personales recuperados.

## Alcance de la sesión de evaluación

Se mantienen las credenciales que autorizan guardar, recuperar y enviar una evaluación concreta y su vínculo con el participante. Cambiar de identidad en un mismo navegador no mezcla sus respuestas. La autorización actual de evaluaciones usa también el token técnico del adaptador; habilitar evaluaciones simultáneas de participantes distintos en pestañas del mismo navegador requeriría otro cambio específico. No se agregó esa función.

Las vistas previas estáticas muestran el diseño y no consultan participantes reales. La simulación utiliza únicamente su participante ficticio y no realiza envíos SMTP ni escrituras de participante reales.

No se hicieron commits. La comprobación humana en Firefox sigue pendiente.

## Reingreso de un email ya validado

Se eliminó la solicitud redundante de validación para una dirección cuyo estado ya es `VERIFIED`. El servidor resuelve el email escrito en ese estudio y consulta su ejecución existente: abre la evaluación disponible o muestra la espera y la próxima fecha. No vuelve a mostrar «Email validado», no programa otra validación y no depende de que coincida con la cookie previa. El aviso predeterminado de espera es «No hay evaluaciones disponibles». Tanto ese texto como los demás avisos conservan sus campos de edición.

Se comprobó por HTTP con y sin cookie previa de otro participante, para estados WAIT y OPEN_ASSESSMENT. También se verificó el mismo reingreso en la simulación y la conservación de la randomización al volver a una evaluación.

## Verificación final

632 pruebas JavaScript y 541 PHP de las suites relacionadas aprobadas en corridas complementarias, más 12 pruebas puras de privacidad. Build, comprobación de artefactos y RCT estricto verdes. Incluye las carreras reales submit/withdraw en ambos órdenes, la limpieza posterior y el reintento de una confirmación cuya respuesta se perdió. Evidencia: `.cache/ci/20261016-151038-e1fb50e7` (JS/build y PHP longitudinal/privacidad) y `.cache/ci/20261016-151310-172069c3` (simulación y resto de suites relacionadas). La única expectativa de la simulación que fallaba en la primera corrida se adaptó al reingreso explícito por email conservando el resultado de randomización. No se modificaron datos reales ni se enviaron emails reales. Sin commit.
