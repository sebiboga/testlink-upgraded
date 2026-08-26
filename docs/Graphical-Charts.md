# Graphical Charts — Modernized Screen

The **Graphical Charts** report (ASIDE → Reports → *Charts*) displays overall execution metrics as client-side rendered charts (pie + stacked bar) instead of the legacy server-side pChart PNG images. It replaces the legacy 1.9.20 `lib/results/charts.php` with a modern Dashio-styled page backed by a plain-PHP REST BFF.

**Path:** ASIDE menu → Reports → *Charts*
**URL:** `gui/templates/results/charts.html?tproject_id=<id>&tplan_id=<id>`
**BFF API:** `api/reports/index.php` — `GET ?action=charts_data&tproject_id=&tplan_id=`
**Rights:** `testplan_metrics`
**Tracking issue:** [#690](https://github.com/sebiboga/testlink-upgraded/issues/690)

---

## Overview

The header carries the test plan context; the toolbar shows the test project name and elapsed time. The screen renders up to 4 chart sections using **Chart.js** (client-side, no GD/pChart dependency):

| Section | Chart Type | Data Source | Condition |
|---------|-----------|-------------|-----------|
| Overall Metrics | Pie | `getExecCountersByExecStatus()` | Always shown (if data exists) |
| Overall Metrics by Platform | One pie per platform | `getStatusTotalsByPlatformForRender()` | Only when plan has linked platforms |
| Results by Keyword | Stacked bar | `getStatusTotalsByKeywordForRender()` | Only when keywords are linked |
| Results by Top-Level Test Suite | Stacked bar | `getStatusTotalsByTopLevelTestSuiteForRender()` | Always shown (if data exists) |

## Empty states

* **No chart data** — "No chart data available for this test plan." with a chart icon.
* Individual sections hide automatically when their data source returns empty.

## Key differences from legacy

* **Client-side rendering** — Chart.js replaces server-side pChart + GD PNG generation
* **No GD dependency** — the legacy `checkLibGd()` guard is irrelevant
* **Single BFF call** — one AJAX request returns all chart data as JSON (vs 5 separate PHP scripts generating images)
* **Interactive** — charts support hover tooltips with percentage breakdowns
* **Responsive** — charts resize with the viewport

## BFF API

```
GET /api/reports/index.php?action=charts_data&tproject_id=1&tplan_id=2
```

**Response:**
```json
{
  "status": "ok",
  "tproject_name": "My Project",
  "tplan_name": "My Plan",
  "charts": {
    "overall": { "labels": ["Not Run", "Passed", "Failed"], "values": [5, 3, 1], "colors": ["#000", "#006400", "#B22222"] },
    "platforms": [{ "name": "Platform A", "labels": [...], "values": [...], "colors": [...] }],
    "by_keyword": { "labels": ["K1", "K2"], "datasets": [{"label":"Not Run","data":[2,3],"backgroundColor":"#000"}] },
    "by_suite": { "labels": ["Suite A"], "datasets": [{"label":"Passed","data":[5],"backgroundColor":"#006400"}] }
  },
  "elapsed_time": 0.02
}
```

## How it works

The BFF calls the same `tlTestPlanMetrics` methods the legacy pChart scripts used, serializes the data as JSON, and the frontend renders it with Chart.js. Status colors come from `$tlCfg->results['charts']['status_colour']` (cfg/const.inc.php).

## Test results (2026-08-26)

| # | Test | Result |
|---|------|--------|
| 1 | Aside link switches to modernized page | PASS |
| 2 | Header shows plan/project names | PASS |
| 3 | Overall Metrics pie chart renders | PASS |
| 4 | BFF returns correct JSON structure | PASS |
| 5 | BFF data matches executions | PASS |
| 6 | Colors match TestLink config | PASS |
| 7 | Locale switcher works | PASS |
| 8 | No console errors | PASS |
| 9 | No network errors | PASS |
| 10 | Empty plan shows graceful handling | PASS |
| 11 | getRootTestSuites crash guard | PASS |
| 12 | Rights gate enforced | PASS |
| 13 | Event Viewer clean | PASS |

**Actual result:** 13/13 PASS.

**Bug fixed:** `lib/functions/testplan.class.php` — `getRootTestSuites()` now returns early on empty `$items`/`$tlnodes` instead of generating invalid `WHERE id IN ()` SQL. The BFF also wraps the call in try/catch as a safety net.
