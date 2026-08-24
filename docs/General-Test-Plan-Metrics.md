# General Test Plan Metrics — Modernized Screen

The General Test Plan Metrics report shows execution status totals for the active
test plan, split by platform, build, top level test suite, priority and keyword,
plus the Milestones & Priority report. It replaces the legacy 1.9.20
`lib/results/resultsGeneral.php` HTML view with a modern Dashio-styled page backed
by a plain-PHP REST BFF. The legacy "send by e-mail" and "export as spreadsheet"
actions are preserved as-is: their buttons POST to the same legacy controller
endpoints (document/mail/XLS generation stays in `lib/results/resultsGeneral.php`).


**Path:** ASIDE menu → Reports → General Test Plan Metrics
**URL:** `gui/templates/results/generalMetrics.html?tproject_id=<id>&tplan_id=<id>` (loaded inside the main frame)
**BFF API:** `api/reports/index.php` — `GET ?action=metrics_general&tproject_id=&tplan_id=`
**Rights:** `testplan_metrics` (same right the legacy controller enforces via `checkRights()`; BFF returns 403 otherwise)
**Tracking issue:** #618 · **Legacy bug fixed along the way:** #670

---

## Table of Contents

1. [Screen Layout](#1-screen-layout)
2. [Report Sections](#2-report-sections)
3. [Toolbar Actions (legacy parity)](#3-toolbar-actions-legacy-parity)
4. [i18n](#4-i18n)
5. [Permission Path and Empty State](#5-permission-path-and-empty-state)
6. [BFF API Reference](#6-bff-api-reference)
7. [Legacy Bug Fixed (#670)](#7-legacy-bug-fixed-670)
8. [Files](#8-files)

---

## 1. Screen Layout

| Section | Description |
|---------|-------------|
| **Header** | Teal Dashio header "General Test Plan Metrics — for test plan X" with locale switcher |
| **Toolbar** | Dark bar: test project context, *Send test report by e-mail* (legacy POST), *Export data as spreadsheet* (legacy POST), *Refresh* |
| **Notice** | "Important Notice" platform-relationship box shown only when the plan has platforms (legacy `important_notice`) |
| **Report sections** | One white card per metrics section, each rendering the classic first-column / TOTAL / per-status qty + [%] table layout with server-localized status labels |
| **Footer** | "Generated on \<timestamp\> · Elapsed seconds: n" |

## 2. Report Sections

Exactly the same calls, in the same order, as the legacy controller:

1. **Results by Platform** (`getStatusTotalsByPlatformForRender`) — only when the plan has platforms.
2. **Overall Build Status** (`getOverallBuildStatusForRender`, total key `total_assigned`)
   plus the legacy note that only active builds with tester-assigned TCs are displayed.
3. **Results by Build on Platform** (`getBuildByPlatStatusForRender`) — one sub-table per platform.
4. **Results by Top Level Test Suite** (`getStatusTotalsByTopLevelTestSuiteForRender`,
   `groupByPlatform=1`) — one sub-table per platform, with the legacy info line.
5. **Results by priority** (`getStatusTotalsByPriorityForRender`, `getOnlyAssigned=false`)
   — only when the project option *testPriorityEnabled* is set.
6. **Results by Keyword** (`getStatusTotalsByKeywordForRender`, `groupByPlatform=1`).
7. **Status of Milestones** (`getMilestonesMetrics`) — two variants exactly like the
   legacy template:
   - priority enabled: High/Medium/Low result-vs-goal pairs + overall progress,
     incomplete cells highlighted red;
   - priority disabled: Total / Completed / progress / goal columns.


## 3. Toolbar Actions (legacy parity)

| Button | Behaviour |
|--------|-----------|
| Send test report by e-mail | POST form to `lib/results/resultsGeneral.php?format=6&tplan_id=N` with hidden `sendByEmail=1` (unchanged legacy flow) |
| Export data as spreadsheet | POST form to `lib/results/resultsGeneral.php?format=3&tplan_id=N&spreadsheet=1` (unchanged legacy flow) |
| Refresh | Re-fetches `metrics_general` and re-renders without page navigation |

## 4. i18n

All labels/messages come from the client-side `TLi18n` module using the new
`rgm.*` key family (43 keys) present in ALL 10 locale bundles
(en/ro/de/fr/es/it/pt/ru/ja/zh). Status column headers use the server-provided
labels from TestLink's own localization (same strings the legacy tables show).
The locale switcher in the header switches bundles live:


## 5. Permission Path and Empty State

- User without `testplan_metrics`: BFF answers `403 {"message":"No permission"}`,
  the screen shows the warnbox + toast.
- Plan without linked test cases: BFF answers `hasData=false`, screen shows the
  legacy empty-state message (`report_tspec_has_no_tsuites`).


## 6. BFF API Reference

`GET /api/reports/index.php?action=metrics_general&tproject_id=<id>&tplan_id=<id>`
(session auth + same-origin guard + `testplan_metrics` check). Response carries:

- context: `tproject_name`, `tplan_name`
- flags: `has_data`, `show_platforms`, `priority_enabled`, ordered `platform_set`
- per-section payloads `{info, columns}` reusing the exact render objects of
  `tlTestPlanMetrics` → numbers are 1:1 with legacy
- legacy export URLs: `send_mail_url`, `export_xls_url`

Errors: `400 Missing test plan id` / invalid ids, `401` not authenticated,
`403 No permission`.

## 7. Legacy Bug Fixed (#670)

While testing the XLS export path, 5 × E_WARNING `Undefined array key "total_tc"`
were raised by the legacy include `inc_results_show_table.tpl`: it hardcodes
`$res.total_tc` although the Overall-Build-Status / Results-by-Build includes pass
`args_column_for_total='total_assigned'`. The include now honours the parameter
(same pattern its sibling includes already used). Fixed in commit
`fix(legacy): inc_results_show_table honors args_column_for_total…` — Fixes #670.

## 8. Files

| File | Purpose |
|------|---------|
| `gui/templates/results/generalMetrics.html` | Modernized screen |
| `api/reports/index.php` | Reports BFF (+ `metrics_general` action) |
| `lib/general/asideMenu.php` | Reports menu entry switch for `link_report_general_tp_metrics` |
| `gui/templates/i18n/*.json` | `rgm.*` keys ×10 bundles |
| `gui/templates/dashio/results/inc_results_show_table.tpl` | #670 fix |
| `tmp/TLU_Test_Cases.md` | Suite 618 — 16/16 PASS |
