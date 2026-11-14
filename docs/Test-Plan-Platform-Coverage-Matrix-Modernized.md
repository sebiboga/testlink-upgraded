# Test Plan Platform Coverage Matrix (platformMatrix)

Modernized screen for issue **#1992** in TestLink 2.0.1.

- Screen: `gui/templates/results/platformMatrix.html`
- BFF: `api/platformmatrix/index.php`
- Rights: `testplan_metrics` on the owning test project
- API: `GET|HEAD ?action=init&tplan_id=N[&tproject_id=P][&build_id=B][&platform_id=PL]`,
  `GET|HEAD ?action=report&tplan_id=N[&tproject_id=P][&build_id=B][&platform_id=PL]`
- Fixture: `tmp/fixtures_1992.php`
- Test suite: `Issue #1992` in `tmp/TLU_Test_Cases.md`

## Why a new report

1.9.20 repeats the latest status once per platform and offers no plan-wide
cross-platform view. The existing 2.0.1 reports answer neighbouring questions but
not this one: `platformComparison` diffs exactly two platforms, `resultsByBuild`
snapshots one build, `buildPlatformMatrix` is project/build scoped rather than
one plan. The Platform Coverage Matrix answers **"which assigned test cases pass
on EVERY platform of this plan?"** in one screen.

## Screen

- Toolbar: Refresh, Export CSV, build selector (defaults to the first open
  build; `All builds` sends an explicit `build_id=0`), platform selector,
  Apply, Close.
- Verdict banner with the plan verdict (`ready` / `watch` / `at_risk` /
  `no_data`), fully-green and failing row counts and the worst platform.
- Report context card (project, plan, build, platform).
- 11 KPI cards: platforms, test cases, assigned/executed cells, passed, failed,
  blocked, not run, coverage %, pass rate %, fully green.
- Per-platform totals table (assigned / executed / passed / failed / blocked /
  not run / coverage / pass rate).
- Matrix DataTable: one row per assigned test-case version, one column per
  platform, each cell showing the latest result (passed / failed / blocked /
  not run / not assigned). Rows are sorted failures-first; search and sort are
  enabled; a legend explains the colours.
- CSV export (`;` separator, BOM, formula-injection guard).
- Localized loading, error, empty and 403 state cards.

## Semantics

- Rows are the test-case versions assigned to the plan (`testplan_tcversions`).
- An assignment with `platform_id = 0` applies to **every** platform of the
  plan; a platform-specific assignment applies only to that platform.
- Platforms in scope are the active `testplan_platforms` links plus any
  platform that still appears on an assignment or an execution of the plan.
- Each cell uses the **latest execution** for that (version, platform) inside
  the selected build scope; an explicit `build_id=0` means all builds.
- Plan verdict precedence: `no_data` (no assigned cells) > `at_risk`
  (coverage or pass rate below 75%) > `watch` (any failure or blocked or
  incomplete coverage) > `ready`.

## API contract

| Case | HTTP | Code |
|---|---|---|
| `GET|HEAD` success | 200 | `ok` |
| Unknown action | 400 | `invalid_request` |
| Missing/invalid `tplan_id` | 400 | `invalid_request` |
| Platform/build outside the project | 400 | `invalid_request` |
| Anonymous | 401 | `not_authenticated` |
| Missing `testplan_metrics` | 403 | `no_right` (AUDIT) |
| Unknown plan | 404 | `tplan_not_found` |
| `tproject_id` mismatch | 404 | `project_mismatch` |
| Non GET/HEAD | 403 | same-origin/CSRF proof |

Security: `bffSameOriginGuard()` + `bffEnforceSession()`, every dynamic value
is an integer, the right is checked before the project assertion, and denial
is logged at AUDIT.

## Wiring

- `cfg/reports.cfg.php` — `platform_matrix`
- `api/aside/index.php` — `link_report_platform_matrix`
- `api/resultsnav/index.php` — `platform_matrix`
- `lib/functions/common.php` — `$actions->platformMatrix`
- `gui/templates/i18n/*.json` — 80 `pmx.*` keys + `footers.platformMatrix`
- `locale/*/strings.txt` — `$TLS_link_report_platform_matrix` (19 locales)

## Verification

- Browser: default open build, `All builds`, platform filter, Romanian locale,
  empty plan (no platforms), no-right user, ASIDE + Reports launcher links,
  console clean.
- Machine: 200/400/401/403/404/405 contract, project/platform/build validation,
  i18n gate 9/9.
- Screenshots: `docs/screenshots/1992-platform-matrix-*.png`.
