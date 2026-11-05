# PERF-2 — isolated investigation, not production optimizations

Explicit opt-in: `EIPSI_PERF2=1 PERF2_OUTPUT=/absolute/output python3 tests/perf2/run.py`.

Record product hashes, HEAD/worktree and run build/check-build, targeted P3/P10-covered suites and FULL first. `python3 tests/perf2/prepare.py` creates an allowlisted distribution copy in `.cache/perf2/plugin`. It never edits product source. `PERF2_PLAIN=1` runs the approved original instead. These runs must be sequential on the same pinned stack.

The copied code carries an explicit context between Database/Native owners/bridge and the public route's renderer. No global/static cache, Redis, transient, cross-request cache, UI or scientific rules. A1 preserves every DB read/lock. B1 caches exact ENGINE checks in an observed connection/transaction generation, invalidating on boundaries/observed DDL/failure. **Both prototypes have demonstrated error-equivalence counterexamples and must not be promoted as-is.**

The runner owns a unique disposable Compose project and volumes. No main database, public ports or real SMTP. DDL uses synthetic tables. The original and copy each use warm-up + N=20, alternating baseline/A1/B1 order by repetition. Only small specific counters are active during timing; no recursive profiler. Original vs copied baseline quantifies instrumentation/composition overhead. P95 is exploratory.

Fixture seed is constant through test-only namespaced entropy. Other identities remain random in timing runs. Exact comparison additionally uses scoped deterministic entropy and restores the same full logical tables, queue rows and durable serialized cron value. Generated columns are recomputed and auto-increments reset only in this disposable DB. IDs/timestamps are never normalized for comparison. Both successful operations must fall in the same real wall-clock second; retries outside that window are discarded. Exact checks run **before** accumulating timed-fixture rows. Exact direct-SQL/lock and transaction-sequence digests are compared privately; neither raw SQL nor digests leave the fixture. WP SQL is additionally covered by PFS count and exact durable state; no all-WP-SQL trace claim.

Counters/evidence contain no row contents, emails, responses, credentials, seeds or tokens. Raw values exist only as normal fixture runtime state and in private comparison memory. Test entropy must never enter a distributable plugin. Tests/profilers and `.cache` are excluded by the package allowlist.

`guard-failures.php` compares approved original reconstruction after mysqli closure with A1. A1's domain probe accesses the connection; original reconstruction is pure with an already-read row. Its Error is a **prototype regression**, not a newly found product regression. A future fallback/domain redesign would require separate proof and measurement; no silent weakening of the guard here.

`ddl.php` proves metadata-only inspection does not hold a target-table MDL, while actual table use does. It also checks remote loss, missing columns, reconnect and implicit commit. B1 must not interpret connection/transaction identity alone as DDL stability.

`analyze.py` produces requested CSV/JSON artifacts from the two completed runs; `check.py` validates product freeze, expected counterexamples, measurements, exact happy-path comparisons and evidence privacy. The report records all limitations and rejects both prototypes. No new human visual review is required.
