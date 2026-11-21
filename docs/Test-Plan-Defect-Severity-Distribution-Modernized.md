# Test Plan Defect Severity Distribution (defectSeverity)

Modernized screen for issue [#2021](https://github.com/sebiboga/testlink-upgraded/issues/2021) in TestLink 2.0.1.

- Screen: `gui/templates/results/defectSeverity.html`
- BFF: `api/defectseverity/index.php`
- Rights: `testplan_metrics` to read, `mgt_modify_tc` to classify, on the owning test project
- API: `GET|HEAD ?action=init&tplan_id=N[&tproject_id=P]`,
  `GET|HEAD ?action=report&tplan_id=N[&tproject_id=P][&build_id=B][&platform_id=PL][&severity=S][&status=S]`,
  `GET|HEAD ?action=detail&tplan_id=N&bug_id=BUG`,
  `GET|HEAD ?action=csv&tplan_id=N[...]`,
  `POST ?action=classify` (JSON) with `tplan_id`, `tproject_id`, `bug_id`,
  `severity`, `status`, `owner`, `component`, `notes`
- Fixture: `tmp/fixtures_2021.php`
- Test suite: `Issue #2021` in `tmp/TLU_Test_Cases.md`

## Why a new report

TestLink 1.9.20 stored bug ids on executions and only showed them one execution
at a time. It never answered "which defect severities are actually affecting this
plan, and who is triaging them?". This 2.0.1 report joins `execution_bugs` with
the plan's executions, infers a severity from the defect reference when no
explicit classification exists, and lets a manager set project-wide severity,
status, owner, component and notes without leaving the report.

## Screen

- Toolbar: test plan / project context, build, platform, severity and status
  pickers, Refresh, Export CSV, Close.
- 12 KPI cards: distinct defects, execution links, critical, high, medium, low,
  unclassified, blockers, open critical/high, critical+high share, affected cases
  and failed runs linked.
- Plan verdict banner (`no_data` / `at_risk` / `watch` / `clear`) and a severity
  distribution bar with legend.
- 13-column defect DataTable sorted by verdict then execution links, showing the
  evidence footprint of each defect (links, failed runs, cases, suites, builds,
  last activity) and a per-row verdict (`blocker`, `high`, `watch`, `low`,
  `resolved`, `unclassified`).
- Clicking a defect opens its execution-evidence modal; clicking Classify opens a
  modal that writes the project-wide classification row through the BFF.
- CSV export with UTF-8 BOM, `;` separator and formula-injection guard; localized
  400/401/403/404/405 state cards and an EN/RO-aware TLi18n locale switcher.

## Semantics

- The unit is a distinct defect id linked to any execution of the plan within the
  current build/platform scope. A defect linked to several executions appears
  once in the report but keeps the full list of runs in its detail modal.
- `platform_id=0` execution links apply to every platform and are therefore
  included when a named platform is selected.
- Severity comes from `tc_defect_severity` when a row exists; otherwise it is
  inferred from tokens in the defect id (`blocker`/`critical`/`crit`/`s1`/`p0`,
  `high`/`major`/`maj`/`s2`/`p1`, `medium`/`med`/`normal`/`s3`/`p2`,
  `low`/`minor`/`min`/`s4`/`p3`/`trivial`), falling back to `unclassified`.
- Status comes from the classification row or defaults to `open`. Row verdicts
  are `blocker` (critical with at least 3 links in scope), `high`, `watch`, `low`,
  `resolved` (fixed/closed/wontfix) and `unclassified`.
- The table's affected-cases and affected-suites figures are scoped to the
  current filters and deduplicated globally across all displayed defects.

## API contract

| Case | HTTP | Code |
| --- | --- | --- |
| `GET\|HEAD` success | 200 | `ok` |
| Unknown action, invalid filter, missing `bug_id`, bad classification | 400 | `invalid_request` |
| Anonymous / expired session | 401 | `not_authenticated` |
| Missing read right / missing `mgt_modify_tc` on classify | 403 | `no_right` (AUDIT) |
| Unknown plan, client project mismatch, defect outside report | 404 | `tplan_not_found` / `project_mismatch` / `defect_not_found` |
| Non GET/HEAD on a read action | 405 | `method_not_allowed` (`Allow: GET, HEAD, POST`) |

Security: `bffSameOriginGuard()` + `bffEnforceSession()`; the plan is resolved
first and the right is checked on its owning project before the client
`tproject_id` assertion is honoured. Denials are logged at AUDIT; responses carry
`nosniff` and `Cache-Control: no-store`.

## Wiring

- `cfg/reports.cfg.php` — `defect_severity`
- `api/aside/index.php` — `link_report_defect_severity`
- `lib/functions/common.php` — `$actions->defectSeverity`
- `install/sql/mysql/testlink_create_tables.sql` — `tc_defect_severity` DDL
- `lib/functions/object.class.php` — table registered for `getDBTables()`
- `gui/templates/i18n/*.json` — `dsv.*` + footer keys in all 10 bundles
  (`ai/verify_i18n_coverage.sh`: 9/9 PASS)
- `locale/*/strings.txt` — `$TLS_link_report_defect_severity` (19 locales)

## Verification

- Browser: default report (5 defects / 8 links / 1 critical / 2 high / 1 low /
  1 unclassified / 1 blocker / 60% critical+high share), detail modal, classify
  modal save and refresh, build and platform filters (including all-platform
  links), Refresh, CSV download, empty plan, no-right 403 state card, anonymous
  redirect, RO locale and clean console.
- Machine: full 200/400/401/403/404/405 contract, `TLU_REQUIRE_SUITE="Issue #2021"`
  gate PASS (no suite lost), i18n gate 9/9, Event Viewer AUDIT-only (0
  Error/Warning).
- Bugs found and fixed: #2022 (detail modal returned the wrong defect because
  the report loop reused the request `bug_id`) and #2023 (named-platform filter
  dropped executions with `platform_id=0`). The browser pass also found missing
  badge/state i18n keys and untranslated Romanian labels, both corrected.

## Files

- `api/defectseverity/index.php`
- `gui/templates/results/defectSeverity.html`
- `gui/templates/i18n/*.json`, `locale/*/strings.txt`
- `cfg/reports.cfg.php`, `api/aside/index.php`, `lib/functions/common.php`
- `install/sql/mysql/testlink_create_tables.sql`, `lib/functions/object.class.php`
- `tmp/fixtures_2021.php`, `tmp/TLU_Test_Cases.md`, `CHANGELOG`
