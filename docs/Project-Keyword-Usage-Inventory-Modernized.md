# Project Keyword Usage Inventory — Modernized Screen

Modernization / net-new report for the TestLink 2.0.1 Reports chapter — GitHub
issue [#2015](https://github.com/sebiboga/testlink-upgraded/issues/2015).
TestLink 1.9.20 stored project keywords and their test-case links, but offered
no single report answering "which keywords are actually used by this project?"
This read-only screen inventories every project keyword, its test-case/version
footprint, execution result split and gap verdict.

**Path:** Reports → *Project Keyword Usage Inventory*
**URL:** `gui/templates/results/keywordUsage.html?tproject_id=<id>`
**BFF API:** `api/keywordusage/index.php?action=projects|init|report|detail&tproject_id=<id>[&keyword_id=<id>]`
**Rights:** `mgt_view_tc` on the addressed test project.
**Tracking issue:** [#2015](https://github.com/sebiboga/testlink-upgraded/issues/2015)

---

## Data model and semantics

The universe is every test case of the selected project, resolved with
`testproject::get_all_testcases_id()`. Keyword links come from
`testcase_keywords` and are accepted only when the linked version belongs to a
test case of that project; foreign-project links are ignored. A keyword's row
reports distinct linked test cases, versions, suite names, plans and executions,
plus passed/failed/blocked counts and the latest activity timestamp. Project
coverage is the share of test cases carrying at least one keyword; the distinct
suite KPI is aggregated once at project level (a suite shared by several
keywords is counted once). The project verdict is `no_keywords` → `no_usage` →
`sparse` (<25%) → `partial` (<70%) → `broad`; each keyword verdict is `unused`,
`low` (<10% coverage), `medium` (<40%) or `high`.

The `detail` action returns the exact test cases carrying one keyword, with
external IDs, suite names, version counts, execution counts and last execution
timestamps.

## Screen features

* Context strip with project name, test-case count, build count and number of
  plans having tagged cases.
* Ten KPI tiles and a localized verdict banner.
* Usage filter (All / Broad / Partial / Sparse / Unused) and project picker.
* Searchable, sortable 13-column DataTable of keywords with an inline usage bar.
* Per-keyword detail modal loaded from the BFF `detail` action.
* Refresh and Export CSV buttons; the CSV carries a UTF-8 BOM, a `;` delimiter
  and a spreadsheet-formula guard.
* Localized 400/401/403/404/405 state cards, loading panel, empty-state card,
  toast messages, localized footer label and in-screen locale switcher.

## Security

Every request passes `bffSameOriginGuard()`; authenticated requests also pass
`bffEnforceSession()`. `mgt_view_tc` is checked on the addressed project before
the project is resolved, and denials are AUDIT-logged with `tLog()`. Project and
keyword IDs are validated as integers and must resolve inside the addressed
project; an unknown project/keyword is an opaque 404. All string output is
escaped in the screen and neutralized in CSV (including a leading `'` for
spreadsheet formulas).

## Verification

Fixture `tmp/fixtures_2015.php` creates KUP2015 (6 cases, 2 suites, 5 keywords,
6 links, 1 plan, 2 builds, 5 executions, an XSS-probe keyword), KUP2015 OTHER
(foreign twin keyword), KUP2015 EMPTY (no keywords) and a role-3 user. Suite
`Issue #2015` records 27 executed cases (2015.1–2015.24 + 2015r.1–2015r.3) covering KPI math, all table columns,
detail modals (with and without linked cases), the usage filter, project
switching and cross-project isolation, Refresh, CSV, stored-markup escaping,
Romanian locale, no-right/anonymous states, invalid parameters, unknown
entities, wrong verb, report-hub wiring and Event Viewer. The shared suite gate
reports 997 suite headings with 0 lost vs the merge-base; the i18n coverage gate
passes 9/9; `kup.*` keys plus `footers.keywordUsage` exist in all 10 client
bundles and `$TLS_link_report_keyword_usage` in all 19 server locales. Browser
console is clean and Event Viewer has only AUDIT rows.

Two bugs found during browser testing were fixed and closed:
[#2016](https://github.com/sebiboga/testlink-upgraded/issues/2016) (distinct
suite KPI) and [#2017](https://github.com/sebiboga/testlink-upgraded/issues/2017)
(duplicate DataTables aria labels after Refresh). The mandatory code review also
fixed the Usage filter reset, numeric Coverage sorting and the plans KPI so a
plan with tagged assigned cases but no executions is counted while foreign-project
plans are excluded.

Related files: `gui/templates/results/keywordUsage.html`,
`api/keywordusage/index.php`, `cfg/reports.cfg.php`, `api/aside/index.php`,
`lib/functions/common.php`.

Screenshots: `docs/screenshots/2015-keywordusage-report.png`,
`docs/screenshots/2015-keywordusage-detail.png`,
`docs/screenshots/2015-keywordusage-empty.png`,
`docs/screenshots/2015-keywordusage-denied.png`.
