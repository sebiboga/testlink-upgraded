# Results by Tester per Build — Modernized Screen

The **Results by Tester per Build** report (ASIDE → Reports → *Results by Tester per Build*) lists execution progress and per-status counters for every tester who has assignments on a build: one card per build (the legacy ext-table "group by Build" view) with the build-level rollup in the header and one row per tester. It replaces the legacy 1.9.20 `lib/results/resultsByTesterPerBuild.php` ext-table screen with a modern Dashio-styled page backed by a plain-PHP REST BFF.

**Path:** ASIDE menu → Reports → *Results by Tester per Build*
**URL:** `gui/templates/results/resultsByTesterPerBuild.html?tproject_id=<id>&tplan_id=<id>`
**BFF API:** `api/reports/index.php` — `GET ?action=metrics_by_tester_per_build&tproject_id=&tplan_id=&show_closed_builds=0|1`
**Rights:** `testplan_metrics`
**Tracking issue:** [#677](https://github.com/sebiboga/testlink-upgraded/issues/677)

---

## Overview

* The header carries the test plan context; the toolbar shows the test project name.
* Every build with tester activity renders as its own section. The section head repeats the exact legacy first-row text:

  `<build name> - Progress <p>% - Total time (hh:mm:ss) <hh:mm:ss>`
* Per-build rollup is computed with the verbatim legacy math: `total`/`executed` summed over testers, `progress = round(executed/total*100, 2)`, `total_time` formatted through the same `bcmul()`-based `minutes2HHMMSS` algorithm (guarded against division by zero).
* Tester rows inside each build are ordered by progress descending (the legacy ext table's default sort on the Progress column).

| Column | Content |
|--------|---------|
| User | login; clicking opens the legacy assignment overview popup (`tcAssignedToUser.php?user_id&build_id&tplan_id`, resizable 800×600 window — same target as legacy `openAssignmentOverviewWindow()`) |
| Assigned | test cases assigned to this tester on this build (exec-task assignments) |
| Not Run / Passed / Failed / Blocked | qty + [%] per status, results-config display order, server-localized labels |
| Progress [%] | executed (non not-run) share of the tester's assigned cases, with an inline teal bar |
| Total time (hh:mm:ss) | sum of `execution_duration` of the tester's executions on that build |

## Show closed builds toggle (legacy parity)

The checkbox mirrors the legacy behavior exactly: when unchecked and the plan has no open builds, the page shows the *There are no open builds* warning instead of a table; when checked, closed builds join the report. The choice persists across reloads (legacy kept it in `$_SESSION['reports_show_closed_builds']`; the BFF updates the same session key from the request parameter).

## Empty states

* No open builds while the toggle is off → warning box (see above).
* Builds present but no tester assignments → *There are no tester assignments to OPEN builds in this testplan.* (legacy `no_testers_per_build` message).

## BFF API

`GET /api/reports/index.php?action=metrics_by_tester_per_build&tproject_id=<id>&tplan_id=<id>&show_closed_builds=0|1`

Key payload fields: `hasData`, `warning_key` (`no_open_builds` / `no_testers_per_build`), `builds` (ordered list `{id,name,progress,total_time,users:[{user_id,login,total,statuses:{<verbose>:{count,percentage}},progress,total_time}]}`), `columns` (status label map in display order), `assignment_url`. Data comes from the very same `tlTestPlanMetrics::getStatusTotalsByBuildUAForRender()` call the legacy controller makes — no reimplementation drift.

Permission handling mirrors legacy `checkRights()`: no session ⇒ 401; missing `testplan_metrics` on global role AND project context ⇒ 403 (users holding the right only via a per-project role pass, like `hasRightOnProj()`). A fix landed during testing: the user-name map must be fetched via the authenticated `tlUser` instance (`getNames()` is not static under PHP 8).

## i18n

13 `rbtb.*` keys in all 10 locale bundles (en de es fr it ja pt ro ru zh); status column labels are resolved server-side via `lang_get()` so they follow the session locale, exactly like the other modernized report screens.

## Testing

11-case suite (Suite 677) in `tmp/TLU_Test_Cases.md`: BFF data parity vs fixtures, render, closed-builds toggle + persistence, both legacy warnings, assignment popup, locale switcher, 403 path, aside link switch, Event Viewer clean.
