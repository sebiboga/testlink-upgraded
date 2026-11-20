# Test Plan Step Coverage — Modernized Screen

Modernization / net-new report for the TestLink 2.0.1 Reports chapter — GitHub
issue [#2012](https://github.com/sebiboga/testlink-upgraded/issues/2012).
TestLink 1.9.20 displayed a test case's design steps only in the specification
view and its latest result only in the execution view. This read-only report
answers "how well are this plan's assigned test cases documented, and do their
steps define expected results?" on one screen.

**Path:** ASIDE menu → Reports → *Test Plan Step Coverage*
**URL:** `gui/templates/results/stepCoverage.html?tproject_id=<id>&tplan_id=<id>`
**BFF API:** `api/stepcoverage/index.php?action=init|report|csv&tplan_id=<id>[&tproject_id=<id>][&build_id=<id>][&platform_id=<id>][&status=all|p|f|b][&scope=all|no_steps|complete|partial|missing_expected]`
`api/stepcoverage/index.php?action=detail&tplan_id=<id>&tcversion_id=<id>[&tproject_id=<id>][&build_id=<id>][&platform_id=<id>][&status=all|p|f|b][&scope=all|no_steps|complete|partial|missing_expected]`
**Rights:** `testplan_metrics` on the project that owns the test plan.
**Tracking issue:** [#2012](https://github.com/sebiboga/testlink-upgraded/issues/2012)

---

## Data model and semantics

The universe is the plan's `testplan_tcversions` assignments, resolved to the
latest `tcversions` row of each test case. For every assigned version the BFF
counts its `tcsteps` rows and how many of them carry a non-empty `expected_results`
field. The owning suite path is resolved by walking `nodes_hierarchy` from the
test case's parent upwards, so the Suite column contains only suite ancestors
(the test case itself is not repeated; bug #2013).

Each version gets a `complete` / `partial` / `missing_expected` / `no_steps`
design verdict and the latest execution result inside the selected build and
platform scope (`platform_id = 0` assignments are included for every platform).
The plan verdict is `no_data` → `undocumented` (every shown version has no
steps) → `at_risk` (expected results missing or a version only partially
documented) → `watch` (some versions have no steps) → `complete`.

Eight KPI cards report assigned versions, versions in scope, versions with
steps, versions without steps, design steps, steps without expected results,
expected-results coverage and executed versions. The latest execution also
feeds per-version result/build/platform/timestamp columns.

## Screen features

* Context strip with test plan and test project names.
* Build, platform, result (All / Passed / Failed / Blocked) and design
  (All / Complete / Partial / Missing expected result / No steps) filters.
* Eight KPI tiles and a localized verdict banner.
* Searchable, sortable 13-column DataTable of assigned test-case versions,
  sorted by suite path and test case.
* Per-version detail modal listing every design step (number, action, expected
  result) loaded from the BFF `detail` action with the same filter scope.
* Refresh and Export CSV buttons; CSV carries a UTF-8 BOM, a `;` delimiter and
  a spreadsheet-formula guard, both client-side and from the BFF `csv` action.
* Localized 400/401/403/404/405 state cards, loading panel, empty-state card,
  toast messages, generated-on footer and in-screen locale switcher.

## Security

Every request passes `bffSameOriginGuard()`; authenticated requests also pass
`bffEnforceSession()`. The plan is resolved server-side first, `testplan_metrics`
is checked on the owning project and only then is the client-supplied
`tproject_id` compared — an unknown plan is an opaque 404 and denials are
AUDIT-logged. Build/platform/status/scope values are allow-listed and bound to
the owning project/plan, `tcversion_id` is accepted only for the detail action,
and all string output is escaped in the screen and neutralized in CSV.

## Verification

Fixture `tmp/fixtures_2012.php` creates STPC2012 (plans STPC Plan / STPC Empty
Plan, two builds, two platforms, five assigned versions — complete, partial,
missing-expected, no-steps and a stored-markup case — three executions and a
role-3 user). Suite `Issue #2012` records 26 executed cases covering KPI math,
all filters, detail modal, CSV, stored-markup escaping, RO locale, empty plan,
role-3 403, anonymous 401, unknown/mismatched plans, invalid parameters, wrong
verb, ASIDE entry, bug #2013 regression and Event Viewer. The shared suite gate
reports 34 PASS / 0 FAIL / 0 SKIP (plus 8 review-fix cases); the i18n coverage gate passes 9/9; `stpc.*`
keys plus `footers.stepCoverage` exist in all 10 client bundles and
`$TLS_link_report_step_coverage` in all 19 server locales. Browser console is
clean and Event Viewer has only AUDIT rows.

Related files: `gui/templates/results/stepCoverage.html`,
`api/stepcoverage/index.php`, `cfg/reports.cfg.php`, `api/aside/index.php`,
`lib/functions/common.php`.
