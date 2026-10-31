# Test Plan Priority Coverage (modernized)

Tracking issue: **#1978** (net-new report; parent metric: #1073, Metrics &
Trends).

## What it is

A net-new TestLink 2.0.1 report under **Reports**. TestLink 1.9.20 can report
execution status per build, suite, tester or keyword, but never per test-case
**importance** — `api/prioritybarchart` is keyword based despite its name — so
there was no read-only answer to "**is the High priority scope executed and
green before shipping?**" without exporting the plan.

This screen rolls the plan's assigned test-case versions up into three
importance bands and reports, per band:

- total assigned versions, executed, passed, failed, blocked and not run
- coverage (`executed / total`) and pass rate (`passed / executed`)
- an overall verdict: `ready`, `at_risk`, `watch` or `empty`

It also lists every assigned version with its latest execution status, tester,
build and platform, and exports the table as CSV.

## Screen

`gui/templates/results/priorityCoverage.html`

- Dashio teal header, dark toolbar (Refresh, Export CSV, Build, Priority,
  Apply, Close).
- Report-context panel (test project, test plan, selected build/priority).
- Verdict banner with the at-risk reason (high coverage or high pass rate).
- 9 KPI cards: assigned versions, executed, not run, coverage, passed, failed,
  blocked, pass rate, untested High.
- 3-row bands table (High / Medium / Low) with coverage and pass-rate bars and
  per-band counters.
- 10-column DataTable: priority, test case, external id, suite, version,
  status, last execution, tester, build, platform.
- CSV export (UTF-8 BOM, `;` separator, spreadsheet-formula neutralisation).
- Localized state cards for missing context, 401 / 403 / 404 / 405 and
  `project_mismatch`.

## BFF

`api/prioritycoverage/index.php` — plain PHP, session auth,
`bffSameOriginGuard`, `bffEnforceSession`, JSON I/O, `Cache-Control: no-store`.

| Action | Method | Parameters |
| --- | --- | --- |
| `init` | `GET\|HEAD` | `tplan_id`, optional `tproject_id` |
| `report` | `GET\|HEAD` | `tplan_id`, optional `tproject_id`, `build_id`, `platform_id`, `importance` |

Rights: `testplan_metrics` on the **owning** test project of the plan
(`getAccess = true`). The client-supplied `tproject_id` is only an assertion and
can never widen the query.

Error contract: `not_authenticated` (401), `no_right` (403),
`tplan_not_found` / `project_mismatch` (404), `invalid_request` /
`method_not_allowed` (400/405).

## Semantics

- The plan is resolved first (`testplans.id = tplan_id`); every query uses the
  owning `testproject_id` from the database.
- The unit is a test-case version currently linked to the plan
  (`testplan_tcversions`); unassigned test cases are ignored.
- A version is **executed** when at least one execution exists for it inside
  the selected scope (plan, optional build and platform; no build filter means
  all builds). Its status is the **latest** execution ordered by
  `execution_ts`, then id — never an older result.
- `importance` is `tcversions.importance`: 1 = Low, 2 = Medium, 3 = High.
  NULL or out-of-range values are normalised to Medium, the TestLink default.
- `coverage_pct = executed / total`; `pass_rate_pct = passed / executed`
  (passed + failed + blocked), with blocked and not run never counted as a
  pass.
- Verdict: `ready` when every non-empty band has coverage and pass rate at
  least 80%; `at_risk` when a band is below 50% coverage or an executed band
  is below 50% pass rate; `watch` otherwise; `empty` when the plan has no
  assigned versions.
- The plan's own `testplan_tcversions` rows are the universe, so a version
  never executed is still counted (not run), and the plan is never widened by
  a client-supplied project id.

## Suite

`Suite #1978` in `tmp/TLU_Test_Cases.md` — 16/16 PASS (default scope, build and
priority filters, empty plan, cross-project assertion, unknown ids, bad
importance, 401/403/404/405, no-rights user, browser render, CSV, EN→RO switch,
Reports/ASIDE integration). Fixture: `tmp/fixtures_1978.php`.
