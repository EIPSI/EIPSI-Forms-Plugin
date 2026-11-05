# UX-F1A regression evidence

`runtime-dom.js` adds 53 acceptance/regression cases to the normal JS gate.
`before-contract.json` is an immutable reference produced from the pre-phase sources
preserved in `informes/EIPSI-Forms/UX-F1A-evidence/baseline`, not from the new code.
Do not regenerate it merely to make a failed comparison pass.

`support.js` drives identical, deterministic interactions against either source set.
It fixes the clock, identity generator, CPU context and randomization seed in tests
only. The trace includes enriched-label links (focus telemetry), actual option
randomization, commands, answers, pages and a server-authoritative conditional view.
All production collectors, validators and transport owners remain in use.

`mobile-dom.cjs` checks DOM contracts at 320/360/390/430px; it does not measure layout.
`browser-check.cjs` uses the already installed puppeteer-core and an installed browser
specified through EIPSI_BROWSER_EXECUTABLE. It never downloads a browser. Exit 2 means
browser unavailable, not a passing visual test. It measures loading button bounds and
checks calendar Enter/Tab/Shift+Tab/Escape, field associations and group relationships.
No WCAG certification or human review is inferred from either script.
