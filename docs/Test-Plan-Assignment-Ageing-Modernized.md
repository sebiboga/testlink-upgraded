# Test Plan Assignment Ageing (assignmentAgeing) — Modernized (2.0.1)

Issue: **#2095**. Screen: `gui/templates/results/assignmentAgeing.html`. BFF: `api/assignmentageing/index.php`.

This net-new read-only test-plan report answers which active tester assignments are ageing, overdue or still without any execution evidence. Legacy 1.9.20 screens showed assignments and executions separately; this report joins `user_assignments` (execution assignments, `type = 1`) with plan versions, deadlines and the latest execution inside the selected scope.

## Why a new screen

1.9.20 had no screen that ranked assigned work by the age of its newest evidence and by deadline pressure. `assignmentAgeing` classifies every active assignment into `never`, `fresh` (<= 7 days), `watch` (8–30), `stale` (31–90) and `dormant` (> 90), and reports `overdue_days` / `days_left` from the optional assignment deadline.

## Screen

- Toolbar: Build, Platform, Tester, Age bucket and Scope filters, Refresh, Export CSV, Apply and Close.
- Context panel: plan, project, applied scope and age bucket.
- Verdict banner: `no_data`, `at_risk` (dormant or overdue), `watch` (stale or overdue) and `healthy`.
- KPI strip: assignments, never executed, executed, overdue, fresh/watch/stale/dormant counts, average age, oldest age and completion percentage.
- DataTable: prefixed external ID, case, suite path, version, tester, build, platform, assigned date, age, deadline with overdue/days-left, age bucket, latest result, last execution and a Details button.
- Detail modal: assignment fields and the latest 100 executions inside the applied build/platform/tester/scope, with focus management, backdrop/Escape/Close dismissal and a localized truncation note.
- UTF-8 CSV with BOM, `;` delimiter, quoted cells and a formula-injection guard; localized 400/401/403/404/405 state cards and a `TLi18n` locale switcher.

## Semantics

- Scope is `user_assignments.type = 1`, `feature_id = testplan_tcversions.id`; `status = 1` means active, while `2`/`3` are included only with Scope = All.
- `build_id = 0` means any build; `platform_id = 0` assignments apply to every platform of the plan and are expanded for the platform filter and evidence lookup.
- Age is whole days since the latest execution inside the scope, or since `creation_ts` when the assignment has never been executed. Deadline state is `none`, `pending` or `overdue`, clamped to zero days.
- The latest result is derived from `executions.status` (`p`/`f`/`b`/other) inside the plan and the applied filters.
- Rights (`testplan_metrics`) are checked on the OWNING project of the plan before the client-supplied `tproject_id` assertion; denials return `no_right` and write an AUDIT event.

## API contract

| Case | HTTP | Code |
|---|---|---|
| `GET\|HEAD` `action=init\|report\|detail` success | 200 | `ok` |
| Unknown action / missing `tplan_id` / missing `assignment_id` | 400 | `invalid_request` |
| Anonymous / expired session | 401 | `not_authenticated` / `session_expired` |
| Missing `testplan_metrics` on the owning project | 403 | `no_right` (AUDIT) |
| Unknown plan / assignment outside the plan | 404 | `tplan_not_found` / `assignment_not_found` |
| Non GET/HEAD | 405 | `method_not_allowed` (`Allow: GET, HEAD`) |

Security: `bffSameOriginGuard()` + `bffEnforceSession()`, `nosniff` and `no-store` headers, integer-only IDs, `html_entity_decode` on display data, escaped client rendering and AUDIT logging for denials.

## Wiring

- `cfg/reports.cfg.php` — `assignment_ageing` report entry.
- `api/aside/index.php` — modern report mapping with project/plan context.
- `lib/functions/common.php` — `$actions->assignmentAgeing`.
- `gui/templates/i18n/*.json` — 81 `aag.*` + footer keys in all 10 bundles; `ai/verify_i18n_coverage.sh` passes 9/9 with 13954 keys.
- `locale/*/strings.txt` — `$TLS_link_report_assignment_ageing` in all 19 server locales.

## Verification

- Fixture `tmp/fixtures_2095.php`: project `AAG2095`, plan `Aging Plan`, 2 builds, 2 platforms, 7 assignments (fresh/watch/stale/dormant/never/any-platform/closed), 2 controlled executions and role-7/role-3 users.
- Suite `Issue #2095`: BFF contract, metrics, filters, browser states, modal, CSV, RO locale, permissions, errors, i18n and Event Viewer all PASS; suite gate reports 34 PASS / 0 FAIL / 0 SKIP.
- Bug #2096 (`E_WARNING Undefined array key "never"`) was filed with the `bug` label, fixed by initializing the counter, re-verified and closed. A 405-before-guard defect and a locale-label syntax defect were also fixed in pushed commits.
- PHP lint, JSON validation, i18n coverage and suite gates pass. Event Viewer has 0 new Error/Warning rows after the #2096 fix.
