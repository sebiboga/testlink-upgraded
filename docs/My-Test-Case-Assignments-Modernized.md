# My Test Case Assignments (tcAssignments) — Modernized Screen

Modernization of **My Test Case Assignments** (`lib/testcases/tcAssignedToUser.php`)
— GitHub issue [#660](https://github.com/sebiboga/testlink-upgraded/issues/660).

The legacy Smarty + ExtJS screen is replaced by a standalone Dashio page
(`gui/templates/execute/tcAssignments.html`) backed by a plain-PHP REST BFF
(`api/tcassignments/index.php`). The ASIDE **Execute → Test Cases Assigned to
Me** entry now points to the new HTML screen (switched in `getActions()` in
`lib/functions/common.php`).

**Path:** Execute → Test Cases Assigned to Me
**URL:** `gui/templates/execute/tcAssignments.html?tproject_id=<id>&tplan_id=<id>`
**BFF API:** `api/tcassignments/index.php`
**Rights:** `exec_testcases_assigned_to_me` enforced server-side on every read
route; writing quick results additionally requires `testplan_execute` on the
target test plan (legacy UI hid the icons without the right, but its raw
INSERT endpoint never re-checked it — fixed here).

![Initial state](images/tcAssignments-initial.png)

---
## Table of Contents
1. [What the screen does](#1-what-the-screen-does)
2. [REST API Reference](#2-rest-api-reference)
3. [Legacy parity notes](#3-legacy-parity-notes)
4. [i18n Keys](#4-i18n-keys)
5. [Security](#5-security)
6. [Testing](#6-testing)

## 1. What the screen does

| Feature | Legacy behavior | Modern implementation |
|---|---|---|
| Grouping | one ExtJS table per test plan, titled "Testplan: X" | one table per plan inside a single card, dark section header row per plan |
| Columns | [user], build, testsuite full path, testcase (prefix-glue-extid : name + version), platform (when plan has platforms), priority (when project `testPriorityEnabled`), last-exec status, [tester], due-since days | identical columns; status rendered as colored chip, priority as badge, days > 30 highlighted red |
| Assigned-to-me mode | default: current user's assignments across all ACTIVE plans of the project | mode button "Assigned to me" |
| Overview mode | direct-link arg `show_all_users=1` → ANYBODY user filter + user/tester columns | mode button "All users (overview)" |
| Closed builds | checkbox (only when no build filter) → `build_status=all`, persisted in `$_SESSION['show_closed_builds']` | same checkbox, same session key via BFF |
| Inactive plans | excluded unless `show_inactive_tplans=1`; explicit tplan/build selection overrides | plan selector marks "(inactive)"; selecting a plan explicitly overrides the status filter (same spirit as legacy `build_id` handling) |
| Quick results | P/F/B icons POST back to the page and raw-INSERT an executions row (NO right re-check!) | P/F/B icons → `POST /quick_result`; validates right + version↔plan↔platform linkage + build∈plan before insert, `execution_type=manual` |
| Exec popup | exec icon → execSetResults.php window with caller tag | same URL opened in new tab |
| History popup | history icon → execHistory.php window | same URL in new tab |
| Design popup | edit icon → archiveData.php edit-testcase window | same URL in new tab |
| Empty state | "no_records_found" | centered empty card |

## 2. REST API Reference

All routes require an authenticated session; callers without
`exec_testcases_assigned_to_me` get `403 {"status":"error","message":"Insufficient rights"}`.

### GET /init?tproject_id=
Context: project `{id,name,priorityEnabled}`, plans `[ {id,name,active,is_public} ]`,
users list for the overview column, persisted `showClosedBuilds` flag, current user.

### GET /rows?tproject_id=&tplan_id=&show_all_users=&show_closed_builds=&show_inactive_tplans=&user_id=&build_id=
Assignment rows grouped per test plan via `testcase::get_assigned_to_user()`
(`mode=full_path`); filters replicate legacy `initFilters()`:
`tplan_status = active|all`, `build_status = open|all`, optional `build_id`.
Per row: assignee login, build, suite path, prefix/ext id/name/version,
platform, priority level, last-execution status key + tester login
(via `get_last_execution()`), days since assignment, per-row `can_exec`
(right + `exec_cfg->exec_mode->tester` parity). Session persistence of
`show_closed_builds` mirrors `init_args()`.

### POST /quick_result
Body `{tproject_id,tplan_id,platform_id,build_id,tcversion_id,result}`.
Writes one `executions` row after validating: `testplan_execute` right,
`result` ∈ configured status codes, tcversion linked to plan+platform
(`testplan_tcversions`), build belongs to plan, version exists.

## 3. Legacy parity notes

- Data source unchanged: same manager method and option/filter semantics.
- `exec_mode->tester='assigned_to_me'` restricts BOTH the icons and the BFF
  write to rows assigned to the caller (legacy only restricted display).
- Deliberate deviation: platform column hidden when a plan has NO linked
  platforms (legacy `!is_null()` check also matched empty maps → empty column).
- The legacy quick-result raw INSERT had zero authorization checks on POST;
  the modern endpoint enforces rights and linkage validation server-side.

## 4. i18n Keys

All labels under the `tca.*` namespace, added to ALL 10 locale bundles
(en, ro, de, fr, es, it, ja, pt, ru, zh): header/headerOverview, toolbar
(testProject/modeMe/modeAll/testPlan/showClosedBuilds/allTestPlans/inactive),
columns (colUser/colBuild/colSuite/colTestCase/colPlatform/colPriority/
colStatus/colTester/colDueSince/colTestplan), actions (execution/
executionHistory/design/quickPassed/quickFailed/quickBlocked),
states (stPassed/stFailed/stBlocked/stNotRun/prioLow/prioMedium/prioHigh),
feedback (noRecords/assignmentsCount).

## 5. Security

- Session auth on every route (401 otherwise), `bffSameOriginGuard()` CSRF
  defense for POSTs, JSON-only I/O.
- Rights enforced server-side (`exec_testcases_assigned_to_me` view gate =
  aside visibility grant; `testplan_execute` write gate).
- All ids intval-cast; result value validated against the configured status
  map; linkage checks prevent cross-plan execution forging (legacy hole).

## 6. Testing

Suite appended to `tmp/TLU_Test_Cases.md` (**Suite 660 — 12/12 PASS**):
init context, default mode, overview mode, closed-builds toggle, inactive-plan
selection, quick-result DB write, assigned_to_me parity, popup URLs, rights
gate 403, i18n validity ×10 bundles + ro locale rendering, Event Viewer clean
after fixing the `is_open` E_WARNING caught during testing, aside link switch
verified end-to-end inside the mainframe.
