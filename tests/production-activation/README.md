# Production clean activation regression

Run `./tests/ci/run.sh smoke` for the real empty WordPress activation, lifecycle, and cold reactivation checks. The fixture asserts absence of plugin tables/options/events before activation; it uses `activate_plugin()` and captures output and diagnostics, checks every registered schema and the monthly recurrence, and verifies the existing-schema migration path.

`./tests/ci/run.sh php` also runs the isolated `cron-unit.php` checks (3) and schema, migration, scheduler and lifecycle regression suites. `./tests/ci/run.sh full` runs all checks together with JS/build and the fresh activation fixture. All databases are disposable and isolated by the CI runner.

The activation checks are part of `tests/ci/activation.php`. Its multiple assertions form one pipeline test per activation; reactivation and repeated smoke assertions do not increase the functional suite count.
