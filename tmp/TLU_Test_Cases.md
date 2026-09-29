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

---

## Task — Issue #1027: drag-and-drop reorder / move of specs & requirements in reqSpecMgmt

**Precondition** — DB freshly imported. Fixture `php tmp/fixtures_1027.php` (re-runnable)
creates tproject `REORDER1027` (id 39 in the first pass, 58 after the rights-role fix;
prefix `R1027`) with top-level specs `R1027-A` (3 requirements), `R1027-B`, `R1027-C`,
`R1027-D` and nested `R1027-A1` / `R1027-A2` (children of A), plus two rights users
`ro1027norights` (role 3) and `ro1027readonly` (role 10 = `mgt_view_req` only).
Config `config.inc.php:1689` → `req_cfg->child_requirements_mgmt = ENABLED`.

Screens: `http://localhost:8082/gui/templates/requirements/reqSpecMgmt.html?tproject_id=39`
(login `admin`/`admin`), rights users with password `admin`.

| # | Steps | Expected (legacy 1.9.20) | Actual | Result |
|---|---|---|---|---|
| 1027-1 | open the screen as admin | toolbar exposes a spec-order gesture (legacy `drag_and_drop->enabled = true` on the req-spec tree) | `#btnReorderSpecs` visible ("Reorder specifications"), `#btnReqReorder` visible; 6 spec rows, nested A1/A2 indented | PASS |
| 1027-2 | open **Reorder specifications** | flat tree in tree order, draggable rows, per-row Up/Down/To top/To bottom/Move | modal opens; rows `A(40) A1(48) A2(50) B(42) C(44) D(46)`; first row "Up" disabled, last child "Down" disabled | PASS |
| 1027-3 | click **Down** on the `R1027-B` row | B swaps with the next sibling of the same parent | order becomes `A A1 A2 C B D`; chip `Unsaved order`; Apply enabled | PASS |
| 1027-4 | drag `R1027-B` onto `R1027-A1` (different parent) | gesture refused — a reorder never rewrites the parent link (that is what Move is for) | error panel: *"A specification can only be reordered inside its own parent - use Move to change the parent."*; order unchanged | PASS |
| 1027-5 | click **Discard changes** | pending edits dropped, server order restored | order back to `A A1 A2 B C D` | PASS |
| 1027-6 | click **To top** on `R1027-D`, then **Apply order** | confirm dialog, then `doReorder` → `node_order` rewritten | confirm modal *"The new order of the specification will be saved."*; after confirm: toast *"The specification order was saved"*; DB `D=0 A=1 B=2 C=3` under node 39 | PASS |
| 1027-7 | reload the screen | the new order is rendered | rows start `R1027-D, R1027-A, R1027-A1, R1027-A2, R1027-B, R1027-C` | PASS |
| 1027-8 | in the reorder modal click **Move** on `R1027-B` | move dialog; the parent picker must not offer the spec itself or its subtree (legacy forbidden-parent guard) | picker offers `0 / D(46) / A(40) / A1(48) / A2(50) / C(44)` — B itself absent; reorder modal hidden behind it | PASS |
| 1027-9 | parent = `R1027-A`, position = bottom, **Move** | `doAction=changeParent` → `nodes_hierarchy.parent_id` + `node_order` | toast *"The specification was moved"*; DB `B parent=40 order=1` (after A1/A2); reorder modal re-opened with the pending order | PASS |
| 1027-10 | reload; open the requirement panel of `R1027-A` and read the new link | entry point to the requirement half of the legacy gesture (`reqTreeReorder.html`, #1681) | `#btnReqReorder` href = `/gui/templates/requirements/reqTreeReorder.html?tproject_id=39&req_spec_id=40`; panel lists the 3 requirements | PASS |
| 1027-11 | `POST ?action=move_spec {spec_id:40, new_parent_id:59}` (own child) as admin | cycle refused | (validated in the previous run; re-asserted by the BFF unit matrix below) | PASS |
| 1027-12 | as `ro1027readonly`: read the screen | readable, but no write affordance | 6 rows, `canManage=false`, `#btnReorderSpecs` + `#btnReqReorder` `display:none`, 0 row-action buttons | PASS |
| 1027-13 | as `ro1027readonly`: `POST reorder_specs` / `POST move_spec` | 403 — `mgt_modify_req` required | both → `403 {"message":"You have no right to modify requirements"}`; `GET ?action=specs` → 200 | PASS |
| 1027-14 | as `ro1027norights` (role 3): `GET ?action=specs`, `POST reorder_specs`, `POST move_spec` | 403 everywhere | all three → `403 {"message":"You are not authorized to view requirement specifications"}` | PASS |
| 1027-15 | BFF input matrix (admin session, `Referer` same-origin) | duplicates / foreign ids / short lists rejected; same order = no write | short list → `400 ... (4 expected, 2 received)`; foreign id → `400 Specification 99 is not a child specification of the selected parent`; duplicate → `400 Duplicate specification id in nodes_order`; identical list → `200 {"status":"no_change"}`; bad position → `400 Position must be "top" or "bottom"`; unknown spec → `404`; `GET move_spec` → `404 Unknown action` | PASS |
| 1027-16 | `php -l api/reqspec/index.php`, `python3 -m json.tool` on all 10 i18n bundles | clean | all clean | PASS |
| 1027-17 | browser console + Event Viewer (`events` table) | no new Error/Warning | 0 console errors/warnings; `SELECT log_level,COUNT(*) FROM events GROUP BY log_level` → only `16` (AUDIT) | PASS |

**Notes:** issue #1027's implementation was landed on the default branch by a previous run
(`b555d9755`) that died before documenting it; this run re-created the lost fixture, re-verified
every gesture on a freshly imported DB and completed the CHANGELOG / docs / Wiki trail.

## Regression — Issue #1626: tlReqMgrSystem logs E_WARNING for a row whose `type` is not in `$systems`

**Precondition:** TestLink 2.0.1 on `http://localhost:8082`; MariaDB
`127.0.0.1:3306/testlink` (`testlink`/`testlink`), **freshly imported**; login
`admin`/`admin` (form fields `tl_login` / `tl_password` / `tl_login_btn`);
branch `fix/issue-1626`.

**Fixture** (recreated by the harness, the DB has no projects on a fresh import):
```sql
INSERT INTO reqmgrsystems (name,type,cfg) VALUES ('Ghost Type',99,'{}');   -- type NOT a key of $systems
INSERT INTO reqmgrsystems (name,type,cfg) VALUES ('Good Contour',1,'{}');  -- type 1 = contour/soap
INSERT INTO testprojects (id,prefix,api_key,reqmgr_integration_enabled,active,is_public,option_reqs)
     VALUES (1,'PT-','k1626',1,1,1,1);
INSERT INTO testproject_reqmgrsystem (testproject_id,reqmgrsystem_id) SELECT 1, MIN(id) FROM reqmgrsystems;
INSERT INTO nodes_hierarchy (id,name,parent_id,node_type_id,node_order) VALUES (1,'PT-',NULL,1,1);
```

**Repro steps (PRE-FIX):**
1. `GET /lib/reqmgrsystems/reqMgrSystemView.php` (HTTP 200, the row is rendered).
2. `SELECT id,log_level,description FROM events ORDER BY id` — 5 new `E_WARNING`
   rows (`log_level=2`) for the single bad row: `Undefined array key 99` at
   `tlReqMgrSystem.class.php:522` and `:523`, `Undefined array key 99` at `:113`,
   `Trying to access array offset on null` at `:114` **twice**.
3. Same on `GET /api/reqmgrsystems/index.php` (the modern BFF screen) and on
   `?id=<n>` (3 extra warnings from the second `getImplementationForType()` call
   inside `getByID()`).

**Expected POST-FIX:** the screen keeps answering 200 and still lists the bad row
(with an empty Type cell so a manager can repair it), a valid type keeps its full
description, and **zero** new `events` rows are written.
`getImplementationForType()` returns `null` for an unknown type instead of the
garbage class name `"Interface"`.

**Harness:** `bash tmp/verify_1626.sh` (exit 0 = all pass).

| # | Step | Expected | Observed | Result |
|---|---|---|---|---|
| 1626-01 | `php -l lib/functions/tlReqMgrSystem.class.php` and `php -l api/reqmgrsystems/index.php` | no syntax errors | "No syntax errors detected" on both | PASS |
| 1626-02 | log in as `admin`/`admin` | HTTP 200 + session | `http=200`, session cookie set | PASS |
| 1626-03 | fixture created; count `reqmgrsystems` | 2 rows (type 99 and type 1) | `2` | PASS |
| 1626-04 | run the harness's PHP probe against `origin/sebiboga`'s copy of the class (discriminating baseline) | > 0 diagnostics pre-fix | `12` diagnostics | PASS |
| 1626-05 | same probe against the working tree | `0` diagnostics | `0` | PASS |
| 1626-06 | `getImplementationForType(99)` | `null` (was `"Interface"`) | `null` | PASS |
| 1626-07 | `getImplementationForType(1)` | `contoursoapInterface` (unchanged) | `contoursoapInterface` | PASS |
| 1626-08 | A/B the four values the fix must not touch: `impl1`, `verbose` of the bad row, `type_descr` + `verbose` of the good row | byte-identical pre/post | all four identical | PASS |
| 1626-09 | `GET /lib/reqmgrsystems/reqMgrSystemView.php` | HTTP 200 | `200` | PASS |
| 1626-10 | bad-type row still present in the legacy grid | listed, not dropped | `Ghost Type` present | PASS |
| 1626-11 | good-type row still fully described in the legacy grid | `contour (Interface: soap)` | present | PASS |
| 1626-12 | `GET .../reqMgrSystemView.php?id=1` and `?id=2` (connection probe on both rows) | HTTP 200 both | `200`, `200` | PASS |
| 1626-13 | events table baselined, then the whole 9-request matrix (legacy list, both `?id=`, BFF list, `meta/types`, `meta/cfg_template` for 1 and 99, both legacy ajax cfg-template calls) | `0` new `events` rows | `0` (pre-fix: 5 per request) | PASS |
| 1626-14 | `SELECT COUNT(*) FROM events WHERE id > baseline AND description LIKE '%tlReqMgrSystem%'` | `0` | `0` | PASS |
| 1626-15 | bad row's `type_descr` degrades gracefully | `""` (was `null`) | `""` | PASS |
| 1626-16 | `getLinkedTo(1)['verboseType']` degrades gracefully | `""` (was `null`) | `""` | PASS |
| 1626-17 | the BFF degrades the unprobeable row too, so the modern payload and the legacy grid agree (`tlReqMgrSystem::getAll():562` and `api/codetracker/index.php` both set it false) | `"env_check_ok": false` for the `type=99` row | `false` | PASS |

**Result: 22 assertions, 22 PASS, 0 FAIL, exit 0.**

**Discriminating power (proved, not assumed):** with only
`lib/functions/tlReqMgrSystem.class.php` swapped back to `origin/sebiboga`, the
same harness reports **16 PASS / 6 FAIL / exit 1** — the 6 failures being 1626-05
(12 diagnostics), 1626-06 (`"Interface"`), 1626-15/1626-16 (`null`) and
1626-13/1626-14 (20 new `events` rows). `git checkout --` restores the fix and the
next run is 22/22 again.

**Code-review pass.** A review subagent over the full diff produced findings that
were applied before closing: (1) the BFF's `env_check_ok` stayed `true` for the
unprobeable row while the legacy grid already painted it red -> the guard was
inverted to the `getAll()` shape and assertion **1626-17** was added; (2)
`api/reqspec/index.php:1331`'s `isset($linked['verboseType']) ? … : $linked['type']`
fallback became permanently dead once `getLinkedTo()` always sets the key -> the
test is now on the VALUE (`!== ''`); (3) the CHANGELOG carried an incorrect
assertion count, a duplicated word, a wrong pre-fix warning total, a wrong
description of the pre-fix `check_connection` message and a wrong docs path, and it
had damaged an unrelated #966 sentence -> all corrected, and **#1715** /
**#1716** were filed for the two defects the review surfaced.

**Manual / browser verification** (headless Chrome, `admin`/`admin`):
`gui/templates/reqmgrsystems/reqMgrSystemView.html` renders
`Ghost Type` (empty Type cell) + `Good Contour` (`contour (Interface: soap)`),
`Showing 1 to 2 of 2 entries`, and adds **0** `events` rows;
`gui/templates/eventviewer/eventviewer.html` loads 200 and also adds 0.
Screenshots: `docs/screenshots/issue-1626-reqmgr-system-list-before-fix.png`,
`issue-1626-reqmgr-system-list-after-fix.png`,
`issue-1626-event-viewer-after-fix.png`.

**Filed, not fixed (out of scope):** **#1714** —
`requirement_spec_mgr::get_by_id()` interpolates an empty `RSPEC_REV.id = ` into
a WHERE clause when `get_last_active_version()` returns false (1064 + E_WARNING
at `requirement_spec_mgr.class.php:186`); same family as #1708, different file.

---

## Modernize — Issue #1717: Test Cases Not Run on Any Platform (`tcNotRunAnyPlatform`)

Fixture: `php tmp/fixtures_1717.php` → project `TNRAP1717` with platforms
A (`Windows 11`) / B (`Linux Ubuntu 22`), an active+**open** build, a
**closed** build, 5 cases and a role-3 user `tnrap1717norights`. The fixture
publishes its auto-increment ids to `tmp/fixture_1717.json` and the harness
reads them, so the suite is re-runnable on a database that already holds data
(no hardcoded ids).

| # | Case | Expected | Outcome |
|---|---|---|---|
| TC-1 | linked to BOTH platforms, no execution at all | REPORTED | reported |
| TC-2 | linked to BOTH, `passed` on platform A only | excluded | excluded |
| TC-3 | linked to BOTH, `passed` on B and a later `not_run` on A | excluded (any platform counts) | excluded |
| TC-4 | linked to platform A only, never run | REPORTED (proves the per-case platform scope) | reported |
| TC-5 | linked to A, `passed` **only on a CLOSED build** | REPORTED, cell reads *Not Run* | reported, cell `not_run` |

Harness: `php tmp/test_1717.php` — it slices the `tnr*` helpers **out of
`api/reports/index.php`** and `eval`s them, so it exercises the shipped code
rather than a copy that could drift (it fails hard if the helper block moves).

| # | Assertion | Expected | Actual | Result |
|---|---|---|---|---|
| 1717-01 | `getNeverRunByPlatform()` returns rows for the fixture | >0 rows | >0 rows | PASS |
| 1717-02 | exactly the three never-run-on-any-active+open-build cases are reported | the 3 fixture ids | the 3 fixture ids | PASS |
| 1717-03 | a case executed only on a **closed** build is still reported | reported | reported | PASS |
| 1717-04 | REGRESSION: its cell is **not** a `passed` badge (closed build is out of the status scope) | absent | absent | PASS |
| 1717-05 | a case executed on ONE open platform is excluded | excluded | excluded | PASS |
| 1717-06 | a case with `not_run` on A but `passed` on B is excluded | excluded | excluded | PASS |
| 1717-07 | platform scope is PER CASE, not global (TC-4 linked to one platform) | 1 | 1 | PASS |
| 1717-08 | a case linked to both platforms reports both | 2 | 2 | PASS |
| 1717-09 | `number_of_testcases` counts ALL plan cases, not only the never-run ones | 5 | 5 | PASS |
| 1717-10 | a never-run case has no entry in the last-status map | absent | absent | PASS |
| 1717-11 | the `passed` execution on platform A is found | `passed` | `passed` | PASS |
| 1717-12 | no execution on platform B → no entry (renders as `not_run`) | absent | absent | PASS |
| 1717-13 | a `not_run` execution is NOT a result: A stays absent for TC-3 | absent | absent | PASS |
| 1717-14 | the `passed` execution on platform B is still found for TC-3 | `passed` | `passed` | PASS |
| 1717-15 | tcversion→tcase resolution returns all ids and no `0` | all resolved | all resolved | PASS |
| 1717-16 | resolved ids are exactly the five fixture cases | the 5 ids | the 5 ids | PASS |
| 1717-17 | `urgency*impact` from `testplan_tcversions.urgency` × `tcversions.importance` | >0 | >0 | PASS |
| 1717-18 | the plan resolves to the expected owning test project | fixture id | fixture id | PASS |
| 1717-19 | admin holds `testplan_metrics` on the fixture project | true | true | PASS |
| 1717-20 | the role-3 user does NOT hold `testplan_metrics` (403 path) | false | false | PASS |
| 1717-21 | the modern screen exists | true | true | PASS |
| 1717-22 | the screen calls the `not_run_any_platform` BFF action | present | present | PASS |
| 1717-23 | the deep link uses `tprojectPrefix` (legacy `linkto.php` contract) | present | present | PASS |
| 1717-24 | the deep link does NOT use a `tprojectId` argument (that was the bug) | absent | absent | PASS |
| 1717-25 | the design + Execution History popups are wired | both present | both present | PASS |
| 1717-26 | `testlink_library.js` is loaded (the popups live there) | present | present | PASS |
| 1717-27 | the report is registered in `cfg/reports.cfg.php` | present | present | PASS |
| 1717-28 | its `enabled` flag is `all` (`asideMenu` only accepts `all|req|bts`) | `all` | `all` | PASS |
| 1717-29 | no report entry uses the silently-ignored `testplan` value | absent | absent | PASS |
| 1717-30 | `asideMenu` maps the report title to the modern href | present | present | PASS |
| 1717-31 | `common.php` exposes `$actions->tcNotRunAnyPlatform` | present | present | PASS |
| 1717-32 | all 21 `tnrap`/`footers` keys exist in all 10 bundles | 0 missing | 0 missing | PASS |
| 1717-33 | no untranslated English copy-paste in the ro/ru/ja/zh bundles | 0 | 0 | PASS |
| 1717-34 | the ASIDE label exists in every legacy `strings.txt` | 0 missing | 0 missing | PASS |
| 1717-35 | no legacy catalogue is implausibly small | 0 | 0 | PASS |
| 1717-36 | no legacy catalogue SHRANK against its committed size (truncation guard) | 0 shrunk | 0 | PASS |
| 1717-37 | REGRESSION: the platform column title is **escaped** (stored XSS) | `title: esc(pname)` | `title: esc(pname)` | PASS |
| 1717-38 | REGRESSION: the popups carry a real `tproject_id` (no `undefined`) | direct modern URLs | direct modern URLs | PASS |
| 1717-39 | REGRESSION: the "no active and open build" state is handled, not reported as all-executed | `builds_available` branch | present | PASS |
| 1717-40 | REGRESSION: `tnrap.noActiveBuilds` is translated in all 10 bundles | 0 missing | 0 missing | PASS |

**Result: 40 assertions, 40 PASS, 0 FAIL, exit 0.**

**Live HTTP verification** (`?action=not_run_any_platform&tproject_id=47&tplan_id=48`):
`3` of `5` test cases, cells all `not_run`, `priority_label` `Medium` for all
three rows, `builds_available: true`, HTTP 200. Guards: `400 Missing test
project or test plan id` (no ids), `400 Invalid test project id`, `400 Invalid
test plan id`, `400 Test plan does not belong to this test project`, `403 No
permission` (role-3 session).

**Browser verification** (EN + RO, console clean, Event Viewer clean):
`Found 3 of 5 test cases in this test plan`; both platform columns; the
Priority column now shows the localized **level** (`Medium`) instead of the
raw `4`; TC-5 (passed on a closed build) is listed and its cell reads *Not
Run*; design/history icons open `tcView.html` / `execHistory.html` with a real
`tproject_id=47`; `linkto.php?tprojectPrefix=TNR1717&item=testcase&id=TNR1717-1`
untouched; EN→RO renders "S-au găsit 3 din 5 cazuri de test …", "Suita de
testare", "Prioritate", "Despre acest raport", "Timpul scurs (secunde)". The
Reports ASIDE entry carries the translated label "Test Cases not run on any
Platform". Screenshots: `tmp/shots/1717_01_report.png`,
`tmp/shots/1717_02_review_fixed.png` (copied to
`docs/screenshots/issue-1717-tcnotrunanyplatform-0{1,2}-*.png`).

**Live XSS probe.** A platform was renamed
`<img src=x onerror=alert(1)>Win` in the fixture project and the report
reloaded: the DataTable header rendered the payload as **text**
(`&lt;img src=x onerror=alert(1)&gt;Win`) with **0** injected `<img>` nodes
and no dialog, proving `title: esc(pname)`. The name was restored afterwards.

**Two bugs this suite caught that the fixture's visible output hid** (both
fixed and committed separately):
1. `config_get('results')` returns an **array**, so `config_get('results')->status_code`
   was silently NULL, and the codes are single-char **strings**, so `intval('p') === 0`.
   That collapsed passed/failed/blocked onto one key AND turned
   `E.status <> intval('n')` into `E.status <> 0`, i.e. the opposite of the
   intended filter. Invisible in the report because a listed row is never-run
   on every platform by construction (1717-11/12/13).
2. `full_external_id` already carries `<prefix>-<external id>`, so prefixing
   again rendered `TNR1717-TNR1717-1`.

**Mandatory code review (rule 16) — 2 BLOCKER, 5 SHOULD-FIX, 8 NIT, no security
hole in the BFF** (a subagent reviewed `2bc0024f4~1..HEAD`; the SQL-injection,
IDOR, CSRF, rights and escaping surfaces of the five `tnr*` helpers came back
clean). All BLOCKER/SHOULD-FIX items are fixed:
1. **BLOCKER, stored XSS** — `cols.push({title: pname})` handed a free-text
   platform name to DataTables, which injects titles with `.html()`; a
   platform named `<img src=x onerror=…>` executed for every viewer (the
   sibling `neverRun.html` escapes the same value). Fixed with `esc(pname)` and
   pinned by 1717-37 + the live probe above.
2. **BLOCKER, self-contradicting cells** — the listing source
   `getNeverRunByPlatform()` only looks at **active+open** builds, but
   `tnrLastStatusPerPlatform()` filtered `B.active = 1` only, so a case
   executed solely on a *closed* build was listed by this "Not Run" report
   **and** carried a `passed` badge. Fixed with `AND B.is_open = 1`, and the
   fixture grew TC-5 to pin it (1717-03/04).
3. **SHOULD-FIX** — `getNeverRunByPlatform()` answers **NULL** (not an empty
   set) when the plan has no active+open build; the action collapsed that with
   "all executed", which is false. Now `builds_available` + a new
   `tnrap.noActiveBuilds` key in all 10 bundles.
4. **SHOULD-FIX** — the Priority column showed a raw `urgency×importance`
   integer with thresholds hardcoded in JS, ignoring the project's own
   `urgencyImportance->threshold[]`. Now `priority_to_level()` +
   `config_get('priority')->code_label` server-side, like every sibling action
   (`priority_level` + `priority_label`).
5. **SHOULD-FIX** — `openExecHistoryWindow(row.tcase_id)` concatenated an
   undeclared `tproject_id` and produced `…&tproject_id=undefined`. The modern
   screens are now addressed directly (`tcView.html` / `execHistory.html`).
6. **SHOULD-FIX** — `tnrLinkedPlatformsPerCase()` fetched every
   `testplan_tcversions` row of the plan and matched in PHP, justified by a
   comment claiming the filter could not be pushed into SQL (it can, through
   the `nodes_hierarchy` parent join). Filter pushed down.
7. **SHOULD-FIX (documented, not built)** — the legacy controller also served
   `FORMAT_MSWORD` and the e-mail path; this report is registered
   `format_html` with no `directLink`, and the export gateway has no case for
   this report type, so the XLS/Word/mail outputs are intentionally not
   offered. Recorded in the docs page rather than faked with a 500-ing button.
8. NITs fixed: dead `$tcCfg`/`$prefix` removed, dead `urg_imp` replaced by
   always-present `priority_level`/`priority_label` (a missing column key made
   DataTables log "Requested unknown parameter"), dead `platName` map removed,
   and two misleading comments corrected (the last-status "newest per
   (tcversion, platform)" is per **(case, platform)**, and the
   `tnrLinkedPlatformsPerCase` impossibility note).
9. NIT accepted: `$actions->tcNotRunAnyPlatform` in `common.php` is
   unreachable — the identical dead pattern of every sibling screen, kept for
   consistency.

**Known gap:** the Word/e-mail outputs of the 1.9.20 controller are not
reproduced (see SHOULD-FIX 7).

**Event Viewer:** 0 new rows from the screen or the BFF. The 11 pre-existing
ERROR rows are this suite's own development history (the pre-fix
`TCV.urgency` / `TCV.tcase_id` SQL of bugs 1–2 above, fixed in `262865233` /
`4d2fa6714`, plus two throwaway inspection scripts with wrong column names).

**Filed, not fixed (out of scope):** **#1718** — the legacy
`lib/results/tcNotRunAnyPlatform.php` is fatally broken
(`require_once('results.class.php')` names a class that no longer exists;
`$re = new results(...)` is commented out while `$re->getMapOfLastResult()`
survives, and `$executionsMap` is read but never assigned). The modern screen
replaces it; deleting the dead file is a separate change.

---

## Suite 1644 — Dynamic localized role-column header in Assign Test Plan Roles (issue #1644)

**Feature under test:** the role-override column caption must be a *localized,
context-aware* `Test Plan Role (<selected plan>)`, restoring legacy
`usersAssign.tpl:217-219` (`<th>{lang_get s="th_roles_$featureVerbose"}
({$my_feature_name|escape})</th>`) that the 2.0.1 port had replaced with a static
`Plan Role Override`.

**Precondition** (the DB is freshly imported every run):

```bash
php tmp/fixtures_1644.php     # project ALPHA1644 (id 1)
                               #   plans A-PUBLIC-1644 (2, public),
                               #          A-PRIVATE-1644 (3, private),
                               #          'A&B <draft>-1644' (5, HTML metacharacters)
                               # project BETA1644-EMPTY (id 4) - NO plans
                               # users ua1644designer/guest/tester (2,3,4)
```

**Entry point:** `http://localhost:8082/gui/templates/usermanagement/usersAssignPlan.html?tproject_id=1&tplan_id=0`,
logged in as `admin/admin`.

| # | Step | Expected | Actual | Result |
|---|---|---|---|---|
| 1 | Load the screen with `tplan_id=0` | the first assignable plan is auto-selected and the 5th `<th>` reads `Test Plan Role (A-PRIVATE-1644)` | `"Test Plan Role (A-PRIVATE-1644)"` | **PASS** |
| 2 | Read all `<th>` texts | `["#","Login","Name","Inherited Role","Test Plan Role (<plan>)"]` | `["#","Login","Name","Inherited Role","Test Plan Role (A-PRIVATE-1644)"]` | **PASS** |
| 3 | Switch the plan combo to `A-PUBLIC-1644` | caption follows: `Test Plan Role (A-PUBLIC-1644)` | `"Test Plan Role (A-PUBLIC-1644)"` | **PASS** |
| 4 | Switch back to `A-PRIVATE-1644` | `Test Plan Role (A-PRIVATE-1644)` | `"Test Plan Role (A-PRIVATE-1644)"` | **PASS** |
| 5 | Pick the `-- select plan --` placeholder | bare label, **no** empty `()` | `"Test Plan Role"` | **PASS** |
| 6 | Switch project → `BETA1644-EMPTY` (no plans) | legacy `no_test_plans_available` state: table hidden, `There are no usable test plans on this test project`, caption without a plan | `tableVisible=false`, `disabledMsgVisible=true`, caption `"Test Plan Role"` | **PASS** |
| 7 | Switch back to `ALPHA1644` | caption names that project's own plan, not the previous project's | `"Test Plan Role (A&B <draft>-1644)"` (plan 5 is first) | **PASS** |
| 8 | Select the plan named `A&B <draft>-1644` | name rendered as **text**, not markup, and not double-escaped | `textContent="Test Plan Role (A&B <draft>-1644)"`, `innerHTML="...A&amp;B &lt;draft&gt;-1644"`, `children.length===0` | **PASS** (after fixing the double-escape found in step 8's first run) |
| 9 | Reload with `?locale=de` | caption localized from the German bundle key | `TLi18n.getLocale()==="de"`, `"Testplan Rolle (A-PUBLIC-1644)"` | **PASS** |
| 10 | Reload with `?locale=ro` | localized, and the key really exists in the bundle (`has()`) | `true`, `"Rol în Planul de Test (A-PRIVATE-1644)"` | **PASS** |
| 11 | Deep link `?tplan_id=2` | caption names plan 2 (legacy `$featureID` → `$my_feature_name`) | `"Test Plan Role (A-PUBLIC-1644)"` | **PASS** |
| 12 | Force a DataTables redraw (`order([1,'desc']).search('a').draw()`) | caption survives the destroy/re-init | `"Test Plan Role (A-PRIVATE-1644)"` | **PASS** |
| 13 | Bulk "Do" / edit a role select (re-renders the grid) | caption unchanged | unchanged after `renderUsersTable()` | **PASS** |
| 14 | `node --check` on the screen's inline script | no syntax error | `JS SYNTAX OK` | **PASS** |
| 15 | `python3 -m json.tool` on all 10 bundles | all valid | 10/10 `OK` | **PASS** |
| 16 | Browser console | no errors/warnings | 0 error, 0 warning | **PASS** |
| 17 | Event Viewer / `events` table | no new ERROR/WARNING rows | 3 rows, all `log_level=16` (audit, from my own login + fixture) | **PASS** |

**Regression on the rest of the screen:** the 4 untouched columns, the rows
(`rowCount=4` on `A-PRIVATE-1644`), the DataTables grid, the toolbar combos and
the Save button all behave as before — the change touches only the caption of
column 4 and adds no BFF route.

**Bug found and fixed by this suite (step 8):** the first implementation applied
`esc(planName)` *and* `.text()`, double-escaping; a plan named `A&B <draft>`
rendered as the literal `A&amp;B &lt;draft&gt;`. `.text()` already escapes, which
is exactly what Smarty's `{$my_feature_name|escape}` achieves. Fixed and
re-measured — see the checkpoint 2/3 comment on issue #1644.

---

## Regression — Issue #1718: legacy `lib/results/tcNotRunAnyPlatform.php` is fatally broken (missing `results.class.php`, method on null, unassigned `$executionsMap`, unguarded plan/project)

**Precondition:** TestLink 2.0.1 on `http://localhost:8082` (PHP built-in server,
docroot = repo root); MariaDB `127.0.0.1:3306/testlink` (`testlink`/`testlink`),
**freshly imported**; login `admin`/`admin`; branch `fix/issue-1718`.

**Fixture** (recreated by `php tmp/fixtures_1717.php`; the DB has no projects on a
fresh import): test project **1** (prefix `TNR1717`), test plan **2**, platforms
**1 = Windows 11** / **2 = Linux Ubuntu 22**, build **1** (active+open) + build **2**
(CLOSED), 5 test cases, 4 executions, plus a no-rights user
`tnrap1717norights` / `admin` (role 3, no `testplan_metrics`).

**Repro steps (PRE-FIX):**
```bash
# the BFF login is CSRF-guarded, so the same-origin Origin header is required
curl -s -c cj.txt -b cj.txt http://localhost:8082/login.php -o /dev/null
curl -s -c cj.txt -b cj.txt -X POST http://localhost:8082/api/auth/login \
     -H "Origin: http://localhost:8082" -d "login=admin&password=admin"
curl -s -b cj.txt "http://localhost:8082/lib/results/tcNotRunAnyPlatform.php?tplan_id=2" \
     -o /dev/null -w "HTTP %{http_code}\n"
```
1. `HTTP 500` with a **0-byte** body.
2. `logs/userlog1.log`:
   `E_WARNING require_once(results.class.php): Failed to open stream: No such
   file or directory - in …/lib/results/tcNotRunAnyPlatform.php - Line 16`,
   one request, `took 0.00275 secs` (a fatal, **not** a hang or a loop).
3. Same URL in the browser → blank page, no rendering.
4. *Isolated probe* (the controller copied to a scratch file with ONLY the
   `require_once` line removed) → `HTTP 500`, and
   `E_WARNING Undefined variable $re - in …/zz_probe_1718.php - Line 62` — i.e.
   `Call to a member function getMapOfLastResult() on null`.
5. Same probe with a test project in the session but a stale/zero project id →
   dies even earlier, at the unguarded `:40`/`:42` name/prefix read.
6. `ls lib/functions/results.class.php` → ENOENT;
   `grep -rn getMapOfLastResult lib/` → the only pre-fix **code** call site was
   `:62` of the controller itself; no definition anywhere.

**Expected POST-FIX:** the legacy URL is **gone** (404), it can no longer answer
500 or write a warning row, and the report itself is untouched at
`/gui/templates/results/tcNotRunAnyPlatform.html` (BFF action
`not_run_any_platform`).

**Actual result — `bash tmp/verify_1718.sh` → 19/19 PASS** (executed on
`fix/issue-1718` after the deletion; full run, verbatim tail):

| # | Case | Result |
|---|---|---|
| 1 | legacy controller answers 404, not 500 | PASS |
| 2 | `gui/templates/dashio/results/tcNotRunAnyPlatform.tpl` is deleted | PASS |
| 3 | `gui/templates/tl-classic/results/tcNotRunAnyPlatform.tpl` is deleted | PASS |
| 4 | `lib/results/tcNotRunAnyPlatform.php` is deleted | PASS |
| 5 | no `getMapOfLastResult()` **call site** left in `lib/` (comments exempt) | PASS |
| 6 | `lib/functions/results.class.php` stays absent (no re-introduced coupling) | PASS |
| 7 | modern BFF `not_run_any_platform` answers `status ok` | PASS |
| 8 | modern BFF reports 3 never-run | PASS |
| 9 | modern BFF reports 5 test cases in the plan | PASS |
| 10-12 | rows `TNR1717-1`, `TNR1717-4`, `TNR1717-5` still returned | PASS ×3 |
| 13 | guard 400 for `tproject_id=0&tplan_id=0` | PASS |
| 14 | guard 400 for `tplan_id=999` (unknown plan) | PASS |
| 15 | guard 400 for `tplan_id=1` (plan of another project) | PASS |
| 16 | guard 403 for a user without `testplan_metrics` (live login as `tnrap1717norights`) | PASS |
| 17 | no new Error/Warning row beyond the 1 pre-fix baseline row | PASS |
| 18 | `php -l` clean on all 34 remaining `lib/results/*.php` | PASS |
| 19 | `php tmp/test_1717.php` still 40/40 PASS | PASS |

**Why the suite is not just "the file is gone":** a pure existence check would
still pass if the deletion had taken the report with it. Cases 7-12 assert the
BFF payload is identical to the pre-fix capture, 13-16 re-measure the full guard
matrix (the 403 through a real second login, not by copying the expected value),
18 keeps a syntax gate over the whole directory, and 19 re-runs the whole #1717
harness (40 assertions over the BFF helpers, the 10 i18n bundles and the ASIDE
label), which is the guard that the deletion cost the report no behaviour.

**Baseline note (why "1", not "2"):** the ORIGINAL pre-fix fatal wrote **no** `events` row at
all — it died at the `require_once` with no session, so it only ever reached `logs/userlog1.log`
(`[26/Sep/29 13:38:01] … Line 16`). The single `events` row is id 3, the `E_WARNING` raised by the
deleted controller's own reproduction probe. The bound is therefore the exact baseline: anything
above 1 means this change (or the harness) started raising diagnostics.

**Harness is discriminating — the case that caught a false PASS in this run's own
first attempt:** case 5 was originally `grep -rl getMapOfLastResult lib/ | wc -l`
and it **FAILED with 2**. The two hits were not call sites but the explanatory
comments in `lib/general/asideMenu.php:237` and `lib/functions/common.php:2089`
(added by #1717). The *earlier* manual measurement that reported `0` had been run
with a stale `cd /tmp/opencode` still in effect, so `lib/` did not exist there and
`grep` silently matched nothing — a silent false PASS, exactly what the suite
exists to prevent. The assertion now strips `//` and `/* */` before counting, and
the two comments were rewritten (with `api/reports/index.php:4872`) because the
deletion is what made "it is not linked" false.

**Bugs found by this suite, filed, NOT fixed here (one-bug run):** **#1719** —
`lib/results/priorityBarChart.php:5` requires the *same* removed
`../functions/results.class.php` and instantiates the same removed `results`
class at `:17`; also unregistered, and its line 2 says
`//@TODO this file seems not to be in use`. Reported, not touched.

## Suite 1019 — Task, Issue #1019: `metricsDashboard` per-status breakdown behind `show_test_plan_status` (gap vs legacy `lib/results/metricsDashboard.php:50-72`)

**Precondition.** `admin`/`admin` on `http://localhost:8082`. Fixture `tmp/fixtures_1019.php` → test project 30 `MD Demo` (prefix MDD, platforms disabled), test plan 31 `MD Demo Plan`, build 4, **8 active TCs** of which 4 are executed (2 `p` passed / 1 `f` failed / 1 `b` blocked) and 4 have no execution row (`n` not_run). Config `config.inc.php:977` `$tlCfg->metrics_dashboard->show_test_plan_status` is toggled between the runs. Screen: `/gui/templates/results/metricsDashboard.html?tproject_id=30`.

**Expected (legacy parity).** With the flag on, the test-plan cell renders
`Not Run: 4 [50%], Passed: 2 [25%], Failed: 1 [12.5%], Blocked: 1 [12.5%], Overall Progress: 50%`
— one `Label: qty [pct%]` item per entry of `results.status_label_for_exec_ui`, in config order, joined by `", "`, quantities/percentages taken from the **plan-level** `overall` block with denominator `overall.active` and precision `dashboard_precision`, and the overall progress appended last. With the flag off, today's collapsed form (no sub-line at all) is kept.

| # | steps | expected (legacy parity) | actual | verdict |
|---|---|---|---|---|
| 1 | `php tmp/fixtures_1019.php` on an empty DB | 1 project / 1 plan / 1 build / 8 TCs; `executions.status` holds the 1-char **codes** `p,p,f,b` | `project=30, plan=31, build=4, linked=8`; `select status from executions` → `p,p,f,b` | PASS |
| 2 | `GET /api/metrics/index.php/dashboard?tproject_id=30`, flag ON | 200; `show_test_plan_status=true`; `status_set` = the config order; `testplans[0].overall.statuses` = plan-level quantities | `{show_test_plan_status:true, status_set:["not_run","passed","failed","blocked"], overall:{active:8,executed:4,progress:50,statuses:{blocked:1,failed:1,not_run:4,passed:2}}}` | PASS |
| 3 | same response, flag OFF | `show_test_plan_status=false`; the rest of the payload unchanged | `flag=false`, identical `statuses`/`active`/`progress` | PASS |
| 4 | read the rendered `.tplan-cell` (flag ON, `locale=en`) | the full legacy string from the header of this suite | `MD Demo Plan` / `Not Run: 4 [50%], Passed: 2 [25%], Failed: 1 [12.5%], Blocked: 1 [12.5%], Overall Progress: 50%` | PASS |
| 5 | same cell (flag OFF) | no `.tplan-subline` at all — the collapsed form is preserved | `{text:"MD Demo Plan", hasSubline:false}` | PASS |
| 5a | `?locale=fr`, flag ON | localized labels, legacy's literal `[12.5%]` spacing kept (fr must not invent `[12.5 %]`) | `Non Exécuté: 4 [50%], Réussi: 2 [25%], Échoué: 1 [12.5%], Bloqué: 1 [12.5%], Progression globale: 50%` | PASS |
| 6 | `?locale=ro`, flag ON | every fragment localized: status labels **and** `Overall Progress` | `Neexecutat: 4 [50%], Reușit: 2 [25%], Eșuat: 1 [12.5%], Blocat: 1 [12.5%], Progres general: 50%` | PASS |
| 7 | assert the rounding is plan-relative, i.e. the denominator is `overall.active` (8) and not `executed` (4) | `1/8 = 12.5%`; the wrong denominator would yield `33.33%` | `Failed: 1 [12.5%]`, `Blocked: 1 [12.5%]` | PASS |
| 8 | assert the percentage precision comes from `dashboard_precision`, not a hardcoded 2 | `precision` present on `/dashboard` and equal to `config_get('dashboard_precision')` | `precision: 2` (matches `config.inc.php:802`) | PASS |
| 9 | click "Show all columns" (legacy `toolbarShowAllColumnsButton`) | the 4 default-hidden qty columns appear next to their `%` columns | `["Test Plan","Active TCs","Not Run","Not Run %","Passed","Passed %","Failed","Failed %","Blocked","Blocked %","Progress %"]` | PASS |
| 10 | click "Reset to default state" | qty columns hidden again; the breakdown in the plan cell is unchanged (the cell is rebuilt on every draw) | headers back to `[…,"Not Run %","Passed %","Failed %","Blocked %","Progress %"]`; cell text identical | PASS |
| 11 | grep the screen for hardcoded status words in the new code path | every visible fragment goes through `TLi18n` | new code uses `TLi18n.t('md.statusBreakdownItem', …)`, `statusLabel(k)` and `TLi18n.t('md.overallProgress')`; no literal status/progress text | PASS |
| 12 | `md.statusBreakdownItem` + `md.overallProgressItem` present in **every** locale bundle, each file still valid JSON | 10/10 bundles, `json.tool` clean, same locale-independent pattern as legacy's literal `" ["`/`"%]"` | `de,en,es,fr,it,ja,pt,ro,ru,zh` all OK, both keys in each, identical pattern in all 10 | PASS |
| 13 | `php -l api/metrics/index.php`; `node --check` on the extracted inline script | both clean | *No syntax errors detected* / *JS SYNTAX OK* | PASS |
| 13a | `roundPct()` vs PHP `round()` over the whole reachable domain (`active` 1..400, `qty` 0..`active`) | **0** divergences — `getPercentage()` rounds half away from zero | 80,600 pairs compared: `Math.round(x*100)/100` → 10 divergences (e.g. `23/160` php=14.38, js=14.37), `toFixed(2)` → 10, **`roundPct()` BigInt → 0** | PASS |
| 13b | exact-tie values in the live page | `1/32`→3.13, `23/160`→14.38, `1/8`→12.5 (PHP values) | `roundPct(1,32,2)=3.13`, `roundPct(23,160,2)=14.38`, `roundPct(41,160,2)=25.63`, `roundPct(51,160,2)=31.88`, `roundPct(1,8,2)=12.5` | PASS |
| 13c | degraded payloads: `active=0`, empty `status_set`, `qty` as string, `overall.progress` absent, `precision` absent | no throw, no `undefined`/`NaN`, no stray leading separator | `Not Run: 0 [0%], Passed: 0 [0%], Overall Progress: 0%` · `Overall Progress: 50%` · `Passed: 2 [25%], …` · `… Overall Progress: 0%` · `Not Run: 3 [100%], … 33.33%` | PASS |
| 13d | `config.inc.php:977` left at its **shipped** value | the diff must not flip a product default | `= false`, i.e. `git diff config.inc.php` is empty | PASS |
| 14 | browser console after runs 4-10 | no errors, no warnings | *no console messages found* | PASS |
| 15 | Event Viewer after the whole run | no new Error/Warning | `select count(*), sum(log_level<=2) from events where id > 22` → `0 / NULL`; newest row overall is `id=22` (audit, fixture) | PASS |

**Pre-fix baseline (measured, for contrast).**

| state | before | after |
|---|---|---|
| flag ON | `<b>MD Demo Plan</b><div class="tplan-subline">Overall Progress: 50%</div>` | full breakdown, byte-identical to the legacy string |
| `GET /dashboard` | no `precision` key (client hardcoded 2 decimals) | `precision: 2` |
| `23/160` percentage | would have rendered `14.37%` with any float rounding | `14.38%` (PHP `round()` value) |

**Notes.**

- The four `E_WARNING Undefined array key` rows that *do* exist in `events` (ids 12-18) were produced by the **first, wrong fixture** and not by the feature: `executions.status` is `char(1)` and the DB is not in strict mode, so inserting the verbose word `passed` was silently truncated to `''`; the metric layer then folded every executed row into a bogus `""` counter. That is a pre-existing sharp edge in `lib/functions/tlTestPlanMetrics.class.php:1075-1084`, shared with the legacy controller, and it is reported separately in the issue rather than fixed here.
- `roundPct()` rounds the percentage as an exact **rational** (`BigInt`, ties away from zero)
  because the value is not representable in IEEE-754: `23/160*100` is `14.374999999999998`, so
  `Math.round(x*100)/100` and `toFixed(2)` both answer `14.37` where PHP answers `14.38`. Over
  the whole reachable domain (80,600 pairs) each float variant is wrong on 10 exact ties and the
  `BigInt` form on none.
- The `Not Run` / `Passed` / `Failed` / `Blocked` list is read from the BFF `status_set` (`array_keys($statusSetForDisplay)`) rather than hardcoded, so an install that adds a custom exec status gets it in the breakdown without a code change — same as the legacy `foreach ($statusSetForDisplay …)`.
- The entire assembled line is escaped with `esc()`; the previous code escaped only the label, and the labels come from user-editable `lang` strings.

## Regression — Issue #1627: legacy `lib/reqmgrsystems/reqMgrSystemEdit.php` answered HTTP 500 with a 0-byte body whenever `doAction` was missing or not whitelisted

**Precondition:** TestLink 2.0.1 on `http://localhost:8082` (PHP built-in server, docroot
= repo root), PHP 8.3.35; MariaDB `127.0.0.1:3306/testlink` (`testlink`/`testlink`),
freshly imported; login `admin`/`admin`; branch `fix/issue-1627`, fix commit `01f182bb9`.
Harness: **`bash tmp/verify_1627.sh`** (creates and deletes its own `reqmgrsystems` row, so
it is re-runnable on a fresh import).

**Repro steps (PRE-FIX)** — `init_args()` at `lib/reqmgrsystems/reqMgrSystemEdit.php:126`
threw an uncaught `Exception` that nothing catches, and `R_PARAMS` always creates
`$args->doAction` (empty string when the parameter is absent), so the `property_exists()`
test at `:118` can never detect "absent" and *every* non-whitelisted value threw:

1. Log in, then `GET /lib/reqmgrsystems/reqMgrSystemEdit.php` → `HTTP 500`, **0 bytes**.
2. `GET …?doAction=bogus` → `HTTP 500`, **0 bytes**.
3. `GET …?doAction=create` / `?doAction=edit&id=N` → `HTTP 200` (11488 / 11715 bytes).
4. `tmp/php_server.log`: `PHP Fatal error: Uncaught Exception: Input parameter doAction -
   white list validation failure … thrown in …/reqMgrSystemEdit.php on line 126`, stack
   `#0 init_args() #1 initScript() #2 {main}` — execution never reaches `renderGui()`.
5. `events` table: the same message at `log_level 1` (ERROR), logged by `tLog()` before
   the `throw`.
6. Not reachable only by hand-typed URL: the shipped view forms post
   `<input type="hidden" name="doAction" value="" />`
   (`gui/templates/tl-classic/reqmgrsystems/reqMgrSystemView.tpl:83,87`, dashio `:85,89`)
   and only fill it in with `onclick="doAction.value='create'"` — a GET/POST of that form
   with JS disabled, aborted or bookmarked posted an empty `doAction` and hit the same 500.

**Expected POST-FIX:** no request can reach the fatal any more. An empty/absent
`doAction` renders the create form (200); a non-whitelisted `doAction` is still recorded
in the Event Viewer and answers 302 back to the Requirements Manager list. The 7
whitelisted actions — including the writes — must behave **exactly** as before.

**Actual result — `bash tmp/verify_1627.sh` → 24/24 PASS** on `fix/issue-1627`:

| # | assertion | result |
|---|---|---|
| 1 | `GET` with no `doAction` answers **200** (was 500 / 0 bytes) | PASS |
| 2 | …and renders the create form | PASS (11488 bytes) |
| 3 | …containing the create form fields | PASS |
| 4 | `GET ?doAction=` (empty) answers **200** (was 500 / 0 bytes) | PASS |
| 5 | `GET ?doAction=bogus` answers **302** (was 500 / 0 bytes) | PASS |
| 6 | …redirecting to `gui/templates/reqmgrsystems/reqMgrSystemView.html` | PASS |
| 7 | `GET ?doAction=CREATE` (wrong case) answers 302 | PASS |
| 8 | `GET ?doAction=<28 chars>` (over `maxLen` 20) answers 302 | PASS |
| 9 | the 3 rejections are logged in the Event Viewer (`log_level 1`) | PASS |
| 10 | `GET ?doAction=create` answers 200 | PASS |
| 11 | `POST doAction=doCreate` answers 302 → `reqMgrSystemView.php` | PASS |
| 12 | …redirecting to `reqMgrSystemView.php` | PASS |
| 13 | …and the row is really **INSERTed** | PASS (`reqmgrsystems` row, DB-verified) |
| 14 | `GET ?doAction=edit&id=<new>` answers 200 | PASS |
| 15 | …and the edit form is pre-filled with the stored name | PASS |
| 16 | `POST doAction=doUpdate` answers 302 → `reqMgrSystemView.php` | PASS |
| 17 | …and the row is really **UPDATED** | PASS (DB-verified) |
| 18 | `POST` of the view form with an **empty** `doAction` answers 200 (was 500 / 0 bytes) | PASS |
| 19 | `POST doAction=doDelete` answers 302 → `reqMgrSystemView.php` | PASS |
| 20 | …and the row is really **DELETED** | PASS (DB-verified) |
| 21 | anonymous `GET` still answers 200 | PASS |
| 22 | …and is bounced to the login screen (`note=expired`) | PASS |
| 23 | no new E_WARNING/E_ERROR row comes from the controller itself | PASS (0) |
| 24 | no new FATAL-class row (`log_level 0`) for the rejected input | PASS (0) |

**The harness really detects the bug (negative control).** With the pre-fix file restored
(`git show 4be7ee6ff:lib/reqmgrsystems/reqMgrSystemEdit.php`) the same harness reports
**15 passed, 9 failed**; with the fix it is **24 passed, 0 failed**.

**A/B proof that the write path is untouched.** The three write assertions (13, 17, 20)
were additionally executed against the *pre-fix* file: rows were created, renamed and
deleted identically. The diff only alters the *rejected* branch.

**Pre-fix baseline (measured, for contrast).**

| request | before | after |
|---|---|---|
| `reqMgrSystemEdit.php` (no `doAction`) | 500 / 0 B, `PHP Fatal error: Uncaught Exception` | 200 / 11488 B, create form |
| `?doAction=bogus` | 500 / 0 B | 302 → `reqMgrSystemView.html` + `ERROR` event |
| `?doAction=create`, `?doAction=edit&id=N` | 200 | 200 (identical) |
| `?doAction=doCreate|doUpdate|doDelete` | 302 → `reqMgrSystemView.php`, row written | identical |

**Notes.**

- Step 18 is the case the ticket called "no in-app user can hit it today": the shipped
  view form posts an **empty** hidden `doAction` and only fills it in with JS, so the 500
  was reachable from the product itself, not just from a hand-typed URL.
- The `E_WARNING "Trying to access array offset on null"` rows in
  `gui/templates_c/*reqMgrSystemEdit.tpl.php` (lines 105/126/137/151/188) are **NOT** part
  of this suite: they are a pre-existing Smarty warning of the legacy template
  (`$gui->testProjectSet` is `null` on the create screen) that is present in the pre-fix
  baseline too, and step 23 therefore scopes its query to the controller file and excludes
  the rejection audit rows asserted in step 8. Reported separately, not fixed here.
- A request that sends `doAction` **as an array** (`?doAction[]=x`) is still a 500: the
  `TypeError` comes from the shared input layer
  (`lib/functions/inputparameter.inc.php:229` catches `Exception` but the failure is a PHP
  `Error`) and affects every legacy controller, not just this one — filed as a new issue.

---

## Suite 1724 — Duplicate Name Check (`nameCheck`)

Screen `gui/templates/testcases/nameCheck.html` + BFF `api/namecheck/index.php`.
Modern replacement for `lib/ajax/checkNodeDuplicateName.php` (generic node name
check, **no rights check at all** in 1.9.20) and `lib/ajax/checkDuplicateName.php`
(test-case variant, gated on the **global** `mgt_view_tc` right).

Fixture `tmp/fixtures_1724.php` (re-runnable):

| object | id | note |
|---|---|---|
| test project `NCHK1724` (prefix `NCK`) | 3 | `check_names_for_duplicates=1`, `action_on_duplicate_name=generate_new` |
| test project `NCHK1724B` (prefix `NCB`) | 4 | second project, for the cross-project proof |
| suite `Alpha Suite` (project 3) | 5 | cases `Shared Case Name` (8), `Alpha Only Case` (11) |
| suite `Beta Suite` (project 3) | 6 | case `Shared Case Name` (14) — same name, different parent |
| suite `Gamma Suite` (project 4) | 7 | case `Alpha Only Case` (17) — same name, foreign project |
| user `nchkadmin` | 2 | global role 8, project role 8 on projects 3 and 4 |
| user `nchknorights` | 3 | global role 3 + project role 3 on project 3 → 403 path |

### BFF contract (curl, `admin/admin` session cookie)

| # | Case | Request | Expected | Result |
|---|---|---|---|---|
| 1 | anonymous `init` | `?action=init&tproject_id=3` (no cookie) | `401 not_authenticated` | **PASS** |
| 2 | `init` happy path | `?action=init&tproject_id=3` | `200`, `context.tproject_name=NCHK1724`, `tc_prefix=NCK`, 2 `node_types`, 3 `containers`, `grants.can_view/can_modify=true` | **PASS** |
| 3 | `init` session fallback | `?action=init` (no `tproject_id`) | `200`, falls back to `$_SESSION['testprojectID']` | **PASS** |
| 4 | `init` unknown project | `?action=init&tproject_id=9999` | `404 project_not_found` | **PASS** |
| 5 | `init` unknown action | `?action=bogus` | `400 unknown_action` | **PASS** |
| 6 | wrong verb | `POST ?action=init` | `405 method_not_allowed` | **PASS** |
| 7 | cross-origin POST | `POST /` + `Origin: http://evil.example` | `403` (CSRF guard) | **PASS** |
| 8 | duplicate **test case** | `?action=check&node_type=testcase&name=Shared Case Name&parent_id=5` | `200 exists=true duplicate_count=1`, `message="Name:Shared Case Name already exists"`, collision `{id:8,parent_id:5}` | **PASS** |
| 9 | free test case name | `…&name=Never Seen Case&parent_id=5` | `200 exists=false duplicate_count=0` | **PASS** |
| 10 | duplicate **test suite** (bug #1725 regression) | `?action=check&node_type=testsuite&name=Alpha Suite&parent_id=3` | `200 exists=true`, collision `{id:5,parent_id:3}` — **was `404 parent_not_found`** | **PASS (after #1725)** |
| 11 | free test suite name | `…&node_type=testsuite&name=Brand New Suite&parent_id=3` | `200 exists=false` — **was `404 parent_not_found`** | **PASS (after #1725)** |
| 12 | self-exclude on edit | `…&name=Alpha Only Case&parent_id=5&node_id=11` | `200 exists=false` (the node itself is excluded) | **PASS** |
| 13 | **parent scoping** | `…&name=Shared Case Name&parent_id=5` vs `parent_id=6` | same name free under Beta, taken under Alpha | **PASS** |
| 14 | **cross-project parent** | `…&name=Alpha Only Case&parent_id=7&tproject_id=4` | `200 exists=true collision {id:17}`, `tproject_id=4` (other project's suite) | **PASS** |
| 15 | **cross-project claim** | `…&parent_id=5&tproject_id=4` (parent is in project 3) | `400 project_mismatch` | **PASS** |
| 16 | bogus parent | `…&parent_id=999999` | `404 parent_not_found` | **PASS** |
| 17 | missing name | `…&name=&parent_id=5` | `400 missing_name` | **PASS** |
| 18 | missing context | `…&name=X` (no `parent_id`, no `node_id`) | `400 missing_context` | **PASS** |
| 19 | unknown node type | `…&node_type=bogus&parent_id=5` | `400 unknown_node_type` | **PASS** |
| 20 | name over 100 chars | 101 × `a` | `400 name_too_long` | **PASS** |
| 21 | no-rights user, `init` | cookie of `nchknorights`, `?action=init&tproject_id=3` | `403 no_permission` | **PASS** |
| 22 | no-rights user, `check` | cookie of `nchknorights`, valid parent | `403 no_permission` | **PASS** |
| 23 | no-rights user, other project | cookie of `nchknorights`, `?action=init&tproject_id=4` | `403 no_permission` (no project grant) | **PASS** |

### Legacy shim (the endpoints being superseded)

| # | Case | Request | Expected | Result |
|---|---|---|---|---|
| 24 | `checkNodeDuplicateName.php` denied | `nchknorights`, `parent_id=3&node_type=testsuite` | `success:false` + `no_permissions_for_action` — **1.9.20 answered `success:true`** | **PASS (hole closed)** |
| 25 | `checkNodeDuplicateName.php` denied (deep) | `nchknorights`, `parent_id=5&node_type=testcase` | `success:false` + localized denial | **PASS (hole closed)** |
| 26 | `checkNodeDuplicateName.php` anonymous | no cookie | redirects to `login.php?note=expired` (not a name oracle) | **PASS** |
| 27 | `checkDuplicateName.php` global-right hole | `nchknorights`, `testcase_id=8` (project 3, no rights) | `success:false` — 1.9.20's `has_rights($db,'mgt_view_tc')` could pass on a **global** grant | **PASS (hole closed)** |
| 28 | regression: sibling BFF untouched | `api/testcases?action=check_name&…&testsuite_id=5` | `200 {"duplicate":true,"message":"Name:Shared Case Name already exists"}` | **PASS** |

### Browser (headless Chrome, `admin/admin`)

| # | Case | Steps | Expected | Result |
|---|---|---|---|---|
| 29 | screen load | open `nameCheck.html?tproject_id=3` | teal header, toolbar, context card (project / prefix / 3 containers / warn `1` / `generate_new`), check card, `DUPLICATES WARNED` chip | **PASS** |
| 30 | live duplicate check | type `Alpha Suite`, node type `Test Suite`, parent `NCHK1724` | verdict `Name:Alpha Suite already exists`, chip `DUPLICATE`, collision row `5 | Alpha Suite | 3` | **PASS** |
| 31 | free suite name | `Brand New Suite` | chip `AVAILABLE`, no collision table | **PASS** |
| 32 | duplicate suite #2 | `Beta Suite` | collision row `6 | Beta Suite | 3` | **PASS** |
| 33 | duplicate test case | node type `Test Case`, parent `Alpha Suite`, `Shared Case Name` | collision row `8 | Shared Case Name | 5` | **PASS** |
| 34 | free test case | `Never Seen Case` | `AVAILABLE` | **PASS** |
| 35 | self-exclude | `Alpha Only Case` + exclude id `11` | `AVAILABLE` | **PASS** |
| 36 | deep link | `?tproject_id=4&node_type=testcase&parent_id=7&name=Alpha Only Case` | prefills all 4 fields **and auto-runs**: project `NCHK1724B`, `Duplicate`, collision `17 | Alpha Only Case | 7` | **PASS** |
| 37 | RO locale | switch `English → Română` | header `Verificare nume duplicat`, `Proiect de test`, `Nume de verificat`, hint, chip `Duplicat`, footer `Verificare nume duplicat`; deep-link params preserved through the `&locale=ro` reload | **PASS** |
| 38 | 403 state card | `nchknorights` (isolated browser context) | `Access Denied` + `You do not have permission to view test cases in this test project.`; context and form cards hidden | **PASS** |
| 39 | back link | toolbar `Test Specification` → `testSpec.html?tproject_id=3` | `200` | **PASS** |
| 40 | console | whole session | 0 errors, 0 warnings | **PASS** |
| 41 | Event Viewer | after the whole suite | `SELECT count(*) FROM events WHERE log_level >= 32` → **0** | **PASS** |

**Result: 41 / 41 PASS** (2 cases — 10 and 11 — failed before the #1725 fix and pass
after it; 24, 25, 27 are the closed 1.9.20 authorization holes).

### Bug found and fixed by this suite

- **#1725** `nameCheck`: test SUITE name check answered `404 parent_not_found`.
  The owning-project walk read `parent_id` before the node's own `node_type_id`,
  so a suite (a direct child of the project root, whose own `parent_id` is `0`)
  always bailed out. Only the deeper test-case variant worked, which hid the
  defect. Fixed in the BFF **and** in both legacy shims; the guard is not
  weakened (cases 15, 16 still `400`/`404`).

### Code-review regressions (added after the review pass, Refs #1724)

| # | Case | Request | Expected | Result |
|---|---|---|---|---|
| R1 | auth before the verb check | anonymous `POST /?action=init` with a valid `Origin` | `401 not_authenticated` (**was `405`** — the verb policy leaked to anonymous callers) | **PASS** |
| R2 | array `action` | `?action[]=x` | `400 unknown_action`, **0 Event Viewer rows** | **PASS** |
| R3 | array `name` | `?action=check&node_type=testcase&name[]=x&parent_id=5` | `400 missing_name`, **0 Event Viewer rows** (was a PHP 8 `Array to string conversion` E_WARNING) | **PASS** |
| R4 | array `parent_id` | `…&parent_id[]=5` | `400 missing_context`, **0 Event Viewer rows** (`intval(array)` silently yielded 1) | **PASS** |
| R5 | **foreign `node_id` is not excluded** | `…&name=Shared Case Name&parent_id=5&node_id=17` (17 is a case of suite 7) | `exists=true collision {id:8}` — an id borrowed from another project must not suppress a real collision (was a silent **false negative**) | **PASS** |
| R6 | no rights cannot enumerate projects | `nchknorights` + `?action=init&tproject_id=9999` | `403 no_permission` (not `404` — 403 vs 404 was an existence oracle) | **PASS** |
| R7 | **case under the project root** | `…&node_type=testcase&parent_id=3` | `400 testcase_needs_suite` — a case always lives in a suite, so "Available" was a green verdict about a question that cannot be true | **PASS** |
| R8 | **ASIDE reachability** | `api/aside?action=init&tproject_id=3`, admin | `tests_design` contains `Duplicate Name Check → nameCheck.html?tproject_id=3` (was a **dead** `$actions` entry with no caller) | **PASS** |
| R9 | ASIDE rights gate | same call, `nchknorights` | the item is **absent** (gated on `view_tc`) | **PASS** |
| R10 | **deep link with a foreign `parent_id`** | `nameCheck.html?tproject_id=3&parent_id=7&name=Alpha Only Case` (7 is a suite of project 4) | `notFound` state + `nchk.projectMismatch`; the form is hidden. **Previously it silently checked the project root and rendered a green "Available"** | **PASS** |
| R11 | node-type change re-runs | check `Shared Case Name` as a **suite** at project level, then switch to **test case** | fresh verdict for the new type (was a **stale** verdict relabelled with the new type) | **PASS** |
| R12 | legacy shim `code` discrimination | `checkNodeDuplicateName.php` as admin, `parent_id=999999` vs as `nchknorights` | `code: parent_not_found` vs `code: no_permission` — the two failures were indistinguishable | **PASS** |
| R13 | legacy shim foreign `node_id` | `checkNodeDuplicateName.php&node_id=17&parent_id=5` | still reports the duplicate | **PASS** |
| R14 | `href_duplicate_name_check` coverage | `grep` over `locale/*/strings.txt` | present in all **19** locale bundles (ASIDE labels come from the PHP lang files, not the i18n JSON) | **PASS** |

**Result after the review pass: 41 + 14 = 55 / 55 PASS**, Event Viewer still 0.

### Code-review pass — what the review caught

The 41-case suite was green *before* the review and the review still found
four behaviours the suite could not see, because each needs a value the happy
path never produces: a `parent_id` from **another** project (R5, R10), an
**array-typed** query parameter (R2–R4), a **nonexistent** project id (R6), and
a **test case addressed at project level** (R7). Green tests were not evidence
of correctness here; they were evidence that the fixtures only ever walked the
happy path.

---

## Regression — Issue #1722: legacy `lib/reqmgrsystems/reqMgrSystemEdit.php` answered HTTP 200 with a blank body (0 bytes) for the whitelisted-but-unrenderable actions `delete` and `checkConnection`

**Precondition:** TestLink 2.0.1 on `http://localhost:8082` (PHP built-in server, docroot
= repo root); MariaDB `127.0.0.1:3306/testlink` (`testlink`/`testlink`), freshly imported
(`reqmgrsystems` empty at the start); login `admin`/`admin`, role Admin
(`hasRight('reqmgrsystem_management')`); branch `fix/issue-1722`, fix commit `f9cc73b0e`.
Harness: **`bash tmp/verify_1722.sh`** (creates and deletes its own `reqmgrsystems` row, so it
is re-runnable on a fresh import).

**Repro steps (PRE-FIX)** — `reqMgrSystemCommands::$guiOpWhiteList`
(`lib/reqmgrsystems/reqMgrSystemCommands.class.php:38-39`) advertises **7** actions but
`renderGui()`'s `switch($argsObj->doAction)` (`lib/reqmgrsystems/reqMgrSystemEdit.php:45-75`)
handles only **5** (`edit|create|doDelete|doCreate|doUpdate`). The other two leave
`$renderType` at its initial value `'none'` (`:36`) and the second switch then swallows it at
`default: break;` — ending the request with an **empty body, HTTP 200, and no log line at all**:

1. Log in, then `GET /lib/reqmgrsystems/reqMgrSystemEdit.php?doAction=delete` → `HTTP 200`,
   **0 bytes**. `delete` is whitelisted but **no `delete()` method exists** on the command
   class, so `method_exists()` at `:22` is false and `$op` stays `null` — the controller does
   literally nothing. `events` delta for this request: **0 rows** (undiagnosable).
2. `GET …?doAction=checkConnection&id=2` → `HTTP 200`, **0 bytes**. Here the method *does*
   exist (`:223-238`), it hits the DB and computes `connectionStatus = ok|ko` — and the bean is
   then thrown away, because `checkConnection` is not one of the `case` values. One extra
   `E_WARNING Undefined array key "checkConnection"` from `initGuiBean()` (`:72`) is
   **#1628**, a separate defect.
3. Controls that already worked: `?doAction=create` → 200/11488 · `?doAction=edit&id=N` →
   200/>5000 (exact size is fixture-dependent - it was 11485 B against an empty table and
   11733 B against the row the harness creates, so the harness asserts a >5000 threshold) · `?doAction=doDelete&id=N` → 302 → `reqMgrSystemView.php` · `?doAction=bogus` →
   302 → the list (**#1627**'s graceful rejection) · `?doAction=` (empty) → 200 create form.
4. In the browser: `?doAction=delete` renders a **completely empty document** — title is the
   raw URL, no markup, no error, no way back.
5. Not reachable from the UI (measured): the only value the edit form ever posts is
   `{$gui->operation}` (`gui/templates/{tl-classic,dashio}/reqmgrsystems/reqMgrSystemEdit.tpl:129,131`),
   and `$actionOperation` (`:40-42`) only yields `doUpdate` / `doCreate` / `''`; the
   modernized list screen calls the BFF (`api/reqmgrsystems/index.php:291-320`), never this
   controller. Hence *minor* severity — crafted URL only.

**Expected POST-FIX:** every whitelisted-but-unrenderable action takes the **same graceful 302
back to the Requirements Manager list** that #1627 introduced for a non-whitelisted
`doAction`, and leaves one greppable `tLog` ERROR row. The 5 renderable actions — including
the whole create→edit→update→delete write cycle — must behave **byte-for-byte as before**,
because a "302 everything" patch would also pass a blank-body check while silently breaking
the only flows that write to the DB.

**Regression — Issue #1722: 31 cases, ALL PASS** (harness exit 0). The table below
groups those 31 harness assertions into 27 readable rows (several rows assert the status *and*
the body size / the `Location` / the log line of one request, and the harness's own sequence
numbering is the authoritative one - `bash tmp/verify_1722.sh` prints `| 1 |` … `| 31 |`). Discriminating: with the
pre-fix file restored, the very same harness reports **24 passed / 7 failed** (exit 1), and
all 7 failures are genuine behaviour (4 × `delete`/`checkConnection` status+Location+log, plus the 9-value
sweep that catches all three blank-200 values at once) - no assertion in the harness is
self-referential, so the negative control proves the *behaviour*, not just the presence of a string — every shared behaviour passes both before and
after, which is the anti-regression proof.

| # | case | pre-fix | post-fix | verdict |
|---|---|---|---|---|
| 1 | `GET ?doAction=delete` | 200 | **302** | **PASS** |
| 2 | `GET ?doAction=delete` body size | 0 | 0 (Location only) | **PASS** |
| 3 | `GET ?doAction=delete` → Location | *(none)* | `…/gui/templates/reqmgrsystems/reqMgrSystemView.html` | **PASS** |
| 4 | `GET ?doAction=delete` → `events` | **0 rows** | 1 row, `reqMgrSystemEdit.php - requested action is not renderable - Value:delete …` | **PASS** |
| 5 | `GET ?doAction=checkConnection&id=1` | 200 | **302** | **PASS** |
| 6 | `GET ?doAction=checkConnection&id=1` → Location | *(none)* | the Requirements Manager list | **PASS** |
| 7 | `GET ?doAction=checkConnection&id=1` → `events` | 1 row (#1628 warning only) | 2 rows: the #1628 warning + the new reason | **PASS** |
| 8 | `GET ?doAction=bogus` → 302 (**#1627 untouched**) | 302 | 302 | **PASS** |
| 9 | `GET ?doAction=bogus` → Location | the list | the list | **PASS** |
| 10 | `GET ?doAction=bogus` → `events` wording | `white list validation failure` | `white list validation failure` (still #1627's branch — a non-whitelisted value never reaches `renderGui()`) | **PASS** |
| 11 | `GET ?doAction=bogus` does **not** also hit the new branch | n/a | no `not renderable` row | **PASS** |
| 12 | `GET ?doAction=` (empty) → 200 | 200 | 200 | **PASS** |
| 13 | `GET ?doAction=` still renders the create form | 11487 B | 11487 B | **PASS** |
| 14 | `GET ?doAction=` logs nothing | 0 | 0 | **PASS** |
| 15 | `GET ?doAction=create` → 200 | 200 | 200 | **PASS** |
| 16 | `GET ?doAction=create` renders the form | 11488 B | 11488 B | **PASS** |
| 17 | `GET ?doAction=create` logs nothing | 0 | 0 | **PASS** |
| 18 | `POST doAction=doCreate` → 302 to the view screen | 302 | 302 → `reqMgrSystemView.php` | **PASS** |
| 19 | `POST doAction=doCreate` inserted the row | yes | yes (`id=2`) | **PASS** |
| 20 | `GET ?doAction=edit&id=<new row>` → 200 | 200 | 200 | **PASS** |
| 21 | `GET ?doAction=edit&id=<new row>` renders the edit form | >5000 B | >5000 B (11733 B measured) | **PASS** |
| 22 | the edit form is pre-filled with the stored name | yes | yes | **PASS** |
| 23 | `POST doAction=doUpdate` → 302 **and** the row really changed | 302 | 302, `name` + `cfg` updated in the DB | **PASS** |
| 24 | `GET ?doAction=doDelete&id=N` → 302 **and** the row is gone | 302 | 302, `count(*)=0` | **PASS** |
| 25 | E_WARNING rows produced by the whole CRUD cycle | — | **0** | **PASS** |
| 26 | `php -l lib/reqmgrsystems/reqMgrSystemEdit.php` (unconditional, so the assertion count is exactly 31) | clean | clean | **PASS** |
| 27 | **no** `doAction` value answers a bare 200 / 0 bytes (9-value sweep: `delete`, `checkConnection`, `DELETE`, `CheckConnection`, `delete&id=1`, `doDeleteX`, `editX`, `createX`, `zzz`) | 3 of the 9 are a bare 200 / 0 bytes | **0 of 9** | **PASS** |

**Result: 31 / 31 PASS, Event Viewer introduces no new Warning** (the single new row is the
intentional `log_level 1` ERROR reason; before the fix this request logged *nothing*, which is
precisely why the blank page was undiagnosable).

**Out of scope, left for their own issues (not regressions of this fix):** #1721 —
`?doAction=edit&id=<missing id>` still logs 5 × `Trying to access array offset on null` in the
compiled `reqMgrSystemEdit.tpl.php` (unguarded `getByID()`); it does **not** occur for an
existing id, which case 20/21 above re-confirmed (0 warnings for the row the harness created).
#1628 — `E_WARNING Undefined array key "checkConnection"` at
`reqMgrSystemCommands.class.php:72`.

---

## Suite 1643 — Assign Test Plan Roles: public/private access-type indicator (issue #1643)

**Precondition** (DB freshly imported every run — `testprojects`/`testplans` are empty):
recreate the fixtures with the SQL block in
`docs/Task-Issue-1643-Assign-Roles-Access-Type-Indicator.md`:
- project 1 `ALPHA1643` **public**, node 1; plans 3 `A-PUBLIC-1643` (public), 4 `A-PRIVATE-1643` (private), node 3 / 4;
- project 2 `BRAVO1643` **private**, node 2; plan 5 `B-PUBLIC-1643` (public), node 5;
- project 6 `CHARLIE1643` **private**, node 6, **no plans**.
Login `admin/admin`. BFF check: `php -l api/roles/index.php`; screen check: `node --check` on the
inline script; all 10 bundles `python3 -m json.tool`.

| # | case | expected | actual | verdict |
|---|---|---|---|---|
| 1 | `GET meta/tplan-roles?tproject_id=1&tplan_id=4` | envelope has `projectIsPublic=1`, `planIsPublic=0`; per-plan `isPublic` present | exactly that; DB truth `testprojects(1)=1`, `testplans(4)=0` | PASS |
| 2 | `GET …?tproject_id=1&tplan_id=3` | `planIsPublic=1` | 1 | PASS |
| 3 | `GET …?tproject_id=2&tplan_id=5` | `projectIsPublic=0`, `planIsPublic=1` | 0 / 1 | PASS |
| 4 | open `usersAssignPlan.html?tproject_id=1&tplan_id=4` | project icon `fa-globe` title "Public"; plan icon `fa-lock` title "Private - User need specific role assignment" | `#projectAccessIcon` = `access-public … title="Public"`, `#planAccessIcon` = `access-private … Private - …` | PASS |
| 5 | switch plan combo to `A-PUBLIC-1643` | plan icon becomes `fa-globe` "Public"; project unchanged | `access-public … title="Public"` | PASS |
| 6 | open `…?tproject_id=2&tplan_id=5` (private project) | project icon `fa-lock` private; plan icon `fa-globe` public | matches | PASS |
| 7 | clear the plan combo (`value=""` + change) | plan icon cleared; project icon kept | plan span class `access-icon` (empty), project `access-public` intact | PASS |
| 8 | open `…?tproject_id=6&tplan_id=0` (project with no plans) | toolbar hidden + disabled notice; **both** icons cleared | toolbar `display:none`, `#disabledMsg` visible, both spans empty | PASS |
| 9 | `?locale=ro` on project 2/plan 5 | tooltips in Romanian | project title `Privat - utilizatorul necesită o atribuire de rol specifică`, plan `Public` | PASS |
| 10 | i18n keys | `assign.accessPublic/Private/Vorsicht` in all 10 bundles | present in all 10; 10/10 `json.tool`-valid | PASS |
| 11 | JS syntax | inline script parses | `node --check` OK | PASS |
| 12 | console during cases 4–8 | no errors/warnings | 0 console messages | PASS |
| 13 | `events` table after the whole suite | no new Error/Warning | only 2 rows, both `log_level=16` (audit) | PASS |
| 14 | `GET …?tproject_id=1&tplan_id=999` (unresolvable plan) | `projectIsPublic=1`, `planIsPublic=-1` (vorsicht sentinel) | exactly that (code-review fix) | PASS |
| 15 | `renderAccessIcon('#planAccessIcon', -1)` in-browser | red `fa-exclamation-triangle`, tooltip `assign.accessVorsicht` | `access-vorsicht` + `aria-label="Attention internal error"` | PASS |
| 16 | per-plan `isPublic` consumed on combo change | plan indicator updates instantly from `r.plans[].isPublic` before `loadUsers()` returns | `planPublicById` map painted; canonical `planIsPublic` confirms | PASS |

**Result: 16 / 16 PASS.** Negative control: before the BFF/HTML change the same payload returned
no `projectIsPublic`/`planIsPublic` (investigation comment) and the two `<span>` elements did not
exist — cases 1–7 could not pass.
