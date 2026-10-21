# Question Authoring Enhancements

Historical phase documentation; current implementation: [Product & Runtime Completion](product-runtime.md). Historical gaps below are not the current feature inventory.

Status: **Question Authoring Enhancements implemented — awaiting human product review.**

Open `wp-admin/admin.php?page=eipsi-assessment-editor` as an administrator. This is the native editor; it stores typed assessments in its existing dedicated repository. It does not publish scientific executions, generate Gutenberg blocks, modify post_content, or persist preview answers.

## Authoring

The palette groups the Text family under **Texto**, with **Fecha** independent. Select one-line/multiline and Free/Email/URL/Telephone/Numeric/Letters in the inspector. Existing text/textarea/email/number/tel/url discriminators remain compatible. Numeric content uses one line. String length limits may be null; Number has min/max/step/integer-only. Letters permits Unicode letters, combining marks and whitespace, without arbitrary regex.

Click question, help or optional footer to edit inline. The contextual toolbar provides formatting, headings, lists, safe links, images from WordPress Media Library and sanitized HTML. Preview shows question → help → control → footer, omitting empty footers. Matrix rows reuse the rich editor for their label.

Each question and Matrix row has a variable name, separate from technical ID and response key. New defaults use a type/item prefix and twelve random hexadecimal digits; older definitions get a deterministic `v_<response_key>` default. Names are case-sensitive and globally unique within the assessment, match `^[A-Za-z_][A-Za-z0-9_]{0,127}$`, and remain independent of labels. Duplicate questions/rows receive new default names. Variable names are visible only in the editor.

Single/Multiple support vertical/horizontal and 1–12 maximum desktop columns. More than one column selects a row-major grid; narrow containers reduce columns. Option order and identities do not change. Select remains a dropdown.

VAS label text stays in `labels[]`; its paired `label_settings[]` holds `{id, position}` in exactly the same order. UI operations maintain both arrays. Null position preserves automatic placement. Finite values inside min/max position that label at `(position-min)/(max-min)`, without changing the automatic positions of other labels. Decimal positions need not align with response step. Clear a position to return to automatic. Close labels use vertical lanes; adaptation to the container preserves usable widths.

## Matrix

`type=matrix`, `matrix_kind=likert` is a native entity, with shared options/presets/min/visual reversal/gradient and 1–100 rows. Each row has stable ID, response key, editable analytical variable, rich label and optional individual required behavior. The Matrix's response key is structurally reserved; answers use only row keys and option IDs. Numeric projection uses the shared canonical option index. Global required requires all rows; optional Matrix still enforces individually required rows.

The inspector adds/removes/reorders/duplicates rows and edits one shared scale. Desktop uses a table; narrow containers stack rows with visible option labels. Cloning renews row IDs, keys, variables and shared option IDs. Row helper/footer fields are preserved and rendered when present, but do not have their own authoring panel in this phase.

## Security and compatibility

The unchanged `EIPSI_ASSESSMENT_V1` contract adds typed defaults; loading does not write a migration. Rich presentation is explicitly separate from scientific structure through label_html/helper_html/footer_html and plain-text fallbacks.

Backend WordPress KSES is authoritative, with an explicit allowlist for paragraphs, formatting, headings, lists, links and images. Script, inline styles/JS, handlers, iframe, SVG and unsafe protocols are excluded. Images require HTTP/HTTPS or root-relative local URLs. Links receive `_blank` and `noopener noreferrer`. A defensive DOM sanitizer protects Canvas/Preview and paste. Existing capability, nonce, origin, payload limits and revision CAS remain.

Engine mapping preserves native metadata and warns about unconsumed presentation/validation. Matrix, Date, URL (including multiline URL) and Multiple reject complete mapping explicitly. No expansion to independent questions, ambiguous string conversion or silent omission. Scientific runtime, exports and legacy remain unchanged.

## Review route

In one disposable assessment create rich Text/help/footer with an image, Text as Email/Numeric/Letters/Multiline, Date, Single horizontal with three columns, Multiple with two columns/cardinality, Select, existing Likert, VAS with mixed auto/manual positions, and a five-row Likert Matrix. Edit variables; exercise invalid/duplicate names. Save/reload, compare settings, and use Desktop/Tablet/Mobile Preview. Check required/all/individual Matrix cases, VAS touched, safe links, Media Library and responsive readability. Close/reopen Preview to confirm answers are cleared.

Automated suites: `tests/assessment-editor/authoring-dom.js` and `tests/run-assessment-authoring.php`, registered in FULL alongside all earlier suites. DOM/CSS tests do not certify actual browser appearance or Media Library interaction. Human review remains PENDING. No next phase, commit, push, tag or release.

## Subsequent correction phase

The historical UX and naming descriptions above are superseded by [Question Authoring Corrections](question-authoring-corrections.md): simple editing with rich modal, persisted short defaults, Matrix ordinal variables and one measured VAS anchor renderer.
