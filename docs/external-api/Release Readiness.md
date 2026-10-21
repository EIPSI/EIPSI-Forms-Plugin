# External API v1 — release decision checklist

Current result: **External API v1 completed — technically and humanly reviewed, ready for release decision.** Human H1–H29 confirmed, isolated fixture removed, final FULL3442 + strict RCT1/1 green. Target deployment checks below remain a separate operational acceptance. No version/tag/release created.

- [x] API-4 OpenAPI/runtime/schema/error contract tests and executable examples green.
- [x] API-0/1/2A/2B/3, SEC, Management/Privacy and Study Engine/Native regressions green.
- [x] Final FULL + strict RCT + build/check-build + clean/upgrade/lifecycle/smoke after human cleanup green.
- [x] API docs reviewed against runtime; finite limits, live/snapshot and privacy distinctions explicit.
- [x] No actual API credential/Bearer in public docs/evidence/logs/build; one-time creation verified.
- [ ] HTTPS, Authorization preservation/duplicate rejection, no public cache and proxy timeouts verified on target stack.
- [ ] Cron/worker/cleanup/retention, temp permissions/capacity, transactional DB checked on target.
- [ ] Backup/restore rehearsal and revoked-key reconciliation agreed.
- [ ] Minimum scopes/grants, secret delivery, rotation and incident owner agreed.
- [x] Integrated human H1–H29 explicitly confirmed PASS by reviewer; browser/OS recorded.
- [x] Human fixture project/DB/volumes/network/bridge/ports/private files removed after approval; sanitized evidence retained; runtime/build residue scan clean.

Before human confirmation: **API-4 technical implementation completed — integrated human review pending.**

Only after human PASS, cleanup and final FULL: **External API v1 completed — technically and humanly reviewed, ready for release decision.**

Local tests do not certify external institutional deployment. R execution is separately recorded if R tooling is unavailable. No identities/process metadata/XLSX/write API; public Media originals, downloaded copies, historical restores and finite quotas remain limitations.
