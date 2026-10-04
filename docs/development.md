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

Trabajar sobre `develop`; `origin/main` es la referencia de integración. `feature/*` es una convención propuesta, no una enumeración de ramas certificadas. No hay CI/E2E completo ni modularización M1–M8. Consultar [arquitectura](architecture.md) y [testing](testing.md) antes de modificar contratos.
