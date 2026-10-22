# WordPress compatibility / distribution gate

[Compatibility policy](../../docs/compatibility.md) · [Packaging](../../docs/installation.md)

After building the plugin:

```sh
python3 -m unittest discover -s tests/compatibility -p 'test_*.py'
python3 scripts/package-plugin.py --output .cache/compatibility/EIPSI-Forms-Plugin.zip
python3 tests/compatibility/lint_php.py
python3 tests/compatibility/run.py
```

The runner downloads the official WordPress 7.1 and 7.1.3 archives (or reuses verified cached copies), checks the SHA256 pins in `matrix.json`, and replaces core in separate fresh CI projects. Every project gets its own database and is removed with its volumes in `finally`. The reference PHP/MariaDB Docker digests remain the same as the full gate. No working WordPress installation is downgraded or queried.

Each core gets clean admin activation with output/error/schema assertions, six admin/public/persistence smoke checks, the existing native participant HTTP suite, lifecycle and cold reactivation. If the local ZIP exists, its extracted files are additionally mounted as the installed plugin on 7.1.3. The test source is mounted separately at `/opt/eipsi-ci`, so the artifact does not contain tests.

`lint_php.py` syntax-checks only the PHP files selected for the package with the pinned PHP 7.4.33 image. It is syntax coverage, not a PHP 7.4 functional certification. The source-wide audit's unreferenced malformed legacy template is explicitly documented and omitted from the package.

The Actions compatibility job runs these commands. `gate.py` still uses its original full-stack reference by default; the optional core/package arguments are isolated fixture seams. Main application schemas, runtime and scientific contracts are unchanged.
