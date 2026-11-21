# Test Plan Step Execution Results (stepExecutionResults)

Modernized screen for issue [#2024](https://github.com/sebiboga/testlink-upgraded/issues/2024) in TestLink 2.0.1.

- Screen: `gui/templates/results/stepExecutionResults.html`
- BFF: `api/stepExecutionResults/index.php`
- Rights: `testplan_metrics` on the owning test project (read-only)
- API: `GET|HEAD ?action=init&tplan_id=N[&tproject_id=P]`,
  `GET|HEAD ?action=report&tplan_id=N[&tproject_id=P][&build_id=B][&platform_id=PL][&status=S][&scope=S]`,
  `GET|HEAD ?action=detail&tplan_id=N&step_id=S`,
  `GET|HEAD ?action=csv&tplan_id=N[...]`
- Fixture: `tmp/fixtures_2024.php`
- Test suite: `Issue #2024` in `tmp/TLU_Test_Cases.md`

## Why a new report

TestLink 1.9.20 reported execution results at case level. The per-step outcome
stored in `execution_tcsteps` was only visible one execution at a time, so a
manager could not see which design steps had actually passed, failed or been
blocked across the builds and platforms of a plan. This 2.0.1 report joins the
plan's assigned case versions, their design steps and every recorded step
result into a single read-only evidence view.

## Screen

- Toolbar: test plan / project context, build, platform, latest result and
  scope pickers, Refresh, Export CSV, Close.
- 11 KPI cards: assigned versions, design steps, step results, passed, failed,
  blocked, steps without evidence, failure rate, failing steps, mixed-result
  steps and cases with failures.
- Plan verdict banner (`no_data` / `clear` / `watch` / `at_risk`).
- 16-column DataTable sorted by verdict, failed count, case and step, showing
  the design step, its action/expected result, evidence count, pass/fail/block
  counts, latest result, build, platform and execution timestamp.
- Clicking a design step opens a step-evidence history modal with every run's
  timestamp, result, build, platform, tester and notes.
- CSV export with UTF-8 BOM, `;` separator, RFC-style field quoting and
  formula-injection guard; localized
  400/401/403/404/405 state cards and an EN/RO-aware TLi18n locale switcher.

## Semantics

- The unit is a design step of a case version assigned to the plan. A step with
  no `execution_tcsteps` row is still listed with `not_run` and counted in
  "steps without evidence".
- `execution_tcsteps.status` is a one-letter code (`p`, `f`, `b`, `n`) mapped to
  `passed`, `failed`, `blocked` and `not_run`. The step verdict is `failing`
  when any row failed, `blocked` when any row was blocked, `flaky` when only
  some rows passed, `passing` when all rows passed and `not_run` otherwise.
- The latest result is chosen by execution timestamp, with the execution id as
  a deterministic tie-breaker. The execution header status never overrides the
  step rows: a step that failed in an earlier run stays `failing` even if the
  case-level execution later passed.
- Build and platform filters narrow the evidence rows and the KPIs; the latest
  result and scope filters narrow the displayed design-step rows. A step with
  no evidence matches the "all design steps" scope only.
- Plan verdict is `no_data` without evidence, `at_risk` with failing steps,
  `watch` with blocked or mixed steps and `clear` otherwise. Failure rate is
  failed step results divided by all step results in scope.
- `platform_id=0` assignments are included for every named platform, matching
  the other modernized reports.

## API contract

| Case | HTTP | Code |
| --- | --- | --- |
| `GET\|HEAD` success | 200 | `ok` |
| Unknown action, invalid filter, bad `step_id` | 400 | `invalid_request` |
| Anonymous / expired session | 401 | `not_authenticated` |
| Missing `testplan_metrics` right | 403 | `no_right` (AUDIT) |
| Unknown plan / step, client project mismatch | 404 | `tplan_not_found` / `step_not_found` / `project_mismatch` |
| Non GET/HEAD | 405 | `method_not_allowed` (`Allow: GET, HEAD`) |

Security: `bffSameOriginGuard()` + `bffEnforceSession()`; the plan is resolved
first and the right is checked on its owning project before the client
`tproject_id` assertion is honoured. Denials are logged at AUDIT; responses
carry `nosniff` and `Cache-Control: no-store`.

## Wiring

- `cfg/reports.cfg.php` — `step_execution_results`
- `api/aside/index.php` — Reports entry for `stepExecutionResults.html`
- `api/resultsnav/index.php` — `step_execution_results` map row
- `lib/functions/common.php` — `$actions->stepExecutionResults`
- `gui/templates/i18n/*.json` — `ser.*` + footer keys in all 10 bundles
  (`ai/verify_i18n_coverage.sh`: 9/9 PASS)
- `locale/*/strings.txt` — `$TLS_link_report_step_execution_results` (19 locales)

## Verification

- Browser: default report (4 versions / 8 steps / 11 results / 6 passed /
  2 failed / 2 blocked / 18.2% failure rate / 2 failing steps /
  2 cases with failures), step-evidence modal, build/latest-result/scope
  filters, DataTables search and sorting, Refresh, Blob CSV download, empty
  plan, no-right 403 state card, anonymous redirect, localized error cards,
  Romanian locale, ASIDE shell link and clean console.
- Machine: full 200/400/401/403/404/405 contract,
  `TLU_REQUIRE_SUITE="Issue #2024"` gate PASS (66 cases, no suite lost),
  i18n gate 9/9, Event Viewer AUDIT-only (0 Error/Warning).
- Bugs found and fixed: #2025 (step verdict computed from the execution header,
  wrong detail row, unresolved tester and timestamp ties), #2026 (badges showed
  raw underscore i18n keys), #2027 (Export CSV navigated to the JSON envelope
  instead of downloading a file), #2028 (evidence duplicated for multi-platform
  assignments), #2029 (CSV fields not quoted) and #2030 (undefined DataTables
  `infoEmpty` key).

## Files

- `api/stepExecutionResults/index.php`
- `gui/templates/results/stepExecutionResults.html`
- `gui/templates/i18n/*.json`, `locale/*/strings.txt`
- `cfg/reports.cfg.php`, `api/aside/index.php`, `api/resultsnav/index.php`,
  `lib/functions/common.php`
- `tmp/fixtures_2024.php`, `tmp/TLU_Test_Cases.md`, `CHANGELOG`
