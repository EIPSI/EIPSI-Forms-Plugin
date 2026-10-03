# Regresiones P0

Suite mínima sin dependencias de PHPUnit ni bootstrap de WordPress. Ejecuta las
funciones y handlers PHP reales con dobles explícitos de WordPress. Solo admite CLI.
No envía emails ni modifica opciones o participantes reales.

```bash
php tests/run-p0.php
```

Dentro del contenedor de este proyecto:

```bash
docker exec wp-eco-wordpress-1 php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/run-p0.php --integration
```

`--integration` requiere mysqli y las variables `WORDPRESS_DB_HOST`,
`WORDPRESS_DB_NAME`, `WORDPRESS_DB_USER` y `WORDPRESS_DB_PASSWORD`. Crea tablas con
prefijo aleatorio `eipsi_p0_test_*`, las elimina en `finally` y prueba los handlers
contra MariaDB usando un adaptador SQL. La identidad, los permisos, los nonces y
el correo siguen siendo dobles controlados: no sustituye una prueba HTTP completa
con cookies reales. La conexión externa se prueba contra la misma MariaDB de
Docker, con tablas aisladas; también se prueba una conexión rechazada y su fallback.

## Flujos y contratos revisados

| P0 | Entrada y callback | Nonce e identidad | Tablas | Consumidores y resultado |
| --- | --- | --- | --- | --- |
| Emergency storage | `eipsi_forms_submit_form` → `eipsi_forms_submit_form_handler` → `eipsi_safety_save_with_retry` → `eipsi_safety_emergency_save` | `eipsi_forms_nonce` en submit; metadata de respuesta en storage | `eipsi_emergency_submissions`, externa o local | Handler de submit y alerta admin. Éxito conserva `emergency_id`, `emergency_mode`, mensaje y `storage`. Fallo devuelve `success=false`; submit utiliza su error existente `SAFETY_SYSTEM_FAILURE`, HTTP 500. |
| Partial debug | `wp_ajax[_nopriv]_eipsi_debug_partial_response` → `eipsi_debug_partial_response_handler` | Ahora `manage_options` + `eipsi_admin_nonce` | `eipsi_partial_responses` | Helper de consola `window.eipsiDebugSaveState()`, no autosave/restauración normales. Solo admin recibe nonce. Respuesta autorizada conserva summary/field_analysis/raw_responses; usuario sin capacidad recibe error 403 sin consulta. |
| Retiro | `wp_ajax[_nopriv]_eipsi_abandon_study` → `eipsi_abandon_study_handler` | Conserva `eipsi_abandon_study`; identidad/estudio desde Auth Service, pertenencia comprobada en DB | Principal `survey_participants`; B2 conserva su tratamiento existente de resultados, parciales, dispositivos, logs, links y sesiones | `includes/templates/withdrawal-modals.php`: payload y respuesta normal conservados. IDs ajenos/sin sesión se rechazan antes de escribir; retiro propio conserva estado, logout y redirect. |

## Cobertura

- INSERT local exitoso, fallido con ID previo, CREATE fallido.
- Persistencia real local/externa, rechazo SQL, fallback tras excepción externa.
- Diagnóstico público denegado, admin autorizado, nonce ausente/inválido.
- Retiro de otro participante (B2) denegado sin escrituras.
- Retiro propio B1, falta de sesión, estudio ajeno, pertenencia inconsistente,
  nonce inválido e identidad derivada de sesión sin IDs enviados.

Para contrastar con el código anterior se puede proporcionar
`EIPSI_P0_SOURCE_DIR` apuntando a copias de los dos handlers de una revisión previa.
La suite no genera ni modifica esas copias.

## Límites de esta fase

No cambia la reconciliación de emergencia ni la cobertura del borrado B2.
No prueba el bootstrap completo del plugin, UI de navegador, transporte de email,
WordPress HTTP/nonces reales o administración de cookies. El `phpunit.xml`
histórico no es el runner de estas pruebas; estas se ejecutan con el comando anterior.

## Regresiones P1-A: autorización e identidad longitudinal

```bash
docker exec wp-eco-wordpress-1 php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/run-p1.php
```

Las 40 pruebas usan los servicios de autenticación, participantes y magic links,
los handlers de consentimiento/submit y el renderer reales. SQL se ejecuta mediante
`wpdb` de WordPress contra MariaDB, con las definiciones actuales de SchemaManager.
Cada caso crea tablas `eipsi_p1_test_*` con prefijo aleatorio y las elimina en
`finally`. Requiere WordPress en `/var/www/html` y las mismas variables de DB del
runner P0. No usa participantes reales ni envía correo. Nonces, posts/metadatos,
hooks y funciones auxiliares de WordPress son dobles explícitos; no sustituye una
prueba HTTP completa del plugin con navegador.

### Política final

`EIPSI_Auth_Service::authorize_participant()` exige participante existente,
pertenencia al estudio y `is_active=1`. Rechaza `declined`, `withdrawn` y decisiones
de consentimiento desconocidas, comprobando también los estados equivalentes.
Permite consentimiento pendiente (NULL/vacío) para conservar el acceso previo a
la decisión; esta fase no impone una nueva obligatoriedad global de consentimiento.

`get_current_session()` deriva participante y estudio del mismo token vigente y
revalida la política en cada lectura. Si el estado cambió, revoca esa sesión.
`authorize_session_context()` rechaza IDs cliente diferentes. Para submit,
`authorize_form_operation()` exige además formulario/wave/assignment propios,
con assignment pendiente o en progreso. Email y fingerprint no autorizan identidad
longitudinal. Formularios publicados vinculados a waves requieren sesión incluso
si el cliente omite contexto; los independientes siguen siendo anónimos salvo su
bandera de login. El fingerprint conserva su función de tracking de parciales.

### Recorridos auditados antes y después

| Recorrido | Antes | Después |
| --- | --- | --- |
| Login → participante → sesión → cookie → lectura | Contraseña comprobaba activo/rechazo; passwordless agregaba retiro. La lectura de sesión no cubría todos los cambios de estado. | Ambos logins y creación/lectura de sesión aplican la política común; token hash y duración conservados. |
| Magic link → token → participante → sesión → estudio | Token/uso/vencimiento sin política uniforme del participante. | Mantiene esas comprobaciones y agrega política/pertenencia antes de crear sesión; el caller solo consume el enlace si la creación tiene éxito. |
| Sesión existente → participante → formulario | Helpers legacy podían leer IDs de cookies; acceso al formulario podía omitir pertenencia. | Token autoritativo y revalidación; formulario longitudinal y shortcode exigen el estudio de la sesión. |
| Consentimiento → IDs → sesión → estado | ID del cliente podía prevalecer; sesión era fallback. | Nonce `eipsi_forms_nonce` conservado, identidad de sesión y comprobación de IDs/formulario antes de cambiar estado. Aceptar/rechazar propios y redirect se conservan. |
| Submit → identidad → study → wave → assignment → persistencia | Podía resolver participante por email/fingerprint y contexto cliente. | Nonce original conservado; sesión → participante autorizado → estudio → wave/formulario → assignment antes de guardar. Metadata y respuesta usan identidad canónica. |

Las tablas involucradas son `survey_participants`, `survey_sessions`,
`survey_magic_links`, `survey_waves`, `survey_assignments`, `vas_form_results`
y `eipsi_partial_responses`; no se modifica schema. El contexto interno
`longitudinal_participant_id` se excluye del INSERT local y se conserva solo para
la sincronización autorizada. Envíos anónimos ya no sincronizan participantes por
email. Los consumidores siguen usando los callbacks/APIs y respuestas existentes;
las nuevas denegaciones de consentimiento/submit devuelven error antes de persistir.

### Cobertura y límites

Incluye los 14 escenarios solicitados, contraseña/passwordless, estado modificado
después del login, pertenencia cambiada, sesión vencida/participante eliminado,
links usados/vencidos, consentimiento propio/ajeno, submit con email de B bajo
sesión A, contexto falsificado, assignment ausente/cerrado, persistencia real y
tracking de parciales. También verifica render y formularios anónimos con y sin
una sesión longitudinal abierta. Ejecutar asimismo las 22 pruebas P0.

Fuera de este bloque: autenticación passwordless inicial por email conserva su
contrato; no se resuelve concurrencia entre validación/consumo de magic links,
atomicidad entre guardar respuesta y actualizar assignment, ventanas temporales,
waves/nudges, pools ni exports. Los hooks se aíslan: esos subsistemas no quedan
validados por esta suite. Los helpers legacy restantes no se eliminan y otros
endpoints no se consideran auditados por estas pruebas.

## Regresiones P1-B

```bash
docker exec wp-eco-wordpress-1 php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/run-p1b.php
```

40 casos de contratos AJAX, persistencia/read-only de waves, copia T1/T2/T3 y
nudges. Reutiliza las tablas aisladas y `wpdb` real del runner P1-A, agrega la cola
`survey_nudge_jobs` y auditoría con schema actual. Dos casos inyectan rechazo SQL
mediante triggers de fixture, eliminados al borrar sus tablas. WP-Cron es un doble
que registra eventos y permite simular fallos; no programa eventos reales ni envía
correo. `EIPSI_TEST_FILTER` permite seleccionar casos por parte del nombre.

Mantener verdes los 22 P0, 40 P1-A y 40 P1-B: 102 casos. La auditoría, matrices de
campos/consumidores y riesgos están en
`wp-eco/informes/EIPSI-Forms/2026.10.03 - Estabilización P1-B.md`.
