# Test Plan Defect Arrival Rate (defectArrivalRate)

Modernized screen for issue [#2031](https://github.com/sebiboga/testlink-upgraded/issues/2031) in TestLink 2.0.1.

- Screen: `gui/templates/results/defectArrivalRate.html`
- BFF: `api/defectarrivalrate/index.php`
- Rights: `testplan_metrics` on the owning test project (read-only)
- API: `GET|HEAD ?action=init&tplan_id=N[&tproject_id=P]`,
  `GET|HEAD ?action=report&tplan_id=N[&tproject_id=P][&build_id=B][&platform_id=PL]`,
  `GET|HEAD ?action=detail&tplan_id=N[&tproject_id=P]&bug_id=BUG`,
  `GET|HEAD ?action=csv&tplan_id=N[...]`
- Fixture: `tmp/fixtures_2031.php`
- Test suite: `Issue #2031` in `tmp/TLU_Test_Cases.md`

## Why a new report

TestLink 1.9.20 only exposed linked defect IDs inside an individual execution popup. It had no plan-wide answer to "when did each defect first appear in this plan's executions?" and no way to see whether arrivals were accelerating. This 2.0.1 report reads the plan's `execution_bugs` links chronologically and turns them into a daily arrival series with per-defect evidence.

## Screen

- Toolbar: test plan / project context, Build and Platform pickers, Refresh, Export CSV, Close.
- 12 KPI cards: distinct defects, execution links, active days, new in last 7 days, average per active day, peak day, new on peak day, affected cases, failed runs linked, first arrival, last arrival and passed share.
- Plan verdict banner (`no_data` / `steady` / `watch` / `surge`).
- Daily DataTable with new defects, cumulative arrivals, link and execution counts, passed/failed/blocked/not-run split, affected case count and sorted defect IDs.
- Clicking a defect ID opens an evidence modal with first/last arrival, link/execution/case/build/platform data and every linked execution.
- CSV export with UTF-8 BOM, `;` separator, RFC-style field quoting and formula-injection guard; localized 400/401/403/404/405 state cards and an EN/RO-aware TLi18n locale switcher.

## Semantics

- The unit is a distinct literal `execution_bugs.bug_id` linked to an execution of the selected plan. HTML is decoded and tags are stripped before the bug ID is used.
- Arrival day is `DATE_FORMAT(executions.execution_ts, '%Y-%m-%d')`. Rows are processed in chronological execution order; a defect is "new" on the day of its first link.
- "Execution links" counts `execution_bugs` rows in scope; the daily `executions` column counts distinct execution IDs. Failed runs linked is the sum of failed linked executions.
- "Affected cases" counts distinct `external_id + name` labels, fixing the initial bug where the daily case map's boolean values were counted instead of its keys (#2032).
- "New in last 7 days" uses the inclusive calendar window from `today - 6 days` to today, anchored to the current date (#2033). A fixture whose last arrival is older than the window reports zero and verdict `steady`.
- Average per active day is distinct defects divided by days with at least one arrival. Peak day is the earliest day with the maximum new-defect count.
- Verdict is `no_data` without arrivals, `surge` when the last 7 days exceed both 3 arrivals and three times the daily average, `watch` when the peak day has 5 or more arrivals, and `steady` otherwise.
- Build filters active project builds; Platform filters platforms linked to the plan. Named-platform filtering includes `platform_id=0` assignments, matching the sibling reports.
- `tproject_id` is only asserted against the plan's owning project after the plan is resolved and `testplan_metrics` is checked on that owner, so foreign-project probes cannot enumerate plans.

## API contract

| Case | HTTP | Code |
| --- | --- | --- |
| `GET\|HEAD` success | 200 | `ok` |
| Unknown action, invalid plan/build/platform, bad `bug_id` | 400 | `invalid_request` |
| Anonymous / expired session | 401 | `not_authenticated` |
| Missing `testplan_metrics` right | 403 | `no_right` (AUDIT) |
| Unknown plan, wrong project, defect outside report | 404 | `tplan_not_found` / `project_mismatch` / `defect_not_found` |
| Non GET/HEAD | 405 | `method_not_allowed` (`Allow: GET, HEAD`) |

Security: `bffSameOriginGuard()` + `bffEnforceSession()`; denials are logged at AUDIT; responses carry `nosniff` and `Cache-Control: no-store`. A POST without `Origin`/`Referer` is blocked by the same-origin guard before the method check.

## Wiring

- `cfg/reports.cfg.php` — `defect_arrival_rate`
- `api/aside/index.php` — Reports entry for `defectArrivalRate.html`
- `api/resultsnav/index.php` — `defect_arrival_rate` map row
- `lib/functions/common.php` — `$actions->defectArrivalRate`
- `gui/templates/i18n/*.json` — `dar.*` + footer keys in all 10 bundles (`ai/verify_i18n_coverage.sh`: 9/9 PASS, 12667 keys)
- `locale/*/strings.txt` — `$TLS_link_report_defect_arrival_rate` (19 locales)

## Verification

- Browser: default report (7 distinct defects / 11 execution links / 5 active days / 0 new in last 7 days / 1.4 average / peak `2026-11-04` with 4 new arrivals / 3 affected cases / 9 failed runs linked), defect evidence modal, build/platform filters, DataTables search, Refresh, AJAX Blob CSV download, empty plan, no-right 403 card, anonymous redirect, Romanian locale and ASIDE shell link.
- Machine: full 200/400/401/403/404/405 contract, 23/23 API checks, `TLU_REQUIRE_SUITE="Issue #2031"` gate PASS (61 cases, no suite lost), i18n gate 9/9, Event Viewer AUDIT-only (0 Error/Warning).
- Bugs found and fixed: #2032 (affected-cases KPI counted map values instead of keys), #2033 (7-day window anchored to the last execution instead of today), #2034 (CSV error displayed raw JSON), #2035 (missing `dar.infoFiltered`, `dar.close` and server menu translations).

## Files

- `api/defectarrivalrate/index.php`
- `gui/templates/results/defectArrivalRate.html`
- `gui/templates/i18n/*.json`, `locale/*/strings.txt`
- `cfg/reports.cfg.php`, `api/aside/index.php`, `api/resultsnav/index.php`, `lib/functions/common.php`
- `tmp/fixtures_2031.php`, `tmp/TLU_Test_Cases.md`, `CHANGELOG`
