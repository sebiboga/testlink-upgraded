# Metrics Dashboard — Docs (Wiki Mirror)

> Mirror of the GitHub Wiki page [Metrics-Dashboard](https://github.com/sebiboga/testlink-upgraded.wiki/blob/master/Metrics-Dashboard.md). Image lines omitted per project convention.
# Metrics Dashboard — Wiki

The Metrics Dashboard shows execution progress for the whole test project and per
test plan (optionally split by platform). It replaces the legacy 1.9.20
`lib/results/metricsDashboard.php` (ExtJS progress bars + Ext table) with a modern
Dashio-styled page backed by a plain-PHP REST BFF.


**Path:** ASIDE menu → Results → Metrics Dashboard
**URL:** `gui/templates/results/metricsDashboard.html?tproject_id=<id>` (loaded inside the main frame)
**BFF API:** `api/metrics/index.php` — `GET /meta/rights`, `GET /dashboard?show_only_active=0|1[&tproject_id=]`
**Rights:** `testplan_metrics` OR `testplan_execute` (same OR-check as the legacy `checkRights()`)

---

## Table of Contents

1. [Screen Layout](#1-screen-layout)
2. [Project Progress](#2-project-progress)
3. [Test Plan Progress Table](#3-test-plan-progress-table)
4. [Filters and Interactions](#4-filters-and-interactions)
5. [i18n](#5-i18n)
6. [Permission Path and Empty State](#6-permission-path-and-empty-state)
7. [BFF API Reference](#7-bff-api-reference)
8. [Legacy Bugs Fixed](#8-legacy-bugs-fixed)
9. [Files](#9-files)

---

## 1. Screen Layout

| Section | Description |
|---------|-------------|
| **Header** | Teal Dashio header "Metrics Dashboard — execution progress overview" with locale switcher |
| **Toolbar** | Dark bar: *Show only active test plans* checkbox + *Public link* toggle |
| **Test Project Progress** | Colored progress bars, one per execution status + overall executed |
| **Test Plan Progress** | DataTable: one row per test plan × platform with status qty/% columns and a progress mini-bar |
| **Footer** | "Generated on \<timestamp\>" |

## 2. Project Progress

Progress bars mirror the legacy `collectTestProjectMetrics()`: overall executed plus one
bar per status from `$tlCfg->results['status_label_for_exec_ui']`
(Not Run / Passed / Failed / Blocked). Each bar is annotated with `[executed/active]`,
where active = active test cases linked to the visible test plans. Percentages are
rounded to `$tlCfg->dashboard_precision`, exactly like the legacy screen.


## 3. Test Plan Progress Table

One row per test plan (per platform when the plan uses platforms):

- Test Plan name (localized "n/a" platform column when the plan has no platforms)
- Active TCs (only plans with ACTIVE builds are considered, like legacy)
- Per status: quantity and percentage columns
- Progress % mini-bar; default sort: Progress % DESC (legacy `setSortByColumnName(progress) DESC`)

Data comes 1:1 from the same metrics managers used by legacy:
`tlTestPlanMetrics::getExecCountersByPlatformExecStatus()` (platform branch) and
`getExecCountersByExecStatus()` (no-platform branch), with
`getOnlyActiveTCVersions => true`.

## 4. Filters and Interactions

- **Show only active test plans** — checkbox drives `show_only_active`, persisted in the
  session with the same tri-state semantics as legacy (`$_SESSION['show_only_active']`).
- **Public link** — toggles the legacy public deep link
  (`lnl.php?type=metricsdashboard&apikey=<project api key>`).
- DataTable search / sorting / paging work as on other modernized screens.


## 5. i18n

All strings come from the client-side `TLi18n` module — 21 new `md.*` keys added to ALL
10 locale bundles (`en`, `de`, `es`, `fr`, `it`, `ja`, `pt`, `ro`, `ru`, `zh`). Status
column headers are generated dynamically from the server status set with localized labels.

## 6. Permission Path and Empty State

- Users lacking both rights see an error box ("You do not have the rights needed…");
  the BFF answers 403.
- Projects without accessible test plans (or none with builds) show the legacy warning
  "No test plans available for the current test project!" instead of the tables.

## 7. BFF API Reference

| Endpoint | Description |
|----------|-------------|
| `GET /meta/rights` | `{canView, showTestPlanStatus, precision}` |
| `GET /dashboard?show_only_active=&tproject_id=` | Full payload: `tproject_name`, `show_only_active`, `show_platforms`, `status_set`, `direct_link`, `warning`, `project_metrics[]`, `testplans[]`, `generated_on` |

Session-based auth (401 JSON otherwise); explicit `?tproject_id=` wins over the session
project, mirroring legacy `R_PARAMS`.

## 8. Legacy Bugs Fixed

- **#559** — `tlTestPlanMetrics::getExecCountersByExecStatus()` always returned NULL
  (`is_array($builds)` check against a stdClass), so every plan WITHOUT platforms showed
  zeros on the legacy dashboard as well.
- **#561** — PHP8 E_WARNINGs in `buildExecStatusMatrix()` (lines 1637/1639/1641):
  unguarded array access on NULL returned by `get_full_path_verbose()`.

## 9. Files

- `gui/templates/results/metricsDashboard.html` — screen (HTML+CSS+JS)
- `api/metrics/index.php` — REST BFF
- `lib/functions/common.php` — aside link switched to the new screen
- `lib/functions/tlTestPlanMetrics.class.php` — bug fixes #559 / #561
- `gui/templates/i18n/*.json` — `md.*` keys
