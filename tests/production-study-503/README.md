# Public Study failure observability regression

`tests/run-production-study-503.php` is a guarded WordPress/MariaDB + real HTTP suite with **35 cases**, registered in `tests/ci/gate.json`.

It publishes Native Assessment and Study fixtures through their owners, then opens the actual public query URL. No execution is inserted manually for a successful entry. Cases cover anonymous/admin success, missing seed/access configuration, absent/malformed pinned snapshots and assessment references, real alias-SELECT failure with errno 1146, public generic errors, sanitized logging, deduplication, proxy HTTPS/no-store, and transversal admission through the real execution owner.

The privacy case writes the structured event to a temporary sink, verifies that exception payloads, emails, external participant IDs, responses and magic/session/capability tokens do not appear, and verifies that repeated reporting produces one event. The fixture MU-plugin is installed only in the disposable database marked by M0 and is always removed. It is not part of the installable plugin package. Temporary corruptions apply only to owned fixture records and are restored.

```sh
./tests/ci/run.sh php
./tests/ci/run.sh full
```

The optional `--baseline` path records the pre-instrumentation expectation (503 / RUNTIME_FAILURE / unavailable without server seed configuration). It is historical reproduction support and is not a passing test mode for the changed runtime. Normal CI runs all 24 current cases.

See the [operational diagnostic guide](../../docs/study-engine/public-runtime-diagnostics.md). Production keyring values are never accepted or used by these tests.

The additional keyring cases cover malformed/active-ID contracts, array and JSON constants, web environment configuration, constant precedence, safe administration diagnostics/warnings and seed rotation/loss. Access signature rotation/loss is covered by the existing longitudinal integration suite. Manual generator tests live in `tests/keyrings/generator.php` (five cases), including private permissions and refusing to overwrite a configuration.
