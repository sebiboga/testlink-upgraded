# Test Plan Defect Density (defectDensity)

Modernized screen for issue **#2018** in TestLink 2.0.1.

- Screen: `gui/templates/results/defectDensity.html`
- BFF: `api/defectdensity/index.php`
- Rights: `testplan_metrics` on the owning test project
- API: `GET|HEAD ?action=init&tplan_id=N[&tproject_id=P]`,
  `GET|HEAD ?action=report&tplan_id=N[&tproject_id=P][&build_id=B][&platform_id=PL][&status=all|p|f|b][&scope=all|with_defects|without_defects|never_run]`,
  `GET|HEAD ?action=detail&tplan_id=N&link_key=<tcversion_id>/<platform_id>[...]`,
  `GET|HEAD ?action=csv&tplan_id=N[...]`
- Fixture: `tmp/fixtures_2018.php`
- Test suite: `Issue #2018` in `tmp/TLU_Test_Cases.md`

## Why a new report

TestLink 1.9.20 stored bug links on executions and exposed them only inside a
single execution's popup. It never answered the manager question "which assigned
test cases carry the most linked defects per failed run in this plan?".
This read-only 2.0.1 report correlates `execution_bugs` with the plan's
executions and assigned test-case versions and ranks the versions by density.

## Screen

- Toolbar: test plan / project context, build and platform pickers, result and
  case-scope filters, Refresh, Export CSV, Close.
- 12 KPI cards: assigned versions, in scope, executed cases, executions, failed
  runs, distinct defects, cases with defects, defects per failed run, pass rate,
  never run, hot cases, defect spikes.
- Plan verdict banner (`no_data` / `at_risk` / `watch` / `monitor` / `clear`).
- Defect-density DataTable: test case, external id, version, suite, runs,
  passed, failed, blocked, defects, defects/failed run, verdict, latest result,
  latest build, platform and executed-on; sorted by defects then density.
- Clicking a test case opens the execution and defect history modal keyed by the
  exact assignment `link_key`, with per-run bug ids and notes.
- BFF CSV export (`;` separator, UTF-8 BOM, formula-injection guard including
  each bug id), localized state cards for 400/401/403/404/405, and an EN/RO-aware
  TLi18n locale switcher.

## Semantics

- The unit is a test-case version assigned to the plan. Each
  `testplan_tcversions` row is keyed as `tcversion_id/platform_id`
  (`platform_id=0` applies to every platform), and the modal uses that exact key
  so multi-platform rows cannot open the wrong history.
- Executions are read from `executions` for the plan and optionally narrowed by
  `build_id` / `platform_id`. Bug ids come from `execution_bugs` joined through
  those executions and are deduplicated inside each execution and each row.
- A row's `linked_bugs` is the number of distinct defect ids linked to that row;
  `density = linked_bugs / failed` (or the link count when there is no failed
  run). Row verdicts are `no_run`, `spike` (>= 3 defects), `hot` (density >= 1),
  `watch` (any defect), `clean`.
- The summary KPI `linked_bugs` is the number of **globally distinct** defect ids
  in scope; `case_bug_links` is the sum of per-row links and is the numerator of
  the summary density KPI. A defect linked to several test cases is therefore
  counted once in the KPI but once per case in the density ratio.
- Plan verdict precedence: `no_data` > `at_risk` (any spike) > `watch` (any hot
  case) > `monitor` (any case with defects) > `clear`.

## API contract

| Case | HTTP | Code |
| --- | --- | --- |
| `GET\|HEAD` success | 200 | `ok` |
| Unknown action, bad `link_key`, invalid filter, missing `tplan_id` | 400 | `invalid_request` |
| Anonymous / expired session | 401 | `not_authenticated` |
| Missing `testplan_metrics` | 403 | `no_right` (AUDIT) |
| Unknown plan / client project mismatch / unknown detail target | 404 | `tplan_not_found` / `project_mismatch` / `tcversion_not_found` |
| Non GET/HEAD with origin proof | 405 | `method_not_allowed` (`Allow: GET, HEAD`) |

Security: `bffSameOriginGuard()` + `bffEnforceSession()`; the plan is resolved
first and the right is checked on its owning project before the client
`tproject_id` assertion is honoured (opaque 404 for unknown or mismatched
plans). Denials are logged at AUDIT; responses carry `nosniff` and
`Cache-Control: no-store`.

## Wiring

- `cfg/reports.cfg.php` — `defect_density`
- `api/aside/index.php` — `link_report_defect_density`
- `api/resultsnav/index.php` — `defect_density`
- `lib/functions/common.php` — `$actions->defectDensity`
- `gui/templates/i18n/*.json` — 90 `dd.*` keys + footer keys in all 10 bundles
  (`ai/verify_i18n_coverage.sh`: 9/9 PASS)
- `locale/*/strings.txt` — `$TLS_link_report_defect_density` (19 locales)

## Verification

- Browser: default report (6 assigned / 6 in scope / 5 executed / 11 runs /
  2 passed / 8 failed / 1 blocked / 4 distinct defects / 3 cases with defects /
  87.5% density / 18.2% pass rate / `at_risk`), detail modal (3 runs and bug ids
  DFD-1/DFD-2/DFD-3), build/platform/result/scope filters, Refresh, CSV byte
  inspection (BOM, `;`, `bug_ids` column and quoted `=FORMULA(1)`), RO locale,
  empty plan, no-right 403 state card, ASIDE launcher; console clean apart from
  the deliberate negative-case 400 request.
- Machine: 200/400/401/403/404/405 contract, `TLU_REQUIRE_SUITE="Issue #2018"`
  gate PASS, i18n gate 9/9, Event Viewer AUDIT-only (0 Error/Warning).
- Bugs found and fixed: #2019 (shared defects double-counted in the distinct KPI)
  and #2020 (CSV omitted the linked defect ids). Code review also corrected the
  multi-platform detail key, CSV newline neutralization, internal payload
  leakage, a misplaced config comment and the Romanian menu label.

## Files

- `api/defectdensity/index.php`
- `gui/templates/results/defectDensity.html`
- `gui/templates/i18n/*.json`, `locale/*/strings.txt`
- `cfg/reports.cfg.php`, `api/aside/index.php`, `api/resultsnav/index.php`,
  `lib/functions/common.php`
- `tmp/fixtures_2018.php`, `tmp/TLU_Test_Cases.md`, `CHANGELOG`
