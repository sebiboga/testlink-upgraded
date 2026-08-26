# MODERNIZATION-STATUS.md — Screen-by-screen inventory

> Source of truth for which TestLink screens are modernized (Dashio HTML + REST BFF).
> `modernize.yml`: when triggered without a screen name, pick the NEXT item from the
> **TODO** section below (ASIDE order, top to bottom) and update this file when done.
>
> Last updated: 2026-08-26 · branch `sebiboga`

## Summary

| State | Count |
|---|---|
| DONE (modernized) | 59 |
| IN PROGRESS | 1 |
| TODO (still legacy) | ~35 |

---

## ✅ DONE — modernized screens

Each row: ASIDE entry → HTML screen + BFF API (`api/<area>/index.php`).

| # | ASIDE section / entry | Screen (gui/templates/) | BFF | Ref |
|---|---|---|---|---|
| 1 | System — Event Viewer | `eventviewer/eventviewer.html` | api/eventviewer | |
| 2 | System — My Settings | `usermanagement/userInfo.html` | api/userinfo | |
| 3 | System — User Management | `usermanagement/usersView.html` | api/users | |
| 4 | System — Role Management | `usermanagement/rolesView.html` | api/roles | |
| 5 | System — Assign Project Roles | `usermanagement/usersAssignProject.html` | api/users | |
| 6 | System — Assign Test Plan Roles | `usermanagement/usersAssignPlan.html` | api/users | |
| 7 | System — Custom Fields | `cfields/cfieldsView.html` | api/cfields | |
| 8 | System — Issue Tracker Mgmt | `issuetracker/issuetrackerView.html` | api/issuetracker | |
| 9 | System — Code Tracker Mgmt | `codetracker/codetrackerView.html` | api/codetracker | |
| 10 | System — Plugin Management | `plugins/pluginView.html` | api/plugins | #636 |
| 11 | Product — Test Project Mgmt | `projectsView.html` | api/projects | |
| 12 | Product — Assign User Roles | `usermanagement/usersAssignProject.html` | api/users | same as #5 |
| 13 | Product — Assign Custom Fields | `cfields/cfieldsAssignView.html` | api/cfields | |
| 14 | Product — Keywords Mgmt | `keywords/keywordsView.html` | api/keywords | |
| 15 | Product — Platforms Mgmt | `platforms/platformsView.html` | api/platforms | |
| 16 | Dashboard — Metrics Dashboard | `results/metricsDashboard.html` | api/metrics | |
| 17 | Dashboard — Inventory | `inventory/inventoryView.html` | api/inventory | |
| 18 | Requirements — Spec Management | `requirements/reqSpecMgmt.html` | api/reqspec | |
| 19 | Requirements — Requirement Overview | `requirements/reqOverview.html` | api/reqspec | #566 |
| 20 | Requirements — Print Requirements | `requirements/printReqSpec.html` | api/reqspec | |
| 21 | Requirements — Search Requirements | `requirements/searchReq.html` | api/search | |
| 22 | Requirements — Search Req Specs | `requirements/searchReqSpec.html` | api/search | |
| 23 | Requirements — Assign Requirements | `requirements/assignReqs.html` | api/requirements | |
| 24 | Requirements — Req Monitor Overview | `requirements/reqMonitorOverview.html` | api/requirements | |
| 25 | Test Spec — Test Specification tree/editor | `testcases/testSpec.html` | api/testcases | |
| 26 | Test Spec — TC Viewer popup | `testcases/tcView.html` | api/testcases | |
| 27 | Test Spec — Search Test Cases | `search/searchView.html` | api/search | |
| 28 | Search — Advanced (full text) | `search/searchAdvancedView.html` | api/search | |
| 29 | Header — Quick search | `search/searchQuickView.html` | api/search | |
| 30 | Test Spec — Assign Keywords | `keywords/keywordsAssign.html` | api/keywords | |
| 31 | Test Spec — TC created per user report | `results/tcCreatedPerUserOnTestProject.html` | api/results | |
| 32 | Plans — Test Plan Management | `plans/planView.html` | api/plans | #576 |
| 33 | Plans — Builds & Releases | `plans/buildsView.html` | api/builds | #585 |
| 34 | Plans — Add/Remove Test Cases | `plans/planAddTCView.html` | api/plans | #593 |
| 35 | Plans — Assign Platforms to Plan | `platforms/platformsAssign.html` | api/platforms | #603 |
| 36 | Execution — Execution History | `execute/execHistory.html` | api/execute | |
| 37 | Plans — Set Test Urgency | `plans/testUrgency.html` | api/plans | #605 |
| 38 | Plans — Show Newest TC Versions | `plans/showNewestTcVersions.html` | api/plans | #643 |
| 39 | Execution — Test Plan Milestones | `plans/planMilestones.html` | api/milestones | #647 |
| 40 | Execution — Execute Tests | `execute/execTest.html` | api/execute | #662 |
| 41 | Plans — Update Linked TC Versions | `plans/planUpdateTC.html` | api/plans | #619 |
| 42 | Execution — TC Exec Assignment | `execute/tcExecAssignment.html` | api/execassignment | #655 |
| 43 | Execution — TC Assignments | `execute/tcAssignments.html` | api/tcassignments | #660 |
| 44 | Reports — Results by Test Suite | `results/resultsByTSuite.html` | api/reports | #671 |
| 45 | Reports — Baselines L1 & L2 | `results/baselineL1L2.html` | api/reports | #673 |
| 46 | Reports — Results by Tester per Build | `results/resultsByTesterPerBuild.html` | api/reports | #677 |
| 47 | Reports — Test Cases Never Run | `results/neverRun.html` | api/reports | #688 |
| 48 | Reports — Results by Status (failed) | `results/resultsByStatus.html` | api/reports | #695 |
| 49 | Reports — Results by Status (blocked) | `results/resultsByStatus.html` | api/reports | #695 |
| 50 | Reports — Results by Status (not_run) | `results/resultsByStatus.html` | api/reports | #695 |
| 51 | Reports — Test Cases with Custom Fields | `results/tcasesWithCF.html` | api/reports | #737 |
| 52 | Reports — Results Matrix | `results/resultsMatrix.html` | api/reports | |
| 53 | Reports — Test Plan Report | `results/testPlanReport.html` | api/reports | |
| 54 | Reports — Results TC Flat | `results/resultsTCFlat.html` | api/reports | |
| 55 | Reports — Results Requirements | `results/resultsRequirements.html` | api/reports | |
| 56 | Reports — Cases Without Tester | `results/casesWithoutTester.html` | api/reports | |
| 57 | Reports — Test Plan with CF | `results/tplanWithCF.html` | api/reports | #737 |
| 58 | Reports — Absolute Latest | `results/absoluteLatest.html` | api/reports | |
| 59 | Reports — General Metrics | `results/generalMetrics.html` | api/reports | |
| 60 | Reports — Assigned TC Overview | `results/assignedTcOverview.html` | api/reports | |
| 61 | Reports — Charts | `results/charts.html` | api/reports | |

Extra modernized feature (not an ASIDE entry):
- `testcases/tcImport.html` + `api/testcasesimport` — Markdown/XML test case import (#540, WIP)

---

## 🔄 IN PROGRESS

### 1. MD Test Case Import UI — Issue #540
- Parser (`lib/functions/markdown_tc_import.class.php`) + BFF
  (`api/testcasesimport/index.php`) DONE and tested.
- Screen `gui/templates/testcases/tcImport.html` exists as WIP
  (commit 5cdc065ce); remaining steps tracked in the old scratch TODO
  (info action polish, i18n keys ×10 bundles, browser test pass, docs/wiki,
  regression suite) before closing the issue.

---

## ⬜ TODO — still legacy PHP (next screens, ASIDE order)

### Reports (from cfg/reports.cfg.php — 26 total, 10 modernized, 16 legacy)

| Priority | Legacy file | Description | Notes |
|---|---|---|---|
| HIGH | `lib/results/freeTestCases.php` | Test Cases not assigned to Any Test Plan | Issue #744 needed |
| HIGH | `lib/results/testCasesWithoutTester.php` | Test Cases without Tester | |
| HIGH | `lib/results/uncoveredTestCases.php` | Uncovered Test Cases | |
| HIGH | `lib/results/resultsTC.php` | Test Case Results | |
| HIGH | `lib/results/resultsTCFlat.php` | Flat Test Case Results | Already has HTML: `resultsTCFlat.html` — verify BFF |
| HIGH | `lib/results/resultsTCAbsoluteLatest.php` | Absolute Latest Results | Already has HTML: `absoluteLatest.html` — verify BFF |
| HIGH | `lib/results/resultsMoreBuildsGUI.php` | Results by Multiple Builds | |
| HIGH | `lib/results/resultsReqs.php` | Requirements Results | Already has HTML: `resultsRequirements.html` — verify BFF |
| HIGH | `lib/results/resultsBugs.php` (type=0) | Bugs Report | |
| HIGH | `lib/results/resultsBugs.php` (type=1) | Bugs Report | |
| HIGH | `lib/results/testPlanWithCF.php` | Test Plan with Custom Fields | Already has HTML: `tplanWithCF.html` — verify BFF |
| HIGH | `lib/results/execTimelineStats.php` | Execution Timeline | |
| HIGH | `lib/results/charts.php` | Charts | Already has HTML: `charts.html` — verify BFF |
| HIGH | `lib/results/keywordBarChart.php` | Keyword Bar Chart | |
| HIGH | `lib/results/overallPieChart.php` | Overall Pie Chart | |
| HIGH | `lib/results/platformPieChart.php` | Platform Pie Chart | |
| HIGH | `lib/results/priorityBarChart.php` | Priority Bar Chart | |
| HIGH | `lib/results/topLevelSuitesBarChart.php` | Top Level Suites Bar Chart | |
| MEDIUM | `lib/testcases/tcAssignedToUser.php` | TCs Assigned to User | |
| MEDIUM | `lib/results/printDocOptions.php` (3x) | Print Documentation | |

### Other legacy screens

| Priority | Screen | Legacy entry point | Notes |
|---|---|---|---|
| HIGH | Create/Edit Test Plan | `lib/plan/planEdit.php` | Refs #750 — planView.html redirects here for create/edit |
| MEDIUM | Login/Logout | `login.php`, `logout.php` | Themed but not fully modernized |
| LOW | Install/Upgrade | `install/` | Stays as-is |
| LOW | Documentation links | `tools/viewer.php` | PDF links, stays as-is |

### Legacy redirects from modernized screens (buttons that still go to .php)

| Priority | Source screen | Legacy target | Issue | Action |
|---|---|---|---|---|
| HIGH | tcView.html | `tcEdit.php`, `tcAssign2Tplan.php`, `tcExport.php`, `tcCompareVersions.php` | #753 | Convert to modals / BFF API |
| HIGH | planView.html | `planEdit.php`, `planExport.php`, `planImport.php`, `usersAssign.php`, `frmWorkArea.php` | #750, #754 | Convert to modals / BFF API |
| HIGH | tcasesWithCF.html | `reqMilestones.php` (BROKEN!), `execSetResults.php`, `archiveData.php` | #756 | Fix broken link + modernize |
| HIGH | assignedTcOverview.html | `archiveData.php`, `execHistory.php`, `execSetResults.php` | #756 | Redirect to modernized pages |
| HIGH | tcAssignments.html | `execSetResults.php`, `execHistory.php`, `archiveData.php` | #756 | Redirect to modernized pages |
| HIGH | 5 req screens | `reqView.php`, `reqSpecView.php`, `*Revision.php`, `printDocument.php` | #755 | Convert to modals / BFF API |
| HIGH | resultsMatrix.html | `execSetResults.php` | #756 | Redirect to modernized page |
| MEDIUM | showNewestTcVersions.html | `archiveData.php`, `tcCompareVersions.php` | #758 | Redirect / modal |
| MEDIUM | searchAdvancedView.html | `archiveData.php`, `reqSpecEdit.php`, `reqEdit.php` | #759 | Redirect to modernized pages |
| MEDIUM | keywordsView.html | `frmWorkArea.php` (keywordsAssign) | #757 | Fix: already have keywordsAssign.html |
| MEDIUM | platformsView.html | `eventviewer.php` | #757 | Fix: already have eventviewer.html |
| MEDIUM | tcImport.html | `tcImport.php` (XML path only) | #757 | Convert XML import to BFF API |
| LOW | 10 report screens | export/mail download endpoints | #756 | Convert to BFF API downloads |

---

## How to verify a screen is DONE (checklist used for this inventory)

1. Standalone page exists at `gui/templates/<area>/<screen>.html` (Dashio pattern).
2. REST BFF route(s) exist under `api/<area>/index.php` (session auth + rights checks).
3. ASIDE link (`gui/templates/dashio/aside.tpl` via `$actions->…` in
   `lib/functions/common.php` `initUserEnv()`) points to the `.html` file, not a
   legacy launcher.
4. i18n keys present in ALL locale bundles (`gui/templates/i18n/*.json`).
5. Regression suite appended to test-cases doc + wiki mirror in `docs/WIKI-*.md`.

## Maintenance rule

Whoever modernizes a screen MUST move its row from TODO to DONE in this same
commit (and bump the Summary counts). CI (`modernize.yml`) reads this file to pick
the next screen when none was specified.
