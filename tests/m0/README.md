# M0: caracterización sobre WordPress real

M0 usa una instalación Docker descartable propia. Sus helpers rechazan cualquier DB_HOST/DB_NAME distintos de `eipsi-m0-db:3306` / `m0` y requieren la marca `eipsi_m0_isolated_install`. No cargan el WordPress de desarrollo. Las 147 pruebas anteriores siguen usando sus fixtures con tablas temporales.

Los correos de M0 se interceptan con `pre_wp_mail`; no se envían recordatorios reales. WP cron automático está desactivado. La suite ejecuta explícitamente solo el cleanup de parciales, en la base descartable. Los fixtures de participantes/Pools se revierten con transacciones. Los formularios/page de smoke se borran al terminar.

Desde la raíz del plugin:

```sh
# Node 22; lockfile intacto. Genera node_modules/ y build/ ignorados por Git.
docker run --rm --user "$(id -u):$(id -g)" \
  -v "$PWD:/work" -w /work -e npm_config_cache=/tmp/npm-cache \
  node:22-bookworm sh -c 'npm ci --no-audit --no-fund && npm run build'

# Entorno independiente: no usa docker-compose.yml del workspace.
docker compose -p eipsi-m0 -f tests/m0/docker-compose.yml up -d
# Esperar a que WordPress haya copiado su core y creado wp-config.php.
docker exec eipsi-m0-wordpress test -f /var/www/html/wp-config.php
docker exec eipsi-m0-wordpress php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/m0/install.php

docker exec eipsi-m0-wordpress php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/run-m0.php

# Inventario por perfil; stderr conserva los diagnósticos, stdout es JSON.
docker exec eipsi-m0-wordpress php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/m0/inventory.php > /tmp/m0-frontend.json
docker exec -e EIPSI_M0_PROFILE=admin eipsi-m0-wordpress php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/m0/inventory.php > /tmp/m0-admin.json
python3 tests/m0/render-inventory.py /tmp/m0-frontend.json /tmp/m0-admin.json --output /tmp/m0-inventario.md

# Suites existentes: cada proceso devuelve 0 solo si sus tests pasan.
docker exec wp-eco-wordpress-1 php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/run-p0.php --integration
docker exec wp-eco-wordpress-1 php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/run-p1.php
docker exec wp-eco-wordpress-1 php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/run-p1b.php
docker exec wp-eco-wordpress-1 php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/run-p1c.php

# Opcional: borrar SOLO el entorno descartable M0, incluidos sus datos.
docker compose -p eipsi-m0 -f tests/m0/docker-compose.yml down -v
```

El helper install.php permite repetir activación, pero **una instalación limpia requiere volúmenes nuevos** (`down -v`, luego `up -d`). El puerto 18080 debe estar libre. Contraseñas y administrador de compose/install son fixtures públicos de uso local, sin credenciales de desarrollo/institucionales.

Ejecución verificada: PHP 8.3.35, WordPress 7.1.2, plugin 2.6.1, Node 22.23.3, npm 10.9.9, MariaDB 11. Las imágenes observadas y sus digests están en el informe. `npm ci` emitió warnings de peers React/deprecations y el build un aviso de Browserslist desactualizado; no se actualizó el lockfile.

La suite añade **36 tests**. Nueve son caracterización explícita de deuda UI→handler: verifican que sigue existiendo el emisor y falta el handler. Que pasen **no significa que esos recorridos funcionen**; cuando se restaure alguno, actualizar su caracterización por una regresión funcional. Los otros tests cubren instalación/schema, registro de acciones/cron/shortcodes/bloques, Pools con sesiones reales, weekly T1, retención de parciales, emergencia real y smoke HTTP de admin/formulario/assets. No constituye E2E completo ni prueba de todas las políticas cron.

`purge-1-manifest.json` conserva los 19 elementos A borrados, con tamaño y SHA-256 previo; no ejecuta borrados. El inventario combina declaraciones PHP tokenizadas, Reflexión y registros reales de WordPress. Las expresiones dinámicas sin resolver quedan indeterminadas; nunca se convierten automáticamente en candidatos de purga.
