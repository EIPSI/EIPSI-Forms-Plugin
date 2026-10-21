# M8 — Schema / Migrations

Guarded disposable Docker only: WORDPRESS_DB_HOST=eipsi-m0-db:3306, DB_NAME=m0 and eipsi_m0_isolated_install marker. Live suites sequential within each DB. No production data, no commits, no release.

## Clean install

From plugin root, with local node_modules already installed:

```sh
docker compose -p eipsi-m8-clean -f tests/m8/clean-compose.yml up -d
docker exec eipsi-m8-clean-wordpress php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/m0/install.php
docker exec eipsi-m8-clean-db mariadb -uroot -pm0-root-isolated -e "CREATE DATABASE IF NOT EXISTS m6_external; CREATE DATABASE IF NOT EXISTS m8_external; GRANT ALL ON m6_external.* TO 'm0'@'%'; GRANT ALL ON m8_external.* TO 'm0'@'%'; CREATE USER IF NOT EXISTS 'm8_noalter'@'%' IDENTIFIED BY 'm8-noalter'; GRANT SELECT,INSERT,UPDATE,DELETE ON m0.* TO 'm8_noalter'@'%';"
```

All credentials above belong exclusively to disposable fixtures. Clean port 18209; upgrade port 18210. Independent networks/WordPress volumes and DBs; never reset previous containers/volumes. PHP helpers preserve the internal HTTP Host 127.0.0.1:18080 fixture contract.

Prepare M6's owned public export probe with exclusive creation, following ../m6/README.md. Copy for runners that write fixtures:

```sh
docker exec eipsi-m8-clean-wordpress sh -c 'mkdir -p /tmp/eipsi-m8-tests; tar -C /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin --exclude=node_modules --exclude=.git -cf - . | tar -C /tmp/eipsi-m8-tests -xf -'
docker exec eipsi-m8-clean-wordpress php /tmp/eipsi-m8-tests/tests/run-p0.php --integration
```

Run run-p1.php, run-p1b.php, run-p1c.php, run-m0.php through run-m8.php in order, adding run-m6-live.php after run-m6.php. Do not run live suites concurrently against the same DB. Preserve all 662 previous cases; 77 M8 PHP cases yield 739 total (695 PHP, 44 JS). Remove the export probe only after verifying its contents against its fixture.

```sh
docker exec -e WORDPRESS_DEBUG=0 eipsi-m8-clean-wordpress php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/m1/debug-off.php
docker exec eipsi-m8-clean-wordpress php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/m8/schema-capture.php
docker exec eipsi-m8-clean-wordpress php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/m7/lifecycle.php
docker run --rm --network none --user 1000:1000 -e HOME=/tmp -v "$PWD:/app" -w /app node:22-bookworm sh -c 'node tests/m3/source-contracts.js && node tests/m3/runtime-dom.js && node tests/m7/frontend-load.js && npm run build'
```

## Historical upgrade in a separate new environment

```sh
docker compose -p eipsi-m8-upgrade -f tests/m8/upgrade-compose.yml up -d
docker exec eipsi-m8-upgrade-wordpress php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/m8/install-upgrade.php
docker exec eipsi-m8-upgrade-db mariadb -uroot -pm0-root-isolated -e "CREATE DATABASE IF NOT EXISTS m6_external; GRANT ALL ON m6_external.* TO 'm0'@'%';"
```

install-upgrade refuses an installed WP DB. It recreates the captured pre-M8 table DDL, seeds one historical row in each of 26 domains, marks migration 9, activates and migrates to 10, then compares IDs, every original value/timestamp and structure. It leaves the owned historical rows to exercise subsequent functional suites. Run M0/M3/M4/M5/M6/M6-live/M7 sequentially on this container, using a writable copy and the owned probe for M6. Capture schema on both containers and use compare-schema.py; collation is reported without automatic conversion.

## Coverage and limits

run-m8 uses a random prefix and isolated copy of options. It preserves 14 explicit data domains plus seeded supporting tables, logical relationships and original payloads. Fixtures: pre-current version 9 missing runtime dates; intermediate version 6 with dynamic T1 VARCHAR metadata/default; early version 0 with template_id plus supported fingerprint identity. These are supported shapes, not a fabricated mapping for all installations. Unsupported participant-based historical identities stop without advancing state.

Failure tests cover SQL rejected during a partially applied v10, retry, future version, unsupported identity, actual denied ALTER privileges, and a worker killed before its second study ALTER. Two independent PHP workers synchronize through a barrier and test migration/repair with shared GET_LOCK. External tests create from zero in m8_external, execute the actual storage INSERT, verify fields/values, repair the three missing legacy columns, preserve rows and fail on a real unavailable connection.

Schema snapshots and full functional upgrade verify physical FK convergence on independent DBs. Prefix fixtures can collide with original FK names in the same database; Installer keeps the historical best-effort FK contract. No new unique policy, destructive charset conversion or universal external migration promise. The settings writer's draft contract is tested on a legacy VARCHAR status fixture; current canonical enum remains unchanged.

baseline-hashes.json/contracts.json freeze original sources/signatures from 8b91fe1. boundary-migrations.json extends the complete M3–M7 history. purge-manifest.json records removed A implementation bodies and retained B/C/D/E. global-retained-matrix.json supports the later institutional PURGA FINAL; no retained class is automatically deleted.
