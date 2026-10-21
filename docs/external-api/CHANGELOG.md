# External API implementation summary (unreleased)

No release number/tag assigned. API namespace remains eipsi-external/v1.

- API-0: explicit Bearer principal, hashed one-time keys, independent scopes/logical Study access, TLS, rate limiting, private/no-store and administrative audit.
- API-1: granted logical Study discovery/detail, bounded pagination and opaque key-bound cursors.
- API-2A: live analytical JSON/CSV and historical dictionary from the shared Management owner; privacy/partials/type/version semantics preserved, explicit capacity failures.
- API-2B: pinned source manifests, immutable READY pages/dictionaries, CSV/NDJSON downloads, checksums, Range, finite derived storage and safe cleanup.
- API-3: bounded operational settings, admin-only health/monitoring/retention/actions, local metrics, independent maintenance and local CLI.
- API-4: documentation/OpenAPI, executable integration examples, contract verification and integrated human review confirmed PASS. Human fixture removed; final FULL3442 + RCT1/1 green. Ready for release decision; no release/tag created. Deployment-specific requirements remain documented.
