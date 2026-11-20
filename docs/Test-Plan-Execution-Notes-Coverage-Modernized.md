# Test Plan Execution Notes Coverage — Modernized Screen

Modernization / net-new report for the TestLink 2.0.1 Reports chapter — GitHub
issue [#2014](https://github.com/sebiboga/testlink-upgraded/issues/2014).
TestLink 1.9.20 recorded execution notes but never answered, on one screen,
"which assigned test cases actually have evidence for every run?" This
read-only report ranks a plan's assigned test-case versions by execution-note
coverage and links directly to the full note history of each version.

**Path:** ASIDE menu → Reports → *Test Plan Execution Notes Coverage*
**URL:** `gui/templates/results/execNotesCoverage.html?tproject_id=<id>&tplan_id=<id>`
**BFF API:** `api/execnotescoverage/index.php?action=init|report|csv&tplan_id=<id>[&tproject_id=<id>][&build_id=<id>][&platform_id=<id>][&status=all|p|f|b][&scope=all|documented|partial|undocumented|latest_missing]`
`api/execnotescoverage/index.php?action=detail&tplan_id=<id>&link_key=<tcversion_id>/<platform_id>[&tproject_id=<id>]`
**Rights:** `testplan_metrics` on the project that owns the test plan.
**Tracking issue:** [#2014](https://github.com/sebiboga/testlink-upgraded/issues/2014)

---

## Data model and semantics

The universe is the plan's `testplan_tcversions` assignment rows. A version's
execution set is taken from `executions` for its `tcversion_id` inside the
selected plan, narrowed by build and platform (`platform_id = 0` assignments
apply to every platform). A run is *documented* when its `execution_notes`
field is non-empty after trimming; each version gets the verdict
`no_exec` → `undocumented` (no run has a note) → `partial` (some runs have
notes) → `documented` (every run has a note). Plan-level note coverage is the
share of in-scope executions carrying a note; the latest run of each version
feeds the `latest_missing` evidence filter and the last-status/last-build/
last-platform columns.

Eight KPI cards report assigned versions, versions in scope, executions,
runs with notes, runs without notes, note coverage, latest runs without a note
and passed versions. The plan verdict precedence is `no_data` → `undocumented`
→ `at_risk` → `watch` → `documented`; the detail action is keyed by the exact
assignment `link_key` and deliberately ignores the report's build/platform
filters, so the full note history stays available.

## Screen features

* Context strip with test plan and test project names.
* Build, platform, result (All / Passed / Failed / Blocked) and evidence
  (All / Every run documented / Partially documented / No notes at all /
  Latest run has no note) filters.
* Eight KPI tiles and a localized verdict banner.
* Searchable, sortable 13-column DataTable of assigned test-case versions
  (the CSV has 14 columns).
* Per-version execution-note detail modal (date, result, build, platform and
  plain-text note) loaded from the BFF `detail` action with the assignment
  `link_key`.
* Refresh and Export CSV buttons; the CSV carries a UTF-8 BOM, a `;` delimiter
  and a spreadsheet-formula guard, from the guarded BFF `csv` action.
* Localized 400/401/403/404/405 state cards, loading panel, empty-state card,
  toast messages, generated-on footer and in-screen locale switcher.

## Security

Every request passes `bffSameOriginGuard()`; authenticated requests also pass
`bffEnforceSession()`. The plan is resolved server-side first, `testplan_metrics`
is checked on the owning project and only then is the client-supplied
`tproject_id` compared — an unknown plan is an opaque 404 and denials are
AUDIT-logged. Build/platform/status/scope values are allow-listed and bound to
the owning project/plan, `link_key` is accepted only for the detail action and
must identify an assignment of the addressed plan, and all string output is
escaped in the screen and neutralized in CSV (including a leading `'` for
spreadsheet formulas).

## Verification

Fixture `tmp/fixtures_2014.php` creates ENC2014 (plans ENC Notes Plan / ENC Empty
Plan, two builds, two platforms, four assigned versions with documented,
partial, undocumented and no-execution notes, seven executions, a stored markup
sample, an `=HYPERLINK(...)` sample and a role-3 user). Suite `Issue #2014`
records 30 executed cases plus 3 code-review fix cases covering KPI math, all
filters, the note detail modal (including independence from active build and
platform filters), no-execution detail, CSV formula guard, stored-markup
escaping, RO locale, empty plan, role-3 403, anonymous 401, session expiry,
unknown/mismatched plans, invalid parameters, wrong verb, ASIDE entry and Event
Viewer. The shared suite gate reports 34 PASS / 0 FAIL / 0 SKIP; the i18n
coverage gate passes 9/9; `enc.*` keys plus `footers.execNotesCoverage` exist in
all 10 client bundles and `$TLS_link_report_exec_notes_coverage` in all 19
server locales. Browser console is clean and Event Viewer has only AUDIT rows.

Related files: `gui/templates/results/execNotesCoverage.html`,
`api/execnotescoverage/index.php`, `cfg/reports.cfg.php`, `api/aside/index.php`,
`lib/functions/common.php`.
