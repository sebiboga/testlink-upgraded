# Results by Issues — Modernized Screen

The **Results by Issues** reports show test cases with linked bugs from the issue tracker. Two variants share one screen: "Latest Generation" (only latest executions) and "All Executions" (every execution with bugs). It replaces the legacy 1.9.20 `lib/results/resultsBugs.php` HTML view with a modern Dashio-styled page backed by a plain-PHP REST BFF.

**Path:** ASIDE menu → Reports → *Results / Issues by Test Plan* / *Results / Issues by Test Plan - All Builds*
**URL:** `gui/templates/results/resultsBugs.html?tproject_id=<id>&tplan_id=<id>&type=0|1`
**BFF API:** `api/reports/index.php` — `GET ?action=results_bugs&type=0|1`
**Rights:** `testplan_metrics` (same right the legacy controller enforces)
**Tracking issue:** [#763](https://github.com/sebiboga/testlink-upgraded/issues/763)

---

## 1. Overview

Two report variants share one screen:

| Variant | `type` param | Title | Behavior |
|---------|-------------|-------|----------|
| Latest Generation | `0` | Results / Issues by Test Plan | Shows only latest generation executions with bugs |
| All Executions | `1` | Results / Issues by Test Plan - All Builds | Shows all executions with bugs (may include duplicates) |

The "All Executions" variant shows a warning hint that some test cases may appear multiple times.

---

## 2. Screen Layout

| Element | Description |
|---------|-------------|
| **Header** | Teal banner with "Results by Issues" title, "for test plan" label, plan name, locale switcher |
| **Toolbar** | Test project name, report type dropdown (Latest Generation / All Executions) |
| **Summary cards** | Four cards: Open Bugs, Resolved Bugs, Total Bugs, TCs with Bugs |
| **Report body** | DataTable with Test Suite, Test Case, and Bugs columns; grouped by test suite |
| **Footer** | Generation timestamp, elapsed time, and explanatory italic info paragraph ("This report shows all bugs linked to test cases during execution.") |

---

## 3. Data Flow and Parity

| Legacy behavior | Modernized behavior |
|-----------------|---------------------|
| `resultsBugs.php?type=0|1` loads via Smarty | `resultsBugs.html?type=0|1` renders standalone HTML+JS |
| PHP template builds HTML table | DataTables jQuery plugin renders the table |
| Data from `getLTCVNewGeneration()` (type=0) or `getAllExecutionsWithBugs()` (type=1) | Same backend calls via BFF |
| Bug links via `get_bugs_for_exec()` | Same backend call via BFF |
| Legacy echoed `link_to_bts` (an **HTML fragment** from `buildViewBugLink()`) straight into the cell | BFF returns that fragment as `bugs[].link` (unchanged, for HTML consumers) **and** the bare tracker URL as `bugs[].url`; the screen anchors on `url` behind a scheme guard — **Fixes #1837** |
| ExtTable with group-by | DataTable with sorting, pagination, search |
| `resultsBugs.tpl:70` renders `<p class="italic">{$labels.info_bugs_per_tc_report}</p>` below the table (report description) | Modern footer renders the equivalent italic info paragraph via `rb.infoReport` (`<p class="info">`) next to the generated-on line — **Refs #1271** |

---

## 4. Report Type Switcher

| Type | Description |
|------|-------------|
| **Latest Generation** (default) | Only shows test cases from their most recent execution — no duplicates |
| **All Executions** | Shows every execution that has linked bugs — test cases may appear multiple times across builds |

---

## 5. i18n

All labels use the `rb.*` i18n key namespace (14 keys per bundle). The screen uses the shared `TLi18n` module for locale loading and switching. Keys are defined in all 10 locale bundles (`en.json`, `ro.json`, etc.).

The footer report-description paragraph uses `rb.infoReport` — added for all 10 locales in **Refs #1271** (en: "This report shows all bugs linked to test cases during execution."; legacy text sourced from `locale/en_GB/strings.txt:1834` `$TLS_info_bugs_per_tc_report`; de/es/fr/ja/pt/zh reuse the legacy translations, ro/it/ru are newly authored).

Column headers reuse existing i18n keys: `title_test_suite_name`, `title_test_case_title`, `title_test_case_bugs`.

---

## 6. BFF API Reference

### GET `?action=results_bugs`

Returns test cases with linked bugs for a test plan.

**Parameters:**
- `tproject_id` (int, required)
- `tplan_id` (int, required)
- `type` (int, optional): `0` for latest generation (default), `1` for all executions

**Response (200):**
```json
{
  "status": "ok",
  "tproject_id": 1,
  "tplan_id": 2,
  "tproject_name": "Test Project",
  "tplan_name": "Test Plan 1",
  "type": 0,
  "verbose_type": "latest",
  "title_key": "link_report_total_bugs",
  "bug_interface_on": true,
  "total_open_bugs": 5,
  "total_resolved_bugs": 3,
  "total_bugs": 8,
  "total_cases_with_bugs": 6,
  "rows": [
    {
      "tc_id": 10,
      "tc_name": "Login Test",
      "full_external_id": "TP-1",
      "tsuite_name": "Authentication",
      "external_id": 1,
      "bugs": [
        {
          "bug_id": "BUG-123",
          "link": "<div title=\"Access issue tracking system\" style=\"background: #ffa0a0;\"><a href='https://bts.example.com/BUG-123' target='_blank'>BUG-123 : [New issue]</a></div>",
          "url": "https://bts.example.com/BUG-123",
          "is_resolved": false,
          "build_name": "Build 1"
        }
      ]
    }
  ],
  "has_data": true,
  "elapsed_time": 0.01
}
```

---

## 7. Permission Path and Empty State

**Rights check:** BFF validates `testplan_metrics` right (global + contextual per-project/per-plan) before any data query.

**Empty states:**
- No test plan configured → HTTP 400 error
- No issue tracker enabled → "No linked bugs found for this report."
- Issue tracker enabled but no bugs found → Same empty state message

---

## 8. Footer Info Paragraph (Refs #1271)

The legacy report footer always renders an explanatory italic line between the result table and the generated-on line
(`resultsBugs.tpl:70` → `<p class="italic">{$labels.info_bugs_per_tc_report}</p>`,
text: *"This report shows all bugs linked to test cases during execution."*). The modern screen renders the same line:

- Modern: `#footerInfo` HTML appends `<p class="info">` with `rb.infoReport` after the `Generated on: … | Elapsed seconds: N` line (`resultsBugs.html:207-212`).
- The paragraph is escaped via the screen's `esc()` helper and translated with `TLi18n.t('rb.infoReport')` in all 10 locales.
- Mirrors the established sibling pattern from `resultsMatrix.html` (`rsm.infoReport`) and `absoluteLatest.html` (`alx.infoReport`).

---

## 9. Files

| File | Purpose |
|------|---------|
| `gui/templates/results/resultsBugs.html` | Standalone HTML+JS+CSS screen (~230 lines) |
| `api/reports/index.php` | BFF API — `results_bugs` action (lines ~3820–3971) |
| `lib/general/asideMenu.php` | ASIDE link switch for `link_report_total_bugs` / `link_report_total_bugs_all_exec` |
| `gui/templates/i18n/*.json` | i18n locale bundles (16 `rb.*` keys per bundle, incl. `rb.infoReport` — Refs #1271 and `rb.executionHistory` / `rb.testCaseDesign` — Refs #1269) |
| `lib/results/resultsBugs.php` | Legacy controller (still exists but no longer linked from ASIDE) |
| `tmp/fixtures_1271.php` | Reproducible fixture: RB1271 project/plan, 2 executions with linked bugs via local mantisdb tracker |
| `tmp/fixtures_1269.php` | Reproducible fixture for the TC icon pair: RB1269 project/plan, 3 executions, 2 with linked bugs (Refs #1269) |

---

## 10. Per-Test-Case Execution-History + Design Icons (Refs #1269)

### Legacy behavior
`lib/results/resultsBugs.php:89-96` — for every test case that carries at least one linked bug
the controller built the Test Case cell as **two icon links** prepended to `PREFIX-id: name`:

```php
$exec_history_link = "<a href=\"javascript:openExecHistoryWindow({$tc_id});\">" .
                     "<img title=\"" . $l18n['execution_history'] . "\" src=\"{$img['history']}\" /></a> ";
$edit_link = "<a href=\"javascript:openTCEditWindow({$tc_id});\">" .
             "<img title=\"" . $l18n['design'] . "\" src=\"{$img['edit']}\" /></a> ";
```

with the labels initialized at `lib/results/resultsBugs.php:73`. The row was therefore clickable
twice: the execution history of that test case and its design.

### Modern behavior (before)
`gui/templates/results/resultsBugs.html` rendered the Test Case column as inert plain text — the
BFF already shipped `tc_id` on every row (`api/reports/index.php`, `rows[].tc_id`) and the JS
never used it, so a user could not reach the execution history or the design of a test case with bugs.

### The fix
* New `tcCell(row)` in `gui/templates/results/resultsBugs.html` renders the pair with the Dashio
  icon set (`fa-clock-rotate-left`, `fa-pen-to-square`, teal `#4ECDC4`) followed by
  `PREFIX-id: name`, matching the sibling modernized report `resultsMatrix.html:346-360`.
* Targets (both already modernized screens):
  * `/gui/templates/execute/execHistory.html?tcase_id=<tc_id>&tproject_id=<project>` — popup `history_popup` (900x650)
  * `/gui/templates/testcases/tcEdit.html?tcase_id=<tc_id>&tproject_id=<project>` — popup `tcEdit_<tc_id>` (900x700), contract from `tplanWithCF.html:128`
  * The legacy window helpers (`gui/javascript/testlink_library.js:1726` / `:913`) do not exist in
    the modern shell, and `openExecHistoryWindow()` concatenated `&tproject_id=` + an **undeclared**
    variable (→ `tproject_id=undefined`, documented at `tcNotRunAnyPlatform.html:139-144`), so both
    popups are addressed directly and `tproject_id` is always sent.
* The renderer branches on `type !== 'display'`: the icons exist only in the display cell, so
  DataTables sorting and the column filter keep working on the plain text.
* Legacy dead markup `"<!-- 0000000001 -->"` (zero-padded `external_id` as an HTML comment) is
  intentionally **not** ported; the value is still delivered as `rows[].external_id`.
* i18n: `rb.executionHistory` + `rb.testCaseDesign` in **all 10** locale bundles.
* **Bonus fix**: the old renderer read `row.name`, but the BFF emits `tc_name` — the test case
  name was rendered as an empty string (`RB-1:`). `tcCell()` reads `row.tc_name || row.name`.

### Verified
12/12 test cases PASS (suite `## Task — Issue #1269` in `tmp/TLU_Test_Cases.md`), fixture
`tmp/fixtures_1269.php` (project `RB1269`, plan `RB Plan`, 3 executions, local `mantis_bug_table`
so the DB-API tracker resolves links and resolved state without any remote service).
History popup shows `RB-1 - Execution History` / `Executions(1)`; design popup shows
`Edit Test Case - login broken`. No new Error/Warning entries in the Event Viewer.

---

## 11. Bugs Column Links (Fixes #1837)

### Symptom (measured pre-fix)
Every bug in the *Bugs* column carried an `href` that was the issue tracker's **HTML fragment**,
percent-encoded and resolved against the current directory, so clicking a bug landed on a TestLink
**404** (`fetch(anchor.href)` → `status: 404`) — the report's main column was unusable. No console
error and no Event Viewer row, so the defect was silent.

```
pre-fix : link " 101" url="http://localhost:8082/gui/templates/results/%3Cdiv%20%20title=%22Access%20issue%20tracking%20system%22…%3C/div%3E"
post-fix: link " 101" url="http://mantis.local/view_bug.php?bug_id=101"
```

### Root cause
1. `issueTrackerInterface::buildViewBugLink()` returns an HTML fragment, not a URL
   (`issueTrackerInterface.class.php:365` builds the anchor, `:437-442` wraps it in a status-coloured
   `<div>`); `lib/functions/exec.inc.php:449-453` stores it as `link_to_bts`. Legacy screens echoed it
   as HTML (`lib/results/resultsBugs.php:204`) — correct there.
2. `api/reports/index.php` (`action=results_bugs`) copied the fragment into a neutrally named `link`
   field and exposed **no** URL, so the field looked like one.
3. `renderBugLinks()` escaped the fragment into `href`.

The bare URL was always available but never left the tracker object:
`issueTrackerInterface::buildViewBugURL()` (`issueTrackerInterface.class.php:485-488`), inherited or
overridden by every tracker.

### The fix
* **BFF** `api/reports/index.php`: new `bugViewUrl($its, $bugId, $linkHtml)` helper returns the bare
  tracker URL via `buildViewBugURL()` — guarded by `is_object()` + `method_exists()` + `try/catch(Throwable)`
  — and falls back to extracting the `href` from the fragment, then to `''`. Each bug entry now carries
  `bugs[].url` next to the untouched `bugs[].link`.
* **Screen** `gui/templates/results/resultsBugs.html`: new `safeHttpUrl()` accepts only `http://`,
  `https://`, `//host/…`, `/path`; `renderBugLinks()` anchors on `b.url` through it, adds
  `rel="noopener"` and degrades to a `<span class="bug-link">` (no href) when no usable URL exists.
  Resolved styling (`.bug-link.resolved`) is unchanged.

### Verified
Suite `## Regression — Issue #1837` in `tmp/TLU_Test_Cases.md`; fixture `php tmp/fixtures_1269.php`
(project `RB1269`, plan `RB Plan`, local `mantis_bug_table`). Live URLs for `type=0` and `type=1`,
resolved/open styling kept, `bugs[].link` unchanged, 11 client + 7 PHP guard cases, console and Event
Viewer clean, sibling `resultsByStatus` (which deliberately echoes the fragment as HTML) unaffected.

A code review subagent's BLOCKER findings were checked against the committed code and did not
reproduce (they described an `ENT_HTML5` decode, a `new URL()` guard and a `$bug['links']`
shadowing loop, none of which are in this diff). Its one valid suggestion was folded in:
`bugViewUrl()` applies the same scheme allow-list server-side before publishing `bugs[].url`, so
the API can never ship `javascript:`/`data:`/markup to a future consumer.

Detail page: [`Bugfix-Issue-1837-resultsBugs-BugLink-Href.md`](Bugfix-Issue-1837-resultsBugs-BugLink-Href.md).
