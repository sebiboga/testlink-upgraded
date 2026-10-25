# Test Plan Platform Comparison (Modernized) — Issue #1963

The modern Dashio page **`gui/templates/results/platformComparison.html`**, backed by the
BFF **`api/platformcomparison/index.php`**, is a **net-new 2.0.1 report**. TestLink 1.9.20
repeats the current plan status once per platform and never diffs the platforms; the
`buildComparison` report diffs two builds of one plan. This screen compares the **same**
test plan across **two of its platforms** and shows, per test-case version, how the
execution status moved (regressed / fixed / new pass / new fail / no longer run / unchanged).

## Layout
- **Header** (teal Dashio) with the report title and the locale switcher in the footer.
- **Toolbar**: Test project, Test plan, **Platform A (baseline)**, **Platform B (target)**,
  Apply, Refresh, **Export CSV**, Close.
- **Banner**: regressions, improvements, stable, or "no execution data" verdict.
- **Report context** card: test project, test plan, platform A, platform B.
- **Platform summary** cards (A and B): assigned, executed, passed, failed, blocked,
  not run, pass rate, coverage.
- **Change summary** cards: Fixed, Regressed, New pass, New fail, No longer run, Changed.
- **Per test case changes** DataTable with a change-type filter and CSV export.
- **State cards**: no platforms, invalid request, not found, access denied, session
  expired, method not allowed, server error — each with the BFF machine code.

## Semantics (computed by the BFF, the client only renders it)
- The universe is every **test-case version linked to the plan** (`testplan_tcversions`),
  so a version assigned to only one of the two platforms still appears (not run there).
- **Latest execution wins** per (tcversion_id, platform_id) using `MAX(executions.id)`.
- `assigned` per platform counts the plan versions whose assignment targets that platform
  (`platform_id = P`) or has no platform (legacy `platform_id = 0`).
- `executed` = assigned versions with a known final status; `not_run` = assigned but never
  executed; `pass_rate` = passed / executed; `coverage` = executed / assigned.
- **Change classification** compares status_a vs status_b: `regressed` (passed -> failed),
  `fixed` (failed/blocked -> passed), `new_pass` (not run -> passed), `new_fail`
  (not run -> failed), `revived` (executed in A, no execution in B), `changed` (other
  transitions) or `same_passed` / `same_failed` / `same_blocked` / `same_not_run`.

## Filters
- `tproject_id`, `tplan_id` — scope (the plan must belong to the asserted project).
- `platform_a`, `platform_b` — two different platforms of the owning project, both linked
  to the plan (`testplan_platforms.active = 1`).

## Data model (BFF `api/platformcomparison/index.php`)
- **Routes**: `GET|HEAD ?action=projects` (project picker),
  `?action=init&tproject_id=N[&tplan_id=P]` (plans + platforms linked to the plan; all
  open project platforms when no plan is supplied), and
  `?action=report&tplan_id=N[&tproject_id=P]&platform_a=A&platform_b=B`.
- **Rights**: session auth + `bffSameOriginGuard` + `bffEnforceSession`, and
  `testplan_metrics` on the **owning** project of the plan. The client `tproject_id` is
  only an assertion.
- **Error contract**: `400 invalid_request|invalid_parameter|unknown_action`,
  `401 not_authenticated|session_expired`, `403 no_right`,
  `404 tplan_not_found|tproject_not_found|project_mismatch`,
  `405 method_not_allowed`.

## Wiring
- `cfg/reports.cfg.php`: `reports_list['platform_comparison']` -> the modern page.
- `api/aside/index.php`: `link_report_platform_comparison` branch in the Reports menu.
- `api/resultsnav/index.php`: `platform_comparison` -> modern page.
- `lib/functions/common.php`: `$actions->platformComparison`.

## i18n
All labels come from `TLi18n` keys under `pcomp.*` and `footers.platformComparison` in all
10 client bundles; the server menu entry uses `link_report_platform_comparison` in all 19
server locales. `ai/verify_i18n_coverage.sh` passes 9/9 bundles.

## Testing
Suite `Issue #1963` (10 cases) in `tmp/TLU_Test_Cases.md`: normal render, platform
auto-selection/swap, change classification, change filter, Apply/Refresh/plan change,
CSV export, plan without platforms, BFF security/error codes, EN+RO i18n, reports-menu
wiring. Event Viewer: 0 new Error/Warning rows.

## Screenshots
See `docs/screenshots/1963-platformcomparison-normal.png`,
`1963-platformcomparison-ro.png` and `1963-platformcomparison-noplatforms.png`.
