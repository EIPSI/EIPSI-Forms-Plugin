# External API — deployment and administration

This is factual deployment material, not certification of an institutional stack. Reference test stack: WordPress 7.1.2, PHP 8.3.35, MariaDB 11.8.9, pinned container digests in tests/ci/gate.json. Other versions/stacks must pass the gate and operational checks; generic MySQL compatibility is not asserted without testing.

## Administration

EIPSI Forms → Configuración → API externa. Enable explicitly. Create API key with a descriptive name, optional expiry, minimum internal scopes and NONE/SELECTED/ALL Study access. ALL includes future Studies. Copy the one-time secret to a secret manager: closing/reloading cannot recover it. Lists/edit/diagnostics only show metadata/mask. API keys are not WordPress users and do not use WordPress cookies or OAuth.

Rotate: create replacement → update integration securely → verify status and granted data → revoke previous key. Old export resources remain owned by the old key; create new exports with the replacement. Revocation immediately rejects requests; artifacts become terminal during bounded maintenance. Key expiration is optional, not automatically imposed by age warnings.

Operations section shows admin-only health, current logical counts/storage, timestamps UTC, export monitor, audit and metrics. Lists page 25 records. Revoke export by typing REVOCAR plus ID; purge only terminal REVOKED/FAILED/EXPIRED with no active lease by typing PURGAR plus ID. This does not delete scientific data. Never use an API key as an administrative diagnostic input; the Authorization probe sends a public nonsecret sentinel.

## Operational settings

| Field | Default | Min–max |
| --- | ---: | --- |
| Export TTL seconds | 86400 | 3600–604800 |
| Active exports/key | 4 | 1–20 |
| PREPARING/key | 2 | 1–4, ≤ active/key and global |
| Global PREPARING | 16 | 1–64 (not a count of simultaneous worker processes) |
| Own logical export bytes | 268435456 | 16777216–536870912 |
| Aggregate logical bytes | 1073741824 | 67108864–4294967296, ≥ own |
| Worker seconds/tick | 5 | 1–15 |
| Worker steps/tick | 100 | 1–500 |
| Cleanup exports/batch | 10 | 1–50 |
| Suggested polling seconds | 5 | 2–60 |
| Operational audit days | 90 | 7–365 |
| Key lifecycle/config audit days | 365 | 90–1095, ≥ operational |

All bounded integers, server validated, configuration revision checked. TTL/own size pinned at creation; changes do not reinterpret READY nor shrink a PREPARING's own budget. Aggregate budget, new admissions, maintenance and polling guidance apply to subsequent operations. Reducing aggregate capacity can block new reservations. Rates/security/scientific invariants are not UI switches. Logical quotas include references and multiple derived representations; not physical tablespace size. Metrics retained 30 days; audit cleanup is bounded, not instantaneous backlog deletion. Purged expired terminal metadata is removed after the API-2B seven-day policy.

## HTTPS, proxy and Authorization

Production HTTPS is mandatory. Local/development HTTP requires the explicit WordPress environment type; never set production to development to bypass TLS. The API does not trust arbitrary X-Forwarded-Proto. Current transport proof requires HTTPS detected and, when that header is present, SERVER_PORT=443. A trustworthy proxy deployment must satisfy this check intentionally, not copy arbitrary inbound headers into PHP variables. Direct TLS to the PHP-facing webserver is the simplest model. A proxy terminating TLS upstream needs a locally reviewed trust boundary; the examples below alone do not establish that trust.

Adapt listener/certificates/upstream/paths/host and timeout values to your stack; do not paste snippets as a complete server configuration. Preserve exactly one Authorization header, reject duplicate/ambiguous headers **at the edge before PHP**; a single forwarded variable cannot prove there was only one on the wire. Validate your edge behavior for raw duplicates and comma-joined values. No universal duplicate-header enforcement snippet is claimed here.

Conservative Nginx **proxy location fragment**, inside an already secured TLS server:

```nginx
location /wp-json/eipsi-external/v1/ {
    proxy_pass http://wordpress_private;
    proxy_set_header Host $host;
    proxy_set_header Authorization $http_authorization;
    proxy_cache off;
    proxy_buffering off;
    proxy_read_timeout 120s;
    proxy_send_timeout 120s;
    # Upstream TLS/WordPress transport proof and duplicate-header rejection
    # must be implemented at your trusted edge; not solved by this fragment.
}
```

Cover query-style `rest_route` requests too if your site uses them; a pretty-path-only rule does not cover those requests. No automatic trust of X-Forwarded-* is added. Nginx distinguishes upstream timeouts and buffering behavior; adapt to the largest allowed transfer, client/proxy limits and PHP timeouts. See [official proxy directives](https://nginx.org/en/docs/http/ngx_http_proxy_module.html#proxy_set_header).

Apache fragment, **only for CGI-compatible handler configurations where this directive applies**, within the relevant secured directory/server context:

```apache
CGIPassAuth On
```

This passes Authorization to the script environment; it does not configure TLS, PHP-FPM routing, reverse-proxy trust, WordPress rewrites or duplicate-header rejection. Adapt to the actual handler and validate with the admin sentinel and a real REST status request. See [Apache CGIPassAuth](https://httpd.apache.org/docs/2.4/mod/core.html#cgipassauth). Neither server's files are modified by the plugin.

Do not log Authorization, request bodies, emails or datasets in access/debug/WAF logs. Disable public caching at every layer, preserve private/no-store and Vary: Authorization. CORS stays closed, machine-to-machine; a browser-origin configuration is not part of this API.

## Cron, CLI and recovery

The existing five-minute eipsi_process_nudge_jobs event invokes SEC-1 maintenance independently of the longitudinal module. Polling can help progress PREPARING, but cron is still required for idle cleanup. A scheduled event is not proof of execution; inspect last tick/success/progress and cleanup backlog. Stale preparation >15 minutes warns; no recent cron with no pending work is not by itself ERROR.

Use WP-Cron with reliable traffic or a system job under the site service account. Example, adapt binary/path/site/user and keep logs private:

```cron
*/5 * * * * /usr/local/bin/wp --path=/srv/wordpress --url=https://study.example cron event run --due-now --quiet
```

Do not enable concurrent schedulers blindly; decide how DISABLE_WP_CRON and system cron interact. The plugin does not edit wp-config/crontab. Headless diagnostics/actions, if WP-CLI is installed:

```sh
wp --path=/srv/wordpress --url=https://study.example eipsi api status
wp --path=/srv/wordpress --url=https://study.example eipsi api maintenance
wp --path=/srv/wordpress --url=https://study.example eipsi api cleanup
```

These are the only API CLI commands registered. They use the same owners as admin UI, are local trusted-host commands, and never print secrets. In multisite select the blog with --url: quotas, metrics and settings are per-prefix, not network aggregates.

## Database, PHP and disk

InnoDB, transactions, required columns/indices and completed M8 migration 20 are required. The SEC-1 limiter also needs transactional options with a unique option_name index. Health inspects these locally without destructive benchmarks. No API-3 repair of scientific corruption occurs. Derived corruption can be explicitly invalidated/purged, never silently rebuilt with another as_of.

Snapshots live in private DB tables, not public uploads. Capacity planning includes blobs, tables/indices, binlogs, replicas and backups. Logical 1 GiB budget does not mean 1 GiB physical. Deletes may leave allocated tablespace. Monitor physical capacity externally.

Downloads verify into private php://temp before delivery; large files spill to temp storage. Provide private writable temp space, at least max downloadable representation plus margin, and suitable filesystem permissions. Health reports warning without public paths. memory_limit/max_execution_time, PHP output buffering, webserver and edge/client timeouts all matter. Upload/post body limits do not cap downloads. Internal 60-second download preparation guard does not guarantee a reverse proxy permits that duration or full transfer.

## Disable and failure response

Disable blocks external requests immediately, preserves key records and READY until normal expiry/revocation. PREPARING is conservatively revoked on subsequent processing; a disable between claim and commit cannot publish READY. Internal cleanup still works disabled. Storage is not frozen. Manual maintenance/cleanup is bounded and may need several invocations; do not spam the action.

Health shows OK/WARNING/ERROR with offline checks, logical counters and sampled integrity (not exhaustive corruption certification). Metrics are local fixed category/day counters, aggregate request time and admitted bytes, not a guarantee of client receipt or exactly-once telemetry. Never infer institutional uptime/SMTP delivery/backups from an OK local panel.

## Backups, restore and incidents

Back up keys **hashes**, grants, settings/revisions and audit per retention. To preserve active downloads, export metadata/sources/chunks must be backed up consistently as a unit; protect WordPress secrets/salts needed by encrypted data/cursors. Metrics are local derived information. Backups can become large: wait for cleanup, or explicitly exclude the derived infrastructure only when the restore strategy discards all related export metadata as well. Never restore READY metadata without matching chunks. No automated exclusions.

An old DB restore can revive credentials revoked after that backup. Disable API before restore, reconcile a protected external operational rotation/revocation record, reapply revocations, check grants, then verify M8/health/TLS/cron/temp before enabling. Practice restores in isolation. No distributed revocation service exists.

Compromised key: revoke → inspect audit by key/export/time → invalidate its exports → bounded cleanup respecting leases → create replacement and rotate securely → review scopes/grants and integration logs. Bytes already downloaded cannot be revoked. Do not delete scientific responses to mitigate an API credential incident.

Public Media originals remain a WordPress/media-host limitation: API confinement does not retroactively privatize public uploaded originals. There are no identities, process metadata, XLSX or scientific write endpoints in this API.
