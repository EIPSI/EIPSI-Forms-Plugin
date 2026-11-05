# Assessment Editor

[Documentation index](../README.md)

An assessment contains the questions and pages that a participant answers. A study selects a published assessment version and decides when and to whom it is presented.

## Create and edit

Open **EIPSI Forms → Assessment Editor**. Create an assessment from the list or open an existing one. Add elements to the visual area and select one to edit its properties.

Supported question families include text, email, telephone, number, date, single and multiple choice, dropdowns, Likert scales, Likert matrices, and VAS scales. Instructions, images, groups, randomizations, and page breaks organize the content. A group organizes elements; a page break creates a navigation boundary. A horizontal simple Likert keeps its options in one row while the columns fit; option labels may wrap within each column. If the columns no longer fit, the entire scale switches to one vertical column.

Use rich text for the fields that offer it and the WordPress media picker for images. Give questions useful **variable names** before collecting data: these names become analytical columns, whereas labels are participant-facing text.

## Responses and messages

Set whether a question is required. Where applicable, configure a validation format, range, or length and edit its validation message. A missing required response and an invalid non-empty response are different cases and can have different messages. Multiline text is free text. In the public assessment, the same validation message appears beside its question or matrix row and is associated with the control for assistive technology. Validation does not automatically move focus to another question. Single-choice, multiple-choice and Likert groups expose the authored question title and instructions as their accessible context. Calendar month navigation keeps the same Previous/Next controls while updating the dates. You can enter a year directly in the calendar header; its accessible label is editable under Calendar in the question properties. Invalid questions use a burgundy outline and a pale background around the entire question.

Use general properties for navigation labels, progress, assessment messages, session duration, partial saving, appearance, and the final screen. Some fields appear only after enabling the feature they describe.

When progress is enabled, **Progress position** places it above all assessment content or below the navigation buttons. The progress text is centered in both positions. The same setting applies to the published assessment, its preview, and the study simulation.

In **General settings → Design**, choose the maximum questionnaire width and side margins for desktop, tablet, and mobile. New defaults are a centered 960 px container, 24 px desktop/tablet margins, and 16 px mobile margins. **Use full available width** overrides the maximum width while retaining the selected margins. Existing authored design dimensions remain editable.

Select a **VAS → Properties → Presentation** to set **Relative label size (%)** from 25 to 300 (100 is the automatic size); labels still adapt to the available space and retain their value anchors. This section also lets you choose label placement (all above, all below, or alternating), automatic or custom space at the scale ends, and horizontal or vertical presentation on mobile. For a vertical VAS, select which numeric end appears at the top and its height. Labels remain anchored to their configured values; above/below becomes left/right in vertical mode. Orientation changes presentation, not response values or scoring. The default remains horizontal with labels at the same level above the rail. Automatic end spacing reserves room for centered endpoint labels; a custom margin can reserve more or less room.

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

### Registration information

In **Assessment Editor → Elements → Selection**, add **Registration information**
(**Datos de registro** in Spanish). Edit the question on the canvas. Its properties
let you edit both option labels, the registration field label and placeholder,
and its required/invalid-response messages.

Choosing **Register** reveals an editable field prefilled with the participant's
email in a longitudinal study or the anonymous participation identifier in a
cross-sectional study. This identifier is not the access cookie or credential.
An assessment opened independently uses its own execution identifier. The editor
preview has no participant authentication value; the Study simulation uses its
dummy participant value.

The response stores both fields, for example
`{"register":true,"value":"participant-edited value"}`. **Do not register** stores
`{"register":false,"value":null}`. A required question requires a choice; declining
is a valid answer. The choice and the edited value are retained in partial/final
responses and in the shared manual/API dataset. Structured values are JSON in CSV
or spreadsheet cells. The dictionary identifies the question as `registration`
and retains both option labels. Conditions can use the boolean choice.

Editing this response never changes authentication, enrollment or session data.
Later email corrections, or removing participant emails when finalizing the study,
do not rewrite this response. Explicitly collecting an email here therefore keeps
it as response content. Process metadata records only the boolean choice, not the
registration text. Leaving the question unanswered differs from declining.
