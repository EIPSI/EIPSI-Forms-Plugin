# Study Engine — Content Adapter and anonymous cross-sectional runtime (P1-E)

The new opt-in route serves a published StudyVersion snapshot, not a WordPress Page,
shortcode configuration or mutable Form Template. Existing `[eipsi_form]`, Waves,
RCT and longitudinal authentication continue through their historical owners.

## Composition and publication

`includes/study-engine/content.php` explicitly loads the P1-D foundation and content/runtime
adapters, without hooks. `runtime/route.php` is loaded only when the plugin sees
`eipsi_study`; it registers the early template handler for that request.
The route is `home_url('/?eipsi_study=PUBLIC_OPAQUE')`. It works without permalink
rewrites, per-form Pages, a full Builder or a rewrite flush. The public ID belongs to
Study; the enrollment row chooses the version for new admissions. Query parameters
cannot choose a published version or another assessment.

Internal publication uses `TemplateAdapter::fromTemplate(templateId, studyId, publicId,
versionNumber, options)` or `fromBlocks` for deliberate administrative fixtures.
The adapter produces a validated P1-B definition. `StudyVersionRepository::draft` then
`publish` compiles and freezes it through P1-C/P1-D. `PublicStudyRepository::activate`
sets OPEN/PAUSED/CLOSED enrollment and the active physical SV ID. Publication and
activation are deliberate operations, not hooks on template save. Existing executions
retain their original SV ID, including retired versions. Public URL does not change
when V2 becomes active. Neither the owner nor renderer subsequently reads the template.

`options` supports explicit P1-B `condition_rules`, `random_groups`, `assessment_schedule`,
`timezone`, and `clone_namespace`. Preserve existing fieldKey/fieldName for normal import
and edits; use an explicit clone namespace when copying questions into another logical
assessment. Missing or duplicate stable keys are errors; labels/position/HTML are never
question identity. Response keys are `R_<stable-key>`; version ownership is explicit.
Options receive pinned opaque IDs derived from source option values. Renaming a legacy
option imports a new option identity; deliberate stable option renaming needs a future
richer authoring adapter.

## Block inventory and mapping

| Current block | Mapping/status |
|---|---|
|eipsi/form-container|Assessment under one immediate Macro; legacy login/random workflow rejected.|
|eipsi/form-page|Internal GROUP + PAGE_BREAK between pages; standard visible pages only.|
|eipsi/campo-texto|TEXT for text/email/tel, NUMBER for number; unsupported formats reject.|
|eipsi/campo-textarea|TEXT, min/max length snapshot and server validation.|
|eipsi/campo-radio|CHOICE with pinned options; semantic radio controls.|
|eipsi/campo-select|CHOICE with the same pinned semantics, rendered as radio controls in this slice.|
|eipsi/campo-likert|INTEGER, custom labels/min/range; presets/reversed variants reject explicitly.|
|eipsi/vas-slider|NUMBER with min/max/step; accessible number input in this slice.|
|eipsi/consent-block|BOOLEAN + INFORMED_CONSENT role and sanitized text; explicit rule decides termination.|
|eipsi/campo-descripcion|TEXT ContentNode.|
|eipsi/campo-multiple|Unsupported: P1-B has no MULTI_CHOICE; never converted to unvalidated text.|
|eipsi/randomization|Legacy-only shortcode/config owner; reject import. Use explicit domain random groups.|
|eipsi/pool-join|Legacy-only recruitment workflow; reject import.|
|core/group|Internal MICRO GROUP, explicit stable anchor required.|
|core/paragraph, core/heading|Sanitized pinned TEXT content.|
|core/image|Pinned local MEDIA snapshot; no mutable external image URL import.|
|Unknown scientific/decorative block, core/html, raw HTML|Explicit import error; no partial publication or silent discard.|

Enabled legacy conditionalLogic must be translated deliberately into P1-B rules; import
rejects it rather than copying client-side branching. PageBreak is a directive and
creates no Screen or BlockExecution. Internal Micros preserve the existing assessment
occurrence and submission boundary.

Content snapshots include question identity, type/options/required/validation,
label/helper/placeholder/format/step, consent text, Micro structure and navigation
policy. They are canonical JSON inside Content snapshot strings, verified by SHA256;
the compiled graph includes that snapshot. A new version changing text, options,
required, PageBreak, validation or media changes the protocol/graph hash, without
mutating a published version.

Media import accepts supported local raster attachments. It copies the bytes to
`uploads/eipsi-study-media/<sha256>.<extension>`, retains attachment ID/source URL,
pinned URL/file path/hash/alt in the snapshot, and verifies the hash before rendering.
Editing/deleting the source attachment does not change the pinned copy. Missing or
modified pinned bytes cause a safe operational error. Remote assets and SVG are
rejected; no network fetch or silent mutable dependency. The copy is publicly served
image content, not a secret store. Publication failures can leave unused media copies;
institutional retention/cleanup remains an operational follow-up.

## Anonymous access and recovery

M8 migration12 adds only `eipsi_engine_public_studies`, `eipsi_engine_anonymous_access`,
and a nullable `local_storage_ref` on the existing journal. Registry has37 local domains.
No DDL is performed by the route. Both new tables and transactional Storage must use
InnoDB. A random AI ID links Study, physical SV and execution; a32-byte bearer is hashed
server-side. Raw bearer appears only in the HttpOnly cookie. Fingerprint is metadata.

The cookie name includes a Study hash, so different Studies do not share authority.
It is scoped to the WordPress base path, HttpOnly, SameSite=Lax, Secure under HTTPS,
and expires after365 days. Server recovery enforces the same age and revoked state.
The first GET reserves identity/version, issues the cookie, then calls P1-D creation
using a stable `ADMISSION_<AI>` request key and fixed creation timestamp. If creation
commits before linkage, retry with the reserved credential recovers that same execution.
Concurrent first visits before either receives a cookie can reserve separate identities;
there is no fingerprint authentication or promise of global anonymous exactly-once.

Refresh/reopening a valid cookie recovers the same execution, including FINISHED or
TERMINATED. Enrollment PAUSED/CLOSED denies new reservations and preserves existing
recoveries. New participation requires a recovery/CSRF-protected restart POST, only
when the Study policy explicitly allows it and the prior execution is terminal.
Restart derives a new random-strength bearer with HMAC of the old bearer and command
ID, reserves idempotently and reuses it on a lost-response retry. It does not reset the
old execution. Old tabs can still display their old finished result. Rotation/revocation
are explicit internal `AnonymousAccess` APIs, not public mutation actions.

`AccessContext` locks and rechecks the access row inside the owner transaction after
command claim, before domain mutation. Concurrent revocation/rotation cannot authorize
a new write with an invalid bearer; a mutation holding the access lock completes before
the revocation obtains it. Lock order is execution→access; revocation/rotation only lock
access and do not take the execution lock.

## HTTP flow and server authority

GET resolves recovery, published graph and `ExecutionOwner::view`. Only NextAction
can offer content. The renderer receives the owner's presentation/effective order,
filters SKIPPED_BY_RULE coverage, and displays the current durable page. It does not
redraw randomization or consult legacy conditional attributes.

POST actions are partial, next, back, submit, refresh and explicit restart. They carry
execution/block references, typed response keys, command ID, expected revision and
Study-scoped CSRF HMAC. Recovery determines execution server-side; posted references
must match. All mutation targets must match NextAction, occurrence, eligibility and
window under the P1-D owner lock. HTTP cannot dispatch arbitrary engine commands,
advance time to a client-selected instant, withdraw, change timezone or emit notifications.

ResponseAdapter maps strings to strict TEXT/INTEGER/NUMBER/BOOLEAN/CHOICE values,
checks snapshot options/bounds/length/email/step and offered identities. Empty controls
represent not-yet-answered questions; required coverage is enforced on next/submit.
A blank control does not erase an already saved response in this slice: change its value
explicitly or reopen the stored state. A future explicit answer-clearing contract must
also define what happens to committed conditions; it is not silently inferred here.

SAVE_PAGE reuses the pure engine ANSWER implementation in effective order, skips
identical stored values, stops on termination and respects immediate jump coverage.
NEXT_PAGE saves and validates the page before advancing within bounds. PREVIOUS_PAGE
only changes the presentation cursor when protocol allows it, without re-answering or
re-randomizing. SUBMIT_PAGE saves current offered answers, then invokes the existing
assessment submit validation. No additional assessment occurrence or submission is
created by an internal Micro or PageBreak. Existing P1-C commands/scenarios retain their
original outputs.

For HTTP mutations the owner advances to server UTC only after command claim. The
idempotency hash describes the original HTTP/domain request plus server-clock mode,
not a fresh timestamp, so retry returns the original receipt. The route then resolves
current authoritative state again; cached NextAction is a receipt, never current authority.
Stale tabs receive409 and a server-rendered current state. WAIT includes a protected
availability refresh POST; GET is a read projection and does not advance a frozen clock.
FINISHED, TERMINATED, WITHDRAWN and operational error have distinct safe screens.
No dashboard/login/email is required. Errors never render a successful receipt.

The HTML form works without JS via explicit Save/Next/Back/Submit and POST→303→GET.
The small separately built JS enhances change autosave, serializes requests, sends
bounded screen/fingerprint metadata, restores focus, and adopts new server HTML/command
IDs after acceptance. Network failure retains the same request ID/form for retry and
shows that saving was not confirmed. It stores no recovery authority in localStorage.
Labels, fieldsets, keyboard controls, visible error association and focus after navigation
are included; this is not an exhaustive visual/WCAG certification. VAS/select semantic
controls are intentionally simpler than the legacy widgets. CSS is minimal/mobile-safe.
Future item metadata can consume `eipsi-study-interaction` and `eipsi-study-presented`
events; no per-item telemetry database or enhanced event model is implemented.

## Storage boundary and metadata

`LocalResponseSink` implements the optional P1-D FinalResponseSink interface; the owner
calls it in the same raw-mysqli transaction before journal confirmation and completion
persistence. It uses the existing `vas_form_results` columns and existing privacy capture
filter, without changing legacy Storage or its external/emergency fallback behavior.
WORDPRESS_LOCAL is an explicit published StudyVersion policy; global legacy external
Storage settings do not reroute this slice. It rejects non-InnoDB or failed local storage;
there is no emergency false success or automatic external write.

Stable SJ ID, SV/SE/BE IDs, canonical occurrence and skip coverage are stored in existing
metadata JSON, avoiding invasive legacy response schema changes. The journal records
`WORDPRESS_DB_<row-id>` and confirms local storage within the same commit. The execution
checkpoint retains working scientific answers needed by the engine; the Storage row is
the durable export projection. Events/journal do not duplicate answer payloads. The
execution command lock/idempotency prevents duplicate Storage rows. Failure after the
Storage INSERT rolls back Storage, journal, responses, blocks/events, revision and command.

Device/browser/OS, screen width/height, fingerprint (metadata only), UTC start/end,
duration and existing JSON metadata are collected and filtered by existing privacy
settings. Start/end fields use server/domain UTC; duration is server seconds. No enriched
per-question timing is claimed. External replication/reconciliation remains the P1-D
foundation and a future integration; no distributed transaction/exactly-once promise.

## Security, keys and cache

Provide a dedicated keyring outside DB through server configuration:
`EIPSI_STUDY_ENGINE_KEYRING` constant or environment JSON with `current` and `keys`,
where each key value is64 lowercase hex characters (32 random bytes). Generate secret
bytes server-side and keep them out of chat, repository, DB options and logs. Preserve
historical keys for existing executions; rotation selects a new current ID and retains
old decryption keys. Missing key configuration/old key causes503; no insecure default
or SMTP/participant password key derivation. Fixtures use a public disposable test key,
only within the isolated WordPress configuration, restored after the HTTP suite.

Recovery+scoped CSRF HMAC+command ID+expected revision are required. Cross-origin POST
Origin is rejected when supplied; the CSRF check protects clients without Origin.
SameSite and HttpOnly supplement those checks. Responses use no-store/private/no-cache,
Vary Cookie/Accept, same-origin referrer and nosniff. DONOTCACHEPAGE is set as soon as
this opt-in plugin request is loaded. Reverse-proxy/CDN/advanced-cache configurations
that answer before WordPress must exclude `eipsi_study`; application headers cannot
invalidate a third-party cache already serving a response.

Local per-IP/Study transient limits per minute:20 creations,120 reads,240 partial/navigation
mutations,40 submits. Counters are bounded best-effort abuse controls, not atomic DDoS
protection; institutional proxy policy remains separate. Tokens/request bodies/answers
are not logged. Operational logs include sanitized public Study, command, available opaque
execution and a safe error code/class. Public ID is public; access/reference errors are
generic and do not enumerate another execution or expose database/crypto diagnostics.

## Local manual smoke

1. Configure the server-only keyring and run the normal verified migration/activation.
2. From the plugin checkout, use administrative WP-CLI:
   `wp eval-file tests/study-engine/cross-sectional/create-demo.php branch restart`.
   It publishes from a real Form Template and prints its template ID, version and URL.
   `simple`, `pages`, `consent` and `random` are additional deliberate demo variants.
3. Open the printed URL in a clean browser. It should show page1 with real controls.
4. Answer q1, wait for “Avance guardado”, refresh and confirm it remains filled.
5. Choose No for q3. The immediate domain rule jumps to q8 on page2; q4/q5 are absent.
6. Answer q8 and submit. Confirm completion, not a dashboard/login.
7. Reopen the same URL and confirm the same completed result.
8. Click the explicit new-participation button only if enabled by the demo policy.
9. With the `pages` variant, exercise Next/Back/refresh and required errors; with two tabs,
   save in one and submit stale state in the other to check409 recovery.
10. With `consent`, No terminates because of the explicit rule; Yes continues.
    With `random`, reload preserves order. Do not claim another execution must have a
    different order: independent random draws can legitimately coincide.

For an existing compatible template, use
`wp eval-file scripts/study-engine/publish-cross-sectional.php TEMPLATE_ID LOGICAL_STUDY_ID PUBLIC_ID VERSION_NUMBER`.
This minimal helper has no implicit conditions/randomization: use the internal API with
explicit P1-B options for those contracts. Editing a template does not publish it.
For V2 use the same logical/public IDs, a new version number and the explicit rules for
that version. Prior browser recovery remains on V1; a clean browser enters V2.
Neither helper adds a Builder UI or publishes on template save. Use disposable local
Studies for this smoke; delete them through a separately reviewed administrative cleanup.

## Verification and limits

P1-E adds44 Content/response contract tests,66 actual WordPress/MariaDB/HTTP tests and
10 DOM tests:120 unique cases. Confirmed FULL1560 unique tests (1482 PHP+78 JS), FAST1552;
rechecks, aliases, RCT strict repetition and cross-version runs are not new cases.
Five HTTP flows compare every command result/state/events/random/condition/cursor/next/
outcome against the same pure Simulator. Actual HTTP tests cover version pinning,
recovery, CSRF/Origin, stale tabs, partial/page/branch/termination/random, Storage+journal,
rollback, actual SQL failure, missing media/corruption, cache headers and rate limiting.
PHP7.4 syntax and original cross-version snapshots are checked separately; DB/HTTP is
validated in the pinned PHP8.3/WordPress/MariaDB gate. No visual browser certification,
external datasets/themes institutional certification, SMTP, notifications, longitudinal
login/entry, privacy cleanup, withdrawal integration, B1/B2 or full Builder is claimed.
