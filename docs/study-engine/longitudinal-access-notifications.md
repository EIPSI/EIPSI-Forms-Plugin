# Longitudinal access, email confirmation and notifications

Published Initial screen settings are shared across all participations, including those assigned to an earlier scientific version. They include the email validation mode, confirmation option, membership controls, availability-notice policy and selected templates. Live access checks, Native authorization and the email worker read that same current scope. Draft edits remain private. The participant's assessments, calendar, conditions, random decisions, responses and scientific version are preserved.

This guide describes the current Study Engine participant flow. Configure its texts and appearance in Study Engine; email bodies belong to Email Templates and transport belongs to SMTP.

## Entering and resuming

Email is normalized by trimming whitespace and ignoring case. Each participation has an opaque identifier, separate from its address and scientific response identifiers. The same address in two studies represents separate participations.

The initial screen asks for the address. A new participant follows the study's validation mode: before the first assessment, from the second, or no required validation. If a first assessment is available and the chosen mode permits direct entry, it opens without an unnecessary immediate availability email. A visit without an available assessment remains on the initial screen with the applicable notices.

Re-entering the same address with valid matching browser access resumes the participation and its available assessment. A provisional session is not promoted to authenticated access or email verification merely by resuming. Access belonging to a different participation is never reused for that address. Missing access follows the configured entry/validation flow. Participants already admitted remain on their published study and assessment versions.

## Questionnaire session and partial saving

An assessment's temporary session has its own configured duration. A valid session can resume its page and server-recorded answers independently of permanent partial saving. Expiry starts the questionnaire from the beginning. When enabled, the expired attempt's partial response is stored separately rather than prefilled. The opportunity's availability window is a separate limit.

A browser close or connection loss can prevent the newest local edit from reaching the server; recovery can only use acknowledged state.

## Reviewing the address after the first take

The optional review applies to direct first access where the participant has not already validated through a link. When several assessments are already open at that first access, they form the initial take: they concatenate in longitudinal block order, retain independent response records, and show only the final screen of the last assessment. Email review and validation from the second take wait until that initial set has finished. It precedes the configured final screen and contains its own heading, description, empty email field and confirmation control; it does not repeat the study presentation or withdrawal controls.

The same normalized address proceeds to the final screen. A different address opens the change-confirmation modal; accepting updates the same participation. An address already belonging to another participant is rejected. The retry preserves the original assessment, and a valid correction still reaches its final screen. Responses and their process metadata are unchanged by correcting the address.

Reviewing/correcting an address is distinct from verification through an email link. It does not mark the address verified or send an unnecessary standalone validation email. Future notifications use the corrected address.

The final screen contains the assessment's configured content and no repeat or return control. Applicable notices appear above and outside its card, close with × or disappear after 40 seconds. Refreshing returns to the initial study screen. The change-confirmation modal shades the full viewport, including when the assessment is embedded, and preserves its typography and button styles.

## Email links and notification content

Current email templates cover validation, assessment availability and reminders. Subject and HTML come from the selected study template snapshot, with escaped named variables. The sender address comes from SMTP configuration. The transport does not append an extra footer outside the template.

Opening an eligible assessment email link also validates the address on its first successful verification. An expired assessment link can still verify the address while its access credential remains valid; the engine then selects the current assessment or shows availability notices. It never forces an expired or skipped opportunity open. There is no extra access-confirmation page.

There is no participant switch to disable/re-enable communications; leaving the study is handled by the configured withdrawal flow. Withdrawal uses the supplied study email and its on-screen typed confirmation, not a separate withdrawal email.

## Scheduling and delivery

Availability and reminder intents are linked to the scheduled opportunity, purpose and schedule revision. Reminder offsets are validated, deduplicated and excluded when at or after expiration. Only selected and eligible assessments produce notifications. Workers recheck availability, participant status and delivery policy before sending.

SMTP acceptance is not proof of inbox delivery. A crash after acceptance and before recording success can permit duplicate delivery; the system does not promise exactly-once delivery.

## Security and operations

Access credentials, participant authorization and questionnaire session retention are separate concepts. The same browser is reused only for its matching active participation; other identities remain isolated. URLs and diagnostic output must not expose bearer tokens or email addresses.

The Native document uses a same-origin referrer policy so browser form navigation preserves its origin. Cross-origin and null-origin confirmation requests remain rejected. The participant route is independent of site headers and footers.

Server-key status and generation belong to **Configuration → Setup**. See [server keyrings](server-keyrings.md), [Setup Center](../setup-center.md), [configuration](../user-guide/configuration.md) and [permissions](../permissions-and-roles.md). No institutional deployment, real SMTP delivery or external scheduler certification is implied by the automated gate.
