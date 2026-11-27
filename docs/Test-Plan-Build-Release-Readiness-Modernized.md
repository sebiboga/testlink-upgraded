# Test Plan Build Release Readiness Report (buildReleaseReadiness) — Modernized (2.0.1)

Issue: **#2087**. Screen: `gui/templates/results/buildReleaseReadiness.html`. BFF: `api/buildreleasereadiness/index.php`.

This net-new read-only report answers a question 1.9.20's results screens could not answer in one place: for every build of a test plan, is there latest execution evidence for its assigned test-case versions, and does that evidence meet release criteria (coverage, pass rate, blockers and linked defects)?

## Why a new report

Legacy screens show build lists, execution results and defect links separately. `buildReleaseReadiness` joins `testplan_tcversions` assignments, `builds`, build state and dated `executions` (including `execution_bugs`) into one Dashio report with per-build coverage, pass rate, defect evidence and a `ready` / `at_risk` / `not_ready` / `no_data` verdict.

## Screen

- Toolbar: Build, State, Platform and Verdict filters, Refresh, Export CSV, Apply and Close. A `tplan_id` deep link is required; ASIDE and Reports supply both project and plan IDs.
- Verdict banners: `ready`, `at_risk`, `not_ready`, `no_data`, `mixed`, each localized and driven by the KPI counts of the applied scope.
- Context panel: project, plan, selected build/platform and report date.
- KPI strip: builds, assigned, executed, passed, failed, blocked, coverage %, pass rate, linked defects, ready, at risk and not ready.
- DataTable: build, state, assigned, executed, pass/fail/block/not-run split, coverage, pass rate, distinct defects, verdict and a Detail button.
- Detail modal: per-case suite, version, status and bug links for the selected build and applied platform filter; Close dismisses it.
- UTF-8 CSV with BOM, `;` delimiter, quoted cells and formula-injection guard; localized 400/401/403/404/405 state cards and a `TLi18n` locale switcher.

## Semantics

- The unit is one `testplan_tcversions` assignment of the selected plan; the report uses the plan's assigned test-case versions, not the suite tree.
- The latest execution per `(build, tcversion)` is chosen by the highest execution `id`, matching the legacy "last recorded" behavior. An assignment with `platform_id = 0` matches any execution platform; a named platform assignment matches only that platform.
- Without a platform filter, the latest global execution is used. With a platform filter, both the named platform and global (`platform_id = 0`) executions are eligible, but only the latest one in that scope supplies status and bug links.
- Bug links are read from the selected latest execution (`execution_bugs`) regardless of its status — a passing execution may carry a link — keyed by execution id; the same bug linked by two builds is still distinct evidence for each build, while the plan KPI counts it once across the filtered rows.
- `coverage_pct = executed / assigned × 100`; `pass_rate = passed / executed × 100`; not-run assignments count as assigned but not executed.
- Verdicts: `no_data` when nothing was executed; `ready` when coverage >= 90%, pass rate >= 95% and there are no failed/blocked executions or linked defects; `at_risk` when coverage >= 70% and pass rate >= 80%; otherwise `not_ready`. The `at_risk` band can contain failures or defects, so it precedes the stricter `not_ready` fall-through.
- Rights (`testplan_metrics`) are checked on the OWNING project before the client-supplied `tproject_id` assertion, so a rightful user cannot use a mismatched project to bypass the check. Denials are opaque 403 responses with an AUDIT event.

## API contract

| Case | HTTP | Code |
|---|---|---|
| `GET\|HEAD` `action=init` / `action=report` / `action=detail` success | 200 | `ok` |
| Missing/invalid `tplan_id`, unknown action, build/platform outside the owning project | 400 | `invalid_request` |
| Anonymous / expired session | 401 | `not_authenticated` |
| Missing `testplan_metrics` on the owning project | 403 | `no_right` (AUDIT) |
| Unknown build in the applied scope / mismatched `tproject_id` | 404 | `build_not_found` / `project_mismatch` |
| Non GET/HEAD | 405 | `method_not_allowed` (`Allow: GET, HEAD`) |

Security: `bffSameOriginGuard()` + `bffEnforceSession()`, `nosniff` and `no-store` headers, integer-only IDs, whitelisted enums, escaped client rendering and AUDIT logging for denials.

## Wiring

- `api/buildreleasereadiness/index.php` — `init`, `report` and `detail`.
- `cfg/reports.cfg.php` — `build_release_readiness` entry in Reports for all roles.
- `api/aside/index.php` — Reports entry with both project and plan IDs.
- `lib/functions/common.php` — `$actions->buildReleaseReadiness`.
- `gui/templates/i18n/*.json` — `brr.*` (88 keys, including `brr.banner.mixed`) + `footers.buildReleaseReadiness` in all 10 bundles; `ai/verify_i18n_coverage.sh` passes 9/9 with 13790 keys.
- `locale/*/strings.txt` — `$TLS_link_report_build_release_readiness` in all 19 server locales.

## Verification

- Fixture `tmp/fixtures_2087.php`: project `BRR2087`, one plan, four builds, two platforms, four assigned versions and dated executions; a role-3 `brr2087norights` user was added. The fixture was rerun after the bug-fix pass to produce clean final screenshots.
- Suite `Issue #2087`: browser-verified normal/filtered/detail/CSV/RO/no-right states, all verdict banners, 400/401/403/404/405 error cards, i18n and syntax gates, Event Viewer and wiring. Suite gate reports 34 PASS / 0 FAIL / 0 SKIP.
- Bugs found during testing/code review: #2088 (Detail modal cleared the loaded report), #2089 (platform filter mixed bug links across platforms), #2090 (no-data scope showed the Ready banner) and #2091 (Detail used an unapplied platform filter). All were filed with the `bug` label, fixed in `91c3ab06a`, browser-reverified and closed.
- PHP lint, extracted `node --check`, JSON validation, i18n coverage and suite gates pass. Event Viewer has 13 AUDIT rows and 0 Error/Warning entries after all fixture, login, denial, filter, CSV, locale and fix paths.
