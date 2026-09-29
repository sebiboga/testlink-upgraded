# TestLink 2.0.1 — Test Cases

## Task — Issue #1026: hierarchical requirement-spec tree in reqSpecMgmt (gap vs legacy)

**Precondition** (recreate on every run, DB is freshly imported):
```bash
mysql -h 127.0.0.1 -utestlink -ptestlink testlink < /tmp/opencode/fixture_1026.sql
```
Fixture: TP 1 "RefSuite" with `RS-100 Inventory Management Spec` (REQ-101, REQ-102
+ nested `RS-200 Nested Order Spec` with REQ-201) and top-level
`RS-300 Export Compliance Spec` (REQ-301). Login `admin/admin`.
Screen: `http://localhost:8082/gui/templates/requirements/reqSpecMgmt.html?tproject_id=1`

| # | Steps | Expected (legacy) | Actual | Result |
|---|---|---|---|---|
| 1026-1 | open the screen, read the Reqs badge of RS-100 | **3** (2 own + 1 in the nested RS-200) — `treeMenu.inc.php:2149-2150` | `3`, tooltip "3 requirement(s) in this specification and its child specifications (2 direct)" | PASS |
| 1026-2 | read the header total line | project total = **4** (`get_all_requirement_ids`) | "total requirements in RefSuite: 4" | PASS |
| 1026-3 | check the row order | RS-200 indented **under** RS-100, RS-300 at top level | RS-100 (depth 0, "2 sub"), RS-250 (depth 1), RS-200 (depth 1), RS-300 (depth 0) | PASS |
| 1026-4 | click the `-` toggle on RS-100 | only its children disappear | rows → RS-100, RS-300 | PASS |
| 1026-5 | click **Expand all** | all children return | RS-100, RS-250, RS-200, RS-300 | PASS |
| 1026-6 | click **Collapse all** | every spec with children folds | RS-100, RS-300 | PASS |
| 1026-7 | collapse RS-100, reload the page | collapse state restored per project (`localStorage`) | 2 rows after reload, key `TL_req_spec_mgmt_collapsed_1 = ["10"]` | PASS |
| 1026-8 | **Create Spec** → open the Parent select | dotted-path list, "top level" default | options `0`, `10 RS-100 (1)`, `11   RS-200`, `12 RS-300` | PASS |
| 1026-9 | create `RS-250` with parent = RS-100 | created **nested** under RS-100 | HTTP 200 `{"status":"ok","id":26}`; RS-100 badge → "2 sub" | PASS |
| 1026-10 | **Edit** RS-100, look at the Parent select | own parent shown, itself never offered as its own parent | value `0`, select disabled, RS-100 absent from the options | PASS |
| 1026-11 | `POST action=specs` with `filter_*` (regression #1025) | identical to pre-change behaviour | identical (0 matches — the fixture has no `req_versions`↔`nodes_hierarchy` version nodes; verified the same on the **baseline** commit) | PASS |
| 1026-12 | `php -l api/reqspec/index.php`, `node --check` on the screen JS, `python3 -m json.tool` on all 10 bundles | clean | all clean | PASS |

**Bug found while testing (fixed in this run):** 1026-9 first returned **HTTP 500**
(empty body) for every `create_spec` with a `parent_id` — `needOwnedSpec()`'s dead
`requirement_spec_mgr::get_by_id()` call fatals without a `latest_rspec_revision`
view row. Fixed in `api/reqspec/index.php:125-146`.

## Task — Issue #1707: show-only-authorized-users row filter in the Assign Roles screens (gap vs legacy)

**Precondition** (recreate on every run, DB is freshly imported):
```bash
php tmp/fixtures_1707.php
```
Fixture: PRIVATE tproject `AN1707` (#10) with plans #11 (public) / #12 (private) and users
admin(8), an1707designer(4), an1707guest(5), an1707leader(9 + explicit plan/project role 6),
an1707tester(7) — 3 of the 5 non-admin rows resolve to effective role 3 (`<no rights>`).
Control project `AN1707PUB` (#13, plan #14, PUBLIC) — 0 `<no rights>` rows.
Login `admin/admin`.
Screens:
- `http://localhost:8082/gui/templates/usermanagement/usersAssignPlan.html?tproject_id=10&tplan_id=12`
- `http://localhost:8082/gui/templates/usermanagement/usersAssignProject.html?tproject_id=10`

| # | Steps | Expected (legacy) | Actual | Result |
|---|---|---|---|---|
| 1707-1 | open the plan screen on the PRIVATE plan, count rows | 5 rows (3 greyed `not_authorized_user`) | 5 rows; info `Showing 1 to 5 of 5 entries` | PASS |
| 1707-2 | tick **Show only authorized users** | only the authorized rows remain (legacy `toggleRowByClass` hid the marker rows) | 2 rows (admin, an1707leader); info `Showing 1 to 2 of 2 entries (filtered from 5 total entries)` | PASS |
| 1707-3 | read the footer | — (new) count of hidden rows | `5 users — 3 unauthorized user(s) hidden` | PASS |
| 1707-4 | untick the box | all 5 rows return, footer back to `5 users` | 5 rows, `Showing 1 to 5 of 5 entries`, footer `5 users` | PASS |
| 1707-5 | filter ON, type `an1707designer` in the DataTables search box | search and filter compose (AND) | 0 rows + localized `No authorized user matches the current filter.` | PASS |
| 1707-6 | filter ON, type `admin` | only authorized rows matching the term | 1 row (`Showing 1 to 1 of 1 entries (filtered from 5 total entries)`) | PASS |
| 1707-7 | filter ON, click the Login column header (sort) | filter survives the redraw | still 2 rows, order toggles | PASS |
| 1707-8 | filter ON, bulk *Set roles to tester* + **Do** | hidden `<no rights>` rows NOT touched | `users[]`: uids 2,3,4 keep `roleVal` 3, `changed:false`; only authorized uid 5 changes to 7 | PASS |
| 1707-9 | filter ON, **Save Changes** (plan) | hidden rows absent from the payload | PUT body `{"tplan_id":12,"assignments":{"5":7}}`; DB `user_testplan_roles` 5/12/6 → `5/12/7`; nothing written for 2,3,4 | PASS |
| 1707-10 | after Save reloads the grid, check the box | filter state persists across the reload | checked, 2 rows, footer `5 users — 3 unauthorized user(s) hidden` | PASS |
| 1707-11 | open the project screen on the PRIVATE project, tick the box | same behaviour on the 4-col grid | 2 rows; footer `5 users — 3 unauthorized user(s) hidden` | PASS |
| 1707-12 | project screen, filter ON, bulk role 7 + Do, then **Save** | hidden rows excluded | PUT body `{"tproject_id":10,"assignments":{"5":7}}`; DB `user_testproject_roles` 5/10 → `5/10/7` | PASS |
| 1707-13 | open the plan screen on the **AN1707PUB** public project/plan (#13/#14), tick the box | control: nothing to hide | 5 rows, `Showing 1 to 5 of 5 entries`, footer `5 users` (no hidden suffix) | PASS |
| 1707-14 | simulate `pagination->enabled = false` (`paginationCfg.enabled=false`, destroy the DataTable), toggle the box | page must still filter (legacy per-row hide) | off: all visible; on: uids 2,3,4 `display:none`, uids 1,5 visible; footer correct | PASS |
| 1707-15 | switch locale to `ro` (`&locale=ro`) | label + messages localized | `Afișează doar utilizatorii autorizați`; `TLi18n.t('assign.unauthorizedHidden',{n:2})` = `2 utilizator(i) neautorizați ascunși` | PASS |
| 1707-16 | `node --check` on both extracted scripts, `python3 -m json.tool` on all 10 bundles | clean | all clean | PASS |
| 1707-17 | browser console + Event Viewer (`events` table) | no new Error/Warning | 0 console errors/warnings; `SELECT log_level,COUNT(*) FROM events GROUP BY log_level` = only `16` (AUDIT) | PASS |
| 1707-18 | *(review fix)* plan/project screen, clear the Test Project combo (empty state), then tick/untick **Show only authorized users** | toggle must not resurrect the previous context's rows | `#assignBody` rows stay 0, empty-state message stays visible after toggling | PASS |
| 1707-19 | *(review fix)* tick/untick the box without touching the search field, read `settings().oLanguage.sZeroRecords` | plain `assign.noUsers` when off, `assign.noAuthorizedUsers` when on (no wrong first-paint message) | `No users in this project.` ⇄ `No authorized user matches the current filter.` | PASS |
| 1707-20 | *(review fix)* inspect the marker column on the pagination-disabled path / pre-init markup | literal `0`/`1` column must never be visible | `getComputedStyle(a th.authz-col).display === 'none'`; with DataTables active the th/td are stripped from the DOM (5 visible columns) | PASS |

**Notes:** the 2 `log_level=1` rows seen mid-run were caused by a typo in the fixture script
(`tproject_id` instead of `testproject_id` in `user_testproject_roles`), not by the app; the typo was
fixed and those 2 self-inflicted rows removed. `tmp/fixtures_1707.php` already contains the fix.
