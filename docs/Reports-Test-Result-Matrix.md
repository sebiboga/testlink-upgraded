# Test Result Matrix — Modernized Screen

The **Test Results Matrix** report (ASIDE → Reports → *Test Results Matrix*) shows, for every
test case linked to the test plan, the execution status **per build**, plus derived columns for
the latest created build and the globally latest execution. It replaces the legacy 1.9.20
`lib/results/resultsTC.php` HTML/ExtJS view with a modern Dashio-styled page backed by a
plain-PHP REST BFF.

**Path:** ASIDE menu → Reports → *Test Results Matrix*
**URL:** `gui/templates/results/resultsMatrix.html?tproject_id=<id>&tplan_id=<id>`
**BFF API:** `api/reports/index.php` — `GET ?action=metrics_results_matrix&tproject_id=&tplan_id=` (+ `do_action=result&build_set[]=` after a launcher apply)
**Rights:** `testplan_metrics`
**Tracking issue:** [#681](https://github.com/sebiboga/testlink-upgraded/issues/681)

---

## Overview

* Header: test project / test plan context, locale switcher, toolbar.
* One row per test case (one per platform when platforms exist), grouped column layout:

| Column | Content |
|--------|---------|
| Test Suite | top-level suite name (legacy grouping) |
| Test Case | `PREFIX-id: name` + history / edit popup links (legacy icons) |
| Priority | High/Medium/Low — only when the project has priority enabled |
| Build A…N | per-build status badge (localized label + version) + execute popup icon |
| [Latest created build] | result cell of the newest active build (legacy quirk: "latest" = end of ordered set) |
| Exec Notes for Latest Created Build | notes recorded on that build |
| Latest execution | status of the globally most recent execution |
| Notes (latest exec) | its execution notes |

* Statuses follow the results-config display order; NOT RUN is synthesized by BFF SQL so
  never-executed cases still render every build column.

## Toolbar actions (legacy parity)

* **Refresh** – re-fetches the matrix.
* **Export as Spreadsheet** – legacy `resultsTC.php?format=3&doAction=result…` download.
* **Send spreadsheet by email** – same controller with `sendSpreadSheetByMail_x=1`.

## Build-selection launcher (> 6 active builds)

With more than `resultMatrixReport.buildQtyLimit` (6) active builds the screen switches to the
legacy launcher behavior: warning text ("Amount of potential data…", "You have N Active Builds,
please choose no more than 6"), one checkbox per active build and an Apply button. Applying a
selection ≤ limit reloads the matrix restricted to those builds (`do_action=result` +
`build_set[]`), exactly like the legacy form submit.

## Empty & error states

* Plan without linked test cases → friendly empty box (legacy rendered nothing).
* Missing rights → localized "Insufficient rights" message instead of data.
* Unknown tplan/tproject → empty shell with feedback line, never a PHP error.

## Legacy bug fixed during modernization

XLS export crashed on PHP 8 with ≥2 builds
([#682](https://github.com/sebiboga/testlink-upgraded/issues/682)):
`buildDataSet()` wrote `$r4build['text']` at the top of each loop iteration although the XLS
branch had turned `$r4build` into a plain string in the previous iteration → TypeError.
Fix: removed the stray pre-initialization (the non-XLS branch initializes its own shape).
