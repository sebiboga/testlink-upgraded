# Test Plan Execution Bottleneck — Modernized (2.0.1)

Issue: #2070. Screen: `gui/templates/results/executionBottleneck.html`. BFF: `api/executionbottleneck/index.php`.

This net-new read-only Report answers a question 1.9.20 could not answer without opening every execution: which assigned test-case versions of a test plan consume the most execution time, how often they were re-run, and which assignments were never executed.

## Behavior

- The unit of reporting is the assigned test-case version (`testplan_tcversions.id`); durations are converted from the legacy `executions.execution_duration` minutes column to seconds and a zero/null duration is treated as untimed, not as a fast run.
- Per assignment the report aggregates executions, timed executions, average/longest/total duration in the selected scope, last result/run/build and the slow/re-run flags.
- The build, platform and tester filters narrow the execution evidence; the tester filter can also narrow the assignment universe. Scope is `all`, `slow`, `rerun` or `never`, with a configurable slow threshold in seconds.
- Twelve KPI tiles: assigned, executed, never executed, timed executions, untimed executions, re-runs, slow versions, average duration, longest run, total time, affected suites and distinct testers.
- The plan verdict is `no_data`, `clear`, `watch` or `at_risk`; the label is localized and independent of the CSS state class.
- A 13-column searchable/sortable DataTable with a `v<version>` badge and per-row execution-history modal; UTF-8 CSV with BOM, `;` delimiter and formula-injection guard; localized 400/401/403/404/405 state cards.

## Security

- `bffSameOriginGuard()` and `bffEnforceSession()` on every route.
- The plan is resolved first; `testplan_metrics` is checked on the OWNING project before the client-supplied `tproject_id` assertion, so cross-project deep links are opaque 404s.
- Denials are logged at AUDIT; the report is GET/HEAD-only and never discloses another project's data.

## Wiring and i18n

- `cfg/reports.cfg.php` entry `execution_bottleneck`, `api/aside/index.php` title mapping and `api/resultsnav/index.php` map.
- `$actions->executionBottleneck` in `lib/functions/common.php`.
- `ebn.*` + footer keys in all 10 client bundles; `bash ai/verify_i18n_coverage.sh` passes 9/9 with 13551 keys; Romanian labels are fully translated.
- `$TLS_link_report_execution_bottleneck` in all 19 server locale files.

## Verification

- Fixture `tmp/fixtures_2068.php`-style `tmp/fixtures_2070.php`: 6 assignments, 7 executions, 2 builds, 2 platforms, 2 testers, a slow/re-run case, an untimed case and an empty plan.
- Suite `Issue #2070`: 12 cases covering the normal report, all filters and threshold, detail modal, CSV bytes and BOM, empty plan, authentication/rights/HTTP contract, state cards, menu wiring, RO/EN locales, gates and Event Viewer — all PASS.
- PHP lint, extracted `node --check`, i18n coverage and suite gate all pass; Event Viewer has no new Error/Warning/L18N entries (AUDIT denials only).
- Bugs #2071 (machine-code verdict labels) and #2072 (untranslated Romanian labels) were found during browser testing, filed with the `bug` label, fixed and closed.
- Code review hardening: platform-aware execution grouping (no cross-platform double count), scope-aware KPIs/verdict/tester/build counters, tester filter narrows assignments, localized modal close `aria-label`, `session_expired` state card, `at_risk` CSS class and strict `>` slow threshold.
- Bug #2073 filed (`bug`) for the remaining untranslated `ebn.*` values in de/es/fr/it/ja/pt/ru/zh; header, subtitle, verdict and toolbar are translated there and the Romanian bundle is complete.
