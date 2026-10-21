# S0 — Prueba de posesión de identidad

31 casos nuevos; 785 casos únicos finales (754 existentes + 31 S0). No contar como nuevos los tests históricos renombrados ni las ejecuciones repetidas contra clean install/reactivación.

## Entorno limpio reproducible

Ejecutar desde la raíz del plugin. Elegir project/prefix/puerto nuevos si ya existe este entorno: un `up` sobre volúmenes anteriores no demuestra clean install. No bajar ni borrar otros entornos.

```sh
EIPSI_S0_PREFIX=eipsi-s0-proof EIPSI_S0_PORT=18224 docker compose -p eipsi-s0-proof -f tests/s0/clean-compose.yml up -d
docker exec eipsi-s0-proof-wordpress php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/m0/install.php
docker exec eipsi-s0-proof-wordpress php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/run-s0.php
docker exec eipsi-s0-proof-wordpress php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/run-m0.php
docker exec eipsi-s0-proof-wordpress php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/run-m1.php
docker exec eipsi-s0-proof-wordpress php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/purga-final/smoke.php
docker exec eipsi-s0-proof-wordpress php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/m8/schema-capture.php
docker exec eipsi-s0-proof-wordpress php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/m7/lifecycle.php
docker exec eipsi-s0-proof-wordpress php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/run-s0.php
```

Los runners rechazan toda DB ajena a `eipsi-m0-db:3306`/`m0` y la marca `eipsi_m0_isolated_install`. El bind productivo es read-only, cron automático está deshabilitado y el MU-plugin temporal solo intercepta correo a `@example.invalid`. No ejecutar suites live concurrentemente. La fixture de Pools declara `version:2`: sin esa precondición la migración legacy de primer admin request interpreta otro formato y vacía sus estudios. No se modifica Pools para resolver un fixture mal formado. El login legacy se prueba con redirect explícito; su fallback sin redirect tiene una deuda preexistente de argumentos fuera de S0.

## Cobertura nueva

- Ataque original con nonce extraído de HTML anónimo y lectura protegida: conserva la cadena completa que produjo RED antes del fix.
- Password correcto e incorrecto; mismo envelope de inicio para email existente/inexistente y ambos actions de magic link.
- Token extraído del correo real interceptado, claim y sesión posterior; ruta survey-access/TTL; token usado, inválido, expirado; inactive/declined/withdrawn.
- Pools denegado al atacante y permitido con identidad canónica probada; cookie inventada y logout con cookie anterior.
- Límite por origen incluso rotando email/estudio; límite por email que cuenta los requests exitosos.
- Registro sin opt-in sin auto-login; opt-in inactivo, confirmación que activa sin sesión; API passwordless fail-closed; métodos callable legacy de login/registro.
- Fallo SQL del claim single-use en ambos consumidores; fallo de entrega con envelope genérico; extensión válida de la misma sesión y revocación ante estados ineligible.

## Security contract change explícito

| Suite/caso | Antes | Ahora | Cantidad |
|---|---|---|---|
| P1-A positivo passwordless | Email autenticaba | Passwordless devuelve proof_required; password válido sigue autenticando | Mismos 40 |
| P1-A pending/Policy | Decisión via email-only | Decisión via password verificado | Sin caso nuevo |
| M2 matriz de estados | Passwordless como entrada | Password como prueba previa a Policy | Mismos 54 |
| M2 HTTP login | Email emitía cookie | Password válido emite cookie | Caso renombrado |
| M2 HTTP errores | Publicaba inactive/declined/withdrawn | Publica invalid_credentials genérico | Mismos casos |
| M2 registro sin opt-in | Auto-login inmediato | requires_email_link, auto_login=false, sin token | Mismo caso |
| M2 tras reactivación | Lookup email-only autenticaba | Password verificado autentica | Mismo caso |
| M3/M4/M5 setup HTTP | Login email-only como preparación | Fixture envía password válido y limpia sus rate keys | Assertions de negocio intactas |
| PURGA FINAL API/assets | Igualdad con baseline congelado | Mismo baseline + delta explícito de dos métodos/JS Auth | Mismos 15 |
| M3 fronteras | Hash shortcode previo | Cadena previa + delta single-use S0 explícito | Mismos 15 |

Los baselines originales no se editan. `security-contracts.json` y `boundary-migrations.json` describen cada excepción con hash previo/nuevo o firma. Para todas las suites PHP, copia temporal escribible y probe M6 seguir [M6](../m6/README.md); añadir `run-m7.php`, `run-m8.php`, `run-purga-final.php` y `run-s0.php`. Ejecutar debug-off aparte.

## JS/build

Con node_modules ya disponible, sin red:

```sh
docker run --rm --network none --user 1000:1000 -e HOME=/tmp -v "$PWD:/app" -w /app node:22-bookworm sh -c 'set -e; node tests/m3/source-contracts.js; node tests/m3/runtime-dom.js; node tests/m7/frontend-load.js; node --check assets/js/participant-auth.js; node --check assets/js/participant-portal.js; npm run build'
```

44 casos JS existentes: 15 fronteras, 26 DOM (baseline/build no duplican conteo), 3 carga M7. Build genera 13 bloques y 18 fragmentos Forms. Sin SMTP real, E2E visual ni garantía atómica de transients. La inyección SQL de fallo pertenece exclusivamente al MU-plugin descartable y se retira en finally; no es un handler productivo.
