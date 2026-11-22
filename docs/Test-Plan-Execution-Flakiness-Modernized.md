# Test Plan Execution Flakiness (executionFlakiness)

Modernized screen for issue [#2036](https://github.com/sebiboga/testlink-upgraded/issues/2036) in TestLink 2.0.1.

- Screen: `gui/templates/results/executionFlakiness.html`
- BFF: `api/executionflakiness/index.php`
- Rights: `testplan_metrics` on the owning test project (read-only)
- API: `GET|HEAD ?action=init&tplan_id=N[&tproject_id=P]`,
  `GET|HEAD ?action=report&tplan_id=N[&tproject_id=P][&build_id=B][&platform_id=PL][&verdict=V]`,
  `GET|HEAD ?action=detail&tplan_id=N&tcversion_id=V[...]`,
  `GET|HEAD ?action=csv&tplan_id=N[...]`
- Fixture: `tmp/fixtures_2036.php`
- Test suite: `Issue #2036` in `tmp/TLU_Test_Cases.md`

## Why a new report

TestLink 1.9.20 exposed only the latest status of a test case. A case that failed once and then passed looked identical to one that had always passed, so repeated status changes, recovery and regression were invisible. This 2.0.1 report reads the execution history of a test plan and surfaces the instability pattern: status flips, fail-to-pass recoveries and pass-to-fail regressions for every assigned test-case version.

## Screen

- Toolbar: test plan / project context, Build, Platform and Verdict pickers, Refresh, Export CSV, Close.
- 12 KPI cards covering assignment, execution, flip, verdict, recovery/regression and pass-rate totals.
- Plan verdict banner (`no_data` / `stable` / `watch` / `at_risk`).
- Searchable/sortable DataTable with test case, suite path, runs, flips, flip rate, recovered, regressed, first/last result, pass rate, verdict and last execution.
- A per-version detail modal with chronological execution evidence (status, timestamp, build, platform and notes) and suite/build/platform metadata.
- CSV export with UTF-8 BOM, `;` separator, RFC-style quoting and formula-injection guard.
- Localized 400/401/403/404/405 state cards and an EN/RO-aware `TLi18n` locale switcher.

## Semantics

- The unit is one assigned `testplan_tcversions` link (a test-case VERSION on a platform). Executions are read inside the selected plan and optionally narrowed by build and/or platform; `platform_id=0` assignments are included in a named-platform scope.
- Statuses are normalized as `p` → passed, `f` → failed, `b` → blocked and anything else → not run. Rows are processed in `execution_ts, id` order, the same order used by the detail modal.
- A flip is a change of normalized status between consecutive executions. A recovery is failed → passed and a regression is passed → failed. Blocked and not-run executions count as runs and can produce a flip, but never create a recovery/regression pair.
- Per-case verdicts: `never_run` (0 runs), `limited` (1 run), `flaky` (at least 2 flips or a flip rate of at least 50% of the gaps), `watch` (one flip or any recovery/regression), otherwise `stable`.
- Plan verdict is `no_data` with no executions, `at_risk` when any case is flaky, `watch` when any case is watch, otherwise `stable`. The verdict filter narrows only the rows; KPI cards always describe the whole scope.
- `tproject_id` is asserted against the plan's owning project only after the plan is resolved and `testplan_metrics` is checked on that owner, so foreign-project probes cannot enumerate plans. Denials are written to AUDIT.

## API contract

| Case | HTTP | Code |
| --- | --- | --- |
| `GET\|HEAD` success | 200 | `ok` |
| Unknown action, invalid plan/build/platform/verdict or missing detail id | 400 | `invalid_request` |
| Anonymous / expired session | 401 | `not_authenticated` / `session_expired` |
| Missing `testplan_metrics` right | 403 | `no_right` (AUDIT) |
| Unknown plan, wrong project, detail case outside report | 404 | `tplan_not_found` / `project_mismatch` / `test_case_not_found` |
| Non GET/HEAD | 405 | `method_not_allowed` (`Allow: GET, HEAD`) |
| POST without `Origin`/`Referer` | 403 | same-origin guard denial |

Security: `bffSameOriginGuard()` + `bffEnforceSession()`; denials are logged at AUDIT; responses carry `nosniff` and `Cache-Control: no-store`. CSV cells starting with `=`, `+`, `-` or `@` are prefixed with an apostrophe.

## Wiring

- `cfg/reports.cfg.php` — `execution_flakiness`
- `api/aside/index.php` — Reports entry for `executionFlakiness.html`
- `api/resultsnav/index.php` — `execution_flakiness` map row
- `lib/functions/common.php` — `$actions->executionFlakiness`
- `gui/templates/i18n/*.json` — `flk.*` + footer keys in all 10 bundles (`ai/verify_i18n_coverage.sh`: 9/9 PASS, 12752 keys)
- `locale/*/strings.txt` — `$TLS_link_report_execution_flakiness` (19 locales)

## Verification

- Browser: default report (6 assigned / 5 executed / 13 executions / 5 flips / 2 flaky / 1 watch / 3 recoveries / 2 regressions / 69.2% pass rate), detail modal, build/platform/verdict filters, DataTables search/sort, Refresh, CSV download, invalid/empty plan cards, no-right card, anonymous redirect, Romanian locale, localized DataTables sort aria labels and ASIDE launcher.
- Machine: full 200/400/401/403/404/405 contract, anonymous/same-origin/method proofs, `TLU_REQUIRE_SUITE="Issue #2036"` gate PASS (63 cases, no suite lost), i18n gate 9/9, PHP/JS/JSON syntax, `git diff --check`, Event Viewer with no new Error/Warning after the fixes.
- Bugs found and fixed: #2037 (nonexistent `executions.execution_number` column), #2038 (raw `flk.` badges), #2039 (English DataTables sort aria labels), #2040 (`never_run_cases` undercount and stale CSV responses), #2041 (untranslated server menu label).

## Files

- `api/executionflakiness/index.php`
- `gui/templates/results/executionFlakiness.html`
- `gui/templates/i18n/*.json`, `locale/*/strings.txt`
- `cfg/reports.cfg.php`, `api/aside/index.php`, `api/resultsnav/index.php`, `lib/functions/common.php`
- `tmp/fixtures_2036.php`, `tmp/TLU_Test_Cases.md`, `CHANGELOG`
