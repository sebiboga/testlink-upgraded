# Execution Timeline Statistics — Modernized Screen

Modernization of **Execution Timeline Statistics** (`lib/results/execTimelineStats.php`)
— GitHub issue [#762](https://github.com/sebiboga/testlink-upgraded/issues/762),
parity verified in-screen-compare issue [#1273](https://github.com/sebiboga/testlink-upgraded/issues/1273).

The legacy Smarty screen is replaced by a standalone Dashio page
(`gui/templates/results/execTimelineStats.html`) backed by a plain-PHP REST BFF
action inside `api/reports/index.php` (`action=exec_timeline`). The ASIDE
**Reports → Execution Timeline Statistics** entry now points to the new HTML
screen when a test plan is selected.

**Path:** ASIDE menu → Reports → *Execution Timeline Statistics*
**URL:** `gui/templates/results/execTimelineStats.html?tproject_id=<id>&tplan_id=<id>` (add `&apikey=<key>` for public-link access)
**BFF API:** `api/reports/index.php?action=exec_timeline&tproject_id=<id>&tplan_id=<id>&group=<day|month|day_hour>`
**Rights:** `testplan_metrics` enforced server-side (same as legacy; anonymous apikey access skips it — `addOpAccess=false` like legacy).
**Tracking issue:** [#762](https://github.com/sebiboga/testlink-upgraded/issues/762)
**Cleanup issue:** [#1274](https://github.com/sebiboga/testlink-upgraded/issues/1274) — delete legacy `execTimelineStats.php` + its 2 dashio templates.

---

## Public-link / apikey anonymous access (Refs #1273)

Legacy `initArgsForReports()` (`lib/results/displayMgr.php:16-80`) accepts:
- a **32-char** apikey → remote access for the owning user (right-checked).
- a **64-char** apikey bound to a testplan/testproject → anonymous access (no rights).

The BFF now mirrors this: `exec_timeline` is allowlisted in `$apikeyActions`
(`api/reports/index.php`), the contextual `testplan_metrics` check is guarded
with `!$isAnon`, export/mail URLs keep the apikey, and the payload carries
`is_anon`. The modern screen forwards `apikey` from the URL and hides the
"Send by email" button for anonymous users (mirroring the legacy
`accessType == 'gui'` gate). The export gateway (`api/reportsexport/index.php`)
also accepts `exec_timeline_stats`/`exec_timeline_stats_mail` apikey actions and
forwards `spreadsheet=1` so the legacy proxy produces a real XLS.

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
filters out the spurious `'testers'` key, sourcing per-hour testers from `$rswf[date][hour]` instead
so the Number of Testers column is correct on every hour row (verified 10h: 3/2, 14h: 1/1, 09h: 2/2).

> Note: legacy hardcodes `group='day'`, so Month and Day + Hour are modern superset groups
> (same shared `tlTestPlanMetrics` engine, no legacy counterpart to diff).

## Parity verification (Refs #1273)

Verified on fixture `tmp/fixtures_etl.php` (proj 10 ETD / plan 18 ETL Plan, 6 executions): day group
byte-identical to legacy (2026-08-10 qty 4 / testers 2; 2026-08-11 qty 2 / testers 2), month and
day_hour superset groups correct, `testplan_metrics` enforced (role-3 user → 403 in BFF / home
redirect in legacy), anon apikey → 200 with mail hidden, remote 32-char → 200, unknown key → 401,
XLS export via gateway → real OLE workbook, mail form opens legacy proxy for logged-in users.

Screenshots: `docs/screenshots/issue-1273-exec-timeline-day.png` (Day view, signed-in),
`docs/screenshots/issue-1273-exec-timeline-anon.png` (Day view, anonymous apikey — no mail button).
