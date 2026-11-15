# Test Plan Custom Fields Coverage (tplanCfCoverage)

Modernized screen for issue **#1994** in TestLink 2.0.1.

- Screen: `gui/templates/results/tplanCfCoverage.html`
- BFF: `api/tplancfcoverage/index.php`
- Rights: `testplan_metrics` on the owning test project
- API: `GET|HEAD ?action=init[&tproject_id=P]`,
  `GET|HEAD ?action=report[&tproject_id=P]`
- Fixture: `tmp/fixtures_1994.php`
- Test suite: `Issue #1994` in `tmp/TLU_Test_Cases.md`

## Why a new report

1.9.20 exposes plan-design custom-field values only inside one plan
(`tplan_with_cf`, already modernized as `tplanWithCF.html`). Nobody could see,
across the whole project, **which test plans have their plan-design custom
fields filled in**. The Test Plan Custom Fields Coverage report answers that in
one screen: one row per test plan with the number of assigned test cases, how
many of them carry at least one custom-field value, the coverage percentage and
a completeness status.

## Screen

- Toolbar: project context, status filter (All / Incomplete only / Complete
  only / Not filled only), Refresh, Export CSV, Close.
- 6 KPI cards: test plans, assigned test cases, rows with values, coverage %,
  custom fields, filled values.
- Info banner when the project has no plan-design custom fields or no plans.
- DataTable: test plan (deep link into `tplanWithCF.html`), active/open
  badges, assigned, with values, coverage bar, status badge, filled values,
  suites. Sorted worst coverage first.
- CSV export (`;` separator, BOM, formula-injection guard, localized values).
- Localized loading, error, empty and 403 state cards.

## Semantics

- Custom fields in scope are the project-linked, test-case-node custom fields
  with `enable_on_testplan_design = 1`.
- The unit is a `testplan_tcversions` assignment of the plan.
- A row counts as "with values" when at least one plan-design custom-field
  value is non-empty for that assignment.
- `coverage_pct = rows_with_values / assigned * 100` (one decimal).
- Status precedence: `empty` (no assignment) > `not_filled` (assignments but
  no value) > `partial` (< 100%) > `complete` (100%).
- Suites are the distinct test-suite paths of the assigned versions.
- Rows are sorted worst coverage first, then by plan name.

## API contract

| Case | HTTP | Code |
|---|---|---|
| `GET|HEAD` success | 200 | `ok` |
| Unknown action / malformed id | 400 | `invalid_request` |
| Anonymous | 401 | `not_authenticated` |
| Missing `testplan_metrics` | 403 | `no_right` (AUDIT) |
| Non GET/HEAD | 403 | same-origin/CSRF proof |
| Unknown project | 404 | `project_not_found` |

Security: `bffSameOriginGuard()` + `bffEnforceSession()`, rights checked on the
addressed project before it is resolved, every dynamic value is an integer,
denial logged at AUDIT.

## Wiring

- `cfg/reports.cfg.php` — `tplan_cf_coverage`
- `api/aside/index.php` — `link_report_tplans_cf_coverage`
- `api/resultsnav/index.php` — `tplan_cf_coverage`
- `lib/functions/common.php` — `$actions->tplanCfCoverage`
- `gui/templates/i18n/*.json` — `tpcc.*` keys + `footers.tplanCfCoverage`
- `locale/*/strings.txt` — `$TLS_link_report_tplans_cf_coverage` (19 locales)

## Verification

- Browser: snapshot table, status filters (All 3 / Incomplete 2 / Complete 1 /
  Not filled 0), CSV content, Romanian locale, project with no linked custom
  fields, no-right user, ASIDE launcher and deep link into the plan report,
  console clean.
- Machine: 200/400/401/403/404/405 contract, i18n gate 9/9, Event Viewer
  AUDIT-only.
- Screenshots: `docs/screenshots/1994-tplancfcoverage-*.png`.
