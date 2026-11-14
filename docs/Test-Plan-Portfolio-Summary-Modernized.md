# Test Plan Portfolio Summary report (modernized)

Tracking issue: **#1990** (net-new 2.0.1 report; TestLink 1.9.20/2.0.1 could
only look at one test plan at a time).

## What it is

Every 1.9.20 results report is scoped to a single test plan. A test manager
with several plans in the same project had to open each report in turn and add
the numbers up by hand. This read-only report puts **every plan of one test
project** on a single screen: assigned / executed / not-run counts, coverage
and pass rate, tester and defect counts, builds used, the last execution
timestamp and a progress/health signal per plan, plus a portfolio-wide summary
for the project.

## Screen

`gui/templates/results/planPortfolio.html`

- Dashio teal header, dark toolbar (test-project switcher, plan-state filter,
  Refresh, Export CSV, Close).
- Report-context panel: test project, test-plan count, project build count,
  last execution timestamp.
- Summary banner: plans in progress with overall coverage, not-started plans,
  all-good, attention (complete plans with failed/blocked results), a neutral
  "no plans" banner, and a neutral "no assigned test cases" banner.
- 12 KPI cards: test plans, active, assigned, executed, coverage, pass rate,
  complete plans, in progress, not started, testers, defects, failed/blocked.
- Main DataTable (16 columns, searchable, sortable): plan name (links to the
  execution dashboard), state, assigned, executed, not run, coverage, pass
  rate, passed, failed, blocked, testers, assigned testers, builds used,
  defects, last execution, progress.
- Plan-state filter (all / active only / closed only) narrows the table
  client-side; the summary always reflects the whole project.
- CSV export `plan-portfolio-<project>.csv` (UTF-8 BOM, `;` separator,
  spreadsheet-formula neutralisation), localised headers and state labels.
- Localized state cards for 400 / 401 / 403 / 404 / 405; locale switcher; all
  labels in `ppf.*` keys.

## BFF

`api/planportfolio/index.php` — plain PHP, session auth,
`bffSameOriginGuard`, `bffEnforceSession`, JSON I/O, `Cache-Control: no-store`.

| Action | Method | Parameters |
| --- | --- | --- |
| `projects` | `GET\|HEAD` | — (projects the caller may read metrics on) |
| `init` | `GET\|HEAD` | optional `tproject_id` |
| `report` | `GET\|HEAD` | optional `tproject_id` |

Rights: `testplan_metrics` on the **addressed** test project. An omitted
`tproject_id` falls back to the session project, then to the first project the
caller may read metrics on.

Error contract: `not_authenticated` (401), `no_right` (403, logged at
`AUDIT`), `project_not_found` (404), `invalid_request` (400),
`method_not_allowed` (405).

## Semantics

- The unit is one test plan of the project; `active = 0` counts as closed.
- The latest execution wins per (version, platform); a platform-less link uses
  its newest execution on any platform.
- `executed` counts only the latest-status executed outcomes (passed / failed
  / blocked); `not_run = assigned - executed`.
- Coverage = executed / assigned; pass rate = passed / executed.
- `progress_state`: `empty` (0 assigned), `not_started` (0 executed),
  `in_progress` (some not run), `complete` (all executed).
- `health`: `na` (nothing executed), `good` (pass rate >= 90% and no failed or
  blocked), `watch` (pass rate >= 70%), `bad` (otherwise).
- Portfolio testers = distinct (plan, tester) pairs with executions; defects =
  distinct linked bug ids across the project's plans.

## Wiring

- `cfg/reports.cfg.php` — `plan_portfolio` report entry.
- `api/aside/index.php` — the Reports submenu link.
- `api/resultsnav/index.php` — `plan_portfolio` → the modern URL.
- `lib/functions/common.php` — `$actions->planPortfolio`.
- `locale/*/strings.txt` (19 locales) — `$TLS_link_report_plan_portfolio`.

## i18n

`ppf.*` (75 keys) + `footers.planPortfolio` in all 10 client bundles;
`ai/verify_i18n_coverage.sh` passes 9/9.

## Verification

- Fixture `tmp/fixtures_1981.php` reused (project BAG1981 with plan BAG Plan:
  7 assigned / 6 executed / 1 never run / 2 defects; a second empty project
  BAG1981 EMPTY; a role-3 user without `testplan_metrics`).
- Machine contract: `projects`, `init`, `report`, project fallback, empty
  project, 400/401/403/404/405 paths, HEAD and POST handling.
- Browser EN/RO: default report, plan-state filter, CSV export, empty project,
  no-right state; console clean; Event Viewer 0 new Error/Warning (the
  right-denial is AUDIT).
- Code review applied: a platform-specific link only sees its own platform's
  runs; project builds are counted even with zero plans; percentage columns
  sort numerically; the plan-state filter is a literal search; CSV follows the
  active plan-state filter.
- Suite `Issue #1990` appended to `tmp/TLU_Test_Cases.md`.

## Screenshots

- `docs/screenshots/1990-plan-portfolio-normal.png` — default report (EN).
- `docs/screenshots/1990-plan-portfolio-filtered.png` — closed-plans filter.
- `docs/screenshots/1990-plan-portfolio-ro.png` — Romanian locale.
- `docs/screenshots/1990-plan-portfolio-empty.png` — project without assigned
  test cases.
- `docs/screenshots/1990-plan-portfolio-norights.png` — no-rights state.
