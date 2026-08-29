# Test Plan Report / Test Report / Test Report on build — Modernized Screen

Three report document types share one modernized navigator screen, switched by the `type`
query argument. They replace the legacy `lib/results/printDocOptions.php` HTML picker plus
the old plan-document pages. The screen is a standalone Dashio page backed by a plain-PHP
REST BFF inside `api/reports/index.php` (`tp_report_init` + `tp_report_tree`); the actual
document generation stays on the legacy `lib/results/printDocument.php` printer (unchanged).

**Path:** ASIDE menu → Reports → *Test Plan Report* / *Test Report* / *Test Report on build*
**URL:** `gui/templates/results/testPlanReport.html?tproject_id=<id>&tplan_id=<id>&type=<testplan|testreport|testreport_onbuild>`
**BFF API:**
  - `api/reports/index.php?action=tp_report_init&tproject_id=<id>&tplan_id=<id>&type=<type>`
  - `api/reports/index.php?action=tp_report_tree&tproject_id=<id>&tplan_id=<id>`
**Rights:** document generation gating (same as legacy); the screen surfaces
`canGenerate=false` when the rights are missing.
**Tracking issue:** [#607](https://github.com/sebiboga/testlink-upgraded/issues/607) (Reports
center umbrella; the navigator itself was landed under the follow-up comments in that issue).

---

## Overview

The screen presents two panels side by side:

* **Select a test suite** — a collapsible tree of the test project's suites, each showing a
  live count of linked test cases for the active plan (deep-summed across children). A
  virtual "whole test plan" root row selects the entire plan.
* **Document print options** — the same option sets the legacy `printDocOptions.php` offered
  for these document types:
  * *Output format*: HTML (default) or MS Word.
  * *Document structure*: Table of contents, Header numbering.
  * *Test case content*: Suite contents header, Test case summary, Test case body, Test case
    author, Test case keywords, Custom fields, Requirements linked to test cases.
  * *Per-build reports* (only for `type=testreport_onbuild`): one direct link per build of the
    plan, opening the on-build document immediately (`lnl.php` with the plan API key).

## Actions

* **Print whole test plan report** — generates the document for the whole plan
  (`level=testproject&id=<tproject_id>`) opening in a new tab.
* **Print selected suite** (or whole plan when no suite is selected) — generates the document
  for a single suite (`level=testsuite&id=<suite_id>`).
* **Refresh tree** — reloads the suite tree from the BFF.
* **Locale switcher** — header language selector (i18n via `TLi18n`).

The generated document URL is `lib/results/printDocument.php` with `type`, `level`, `id`,
`docTestPlanId`, `tproject_id`, `format` and the collected checkbox options
(e.g. `toc=y`, `summary=y`, ...).

## Document types

| `type` | Title key | Meaning |
|---|---|---|
| `testplan` | `tpr.header` | Whole test plan (document + per-suite) |
| `testreport` | `tpr.docTitle.testreport` | Test report for the plan |
| `testreport_onbuild` | `tpr.docTitle.testreportOnBuild` | Test report on a single build (adds per-build section) |

In the ASIDE menu all three entries map here with their `type`
(`lib/general/asideMenu.php` modernTypes map, Refs #608/#607).

## Empty & error states

* No project/plan context → `hasContext=false` with a "no context" warning and empty box.
* Unknown project/plan id → 400.
* Missing generation rights → warning "insufficient rights" and generate buttons are inert.
* Suite tree with no suites → "No test suites found" empty message.
