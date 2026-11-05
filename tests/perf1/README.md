# PERF-1 — P3/P10 server decomposition

Explicit opt-in only: `EIPSI_PERF1=1 PERF1_OUTPUT=/absolute/evidence`.

1. Record HEAD/worktree. Run build, check-build, relevant targeted and FULL on the approved source **before measuring**. Do not update UX-F1A's immutable scientific reference.
2. `python3 tests/perf1/prepare.py /absolute/evidence` creates `.cache/perf1/instrumented-plugin` from the distribution allowlist. It never edits src/includes in the real plugin. The pre-existing packaging exclusion for an unused invalid template also applies to the copy.
3. With the pinned WordPress PHP image: `php tests/perf1/instrument.php /app/.cache/perf1/instrumented-plugin`. Requires `EIPSI_PERF1=1`. Tokenizer injection preserves signatures/return/throw via try/finally, handles empty functions/closures and replaces only the test copy's direct SQL call. Lint the complete includes tree.
4. Original control: `EIPSI_PERF1=1 PERF1_PLAIN=1 PERF1_OUTPUT=/absolute/evidence/control-original python3 tests/perf1/run.py`.
5. Detailed copy: `EIPSI_PERF1=1 PERF1_OUTPUT=/absolute/evidence python3 tests/perf1/run.py`.
6. `python3 tests/perf1/analyze.py /absolute/evidence`; then `python3 tests/perf1/report.py /absolute/evidence`.
7. Unit collector: `php tests/perf1/profiler-test.php` (pinned PHP image, no network). Privacy/product integrity: `PERF1_OUTPUT=/absolute/evidence python3 tests/perf1/check.py`.

Only new unique Gate-managed Docker projects are used; teardown belongs to each project and removes its volumes. No main WordPress database or actual SMTP. The original-control and traced series run sequentially to reduce contention.

The script has warm-up + 20 repetitions, functional fixture clock, live HTTP P3, and real Native owner command/NativeBridge for P10. Source-original, copy-probes-disabled and copy-trace-enabled are **instrumentation-overhead comparisons**, not optimized A/B. No optimization is silently applied.

SQL output contains normalized structural fingerprints, tables/operations, caller/owner/repository names, counts and durations; never SQL values. Full row context comparison and exact read identity use private in-memory data and export only counts. Private DB/response equality for replay is compared without normalizing IDs; the exported logical snapshot contains safe ordinal/state/revision summaries. Concurrent worker inputs/results containing fixture identifiers travel through pipes and are not published in evidence.

Tiny recursive Canonical/Check/CompiledStudy calls remain in method/edge counts; only their expanded events are omitted from the representative owner/SQL timeline. This keeps the causal timeline complete without retaining over 100,000 repetitive events. All instrumentation is outside production and excluded from its ZIP.

The collector itself has meaningful overhead. Do not treat an instrumented inclusive total as a promised optimization gain. Isolated compiled/crypto benchmarks from the original source bound potential gains. Scope of clocks, SQL timers, missing WP/core coverage and concurrency/failure caveats is documented in the report.
