# Native Assessment: Product & Runtime

Human product review remains pending. No Study Composer or protocol scheduling changes.

## Library and authoring

Open `/wp-admin/admin.php?page=eipsi-assessment-editor`. Rows offer Edit, Duplicate and Delete. Edit retains the same draft and revision. Duplicate regenerates assessment, element, response, option, matrix row, VAS label and page identities while preserving scientific content, variable names and counters. Delete requires a named confirmation and exact revision; any published snapshot blocks deletion, including retired snapshots. Associated admin partial state is removed across users.

Page headers and the completion card have contextual inspectors. Completion sits after the main Assessment container and is not a scientific page. Select the main container for global settings. Back/Next/Finish empty labels use defaults. Likert and Matrix share compact option cards; labels appear above radio controls and analytical values remain hidden from participants. Rich editing retains WordPress TinyMCE and Media Library with a focus-only icon. Metadata profile cards explain collection; diagnostics default to human Assessment/Page/Question views with optional technical JSON.

## Visual editing and design

Authoring multiline fields start at one row and grow with content/wrapping instead of offering a mouse resize handle. Formatted fields remain directly editable after applying the rich-text modal; repeated modal edits retain content. Inline paste is sanitized before insertion.

Page title and description have separate, small contextual controls, visible on hover or keyboard focus (always visible on touch). Each opens its field within the page rather than the inspector. Completion is a single rich-text editor in the Canvas supporting headings, paragraphs and images. Editing existing completion content folds the previous title and image into that one HTML field without duplicating them; old immutable publications remain unchanged.

Navigation copy uses plain labels with default text in placeholders. Session duration and its unit share one row. **Diseño** in main-container settings adapts Clinical Blue, Minimal White, Warm Neutral and Serene Teal; authors can adjust colors, font family/size, width, interior spacing, question gap, rounded borders, shadow and navigation at the edges or centered. Preview and participant use the same design renderer. Without Back, the forward action remains on the right in the default edges layout.

Design is an optional, strictly validated `flow.design` object. Opening historical definitions does not inject it or change their immutable hash. Explicit edits persist design in the draft; publish a new version to apply it to new executions. CSS injection, unknown fields/enums and out-of-range dimensions reject at the backend. It does not alter question identities or analytical answers.

Property groups are collapsible with consistent heading sizes; the unused Design placeholder has been removed from the left palette. The session window field is compact and accepts up to four digits, leaving space for the unit selector. Existing stored windows are not silently rewritten.

The rich-text modal provides **Editar HTML / Volver a visual** and **Insertar o editar enlace** in both TinyMCE and the fallback editor. HTML source can be saved directly; it is sanitized with a presentation-only allowed-content contract. The embedded link panel accepts URL and label, retains selected rich text and bookmarks, supports changing an existing URL, rejects unsafe schemes and offers cancellation. The explicit link panel remains available alongside the native TinyMCE link control. Canceling the outer modal leaves the original content unchanged.

VAS value-position and value-size controls appear only while **Mostrar valor** is checked; toggling it preserves the hidden settings. Simple Likert and Matrix omit the redundant preset/reverse scale section; shared option labels and analytical values remain editable. Existing stored presets/reverse flags retain their runtime meaning.

For Matrix, the required checkbox acts as a master control: checking it marks every item required; clearing it makes every item optional. Individual changes remain authoritative. A mixed set displays an indeterminate master. When customizing a historical globally required Matrix, the old global requirement is first materialized into row flags, then the selected exception is applied and the global flag cleared. This preserves requirements on other rows and uses the existing backend validation without changing immutable historical versions. New items inherit an active all-required master.

## Preview recovery controls

Controls are grouped by purpose. The recovery group now offers elapsed time outside the questionnaire (number and minutes/hours/days/weeks/months), **Salir del cuestionario** and **Volver al cuestionario**. Exiting suspends the questionnaire; returning evaluates elapsed time and the configured session window. This is session retention, not a response time limit.

| State on return | Partial saving | Result |
| --- | --- | --- |
| Session still valid | OFF or ON | Same page and answers |
| Session expired | OFF | First page, empty answers |
| Session expired | ON | First page, empty answers; previous partial retained separately |
| Already completed | OFF or ON | Completion screen |

Expired saved partials are never loaded into the new session. The notice reads: “Las respuestas parciales de la sesión anterior quedaron guardadas.” Preview records remain simulation memory only.

## Publication and execution

Save then use **Publicar versión**. This captures the exact revision into an immutable definition/hash and pins local raster media by content hash. Remote images reject publication; no remote fetch is performed. Later draft or original media changes do not change the published version. Retired versions reject assignments/access and retain references.

**Preparar ejecución para mi sesión** assigns a real native execution to the current WordPress user. Open its participant link while authenticated as that user. Possession of its NX identifier grants no access. Native participant rendering uses the same controls and native validators as Preview, with backend validation authoritative. All native types, Matrix rows, pages, progress and completion persist through this route. After completion, **Exportar respuestas guardadas** downloads the real final tidy CSV. Preview export remains a separate simulation and stores no participant responses.

The admin API also supports bindings to existing Study anonymous or longitudinal identities, reusing their existing sessions, revocation and data policy. Native execution owns its own completion; it does not advance or replace an old Study protocol. Old definitions continue through existing Engine routes. No enrollment/email-only access mechanism is introduced.

## Storage, recovery and metadata

`Runtime\Owner` owns three InnoDB tables: native versions, executions and command receipts. Explicit admin publication installs these tables idempotently. They use the WordPress database and existing SQL driver; failures do not silently reconnect or fall back to mutable drafts. `PartialProgress` remains an independent admin-preview service.

A temporary questionnaire session stores answers, touched flags, page and metadata regardless of the partial-saving checkbox. The existing `save_continue.window` controls its inactivity window independently of `save_continue.enabled`; enabled now controls archival of partial responses on expiry. Published definition hashes/schema remain unchanged.

Visible participant activity is batched through a heartbeat; page transitions and explicit exit also refresh the window. Returning while valid restores the same session. At expiry the original execution resets to page one with empty answers. With partial saving enabled, the last server-confirmed answers become a separate `PARTIAL_SAVED` execution record, including metadata, immutable provenance and source execution ID. Such records cannot be executed or resumed. With saving disabled, temporary answers are discarded. No new table or authentication mechanism is introduced.

Expiry is enforced on reads/mutations and bounded cleanup. Late submissions return a reset without accepting stale answers. Cleanup uses the existing owned hook; physical archival timing depends on WP-Cron or a subsequent access. Final submission atomically stores final answers/metadata and clears temporary session state; earlier archived partials remain distinct. Restart preserves the previous final response. Authentication expiry is independent and still requires valid access.

Existing WordPress session tokens or Study sessions are rechecked under locks. Mutations require the applicable nonce/CSRF plus exact version and revision. Two tabs cannot silently overwrite; exact command retries are idempotent. Responses to ambiguous network failures remain visible with an exact retry. There is no offline durable queue. Abrupt closure or network failure can lose changes not yet confirmed by the server; partial archival does not promise recovery of unsent input.

One `NATIVE_PROCESS_V1` recorder supplies Preview and participant collection. Page transitions, partial save, final submit and best-effort visibility flush batch metadata, without per-movement requests. Recovery preserves history and adds a resume marker. Basic/Standard/Detailed contracts, 128 trajectory states per item, 2048 global events and ingestion volume limits are enforced. Text intermediate state contains length/timestamps, never raw intermediate text or keys. Client times describe observations, not proof of identity. Final answers use ordinary answer storage.

Exports include archived partials and final responses with separate execution IDs and response_status/saved_at columns. Exports preserve variable_name, actual Multiple arrays, selected Likert IDs and numeric/label-fallback values, individual Matrix row values and native immutable version/execution provenance. Optional binding timepoint remains a separate dimension. Existing Study DO_NOT_USE cleanup erases linked native state inside the parent cleanup transaction; deleting an assigned WordPress user erases their native state. Study policy may conservatively prevent native export.

## Review and limitations

The disposable local demo installer is `tests/assessment-editor/install-product-review.php`; it blocks mail and removes only the exact obsolete disposable source from the preceding phase. The full 60-step review checklist and evidence are in the dated Product Runtime Completion report. Review in your real browser remains mandatory. Automated DOM tests do not certify typography, TinyMCE interaction or product acceptance.

Deployment still requires appropriate session/HTTPS configuration, working WP-Cron, backups and institution-specific checks. Published snapshots and pinned media are retained; automatic destructive media garbage collection is not provided. Study Composer, protocol-level randomization, branching, reminders and longitudinal scheduling are outside this phase. Local Assessment randomization is implemented below.

General configuration includes **Mostrar título de la evaluación**, enabled by default. Optional boolean `flow.show_title` controls the heading in Preview and the participant runtime; the Canvas title remains editable. Missing values retain historical visible behavior without adding a key during validation, preserving published snapshot hashes. Publish a new version to apply a changed setting to the participant runtime.


## Rich typography and compact property layout

The WordPress TinyMCE 4 editor exposes semantic bold/italic/underline/strike, font family/size, foreground/background color, alignment, lists, indentation, quotation, sub/superscript, headings, code, links, images, character insertion, undo/redo, clear formatting and fullscreen. The existing explicit HTML and URL modes remain. TinyMCE menus/dialogs render above the modal overlay. No third-party editor/CDN dependency is added.

The browser and PHP sanitize presentation styles with the same allowlist: font family/size/weight/style, decoration, alignment, text/background colors, line height, indentation and vertical alignment. URLs, executable CSS, positioning, event attributes and unsupported embeds remain disallowed. WordPress KSES remains the server authority. Span-based formatting is recognized as rich content so it is not converted back into an unformatted textarea. Bold and italic have explicit participant/Canvas CSS. Unsupported historical styles were already removed from saved definitions; existing published canonical snapshots remain valid.

The session window has its long explanation on its own row, followed by compact **Duración** and **Unidad** labels and fields. Accessibility labels retain their full names. Likert/Matrix option cards pair each description with its field in two columns (Etiqueta + text, Valor + analytical value), with actions on a separate wrapping row.

## Local Randomization element

Add **Randomización** from **Estructura**. It behaves as a container for questions, content, groups and other Randomization containers. Drag/drop, moving out, reordering and duplication use the same native tree operations; ancestor cycles reject and depth remains capped at 8. Canvas shows a dashed boundary and a short selection/order summary; properties expose **Ordenar todos aleatoriamente** or **Seleccionar una cantidad**, then **Mostrar N de X** and original/random order. Groups are selected atomically. PageBreak stays at Assessment level, including when nested through a group. Empty all-mode containers can be authored; selecting N requires 1 <= N <= X and invalid values reject persistence/publication.

`randomization` is a structural V1 element with `label`, `mode` (all/select), `count` (integer 1–300) and `order` (original/random). It adds no analytical response key or option. All-mode ignores count/order and shuffles eligible children around the fixed children described below. Select-mode chooses N distinct stable IDs; original-order presents the chosen subset in authored order. Labels never affect decisions. Each block has an independent ID-derived seeded stream, so nested composition does not consume a parent stream. Definition fields stay independent of any future condition engine; no conditions are implemented here.

Preview and the productive participant share `randomization.js`, `PreviewSession` and the same resolved page/renderer path. The PHP companion implements the exact algorithm and is checked against twelve shared vectors: FNV-1a over ASCII seed + colon + stable block ID, xorshift32 Fisher-Yates over IDs sorted with binary/ASCII order, bounded rejection sampling. Production seeds use 32 cryptographically random bytes; the shuffle is reproducible, not a cryptographic security mechanism. The definition and original immutable hash never change: a separate resolved view projects selected containers to ordinary groups for validation/render/export.

Production resolves under the assignment transaction, once per native execution. `executions.metadata_json.randomization` stores `LOCAL_RANDOMIZATION_V2` for new resolutions (stored V1 results remain readable), seed, resolution timestamp (Unix milliseconds) and a map keyed by block ID containing candidates, selected IDs in original order, and final display order. The execution row supplies execution/version association. All nested blocks are resolved once, including those inside an unselected ancestor; ancestor decisions determine whether those nested results are ever presented, and process metadata records actual presentations. No table/schema migration is needed. The seed has no normal authoring control.

Frames expose the original definition plus the authoritative resolution. Answer validation, metadata ingestion, partial archival and final analytical export use the resolved view; excluded question answers/process identities reject. Client provenance cannot replace the stored decision. Back, next, refresh, re-render, recovery and even expiry/reset of answers within the same execution preserve it. Only an explicit new productive execution gets a new seed. Privacy erasure removes metadata/provenance with the execution. Missing stored decisions fail before a participant frame is returned.

Preview uses a secure random seed in simulation memory. **Volver a simular randomización** explicitly creates another simulated resolution and clears simulated answers without touching the authoring definition. Ordinary Preview reset, back and recovery retain the existing result. Preview sends no participant response. Admin Preview CSV is generated from the resolved definition.

`export_execution` returns an additional `randomization` object, and process metadata also retains it for archived partials/final execution. The CSV stays analytical and contains only selected questions; published definition hashes remain the source hashes. Reconstruct display by loading the immutable version and following each stored block order recursively, without randomizing a second time. Provenance is available through the authorized JSON export and stored execution metadata, not encoded as extra analytical variables. The older prototype EngineMapper rejects this new native type explicitly; it cannot silently flatten local selection into the separate Study Engine.

**Local Randomization element implemented — awaiting human product review.**


## Advanced local randomization (previous configuration, retained for historical versions)

In the Randomization inspector, **Candidatos, pesos y fijos** lists each direct child with **Peso** (positive finite number, decimals accepted, implicit default 1) and **Siempre mostrar** (implicit default false). Weights are relative; 1/2/1 means 25%/50%/25% for the first draw. The UI shows the first-draw probability; it is not a marginal inclusion probability for N > 1. All-mode includes all eligible children and does not use weights to order them.

**N counts only eligible random children; fixed children are added separately.** Select-mode samples without replacement, removing each selected ID and recalculating probabilities from remaining weights. Unequal weights use sequential weighted roulette with max-weight scaling to avoid overflow, an ID-sorted pool and the independent `seed:blockID:selection` stream. Selected visual ordering uses `:order`; equal weights retain the previous uniform Fisher-Yates result. Extreme ratios remain subject to ordinary floating-point precision. This is local reproducibility, not allocation concealment or global balancing.

**Mantener posición original** is the default fixed policy. After removing unselected candidates, fixed children keep their slots in the surviving authored sequence; eligible slots receive the selected visual order. This keeps an introduction first and a closure last. **Participar del orden final** includes the fixed IDs in the final shuffle (`:final`) when random ordering is active. Original-order selection retains the surviving authored sequence under either policy. Fixed children do not consume N; selecting from an empty eligible pool is invalid, while all-mode with only fixed children is valid.

Single Choice, Multiple Choice and simple Likert expose **Randomizar orden de opciones**; enabled questions expose **Posición fija** per option. Fixed options keep their exact authored slot because options are not removed. Other options use the question-specific `seed:questionID:options` stream. Only presentation order changes: option ID, label, analytical value and their association are preserved. Legacy Likert `reversed` is suppressed in the resolved view when randomization is enabled, leaving the original frozen definition untouched. Matrix/select are outside this option feature.

Optional authoring fields avoid changing historical snapshot normalization: child element `randomization: {weight, always_show}`, parent `properties.fixed_policy` (`original`/`mixed`), compatible question `properties.randomize_options` and compatible option `fixed`. Missing fields have implicit defaults and are not injected. Duplication deep-copies all flags/weights and renews block, child, response and option IDs; no resolution is copied. Independent nested streams preserve internal decisions when an outer selection changes.

New block audit records add `eligible`, `fixed`, `weights`, `fixed_policy`, `mode`, `count`, `order_policy` and `selection_method` to existing candidate/selection/order IDs. The root V2 record adds `option_orders` when needed, with question ID, candidate/fixed IDs, resulting order and method. Seed and resolution timestamp plus execution/version binding reproduce the decisions. Even an options-only execution gets a persisted record. Recovery applies the stored record directly, including legacy V1 results, without calling the resolver. Expiry resets answers but keeps the same execution decision; a new execution gets a new seed. The server rejects client changes to weights, fixed IDs, selections and option order in provenance.

Preview has an expandable **Resolución de randomizaciones · sólo Preview** panel with seed/reference, every block's candidates, weights, fixed flags, selected IDs and resulting order, followed by option orders. **Volver a simular randomización** generates another Preview execution and clears simulated responses. The participant renderer has no audit panel. Authorized JSON export includes provenance; analytical CSV preserves stable IDs/values and includes only selected questions. The prototype EngineMapper rejects active option randomization to avoid flattening it into an unsupported Study contract.

No global quotas, between-participant balancing, stratification, minimization, treatment arms, RCT assignment or logical conditions are introduced.

**Advanced Local Randomization implemented — awaiting human product review.**


## Weighted ordering and independent fixed questions (current editor)

The current Randomization inspector removes both parent fixed-policy and selected-order selectors and their old explanation. Child labels/controls share a row. New containers and explicitly edited randomization settings use optional `properties.algorithm = "weighted_positions"`; historical published definitions without it retain their original resolver and stored execution decisions. Changing mode, N, child weight or either child flag opts that draft block into the current algorithm. Publish a new version to change participant behavior.

**Ordenar todos aleatoriamente** uses positive relative weights to draw the first movable child, then the next, without replacement. **Pregunta Fija** means an absolute authored position, excludes that child from ordering competition and hides Peso. All children are displayed; the old Siempre mostrar flag is irrelevant in this mode. The help reports the probability of the first available ordering slot and explains recalculation.

**Seleccionar una cantidad** always produces weighted random order. **Mostrar siempre** bypasses selection, adds the child to N and hides its weight; its effective ordering weight is 1 unless pinned. **Pregunta Fija** is independent: it does not force inclusion, does not consume an extra slot and leaves Peso visible unless Mostrar siempre is checked. It pins the child only if selected/always shown. A pin's target is its original zero-based index, clamped to the last possible resulting slot. Multiple pins preserve authored relative order and reserve distinct closest feasible positions. Other children fill the remaining slots in weighted random order. Example authored A/B/C, B fixed and only B/C selected => C/B. C fixed with any other selected child => other/C.

Selection and ordering use separate ID-derived streams (`:selection` and `:order`); equal weights use uniform Fisher-Yates, unequal weights use sequential weighted sampling. The audit stores `algorithm`, independent `always_show`/`fixed` IDs, weights and the resulting order. The Preview table separates Mostrar siempre from Fijo. Recovery applies that exact stored result; historical V1/V2 records are not recalculated. Child optional `randomization.fixed_position` is strictly boolean and copied on duplication.


### Selection weights and independent order — latest correction

The current editor uses `selection_uniform_order` for new blocks and explicit edits to mode, N, weights or flags. In select mode, weights apply **only to inclusion**. Selected children plus always-show children receive a separate **uniform** shuffle, respecting fixed positions. Changing weights cannot change the order for an identical visible set and seed. All-mode continues to use weighted ordering. The UI help states that selection and order are independent; audit `order_policy` is `uniform` for selection and `weighted` for all-mode.

In select mode the maximum is **eligible candidate count minus one**, excluding Mostrar siempre. With two candidates only N=1 is allowed. With zero/one candidate there is no valid N; the inspector asks for at least two candidates. Browser max, Preview resolver, server definition validation and PHP resolver enforce this. Historical published definitions and persisted executions retain their original algorithm/limits, including the preceding `weighted_positions` variant, without changing normalization hashes or recovery decisions. Saving an explicitly edited configuration and publishing a new version applies the latest rules.
