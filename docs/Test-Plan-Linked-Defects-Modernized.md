# Test Plan Linked Defects — Modernized Screen

Modernization / net-new report for the TestLink 2.0.1 Reports chapter — GitHub
issue [#2011](https://github.com/sebiboga/testlink-upgraded/issues/2011).
TestLink 1.9.20 could attach a bug to an execution, but the only way to answer
"which defects affect this plan, and where?" was to walk every execution popup.
This read-only report groups the plan's `execution_bugs` links by defect.

**Path:** ASIDE menu → Reports → *Test Plan Linked Defects*
**URL:** `gui/templates/results/linkedDefects.html?tproject_id=<id>&tplan_id=<id>`
**BFF API:** `api/linkeddefects/index.php?action=init|report|csv&tplan_id=<id>[&tproject_id=<id>][&build_id=<id>][&platform_id=<id>][&status=all|p|f|b]`
`api/linkeddefects/index.php?action=detail&tplan_id=<id>&bug_id=<id>[&tproject_id=<id>][&build_id=<id>][&platform_id=<id>][&status=all|p|f|b]`
**Rights:** `testplan_metrics` on the project that owns the test plan.
**Tracking issue:** [#2011](https://github.com/sebiboga/testlink-upgraded/issues/2011)

---

## Data model and semantics

The source rows are `execution_bugs` joined to `executions` of the selected plan,
then to `tcversions` and `nodes_hierarchy` for the test case name/version. A
link inherits its execution's build, platform, status, tester and timestamp.
A `platform_id = 0` execution applies to every platform and is included in any
platform filter; `build_id = 0` means "no build recorded" and is excluded from
the distinct-build counters.

Rows are grouped by the literal `bug_id` string (case-sensitive, max 64 chars).
Each row carries link/execution/case/build/platform counts, the sorted set of
execution statuses and a verdict:

* `failed` — at least one link on a failed execution;
* `blocked` — no failed link, at least one blocked execution;
* `passed` — at least one passed link and no failed or blocked link (not-run links are allowed);
* `unresolved` — links on not-run executions only.

The plan verdict is `no_data` → `at_risk` (any failed defect) → `watch` (blocked
links) → `clear`. The eight KPIs report defects, links, executions, cases,
builds, failed defects, blocked links and the pass rate of links.

## Screen features

* Context strip with test plan and test project names.
* Build, platform and result filters (All / Passed / Failed / Blocked); `bug_id`
  is an API-only filter for the detail action.
* Eight KPI tiles and a localized verdict banner.
* Searchable, sortable 10-column DataTable of defects, ordered by link count.
* Per-defect detail modal listing every linked execution (case, version, build,
  platform, status, timestamp, step), loaded from the BFF `detail` action with
  the same filter scope.
* Client-side CSV export of the filtered DataTable rows with UTF-8 BOM,
  semicolon delimiter and formula-injection guard; the BFF also offers a
  server-side `csv` action with the same guard.
* Localized 400/401/403/404/405 state cards, loading panel, empty-state card,
  toast messages, generated-on footer and in-screen locale switcher.

## Security

Every request passes `bffSameOriginGuard()`; authenticated requests also pass
`bffEnforceSession()`. The plan is resolved server-side first, `testplan_metrics`
is checked on the owning project and only then is the client-supplied
`tproject_id` compared — an unknown plan is an opaque 404 and denials are
AUDIT-logged. Build/platform filter values must belong to that project/plan,
`bug_id` is length- and character-validated, and insertion into SQL uses
`prepare_string()`. Output is HTML-escaped in the screen.

## Verification

Fixture `tmp/fixtures_2011.php` creates LDF2011 (plans LDF Plan/LDF Empty Plan,
two builds, two platforms, four cases, six links across BUG-100/200/300 and a
role-3 user). Suite `Issue #2011` records 22 executed cases plus 4 code-review
cases covering default
aggregation, DataTable, detail modal, all filters, CSV, empty plan, the full
BFF contract (init/report/detail/csv), 400/401/403/404/405 paths, locale switch,
Reports menu entry, console and Event Viewer. The shared suite gate reports no
lost suites; the i18n coverage gate passes 9/9; 75 `ldf.*` keys plus
`footers.linkedDefects` exist in all 10 client bundles and
`$TLS_link_report_linked_defects` in all 19 server locales. Code review added
explicit `session_expired` / `invalid_parameter` state cards that clear any
stale report, and replaced the blank detail screenshot with a verified modal
capture. Event Viewer: 0 new Error/Warning rows.

Related files: `gui/templates/results/linkedDefects.html`,
`api/linkeddefects/index.php`, `cfg/reports.cfg.php`, `api/aside/index.php`,
`lib/functions/common.php`.
