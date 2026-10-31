# Test Plan Tester Workload (modernized)

Tracking issue: **#1977** (net-new report; parent metric: #1073, Metrics &
Trends).

## What it is

A net-new TestLink 2.0.1 report under **Reports**. TestLink 1.9.20 stores
assignments in `user_assignments` (`type=1` → test-plan test cases) and shows
them one test case at a time in the plan's assignment screens. There was no
per-tester answer to "**who has what left on this plan, and how is it going?**"
— you had to open each test case, or reconcile the execution history by hand.

This screen aggregates the plan's assignments and executions **per tester**:

- assignments (`user_assignments.type=1`, `feature_id` → `testplan_tcversions.id`)
- executed assignments (an execution exists for the same test-case version and
  platform, on the assignment's build or any build when `build_id=0`)
- remaining and overdue assignments
- execution results performed by the tester (passed / failed / blocked, pass
  rate, last activity)
- the next assignment deadline and a per-tester verdict
  (`complete`, `in_progress`, `at_risk`, `ad_hoc`)

Testers without assignments but with executions on the plan are listed too
(`ad_hoc`), so ad-hoc work is visible.

## Screen

`gui/templates/results/testerWorkload.html`

- Dashio teal header, dark toolbar (Refresh, Export CSV, Build, Platform,
  Apply, Close).
- Report-context panel (test project, test plan, selected build/platform).
- 9 KPI cards: testers, assignments, done, remaining, overdue, completion,
  executions, active testers, average assignments.
- Banner states: empty scope, complete workload, still-open workload, overdue
  assignments (with count and completion percentage).
- 15-column DataTable: tester, login, assigned, done, remaining, overdue,
  completion, executions, passed, failed, blocked, pass rate, last activity,
  next deadline, verdict.
- CSV export (UTF-8 BOM, `;` separator, spreadsheet-formula neutralisation).
- Localized state cards for missing context, 401 / 403 / 404 / 405 and
  `project_mismatch`.

## BFF

`api/testerworkload/index.php` — plain PHP, session auth,
`bffSameOriginGuard`, `bffEnforceSession`, JSON I/O, `Cache-Control: no-store`.

| Action | Method | Parameters |
| --- | --- | --- |
| `init` | `GET\|HEAD` | `tplan_id`, optional `tproject_id` |
| `report` | `GET\|HEAD` | `tplan_id`, optional `tproject_id`, `build_id`, `platform_id` |

Rights: `testplan_metrics` on the **owning** test project of the plan
(`getAccess = true`). The client-supplied `tproject_id` is only an assertion and
can never widen the query.

Error contract: `not_authenticated` (401), `no_right` (403),
`tplan_not_found` / `project_mismatch` (404), `invalid_request` /
`method_not_allowed` (400/405).

## Semantics

- The plan is resolved first (`testplans.id = tplan_id`); every query uses the
  owning `testproject_id` from the database.
- Assignment universe: `user_assignments.type=1`
  (`TYPE_TESTPLAN_TCVERSIONS`) joined to the plan's `testplan_tcversions`
  through `feature_id = testplan_tcversions.id`.
- An assignment counts as **done** when at least one execution exists with the
  same `tcversion_id` and `platform_id`, on the assignment's `build_id` (or on
  any build when `build_id=0`).
- Closed (`status=2`) and completed (`status=3`) assignments, and rows with no
  user (`user_id<=0`), are excluded from the workload.
- An assignment counts as **overdue** when it is not done and
  `deadline_ts < NOW()` (database clock). `next deadline` only considers
  assignments that are still not executed.
- Execution counters (passed / failed / blocked / not run) are executions
  **performed by that user** (`executions.tester_id`), not assignments.
- Executions are loaded once and filtered with an `EXISTS` on
  `testplan_tcversions`, so an execution whose platform is no longer linked to
  the plan does not inflate the counters. The same set drives the done test.
- Build and platform filters narrow both the done test and the executions.
  Invalid ids return `invalid_request` (400).
- Verdicts: `complete` (assignments done, no remaining), `in_progress` (done
  work and no overdue), `at_risk` (any overdue), `ad_hoc` (executions without
  assignments).
- `avg_assignments` divides by testers that actually hold assignments, so
  ad-hoc executors do not dilute the KPI.
- CSV uses a UTF-8 BOM and `;` separator, localized headers and verdicts, and
  neutralises spreadsheet formulas. DataTables controls use the shared
  `common.*` keys.

## Wiring

- `cfg/reports.cfg.php`: `$tlCfg->reports_list['tester_workload']`.
- `api/aside/index.php`: Reports menu mapping for
  `link_report_tester_workload`.
- `api/resultsnav/index.php`: launcher mapping for `tester_workload`.
- `lib/functions/common.php`: `$actions->testerWorkload`.
- `locale/*/strings.txt`: `$TLS_link_report_tester_workload` (19 server
  locales).
- i18n: 63 `twl.*` keys + `footers.testerWorkload` in all 10 bundles.

## Verification

- Fixture: `tmp/fixtures_1977.php` (project `Demo Project` / plan
  `Sprint 1 plan`, builds `Build A`/`Build B`, platforms `Chrome`/`Firefox`,
  users `alice`/`bob`, 4 assignments, 3 executions). Ids are fixed:
  tproject 1 / tplan 2.
- BFF: init 200 (2 builds, 2 platforms); report 200 (2 testers, 4 assignments,
  3 done, 1 remaining, 1 overdue, completion 75%).
- Build filter: `build_id=2` → 0 done / 3 remaining (executions only exist on
  build 1); platform filter `platform_id=2` → 1 tester / 1 overdue.
- Errors: no session 401; unknown plan 404 `tplan_not_found`; cross-project
  assertion 404 `project_mismatch`; unknown action 400; POST 403/405; user
  `alice` without `testplan_metrics` 403 `no_right`.
- Browser (admin, EN + RO): KPIs, banner, table, build/platform filters and CSV
  export work; Romanian labels render with no raw keys; console clean.
- Reports menu: entry "Test Plan Tester Workload" opens the screen in the
  main frame with the current `tproject_id` / `tplan_id`.
- i18n coverage gate: 9/9 bundles PASS (10,340 keys).
- Suite: `Issue #1977` in `tmp/TLU_Test_Cases.md` = 16/16 PASS.

## Screenshots

- `docs/screenshots/issue-1977-tester-workload-normal.png` (all builds and
  platforms, overdue banner).
- `docs/screenshots/issue-1977-tester-workload-filtered.png` (Firefox filter,
  single overdue assignment).
