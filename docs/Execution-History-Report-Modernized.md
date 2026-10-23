# Test Plan Execution History (Modernized) — Issue #1961

The modern Dashio page **`gui/templates/results/execHistoryReport.html`**, backed by the read-only BFF **`api/exechistoryreport/index.php`**, shows the history of executions for a test plan in chronological order with filtering by build, platform, status and tester, KPIs, and per-execution details including linked defects.

## Layout
- **Header** (teal Dashio) with the report title and the locale switcher in the footer.
- **Toolbar**: Refresh, **Export CSV**, **Build**, **Platform**, **Status**, **Tester** selects, **Apply** and **Close**.
- **Report context** panel: test project, test plan.
- **KPI cards**: Executions, Test cases, Passed, Failed, Blocked, Other, Testers, Builds, Defects.
- **Defects/Activity banner**: summary of distinct defects seen and last activity.
- **DataTable** (12 columns, searchable, sortable): Date, Test case, ID, Suite, Version, Status, Build, Platform, Tester, Duration (s), Defects, Details.
- **Detail modal**: execution meta (date/status/build/platform/tester/duration), test case/version/suite path, notes (plain text), and linked bugs with links.
- **State cards**: Invalid request / Not found / Access denied / Server error with the machine code of the BFF.

## Data model (BFF `api/exechistoryreport/index.php`)
- **Routes**: `GET|HEAD ?action=init&tproject_id=N&tplan_id=M` / `?action=report&tproject_id=N&tplan_id=M[&build_id=B][&platform_id=PL][&status=S][&tester_id=T]` / `?action=detail&tproject_id=N&tplan_id=M&execution_id=E`. No write verb.
- **Rights**: `testplan_metrics` on the **owning** project of the plan; the caller's `tproject_id` is asserted only (project mismatch → 404). Unauthenticated → 401, no rights → 403.
- **Error contract**: `400 invalid_request` / `invalid_parameter` (bad filter values), `401 not_authenticated` / `session_expired`, `403 no_right`, `404 tplan_not_found` / `project_mismatch`, `405 method_not_allowed`.
- **Suite path**: recursive walk **up** the `nodes_hierarchy` tree from the **test-case** node to build the suite path.
- **External id**: `testprojects.prefix` + `tcversions.tc_external_id` rendered as `PREFIX-<id>`.
- **Defects**: distinct `execution_bugs.bug_id` values for the execution.
- **Rows capped**: 5000 rows; truncated flag in summary when exceeded.
- **Duration**: seconds (rounded to 2 decimals).

## Wiring
- `cfg/reports.cfg.php` — `$tlCfg->reports_list['exec_history']` with title `link_report_exec_history` and URL `gui/templates/results/execHistoryReport.html`.
- `api/aside/index.php` — ASIDE entry in the Reports section for `link_report_exec_history`.
- `lib/functions/common.php` — `$actions->execHistoryReport`.
- Client i18n `ehr.*` + `footers.execHistoryReport` (76 keys) in all 10 bundles; server `$TLS_link_report_exec_history` in all 19 locales.

## Bugs found and fixed while testing
1. **BFF SQL errors** — used `$db->escape()` (does not exist) → replaced with `$db->prepare_string()`.
2. **Detail missing data** — detail query missed `e.tester_id` and `e.tcversion_id` needed by UI/logic.
3. **Durations rounded incorrectly** — used `intval()` instead of `round((float)$v, 2)`.

## Screenshots
- `docs/screenshots/1961-exechistory-normal.png` — the report (EN).
- `docs/screenshots/1961-exechistory-detail.png` — execution detail modal.
