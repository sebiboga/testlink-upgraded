# Test Plan Comparison (Modernized) — Issue #1957

The modern Dashio page **`gui/templates/results/planComparison.html`**, backed by the
BFF **`api/plancomparison/index.php`**, is a **net-new 2.0.1 report**: every TestLink
1.9.20 report is scoped to **one** test plan, so comparing two plans meant opening two
report tabs and comparing the numbers by eye. This screen puts two plans of the same
project side by side and shows the delta per metric, the better plan, and per-build /
per-platform breakdowns.

## Layout
- **Header** (teal Dashio) with the report title and the locale switcher in the footer.
- **Toolbar**: Test project, **Plan A**, **Plan B**, **Build**, **Platform**, Apply,
  Refresh, **Export CSV**, Close.
- **Verdict banner**: which plan leads on pass rate, a tie variant and a "no execution"
  variant.
- **Report context** card: test project, plan A, plan B, build, platform.
- **Plan metrics** cards: assigned, executed, passed, failed, blocked, not run, pass
  rate, defect references — one block per plan.
- **Comparison DataTable** (11 rows): metric, Plan A, Plan B, delta (B - A), better plan.
- **Results per build** and **Results per platform** DataTables: executed / passed /
  failed / blocked per plan for every build/platform of the project.
- **State cards**: Invalid request / Not found / Access denied / Session expired /
  Method not allowed / Server error, each with the machine code of the BFF.

## Semantics (computed by the BFF, the client only renders it)
- The unit is the **test-case version** linked to the plan (`testplan_tcversions`), so a
  version linked on two platforms is counted **once** when no platform filter is set.
- The **latest execution wins** (`MAX(executions.id)` per version in the analysed scope).
- `assigned` = linked versions; `executed` = versions with a known final status;
  `not_run` = assigned - executed (never negative).
- `pass_rate` = passed / executed; `coverage` = executed / assigned.
- `defects` = **distinct** `execution_bugs.bug_id` references of the analysed
  executions (the id is a varchar, never summed numerically), restricted to the
  versions still linked to the plan.
- An execution of a version that is no longer linked to the plan is ignored.
- The per-build breakdown takes the latest execution per (version, build); the
  per-platform breakdown per (version, platform) — a version counts once per row
  and walks the **union** of both plans' platforms, so a platform linked only to
  plan B still appears (with zeros on the A side).
- **Both plans must belong to the same test project.** Comparing plans of two
  different projects is refused with `400 plans_not_comparable`.

## Filters
- `build_id` — restricts the analysed executions (and the context card).
- `platform_id` — restricts the executions **and** the universe: only the versions
  linked on that platform are counted.
- A `build_id` / `platform_id` of another project is `400 invalid_request`.

## Data model (BFF `api/plancomparison/index.php`)
- **Routes**: `GET|HEAD ?action=projects` (project picker), `?action=init&tproject_id=N`
  (plans + builds + platforms), `?action=report&tplan_a=A&tplan_b=B[&tproject_id=P]
  [&build_id=B][&platform_id=PL]`. No write verb.
- **Rights**: `testplan_metrics` on the **owning** project of both plans (the
  project picker / `init` accept any of `mgt_view_tc`, `mgt_modify_tc`,
  `testplan_metrics`, so a metrics-only user can use the report). The client
  `tproject_id` is only an **assertion**: it is compared with the owning project and
  never widens the query (`404 project_mismatch`). Comparing a plan with itself is
  refused server-side (`400 invalid_request`), not only by the UI.
- **Error contract**: `400 invalid_request` / `400 plans_not_comparable` /
  `400 unknown_action`, `401 not_authenticated|session_expired`, `403 no_right`,
  `404 tplan_not_found|tproject_not_found|project_mismatch`,
  `405 method_not_allowed`. An unknown plan is an opaque 404 (no existence oracle).
- All ids are read with a strict positive-integer reader ("1abc", "1.9", "-3" and ""
  are refused). Permission denials are logged at **AUDIT** level, so the Event Viewer
  stays free of Error/Warning rows.

## Wiring
- `cfg/reports.cfg.php` → `reports_list['plan_comparison']` (`enabled = all`).
- `api/aside/index.php` → Reports submenu entry `link_report_plan_comparison`.
- `api/resultsnav/index.php` → `plan_comparison` in `$legacy2modern` (absolute href).
- `lib/functions/common.php` → `$actions->planComparison`.
- `$TLS_link_report_plan_comparison` in **all 19** server locales.
- `plcmp.*` (88 keys) + `footers.planComparison` in **all 10** client bundles
  (`ai/verify_i18n_coverage.sh` 9/9 PASS).

## Verification
- Browser (EN + RO): default render via a `?tplan_id=` deep link (Plan A = the current
  plan, Plan B = the first different plan), build filter, platform filter, plan swap,
  same-plan state, single-plan project state, project switch, CSV export, the
  rights-less account (empty picker, no project name disclosed), the anon redirect to
  `login.php?note=expired`, the Reports-menu entry, console clean, Lighthouse
  Accessibility/Best Practices/SEO 100 with 0 failed audits.
- API (curl): the fixture returns A assigned 5 / executed 4 / passed 2 / failed 1 /
  blocked 1 / not run 1 / pass 50.0 / coverage 80.0 / defects 1 and B assigned 4 /
  executed 3 / passed 2 / failed 1 / not run 1 / pass 66.7 / coverage 75.0 / defects 1;
  build 2 and platform filters change the scope exactly as documented; the full
  400/401/403/404/405 matrix is green.
- Bugs found and fixed during testing:
  - Plan B defaulted to the same plan as Plan A (`.html()` leaves a `<select>` on its
    first option) and the `tplan_id` deep link was ignored → now A = requested/current
    plan and B = the first different plan.
  - The delta green (`#2f9e44`) failed WCAG AA on striped rows (3.07) → `#1e7a33`.
- Review follow-up fixes: the report asserted the project from the URL instead of
  the selected one (`404 project_mismatch` after a project switch) → asserts
  `#selProject` and the Apply handler waits for an in-flight `init` (a late
  answer for a project the user already left is ignored); the platform breakdown
  now walks the union of both plans; defects only count versions still linked to
  the plan; a plan without executions shows a dedicated warning banner instead of
  a false tie; CSV keeps negative numbers numeric and localizes the "better"
  column (`n/a` for neutral metrics); the same-plan guard is enforced by the BFF.
- Fixture: `tmp/fixtures_1957.php` (project `PC1957`, plans `PC Plan A`/`PC Plan B`,
  builds `PC Build 1/2`, platforms `PC Linux/PC Windows`, project `PC1957 OTHER`,
  user `pcnorights`).
- Suite: `Issue #1957` in `tmp/TLU_Test_Cases.md`.
- Event Viewer: no new Error/Warning row; only the AUDIT row of the deliberate 403
  probe.

## Screenshots
- `docs/screenshots/1957-plan-comparison-en.png` — the report (EN).
- `docs/screenshots/1957-plan-comparison-build-filter.png` — build filter applied.
- `docs/screenshots/1957-plan-comparison-same-plan.png` — same-plan state card.
- `docs/screenshots/1957-plan-comparison-ro.png` — the report (Romanian).
