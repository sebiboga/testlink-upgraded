# Test Plan Step Failure Hotspots (stepFailureHotspots)

Modernized screen for issue [#2042](https://github.com/sebiboga/testlink-upgraded/issues/2042) in TestLink 2.0.1.

- Screen: `gui/templates/results/stepFailureHotspots.html`
- BFF: `api/stepfailurehotspots/index.php`
- Rights: `testplan_metrics` on the owning test project (read-only)
- API: `GET|HEAD ?action=init&tplan_id=N[&tproject_id=P]`,
  `GET|HEAD ?action=report&tplan_id=N[&tproject_id=P][&build_id=B][&platform_id=PL][&scope=S][&min_failures=M]`,
  `GET|HEAD ?action=detail&tplan_id=N&step_id=S[...]`,
  `GET|HEAD ?action=csv&tplan_id=N[...]`
- Fixture: `tmp/fixtures_2042.php`
- Test suite: `Issue #2042` in `tmp/TLU_Test_Cases.md`

## Why a new report

TestLink 1.9.20 reported failures at test-case or build level. A case with one repeatedly failing design step looked the same as one with an isolated failure at a different step, so rework could not be targeted. This 2.0.1 report joins active design steps with per-step execution evidence in the selected plan and ranks the steps by a failure score, exposing the exact actions and expected results that need attention.

## Screen

- Toolbar: test plan / project context, Build, Platform, Scope and Min score pickers, Refresh, Export CSV, Close.
- 12 KPI cards covering design steps, step results, passed/failed/blocked/without-evidence counts, failure rate, critical/high/blocked steps and affected cases/suites.
- Plan verdict banner (`no_data` / `clear` / `watch` / `at_risk`).
- Searchable/sortable DataTable with score, verdict, case, external ID, suite path, step, actions, results, failed, blocked, passed, failure rate, latest result, latest build, platform and tester.
- A per-step detail modal with step metadata, expected result and chronological execution evidence (status, timestamp, build, platform, tester and notes).
- CSV export with UTF-8 BOM, `;` separator, RFC-style quoting and formula-injection guard.
- Localized 400/401/403/404/405 state cards and an EN/RO-aware `TLi18n` locale switcher with localized DataTables sort labels.

## Semantics

- The unit is one active design step of a test-case version assigned to the selected test plan. `tproject_id`, Build and Platform narrow the scope; `tptv.platform_id=0` assignments are included in named-platform scopes and `platform_id=0` means all platforms.
- Statuses in `execution_tcsteps.status` are normalized as `p` → passed, `f` → failed, `b` → blocked and anything else → not run. Evidence is read from executions in the plan, ordered by `execution_ts`, execution ID and evidence ID; the last row becomes the latest result.
- Score is `2 * failed + blocked`. Failure rate is `failed / evidence * 100`, rounded to one decimal.
- Per-step verdicts: `critical` when `failed >= 3` or failure rate is at least 50% with at least one failure, `high` for any other failure, `blocked` for blocked-only evidence, `clear` for passed-only evidence and `no_data` without evidence.
- Scope values: `all` (ranked steps), `failed`, `blocked` and `failing` (score > 0). `min_failures` filters by score and is capped at 100. KPI cards always describe the whole scope, not just filtered rows; CSV uses filtered rows.
- Plan verdict is `no_data` without evidence, `at_risk` when any step is critical, `watch` when any high or blocked step exists, otherwise `clear`.
- `tproject_id` is asserted against the plan's owning project only after the plan is resolved and `testplan_metrics` is checked on that owner, so foreign-project probes cannot enumerate plans. Denials are written to AUDIT.

## API contract

| Case | HTTP | Code |
| --- | --- | --- |
| `GET\|HEAD` success | 200 | `ok` |
| Unknown action, invalid plan/build/platform/scope/threshold or missing detail step | 400 | `invalid_request` |
| Anonymous / expired session | 401 | `not_authenticated` / `session_expired` |
| Missing `testplan_metrics` right | 403 | `no_right` (AUDIT) |
| Unknown plan, wrong project, detail step outside plan | 404 | `tplan_not_found` / `project_mismatch` / `step_not_found` |
| Non GET/HEAD | 405 | `method_not_allowed` (`Allow: GET, HEAD`) |
| POST without `Origin`/`Referer` | 403 | same-origin guard denial |

Security: `bffSameOriginGuard()` + `bffEnforceSession()`; denials are logged at AUDIT; responses carry `nosniff` and `Cache-Control: no-store`. CSV cells starting with `=`, `+`, `-` or `@` are prefixed with an apostrophe.

## Wiring

- `cfg/reports.cfg.php` — `step_failure_hotspots`
- `api/aside/index.php` — Reports entry for `stepFailureHotspots.html`
- `api/resultsnav/index.php` — `step_failure_hotspots` map row
- `lib/functions/common.php` — `$actions->stepFailureHotspots`
- `gui/templates/i18n/*.json` — `sfh.*` + footer keys in all 10 bundles (`ai/verify_i18n_coverage.sh`: 9/9 PASS, 12837 keys)
- `locale/*/strings.txt` — `$TLS_link_report_step_failure_hotspots` (19 locales)

## Verification

- Browser: default report (7 design steps / 15 step results / 8 passed / 4 failed / 3 blocked / 26.7% failure rate / 2 critical / 1 high / 3 blocked / 2 affected cases / 1 suite), detail modal, build/scope/threshold filters, DataTables search, Refresh, CSV download, empty/not-found/wrong-project cards, no-right card, anonymous redirect, Romanian locale, localized DataTables sort aria labels and ASIDE launcher.
- Machine: full 200/400/401/403/404/405 contract, `TLU_REQUIRE_SUITE="Issue #2042"` gate PASS (60 cases, no suite lost), i18n gate 9/9, PHP/JS/JSON syntax, `git diff --check`, Event Viewer with no new Error/Warning after the fixes.
- Bugs found and fixed: #2043 (English DataTables sort aria labels in non-English locales).

## Files

- `api/stepfailurehotspots/index.php`
- `gui/templates/results/stepFailureHotspots.html`
- `gui/templates/i18n/*.json`, `locale/*/strings.txt`
- `cfg/reports.cfg.php`, `api/aside/index.php`, `api/resultsnav/index.php`, `lib/functions/common.php`
- `tmp/fixtures_2042.php`, `tmp/TLU_Test_Cases.md`, `CHANGELOG`
