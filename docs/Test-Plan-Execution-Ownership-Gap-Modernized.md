# Test Plan Execution Ownership Gap — Modernized (2.0.1)

Issue: #2068. Screen: `gui/templates/results/executionOwnership.html`. BFF: `api/executionownership/index.php`.

This net-new read-only Report answers a question 1.9.20 could not answer without opening executions one by one: which active test-plan assignments were actually executed by someone other than the assigned tester, which executions have no active assignee, and which assigned tests were never executed.

## Behavior

- Active assignments are `user_assignments` with `type=1`, positive `user_id`, `status` not in `{2,3}`, joined by `feature_id` to `testplan_tcversions.id`.
- Build and platform filters are re-scoping filters: `0` means all; a non-zero value must belong to the plan/project.
- The latest execution is chosen per test-case version/platform by timestamp then id, so re-runs do not duplicate a case.
- Every assigned version is classified as `owned`, `mismatch`, `unowned` or `never_executed`; the plan verdict is `no_data` / `clear` / `watch` / `at_risk`.
- Nine KPI tiles: assigned, executed, executed by assignee, different tester, no active assignee, gap percentage of executed, never executed, affected suites, distinct testers.
- 11-column gap-first DataTable with localized DataTables controls, a capped per-version history modal, and UTF-8 CSV with BOM, `;` delimiter and formula guard.
- Localized 400/401/403/404/405 state cards for invalid requests, missing session, missing rights, unknown plan and cross-project deep links.

## Security

- `bffSameOriginGuard()` and `bffEnforceSession()` on every route.
- The plan is resolved first; `testplan_metrics` is checked on the OWNING project before the client-supplied `tproject_id` assertion.
- Denials are logged at AUDIT; responses are GET/HEAD-only and never disclose another project's data.

## Wiring and i18n

- `cfg/reports.cfg.php` entry `execution_ownership`, `api/aside/index.php` title mapping and `api/resultsnav/index.php` map.
- `$actions->executionOwnership` in `lib/functions/common.php`.
- 85 `eown.*` + footer keys in all 10 client bundles; `bash ai/verify_i18n_coverage.sh` passes 9/9 with 13442 keys.
- `$TLS_link_report_execution_ownership` in all 19 server locale files; all lines terminate with `;` after bug #2069.

## Verification

- Fixture `tmp/fixtures_2068.php`: 5 assignments, 4 executions, 2 mismatches, 1 unowned, 1 never executed, 2 builds and 2 platforms.
- Suite `Issue #2068`: 10 cases; non-browser BFF checks PASS; browser interaction cases are TODO after two consecutive Chrome MCP failures.
- PHP lint, `node --check`, i18n coverage and suite gate all pass; Event Viewer has no new Error/Warning/L18N entries.
- Normal-state browser render verified with a screenshot. Browser verification of the modal, Refresh, CSV, filters, RO locale and low-privilege login remains TODO.
- Bug #2069 found during fixture testing (unterminated server-locale label) and fixed in all 19 locale files.
