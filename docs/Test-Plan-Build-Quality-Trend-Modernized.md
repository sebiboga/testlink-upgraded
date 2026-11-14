# Test Plan Build Quality Trend (Modernized) — Issue #1991

The modern Dashio page **`gui/templates/results/buildTrend.html`**, backed by the
BFF **`api/buildtrend/index.php`**, is a **net-new 2.0.1 report** answering
"is this test plan getting better or worse build after build?". TestLink 1.9.20
shows a per-build snapshot (Results by Build) and 2.0.1 adds a pairwise diff
(Build Comparison), but neither shows the **chronological trend** of the plan's
quality across every build.

## Layout
- **Header** (teal Dashio) with the report title, subtitle and the locale switcher
  in the footer.
- **Toolbar**: Refresh, Export CSV, Platform, Apply, Close.
- **Plan banner**: plan verdict text plus a trend pill (`improving` / `declining` /
  `stable` / `not_enough_data`) and the total pass-rate delta in percentage points.
- **Report context** card: test project, test plan, platform.
- **11 KPI cards**: builds, builds with activity, assigned versions, executed
  (+%), passed, failed, blocked, not run, pass rate, linked defects, trend.
- **Per-build DataTable** (16 columns): build, state (open/closed), assigned,
  executed, passed, failed, blocked, not run, coverage %, pass rate %, Δ pass
  rate, executions, testers, defects, last execution, verdict.
- **CSV export**: UTF-8 BOM, `;` separator, formula-injection guard.

## Semantics (computed by the BFF, the client only renders it)
- The universe is the **builds of the owning test project** (`builds.testproject_id`),
  ordered chronologically by `creation_ts, id`.
- **Assigned** is the plan's assigned-version count (`testplan_tcversions`), the
  same denominator for every build.
- **Latest execution wins** per (tcversion_id, build_id, platform_id) using
  `MAX(executions.id)`; the optional platform filter narrows both the executions
  and the universe.
- `coverage` = executed / assigned; `pass_rate` = passed / executed.
- **Per-build verdict** compares pass rate and coverage with the **previous build
  that had activity** (±1.0 pp threshold): `baseline` (first active build),
  `improved`, `stable`, `regressed`, or `na` for a build without activity.
- **Plan trend** is the delta between the first and the last active build:
  `improving` (> +1.0 pp), `declining` (< -1.0 pp), `stable`, or
  `not_enough_data` (< 2 active builds).
- **Plan verdict**: `empty` (no assigned versions), `ready` (coverage and pass
  rate ≥ 80%), `watch`, `at_risk` otherwise.
- Defects are distinct `execution_bugs.bug_id` linked to the executions of the
  build.

## Filters
- `tproject_id`, `tplan_id` — scope (the plan must belong to the project).
- `platform_id` — restricts assignments and executions to that platform.

## Data model (BFF `api/buildtrend/index.php`)
- **Routes**: `GET|HEAD ?action=init&tplan_id=N[&tproject_id=P]` (context +
  platform filter) and `?action=report&tplan_id=N[&tproject_id=P][&platform_id=PL]`
  (summary + chronological rows).
- **Rights**: `testplan_metrics` on the owning project, checked **before** the
  plan is resolved (no existence oracle); denials are logged at AUDIT.
- **Error contract**: `400 invalid_request|project_mismatch`,
  `401 not_authenticated`, `403 no_right`, `404 tplan_not_found`,
  `405 method_not_allowed`, plus the same-origin CSRF guard on non-GET verbs.

## Wiring
- `cfg/reports.cfg.php`: `build_trend` → `gui/templates/results/buildTrend.html`.
- `api/aside/index.php`: Reports entry `link_report_build_trend`.
- `api/resultsnav/index.php`: `build_trend` legacy→modern map.
- `lib/functions/common.php`: `$actions->buildTrend`.

## i18n
All labels come from `TLi18n` keys under `btr.*` (70) and `footers.buildTrend` in
all 10 client bundles; the server menu entry uses
`$TLS_link_report_build_trend` in all 19 locales. `ai/verify_i18n_coverage.sh`
passes 9/9.

## Verification
Browser-verified EN/RO: 4 builds (Build 1.0 baseline 75%/33.3% → 1.1 improved
+33.4 pp → 1.2 improved +8.3 pp → 2.0 improved +25 pp), Windows platform filter
(`at_risk`, 25% coverage), empty plan (`empty`, neutral banner), CSV export,
error contract, no console errors, Event Viewer 0 new Error/Warning. Suite
`Issue #1991` appended to `tmp/TLU_Test_Cases.md`.
