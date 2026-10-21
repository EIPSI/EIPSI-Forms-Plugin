# Data, management, and API

[Documentation index](../README.md)

Open **Study Engine → Manage** for a study. Management works with recorded data; viewing it does not simulate new participant answers.

## Management sections

| Section | What to review |
| --- | --- |
| Summary | Participation and response counts and recent activity. |
| Participations | Study cycle, assigned version, state, and relevant dates. |
| Assessments and responses | Scheduled opportunities, attempts, recorded values, and the published question version. |
| Communications | Longitudinal notification records and delivery attempts. |
| Audit | Recorded scientific and administrative events and randomization decisions. |
| Data and export | Response filters, preview, downloadable data, and selected annexes. |
| Diagnosis | Structural and operational checks with their reported limits. |

## What a response dataset means

A main row represents **one participation at its actual recorded response time**. Scheduled time and response time are different. The same participation may have multiple rows, but its response time must identify the measurement unambiguously. Export timestamps use UTC.

Question variable names become columns. The historical dictionary explains labels, types, options, coding, and versions. Changing a draft's label does not rewrite the dictionary associated with an earlier published assessment.

Choose saved partial responses explicitly when needed. An in-progress browser/session state is not automatically a saved partial or a completed response. Response-status records distinguish missing values, skipped questions, and questions not selected by randomization.

## Prepare a manual export

1. Choose filters and the annexes you need.
2. Prepare the export and review its rows, variables, warnings, and conflicts.
3. Wait for the export to be ready.
4. Download XLSX, JSON, or the offered CSV sections.

The export fixes a snapshot of its sources. A conflicting variable or ambiguous response time blocks the export rather than silently merging values. Response changes or withdrawal can invalidate a prepared file; prepare another export when the system asks you to.

## Identities and process metadata

Identities are separate from scientific response columns. Administrator-only identity annexes are not available to external API keys. Process metadata is also separate and contains only the fields permitted by privacy settings and the assessment's capture profile.

Metadata can include session/page/item events and enabled client attributes. It is not a new analytical subject identifier. Turning a capture setting off changes future capture, not previously collected records.

## API connections

Open **Configuration → External API** to enable access, create a key, and select study permissions. Use the downloadable R/Python examples in **How to connect**. Insert or load your own key where the example indicates; a participant's public link is not an API credential.

To compare with a manual scientific download, request the same study, filters, annexes, and the current canonical dataset contract. The API and manual export share the dataset projection; a live read taken later can legitimately include different observations. Use a snapshot export when you need a fixed cut.

The API reports preparation status before a snapshot can be downloaded. Follow its retry interval while polling. External keys do not grant access to the manual identities annex.

See the [API documentation](../external-api/README.md) for endpoints and examples, and the [canonical dataset specification](../dataset/Canonical%20Dataset%20and%20Metadata%20Policy.md) for columns, metadata, conflict handling, and formats.
