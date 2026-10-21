# Configuration

[Documentation index](../README.md)

Open **EIPSI Forms → Configuration**. These settings belong to the plugin installation; assessment and study content is edited in its own editor.

## Language

The language card above the tabs selects **English** or **Spanish** for the plugin interface and newly created content. English is the default. The choice is shared across administrators and does not change the language of WordPress or other plugins.

An existing assessment, study, or email template keeps its original language and text. New questions inside an existing assessment use that assessment's original language. Published versions do not change when you change this setting.

## Database & Storage

WordPress keeps the plugin's internal study and participation records. The storage panel also configures an external destination for the submission flows that support external storage; choosing it does not relocate every plugin table or migrate existing records.

For external storage, use a database dedicated to EIPSI Forms and a dedicated database user. This keeps database access, backups, and maintenance easier to administer. Ask the person managing your server to create the database and grant the access required for the plugin to create and maintain its tables.

Enter the host, database name, username, and password, run **Test Connection**, and then **Save Configuration**. A successful connection test is a check, not a saved configuration. Review the status after saving. You can keep the WordPress database when external storage is not needed.

## SMTP

Configure the mail provider, SMTP connection, and sender here. Email Templates controls the email content; SMTP controls transport. Use the email test to check the configured connection. For Gmail or another provider, use the credentials and authentication method permitted by that provider.

A transport reporting success means it accepted the message. It does not by itself prove inbox delivery or that a participant read the message.

## Privacy & Security

Choose which process and client metadata the plugin may capture. The global selection is a ceiling: an assessment's metadata profile can capture only fields permitted here. Changes apply to future capture; they do not rewrite earlier responses.

Review these choices before collecting data. [Data and management](data-and-api.md) explains how responses, identities, and process metadata are separated.

## Schema Status

Use this tab to inspect the database structure required by the plugin and see missing or inconsistent components. It is a structural check, not a measure of response quality or a substitute for backups. Follow the panel's supported repair controls when it reports a problem.

## External API

Enable the API only when you need an external analysis workflow. Create an API key, choose permissions and study access, and use **How to connect** for the supplied R/Python examples. Keep keys outside shared scripts and notebooks. The API is separate from the participant's public study link.

See [data and API](data-and-api.md) for the download workflow and [API reference](../external-api/README.md) for requests and permissions.

## Scheduler

The scheduler advances scheduled work and sends eligible notifications. Use either WordPress cron or the external scheduler configuration shown in the panel. The status and activity views report observed executions; a configuration without a recorded execution is not proof that scheduled work is running.

See the [scheduler guide](../scheduler/Scheduler%20and%20WP-Cron%20Guide.md) for server setup and verification.

## Fallback messages

**Fallback messages / Mensajes de respaldo** contains the texts used when a study definition cannot be loaded. These fields also have their own locations in Study Engine. Whenever the definition is available, the study's saved messages take precedence.

Changing a fallback does not edit an existing study or assessment. A nonexistent study link is not a separate participant page populated from these fields.
