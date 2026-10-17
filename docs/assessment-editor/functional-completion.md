# Native Assessment Editor: functional completion

Historical functional-phase description. Current behavior and review route: [Product & Runtime Completion](product-runtime.md). Publication, participant transport and recovery limitations below are superseded by that phase.

This document supersedes the earlier phase's missing-flow/value limitations. Human product acceptance remains pending. No native publication, participant authentication, Study Composer, branching or randomization is introduced.

## Contract and compatibility

`Definition::validate()` remains the authority. Older definitions load with default `flow`; missing scale-option `value` migrates to `min + index`. Explicit `null` instead means analytical label fallback. Scale options are `{id,label,value}` with finite numeric or null value. Reverse changes visual order only. Matrix shares these options, retaining stable row IDs/keys and deriving analytical variables from base plus current ordinal. Ordinary Canvas/Preview hide row variables.

`flow` contains `pages`, `allow_back`, `progress`, `completion`, `save_continue` and `metadata_profile`. Pages follow the start boundary and top-level PageBreak IDs, rather than their ordinal. A copied assessment preserves stored page identities. Newly derived long IDs are shortened deterministically (FNV-1a, for identity shortening only); collisions reject. Removing a break merges content into the previous page and discards the removed boundary's page configuration. Completion is not a page.

The only recovery window is a positive integer plus minutes/hours/days/weeks/months. Months mean 30 days; total retention is bounded to 157,680,000 seconds. No authentication TTL settings are exposed.

## Rendering and rich editing

Canvas and Preview use shared native widgets. Preview now consumes `renderParticipantElements`; `assets/js/assessment-participant.js` exposes the same widgets and `mountParticipant` without authoring UI or network/storage transport. The host must own access, identity, persistence and publication. This is the beginning of productive convergence, not an automatic replacement of existing Engine routes. EngineMapper still explicitly rejects unsupported native types and Likert values that cannot preserve its consecutive INTEGER contract.

VAS solves one maximum common font size against all contextual boxes/anchors and wraps, then applies the relative user factor with bounds. Browser Canvas text metrics are used when available; deterministic metrics support headless tests. Compact/coincident anchors retain bounded fallback behavior. Actual Firefox typography remains subject to human review.

Rich fields use a discreet internal icon and a transactional modal backed by WordPress's bundled classic TinyMCE and Media Library. Undo/redo, blocks/headings, emphasis, lists, links, images and remove-format are available. The locally inspected WordPress distribution bundles TinyMCE 4.9.11: this uses the platform dependency and does not claim the latest upstream release. Native-command fallback supports headless/unavailable-editor environments. Server RichText sanitization remains authoritative and its allowlist is not widened.

## Recovery and metadata boundaries

Preview recovery is memory-only, with explicit save/leave/resume/expire controls. Closing/restarting/completing clears it. It is not an offline persistence promise. `PartialProgress` provides a separate capability-protected native admin owner using scoped WordPress transients, fixed retention, definition-hash validation, answer/page validation and expiry cleanup. OFF removes native state. This service is not connected to longitudinal participant/auth endpoints; productive recovery policy remains unchanged.

One `MetadataRecorder` covers all widgets and Matrix rows. Basic captures lifecycle/final timestamps; Standard adds counters, focus/visits/transitions and device/input context; Detailed adds compatible intermediate state. Text intermediate metadata contains length/timestamps only. It never records raw intermediate text or keys. Counters remain available in the common object shape across profiles; Detailed trajectories are coalesced within 250ms and bounded to 128 per item and 2048 events globally, with a drop counter. All records are batched in memory, with no request per movement. Snapshot/partial retains history and a resume marker. Native session definition is the authoritative presentation snapshot, linked by definition_ref; server export supplies its SHA-256. No fabricated StudyVersion is assigned to Preview.

## Analytical export

`AnalyticalExport` emits tidy rows keyed analytically by `variable_name` while retaining assessment/item/response-key, definition hash and optional StudyVersion/block/timepoint context. Matrix produces one row per derived variable, no aggregate column. Repeated variables across assessments/timepoints are valid. Multiple values remain arrays in JSON and JSON cells in CSV. CSV guards spreadsheet formula strings while preserving numeric negatives. Preview export is an explicit guarded admin operation and saves no participant answers. Existing Engine tidy export gains an additive `variable_name` from its native-element snapshot, falling back to response_key for older snapshots.

## Validation

`tests/run-assessment-functional.php` (37) and `tests/assessment-editor/functional-dom.js` (36) add 73 cases to the 2175 baseline. FULL enforces exact suite counts, build freshness including the standalone participant artifact, strict RCT, clean/upgrade, smoke and lifecycle. Tests do not constitute human product acceptance.
