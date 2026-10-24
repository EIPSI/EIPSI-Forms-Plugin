# Study Engine server keyrings

## Purpose

For a normal WordPress installation, open **EIPSI Forms → Configuration → Setup → Generate server keys**, explicitly confirm the backup requirement, and reload to verify both keyrings. See [Setup Center](../setup-center.md) for the encrypted store, recovery dependency on WordPress salts and its first-run checklist. No manual PHP editing is required on that path.

This guide also documents the **advanced/server-managed** alternative. Existing constants and environment values remain supported and are never copied into the managed store. Activation, updates, cron, repair and runtime never generate keys automatically. With existing executions, inventory and securely back up current keys before any change.

## Required keyrings

| Configuration name | Consumer and purpose | Required for |
| --- | --- | --- |
| `EIPSI_STUDY_ENGINE_KEYRING` | `SeedVault`: AES-256-GCM encryption of a random 32-byte seed for each durable Study execution | Public transversal and longitudinal Studies; resuming executions, Native Study bridges/conditions and longitudinal workers |
| `EIPSI_STUDY_ENGINE_ACCESS_KEYRING` | `Capability`: HMAC-SHA256 authentication of opaque email access/validation links | Longitudinal public entry and mail workers |

Use **two independent keys**, one in each keyring initially. No shared key is needed. Study drafts, publishing and simulations do not themselves encrypt durable participant seeds; publication does not prove that the web server has the keys. Authorized administrators receive a setup notice when required configuration is unready; authoring and publication semantics are unchanged.

The access keyring **signs**, rather than encrypts, tokens. Tokens contain a key ID, random nonce and MAC; scope, expiry, participant, Study and opportunity are checked against server-side records. Only token hashes are stored. Participant cookies/recovery hashes and assessment sessions use their own existing mechanisms; they are not encrypted with this access keyring. No extra recovery-email or email-withdrawal flow is introduced by key configuration.

## Exact format

External sources accept either a PHP associative array constant or a string containing a JSON object. Environment values must be JSON strings. The managed resolver supplies exactly the same validated array from its encrypted store. Each has `current` (active key ID) and `keys` (key ID → hex key). No `version`, `algorithm`, base64 or raw-byte key field is required.

These **public, fictitious** examples demonstrate syntax only. **Do not deploy these example values. Replace the entire two definitions with the output of the secure generator.**

```php
define('EIPSI_STUDY_ENGINE_KEYRING', array(
    'current' => 'SEED_1',
    'keys' => array('SEED_1' => '1111111111111111111111111111111111111111111111111111111111111111'),
));
define('EIPSI_STUDY_ENGINE_ACCESS_KEYRING', array(
    'current' => 'ACCESS_1',
    'keys' => array('ACCESS_1' => '2222222222222222222222222222222222222222222222222222222222222222'),
));
```

Each hex value must be **exactly 64 lowercase characters `0–9a–f`**, decoded to 32 bytes. Some internal seed readers accept uppercase hex, but the public route and workers require lowercase: use lowercase everywhere. Every retained entry must be valid, not just the active one. `current` must resolve to an entry in that same keyring.

Seed IDs follow `^[A-Za-z][A-Za-z0-9_.:-]{0,127}$`. Access IDs follow `^[A-Za-z0-9_-]{1,64}$`. For portable IDs in both, use `SEED_1` / `ACCESS_1` and subsequent numbered labels. IDs are labels, not key material. Multiple retained entries are supported; one is active for new encryption/signing. OpenSSL with `aes-256-gcm` must be available for seed encryption.

## Secure generation

Use PHP CLI on a trusted computer or server. This works without loading WordPress and does **not** configure the site. From the plugin source directory, on Linux/macOS:

```sh
mkdir -m 700 "$HOME/eipsi-private"
php scripts/generate-eipsi-keyrings.php "$HOME/eipsi-private/eipsi-server-keyrings.php"
```

If the directory already exists, keep it and check that it is private instead of creating it again. On Windows, choose a directory readable only by your account and pass its full path as the command's destination. PHP must be installed locally; Hostinger SSH is not required when generating locally.

The generator independently calls `random_bytes(32)` twice, hex-encodes the results, and writes ready-to-use PHP definitions into the specified new file with mode 0600. It refuses to overwrite an existing file and prints no key values. Windows permissions must also be protected with the operating system's ACLs. Do not generate into the repository or a publicly served directory. Do not run the generator as a public web page; its HTTP entry returns 404.

For an administrator who deliberately needs one new rotation key, `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'` prints one key to that terminal; use a private terminal and protect its output. Never substitute a password, UUID, timestamp, WordPress salt or hash of a domain.

## wp-config.php setup

For traditional WordPress hosting, `wp-config.php` is usually simplest:

1. Securely back up the existing configuration.
2. Open the generated private PHP file locally. Copy **only the two `define(...)` statements**, without its opening `<?php` tag.
3. Paste them once into the actual site's `wp-config.php`, **before** `/* That's all, stop editing! */` (or its translated equivalent), and before `require_once ABSPATH . 'wp-settings.php';`.
4. Save. Keep the existing WordPress database configuration and salts intact. Do not add duplicate definitions.

The generator supplies the complete definitions; no guesswork about where to place hex values is needed. The example above is not an alternative source of deployment keys. A separate private PHP file outside the web root can also be required by wp-config, but that path must be configured and secured by the server administrator; it is not a new plugin configuration source.

External keys are not copied into wp_options. Managed keys use the encrypted, non-autoloaded administrative option described in [Setup Center](../setup-center.md). Plaintext keys are never stored in options, Study JSON, participant HTML, API responses or scientific exports. Diagnostics cannot reveal key values.

## Environment setup

All consumers share one resolver. Precedence is **a defined constant → a present `getenv()` value → the encrypted EIPSI Secret Store** for that keyring. An empty but present environment value is invalid and does not fall back to the store. A defined but invalid constant does not fall back to a valid environment variable. String values are JSON-decoded. There is no automatic `.env` loader, plaintext option/filter fallback or secret generator in runtime. Only explicit Setup provisioning creates managed keys; reading its store cannot create or replace keys.

An environment value has this shape (fictitious values only):

```json
{"current":"SEED_1","keys":{"SEED_1":"1111111111111111111111111111111111111111111111111111111111111111"}}
```

Set a second independent JSON value for `EIPSI_STUDY_ENGINE_ACCESS_KEYRING`, with its own active ID and key. Use your process manager/hosting secret configuration, not a public PHP file or a browser query string. Avoid secrets in shell history. A shell `export` affects that shell's children, not necessarily PHP-FPM, Apache or a hosting web worker. PHP-FPM can filter environment variables; ask the host how to pass them to both web PHP and external cron/CLI. No new environment mechanism is implemented by this task.

## Hostinger setup

1. Open the correct WordPress website in hPanel and its **File Manager**. Locate that installation's `wp-config.php`, normally in its web root (`public_html`); verify the site before editing.
2. Download a private backup. Generate the two keyrings locally using the command above if SSH is unavailable.
3. Use File Manager's editor to insert the two generated definitions into wp-config as described above. Do not upload the private generator output as a publicly downloadable file.
4. Save, sign into WordPress, then check **EIPSI Forms → Configuration → Setup → Study Engine server keys**.
5. If the web process still reports missing configuration, confirm the correct file/site and syntax. Ask hosting support to refresh PHP/opcode cache if needed. Environment-based changes may need a PHP process restart; do not assume a CLI export reached web PHP.

File Manager's PHP editor and WordPress file access are documented by [Hostinger File Editor](https://www.hostinger.com/support/7949267-how-to-use-the-file-editor-at-hostinger/) and [Hostinger File Manager](https://www.hostinger.com/support/4548688-basic-actions-in-the-file-manager-in-hostinger/). Some plans restrict editing; consult [Hostinger file permissions](https://www.hostinger.com/support/1583479-how-to-fix-file-permissions-in-hostinger/). These instructions do not assume an SSH service or a general-purpose PHP environment-variable UI.

## Verification

The administration table checks **this PHP web request** and shows only Yes/No for each keyring: available, valid format, active key resolvable and configured. Seed “Configured” also requires AES-256-GCM availability. Reload after server changes. This confirms process configuration; it does not prove an old execution's ciphertext matches the configured bytes or certify SMTP/delivery.

An EIPSI/WP administrator can view it with `eipsi_manage_privacy`. Authorized administrators receive a setup notice before operational use, without secret values. No publication/scientific validation rule was changed. CLI and web may differ; the web table is the check for participant requests. Verify external cron with the same configured wp-config/environment and normal scheduler/worker diagnostics, without dumping env or phpinfo.

Production checklist:

1. For normal WordPress, open Setup and explicitly generate the independent keyrings.
2. For advanced infrastructure, generate the independent pair with the CLI helper.
3. For that external path, configure both server-side before WordPress loads; managed Setup needs no wp-config editing.
4. Reload Setup after provisioning. For external changes, reload PHP/site if the hosting process requires it.
5. Confirm all four flags are Yes for both in web diagnostics.
6. Open an activated disposable Study's public URL and perform its participant flow; check a longitudinal email link as well.
7. Confirm that internal diagnostics no longer record `SEED_KEYRING_UNAVAILABLE` or access-keyring errors. Do not publish logs containing tokens or private data.
8. Back up external keys securely, or back up the managed encrypted store **plus its original eight WordPress keys/salts and blog ID**. Remove temporary local copies according to your secret handling process.

A missing seed keyring produces internal `SEED_KEYRING_UNAVAILABLE`; invalid entries produce `INVALID_SERVER_KEYRING`; a missing referenced active seed ID produces `SEED_KEY_UNAVAILABLE`. Missing longitudinal access configuration produces `ACCESS_KEYRING_UNAVAILABLE`; invalid entries produce `INVALID_ACCESS_KEYRING`; an unresolved active ID produces `ACCESS_KEY_UNAVAILABLE`. Other malformed PHP types can produce type-validation errors; use the complete documented format. Participants continue to see a generic failure, never keys or internal diagnostics. There is no weak crypto fallback.

## Rotation

For externally managed keyrings, to rotate, independently generate a new key and assign a **new ID**, add it to `keys`, then set `current` to that new ID. **Keep the old ID mapped to the exact same old bytes.** Do not rename/reuse an old ID for new bytes. Update all web workers, cron/CLI and replicas consistently. Existing encryption/signature verification selects by the stored/token ID, not by the current active ID.

Seed rotation does not re-encrypt existing executions automatically. Keep every seed key referenced by executions that may be loaded, replayed or audited, including retained backups. An expired email or finalized study does not prove that seed keys may be removed. Removal requires an administrative inventory of retained encrypted executions and backups; no automatic retirement or rewrap tool is introduced.

Access rotation keeps old links verifiable while their keys remain available. Token lifetime on mint is 1–48 hours, with actual expiry, revocation, consumption/session and purpose checked server-side. **Assessment availability can expire before its email token**; an old assessment link can still validate email while that credential remains valid. Retain the old signing key until no valid issued credential/backed-up credential requires it, unless deliberately invalidating them. Configured cookie sessions are not directly signed by this keyring, so dropping a signing key is not a blanket session-revocation operation.

## Backup

For managed keys, read the [Setup recovery matrix](../setup-center.md#backup-migration-and-loss). Changing WordPress salts can make the encrypted option unreadable; the correct backup is DB plus matching original salts/configuration. No plaintext UI export or managed rotation/reset UI is offered.

Back up keyrings with critical server configuration in an encrypted, access-controlled secret backup, separate from shared research datasets. A complete server or wp-config backup may contain them and must be protected accordingly. Preserve the ID-to-key mapping. Restoring a database alone without matching server keys does not restore execution access.

## Recovery/loss

On a truly new installation with no executions or credentials to preserve, provision fresh independent keyrings after verifying that premise. No data deletion or resetting is done by this task.

With existing executions, losing an old seed key prevents decrypting its seed and hydrating/replaying that execution. Replacing bytes under the same ID fails AES-GCM authentication. Published protocol snapshots and existing raw responses are not themselves decrypted with this key, but operational continuity and deterministic reconstruction of those executions are affected. Restore the exact old mapping from backup; never redraw seeds to disguise loss.

Losing an access key rejects links signed by it; changing bytes under the same ID invalidates their MAC. Restore the old key if possible, or use an authorized normal validation flow to issue fresh links after a correct new configuration. Email links already delivered are not rewritten. Stored answers and seed encryption are not changed by access-key rotation. Loss of either keyring must not trigger automatic regeneration.

## Security warnings

Keep actual values out of Git, README, screenshots, tickets, support chat, console recordings and normal scientific exports. Examples here are deliberately public fake values. `.gitignore` excludes `.env*` (except a deliberately fake `.env.example`), cache files and generated `eipsi-server-keyrings*.php`; packager includes only the generator code and this guide, never its output. Ignore rules do not untrack previously committed secrets: review staged files before committing.

Do not expose phpinfo or an unauthenticated “show config” endpoint. Do not paste real values into diagnostics to test them. The distributed plugin is identical on different servers; secrets are supplied per installation. Moving to another server requires a secure transfer of the existing keys if its database/executions are retained.
