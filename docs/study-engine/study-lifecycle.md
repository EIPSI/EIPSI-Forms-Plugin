# Estado del estudio y reapertura

La gestión se encuentra en **Configuración General → Estado del estudio**. Publicar una versión científica no activa por sí solo el estudio.

| Estado visible | Significado | Acciones |
| --- | --- | --- |
| Draft | Aún no se inició el estudio. | Activar, después de publicar una versión. |
| Activo | Admite nuevos participantes. | Pausar, Finalizar estudio. |
| Pausado | No admite nuevos participantes; los actuales continúan, con sus emails y programación. | Activar, Finalizar estudio. |
| Finalizado | No admite nuevos participantes ni continuación de participaciones anteriores. | Reapertura. |

`CLOSED` se conserva como estado interno histórico de cierre de ingresos y se presenta como Pausado. No es una nueva opción del editor.

## Finalizar

El modal informa participaciones y evaluaciones en curso. **Eliminar los emails de los participantes** está marcado por defecto. Se bloquean las participaciones de todas las versiones del estudio, se revocan sesiones y enlaces, se cancelan los envíos pendientes y se conservan respuestas y parciales ya persistidos. No se registra abandono, retirada ni exclusión del análisis.

La finalización conserva el checkpoint científico y agrega `finalized_at` y `study_cycle` por ejecución. Los campos también acompañan las filas del export. La retención temporal del runtime Native no borra los parciales congelados por finalización. Las respuestas que sólo estaban en el navegador, sin guardar, no se pueden recuperar.

Cuando se elimina el email, también se eliminan los recibos operativos de inscripción/corrección que podían contenerlo y las copias históricas de email del propio estudio. No se modifica el texto libre que el investigador haya recogido como respuesta. No se borran backups, correos ya entregados ni exports descargados.

Si se desmarca la eliminación, el email se conserva en `participants.archived_email`. El campo de identidad activo queda vacío: el email histórico no participa en la búsqueda, validación ni recuperación de sujetos.

La operación es transaccional, requiere tablas InnoDB, autorización administrativa y nonce. Una revisión de estado independiente detecta pestañas desactualizadas; un identificador de solicitud permite repetir una solicitud sin repetir sus efectos. Un bloqueo por estudio serializa la finalización con el tramo de envío de email: un envío ya iniciado debe terminar antes de confirmar el cierre.

## Reapertura

Se conserva el identificador lógico, el protocolo publicado seleccionado y la URL pública. Se incrementa `study_cycle`; las ejecuciones anteriores mantienen su fecha de finalización y su número de etapa. Ninguna sesión, respuesta, asignación, enlace ni email anterior se vuelve a activar.

La misma dirección de email crea un participante y una ejecución nuevos. Los límites por IP de Transversal se aplican a la nueva etapa; los marcadores históricos se conservan. Si el email se eliminó, la reapertura no lo reconstruye.

## Simulación y verificación

Los controles de estado simulados están fuera del contenido del participante. La finalización muestra el mismo contenido público de estudio finalizado; la reapertura crea un recorrido nuevo y conserva el anterior separado dentro de la simulación.

La migración v15 es aditiva. Las pruebas de `run-study-lifecycle.php` cubren permisos, DTO, estados, pausa, cierre, email borrado/conservado, todas las versiones, reapertura, reintentos, envíos en curso, IP, parciales Native y upgrade desde v14. `builder/lifecycle-dom.js` cubre controles disponibles y eliminación marcada por defecto. No se ejecutan envíos reales ni se modifica la instalación local durante estas pruebas.

## Validación del 15 de octubre de 2026

- Gate completo: `.cache/ci/20261015-020806-fcb1b3a8/summary.json`, salida 0, 266,31 s. Total: 2.866 tests (588 JS, 428 PHP puros, 1.850 WordPress), más RCT estricto; build, instalación/upgrade y smoke/lifecycle verdes.
- Tras ajustar el indicador persistente y el recuento del modal: build `20261015-021254-7c2d6344`, JS `20261015-021300-b0c72f3c` (588/588), lifecycle `20261015-021256-3d1106a2` (13/13 y RCT). Se verificó una respuesta realmente enviada, su export y journal después de finalizar.
- La revisión visual humana en el WordPress local sigue pendiente. No se modificó su base de datos ni se enviaron emails reales. Sin commit.
