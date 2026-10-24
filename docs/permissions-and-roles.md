# Permissions and roles

EIPSI Forms authorizes administrative WordPress users through its own capabilities. Assign roles through **WordPress → Users → Edit user → Role**. Only a WordPress administrator (or an authorized network administrator) manages WordPress users. EIPSI does not add a separate user-management screen.

## Choosing a role

- **EIPSI Administrator:** all current EIPSI administration, including SMTP, storage/schema, privacy, external API and scheduler. This role does not grant WordPress settings, user management, plugin installation or theme administration.
- **EIPSI Researcher:** create, edit, publish, duplicate, archive and delete assessments/studies; configure their conditions/randomization; operate participant assignments; view progress/responses, export data and edit email templates. Global infrastructure and identity lookup/export are excluded.
- **EIPSI Data Manager:** read assessment/study context, progress and responses; create/download datasets and dictionaries; consult audit. Cannot change/publish/delete protocols or edit configuration/templates.
- **EIPSI Viewer:** read assessment/study context and progress. Cannot read individual responses, access identities/audit, export datasets or mutate content/configuration.

The WordPress Administrator role receives every EIPSI capability during installation/upgrade. Existing users keep their roles. Other WordPress roles do not gain EIPSI access automatically. EIPSI Administrator and Researcher also receive WordPress `read` and `upload_files` for the standard media selector; Data Manager/Viewer receive `read` only. Media uploaded to the WordPress library follows WordPress media permissions.

## Capability matrix

| Action / capability | WP Admin | EIPSI Admin | Researcher | Data Manager | Viewer |
|---|---:|---:|---:|---:|---:|
| `access` / `eipsi_access` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `assessments.view` / `eipsi_view_assessments` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `assessments.edit` / `eipsi_manage_assessments` | ✓ | ✓ | ✓ | — | — |
| `assessments.publish` / `eipsi_publish_assessments` | ✓ | ✓ | ✓ | — | — |
| `assessments.delete` / `eipsi_delete_assessments` | ✓ | ✓ | ✓ | — | — |
| `studies.view` / `eipsi_view_studies` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `studies.edit` / `eipsi_manage_studies` | ✓ | ✓ | ✓ | — | — |
| `studies.publish` / `eipsi_publish_studies` | ✓ | ✓ | ✓ | — | — |
| `studies.delete` / `eipsi_delete_studies` | ✓ | ✓ | ✓ | — | — |
| `progress.view` / `eipsi_view_progress` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `participants.manage` / `eipsi_manage_participants` | ✓ | ✓ | ✓ | — | — |
| `responses.view` / `eipsi_view_responses` | ✓ | ✓ | ✓ | ✓ | — |
| `data.export` / `eipsi_export_data` | ✓ | ✓ | ✓ | ✓ | — |
| `identities.view` / `eipsi_view_identities` | ✓ | ✓ | — | — | — |
| `audit.view` / `eipsi_view_audit` | ✓ | ✓ | ✓ | ✓ | — |
| `templates.manage` / `eipsi_manage_templates` | ✓ | ✓ | ✓ | — | — |
| `settings.manage` / `eipsi_manage_settings` | ✓ | ✓ | — | — | — |
| `email.manage` / `eipsi_manage_email` | ✓ | ✓ | — | — | — |
| `privacy.manage` / `eipsi_manage_privacy` | ✓ | ✓ | — | — | — |
| `api.manage` / `eipsi_manage_api` | ✓ | ✓ | — | — | — |
| `scheduler.manage` / `eipsi_manage_scheduler` | ✓ | ✓ | — | — | — |
| `schema.manage` / `eipsi_manage_schema` | ✓ | ✓ | — | — | — |

Capabilities are primitives; there is no new per-user ownership of assessments or studies. Authorized users share access to current plugin objects. Editing randomization and conditions is part of editing the corresponding protocol; viewing an authored protocol does not grant access to participants' realized decisions or responses.

## Security boundaries

Every administrative operation requires `eipsi_access` and its action capability. Menus are an aid: pages, AJAX dispatch and sensitive owners repeat checks server-side. Valid nonces do not grant authorization, and capabilities do not replace nonces/method/origin checks.

Archiving a Study closes enrollment and therefore requires study publication/lifecycle permission, including through direct owner calls. Draft-edit permission alone cannot pause a published study by archiving it.

Study Management separates aggregate progress, response inspection, identities and audit. Dataset exports require `eipsi_export_data`; scientific content also requires response access, and identities/audit attachments require their separate capabilities. Existing export jobs remain bound to their creator. Permissions are checked again when stepping or downloading a saved job: removing access prevents later download, including jobs created before revocation. Exported content itself is unchanged.

Configuration is checked per tab: Database/Schema, SMTP, Privacy, External API, Scheduler and fallback messages. Global language/fallback texts use general settings permission. An SMTP administrator need not edit email-template content, and a template author need not see SMTP credentials. A per-study lifecycle action does not grant global privacy settings access.

The retired mixed longitudinal-management helper is restricted to schema administrators; granting native participant-management rights does not grant its old identity-bearing RPCs. Classic retired WordPress endpoints are not added to the new role menus.

Public participants do not need WordPress users or EIPSI roles. Their current assessment sessions, email links and public Study flows retain their existing authorization. External API clients continue using API keys, scopes and grants: WordPress administrative roles neither replace a key nor widen its scope. Internal cron/workers retain their existing trusted entry points; there is no request-controlled role bypass.

## Custom and multiple roles

Custom roles can grant the same EIPSI primitives. Include `eipsi_access` plus the required action capabilities and normal WordPress `read`. For example, a read-only assessment role uses `read`, `eipsi_access`, and `eipsi_view_assessments`. Scientific Management/export workflows additionally need study/progress context capabilities. Roles combine through WordPress's normal rules; the policy checks capabilities, never role slugs. Explicit per-user denied capabilities remain effective.

Do not grant identity, API, schema or infrastructure permissions merely to make a hidden page appear. Assign the actions the person's job actually requires.

## Installation, upgrades and removal

The model is versioned by site option `eipsi_rbac_version`. Version 1 creates absent EIPSI roles and grants the new capabilities to the WordPress administrator. A current version performs no role writes. Existing EIPSI-role customizations and custom roles are preserved; users are never reassigned automatically. Future model versions require deliberate migrations.

Deactivation preserves roles, capabilities and user assignments. Reactivation does not reset customized roles. The plugin has no hard-uninstall routine that removes assigned roles; deleting plugin files does not silently reassign its users. A WordPress administrator may later review unused roles through normal WordPress administration/tools.

## Multisite

Roles and model versions belong to each site, not the entire network. Existing sites are initialized when the active plugin loads or the loaded plugin switches to that site; new sites are initialized after WordPress finishes populating core roles. A site administrator receives EIPSI permissions on that site without becoming a network super administrator. Super administrators use WordPress's native capability resolution. Switching sites must not leak the previous site's roles.

The existing scheduler prohibition on editing a shared `wp-config.php` from multisite remains in effect even for EIPSI administrators/super administrators. Configure network infrastructure separately. This role model does not certify an institutional deployment or third-party role-management extensions.

## Verification

Automated tests use disposable users and real administrative pages/AJAX, owner-level negative checks, invalid nonces, exports and revocation, custom/multiple roles, and real WordPress multisite initialization. The human review checklist is separate and must not be auto-approved.

Setup Center uses `eipsi_access` plus `eipsi_manage_privacy` for its sensitive status and explicit initial server-key provisioning. This permission does not expose key values and cannot reset an existing encrypted store. See [Setup Center](setup-center.md) for backup and salt dependencies.

Configuration readiness warnings follow the same component permissions: Setup uses privacy.manage, Database & Storage uses schema.manage, and SMTP uses email.manage. A user with only one of these permissions sees only that component, its warning and its permitted link; the combined warning does not expose the other components. No extra capability was added for the visual changes.
