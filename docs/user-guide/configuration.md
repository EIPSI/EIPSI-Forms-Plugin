# Configuration

[Documentation index](../README.md)

Open **EIPSI Forms → Configuration**. These settings belong to the plugin installation; assessment and study content is edited in its own editor.

## Setup and configuration warnings

Start with **Setup**, the first tab. Generate the initial server keys, review the database/schema status, and check the scheduler, email, privacy and optional API cards. [Setup Center](../setup-center.md) explains the backup requirement.

Outside Configuration, one combined warning identifies pending Setup, database or SMTP configuration. Inside Configuration, separate warnings link to pending components except the tab already open. Yellow icons mark the pending Setup, Database & Storage and SMTP tabs. Users see only warnings for settings they are allowed to manage. WordPress storage is valid without a separate external database. Explicitly disabling a configured connection shows a database warning; saving or disabling it updates the tab icon immediately. SMTP is needed for email delivery; its warning does not prevent preparing or running email-free assessments.

Server-key diagnostics are located in **Setup**, not duplicated in Privacy & Security.

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

### Email testing and delivery diagnostics

The test reports whether the configured transport accepts the message; it does not require a participant record. An optional researcher Reply-To address is not an SMTP prerequisite. Delivery statistics use current Study Engine transport attempts, including retryable failures; an accepted message is not proof of inbox delivery. Test messages are excluded, and the success rate is unavailable until there are outcomes. If automatic emails do not arrive, inspect Scheduler activity and the study communication/job records as well as SMTP. A successful manual test does not establish that scheduled jobs are running.

A still-open opportunity blocked by a protocol dependency keeps its email pending until it becomes eligible. Study-level notice settings and selected templates are read from the latest published Initial screen settings even for earlier participations. Disabling notices defers eligible queued notices; re-enabling can deliver them while their original opportunity remains open. Expired or closed opportunities do not send their notices. Historical cancelled notices are not resent automatically.

## Page caching

Study and assessment pages contain private, changing participant state. Their HTML must not be served from a page cache. A cached copy can show an outdated session or assessment state.

EIPSI Forms sends no-cache instructions automatically. Manual exclusions are needed when a cache service overrides or does not honor those instructions; not every installation requires an extra rule. Check this when setting up or changing a cache plugin, hosting cache, reverse proxy or CDN.

### General instructions for any cache service

1. Find the service's page-cache exclusions or cache-bypass rules. If both the hosting provider and a CDN cache HTML, review both layers.
2. Exclude requests whose URL contains **any one** of these query-parameter names, regardless of its value:

   ```text
   eipsi_study
   eipsi_native
   eipsi_native_asset
   ```

   For example, `/?eipsi_study=STUDY_KEY` must bypass the page cache for every study key. These are parameter names, not WordPress tags. If the service only accepts URL patterns, ask its support team to translate this rule into its supported syntax; do not assume LiteSpeed's syntax works in another service.
3. Save the rules and purge previously cached participant pages in every relevant layer. New exclusions do not necessarily remove copies already stored.
4. Open the real public study link in a private browser window and enter a disposable test participation. In the browser's Network panel, check the study document and the assessment document loaded inside its iframe. Their cache headers should indicate bypass/non-cacheable behavior, not a page-cache hit. Header names vary by service. Test answering, navigating and reloading as well.

These exclusions apply to all studies in that installation; they are not configured separately for each study. Keep normal caching for the rest of the website and versioned static CSS/JavaScript. Page-cache exclusions do not require disabling the database object cache.

If your service has no suitable exclusion controls, ask your hosting provider to bypass HTML caching for requests containing those parameters before collecting responses.

### LiteSpeed Cache

For LiteSpeed Cache, open **Cache → Excludes → Do Not Cache Query Strings** and add each key on its own line:

```text
eipsi_study
eipsi_native
eipsi_native_asset
```

Save and purge the affected page cache. Reopen the evaluation and check for `X-LiteSpeed-Cache-Control: no-cache` and that its HTML is not served with `X-LiteSpeed-Cache: hit`.

See LiteSpeed's [query-string exclusions and cache verification](https://docs.litespeedtech.com/lscache/lscwp/cache/#do-not-cache-query-strings).
