# Assessment Editor

[Documentation index](../README.md)

An assessment contains the questions and pages that a participant answers. A study selects a published assessment version and decides when and to whom it is presented.

## Create and edit

Open **EIPSI Forms → Assessment Editor**. Create an assessment from the list or open an existing one. Add elements to the visual area and select one to edit its properties.

Supported question families include text, email, telephone, number, date, single and multiple choice, dropdowns, Likert scales, Likert matrices, and VAS scales. Instructions, images, groups, randomizations, and page breaks organize the content. A group organizes elements; a page break creates a navigation boundary.

Use rich text for the fields that offer it and the WordPress media picker for images. Give questions useful **variable names** before collecting data: these names become analytical columns, whereas labels are participant-facing text.

## Responses and messages

Set whether a question is required. Where applicable, configure a validation format, range, or length and edit its validation message. A missing required response and an invalid non-empty response are different cases and can have different messages. Multiline text is free text.

Use general properties for navigation labels, progress, assessment messages, session duration, partial saving, appearance, and the final screen. Some fields appear only after enabling the feature they describe.

Keeping a session available lets a participant continue the current attempt. Saving a partial response is a separate setting. If a session expires, the participant starts at the beginning; a previously saved partial is handled according to the configured flow. **Discard response**, when enabled, acts on the current assessment attempt and its stored response, not on other scheduled assessments.

## Randomization and logic

An internal randomization organizes its candidate questions or elements. Configure whether to randomize the order or select a subset, weights, always-shown candidates, and fixed positions as applicable. Review the resulting experience in preview and study simulation.

Study Engine's **Logical conditions** coordinates conditions across the protocol, including question-level actions. Use its track view and help dialog to check sources, order, and destinations; the assessment supplies the questions and published version referenced there.

## Save, publish, and reuse

**Save** updates the editable draft. **Publish version** creates a fixed version that studies can reference. Later draft edits do not replace that published content. Use **Published versions** in the assessment menu to inspect earlier versions; select the intended version when adding the assessment to a study.

The assessment menu also provides JSON import/export and question import. These files reuse authored content, so review variable names, references, and the result before publishing. See [JSON exchange](../assessment-editor/json-portability.md) for the exact supported controls and format.

The assessment's language is captured when it is created. Changing the global language does not translate its existing text or change the defaults for questions subsequently added to that assessment.

## Leaving and resuming an assessment

A still-valid questionnaire session can resume its last server-recorded page and answers even when permanent partial saving is disabled. The duration configured in the assessment controls this temporary session. After it expires, the questionnaire starts from the beginning; if partial saving was enabled, the previous partial response remains stored separately and is not prefilled into the new session.

In a cross-sectional study, recovery uses the same browser's access cookie. In a longitudinal study, re-entering the same participant email with matching valid browser access resumes the existing opportunity; it does not send another validation email or mark the address as verified. If access is missing or belongs to someone else, the study's access rules still apply. The scheduled opportunity must remain available.

After a final response, the configured completion remains visible without a repeat/return control. Refreshing returns to the study entry page. A configured email review occurs before the completion is shown: the field starts empty, a changed address requests confirmation, and a conflicting registered address must be corrected. Correction updates the same participation without rewriting its assessment response. Notices appear outside and above the final card; they expire after 40 seconds or can be closed individually with ×.
