# Testing

La suite actual tiene 183 pruebas: P0 22, P1-A 40, P1-B 40, P1-C 45 y M0 36.

## Ejecutar las regresiones

Desde un workspace con los contenedores de desarrollo iniciados:

```sh
docker exec wp-eco-wordpress-1 php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/run-p0.php --integration
docker exec wp-eco-wordpress-1 php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/run-p1.php
docker exec wp-eco-wordpress-1 php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/run-p1b.php
docker exec wp-eco-wordpress-1 php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/run-p1c.php
```

Cada runner devuelve código distinto de cero si falla. Los contratos, fixtures SQL y dobles están documentados en [tests/README.md](../tests/README.md).

## M0 y clean install

Seguir [tests/m0/README.md](../tests/m0/README.md) para build, creación de la instalación descartable, activación, schema, smoke HTTP e inventario ejecutable. Con ese entorno preparado:

```sh
docker exec eipsi-m0-wordpress php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/run-m0.php
```

Reactivar una instalación existente no constituye instalación limpia: esta requiere volúmenes nuevos. M0 rechaza otras bases, intercepta correo y desactiva cron automático. No cambiar esas protecciones para ejecutar contra datos reales.

## Límites y comprobaciones documentales

Nueve tests M0 verifican emisores UI sin handler: son caracterización de deuda. La suite no sustituye E2E, matriz de compatibilidad, CI ni evaluación de todos los cron. Tras cambios documentales ejecutar las cinco suites, `npm run build`, `git diff --check` y comprobar links y referencias eliminadas. Las regresiones existentes no se deben reducir para conseguir un resultado verde.
