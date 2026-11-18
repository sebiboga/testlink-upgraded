# Test Plan Keyword Coverage (keywordCoverage)

Modernized screen for issue **#1995** in TestLink 2.0.1.

- Screen: `gui/templates/results/keywordCoverage.html`
- BFF: `api/keywordcoverage/index.php`
- Rights: `testplan_metrics` on the owning test project
- API: `GET|HEAD ?action=init&tplan_id=N[&tproject_id=P]`,
  `GET|HEAD ?action=report&tplan_id=N[&tproject_id=P][&build_id=B][&platform_id=PL][&keyword_id=K]`
- Fixture: `tmp/fixtures_1995.php`
- Test suite: `Issue #1995` in `tmp/TLU_Test_Cases.md`

## Why a new report

TestLink 1.9.20 shipped only a stacked-bar PNG (`lib/results/keywordBarChart.php`)
counting execution statuses per keyword. It never answered the manager questions
this screen answers: what share of a keyword's assigned tests were executed, what
is its pass rate, which project keywords have no test in the plan (gaps), and
which assigned tests carry no keyword at all (untagged).

## Screen

- Toolbar: test plan / test project context, build and platform pickers,
  `Gaps only` toggle, Refresh, Export CSV, Close.
- 7 KPI cards: keywords, keywords in use, gaps, assigned versions, untagged
  versions, coverage %, pass rate.
- Verdict banner (`ready` / `watch` / `at_risk` / `no_data` / `no_keywords`) and
  a warning strip for no assignments, no keywords, gaps and untagged versions.
- Keyword DataTable: keyword, cases, executed, not run, passed, failed, blocked,
  coverage bar, pass rate, gap/in-use badge; gaps sort first, then lowest
  coverage. A `keyword_id` filter narrows the BFF rows to one keyword.
- Collapsible untagged test-case panel with version id and latest-result badge.
- CSV export (`;` separator, UTF-8 BOM, formula-injection guard, localized
  headers), localized state cards for 400/401/403/404/405 and an EN/RO-aware
  TLi18n locale switcher.

## Semantics

- The unit is a test-case version assigned to the plan
  (`testplan_tcversions.tcversion_id`), deduplicated across platform pins.
  `platform_id=0` assignments are in scope for every platform.
- Keyword links are read from `testcase_keywords` on the **assigned version**
  (plus legacy `tcversion_id = 0` test-case-level links); a version with several
  keywords is counted under each one.
- The latest result is the newest execution id for that version inside the
  plan, optionally narrowed by `build_id` / `platform_id`; statuses `p` / `f` /
  `b` mean passed / failed / blocked, no row means not run.
- `coverage_pct = executed / cases * 100`, `pass_pct = passed / executed * 100`.
- A gap is a project keyword with zero plan-assigned versions; an untagged
  version is a plan-assigned version with no keyword.
- Plan verdict precedence: `no_data` (no assignments) > `no_keywords` (project
  has no keywords) > `at_risk` (coverage < 50 or pass < 80) > `watch`
  (coverage < 80 or pass < 90) > `ready`.

## API contract

| Case | HTTP | Code |
|---|---|---|
| `GET\|HEAD` success | 200 | `ok` |
| Unknown action / malformed or foreign id / missing plan | 400 | `invalid_request` |
| Anonymous | 401 | `not_authenticated` |
| Missing `testplan_metrics` | 403 | `no_right` (AUDIT) |
| Unknown plan / client project mismatch | 404 | `tplan_not_found` / `project_mismatch` |
| Non GET/HEAD with same-origin proof | 405 | `method_not_allowed` (`Allow: GET, HEAD`) |

Security: `bffSameOriginGuard()` + `bffEnforceSession()`; the plan is resolved
first (opaque 404) and the right is checked on its owning project before the
client `tproject_id` assertion is honoured (#1697 no-oracle convention). Denials
are logged at AUDIT; responses carry `nosniff` and `Cache-Control: no-store`.

## Wiring

- `cfg/reports.cfg.php` — `keyword_coverage`
- `api/aside/index.php` — `link_report_keyword_coverage`
- `api/resultsnav/index.php` — `keyword_coverage`
- `lib/functions/common.php` — `$actions->keywordCoverage`
- `gui/templates/i18n/*.json` — `kwc.*` keys (67) + `footers.keywordCoverage`
  in all 10 bundles (`ai/verify_i18n_coverage.sh`: 9/9 PASS)
- `locale/*/strings.txt` — `$TLS_link_report_keyword_coverage` (19 locales)

## Verification

- Browser: default report (5 assigned / 5 executed / 3 passed / 1 failed /
  1 blocked / 100% coverage / 60% pass / `at_risk`), gap row, untagged panel,
  build and platform filters, gaps-only toggle, CSV byte inspection (BOM + `;`
  + formula guard), RO locale, empty plan, no-keyword plan, no-right 403 state
  card, 404 state card, ASIDE launcher; console clean.
- Machine: 200/400/401/403/404/405 contract, `TLU_REQUIRE_SUITE="Issue #1995"`
  gate PASS (23 executed cases), i18n gate 9/9, Event Viewer AUDIT-only.
- Screenshots: `docs/screenshots/issue-1995-keywordcoverage-{main,nodata,ro,norights}.png`.
