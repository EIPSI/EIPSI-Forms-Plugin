# Cambios del proyecto

Este archivo describe trabajo interno; no constituye un historial de releases públicas. El changelog anterior mezclaba numeraciones y fechas con afirmaciones de publicación no acreditadas, incluida una supuesta v1.0 pública. Se retiraron esas afirmaciones; el contenido anterior permanece consultable en Git.

## Trabajo actual en develop — sin release pública

- P0: persistencia de emergencia confirmada, autorización del diagnóstico parcial e identidad de sesión en retiro.
- P1-A/P1-B/P1-C: estabilización de contratos de autorización, comunicaciones y persistencia; cobertura detallada en [tests](tests/README.md).
- M0: caracterización de bootstrap/UI/cron, autorización REST de Pools, instalación limpia, build y smoke; correcciones acotadas documentadas por sus regresiones.
- PURGA 1: retiro de 19 elementos A confirmados; manifiesto en [tests/m0/purge-1-manifest.json](tests/m0/purge-1-manifest.json).
- Purga documental: README reconstruido, documentación vigente mínima y eliminación de diez documentos obsoletos. Sin cambios funcionales ni avance a M1.

Numeraciones heredadas: PHP `2.6.1`, paquete npm `1.5.5`. No se asigna una nueva versión. [Política propuesta](docs/development.md).
