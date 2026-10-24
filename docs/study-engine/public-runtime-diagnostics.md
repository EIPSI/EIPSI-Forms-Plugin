# Public Study runtime diagnostics

[Documentation index](../README.md)

The `?eipsi_study=...` entrypoint reports unexpected failures through one structured `STUDY_OPERATION_FAILED` event. The participant continues to see the configured generic public message; logs do not include exception messages, SQL, answers, emails, participant IDs or credentials.

## Read a diagnostic event

The event includes the operation stage, fixed route name, HTTP method, first 20 hexadecimal characters of SHA256 of the requested public Study code, internal Study ID if resolved, stable error code, exception class, component, source method/line, stack fingerprint and database errno when available. Class namespaces are represented with dots. The stack fingerprint contains no trace arguments.

`study_id` is omitted when lookup has not completed. In particular, seed configuration is checked before the public Study lookup, so a seed configuration failure can occur even when the URL has a valid Study code. A missing Study returns an empty 404 and an info-level `STUDY_NOT_FOUND` event. Successful entries produce no failure event.

| Code / stage | Meaning and next check |
| --- | --- |
| SEED_KEYRING_UNAVAILABLE | The existing server seed-key configuration was not found or was not a complete configuration. Check its presence in this PHP/web environment. |
| INVALID_SERVER_KEYRING / SEED_KEY_UNAVAILABLE | Seed configuration structure or current key is invalid/unavailable. |
| ACCESS_KEYRING_UNAVAILABLE / INVALID_ACCESS_KEYRING | The separate longitudinal access-key configuration is missing/invalid. |
| STORAGE_FAILURE | Inspect the numeric db_errno and component; the log intentionally excludes SQL and connection credentials. |
| PUBLIC_RUNTIME_SCHEMA_UNAVAILABLE / DURABLE_SCHEMA_UNAVAILABLE | Required local runtime structures are unavailable. Inspect Schema Status; do not repair the database blindly. |
| PUBLISHED_VERSION_MISSING / STUDY_NOT_PUBLISHED / PUBLIC_RECORD_INCONSISTENT | The public record does not resolve to the required published contract. |
| SNAPSHOT_INVALID | The pinned protocol/graph is invalid. cause_code, when known, distinguishes hash, JSON and binding failures. |
| ASSESSMENT_REFERENCE_MISSING / ASSESSMENT_SNAPSHOT_INVALID | A referenced Native Assessment version is absent or its pinned content/state/hash is inconsistent. |
| EXECUTION_INIT_FAILED / SESSION_INIT_FAILED / RENDER_FAILURE | Use the component, method/line and fingerprint to locate the failing owner. |

Persistent configuration/schema failures use HTTP 500. Known transient database connection, lock/deadlock and availability errors use 503. Input, conflict, not-found and normal access handling retain their own status semantics. Neither the code nor its details are printed to the participant.

## Existing server configuration requirements

Public Studies require `EIPSI_STUDY_ENGINE_KEYRING`; longitudinal Studies additionally require `EIPSI_STUDY_ENGINE_ACCESS_KEYRING`. These are existing requirements, supplied through wp-config.php constants or JSON environment variables, not settings automatically provisioned by activation. The configuration instructions are in [server keyrings guide](server-keyrings.md).

Each has a current key ID and a map of key IDs to 64-character hexadecimal keys. The two keyrings have separate purposes. If an installation already used them, restore/retain its original keys and IDs: replacing a seed key cannot recover existing encrypted seeds, and changing access keys affects outstanding links. Do not send their values in a support report or substitute disposable fixture keys.

The initial longitudinal landing GET does not create an execution or participant session. A transversal GET can create anonymous access and an execution through the real owners. Both routes also use the existing rate limiter. No diagnostic endpoint, public debug flag, automatic key generation or fallback to a different seed was added.

## Deployment verification

1. Install the prepared diagnostic plugin ZIP while retaining wp-config.php and existing server keys.
2. Open the affected public URL once anonymously and once as the logged-in administrator.
3. Inspect the new `STUDY_OPERATION_FAILED` JSON line in the server log. Share only that structured event, not the whole debug.log, request, cookies, keyrings or participant data.
4. Confirm the identified condition before changing configuration or persisted records. If the same Study returns 200 after correcting a confirmed condition, repeat its participant smoke.

This instrumentation does not by itself establish the cause of a particular hosting incident. LiteSpeed, proxy, database settings, filesystem access and persistent-cache behavior still require evidence from that installation; no host-specific workaround is applied.
