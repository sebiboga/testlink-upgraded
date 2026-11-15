# Test Plan Suite × Build Coverage Matrix (suiteBuildMatrix)

Modernized screen for issue **#1993** in TestLink 2.0.1.

- Screen: `gui/templates/results/suiteBuildMatrix.html`
- BFF: `api/suitebuildmatrix/index.php`
- Rights: `testplan_metrics` on the owning test project
- API: `GET|HEAD ?action=projects`,
  `GET|HEAD ?action=init&tproject_id=N[&tplan_id=P]`,
  `GET|HEAD ?action=report&tplan_id=N[&tproject_id=P][&build_id=B]`
- Fixture: `tmp/fixtures_1993.php`
- Test suite: `Issue #1993` in `tmp/TLU_Test_Cases.md`

## Why a new report

TestLink 1.9.20 can print a whole plan on one build (`resultsByBuild`) or a
single suite on one build (`resultsByTSuite`), and 2.0.1 added a builds ×
platforms matrix (`buildPlatformMatrix`). None of them puts **every suite of a
plan against every build of the project**, so "which suite is green in which
build?" had to be stitched together by eye. The Suite × Build Coverage Matrix
answers that question in one screen.

## Screen

- Toolbar: project, plan and build pickers, Apply, Refresh, Export CSV, Close.
- Verdict banner (`no_data` / `not_started` / `in_progress` / `complete`) with
  the plan-wide coverage percentage and executed/assigned counts.
- Report context card (project, plan, active build filter).
- 11 KPI cards: suites, builds, assignments, distinct cases, executed, passed,
  failed, blocked, not run, coverage %, pass rate %.
- Matrix: one row per test suite (labelled with its full path), one column per
  build; each cell shows coverage %, executed/assigned, a coverage bar and the
  passed / failed / blocked / not-run counters, colour-coded by state. Clicking
  a cell re-scopes the report to that build.
- Per-suite totals table and per-build totals table (with an active/inactive
  badge per build).
- CSV export with four sections (summary, per-suite totals, per-build totals,
  matrix cells), `;` separator, BOM and formula-injection guard.
- Localized loading, empty, 400/401/403/404/405 state cards.

## Semantics

- The unit is a test-case version assigned to the plan (`testplan_tcversions`).
- A version belongs to the **direct parent suite of its test-case node**
  (`tcversion node → test case node → test suite node`); suites without assigned
  versions are omitted, and a suite's row label is its full hierarchy path.
- Builds are all builds of the owning test project, active or closed; an
  optional `build_id` filter collapses the matrix to that single build.
- Each cell uses the **latest execution** (highest `executions.id`) per (build,
  test-case version) across every platform of the plan; a version with no
  execution in a build counts as **not run**.
- `assigned` is counted per build, so a plan with 6 versions and 3 builds has
  18 assignments; `distinct_cases` stays 6.
- Verdict precedence: `no_data` (no assignments at all) > `not_started` (no
  execution) > `complete` (no not-run assignment) > `in_progress`.

## API contract

| Case | HTTP | Code |
|---|---|---|
| `GET|HEAD` success | 200 | `ok` |
| Unknown action / missing or invalid `tplan_id` | 400 | `invalid_request` |
| No session | 401 | `not_authenticated` / `session_expired` |
| No `testplan_metrics` right on the owning project | 403 | `no_right` |
| Unknown plan/project, or `tproject_id` not the plan owner | 404 | `tplan_not_found` / `tproject_not_found` / `project_mismatch` |
| POST without same-origin headers | 403 | `cross_origin` |
| `POST` with same-origin headers (GET only) | 405 | `method_not_allowed` |

The plan is resolved first, then `testplan_metrics` is checked on the **owning**
project; the client `tproject_id` is only an assertion, so it can never widen
access. Every denial is written to the `AUDIT` log.

## Wiring

- `cfg/reports.cfg.php`: `reports_list['suite_build_matrix']` →
  `gui/templates/results/suiteBuildMatrix.html`.
- `api/aside/index.php`: `link_report_suite_build_matrix` branch builds the
  contexted href for the Reports menu.
- `lib/functions/common.php`: `$actions->suiteBuildMatrix`.
- i18n: `sbm.*` (86 keys) + `footers.suiteBuildMatrix` in all 10 client bundles;
  `$TLS_link_report_suite_build_matrix` in all 19 server locales.

## Verification

- Browser: default matrix (2 suites × 3 builds, coverage 72.2%, pass rate
  76.9%), latest-execution-wins, cell-click build re-scoping, deep-link build
  filter, empty plan (`no_data`), pickers after absent deep-link ids, Romanian
  locale, no-right user (`no_right` card), CSV content, ASIDE + Reports
  launcher; console clean; Event Viewer 0 new Error/Warning.
- Machine: 200/400/401/403/404/405 contract, project mismatch, `HEAD`, i18n
  gate 9/9, test-suite gate PASS.
