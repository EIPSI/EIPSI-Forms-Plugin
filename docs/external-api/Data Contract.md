# EIPSI_STUDY_DATA_V1

Long analytical format. Each row describes one question/matrix item for one attempt/opportunity. `study_code` belongs to JSON envelopes; CSV prepends it as an extra first column. JSON preserves numbers, booleans, arrays and null. CSV arrays use JSON, booleans true/false, null an empty cell; CSV alone cannot distinguish all empty-string/null cases. SEC-1 CSV serialization neutralizes spreadsheet formulas (including after leading whitespace); preserve the JSON representation if exact original text is needed.

| Column | JSON type / nullable | Meaning and historical semantics |
| --- | --- | --- |
| study_id | string, no | Logical Study ID, common to historical versions/cycles |
| cycle | integer, no | Lifecycle cycle at participation creation, starting at 1 |
| study_version_id | string, no | Immutable StudyVersion actually used |
| participation_id | string, no | Opaque Study execution, not email or direct identity |
| opportunity_id | string/null | Scheduled assessment occurrence/block execution binding |
| occurrence | object/array/string/null | Stored Native occurrence descriptor or domain occurrence key; preserve its structure |
| assessment_id | string/null | Assessment binding/definition ID from historical attempt; do not assume current authoring ID |
| native_version_id | string/null | Pinned Native version; null for domain-native responses |
| attempt_id | string/null | Server attempt identifier; domain path uses block execution |
| attempt_state | string/null | Persisted attempt state, e.g. COMPLETED/PARTIAL_SAVED; not inferred from value presence |
| saved_at | UTC string/null | Confirmed save/completion time when available; no invented timestamp |
| item_id | string/null | Historical element or matrix-row ID |
| response_key | string/null | Historical response storage key; matrix rows have distinct keys |
| variable_name | string/null | Researcher's historical variable label; renames do not rewrite past rows |
| label | string/null | Question/matrix-row historical text, stripped of markup for dictionary projection |
| type | string/null | Historical question type; supports typed text, number, Likert, VAS, matrix, date, multiple choice, consent, etc. |
| response_status | string, no | Explicit meaning below; never impute missing values from null alone |
| value | string/number/boolean/array/null | Analytical value from pinned definition; numeric option values/VAS stay numeric; multiple selection stays an array |
| selected_option_id | string/array/null | Selected option identifier(s), separate from analytical value |
| definition_hash | string/null | SHA-256 of pinned Native definition, or domain question snapshot |
| data_policy | string, no | USE_ALLOWED or RETAIN_FOR_ANALYSIS; DO_NOT_USE absent |

Nullable means consumers must tolerate null; it does not mean the system invents rows for every opportunity. Domain-native output projects recorded responses; finalized Native output can include dictionary questions with explicit missing/excluded states.

## response_status

| Status | Meaning |
| --- | --- |
| VALUE_RECORDED | A stored row exists and its analytical value is non-null; false/0/empty text must not be treated as missing |
| NO_VALUE_RECORDED | A stored row exists with null value, e.g. optional unanswered question |
| NOT_RECORDED | Finalized Native definition contains item without stored row or a more specific recorded exclusion |
| SKIPPED_BY_LOGIC | No stored row and binding records item as logically skipped |
| NOT_SELECTED_BY_RANDOMIZATION | No stored row and recorded randomization excludes its candidate |
| RANDOMIZATION_UNRESOLVED | No stored row and the relevant randomization has no recorded decision |

These are persisted evidence projections. They do not resolve randomization, execute conditions or reconstruct what a participant ought to have seen. Stored rows take precedence over inferred absence categories. No seeds/candidate plans/process-event stream are exposed.

## Partials and privacy

`include_partials=false` by default. True includes persisted analytical partials eligible under the same policy: saved Native partial records (PARTIAL_SAVED) or explicitly saved domain responses from uncompleted blocks. An ACTIVE in-memory/persisted questionnaire session is not, by itself, a saved analytical partial; merely opening an evaluation creates no scientific answers.

DO_NOT_USE is excluded before physical cleanup and also after cleanup. RETAIN_FOR_ANALYSIS remains exportable without identity. Privacy changes invalidate relevant derived snapshots and all future deliveries reauthorize; previously downloaded copies cannot be withdrawn. Pausing, archiving/finalizing a Study does not erase historical scientifically eligible rows.

## Dictionary and version history

A logical Study can span StudyVersions, reopening cycles, and Assessment/Native versions. Never interpret all rows using the latest editor definition. Join dictionary with the tuple `(study_version_id, assessment_id, native_version_id, response_key, definition_hash)` and check variable_name/item_id. response_key alone or variable_name alone is not a global identity.

Each dictionary item contains version/binding/hash, item_id, response_key, variable_name, historical label/type, `options` (id/label/value), nullable=true and relevant validation (required/min/max/integer/length/selection). Validation is historical descriptive context, not a new runtime rule for imported rows. Option ID remains separate from its analytical value; matrix row variables are distinct. Dictionary only covers rows represented by the requested final/partial dataset. Snapshot dictionary is pinned; a live dictionary can change between pages.

## Explicit V2 contract

This document's per-item V1 schema is retained for requests without negotiation. DATA-1 introduces an explicit wide `EIPSI_STUDY_DATA_V2` contract; no V1 identifier changes meaning. See [Canonical Dataset and Metadata Policy](../dataset/Canonical%20Dataset%20and%20Metadata%20Policy.md). Live data uses `contract=EIPSI_STUDY_DATA_V2`; snapshot POSTs use the same contract property. Responses declare EIPSI_EXTERNAL_DATA_V2 / EIPSI_EXTERNAL_DICTIONARY_V2. V2 JSON exports are bundles, CSV main data, XLSX selected sheets plus manifest. No identities are exposed via API.
