# Execution Timeline Statistics — Modernized Screen

Modernization of **Execution Timeline Statistics** (`lib/results/execTimelineStats.php`)
— GitHub issue [#762](https://github.com/sebiboga/testlink-upgraded/issues/762).

The legacy Smarty screen is replaced by a standalone Dashio page
(`gui/templates/results/execTimelineStats.html`) backed by a plain-PHP REST BFF
action inside `api/reports/index.php` (`action=exec_timeline`). The ASIDE
**Reports → Execution Timeline Statistics** entry now points to the new HTML
screen when a test plan is selected.

**Path:** ASIDE menu → Reports → *Execution Timeline Statistics*
**URL:** `gui/templates/results/execTimelineStats.html?tproject_id=<id>&tplan_id=<id>`
**BFF API:** `api/reports/index.php?action=exec_timeline&tproject_id=<id>&tplan_id=<id>&group=<day|month|day_hour>`
**Rights:** `testplan_metrics` enforced server-side (same as legacy).
**Tracking issue:** [#762](https://github.com/sebiboga/testlink-upgraded/issues/762)

---

## Overview

The screen displays execution activity over time for the selected test plan, grouped into three views:

| Group mode | Columns | Description |
|---|---|---|
| Day (default) | Date, Qty, Number of Testers | One row per day with execution count |
| Month | Year/Month, Qty, Number of Testers | One row per month, aggregated totals |
| Day + Hour | Date, Hour, Qty, Number of Testers | One row per date-hour slot |

All three views use the same underlying query via `tlTestPlanMetrics::getExecTimelineStats()` with `workforce=true`.

## Toolbar actions (legacy parity)

* **Group by buttons** – Day, Month, Day + Hour — each triggers AJAX reload via BFF API.
* **Refresh** – re-fetches data from BFF API.
* **Export as Spreadsheet** – delegates to legacy `execTimelineStats.php?format=xls&spreadsheet=1`.
* **Send report by e-mail** – delegates to legacy `execTimelineStats.php?format=mail_html`.

## Empty & error states

* Plan without executions → friendly "No execution data available" info panel.
* Missing rights → localized "Insufficient rights" message.
* Unknown tplan → empty shell with feedback line.

## Legacy bug fixed during modernization

The `day_hour` grouping had a data-structure issue in `getExecTimelineStats()`: the legacy workforce merge
sets `$rs[date]['testers']` at the date level instead of at the hour level, creating a spurious entry
that would render as a non-hour row in the Day + Hour view. The BFF flattens the nested structure and
filters out the spurious `'testers'` key.
