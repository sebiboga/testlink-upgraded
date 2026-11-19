# Test Plan Execution Timeline (executionTimeline)

Refs #2005. TestLink 1.9.20/2.0.1 reports the current status of a test plan, but
never presents execution activity as a chronological timeline. This net-new
Dashio report answers "what happened in this plan during the last N days?" with
a daily table, KPI cards, scoped filters and CSV export.

## Scope and filters

- Test plan is required; the test project may be supplied as a context assertion.
- Window (days): 1–365, default 30.
- Build: active builds of the owning project only.
- Platform: platforms linked to the report scope.
- Tester: users with executions in the plan.
- Result: any result status configured for the TestLink instance.
- Apply re-runs the report; Refresh reloads the last valid request; Export CSV is
  disabled on empty/error states; Close returns to the home page.

## Report content

- 13 KPIs: executions, assigned links, executed links, remaining links, coverage,
  passed, failed, blocked, pass rate, active days, average per active day, first
  execution and last execution.
- Verdict banner handles single/multiple executions, no data, no assignments and
  unavailable/denied states.
- Timeline DataTable: date, executions, passed, failed, blocked, other statuses,
  distinct test-case versions, distinct testers, newly executed links and
  cumulative coverage.
- Context panel: plan, project, scope filter values, first/last activity and the
  report window.
- CSV: UTF-8 BOM, semicolon separators, localized headers and a formula-injection
  guard.

## Security and error states

- Session authentication plus same-origin guard; anonymous requests get 401 and
  the screen redirects to the login page with an expired-session notice.
- The plan is resolved first; `testplan_metrics` is checked on the owning project
  before the client-supplied project assertion, so mismatches are opaque 404s.
- Permission denials are logged at AUDIT level and return a localized no-right
  card without data.
- Localized cards cover invalid request, missing plan, not found, project mismatch,
  no rights, unauthenticated and unsupported method states.

## Implementation notes

- HTML: `gui/templates/results/executionTimeline.html`
- BFF: `api/executiontimeline/index.php`
  - `GET|HEAD ?action=init&tplan_id=N[&tproject_id=P]`
  - `GET|HEAD ?action=report&tplan_id=N[&tproject_id=P][&days=D][&build_id=B][&platform_id=PL][&tester_id=U][&status=S]`
- Wiring: `cfg/reports.cfg.php`, `api/aside/index.php`,
  `api/resultsnav/index.php` and `$actions->executionTimeline`.
- i18n: `exlt.*` and `footers.executionTimeline` in all 10 client bundles;
  `$TLS_link_report_execution_timeline` in all 19 server locales.

## Verification

Suite `Issue #2005` (`tmp/TLU_Test_Cases.md`) records 2005.1–2005.23, all PASS:
default report, ASIDE/report launcher, each filter, Refresh/Apply/Close, CSV
bytes, window bounds, empty plan, invalid request, unknown plan, project
mismatch, invalid scoped filters, unsupported action/method, anonymous and
no-right access, console checks and Event Viewer checks. Bug #2006 (singular
banner for one execution) and two aggregation defects (future-dated executions
inflating coverage, mixed-status days undercounting distinct versions/testers)
were found and fixed during testing.
