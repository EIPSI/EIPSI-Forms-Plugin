# Compatibility and evidence

[Documentation index](README.md) · [Installation](installation.md)

## Supported declaration and measured coverage

| Component | Minimum / technical finding | Measured coverage / recommendation |
| --- | --- | --- |
| WordPress | **7.1 supported branch minimum** | 7.1 and 7.1.3: clean install, activation, full registered schema, admin/public submission smoke, lifecycle/reactivation. 7.1.2: full regression reference. Prefer the latest maintained 7.1 patch. |
| PHP | **7.4**, unchanged declaration; typed properties/arrow functions require it | Distributable PHP syntax checked on 7.4.33. Full integration reference: 8.3.35. No claim that the complete functional suite ran on 7.4. |
| Database | InnoDB transactions, JSON type/functions, locking and utf8mb4; no fully tested universal minimum | MariaDB 11.8.9 is the integration reference. Older MariaDB and MySQL are not in this matrix. |

WordPress 7.1 is a deliberately tested support floor, not a claim that an individual plugin API first appeared there. Static findings include Block API v3 (WordPress 6.3), `WP_HTML_Tag_Processor` (6.2), block metadata registration (5.8), `wp_get_environment_type` (5.5), and WordPress date/timezone APIs (5.3). No dependency on a WordPress version later than 7.1 was found. These findings invalidate the old 5.8 declaration but do not certify every earlier WordPress version or Gutenberg package combination.

Database JSON features imply a feature floor newer than WordPress's historical generic MySQL minimum. Feature introduction is not integration evidence. In particular, MariaDB's JSON alias/check behavior and MySQL's native JSON storage are not assumed interchangeable without tests. No untested database version is advertised with a ≥ badge.

PHP extensions include mysqli, OpenSSL, mbstring and ZipArchive for XLSX. PHP 7.4 support is a language declaration, not a recommendation to operate an unsupported PHP release. Use a maintained PHP version validated with your hosting configuration.

## Reproducible checks

Pinned versions/digests for the full reference stack: [gate.json](../tests/ci/gate.json). Official WordPress core archives and SHA256 pins for the compatibility matrix: [matrix.json](../tests/compatibility/matrix.json). The matrix replaces only core files in newly created disposable containers; it never downgrades a working site.

```sh
./tests/ci/run.sh build
python3 -m unittest discover -s tests/compatibility -p 'test_*.py'
python3 scripts/package-plugin.py --output .cache/compatibility/EIPSI-Forms-Plugin.zip
python3 tests/compatibility/run.py
```

The runner verifies archive hashes, observed WordPress/PHP versions, zero activation output/diagnostics, schema convergence and admin/participant smoke and the native participant HTTP suite. If the package at the path above exists, it additionally verifies the installed package on 7.1.3. Test scripts live outside that package. See [matrix details](../tests/compatibility/README.md).

A source-wide PHP audit found one unreferenced legacy template with a syntax error on both PHP 7.4 and 8.3. It is explicitly excluded from distribution, not silently counted as a successful source-wide lint. All packaged PHP files are checked; no source runtime fix was made to that unused template.

## Limits

The matrix is not a full regression run on every WordPress patch, a complete PHP-version matrix, browser testing of every editor interaction, or hosting/SMTP certification. A reported production WordPress 7.1.3/PHP 8.3 environment is context, not proof that it passed these tests. Database versions outside the pinned MariaDB environment require separate validation.

## Primary references

- [Block API metadata and versions](https://developer.wordpress.org/block-editor/reference-guides/block-api/block-metadata/).
- [HTML Tag Processor introduced in WordPress 6.2](https://make.wordpress.org/core/2023/03/07/introducing-the-html-api-in-wordpress-6-2/).
- [WordPress 7.1 server compatibility](https://make.wordpress.org/hosting/handbook/compatibility/version/7-1/).
- [WordPress 7.1.3 release](https://wordpress.org/documentation/wordpress-version/version-7.1.3/).
- [WordPress hosting recommendations](https://wordpress.org/about/requirements/).
