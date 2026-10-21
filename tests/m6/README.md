# M6 — Storage / Privacy / Export

Ejecutar solo contra el Docker descartable M0: DB_HOST=eipsi-m0-db:3306, DB_NAME=m0, marca eipsi_m0_isolated_install. Nunca ejecutar runners contra desarrollo/producción. El harness P1 usa tablas con prefijo aleatorio y stubs WordPress; el runner live usa WordPress real, HTTP, workers y conexiones independientes. Correo interceptado. Suites live secuenciales, porque restauran opciones y fixtures compartidas.

## Comandos reproducibles

Desde la raíz del plugin:

```sh
docker compose -p eipsi-m0 -f tests/m0/docker-compose.yml up -d
docker exec eipsi-m0-wordpress php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/m0/install.php
docker exec eipsi-m0-db mariadb -uroot -pm0-root-isolated -e "CREATE DATABASE IF NOT EXISTS m6_external; GRANT ALL ON m6_external.* TO 'm0'@'%';"
```

Las credenciales anteriores son únicamente las públicas del fixture Docker. `m6_external` no contiene información real. La fixture crea sus tablas con el schema vigente y elimina exclusivamente su prefijo aleatorio. Se prueba el adapter externo contra una tabla válida, no una migración externa.

Para los runners que generan archivos, copiar el código a un directorio temporal escribible sin node_modules ni .git:

```sh
docker exec eipsi-m0-wordpress sh -c 'mkdir -p /tmp/eipsi-m6-tests; cd /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin; tar --exclude=./node_modules --exclude=./.git -cf - . | tar -xf - -C /tmp/eipsi-m6-tests'
docker exec eipsi-m0-wordpress php /tmp/eipsi-m6-tests/tests/run-p0.php --integration
docker exec eipsi-m0-wordpress php /tmp/eipsi-m6-tests/tests/run-p1.php
docker exec eipsi-m0-wordpress php /tmp/eipsi-m6-tests/tests/run-p1b.php
docker exec eipsi-m0-wordpress php /tmp/eipsi-m6-tests/tests/run-p1c.php
docker exec eipsi-m0-wordpress php /tmp/eipsi-m6-tests/tests/run-m6.php
```

Para HTTP, crear el probe sin sobrescribir archivos existentes y retirarlo al terminar. Esto copia solamente texto no sensible del fixture y no borra exports históricos:

```sh
python3 -c "from pathlib import Path; p=Path('exports/m6-public-probe.csv'); f=p.open('x'); f.write(Path('tests/m6/fixtures/m6-public-probe.csv').read_text()); f.close()"
docker exec eipsi-m0-wordpress php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/run-m6-live.php
python3 -c "from pathlib import Path; p=Path('exports/m6-public-probe.csv'); assert p.read_bytes()==Path('tests/m6/fixtures/m6-public-probe.csv').read_bytes(); p.unlink()"
```

El runner live usa IDs de fixture 993603/993607, verifica colisiones, limpia sus registros, restaura configuración externa y retira su archivo personal privado. La aprobación concurrente prueba un único ganador; no garantiza recovery de un proceso que muere en processing. Cleanup + writer confirma persistencia de la respuesta y declara cobertura incompleta; puede quedar captura posterior al cleanup. Fallback y emergency concurren con IDs distintos. Filenames se reservan en dos procesos independientes.

Ejecutar M0–M5 secuencialmente con `tests/run-m0.php` hasta `tests/run-m5.php`; añadir `docker exec -e WORDPRESS_DEBUG=0 ... php .../tests/m1/debug-off.php`. Para build y JS:

```sh
docker run --rm --network none --user 1000:1000 -e HOME=/tmp -v "$PWD:/app" -w /app node:22-bookworm npm run build
docker run --rm --network none --user 1000:1000 -v "$PWD:/app" -w /app node:22-bookworm sh -c 'node tests/m3/source-contracts.js; node tests/m3/runtime-dom.js'
```

Requiere node_modules ya instalado; no hace CI ni release. Build produce 13 directorios de bloques y recompone el runtime clásico. Para clean install usar el compose M0 con otro project, nombres de containers y puerto, volúmenes nuevos y alias de red eipsi-m0-db; nunca bajar/eliminar el volumen existente. El informe M6 incluye el compose concreto utilizado y sus logs.

## Evidencia y contratos

`baseline-hashes.json` congela 18 archivos del HEAD 832ee29; `contracts.json` congela funciones y APIs públicas anteriores. `boundary-migrations.json` conserva el encadenamiento M3→M4→M5→M6. M1 mantiene todas las comparaciones originales y permite únicamente los nuevos includes de definición, dos hooks de descarga y el path de XLSX equivalente. No se renumeran ni sustituyen las 507 pruebas anteriores.

`purge-manifest.json` registra las eliminaciones A, hashes y facades C retenidas. LONG conserva deuda: los dos botones no tienen handler y el dataset esperado no se define inequívocamente; se comprueba que no aparece un alias ficticio. La protección HTTP se demostró en Apache; Nginx necesita regla equivalente.

La prueba del IV usa valores deterministas con `:` final y `::` interno: se conserva el formato original base64(IV binario de 16 bytes + :: + ciphertext), corrigiendo únicamente la lectura por longitud fija.
