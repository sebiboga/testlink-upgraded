# Test Plan Test Case × Build Result Matrix (tcBuildMatrix)

Modernized screen for issue **#1999** in TestLink 2.0.1.

- Screen: `gui/templates/results/tcBuildMatrix.html`
- BFF: `api/tcbuildmatrix/index.php`
- Rights: `testplan_metrics` on the owning test project
- API: `GET|HEAD ?action=projects`,
  `GET|HEAD ?action=init&tproject_id=N[&tplan_id=P]`,
  `GET|HEAD ?action=report&tplan_id=N[&tproject_id=P][&build_id=B]`
- Fixture: `tmp/fixtures_1999.php`
- Test suite: `Issue #1999` in `tmp/TLU_Test_Cases.md`

## Why a new report

TestLink 1.9.20 can print a whole plan on one build (`resultsByBuild`) or a
single suite on one build (`resultsByTSuite`), and 2.0.1 added suite × build,
tester × build and suite × platform matrices. None of them shows **every
individual test case against every build**, so "which test case is green in
which build?" had to be stitched together by eye. The Test Case × Build Result
Matrix answers that question at the finest granularity.

## Screen

- Toolbar: project, plan and build pickers, Apply, Refresh, Export CSV, Close.
- Verdict banner (`no_data` / `not_started` / `in_progress` / `complete`) with
  the plan-wide coverage percentage and executed/cell counts.
- Report context card (project, plan, active build filter).
- 11 KPI cards: test cases, builds, cells, executed, passed, failed, blocked,
  not run, coverage %, pass rate %, flaky cases.
- Matrix: one row per assigned test-case version (name, version badge, suite
  path and external id), one column per build; each cell shows the latest
  result of that version in that build (passed / failed / blocked / not run)
  with an icon and colour. Clicking a cell re-scopes the report to that build.
- Per-test-case totals table (builds, executed, passed, failed, blocked, not
  run, pass rate, per-row verdict `stable_pass` / `stable_fail` / `mixed` /
  `blocked_only` / `not_run`) and per-build totals table (with an
  active/inactive badge per build).
- CSV export with three sections (summary, matrix cells, per-build totals),
  `;` separator, BOM and formula-injection guard.
- Localized loading, empty, 400/401/403/404/405 state cards.

## Semantics

- The unit is a test-case version assigned to the plan (`testplan_tcversions`);
  a version pinned on several platforms collapses to one row.
- The suite path is resolved through the test-case node
  (`tcversion node → test case node → test suite node`), following the full
  hierarchy.
- Builds are all builds of the owning test project, active or closed; an
  optional `build_id` filter collapses the matrix to that single build.
- Each cell uses the **latest execution** (highest `executions.id`) per (build,
  test-case version) across every platform of the plan; a version with no
  execution in a build counts as **not run**.
- `assigned` is counted per build, so 5 versions × 3 builds = 15 cells.
- Row verdict: `not_run` (no execution), `stable_pass` (no failure/block),
  `stable_fail` (no pass/block), `blocked_only` (only blocks), otherwise
  `mixed`. A row is counted as flaky when its verdict is `mixed`.
- Plan verdict precedence: `no_data` (no versions or no builds) >
  `not_started` (no execution) > `complete` (no not-run cell) > `in_progress`.

## API contract

| Case | HTTP | Code |
|---|---|---|
| `GET|HEAD` success | 200 | `ok` |
| Unknown action / missing or invalid `tplan_id` | 400 | `invalid_request` |
| No session | 401 | `not_authenticated` / `session_expired` |
| No `testplan_metrics` right on the owning project | 403 | `no_right` |
| Unknown plan/project, or `tproject_id` not the plan owner | 404 | `tplan_not_found` / `tproject_not_found` / `project_mismatch` |
| POST without same-origin headers | 403 | shared guard, no `code` field |
| `POST` with same-origin headers (GET only) | 405 | `method_not_allowed` |

The plan is resolved first, then `testplan_metrics` is checked on the **owning**
project; the client `tproject_id` is only an assertion, so it can never widen
access. Authorization denials (`no_right`) are written to the `AUDIT` log.
An unknown plan id is reported as 404 only to callers that already hold
`testplan_metrics`; callers without it get 403 `no_right` first (fail-closed,
no existence oracle).

## Wiring

- `cfg/reports.cfg.php`: `reports_list['tc_build_matrix']` →
  `gui/templates/results/tcBuildMatrix.html`.
- `api/aside/index.php`: `link_report_tc_build_matrix` branch builds the
  contexted href for the Reports menu.
- `lib/functions/common.php`: `$actions->tcBuildMatrix`.
- i18n: `tcbm.*` (97 keys) + `footers.tcBuildMatrix` in all 10 client bundles;
  `$TLS_link_report_tc_build_matrix` in all 19 server locales.

## Verification

- Browser: default matrix (5 test cases × 3 builds, coverage 46.7%, pass rate
  42.9%, 1 flaky case), latest-execution-wins, cell-click build re-scoping,
  deep-link build filter, empty plan (`no_data`), pickers after absent
  deep-link ids, Romanian locale, no-right user (`no_right` card), CSV content,
  ASIDE + Reports launcher; console clean; Event Viewer 0 new Error/Warning.
- Machine: 200/400/401/403/404/405 contract, project mismatch, `HEAD`, i18n
  gate 9/9, test-suite gate PASS.
