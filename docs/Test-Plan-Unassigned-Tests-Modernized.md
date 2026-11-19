# Test Plan Unassigned Tests — Modernized Screen

Modernization / net-new report for the TestLink 2.0.1 Reports chapter — GitHub
issue [#2007](https://github.com/sebiboga/testlink-upgraded/issues/2007).
TestLink 1.9.20 showed tester assignments inside execution screens but had no
plan-wide report answering "which assigned test-case versions currently have no
active tester, and what is their latest result?".

**Path:** ASIDE menu → Reports → *Test Plan Unassigned Tests*
**URL:** `gui/templates/results/unassignedTests.html?tproject_id=<id>&tplan_id=<id>`
**BFF API:** `api/unassignedtests/index.php?action=init|report&tplan_id=<id>[&tproject_id=<id>][&build_id=<id>][&platform_id=<id>][&scope=unassigned|all]`
**Rights:** `testplan_metrics` on the project that owns the test plan.
**Tracking issue:** [#2007](https://github.com/sebiboga/testlink-upgraded/issues/2007)

---

## Data model and semantics

The report unit is one row of `testplan_tcversions` (a test-case version assigned
to the selected plan, optionally pinned to a platform). A tester is present only
when an `user_assignments` row is active: `type=1`, `user_id>0` and
`status not in (2,3)`. A `build_id=0` assignment applies to every build; a
positive `build_id` applies only to that build.

The latest result is the newest execution row for the version inside the plan and
the active build/platform scope. For platform-agnostic assignments (`platform_id=0`)
the latest execution on any platform is used; for platform-pinned assignments the
exact platform result takes precedence, with an all-platform execution as fallback.
The KPI "Unassigned, no run in scope" therefore refers to the filtered scope, not
to the lifetime of the test case.

## Screen features

* Context strip with test plan and test project names.
* Build, platform and scope (unassigned only / all assigned tests) filters.
* Eight KPI tiles: assigned versions, with tester, without tester, unassigned %,
  no run in scope, affected suites, unassigned passed, unassigned failed.
* `no_data` / `clear` / `watch` / `at_risk` verdict banner.
* Searchable, sortable DataTable with test case, suite, platform, priority,
  tester, last result, last run, build and executions columns.
* Test-case name links open `execTest.html` with project/plan/test case context.
* CSV export generated client-side from the filtered DataTable rows, with UTF-8
  BOM, semicolon delimiter and formula-injection guard.
* Localized 400/401/403/404/405 state cards, loading panel, empty-state card,
  toast messages and generated-on footer.

## Security

`bffSameOriginGuard()` and `bffEnforceSession()` protect every request. The plan
is resolved server-side first, `testplan_metrics` is checked on the owning
project, and only then is the client-supplied `tproject_id` compared (mismatch is
an opaque 404). Permission denials are logged as AUDIT events. All identifiers
are validated positive integers and build/platform IDs are checked against the
plan's allowlists.

## Bugs found and fixed during verification

* #2008 — a platform-agnostic assignment hid an execution recorded on a named
  platform (row showed "Not run" and 0 executions). The BFF now keeps the latest
  execution across platforms for `platform_id=0` links.
* #2009 — the Priority column showed the numeric HIGH/MEDIUM/LOW constant. The
  BFF now maps it to `high` / `medium` / `low`.
* #2010 — the "never run" KPI label was wrong when build/platform filters were
  active; it is now "Unassigned, no run in scope" in all ten client bundles.

## Verification

`php -l`, `python3 -m json.tool` for all bundles, `ai/verify_i18n_coverage.sh`
(9/9, 11977 keys) and `TLU_REQUIRE_SUITE="Issue #2007" ai/verify_test_suites.sh`
(34 PASS / 0 FAIL) are clean. The default fixture report returned assigned 6,
covered 3, unassigned 3 and verdict `at_risk`. The browser pass covered default and filtered
reports, CSV bytes and formula guard, empty and fully covered plans, EN/RO
locales, role-3 denial, anonymous session, 400/404/405 paths, Refresh, Close and
DataTable interactions. Console and Event Viewer had no new Error/Warning rows.
Suite `Issue #2007` is appended to `tmp/TLU_Test_Cases.md`.

Screenshots captured: `docs/screenshots/issue-2007-unassigned-tests-normal.png`
(default report), `...-filters.png` (scope + build filter), `...-empty.png`
(no-data plan) and `...-no-right.png` (role-3 denial).
