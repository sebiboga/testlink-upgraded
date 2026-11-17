# Test Plan Suite × Platform Coverage Matrix (suitePlatformMatrix)

Modernized screen for issue **#1996** in TestLink 2.0.1.

- Screen: `gui/templates/results/suitePlatformMatrix.html`
- BFF: `api/suiteplatformmatrix/index.php`
- Rights: `testplan_metrics` on the owning test project
- API: `GET|HEAD ?action=projects`,
  `GET|HEAD ?action=init&tproject_id=N[&tplan_id=P]`,
  `GET|HEAD ?action=report&tplan_id=N[&tproject_id=P][&platform_id=PL]`
- Fixture: `tmp/fixtures_1996.php`
- Test suite: `Issue #1996` in `tmp/TLU_Test_Cases.md`
- Screenshots: `docs/screenshots/issue-1996-suiteplatformmatrix-{en,ro,empty}.png`

## Why a new report

TestLink 1.9.20 (and 2.0.1 until now) can cross-tabulate suites against builds
(`suiteBuildMatrix`) and builds against platforms (`buildPlatformMatrix`), and
`platformMatrix` lists assigned test-case versions against platforms. None of
them puts **every suite of a plan against every platform of the plan**, so
"which suite is covered on which platform?" had to be reconstructed by hand.
The Suite × Platform Coverage Matrix answers that question in one screen and
closes the suite/build/platform triangle of TestLink reports.

## Screen

- Toolbar: project, plan and platform pickers, Apply, Refresh, Export CSV,
  Close.
- Verdict banner (`no_data` / `not_started` / `in_progress` / `complete`) with
  the plan-wide coverage percentage and executed/assigned counts.
- Report context card (project, plan, active platform filter).
- 11 KPI cards: suites, platforms, assignments, distinct cases, executed,
  passed, failed, blocked, not run, coverage %, pass rate %.
- Matrix: one row per test suite (labelled with its full path), one column per
  platform; each cell shows coverage %, executed/assigned, a coverage bar and
  the passed / failed / blocked / not-run counters, colour-coded by state.
  Clicking a cell re-scopes the report to that suite × platform pair.
- Per-suite totals and per-platform totals tables.
- CSV export with four sections (summary, per-suite totals, per-platform
  totals, matrix cells), `;` separator, BOM and formula-injection guard.
- Localized loading, empty, 400/401/403/404/405 state cards.

## Semantics

- The unit is a test-case version assigned to the plan (`testplan_tcversions`).
- A version belongs to the **direct parent suite of its test-case node**
  (`tcversion node → test case node → test suite node`); suites without
  applicable versions are omitted, and a suite's row label is its full
  hierarchy path.
- Platform applicability comes from `testplan_tcversions.platform_id`:
  `0` means the assignment applies to **every** platform of the plan, a
  non-zero value pins it to that platform only. Non-applicable pairs are not
  fabricated as extra not-run rows.
- Platforms in scope are the `testplan_platforms` links plus any platform
  still present on an assignment or an execution, so history is never hidden.
- Each cell uses the **latest execution** (highest `executions.id`) per
  (platform, test-case version) across every build of the plan; a version with
  no execution counts as **not run**.
- `distinct_cases` is restricted to the assignments applicable to the filtered
  platform(s), so it shrinks with the platform filter instead of staying
  plan-wide.
- Verdict precedence: `no_data` (no assignments) > `not_started` (no execution)
  > `complete` (no not-run assignment) > `in_progress`.

## API contract

| Case | HTTP | Code |
|---|---|---|
| `GET|HEAD` success | 200 | `ok` |
| Unknown action / missing or invalid `tplan_id` / unknown `platform_id` | 400 | `invalid_request` |
| No session | 401 | `not_authenticated` / `session_expired` |
| No `testplan_metrics` right on the owning project | 403 | `no_right` |
| Unknown plan/project, or `tproject_id` not the plan owner | 404 | `tplan_not_found` / `tproject_not_found` / `project_mismatch` |
| POST without same-origin headers | 403 | `cross_origin` |
| `POST` with same-origin headers (GET only) | 405 | `method_not_allowed` |

The plan is resolved first, then `testplan_metrics` is checked on the **owning**
project; the client `tproject_id` is only an assertion, so it can never widen
access. Every denial is written to the `AUDIT` log.

## Wiring

- `cfg/reports.cfg.php`: `reports_list['suite_platform_matrix']` →
  `gui/templates/results/suitePlatformMatrix.html`.
- `api/aside/index.php`: `link_report_suite_platform_matrix` branch builds the
  contexted href for the Reports menu.
- `lib/functions/common.php`: `$actions->suitePlatformMatrix`.
- i18n: `spm.*` (86 keys) + `footers.suitePlatformMatrix` in all 10 client
  bundles; `$TLS_link_report_suite_platform_matrix` in all 19 server locales.

## Verification

- Browser: default matrix (3 suites × 2 platforms, coverage 75.0%, pass rate
  66.7%, `in_progress`), per-assignment platform applicability, not-run Gamma
  rows, platform filter (4 assignments / 3 executed / 2 passed / 1 failed),
  cell-click suite × platform filtering, empty plan (`no_data`), empty project,
  Romanian locale, no-right user (`no_right` card), CSV content, ASIDE +
  Reports launcher; console clean; Event Viewer 0 new Error/Warning.
- Machine: 200/400/401/403/404/405 contract, project mismatch, unknown
  platform, POST 403, `HEAD`, i18n gate 9/9, test-suite gate PASS.
