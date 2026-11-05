# UX-F0 — isolated profiling (not an optimization)

Explicit opt-in: `EIPSI_UX_F0=1`; scripts refuse execution otherwise. Output directory is required as `UX_F0_OUTPUT`. Tests are excluded by the production ZIP allowlist. No production manifest or source includes these helpers.

1. Run build, check-build, relevant targeted and FULL; retain the green summary/hash before profiling.
2. Node (pinned image from `tests/ci/gate.json`, dependencies already installed): `EIPSI_UX_F0=1 UX_F0_OUTPUT=/evidence node tests/ux-f0/client.cjs`. Mount an output folder at `/evidence`. No network required. Warm-up and 20 repetitions; delayed transport 1 warm-up + 3 measured repetitions per delay. Uses existing jsdom/acorn, no browser install. Durations use performance.now.
3. Host: `EIPSI_UX_F0=1 UX_F0_OUTPUT=/absolute/evidence python3 tests/ux-f0/run-server.py`. Docker required. Creates a unique WordPress/MariaDB fixture using existing gate, then removes only that project's containers/volumes. Test-only wp-config + MU helper enable SAVEQUERIES, monotonic measurements and a Performance Schema-only Compose override. A read-only observer grant is created for the disposable DB user. DB counters come from a separate connection so the observer queries are not counted as application work. SAVEQUERIES alone misses the driver’s direct mysqli queries; its duplicate/caller data is only a subset. Captures mail; no external SMTP.
4. Browser: reuse installed puppeteer-core with `EIPSI_BROWSER_EXECUTABLE`. No automatic executable download. Browser measurements unavailable if executable absent; jsdom gives no layout, paint, CLS, network waterfall, keyboard or physical-device evidence.
5. Generate summary artifacts with `python3 tests/ux-f0/summarize.py /absolute/evidence`.
6. Recheck immutable scientific equivalence, production fingerprints, package exclusions and FULL final.

Raw operation payloads stay in memory. Output consists only of duration/count/byte measurements, scenario names and test asset paths. SQL and values are not logged; duplicate exact-query counts are computed in memory. Functional clocks remain unchanged. Avoid interpreting owner timings as HTTP request timings or summing inclusive measurements.

Additional explicit profiling commands: `node tests/ux-f0/extras.cjs` for JSON/clone/metadata and existing logic/heartbeat queue; `node tests/ux-f0/cpu.cjs` for aggregate V8 sampling; `python3 tests/ux-f0/check.py` inside the existing Node image for nine instrumentation invariants (Node + Python available there). After summarizing, `python3 tests/ux-f0/report.py /absolute/evidence` regenerates the report from measured aggregates. These are not added to the functional gate count.

Do not pool earlier exploratory runs with the canonical series. If another CI fixture is active, use a separate subnet for isolation, but wait until it stops before collecting the final timing series to reduce shared-host contention.

`node tests/ux-f0/equivalence.cjs` compares the immutable UX-F1A trace with all profiling wrappers enabled (including DOM counters). The original reference is never regenerated. Run it after the client profiler has created the temporary instrumented source copy.
