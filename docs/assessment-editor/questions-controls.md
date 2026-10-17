# Questions & Controls Parity

Historical phase documentation; current implementation: [Product & Runtime Completion](product-runtime.md). Historical gaps below are not the current feature inventory.

**Questions & Controls Parity implemented — awaiting human product review.** The v0 visual direction was approved; this extension's acceptance remains pending.

Open `/wp-admin/admin.php?page=eipsi-assessment-editor`. The editor remains parallel to Gutenberg. No scientific runtime, legacy consumer, page/navigation/condition/telemetry/export implementation changes.

## Native contract and compatibility

`EIPSI_ASSESSMENT_V1` now supports twelve response types: `text`, `email`, `number`, `tel`, `date`, `url`, `textarea`, `single_choice`, `multiple_choice`, `select`, `likert`, `vas`. Explicit type-specific properties are validated in `Definition.php`; unknown properties/types still fail. `validate()` returns a normalized copy. Repository saves normalized JSON and normalizes reads in memory. Reading never updates the stored option or its revision; a subsequent manual Save persists the enriched definition under the existing CAS revision rule.

Existing v0 definitions retain IDs/keys/options/order and remain openable. Defaults fill only newly introduced properties: text minimum length 0, textarea rows 4, multiple min_selected 0/max_selected null, Likert min 1/custom/not reversed/no gradient, and VAS presentation/no gradient. Old left_label/right_label become labels[] only when labels is absent. Original endpoint strings remain preserved as compatibility fields; labels[] is the authoritative new presentation list. Explicit new settings always win. Normalization is idempotent and non-destructive.

| Family | Properties introduced / retained |
|---|---|
| Text/email/tel/url | label/helper/required/placeholder, min_length/max_length |
| Number | placeholder; nullable min/max; positive finite step; typed numeric answers |
| Date | explicit date type, label/helper/required; no irrelevant placeholder |
| Textarea | text properties plus rows 1–100 |
| Single/multiple | ordered stable option IDs, vertical/horizontal presentation |
| Multiple | min_selected and nullable max_selected, bounded by option count |
| Select | real select with unanswered placeholder; stable option IDs |
| Likert | min integer; ordinal values derived from option order; preset/custom; reversed presentation; gradient and custom colors |
| VAS | min/max/step/initial; labels[]; value_position above/below; label_font_size 12–36; value_font_size 16–72; bold_labels/label_boxes; label_alignment 0–100; show_value; gradient and custom colors |

Option ID is the stored answer identity. Labels are freely editable and may contain punctuation, separators, quotes or newlines without parsing. A separate generic stored value would duplicate identity without a consumer and is not introduced. Duplicate IDs are rejected; repeated labels remain distinguishable by stable IDs (and ordinal values for Likert), preserving v0 compatibility. Reordering and renaming preserve IDs. Question duplication renews all question/option IDs and response keys.

## Shared rendering and properties

`controls.js`: defaults, presets, normalization, response validation, numeric projection and presentation helpers. `model.js`: existing tree/identity/session model. `widgets.js`: one rendering layer for canvas (inline editing) and participant preview. `inspector.js`: contextual controls grouped into response/options/scale/presentation/gradient. `app.js`: integration and synchronized inline editing. No Gutenberg APIs, block generation, new framework or dependency.

Canvas displays real inputs/select/radios/checkboxes/Likert/range. Labels, helpers, choice labels and VAS semantic labels support multiline inline editing; the inspector offers option/label add/remove/reorder. Question technical identity remains under Advanced. Existing groups/PageBreak/library/persistence/navigation continue unchanged in scope.

Six presets match `src/components/LikertPresets.js`: agreement5, satisfaction5, frequency5, agreement7, satisfaction7, agreement4. Selecting one applies min/labels; existing positional option IDs are reused, extra points receive new IDs. Editing options or the start value changes the preset marker to custom. Preset is authoring metadata, never a separate scientific entity. Inversion reverses rendering order while preserving canonical option indices/IDs and model order, including wrapped/mobile layouts; it never changes numeric values or applies reverse scoring. `PreviewSession.values()` projects selected option ID to min + canonical option index while `answers` preserves the ID.

VAS supports arbitrary finite decimal ranges and positive steps, with initial aligned to the step from min. Max need not itself be a valid step stop; responses must be. Initial thumb is unanswered. Pointer/input/touch/native keyboard interactions mark touched even when the value does not change. Display hidden/above/below/font choices never change the response. All original gradient directions/colors are retained: five-stop red→green, inverse green→red, neutral grey, custom start/end, none.

Original VAS spacing includes interpolation tables for 3–5 labels and an alignment control; it is not merely uniform. The native redesign preserves ordered labels and compact/expanded distribution using container-relative grid/insets, without reproducing fixed offsets/transforms that overflow. Narrow container queries reflow labels into readable rows; no one-time <=480px JS initialization. Width changes in Preview react through CSS. This preserves presentation capability, not exact legacy pixel positions. Manual visual/mobile acceptance remains pending.

## Validation and isolation

Preview validates required, Unicode code-point length, email/URL/date, number bounds/step, available option IDs, unique multiple selections and min/max cardinality, VAS bounds/step/touched. Optional blank fields remain blank; a positive authored min_selected requires a selection even if required is off. Errors are shown by the existing preview navigation, with no new page/navigation features.

`Answers::errors()` provides the corresponding pure backend validation and rejects unknown response keys. `Answers::value()` provides Likert numeric projection. These methods never persist or send responses, and no AJAX preview endpoint is added. Server schema remains authoritative at Save/load. Preview uses JS memory only; it creates no participant/execution/submission, browser storage or network request.

## Engine boundary

Only native mapper changes. Existing scientific source files are preserved. Mapper keeps the whole native DTO in protocol metadata and each element in snapshots; output includes explicit mapping_warnings. Email format, text min_length, numeric step, Likert bounds/labels and the existing textarea content-renderer discriminator are emitted where existing consumers support them. The textarea discriminator is an existing string contract, not Gutenberg serialization.

| Native type | Engine capability |
|---|---|
| Text | TEXT and length validation; placeholder/multiline presentation remain gaps |
| Email | TEXT/format=email with existing email consumer; presentation gaps remain |
| Number | NUMBER bounds and snapshot step consumed by existing adapter/renderer |
| Telephone | TEXT with native format preserved; existing renderer still text |
| Date / URL | entire mapping explicitly rejected; definitions still save/preview |
| Textarea | TEXT with textarea discriminator; rows/placeholder remain native metadata |
| Single | CHOICE with stable option IDs; existing layout remains basic radios |
| Multiple | entire mapping rejected; no flattening, silent omission or false success |
| Select | CHOICE semantics; native select/placeholder retained in metadata, existing renderer still radio; explicit warning |
| Likert | INTEGER min..min+N−1; full native presets/reverse/gradients/options retained, existing runtime still basic number |
| VAS | NUMBER bounds/step; full native widget preserved, existing runtime still number rather than slider/touched/presentation |

No automatic publication or production integration. Definitions that contain Date/URL/Multiple are rejected as a whole by mapper. Native assessments remain usable independently of that export boundary.

## Verification and human route

Fixtures: `tests/assessment-editor/parity-fixture.json` contains all twelve maximally configured types, generated explicitly by `make-parity-fixture.js`. This includes decimal VAS -2.5..7.5/0.25, initial 2.5, five multiline labels, custom colors, label/value styling and required. Existing fixture.json remains the untouched v0 compatibility fixture.

`parity-dom.js` adds 28 meaningful cases; `run-assessment-controls.php` adds 27 backend cases, including a separate save/load comparison for each type. Existing 28 DOM/21 backend v0 cases remain. FULL includes new suites and all existing regressions/build/smoke/lifecycle/strict RCT.

In one disposable evaluation, create every text subtype, textarea, radio/multiple/select/Likert/VAS. Configure required/helper/placeholder, number -2.5..7.5 step .25, textarea rows/length, multiple min2/max3, select placeholder. Try all six Likert presets, custom labels/start -3, inverse display and every gradient. Configure VAS decimal range/initial/five multiline labels, compact/expanded labels, font sizes/bold/background, value above/below/hidden, every gradient/custom colors. Save, reload and reopen. Preview invalid and valid inputs, cardinality errors, untouched VAS, same-value touch/keyboard, widths Desktop/Tablet/Mobile, close/reopen empty. Record Firefox/Windows results and issues; automated tests do not mark HUMAN PASS.

F29–F68 remain outside this phase. No next phase until human review. No commit/push/tag/release.

The subsequent [Question Authoring Enhancements](question-authoring.md) phase adds unified Text authoring, rich content/footer, variable names, selection columns, VAS label positions and native Likert Matrix. Its own human review remains pending.
