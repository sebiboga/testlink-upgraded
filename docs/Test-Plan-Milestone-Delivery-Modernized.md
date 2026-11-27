# Test Plan Milestone Delivery Report (milestoneDelivery) — Modernized (2.0.1)

Issue: **#2079**. Screen: `gui/templates/results/milestoneDelivery.html`. BFF: `api/milestonedelivery/index.php`.

This net-new read-only report answers a question 1.9.20's milestone screen could not answer in one place: before each deadline, did the plan's assigned test cases actually receive execution evidence, and did that evidence match the expected high/medium/low priority mix?

## Why a new report

Legacy milestone data (`milestones.a/b/c`, `start_date`, `target_date`) is displayed as an expected priority pie, while execution evidence lives in a separate results screen. `milestoneDelivery` joins the plan's `testplan_tcversions` assignments, `tcversions.importance` and dated `executions` into one Dashio report with per-milestone coverage, weighted completion, days left and an overdue/at-risk/on-track verdict.

## Screen

- Toolbar: test-plan/project context, Build and Platform filters, Refresh, Export CSV and Close. A `tplan_id` deep link is required; ASIDE and Reports supply both project and plan IDs.
- Verdict banners: `no_milestones`, `no_data`, `complete`, `on_track`, `at_risk` and `overdue`, each localized independently of its CSS state class.
- Context panel: project, plan, selected build/platform and report date.
- KPI strip: milestones, assigned links, executed-in-window, not run, coverage %, pass rate, complete, overdue and at risk.
- DataTable: milestone, evidence window, expected H/M/L, assigned, executed, passed/failed/blocked/not-run split, coverage, pass rate, weighted completion, days left, verdict and a Detail button.
- Detail modal: per-priority expected/assigned/executed/coverage plus executed status counts; Close dismisses it.
- UTF-8 CSV with BOM, `;` delimiter, quoted cells and formula-injection guard; localized 400/401/403/404/405 state cards and a `TLi18n` locale switcher.

## Semantics

- The unit is one `testplan_tcversions` assignment of the selected plan, keyed by `(tcversion_id, platform_id)`.
- `milestones.a`, `b` and `c` are the expected percentages for importance 3 (high), 2 (medium) and 1 (low).
- An execution is evidence when its `execution_ts` date falls inside a milestone's `start_date..target_date` window and its status is not `n` (not run), matching legacy `tlTestPlanMetrics`. The first matching execution per assignment counts; `tcversion_number` is not used.
- Pass rate is `passed / executed × 100` over non-`n` executions in scope; not-run assignments are the remainder of the plan's links.
- An assignment with `platform_id = 0` is legacy-style and matches every execution platform; a named platform assignment matches only that platform.
- `coverage_pct = executed / assigned × 100`; `completion_pct` weights executed priorities by the milestone's expected mix and caps the ratio at 100%.
- Verdicts: no milestones → `no_milestones`, no assigned links → `no_data`, no days left and full weighted completion → `complete`, negative days left → `overdue`, ≤ 7 days left → `at_risk`, otherwise `on_track`. The plan-level verdict prefers overdue, then at-risk, then on-track, then complete.
- Rights (`testplan_metrics`) are checked on the OWNING project before the client-supplied `tproject_id` assertion, so a rightful user cannot use a mismatched project to bypass the check. An unknown plan returns 404 before the rights check.

## API contract

| Case | HTTP | Code |
|---|---|---|
| `GET\|HEAD` `action=init` / `action=report` success | 200 | `ok` |
| Missing/invalid `tplan_id`, unknown action, build/platform outside the owning project | 400 | `invalid_request` |
| Anonymous / expired session | 401 | `not_authenticated` / `session_expired` |
| Missing `testplan_metrics` on the owning project | 403 | `no_right` (AUDIT) |
| Unknown plan / mismatched `tproject_id` | 404 | `tplan_not_found` / `project_mismatch` |
| Non GET/HEAD | 405 | `method_not_allowed` (`Allow: GET, HEAD`) |

Security: `bffSameOriginGuard()` + `bffEnforceSession()`, `nosniff` and `no-store` headers, integer-only IDs, and AUDIT logging for denials. Both `init` and `report` return the build/platform filter lists.

## Wiring

- `api/milestonedelivery/index.php` — `action=init` and `action=report`.
- `cfg/reports.cfg.php` — `milestone_delivery` entry in Reports for all roles.
- `api/aside/index.php` — Reports entry with both project and plan IDs.
- `lib/functions/common.php` — `$actions->milestoneDelivery`.
- `gui/templates/i18n/*.json` — `mdlv.*` (89 keys) + `footers.milestoneDelivery` in all 10 bundles; `ai/verify_i18n_coverage.sh` passes 9/9 with 13701 keys.
- `locale/*/strings.txt` — `$TLS_link_report_milestone_delivery` in all 19 server locales.

## Verification

- Fixture `tmp/fixtures_2079.php`: project `MDV2079`, plan `MDV Delivery Plan`, two builds, two platforms, six assigned versions (3 high / 2 medium / 1 low), three milestones and six dated executions; extra plans cover `no_milestones` and `no_data`, plus a role-3 `mdv2079norights` user.
- Suite `Issue #2079`: browser-verified normal/filtered/detail/CSV/RO states, all verdict banners, 400/401/403/404/405/empty/error cards, i18n and syntax gates, Event Viewer and wiring. Suite gate reports 34 PASS / 0 FAIL / 0 SKIP.
- Bugs found during testing/code review: #2080 (raw DataTables keys and numeric all-filter labels), #2081 (`report` omitted filter lists), #2082 (duplicate DataTables header `aria-label` after re-apply), #2083 (`not_run` executions counted as evidence), #2084 (pass rate / status split missing from the UI) and #2085 (documentation overstatements). All were filed with the `bug` label and fixed in separate commits; #2080–#2082 were browser-reverified and closed.
- PHP lint, extracted `node --check`, JSON validation, i18n coverage and suite gates pass. Event Viewer has 8 AUDIT rows and 0 Error/Warning entries after all fixture, login, denial, filter, CSV and fix paths.
