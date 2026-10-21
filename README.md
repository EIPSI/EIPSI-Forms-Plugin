# EIPSI Forms

EIPSI Forms is a WordPress project by [EIPSI](https://equipoeipsi.com/) for creating assessments and running cross-sectional and longitudinal research studies. It brings questionnaire design, study planning, participant communication, and research data management into one place.

The goal is to let researchers build and review the participant experience visually, while keeping published assessments, study versions, and collected responses traceable.

## Before your first study

Open **EIPSI Forms → Configuration** and review these essentials before collecting responses:

- **Interface language:** choose English or Spanish for the administration interface and initial default texts. You can edit participant-facing content in any language; existing content keeps its saved text.
- **Storage and backups:** confirm where responses will be stored and arrange backups. You can use the WordPress database; if using external storage, prefer a dedicated database, test the connection, save it, and check the saved status.
- **Email delivery:** for studies that use email, configure SMTP and the sender, send a test, and verify receipt. Review the Email Templates, including their links and variables.
- **Scheduled work:** for timed evaluations and notifications, configure WP-Cron or an external scheduler and verify recorded executions. This is essential for longitudinal studies.
- **Privacy:** review the identifying data and optional metadata you will collect before enrolling participants.
- **Readiness:** check Schema Status, the study's time zone and timing, and its validation results. Try the simulation and the real public link with disposable data before activating recruitment.

The external API is optional; configure it only if you need connected analysis tools. See the [configuration guide](docs/user-guide/configuration.md) for each setting.

## How it works

1. **Create an assessment.** Add questions, pages, instructions, response validation, and internal randomization in Assessment Editor.
2. **Publish a version.** Saving keeps an editable draft; publishing fixes a version that a study can use.
3. **Build a study.** Choose a cross-sectional or longitudinal design, select published assessment versions, and configure timing, randomization, and logical conditions.
4. **Prepare the participant experience.** Edit the initial screen and emails where relevant, review validation results, and try the simulation.
5. **Activate and share.** Publish the study, activate it, and share its public link. Participants see the study content without the website header or footer.
6. **Follow the study and use its data.** Review participation, responses, communications, and audit records. Download datasets or connect an authorized R/Python workflow through the API.

## Main sections

| Section | What it is for | Guide |
| --- | --- | --- |
| Configuration | Language, storage, SMTP, privacy, schema status, API, scheduler, and fallback messages | [Configuration](docs/user-guide/configuration.md) |
| Email Templates | Reusable subjects and rich-text email bodies with study variables | [Emails](docs/user-guide/email-templates.md) |
| Assessment Editor | Questions, pages, validation, appearance, previews, and published versions | [Assessments](docs/user-guide/assessments.md) |
| Study Engine | Study design, scheduling, randomization, conditions, simulation, and publication | [Studies](docs/user-guide/studies.md) |
| Study management | Participation, responses, audit, and consistent manual/API exports | [Data and management](docs/user-guide/data-and-api.md) |

Start with [your first study](docs/user-guide/getting-started.md), or browse the [documentation index](docs/README.md).

## Language and content

The administration interface is available in **English** (default) and **Spanish**, selected in Configuration. This setting also supplies the initial default texts for new content; it does not limit the language of your research.

You can write and edit participant-facing content in **any language**, including assessment questions, instructions, buttons, messages, study screens, and email templates. Changing the interface language does not translate existing content or published versions.

Participant-facing text is edited in its corresponding section: assessment messages in Assessment Editor, study messages in Study Engine, and email content in Email Templates.

## Technologies

EIPSI Forms runs inside WordPress. It uses PHP for server-side operations, JavaScript and CSS for its visual editors and participant interface, WordPress editor/media tools for rich content, and MySQL/MariaDB for persistence. Its external API supports authorized connections to analysis tools such as R and Python.

For installation, builds, and automated checks, see the [developer setup](docs/development.md) and [test guide](tests/ci/README.md). These details are separate from the user guides.

## Project and license

Maintained by [EIPSI](https://equipoeipsi.com/). Source code and issue tracking: [GitHub](https://github.com/EIPSI/EIPSI-Forms-Plugin).

Licensed under [GPL-2.0-or-later](LICENSE).
