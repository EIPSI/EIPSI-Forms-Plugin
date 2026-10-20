# External API v1

Machine-to-machine read access to EIPSI Studies. [OpenAPI 3.1](../../openapi/eipsi-external-v1.yaml) (JSON syntax, valid YAML), [scientific contract](Data%20Contract.md), [deployment/admin guide](External%20API%20Deployment%20Guide.md), [integration examples](Integration%20Guide.md), [release checklist](Release%20Readiness.md).

Use `https://your-site/wp-json/eipsi-external/v1` as the API base. Without pretty permalinks use `https://your-site/?rest_route=/eipsi-external/v1`; append additional query parameters with `&`. All timestamps are UTC; `as_of` for pinned snapshots may include fractional seconds.

Every endpoint requires `Authorization: Bearer <API key>` and an enabled API. WordPress login cookies do not authenticate API clients. API-key **scopes** are internal permissions, not OAuth scopes: `read:studies` and `read:data` are independent. **Study access** is a second check: NONE, SELECTED logical Study IDs, or explicit ALL (including future Studies). A public **Study code** is an address, never an authorization credential. Exports additionally belong to their creating API key: a replacement key cannot retrieve an old key's exports.

| Method | Path | Scope | Purpose |
| --- | --- | --- | --- |
| GET | /status | Active key, no scientific scope | Minimal authentication probe |
| GET | /studies | read:studies | Granted logical Studies, limit 1–200, default 50, cursor |
| GET | /studies/{code} | read:studies | Administrative Study projection |
| GET | /studies/{code}/data | read:data | Live JSON or small CSV |
| GET | /studies/{code}/dictionary | read:data | Historical dictionary for rows included by filter |
| POST | /studies/{code}/exports | read:data | Capture snapshot sources; returns 202 PREPARING |
| GET | /exports/{id} | read:data | State; bounded polling recovery |
| GET | /exports/{id}/manifest | read:data | READY checksums and chunk inventory |
| GET | /exports/{id}/data | read:data | Immutable JSON pages |
| GET | /exports/{id}/dictionary | read:data | Pinned dictionary |
| GET | /exports/{id}/download | read:data | CSV or NDJSON, single byte Range |

Discovery returns code/name/type/status/created_at/updated_at/archived; it does not expose responses, participant identities, definitions, conditions, seeds or randomization internals. Draft/paused/finalized/archived Studies are discoverable when granted; availability to participants is a different concern.

## Errors and headers

```json
{"code":"eipsi_external_unauthorized","message":"External API request could not be authorized.","data":{"status":401,"request_id":"synthetic-correlation-id","api_version":"v1"}}
```

`status`, `request_id`, `api_version` are inside `data`, not top-level. The deliberately generic message also covers operational failures; inspect code/status, never parse message text. 429 from the limiter may add `data.retry_after`. Log the correlation ID, never Authorization or scientific bodies. OpenAPI enumerates per-operation responses/error codes. Common 401 invalid/revoked/expired key; 403 missing scope/insecure production transport; 404 disabled API, absent/ungranted Study or unavailable export; 429 rate/quota; 503 local capacity/infrastructure. Unknown routes/methods can receive WordPress core errors outside these registered operation contracts.

| Family | Additional failures |
| --- | --- |
| Discovery list/detail | 400 invalid parameters/cursor (detail has no cursor) |
| Live data/dictionary | 400 filters/format/cursor where supported; 422 capacity |
| Create export | 400 JSON/body/query; 422 source/size capture cap; 429 quota/mutex |
| Export status | 400 unexpected query/body; terminal states are metadata in 200 |
| READY manifest/pages/dictionary/download | 409 PREPARING/FAILED; 410 EXPIRED/REVOKED |
| Snapshot pages | 400 filters/cursor |
| Download | 416 invalid/unsatisfiable range; 503 integrity/temp capacity |

All owned responses: `Cache-Control: private, no-store`, `Pragma: no-cache`, `Vary: Authorization`, `X-Content-Type-Options: nosniff`, `X-Request-ID`; 401 adds `WWW-Authenticate: Bearer`. Do not put API routes behind public caches. No browser CORS allowlist/wildcard; use a trusted server-side client.

202 and still-PREPARING polling provide `Retry-After` (default five seconds); 429 provides retry seconds. CSV live adds Content-Disposition and X-EIPSI-As-Of. Verified snapshot downloads add Content-Disposition, Content-Length, Accept-Ranges: bytes, strong quoted SHA-256 ETag; 206 adds Content-Range, 416 supplies `bytes */total`. Download headers describe the file representation, not DB storage bytes.

## Live versus snapshot

`/data`: JSON limit 1–1000, default 500; format json/csv; include_partials string `true`/`false`, default false. Cursors bind key, Study, partial filter and page position, expire after one hour. Pagination is **live**, not a global snapshot: changes between requests can change later rows/dictionary. CSV rejects cursor/limit. Dictionary only accepts include_partials. Neither supports authoring/identity/process filters.

`POST /studies/{code}/exports`: JSON `{ "format":"csv", "include_partials":false }`; format required, partial boolean optional. No query params. POST is not idempotent: do not blindly recreate on an ambiguous timeout. It captures a source manifest and DB read-view `as_of` before materializing. PREPARING → READY when hashes/chunks/dictionary commit; source change can yield FAILED rather than a newly interpreted dataset. FAILED is terminal, EXPIRED follows TTL, REVOKED follows authorization/privacy/admin invalidation. A newly requested export is a distinct snapshot.

READY bytes, JSON pages, dictionary and manifest remain immutable; access still rechecks current enabled/key/scope/grant/ownership/privacy. `DO_NOT_USE` invalidates relevant snapshots. Already downloaded bytes cannot be recalled. Revoking a key immediately rejects future requests; cleanup of artifacts is bounded and later.

JSON exports download **NDJSON** (`application/x-ndjson`), one row per line, not a JSON array/envelope. `/data` on either export format returns JSON envelopes/pages. CSV prepends study_code to the same scientific columns. Manifest checksums cover raw downloaded bytes and pinned dictionary encoding. Validate Content-Length and ETag/SHA-256 before presenting a file as complete.

Range: one `bytes=start-end`, `bytes=start-` or `bytes=-suffix`; no multiple ranges. Valid resumes return 206; malformed/out-of-file ranges return 416. Send the exact strong quoted ETag in If-Range. Other validators revert a syntactically valid range to full 200: replace the partial file, never append that 200. Validate Content-Range and final full hash. Range is parsed before If-Range; an invalid Range can still return 416 with a mismatched validator.

## Limits

Live guards: 128 StudyVersions / 4 MiB definitions; 2 MiB execution-checkpoint window, 10000 analytical rows and 8 MiB bulk payload; JSON pages also bounded. 422 is explicit; use snapshots for bulk/reproducibility. Dictionary is derived from exportable rows, not an authoring inventory of unused questions.

Snapshot fixed guards include source capture duration ten seconds, 100000 source references, bounded source hydration, 1 MiB chunks, single global worker step, bounded leases and 60-second preparation of a download stream. These are finite safeguards, not throughput guarantees. Current settings/defaults and bounds are in the deployment guide. A 256 MiB logical export budget includes references and derived representations, so a 256 MiB downloadable file is not guaranteed.

Rates per minute per key: authenticated 120, discovery 60, live data 30, live CSV/dictionary bulk 5, export creation 6, export status 90, export pages/manifest/dictionary 60, download 20. Invalid authentication: 60/min per remote-address bucket. Budgets combine. Honor 429 + Retry-After; do not tight-loop or automatically retry POST. These are internal API-key permissions, not OAuth rate tiers.

## DATA-1 canonical wide dataset

New scientific downloads explicitly request `EIPSI_STUDY_DATA_V2`: one participation × real response datetime, response variables as columns, historical dictionary and selected process annexes. V1 remains per-item when no contract is requested. The updated Python/R examples request V2. See [Canonical Dataset and Metadata Policy](../dataset/Canonical%20Dataset%20and%20Metadata%20Policy.md) for UTC, collisions, formats, filters and privacy. Private snapshots support real XLSX and JSON bundles; V1 JSON remains NDJSON.
