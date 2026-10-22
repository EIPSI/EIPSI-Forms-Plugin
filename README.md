<p align="center">
  <img src="assets/eipsi-icon.svg" width="88" height="88" alt="EIPSI Forms" />
</p>
<h1 align="center">EIPSI Forms</h1>
<p align="center">Assessments and cross-sectional / longitudinal research studies for WordPress.</p>
<p align="center">
  <a href="docs/compatibility.md"><img alt="WordPress minimum 7.1" src="https://img.shields.io/badge/WordPress-7.1%2B-21759B?logo=wordpress&amp;logoColor=white" /></a>
  <a href="docs/compatibility.md"><img alt="PHP declared minimum 7.4" src="https://img.shields.io/badge/PHP-7.4%2B-777BB4?logo=php&amp;logoColor=white" /></a>
  <img alt="JavaScript" src="https://img.shields.io/badge/JavaScript-F7DF1E?logo=javascript&amp;logoColor=black" />
  <a href="docs/compatibility.md"><img alt="MariaDB 11.8.9 tested" src="https://img.shields.io/badge/MariaDB-11.8.9%20tested-003545?logo=mariadb&amp;logoColor=white" /></a>
  <a href="LICENSE"><img alt="GPL 2.0 or later" src="https://img.shields.io/badge/License-GPL--2.0--or--later-3A7D44" /></a>
  <a href=".github/workflows/technical-gate.yml"><img alt="GitHub Actions CI workflow" src="https://img.shields.io/badge/GitHub_Actions-CI%20workflow-2088FF?logo=githubactions&amp;logoColor=white" /></a>
</p>
<p align="center">
  <a href="docs/user-guide/getting-started.md">First study</a> ·
  <a href="docs/installation.md">Installation</a> ·
  <a href="docs/README.md">Documentation</a> ·
  <a href="https://github.com/EIPSI/EIPSI-Forms-Plugin/issues">Issues</a>
</p>

## What is EIPSI Forms?

A WordPress research project by [EIPSI](https://equipoeipsi.com/) that brings questionnaire design, study planning, participant communication and research data management into one place. Researchers can build and review the participant experience visually, while keeping published assessment versions, study protocols and responses traceable.

## Key capabilities

- **Assessment Editor:** questions, pages, rich content, validation, appearance, internal randomization and published versions.
- **Study Engine:** cross-sectional and longitudinal designs, relative/fixed scheduling, weighted randomization, logical conditions and simulation.
- **Participant experience:** dedicated study pages, assessment sessions and partial responses, email validation and study withdrawal.
- **Research operations:** participation management, audit records, optional metadata and manual/API datasets with a shared data model.
- **Communication:** reusable email templates, SMTP transport and scheduled availability notices/reminders.
- **Language:** English/Spanish administration and initial defaults; participant-facing questions, buttons, messages, screens and emails can be edited in **any language**.

## How it works

1. **Create an assessment.** Add questions, pages, instructions, response validation and internal randomization in Assessment Editor.
2. **Publish a version.** Saving keeps an editable draft; publishing fixes a version that a study can use.
3. **Build a study.** Choose a cross-sectional or longitudinal design, select published assessment versions, and configure timing, randomization and logical conditions.
4. **Prepare the participant experience.** Edit the initial screen and emails where relevant, review validation results, and try the simulation.
5. **Activate and share.** Publish the study, activate it and share its public link. Participants see the study content without the website header or footer.
6. **Follow the study and use its data.** Review participation, responses, communications and audit records. Download datasets or connect an authorized R/Python workflow through the API.

## Requirements

| Component | What you need |
| --- | --- |
| WordPress | **7.1 or later**. Versions 7.1 and 7.1.3 have been checked; use an up-to-date maintained release. |
| PHP | **7.4 or later** declared minimum; PHP **8.3** is the tested reference environment. |
| Database | **MariaDB 11.8.9** is the tested reference. Check the [compatibility guide](docs/compatibility.md) with your hosting provider before choosing another database version or MySQL. |

The [compatibility guide](docs/compatibility.md) explains the scope and limits of these checks.

## Installation

1. Obtain the **installation ZIP** from the project maintainer.
2. In WordPress, open **Plugins → Add New → Upload Plugin**, select the ZIP and install it.
3. Activate EIPSI Forms, then open **EIPSI Forms → Configuration**.
4. Review the setup essentials below before enrolling participants.

Use a prepared installation package: **GitHub → Code → Download ZIP is a source archive and does not include all required files**. See the [installation guide](docs/installation.md) for details.

Installing the plugin does not transfer studies, responses or settings from another WordPress installation.

## First setup

Open **EIPSI Forms → Configuration** and review these essentials before collecting responses:

- **Interface language:** choose English or Spanish for administration and initial default texts. Existing content keeps its saved text; you can author participant-facing content in any language.
- **Database & Storage:** confirm where responses will be stored and arrange backups. WordPress storage is available; for external storage, prefer a dedicated database, test the connection, save it and verify the saved status. This does not relocate every internal study table.
- **SMTP and templates:** for studies using email, configure the connection/sender, send a test and verify receipt. Review template links and variables.
- **Scheduler:** configure WP-Cron or an external scheduler and verify that it is running. This is essential for longitudinal studies: timed evaluations and notifications depend on it.
- **Privacy:** decide which identifying data and optional metadata to collect before recruitment.
- **Readiness:** review Schema Status, the study's time zone/timing and validation results. Try the simulation and real public link with disposable data.

The external API is optional. See the [configuration guide](docs/user-guide/configuration.md) for each setting.

## Main modules

| Module | Purpose | Guide |
| --- | --- | --- |
| Configuration | Storage, SMTP, privacy, schema, API, scheduler and fallback messages | [Settings](docs/user-guide/configuration.md) |
| Email Templates | Reusable subjects, rich-text bodies and variables | [Emails](docs/user-guide/email-templates.md) |
| Assessment Editor | Questionnaire design, previews, import/export and versions | [Assessments](docs/user-guide/assessments.md) |
| Study Engine | Design, scheduling, conditions, initial screen and publication | [Studies](docs/user-guide/studies.md) |
| Study management | Participation, responses, audit and exports | [Data and management](docs/user-guide/data-and-api.md) |

## Language and content

The administration interface is available in **English** (default) and **Spanish**, selected in Configuration. This setting also supplies the initial default texts for new content; it does not limit the language of your research.

You can write and edit participant-facing content in **any language**, including assessment questions, instructions, buttons, messages, study screens and email templates. Assessments, studies and templates keep their saved content. Changing the interface language does not translate existing content or published versions.

Participant-facing text is edited in its corresponding section: assessment messages in Assessment Editor, study messages in Study Engine, and email content in Email Templates.

## API and scheduler

The [external API](docs/external-api/README.md) supports authorized dataset access from R/Python, with scoped keys and downloadable examples. It uses the same dataset model as manual downloads. Enable it only when needed and keep keys out of shared scripts.

The [scheduler](docs/scheduler/Scheduler%20and%20WP-Cron%20Guide.md) runs scheduled work. Choose one scheduling arrangement and verify its activity; saving a configuration alone does not prove execution.

## Technologies

EIPSI Forms runs inside WordPress. It uses PHP for server-side operations, JavaScript and CSS for its visual editors and participant interface, WordPress editor/media tools for rich content, and MySQL/MariaDB for persistence. Its external API supports authorized connections to analysis tools such as R and Python.

## Security and privacy

EIPSI Forms handles privacy-sensitive research data. Use **HTTPS**, least-privilege administrative/database/API access, dedicated backups and a verified scheduler. Review metadata capture and data retention before collecting responses. Validate a deployment with disposable data before involving participants; no clinical or institutional certification is claimed.

## Documentation and project

Start with [your first study](docs/user-guide/getting-started.md), or browse the [documentation index](docs/README.md) for the guide to each section.

Technical installation, package preparation and testing instructions are available separately in the [installation](docs/installation.md) and [developer](docs/development.md) guides.

Maintained by [EIPSI](https://equipoeipsi.com/). Source and issue tracking: [GitHub](https://github.com/EIPSI/EIPSI-Forms-Plugin).

## License

[GPL-2.0-or-later](LICENSE).
