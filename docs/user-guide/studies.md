# Study Engine

[Documentation index](../README.md)

A study connects published assessment versions with a participant route. Open **EIPSI Forms → Study Engine** to create a study or use its card to edit, manage, or copy the public link.

## General settings

Set the study name, public URL key, time zone policy, date format, and applicable participant messages. Choose cross-sectional or longitudinal design. The study keeps the language captured at creation; it has no separate language selector.

Saving keeps a draft. Publishing creates a fixed protocol version. **Activation is a separate action**: a published draft is not automatically an open study. Participants and responses remain tied to the versions used for their route.

## Cross-sectional

Use an immediate assessment, or add several candidate assessments in a randomization and select one per participant. Select the published version of each candidate. The participant completes the assigned assessment without a longitudinal email/calendar flow.

**Unique response** limits participation by IP when enabled. It is a study setting; a new browser session and a new eligible study participation are not the same concept.

## Longitudinal

Add a longitudinal block and schedule its interactions in the visual editor. Each interaction can present a selected assessment or a randomization among candidate assessments, with versions and weights as offered in its properties.

Choose fixed or relative timing. Fixed timing uses the calendar's start/end controls. Relative timing uses a trigger and an elapsed delay, such as time after study start or email validation. The displayed relative day is derived from the delay; it is not another independent delay.

Configure expiration, reminders, required opportunities, and unique response as needed. Longitudinal unique response applies per scheduled opportunity. When repetition is allowed, it is limited by that opportunity's open period.

Use **Repetition rules** to add patterned interactions or extend the last block of days, weeks, fortnights, months, or a custom number of days. Review the generated opportunities and their relative offsets before publishing.

## Logical conditions

The track view shows possible routes through randomizations and conditions. Select an assessment or question and open the condition dialog. Build its expression using the available sources and operators, then choose its action and destination.

Sources and destinations depend on the track: earlier answers can inform a condition, while actions point to eligible future elements. Actions include question jumps, ending an assessment, waiting, and cancelling elements. Cancelling a repeated assessment targets the selected scheduled opportunity. Use the help dialog for operation details and inspect truncated/impossible routes in the track view.

## Initial screen

This tab appears for longitudinal studies. Edit the title, description, email authentication field, footer, and withdrawal button in the visual editor. Select an element to see its properties.

Email authentication controls verification before the first assessment, from the second opportunity, or without requiring initial verification. Where enabled, confirming the address after a directly entered first assessment lets the participant check/correct it. Choose the three email templates in this element's properties; edit their content in Email Templates.

Edit withdrawal separately: the choice dialog, confirmation dialog, and final message have their own visual sections. Participants enter a registered email and confirm the chosen action on screen. The two choices retain data for analysis or request removal from use and local deletion. Withdrawal is distinct from the researcher's administrative finalization of the study.

## Tests

Validation checks whether the current draft can be published and reports problems to review. The simulation uses a dummy participant and captured, simulated emails; it does not save real participant responses or deliver mail.

In a longitudinal simulation, advance time, open the initial screen or simulated email link, and answer assessments to explore conditions. Change the numeric seed and restart to try another randomization. Cross-sectional simulation has no longitudinal time/email controls. Complete a real disposable participant route before sharing a study for collection.

## Study state and management

- **Draft:** the study has not yet started.
- **Active:** the study admits participants according to its protocol.
- **Paused:** admission and new assessment access are restricted. Already started assessments follow the continuation rules; responses are not erased.
- **Finalized:** previous participations stop. The finalization dialog offers email removal, selected by default, while retaining recorded research responses.

Reopening uses the same study and public URL but starts a new cycle. Old sessions and assignments are not restored, and the same email is treated as a new participation in that cycle.

Use **Manage** for recorded participation, communications, audit, and exports. See [data and management](data-and-api.md).
