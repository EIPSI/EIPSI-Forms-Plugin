# Desarrollo

## Instalación y build

Desde la raíz del plugin, con Node compatible con el lockfile:

```sh
npm ci --no-audit --no-fund
npm run build
```

No ejecutar scripts históricos de automatización que hagan commits/push como parte de este procedimiento. El script npm de build produce 13 bloques en `build/blocks/`; mantener `package-lock.json` sin regenerarlo incidentalmente. Para trabajar en el editor, `npm start` inicia el watcher.

Ubicar el plugin en `wp-content/plugins/EIPSI-Forms-Plugin`, generar el build y activar desde WordPress. La instalación limpia reproducible está en [M0](../tests/m0/README.md); usa una base descartable distinta del entorno de desarrollo. El compose M0 es un fixture local, no una configuración de despliegue institucional.

## Fuentes de verdad

Metadatos y atributos de bloques: `src/blocks/*/block.json`. Tokens CSS: [eipsi-tokens.css](../assets/css/eipsi-tokens.css). Importación/exportación JSON: [form-library-tools.php](../admin/form-library-tools.php). No se mantiene una tabla duplicada de atributos o colores.

Correo: los servicios bajo `admin/services/` usan el transporte de WordPress y su configuración. No inferir entregabilidad por un log ni ejecutar cron de estudios reales para probar el transporte; las pruebas usan dobles/interceptación. Los recorridos legacy siguen activos y deben caracterizarse antes de migrarlos.

## Versiones: evidencia y propuesta

- `eipsi-forms.php`: header, constante `EIPSI_FORMS_VERSION` y `Stable tag` conservan `2.6.1`. La constante también versiona assets.
- `package.json` / `package-lock.json`: `1.5.5`, numeración del paquete de build heredada.
- En el historial disponible, `29feac5` (2026-05-10) introduce `2.6.1` en el header; su padre declara `2.0.0`. `cf6b8ce` (2026-02-17) documenta la numeración interna `1.5.5`.
- No hay tags locales que acrediten publicaciones. Esto no demuestra por sí solo ausencia de distribución externa; según el responsable, nunca existió una release pública v1.0. No se ha acreditado que `2.6.1` sea una release pública.
- El README anterior decía `2.5.3`; el changelog mezclaba `2.6.2`–`2.6.5` con una supuesta versión clínica estable `1.0.0`. Estas afirmaciones se retiraron, sin modificar números productivos.
- `@since`, comentarios de migración y metadatos de fixtures describen antecedentes internos. `schemaVersion: 1.0.0` y `version: 2.0` son formatos JSON, no releases públicas.

Propuesta pendiente de adopción: usar una única versión de distribución del plugin y sincronizar header/constante/metadatos npm al preparar una publicación deliberada. Vincular release con tag Git, artefacto generado, changelog comprobable y pruebas. Mientras tanto, identificar cambios por rama/commit y mantener las numeraciones heredadas. No inferir madurez a partir de un número mayor a 1 ni anunciar una primera release sin aprobación de esa política.

## Alcance actual

Trabajar sobre `develop`; `origin/main` es la referencia de integración. `feature/*` es una convención propuesta, no una enumeración de ramas certificadas. M1 incorporó `includes/bootstrap/`; M2 incorporó `includes/auth/` y `includes/participants/`. M3 incorporó `includes/forms/` y `src/frontend/forms/`; M4–M8 siguen pendientes. No hay CI/E2E completo. Consultar [arquitectura](architecture.md) y [testing](testing.md) antes de modificar contratos.

## Cambiar el bootstrap

Mantener el manifest de ServiceLoader en orden y ejecutar sus requires desde la composición global. Las entradas `:migration` y `:survey-access` documentan límites de inicialización, no archivos. No convertir cargas actualmente universales en admin-only sin caracterización nueva.

Registrar mediante el owner adecuado y preservar callback, prioridad, accepted args y orden respecto de otros registros. Las funciones globales de compatibilidad no deben retirarse por existir una clase. Lifecycle usa `EIPSI_FORMS_PLUGIN_FILE` para conservar la identidad de los hooks de activación y la ruta de traducciones.

Los cron por estudio/asignación continúan programándose en sus recorridos de dominio. Actualizar el catálogo explícito de CronRegistry cuando se demuestre un nuevo hook propietario; no limpiar todos los nombres con prefijo `eipsi_`. [Pruebas de contratos y ciclo de vida](testing.md).

## Cambiar Auth/Participants

La semántica P1-A vive en `EIPSI_Authorization_Policy`. No agregar decisiones alternativas en handlers, shortcodes o servicios: usar sus facades de autorización y derivar participante/estudio de la sesión. Los owners Auth gestionan exclusivamente autenticación, sesiones y magic links; ParticipantRepository está limitado a participantes y consultas de contexto específicas, no es un repositorio universal.

Mantener las facades en `admin/services/`: sus firmas forman API compatible, incluso cuando no aparece un caller interno. Los adapters WP siguen validando request/nonce/capability y formando respuestas; Registration/Import/State ejecutan operaciones del dominio. Los servicios de correo, asignaciones, anonimización y Pools continúan siendo dependencias externas. No cambiar durations, cookies, tablas, columnas, action names o redirects incidentalmente.

Ejecutar las 214 regresiones anteriores y la suite M2 real descrita en [testing](testing.md). El baseline de APIs M2 procede de `develop` en `2775faa`; no regenerarlo desde el resultado de un cambio para ocultar una regresión. La allowlist M1 añade únicamente los nueve includes M2 enumerados; no excluye hooks/REST/AJAX de la comparación.

## Cambiar Forms/Submit

El adapter AJAX conserva nonce y request de WordPress (incluido su slashing). `EIPSI_Submit_Service::submit($request, $query, $server)` devuelve un resultado interno; `EIPSI_Form_Response::emit` conserva los JSON/status públicos. Capture mantiene filtros P1-C antes de persistir. El servicio no vuelve a inferir identidad: delega en la facade Auth y su Policy; el flujo anónimo mantiene identidad longitudinal cero.

El adapter Storage llama las tres funciones originales de Data Safety. El adapter longitudinal en `admin/services/` encapsula el bloque legacy extraído sin cambiar reglas, transacciones, SQL, notificaciones ni próximos eventos. No extender ese bloque en Forms; sus reglas pertenecen a M4. No borrar facades globales ni `EIPSI_Partial_Responses` por no encontrar callers internos.

Editar las fuentes ordenadas en [manifest.json](../src/frontend/forms/manifest.json), no el artifact generado. Los archivos `.js.inc` son fragmentos del mismo closure clásico; algunos contienen métodos de un objeto compartido y no son scripts independientes. No cambiar orden, globals, nombres, selectores ni convertir a imports sin caracterización adicional. El build usa terser con `compress:false` y `mangle:false`; conserva la URL/handle y localizations existentes. `npm run start` sigue siendo el watcher histórico de Gutenberg: después de editar Forms ejecutar `node scripts/build-form-runtime.js` o `npm run build`.

La fixture `tests/m3/runtime-baseline.js` es exclusivamente de pruebas; no restaurarla como fuente de producto. La composición M3 debe reproducir su hash hasta que se autorice un cambio funcional. Las pruebas comparan DOM anterior/generado y congelan límites críticos. Actualizar baselines requiere explicar qué contrato cambia; no regenerarlos para hacer pasar una regresión.
