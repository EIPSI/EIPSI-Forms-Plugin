# EIPSI Forms

## Estado del proyecto

Plugin en desarrollo activo. `origin/main` es la referencia de integración; `develop` contiene la estabilización P0/P1, la caracterización M0 y la extracción del bootstrap M1 y Auth/Participants M2 y Forms/Submit M3. No se ha acreditado una rama estable para producción. Según el historial informado por el responsable, nunca existió una release pública v1.0. Las versiones heredadas del código no acreditan publicaciones.

## Qué es

Plugin de WordPress para construir formularios con Gutenberg y administrar estudios longitudinales, participantes, respuestas y comunicaciones.

## Funcionalidades principales

- Bloques de formularios, páginas, campos, escalas y consentimiento.
- Biblioteca de formularios con importación/exportación JSON y compatibilidad con formatos anteriores.
- Persistencia de respuestas, guardado parcial y almacenamiento de emergencia confirmado.
- Estudios por waves, fechas relativas a T1 y dashboard del participante.
- Sesiones de participantes, magic links, retiro del estudio y asignación a Pools.
- Servicios de correo, recordatorios, nudges y tareas cron.
- Administración y exportación de datos mediante recorridos existentes. Algunas acciones de UI siguen sin handler; consultar las limitaciones en [arquitectura](docs/architecture.md).

## Arquitectura

`eipsi-forms.php` conserva metadata y constantes y carga la composición en `includes/bootstrap/`. Registries específicos organizan hooks, assets, bloques, cron y lifecycle; las funciones globales y servicios de dominio conservan sus contratos. Coexisten recorridos actuales y legacy. M1 separó el bootstrap y M2 asignó ownership a Auth/Participants; M3 separó Forms/Submit y el runtime; M4 asignó ownership a Longitudinal; M5 y M6 asignan ownership a Notifications y Storage/Privacy/Export; M7–M8 siguen pendientes. Véase [arquitectura actual](docs/architecture.md).

La composición está en `includes/bootstrap/`; Auth y Participants tienen owners en `includes/auth/` e `includes/participants/`, con facades compatibles en `admin/services/`. Forms tiene owners en `includes/forms/`; el runtime clásico se compone desde `src/frontend/forms/manifest.json` durante `npm run build`, manteniendo `assets/js/eipsi-forms.js` como URL pública. Longitudinal tiene owners en `includes/longitudinal/`, con facades compatibles y un comando de submit; Notifications tiene owners en `includes/notifications/`; Storage, Privacy y Export tienen owners en `includes/storage/`, `includes/privacy/` e `includes/export/`.

## Requisitos

- WordPress: el header heredado declara 5.8; los bloques usan `apiVersion: 3`. La compatibilidad con versiones antiguas no está certificada. Referencia comprobada: WordPress 7.1.2.
- PHP: mínimo declarado 7.4, con acceso mysqli para las pruebas SQL. Referencia comprobada: PHP 8.3.35; no hay una matriz completa de compatibilidad.
- MariaDB/MySQL con JSON y funciones de ventana (`ROW_NUMBER()`); MySQL 8.0 o MariaDB 10.2 son el piso técnico por esas funciones, no una matriz certificada. MySQL 5.7 no alcanza para este último recorrido. MariaDB 11 es la referencia comprobada. El usuario de base necesita permisos de creación y modificación de schema durante instalación/migraciones.
- Build: Node compatible con las dependencias bloqueadas; `jsdom` admite Node 20.x desde 20.19, 22.x desde 22.12 o versiones 24+. Referencia comprobada: Node 22.23.3 / npm 10.9.9. Node/npm no son necesarios para ejecutar un build ya generado.

Los mínimos históricos declarados no equivalen a compatibilidad funcional validada.

## Instalación para desarrollo

Ubicar este repositorio en `wp-content/plugins/EIPSI-Forms-Plugin`, instalar dependencias y generar los bloques antes de activar el plugin. La activación crea/verifica el schema; usar una base descartable para pruebas de instalación. Véase [desarrollo](docs/development.md).

## Build Gutenberg

```sh
npm ci --no-audit --no-fund
npm run build
```

El proceso ejecuta `wp-scripts`, corrige referencias CSS de los metadatos y compone el runtime Forms clásico. Debe generar `build/blocks/` con 13 bloques. `build/` y `node_modules/` están ignorados por Git.

## Tests

| Suite | Pruebas |
|---|---:|
| P0 | 22 |
| P1-A | 40 |
| P1-B | 40 |
| P1-C | 45 |
| M0 | 36 |
| M1 | 31 |
| M2 | 54 |
| M3 | 80 |
| M4 | 55 |
| Total | 403 |

Comandos y límites en [testing](docs/testing.md). Nueve pruebas M0 caracterizan discrepancias UI→handler: pasar esas pruebas no demuestra que esas acciones funcionen.

## Entorno local Docker

El workspace usa `wp-eco-wordpress-1` y `wp-eco-db-1`. M0 usa una instalación independiente, `eipsi-m0-wordpress` / `eipsi-m0-db`, con puerto local 18080, correo interceptado y cron automático desactivado. [Instrucciones reproducibles y aislamiento](tests/m0/README.md).

## Ramas

- `main` / `origin/main`: referencia de integración; no implica release ni certificación de producción.
- `develop`: estabilización y desarrollo actual.
- `feature/*`: convención propuesta para cambios acotados a partir de `develop`.

## Versionado

PHP/header/constante/assets usan `2.6.1`; npm y lockfile usan `1.5.5`. Son numeraciones internas heredadas sin política pública demostrada. Se preservan sin renumeración arbitraria. Las versiones JSON describen formatos, no releases del plugin.

Política propuesta: acordar una única versión del plugin antes de publicar, alinear los metadatos de distribución y registrar cada publicación con tag, artefacto reproducible, changelog y validación. Hasta entonces, identificar trabajo por rama y commit. El número de la primera release pública queda pendiente de decisión; no se declara aquí. [Evidencia y política](docs/development.md).

## Licencia

GPL-2.0-or-later según los metadatos del proyecto. Se conserva la [licencia GPL](LICENSE).

## Estado de madurez

P0/P1, M0, PURGA 1, purga documental, M1, M2, M3, M4, M5 y M6 completados. Persisten deuda UI, recorridos legacy y límites de cron/exportación. Faltan modularización funcional M7–M8, E2E completo, CI y un proceso de releases. Las pruebas actuales no certifican preparación para producción.

Notifications tiene owners en `includes/notifications/` para policy, scheduling, queue/worker, email/templates/logs y reminders; las APIs históricas delegan conservando firmas. [Arquitectura](docs/architecture.md) y [pruebas M5](tests/m5/README.md).

M6 separa persistencia, CapturePolicy/cleanup y datasets/files/download, manteniendo facades y cobertura local incompleta. Véanse [pruebas M6](tests/m6/README.md) y [arquitectura](docs/architecture.md).


Pools and Randomization domain owners live in `includes/pools/` and `includes/randomization/`; historical callback files remain compatibility facades. See [architecture](docs/architecture.md) and [M7 tests](tests/m7/README.md).
