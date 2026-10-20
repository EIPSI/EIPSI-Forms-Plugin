# Canonical Dataset and Metadata Policy — DATA-1

## Scientific contract

`EIPSI_STUDY_DATA_V2` is a wide projection over retained scientific source records. It does not change response persistence. A main row represents one `participation_id × response_at` within a Study/cycle. Participation is the available subject identity; emails/IPs are not analytical identifiers or cross-study linking keys.

Final observations use the server-confirmed Assessment `completed_at`; explicit saved partials use their confirmed `saved_at`. `response_at` is always `YYYY-MM-DD HH:MM:SS` in **UTC**. Scheduled opportunities, deadlines and enrollment dates do not define response moments. No historical milliseconds are invented. Never-answered opportunities generate no main rows.

`variable_name` declares an analytical variable. The same name in different Assessments at different moments occupies the same column. It is never automatically prefixed. Context columns are reserved: observation_id, study_id, study_code, cycle, participation_id, response_at, study_version_id, attempt_state, response_kind.

`observation_id` is a deterministic trace identifier, not a substitute for subject/time uniqueness. Two different attempts at the same second for the same participation block the export (`RESPONSE_TIME_COLLISION`), even if values match. Repeated source cells deduplicate only when value, provenance and response status are identical. Different cells for one variable/moment block (`VARIABLE_VALUE_COLLISION`). No first/last/average/concatenation fallback exists.

Incompatible historical value families block (`VARIABLE_TYPE_CONFLICT`); missing dictionary and reserved names also block. Historical label/options changes produce warnings and retain separate dictionary variants. A successful manifest has `conflicts: []`; a conflict fails the job instead of returning incomplete data.

## Main data and annexes

| Section | Contents |
| --- | --- |
| data | Small context plus wide response columns; no automatic identity/process columns |
| dictionary | Historical variables, labels, types, options/codings, item/response keys, Assessment/Study/Native versions and definition hash |
| response_status | observation_id, variable_name and original absence/value status |
| bindings | Per-observation variable provenance, attempt, opportunity, versions and hash |
| process_metadata | Structured session/page/item/event/client records, not thousands of wide columns |
| randomization | Decisions recorded by existing Study/Assessment owners, never rerandomized |
| audit | Recorded scientific and administrative events, separate from answers |
| identities | Manual administrator-only identity annex; never accessible by external API keys |

Null response cells do not erase absence meaning. `response_status` retains VALUE_RECORDED, NO_VALUE_RECORDED, NOT_RECORDED, SKIPPED_BY_LOGIC, NOT_SELECTED_BY_RANDOMIZATION and RANDOMIZATION_UNRESOLVED as supplied by the scientific source. Multiple choice is an array of option IDs, serialized as stable JSON in CSV/XLSX cells.

Process records include observation/participation/time/attempt, Assessment/version/hash, level, entity_id, field, value and unit. Recorder timestamps/durations use milliseconds; server_completed_at is Unix seconds. Text intermediate states contain lengths, not typed text. Nested events/trajectory remain structured JSON values.

## Shared owner and filters

Manual, live API and private snapshot workers invoke `CanonicalStudyDataset` through `Source`, using existing historical ScientificDatasetOwner sources. They share pivot, dictionary, type and collision rules. Equal cut/source/filter inputs produce equal logical data; a live read and a later snapshot need not match if new answers arrived.

Canonical filters: version (StudyVersion), cycle, execution (participation), assessment, opportunity, response_from, response_to, include_partials (default false), include_retained (default true). Response bounds are inclusive UTC full seconds. Manual enrollment filters additionally delimit the participation population and are reported separately as population_filters in its manifest. DO_NOT_USE never appears; RETAIN_FOR_ANALYSIS follows include_retained. No active browser state is treated as a final response. Only partial records with authoritative saved_at already retained by the persistence owner are projected; Native session autosaves are not invented as historical observations.

## Formats and downloads

JSON bundles contain manifest and selected section arrays, preserving numbers, booleans, arrays and nulls. CSV downloads one section at a time, using the existing SEC-1 serializer; potentially dangerous spreadsheet strings are escaped, null is blank, arrays use JSON. Read CSV alongside dictionary/status to recover types and absence.

XLSX is a real OpenXML ZIP workbook with one sheet per selected section and a manifest sheet. Main data includes dictionary by default. Numeric cells are numeric, booleans typed, text inline strings (never formulas), arrays JSON strings, null blank. Datetimes are full UTC strings to avoid Excel locale/timezone conversions. A small incremental writer uses PHP ZipArchive, private temporary sheet files and bounded-memory streams, rather than adding a large spreadsheet dependency. Excel limits are enforced: 16,384 columns, 1,048,576 rows and conservative 32,767-byte text cells. Exceeding them fails explicitly. Numbers outside Excel’s safe fifteen-significant-digit representation and XML-forbidden text characters also fail explicitly; JSON preserves the full source representation.

Manual Study → Gestión → Datos y exportación lets researchers select sections, prepare an export, review counts/variables/partials/warnings and download JSON, XLSX or section CSV. Identifiers and annexes are separated; no email is automatically added to main data. Manual exports use the existing authenticated, expiring, fingerprint-checked job mechanism and a 32 MiB payload bound. Large scopes must be reduced or use API snapshots.

## Explicit external version negotiation

The HTTP namespace remains `/eipsi-external/v1`. Existing clients without negotiation retain the documented per-item V1 contract; it is not silently reinterpreted. The current manual scientific UI uses V2. V1 remains explicitly implemented for prior API consumers/tests and internal operational exports; there is no automatic data migration.

Live: `GET /studies/{code}/data?contract=EIPSI_STUDY_DATA_V2` returns EIPSI_EXTERNAL_DATA_V2 with wide items, columns, warnings and UTC. `format=csv` uses the same projection. Dictionary: the same contract query on `/dictionary` returns EIPSI_EXTERNAL_DICTIONARY_V2. Cursors are bound to key, Study, versioned contract and filters; V1 and V2 cursors cannot be exchanged. Live reads are bounded (32 MiB/10,000 observations); use snapshots above that scope.

Snapshot request example:

```json
{"contract":"EIPSI_STUDY_DATA_V2","format":"xlsx","filters":{"cycle":1,"include_partials":false,"include_retained":true},"annexes":["data","dictionary","response_status","bindings","process_metadata","randomization"]}
```

POST `/studies/{code}/exports`, poll `/exports/{id}` until READY, then use authenticated `/exports/{id}/download`. V2 `json` is one bundle JSON object; V1 `json` remains NDJSON. CSV snapshots contain main data; use JSON/XLSX for multiple annexes. Snapshot dictionary and paginated data endpoints return the matching V2 contract. Audit is explicitly selected; identities are rejected for external keys.

Snapshots reuse API-2B source hashes/as_of, leases, private chunks, checksum/ETag, Range/resume, expiry/cleanup, grants/scopes and withdrawal invalidation. XLSX is never placed in public Media Library. A schema/cell conflict terminally fails materialization; no partial workbook is published READY. Dictionary/schema size is bounded; oversized exports fail rather than silently truncating variables.

## One metadata capture policy

Configuración → Privacidad y seguridad configures a **global ceiling** without needing existing responses or a form selector. Session, page, item, client and event fields share one authoritative default map. Assessment Basic/Standard/Detailed selects a subset:

- Basic: session/page/item process without client context, event stream, focus/input method or trajectory.
- Standard: process plus permitted client/events/focus, without trajectory.
- Detailed: all globally permitted process fields, including trajectory.

Effective capture = global permission ∩ published Assessment profile. Disallowed client attributes are not read by the browser recorder; outgoing metadata is filtered and the Native server reapplies the policy. A crafted client cannot enable forbidden fields. Technical response/definition/attempt identifiers and server completion needed for scientific integrity are not optional process capture.

Collected data is stored and exportable in its annex: no separate export/retention switches. Turning a field OFF changes future captures, never rewrites/deletes historical responses. If changed mid-attempt, already captured disabled values remain frozen; incoming replacements are not accepted. New attempts omit the disabled field. LocalResponseSink has an explicit bounded adapter for permitted legacy client fields; it does not supply Native defaults.

The privacy UI no longer exposes the old response-table selector, contradictory capture/export toggles or a seven-year regulatory claim. The global “delete all clinical data” UI was withdrawn because its implementation only cleared old tables; DATA-1 never invokes destructive cleanup. Withdrawal exclusion/invalidation and existing Study data-management owners remain authoritative.

## Validation and review

Permanent DATA-1 tests cover pivot/collisions/types, partial moments, datetime, shared manual/live/snapshot output, formats, formula strings, private binary percent preservation, bounded-memory XLSX, policy intersection/backend enforcement, historical preservation and global UI defaults. Final closure additionally requires the complete CI FULL suite and strict RCT gate. Human review must verify selected formats and privacy controls in WordPress; automated checks do not count as human approval.


### Additional client fields

The global client group also supports `ip_address`, `webgl_renderer`, `screen_depth`, `pixel_ratio`, `cpu_cores` and `cookies_enabled`. These follow the same ceiling and profile: Basic excludes them; Standard/Detailed may capture them when globally enabled. Their global default follows the existing enabled defaults; each can be switched off independently. They appear as client records in process_metadata, never as subject identity or automatic main-data columns.

IP is derived on the server from the validated connection peer (`REMOTE_ADDR`, IPv4/IPv6), not from submitted metadata or forwarded headers. With a reverse proxy it represents the peer address exposed to PHP unless the server itself configures the real remote address. It records the address of the accepted command. There is no external IP lookup.

WebGL reports the browser-provided vendor/renderer; graphics access is performed only when enabled and its temporary context is released. Color depth is an integer in bits; pixel_ratio is numeric; cpu_cores is the exposed logical-processor count; cookies_enabled is a boolean capability indicator, never cookie content. Unsupported browser fields are null. The server validates types/bounds and strips fields disabled globally. Already captured historical metadata is retained.
