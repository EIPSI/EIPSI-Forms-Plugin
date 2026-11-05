# UX-F1B viewport and continuity

The JS gate runs 56 cases for page viewport policy, iframe measurement/cleanup,
secondary targets, modal sizing, textarea capability/fallback, safe focus return,
live regions, observer storms and the immutable UX-F1A scientific reference.

The expected scientific fixture is `../ux-f1a/before-contract.json`. It is never
regenerated from this phase. `EQ-calls` compares complete command payloads and IDs;
metadata, responses, required, randomization and conditional views are also exact.

`browser-check.cjs` uses existing puppeteer-core and an executable specified by
EIPSI_BROWSER_EXECUTABLE (default /usr/bin/chromium). It never downloads a browser.
Exit 2 means UNAVAILABLE, not PASS. Scenarios cover the five requested mobile and
three desktop sizes; desktop 200% and 400% are explicitly conceptual viewport/DPR
reflow proxies, not native browser UI zoom. A reduced-height viewport approximates
a keyboard but does not validate a physical IME. Screen readers require human review.
