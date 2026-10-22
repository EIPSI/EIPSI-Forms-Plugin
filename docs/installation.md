# Installation and distribution packages

[Documentation index](README.md) · [Compatibility](compatibility.md)

## Install on WordPress

Use WordPress 7.1 or later and review the compatibility matrix before choosing PHP/database versions. Obtain a built plugin ZIP from the project maintainer or prepare it from source below. This repository does not automatically publish releases.

In WordPress, open **Plugins → Add New → Upload Plugin**, install the ZIP and activate. Then open **EIPSI Forms → Configuration** and review language, storage, SMTP, privacy and scheduler settings. Follow [first setup](user-guide/configuration.md) and test a disposable study through its public link before recruitment.

Plugin files do not contain the studies, answers or configuration from another installation. Installing the ZIP does not transfer that database.

## Build a local package from source

GitHub's **Code → Download ZIP** excludes `build/`, which is ignored by Git. It is not a ready-to-install package. There is no Composer/vendor installation step; the PHP spreadsheet library is already under `lib/`, and language catalogs are under `includes/i18n/`.

From the repository root:

```sh
npm ci --no-audit --no-fund
npm run build
node tests/ci/check-build.js
python3 -m unittest discover -s tests/compatibility -p 'test_*.py'
python3 scripts/package-plugin.py
```

Use the Node version in [gate.json](../tests/ci/gate.json); the [CI guide](../tests/ci/README.md) also explains the pinned Docker build. The output is `dist/EIPSI-Forms-Plugin.zip`, containing a single `EIPSI-Forms-Plugin/` directory. This command creates a local artifact; it does not tag, publish or upload a release.

The packager includes the plugin entrypoint, PHP services/admin/templates, generated blocks and frontend assets, language catalogs, library, downloadable API examples, readmes/license, export-directory protection files and the runtime-referenced migration helper. It excludes `node_modules`, Git, tests, caches, collected exports, source build inputs and unrelated automation/SQL helpers. Missing builds or referenced compiled assets are rejected.

One unreferenced legacy template (`includes/templates/progress-bar-component.php`) has a pre-existing syntax error on both PHP 7.4 and 8.3. It is omitted from the package; no runtime caller was found. Its source is unchanged by this documentation/compatibility task.

After building the package, run the [compatibility runner](../tests/compatibility/README.md). It installs that ZIP's extracted files in an isolated WordPress fixture, using test scripts mounted separately so tests are not shipped in the plugin. Archive construction checks do not replace a hosting smoke test.
