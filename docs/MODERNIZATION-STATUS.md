# MODERNIZATION-STATUS.md — Screen-by-screen inventory

> Source of truth for which TestLink screens are modernized (Dashio HTML + REST BFF).
> `modernize.yml`: when triggered without a screen name, pick the NEXT item from the
> **TODO** section below (ASIDE order, top to bottom) and update this file when done.
>
> Last updated: 2026-08-25 · branch `sebiboga` @ 074e89661

## Summary

| State | Count |
|---|---|
| DONE (modernized) | 43 |
| IN PROGRESS | 1 |
| TODO (still legacy) | 6 |

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
| 9b | System — Plugin Management ✅ (#636) | `plugins/pluginView.html` | api/plugins | right `mgt_plugins` |
| 10 | Product — Test Project Mgmt | `projectsView.html` | api/projects | |
| 11 | Product — Assign User Roles | `usermanagement/usersAssignProject.html` | api/users | same as #5 |
| 12 | Product — Assign Custom Fields | `cfields/cfieldsAssignView.html` | api/cfields | |
| 13 | Product — Keywords Mgmt | `keywords/keywordsView.html` | api/keywords | |
| 14 | Product — Platforms Mgmt | `platforms/platformsView.html` | api/platforms | |
| 15 | Dashboard — Metrics Dashboard | `results/metricsDashboard.html` | api/metrics | |
| 16 | Dashboard — Inventory | `inventory/inventoryView.html` | api/inventory | |
| 17 | Requirements — Spec Management | `requirements/reqSpecMgmt.html` | api/reqspec | |
| 18 | Requirements — Requirement Overview | `requirements/reqOverview.html` | api/reqspec | #566 |
| 19 | Requirements — Print Requirements | `requirements/printReqSpec.html` | api/reqspec | |
| 20 | Requirements — Search Requirements | `requirements/searchReq.html` | api/search | |
| 21 | Requirements — Search Req Specs | `requirements/searchReqSpec.html` | api/search | |
| 22 | Requirements — Assign Requirements | `requirements/assignReqs.html` | api/requirements | |
| 23 | Requirements — Req Monitor Overview | `requirements/reqMonitorOverview.html` | api/requirements | |
| 24 | Test Spec — Test Specification tree/editor | `testcases/testSpec.html` | api/testcases | |
| 25 | Test Spec — TC Viewer popup | `testcases/tcView.html` | api/testcases | |
| 26 | Test Spec — Search Test Cases | `search/searchView.html` | api/search | |
| 27 | Search — Advanced (full text) | `search/searchAdvancedView.html` | api/search | |
| 28 | Header — Quick search | `search/searchQuickView.html` | api/search | |
| 29 | Test Spec — Assign Keywords | `keywords/keywordsAssign.html` | api/keywords | |
| 30 | Test Spec — TC created per user report | `results/tcCreatedPerUserOnTestProject.html` | api/results | |
| 31 | Plans — Test Plan Management | `plans/planView.html` | api/plans | #576 |
| 32 | Plans — Builds & Releases | `plans/buildsView.html` | api/builds | #585 |
| 33 | Plans — Add/Remove Test Cases | `plans/planAddTCView.html` | api/plans | #593 |
| 34 | Plans — Assign Platforms to Plan | `platforms/platformsAssign.html` | api/platforms | #603 |
| 35 | Execution — Execution History | `execute/execHistory.html` | api/execute | |
| 36 | Plans — Set Test Urgency | `plans/testUrgency.html` | api/plans | #605 |
| 37 | Plans — Show Newest TC Versions | `plans/showNewestTcVersions.html` | api/plans | #643 |
| 38 | Execution — Test Plan Milestones ✅ (#647) | `plans/planMilestones.html` | api/milestones | right `testplan_planning` (controller parity) |
| 39 | Execution — Execute Tests ✅ (#662) | `execute/execTest.html` | api/execute (init/tcList/tcDetails/save) | rights `testplan_execute` / RO `exec_ro_access`; reuses legacy write_execution() |
| 40 | Reports — Results by Test Suite ✅ (#671) | `results/resultsByTSuite.html` | api/reports (metrics_by_tsuite) | right `testplan_metrics`; baseline save action lives here |
| 41 | Reports — Baselines L1 & L2 ✅ (#673) | `results/baselineL1L2.html` | api/reports (metrics_baseline_l1l2) | right `testplan_metrics`; reads baseline_l1l2_context/details |
| 42 | Reports — Results by Tester per Build ✅ (#677) | `results/resultsByTesterPerBuild.html` | api/reports (metrics_by_tester_per_build) | right `testplan_metrics`; reuses getStatusTotalsByBuildUAForRender(); legacy show_closed_builds session contract |
| 43 | Reports — Test Cases Never Run ✅ (#688) | `results/neverRun.html` | api/reports (never_run_init/never_run_result) | right `testplan_metrics`; getNeverRunByPlatform(); legacy export/email endpoints reused |

Extra modernized feature (not an ASIDE entry):
- `testcases/tcImport.html` + `api/testcasesimport` — Markdown/XML test case import
  (#540, screen still WIP — see IN PROGRESS).

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

| Priority | Screen | Legacy entry point | Right gate | Notes |
|---|---|---|---|---|
~~1–3~~ done by CI: tcExecAssignment (#655), tcAssignments (#660) and Execute Tests (#662). Next: Reports center. |
| 4 | **Update Linked TC Versions** ✅ modernized (`planUpdateTC.html` + BFF, #619) | launcher `planUpdateTC` | `testplan_update_linked_testcase_versions` | |

| 5 | ~~Test Plan Milestones~~ ✅ modernized (`plans/planMilestones.html` + `api/milestones`, #647) | was `lib/plan/planMilestonesView.php` | `testplan_planning` | |
| 7 | Reports center | launcher `showMetrics` → `lib/results/resultsNavigator.php` | varies | ~30 legacy report pages under lib/results/; metricsDashboard + tcCreatedPerUser already done; **Results by Test Suite done (#671)**; **Baselines L1 & L2 done (#673)**; **Results by Tester per Build done (#677)**; **Test Cases Never Run done (#688)** — next: the remaining report pages |
| 8 | Plugin Management ✅ modernized (`plugins/pluginView.html` + `api/plugins`, #636) | legacy right `mgt_plugins` (BFF-enforced) | |

Not modernization targets (stay as-is by design):
- Documentation section (viewer.php PDF links, GitHub wiki link).
- Login/logout/install pages (already themed where needed).

---

## How to verify a screen is DONE (checklist used for this inventory)

1. Standalone page exists at `gui/templates/<area>/<screen>.html` (Dashio pattern).
2. REST BFF route(s) exist under `api/<area>/index.php` (session auth + rights checks).
3. ASIDE link (`gui/templates/dashio/aside.tpl` via `$actions->…` in
   `lib/functions/common.php` `initUserEnv()`) points to the `.html` file, not a
   legacy launcher.
4. i18n keys present in ALL locale bundles (`locale/*/LC_MESSAGES/*.json` client bundles).
5. Regression suite appended to test-cases doc + wiki mirror in `docs/WIKI-*.md`.

## Maintenance rule

Whoever modernizes a screen MUST move its row from TODO to DONE in this same
commit (and bump the Summary counts). CI (`modernize.yml`) reads this file to pick
the next screen when none was specified.
