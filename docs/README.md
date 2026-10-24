# EIPSI Forms documentation

The user guides describe the current workflow: **Configuration → Email Templates → Assessment Editor → Study Engine → Study management**. Interface labels may appear in English or Spanish according to the general language setting.

## User guides

- [Your first study](user-guide/getting-started.md): a short walkthrough from setup to participation.
- [Configuration](user-guide/configuration.md): language and every configuration tab.
- [Assessments](user-guide/assessments.md): question design, messages, randomization, versions, and JSON exchange.
- [Email Templates](user-guide/email-templates.md): purposes, editable content, variables, and study copies.
- [Studies](user-guide/studies.md): cross-sectional and longitudinal design, conditions, initial screens, tests, and lifecycle.
- [Data and management](user-guide/data-and-api.md): follow-up, datasets, metadata, privacy, and API connections.

## Implementation and detailed references

These references contain engineering details and phase history. For the current visual workflow, start with the user guides above.

| Topic | Reference |
| --- | --- |
| Build and development | [Development](development.md) |
| Automated checks | [CI test guide](../tests/ci/README.md) |
| Assessment question properties | [Question authoring](assessment-editor/question-authoring.md) |
| Assessment JSON | [Import and export](assessment-editor/json-portability.md) |
| Published assessment versions | [Versions](assessment-editor/published-versions.md) |
| Logical conditions | [Operations and help](study-engine/logical-conditions-help.md) |
| Study management | [Queries, audit, and export](study-engine/management/README.md) |
| Dataset and metadata | [Canonical dataset contract](dataset/Canonical%20Dataset%20and%20Metadata%20Policy.md) |
| External API | [API documentation](external-api/README.md) |
| Scheduler | [WP-Cron and external scheduler](scheduler/Scheduler%20and%20WP-Cron%20Guide.md) |
| Database structure | [Schema](schema.md) |

The technical documents record decisions at different development stages. An older phase description is not a promise that a retired screen remains part of the current product.

## Installation and compatibility

- [Installation and distribution packages](installation.md)
- [Compatibility matrix and measured limits](compatibility.md)

- [Public Study runtime diagnostics](study-engine/public-runtime-diagnostics.md)

- [Permissions and roles](permissions-and-roles.md): team access, capability matrix, data boundaries and multisite.

- [Study Engine server keyrings](study-engine/server-keyrings.md): exact format, manual generation, Hostinger, web verification, rotation and recovery.

- [Setup Center](setup-center.md): first-run cards, explicit encrypted key provisioning, source precedence and backup/recovery.
