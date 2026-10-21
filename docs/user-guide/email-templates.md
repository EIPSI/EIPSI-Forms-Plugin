# Email Templates

[Documentation index](../README.md)

Open **EIPSI Forms → Email Templates**. The list is on the left and the selected template editor is on the right. Templates form a reusable library; a study selects the content it uses for its own protocol.

## The three purposes

| Purpose | Use |
| --- | --- |
| Email validation | Confirm an email and provide access when the study requires validation. |
| Assessment available | Announce an assessment that has become available and provide its direct link. |
| Assessment reminder | Remind a participant about a scheduled opportunity, according to the study's reminder settings. |

The study's verification mode determines when a validation message is needed. An assessment link also validates the email when redeemed; expiration of the assessment period does not itself extend that period.

## Edit content

Choose a name and purpose, then edit the subject, sender name where offered, and rich-text body. The sender email and delivery connection come from SMTP configuration.

The visible email is the template content: include the links, explanations, and footer you want participants to receive. The plugin does not append an extra participant paragraph or footer outside that content. Use **Preview** to review the result in its modal; preview does not deliver an email.

## Variables

Insert the supported variables shown in the editor. Common examples are:

| Variable | Meaning |
| --- | --- |
| `[study_name]` | Study name. |
| `[assessment_name]` | Assessment for the opportunity. |
| `[available_time]` | Availability date and time. |
| `[due_time]` | Deadline date and time, when present. |
| `[direct_link]` | The participant's access/assessment link. |
| `[withdrawal_link]` | Link to the study's withdrawal entry. |

Use the exact spelling. In particular, a link reaches the email only if the template includes it. The editor's variable list is the reference for supported substitutions.

## Select templates in a study

For a longitudinal study, open **Initial screen**, select the email authentication element, and choose templates in its properties. The study keeps its own copies. Editing a library template does not silently replace the content in published studies or already assigned versions; review the study draft and publish the intended change.

A new template takes the global language at creation. Existing templates and saved study copies keep their own text when the general language changes.
