# Templates de Emails

La biblioteca global está en `admin.php?page=eipsi-email-templates`. Requiere `manage_options` y nonce del Builder para lectura, escritura, eliminación y preview. Permite hasta 50 templates reutilizables de validación, evaluación disponible y recordatorio. Cada uno tiene nombre, tipo, nombre del remitente, asunto y cuerpo con el editor enriquecido del Assessment Editor. Los templates nuevos precargan el contenido predeterminado visible. En un template editado, asunto o cuerpo vacíos permanecen vacíos.

Cada modificación usa revisión global y compare-and-swap en una opción sin autoload. Una escritura obsoleta se rechaza sin sobrescribir la biblioteca. La interfaz conserva la edición local ante conflicto. Los previews usan datos de ejemplo, no mandan correo y no generan enlaces personales reales. La página no administra contraseñas SMTP.

## Copias por estudio

En **Pantalla Inicial → Autenticación → Templates de Emails** se elige un template de cada tipo; la selección aplica su copia al draft. No hay pestaña Emails ni edición de remitente, asunto o cuerpo en el Study. Se guardan asunto, cuerpo, remitente y referencia/revisión/nombre de origen. El contenido se edita en la biblioteca global. Recargar la biblioteca no reemplaza sus copias; se requiere seleccionar explícitamente un template.

Publicar fija esos valores en la versión del estudio. Editar o eliminar un template global no cambia definiciones publicadas ni ejecuciones existentes. El worker utiliza exclusivamente la copia de la versión fijada; no consulta la biblioteca global al enviar. Las copias y overrides previos dentro de `initial_screen.templates` se conservan como configuración existente hasta seleccionar un reemplazo o el predeterminado. No requiere migración de datos científicos.

## Shortcodes

Son variables del template, no ejecución de shortcodes arbitrarios de WordPress. Se admiten `[variable]` y la sintaxis histórica `{{variable}}`. La interfaz muestra la lista con sus descripciones:

| Shortcode | Contenido |
| --- | --- |
| `[study_name]` | Nombre del estudio |
| `[assessment_name]` | Nombre de la evaluación de esa oportunidad |
| `[available_time]` | Fecha/hora de disponibilidad en la zona del participante |
| `[due_time]` | Vencimiento en esa zona |
| `[direct_link]` | Acceso personal que también acredita el email según el contrato del estudio |
| `[withdrawal_link]` | Opciones de abandono |

El contenido enriquecido se sanitiza al guardar y renderizar. Variables desconocidas y remitentes inválidos se rechazan. Los valores se escapan según contenido/encabezado; no se ejecutan PHP ni callbacks de WordPress desde el cuerpo. El asunto y el cuerpo provienen exclusivamente del template elegido, con sustitución segura de shortcodes. No se añaden botones, enlaces, footer ni avisos de validación. Si se elimina un enlace, tampoco se repone al abrir el editor.

## Relación con autenticación y notificaciones

Pantalla Inicial conserva el modo inmediata/desde la segunda/nunca, revisión posterior de email, pertenencia y propiedades de la pantalla y abandono. En Notificaciones conserva habilitar avisos, comportamiento cuando el participante está presente y mostrar próxima disponibilidad. `email_enabled` gobierna avisos científicos de disponibilidad y recordatorios; no suprime los enlaces exigidos por el modo de validación. Los tiempos de recordatorio se definen en cada interacción longitudinal.

El selector histórico “Notificar a: sólo verificados/no verificados” no se muestra en V2, cuya validación depende de sus tres modos y cuyos enlaces de evaluación acreditan la dirección. La biblioteca no cambia esos gates, programación, destinatarios, capabilities, sesiones ni retiros.

El remitente se aplica sólo al mensaje correspondiente: en SMTP se pasa en una copia de la configuración y en `wp_mail` mediante encabezado/filtro con limpieza `finally`. No cambia la cuenta, credenciales, configuración global o remitente de otros emails. Si se deja vacío, conserva el remitente existente. La dirección del remitente se toma de la configuración global del sistema.

## Verificación

Pruebas DOM: separación de pestañas, copias independientes, edición sin perder el foco, sólo lectura de publicados, DTO de guardado/preview, catálogo y conflictos. Integración: permisos/CSRF, CAS, sanitización, shortcodes, preview sin envío, persistencia y entrega después de eliminar el origen global, aislamiento del remitente y página HTTP. Todo SQL y correo se prueba en harness descartable con captura; la inspección visual humana local continúa pendiente. Sin commit ni push.

Resultado 2026-10-13: `./tests/ci/run.sh full` PASS; 2.747 tests (535 JavaScript, 428 PHP puro, 1.784 WordPress), cero fallos; RCT estricto 1/1 y build/clean/upgrade/smoke/lifecycle verdes. Evidencia: `.cache/ci/20261013-095904-b7da5b27`. Revisión visual humana pendiente.

### Presentación unificada y remitente del sistema

La biblioteca comparte botones, controles y tipografía de Assessment Editor, en una sola columna: listado, propiedades, cuerpo enriquecido y acciones. WordPress monta su editor Visual/HTML con herramientas de formato y medios; no requiere abrir otro modal para editar el cuerpo. El nombre del remitente permanece opcional, pero no se configura una dirección por template. Guardar omite `from_email`; el render de emails usa siempre la dirección global del sistema, incluso si una copia histórica conserva ese dato. El contenido y las referencias de esas copias no se reescriben.

La vista previa abre un modal con asunto y HTML en un iframe sandbox. Cerrarlo devuelve el foco al botón de origen; no desmonta el editor ni envía correos.

### Contenido exacto del template

Validación, evaluación disponible y recordatorio comparten esta regla. El aviso fijo del worker sobre validación tras vencimiento se eliminó. Editor, previsualización, simulación y transporte usan el mismo contenido; el email combinado de evaluación y validación también usa el template de disponibilidad en la simulación. Se mantienen los propósitos y controles de los enlaces, pero sólo aparecen en el mensaje cuando el template los incluye. Las copias seleccionadas por estudio se conservan: editar la biblioteca no reemplaza silenciosamente los templates de un protocolo publicado.

## Avisos sin preferencia individual

Se retiró «Administrar comunicaciones»: no hay pantalla, acción ni enlace para activar o desactivar avisos por participante. La configuración de envíos pertenece al estudio; el abandono cancela sus avisos pendientes. La auditoría de comunicaciones de Gestionar sigue mostrando los envíos registrados.

La migración 17 elimina la preferencia almacenada y sus credenciales específicas. Retira el enlace de la biblioteca y de las copias de templates al presentarlas, sin reescribir los protocolos publicados. No reactiva envíos históricos cancelados.
