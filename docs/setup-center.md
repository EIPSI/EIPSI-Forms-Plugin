# Setup Center

Open **EIPSI Forms → Configuration → Setup** after activation. In Spanish: **Configuración → Configuración inicial**. This is the first tab for EIPSI/WP administrators and other users with `eipsi_access` plus `eipsi_manage_privacy`. Researchers, Data Managers and Viewers do not have that permission by default.

## First installation

1. Install and activate the plugin. Activation does **not** generate server keys.
2. Open Setup. Review the required **Study Engine server keys** and **Database / schema** cards.
3. On a new site without key configuration, read the backup requirement, explicitly check its confirmation, then click **Generate server keys**. No PHP editing is needed for a standard WordPress installation with its normal eight installation keys/salts and OpenSSL AES-GCM.
4. After redirect/reload both keyrings should be **Managed by EIPSI**, with all four flags Yes. The button disappears. Keys are not shown or downloaded.
5. Back up the database **and** the original eight WordPress keys/salts in `wp-config.php` securely. The database alone cannot recover these managed secrets.
6. Review Scheduler, Email, Privacy and External API according to your protocol, then create and test your Study through its public link.

The keys card uses the same resolver and diagnostic as Privacy & Security and the public runtime. It shows availability, valid format, active-key resolution and configured status only. It does not verify old ciphertext against every historical execution.

## Cards and readiness

| Card | Meaning | Action |
| --- | --- | --- |
| Study Engine server keys | Required. Both keyrings are part of complete Setup; every public Study needs seed keys, and longitudinal access also needs access keys. | Generate **initial** keys when no source exists; key diagnostics remain here in Setup (they are not duplicated in Privacy & Security). |
| Database / schema | Required. Reads the existing registry/migration/Native schema diagnostic. | Open Database settings (Schema Status); no automatic repair. |
| Scheduler / cron | Recommended. Reuses SCH-1: WP-Cron setting, confirmed external activity, last heartbeat and overdue events. | Open Scheduler. Viewing Setup does not trigger cron. |
| Email | Depends on use. SMTP configured or normal WordPress mail fallback. This does not certify delivery. | Open Email settings. No email is sent on page load. The SMTP owner has no persisted delivery-test result to display. |
| Privacy & Security | Saved metadata policy versus defaults awaiting review, without a fabricated privacy score. | Open Privacy & Security. Saving that policy remains in its own section. |
| External API | Optional. Disabled is valid; when enabled, shows whether any non-revoked/non-expired API key exists. It does not show keys or grants. | Open External API. |

Global **Critical configuration missing** means either required component is unready. **Needs attention** covers scheduler/privacy review or an enabled API needing attention. **Ready** means the required components are configured and the reported recommended/review components have no outstanding flag. It is not institutional, research or SMTP certification. Optional SMTP and a disabled API do not block authoring or make the global state critical.

Buttons only link to advanced tabs allowed by the current user's capabilities. Setup does not change other tabs' configuration, run repairs or send test mail. Other EIPSI admin pages show one combined **Configuration needs attention** warning when Setup keys, database/schema, or SMTP needs attention. Configuration instead shows a separate warning for each pending component except the currently open tab; Setup, Database & Storage and SMTP tabs share the yellow warning icon. Warnings include only components the user is authorized to administer and never block authoring. WordPress storage with a healthy schema is valid without an external database; an explicitly disabled saved connection, or an enabled external destination with missing settings or a recorded connection failure, needs review. Saving or disabling the connection updates its tab icon immediately. A missing SMTP warning does not make SMTP mandatory for email-free studies.

## Sources and existing installations

Precedence is **explicit constant → environment variable → EIPSI encrypted Secret Store**. An invalid higher-precedence source fails closed; it never falls back to a different key. Even an empty, present environment value counts as external configuration and must be repaired there.

Constant/environment sources show **Externally configured**. Setup cannot edit, copy, import or overwrite them. Existing installations keep working without migration. If only one external keyring exists, complete the other in server configuration; initial UI generation does not mix sources. See [advanced server configuration](study-engine/server-keyrings.md).

## Encrypted Secret Store

The administrative option `eipsi_server_secrets_v1` is separate from Study, participant, export and API tables, with `autoload=no`. It contains a versioned JSON AES-256-GCM envelope: version, fixed cipher/KDF identifiers, blog ID, random 12-byte nonce, ciphertext and 16-byte tag. The atomic payload contains both independent keyrings, with the same `current`/`keys` contract used by external configuration. Each encryption receives a fresh nonce; there is no nonce reuse or per-key byte sharing.

The 32-byte encryption key is derived with PHP's standard `hash_hkdf('sha256', ...)` from a fixed-order, unambiguously JSON-encoded mapping of **AUTH_KEY, SECURE_AUTH_KEY, LOGGED_IN_KEY, NONCE_KEY, AUTH_SALT, SECURE_AUTH_SALT, LOGGED_IN_SALT and NONCE_SALT**. HKDF uses the public salt `EIPSI_HKDF_WP8_V1` and info `EIPSI_SERVER_SECRETS_V1|blog:<ID>`. AES-GCM authenticates a fixed purpose/cipher/KDF/blog context as AAD. No derived key is stored. Domain URL, database password and table prefix are not KDF inputs.

The derivation uses the standard [PHP hash_hkdf implementation](https://www.php.net/manual/en/function.hash-hkdf.php) of [RFC 5869](https://www.rfc-editor.org/rfc/rfc5869). The fixed public HKDF salt and purpose-specific info provide stable domain separation for existing high-entropy WordPress installation secrets; this is not password hashing.

This protects against disclosure of the database by itself. It does **not** protect against an attacker who can execute PHP on the site or obtain both the database and its WordPress secrets. This trade-off allows normal WordPress setup without a new external master-key installation step. A separate master file would require file provisioning/hosting access and a separate backup dependency; managed infrastructure may instead use the existing constant/environment path.

The option is created with an exclusive `INSERT IGNORE` against WordPress's unique option name, rather than `add_option()`'s upsert. Concurrent initial requests cannot replace the winner. Secret reads use the database record, not stale option-cache authorization. SQL error reporting is suppressed for the encrypted write; the administrative audit contains only actor, UTC timestamp, operation, result and source, never keys, key hashes, nonce, tag, ciphertext or master material.

## Backup, migration and loss

**Changing any of the eight WordPress keys/salts can make the managed store unreadable.** Security tools or hosting migrations that rotate salts must take this dependency into account. Do not rotate those values on a live managed installation without a reviewed recovery/rewrap procedure. This version does not implement salt rewrap or key rotation UI.

Use encrypted, access-controlled backups of both the database (including the encrypted option) and the corresponding `wp-config.php` secrets. Do not put them in scientific exports, Git, screenshots or support tickets. No casual plaintext backup export is offered by Setup.

| Situation | Required recovery |
| --- | --- |
| Database lost | Restore the database and its matching original WordPress configuration; a new empty DB does not contain the managed keyrings. |
| Any WordPress key/salt changed | Restore the original eight values along with the matching encrypted record. Do not generate replacement keys to bypass the failure. |
| Secret record deleted or corrupt | Restore the matching encrypted option from a secure database backup. If durable executions or access credentials exist, initial generation is rejected. |
| Site moved to another server/domain | Move DB **and** matching eight WordPress secrets. Normal migration retaining the same blog ID works across URL/server changes. Verify Setup and public/longitudinal access after the move. |
| Individual multisite blog moved to a different blog ID | Blog ID is authenticated/derived context. Preserve the original ID or use a reviewed secret rewrap/configuration procedure; changing it alone does not recover managed keys. |
| No records, no credentials, no keys, truly fresh installation | Use explicit initial generation. This is not an automatic recovery/reset operation. |

A decryption failure shows a safe administration error and fails closed in the public runtime. There is no automatic regeneration on activation, update, cron, repair, read or failure. Deactivation/reactivation preserves the encrypted store.

## Rotation boundary

No reset/regenerate button exists. This version implements **initial provisioning, status and backup guidance**. Existing external keyrings retain their established rotation contract: add a new ID/key, make it current, keep historical ID-to-key bytes. Managed rotation requires a future reviewed management procedure; do not replace the encrypted record manually on an active site. Old encrypted execution seeds and signed links still require their historical keys.

Server keys appear as a full-width section immediately after the tabs, before the summary and the five status cards. Those five cards use equal grid widths and heights. The key table can scroll within its section on narrow screens.
