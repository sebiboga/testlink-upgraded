# SCREEN COMPARE STATUS (legacy ↔ modern feature-parity)

Tracks, per screen, whether a feature-parity analysis has been run comparing the
modernized screen against its legacy 1.9.20 counterpart. One GitHub Actions run
analyses EXACTLY ONE screen; it then marks it **✓** and appends any **sub-screens**
(L2) it discovers reachable from it, plus their own sub-screens (L3) — so the next
run automatically picks a different, not-yet-analysed screen.

## Format

Column meanings:
- **#** — row number (used as a stable reference).
- **Level** — L1 = main ASIDE screen; L2 = screen reachable by clicking an action
  inside an L1 screen; L3 = screen reachable from an L2 screen (and so on).
- **Screen** — the modernized screen under `gui/templates/...`.
- **BFF** — the backing REST BFF (`api/<area>/index.php` (+ action)).
- **Parent** — the screen from which this one is reached (empty for L1). Closes the
  sub-screen relationship.
- **Legacy** — the legacy 1.9.20 controller/template it maps to.
- **Status** — **✓** = analysed (parity checked). Empty = not yet analysed.
- **Issues** — GitHub issue numbers filed (gaps + the 'Delete legacy' cleanup task).
- **Verdict** — one-line outcome of the analysis.

> **RULES for the analyzer agent / workflow:**
> - Only ONE screen per run: pick the first row (top-down) whose **Status** is empty.
> - After analysing, set that row's **Status** to `✓`, append its **Issues** and
>   **Verdict**, and add a row for every **sub-screen (L2/L3)** it discovered
>   reachable from this screen (from the modern HTML's links/buttons AND the legacy
>   controller's redirects), with Status empty so a later run handles them.
> - When a screen reaches `✓`, also FILE a cleanup issue:
>   `gh issue create --label task --title 'Delete legacy <screen> (Refs #N)'`
>   describing the legacy controller/template to remove, and reference it in Issues.
> - Never analyse the same screen twice; keep Levels/parents accurate.

| # | Level | Screen (gui/templates/) | BFF | Parent | Legacy | Status | Issues | Verdict |
|---|---|---|---|---|---|---|---|---|
<!-- L1 already-analysed (flat-era, reconciled 2026-09-05): -->
| 1 | L1 | `eventviewer/eventviewer.html` | api/eventviewer | | `lib/eventviewer/eventviewer.php` | ✓ | #867 #868 #869 #870 #871 #872 #873 #874 | 8 gaps (all task, OPEN): #867 mgt_view_events view-right missing on all GET routes (any auth user reads full log) · #868 multi-user filter dropped · #869 log-level grouping + collapsible-group toolbar + Show All Columns / Transaction column dropped · #870 Clear Events not audited · #871 filters not reset after Clear Events (BUGID 3908) · #872 event detail drops level/description/user + real session_id (modern mislabels transaction id as Session ID) · #873 empty state hardcoded English vs legacy localized no_events · #874 Timestamp string-sorts dd/mm/yyyy lexicographically vs legacy epoch sort. Confirmed covered (browser-verified with 21+ seeded events + no-rights vguest): level/date/user filters, Apply, object_id/object_type drill-down, Clear gated on events_mgt, charts, footer count + generated-on, pagination 25, i18n in all bundles. |
| 2 | L1 | `usermanagement/userInfo.html` | api/userinfo | | `lib/usermanagement/userInfo.php` | ✓ | #875 #876 #877 #878 | 4 gaps (all task, OPEN): #875 external password-management gating (`isPasswordMgtExternal()`) missing · #876 API-enabled gating (`$tlCfg->api->enabled`) missing · #877 Show-event-history drill-down gated on mgt_view_events missing · #878 demoMode read-only missing. Confirmed covered (browser-verified): edit personal data with validation, change password with old/new/confirm, API key generate + copy, login history (10 ok / 10 fail) with localized empty states, role/login readonly, session refresh on save. |
| 3 | L1 | `usermanagement/usersView.html` | api/users | | `lib/usermanagement/usersView.php` | ✓ | #879 #880 #881 #882 #883 #884 #885 #886 #887 #888 #889 #890 #891 | 13 gaps (all task, OPEN): #879 mgt_users rights check missing on ALL BFF routes (guest created admin-role account, privilege escalation) · #880 users.xml export dropped · #881 'Manage user' search dropped · #882 Auth method + Expiration Date missing from modal · #883 Locale + Expiration Date columns missing · #884 Reset Password missing · #885 Generate API Key missing · #886 show-event-history link missing · #887 demoMode gating missing · #888 create allows empty password · #889 role dropdown includes <inherited> id=0 + wrong default role · #890 no success/failure feedback · #891 grid toolbar (Expand/Collapse Groups, Show all Columns, Reset, Refresh, Reset Filters) dropped. Confirmed covered (browser-verified user1/nopass/vguest): create/edit modal, enable/disable (+ cannot-disable-self guard), delete (soft-delete active=2, admin protection), DataTables sort/search/pagination, i18n, tabs, generated-on footer. |
| 4 | L1 | `usermanagement/rolesView.html` | api/roles | | `lib/usermanagement/rolesView.php` | ✓ | #897 #898 #899 #900 #901 #902 #903 #904 | 8 gaps (all task, OPEN): #897 role_management rights check missing on ALL BFF routes (guest loads full screen) · #898 system-role protection hardcodes id=3 instead of TL_LAST_SYSTEM_ROLE=9 (roles 4-9 mislabeled Custom + deletable) · #899 legacy edit lock dropped (admin id=8 / <no rights> id=3 unsavable in legacy, modern edits any) · #900 Duplicate Role action missing · #901 show-event-history link missing · #902 delete confirmation drops affected-users + reset warning (`/{id}/users` shadowed by `/{id}`) · #903 demoMode read-only missing · #904 no success/failure feedback. Confirmed covered (admin + guest): grid + sort/search/pagination, create modal with rights checkboxes + Select All + validation, edit modal, delete modal (broken users per #902), system guard only ≤3 (wrong vs legacy), tabs, footer counts. |
| 5 | L1 | `usermanagement/usersAssignProject.html` | api/roles (`tproject-roles`) | | `lib/usermanagement/usersAssign.php` (featureType=testproject) | ✓ | #924 #925 #926 #927 #928 #929 #930 #931 #932 | 9 gaps (all task, OPEN): #924 access rights check missing (any auth user reads users+roles) · #925 update rights check missing (PUT privilege escalation, frank wrote role) · #926 effective/inherited role display dropped (`-- no role --` + corrupt `<inherited>` option) · #927 global-admin rows editable (legacy disables + 'Global Admin can not be changed') · #928 `admin` role id 8 offered in dropdown · #929 'Set roles to <role> + Do' bulk action missing · #930 DataTables pagination + search + sort missing · #931 no localized save feedback · #932 demoMode gating missing. Confirmed covered: project selector, active-user table, per-user role select defaulting to assigned, save writes delete+addUserRole (row verified), audit trail, tabs, session-preserved tproject_id, empty state, i18n assign.*. |
| 6 | L1 | `usermanagement/usersAssignPlan.html` | api/roles (`tplan-roles`) | | `lib/usermanagement/usersAssign.php` (featureType=testplan) + `usersAssign.tpl` | ✓ | #935 #936 #937 #938 #939 #940 #941 #942 #943 #944 #945 #946 #947 | 12 gaps (all task, OPEN): #935 access (read) rights check missing (any auth user reads users+roles) · #936 update rights check missing (PUT privilege escalation) · #937 plan dropdown not filtered by testplan_user_role_assignment/mgt_users (every plan offered incl. guest) · #938 'Set roles to <role> + Do' bulk assignment missing · #939 DataTables search + pagination missing · #940 global-admin rows editable (legacy disables + hint) · #941 `admin` role id 8 offered in per-user dropdown · #942 no localized save/empty/denied feedback (silent reload) · #943 demoMode gating missing · #944 effective/inherited role display: global-role fallback dropped (`No` vs `<inherited> guest`) · #945 tplan_id/featureID URL param ignored (opens with empty plan combo) · #946 usersAssignGlobalRoleColoring row colouring missing. Cleanup: #947 Delete legacy usersAssign.php (+usersAssign.tpl) — shared with usersAssignProject (row 5). Confirmed covered: project+plan combos, active-user table, per-user role select defaulting to assigned, save persists via PUT (BFF writes verified, audit event 'Test plan roles updated for plan #'), tabs, i18n assignPlan.*, empty state. Legacy E_WARNING roles.inc.php:410 was a fixture artifact (testplan nodes must hang under the testproject in nodes_hierarchy) and is NOT filed. |
| 7 | L1 | `cfields/cfieldsView.html` | api/cfields | | `lib/cfields/cfieldsView.php` | | | |
| 8 | L1 | `issuetracker/issuetrackerView.html` | api/issuetracker | | `lib/issuetracker/issuetrackerView.php` | | | |
| 9 | L1 | `codetracker/codetrackerView.html` | api/codetracker | | `lib/codetracker/codetrackerView.php` | | | |
| 10 | L1 | `plugins/pluginView.html` | api/plugins | | `lib/plugin/pluginView.php` | | | |
| 11 | L1 | `projectsView.html` | api/projects | | `lib/projects/projectsView.php` | | | |
| 12 | L1 | `cfields/cfieldsAssignView.html` | api/cfields | | `lib/cfields/cfieldsAssignView.php` | | | |
| 13 | L1 | `keywords/keywordsView.html` | api/keywords | | `lib/keywords/keywordsView.php` | | | |
| 14 | L1 | `platforms/platformsView.html` | api/platforms | | `lib/platforms/platformsView.php` | | | |
| 15 | L1 | `results/metricsDashboard.html` | api/metrics | | `lib/results/metricsDashboard.php` | | | |
| 16 | L1 | `inventory/inventoryView.html` | api/inventory | | `lib/inventory/inventoryView.php` | | | |
| 17 | L1 | `requirements/reqSpecMgmt.html` | api/reqspec | | `lib/requirements/reqSpecMgmt.php` | | | |
| 18 | L1 | `requirements/reqOverview.html` | api/reqspec | | `lib/requirements/reqOverview.php` | | | |
| 19 | L1 | `requirements/printReqSpec.html` | api/reqspec | | `lib/requirements/reqSpecPrint.php` | | | |
| 20 | L1 | `requirements/searchReq.html` | api/search | | `lib/requirements/searchReq.php` | | | |
| 21 | L1 | `requirements/searchReqSpec.html` | api/search | | `lib/requirements/searchReqSpec.php` | | | |
| 22 | L1 | `requirements/assignReqs.html` | api/requirements | | `lib/requirements/assignReqs.php` | | | |
| 23 | L1 | `requirements/reqMonitorOverview.html` | api/requirements | | `lib/requirements/reqMonitorOverview.php` | | | |
| 24 | L1 | `testcases/testSpec.html` | api/testcases | | `lib/testcases/listTestCases.php?feature=edit_tc` + `tcTree.tpl` (+ `archiveData.php`/`tcView.tpl`, `tcEdit.php`, `testcaseCommands.class.php`) | ✓ | #906 #907 #908 #909 #910 #911 #912 #913 #914 #915 #916 #917 #918 #919 | 12 gaps (all task, OPEN) + 2 bugs: #906 full step management (insert/copy/reorder/resequence + per-step exec type) · #907 design-time custom-field inputs (project CF 'Tier' missing) · #908 testcase STATUS select + write dropped · #909 edit-mode filter panel (id/title/suite/keyword/Or/And/Not/platform/status/importance/exec-type/CF + Apply/Reset/HideCF) · #910 drag-and-drop move/copy gated on mgt_modify_tc · #911 estimated-exec-duration field · #912 requirements assignment · #913 attachment upload/delete (modern lists read-only) · #914 version selection + lifecycle (single-version delete, freeze/unfreeze, activate/deactivate/setIsOpen) · #915 per-version platform assignment · #916 'add to test plan' action · #917 duplicate-name check/warning · #918 legacy tcView.tpl:105 Smarty error (breaks legacy View/Edit) · #919 modern keyword picker renders PHP object dumps. Confirmed covered (proj 1 fixture, 3 suites/4 cases, plan 20): tree load/count/expand, name filter, detail view (ver/extid/importance/exec-type/id/summary/preconditions/steps/keywords), edit modal Save/Cancel, create new TC, create new version, delete TC, Full Viewer link, mgt_view_tc write gating, executed-version 403 guard, i18n. |
| 25 | L1 | `testcases/tcView.html` | api/testcases | | `lib/testcases/tcView.php` | | | |
| 26 | L1 | `search/searchView.html` | api/search | | `lib/search/searchView.php` | | | |
| 27 | L1 | `search/searchAdvancedView.html` | api/search | | `lib/search/searchAdvancedView.php` | | | |
| 28 | L1 | `search/searchQuickView.html` | api/search | | `lib/search/searchAdvancedView.php#quick` | | | |
| 29 | L1 | `keywords/keywordsAssign.html` | api/keywords | | `lib/keywords/keywordsAssign.php` | | | |
| 30 | L1 | `results/tcCreatedPerUserOnTestProject.html` | api/results | | `lib/results/tcCreatedPerUserOnTestProject.php` | | | |
| 31 | L1 | `plans/planView.html` | api/plans | | `lib/plans/planView.php` | | | |
| 32 | L1 | `plans/buildsView.html` | api/builds | | `lib/plans/buildsView.php` | | | |
| 33 | L1 | `plans/planAddTCView.html` | api/plans | | `lib/plans/planAddTCView.php` | | | |
| 34 | L1 | `platforms/platformsAssign.html` | api/platforms | | `lib/platforms/platformsAssign.php` | | | |
| 35 | L1 | `execute/execHistory.html` | api/execute | | `lib/execute/execHistory.php` | | | |
| 36 | L1 | `plans/testUrgency.html` | api/plans | | `lib/plans/testUrgency.php` | | | |
| 37 | L1 | `plans/showNewestTcVersions.html` | api/plans | | `lib/plans/showNewestTcVersions.php` | | | |
| 38 | L1 | `plans/planMilestones.html` | api/milestones | | `lib/plans/planMilestones.php` | | | |
| 39 | L1 | `execute/execTest.html` | api/execute | | `lib/execute/execTest.php` | | | |
| 40 | L1 | `plans/planUpdateTC.html` | api/plans | | `lib/plans/planUpdateTC.php` | | | |
| 41 | L1 | `execute/tcExecAssignment.html` | api/execassignment | | `lib/execute/tcExecAssignment.php` | | | |
| 42 | L1 | `execute/tcAssignments.html` | api/tcassignments | | `lib/execute/tcAssignments.php` | | | |
| 43 | L1 | `results/resultsByTSuite.html` | api/reports | | `lib/results/resultsByTSuite.php` | | | |
| 44 | L1 | `results/baselineL1L2.html` | api/reports | | `lib/results/baselineL1L2.php` | | | |
| 45 | L1 | `results/resultsByTesterPerBuild.html` | api/reports | | `lib/results/resultsByTesterPerBuild.php` | | | |
| 46 | L1 | `results/neverRun.html` | api/reports | | `lib/results/neverRun.php` | | | |
| 47 | L1 | `results/resultsByStatus.html` | api/reports | | `lib/results/resultsByStatus.php` | | | |
| 48 | L1 | `results/tcasesWithCF.html` | api/reports | | `lib/results/tcasesWithCF.php` | | | |
| 49 | L1 | `results/resultsMatrix.html` | api/reports | | `lib/results/resultsMatrix.php` | | | |
| 50 | L1 | `results/testPlanReport.html` | api/reports | | `lib/results/testPlanReport.php` | | | |
| 51 | L1 | `results/resultsTCFlat.html` | api/reports | | `lib/results/resultsTCFlat.php` | | | |
| 52 | L1 | `results/resultsRequirements.html` | api/reports | | `lib/results/resultsRequirements.php` | | | |
| 53 | L1 | `results/casesWithoutTester.html` | api/reports | | `lib/results/casesWithoutTester.php` | | | |
| 54 | L1 | `results/tplanWithCF.html` | api/reports `tplan_with_cf` | | `lib/results/testPlanWithCF.php` + `testPlanWithCF.tpl` | ✓ | #847 #848 #858 #859 #860 #864 #865 #866 | 8 gaps total: #847 (suite grouping + collapsible group toolbar, OPEN) · #848 (direct edit dropped → read-only viewer, FIXED) · #858 (info text missing, FIXED) · #859 (generated-by-timestamp missing, FIXED) · #860 (doubled glue-char in external ID, FIXED) + re-analysis 2026-09-04 ×4 (runs 2-4 browser-verified, fresh fixtures proj 39/200, plans 57/58/59/209/210): #864 (per-column filters + Reset Filters) #865 (custom-field columns not sortable) #866 (multi-column sort / drag headers) — all re-confirmed, no new distinct gaps. Newly documented (no issue): legacy LAST-CF-empty row drop by `$hasValue=$cf_value?true:false` overwrite bug (testPlanWithCF.php:144-147) — modern shows strict superset; legacy 0-TC plan FATAL count(null) PHP8→HTTP 500 vs modern localized no_linked_tplan_cf; ExtTable toolbar (Expand/Collapse Groups, Show all Columns, Reset Default, Refresh, Reset Filters, MultiSort) + group-by-suite-(N Item) all missing in modern DataTable. Confirmed covered (browser-verified): testplan_metrics rights (BFF 403 verified), CF header cols + values, suite path, ext id single glue (TPWC-1), edit icon→tcEdit.html popup, non-empty-CF filter, empty states, footer + generated-on + elapsed, sort/search/pagination 25, tpwcf.* keys valid in all 10 bundles. |
| 55 | L2 | `testcases/tcView.html` | api/testcases | `results/tplanWithCF.html` | `lib/testcases/tcView.php` | | | |
| 56 | L1 | `results/absoluteLatest.html` | api/reports | | `lib/results/absoluteLatest.php` | | | |
| 57 | L1 | `results/generalMetrics.html` | api/reports | | `lib/results/generalMetrics.php` | | | |
| 58 | L1 | `results/assignedTcOverview.html` | api/reports | | `lib/results/assignedTcOverview.php` | | | |
| 59 | L1 | `results/charts.html` | api/reports | | `lib/results/charts.php` | | | |
| 60 | L1 | `results/freeTestCases.html` | api/reports | | `lib/results/freeTestCases.php` | | | |
| 61 | L1 | `results/resultsBugs.html` | api/reports | | `lib/results/resultsBugs.php` | | | |
| 62 | L1 | `results/execTimelineStats.html` | api/reports | | `lib/results/execTimelineStats.php` | | | |
| 63 | L1 | `auth/login.html` | api/auth | | `login.php` | | | |
| 64 | L1 | `documentation/documentation.html` | api/documentation | | `lib/documentation/documentation.php` | | | |
| 65 | L1 | `install/installView.html` | api/install | | `install/index.php` | | | |