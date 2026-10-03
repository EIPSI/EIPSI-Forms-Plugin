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
