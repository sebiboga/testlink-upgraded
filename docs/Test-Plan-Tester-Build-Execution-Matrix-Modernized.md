# Test Plan Tester × Build Execution Matrix (testerBuildMatrix)

Modernized net-new screen for issue **#1997** in TestLink 2.0.1.

- Screen: `gui/templates/results/testerBuildMatrix.html`
- BFF: `api/testerbuildmatrix/index.php`
- Rights: `testplan_metrics` on the owning test project
- API: `GET|HEAD ?action=projects`,
  `GET|HEAD ?action=init&tproject_id=N[&tplan_id=P]`,
  `GET|HEAD ?action=report&tplan_id=N[&tproject_id=P][&build_id=B][&platform_id=PL]`
- Fixture: `tmp/fixtures_1997.php`
- Test suite: `Issue #1997` in `tmp/TLU_Test_Cases.md`
- Screenshots: `docs/screenshots/issue-1997-testerbuildmatrix-{en,build2,empty,ro,norights}.png`.

## Why a new report

TestLink 1.9.20 had `resultsByTesterPerBuild` (one tester's results across
builds) and `testerWorkload` (who is overloaded), but no report that put
**every tester of a plan against every build of the plan** and showed the
execution result in each cell. Managers had to reconstruct "who covered what,
in which build?" by hand from several screens. The Tester × Build Execution
Matrix answers that question with one cross-tab, including an explicit
`(unassigned)` bucket for plan links without an active tester assignment.

## Screen

- Toolbar: project, plan, build and platform pickers, Apply, Refresh, Export
  CSV, Close.
- Report context card (project, plan, active build and platform filters).
- Verdict banner (`no_data` / `not_started` / `in_progress` / `complete`) with
  the plan-wide coverage percentage and executed/assigned counts.
- 12 KPI cards: testers, builds, assignments, plan links, unassigned links,
  executed, passed, failed, blocked, not run, coverage %, pass rate %.
- Matrix: one row per tester (plus `(unassigned)`), one column per build; each
  cell shows coverage %, executed/assigned, a coverage bar and the passed /
  failed / blocked / not-run counters, colour-coded by state. Clicking a cell
  re-scopes the report to that build.
- Per-tester totals and per-build totals tables with localized verdicts.
- CSV export with four sections (summary, per-tester totals, per-build totals,
  matrix cells), `;` separator, UTF-8 BOM and formula-injection guard.
- Localized loading, empty, 400/401/403/404/405 state cards and footer locale
  switcher.

## Semantics

- The unit is an assignment link (`testplan_tcversions`) of the selected plan.
- Active ownership comes from `user_assignments` with `type = 1` and
  `status NOT IN (2, 3)`; `build_id = 0` means the tester covers that link in
  every build, a non-zero `build_id` pins it to that build only.
- Links without an active assignment are collected into the synthetic
  `(unassigned)` row (`user_id = 0`), so no plan link is silently dropped.
- Platform applicability comes from `testplan_tcversions.platform_id`:
  `0` means every platform of the plan, a non-zero value pins one platform.
- Every cell uses the **latest execution** (highest `executions.id`) per
  (build, platform, test-case version); a link with no execution in that build
  counts as not run.
- The build filter narrows both the matrix columns and the counters, and the
  response's `context.build` keeps the context card and CSV header truthful.
- Verdict precedence: `no_data` (no assignments) > `not_started` (no execution)
  > `complete` (no not-run assignment) > `in_progress`.

## API contract

| Case | HTTP | Code |
|---|---|---|
| `GET|HEAD` success | 200 | `ok` |
| Unknown action / missing or invalid `tplan_id` | 400 | `unknown_action` / `invalid_request` |
| No session | 401 | `not_authenticated` |
| No `testplan_metrics` right on the owning project | 403 | `no_right` |
| Unknown plan/project, or `tproject_id` not the plan owner | 404 | `tplan_not_found` / `project_mismatch` |
| POST without same-origin headers | 403 | `cross_origin` |
| `POST` with same-origin headers (GET only) | 405 | `method_not_allowed` |

The plan is resolved first, then `testplan_metrics` is checked on the **owning**
project; the client `tproject_id` is only an assertion. Every denial is written
to the `AUDIT` log.

## Wiring

- `cfg/reports.cfg.php`: `reports_list['tester_build_matrix']` →
  `gui/templates/results/testerBuildMatrix.html`.
- `api/aside/index.php`: `link_report_tester_build_matrix` branch builds the
  contexted href for the Reports menu.
- `lib/functions/common.php`: `$actions->testerBuildMatrix`.
- i18n: `tbm.*` (91 keys) + `footers.testerBuildMatrix` in all 10 client
  bundles; `$TLS_link_report_tester_build_matrix` in all 19 server locales.
  Gate `bash ai/verify_i18n_coverage.sh` = 9/9 PASS.

## Verification

- Browser: default matrix (2 testers + unassigned × 3 builds, 14 assignments,
  57.1% coverage, 62.5% pass rate), any-build vs build-scoped assignments,
  latest-execution re-run, explicit unassigned bucket, `platform_id=0`
  applicability, build filter, combined build × platform filter, cell-click
  re-scoping, empty plan, no-right user, anonymous session, EN/RO locale,
  CSV content, Reports ASIDE launcher; console clean.
- Machine: 200/400/401/403/404/405 contract, test-suite gate
  `TLU_REQUIRE_SUITE="Issue #1997"` PASS, i18n gate 9/9, Event Viewer 0 new
  Error/Warning entries (only AUDIT rows).
- Bugs found and fixed during the pass: missing `context.build` in the BFF
  report payload (context card/CSV showed "All builds" for a filtered report),
  copied suite wording in the RO/JA/RU/ZH `tbm.*` bundles, missing `id`/`name`
  on the two DataTables search inputs, untranslated `__UNASSIGNED__` in CSV,
  and the per-build CSV header using the tester label.
- Screenshots are listed above; the wiki mirror embeds the same five captures.
