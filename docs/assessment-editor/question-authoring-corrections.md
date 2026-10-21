# Question Authoring Corrections

Historical phase documentation; current implementation: [Product & Runtime Completion](product-runtime.md). Historical gaps below are not the current feature inventory.

Status: **Question Authoring Corrections implemented — awaiting human product review.**

Open `/wp-admin/admin.php?page=eipsi-assessment-editor`. This contract supersedes the inline rich toolbar, hash defaults, independent Matrix row names and VAS lanes described in the earlier authoring document. Existing choices, Text family, Date, Matrix response controls and ephemeral Preview remain available.

## Simple content and rich modal

Question, Helper, Footer and Matrix item label use ordinary textareas by default. Each has a discrete **Formato enriquecido** button. Already formatted content renders without a plain editable surface: edit it through that button to avoid silently stripping formatting. The modal edits a draft; **Guardar formato** sanitizes and applies it, while Cancel, Escape and backdrop discard the draft. Formatting includes bold, italic, underline, paragraphs/headings, lists, links, line breaks and WordPress Media Library images. Media Library appears above the custom dialog. Focus returns to the authoring control after closing. Plain content is escaped. Frontend sanitizer and backend `wp_kses` retain the existing allowlist; scripts, events, arbitrary styles, iframes and executable URLs remain prohibited.

## Analytical variables and allocator

Stable IDs identify questions/rows. Response keys identify technical responses. `variable_name` names the analytical variable for future datasets/exports; this phase changes neither productive exports nor their existing storage keys.

| Creation family | Default prefix |
| --- | --- |
| Text / textarea / email / number / telephone / URL | text |
| Date | date |
| Single / Multiple / Select | single / multiple / select |
| Likert / VAS / Matrix | likert / vas / matrix |

Each Assessment has independent, persisted next ordinals in `authoring.variable_next`. Creating Text, Text, VAS, Text, VAS yields `text_1,text_2,vas_1,text_3,vas_2`. Reordering or renaming does not recalculate names. Deleting even the highest ordinal does not reuse it after saving and reloading. Duplicate allocates a new name even when the original has a manual name. Manual names survive content/format changes. Existing names are observed to raise the next ordinal; candidate collisions, including Matrix derived outputs, are skipped. The repository preserves the maximum saved counter when a client sends lower counters, in addition to existing revision checks.

Names are ASCII identifiers `^[A-Za-z_][A-Za-z0-9_]*$`, case-sensitive, max 128 characters for standalone/base, 132 for derived row names. Effective outputs must be unique throughout one Assessment, including nested groups. The backend is authoritative; the UI reports conflicts immediately. Different Assessments/timepoints may reuse `ansiedad` or any other name: the timepoint is a separate dimension.

## Matrix

Only the Matrix base is editable. `phq` with three rows produces readonly `phq_1,phq_2,phq_3`. The suffix follows current row order; after reordering, the analytical names change while stable row IDs, response keys and independent answers stay unchanged. Duplicate assigns a new Matrix base and new identities. Derived names conflict with standalone outputs. A standalone `phq` may coexist with a Matrix base `phq`, because the base is not an aggregated response column. Two Matrices may not share the same base.

The additive root `authoring` metadata records `variable_policy: MATRIX_ORDINAL_V1`, counters and `legacy_row_variables`. Definitions without this metadata load without changing existing question names, including old hash defaults. Legacy independent row names become base-plus-ordinal in memory; `{row_id,variable_name}` aliases preserve their previous names for audit, not as additional analytical outputs. Technical identities are unchanged. Existing records are written only on explicit save. Counters cannot reconstruct deleted ordinals from before this metadata existed. Current-policy payloads attempting independently overridden row names are rejected by the backend.

## VAS geometry

Semantic labels use one shared renderer for automatic and explicit positions. A controlled visible rail is measured with `getBoundingClientRect`; its left/right bounds, not the input or parent width, define the scale. The native accessible range overlays it invisibly; the visible thumb projects its unchanged value onto that rail. The controlled 22px thumb gives an 11px inset at both ends in Firefox and WebKit. ResizeObserver and font readiness trigger remeasurement, with a window-resize fallback.

Explicit fraction is `(position-min)/(max-min)`. A label's containing box is centered at `railStart + fraction * railWidth` using `translateX(-50%)`. Min/max anchors stay exactly at the rail ends. Container gutters provide room for centered endpoint boxes; no horizontal clamping changes the anchor. Decimal labels need not align with response step.

Automatic labels retain their ordinal distribution and label-alignment inset: `inset=(100-label_alignment)*.002`, then `inset+(1-2*inset)*(index+.5)/count`. This removes the old incidental CSS-grid gap and mobile wrapping offsets: for five labels at alignment 74, positions include .352 and .648 rather than the desktop grid's approximate .35/.65. It is an explicit visual correction. Automatic fractions are invariant across viewport widths and other labels' manual positions.

Boxes use `width:max-content`, compact padding, wrapping and individual max widths bounded by neighbors, container edges and 220px. Font size responds to actual width, density, proximity and text length, multiplied by Small=.8, Normal=1 or Large=1.2. Legacy 12–14px maps to Small, 15–24 to Normal, 25–36 to Large; explicit `label_scale` takes precedence. The legacy property remains load-compatible but has no public pixel-size editor.

Normal labels share one visual plane, with no alternating lanes. For exact coincident anchors only, labels share one centered group stacked in source order. For extremely close distinct anchors with less than 18px available width, compact ellipsis keeps the exact anchor; full text remains in title/accessibility text and the inspector. These are minimum, predictable fallbacks, not horizontal repositioning. The inspector uses separate vertical cards for text, position, Automatic, order and removal.

DOM tests inject measured track bounds at 320/480/800px; they prove coordinate math and wiring, not actual Firefox pixel rendering. Before a connected/measured layout exists, a deterministic provisional layout is marked `data-track-measured=false`. Human Firefox review must check the real rendered centers, wrapping, pointer and keyboard interactions.

## Scope retained for later

Page configuration/titles, progress, previous/next policies, completion, Save & Continue, recovery, telemetry, productive runtime widget parity, variable-based exports remain pending in this historical phase. Randomization, conditions and response branching belong to future Study Composer work. No scientific runtime parity or product acceptance is implied by these authoring corrections.

## Subsequent functional phase

The later [functional completion contract](functional-completion.md) adds flow, explicit Likert values, shared participant widgets, recovery/metadata and one-way import. This historical phase's test counts and scope remain its original evidence.
