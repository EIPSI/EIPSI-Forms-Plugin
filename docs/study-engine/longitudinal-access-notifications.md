# P1-F — acceso longitudinal y notificaciones

El modo `EMAIL_LONGITUDINAL` es opt-in por publicación del Study Engine. `ANONYMOUS` conserva el flujo P1-E. No migra Surveys/Waves ni agrega un Builder. El mismo endpoint público decide el modo; la publicación activa habilita la composición y el procesamiento del cron existente.

## Identidad y enrollment

Participant tiene un ID opaco `PT_*`, email canonicalizado (trim, minúsculas y validación WordPress) único dentro del Study lógico, estado de verificación, privacidad, comunicación y revisión de acceso. El email no es la identidad científica de Storage. El mismo email en dos Studies representa dos participantes separados; no se eliminan puntos ni aliases Gmail.

La admisión serializa por Study y usa un receipt del intento. Participant, execution con versión pinneada, sesión provisional, intent de verificación y job S2 se confirman en una transacción InnoDB. Un fallo SQL revierte todo. Dos pestañas y retries no crean dos executions. Un participante existente con sesión válida recupera directamente; sin sesión obtiene un mensaje genérico y un enlace de acceso. El email por sí solo nunca abre respuestas. Una execution completada no se reinscribe automáticamente. Una publicación retirada conserva sus executions, mientras nuevos participantes usan la versión activa. PAUSED impide nuevas admisiones y suspende delivery; no cambia fechas históricas.

## Sesiones y verificación

Los tokens aleatorios de sesión se guardan como hash. Cada sesión pertenece a Participant/Study/Execution/StudyVersion y revisión de acceso. Defaults explícitos en el snapshot: provisional 24 horas, autenticada 30 días; la actividad no renueva su vencimiento. Se permiten dos dispositivos. Logout revoca sólo esa sesión. Una revisión de acceso del participante permite revocación conjunta; fingerprint y email no autentican.

Un email nuevo entra inmediatamente a la assessment elegible o a WAIT. Verificación no es una barrera universal. `VERIFY_PARTICIPANT` consume el enlace, registra VERIFIED y el evento científico correspondiente en una transacción; un protocolo con anchor explícito sí puede esperar ese evento. La política de notificaciones científicas por defecto es `VERIFIED_ONLY`; `ALLOW_UNVERIFIED` es una elección explícita de publicación.

## Capabilities y enlaces directos

Envelope: `key_id.nonce.HMAC-SHA256`, con nonce criptográfico y hash del token completo en servidor. La firma usa un keyring de acceso dedicado, separado del seed vault y SMTP. El servidor conserva Participant, Study, Execution, versión, propósito, BlockExecution opcional, revisiones, emisión, vencimiento, consumo y revocación. TTL por defecto 48 horas; propósitos separados: VERIFY_PARTICIPANT, ACCESS, ASSESSMENT_AVAILABLE, ASSESSMENT_REMINDER, COMMUNICATION_OPTIONS y WITHDRAWAL_OPTIONS.

Desde P1-I, GET con `eipsi_cap` sólo valida y guarda una cookie pendiente HttpOnly/SameSite=Lax de diez minutos, con Secure bajo HTTPS; responde 303 a una URL limpia de confirmación. No consume ni verifica al participante. La pantalla no contiene el bearer en HTML: un POST explícito con cookie, CSRF y Origin válido canjea atómicamente, emite sesión, elimina la cookie pendiente y redirige a la URL del estudio. Esto evita el consumo por escáneres que sólo hacen GET; no promete resistir automatizaciones que envíen deliberadamente el formulario. El bearer aún puede aparecer en el primer access log: requiere redacción operacional de query strings. Replay sin sesión falla; un retry con la sesión ya emitida no crea otra. Un bearer reenviado antes del canje permite a su poseedor canjearlo: no se promete detectar al destinatario original.

El enlace autentica; el engine decide el NextAction actual. Nunca fuerza el bloque de su payload sobre COMPLETED, EXPIRED, SKIPPED, no disponible o WAIT. Un enlace antiguo de E2 no atraviesa un WAIT que eligió E4. Los enlaces de retiro emiten sesiones restringidas, sin acceso a las respuestas. No existe una preferencia del participante para activar o desactivar avisos. Retiro es un shell informativo: el flujo y cleanup completo corresponden a P1-G.

## Configuración de publicación y reminders

`Configuration::apply()` escribe el contrato `P1F_V1` en `protocol_metadata`, incluyendo todas sus elecciones. Defaults: emails habilitados, templates ASSESSMENT_AVAILABLE/ASSESSMENT_REMINDER, unidad `minutes`, base `AFTER_AVAILABLE`, offsets `[15,30,60,120]`, `VERIFIED_ONLY`, provisional 24 h, sesión 30 días, capability 48 h, `SUPPRESS_WHILE_PRESENT`, mostrar próxima fecha.

Offsets aceptan CSV o array de enteros positivos, se ordenan y deduplican. Máximo 64 entradas y 525600 minutos; se rechazan cero, negativos, floats, NaN y unidades desconocidas. No se crea reminder cuando su fecha alcanza o supera due_at. No se usa una configuración paralela para el calendario científico.

Availability y reminders nacen dentro de la transición científica, por BlockExecution, propósito, canal y schedule_revision. Sólo assessments reales de la rama elegida generan oportunidades. Ancestros y barreras se verifican de nuevo antes de envío. La política SUPPRESS_WHILE_PRESENT cancela la oferta inmediata si el participante estuvo activo en los últimos 60 segundos; SEND permite enviarla. Ninguna de estas políticas cambia disponibilidad o respuestas.

## Outbox, worker y delivery

Se reutilizan NotificationIntent, `survey_nudge_jobs`, claims/lease/retries S2 y el owner EmailDelivery. La inserción del job usa la misma conexión/transacción de admisión o transición. El worker reclama el job existente, avanza el reloj del engine y revalida participante, publicación, configuración, estado, barrera, revisión, ventana y preferencias inmediatamente antes del delivery externo. No existe un segundo lease.

El cron existente `eipsi_process_nudge_jobs` procesa lotes de 25. No programa un evento por participante. Activación opt-in asegura el owner; desactivación lo limpia y conserva datos; reactivación retoma. PAUSED/CLOSED posponen sin consumir retries. Fallos de SMTP conservan el estado científico; el outcome terminal converge a FAILED_FINAL. Una cancelación revoca los capabilities ligados al intent. Cambios de timezone cancelan pendientes viejos y crean futuras oportunidades; SENT permanece histórico.

El delivery usa el servicio SMTP existente cuando está configurado, con fallback centralizado a wp_mail. WHAT/message keys y variables escapadas viven en EmailTemplate, separados de WHEN. No ejecuta PHP ni shortcodes. El enlace de retiro se incluye sólo si figura en el template. Los avisos se detienen al abandonar el estudio. El log del worker incluye IDs, template, intento y estado, sin dirección, bearer ni body.

SMTP no participa de la transacción MySQL. Aceptación SMTP seguida de crash antes de SENT puede producir duplicado en retry; no hay exactly-once. Tampoco puede retirarse un mensaje ya entregado si cambia el estado después de la última revalidación. Los tests capturan correo local y verifican esta ambigüedad; no certifican deliverability institucional.

## Timezone y Storage

La timezone IANA propuesta por el navegador se valida; si falta se usa la del Study. El origen se registra como PARTICIPANT_LOCAL o STUDY_FALLBACK. Cambiarla después exige confirmación explícita; no se actualiza automáticamente al viajar. DST gap/fold se resuelven por el engine y las fechas de intents, emails y vistas usan ese mismo calendario.

Storage y SubmissionJournal mantienen la transacción y referencias existentes. Participant opaco sustituye la identidad anónima para este modo. Metadatos P1-E (UA, device, browser, OS, pantalla y fingerprint informativo) se conservan; se agrega timezone/source. Varias assessments conservan filas y referencias distintas; ningún fingerprint otorga autorización.

## Configuración local y operación

Se requieren dos keyrings independientes, fuera del repositorio, en wp-config.php o entorno. `EIPSI_STUDY_ENGINE_KEYRING` protege seeds; `EIPSI_STUDY_ENGINE_ACCESS_KEYRING` protege acceso. Ambos tienen estructura `{"current":"KEY_ID","keys":{"KEY_ID":"64 caracteres hex aleatorios"}}`. Genere cada secreto localmente con `openssl rand -hex 32`; no copie las claves públicas desechables de los tests. Retenga claves viejas del seed vault mientras existan executions que las necesiten; retenga claves de acceso durante la vigencia de enlaces emitidos, salvo revocación deliberada.

Con WP-CLI y keyrings configurados, puede publicar un template real:

```sh
wp eval-file wp-content/plugins/EIPSI-Forms-Plugin/scripts/study-engine/publish-longitudinal.php TEMPLATE_ID STUDY_ID PUBLIC_ID VERSION_NUMBER '15,30,60,120'
```

El helper de demos `tests/study-engine/longitudinal/create-demo.php` acepta prepost, daily, ema, rct o wait. Sólo para datos locales desechables; no integra B1/B2 completo. Estos helpers se verifican por sintaxis; la integración funcional está cubierta por fixtures MariaDB/HTTP, no por instalación de WP-CLI en CI.

Para deploy verifique HTTPS, cookies Secure/HttpOnly y políticas de cache privado, ausencia de analytics en rutas con bearer, proxy/access logs sin query strings sensibles, ejecución real del cron, reloj/timezone, conservación/rotación de keyrings, respaldo y capacidad InnoDB. Compruebe SMTP/remitente, SPF/DKIM/DMARC y recepción real con cuentas de prueba autorizadas. El gate local no valida Nginx, SMTP real ni instalaciones institucionales.

## Pruebas y límites

`./tests/ci/run.sh full`: 1689 tests únicos, 1605 PHP + 84 JS. P1-F agrega 123 DB/HTTP y 6 DOM; RCT strict y build se mantienen. Casos incluyen carreras de admisión, rollback SQL, verificación/anchor, scope/replay/revocación, sesión vencida, dos dispositivos, WAIT/RCT, DST, revisión de schedule, sent histórico, lease/stale lease, retries y aceptación SMTP antes de crash. Los snapshots científicos originales permanecen idénticos en PHP 7.4 y 8.3.

La diferencia visible entre email nuevo (entrada inmediata) y existente (solicitud de enlace) admite enumeración residual; respuestas genéricas y límites separados de admisión/acceso/resend/canje reducen abuso sin prometer indistinguibilidad. El contrato inicial P1-F canjeaba por GET y podía ser consumido por scanners; P1-I corrigió ese defecto con la confirmación POST descrita arriba. El alcance original P1-F no implementaba multilanguage, Builder, migración legacy ni retiro completo; P1-G/P1-H añadieron retiro y Builder, sin migración legacy.
