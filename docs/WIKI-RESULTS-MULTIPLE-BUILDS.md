# Results by Multiple Builds — Modernized Screen

The **Results by Multiple Builds** report (ASIDE → Reports → *Query Metrics*) shows a per-test-case × per-build execution-status matrix: each rendered column is one of the selected builds, each row one test case, and the cell is the test case's **last** execution status on that build. It replaces the legacy 1.9.20 screens `lib/results/resultsMoreBuildsGUI.php` (parameter form) and `lib/results/resultsMoreBuilds.php` (report) with a modern Dashio-styled page backed by a plain-PHP REST BFF.

**Path:** ASIDE menu → Reports → *Query Metrics*
**URL:** `gui/templates/results/resultsMoreBuilds.html?tproject_id=<id>&tplan_id=<id>`
**BFF API:** `api/reports/index.php` — `GET ?action=more_builds_init&tproject_id=&tplan_id=` and `GET ?action=more_builds&...`
**Rights:** `testplan_metrics` (global role and project context)
**Tracking issue:** [#838](https://github.com/sebiboga/testlink-upgraded/issues/838)

---

## Overview

* The header carries the test plan context; the toolbar shows the test project name and a **Run Query** button plus a locale switcher.
* A **Query Parameters** panel collects every filter the legacy `resultsMoreBuildsGUI.tpl` form offered: multi-select **Builds** (open + closed), multi-select top-level **Test Suites**, **Platforms**, **Keyword** (with `Any`), **Last Result** statuses, **Owner**, **Executor**, **Search in notes**, a **Start/End date** range, and a **Results** selector (`All results` / `Latest results`).
* Four display toggles (**Display suite summaries / Display test cases / Display query parameters / Display totals**) mirror the legacy checkboxes.
* **Run Query** re-renders the matrix from the selected filters.

## The matrix

The matrix has one column per selected build and one row per retained test case (external id + name + suite). Each cell is the test case version's latest execution status on that build, colored with the standard status palette (passed/failed/blocked/not-run).

* **Totals** — one block per selected build with the per-status counter (Not Run / Passed / Failed / Blocked / All / Not Available / Unknown) and the retained-test-case total. Totals are computed only over the test cases kept by the *lastStatus* filter so the numbers stay consistent with the visible matrix.
* **Suite Summaries** — one small table per top-level suite with the same per-status totals.
* **Query Parameters recap** — the *User-selected query parameters* box repeats what was run (test plan, builds, suites, keyword, owner, last-result statuses, executor, search-in-notes, results mode).

## BFF API

`GET /api/reports/index.php?action=more_builds_init&tproject_id=<id>&tplan_id=<id>`

Returns the filter vocabulary: open builds + closed builds, top-level test suites, linked platforms, keywords (index 0 = `Any`), users for owner/executor, status options in results-config order and their server-localized labels, plus the default date range (`start_date_offset` / `start_time`).

`GET /api/reports/index.php?action=more_builds&tproject_id=<id>&tplan_id=<id>&builds[]=&testsuites[]=&platforms[]=&keyword_id=&status[]=&owner=&executor=&search_notes=&start_date=&end_date=&display_*=&results=`

Rebuilds the per-test-case × per-build last-status matrix from `tlTestPlanMetrics::getExecStatusMatrixFlat()` (the same source the legacy matrix report used), aggregating per `(tcversion, build)` keeping the highest `executions_id`, then applies the suite/platform/keyword/last-status/owner/executor/search-in-notes/date filters and the display toggles.

> **Discovery:** the legacy `resultsMoreBuilds.php` report-query block is *dead code* (the `newResults` calls are commented out), so the legacy screen rendered an empty table. This BFF rebuilds the feature from the flat metrics rows instead of porting dead code. In the initial aggregation, only *one* build per platform+test-case survived (`latestExec`), so cells were wrong; the fix aggregates from the flat rows per `(tcase, build)`.

Error handling: missing/invalid test plan id ⇒ HTTP 400; no session ⇒ 401; missing `testplan_metrics` ⇒ 403.

## No-build guard

If the user deselects every build and runs the query, the page shows the toast *At least one build must be selected.* and does not render a report (legacy parity).

## i18n

`rmb.*` keys in all 10 locale bundles (en de es fr it ja pt ro ru zh); status column/cell labels are resolved server-side via `lang_get()` so they follow the session locale, exactly like the other modernized report screens.

## Testing

13-case suite (Suite 838) in `tmp/TLU_Test_Cases.md`: init context + filter options, default multi-build matrix (6 TC × 3 build cell parity), per-build totals, per-suite summaries, query-params recap, single-build + last-status filter, no-build guard, display toggles, no-rights user (403), invalid plan (400), locale switch, no console errors, Event Viewer clean — 13/13 PASS.
