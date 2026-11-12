# Test Plan Build × Platform Coverage Matrix (modernized)

Tracking issue: **#1987** (net-new 2.0.1 report; TestLink 1.9.20/2.0.1 stored
executions per build and per platform but shipped no screen that showed the two
dimensions together).

## What it is

A test plan assigns test cases to the plan and, when platforms are used, every
assignment also carries a `platform_id`. Executions are stored per
**build + platform**, so "is build 5 covered on Android?" cannot be answered
from the execution dashboard, which aggregates builds and platforms separately.

This read-only report renders the plan's **build × platform matrix**: one row
per build, one column per platform (plus a "No platform" column for assignments
without a platform), and in every cell the coverage, pass rate and
passed/failed/blocked/not-run counters of that build on that platform. A
plan-wide verdict banner plus per-build and per-platform totals make gaps
obvious, and clicking a cell filters the whole report to that build/platform.

![Build × platform matrix, default scope](screenshots/issue-1987-buildplatformmatrix-normal.png)

## Screen

`gui/templates/results/buildPlatformMatrix.html`

- Dashio teal header, dark toolbar (project, plan, build and platform selects,
  Apply, Refresh, Export CSV, Close).
- Report-context panel: test project, test plan, active build filter, active
  platform filter.
- Verdict banner: `complete` (everything executed), `in_progress` (some
  execution), `not_started` (assignments but nothing executed), `no_data`
  (the plan has no assignments).
- 11 KPI cards: builds, platforms, assignments, distinct cases, executed,
  passed, failed, blocked, not run, coverage, pass rate.
- Sticky-header matrix table with one row per build, one column per platform:
  coverage bar, executed/assigned fraction, pass/fail/blocked/not-run mini
  counters and colour-coded cells (fully covered, partial/blocked, failures,
  nothing executed). Clicking a cell sets the build and platform filters and
  reloads the report.
- Per-build DataTable and per-platform DataTable (searchable, sortable) with
  assignments, executed, passed, failed, blocked, not run, coverage, pass rate
  and verdict.
- CSV export `build-platform-matrix-<plan>.csv` (UTF-8 BOM, `;` separator,
  spreadsheet-formula neutralisation), disabled on error and empty plans; four
  sections: summary KPIs, per-build totals, per-platform totals and the
  build × platform matrix.
- Localized state cards for 400 / 401 / 403 / 404 / 405, `project_mismatch`
  and "no test projects"; locale switcher, all labels in `bpxm.*` keys.

![Build × platform matrix, filtered by cell click](screenshots/issue-1987-buildplatformmatrix-filtered.png)

![Build × platform matrix, empty plan](screenshots/issue-1987-buildplatformmatrix-empty.png)

![Build × platform matrix, Romanian locale](screenshots/issue-1987-buildplatformmatrix-ro.png)

## BFF

`api/buildplatformmatrix/index.php` — plain PHP, session auth,
`bffSameOriginGuard`, `bffEnforceSession`, JSON I/O,
`Cache-Control: no-store`, `JSON_INVALID_UTF8_SUBSTITUTE`.

| Action | Method | Parameters |
| --- | --- | --- |
| `projects` | `GET\|HEAD` | — |
| `init` | `GET\|HEAD` | `tproject_id`, optional `tplan_id` |
| `report` | `GET\|HEAD` | `tplan_id`, optional `tproject_id`, `build_id`, `platform_id` |

Rights: `testplan_metrics` on the **owning** test project of the plan. The
client-supplied `tproject_id` is only an assertion: it is checked against the
plan's owning project and can never widen the query.

Order of operations: resolve the plan (opaque 404 when unknown) → check
`testplan_metrics` on its owning project (403 `no_right`) → only then assert
the client `tproject_id` (404 `project_mismatch`), so the endpoint is not a
plan/project existence oracle for unentitled callers.

Error contract: `not_authenticated` (401), `no_right` (403),
`tplan_not_found` / `project_mismatch` (404), `invalid_request` (400, also an
invalid platform), `unknown_action` / `method_not_allowed` (400/405).

## Semantics

- The unit is the **test case version assigned to the plan** through
  `testplan_tcversions`; `platform_id` is part of that assignment, so each
  platform column has its own universe of assigned versions.
- `platform_id = 0` is a real "No platform" column (assignments without a
  platform); it is rendered but cannot be selected in the filter, because the
  filter parser only accepts positive ids.
- Per cell, the **latest execution wins**: for each assigned version the
  execution with the highest `executions.id` for that build and platform is
  classified as passed / failed / blocked; no execution means "not run".
- `assigned` counts assignments (a version assigned on two platforms counts in
  both columns and twice in the overall total); `distinct_cases` counts the
  unique test cases behind those assignments.
- Coverage = executed / assigned; pass rate = passed / executed. With no
  executed assignment the pass rate is 0.
- `build_id` narrows the matrix to one row, `platform_id` to one column, but
  the summary always describes exactly the filtered scope.
- Builds are listed newest-first; platforms keep their plan link order and the
  "No platform" column is last. Inactive builds are shown with an `Inactive`
  badge.
- Verdict: `no_data` when there are no assignments, `complete` when every
  assignment has an execution, `in_progress` when at least one execution
  exists, `not_started` otherwise.

## i18n

- 90 `bpxm.*` keys in all 10 client bundles
  (`gui/templates/i18n/*.json`), including `footers.buildPlatformMatrix`.
- `$TLS_link_report_build_platform_matrix` in all 19 server locales
  (`locale/*/strings.txt`).
- ASIDE mapping in `api/aside/index.php`, reports catalogue entry in
  `cfg/reports.cfg.php`, `$actions->buildPlatformMatrix` in
  `lib/functions/common.php`.

## Verification

- Suite `Issue #1987` in `tmp/TLU_Test_Cases.md`: 18/18 PASS (API semantics,
  rights and error paths, browser, filters, cell click, CSV, empty plan,
  no-rights user, RO locale, regressions).
- `bash ai/verify_i18n_coverage.sh` → 9/9 bundles, no missing key.
- `php -l` clean on the BFF and every touched wiring file.
- Event Viewer: AUDIT rows only, no new Error/Warning.
