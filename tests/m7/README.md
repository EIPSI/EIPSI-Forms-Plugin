# M7 — Pools / Randomization

Run only on the disposable Docker M0 (guarded DB host/name and installation marker). No commits, schema changes or M8 work.

```sh
docker exec eipsi-m0-wordpress php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/run-m7.php
docker run --rm --network none --user 1000:1000 -v "$PWD:/app" -w /app node:22-bookworm node tests/m7/frontend-load.js
docker run --rm --network none --user 1000:1000 -e HOME=/tmp -v "$PWD:/app" -w /app node:22-bookworm npm run build
docker exec eipsi-m0-wordpress php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/m7/lifecycle.php
docker exec eipsi-m0-wordpress php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/m7/schema-capture.php
```

62 PHP + 3 JS new tests; 597 prior tests unchanged in number. Existing P0/P1/M6 harness commands and eight M6 live tests are in ../m6/README.md. Run live suites sequentially; use the documented owned export probe only for M6, then remove it after verifying its contents. Repeated clean-install executions are verification, not additional test counts.

For fresh installation use `clean-compose.yml` with project eipsi-m7-clean. It has its own MariaDB, WordPress volume and network alias; it does not reset the old database. Port 18107 is mapped externally. Helpers intentionally use HTTP Host 127.0.0.1:18080 internally to preserve the fixture URL contract.

```sh
docker compose -p eipsi-m7-clean -f tests/m7/clean-compose.yml up -d
docker exec eipsi-m7-clean-wordpress php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/m0/install.php
docker exec eipsi-m7-clean-wordpress php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/run-m0.php
docker exec eipsi-m7-clean-wordpress php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/run-m1.php
docker exec eipsi-m7-clean-wordpress php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/run-m7.php
docker exec eipsi-m7-clean-wordpress php /var/www/html/wp-content/plugins/EIPSI-Forms-Plugin/tests/m7/lifecycle.php
```

Concurrency uses two PHP processes, a ready barrier, independent database connections and scoped GET_LOCK. The fixture rejects pre-existing IDs. Output JSON and worker directories are removed after completion. `purge-manifest.json` records the four A implementations removed and B/C/E decisions. Public symbols are frozen in contracts.json; original source hashes are retained in baseline-hashes.json. Historical postmeta and both eipsi-random.js sources remain.
