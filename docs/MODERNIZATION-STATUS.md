# MODERNIZATION-STATUS.md — Screen-by-screen inventory

> Source of truth for which TestLink screens are modernized (Dashio HTML + REST BFF).
> `modernize.yml`: when triggered without a screen name, pick the NEXT item from the
> **TODO** section below (ASIDE order, top to bottom) and update this file when done.
>
> Last updated: 2026-08-30 (#798 Requirement Editor modernized) · branch `sebiboga`

## Summary

| State | Count |
|---|---|
| DONE (modernized) | 67 + 4 extras (tcImport, planEdit modal, Dashboard, Requirement Editor reqEdit) |
| IN PROGRESS | 0 |
| TODO (still legacy) | 0 — all ASIDE screens modernized |

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
| 62 | Reports — Free Test Cases | `results/freeTestCases.html` | api/reports | #751 |
| 63 | Reports — Results by Issues / Bugs per TC | `results/resultsBugs.html` | api/reports | #763 |
| 64 | Reports — Execution Timeline Statistics | `results/execTimelineStats.html` | api/reports | #762 |
| 65 | Login / Logout | `auth/login.html` | api/auth | #775 |
| 66 | Documentation links / hub | `documentation/documentation.html` | api/documentation | #764 |
| 67 | System — Install / Upgrade check | `install/installView.html` | api/install | #797 |

> **Install wizard scope note:** only the status-check part of the legacy
> install area is modernized (#797). The full install wizard
> (`install/index.php` + step scripts) runs **before** the DB/session exist and
> intentionally stays legacy; the main APEX remains reachable from the new
> screen via its "Open installer wizard" action.

Extra modernized feature (not an ASIDE entry):
- `testcases/tcImport.html` + `api/testcasesimport` — Markdown/XML test case import (#540, DONE)
- Create/Edit Test Plan modal in `plans/planView.html` + `api/plans` (`POST /`, `PUT /{id}`, `GET /{id}`) — replaces the `planEdit.php` legacy redirect (#750, DONE)
- Dashboard / main page (`lib/general/mainPage.php`) — `gui/templates/mainpage/mainPage.html` + `api/mainpage/index.php` (#780, DONE). This is the ASIDE **mainframe landing** (the "home" view), not an ASIDE child item; the link switch lives in `index.php` `getReturnWorkArea()` which now defaults the mainframe to the modernized screen.

---

## 🔄 IN PROGRESS

None — all modernized screens are green.

---

## ⬜ TODO — still legacy PHP (next screens, ASIDE order)

### Reports (from cfg/reports.cfg.php — all 24 ACTIVE reports modernized)

All report entries in `cfg/reports.cfg.php` now map to a modernized `.html` screen +
BFF action in `api/reports/index.php` (see DONE rows 44–64). The two report types that
have **no** modernized screen (`lib/results/uncoveredTestCases.php` — Uncovered Test
Cases, and `lib/results/resultsMoreBuildsGUI.php` — Results by Multiple Builds) are
**commented out** in `cfg/reports.cfg.php`, so they are not reachable from the ASIDE
menu and are deferred. The legacy `else` branch in `lib/general/asideMenu.php`
(fallback to `lib/**/*.php` report controllers) is now dead code for all active entries.

### Other legacy screens

None — every ASIDE entry now maps to a modernized `.html` screen + BFF.

> The **Documentation links** screen (previously `tools/viewer.php`) was modernized
> as the Documentation Hub in **#764**: `gui/templates/documentation/documentation.html`
> + `api/documentation/index.php` (see DONE row 66). `tools/viewer.php` is kept for
> backward compatibility (direct links to PDFs still work).

### Legacy redirects from modernized screens (buttons that still go to .php)

| Priority | Source screen | Legacy target | Issue | Action |
|---|---|---|---|---|
| HIGH | tcView.html | `tcEdit.php`, `tcAssign2Tplan.php`, `tcExport.php`, `tcCompareVersions.php` | #753 | Convert to modals / BFF API |
| HIGH | planView.html | `planExport.php`, `planImport.php`, `usersAssign.php`, `frmWorkArea.php` | #754 — Export/Import/Roles/Execute still legacy (links fixed for tplan_id, #766) |
| HIGH | tcasesWithCF.html | `reqMilestones.php` (BROKEN!), `execSetResults.php`, `archiveData.php` | #756 | Fix broken link + modernize |
| HIGH | assignedTcOverview.html | `archiveData.php`, `execHistory.php`, `execSetResults.php` | #756 | Redirect to modernized pages |
| HIGH | tcAssignments.html | `execSetResults.php`, `execHistory.php`, `archiveData.php` | #756 | Redirect to modernized pages |
| HIGH | 5 req screens (#755 progress: `reqView.php` ✅, `reqSpecView.php` ✅) | `reqSpecViewRevision.php`, `reqSpecViewRevisions.php`, `printDocument.php` | #755 | Convert to modals / BFF API |
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
