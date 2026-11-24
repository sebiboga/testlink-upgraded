# Modernize: Test Plan Build Execution Activity (Issue #2056)

**Screen:** `gui/templates/results/buildActivity.html`
**BFF:** `api/buildactivity/index.php`
**Status:** DONE — one new standalone Dashio screen, no Smarty, no legacy controller.

## Why this screen

TestLink 1.9.20 could show a plan's latest result per build (`resultsByBuild`)
or a chronological quality trend (`buildTrend`), but it never answered the
operational question: *when was each build actually exercised, by whom, with
which results, and how long has it been idle?* The latest-status snapshots
collapse re-runs and hide the activity timeline. 2.0.1 now has a dedicated
per-build activity report that groups **all execution events** of one test plan
by build.

## Screen

- Header toolbar: Refresh, Export CSV, Build / Platform / Result / Window
  filters and Apply / Close.
- Context panel with test project, test plan and the active scope.
- 12 KPI tiles: builds, builds with activity, executions, assigned versions,
  passed/failed/blocked, pass rate, testers, linked defects, active days and
  last activity.
- Verdict banner (`active`, `watch`, `idle`, `n/a`) driven by recent activity
  (last 7 days) and idle gap; stale builds are `watch`, not `active`.
- Build DataTable with 17 columns: state, executions, distinct cases,
  passed/failed/blocked, pass rate, testers, defects, active days, average
  duration, last-7-days count, idle days, last execution and verdict.
- Per-build Detail modal listing the execution events of the selected build
  (day, test-case version, result, tester, duration, defects, notes), with
  `inert` applied to the shell while the modal is open.
- Daily activity DataTable and UTF-8 CSV export with BOM, `;` separator,
  quoted fields and the `=+-@` formula-injection guard.
- Localized 400/401/403/404/405 state cards; EN/RO checked in the browser.
  All `bact.*` labels and `footers.buildActivity` exist in all 10 client
  bundles; `ai/verify_i18n_coverage.sh` reports 9/9 PASS (13173 keys).
  `$TLS_link_report_build_activity` was added to all 18 server locale files.

## BFF contract

| Route | Method | Right | Result |
|---|---|---|---|
| `?action=init&tplan_id=N[&tproject_id=P]` | GET/HEAD | `testplan_metrics` | context, filter options and current scope |
| `?action=report&tplan_id=N[&tproject_id=P][&build_id=B][&platform_id=PL][&status=p\|f\|b][&window_days=0\|7\|30\|90\|365]` | GET/HEAD | `testplan_metrics` | summary, per-build rows, daily series |
| `?action=detail&tplan_id=N&build_id=B[...]` | GET/HEAD | `testplan_metrics` | capped execution-event list for one build |

All routes call `bffSameOriginGuard()` and `bffEnforceSession()`. The plan is
resolved first; `testplan_metrics` is checked on the **owning** project before
the client's `tproject_id` assertion, so cross-project probes are opaque 404s.
Denials are written to the Event Viewer as AUDIT rows. Validation returns
machine codes `invalid_request`, `not_authenticated`, `no_right`,
`tplan_not_found`, `project_mismatch`, `build_not_found` and
`method_not_allowed`.

The BFF counts every execution row (re-runs included), deduplicates cases,
testers and literal defect IDs, reads `execution_duration` for the average,
and reports builds with no executions as `idle`. Platform `0` executions are
counted in the all-platforms scope; selecting a named platform restricts the
report to executions recorded on that platform. Selecting a build narrows the
emitted rows and `summary.builds` to that build, including deleted/orphan
build IDs still referenced by executions.

## Link switch

`cfg/reports.cfg.php` gained `build_activity` with title
`link_report_build_activity`; `api/aside/index.php` maps that Reports entry to
the new screen with `tproject_id` / `tplan_id`; `api/resultsnav/index.php`
gained the `build_activity` screen map; `lib/functions/common.php` gained
`$actions->buildActivity`. No legacy controller was reused.

## Issues found while testing

- #2057 — duplicate `All time` option in the Window filter (BFF returned a
  `days=0` window in addition to the screen's all-option). Fixed and verified:
  the filter now has one All time plus 7/30/90/365 days.
- #2058 — a build with no executions in 7 days and a 13-day idle gap was shown
  as `Active`. Fixed: `busy` now requires recent executions; stale builds are
  `watch`.
- #2059 — Romanian title/subtitle/footer were English or machine-mixed, and
  the daily DataTable length label stayed `Show`. Fixed: all three keys
  translated in every non-English bundle and the daily table now uses the
  shared localized `lengthMenu` / `paginate` strings.
- #2060 — code-review hardening: the detail modal now applies `inert` only to
  the shell, closes on backdrop click and on load failure, Refresh re-runs the
  scoped report instead of resetting filters, builds deleted from `builds`
  still appear as history rows, the all-time first/last timestamps survive a
  rolling-window filter, the detail response no longer duplicates the whole
  report, and untranslated message keys were localized in every bundle.

Fixture: `tmp/fixtures_2056.php` (BAC2056 project, 2 plans, 3 builds, 2
platforms, 4 cases, 8 executions, one idle build and a role-3 no-right user).
Suite `Issue #2056` is appended to `tmp/TLU_Test_Cases.md` and executed.
Event Viewer stayed clean (AUDIT-only rows from login and permission probes).
