# Modernize: Results by Tester Bar Chart (`ownerBarChart`) — Refs #1972

## Context

`cfg/const.inc.php` still declares the chart dimension `ownerBarChart`
(`results_by_tester`), but the modern Charts hub only exposed the overall pie,
the per-platform pies, the keyword bar, the priority bar and the top-level
suite bar. The legacy by-tester renderer was deprecated upstream and the
per-build table lives in `resultsByTesterPerBuild.html`, so no screen showed the
aggregate picture: **who executed what, and with which result, across all
builds**.

This run adds that missing standalone chart. It is a new screen, not a twin of
a live legacy controller.

## What changed

### `api/ownerbarchart/index.php` (new BFF)

`GET|HEAD ?action=init&tplan_id=N[&tproject_id=P]`

- Resolves the plan first, then checks `testplan_metrics` on the **owning**
  project + plan with `getAccess = true` (same gate as the sibling chart BFFs).
  A forged `tproject_id` is rejected with `project_mismatch` only after the
  right check.
- Counts the **latest recorded execution per (test-case version, platform)**
  across all builds (`NOT EXISTS` newer `execution_ts`, ties by higher id) and
  only for versions still assigned to the plan (`testplan_tcversions`).
- Groups those rows by the tester who recorded them. Null testers are reported
  as `context.unattributed` and are never turned into a fake bar.
- Statuses: passed / failed / blocked. “Not run” is the absence of an execution
  and cannot be attributed to a tester, so it is not drawn.
- Per-tester and total `percent` = pass rate (passed / total), one decimal.
- JSON: `status`, `code`, `context`, `items`, `statuses`, `totals`.
  400 `invalid_request`, 401 `not_authenticated` / `session_expired`,
  403 `no_right`, 404 `tplan_not_found` / `project_mismatch`,
  405 `method_not_allowed`.
- `Cache-Control: no-store`, `nosniff`, `bffEnforceSession`,
  `bffSameOriginGuard`, GET/HEAD only.

### `gui/templates/results/ownerBarChart.html` (new screen)

- Dashio shell (teal header, dark toolbar, locale switcher, footer).
- Context strip: project, plan, tester count, latest executions, executions
  without a tester, plus a note explaining the counting rule.
- One stacked CSS bar per tester with pass / fail / blocked legend, count and
  pass-rate caption; accessible `role="img"` labels.
- DataTable with the numbers behind every bar and a totals row.
- CSV export (BOM, localized headers, one row per tester + totals).
- State cards for loading, empty plan, denied, expired, not found, bad request
  and generic errors; “All charts” returns to `charts.html` with the same
  context, Refresh re-runs the query, Close closes the tab.

### Wiring

- `$actions->ownerBarChart` in `lib/functions/common.php` (plan-scoped).
- New “Results by Tester” section on `gui/templates/results/charts.html` with
  the “Open Results by Tester Bar Chart” link, carrying `tplan_id` and
  `tproject_id`; the link is hidden when no plan is selected.

### i18n

`obc.*` (45 keys) + `charts.resultsByTester`,
`charts.openOwnerBarChart`, `charts.resultsByTesterHint` and
`footers.ownerBarChart` in all 10 client bundles
(`en, de, es, fr, it, ja, pt, ro, ru, zh`).
`bash ai/verify_i18n_coverage.sh` → 9/9 PASS.

## Verification

Fixture: project 100 “OBC Project”, plan 400 “OBC Plan”, builds 500/501, two
testers, three assigned versions (one never executed, one executed without a
tester).

- Latest-per-version across builds is counted once; build-ordering does not
  change the result; unattributed executions are not drawn (Suite #1972).
- Two tester bars render with pass rates; totals are 3 / 2 / 1 / 0 / 66.7%.
- Empty plan 401 → “Nothing to plot”, HTTP 200, `items: []`.
- CSV downloads with localized headers, tester rows and totals.
- `norights` (role 3) → 403 card; anonymous → 401; bad/missing plan → 400/404;
  `project_mismatch` → 404; POST → 405 before the CSRF guard.
- Charts hub link keeps the context; RO locale renders localized labels with no
  raw keys.
- No console errors; Event Viewer shows only the intentional `no_right` audit
  ERROR from the rights test.
