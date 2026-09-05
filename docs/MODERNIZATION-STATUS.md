# MODERNIZATION-STATUS.md — Screen-by-screen inventory

> Source of truth for which TestLink screens are modernized (Dashio HTML + REST BFF).
> `modernize.yml`: when triggered without a screen name, pick the NEXT item from the
> **TODO** section below (ASIDE order, top to bottom) and update this file when done.
>
> Last updated: 2026-09-04 — (projectEdit: last user-reachable legacy screen `lib/project/projectEdit.php` modernized, Refs #967)

## Summary

| State | Count |
|---|---|
| DONE (modernized) | 67 + 24 extras (tcImport, planEdit modal, Dashboard, Requirement Editor reqEdit, Requirement Document Print printDocument, Self Sign-Up firstLogin, Lost Password lostPassword, Compare Test Case Versions tcCompare, Assign TC to Test Plan tcAssign2Tplan, Test Case Editor tcEdit, Test Plan Export planExport, Test Plan Import planImport, Set Results popup execSetResults, Test Suite viewer popup suiteView, Edit Execution popup editExecution, Requirement Spec Revision Compare reqSpecCompare, Results by Multiple Builds resultsMoreBuilds, Uncovered Test Cases uncoveredTestCases, Execution Print execPrint, Test Plan Report Print reportPrint, Report XLS/Mail Export Gateway reportsexport, User Management Export usersExport, Test Project Information viewer projectInfoView, Test Project Create/Edit projectEdit) |
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

> **tplanWithCF gap fixes (2026-09-04, Refs #848 #858 #859 #860):** external IDs now use a single glue-char
> (`buildExternalIdString($prefix, …)` — no more doubled `CFRP--2`); footer shows the legacy italic info text
> ("This Report shows all test cases…" via new `tpwcf.infoText` key) plus a "Generated on" server timestamp
> (`generated_on` in BFF response, new `tpwcf.generatedOn` key) alongside elapsed time; per-row edit icon now
> opens the direct test-case editor `tcEdit.html` instead of the read-only `tcView.html`. i18n keys added to all
> 10 locale bundles. Regression suite TPWCF-1 11/11 PASS; Event Viewer clean.
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
- `testcases/tcImport.html` + `api/testcasesimport` — Markdown/XML test case import (#540, DONE). XML path: `submitLegacyXml()` converted to an AJAX call to the BFF `POST ?action=import_xml` (Refs #819). The BFF extracts the legacy import functions from `lib/testcases/tcImport.php` (pure function defs, eval'd to avoid page side effects), validates the XML with a safe parser (returns a JSON 422 instead of the legacy script's `die()` on malformed XML), and normalizes the flat legacy `[title, message]` resultMap into rows for a report table. Regression suite 819 PASS.
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
- Test Suite viewer popup (`archiveData.php?edit=testsuite`) — `gui/templates/testcases/suiteView.html` + `api/suiteview/index.php` `GET ?action=info` (#818, DONE). Read-only popup popup: suite overview/details, latest-version test-case rows (ext. id, name, version, importance badge, summary) in a DataTable, suite keywords chips, attachments list. Rights `mgt_view_tc` on the owning test project (walked up `nodes_hierarchy`; 401/400/403/404 parity); `frr()` normalizes `fetchFirstRow()` `false` so error paths never write E_WARNING events. Switched `searchAdvancedView.html` `openTsEdit()` from `archiveData.php?edit=testsuite` to `suiteView.html`. Intentionally NOT porting `edit=testproject`/`edit=testcase` (tcView.html/testSpec.html already cover them). Regression suite 819 21/21 PASS.
- Edit Execution popup (`lib/execute/editExecution.php`) — `gui/templates/execute/editExecution.html` + `api/executionedit/index.php` `GET ?action=init` + `POST ?action=update` (#825, DONE). Edits an existing execution's **notes** (`updateExecutionNotes`) and **execution custom fields** (same legacy `html_table_of_custom_field_inputs` + `execution_values_to_db` contract; forged CF names ignored). Rights on the owning test project (`testplan_execute` AND `exec_edit_notes` → 403 otherwise); closed build → read-only (Save disabled + update 400); 404 unknown execution. Switched `openEditExecution()` in `execTest.html` and `execHistory.html` from `editExecution.php` to `editExecution.html`. Regression suite 825 12/12 PASS.
- Requirement Spec Revision Compare (`lib/requirements/reqSpecCompareRevisions.php`) — `gui/templates/requirements/reqSpecCompare.html` + `api/reqspec/index.php` `GET ?action=spec_revision_compare` (#837, DONE). Closes the HIGH `reqSpecViewRevisions` (revision-compare) item of #755. DataTable of spec revisions (from `get_history`) with left/right radios + diff-method choice (HTML comparison / HTML code comparison + context); diff panel shows the attribute table (doc_id/name/type), the scope diff (`HTMLDiffer::htmlDiff` or `diff::doDiff`+`inline`), and linked design custom-fields diff. Right `mgt_view_req` gates every route (same as `spec_revision_view`). Switched the "Compare revisions" buttons in `reqSpecView.html` and `reqSpecViewRevision.html` from the legacy screen to `reqSpecCompare.html`. Regression suite 837 13/13 PASS.
- Results by Multiple Builds (`lib/results/resultsMoreBuildsGUI.php` + `resultsMoreBuilds.php`) — `gui/templates/results/resultsMoreBuilds.html` + `api/reports/index.php` `GET ?action=more_builds_init` + `GET ?action=more_builds` (#838, DONE). Re-enabled and modernized the last deferred report screen: per-test-case × per-build last-execution-status matrix with build totals, per-suite summaries, and a query-parameters recap. Right `testplan_metrics` gates every route; legacy dead code (`resultsMoreBuilds.php` `newResults` block) rebuilt from `getExecStatusMatrixFlat()` (aggregating per `(tcversion,build)` keeping highest `executions_id`). ASIDE link (Reports → Query Metrics) and `cfg/reports.cfg.php` `results_custom_query` point at the modern screen. Regression suite 838 13/13 PASS; wiki + docs mirror.
- Uncovered Test Cases report (`lib/results/uncoveredTestCases.php`) — `gui/templates/results/uncoveredTestCases.html` + `api/reports/index.php` `GET ?action=uncovered_testcases` (#843, DONE). The LAST report without a modern screen. Mirrors the legacy SQL: `genComboReqSpec` → has_reqspec (`testproject_has_no_reqspec`); `requirement_spec_mgr::get_requirements_count` → has_requirements (`testproject_has_no_requirements`); `get_all_testcases_id` + LEFT OUTER JOIN `req_coverage` WHERE `req_id IS NULL` → uncovered; external_id joined to `tcversions`, grouped by suite; all-covered → `no_uncovered_testcases`. Re-enabled the entry in `cfg/reports.cfg.php` (`enabled='req'` — shown in the aside only when the project has requirements enabled and a test plan is selected) and added the `link_report_uncovered_testcases` branch to `lib/general/asideMenu.php`. Fixed a latent bug: `get_all_testcases_id($tprojectId,'just_id')` returns a plain list of ids, so the BFF uses `implode(',', array_map('intval',...))` instead of `array_keys()`. Regression suite 843 11/11 PASS; wiki + docs mirror.
- Execution Print popup (`lib/execute/execPrint.php`) — `gui/templates/execute/execPrint.html` + `api/executionprint/index.php` `GET ?action=print&id=N` + `GET ?action=delete_attachment&id=N&deleteAttachmentID=M` (#844, DONE). Renders a printable single-execution document by reusing the legacy render machinery (`lib/functions/print.inc.php` `renderExecutionForPrinting`) over the network and returning the print body as JSON; the Dashio screen provides the shell, toolbar (Print → `window.print()`), meta line (status badge / build / tester / timestamp) and print CSS. Hardens the previously **unauthenticated** legacy entry point: requires a session + `testplan_execute` on the owning test project (403 otherwise); unknown/forged execution id → 404 (probed before render, so no E_WARNING events). The inline legacy attachment-delete form is intercepted in JS and routed through the BFF `delete_attachment` route, then re-renders. Switched `openExecPrint()` in `execTest.html` and `execHistory.html` from `lib/execute/execPrint.php` to `execPrint.html?id=`. The public anonymous direct-link (`lnl.php`) intentionally stays on the legacy `execPrint.php` so the share-link use case keeps working. Regression suite 844 12/12 PASS.
- Report XLS/Mail Export Gateway (`api/reportsexport/index.php`) — (#854, DONE). Authenticated BFF gateway for the 10 modernized report screens' spreadsheet (XLS) and email (mail) export buttons. Validates session + `testplan_metrics` right on the owning test project, then 303-redirects to the corresponding legacy controller for actual XLS/mail generation. All `export_xls_url` / `send_mail_url` values in `api/reports/index.php` now point here — no legacy `.php` references remain in any modernized report screen's frontend. Unauthenticated access → 401; unknown action → 400; no plan → 404; no right → 403. Actions: `general_metrics`, `results_by_tsuite`, `baseline_l1l2`, `results_matrix`, `results_tc_flat`, `absolute_latest`, `results_by_status`, `never_run`, `exec_timeline_stats`, `assigned_tc_overview` (+ `_mail` variants).
- User Management Export (`lib/usermanagement/usersExport.php`) — `gui/templates/usermanagement/usersExport.html` + `api/usersexport/index.php` `GET ?action=info` + `POST ?action=export` (#920, DONE). Replaces the `usersView.html` toolbar's `usersExport.php` form-submit (the last legacy action reachable from the modern UI) with a BFF-driven XML download of the full user list. Right `mgt_users` enforced on every route (403 otherwise — mirroring the checked legacy export screen); filename overridable and sanitized via `basename()` (path-traversal safe); info returns count + `grants.mgt_users`; export streams `text/xml` + `Content-Disposition: attachment` with the exact legacy 10 fields per user (`id, login, role_id, email, first, last, locale, default_testproject_id, active, expiration_date`) via ADODB_XML. `usersView.html` toolbar Export button → `usersExport.html?tproject_id=&tplan_id=` (`gotoExport()`). Closes gap #880 (user export missing). Regression suite 71 13/13 PASS.
- Test Project Information viewer (`lib/testcases/archiveData.php?edit=testproject` project home pane) — `gui/templates/projects/projectInfoView.html` + `api/projectinfo/index.php` `GET ?action=info` (#923, DONE). The LAST remaining `archiveData.php` viewer mode (edit=testsuite/edit=testcase were already modernized). Dashio screen with Overview card (name/prefix/status/visibility/tc_counter/option chips), Description, Integrations (issue/code/reqmgr flags) and an Attachments DataTable (search/sort/pagination + server-built `attachmentdownload.php` link; card hidden when empty); BFF resolves project id by `id` > `tproject_id` > session `testprojectID` (legacy parity), 401/404/400, returns options/flags/attachments/grants (`mgt_modify_product`, `mgt_view_tc`, `mgt_view_req`). Link switches: right frame of the Specification workspace (`frmWorkArea.php` editTc), common `$actions->projectInfo` (ASIDE/legacy redirect), Info button per project row in `projectsView.html`, and the testproject-level Cancel targets of `containerEdit.php` + `tcImport.php`. `execNavigator.php`/`planAddTCNavigator.php` `?edit=testproject` unchanged — that param drives `execSetResults.php`/`planAddTC.php` item-level, not the viewer. i18n `piv.*` flat keys in all 10 bundles. Regression suite 923 20/20 PASS; wiki + docs mirror.
  - **Attachment upload/delete gap (#933, DONE):** `api/projectinfo/index.php` gained `POST ?action=upload` (multipart `uploadedFile`+`fileTitle`, ports legacy `fileUploadManagement(...,'nodes_hierarchy')` — repo allowed-file/extension/size guards, 422 on rejection) and `POST ?action=delete&file_id=N` (legacy `deleteAttachment($db,id,false)` preceded by a `fk_id`+`fk_table`=nodes_hierarchy ownership guard — forged file_id → 404). Both gated by `mgt_modify_product` on the owning project (403; the legacy containerEdit used `testcase_mgmt` for the project-level container — see #933). `projectInfoView.html` renders an upload form (title + file picker + Upload) above the table and a per-row Delete button (confirm dialog) only when the grant is true; the attachments card stays visible for first-attachment upload. Fixed a DataTable re-render bug (destroy-then-rebuild so stale rows are never re-shown after upload/delete). i18n: 11 new `piv.*` keys (`upload`,`uploadTitle`,`chooseFile`,`noFileSelected`,`delete`,`deleteConfirm`,`uploadOk`,`uploadFail`,`deleteOk`,`deleteFail`,`addAttachment`) in all 10 bundles. Regression suite 933 20/20 PASS; wiki + docs mirror + screenshots.
- Test Project Create/Edit (`lib/project/projectEdit.php` create/edit/doCreate/doUpdate/doDelete/setActive/setInactive/enableRequirements/disableRequirements) — `gui/templates/projects/projectEdit.html` + `api/projectedit/index.php` `GET ?action=init` + `POST ?action=create|update|delete|toggle_active|toggle_requirements` (#967, DONE). The LAST user-reachable legacy screen (found by walking the entire ASIDE — the zero-test-projects bootstrap in `lib/functions/common.php` still routed admin to it). Full-page Dashio form: **Source data** clone-from card (legacy `copy_from_tproject_id`→`copy_as`), name/prefix fields with the legacy hint + empty warnings, description, feature checkboxes (optReq/optPriority/optAutomation/optInventory), issue/code/reqmgr tracker selects with enabled-checkbox disabling (no interfaces → "-- none --" + disabled), active/public, read-only api_key in edit mode, **Show event history** popup → `eventviewer.html?objectId&objectType=testprojects`. BFF: `crossChecksBFF` duplicate name/prefix (legacy messages), `create($item,['doChecks'=>true,'setSessionProject'=>true])`, `applyTrackers`, `protectFromLockout`, `enableRequirements/disableRequirements`/`setActive/setInactive` toggles, full cascade `delete` (409 + localized message on failure), audit events `audit_testproject_created/updated/deleted` (legacy parity). Link switches: `lib/functions/common.php` zero-projects redirect → modern screen (the sole entry point that reached the legacy `projectEdit.php` — `projectsView.html` create/edit stay on its own modern inline modal). i18n `pedit.*` (17 keys) + `footers.projectEdit` in all 10 bundles. Regression suite 967 17/17 PASS; Event Viewer clean; wiki + docs mirror + screenshots. Locked legacy `copy_as` empty-source E_WARNING → #968.

---

## 🔄 IN PROGRESS

None — all modernized screens are green.

---

## ⬜ TODO — still legacy PHP (next screens, ASIDE order)

### Reports (from cfg/reports.cfg.php — all 24 ACTIVE reports modernized)

All report entries in `cfg/reports.cfg.php` now map to a modernized `.html` screen +
BFF action in `api/reports/index.php` (see DONE rows 44–64, plus the *Results by
Multiple Builds* and *Uncovered Test Cases* extras). The previously commented-out
`uncovered_testcases` entry (Uncovered Test Cases) is now **re-enabled and modernized**
(`gui/templates/results/uncoveredTestCases.html` + BFF `uncovered_testcases`, Refs #843).
The legacy `else` branch in `lib/general/asideMenu.php` (fallback to
`lib/**/*.php` report controllers) is now dead code for all active entries.

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
| HIGH | 5 req screens (#755: `reqView.php` ✅, `reqSpecView.php` ✅, `printDocument.php` ✅, `reqSpecViewRevision.php` ✅, `reqSpecViewRevisions.php` ✅) | `reqSpecViewRevision.php`, `reqSpecViewRevisions.php` | #755 | ALL converted: `reqSpecViewRevision.php` ✅→ modern `spec_revision_view` + `reqSpecViewRevision.html`; `reqSpecViewRevisions` (compare) ✅→ `reqSpecCompare.html` + BFF `action=spec_revision_compare` (Refs #837) — **ALL REDIRECTS FIXED** |
| HIGH | resultsMatrix.html | `execSetResults.php` ✅ | #756 | Redirect to modernized page; `execSetResults.php` ✅→ `execSetResults.html` (Refs #817) |
| MEDIUM | showNewestTcVersions.html | `archiveData.php`, `tcCompareVersions.php` ✅ | #758 | ✅ `compareUrl()` now opens `tcCompare.html?tcase_id=&version_left=&version_right=` with auto-select+compare; `tcCompare.html` gained `version_left`/`version_right` URL-param pre-selection (Refs #809, #758 — FIXED) |
| MEDIUM | searchAdvancedView.html | `archiveData.php`, `reqSpecEdit.php`, `reqEdit.php` | #759 | ✅ All functions (`openTcEdit`, `openTsEdit`, `openReqSpecEdit`, `openReqEdit`, `openExecHistory`) already point to modernized `.html` screens — no legacy links remain (Refs #759 — FIXED) |
| MEDIUM | keywordsView.html | `frmWorkArea.php` (keywordsAssign) | #757 | ✅ `goAssign()` now opens `keywordsAssign.html?tproject_id=&tplan_id=` (Refs #757, fixed) |
 | MEDIUM | platformsView.html | `eventviewer.php` | #757 | ✅ Event History now opens `eventviewer.html` with `objectId`+`objectType` filter (Refs #757, fixed); `eventviewer.html` gained client-side object filter |
 | MEDIUM | tcImport.html | `tcImport.php` (XML path only) | #757 → #819 | ✅ `submitLegacyXml()` in `tcImport.html` now calls BFF `action=import_xml` in `api/testcasesimport/index.php` (Refs #819); removed `legacyUrl` (XML path only) — **FIXED** |
| LOW | 10 report screens | export/mail download endpoints | #756, #854 | ✅ Converted to BFF gateway: `api/reportsexport/index.php` (Refs #854). All 10 report screens' `export_xls_url` + `send_mail_url` now point to the BFF which validates auth + testplan_metrics right, then 303-redirects to the legacy controller for XLS/mail generation. No legacy `.php` references remain in any modernized report screen's frontend code. |

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
