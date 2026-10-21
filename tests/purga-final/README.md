# PURGA FINAL post M1–M8

Baseline develop f9dac15: 739 existing cases (695 PHP + 44 JS). Adds 15 regression/characterization cases: **754 total**. No new functionality, schema policy, release or commit.

[Deletion manifest](deletion-manifest.json) records four A elements with original hashes and owners. [The final 70-row matrix](../m8/global-retained-matrix.json) records each prior decision, owner, consumers, historical-data limit and renewed evidence, plus UI/Auth debt. [Public signatures](public-contracts-before.json) include inherited methods and seven lazily loaded facades. [Consumer catalog](public-consumers.json) distinguishes qualified calls from untyped object/self/static candidates; candidates are not proof of a typed caller. Complete executable captures are preserved with the workspace report.

Run from the plugin root. Disposable Docker only: DB_HOST=eipsi-m0-db:3306, DB_NAME=m0, isolated marker, mail intercepted. Clean/upgrade use independent networks, volumes and databases; ports 18211/18212. Never reset an existing environment to simulate a new install.

```sh
docker compose -p eipsi-purga-final-clean -f tests/purga-final/clean-compose.yml up -d
docker exec eipsi-purga-final-clean-wordpress php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/m0/install.php
docker exec eipsi-purga-final-clean-db mariadb -uroot -pm0-root-isolated -e "CREATE DATABASE IF NOT EXISTS m6_external; CREATE DATABASE IF NOT EXISTS m8_external; GRANT ALL ON m6_external.* TO 'm0'@'%'; GRANT ALL ON m8_external.* TO 'm0'@'%'; CREATE USER IF NOT EXISTS 'm8_noalter'@'%' IDENTIFIED BY 'm8-noalter'; GRANT SELECT,INSERT,UPDATE,DELETE ON m0.* TO 'm8_noalter'@'%';"
docker compose -p eipsi-purga-final-upgrade -f tests/purga-final/upgrade-compose.yml up -d
docker exec eipsi-purga-final-upgrade-wordpress php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/m8/install-upgrade.php
docker exec eipsi-purga-final-upgrade-db mariadb -uroot -pm0-root-isolated -e "CREATE DATABASE IF NOT EXISTS m6_external; GRANT ALL ON m6_external.* TO 'm0'@'%';"
```

Prepare the owned M6 export probe with exclusive creation and remove only after verifying its original bytes; follow [M6](../m6/README.md). Copy runners that write fixtures; leave the installed plugin mount read-only:

```sh
docker exec eipsi-purga-final-clean-wordpress sh -c 'mkdir -p /tmp/purga-final-tests; tar -C /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin --exclude=node_modules --exclude=.git -cf - . | tar -C /tmp/purga-final-tests -xf -'
docker exec eipsi-purga-final-clean-wordpress sh -c 'set -e; for suite in p0 p1 p1b p1c m0 m1 m2 m3 m4 m5 m6 m6-live m7 m8 purga-final; do if [ "$suite" = p0 ]; then php /tmp/purga-final-tests/tests/run-$suite.php --integration; else php /tmp/purga-final-tests/tests/run-$suite.php; fi; done'
docker exec -e WORDPRESS_DEBUG=0 eipsi-purga-final-clean-wordpress php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/m1/debug-off.php
docker run --rm --network none --user 1000:1000 -e HOME=/tmp -v "$PWD:/app" -w /app node:22-bookworm sh -c 'node tests/m3/source-contracts.js && node tests/m3/runtime-dom.js && node tests/m7/frontend-load.js && npm run build'
```

On upgrade, prepare the same writable copy and run M0/M3/M4/M5/M6/M6-live/M7/Purga sequentially. The **401** upgrade cases are repeated validation, not added to 754. `install-upgrade.php` rejects existing installations, activates/migrates the captured pre-M8 schema and checks original values/timestamps in all 26 seeded domains.

```sh
docker exec eipsi-purga-final-clean-wordpress php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/m7/lifecycle.php
docker exec eipsi-purga-final-upgrade-wordpress php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/m7/lifecycle.php
docker exec eipsi-purga-final-clean-wordpress php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/purga-final/smoke.php
docker exec eipsi-purga-final-clean-wordpress php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/purga-final/capture.php
docker exec -e EIPSI_M0_PROFILE=admin eipsi-purga-final-clean-wordpress php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/purga-final/capture.php
docker exec eipsi-purga-final-clean-wordpress php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/m8/schema-capture.php > /tmp/purga-clean-schema.json
docker exec eipsi-purga-final-upgrade-wordpress php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/m8/schema-capture.php > /tmp/purga-upgrade-schema.json
python3 tests/m8/compare-schema.py /tmp/purga-clean-schema.json /tmp/purga-upgrade-schema.json
```

Repeat smoke and inventories on upgrade. M0 exercises real HTTP admin/schema, basic form, unauthorized AJAX/REST and assets. Purga smoke additionally invokes all nine shortcodes and 13 blocks. New tests assert A absent, public class/global signatures, hook priority/order, active includes, asset dependencies/body hashes, cron shape and retained F/E characterization. Forms JS request-time cache version is normalized while its bytes are independently frozen. Privacy Dashboard cache version must equal the current checkout filemtime; only that timestamp is normalized when comparing against the historical inventory. The complete M3–M8 hash history is preserved through a new explicit boundary entry.

Empty fixtures do not establish absence of institutional content, metadata, themes, integrations or external databases. Existing guarded historical fallback paths are not removed or repaired in this phase. See [retained compatibility](../../docs/legacy-compatibility.md).

El caso cron ejecuta primero una petición HTTP frontend: el worker se programa en `wp`, mientras activación programa 13 eventos. Solo después se comparan los 14 eventos del baseline. No se cambia scheduling para satisfacer el test.
