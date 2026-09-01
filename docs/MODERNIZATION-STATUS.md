# MODERNIZATION-STATUS.md — Screen-by-screen inventory

> Source of truth for which TestLink screens are modernized (Dashio HTML + REST BFF).
> `modernize.yml`: when triggered without a screen name, pick the NEXT item from the
> **TODO** section below (ASIDE order, top to bottom) and update this file when done.
>
> Last updated: 2026-09-01 (#817 Set Results popup execSetResults modernized + Suite 817 + 4 report-screen links switched; #815 Test Plan Import planImport modernized + Suite 815; #754 planView redirects all fixed; #754 Test Plan Export planExport modernized + Suite 813 + planView redirect fixes; #812 Test Case Editor tcEdit modernized + Suite 812; #810 Assign Test Case to Test Plan modernized + Suite 810; #809 Test Case Compare Versions modernized + Suite 70; #803 Test Case Export modernized + Suite 69; #783 Lost Password modernized + regression suite; #782 Self Sign-Up code landed 1b62a353c) · branch `sebiboga`

## Summary

| State | Count |
|---|---|
| DONE (modernized) | 67 + 13 extras (tcImport, planEdit modal, Dashboard, Requirement Editor reqEdit, Requirement Document Print printDocument, Self Sign-Up firstLogin, Lost Password lostPassword, Compare Test Case Versions tcCompare, Assign TC to Test Plan tcAssign2Tplan, Test Case Editor tcEdit, Test Plan Export planExport, Test Plan Import planImport, Set Results popup execSetResults) |
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
- Self Sign-Up (`firstLogin.php`) — `gui/templates/auth/firstLogin.html` + `api/auth/index.php` `POST /api/auth/signup` (#782, DONE). Reached from `login.html` "New user? Create account".
- Lost Password / Password Reset (`lostPassword.php`) — `gui/templates/auth/lostPassword.html` + `api/auth/index.php` `POST /api/auth/reset` (+ `GET /api/auth/config`) (#783, DONE). Enumeration-safe generic success; reached from `login.html` "Lost password?". Regression suite 783 10/10 PASS.
- Test Case / Suite / Project Export (`tcEdit` toolbar) — `gui/templates/testcases/tcExport.html` + `api/testcasesexport/index.php` `GET ?action=info` + `POST ?action=export` (#803, DONE). Converted the `tcView.html` `tcExport.php` legacy redirect to a BFF-driven XML download (test case / suite-children / suite-deep / project-deep modes). Regression suite 69 13/13 PASS. Refs #753.
- Test Case Version Compare (`tcView` toolbar) — `gui/templates/testcases/tcCompare.html` + `api/testcasescompare/index.php` `GET ?action=info` + `GET ?action=compare` (#809, DONE). Converted the `tcView.html` `tcCompareVersions.php` legacy POST redirect to a BFF-driven side-by-side version diff (HTML/DaisyDiff + legacy text engines). Regression suite 70 13/13 PASS. Refs #753.
- Assign Test Case to Test Plan (`tcView` toolbar) — `gui/templates/testcases/tcAssign2Tplan.html` + `api/tcassign2tplan/index.php` `GET ?action=init` + `POST ?action=add` (#810, DONE). Converted the `tcView.html` `tcAssign2Tplan.php` legacy redirect (last remaining HIGH-priority link from #753 together with `tcEdit.php`) into a BFF-driven plan/platform link screen with read-only already-linked rows. Regression suite 810 13/13 PASS.
- Test Case Editor (`tcView` toolbar) — `gui/templates/testcases/tcEdit.html` + `api/testcasesedit/index.php` `GET ?action=edit` + `POST ?action=update` + `POST ?action=create_version` (#812, DONE). Converted the `tcView.html` `tcEdit.php` legacy redirect (the last remaining HIGH-priority link from #753) into a BFF-driven full-power version editor (title, summary, preconditions, steps/expected-results rows with add/remove, importance, status, exec. type, estimated duration, keywords, New Version clone). Rights `mgt_modify_tc` enforced on every route; executed-version edit gated by `testproject_edit_executed_testcases`. Regression suite 812 17/17 PASS.
- Test Plan Export (Plans → Test Plan Management toolbar) — `gui/templates/plans/planExport.html` + `api/planexport/index.php` `GET ?action=info` + `POST ?action=export` (#754, DONE). Converted the `planView.html` `planExport.php` legacy redirect into a BFF-driven XML download (linkedItems / tree / 4results modes; default filename; header-injection-safe). Also re-pointed the `planView.html` `usersAssign.php` redirect → `usersAssignPlan.html` and the `frmWorkArea.php` execute redirect → `execTest.html`. Regression suite 813 14/14 PASS.
- Test Plan Import (Plans → Test Plan Management toolbar) — `gui/templates/plans/planImport.html` + `api/planimport/index.php` `GET ?action=info` + `POST ?action=import` (#815, DONE). Converted the `planView.html` `planImport.php` legacy redirect into a BFF-driven XML upload screen that imports test plan links to test cases and platforms (legacy `importTestPlanLinksFromXML` / `processPlatforms` ports; rights `mgt_testplan_create`; size limit `import_file_max_size_bytes`; execution-order updates; legacy resultMap `OK`/`Not imported`). This closes the last remaining HIGH-priority #754 redirect — `importAction` now points at `planImport.html`. Regression suite 815 12/12 PASS.
- Set Results popup (execution of a single test case version, `level=testcase`) — `gui/templates/execute/execSetResults.html` + `api/execsetresults/index.php` `GET ?action=init` + `POST ?action=save` (#817, DONE). Port of `lib/execute/execSetResults.php` returning TC context, stepped plan (builds/platforms), per-step and overall statuses, grants, and prior-execution resume (partial-execution). Write via `write_execution()`; server validates linked version + build + step ids (forged ids skipped); `not_run` ignored (legacy parity); `testplan_execute` / read-only `exec_ro_access` respected (Save disabled + 403). Accepts legacy aliases `id`/`version_id`/`setting_build`/`setting_platform`. Switched the 5 remaining `execSetResults.php` links off the legacy redirect: `resultsMatrix.html`, `tcasesWithCF.html`, `assignedTcOverview.html`, `tcAssignments.html`, `testlink_library.js` `openExecutionWindow()`. This closes the last HIGH `execSetResults.php` rows from #756. Regression suite 817 18/18 PASS.

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
| HIGH | tcView.html | `tcEdit.php`, `tcAssign2Tplan.php` ✅, `tcExport.php` ✅, `tcCompareVersions.php` ✅ | #753 | `tcExport.php` converted to `tcExport.html` + BFF (Refs #803); `tcCompareVersions.php` converted to `tcCompare.html` + BFF (Refs #809); `tcAssign2Tplan.php` converted to `tcAssign2Tplan.html` + BFF (Refs #810); `tcEdit.php` converted to `tcEdit.html` + BFF (Refs #812) — ALL 4 redirected |
| HIGH | planView.html | `planExport.php`, `planImport.php`, `usersAssign.php`, `frmWorkArea.php` | #754 — `planExport.php` ✅ converted to `planExport.html` + BFF (Refs #754, Suite 813); `usersAssign.php` ✅→ `usersAssignPlan.html`; `frmWorkArea.php` execute ✅→ `execTest.html`; `planImport.php` ✅ converted to `planImport.html` + BFF (Refs #815, Suite 815) — **ALL REDIRECTS FIXED** |
| HIGH | tcasesWithCF.html | `reqMilestones.php` (BROKEN!), `execSetResults.php` ✅, `archiveData.php` | #756 | Fix broken link + modernize; `execSetResults.php` ✅→ `execSetResults.html` (Refs #817) |
| HIGH | assignedTcOverview.html | `archiveData.php`, `execHistory.php`, `execSetResults.php` ✅ | #756 | Redirect to modernized pages; `execSetResults.php` ✅→ `execSetResults.html` (Refs #817) |
| HIGH | tcAssignments.html | `execSetResults.php` ✅, `execHistory.php`, `archiveData.php` | #756 | Redirect to modernized pages; `execSetResults.php` ✅→ `execSetResults.html` (Refs #817) |
| HIGH | 5 req screens (#755 progress: `reqView.php` ✅, `reqSpecView.php` ✅, `printDocument.php` ✅) | `reqSpecViewRevision.php`, `reqSpecViewRevisions.php` | #755 | Convert to modals / BFF API |
| HIGH | resultsMatrix.html | `execSetResults.php` ✅ | #756 | Redirect to modernized page; `execSetResults.php` ✅→ `execSetResults.html` (Refs #817) |
| MEDIUM | showNewestTcVersions.html | `archiveData.php`, `tcCompareVersions.php` ✅ | #758 | Redirect / modal; `tcCompareVersions` now `tcCompare.html` (Refs #809) |
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
