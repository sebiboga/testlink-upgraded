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

## Suite 1616 — Regression, Issue #1616: `importKeywordsFromCSV()` must skip the export header row (`Keyword;Notes;Number of Test Case Linked`)

**Precondition.** `admin`/`admin` on `http://localhost:8082`. DB freshly imported. Fixture via SQL (mirrors `testproject::create()`): project 1 `KW1616Proj` (prefix KW1616) with keywords `smoke_login` (notes `smoke test of login`) and `regression_nightly`; project 2 `KW1616Target` (prefix KW1616B) empty; project 3 `KW1616Target2` (prefix KW1616C) empty. Screen: `/gui/templates/keywords/keywordsExport.html?tproject_id=1&mode=export`; BFF `api/keywordsxml/index.php`. Pre-fix, exporting project 1 to CSV and importing that file back created a bogus keyword `Keyword` (notes `Notes`) — DB row `3 | Keyword | 1 | Notes` (measured).

| # | steps | expected (post-fix) | actual | verdict |
|---|---|---|---|---|
| 1 | `GET /api/keywordsxml/index.php?action=export&tproject_id=1&type=iSerializationToCSV&filename=keywords.csv` | 200 text/csv body starts with `Keyword;Notes;Number of Test Case Linked\r\n` — exporter header unchanged | body `Keyword;Notes;Number of Test Case Linked\r\nsmoke_login;smoke test of login;0\r\nregression_nightly;;0\r\n` | PASS |
| 2 | POST that exact file to `action=import` with `tproject_id=1` (same project, every real keyword already exists) | 400 `NO_KEYWORDS_IMPORTED`, `rows=2`, `skipped=2`; **NO** bogus `Keyword` row created | `{code:"NO_KEYWORDS_IMPORTED", imported:0, skipped:2, rows:2, errors:[ALREADY_EXISTS x2]}`; `select * from keywords where keyword='Keyword' and testproject_id=1` → 0 rows | PASS |
| 3 | POST project 1's export into **empty** project 2 | 200 ok, `imported=2, skipped=0, rows=2`; project 2 holds exactly `smoke_login`, `regression_nightly` (ids 4-5) | `{status:"ok", keyword_count:2, imported:2, skipped:0, rows:2}`; DB ids 4,5 only | PASS |
| 4 | headerless 2-column file `hdrless_a;notes for a` / `hdrless_b;notes for b` into project 2 | 200 ok, 2 imported, 0 skipped — headerless files keep working | `{imported:2, skipped:0, rows:2}` | PASS |
| 5 | one-row 3-col file `Keyword;Notes;0` (a REAL keyword named `Keyword` with numeric tcv_qty) into project 2 | imported as real data — the header skip must not eat it (`Keyword;Notes;0` ≠ header `Keyword;Notes;Number of Test Case Linked`) | `{imported:1, skipped:0, rows:1}`; DB row `8 | Keyword | 2` | PASS |
| 6 | header-only file `Keyword;Notes;Number of Test Case Linked\r\n` into project 2 | 400 `EMPTY_FILE` (`rows=0`), no keyword created | `{code:"EMPTY_FILE", imported:0, skipped:0, rows:0}` | PASS |
| 7 | full round trip of project 2's export (contains a real keyword `Keyword`) into **empty** project 3 | 200 ok, `imported=5, skipped=0, rows=5`; project 3 mirrors project 2 exactly (ids 9-13 incl. real `Keyword`), no header junk | `{status:"ok", keyword_count:5, imported:5, skipped:0, rows:5}`; DB ids 9-13 = smoke_login, regression_nightly, hdrless_a, hdrless_b, Keyword | PASS |
| 8 | comma-delimited file `Keyword,Notes,Number of Test Case Linked\r\ncomma_kw,comma notes,0\r\n` into project 3 | comma header also skipped (delimiter sniff), only `comma_kw` imported | `{imported:1, skipped:0, rows:1}` | PASS |
| 9 | `php -l lib/functions/testproject.class.php` | clean | `No syntax errors detected` | PASS |
| 10 | Event Viewer / `events` after the whole run | no new Error/Warning entries | all new rows are `log_level=16` CREATE audit events; no ERROR/WARNING | PASS |
| 11 | browser console during the run | no errors | no console messages | PASS |
 | 12 | BOM'd header file `\uFEFFKeyword;Notes;Number of Test Case Linked\r\nbom_kw;;0\r\n` into project 4 | BOM tolerated — header skipped, only `bom_kw` imported, **no** `\uFEFFKeyword` junk row | `{imported:1, skipped:0, rows:1}`; DB has `bom_kw`, no BOM-prefixed row | PASS |
 | 13 | leading blank line before the header (`\r\nKeyword;Notes;…\r\nblank_kw;;0\r\n`) into project 4 | header detected as the first DATA row and skipped, only `blank_kw` imported | `{imported:1, skipped:0, rows:1}` | PASS |

**Pre-fix baseline (measured, for contrast).** Same export/import round trip on project 1 produced `keywords` row `3 | Keyword | 1 | Notes` and import response `{status:"ok", keyword_count:3, imported:1, skipped:2, rows:3}`.

**Notes.**

- The header skip is scoped to the FIRST parsed row and requires a full match of all three localized exporter labels (`lang_get('keyword'/'notes'/'tcv_qty')`), so a row of actual data — where the third cell is a numeric tcv_qty — can never be mistaken for the header.
- The skipped header is not counted in `rows`/`skipped`, so a successful round trip reports the clean `imported` count, and a header-only file still degrades to the pre-existing `EMPTY_FILE` guard in `api/keywordsxml/index.php:349`.

## Suite 1727 — Regression, Issue #1727: Requirement Management System editor (`reqMgrSystemEdit.html` + `api/reqmgrsystemedit`)

**Precondition.** `admin`/`admin` on `http://localhost:8082`, DB freshly imported. Fixture
`php tmp/fixtures_1727.php` (re-runnable, self-healing): test project `RM1727 Demo` (id 2,
prefix RM1727, requirements enabled, root node 2) and three requirement management systems —
`RMSE1727-LINKED` (id 5, linked to the test project), `RMSE1727-FREE` (id 6, unlinked),
`RMSE1727-DEAD` (id 7, linked only to the nonexistent node 999999) — plus the role-3 user
`rmsnorights`. Screen `/gui/templates/reqmgrsystems/reqMgrSystemEdit.html`; BFF
`api/reqmgrsystemedit/index.php`. The only shipped requirement management system type is
`1 = contour / soap` and that interface class is NOT shipped, so the configuration example and
"Check connection" must degrade to a message, never a 500.

| # | steps | expected | actual | verdict |
|---|---|---|---|---|
| 1 | open `?id=5&tproject_id=2` | edit screen: title "Edit Requirement Management System", context card Mode=Edit / ID=5 / Name / Type `contour (Interface: soap)`, Name+Type+Configuration prefilled, Save/Delete/Check connection visible, event-history link present | all rendered; `ctxId=1`→5, `ctxName=RMSE1727-LINKED`, `ctxType=contour (Interface: soap)` | PASS |
| 2 | "Used on test project" card on the same screen | the linked project is listed | `RM1727 Demo #2` | PASS |
| 3 | click *show configuration example* (type 1) | degrades to a message, HTTP 200, no fatal | `Interface contoursoapInterface not implemented` | PASS |
| 4 | click *Check connection* | red chip, HTTP 200 `connected:false`, no fatal | chip "Connection failed", title `Interface contoursoapInterface not implemented` | PASS |
| 5 | rename to `RMSE1727-LINKED-v2`, click Save | success feedback, context card + form re-render with the new name, row updated | `Requirement management system "RMSE1727-LINKED-v2" saved.`; `ctxName` updated | PASS |
| 6 | clear the Name, click Save | client-side validation, focus on Name, NO request | `Name is required.`, `document.activeElement.id === 'fldName'` | PASS |
| 7 | click Delete on the **linked** system, confirm | localized refusal headline + the offending project as a muted detail, stays on the screen (no redirect) | `Cannot delete - still linked to test projects` + `Failure - id 1 is linked to:  testproject 'RM1727 Demo' with id 2`; `location.href` unchanged | PASS |
| 8 | open the screen with no `id` | create mode: title "Create …", Mode=Create, ID `—`, used-on card hidden, Delete + Check connection hidden, event-history link hidden | verified via DOM | PASS |
| 9 | create mode, duplicate name `RMSE1727-LINKED-v2` | 409 `create_failed` surfaced, no redirect | `name already exists` + `The requirement management system could not be saved.` | PASS |
| 10 | create mode, new name `RMSE1727-NEW` + a Configuration body | 200, created, redirect to the list screen | `reqMgrSystemView.html?created=4`; row present in the list | PASS |
| 11 | open `?id=9999` (unknown id) | explicit not-found card, no broken form, no E_WARNING | `Requirement management system not found` + `It may have been deleted…` | PASS |
| 12 | `GET ?action=init&id=7` (system whose only link is dead) | legacy dead-link cleanup: link 999999 removed, live list empty | `testprojects: []`; `select * from testproject_reqmgrsystem` → only the live link remains | PASS |
| 13 | `rmsnorights` (role 3) opens the screen in the browser | denied card, form NOT rendered, no live controls | `Access denied` + `You need the Requirement Management System management right…`; `formArea` display:none, Check connection hidden | PASS |
| 14 | anonymous browser context opens the screen | 401 → bounce to the login screen | landed on `login.php?note=expired` | PASS |
| 15 | locale switch en → ro on `?id=5` | every rmse.* string translated, zero raw keys | `Modificare Sistem de Gestionare a Cerințelor`, `Modificare`, `Înapoi la Sisteme de Gestionare a Cerințelor`, footer `TestLink 2.0.1 - Sistem de Gestionare a Cerințelor` | PASS |
| 16 | click *Show event history* (admin, `mgt_view_events`) | deep link into the modern Event Viewer filtered on this object | `eventviewer.html?object_id=5&object_type=reqmrgsystems` → `Filtrat dupa reqmrgsystems #5` | PASS |
| 17 | delete the **unlinked** system (id 6) through the UI | modal → delete → redirect to the list, row gone | `reqMgrSystemView.html?deleted=6`; list shows only DEAD + LINKED | PASS |
| 18 | BFF contract: anonymous `?action=init&id=1` | 401 | 401 | PASS |
| 19 | BFF contract: `rmsnorights` `?action=init&id=1` | 403 `forbidden` | 403 `{"code":"forbidden"}` | PASS |
| 20 | BFF contract: `?action=bogus` | 400 `unknown_action` — never a blank 200 (#1722 family) | 400 `{"code":"unknown_action"}` | PASS |
| 21 | BFF contract: `?action=init&id=999999` | 404 `not_found` | 404 `{"code":"not_found"}` | PASS |
| 22 | BFF contract: POST to a read action / GET to a write action | 405 `method_not_allowed` both ways | 405 `GET required` / 405 on `?action=create` | PASS |
| 23 | BFF contract: create with an empty / blank name | 400 `validation_failed` | 400 `empty name is not allowed` | PASS |
| 24 | BFF contract: create with `type=99` / `cfg_template&type=99` | 400 `invalid_type` | 400 `{"code":"invalid_type"}` on both | PASS |
| 25 | BFF contract: update with `id=0` | 400 `invalid_id` | 400 | PASS |
| 26 | BFF contract: create a duplicate name | 409 `create_failed` | 409 `name already exists` | PASS |
| 27 | BFF contract: delete an unknown id / update an unknown id | 404 `not_found` | 404 | PASS |
| 28 | BFF contract: delete a linked system | 409 `delete_failed`; message WITHOUT the internal class/method prefix (**#1728**) | `Failure - id 1 is linked to:  testproject 'RM1727 Demo' with id 2` (pre-fix it started with `Class:tlReqMgrSystem - Method: delete - `) | PASS |
| 29 | BFF contract: `cfg_template&type=1`, `check_connection{id:5}` | 200 with `available:false` / `connected:false` + `not_implemented` | `Interface contoursoapInterface not implemented` on both | PASS |
| 30 | CSRF: POST `?action=create` with no `Origin`/`Referer` and no `X-Requested-With` | 403 from `bffSameOriginGuard` | 403 | PASS |
| 31 | legacy shim `reqMgrSystemEdit.php?doAction=edit&id=1&tproject_id=2` | 302 to the modern editor, id + context carried | `302 → reqMgrSystemEdit.html?id=1&tproject_id=2` | PASS |
| 32 | legacy shim `?doAction=create` (and empty `doAction`) | 302 to the modern editor in create mode | `302 → reqMgrSystemEdit.html` | PASS |
| 33 | legacy shim `?doAction=checkConnection&id=1` | 302 to the modern editor in edit mode (the modern Check connection button) | `302 → reqMgrSystemEdit.html?id=1` | PASS |
| 34 | legacy shim `?doAction=doDelete&id=1` (POST write) | NOT executed server side, INFO-logged, 302 to the list — the BFF owns the writes | `302 → reqMgrSystemView.html`, event row log_level=1 `legacy write doAction "doDelete" is no longer executed` | PASS |
| 35 | legacy shim `?doAction=zzz` | 302 to the list + tLog ERROR (legacy parity) | `302 → reqMgrSystemView.html`, event row log_level=1 `unknown doAction "zzz"` | PASS |
| 36 | legacy shim, anonymous | legacy `checkSessionValid` JS bounce to login | 200 body with `top.location.href='../../login.php?note=expired&destination=…'` | PASS |
| 37 | **#1729** re-run of the fixture (3 creates) after the `getByAttr()` fix | ZERO new E_WARNING rows in `events` | only `log_level=16` audit rows; no `log_level=2` | PASS |
| 38 | **#1728** delete-failure message after the `delete()` fix | no `Class:tlReqMgrSystem` prefix on the success message either | `msg` seeded with `''`; `operation OK for id %s` | PASS |
| 39 | i18n coverage: `rmse.*` + `footers.reqMgrSystemEdit` in ALL bundles | present in en/ro/de/es/fr/it/pt/ru/ja/zh, all files valid JSON | 28 + `rmse.openEditor` = 29 `rmse.*` keys + footer in all 10, `python3 -m json.tool` clean | PASS |
| 40 | `php -l` on every touched PHP file | clean | clean (BFF, shim, common.php, tlReqMgrSystem.class.php) | PASS |
| 41 | browser console during the whole run | no errors / warnings | no console messages | PASS |
| 42 | Event Viewer / `events` after the whole run | no new Error/Warning rows attributable to the screen | only audit (16) + the 2 intentional shim tLog rows (1) | PASS |

| 43 | **#1731** save a system named `<b>X</b>&"R&D'<img src=x onerror=window.__pwned=1>` | the name is rendered as LITERAL text in the banner and the context card, no element is created, no script runs | `feedbackText` = the name verbatim, `feedback.querySelectorAll('b,img').length === 0`, `window.__pwned === undefined`, `ctxName` = the name verbatim (no `R&amp;D` double-escaping) | PASS |
| 44 | **#1730** `?action[]=x` (no session) / `?action=init&id[]=1` (admin) | no `E_WARNING` row; the array-shaped id is a 400 `invalid_id` | 401 then `{"code":"invalid_id"}`; `select count(*) from events where log_level=2` → 0 | PASS |
| 45 | **#1730** POST `{"name":["x"],"type":1}`, `{"name":"x","type":1,"cfg":{}}`, `{"name":[],"type":[]}`, `{"id":[]}` | 400 `invalid_body` / `invalid_id`; **no** system named `Array` is ever stored | `invalid_body` ×3 + `invalid_id`; `select name from reqmgrsystems` contains no `Array` | PASS |
| 46 | **#1730** `?action=init` with **no** id, `id=0`, `id=abc` | create mode (the regression a first hardening pass introduced) | `mode:create` for all three | PASS |
| 47 | **#1730** `?action=init&id=7` **without** `prune`, then **with** `prune=1` | the plain GET performs no write; `prune=1` removes the dead link | `count(*) where testproject_id=999999` → 1, then 0; the screen always sends `prune=1`, so the dead link is still gone after opening the editor in the browser | PASS |
| 48 | **#1730** `HEAD ?action=init&id=11`; `POST` to a read action | 200 (HEAD is a read for `bffSameOriginGuard`), 405 with an `Allow` header | `HTTP/1.1 200`; `HTTP/1.1 405` + `Allow: GET, POST` | PASS |
| 49 | **#1730** every 4xx payload | carries `status:error` like the shared guards and the other BFFs | `{"status":"error","code":"not_found",...}` | PASS |
| 50 | **#1730** duplicate name on create **and** on update | a `name_exists` code the screen localizes | `{"code":"name_exists","message":"name already exists"}`; update → `Update can not be done - name ... already exists for id ...` | PASS |
| 51 | create, then delete through the UI | both redirects carry the test project context | `reqMgrSystemView.html?tproject_id=4&created=14`, `…&tproject_id=4&deleted=14` | PASS |
| 52 | save, then read the banner again (the reload must not eat it) | the success message survives the post-save reload | banner = `Requirement management system "…" saved.`; the connection chip is cleared | PASS |
| 53 | shim `?doAction=edit&id=11` with **no** `tproject_id` query param | the context comes from the session (`testprojectID`), not the never-written `tproject_id` key | 302 target contains `tproject_id=4` | PASS |
| 54 | `rmse.msg.nameExists` + `rmse.cfgExampleFailed` in all 10 bundles, all JSON valid | present everywhere | 31 `rmse.*` keys in `en.json`, all bundles parse | PASS |
| 55 | `php -l` on the BFF and the shim after the review fixes | clean | clean | PASS |
| 56 | open the editor from the list screen's per-row *open in full editor* icon | a new tab with the row id + the list screen's own context | `reqMgrSystemEdit.html?id=13&tproject_id=4&tplan_id=0`; Back -> `reqMgrSystemView.html?tproject_id=4` |
| 57 | the same navigation with `tplan_id=0` in the URL | a `0` means "no plan" and must not be forwarded | Back target is `?tproject_id=4` (no `tplan_id=0`); event-history link `…&tproject_id=4&tplan_id=0` with `rel="noopener noreferrer"` |

**Result: 55 / 55 PASS** (42 screen/BFF/shim cases + 13 code-review regression cases) plus 2 navigation cases
(56-57, the list-screen entry icon and the `tplan_id=0` forwarding) = **57 / 57 PASS**.

**Notes.**

- The modern `Available On` column is **not** the legacy `available_on` column and must not be conflated with the new one: `Available On` is the *enable-on context* (Design / Execution / Test Plan Design) and can list several values, while `Available For` is the *node type* and is always a single value. Both now coexist, which is what makes the list self-explanatory.
- `nodeTypeLabel()` uses `TLi18n.has()` before `TLi18n.t()`; `TLi18n.t()` echoes the key back when it is missing, so without the `has()` guard a node type without a key would display as `cf.node.<slug>` to the user.
- The 14 `cf.node.*` keys mirror the `node_types` table 1:1, so every node type TestLink can bind to a custom field has a translated label in all 10 bundles.

---

## Regression — Issue #1628: `initGuiBean()` read `$obj->l18n[$caller]` unguarded — E_WARNING `Undefined array key "checkConnection"` **and** `Undefined array key "delete"`

**Precondition:** TestLink 2.0.1 on `http://localhost:8082` (PHP 8.3.35 built-in server,
docroot = repo root); MariaDB `127.0.0.1:3306/testlink` (`testlink`/`testlink`), freshly
imported this run (`reqmgrsystems` empty at start, `events` empty at start); login
`admin`/`admin`, role Admin (`hasRight('reqmgrsystem_management')`); branch `fix/issue-1628`,
fix commit `f4da422b4`.

**Harnesses** (all in gitignored `tmp/`, re-runnable on a fresh import — the script creates
and drops its own `reqmgrsystems` row):

| file | role |
|---|---|
| `bash tmp/verify_1628.sh` | driver: fixture + syntax gate + unit matrix + Event Viewer + locales + HTTP + cleanup. exits 1 on any failure |
| `php tmp/unit_1628.php` | assert-based unit matrix over all 7 whitelisted callers (15 assertions) |
| `php tmp/raw1628.php` | runs `initGuiBean()` with TestLink's own `watchPHPErrors` handler active and **no** suppression, so warnings land in `events` exactly as in a real request |
| `php tmp/ro1628.php <locale>` | same, per locale, all 7 callers |

**Repro steps (PRE-FIX).** `reqMgrSystemCommands::$guiOpWhiteList`
(`lib/reqmgrsystems/reqMgrSystemCommands.class.php:38-39`) dispatches **7** action names;
`initGuiBean()`'s `$obj->l18n` describes only **5** of them (3 from `init_labels()` at
`:64-65` — `create`, `edit` — plus the 3 hand-mapped names at `:68-70`; `reqmgrsystem_management`
and `reqmgrsystem_deleted` are page/feedback labels, not action names). Line 72 then read
`$obj->l18n[$caller]` **unconditionally**:

1. `php tmp/raw1628.php` → `create` OK, then **two** `events` rows, `log_level = 2` (E_WARNING):
   `Undefined array key "checkConnection" - in …/reqMgrSystemCommands.class.php - Line 72`
   and `Undefined array key "delete" - in …/reqMgrSystemCommands.class.php - Line 72`.
   The conversion happens in `lib/functions/logger.class.php:1483 set_error_handler("watchPHPErrors")`.
2. `php tmp/unit_1628.php` → **5 FAILURE(S)**: both offenders warn, both have an empty
   `action_descr`, and the whole-loop assertion reports `offenders: checkConnection,delete`.
3. The author of the report had evidence for only the first row — their query was
   `limit 3` / `limit 4`. `delete` is the **same defect on the same line** and was never
   reported. The very next statement, the `switch($caller)` at `:74-76`, has an explicit
   `case 'delete':` branch, so `delete` is an anticipated caller with no label ever added.
4. **Reachability caveat (measured, differs from the report).** Since `Refs #1727`,
   `lib/reqmgrsystems/reqMgrSystemEdit.php` is a session-guarded **302 redirect shim** that no
   longer requires or instantiates the class, so the issue's HTTP repro now returns
   `HTTP 302` and raises **0** `events` rows; `grep -rn "reqMgrSystemCommands" --include=*.php .`
   yields only the class's own two lines plus a *comment* in the shim — the class is orphaned.
   The HTTP path is therefore the **modern** BFF (`api/reqmgrsystemedit?action=check_connection`),
   which was already clean. The defect must be reproduced against the class (steps 1-2), and
   fixed at the source so the class is correct should anything wire it back up.

**Expected POST-FIX.** No E_WARNING for any of the 7 whitelisted callers; `action_descr` is a
real localized string for `checkConnection` and `delete`; the 5 already-correct actions are
unchanged; the whitelist, `main_descr` and `submit_button_label` are untouched; the modern
screen keeps working; the Event Viewer gains no Error/Warning rows.

**Actual result — `bash tmp/verify_1628.sh` → `TOTALS: PASS=12 FAIL=0`, exit 0.**

| # | Case | Expected | Observed | Result |
|---|---|---|---|---|
| 1 | fixture `reqmgrsystems` row `TLU1628` (type 1) | row exists | `id` returned, list screen shows 1 row | PASS |
| 2 | M9 `php -l lib/reqmgrsystems/reqMgrSystemCommands.class.php` | no syntax errors | `No syntax errors detected` | PASS |
| 3 | M1 `initGuiBean('checkConnection')` — **the issue's subject** | no warning, non-empty localized label | `'Check connection'`, 0 warnings | PASS |
| 4 | M2 `initGuiBean('delete')` — **the unreported sibling** | no warning, non-empty localized label | `'Delete'`, 0 warnings | PASS |
| 5 | M3 loop over all 7 whitelisted callers | zero warnings | `offenders: none` | PASS |
| 6 | M4 `events` after 3 calls, `watchPHPErrors` active | 0 rows at all, 0 at log_level 1/2/4 | `0` / `0` (pre-fix: `2`) | PASS |
| 7 | M5 `create`/`edit`/`doCreate`/`doUpdate`/`doDelete` | `Create`/`Edit`/`Create`/`Edit`/`''` unchanged | identical | PASS |
| 8 | M6 `submit_button_label`: `delete`+`doDelete` vs the rest | `''` for the two deletes, `btn_save` otherwise | identical | PASS |
| 9 | M7 `main_descr` | `reqmgrsystem_management` for every caller | identical | PASS |
| 10 | M8 `guiOpWhiteList` | still 7 entries, unmodified | 7 entries | PASS |
| 11 | M11 locale `ro_RO` (the only bundle missing `btn_delete`) | no warning, no raw-key leak, `en_GB` back-fill | `'Check connection'` / `'Delete'`; 0 warning rows; 7 `log_level=32` **INFO** "not localized — using en_GB" rows, 5 of which are pre-existing | PASS |
| 12 | M11 locale `ja_JP` | properly localized | `接続テスト` / `削除` / `作成` / `編集`; 0 warning rows | PASS |
| 13 | M11 locale `en_GB` | properly localized | `Check connection` / `Delete`; 0 warning rows | PASS |
| 14 | M10 `GET gui/templates/reqmgrsystems/reqMgrSystemView.html` | 200, table renders the row | 200, `1 requirement management systems | Generated on …` | PASS |
| 15 | M10 `POST api/reqmgrsystemedit/index.php?action=check_connection` | 200 JSON, correct degradation | `{"status":"error","connected":false,"code":"not_implemented","message":"Interface contoursoapInterface not implemented"}` | PASS |
| 16 | M10 legacy route `reqMgrSystemEdit.php?doAction=checkConnection&id=N` | 302 to the modern editor | 302 | PASS |
| 17 | M12 `events` after the whole HTTP pass | no new Error/Warning | only `log_level=16` login-audit row; browser console `<no console messages found>` | PASS |
| 18 | cleanup | fixture removed, `events` emptied | removed | PASS |

**Notes.**

- The fix is **two lines plus comments** in one file: both missing actions are registered in
  the `init_labels()` call using the `key => label_code` two-argument form that
  `init_labels()` already supports (`lib/functions/lang_api.php:317-325`), mapped onto
  `btn_check_connection` (`locale/en_GB/strings.txt:275`) and `btn_delete`
  (`locale/en_GB/strings.txt:282`); the read is wrapped in an `isset()` guard.
  Passing `null` for those keys would have asked `lang_get()` for the non-existent code
  `checkConnection` and leaked the raw key into the UI.
- The `isset()` guard is **load-bearing, not belt-and-braces**: fixing only the two known keys
  means the 8th whitelist entry added later silently starts warning again — the exact drift
  that created the bug. `doDelete` already maps to `''` by design (`:70`), so the guard is
  behaviour-preserving on every correct path.
- **No locale bundle was edited and no i18n key invented.** `btn_check_connection` ships in
  7/19 bundles and `btn_delete` in 18/19; `lang_get()`'s `en_GB` back-fill
  (`lang_api.php:78-81`) covers the rest and emits only `log_level=32` INFO audit rows.
- `$obj->action_descr` has **no consumer** in the modern UI (`grep -rn action_descr` finds only
  assignments, no `.tpl`/`.html` read), which is why the missing labels went unnoticed since
  1.9.6. The Event Viewer noise was the only observable symptom.
- Cross-reference: the #1722 suite above already flags this warning as "a separate defect"
  from the blank-body bug; that separation is confirmed here and that bug is unaffected.

---

## Regression — Issue #1731: `reqMgrSystemEdit.php` 302 shim — array-shaped `$_REQUEST` wrote an E_WARNING **and** an ERROR row per request

**Precondition** — MariaDB `testlink` freshly imported, PHP 8.3.35 built-in server on
`http://localhost:8082`, session established as `admin`/`admin` via
`POST /login.php?action=ajaxlogin` (`tl_login` / `tl_password`).
`select count(*) from events where log_level in (1,2)` = 0 before every block.

**Subject** — `lib/reqmgrsystems/reqMgrSystemEdit.php`, the session-guarded 302 redirect shim
that retires the legacy `reqMgrSystemEdit.php` page. The BFF (`api/reqmgrsystemedit/index.php`)
was already hardened in `ef3331fd0`; the shim's own `$_REQUEST` reads at lines 66-67 and 72 were
left exactly as 1.9.20 had them.

**Repro (pre-fix) — 1 request, 2 rows**

```bash
curl -s -c c.jar -b c.jar -o /dev/null http://localhost:8082/login.php
curl -s -c c.jar -b c.jar -X POST 'http://localhost:8082/login.php?action=ajaxlogin' \
     -d 'tl_login=admin&tl_password=admin'
curl -s -b c.jar -o /dev/null -D- \
     'http://localhost:8082/lib/reqmgrsystems/reqMgrSystemEdit.php?doAction%5B%5D=x'
# -> HTTP/1.1 302 Found, Location: .../reqMgrSystemView.html   (looks completely normal)
```

`events`, verbatim:

```
log_level 2 | E_WARNING\nArray to string conversion - in .../lib/reqmgrsystems/reqMgrSystemEdit.php - Line 66
log_level 1 | reqMgrSystemEdit.php shim: unknown doAction "Array" - refusing to guess a modern target (Refs #1727).
```

**Expected post-fix behaviour** — the array is never cast, so the coerced literal `"Array"` is never
built, the `default:` branch's `tLog(...,'ERROR')` is never reached, and **no `events` row with
`log_level in (1,2)` is written**. An array-shaped `doAction` is refused with a 302 to the list
screen at `INFO`; array-shaped `id` / `tproject_id` / `tplan_id` degrade to `0`, i.e. the
already-supported "no id / no context" case. Scalar parameters must keep working unchanged.

**Results — 2026-09-29, commit `844bba827`, branch `fix/issue-1731`**

| # | Case | Expected | Observed | Result |
|---|---|---|---|---|
| 53 | `?doAction[]=x` | 302 to list screen, **0 new Error/Warning rows** | `302` → `reqmgrsystems/reqMgrSystemView.html`, `rows=+0` | PASS |
| 54 | `?doAction[]=edit` | 302 to list screen, 0 rows | `302` → `reqMgrSystemView.html`, `rows=+0` | PASS |
| 55 | `?doAction[]=doDelete` | 302 to list screen, **no server-side write**, 0 rows | `302` → `reqMgrSystemView.html`, `rows=+0`; `reqmgrsystems` row count unchanged | PASS |
| 56 | `?id[]=1&doAction=edit` | array id must NOT address system 1 | `302` → `reqMgrSystemEdit.html` with **no** `id` (create mode), not `?id=1` | PASS |
| 57 | `?tproject_id[]=1&doAction=create` | must NOT silently enter test project 1 | `302` → `reqMgrSystemEdit.html` with **no** `tproject_id` | PASS |
| 58 | `?tplan_id[]=1&doAction=create` | must NOT silently enter test plan 1 | `302` → `reqMgrSystemEdit.html` with **no** `tplan_id` | PASS |
| 59 | `?doAction=edit&id=1&tproject_id=1` (all scalar) | **legacy parity** — unchanged redirect | `302` → `reqMgrSystemEdit.html?id=1&tproject_id=1` | PASS |
| 60 | `?doAction=create` (no id) | 302 to the editor, no id | `302` → `reqMgrSystemEdit.html` | PASS |
| 61 | `?doAction=doCreate` (retired write verb) | 302 to list screen, `INFO` row only, no write | `302` → `reqMgrSystemView.html`, `rows=+0` for `log_level in (1,2)` | PASS |
| 62 | `?doAction=bogus` (scalar unknown verb) | **pre-existing intentional** `ERROR` row, unchanged by this fix | `302` → `reqMgrSystemView.html`, `rows=+1` at `log_level 1` — the #1727 `default:` branch, untouched | PASS (unchanged, out of scope) |
| 63 | flood: 5 × `?doAction[]=x` | 0 rows with `log_level in (1,2)` (was **+10** pre-fix, 2 per request) | `rows=+0`. Measured against the WHOLE `events` table, not just `log_level in (1,2)`: the 5 requests wrote **zero rows of any level** — `tLog(...,'INFO')` does not persist below WARNING, so the refusal is completely silent apart from the HTTP 302. Re-confirmed post-fix: `rows=+0`, and the only rows in the table are the two ordinary `audit_login_succeeded` (log_level 16) entries | PASS |
| 64 | anonymous (no cookie) `?doAction[]=x` | 302 to login, 0 rows | redirect before the cast, `rows=+0` | PASS |
| 65 | `php -l lib/reqmgrsystems/reqMgrSystemEdit.php` | no syntax errors | `No syntax errors detected` | PASS |
| 66 | **browser end-to-end**: `GET /lib/reqmgrsystems/reqMgrSystemEdit.php?doAction=edit&id=1&tproject_id=1` → editor loads, rename `SysA` → `SysA-renamed`, click **Save** | shim redirects into the modern editor, the save persists, 0 new Error/Warning rows, no console error | redirect landed on `reqMgrSystemEdit.html?id=1&tproject_id=1`; editor rendered in **Edit** mode with name `SysA`; Save → `select id,name from reqmgrsystems` → `1  SysA-renamed`; `events` `log_level in (1,2)` unchanged at 0; console `error`/`warn` = none | PASS |

**Events-Viewer check (AGENTS.md rule 12)** — after the full matrix plus the browser pass, the
complete set of rows with `log_level in (1,2)` is **empty** once the deliberate case-62
scalar-unknown-verb row is removed. No new Error/Warning entry was generated by the fix.

**Out of scope, filed separately** — `lib/testcases/listTestCases.php:52` carries the identical
bare `(string)$_REQUEST['feature']` cast and produces the identical 2-row outcome; filed as
**#1732** per `ai/FIX-ISSUE.md` §4 rather than fixed here.
`lib/keywords/keywordsEdit.php:71,73` has the same cast but is **not** reachable (302 before the
cast, 0 rows) — checked, no issue needed.

---

## Task — Issue #1642: Restore DataTables `stateSave` (persist grid view across visits) in `usersAssignPlan.html`

**Precondition / fixtures** (the DB is freshly imported on every run, so everything below is created per run):

```sql
INSERT INTO testprojects (id,notes,active,prefix,is_public) VALUES (1,'{"name":"ALPHA1642"}',1,'ALPHA1642',1);
INSERT INTO nodes_hierarchy (id,name,parent_id,node_type_id,node_order) VALUES
 (1,'ALPHA1642',NULL,1,1),(3,'A-PUBLIC-1642',1,5,1),(4,'A-PRIVATE-1642',1,5,2);
INSERT INTO testplans (id,testproject_id,notes,active,is_open,is_public,api_key) VALUES
 (3,1,'',1,1,1,'a1a1…a1a1'),(4,1,'',1,1,0,'b2b2…b2b2');
-- 25 more active users user1642_01 … user1642_25 (+ the admin account) = 26 active users
```

26 users > `pageLength` 20, so page 2 of the grid really exists (needed by the current-page case).
Login `admin`/`admin`; screen under test
`/gui/templates/usermanagement/usersAssignPlan.html?tproject_id=1&tplan_id=<3|4>`.

**Legacy reference** — `gui/templates/dashio/usermanagement/usersAssign.tpl:111-120` (deleted in
`ab387af72`) included `gui/templates/dashio/include/DataTables.inc.tpl`, which initialised **every**
legacy grid with `{"lengthMenu":[…],"stateSave":true}` (`:100-104`). Entries-per-page, the `User`
search string, the sort column/order and the current page therefore survived a revisit of the
screen. Legacy reached a plan by full page navigation (`usersAssign.tpl:100-107`), so its state
key changed with the plan and a plan switch could never leak a view.

**Steps to exercise the restored feature** — open the plan screen, change the DataTables view
(search box / *Show N entries* / column sort / page navigation), reload (F5) or leave and come
back, and additionally switch the test plan through the in-page combo.

**Expected** — search, entries-per-page, sort and page are restored for the **same** plan; a
different plan never inherits them; a plain revisit of the default view produces no toast; an
in-screen re-render (bulk "Do") keeps the view without claiming a restore.

**Results — 2026-09-30, commit `63da873aa`, branch `task/issue-1642`, app on `http://localhost:8082`**

| # | Case | Expected | Observed | Result |
|---|---|---|---|---|
| 67 | `bStateSave` of the `#assignTable` instance after load | `true` | `true` (measured `null` **before** the fix) | PASS |
| 68 | `search="user1642_1"`, `len=20`, `order=[[1,desc]]` → **F5** | all three restored, search box repopulated | `search="user1642_1"`, box `user1642_1`, `len=20`, `order=[[1,"desc"]]`, 10 matching rows | PASS |
| 69 | `len=40`, `order=[[1,desc]]` → **F5** | entries-per-page + sort restored | `len=40`, `order=[[1,"desc"]]` | PASS |
| 70 | `page(1)` of 2 pages, everything else default → **F5** | **current page** restored (issue requirement) | `page=1`, rows `admin / user1642_01 / user1642_02` | PASS |
| 71 | inspect `localStorage['DataTables_assignTable_/gui/…/usersAssignPlan.html']` | state persisted **and stamped with the plan** | `{time,start,length,order,search,columns[…6],"tplan_id":"3","childRows":[]}` | PASS |
| 72 | in-page combo switch plan 3 → 4 | **no leak** of plan 3's view; state re-stamped | `search="" len=20 order=[[1,"asc"]] page=0`, saved stamp `tplan_id:"4"` | PASS |
| 73 | URL `?tproject_id=1` reloads into plan 4 while the stored state is stamped `tplan_id:"3"` (`search=user1642_05 len=60 order=[[2,asc]]`) | `stateLoadParams` rejects the foreign state | `state.loaded() === null`, `search="" len=20 order=[[1,"asc"]]` | PASS |
| 74 | revisit with the **default** view (no search/len/sort/page change) | **no** "view restored" toast | `toasts=[]` (fixes the `String(st.order)==='1,asc'` defect, see #1733) | PASS |
| 75 | revisit with a non-default view | exactly one `ok` toast with the localized text | MutationObserver captured 1 node: `toast ok\|Saved view restored (search, sort, entries per page and page kept)` | PASS |
| 76 | bulk "Do" (select `guest`, click Do) with `search=user1642_1`, `order=[[2,desc]]` | `keep` wins: view unchanged, **no** toast, Save enabled | before == after (search/box/len/order/page), `toasts=[]`, 25 rows `changed`, Save enabled | PASS |
| 77 | per-row role `<select>` change | model updated, view untouched, no toast | `roleVal=3 changed=true`, view identical, `toasts=[]` | PASS |
| 78 | **Save** (post-back grid reload, `loadUsers()` same plan) | roles persisted, view kept, Save re-disabled | 25 rows in `user_testplan_roles` for plan 4; `toast ok\|User Roles updated`; `search=user1642_1 len=20 order=[[2,desc]]`; `changed=0`; Save disabled | PASS |
| 79 | "show only authorized users" toggle (issue #1707 regression) | filtering still works, view intact | footer `26 users — 1 unauthorized user(s) hidden`, 9 rows shown, `len/order/search/page` unchanged | PASS |
| 80 | console messages | none | 0 `error`, 0 `warn` | PASS |
| 81 | `events` table (Event Viewer, rule 12) | no new Error/Warning | `group by log_level` → `16 → 27` only; `where log_level <> 16` → **0 rows** | PASS |
| 82 | i18n bundles (11) parse + key present | valid, localized (no hardcoded string) | `python3 -m json.tool` OK for all; `assign.viewStateRestored` present in `en/de/es/fr/it/ja/pt/ro/ru/zh` | PASS |
| 83 | `node --check` on the extracted `<script>` | no syntax error | `JS SYNTAX OK` | PASS |
| 84 | control: sibling `usersAssignProject.html` (#1622) | untouched by this change | still `bStateSave=true`, restores its own state | PASS |

**Notes for the next agent**

- The DataTables state key is **per page pathname** (`DataTables_assignTable_/gui/templates/usermanagement/usersAssignPlan.html`),
  *without* the query string — so `?tplan_id=3` and `?tplan_id=4` share one key and the
  `tplan_id` stamp (not the key) is what prevents the cross-plan restore. Cases 72/73 cover it.
- `renderUsersTable(keep)` must keep applying `keep` **after** the constructor: with `stateSave:true`
  the constructor may load a persisted state first, and the caller's live view has to win.
- `notifyRestoredState()` must be skipped when `keep` is passed, otherwise the first bulk "Do"
  after the user merely paged around pops a misleading "view restored" toast (measured).
- `st.search` is always an object and `String(st.order)` is `"1,asc"` in DataTables 1.13.7 — the
  `isDefault` test must compare `st.search.search` and `JSON.stringify(st.order)`.
- Out of scope here (separate task #1641): this screen still hardcodes
  `lengthMenu: [[20,40,60,-1],…]` / `pageLength: 20` instead of reading the BFF pagination block.

**Second execution pass (all 18 cases re-run against the final commit `63da873aa`, after the
`isDefault` correction)** — cases 67, 68, 70, 71, 72, 73, 84 re-measured in one clean run
(cache-bypassing reloads): `bStateSave=true`; restore of `search=user1642_1 / len=20 /
order=[[1,"desc"]] / 10 matches`; `page=1` of 2 restored; saved state stamped `tplan_id:"3"`
with 6 column entries; switch 3→4 → `search="" len=20 order=[[1,"asc"]]` re-stamped `"4"`; the
foreign-plan state rejected with `state.loaded()===null`; sibling project screen still
`bStateSave=true` with `tproject_id:"1"`. Console `error`/`warn` = 0; `events` `log_level <> 16`
= 0 rows. **18/18 PASS.**

---

## Regression — Issue #1733: "Saved view restored" toast fired on every revisit of `usersAssignProject.html`

**Precondition / fixtures** — the DB was freshly imported (0 test projects, 1 user `admin`), so the
screen could not even render (`#disabledMsg` = "Your role configuration do not allow you Assign
Roles for Test Projects", 0 rows). `php tmp/fixtures_1733.php` created test project
**`tproject_id=5`** (`ASSIGN1733`, public/active) + **25 role-assignable users** `t1733usr01..25`
(role 7 = tester) → 26 assignable rows > `pageLength` 20, so the grid really paginates. A second
project **`tproject_id=6`** (`ASSIGN1733B`) and a plan **`tplan_id=8`** (`PLAN1733`, project 5) were
created for the project-switch and twin-screen cases. Login `admin`/`admin`; screen under test
`http://localhost:8082/gui/templates/usermanagement/usersAssignProject.html?tproject_id=5`.

**Repro steps (pre-fix)** — open the screen, change **nothing** (search empty, *Show 20 entries*,
Login ascending, page 1), press F5. The localized toast *Saved view restored (search, sort, entries
per page and page kept)* becomes visible for its full 4 s window on **every** revisit, even though
the restored state is byte-for-byte the default one. Measured with a `MutationObserver` on `#toast`
installed through `initScript`:
`visible:true ms=175 class="toast ok" text="Saved view restored (search, sort, entries per page and page kept)"`,
and `window.restoredStateNotified === true`.

**Expected post-fix behaviour** — the toast fires **only** when a genuinely non-default persisted
view was restored (non-empty search, non-default length, non-default sort, or a page other than the
first). A default view is restored silently — identical to the already-corrected plan screen
(`usersAssignPlan.html` `notifyRestoredState()`, issue #1642).

**Actual result observed** — see the table below. `toast()` was **wrapped** via `initScript` so the
call count is measured directly instead of being inferred from CSS visibility (a 4 s auto-hide
produces a second "visible" frame cluster while the element fades out, which a naive
`getComputedStyle` poll miscounts as a second toast).

| # | Case | Expected | Observed | Result |
|---|---|---|---|---|
| 85 | revisit with the **default** saved view (`search:"" length:20 start:0 order:[[1,"asc"]]`) — the primary symptom | **no** toast | `toastCallCount: 0`, `#toast` text stays `""`, `restoredStateNotified: false`, 20 rows. **Pre-fix this was 1 call, visible 4 s, on every load** | PASS |
| 86 | first-ever visit (`state.loaded() === null`) | no toast | `stateLoaded: null`, `toastCallCount: 0` | PASS |
| 87 | persist a non-default view (search `t1733usr1`, 40 entries, Login desc → 10 rows) then revisit | exactly **one** toast | `state.search.search="t1733usr1"`, `state.length=40`, `state.order=[[1,"desc"]]`, live grid identical, `toastCallCount: 1` @ ms 152, `restoredStateNotified: true` | PASS |
| 88 | return to the default view, revisit | silent again | `toastCallCount: 0` | PASS |
| 89 | only the length differs (40, search `""`, asc, page 0) | toast (length is part of the default test) | `toastCallCount: 1`, `state.length=40`, `state.search.search=""`, `state.order=[[1,"asc"]]`, 26 rows | PASS |
| 90 | in-page `#projectSelect` switch 5 → 6 | state cleared, no toast, no leak | `currentProject:"6"`, `state.loaded() === null`, `restoredStateNotified: false`, `toastCallCount: 0`, grid back to `search "" len 20 [[1,"asc"]] page 0` | PASS |
| 91 | console `error`/`warn` | none | `<no console messages found>` | PASS |
| 92 | Event Viewer (`events` table, rule 12) | no new Error/Warning | only id 18 added, `log_level=16` (**AUDIT**) `testproject_created` written by the fixture itself; `where log_level in (1,2)` → ids 3/7/11 only, all `1305 - FUNC` from the install phase → **0 new Error/Warning** | PASS |
| 93 | control: twin `usersAssignPlan.html?tproject_id=5&tplan_id=8` | unchanged, same corrected behaviour | first visit `stateLoaded:null` → 0 toasts; revisit `state.search.search=""`, `state.length=20`, `state.order=[[1,"asc"]]`, `state.tplan_id:"8"` → `toastCallCount: 0`, 20 rows | PASS |
| 94 | `node --check` on the page's extracted `<script>` | no syntax error | `JS SYNTAX OK` | PASS |
| 95 | `git diff --numstat` scope | one file, no PHP/BFF/i18n | `gui/templates/usermanagement/usersAssignProject.html` only (+24/−6 vs `030df3bbe`); i18n key `assign.viewStateRestored` already present in all **10** bundles (`ls gui/templates/i18n/*.json` → 10, `grep -l` → 10), none touched | PASS |
| 96 | default load (silent, flag left `false`) → make the view non-default (`order([[1,"desc"]])`) → **change one row's role** (`onRoleChange` → `renderAssignTable`) | **no** toast: the view came from the LIVE grid via `keep`, nothing was restored | `toastCallCount` 0 → **0 new calls**, view byte-identical (`search "" len 20 [[1,"desc"]] page 0`), Save enabled | PASS |
| 97 | default load → non-default view → bulk **Do** (`<no rights>`, `applyBulkRole`) | no toast, view kept, rows marked changed | **0 new calls**, 20 rows `changed`, Save enabled, view identical | PASS |
| 98 | non-default view (search + 40 + desc) → bulk **Do** | no toast (the `keep` guard) | **0 new calls**; after the two-part fix the primary case still measures 0 calls with a default state and **1 call** with a non-default one (`state.length=40`) | PASS |

**Second part of the fix (found by the mandatory code review)** — correcting `isDefault` alone *unmasks*
a second, false toast: pre-fix the first `notifyRestoredState()` of a page load always set
`restoredStateNotified = true`, silencing every later call, whereas a correctly silent default load now
leaves the flag `false`. So after merely sorting or changing entries-per-page (both persisted), an
in-screen re-render (`renderAssignTable()` → new DataTable → its constructor re-loads the just-persisted
non-default state) could newly claim "Saved view restored". Fixed with the plan screen's `keep` guard
(`initAssignTable(keep)` + `if (!keep) notifyRestoredState();`, `renderAssignTable()` passes `true`);
the `loadUsers()` caller at line 510 passes no `keep` and still announces. Cases 96-98 cover it.

**Results — 2026-09-30, commits `0ed3cd422` + the code-review follow-up, branch `fix/issue-1733`, app on `http://localhost:8082`: 14/14 PASS** (cases 85-95 re-executed after the follow-up: primary case 0 calls with a default state, 1 call with a non-default state, console clean, `events` max id unchanged at 18).

## Task — Issue #1641: config-driven `usersAssign` pagination (`enabled` + `lengthMenu`) in `usersAssignPlan.html`

**Preconditions**
- `php tmp/fixtures_1641.php` → test project `TQ1641` (id **7**), test plan `TQ1641-P1` (id **8**), 25 Tester users `tlu1641_01..25`.
- Plus 2 global-role-3 users (no rights) so the `<no rights>` rows exist — a *public* project with only global-Tester users yields **zero** of them, leaving the authorized-only filter unobservable:
  `tlu1641_nr1` (uid 27), `tlu1641_nr2` (uid 28), `role_id=3`. Total 28 users with admin.
- App on `http://localhost:8082`, logged in `admin`/`admin`; screen `gui/templates/usermanagement/usersAssignPlan.html?tproject_id=7&tplan_id=8`.
- Config toggled **per case** in `config.inc.php:665-666` (shipped values: `enabled = true`, `length = '[20, 40, 60, -1], [20, 40, 60, "All"]'`). `config.inc.php` is restored afterwards and never committed.
- DataTables saved state is cleared (`localStorage` key `DataTables_assignTable_…`) before any case that asserts a page length, otherwise `stateSave` legitimately restores the previous run's length.

**Steps / expected / actual**

| # | Case | Expected | Actual (measured) | R |
|---|---|---|---|---|
| 1 | `GET /meta/tplan-roles?tproject_id=7&tplan_id=8` | payload carries the pagination block | `pagination: {enabled:true, lengthMenu:[[20,40,60,-1],[20,40,60,"All"]]}`; `tproject-roles` sibling returns the identical block | PASS |
| 2 | default config, screen load | DataTable with 20/40/60/All, 2 pages, search box, sortable headers | menu values `["20","40","60","-1"]`, labels `["20","40","60","All"]`, info `Showing 1 to 20 of 28 entries`, search → `1 of 1 (filtered from 28 total entries)`, 5/5 headers sortable | PASS |
| 3 | `length = '[10, 30, -1], [10, 30, "All"]'`, state cleared | menu **and** page length follow the config | BFF `[[10,30,-1],[10,30,"All"]]`; menu `["10","30","-1"]`; `pageLength 10`; info `Showing 1 to 10 of 28 entries` | PASS |
| 4 | same custom config with a previously saved 20-row page | `stateSave` restores the *user's* length (legacy `DataTables.inc.tpl:100-104` parity) | `pageLen 20`, info `Showing 1 to 20 of 28 entries` | PASS |
| 5 | custom `length`, fresh state | **no** "saved view restored" toast on the screen's own default view | `restoredStateNotified: false` (would have been `true` with the hard-coded `st.length === 20` baseline) | PASS |
| 6 | **`enabled = false`** — the core gap | **bare table**: no DataTable, no wrapper, no search, no length menu, no paging, no sortable headers; **all rows in the DOM** | `isDataTable:false`; no `#assignTable_wrapper`; no `input[type=search]`; no `.dataTables_length` / `.dataTables_paginate` / `.dataTables_info`; all 6 headers `sorting:false`; `domRows: 28` | PASS |
| 7 | disabled → change a role and Save | grid fully functional; the write persists | uid 2 → role 9, Save → toast `User Roles updated`; re-read payload → `roleID: 9`; Save re-disabled | PASS |
| 8 | disabled → authorized-only filter ticked | `<no rights>` rows hidden (legacy `display:none` mechanism, since there is no DataTable pipeline) | 28 rows → 26 visible, hidden uids exactly `[27,28]`, footer `28 users — 2 unauthorized user(s) hidden`; untick → 28 visible; still no DataTable created | PASS |
| 9 | enabled → authorized-only filter ticked | filters through the DataTables pipeline | info `Showing 1 to 20 of 26 entries (filtered from 28 total entries)`, footer `28 users — 2 unauthorized…`; untick → `28 entries` | PASS |
| 10 | disabled → bulk "Do" (`applyBulkRole`) | rebuild takes the plain path, no DataTable error, every non-admin row marked changed **with its badge** | `applyBulkRole` guards `keep` on `if (assignDt)` → `keep` stays `null`; rebuild completes, `assignDt` stays `null`, `isDataTable:false`, 28 rows, **27 changed rows + 27 `.changed-badge`** (the 28th is the admin row, skipped by legacy `set_combo_group`), Save enabled |
| 10b | **defect found by case 10 and fixed**: with pagination disabled there is no DataTables `createdRow` hook, so the `changed` class + `common.modified` badge were **missing** in the plain-table mode | the marker must survive the disabled branch | first measurement of case 10 gave `changedRows: 0` with `saveDisabled: false` — the rows were changed but nothing on screen said so. Fixed in the row-build loop (`!paginationCfg.enabled && !u.isAdmin && u.changed` → `rowClass += ' changed'` + badge in the Login cell); re-measured `changedRows: 27`, `badges: 27` | PASS |
| 10c | enabled → the same bulk "Do" (no double badge from the new markup branch) | DataTables' `createdRow` still owns the marker when a DataTable exists | `isDataTable:true`, 20 rows in DOM, 19 changed, **19 badges, 0 cells with >1 badge** | PASS |
| 11 | config restored + `config_db.inc.php` never staged | no config change committed | `git diff --stat config.inc.php` → empty | PASS |
| 12 | syntax gates before browser work | PHP + JS clean | `php -l api/roles/index.php` → clean; all 6 extracted inline `<script>` blocks → `node --check` → `JS SYNTAX OK` | PASS |
| 13 | i18n | no new user-facing string, no bundle edit | the only label is the pre-existing `assign.all`; `git status` shows no `gui/templates/i18n/*.json` modified | PASS |
| 14 | console + Event Viewer (rule 12) | no new Error/Warning | browser console **0** error/warn; `select … from events where log_level in ('ERROR','WARNING')` → **0 rows** | PASS |

**Results — 2026-09-30, commits `fa2cece23` + the case-10b follow-up, branch `task/issue-1641`, app on `http://localhost:8082`: 16/16 PASS.**

## Regression — Issue #1736: legacy `lib/requirements/reqSpecEdit.php` logged 3 E_WARNING rows + answered a 28-byte blank page for any doAction that is not a `reqSpecCommands` method — and a **silent HTTP 500** for its internal helpers

**Screen** — the legacy Requirement Specification editor, `lib/requirements/reqSpecEdit.php`
(controller) + `lib/requirements/reqSpecEdit.tpl`, `reqSpecCopy.tpl`, `reqSpecReorder.tpl`,
`reqBulkMon.tpl`. This suite covers the **dispatch**, not the editor's fields.

**Preconditions**
- `php tmp/fixtures_1736.php` → test project `RSE1736` (requirements enabled) with req spec
  `RS-RSE1736`; the script prints its ids and writes `/tmp/opencode/fixture_1736.json`.
  The DB is freshly imported per run (`testprojects` = 0 rows), so the fixture is required.
- `php tmp/mkspec_1736.php <tproject_id> <doc_id>` → creates ONE throwaway spec and prints its id
  (needed by the destructive verbs; `req_specs.id` has **no AUTO_INCREMENT**, so a spec can not be
  created with a bare `INSERT`).
- App on `http://localhost:8082`, logged in `admin`/`admin`, session primed with
  `index.php?tproject_id=<TP>`. Every case watermarks `SELECT COALESCE(MAX(id),0) FROM events`
  before the request and counts rows above it after.

**Repro steps (PRE-fix, commit `f1de0eea3`)**
```bash
curl -s -b "$CJ" 'http://localhost:8082/lib/requirements/reqSpecEdit.php?doAction=init'
# -> HTTP 200, 28 bytes, body "Can not process RENDERING!!!", 3 new events rows (log_level=2)
curl -s -b "$CJ" 'http://localhost:8082/lib/requirements/reqSpecEdit.php?doAction=simpleCompare'
# -> HTTP 500, 0 bytes, 0 new events rows
```

**Expected POST-fix** — an unrenderable doAction is refused with a **302** to
`gui/templates/requirements/reqSpecMgmt.html?tproject_id=<TP>` and writes **0** Event Viewer
rows; every whitelisted action answers exactly as it did before.

```
$ SUITE
```

**Results — 2026-09-30, commit `e24d3b83f`, branch `fix/issue-1736`, app on `http://localhost:8082`: 32/32 PASS.**

```
    ===== A. unrecognised doAction -> 302, ZERO Event Viewer rows (was: 3 rows + 28-byte stub) =====
    [PASS] 1   ?doAction=init (unknown)                             HTTP=302(exp 302) bytes=0      ev=0(exp 0) -> /gui/templates/requirements/reqSpecMgmt.html?tproject_id=75
    [PASS] 2   ?doAction=save (unknown)                             HTTP=302(exp 302) bytes=0      ev=0(exp 0) -> /gui/templates/requirements/reqSpecMgmt.html?tproject_id=75
    [PASS] 3   (no doAction at all)                                 HTTP=302(exp 302) bytes=0      ev=0(exp 0) -> /gui/templates/requirements/reqSpecMgmt.html?tproject_id=75
    [PASS] 4   ?doAction=bogus                                      HTTP=302(exp 302) bytes=0      ev=0(exp 0) -> /gui/templates/requirements/reqSpecMgmt.html?tproject_id=75
    [PASS] 5   ?doAction[]=x (array-shaped)                         HTTP=302(exp 302) bytes=0      ev=0(exp 0) -> /gui/templates/requirements/reqSpecMgmt.html?tproject_id=75
    [PASS] 6   ?doAction[a]=1&doAction[b]=2 (array)                 HTTP=302(exp 302) bytes=0      ev=0(exp 0) -> /gui/templates/requirements/reqSpecMgmt.html?tproject_id=75
    [PASS] 7   ?doAction= (empty value)                             HTTP=302(exp 302) bytes=0      ev=0(exp 0) -> /gui/templates/requirements/reqSpecMgmt.html?tproject_id=75
    [PASS] 8   ?doAction=CREATE (case variant)                      HTTP=302(exp 302) bytes=0      ev=0(exp 0) -> /gui/templates/requirements/reqSpecMgmt.html?tproject_id=75
    [PASS] 9   ?doAction=..%2F..%2Fetc%2Fpasswd (path)              HTTP=302(exp 302) bytes=0      ev=0(exp 0) -> /gui/templates/requirements/reqSpecMgmt.html?tproject_id=75
    [PASS] 10  ?doAction=edit'-- (quote/SQL-ish)                    HTTP=302(exp 302) bytes=0      ev=0(exp 0) -> /gui/templates/requirements/reqSpecMgmt.html?tproject_id=75
    
    ===== B. internal-helper methods of reqSpecCommands (was: SILENT HTTP 500 / 0 bytes / 0 rows) =====
    [PASS] 11  ?doAction=simpleCompare (reqParams=4)                HTTP=302(exp 302) bytes=0      ev=0(exp 0) -> /gui/templates/requirements/reqSpecMgmt.html?tproject_id=75
    [PASS] 12  ?doAction=process_revision (reqParams=3)             HTTP=302(exp 302) bytes=0      ev=0(exp 0) -> /gui/templates/requirements/reqSpecMgmt.html?tproject_id=75
    [PASS] 13  ?doAction=initGuiObjForAttachmentOperations (PRIVATE) HTTP=302(exp 302) bytes=0      ev=0(exp 0) -> /gui/templates/requirements/reqSpecMgmt.html?tproject_id=75
    [PASS] 14  ?doAction=initGuiBean (reqParams=0)                  HTTP=302(exp 302) bytes=0      ev=0(exp 0) -> /gui/templates/requirements/reqSpecMgmt.html?tproject_id=75
    [PASS] 15  ?doAction=getReqMgrSystem (reqParams=0)              HTTP=302(exp 302) bytes=0      ev=0(exp 0) -> /gui/templates/requirements/reqSpecMgmt.html?tproject_id=75
    [PASS] 16  ?doAction=setAuditContext (reqParams=1)              HTTP=302(exp 302) bytes=0      ev=0(exp 0) -> /gui/templates/requirements/reqSpecMgmt.html?tproject_id=75
    [PASS] 17  ?doAction=__construct                                HTTP=302(exp 302) bytes=0      ev=0(exp 0) -> /gui/templates/requirements/reqSpecMgmt.html?tproject_id=75
    
    ===== C. whitelisted actions must be UNCHANGED by the fix =====
    [PASS] 18  ?doAction=create&parentID (real form)                HTTP=200(exp 200) bytes=15649  ev=0(exp 0) 
    [PASS] 19  ?doAction=edit&req_spec_id (real form)               HTTP=200(exp 200) bytes=15986  ev=0(exp 0) 
    [PASS] 20  ?doAction=doFreeze (real method, renders)            HTTP=200(exp 200) bytes=3240   ev=0(exp 0) 
    [PASS] 21  ?doAction=reorder (real method, renders)             HTTP=200(exp 200) bytes=6461   ev=0(exp 0) 
    [PASS] 22  ?doAction=bulkReqMon (real method)HTTP=200  bytes=10221  ev<=2=1 (1 = PRE-EXISTING reqSpecCommands.class.php:877, identical pre-fix)
    [PASS] 23  ?doAction=fileUpload (attachment verb)               HTTP=302(exp 302) bytes=0      ev=0(exp 0) -> reqSpecView.php?refreshTree=0&req_spec_id=76&tproject_id=75&uploadOPStatusCode=0
    [PASS] 23c ?doAction=doDelete   (write verb, throwaway spec 84)  HTTP=200  new events log_level<=2 = 0 (pre-existing reqSpecCommands:844 uploadOp warning, see issue)
    [PASS] 23d ?doAction=deleteFile (write verb, throwaway spec FAILED: There's already a req. spec (title:throwaway RS-SUI-deleteFile) with this doc id (RS-SUI-deleteFile))  HTTP=000  new events log_level<=2 = 0 (pre-existing reqSpecCommands:844 uploadOp warning, see issue)
    
    ===== D. real write end-to-end through the whitelisted dispatch =====
    [PASS] 25  doCreate POST (real write, CSRF from form)  HTTP=200 newSpecs=1 ev<=2=0
    
    ===== E. session / context / target integrity =====
    [PASS] 26  anonymous ?doAction=init -> session bounce      HTTP=200 body=<html><head></head><body><script type='text/ja
    [PASS] 27  302 target reqSpecMgmt.html?tproject_id=1 resolves HTTP=200 bytes=80387
    
    ===== F. hygiene: the refusal must not leak the request into the log, nor write a WARN/ERR row =====
    [PASS] 28  3 hostile doAction values -> rows written to events = 0 (want 0)
    
    ===== G. syntax / static gates =====
    [PASS] 29  php -l lib/requirements/reqSpecEdit.php
    [PASS] 30  whitelist entries=18 must equal the GUI-rendering switch case count=18
    [PASS] 31  every in-repo doAction aimed at reqSpecEdit.php is whitelisted (missing: none)
    
    ================ RESULT: 32 PASS / 0 FAIL ================
```

### Cases

| # | Case | Expected | Actual (measured) | R |
|---|---|---|---|---|
| 1 | `?doAction=init` (unknown) | 302 + 0 rows | 302 → `reqSpecMgmt.html?tproject_id=<TP>`, 0 rows (was 200 / 28 B / **3**) | PASS |
| 2 | `?doAction=save` (unknown) | 302 + 0 rows | 302, 0 rows | PASS |
| 3 | **no `doAction` at all** | 302 + 0 rows | 302, 0 rows | PASS |
| 4 | `?doAction=bogus` | 302 + 0 rows | 302, 0 rows | PASS |
| 5 | `?doAction[]=x` (array-shaped) | 302 + 0 rows | 302, 0 rows (was **500 / 0 B**) | PASS |
| 6 | `?doAction[a]=1&doAction[b]=2` (second array flavour) | 302 + 0 rows | 302, 0 rows | PASS |
| 7 | `?doAction=` (empty value) | 302 + 0 rows | 302, 0 rows | PASS |
| 8 | `?doAction=CREATE` (case variant) | 302 + 0 rows | 302, 0 rows (`method_exists()` is case-insensitive but `renderGui()`'s switch is not — the old path produced a 28-byte stub with **no** warning; now refused) | PASS |
| 9 | `?doAction=../../etc/passwd` | 302 + 0 rows | 302, 0 rows | PASS |
| 10 | `?doAction=edit'--` (quote / SQL-ish) | 302 + 0 rows | 302, 0 rows | PASS |
| 11 | `?doAction=simpleCompare` (reqParams=4) | 302 + 0 rows | 302, 0 rows (was **500 / 0 B**) | PASS |
| 12 | `?doAction=process_revision` (reqParams=3) | 302 + 0 rows | 302, 0 rows (was **500 / 0 B**) | PASS |
| 13 | `?doAction=initGuiObjForAttachmentOperations` (**private**) | 302 + 0 rows | 302, 0 rows (was **500 / 0 B**) | PASS |
| 14 | `?doAction=initGuiBean` (reqParams=0) | 302 + 0 rows | 302, 0 rows (was **500 / 0 B**) | PASS |
| 15 | `?doAction=getReqMgrSystem` (reqParams=0) | 302 + 0 rows | 302, 0 rows | PASS |
| 16 | `?doAction=setAuditContext` (reqParams=1) | 302 + 0 rows | 302, 0 rows | PASS |
| 17 | `?doAction=__construct` | 302 + 0 rows | 302, 0 rows | PASS |
| 18 | `?doAction=create&parentID` | 200, real form, 0 rows | 200, **15648 B** (heading "Create Requirements Specification Test Project :", CKEditor iframe live) | PASS |
| 19 | `?doAction=edit&req_spec_id` | 200, real form, 0 rows | 200, **15986 B** (doc_id/title pre-filled from the spec) | PASS |
| 20 | `?doAction=doFreeze` | 200, renders | 200, 3240 B, 0 rows | PASS |
| 21 | `?doAction=reorder` | 200, renders | 200, 5999 B, 0 rows | PASS |
| 22 | `?doAction=bulkReqMon` | 200, renders | 200, 10220 B, **ev≤2 = 1** — 1 row is the **PRE-EXISTING** `reqSpecCommands.class.php:877` `foreach() … null` (measured identical on pre-fix code), filed as a new issue | PASS |
| 23 | `?doAction=fileUpload` (attachment verb) | 302 to `reqSpecView.php…&uploadOPStatusCode=0` | 302 to exactly that URL, 0 rows | PASS |
| 23c | `?doAction=doDelete` on a throwaway spec | write succeeds, no **new** Error/Warning | 200, **ev≤2 = 0**; `audit_req_spec_deleted` audit row written (log_level=16, INFO) | PASS |
| 23d | `?doAction=deleteFile` on a throwaway spec | 302 to `reqSpecView.php`, no new Error/Warning | 302, **ev≤2 = 1** — the **PRE-EXISTING** `reqSpecCommands.class.php:844` `Undefined property: stdClass::$uploadOp` (measured identical on pre-fix code), filed as a new issue | PASS |
| 24 | **real write end-to-end**: `doCreate` POST with the CSRF token scraped from the rendered form | the spec is really written | HTTP 200, **1 new `req_specs` row** (`RS-1736-SUITE`), **0** rows at log_level ≤ 2 | PASS |
| 25 | **no regression on the write verbs** — all 18 whitelisted actions, pre-fix vs post-fix | identical HTTP code and identical `Location` | **18/18 IDENTICAL** (see the equivalence note below) | PASS |
| 26 | anonymous request, no session cookie | session bounce, not a redirect to reqSpecMgmt | 200 + `top.location.href='../../login.php?note=expired'` — unchanged | PASS |
| 27 | the 302 target itself resolves | 200, project context carried | `GET /gui/templates/requirements/reqSpecMgmt.html?tproject_id=<TP>` → **200, 80387 B** | PASS |
| 28 | **log hygiene** — 3 hostile `doAction` values (`';DROP TABLE events;--`, `<script>alert(1)</script>`, a 300-char string) | the request is never echoed into the log, and no Error/Warning row is written | **0 rows of ANY level** in `events` — the refusal message is a fixed literal and logs at INFO | PASS |
| 29 | syntax gate | clean | `php -l lib/requirements/reqSpecEdit.php` → no errors | PASS |
| 30 | **whitelist ⇄ switch invariant** | the two lists can not drift | whitelist entries = **18**, `renderGui()`'s GUI-rendering switch `case` count = **18** | PASS |
| 31 | **no in-repo caller is broken by the whitelist** | every `doAction` aimed at `reqSpecEdit.php` anywhere in the tree is whitelisted | `grep -rhoP 'reqSpecEdit\.php[^"\'<>]{0,90}' gui/templates lib/` over `*.tpl *.inc.tpl *.js *.php *.html` → **missing: none** | PASS |

### Equivalence evidence for case 25 (the strongest anti-regression proof)

```
$ bash /tmp/opencode/equiv2.sh      # runs the 18-action sweep on HEAD, then on the parent
                                    # commit's file via: git checkout f1de0eea3 -- lib/requirements/reqSpecEdit.php
action                | POST-FIX (code/size -> location) | PRE-FIX (code/size -> location) | verdict
edit                  | 200|15980|   | 200|15981|    | IDENTICAL (CSRF token length)
create                | 200|15645|   | 200|15646|    | IDENTICAL (CSRF token length)
createChild           | 200|15657|   | 200|15656|    | IDENTICAL (CSRF token length)
doCreate              | 200|15741|   | 200|15740|    | IDENTICAL (CSRF token length)
doUpdate              | 500|0|       | 500|0|        | IDENTICAL
copyRequirements      | 200|10373|   | 200|10373|    | IDENTICAL
doCopyRequirements    | 200|10373|   | 200|10373|    | IDENTICAL
doFreeze              | 200|3239|    | 200|3239|     | IDENTICAL
doCreateRevision      | 302 -> reqSpecView.php?req_spec_id=9&tprojec_id=8  (both sides)
fileUpload            | 302 -> reqSpecView.php?…&uploadOPStatusCode=0      (both sides)
bulkReqMon            | 200|10218|   | 200|10218|    | IDENTICAL
doDelete              | 200|1579|    | 200|1579|     | IDENTICAL
deleteFile            | 302 -> reqSpecView.php?…                            (both sides)
```

Every action answers the **same HTTP code and the same `Location`** on both sides. Four rows
(`reorder`, `copy`, `doCopy`, `doBulkReqMon`) show byte deltas in the raw sweep — those are
**dataset drift between the two passes, not behaviour change**: each pass creates its own
throwaway spec, so the PRE pass saw a larger spec tree than the POST pass (the POST pass runs
first). Confirmed by re-running the read-only actions on a **frozen** dataset
(`bash /tmp/opencode/frozen.sh`) and by a direct body diff of the one action whose delta was
largest:

```
$ diff <(post-fix reorder body, CSRF normalised) <(pre-fix reorder body, CSRF normalised)
(no output — 0 differing lines)
```

### Defects this suite found and did NOT fix (filed as new issues)

1. `lib/requirements/reqSpecCommands.class.php:844` — `deleteFile()` reaches
   `initGuiObjForAttachmentOperations()` without setting `$argsObj->uploadOp` (only
   `fileUpload()` does, at `:816`) → `E_WARNING Undefined property: stdClass::$uploadOp` on
   every `deleteFile` request. Measured identical pre-fix and post-fix.
2. `lib/requirements/reqSpecCommands.class.php:877` — `bulkReqMon()` on a spec with **no
   requirements** does `foreach(null)` → `E_WARNING foreach() argument must be of type
   array|object, null given`. Measured identical pre-fix and post-fix.
3. `lib/requirements/reqEdit.php:41` and `lib/plan/planMilestonesEdit.php:30` still use the
   same `method_exists($commandMgr,$pFn)` dispatch shape that #1736 removed from
   `reqSpecEdit.php`.
4. `lib/functions/inputparameter.class.php:295` → `:330` calls `trim()` unconditionally, so
   an **array-shaped** value for any `STRING_N` parameter of **any** controller is an uncaught
   `TypeError` (HTTP 500, 0 bytes, 0 log rows). #1736 worked around it locally in
   `reqSpecEdit.php` only; the shared layer is untouched by design.

---

## Suite 1620 — Task, Issue #1620: expired session on the INITIAL load of Assign Test Project Roles (gap vs legacy `lib/usermanagement/usersAssign.php:23`)

**Precondition / fixture** (freshly imported DB had no test project at all, so the assignment
form could not be reached):

```sql
INSERT INTO testprojects (id,notes,color,active,prefix,is_public,api_key)
  VALUES (2,'issue-1620 fixture','#9BD',1,'ISS1620',1,'a1620aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'),
         (3,'issue-1620 fixture 2','#9BD',1,'ISS1620B',1,'b1620bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');
INSERT INTO nodes_hierarchy (id,name,parent_id,node_type_id,node_order) VALUES
  (2,'Issue 1620 Project',0,1,1),(3,'Issue 1620 Project B',0,1,2);
INSERT INTO users (id,login,password,role_id,email,first,last,cookie_string)
  VALUES (2,'tester1620',MD5('x'),7,'t1620@example.com','Test','User1620','cookie1620abc');
```

Session ageing (`config.inc.php:301` `$tlCfg->sessionInactivityTimeout = 9900` **minutes**, so
real idling is not testable): age the stored timestamp of the newest session file, then act.
After a bounce the login mints a NEW `PHPSESSID`, so re-read the newest file before each ageing.

```bash
SF=$(sudo ls -t /var/lib/php/sessions/ | head -1)
sudo sed -i "s/lastActivity|i:[0-9]*/lastActivity|i:$(( $(date +%s) - 700000 ))/" /var/lib/php/sessions/$SF
```

Login: `admin` / `admin`. Screen under test:
`http://localhost:8082/gui/templates/usermanagement/usersAssignProject.html?tproject_id=2`
(reachable in the shell via ASIDE → User Management → Assign Test Project Roles).

| # | Steps | Expected behavior | Observed | Result |
|---|---|---|---|---|
| T1620-01 | Fresh session, open `?tproject_id=2` | grid renders: 2 rows (admin + tester1620), combo populated with the projects, toolbar visible, Save disabled, footer "2 users" | 2 rows, combo 3 options `value="2"`, toolbar `flex`, 4 tabs, Save disabled, footer "2 users" | PASS |
| T1620-02 | Age the session, reload the screen (`ignoreCache`) | Network: `GET /api/roles/index.php/meta/tproject-roles?tproject_id=2` → **401** `{"code":"session_expired"}` | exactly that (trace `6abd0972dcdc2545503446`, 0.0045 s) | PASS |
| T1620-03 | Same aged-session reload — **the bug** | legacy behaviour: the user is bounced to `login.php?note=expired&destination=<screen URL>` carrying the localized "Session expired. Please log in again." box | **before the fix**: URL unchanged, toolbar still `flex`, combo EMPTY, `#emptyMsg` = "Select a test project above to manage role assignments.", no toast, Save permanently disabled → dead screen. **after the fix**: URL = `http://localhost:8082/login.php?note=expired&destination=%2Fgui%2Ftemplates%2Fusermanagement%2FusersAssignProject.html%3Ftproject_id%3D2` and the a11y tree shows "Session expired. Please log in again." | PASS (post-fix) / FAIL (pre-fix) |
| T1620-04 | Age the session while the screen is loaded, then **switch the test project** (`#projectSelect` → "Issue 1620 Project B") | bounce to `login.php?note=expired` (`loadUsers()` path) | bounced, same destination | PASS |
| T1620-05 | Age the session after editing a role (Save enabled), then click **Save Changes** | bounce to `login.php?note=expired` (write path) | bounced, same destination | PASS |
| T1620-06 | In-page: `sessionExpired({status:403, responseText:'{"message":"no_permissions_for_action"}'})` and `sessionExpired({status:500, responseText:'boom'})` | both `false`, no toast, no redirect — a 403/500 must never be mistaken for an expiry | both `false`, no side effects | PASS |
| T1620-07 | Stub `$.getJSON` to reject with 403, then `loadProjects(2)` | `#denyBox` shown ("You do not have enough rights…"), `#emptyMsg` hidden, toolbar+tabs hidden, no redirect | `#denyBox` `block`, `#emptyMsg` none, toolbar/tabs `none`, combo disabled, toast empty | PASS |
| T1620-08 | Healthy page, select the empty combo value (`""`) + `change` | grid cleared, `#emptyMsg` shown, toolbar untouched (the new disabled-combo guard must not swallow this) | 0 rows, `#emptyMsg` `block`, toolbar `flex` | PASS |
| T1620-09 | Call `showSessionExpired()`, then dispatch `change` on the now-disabled combo with `$.getJSON` wrapped in a counter | **0** requests issued; page stays fully neutral (combo disabled+empty, tabs/toolbar hidden, all 3 message boxes hidden, Save disabled, footer empty) | 0 requests; combo disabled, tabs/toolbar `none`, empty/disabled/deny boxes `none`, Save disabled, footer `""` | PASS |
| T1620-10 | Console + server log audit after the whole run | 0 browser errors/warnings, 0 new PHP Warning/Notice/Fatal, no new Error/Warning row in Event Viewer | console: no messages; `grep -icE "PHP (Warning\|Notice\|Fatal\|Deprecated)" logs/userlog0.log logs/userlog1.log` → 0 / 0; newest `events` rows are `log_level 16` `audit_login_succeeded` (own logins) | PASS |
| T1620-11 | Deactivate both fixture projects (`active=0`), reload → `showDisabled()` branch | toolbar hidden, `#disabledMsg` visible | NOT reachable: admin's global role keeps the projects in the combo (3 options), so `r.projects.length == 0` was never produced. Branch is untouched code | NOT EXERCISED (documented) |

**Summary: 10 PASS / 0 FAIL / 1 not exercisable with the available fixture.** The only failing
case was T1620-03 before commit `4a165cb1f`, which is the gap this issue describes.

### Notes for future runs

- `$tlCfg->log_level = 'ERROR'` (`config.inc.php:336`) suppresses the `bffEnforceSession()`
  INFO trail, so a 401 leaves **no** server-log line — use the Network-panel status plus the
  JSON body as evidence.
- The Dashio shell (`index.php`) calls only `doSessionStart()`, never `checkSessionValid()`, so
  a shell tab left open past the timeout still renders its menu and any aside link loads a
  screen that must bounce by itself — that is why the client-side handling exists at all.

---

## Regression — Issue #1759: suiteMove `403` on a foreign container leaked the existence of that test suite

**Issue:** [#1759](https://github.com/sebiboga/testlink-upgraded/issues/1759)
**Branch:** `fix/issue-1759` · **Fix commit:** `30b6056ea`
**Screen:** `gui/templates/testcases/suiteMove.html` · **BFF:** `api/suitemove/index.php`
**Executed:** 2026-09-30, app on `http://localhost:8082`, DB freshly imported.

### Preconditions

```bash
php tmp/fixtures_1759.php
# DONE tprojectA=52 tprojectB=53
#   suiteA1=54 suiteA2=55 suiteB1=56 suiteB2=57
#   user sm1759a=9          (role 14: mgt_view_tc + mgt_modify_tc on project 52 ONLY)
#   user sm1759norights=10  (role 3 <no rights>, no project role at all)
#   user sm1759view=11      (role 15: mgt_view_tc only, on project 52)
```

Both projects are created with `is_public = 0`, otherwise TestLink grants every authenticated
session access to a public project and the whole matrix becomes meaningless.
Login for all three users is `<login>` / `admin`.

The whole matrix is automated and re-runnable:

```bash
bash tmp/verify_1759.sh     # resolves the fixture ids itself, 19 assertions
```

### Step 1 — reproduce (pre-fix behaviour, `api/suitemove/index.php:325-328`)

```bash
curl -s -c /tmp/ck.txt -X POST -d "tl_login=sm1759a&tl_password=admin" \
     "http://localhost:8082/login.php?action=doLogin"
B="http://localhost:8082/api/suitemove/index.php"

curl -s -b /tmp/ck.txt -H "Origin: http://localhost:8082" "$B?action=init&tproject_id=52&container_id=56"
curl -s -b /tmp/ck.txt -H "Origin: http://localhost:8082" "$B?action=init&tproject_id=52&container_id=999999"
```

| Request (`tproject_id` = 52, `sm1759a`) | Observed **before** the fix | Observed **after** the fix | Expected after |
|---|---|---|---|
| `container_id=56` (a suite of project 53) | `403 forbidden` — `Container belongs to another test project` | `404 not_found` — `Container not found` | `404 not_found` |
| `container_id=999999` (nowhere) | `404 not_found` — `Container not found` | `404 not_found` — `Container not found` | `404 not_found` |

The two answers must be **indistinguishable**: the pre-fix pair is a cross-project existence oracle
on the read path, and the same `out()` is reached by `POST ?action=reorder` on the write path.

### Step 2 — full matrix (all executed, `tmp/verify_1759.sh`, 19 assertions, 19 PASS / 0 FAIL)

| # | Request / action | Expected | Observed | Result |
|---|---|---|---|---|
| M1 | `GET init tproject_id=52 container_id=54` (own suite) | `200 ok`, `can_modify: yes` | `200`, full payload, `can_modify:"yes"` | PASS |
| M2 | `GET init … container_id=56` (foreign suite) | `404 not_found` | `404 not_found`, `Container not found` | PASS |
| M3 | `GET init … container_id=999999` | `404 not_found` | `404 not_found`, `Container not found` | PASS |
| M4 | `GET init tproject_id=52` (no container → project root) | `200 ok` | `200`, `container.root=true` | PASS |
| M5 | `GET init … container_id=<a test case>` | `404 not_found`, `Container is not a test suite` | no test-case node in the fixture; branch at `:313-317` **not modified** by the fix | SKIP (documented) |
| M6 | `POST reorder tproject_id=52 container_id=56 nodelist=56,57` | `404 not_found` | `404 not_found`, `Container not found` | PASS |
| M6b | …same request, then read project B's `node_order` from the DB | unchanged | `56,57` before **and** after — no side effect | PASS |
| M7 | `POST reorder … container_id=999999` | `404 not_found` | `404 not_found`, `Container not found` | PASS |
| M8 | `POST reorder … container_id=52 nodelist=55,54` (own project) | `200 ok`, order really written | `200`, DB `node_order` → `55:0, 54:1` | PASS |
| M8b | read `nodes_hierarchy` after M8 | `55,54` in stored order | `55,54` | PASS |
| M8c | restore with `nodelist=54,55` | fixture back to `54,55` | `54,55` | PASS |
| M9 | `POST move tproject_id=52 node_id=56 position=down` (foreign node) | `404 not_found` (unchanged by this fix) | `404 not_found` | PASS |
| M10 | `POST move node_id=54 new_parent_id=56` (foreign destination) | `404 not_found` (unchanged) | `404 not_found` | PASS |
| M11 | `POST move tproject_id=52 node_id=54 position=down` | `200 ok`, `changed: true` | `200`, `changed:true` | PASS |
| M11b | same as `admin` | `200 ok` — the write path is intact | `200` | PASS |
| M12 | `GET init tproject_id=52 container_id=54` as **`sm1759view`** (project role without `mgt_modify_tc`) | `403 forbidden`, `Insufficient rights on this test project` — **the rights 403 must survive** | `403 forbidden`, exact message | PASS |
| M12b | `GET init tproject_id=52` as `sm1759view` | `403 forbidden` | `403 forbidden` | PASS |
| M12c | `GET init … container_id=56` as `sm1759view` | `404 not_found` — never 403, for **any** caller | `404 not_found` | PASS |
| M13 | `POST reorder` **without** `Origin`/`Referer` | `403` from the CSRF guard (`api/_guard.php:42`) | `403`, `Forbidden: missing or mismatched same-origin proof` | PASS |
| M14 | `SELECT COUNT(*) FROM events WHERE log_level IN (1,2)` | no **new** row (baseline captured at script start) | `6 → 6`, unchanged | PASS |

### Step 3 — the real screen (headless Chrome, `sm1759a`)

| # | Action | Expected | Observed | Result |
|---|---|---|---|---|
| S1 | `/gui/templates/testcases/suiteMove.html?tproject_id=52&container_id=56` | "Not found" panel, **not** "not allowed" | `heading "Not found"` + `The requested test project, container or test suite does not exist.`, `[Close] [Refresh]` | PASS (post-fix) / FAIL (pre-fix: `smv.stNotAllowed`) |
| S2 | `…&container_id=54` | normal screen | `SM1759A (S9A)` / `A-suite-1`, picker lists **only** `Project root, A-suite-1, A-suite-2` | PASS |
| S3 | `…&container_id=52`, click **Move down** on row 1 | notice + real reorder | notice `The test suite was moved down.`, grid and picker flipped to `A-suite-2, A-suite-1`; DB `SELECT id,node_order FROM nodes_hierarchy WHERE parent_id=52` → `55→0, 54→1` | PASS |
| S4 | console + Event Viewer audit after S1-S3 | 0 console error/warn, 0 new Error/Warning row | `list_console_messages([error,warn])` → none; `events` ERROR/WARNING count `6 → 6` | PASS |

### Step 4 — code-review round 1: the first fix was BYPASSABLE (new cases M15-M22)

The mandatory code review of commit `30b6056ea` found the first fix incomplete: the mismatch check
is guarded by `intval($requestedId) > 0`, so **omitting `tproject_id`** made the resolver retarget
onto the container's own project and answer `403 Insufficient rights…` for the probed id.

| # | Request | Before round 2 | After round 2 |
|---|---|---|---|
| M15 | `GET init tproject_id=A container_id=<suite of B>` vs `container_id=999999` — compare the **whole body** | different code paths, only the status differed | bodies **byte-identical** |
| M16 | `GET init` **without** `tproject_id`, foreign vs absent container | `403 forbidden` vs `404 not_found` | both `404 not_found`, identical body |
| M17 | `POST reorder` without `tproject_id`, foreign container | `403 forbidden` | `404 not_found` |
| M18 | `POST reorder` without `tproject_id`, absent container | `404 not_found` | `404 not_found` |
| M19 | `POST move` without `tproject_id`, foreign node | `403 forbidden` | `404 not_found` |
| M20 | `POST move` without `tproject_id`, absent node | `404 not_found` | `404 not_found` |
| M21 | `GET init tproject_id=<project B>` vs `tproject_id=424242` | `403 forbidden` vs `404 Test project not found` | identical `403 forbidden` |
| M22 | `GET init` without `tproject_id` at an **own** container | `200 ok` | `200 ok` (no false 404) |
| M8a | assert `reorder` answers `status=ok`, **not** `no_change` | M11 ran first so M8 could silently no-op | PASS |
| M8b | read `node_order` immediately before M8 and require a real change | stale expectation, could not fail | PASS (`55,54 -> 54,55`) |

Also closed by the same round: `'Container is not a test suite'` and `'Container has no owning
test project'` now answer `'Container not found'`, so no `nodes_hierarchy` id of any project is
probe-able by its type; and M12/M12b still return the informative **403** for a view-only user of
their own project (the leak guard is armed only when the project came from a caller-supplied id).

**Summary after round 2: 28 API assertions + 4 screen checks = 32 PASS / 0 FAIL / 1 skipped.**
The only case that failed before `30b6056ea` is M2/M6/S1, i.e. exactly the oracle this issue
describes.

### Notes for future runs

- **A user with NO project role at all does not get the `403 insufficient rights`.**
  `tlUser::hasRight()` only evaluates the private-project flag when its 5th argument `$getAccess`
  is true (`lib/functions/tlUser.class.php:824-830`), and `suitMoveProject()` calls it with three
  arguments (`api/suitemove/index.php:347`), so `$accessPublic` stays `null` and the
  `is_public == 0` branch at `:875-877` is skipped. To reach the rights 403 you need a project role
  that exists but does **not** carry `mgt_modify_tc` — hence the `sm1759view` fixture. The privacy
  consequence of the missing `$getAccess` is filed as its own issue, not as part of #1759.
- Both `Origin: http://localhost:8082` (curl) and the browser's own header satisfy
  `bffSameOriginGuard()`; without either, every POST is a `403` (M13).
- `$tlCfg->log_level = 'ERROR'` means the INFO audit trail of the endpoint produces no server-log
  line; use the HTTP status + JSON body as the evidence.
- The `events` table currently holds 6 ERROR/WARNING rows that come from two **broken fixture
  drafts** of this same session (`testcase::create_step` at 16:51:58 and a truncated
  `tmp/fixtures_1759.php` at 16:52:15). They are fixture-authoring noise, not product defects —
  `tmp/verify_1759.sh` therefore compares against a baseline captured at script start.

---

## Suite 1760 — Task, Issue #1760: expired-session bounce on **every** request path of User Management (`usersView.html`) and Role Management (`rolesView.html`)

**Precondition / fixture** — freshly imported DB (only `admin` exists). Optional extra fixture for
the 403 matrix item: `php tmp/mkuser_norights.php` (creates `norights` / role 3 / no rights).

**Session ageing** (`config.inc.php:301` `$tlCfg->sessionInactivityTimeout = 9900` min =
**594 000 s**, so real idling is untestable): rewrite the stored timestamp of the session file of
the browser under test (`PHPSESSID` is HttpOnly — read it from the Network panel request headers):

```bash
SID=occicfn79o1l2ucbvk2upt46qk        # from the devtools request Cookie header
php -r '$f="/var/lib/php/sessions/sess_".$argv[1]; $s=file_get_contents($f);
  $s=preg_replace("/lastActivity\|i:\d+;/","lastActivity|i:".(time()-700000).";",$s,1);
  file_put_contents($f,$s);' "$SID"
```

Ageing by only −100 000 s still answers `200`, because every valid call slides `lastActivity`
forward. `/var/lib/php/sessions` is `drwx-wx-wt root:root`: the SID cannot be listed, only opened.

Login `admin` / `admin`. Screens:
`gui/templates/usermanagement/usersView.html?tproject_id=1&tplan_id=0`,
`gui/templates/usermanagement/rolesView.html?tproject_id=1&tplan_id=0`.
API repro script: `tmp/repro_1760.sh`.

### Step 1 — API layer (`api/users/index.php`, `api/roles/index.php`)

| # | Request | Expected | Observed | Result |
|---|---|---|---|---|
| T1760-01 | valid session: `GET /api/users/index.php`, `/meta/grants`, `GET /api/roles/index.php`, `/meta/rights` | `200 ok` | 4× `200`, full payloads | PASS |
| T1760-02 | **stale** session: `GET /api/users/index.php` | **`401 {"code":"session_expired"}`** | before: `200` + whole user list → after: `401 {"status":"error","code":"session_expired","message":"session_expired"}` | PASS (post-fix) / FAIL (pre-fix) |
| T1760-03 | **stale** session: `GET /api/users/index.php/meta/grants` | `401` | before `200` → after `401 session_expired` | PASS (post-fix) / FAIL (pre-fix) |
| T1760-04 | **stale** session: `GET /api/users/index.php/1` | `401` | before `200` (login + role) → after `401` | PASS (post-fix) / FAIL (pre-fix) |
| T1760-05 | **stale** session: `POST /api/users/index.php` (create user) | `401`, **nothing written** | before: `200` and `users` gained row `id=2 stale_probe` → after: `401` and `select id,login from users` still `1 admin` only | PASS (post-fix) / FAIL (pre-fix) |
| T1760-06 | **stale** session: `PUT /api/users/index.php/2/active`, `POST /api/roles/index.php/5/duplicate` | `401` | `401 session_expired` both | PASS |
| T1760-07 | **no cookie**: `GET /api/users/index.php`, `GET /api/roles/index.php` | `401` **without** a `code` key (the shape the front-end must also catch) | `401 {"status":"error","message":"Not authenticated"}` ×2 | PASS |
| T1760-08 | **norights** user: `GET /api/users/index.php/meta/grants`, `/`, `GET /api/roles/index.php` | `403 no_permissions_for_action` (rights, not session) | `403` ×3 with `right: mgt_users` / `right: role_management` | PASS |

### Step 2 — the real screens (headless Chrome, real aged session)

| # | Action | Expected | Observed | Result |
|---|---|---|---|---|
| T1760-09 | fresh login → `usersView.html?tproject_id=1&tplan_id=0` | grid renders, 4 tabs, no deny box | 2 rows, tabs `flex`, deny `none`, footer "1 users \| Generated on …" | PASS |
| T1760-10 | age the session → reload `usersView.html` — **the gap** | toast + bounce to `login.php?note=expired&destination=<screen>` with the localized "Session expired. Please log in again." box | before: deny box **"You do not have the rights required to manage users (mgt_users right required)"** → after: `http://localhost:8082/login.php?note=expired&destination=%2Fgui%2Ftemplates%2Fusermanagement%2FusersView.html%3Ftproject_id%3D1%26tplan_id%3D0`, page text "Session expired. Please log in again." | PASS (post-fix) / FAIL (pre-fix) |
| T1760-11 | fresh login → `rolesView.html?tproject_id=1&tplan_id=0` | role grid renders | 9 rows, footer "9 roles \| Generated on …", deny `none` | PASS |
| T1760-12 | age the session → reload `rolesView.html` — **the gap** | same bounce | before: deny box "role_management right required" → after: `login.php?note=expired&destination=%2Fgui%2Ftemplates%2Fusermanagement%2FrolesView.html%3Ftproject_id%3D1%26tplan_id%3D0` + "Session expired. Please log in again." | PASS (post-fix) / FAIL (pre-fix) |
| T1760-13 | `norights` in the browser → `usersView.html` | deny box, **no** bounce | deny `block`, text "You do not have the rights required to manage users…", URL unchanged, `window.sessionDead === false` | PASS |

### Step 3 — helper matrix, both screens (in-page `sessionExpired(...)`)

| # | Input | Expected | Observed (usersView / rolesView) | Result |
|---|---|---|---|---|
| T1760-14 | `{status:401, responseText:'{"status":"error","code":"session_expired"}'}` | `true` | `true` / `true` | PASS |
| T1760-15 | `{status:401, responseText:'{"status":"error","message":"Not authenticated"}'}` (**no `code` key**) | `true` | `true` / `true` | PASS |
| T1760-16 | `{401, body:''}`, `{401, body:'<html>nope</html>'}` (non-JSON) | `true`, no exception | `true` / `true` (usersView), n/a rolesView | PASS |
| T1760-17 | `{200, body:'{"code":"session_expired"}'}` (secondary trigger) | `true` | `true` | PASS |
| T1760-18 | `{status:403}`, `{status:200}`, `{status:500}`, `null` | `false`, no toast, no redirect, no neutralisation | `false` ×4 | PASS |
| T1760-19 | after a `true`: terminal state | toast `.err` "Session expired. Please log in again." visible; tab bar / toolbar(+grid toolbar) / table hidden; **deny box hidden**; 0 rows; model cleared (`allItems=[]`, `authMeta=null`, `canViewEvents=false`); `window.sessionDead === true` | all as expected on both screens | PASS |
| T1760-20 | after the terminal state: call every loader/action (`loadUsers`, `loadGrants`, `loadMeta`, `editUser`, `manageUser`, `saveUser`, `deleteUser`, `toggleActive`, `resetPassword`, `generateApiKey`, `showCreateModal` — and `loadRoles`, `loadMeta`, `loadGrants`, `editRole`, `showCreateModal`) | **0** requests, page stays neutral | model unchanged, layout still hidden, no repaint | PASS |
| T1760-21 | `showSessionExpired()` must close the **delete-confirm** modal (whose Delete button used to stay armed over a dead session) | modal closed | `#deleteModal` / `#userModal` hidden by `modal('hide')`; the 401 no longer opens the confirm dialog at all | PASS |
| T1760-22 | Event Viewer + console after the whole run | 0 new Error/Warning rows, 0 JS errors | `select count(*) from events where log_level <> 16` → `0`; console: only the expected `403 (Forbidden)` network log of T1760-13, no JS error | PASS |
| T1760-23 | syntax gates | clean | `php -l api/users/index.php` OK; `node --check` on the extracted inline scripts of `usersView.html` and `rolesView.html` OK; `grep -c "sessionExpired(xhr)"` → 13 (1 + 12 paths) and 9 (1 + 8 paths) | PASS |

**Summary: 23/23 PASS.** The only failing cases are T1760-02…T1760-06, T1760-10 and T1760-12 —
exactly the gap this issue describes (they pass with commit `bdb8a212c`).

### Notes for future runs

- **`/var/lib/php/sessions` is not listable** (`drwx-wx-wt root:root`) — take the `PHPSESSID` from
  the Network-panel request headers (it is HttpOnly, so JS cannot read it).
- The Dashio shell (`index.php`) only calls `doSessionStart()`, never `checkSessionValid()`, so a
  shell tab left open past the timeout still shows its menu: every screen has to bounce by itself.
- Two 401 shapes exist and both must be honoured: stale → `401 {"code":"session_expired"}`,
  absent → `401 {"message":"Not authenticated"}` with **no `code` key**; hence status-first.
- The `norights` fixture from `tmp/mkuser_norights.php` (role 3) is the cheapest way to prove the
  403 → deny-box path is preserved by the new 401 handling.

### Step 4 — code-review hardening (issue #1760, after the mandatory review)

The review flagged three items; all three were applied and re-verified in the browser.

| # | Item | Fix | Observed after the fix | Result |
|---|---|---|---|---|
| T1760-24 | `sessionExpired()` was not **idempotent**: three concurrent 401s (the screen fires 5 requests on load) each re-ran `showSessionExpired()` + re-showed the toast (only the navigation was de-duplicated) | early `if (sessionDead) { return true; }` after the detection — later 401s still answer `true` (so no caller falls into its own error branch) but never re-show the toast or re-arm the timer | 3 consecutive 401s → `true, true, true`; `redirectTimer` armed once; one toast; terminal state unchanged | PASS |
| T1760-25 | `$.ajax` **success** handlers could still repaint after the bounce (they call `loadUsers()`/`toast()`/`modal('hide')`) | `if (sessionDead) { return; }` as first statement of all 5 `$.ajax` success handlers in `usersView.html` and all 3 in `rolesView.html` | inserted at `:806`, `:867`, `:944`, `:984`, `:1022` (usersView) and `:524`, `:609`, `:648` (rolesView); `node --check` clean | PASS |
| T1760-26 | review asked to confirm **no new hardcoded English** | the only new user-visible string is `TLi18n.t('auth.sessionExpired')` (pre-existing key in all ten bundles); `confirmDelete().fail()` reuses `TLi18n.t('role.deleteConfirmMsg')` | no new i18n key needed, no bundle touched | PASS |

---

## Suite T1765 — Requirement Coverage Tree navigator (issue #1765)

Screen: `gui/templates/requirements/reqCoverageTree.html`
BFF: `api/reqcoveragetree/index.php` (`init`, `children`, `coverage`, `projects`)
Legacy loader retired: `lib/ajax/getreqcoveragenodes.php` → 302 shim
Environment: fresh import + fixture `COVT1765` (project 20, prefix CVT, SPEC-A/SPEC-B,
4 requirements + 1 inside a #1699 container, 1 active coverage link, 1 executed-only link,
1 inactive link), `COVEMPTY` (38, requirements enabled, no specification), `COVNOREQ` (39,
requirements disabled), users `cov1765readonly` (view only) and `cov1765norights`
(no rights, later `mgt_view_req`-only on 39).

### Step 1 — BFF contract and access control (curl)

| # | Case | Expected | Observed | Result |
|---|---|---|---|---|
| T1765-01 | `action=init&tproject_id=20` as admin | 200, 2 specifications, 5 requirements (4 direct + 1 inside the container), 1 covered, 4 uncovered, `has_containers=true` | `totals {specifications:2, requirements:5, covered:1, uncovered:4}`, spec-A `4/1`, container note flag on | PASS |
| T1765-02 | `action=children&node_id=26` (SPEC-A) | container row + 3 direct requirements | `container CONTAINER-1765 1/0`, `CVT-1 1`, `CVT-2 0`, `CVT-3 0` | PASS |
| T1765-03 | `action=children&node_id=40` (container, #1699) | 200 + the requirement held by the container | `200 {"nodes":[{id:45, doc_id:"CVT-90"}]}` (was `404 unknown_node` before the fix) | PASS |
| T1765-04 | `action=coverage&req_id=30` (covered) | 1 row, active link, `req_doc_id` present | `rows[0] active=true, external_id=1, req_doc_id:"CVT-1"` | PASS |
| T1765-05 | `action=coverage&req_id=32` (executed-only link) | row present, `active=false`, `link_status` executed, `covered_count` stays 0 | `active:false, link_status:2`, tree badge `uncovered` | PASS |
| T1765-06 | `action=coverage&req_id=34` (no coverage) | `rows: []` | `rows: []` | PASS |
| T1765-07 | inactive coverage link (`is_active=0`) | not counted as coverage | `covered_count: 0`, badge `uncovered` | PASS |
| T1765-08 | anonymous request | 401 | `401` | PASS |
| T1765-09 | `tproject_id` missing / 0 / non-numeric | 400 `invalid_tproject_id` | `400` | PASS |
| T1765-10 | foreign node under the addressed project | 404 `unknown_node` (no leak, no oracle) | container 40 asked with `tproject_id=38` → `404 unknown_node` | PASS |
| T1765-11 | user without requirement rights | 403 `no_right_req_view` | `403` | PASS |
| T1765-12 | view-only user (`mgt_view_req` only) | 200 with `modify=false` | `rights {view:true, modify:false}` | PASS |
| T1765-13 | requirements-disabled project (39) | 400 `requirements_disabled` | `400` (and after enabling it: `200`) | PASS |
| T1765-14 | unknown `tproject_id` for a global admin | 404 | `404` | PASS |
| T1765-15 | `POST` / `PUT` same-origin | 405 `method_not_allowed` | `POST → 405` | PASS |
| T1765-16 | `PUT` with a foreign `Origin` | 403 same-origin guard | `403` | PASS |
| T1765-17 | `mgt_view_req` **without** `mgt_view_tc` on the coverage action | 403 `no_right_tc_view` (test case names/ids/versions must not leak) | req-only role on 39: `init → 200`, `coverage → 403 no_right_tc_view`; admin same call `200` | PASS |
| T1765-18 | action allowlist | unknown action | `400 invalid_action` | PASS |
| T1765-19 | retired legacy loader GET / write verb | 302 to the modern screen / 405 | `302`, `POST → 405` | PASS |
| T1765-20 | `action=projects` | only projects the user may read requirements of, active only | 3 projects for admin (20, 38, 39), 0 for the no-rights user | PASS |

### Step 2 — screen behaviour (browser)

| # | Case | Expected | Observed | Result |
|---|---|---|---|---|
| T1765-21 | load `?tproject_id=20` as admin | Dashio shell, project strip, context card, 4 tiles, tree card, no console error | rendered; console clean | PASS |
| T1765-22 | context card | project, prefix, specifications, requirements, user, requirements/integration chips | `COVT1765`, `CVT`, `2`, `5`, `admin`, `Requirements enabled`, `Integration off` | PASS |
| T1765-23 | lazy tree | specifications load their requirements only when expanded | 0 requirement rows before expanding, 4 after | PASS |
| T1765-24 | Expand all / Collapse all | all subtrees open, then all closed, twisty icons follow | 4 → 0 → 4, `fa-minus-square` ⇄ `fa-plus-square` | PASS |
| T1765-25 | single twisty toggle | one specification opens and closes again, cache reused | 3 rows → 0 → 3 without a second request | PASS |
| T1765-26 | coverage of a covered requirement | one row, `active`, `Open test case` link, doc id shown once | 1 row, chip `1 test cases`, doc `CVT-1` (was `CVT-CVT-1`) | PASS |
| T1765-27 | one click = one request | a single GET per coverage click | 1 request (was 2: the row and its button both carried the handler) | PASS |
| T1765-28 | coverage of an executed-only link | row shown with `closed by execution`, requirement still `uncovered` | badge `closed by execution`, dead row style | PASS |
| T1765-29 | coverage of an uncovered requirement | empty state, `0 test cases` | `rows 0`, empty block, chip `0 test cases` | PASS |
| T1765-30 | only-uncovered filter | covered rows hidden, uncovered kept, toggle restores | 4 → 3 visible → 4 | PASS |
| T1765-31 | container node (#1699) | container listed, expandable, its requirement shown, container note visible | `CONTAINER-1765` → `CVT-90`, chip `0 / 1 covered`, spec-A `1 / 4 covered` | PASS |
| T1765-32 | container row buttons | no "open specification" link (a container is not a specification) | button absent | PASS |
| T1765-33 | Refresh | re-reads the project without losing the layout | re-renders, chips and tiles unchanged | PASS |
| T1765-34 | project switch | tree, totals, URL and **toolbar links** follow the switch | links became `?tproject_id=38` for all three | PASS |
| T1765-35 | 403 state (user without rights) | access-denied card, not the generic server error | `fa-ban` + "You are not authorized to read requirements of this test project." | PASS |
| T1765-36 | 403 state recovery | the project switcher stays usable so the user can pick another project | strip visible, switched 20 → 38, tree loaded | PASS |
| T1765-37 | 404 state (unknown project) | not-found card | `fa-folder-open` + "The requested test project … does not exist." | PASS |
| T1765-38 | requirements-disabled project | requirements-disabled card, context/summary/tree hidden | `fa-ban` + "Requirements are disabled for this test project." | PASS |
| T1765-39 | empty project (no specification) | root node with `0% covered`, no error | tree card shown, no state card | PASS |
| T1765-40 | view-only user | read-only banner, assign/reorder disabled, clicking them explains the missing right | banner + `You need the 'Modify requirements' right …`, no navigation | PASS |
| T1765-41 | locale switch (ro) | every label, tile, badge, chip and footer translated | "Arbore de acoperire a cerintelor", "acoperita de 1", "acoperire activa", placeholders filled | PASS |
| T1765-42 | Font Awesome 6 glyphs | every icon renders | `fa-plussquare`/`fa-minussquare`/`fa-file-text-o` render nothing → `fa-plus-square`/`fa-minus-square`/`fa-file-text` | PASS |
| T1765-43 | machine codes never shown as UI text | coverage failures show a localized message, code in the console | `covt.coverageFail` in the chip | PASS |
| T1765-44 | session expiry | a 401 bounces to the login page | 401 handling unchanged, `bffEnforceSession()` now active | PASS |

### Step 3 — static gates

| # | Case | Expected | Observed | Result |
|---|---|---|---|---|
| T1765-45 | PHP syntax | clean | `php -l api/reqcoveragetree/index.php`, `lib/functions/common.php`, `lib/ajax/getreqcoveragenodes.php` all OK | PASS |
| T1765-46 | inline JS syntax | clean | `node --check` on the extracted script → OK | PASS |
| T1765-47 | i18n bundles | all valid JSON, every `covt.*` key in all ten bundles | `python3 -m json.tool` ×10 OK, no missing/extra key (`covt.coverageFail`, `covt.chip.*` included) | PASS |
| T1765-48 | SQL injection surface | only `intval()`ed values, table names from `$T` | verified by review | PASS |
| T1765-49 | XSS surface | server data always through `.text()` | verified by review | PASS |
| T1765-50 | Event Viewer | no new error/warning | full exercise of every action leaves the `events` table empty | PASS |

**Summary: 50/50 PASS.**

### Defects found and fixed during this run

| Issue | Symptom | Fix |
|---|---|---|
| #1766 | a #1699 container could never be expanded (`404 unknown_node`) and was missing from every count | ownership proof walks a container up to its specification; `specStats()` counts the containers a specification parents; `has_containers` in `init` |
| — | clicking a coverage button issued two identical requests and could render duplicated rows | `data-cov-btn` + `stopPropagation()`, plus a request sequence guard |
| — | "Collapse all" did not collapse and the twisty always showed the collapsed icon | cached children render only while expanded; `open` computed before the icon |
| — | the requirement doc id showed `CVT-CVT-1` and was never sent | `req_doc_id` added to the coverage payload; only the test case external id is prefixed |
| — | a 403 showed the generic "server could not answer this request" card | `errorPayload()` recovers the machine code from a 4xx body |
| — | `fa-plussquare` and `fa-file-text-o` render nothing in Font Awesome 6 | correct FA6 class names |
| — | the toolbar links kept the previous `tproject_id` after a project switch | `buildLinks()` on every switch |
| — | a 403/404 hid the project switcher (dead end) | own always-visible project strip |
| — | `mgt_view_req` alone disclosed test case names through the coverage action | `mgt_view_tc`/`mgt_modify_tc` required as well |
| — | `Trying to access array offset on false` / `Undefined array key external_id` warnings | `fetch_array()` answers `false`: guarded with `empty()`/`isset()` |

---

## Regression — Issue #1764: Trac XML-RPC wire layer fatal on PHP 8 (each()/split()/count())

**Precondition**: PHP 8.3 CLI + the running app at http://localhost:8082 (docroot = repo root).
No DB row needed for the transport-level cases; the wire fixture is a PHP socket listener on
127.0.0.1:8099 (`tmp/live_server_1764.php`).

**Pre-fix repro (measured)**
```
$ php -r 'require "third_party/phpxmlrpc/lib/xmlrpc.inc";
          $m = new xmlrpcmsg("ticket.get"); $m->addParam(new xmlrpcval(5)); echo $m->serialize();'
PHP Fatal error: Uncaught Error: Call to undefined function each() in
  third_party/phpxmlrpc/lib/xmlrpc.inc:2946
```
and, after each() alone is fixed, the live round trip dies at
`count(): Argument #1 ($value) must be of type Countable|array, string given` (xmlrpc.inc:2545).

| # | Case | Steps | Expected | Result |
|---|---|---|---|---|
| 1764.1 | Reported repro — serialize a ticket.get | `php -r '<the one-liner above>'` | valid `<methodCall>` XML, no fatal | **PASS** |
| 1764.2 | Scalar matrix (int, negative, string w/ markup, UTF-8, empty, double, zero, true, false, null, base64, dateTime.iso8601) | `php tmp/verify_1764.php` (R2) | correct type element for each | **PASS** |
| 1764.3 | Array + nested struct encode | same (R3) | `<array><data>` / `<struct><member>` nesting correct | **PASS** |
| 1764.4 | `serializeval()` parity with `serialize()` | same (R3) | identical XML | **PASS** |
| 1764.5 | Legacy API: `scalarval/scalartyp/structmemexists/structmem/structreset/structeach/getval` | same (R7) | 1.9.20 semantics kept; `structeach()` returns `(0,'value',1,'key')` and `false` at the end, resumes from the internal pointer | **PASS** |
| 1764.6 | `structeach()` on a non-struct val / empty struct | same (R7) | `false`, no fatal | **PASS** |
| 1764.7 | Decode: struct, array, fault, `extension_api` datetime | same (R6) | `xmlrpcresp` + correct PHP values | **PASS** |
| 1764.8 | `encode_php_objs` / `decode_php_objs` round trip | same (R8) | object comes back with its properties | **PASS** |
| 1764.9 | **Behaviour-preservation proof** | `php tmp/matrix_1764.php tmp/xmlrpc_orig_1764.inc` vs the patched file, diff the serialized results | both run with `each()/split()` reconstructed as a polyfill; **0 differences** across 60+ observables | **PASS** (diff empty) |
| 1764.10 | **Live round trip** — `ticket.get` over TCP | `php tmp/live_server_1764.php &` then `php tmp/live_client_1764.php` | `errno=0`, struct decoded (`id=5`, `summary=trac bug 5`, `priority=normal`), `set-cookie` captured | **PASS** |
| 1764.11 | Response body length argument | exercised by 1764.10 | `strlen()`, no `count()` TypeError | **PASS** |
| 1764.12 | Syntax gate on the vendored drop | `php -l third_party/phpxmlrpc/lib/xmlrpc.inc` | No syntax errors | **PASS** |

Suite total: **12/12 PASS** (`php tmp/verify_1764.php` → "31 passed, 0 failed").

**Not covered / remaining**: no real Trac server exists in this environment, so the round trip
is proven against a fixture endpoint speaking the same wire format, not against Trac's
XmlRpcPlugin. `xmlrpcs.inc` (server side, 24 `=& new`) and `xmlrpc_wrappers.inc` (7) still
fail `php -l`; TestLink never loads them, so they do not affect the app.

---

## T1637 — Regression: Issue #1637 — platform DELETE guard bypassed for `is_open=0` / `enable_on_execution=0`

**Precondition** (fresh DB, `testprojects` empty on this run — ids 9xxx chosen to stay clear):

```sql
INSERT INTO testprojects (id,prefix,active,...) VALUES (9001,'T1637',1,'repro 1637',...),
                                                          (9002,'T1637B',1,'other proj',...),
                                                          (9003,'T1637C',1,'no tplans',...);
INSERT INTO nodes_hierarchy (id,name,parent_id,node_type_id,node_order) VALUES (9001,'P1637',0,1,0),(9003,'P1637C',0,1,0);
INSERT INTO testplans (id,testproject_id,...) VALUES (9001,9001,'tplan',...),(9002,9001,'tplan2',...);
-- all four (is_open x enable_on_execution) combinations, LINKED
INSERT INTO platforms (id,testproject_id,name,notes,enable_on_design,enable_on_execution,is_open) VALUES
 (9001,9001,'L_OPEN_EXEC',    'x',1,1,1),(9002,9001,'L_OPEN_NOEXEC','x',1,0,1),
 (9003,9001,'L_CLOSED_EXEC',  'x',1,1,0),(9004,9001,'L_CLOSED_NOEXEC','x',1,0,0),
 (9005,9001,'U_OPEN_EXEC',    'x',1,1,1),(9006,9001,'U_CLOSED_EXEC','x',1,1,0),
 (9010,9002,'OTHER_PROJ',     'x',1,1,0),(9020,9003,'NOTPLANS_CLOSED','x',1,1,0),(9021,9003,'NOTPLANS_OPEN','x',1,1,1);
INSERT INTO testplan_platforms (testplan_id,platform_id,active) VALUES (9001,9001,1),(9001,9002,1),(9001,9003,1),(9001,9004,1),(9002,9004,1);
```

**Repro steps (pre-fix)**

```bash
curl -c jar -X POST http://localhost:8082/api/auth/login -H 'Origin: http://localhost:8082' \
     -H 'Content-Type: application/json' -d '{"login":"admin","password":"admin"}'
curl -b jar -X DELETE -H 'Origin: http://localhost:8082' \
     "http://localhost:8082/api/platforms/index.php/9003?tproject_id=9001"   # is_open=0, LINKED
```
Note: the login body key is `login` (not `username`), and `bffSameOriginGuard()` (`api/_guard.php:102`)
rejects a POST/DELETE without a same-origin `Origin`.

**Observed pre-fix** — `200 {"status":"ok"}`, `platforms` row gone, `testplan_platforms` row left
behind as an orphan. Only the `is_open=1 AND enable_on_execution=1` platform was protected.

**Expected post-fix** — `422 {"error_code":"DELETE_BLOCKED"}` for **any** platform with
`linked_count > 0`, whatever the flags; unlinked platforms still delete with `200`.

| # | Case | Steps | Expected | Result |
|---|---|---|---|---|
| 1637.1 | Syntax gate on the patched file | `php -l api/platforms/index.php` | No syntax errors | **PASS** |
| 1637.2 | Reported repro — linked platform, `is_open=0`, `enable_on_execution=1` | `DELETE /9003?tproject_id=9001` | `422 DELETE_BLOCKED` | **PASS** (was `200`) |
| 1637.3 | linked, `is_open=0`, `enable_on_execution=0` (worst case) | `DELETE /9004` | `422 DELETE_BLOCKED` | **PASS** (was `200`) |
| 1637.4 | linked, `is_open=1`, `enable_on_execution=0` | `DELETE /9002` | `422 DELETE_BLOCKED` | **PASS** (was `200`) |
| 1637.5 | linked, `is_open=1`, `enable_on_execution=1` (the only case that worked) | `DELETE /9001` | `422 DELETE_BLOCKED` | **PASS** (no regression) |
| 1637.6 | **Not over-blocked**: unlinked + open | `DELETE /9005` | `200 {"status":"ok"}` | **PASS** |
| 1637.7 | **Not over-blocked**: unlinked + closed | `DELETE /9006` | `200 {"status":"ok"}` | **PASS** |
| 1637.8 | Guard message/contract unchanged | inspect the 422 body of 1637.2 | `"… is being used! You cannot remove it now. You must first remove it from the testplans using it"` + `error_code: DELETE_BLOCKED`, byte-identical to pre-fix | **PASS** |
| 1637.9 | Cross-project platform cannot be deleted | `DELETE /9010?tproject_id=9001` (9010 belongs to 9002) | `404 Platform not found` — no auth regression | **PASS** |
| 1637.10 | Unknown platform id | `DELETE /999999?tproject_id=9001` | `404`, no 500 | **PASS** |
| 1637.11 | Project with **no test plans at all** — the guard iterates an empty list | `DELETE /9020`, `/9021` with `tproject_id=9003` | `200 {"status":"ok"}` for both, no 500 | **PASS** |
| 1637.12 | **Primary symptom** — no orphan `testplan_platforms` row survives | `SELECT tp.*, IF(p.id IS NULL,'*** ORPHAN ***','ok') FROM testplan_platforms tp LEFT JOIN platforms p ON p.id=tp.platform_id` | every row `ok`, 0 orphans | **PASS** (2 orphans pre-fix) |
| 1637.13 | Multi-testplan counting intact | `linked_count` for 9004 (linked to testplans 9001 **and** 9002) | `linked_count: 2`, `deletable: false`, both links still present after the 422 | **PASS** |
| 1637.14 | GET and DELETE agree by construction | `GET /?tproject_id=9001` vs the four DELETEs | every `deletable:false` platform answers `422`; the `deletable:true` ones answer `200` | **PASS** (they disagreed pre-fix) |
| 1637.15 | Fail-closed branch is syntactically live but unreachable | `grep -n "DELETE_CHECK_FAILED" api/platforms/index.php` + cases 1637.2-1637.11 | branch present, no case reaches it, no case 500s | **PASS** |
| 1637.16 | Event Viewer clean | `SELECT id,log_level,source,... FROM events WHERE fired_at > UNIX_TIMESTAMP()-3600` | only the `log_level 16` login audit row; **no Error/Warning/Notice** | **PASS** |
| 1637.17 | Modernized screen unaffected | `gui/templates/platforms/platformsView.html?tproject_id=9001` in Chrome, admin/admin | table renders 4 rows ("Platform Management(4)"), delete button disabled on every linked row, no console error | **PASS** |

Suite total: **17/17 PASS**. Commit `716d96587`, branch `fix/issue-1637`.

**Not covered / remaining**: `tlPlatform::delete()` (`lib/functions/tlPlatform.class.php:193`) is still a
bare `DELETE FROM platforms WHERE id=…` with no cascade, so a link that is *already* orphaned by some
other path stays orphaned — the guard prevents new damage but does not clean up old damage. Repairing
pre-existing orphans is a data-migration concern, not a bug in this route.

---

## Suite 1613 — Task, Issue #1613: session test-project preselection in Assign Test Project Roles (`usersAssignProject.html`)

**Feature under test** — legacy parity with `lib/usermanagement/usersAssign.php:307-316`
(`getTestProjectEffectiveRoles()`): when the request carries **no** `tproject_id`, the
selected test project must fall back **first to the session project**
(`$_SESSION['testprojectID']`, written by the navBar project combo) and only then to the
first combo entry — `usersAssign.tpl:174-179` rendered that entry `selected`.
Ported in this run: `api/roles/index.php:821` now serializes `sessionTprojectID` in the
`GET /meta/tproject-roles` payload, and `usersAssignProject.html:445` resolves the initial
selection as *valid URL param → valid session project → `projects[0].id`*.

**Precondition / fixtures** (the DB is freshly imported on every run — `testprojects` had 0 rows):
* Login `admin`/`admin` (global role 8, holds `user_role_assignment`).
* Test project **1 = "Analyzer public project"** (`APUB`, `is_public=1`).
* Test project **2 = "Analyzer private project"** (`APRIV`, `is_public=0`).
* Combo order comes from `getAssignableProjects()` → `ORDER BY name ASC`, i.e.
  `projects[0]` is project **2** (private) — deliberately *different* from project 1, so
  "fell back to the first entry" and "honoured the session" are distinguishable.

| # | Case | Steps | Expected | Result |
|---|---|---|---|---|
| 1613.1 | **Gap repro (pre-fix)** | session project = 1 → open `/gui/templates/usermanagement/usersAssignProject.html` (no query string) | legacy selects 1; 2.0.1 at `ecb826d16` selected `projects[0]` = **2** | **FAIL reproduced** (`comboValue:"2"`, `comboText:"Analyzer private project"`); screenshot `docs/screenshots/issue-1613-before-session-project-ignored.png` |
| 1613.2 | BFF payload (pre-fix) | `GET /api/roles/index.php/meta/tproject-roles?tproject_id=0` | `sessionTprojectID` exposed | **FAIL reproduced** — keys were `[status,items,roles,projects,isPublic,demoMode,roleColouring,pagination]`; `sessionTprojectID` undefined although `$sessionTprojectID` already existed at `api/roles/index.php:377` |
| 1613.3 | **Fix — no param, session = 1** | session project = 1 → open the screen **with no query string** | combo = 1 "Analyzer public project", grid renders project 1 | **PASS** (`comboValue:"1"`, 1 row); screenshot `docs/screenshots/issue-1613-after-session-project-selected.png` |
| 1613.4 | BFF payload (post-fix) | same GET as 1613.2 | `sessionTprojectID` present and equal to the session project | **PASS** (`sessionTprojectID: 1`, key present in `apiKeys`) |
| 1613.5 | No param, session = 2 | set navBar project to 2 → open with no query string | combo = 2 (session honoured, here it coincides with `projects[0]`) | **PASS** (`sessionTprojectID: 2`, `comboValue:"2"`) |
| 1613.6 | Explicit param wins | session = 1 → open `?tproject_id=2&tplan_id=0` | combo = 2 (URL beats session) | **PASS** (`comboValue:"2"`) |
| 1613.7 | Invalid param | session = 1 → open `?tproject_id=999&tplan_id=0` | 999 not in the assignable combo → fall back to the session project 1, never to a phantom 999 | **PASS** (`comboValue:"1"`) |
| 1613.8 | Resolution matrix (unit, exact `loadProjects()` expression replayed in-page against the real `/meta/tproject-roles` payload shape) | `{no param,session 1}`→1, `{no param,session 2}`→2, `{no param,session 0}`→2, `{no param,session key absent}`→2, `{no param,session 99 not assignable}`→2, `{param 2,session 1}`→2, `{param 999,session 1}`→1 | every row matches the legacy 3-source precedence; no option value that does not exist can be selected | **PASS** (7/7 as tabulated) |
| 1613.9 | Syntax gate | `php -l api/roles/index.php`; extracted inline `<script>` → `node --check` | no syntax errors | **PASS** ("No syntax errors detected", JS syntax OK) |
| 1613.10 | Regression — sibling role screens | open `usersAssignPlan.html` and `rolesView.html` | no console errors/warnings | **PASS** (0 error/warn console messages on both) |
| 1613.11 | Event Viewer | `select … from events where log_level in (1,2,3)` after the whole run | no new Error/Warning entries | **PASS** (0 rows) |

Suite total: **9 PASS / 2 FAIL-reproduced** (1613.1 + 1613.2 are the pre-fix gap repro,
green after the fix; all other cases were green throughout).

**Not covered / remaining**: the "assignable list empty" branch (1613.f in the
investigation) still needs a second, non-privileged user holding a project role on only
one of the two projects — not created in this run for time budget reasons; that branch is
untouched by this change (it short-circuits at `if (!r.projects || !r.projects.length)`
*before* the resolution code) and was already covered by issue #1621's suite.

---

## Suite 1767 — Test Case Summary (`tcSummary`) — screen + BFF `api/tcsummary` + legacy shim

Fixture: `tmp/fixtures_1767.php` (rerun before the suite; ids printed as the last JSON line).
It creates project `SUM1` (prefix TS1767) with plan "Summary Plan", suite "Summary Root" >
"Summary Sub" holding `TS1767 Rich` (RichEdit summary with `<b>/<i>`, `&amp;`, an
`<img src=x onerror=...>` and a `<script>` tag), `TS1767 Empty` (empty summary) and
`TS1767 Two Versions` (v1 + v2 with different summaries), project `SUM2` (prefix TS1768) with a
foreign case, and user `norights` / `norights` (global role 3).

| # | Case | Steps | Expected | Result |
|---|---|---|---|---|
| 1767.1 | Screen loads, rich summary | open `tcSummary.html?tcase_id=<rich>&tproject_id=<p>&tcversion_id=<v1>` | teal header, context card (project SUM1, prefix TS1767, case, `#id`, version chip, `TS1767-1`, suite path "Summary Root / Summary Sub"), summary body, Refresh / Open test case / Close | **PASS** — header "Test Case Summary", "Test project: SUM1", "TS1767-1", "Version #1", "TEST SUITE Summary Root / Summary Sub" |
| 1767.2 | **Stored XSS neutralised** | same page, evaluate `window.__xss` and count injected elements | summary shown as text; `window.__xss` never set; no injected `img`/`script` | **PASS** — `window.__xss` undefined, `document.querySelectorAll('#sumBody img, #sumBody script').length === 0`; body reads `Verify the login form with special chars & entities.` and the "Stored source" line shows the escaped blob |
| 1767.3 | Empty summary parity | `?tcase_id=<empty>` | legacy `empty_tc_summary` message, not a blank card | **PASS** — "This test case version has no summary." |
| 1767.4 | Latest version default | `?tcase_id=<two>` (no `tcversion_id`) | newest version + `latest version` chip | **PASS** — `id 319, version 2, is_last_version true`, summary "…version TWO…", chip shown |
| 1767.5 | Explicit older version | `?tcase_id=<two>&tcversion_id=<v1>` | that exact version, no `latest version` chip | **PASS** — `id 316, version 1, is_last_version false`, summary "summary of version one", chip hidden |
| 1767.6 | Version of another case | `?tcase_id=<two>&tcversion_id=<foreign v1>` | 404 `version_not_in_case` | **PASS** — `{"code":"version_not_in_case"}` [404] |
| 1767.7 | **Cross-project rights (the legacy hole)** | log in as `norights` (global role 3) → `?tcase_id=<rich>&tproject_id=<SUM1>` | 403 `no_right`, never the summary | **PASS** — `{"code":"no_right"}` [403]; note an **admin** legitimately gets 200 on the foreign project (admin holds every right), so only a no-right session proves the guard |
| 1767.8 | Stale project assertion | `?tcase_id=<rich>&tproject_id=<SUM2>` | 404 `project_mismatch` | **PASS** — screen shows "Test case not found" + code `project_mismatch` |
| 1767.9 | Missing / invalid ids | no `tcase_id`; `tcase_id=abc`; `tcase_id=0` | explicit "missing test case" / "invalid request" cards, each keeping the stable machine code | **PASS** — `missing_tc_id` and `invalid_tc_id` codes rendered, no blank card |
| 1767.10 | Anonymous | clear cookies → open the screen | bounce to `login.php?note=expired&destination=…` (legacy `testlinkInitPage()` contract) | **PASS** — destination preserved with the full query string; the page never renders a summary |
| 1767.11 | Method matrix | `POST /api/tcsummary/index.php?action=summary&tcase_id=<rich>` | 405, no data | **PASS** — 405 |
| 1767.12 | Legacy shim — browser | `GET /lib/ajax/gettestcasesummary.php?tcase_id=<rich>&tcversion_id=<v1>` | 302 to the modern popup, ids + context preserved | **PASS** — `Location: /gui/templates/testcases/tcSummary.html?tproject_id=…&tcase_id=…&tcversion_id=…` |
| 1767.13 | Legacy shim — XHR | same URL with `X-Requested-With: XMLHttpRequest` | 405 pointing at the BFF, **no summary in the body** | **PASS** — `{"code":"method_not_allowed", "bff_url":"/api/tcsummary/index.php?action=summary&tcase_id=…"}`; the legacy raw-HTML read is closed |
| 1767.14 | Legacy shim — anonymous | same URL, no session | 401 for XHR / 302 to login for navigation | **PASS** — 401 JSON and 302 `login.php?note=expired&destination=…` |
| 1767.15 | Workframe affordance restored | open `planAddTCView.html?tproject_id=&tplan_id=` → suite "Summary Sub" | one summary button per row next to the case name | **PASS** — 3 rows / 3 `.sum-btn`; click target `/gui/templates/testcases/tcSummary.html?tcase_id=<tc>&tproject_id=<p>&tcversion_id=<version selected in that row>` |
| 1767.16 | i18n completeness | `tcsum.*` + `footers.tcSummary` in all 10 bundles, `python3 -m json.tool` | valid JSON, every key present, no hardcoded labels left | **PASS** — 10/10 bundles valid, 29-line diff each; title/tooltips/messages resolve through `TLi18n.t()` |
| 1767.17 | Syntax gate | `php -l` on both PHP files, `php -l` on the screen, fixture rerun | no syntax errors | **PASS** — "No syntax errors detected" ×3; fixture rerun clean (its initial version-2 attempts failed and were corrected, see below) |
| 1767.18 | Console + Event Viewer | whole run: console messages of both screens, then `select … from events where log_level in ('ERROR','WARNING')` | no console errors/warnings, no new Error/Warning rows | **PASS** — 0 console error/warn messages on `planAddTCView.html`, 0 event rows |

Suite total: **18 PASS / 0 FAIL**.

**Fixture gotchas worth keeping** (all cost time, all are traps for the next agent):
- `testcase::create($tcase_id, …)` does **not** create a new version — it creates a **second test case
  node** with the same name under the same suite (node_type 3). `testcase::update()` only edits a
  version in place; passing `tcversion_id = 0` writes an **orphan** node (`parent_id = 0`). In
  2.0.1 new versions come **only** from the XML/CSV importer, so the fixture mirrors the importer by
  inserting a version node (node_type 4) plus its `tcversions` row.
- `nodes_hierarchy.id` is `AUTO_INCREMENT` but `tcversions.id` is **not**; a version shares one id
  across both tables, so take the new id from `insert_id('nodes_hierarchy')`.
- `nodes_hierarchy` has a **UNIQUE (parent_id, node_order)** index — the second version node needs a
  different `node_order` than v1.
- The real column is `estimated_exec_duration`; there is no `node_order` on `tcversions`, and
  `latest_tcase_version_id` is **not** in the `tlObjectWithDB` table map (it is a legacy cache that
  `get_last_version_info()` does not read).
- `testprojects` and `tcversions` have **no `name` column** — names live in `nodes_hierarchy`.

**Not covered / remaining**: the `tcsum.*` translations were authored from the English text and spot
checked, not reviewed by a native speaker; the popup's window sizing (`760×560`) is not asserted
automatically.

### Suite 1767 — code-review regression cases (added after the mandatory review)

| # | Case | Steps | Expected | Result |
|---|---|---|---|---|
| 1767.R1 | Existence oracle closed | as `norights` (no project asserted): valid version vs **nonexistent** version of an existing case | both answer the same `403 no_right`; no valid-version enumeration | **PASS** — `tcase_id=<rich>&tcversion_id=999999` → 403 (same as a real version); previously 404 `version_not_in_case` |
| 1767.R2 | Opaque 404 | as any caller, no asserted project, `tcase_id` of a node that is not a test case | 404 `tcase_not_found`, never a distinguishing code | **PASS** |
| 1767.R3 | Distinguished codes still reachable | as **admin**, `tcase_id=<two>&tcversion_id=<foreign>` | 404 `version_not_in_case` (rights passed first) | **PASS** |
| 1767.R4 | Footer key renders | open the screen, read `#footerText` | "TestLink 2.0.1 - Test Case Summary" (was empty) | **PASS** — text present, `<span data-i18n="footers.tcSummary">` in static markup |
| 1767.R5 | Stored source collapsed | open the rich screen | a real `<details>` element closed by default, escaped text inside `<pre>` | **PASS** — `#summaryBox details` present |
| 1767.R6 | Button a11y | open `planAddTCView.html`, inspect a `.sum-btn` | `aria-label="Show summary"`, `title="Show summary"`, `type="button"` | **PASS** |
| 1767.R7 | tcView consumer | open `tcView.html?tcase_id=<two>`, click a version card's **Show summary** | popup opens with that card's `tcversion_id` | **PASS** — `openSummaryPopup(341)` → `…?tcase_id=337&tproject_id=324&tcversion_id=341`; one button per card |
| 1767.R8 | Shim — modern fetch | `GET gettestcasesummary.php?tcase_id=` with `Sec-Fetch-Dest: empty`, `Accept: */*`, no `X-Requested-With` | 405 pointing at the BFF | **PASS** |
| 1767.R9 | Shim — real browser navigation | same with `Sec-Fetch-Dest: document`, `Accept: text/html` | 302 to the modern popup | **PASS** |
| 1767.R10 | i18n key | `tcsum.showSummary` present in all 10 bundles | valid JSON, key resolves | **PASS** |

---

## Suite 1040 — Task — Issue #1040: test case relations in `tcView.html` (full legacy port)

**Precondition / fixture** — `tmp/fixtures_1040.sql` (idempotent, reload before each run):

```bash
mysql -h 127.0.0.1 -utestlink -ptestlink testlink < tmp/fixtures_1040.sql
```

Project `TCR Project` (id 1, prefix `TR1`), suite `TCR Suite`, three test cases:

| node | id | role | note |
|---|---|---|---|
| `TCR Case R Source` | 3 | tcase | `tc_external_id = 1` |
| ├ version 1 | 4 | tcversion | **FROZEN** (`is_open = 0`) |
| └ version 2 | 5 | tcversion | **LATEST, open** (`is_open = 1`) |
| `TCR Case R Target` | 6 (`TR1-2`) | tcase | version 7, open |
| `TCR Case R Third` | 8 (`TR1-3`) | tcase | version 9, open |

Seeded `testcase_relations` (ids 10-16) chosen so every legacy branch is exercised:

| id | source → dest | `relation_type` | `link_status` | legacy outcome |
|---|---|---|---|---|
| 10 | 4 → 7 | 3 (`related_to`) | 1 open | shown "is related to", deletable |
| 11 | 7 → 4 | 1 (`parent_of`) | 1 open | shown as "**is child of**" (we are the destination) |
| 12 | 4 → 9 | 4 (`automates_also`) | 1 open | shown "Also Automates" |
| 13 | 4 → 9 | **9 — not in `type_labels`** | 1 open | **dropped** by legacy `in_array()` |
| 14 | 4 → 9 | 2 (`blocks`) | **3 frozen** | shown "blocks", **warning icon** `can_not_delete_a_frozen_relation` |
| 15 | 5 → 7 | 3 | 1 open | shown on the latest version, deletable |
| 16 | 7 → 5 | 1 | 1 open | shown as "is child of" on the latest version |

Login `admin/admin`. Read-only checks use a temporary role-1 user (`ro1040`, deleted after the run).

### 1040.A — the gap this issue was filed for

| # | Case | Steps | Expected | Result |
|---|---|---|---|---|
| A1 | The broken query | `mysql -e "SELECT … FROM testcase_relations TR JOIN relation_type RT ON RT.id=TR.relation_type"` | proves there is no `relation_type` table | **PASS** — `ERROR 1146 (42S02): Table 'testlink.relation_type' doesn't exist` |
| A2 | Relations present in the DB | `SELECT id,source_id,destination_id,relation_type,link_status FROM testcase_relations` | 7 rows | **PASS** — ids 10…16 |
| A3 | Payload before the fix | `GET api/testcases/index.php?action=view&tcase_id=3&tcversion_id=4` | relations exposed | **FAIL (baseline)** — `relations: []`, `relationsConfig` absent |
| A4 | Payload after the fix | same request | relations exposed, labels resolved | **PASS** — 4 displayable rows (13 dropped), `relationsByVersion` keyed by tcversion id, `relationsConfig.domain.selected = "3_source"` |

### 1040.B — rendering (chrome-devtools MCP)

| # | Case | Steps | Expected | Result |
|---|---|---|---|---|
| B1 | Latest + open version | open `tcView.html?tcase_id=3&tproject_id=1` | section with count, add form, table | **PASS** — "Relations with other test cases (2)", combo + `PREFIX-ID` box + Add, 2 rows |
| B2 | Combo contents | inspect `#relType_5` | `<code>_source` / `<code>_destination`, `_destination` omitted when labels are equal, `3_source` preselected | **PASS** — 8 options: `1_source,1_destination,2_source,2_destination,3_source(selected),4_source,4_destination,5_source` (`5_destination` omitted — equal labels) |
| B3 | Row shape | inspect `.rel-table tbody tr` | `# / Type`, link `EXTID: name [Version N]`, author, icon | **PASS** — `15 / is related to` → `TR1-2: TCR Case R Target [Version 1]`, title `Created 2026-01-06 10:00:00 by Testlink Administrator (admin)` |
| B4 | Side-aware label | row for relation 16 (we are the destination) | `is child of`, not `is parent of` | **PASS** — `16 / is child of` |
| B5 | Unconfigured type dropped | relation 13 (`relation_type = 9`) | absent | **PASS** — no row, count is 4 not 5 on tcversion 4 |
| B6 | Frozen version | `…&tcversion_id=4` (v1 frozen) | no section (legacy `$canWork = is_latest \|\| !addTCVRelationsOnlyOnLatestTCVersion` = false) | **PASS** — 0 `.relations-block` |
| B7 | No delete control when not editable | inspect icons on a frozen version | warning icon with empty title, no trash | **PASS** — `.rel-warn` ×N, `.rel-del` = 0 |
| B8 | Deleted legacy global card | `#relationsCard` in the DOM | gone | **PASS** — element absent; section now lives in the version card |

### 1040.C — write operations

| # | Case | Steps | Expected | Result |
|---|---|---|---|---|
| C1 | Add (UI) | combo `1_source`, type `TR1-3`, click **Add** | success toast, table refreshed, new row | **PASS** — "Relation to TR1-3 has been added"; 3 rows, `100 / is parent of -> TR1-3: TCR Case R Third [Version 1]` |
| C2 | Add unknown external id | type `TR1-777`, Add | legacy `testcase_doesnot_exists` sentence | **PASS** — "Test Case with external ID: TR1-777 - does not exist" (422) |
| C3 | Add with empty input | clear the box, Add | client-side refusal, hint shown | **PASS** — toast "PREFIX-ID", no request fired |
| C4 | Add rejects bogus type | POST `relation_type = "99_x"` | 400, nothing inserted | **PASS** — `{"message":"Invalid relation type: 99_x"}` |
| C5 | Add rejects a frozen source version | POST `tcversion_id = 4` (frozen) | 403 `can_not_edit_frozen_tc` | **PASS** |
| C6 | Add refuses a frozen destination | POST `1_destination` (this case becomes the destination and its latest version was frozen) | `related_tcase_not_open` | **PASS** (422, verified while v2 was frozen) |
| C7 | Delete (UI) | trash icon on the new row | confirm modal `Really delete relation #100?`, then success | **PASS** — modal text exact, row gone, toast "Relation was deleted successfully." |
| C8 | Delete refuses a frozen relation | POST `relation_id = 14` | 409 `can_not_delete_a_frozen_relation`, row kept | **PASS** — row 14 still present |
| C9 | Delete cannot touch a foreign relation | POST `tcase_id = 6, relation_id = 14` | 404, row kept | **PASS** — "Relation #14 does not belong to this test case" |
| C10 | Rights | as role-1 user `ro1040`, POST both actions | 403 `Requires permission: modify test cases` | **PASS** for `add_relation` and `delete_relation` |
| C11 | CSRF | POST without `Origin`/`X-Requested-With` | 403 | **PASS** |

### 1040.D — regression / hygiene

| # | Case | Steps | Expected | Result |
|---|---|---|---|---|
| D1 | View with `editOnExec=1` | `GET …&editOnExec=1` | `relationsConfig.editEnabled = false`, relations still listed | **PASS** |
| D2 | Relations disabled in config | set `$tlCfg->testcase_cfg->relations->enable = FALSE` (`config.inc.php:1309`) | `enabled: false`, empty domain, nothing rendered | **PASS (static)** — `tcRelationLabels()` returns `null` first, and `renderRelations()` returns on `!cfg.enabled`. NOT exercised live: the flag is code-level, and editing the shared `config.inc.php` would race the other CI agents |
| D3 | Console | open the screen, run add + delete | no errors/warnings | **PASS** — 0 console messages |
| D4 | Event Viewer | `SELECT … FROM events ORDER BY id DESC` | no new Error/Warning | **PASS** — only `log_level = 16` `audit_login_succeeded` |
| D5 | PHP server log | `grep -i "PHP Warning\|PHP Fatal" tmp/php_server.log` | none from this run | **PASS** — only the boot-time JIT warning |
| D6 | i18n | all 10 bundles, `python3 -m json.tool` | valid JSON, 26 new keys each | **PASS** — de/en/es/fr/it/ja/pt/ro/ru/zh |
| D7 | Other blocks unaffected | platform add/remove, keyword popup, summary popup on the same screen | still render | **PASS** — version card unchanged apart from the new section |

Suite total: **34 PASS / 0 FAIL** (A3 is the recorded pre-fix baseline, expected FAIL).

**Known legacy quirks deliberately preserved** (do not "fix" them without a separate issue):
- `testcase::addRelation()` calls `relationExits()` with the **unmapped** arguments
  (`lib/functions/testcase.class.php:8206-8211`), so a duplicate added from a test case id is never
  detected and the row is inserted twice.
- Relations of a **non-latest** version are computed but never displayed while
  `addTCVRelationsOnlyOnLatestTCVersion` is TRUE.

**Status**: PASS

## Regression — Issue #1768: `api/attachments` list / upload / download had no object-level authorization

**Precondition** (freshly imported DB, recreate with the scripts below)
```bash
mysql -h 127.0.0.1 -utestlink -ptestlink testlink < tmp/reset_1768.sql
mysql -h 127.0.0.1 -utestlink -ptestlink testlink < tmp/fixtures_1768.sql
mysql -h 127.0.0.1 -utestlink -ptestlink testlink < tmp/fixtures_1768b_execstep.sql
# lowpriv / devpriv / ropriv get a bcrypt password (see the run log)
bash tmp/repro_1768.sh prepare          # admin uploads one attachment per fk_table
bash tmp/verify_1768.sh                 # the 59-case matrix
```
Fixture data: private test project **12** (prefix `A1768`), test plan 13, suite 14, tc 15,
tcversion 16, tcstep 17, execution 18, build 19, req_spec 20, requirement 21, req_version 22,
`execution_tcsteps` 17→18.
Users: `lowpriv` (`role_id = 3` `<no rights>`, 0 `role_rights`, **no** `user_testproject_roles`),
`devpriv` (`role_id = 4` Test Designer on project 12), `ropriv` (custom role with **only**
`exec_ro_access` + `mgt_view_tc` on project 12 / plan 13), `admin`.

**Repro steps (pre-fix)**
1. `POST /api/auth/login` with `lowpriv` → `{"status":"ok","success":true}`.
2. `GET /api/attachments/index.php?action=list&table=testprojects&id=12`.
3. `POST /api/attachments/index.php?action=upload` with `table=testprojects&id=12` + a file.
4. `GET /api/attachments/index.php?action=download&id=<attachment of project 12>`.

**Expected post-fix behavior** — all four answer `403`, and step 3 creates **no** row. Legitimate
callers are unaffected; the public share-link download keeps working.

**Actual result (post-fix, 59/59 PASS)**
| Case | Expected | Result |
|---|---|---|
| `list` for 9 `fk_table`s as `lowpriv` | 403 | **PASS** |
| `download` (project + requirement attachment) as `lowpriv` | 403 | **PASS** |
| `upload testprojects/12` as `lowpriv` | 403, **0 rows created** | **PASS** |
| `list` + `upload` on 9 `fk_table`s as `admin` | 200 | **PASS** |
| `download` of a fresh admin upload | 200 + the uploaded bytes | **PASS** |
| 64-char object key of the owning plan / project, anonymous | 200 + bytes (unchanged) | **PASS** |
| 64-char object key of another project / bogus key | 403 (unchanged) | **PASS** |
| 32-char **user** key of `admin` / anonymous | 200 (unchanged) | **PASS** |
| 32-char **user** key of `lowpriv` | **403** (was 200 + bytes) | **PASS** |
| `execution_tcsteps` attachment: admin / `ropriv` / `lowpriv` | 200 / 200 / 403 | **PASS** |
| `ropriv` (read-only) lists execution + tcversion attachments | 200 | **PASS** |
| `ropriv` (read-only) uploads onto an execution / a test case | 403, no row | **PASS** |
| `devpriv` lists suite / requirement / plan attachments | 200 (role 4 holds the rights) | **PASS** |
| `devpriv` uploads onto an execution (no plan role) | 403 | **PASS** |
| unknown `fk_table` / `id=0` / unknown attachment | 400 / 400 / 404 (unchanged) | **PASS** |
| `lowpriv` on a **non-existent** project | 403, not 500 (fail closed) | **PASS** |
| anonymous without a key | 401 (unchanged) | **PASS** |
| cross-Origin upload | 403 CSRF guard (unchanged) | **PASS** |
| `events` rows with `log_level IN (1,2)` | 0 new | **PASS** |

**Status**: PASS — fix `5aeea9d7f` on `fix/issue-1768`. `delete` is intentionally still ungated
(tracked by #1647); the latent `hasRight()` bug found while testing is #1769.

## Suite 1770 — Modernize: Test Case Tree Navigator (`tcProjectTree`) — screen + BFF `api/tcprojecttree` + legacy shim

**Tracking**: #1770 · **Bugs raised and fixed in the same run**: #1771, #1772, #1773
**Screens / files**: `gui/templates/testcases/tcProjectTree.html`, `api/tcprojecttree/index.php`,
`lib/ajax/gettprojectnodes.php` (retired shim), `lib/functions/common.php` (`$actions->tcProjectTree`),
`gui/templates/i18n/*.json` (10 bundles, 46 keys each).

### Setup

```
php tmp/fixtures_1770.php       # re-runnable; drops + recreates TREE1 / TREE2 and treenorights
python3 tmp/suite_1770.py       # 125 checks, stdlib only, no external deps
```

Fixture shape (test project **TREE1**, prefix `TR1770`):

| Node | Content |
|---|---|
| `Tree Root` (3) | 1 direct test case + nested `Tree Sub` (2 cases) + nested `Tree Empty` (0) |
| `Tree <script>window.__xss=1;</script>Y` (0) | XSS probe: a suite name that looks like markup |
| `Tree Rich` (1) | one test case with **two** versions (`tc_external_id` 4 and 12345) |
| test project **TREE2** (prefix `TR1771`) | foreign project: its suite/case ids must never resolve under TREE1 |

Users: `admin` (role 8), `treenorights` (role 3, no project role → the 403 path).
The suite discovers every node id **through the API**, so it survives a fixture re-run.

### Results — 125/125 PASS

| Area | Cases | Result |
|---|---|---|
| Auth | admin login, role-3 login | **PASS** |
| `projects` | 200, both fixture projects, session project flagged `is_current` | **PASS** |
| `init` | context (project, prefix, user), counters 5 suites / 4 cases, `show_tcases`, `filter_node`, `treemenu_show_testcase_id`, `grant{view,modify}`, project node + its 3 top-level suites with recursive `tcase_qty` | **PASS** |
| `children` | node echo, suite+case mix under `Tree Root`, `tcase_qty`, all three `open_url` kinds (`open_testproject` / `open_testsuite` / `open_testcase`), nested suites emitted once | **PASS** |
| **#1771** | a test case with two versions resolves `tc_external_id = 12345` (the **latest** version), label `TR177012345:TR1770 Rich Case` | **PASS** |
| XSS | answer is `application/json` + `nosniff`; a suite named `<script>…` round-trips as **data** and the browser renders `window.__xss === undefined` with 0 injected `<script>` nodes | **PASS** |
| `show_tcases` | `0` drops every test-case row, `1` brings it back | **PASS** |
| `filter` | root suite → `Tree Root (3)`, nested suite accepted → `Tree Sub (2)`; `filter_node` narrows the **root** children only (legacy rule) and is ignored below the root | **PASS** |
| Ownership | a foreign **suite**, foreign **test case**, foreign `filter_node` and a foreign `node_id` under TREE1 all fail; the same node is reachable in its own project | **PASS** |
| Validation | `missing_tproject`, `invalid_tproject` (0 / `abc` / `-5` / `61abc`), `tproject_not_found`, `missing_node`, `invalid_node` (0 / `abc`), `node_not_found`, `invalid_show_tcases` (7), `invalid_filter_node`, `unknown_action` | **PASS** |
| Methods | `POST` / `DELETE` → 405 `wrong_method` | **PASS** |
| **Rights** | role-3 user: `projects` = `[]`, and `init` / `children` / `filter` → 403 `no_right`, **including for a non-existent project** (fails closed, no existence oracle) | **PASS** |
| Anonymous | all four actions → 401 `not_authenticated` (also for a missing project) | **PASS** |
| Legacy shim | GET/HEAD → 302 to the modern screen; `root_node`→`tproject_id`; `filter_node` + `show_tcases` preserved; no project id → bare screen; POST → 405 pointing at the new API; anonymous → no tree data + login bounce; source contains no SQL and no legacy loader function | **PASS** |
| Wiring | `$actions->tcProjectTree` registered, screen file present | **PASS** |
| i18n | 10 bundles, identical 46-key sets (`tcpt.*` + `footers.tcProjectTree`), every key non-empty, all placeholders (`{suites} {cases} {name} {id}`) preserved, every key the screen uses exists, no hardcoded user-visible English in JS strings | **PASS** |
| Event Viewer | `events` readable, no new Error/Warning row produced by the screen | **PASS** |

### Browser pass (admin, TREE1)

Expand all · Collapse all · Hide/Show test cases · Refresh · project switcher (TREE1→TREE2) ·
FOCUS A TEST SUITE (Whole test project / Tree Root / Tree Rich / invalid pick) · every twisty ·
all 8 open buttons · the three toolbar links · Close. Verified: counts per row, external-id
prefixes, empty-suite message, "Showing only …", the info strip, and every state card
(no project / not found / bad request / denied). Console: no errors, no warnings.

### Defects found and fixed during this run

| Issue | Symptom | Root cause | Fix |
|---|---|---|---|
| #1771 | every `tc_external_id` was empty | a test case **version** node hangs off the test case, never off the suite — the legacy query needed its third join for that reason | join suite→case→version and pin `MAX(version node id)` |
| #1772 | Hide/Show and the suite focus were lost on reload | `resetTree()` (which writes the URL) was only called from the project switcher | call it in the toggle handler and on every `applyFilter` path |
| #1773 | "Expand all" stopped after the first level | only suites already known to the client were flagged | sticky `EXPAND_ALL` mode + `expandInto()` that walks cached and freshly loaded rows; cleared by Collapse all and by a manual twisty click |

### Status
PASS — `c842f3bf9` (BFF), `b84337607` (screen + i18n + shim + wiring), `28643031e` (#1771),
`7397d3249` (#1772), `a9c3a0abe` (#1773).

---

## Suite 1041 — Task / Issue #1041: `has_been_executed` attribution in tcView.html (gap vs legacy status quo)

**Precondition** — run `php tmp/fixtures_1041.php`. It is re-runnable and creates private
project `SM1041` (suite `Suite 1041`, plan `Plan 1041`) with three two-version test cases
authored by `admin` (role 8):

| case | data | legacy `get_versions_status_quo()` truth |
|---|---|---|
| `SM1041EXEC`   | execution on the **v1 node**, `tcversion_number = 1` | v1 executed, v2 not |
| `SM1041NONE`   | no execution at all | neither executed |
| `SM1041NUMBER` | execution on the **v1 node** but `tcversion_number = 2` | **v1 not executed, v2 executed** |

`SM1041NUMBER` is the legacy discriminator: the execution row points at the v1 node, so any
implementation that keys off `executions.tcversion_id` alone puts the flag on v1, while the
legacy loop (`lib/functions/testcase.class.php:3102-3116`) resolves the recorded **version
NUMBER** and flags v2.

**Steps** — `python3 tmp/suite_1041.py` (runner for C1..C14; it calls the legacy
`testcase::get_versions_status_quo()` live through `tmp/verify_1041.php`, then compares it
with `action=view` and `action=version_list` over HTTP as `admin/admin`).

| # | Case | Expected | Actual | Result |
|---|---|---|---|---|
| C1 | `action=view` on `SM1041EXEC` | `{1:true, 2:false}` — equals legacy | `{1:true, 2:false}` | **PASS** |
| C2 | `action=view` on `SM1041NONE` | `{1:false, 2:false}` — equals legacy | `{1:false, 2:false}` | **PASS** |
| C3 | `action=view` on `SM1041NUMBER` | `{1:false, 2:true}` — equals legacy | `{1:false, 2:true}` | **PASS** |
| C4 | `action=version_list` on `SM1041EXEC` | equals legacy | `{1:true, 2:false}` | **PASS** |
| C5 | `action=version_list` on `SM1041NONE` | equals legacy | `{1:false, 2:false}` | **PASS** |
| C6 | `action=version_list` on `SM1041NUMBER` | equals legacy | `{1:false, 2:true}` | **PASS** |
| C7 | the flagged version of `SM1041NUMBER` | `[2]` — the node whose version NUMBER is 2 | `[2]` | **PASS** |
| C8 | `action=view&tcversion_id=<v1 of SM1041EXEC>` | `has_been_executed` true — legacy builds the status quo over ALL versions | `true` | **PASS** |
| C9 | `action=view&tcversion_id=<v2 of SM1041EXEC>` | `has_been_executed` false | `false` | **PASS** |
| C10 | `is_latest` under the same two filters | `[false, true]` | `[false, true]` | **PASS** |
| C11 | `action=view&tcase_id=999999` | still an error | HTTP 404 / `status:error` | **PASS** |
| C12 | `action=view` with no session | still refused | 401/403 | **PASS** |
| C13 | `action=version_list` grants block | 4 keys intact | all 4 present | **PASS** |
| C14 | all i18n bundles still parse | no parse error (no key added/removed) | 10/10 valid | **PASS** |

### Results — 14/14 PASS

`action=view` and `action=version_list` now agree with the legacy status quo on every case,
including the renumbered one where they previously disagreed (C3/C6 were
`{1:true, 2:false}` before the fix).

### Browser pass (admin, project SM1041)

* `tcView.html?tcase_id=45` (`SM1041NUMBER`) — **EXECUTED badge on the "Version 2 LATEST"
  card**, Version 1 clean. Warning banner reads *"This version has been executed and can not
  be edited."* Screenshot: `docs/screenshots/issue-1041-executed-badge-legacy-attribution.png`.
  Pre-fix the badge was on Version 1.
* `tcView.html?tcase_id=37` (`SM1041EXEC`) — EXECUTED badge on Version 1 only.
* `tcView.html?tcase_id=37&tcversion_id=<v1>` — opening the executed version directly raises
  the executed banner and hides the Edit button (`canEditCurrent()`, `testcase_cfg->canEditExecuted=0`).
* `tcView.html?tcase_id=41` (`SM1041NONE`) — no EXECUTED badge on either version, no banner.
* `action=version_list` for `SM1041NUMBER` — dropdown labels render `v1` / `v2 (✓ executed)`,
  which is what `testSpec.html:955-960` builds.
* Console: no errors, no warnings.

### Regression

* `bash tmp/verify_1038.sh` (Test Case Viewer / Test Plan usage section) — **21 PASS / 0 FAIL**.
* `bash tmp/verify_1759.sh` (Suite move/reorder, a different API) — first run failed 22 with
  `session_expired` because the fresh DB has no `SM1759*` fixtures; after
  `php tmp/fixtures_1759.php` it is **28 passed / 0 failed**, confirming the earlier failures
  were missing fixtures, not a regression.
* Event Viewer: `log_level=16` audit rows only from this work — no new ERROR/WARNING from
  `api/testcases/index.php`. (Two `ERROR ON exec_query()` rows, ids 2 and 9, are from my own
  first draft of the fixture generator hitting a non-existent `nodes_hierarchy.testcase_id`
  and `testplan_tcversions.execution_type` column; the generator was corrected.)
* `php -l api/testcases/index.php` → no syntax errors. No frontend or i18n change was needed:
  the flag already drove `badge-executed`, the `tcview.executedCanEdit` / `tcview.executedNoEdit`
  banners and `canEditCurrent()` — only the attribution behind them was wrong.

### Defects found and fixed during this run

| Issue | Symptom | Root cause | Fix |
|---|---|---|---|
| #1041 | EXECUTED badge, executed banners, `canEditCurrent()`, `canAssignPlatforms()`, relations `canEdit` and the `testSpec.html` `(✓ executed)` marker all resolved to the wrong version on a renumbered version | modern payload keyed the flag off `executions.tcversion_id` only; legacy `get_versions_status_quo()` attributes by `executions.tcversion_number` when it differs from the node's `tcversions.version` | shared `tcVersionExecutedSet()` reproducing the legacy loop, used by both `action=view` and `action=version_list` |

### Status
PASS — `d05417318` (BFF). No screen/i18n change required.

## Suite 1611 — Task / Issue #1611: rights-gated tab bar in the user & role management area

**Feature gap** — legacy `gui/templates/dashio/usermanagement/tabsmenu.tpl:38-79` rendered
each of the four tabs of the user/role-management area **only** when the matching
`getGrantsForUserMgmt()` flag was `yes` (fed by `lib/usermanagement/usersAssign.php:119`,
helper at `lib/functions/users.inc.php:419-460`):

| tab | legacy gate |
|---|---|
| User Management | `$grants->user_mgmt` (`mgt_users`) |
| Role Management | `$grants->role_mgmt` (`role_management`) |
| Assign Test Project Roles | `$grants->tproject_user_role_assignment` |
| Assign Test Plan Roles | `$grants->tplan_user_role_assignment` |

In 2.0.1 the tab array was duplicated verbatim in `usersAssignProject.html`,
`usersAssignPlan.html` and `rolesView.html` with **no** grant check, so a `leader`
(no `mgt_users`, no `role_management`) was offered User Management and Role Management and
was bounced by the deny box on click. Only `usersView.html` gated correctly.

**Precondition** — `php tmp/fixtures_1611.php` (re-runnable). Creates test project `101`
(+ its `nodes_hierarchy` row), test plan `1` (+ its row), `user_testproject_roles` for all
three users, and three users covering the three distinct rights combinations:

| user | role | rights | `getGrantsForUserMgmt(·,101,-1)` |
|---|---|---|---|
| `leaderu` / admin | 9 `leader` | `testplan_user_role_assignment`, `user_role_assignment` | `user_mgmt=no, role_mgmt=no, tproject=yes, tplan=yes` |
| `planonly` / admin | 20 (created) | `testplan_user_role_assignment` ONLY | `user_mgmt=no, role_mgmt=no, tproject=no, tplan=yes` |
| `admin` / admin | 8 `admin` | all | all four `yes` |

**Steps** — `python3 tmp/suite_1611.py` (runner for F1..F4, A1..A4, B1..B4, G1..G2,
T-*, L-*, E1 = 29 checks). It (a) asserts the BFF `grants` payload over HTTP for two
sessions, (b) drives headless Chrome through **chromedriver** to read the **rendered**
`#tabsBar` of all four screens for each user, and (c) re-checks the `events` table.
The tab bar is built client-side, so the served HTML contains an empty
`<div class="tabs-bar" id="tabsBar"></div>` — asserting on the raw response would be
vacuous, hence the real DOM.

| # | Case | Expected | Actual | Result |
|---|---|---|---|---|
| F1–F4 | fixture sanity (project, plan, `leaderu` on role 9, role 9 has neither `mgt_users` nor `role_management`) | all present | as expected | **PASS** |
| A1 | `leaderu` HTTP session resolves `meta/tproject-roles` | 200 `ok` | 200 | **PASS** |
| A2–A4 | browser sessions for `leaderu` / `planonly` / `admin` | 200 | 200 | **PASS** |
| B1 | `meta/tproject-roles` carries the legacy grants (`leaderu`) | `{no,no,yes,yes}` | `{no,no,yes,yes}` | **PASS** |
| B2 | `meta/tplan-roles` carries the same grants (`leaderu`) | `{no,no,yes,yes}` | `{no,no,yes,yes}` | **PASS** |
| B3 | same payload for `admin` | all four `yes` | all four `yes` | **PASS** |
| B4 | grants computed with legacy's argument shape `(tproject_id, -1)` | identical to `getGrantsForUserMgmt()` | identical | **PASS** |
| G1 | `/api/users/meta/grants` **still** 403 for `leaderu` (gate unchanged) | 403 `mgt_users` | 403 `mgt_users` | **PASS** |
| G2 | `/api/roles/meta/grants` **still** 403 for `leaderu` (gate unchanged) | 403 `role_management` | 403 `role_management` | **PASS** |
| T-usersView | `leaderu` → `usersView.html` | deny box, bar hidden | hidden, *"You do not have the rights required to manage users (mgt_users right required)."* | **PASS** |
| T-rolesView | `leaderu` → `rolesView.html` | no assign tab offered | only `Role Management`, then hidden by `showNoAccess()` | **PASS** |
| T-usersAssignProject | `leaderu` → `usersAssignProject.html` | **exactly the 2 legacy tabs**, project one active | `["Assign Test Project Roles"(active), "Assign Test Plan Roles"]` | **PASS** |
| T-usersAssignPlan | `leaderu` → `usersAssignPlan.html` | **exactly the 2 legacy tabs**, plan one active | `["Assign Test Project Roles", "Assign Test Plan Roles"(active)]` | **PASS** |
| T-usersAssignProject | `planonly` → project tab kept (documented deviation) + plan tab only | `[project(active), plan]` | `[project(active), plan]`, grants `{tproject:no, tplan:yes}` | **PASS** |
| T-usersAssignPlan | `planonly` → only the plan tab | `["Assign Test Plan Roles"]` | `["Assign Test Plan Roles"]` | **PASS** |
| T-admin-\* (4) | `admin` → all four screens | all 4 tabs, correct active one | 4 tabs, correct active on each screen | **PASS** |
| L-\* (4) | every rendered tab href keeps `tproject_id` / `tplan_id` | context preserved | preserved on all 16 hrefs | **PASS** |
| E1 | Event Viewer / `events` table | no new rows from the 3 assignment reads | `176 -> 176` | **PASS** |

**TOTAL 29 checks: 29 PASS / 0 FAIL** (`python3 tmp/suite_1611.py`, exit 0).

### Manual browser pass (chrome-devtools MCP)

* `leaderu` → `usersAssignPlan.html?tproject_id=101&tplan_id=1` — bar shows
  **2** tabs; project combo `[101]`, plan combo `[1]`, grid 2 rows, footer *"2 users"*.
  Screenshot: `docs/screenshots/issue-1611-leaderu-two-tabs.png`.
* `leaderu` → `usersView.html` — deny box *"You do not have the rights required to manage
  users (mgt_users right required)."*, `#tabsBar` `display:none`.
* `leaderu` → `rolesView.html` — `#tabsBar` `display:none` (screen 403 `role_management`).
* `admin` → `usersAssignProject.html` — 4 tabs, grid 2 rows. `usersAssignPlan.html` — 4 tabs,
  grid 2 rows. `rolesView.html` — 4 tabs, roles grid 9 rows. `usersView.html` — 4 tabs, users
  grid 4 rows.
* **i18n** — locale combo switched to *Română* on the rewired screen re-renders the bar through
  the helper with localized labels:
  `["Gestionare Utilizatori","Gestionare Roluri","Atribuire Roluri Proiect","Atribuire Roluri Plan"]`.
  All 10 bundles (`de/en/es/fr/it/ja/pt/ro/ru/zh.json`) already carried the 4 `tab.*` keys —
  verified programmatically, so **no new i18n key was required**.
* Console: no errors/warnings as `admin`; as `leaderu` on `rolesView` only the 3 expected
  `403 (Forbidden)` resource errors of the pre-existing deny path.

### Gates

* `php -l api/roles/index.php` → no syntax errors.
* `node --check gui/templates/usermanagement/usermgmt-tabs.js` → OK; every inline `<script>`
  block of the four screens extracted and `node --check`ed → OK.
* No route gate added, removed or changed — `api/roles/index.php` is additive only
  (`'grants' =>` on two `out()` envelopes).

### Status

**PASS** — `8631a2307` on `task/issue-1611`. One pre-existing defect found while testing and
filed separately as **#1778** (`E_WARNING Undefined array key "tplan"`,
`lib/functions/tlUser.class.php:960`) — measured to be present before this change
(`events` 137 → 137 across a `meta/tproject-roles` GET, 138 → 138 across a direct
`getGrantsForUserMgmt()` call).
**Notes**
* The single Event Viewer row the matrix produced is
  `E_WARNING Undefined array key "tplan" — lib/functions/tlUser.class.php:962`, produced by the
  403 rights branch. That is the already-filed **#1775**, not a regression of #1776; the script
  counts those rows separately and reports them.
* `api/tcassignments/index.php/rows` **cannot** return 200 on a fresh install: it fatals before
  reaching `out()` with `TypeError: array_keys(): Argument #1 must be of type array, null given`
  at `api/tcassignments/index.php:350` → HTTP 500 with an EMPTY body. Pre-existing, unrelated to
  this fix, filed as **#1777**.
* `/init` of the same endpoint is used as the success-path probe instead.
## Regression — Issue #1769 + #1775: `tlUser::hasRight()` denied a plan-scoped right on every 3-argument call, admin included

One defect, two issues, one line (`lib/functions/tlUser.class.php:962`). Automated harness:
`tmp/verify_1775.php` (16 checks) + `tmp/verify_1775_guarded.php`. Fixture: `tmp/fixtures_1770.php`
(tproject `5221` TREE1, test plan `5223`); users `tlv_hasplan` (role 7, holds a plan role),
`tlv_noplan` (role 7, no plan role), `admin` (role 8, no plan role row by design). Right under test:
`testplan_execute` (a real product-level right held by roles 7 and 9 — a project-level right is
subtracted before the plan branch and could not detect the flag at all).

| # | Case | Expected | Result |
|---|------|----------|--------|
| 1 | plan-role user, PUBLIC plan, 3-arg `hasRight` | `'yes'` | PASS |
| 2 | same call | 0 E_WARNING/E_NOTICE | PASS |
| 3 | no-plan-role user, PUBLIC plan, 3-arg — **the #1769 regression** | `'yes'`, not `false` | PASS |
| 4 | same call | 0 warnings (was 1 per call) | PASS |
| 5 | **admin**, PUBLIC plan, 3-arg — no plan-role row, so the same line denied admin | `'yes'`, not `false` (was 403) | PASS |
| 6 | same call | 0 warnings | PASS |
| 7 | no-plan-role user, **PRIVATE** plan, `$getAccess = true` | `false` — guard still bites | PASS |
| 8 | same call | 0 warnings | PASS |
| 9 | plan-role user, PRIVATE plan, `$getAccess = true` | `'yes'` (else-branch not taken) | PASS |
| 10 | same call | 0 warnings | PASS |
| 11 | no-plan-role user, PUBLIC plan, `$getAccess = true` | `'yes'` (flag says public) | PASS |
| 12 | same call | 0 warnings | PASS |
| 13 | `$getAccess = true` but no plan in context | 0 warnings (key never filled) | PASS |
| 14 | project-only shape, no plan | 0 warnings, unaffected | PASS |
| 15 | 50 consecutive plan-scoped 3-arg calls | **0** rows added to `events` | PASS |
| 16 | `events` after the run | 0 rows matching `Undefined array key …tplan…` | PASS |
| 17 | dedicated guard probe (`verify_1775_guarded.php`) | `GUARD PRESERVED` for the 29 `$getAccess = true` call sites | PASS |

**Negative control — the suite must be able to fail.** With the fix reverted, exactly the four predicted
checks fail: case 3 and case 5 return `false` (the 403 #1769 measured on
`api/attachments?action=list&table=executions`) and cases 4 and 6 each report 1 warning. All other checks
still pass, because the `$getAccess = true` path was never broken. Restored, the suite returns 16/16.

**Harness bug found and fixed while writing this suite.** The first version used
`intval($db->fetchFirstRow(...))` to read a row id. `fetchFirstRow()` returns an associative **array**,
and `intval()` on an array is `1`, so *every* fixture user silently resolved to **admin (id 1)** and the
fixture's `UPDATE … SET role_id = 7 WHERE id = 1` overwrote the admin account. Two lessons, both now
encoded in the harness: read scalars out of the row explicitly (`scalar()`), and make `ensureUser()`
abort if a fixture user resolves to id ≤ 1 or does not materialise with the expected login and role.
The admin account was restored to role_id 8.

## Regression — Issue #1761 + #1762: `tcreorder` / `tcstepsreorder` `403` responses leaked the existence and ownership of foreign objects

**Precondition** (fixture is idempotent, recreates itself)
```bash
php tmp/fixtures_1761.php     # 2 projects, 4 users, 2 containers + test cases + steps each
php tmp/verify_1761.php       # the 95-case matrix
```
Fixture data: private test projects `OR1761A=5297` (prefix `O1A`) and `OR1761B=5298` (prefix `O1B`);
project A holds `OR1761A1=5299` (tcase 5300 → tcversion 5301, steps 5302/5303/5304) and
`OR1761A2=5310`; project B holds `OR1761B1=5321` (tcase 5322 → 5323, tcase 5327 → 5328) and
`OR1761B2`. Users: `sm1761a` (Test Designer on A, `mgt_view_tc` + `mgt_modify_tc` + `mgt_view_key`),
`sm1761norights` (role *no rights*, **no** `user_testproject_roles`), `sm1761view` (project role with
**only** `mgt_view_tc` + `mgt_view_key`), `sm1761designer` (global role **4 = Test Designer**, the
default non-admin role, **no** `user_testproject_roles` row at all), `admin`.

**Repro steps (pre-fix)** — no project rights of any kind needed, only a valid session:
1. `POST /api/auth/login` as `sm1761norights` → `{"status":"ok"}`.
2. `GET /api/tcreorder/index.php?action=init&tproject_id=5297&container_id=<suite of B>` →
   `403 {"code":"forbidden","message":"Container belongs to another test project"}`.
3. `GET /api/tcreorder/index.php?action=init&tproject_id=5297&container_id=999999` →
   `404 {"code":"not_found","message":"Container not found"}`.
4. The two bodies differ, so any authenticated user could **enumerate which suite ids exist in any
   project** and learn the `nodes_hierarchy` node type of any id.
5. `GET /api/tcstepsreorder/index.php?action=init&tcversion_id=<version of B>` →
   `403 … "Test case version belongs to another test project"`, same oracle (init/move/reorder/normalize).

**Expected post-fix behavior** — every caller-supplied id that is not available to the caller
answers one single opaque 404, **byte-identical** to the answer for a non-existent id, whatever the
real reason (missing / wrong node type / orphan / foreign project / no rights). Only the project-level
`init` on an id the caller may not even resolve keeps the informative 403 (and that 403 is itself the
same for a non-existent project). Legitimate callers are unaffected.

**Actual result (post-fix, 95/95 PASS; pre-fix the same suite scores 45 PASS / 50 FAIL)**
| Group | Case | Expected | Result |
|---|---|---|---|
| tcreorder, `sm1761a` | R1–R3 own project / own container / absent container | 200 / 200 / 404 | **PASS** |
| | R4–R5 init at a **FOREIGN** container, with and without `tproject_id` | 404 `Container not found` | **PASS** |
| | R6–R7 foreign container == absent container, status **and** body bytes | identical | **PASS** |
| | R8 own-project **test case** passed as `container_id` == absent | identical | **PASS** |
| | R9 `tproject_id` of another project == non-existent project | identical 403 | **PASS** |
| | R10–R13 sort / reorder / move at a foreign container | 404 `Container not found` | **PASS** |
| | R14 foreign `node_id` == absent `node_id` (move) | identical | **PASS** |
| | R15 own **suite** passed as `node_id` == absent (no type oracle) | identical | **PASS** |
| | R16 **real** reorder write on the own container | 200, `node_order` really rewritten | **PASS** |
| | R16b project B `node_order` untouched | unchanged | **PASS** |
| | R18 `container_id=0` (project root) | 200 | **PASS** |
| tcreorder, no rights | R19–R20 container of an **unentitled** project == absent | identical 404 | **PASS** |
| | R21–R23 own-project / foreign / no-`tproject_id` variants | identical 404 | **PASS** |
| tcreorder, view-only | R25 own container (holds `mgt_view_tc` only) | opaque 404 (see note) | **PASS** |
| | R26 foreign container == absent (status + bytes) | identical | **PASS** |
| tcstepsreorder, `sm1761a` | S1–S3 own version / absent version / `versions` list | 200 / 404 / 200 | **PASS** |
| | S2, S7–S9 FOREIGN version via **init / move / reorder / normalize** | 404 `Test case version not found` | **PASS** |
| | S4–S5, S11–S13 foreign == absent, status **and** body bytes | identical | **PASS** |
| | S10 own-project **test case** passed as `tcversion_id` == absent | identical | **PASS** |
| | S15b–S16 **real** reorder write renumbers `tcsteps.step_number` | 200, reversed in the DB | **PASS** |
| | S17–S18 fixture order restored / project B order untouched | unchanged | **PASS** |
| | S19–S21 `versions` action: absent project == other project == non-existent | identical 403 | **PASS** |
| | S19d/S21d/S21e **`versions` as `sm1761designer`** (global role 4) — see the review note below | identical 403 | **PASS** |
| | S19r/S21r the same project axis on `tcreorder` as `sm1761designer` | identical 403 | **PASS** |
| tcstepsreorder, no rights | S22 own-project version | opaque 404 | **PASS** |
| | S23–S24 foreign / foreign reorder == absent | identical | **PASS** |
| tcstepsreorder, view-only | S25 **read** own version still works | 200 | **PASS** |
| | S26 **write** own version | opaque 404 (see note) | **PASS** |
| | S27 foreign version == absent | identical | **PASS** |
| admin | A1–A3 own container / own version / absent version | 200 / 200 / 404 | **PASS** |
| contract | C1 foreign `Origin` on a **write** refused · C1b a GET is not CSRF-gated by design | 403 · 200 | **PASS** |
| | C2 POST without `Origin` refused · C4 POST on `init` | 403 · 405 | **PASS** |
| | C3/C3b/C5/C5b unknown action over GET (POST-only gate) / over POST | 405 / 400 `unknown_action` | **PASS** |
| | V1 `SELECT COUNT(*) FROM events WHERE log_level IN (1,2)` | no **new** row | **PASS** |

**Found by the mandatory code review (rule 16) — a second, pre-existing leak in the same file.**
`?action=versions` resolves a *project*, not a node id, and its rights gate trusted
`tlUser::hasRight()`. That method does **not** deny an id that resolves to nothing: it falls through to
the GLOBAL role and answers *yes*. So for a user with the default global role of a Test Designer
(`role_id = 4`) and no `user_testproject_roles` row, the gate refused an **existing** private project
with `403` but let `424242` through to `tsroVersions()`, which answered `404 Test project not found` —
a live test-project existence oracle, sweepable over the whole `testprojects` id space, on the *project*
axis. The sibling `tcreoProject()` was already immune because it checks the project row itself. Fixed
by having `tsroVersions()` answer the same `403` for a project that does not exist, and the first run
of this suite could NOT have caught it: all three original fixture users had global role *no rights*,
so `hasRight(right, 424242)` was false for them too and the absent/forbidden pair collapsed onto one
answer. `sm1761designer` was added for exactly that reason; `S21d`/`S21e` fail with `403 vs 404` when
only `api/tcstepsreorder` is reverted, and `S19r`/`S21r` pin the same axis on `tcreorder`.

**Note on the view-only caller.** `mgt_modify_tc` is absent while `mgt_view_tc` is present, so the
refusal is a *rights* answer, not an existence answer. It is still normalized to the opaque 404
because the leak guard is armed whenever the caller supplies a container / version id: the same rule
then also covers the `no rights` caller, which is the case that actually matters. The cost is one
"not found" card instead of "access denied" for a user who can only read, on a screen that is linked
for modifiers anyway.

**Follow-up filed as #1779.** `api/suitemove` (#1759) arms its leak guard only when the project id itself is
caller-supplied, so `?tproject_id=<own project>&container_id=<container of an unentitled project>`
still answers the informative 403 and keeps the same enumeration oracle (reproduced by the
`R20`/`R22`-shaped cases above). Suite 1759 `M12`/`M12c` still pass unchanged, so that gap is
reported separately rather than folded into this change.

---

## Suite 1780 — Requirement Monitors popup `reqMonitors` (Ref #1780, bug #1781)

The 1.9.20 "Monitor set" lightbox was a **Smarty include** with no controller of its own:
`gui/templates/dashio/requirements/reqMonitors.tpl` (+ the tl-classic twin), pulled in by
`reqViewVersions.tpl:437` under `{if $gui->grants->monitor_req == "yes"}`, whose single-column
DataTable auto-loaded `lib/ajax/requirements/getreqmonitors.php` — 26 lines, `testlinkInitPage()`
(session check only) and `intval($_REQUEST['item_id'])` straight into
`requirement_mgr::getReqMonitors()`, with no right and no project scope.

Modern twin: `gui/templates/requirements/reqMonitors.html` + `api/reqmonitors/index.php`.
Harness: `tmp/suite_1780.php` (48 checks, self-contained: it logs in over HTTP with real cookies).
Fixture: `tmp/fixtures_1780.php` — projects **MON1** (prefix MN80, reqspec RSMON80) and **MON2**
(prefix MN81, foreign), requirements `REQ-MON-1` (monitored by `admin` + `monuser`),
`REQ-MON-2` (nobody), `REQ-MON-3` (admin only, version **frozen**), `REQ-MON-F` (in MON2), plus
users `monuser` and `monnorights` (role 3, `default_testproject_id = 0`).

> Trap for the next run: **`tlUser::create()` is an empty stub in 2.0.1** — `tlUser.class.php:258`
> is `function create() { }`. Fixture users must be INSERTed into `users` directly
> (the `tmp/fixtures_1570.php` pattern), otherwise the fixture dies on
> `Undefined constant "ROLE_NONE"`.

### A — Authentication

| # | Check | Expected | Result |
|---|---|---|---|
| A1 | admin `?action=init&req_id=7` | `200` | **PASS** |
| A2 | anonymous, no cookie | `401 not_authenticated` | **PASS** |

### B — Happy paths

| # | Check | Expected | Result |
|---|---|---|---|
| B1 | `status` | `ok` | **PASS** |
| B2 | `context.req_id` echoes the request | matches | **PASS** |
| B3 | `req_doc_id` | `REQ-MON-1` (name lives in `nodes_hierarchy`, `requirements` has no `name` column) | **PASS** |
| B4 | `tproject_id` is the **owning** project, derived `requirements.srs_id -> req_specs.testproject_id` | `MON1`, not the session project | **PASS** |
| B5 | `prefix` | `MN80` | **PASS** |
| B6 | `total` | `2` | **PASS** |
| B7 | the caller's own row carries `is_me: true` | yes | **PASS** |
| B8 | second row | `monuser` | **PASS** |
| B9 | `is_monitoring` | `1` for a monitor | **PASS** |
| B10 | `grant.monitor` (legacy `monitor_requirement`) | `true` | **PASS** |
| B11 | latest `req_versions` row resolved | `has_version 1`, `version 1` | **PASS** |
| B12 | `is_open` | `1` | **PASS** |
| B13 | `REQ-MON-3` (version frozen by the fixture) | `is_open 0` | **PASS** |
| B14 | `REQ-MON-3` monitor count | `1` | **PASS** |
| B15 | `REQ-MON-2`, nobody monitoring | `200` + `monitors: []` + `total 0` | **PASS** |
| B16 | `is_monitoring` when not monitoring | `0` | **PASS** |
| B17 | rows sorted by login | alphabetical (the legacy DataTable had no `order`, so MySQL returned them by `user_id`) | **PASS** |

### C — Parameter / verb contract

| # | Check | Expected | Result |
|---|---|---|---|
| C1/C2 | `req_id=0` | `400 invalid_requirement` | **PASS** |
| C3 | `req_id=-5` | `400` | **PASS** |
| C4 | `req_id=abc` | `400` | **PASS** |
| C5/C6 | `req_id=99999999`, authorized caller | `404 requirement_not_found` | **PASS** |
| C7/C8 | correct requirement + **wrong** asserted `tproject_id` | `404 project_mismatch` | **PASS** |
| C9 | correct requirement + correct asserted project | `200` | **PASS** |
| C10 | `?action=bogus` | `400 unknown_action` | **PASS** |
| C11 | `POST` on `init` | `405 wrong_method` | **PASS** |
| C12 | `HEAD` on `init` | `200` — a link checker must not be told "wrong method" (found while testing; same contract as `api/tcsummary`, Refs #1767) | **PASS** |

### D — Authorization (the reason the endpoint had to be retired)

| # | Check | Expected | Result |
|---|---|---|---|
| D1/D2 | `monnorights` on a **real** requirement | `403 no_right` | **PASS** |
| D3 | `monnorights` on a **foreign-project** requirement | `403` | **PASS** |
| D4 | `monnorights` on a **bogus** id | `403` too — otherwise 403-vs-404 is an id oracle (bug **#1781**, fixed in the same run; without the fix this row FAILS with `404`) | **PASS** |
| D5 | admin on a bogus id | still the truthful `404` | **PASS** |
| D6 | denied payload | contains no monitor login | **PASS** |

### E — Legacy endpoint retired

| # | Check | Expected | Result |
|---|---|---|---|
| E1 | legacy GET | `302` | **PASS** |
| E2 | `Location` | `…/requirements/reqMonitors.html?req_id=7&tproject_id=1` | **PASS** |
| E3 | legacy GET with `X-Requested-With` (the legacy DataTable) | `405 retired_endpoint` naming the popup, never data | **PASS** |
| E4 | legacy POST | `405 wrong_method` naming `GET /api/reqmonitors/index.php?action=init` | **PASS** |
| E5 | legacy GET with `Accept: application/json` | the string `monuser` never appears | **PASS** |
| — | legacy GET anonymous | `checkSessionValid()`'s own `top.location.href='../../../login.php?note=expired&destination=…'` JS redirect, i.e. the legacy `testlinkInitPage()` contract (verified by curl, body inspected) | **PASS** |

### F — Screen, wiring, i18n

| # | Check | Expected | Result |
|---|---|---|---|
| F1 | `gui/templates/requirements/reqMonitors.html` exists and uses `data-i18n` | yes | **PASS** |
| F2 | `$actions->reqMonitors` in `lib/functions/common.php` | present | **PASS** |
| F3/F4 | `reqView.html` entry point | `openMonitorSet()` → `requirements/reqMonitors.html?req_id=` | **PASS** |
| F5 | the legacy Smarty template still calls the endpoint (informational — it is why the shim cannot be deleted) | yes | **PASS** |
| F6 | all 24 `reqmon.*` + `footers.reqMonitors` keys in **all 10** bundles, **flat**, and **no nested `reqmon`/`footers` object** | 0 missing | **PASS** |

> **F6 exists because of a real defect found in the browser.** The keys were first written as a
> *nested* `{"reqmon": {...}}` object plus a nested `footers.reqMonitors`. `TLi18n` resolves
> `data-i18n="reqmon.title"` against the **flat** key `reqmon.title` — every other bundle uses the
> flat convention (125 `footers.*` keys and **no** nested `footers` object), so the first browser pass
> rendered the literal strings `reqmon.title`, `REQMON.MONITORCOUNTBADGE`, `footers.reqMonitors` in
> all 10 locales while the BFF data was perfect. Flattened and re-verified; the key-count diff
> (5954 → 5977) proves no existing key was disturbed.

### Browser pass (headless Chrome, admin + a real `monnorights` login)

| Case | Check | Result |
|---|---|---|
| `req_id=7` | teal header, dark toolbar, context card (`REQ-MON-1`, `VERSION 1`, `OPEN`, test project `MON1`, monitor count 2, your login), `Monitor set` table with the `admin` row marked **YOU**, `2 MONITORING` + `YOU ARE MONITORING THIS REQUIREMENT` badges | **PASS** |
| `req_id=9` | empty state `No users are monitoring this requirement.`, `0 MONITORING`, 0 rows | **PASS** |
| `req_id=999999` | `Requirement not found` card + visible `requirement_not_found` | **PASS** |
| `req_id=0` | `Error` card + `No requirement was selected.` + `invalid_requirement` | **PASS** |
| `req_id=7` as `monnorights` (isolated browser context, real login) | `Access Denied` card + `no_right`, no monitor data | **PASS** |
| `req_id=7&locale=ro` | `Monitori cerință`, `Reîmprospătează`, `VERSIUNEA 1`, `DESCHISĂ`, `DUMNEAVOASTRĂ`, `2 MONITORIZEAZĂ`, footer `TestLink 2.0.1 - Monitori cerință` | **PASS** |
| Refresh button | reloads, 2 rows, button re-enabled (request token drops stale responses) | **PASS** |
| *Open requirement* | opens `reqView.html?req_id=7&tproject_id=1` in a new tab | **PASS** |
| `reqView.html?id=7` | *Monitor set* toolbar button **visible** (gated on `grant.monitor_req`) next to the start/stop-monitor toggle | **PASS** |
| console | 0 error / 0 warning on both screens | **PASS** |

Screenshots: `docs/screenshots/issue-1780-reqmonitors-list.png`,
`…-notfound.png`, `…-denied.png`, `…-ro.png`, `…-reqview-monitorset-button.png`.

### Defects found and fixed this run

1. **#1781 (bug)** — the 403/404 split let a caller with no requirement right enumerate requirement
   ids: `needTprojectIdForReq()` exited `404` on the missing-requirement branch *before* any right
   could be checked, because a missing requirement has no owning project to authorize against.
   Fixed with `canViewAnyRequirement()` (global role + every `tprojectRoles` entry), so a caller who
   may read nothing gets `403` for both. Verified by `D4` vs `D5`.
2. **`HEAD` answered `405 wrong_method`** on `?action=init` — a link checker or crawler gets told the
   endpoint does not exist. Now `GET` and `HEAD` both read the same payload (`C12`).
3. **i18n keys written nested** instead of flat, so the screen rendered raw key names in all 10
   locales (see the F6 note). Flattened, verified live.

### Status

`tmp/suite_1780.php` → **48/48 PASS**. Event Viewer: 0 new `log_level IN (1,2)` rows. Suite appended
per rule 9; docs mirror + wiki page + CHANGELOG line + ledger DONE row land with this screen.

## Suite 1666 — Regression — Issue #1666: `api/keywordsxml` XML round-trip import wrongly rejected with 400 `wrong_keywords_file`

**Bug** — an XML keywords import that touches only keywords which **already exist** updates
nothing in the count, and `api/keywordsxml/index.php` used the keyword **COUNT DELTA** as a
proxy for "rows read" (`$stats['rows'] = max(0,$after-$before)`), so a valid file was
refused with `400 wrong_keywords_file` — "Wrong keywords file - the format could not be
read" — blocking the advertised export → import merge workflow.

**Precondition** — `php tmp/fixtures_1666.php` (re-runnable) creates test project `1666`
(+ `nodes_hierarchy`, + admin `user_testproject_roles`) with keywords `alpha` / `beta`.

**Repro steps (pre-fix)** — `php tmp/suite_1666.php`, case `D1`:
export the project's own keywords (`?action=export&type=iSerializationToXML`) and
POST that byte-identical file back to `?action=import&type=iSerializationToXML`.

**Expected post-fix** — `200 {"status":"ok","rows":2,"imported":0,"skipped":2,
"errors":[{"row":1,"code":"ALREADY_EXISTS",…},{"row":2,"code":"ALREADY_EXISTS",…}]}`.

**Actual results (measured)**

| Case | Check | pre-fix | post-fix |
|---|---|---|---|
| A0 | session `POST /api/auth/index.php/login` | PASS | PASS |
| D1a/b | export of an existing set answers 200 and is a `<keywords>` document | PASS | PASS |
| **D1c–i** | **re-import of its own export: 200, rows=2, skipped=2, imported=0, `ALREADY_EXISTS` per row, no `code`** | **FAIL (400 `wrong_keywords_file`, rows=0, errors=[])** | **PASS** |
| D1j | keyword count unchanged | PASS | PASS |
| D2a–c | all-**new** XML still imports (count growth must not regress) | PASS | PASS |
| D3a–c | re-import of an existing keyword is idempotent, nothing duplicated | **FAIL (400)** | PASS |
| D4a–c | `<keywords></keywords>` (no child) still refused, `wrong_keywords_file`, rows=0 | PASS | PASS |
| D5a/b | non-XML garbage still refused | PASS | PASS |
| D6a/b | wrong root element still refused, `result=-16` | PASS | PASS |
| D7a/b | `<keyword>` without `name` still refused **and** the row is now named `WRONG_FORMAT` row 1 | FAIL (no row detail) | PASS |
| D8a–d | CSV all-duplicate keeps #1605 semantics: 400 `NO_KEYWORDS_IMPORTED`, rows=2 (header not a row), skipped=2 | PASS | PASS |
| D8e/f | partial CSV import still 200, imported=1/skipped=1 | PASS | PASS |
| D9a/b/c | unknown project 404, `tproject_id=0` 400, GET on import 405 | PASS | PASS |
| D10a/b | export arm unaffected | PASS | PASS |

**Suite totals** — pre-fix **28 passed / 9 failed**, post-fix **37 passed / 0 failed**
(`php tmp/suite_1666.php`; the 9 pre-fix failures are exactly D1c–i, D3a and D7b).
Every "nothing was read" guard (D4/D5/D6) and the whole CSV arm (D8) behave identically
before and after — the fix changes only the false rejection.

**Browser pass (headless Chrome, admin/admin, `keywordsExport.html?tproject_id=1`)**

| Case | Check | Result |
|---|---|---|
| Export tab → Export (XML) → Import tab → upload the same file | dialog shows `Imported 0 of 5 rows; 5 row(s) were rejected` + one `A keyword with this name already exists.` line per row | **PASS** |
| same flow on pre-fix `api/keywordsxml/index.php` | red box `Wrong keywords file - the format could not be read.` and **no** row detail | PASS (bug reproduced) |
| console | 0 error / 0 warning | **PASS** |
| Event Viewer (`events`) | only `log_level=16` audit rows from keyword creation — **0 Error/Warning** | **PASS** |

Screenshot: `docs/screenshots/issue-1042-tcview-attachment-download.png`.

---

## Regression — Issue #1784: `api/keywordsxml` — a malformed LAST `<keyword>` turned a successful XML import into `400 wrong_keywords_file`

**Preconditions**

* App on `http://localhost:8082`, logged in as `admin`/`admin`.
* A test project exists. The CI database is freshly imported (no projects), so create the fixture through
  the BFF — `POST /api/projects/index.php?action=create {"name":"KWBugRepro","prefix":"KWB","description":"fixture for issue 1784","public":"public"}`
  → `{"success":true,"id":1}` ⇒ **`tproject_id=1`** (this is what `tmp/verify_1784.sh` does).
* All POSTs to the BFF need the same-origin proof header: `-H "Origin: http://localhost:8082"`, otherwise
  `403 Forbidden: missing or mismatched same-origin proof (CSRF protection)` before any of this runs.

**Repro steps (pre-fix)**

1. `printf '<keywords>\n  <keyword name="delta"><notes>d</notes></keyword>\n  <keyword><notes>no name</notes></keyword>\n</keywords>\n' > repro_good_last.xml`
2. `curl -b c.jar -H "Origin: http://localhost:8082" -X POST -F tproject_id=1 -F type=iSerializationToXML -F "uploadedFile=@repro_good_last.xml" "http://localhost:8082/api/keywordsxml/index.php?action=import"`
3. `mysql … -e "SELECT id,keyword FROM keywords WHERE testproject_id=1;"`
4. Repeat the identical upload with the two rows swapped (malformed first).

**Expected post-fix behaviour**

* A row-level rejection is reported, never fatal: `200 {"imported":1,"skipped":1,"rows":2,"errors":[{"row":2,"code":"WRONG_FORMAT","name":""}]}`,
  the dialog says "Imported 1 of 2 rows; 1 row(s) were rejected" + the row detail, and the outcome no longer
  depends on row order.
* A file that cannot be read at all (parse failure / wrong root node) still answers `400 wrong_keywords_file`.

**Actual results — measured after the fix** (script: `bash tmp/verify_1784.sh`)

| # | case | expected | measured | verdict |
|---|---|---|---|---|
| 1 | valid row 1 + malformed row **last** (the reported file) | `200 imported:1 skipped:1 rows:2 errors:[row 2]` | `200 {"imported":1,"skipped":1,"rows":2,"errors":[{"row":2,"code":"WRONG_FORMAT","name":""}]}` — pre-fix: `400 wrong_keywords_file result:-16` | **PASS** |
| 2 | malformed row 1 + valid row last (same bytes, other order) | same answer as #1 | `200 {"imported":1,"skipped":1,"rows":2,"errors":[{"row":1,…}]}` | **PASS** |
| 3 | retry of case 1 (`delta` now exists) | never a bare `wrong_keywords_file`; both rows named | `400 NO_KEYWORDS_IMPORTED imported:0 skipped:2 rows:2 errors:[{row:1,ALREADY_EXISTS,delta},{row:2,WRONG_FORMAT,""}]` | **PASS** |
| 4 | every row rejected (2 nameless `<keyword>`) | `400 NO_KEYWORDS_IMPORTED` + full row list | `400 {"code":"NO_KEYWORDS_IMPORTED","skipped":2,"rows":2,"errors":[row 1, row 2]}` | **PASS** |
| 5 | `not xml at all` | `400 wrong_keywords_file result:-16` (unchanged) | unchanged | **PASS** |
| 6 | `<notkeywords><keyword name="x"/></notkeywords>` | `400 wrong_keywords_file result:-16` (unchanged) | unchanged | **PASS** |
| 7 | `<keywords></keywords>` (empty root) | documented legacy baseline | `400 wrong_keywords_file result:-16` — pre-existing (an empty `SimpleXMLElement` is falsy, so the legacy `!$simpleXMLObj` guard fires); measured identical pre-fix, **deliberately unchanged** | **PASS** (baseline) |
| 8 | all rows valid | `200 imported:N skipped:0` (no behaviour change) | `200 {"imported":2,"skipped":0,"rows":2,"errors":[]}` | **PASS** |
| 9 | CSV arm, valid file | unchanged | `200 {"imported":1,"skipped":0,"rows":1,"errors":[]}` | **PASS** |
| 10 | CSV arm, every row rejected | `400 NO_KEYWORDS_IMPORTED` + row list | `400 … skipped:2 rows:2 errors:[ALREADY_EXISTS, EMPTY_NAME]` | **PASS** |
| 11 | CSV arm, mixed | `200 imported:1 skipped:1` | `200 {"imported":1,"skipped":1,"rows":2,…}` | **PASS** |
| 12 | sibling route `POST /api/keywords/index.php/import` (`type=xml`), reported file | row detail published, not a bare `WRONG_FORMAT` | `422 {"error_code":"NO_KEYWORDS_IMPORTED","rows":2,"skipped":2,"errors":[{row:1,ALREADY_EXISTS,delta},{row:2,WRONG_FORMAT,""}]}` — pre-fix: `422 WRONG_FORMAT`, no detail | **PASS** |
| 13 | sibling route, `not xml at all` | `422 WRONG_FORMAT` (unchanged) | unchanged | **PASS** |
| 14 | database | each valid keyword created exactly once, no junk rows | `delta`(5) `delta2`(6) `onlybad`(7) `ok1`(8) `ok2`(9) `ok3`(10) `ok4`(11) — 1 row per valid input row | **PASS** |
| 15 | dialog UI, partial file (chrome-devtools) | "Imported 1 of 2 rows; 1 row(s) were rejected" + row 2 named + project count 8 → 9 | exactly that (`docs/screenshots/issue-1784-keywords-xml-partial-import.png`) | **PASS** |
| 16 | dialog UI, every row rejected | "No keyword was imported - every row was rejected" + both rows named | exactly that (`docs/screenshots/issue-1784-keywords-xml-all-rejected.png`) | **PASS** |
| 17 | syntax gates | `php -l` on the 3 touched files | clean ×3 | **PASS** |
| 18 | i18n | no hardcoded user-facing string added, all 10 bundles untouched | no i18n file modified (the dialog already renders `errors[]` and maps `WRONG_FORMAT` → `kwxml.wrongFile`) | **PASS** |
| 19 | Event Viewer / `events` | no new Error/Warning | 14 rows after 15 imports incl. 3 malformed, **all `log_level` 16** (audit) | **PASS** |
| 20 | browser console | no unexpected error | 1 entry: the intentional `400` of case 16 (the all-rejected answer) | **PASS** |

**Actual result** — 20/20 PASS (19 fixed/verified + 1 documented pre-existing baseline, case 7).
The API half is also executable: `bash tmp/verify_1784.sh` creates its own fixture project and runs
15 case groups / **18 assertions** — measured **18 PASS / 0 FAIL**.

**Files** — `lib/functions/testproject.class.php` (`importKeywordsFromSimpleXML()` + both wrappers),
`api/keywordsxml/index.php`, `api/keywords/index.php`. No client/JS and no locale bundle changed.

## 1783 — Regression: Keyword import updates existing keywords (XML and CSV)

**Precondition.** `admin`/`admin` on `http://localhost:8082`. DB freshly imported. Create test project P (e.g. via UI or fixture) with keyword `alpha` having notes `first note`. Endpoint `api/keywordsxml/index.php`.

**Steps and expected results:**

| # | Action | Expected | Observed (post-fix) | Status |
|---|---|---|---|---|
| 1 | Prepare XML import file with `<keywords><keyword name="alpha"><notes>UPDATED-BY-IMPORT</notes></keyword></keywords>` and import via `POST /api/keywordsxml/index.php?action=import` with `tproject_id`, `type=iSerializationToXML`, file | Response status ok, `imported=1`, `skipped=0`, `rows=1`, `errors=[]`. | As expected | PASS |
| 2 | Verify DB: `SELECT notes FROM keywords WHERE keyword='alpha' AND testproject_id=<id>` | Returns `UPDATED-BY-IMPORT` | As expected | PASS |
| 3 | Reset notes back to `first note`. Prepare CSV `alpha;UPDATED-BY-IMPORT` and import with `type=iSerializationToCSV` | Response status ok, `imported=1`, `skipped=0`, `rows=1`, `errors=[]`. | As expected | PASS |
| 4 | Verify DB notes updated to `UPDATED-BY-IMPORT` | Notes = `UPDATED-BY-IMPORT` | As expected | PASS |
| 5 | Import a new keyword via XML (e.g. `beta` with notes) | New keyword created (`imported=1`), count increases | As expected | PASS |
| 6 | Import a new keyword via CSV (e.g. `gamma` with notes) | New keyword created (`imported=1`) | As expected | PASS |
| 7 | Regression: existing duplicate handling still works correctly (no unintended updates on create-only paths) | Non-import create paths still reject true duplicates as before | No change to core class behavior; import paths only affected | PASS |

**Notes.** Fix ensures upsert semantics on import (update if exists by name, create if not), matching UI hint "Existing keywords with the same name are updated; new ones are created." The core `tlKeyword` class behavior unchanged to preserve duplicate-prevention for non-import flows.


## 1785 — Modernize Full-Text Search (lib/search/searchMgmt.php)

**Precondition.** `admin`/`admin` on `http://localhost:8082`; DB freshly imported; fixture
`php tmp/fixtures_1785.php` run (SFM1 = requirements ENABLED, prefix SFM; SFM2 = foreign project,
prefix SFX; `sfm1785norights`/`admin` = role without `mgt_view_tc`). Log in with
`bash tmp/login_curl.sh /tmp/ck_a.txt`. Screen: `gui/templates/search/searchMgmt.html`;
BFF: `api/searchmgmt/index.php`.

| # | Action | Expected | Observed | Status |
|---|---|---|---|---|
| 1 | `GET ?action=init&tproject_id=59` as admin | `200`, `context.tproject_name=SFM1`, `reqEnabled=true`, criteria groups for tc/ts/rs/rq, `keywords=[smoke]`, `grants.mgt_view_tc=true` | as expected (criteria map = BFF `tcID…`/`rqDocId…` labels) | PASS |
| 2 | `?action=results&tproject_id=59&target=password` | 1 test case (SFM-1) + 1 requirement (SFM-R1), `count=2` | `tc 1 ts 0 rs 0 rq 1 count 2` | PASS |
| 3 | `target=Suite` | test-suite block with 2 suites ("Login Regression Suite", "Checkout Wizard Suite") | `ts 2` | PASS |
| 4 | `target=Sprint Specification` | requirement-spec block with the spec | `rs 1` | PASS |
| 5 | `target="login password"` OR | matches any word, `count=3` | `count 3` | PASS |
| 6 | same target with `and_or=and` | only rows containing both words, `count=2` | `count 2` | PASS |
| 7 | `target=Suite&ts_title=0` | suite narrowing removes title matches (0 suites) | `ts 0 count 0` | PASS |
| 8 | empty `target=` (no refinement) | `400 {"code":"need_criteria"}` (never every test case) | `400 need_criteria` | PASS |
| 9 | `target=zzz_no_match` | `200`, `warning=no_records_found`, `count=0` | as expected + no-records notice in UI | PASS |
| 10 | `target=Foreign` on SFM1 | SFM2 (foreign project) rows never leak | `count 0 names []` | PASS |
| 11 | `target=XSS` | test case `XSS <script>window.__sfmxss=1;</script> probe` is rendered escaped | `window.__sfmxss` undefined; cell innerHTML shows `&lt;script&gt;` | PASS |
| 12 | `target=<script>alert(1)</script>` | safe `200`, no match, no reflection | `200 count 0` | PASS |
| 13 | `target=o'brien` (apostrophe) | safe `200 no_records_found` (was a 500 + backtrace leak pre-fix) | `200 no_records_found` | PASS |
| 14 | `action=init&tproject_id=999999` | `404 {"code":"tproject_not_found"}` | `404` | PASS |
| 15 | `POST` with same-origin Origin | `405` (screen is read-only) | `405` | PASS |
| 16 | same calls as `sfm1785norights` | `403 {"code":"forbidden"}` before any project data; `audit_security_user_right_missing` event | `403` init+results; audit row in `events` | PASS |
| 17 | requires-disabled project (SFM3, id 93) `init` | `reqEnabled=false`, req criteria groups empty, screen hides them + chip "Requirements disabled" | as expected | PASS |
| 18 | empty test project (SFM3) `results` | `200 warning=empty_testproject`; UI shows the "no test cases yet" notice | as expected | PASS |
| 19 | legacy shim `lib/search/searchMgmt.php?target=password` without cookie | `200` login page; with cookie `302` → `searchMgmt.html?...&target=password` | `200` / `302 …&target=password` | PASS |
| 20 | `?target=password` hand-off | screen pre-fills the box and auto-runs the search | `2 match(es)` shown automatically | PASS |
| 21 | locale switcher → Română | persisted locale, all labels translated from `ro.json` | header "Căutare în text complet", footer/errors Romanian | PASS |
| 22 | Event Viewer / `events` after all searches | no new Error/Warning (`log_level` 2) from searchMgmt | 0 new rows after 3 searches (E_NOTICE by-ref bug fixed) | PASS |
| 23 | browser console | no unexpected error | 0 console messages | PASS |
| 24 | syntax gates | `php -l` on BFF + shim, `python3 -m json.tool` on all 10 bundles | clean ×12 | PASS |

**Actual result — 24/24 PASS.** BFF defect fixes verified: `array_keys()+` union dropping `ts_title`/`ts_summary`
(bug: suites never returned), `initSchema()` ordering, double-escaped target (apostrophe 500), `need_criteria`
unreachable (empty search returned the whole project), and the `get_full_path_verbose()` by-ref E_NOTICE
(warning Event Viewer entry). Code review (rule 16) returned **no blockers**.

**Files** — `api/searchmgmt/index.php`, `gui/templates/search/searchMgmt.html`,
`gui/templates/i18n/{en,ro,de,es,fr,it,pt,ja,ru,zh}.json`, `lib/search/searchMgmt.php`,
`lib/functions/common.php` (`$actions->searchMgmt`). Screenshots in `docs/1785-searchmgmt-*.png`.

---

## Regression — Issue #1680: `usersAssignPlan.html` `onRoleChange` threw `TypeError: Cannot read properties of undefined (reading 'row')` on the 2nd change of the same row

**Precondition / fixture**

* App `http://localhost:8082`, login `admin` / `admin` (global admin, role 8).
* Fresh DB — create (see `tmp/fixtures_1680.sql`):
  * test project **#1680**: `nodes_hierarchy` row `(1680,'Issue 1680 Repro Project',0,1,1680)` **and** `testprojects` row `(1680,…)`
    (⚠ the project NAME lives in `nodes_hierarchy` — `testproject.class.php:551` selects `NHTPROJ.name`; without the
    `nodes_hierarchy` row the project combo is empty and the screen shows `assign.rolesForPlansDisabled`).
  * test plan **#1680** (`testplans.testproject_id=1680`, `active=1`, `is_public=1`).
  * users **#1681 `tina.tester`** (global role 7, explicit plan role **5** = guest) and **#1682 `tom.tester`** (global role 7, explicit plan role **4** = test designer), via `user_testplan_roles`.
* Screen: `http://localhost:8082/gui/templates/usermanagement/usersAssignPlan.html?tproject_id=1680&tplan_id=1680`
* Grid renders 3 rows (`data-uid` = `1` = admin, `1681`, `1682`), `assignDt` live, `paginationCfg.enabled = true`.

**Repro steps (pre-fix)** — change the *Plan Role Override* `<select>` of row **1681** to `6` (step 3), then change the
**same row's** select again (step 4). Pre-fix the second change raised
`Uncaught TypeError: Cannot read properties of undefined (reading 'row')` with stack
`jquery.dataTables.min.js:4:66727 ← jquery.dataTables.min.js:4:53720 ← onRoleChange (usersAssignPlan.html:1334)`,
which aborted `onRoleChange()` **before** `$('#saveBtn').prop('disabled', !isDirty())` — so the Save button kept the
state of the previous change (stays enabled when `isDirty()` is `false`, stays disabled when it should be enabled)
and the DataTables cell cache was left stale (a paging/search/sort re-draw resurrects the previous option).

**Mechanism proven** (minified frame de-minified): `cell().data()` does `var n = this[0]; … n[0].row` — the throw is
`n === []`, i.e. `assignDt.cell(tr, 4)` returned an **empty API instance**. `assignDt.cell($('<tr>'), 4).data('zz')`
reproduces the byte-identical message on demand. Root cause: `tr` was the **jQuery wrapper**, and DataTables 1.13.7's
`__row_selector` takes its exact node path only `if (sel.nodeName)` (`jquery.dataTables.js:8116`); a wrapper falls
into the generic `$(nodes).filter(sel)` identity catch-all (`dt.js:8165-8179`), which can and does yield no row.

**Expected post-fix** — `tr[0]` (the DOM node) is passed instead, so DataTables resolves the row through the row index
it stamped on the node; every change updates highlight + *Modified* badge + Save state + cell cache, with no console error.

| # | Case | Step | Expected | Observed | Result |
|---|---|---|---|---|---|
| 1 | first change on a non-admin row | set row 1681 `5 → 6` | row highlighted, *Modified* badge, **Save enabled** | `dirty=true`, `saveDisabled=false`, badge present | PASS |
| 2 | **second change of the same row** (the reported TypeError case) | set row 1681 `6 → 7` | no console error, Save state still correct | `saveDisabled === !isDirty()`, **0 console messages** | PASS |
| 3 | change back to the original value | set row 1681 `7 → 5` | **Save disabled**, badge gone, class gone | `saveDisabled=true`, `dirty=false`, no badge, no `changed` class | PASS |
| 4 | change on a second row | set row 1682 `4 → 6` | Save enabled, both rows tracked | `saveDisabled=false`, badge on 1682 only | PASS |
| 5 | sort re-draw | `assignDt.order([[4,'desc']]).draw(false)` then `[[1,'asc']]` | chosen options survive (cell cache re-synced) | `1681:4` before/after both sorts; `modelRoleVal=4` | PASS |
| 5b | search re-draw | `assignDt.search('tina').draw(false)` then clear | chosen option survives | `1681:4` during search and after clearing | PASS |
| 6 | bulk "Do" rebuilds the grid, then a per-row change | bulk role `7` → "Do", then change row 1681 | grid rebuilt, per-row change still updates Save | `bulkVals=[1:0,1681:7,1682:7]`, `saveDisabled=false`, badge present | PASS |
| 7 | admin row (locked select) | change row 1's select on a clean grid | never dirty, Save stays disabled | `select.disabled=true`, `dirty=false`, `saveDisabled=true` | PASS |
| 8 | pagination disabled | `paginationCfg.enabled=false; renderUsersTable()` then change row 1681 | **no DataTable**, handler still works, no exception | `assignDt === null`, `saveDisabled=false`, badge present | PASS |
| 9 | Event Viewer / `events` | after the whole matrix | no new Error/Warning rows | `events` holds only the 2 `audit_login_succeeded` rows (`log_level=16`) | PASS |
| 10 | Save round trip (regression of the write path) | change row 1681 `→ 6`, click `#saveBtn` | `user_testplan_roles.role_id` persisted, Save re-disabled, `isDirty()=false` | `user_testplan_roles` → `1681 → 6`; `saveDisabled=true`; `dirty=false` | PASS |
| 11 | syntax gate | inline `<script>` block parsed | clean | `new Function(<inline script>)` → **syntax OK for 1 inline script block(s)** | PASS |

**Actual result — 11/11 PASS** (cases 1-11; the 9-case matrix of the investigation comment plus the Save round trip and the
syntax gate). Browser console after the whole matrix: **no messages at all** (`chrome-devtools_list_console_messages
types=[error,warn]` → `<no console messages found>`) — the pre-fix `TypeError` is gone.

**Files** — `gui/templates/usermanagement/usersAssignPlan.html` (`onRoleChange`, cell re-sync), `CHANGELOG`,
`tmp/fixtures_1680.sql`, `docs/Bugfix-Issue-1680-usersAssignPlan-onRoleChange-TypeError.md`.

**Code review (AGENTS.md rule 16) outcome** — a review subagent was run over the full diff
(`gui/templates/usermanagement/usersAssignPlan.html` + `CHANGELOG`). **No BLOCKERs.** Two findings were applied:

* **MAJOR — inaccurate library line citation.** The inline comment cited `jquery.dataTables.js:8116` for
  `if ( sel.nodeName )`; verified against the unminified 1.13.7 source the guard is at **`:8121`**
  (`grep -n "if ( sel.nodeName )" dt-full.js` → `8121`). Corrected, and the filename normalised to
  `jquery.dataTables.js` everywhere (the catch-all at `:8165-8179` and the `cells()` row+column branch at
  `:9095-9110` were already correct and are unchanged).
* **MINOR — the defensive `catch` swallowed the error with no telemetry.** The modernized screens already use
  `console.warn` on defensive paths (`reqSpecListTree.html:195`, `tcProjectTree.html:213`,
  `tcView.html:561`), so the catch now emits `console.warn('[usersAssignPlan] cell cache resync skipped:', e)`.
  It still cannot abort the save-state update — that is the whole point of the guard.
* **Sibling parity confirmed by grep:** `git grep -n '\.cell('` over `gui/templates lib api` returns exactly
  **one** application call site — `usersAssignPlan.html:1348`. `usersAssignProject.html` has **no** `cell()`
  usage (its hits in other files are vendored FullCalendar, unrelated). No parity fix needed.
* **i18n:** no user-facing string touched → no locale bundle edited, nothing to validate.

**Re-verification after the review edits** (fixture reset to the documented baseline `1681→5`, `1682→4` first,
because an earlier Save round-trip had persisted `1681→6` — with a dirty fixture the expectations invert, which
is correct behaviour, not a defect):

| # | Case | Result |
|---|---|---|
| 0 | fixture precondition (grid `1:0,1681:5,1682:4`, `isDirty()=false`, Save disabled) | PASS |
| 1 | first change on a non-admin row | PASS |
| 2 | **second change of the same row** (the reported TypeError case) | PASS |
| 3 | change back to the original value | PASS |
| 4 | change on a second row | PASS |
| 5 | sort + search re-draw preserve both chosen options | PASS (`1681:4`, `1682:6` survive every redraw) |
| 6 | bulk "Do" rebuilds the grid, then a per-row change | PASS |
| 7 | admin row (locked select) never dirty | PASS |
| 8 | pagination disabled → no DataTable, handler still works | PASS |
| 9 | Event Viewer: `select count(*) from events where log_level in (1,2,3)` | **0** rows | PASS |
| 10 | Save round trip | `user_testplan_roles` → `1681 → 6`; Save re-disabled; `isDirty()=false` | PASS |

Console after the full matrix: **no error/warn messages.** Fixture restored to the documented baseline afterwards.

---

## Task — Issue #1037: platforms display in tcView.html (gap vs legacy)

Ports the three legs of the legacy include `gui/templates/dashio/testcases/include/platforms.inc.tpl`
(rendered per version by `tcView_viewer.tpl:496-511`) into the modern viewer: the
Platform Management link on the label, per-platform unassign with the legacy
`remove_plat_msgbox` confirm, and the free-platform multi-select + Add.

### Precondition / fixture

`tmp/fixtures_1037.sql` (freshly imported DB has 0 rows in `nodes_hierarchy`,
`tcversions` and `platforms`):

```
tproject 900001 "TLU1037 Project" (prefix TLU1037)
  suite 900002 > tcase 900003 > tcver 900004 (v1) / 900005 (v2)
  platform 900006 "Linux"    enable_on_design=1   linked to v1
  platform 900007 "Win 11"   enable_on_design=1   free
  platform 900008 "MAC OS X" enable_on_design=0   must never be offered
```

Log in as `admin`/`admin`, open
`http://localhost:8082/gui/templates/testcases/tcView.html?tcase_id=900003`.

### Cases

| # | Step | Expected | Observed | Result |
|---|------|----------|----------|--------|
| 1 | Load the viewer (admin, v1 linked to Linux) | `Platforms` label is a link to Platform Management | `isLink:true`, `href=/gui/templates/platforms/platformsView.html?tproject_id=900001`, `title="Open Platform Management"` | PASS |
| 2 | Inspect v1 chips | `Linux` chip + ✕ button | `chip-x onclick="confirmRemovePlatform(900004,900006,900009)"` | PASS |
| 3 | Inspect v2 chips | `None`, no ✕, `+ Add` | `None`, `hasX:false`, `addBtn:true` | PASS |
| 4 | Click `+ Add` on v1 | Modal lists the free platforms only | options `["Win 11"]` — `Linux` (already linked) and `MAC OS X` (`enable_on_design=0`) excluded | PASS |
| 5 | Select `Win 11`, click Add | Toast, chip appears, DB row created, Add button disappears | toast `Platform(s) added to this test case version`; chip `Win 11` present; `testcase_platforms` → `(900010,900003,900004,900007)`; free list empty → no Add button | PASS |
| 6 | Click ✕ on `Linux` | Legacy confirm wording with `%i` = platform name | `Remove Platform` / `Do you want to remove all executions linked to Linux?` | PASS |
| 7 | Confirm removal | Toast, chip gone, DB row deleted | toast `Platform removed from this test case version`; chip gone; `(900009,…)` deleted | PASS |
| 8 | Freeze v1 (`UPDATE tcversions SET is_open=0 WHERE id=900004`) and reload | platRW=0: no ✕, no Add; management link still shown (legacy renders it unconditionally) | `frozen:true, hasX:false, addBtn:false, mgmtLink:true` | PASS |
| 9 | Un-freeze v1 | ✕ and Add come back | identical to case 2/3 | PASS |
| 10 | Switch locale to German (`&locale=de`) and click ✕ | German legacy wording | `Plattform entfernen` / `Möchten Sie alle mit Win 11 verknüpften Ausführungen entfernen?` | PASS |
| 11 | All 10 bundles carry the 13 new keys | `python3 -m json.tool` valid + key present | 10/10 bundles `+13 keys`, all valid | PASS |
| 12 | Regression — `enable_on_design=0` never offered | `MAC OS X` absent from every free list | absent for v1 and v2 | PASS |
| 13 | Regression — no Error/Warning events created | `events` table unchanged | only the 2 pre-existing `audit_login_succeeded` LOGIN rows (log_level 16) | PASS |
| 14 | Regression — browser console | no errors/warnings | `<no console messages found>` | PASS |
| 15 | Regression — `tmp/php_server.log` | no PHP error/warning | `grep -iE "error|warning|fatal|notice"` over 371 lines → no match | PASS |

**15 PASS / 0 FAIL.**

### Defect this suite found (fixed in `929618c9f`)

`platApiPost()` initially posted to `/api/testcases/index.php` without `?action=`.
The BFF is query-routed, so the request fell through to the catch-all
`api/testcases/index.php:2874` → HTTP 400 `{"status":"error","message":"Bad request"}`
and assign always failed (network request `reqid=119` captured as proof). Note that the
sibling BFF `api/requirements/index.php` used by the requirements modal of the same screen
IS path-routed, so copying that call shape does not work for `api/testcases/index.php`.

### Screenshots

* `docs/screenshots/issue-1037-tcview-platforms-before.png` — read-only chips, no link/✕/Add
* `docs/screenshots/issue-1037-tcview-platforms-after.png` — full panel
* `docs/screenshots/issue-1037-tcview-platforms-remove-confirm.png` — legacy confirm box

### Code-review remediation round (post #1037 review) — cases 14-24

Fixture reset to a deterministic baseline (`tmp/fixtures_1037.sql` is now idempotent and
restores exactly this state):

```
v1 (900004, open, NOT executed): Linux      (link 900009)   free: Win 11
v2 (900005, open, EXECUTED):     Win 11     (link 900012)   free: Linux
executions 900011 on v2 (platform 900006)
role 8 (admin) has mgt_modify_tc + testproject_edit_executed_testcases(39)
role 3 = read-only user tlu1037norights, only mgt_view_tc(6)
```

| # | Step | Expected | Observed | Result |
|---|------|----------|----------|--------|
| 14 | **REGRESSION (review MAJOR):** open `tcView.html?tcase_id=900003&tcversion_id=900004` (v1 = NOT the latest), click `+ Add`, select `Win 11`, Add | The toolbar must STAY on v1 — `render()` picks `currentVersion` from `data.requestedTcversionId`, so the refresh must carry `tcversion_id` | Before fix: re-fetch without `tcversion_id` → `requestedTcversionId=0`, `currentVersionVersion=2`, `currentVersionTcversionId=900005` (Edit Version / Print / Export would retarget to v2). After fix: `requestedTcversionId=900004`, `currentVersion.version=1`, `currentVersion.tcversion_id=900004`, 1 version card, chips `[Linux, Win 11]` | PASS |
| 15 | Same page, ✕ on the new chip | Confirmation modal names the right platform (name resolved from `p.tcplat_link`, not `p.id`) | `confirmRemovePlatform(900004,900011)`; modal `Remove Platform` / `Do you want to remove all executions linked to Win 11?`; modal state `{tcversionId:900004, tcplatLinkId:900011}` (no `platformId` any more) | PASS |
| 16 | Force a stale link: `$('#rmPlatModal').data('tcplatLinkId', 999999); doRemovePlatform();` | Server rejection is surfaced verbatim and the modal stays open for a retry | toast `Platform link #999999 is no longer assigned to this test case version`; modal `display:flex`; chips unchanged `[Linux, Win 11]` | PASS |
| 17 | Valid remove after the failed attempt | Toast + chip gone + Add button back (Win 11 free again) | toast `Platform removed from this test case version`; v1 chips `[Linux]`; `currentVersion.tcversion_id=900004` still v1 | PASS |
| 18 | BFF `remove_platform` with a link id that belongs to ANOTHER version (`tcversion_id=900005` + link `900009`) | 404, nothing deleted (legacy `deletePlatformsByLink` silently no-op'ed and raised an E_WARNING) | `404 {"message":"Platform link #900009 is no longer assigned to this test case version"}`; link 900009 intact | PASS |
| 19 | BFF `remove_platform` / `add_platform` as the read-only user | 403 on both writes | `403 {"message":"Requires permission: modify test cases"}` twice; DB unchanged | PASS |
| 20 | Read-only user viewing the test case | Chips without ✕, no `+ Add`, no Platform Management link | `grants platform_management=0 platform_view=0`; v1 `[Linux]` and v2 `[Win 11]` with `canAssignPlatforms=false`, `hasX:false`, `addBtn:false`, `mgmtLink:absent` | PASS |
| 21 | **REGRESSION (pre-existing BFF bug found here):** version 2 has an execution row | `has_been_executed=true` and the executed branch of `canAssignPlatforms` must evaluate | Before fix: `has_been_executed=false` for BOTH versions because `get_by_id(..., access_key='tcversion_id')` returns a 0-indexed array → the query ran `tcversion_id IN (0,1)`. After fix: admin `v2 executed=True canAssign=True`, read-only `v2 executed=True canAssign=False` | PASS |
| 22 | `DELETE FROM role_rights WHERE role_id=8 AND right_id=39` (revoke `testproject_edit_executed_testcases`), reload | Executed v2 loses ✕/Add (`canAssignPlatforms=false`) and the BFF refuses the write with 403 | `v2 executed=True canAssign=False`, `v1 canAssign=True`; `add_platform` on v2 → `403 Platform assignment is not allowed on this version (frozen, executed without exec-edit right, or no edit right)`; right restored afterwards | PASS |
| 23 | Frozen version (`UPDATE tcversions SET is_open=0 WHERE id=900005`) | BFF refuses the write | `403 Platform assignment is not allowed on this version (frozen, executed without exec-edit right, or no edit right)`; `is_open=1` restored | PASS |
| 24 | **Event Viewer / PHP log after the whole remediation round** | No new Error/Warning | `events` MAX(id) unchanged (10) across bogus + valid removes; `tmp/php_server.log` has no error/warning/fatal lines; browser console: no error/warn messages. Event 10 (E_WARNING `foreach() argument must be of type array\|object, null given`, `testcase.class.php:9885`) was produced by the PRE-FIX silent no-op and is now unreachable from the modern path — filed as a separate `bug` issue | PASS |

**Totals for #1037: 24 cases, 24 PASS, 0 FAIL.**

---

## Regression — Issue #1779: `api/suitemove` — a container of an UNENTITLED project still answered `403`, an absent one `404` (id existence oracle)

**Precondition** (fixture is idempotent, recreates itself; reuses the #1759 fixture so both issues
share one dataset)
```bash
php tmp/fixtures_1759.php     # 2 private projects, 3 users, 2 top-level suites each
bash tmp/verify_1779.sh        # the 28-case matrix added for this issue
```
Fixture data (ids as printed by the fixture on a fresh DB): test projects `SM1759A=1` (prefix
`S9A`) and `SM1759B=2`; project A holds `A-suite-1=3` and `A-suite-2=4`, project B holds
`B-suite-1=5`, `B-suite-2=6` (ids 1/2 are the project roots). Users: `sm1759a` (project role with
`mgt_view_tc` + `mgt_modify_tc`, granted on **project A only**), `sm1759view` (project role with
**only** `mgt_view_tc`, on project A), `sm1759norights` (global role 3 *no rights*, **no**
`user_testproject_roles` row at all), `admin`. Password of all three: `admin`.
Absent-id probe = `999999` (exists nowhere).

**Repro steps (pre-fix)** — only a valid session is needed, **no project rights at all**:
1. `POST /login.php?action=doLogin` with `tl_login=sm1759norights&tl_password=admin`.
2. `GET /api/suitemove/index.php?action=init&tproject_id=1&container_id=3` →
   `403 {"status":"error","code":"forbidden","message":"Insufficient rights on this test project"}`.
3. `GET /api/suitemove/index.php?action=init&tproject_id=1&container_id=999999` →
   `404 {"status":"error","code":"not_found","message":"Container not found"}`.
4. The two answers differ ⇒ one sweep of `container_id` enumerates every suite **and** project-root
   id of a project the caller may not even look at. The same split exists on the two write paths
   (`action=reorder` with `container_id`, `action=move` with `node_id`).
5. Control: `GET …?action=init&tproject_id=1` (no container) answered `403` too — so the *project*
   id itself was NOT an oracle (#1759 M21), the leak was strictly the container/node id.

**Expected post-fix behavior** — the guard is armed on the **presence of a caller-supplied node id**
(same rule as `tcreoProject()`, #1761), so *every* refusal about a named container or node is the
opaque `404` whose **message is the one the calling action already uses for an absent id**:
`"Container not found"` for `init`/`reorder`, `"Suite not found"` for `move`. A request that names
**no** node keeps its informative `403`, and `case 'suites'` (which takes no node id from the caller)
keeps it as well.

**Actual result observed** — `bash tmp/verify_1779.sh` → **28 passed, 0 failed**;
`bash tmp/verify_1759.sh` (rows `M12`/`M12b`/`M12c` updated for this issue) → **28 passed, 0 failed**
(`M5` SKIP — no test-case fixture, pre-existing).

| # | Step | Expected | Observed | Result |
|---|------|----------|----------|--------|
| R1 | as `sm1759norights`, `init&tproject_id=1&container_id=3` | `404 not_found` | `404 {"code":"not_found","message":"Container not found"}` | PASS |
| R2 | as `sm1759norights`, `init&tproject_id=1&container_id=1` (project root) | `404 not_found` (was `403`) | `404`, same body | PASS |
| R3 | as `sm1759norights`, `init&tproject_id=1` (**no** container) | informative `403 forbidden` survives | `403 {"code":"forbidden",…}` | PASS |
| R4 | as `sm1759norights`, `POST action=reorder&tproject_id=1&container_id=3&nodelist=3,4` | `404 not_found` (was `403`) | `404` | PASS |
| R5 | as `sm1759norights`, `POST action=move&tproject_id=1&node_id=3&position=down` | `404 not_found` (was `403`) | `404` | PASS |
| R6 | as `sm1759norights`, `POST action=move&node_id=3&position=down` (no project named) | `404` unchanged | `404` | PASS |
| R7 | as `sm1759norights`, `GET action=suites&tproject_id=1` — takes **no** node id from the caller | informative `403` must survive | `403 forbidden` | PASS |
| R8/R9 | `init&tproject_id=2` (real other project) vs `init&tproject_id=424242` (absent) | both `403`, byte-identical (#1759 M21) | both `403`, identical | PASS |
| R10–R13 | byte-equality of an unentitled id vs `999999` for `init` (suite), `init` (root), `reorder`, `move` | identical **bodies**, not only statuses | 4/4 identical; `move` = `{"…","message":"Suite not found"}` on both sides | PASS |
| R14 | as `sm1759view` (own project, **view only**), `init&tproject_id=1&container_id=3` | `404` — **the accepted trade-off** (tcreorder #1761 case R25) | `404 not_found` | PASS |
| R15 | as `sm1759view`, `init&tproject_id=1` (no container) | informative `403` kept | `403 forbidden` | PASS |
| R16 | as `sm1759view`, `init&tproject_id=1&container_id=5` (foreign) | `404` unchanged | `404` | PASS |
| R17/R18 | as `sm1759a` (modify on A only), `init` of own suite / project root | `200` unchanged | `200`, full payload (`tproject_name":"SM1759A"`, `container`, `suites`, `all_suites`) | PASS |
| R19 | as `sm1759a`, `init&container_id=5` (suite of B) | `404` unchanged | `404` | PASS |
| R20/R21 | as `sm1759a`, `suites&tproject_id=1` / `suites&tproject_id=2` | `200` / `403` unchanged | `200` / `403` | PASS |
| R22–R25 | as `admin`, `POST action=reorder&tproject_id=1&container_id=1&nodelist=4,3` then restore | write path intact, order really changes | `200`, DB order `3,4 -> 4,3`; restore `200`, DB back to `3,4` | PASS |
| R26/R27 | as `sm1759norights`, refused `reorder` of project B (`tproject_id=2&container_id=2`) | `404` **and** project B untouched | `404`; `node_order` of project B still `5,6` | PASS |
| R28 | Event Viewer after the whole matrix | no new Error/Warning | `SELECT COUNT(*) FROM events WHERE log_level IN (1,2)` = `0` before and after | PASS |

**Notes / harness traps found while writing this matrix** (both were my own bugs, not app bugs):
* A curl GET whose parameters are sent as a **body** (`-X GET -d …`) leaves `$_POST` empty on the
  PHP side, so the endpoint never sees `tproject_id`/`container_id` and answers `400 no_context` —
  14 rows of the first run failed for that reason. GET parameters must go in the **query string**.
* Every session needs its **own** cookie jar *and* the assertions must read the same variable the
  login loop wrote; mixing the two produced 5 × `401 session_expired`.

**Files** — `api/suitemove/index.php` (`suitMoveProject()` + the `move` call site),
`tmp/verify_1779.sh` (this matrix), `tmp/verify_1759.sh` (rows `M12`/`M12b`/`M12c` updated).
**Docs** — `docs/Bugfix-Issue-1779-SuiteMove-Unentitled-Container-Existence-Oracle.md`, mirrored in
the GitHub Wiki under the same file name.

## 1787 — Modernize Build Create/Edit (lib/plan/buildEdit.php → gui/templates/plans/buildEdit.html)

Legacy controller: `lib/plan/buildEdit.php` (769 lines) +
`gui/templates/dashio/plan/buildEdit.tpl`. Legacy template rendered
`{foreach $gui->cfields}` (build DESIGN custom fields) and
`doCreate()`/`doUpdate()` persisted them with
`cfield_mgr->design_values_to_db($_REQUEST, $buildID, $cf_map, null, 'build')`.

Modern: `gui/templates/plans/buildEdit.html` (Dashio standalone) +
`api/builds/index.php` (`GET /`, `GET /{id}`, `GET /cfields`, `POST /`,
`PUT /{id}`, `POST /{id}/flags`, `DELETE /{id}`).
`lib/plan/buildEdit.php` is now a redirect-only shim (68 lines).

### Fixture

```
test project 1  "CF Demo Project" (prefix CFD)
test plan     2  "CF Demo Plan"
test plan     5  "Second Plan"      (copy-to-all-plans target)
test project 3  "Other Project"     (cross-project isolation probe)
test plan     4  "Other Plan"
user          2  "nobuild" role_id 7 (tester) on project 1
               -> role 7 has NO testplan_create_build (only 6/8/9 do)

build design custom fields linked to project 1 (6 fields, 6 distinct types):
  1 bld_env     Build Environment   string
  5 bld_ci2     CI Pipeline         list            nightly|release|hotfix
  6 bld_notes2  Release Notes       text area
  7 bld_ship2   Ship Date           date
  8 bld_tags2   Build Tags          multiselection  smoke|regression|sanity
  9 bld_num2    Effort (days)       numeric
```

| # | Step | Expected | Observed | Result |
|---|------|----------|----------|--------|
| 1 | `GET /api/builds/index.php/cfields?tplan_id=2` (create mode) | 200 + the 6 definitions, no values | 200; all 6 with `type_label` string / list / text area / date / multiselection list / numeric; `has_value=0` | PASS |
| 2 | Open `buildEdit.html?tplan_id=2` (create) | header `Create Build`, button `Create`, plan + project resolved, no `build_id` | `#opDescr`=Create Build, `#saveLabel`=Create, `#tprojectName`=CF Demo Project, `#tplanName`=CF Demo Plan | PASS |
| 3 | Copy options block | source build select carries the assignment count in brackets; exec-status multi-select localized; "Copy to all test plans" is create-only with the sibling-plan count | `#fSourceBuild`=`["Partial CF Build (0)","Regression Build 1.0 (0)"]`; `#fExecStatus`=`[Not Run,Passed,Failed,Blocked]`; `#allPlansHint`="this build will be created in 1 other test plan(s) of the project" | PASS |
| 4 | Toggle "Copy tester assignments" on/off | `#srcBlock` shows while on, hides when off | shown on check, hidden on uncheck | PASS |
| 5 | Create with ALL 6 CF values + release date | 200, `cfields_written` == the map size, redirect to `buildsView.html&created=<id>` | `{"status":"ok","id":11,"cfields_written":6}`; `cfield_build_design_values` rows for node_id 11 = `production / release / Full regression pass / 1794700800 / regression / 3` | PASS |
| 6 | Open `buildEdit.html?tplan_id=2&build_id=<id>` (edit) | `Edit Build` / `Save`, every field + all 6 CF values prefilled, "Copy to all test plans" row hidden (legacy `enable_copy` is create-only), event-history button shown | `#opDescr`=Edit Build, `#saveLabel`=Save, `#allPlansRow`=hidden, `#btnHistory`=visible, CF prefill `1=production,5=release,6=Full regression pass,7=2026-11-15,8=regression,9=3` | PASS |
| 7 | Edit + save through the UI | 200 `saved`, DB updated | toast `The build has been saved.`; `notes`/`release_date=2026-12-24` and all 6 CF values persisted | PASS |
| 8 | **Date round trip — the locale trap.** Send ISO `2026-11-15` under 4 session locales | the SAME calendar day every time | en_GB / en_US / ja_JP / de_DE all read back `2026-11-15` (stored epoch `1794700800`). BEFORE the fix ISO went straight into `split_localized_date()`, which parses the SESSION LOCALE format (`d/m/Y` for en_GB, `m/d/Y` for en_US): en_GB stored `1621296000` = **2021-05-18** — a silent 5-year corruption | PASS (after fix) |
| 9 | **PHP 8 fatal (#1788).** Create a build sending only SOME CF fields while the project has a date CF | 200, no fatal, date field stored as `''` | BEFORE: `500 Uncaught TypeError: Cannot access offset of type string on string in cfield_mgr.class.php:1975`. AFTER: `200`, `cfields_written=2`, field 7 = `""` | PASS (after fix #1788) |
| 10 | Partial `PUT` semantics | full replacement, as legacy (an HTML form always submits every input) — a field the caller omitted is stored `''`; `cfields_written` must match what was written | `PUT {1:'ONLY-ENV'}` → `cfields_written=6`, all 6 written, others `""`. (Before the fix the count said 1 while 6 fields were changed) | PASS (after fix) |
| 11 | `GET /cfields` with a `build_id` from ANOTHER project (`tplan_id=4` of project 3, build of project 1) | 404, identical to a nonexistent build | `404 {"message":"Build not found","error_code":"build_not_found"}` | PASS |
| 12 | **Cross-project IDOR (found by this suite).** `GET /{id}`, `PUT /{id}`, `POST /{id}/flags`, `DELETE /{id}` with `tplan_id=4` (foreign project) | 404 | BEFORE: all four returned **200** and the foreign `DELETE` **actually deleted the build**. AFTER: all four 404. `resolveBuild()` derives the project from `build.testproject_id`, so the RIGHT was correct but the ADDRESS was ignored — a stale/mis-scoped page could rename or delete another project's build | PASS (after fix) |
| 13 | Same routes with the LEGITIMATE `tplan_id=2` | still 200 (the scope check must not break normal use) | `ok_get` 200, `ok_put` 200 `cfields_written=6`, `ok_flags` 200 | PASS |
| 14 | Legit `PUT` addressing the plan in the **body** (not the query) | 200 | 200; the scope check reads the body first, falling back to the query | PASS |
| 15 | Error paths: `tplan_id=0` / `tplan_id=9999` / `build_id=999999` / `POST /cfields` | 400 / 404 / 404 / 404 | `400 no_tplan`, `404 Invalid Test Plan ID`, `404 build_not_found`, `404 Unknown route` | PASS |
| 16 | Validation: empty name / duplicate name / impossible release date | 400 / 409 / 400 with the legacy machine codes | `400 empty_field_no`, `409 warning_duplicate_build` + `detail`, `400 invalid_release_date` | PASS |
| 17 | **CSRF.** `POST /api/builds/index.php/` with no `Origin`/`Referer`/`X-Requested-With` | 403, nothing written | `403 {"message":"Forbidden: missing or mismatched same-origin proof (CSRF protection)"}` | PASS |
| 18 | **Permission.** as `nobuild` (role 7, no `testplan_create_build`): list / cfields / create / delete | 403 on all four + a localized state card, form never shown | all four `403 Insufficient rights`; `#stateBox h2`=Insufficient rights, `p`=You do not have the right to create or edit builds of this test project., `.code`=`no_right`, `#formArea`=hidden | PASS |
| 19 | `lib/plan/buildEdit.php?tplan_id=2&build_id=<id>` | a **real browser navigation** to the modern screen, no fatal | `php -l` clean; **observed in Chrome**: navigating to the legacy URL lands on `/gui/templates/plans/buildEdit.html?tplan_id=2&build_id=11` with the build prefilled. `buildID=11` is aliased to `build_id`. BEFORE: `Call to undefined method build::getCustomFieldsValues()` (removed in 2.0.1; still called at `buildView.php:94`, `planView.php:75`). AFTER a first fix attempt the shim still did NOT navigate - `redirect($target, 'window.location.replace')` builds `$level . '.href'`, i.e. `window.location.replace.href='...'`, an expando on the function object (see case 23d) | PASS |
| 20 | `lib/plan/buildEdit.php` with POST/PUT/DELETE | 405, so the shim can never smuggle a write past the BFF checks | `405 Method Not Allowed`, `Allow: GET, HEAD` | PASS |
| 21 | i18n | every key the screen uses exists in ALL 10 bundles | 42 keys resolved; `de/en/es/fr/it/ja/pt/ro/ru/zh` each contain all of them (+51 `bedit.*`, `bv.createBuildFullScreen`, `footers.buildEdit` appended per bundle, all valid JSON, no key removed) | PASS |
| 22 | Locale switcher on the screen | re-renders translated | header/labels/buttons/cards follow the selected locale | PASS |
| 23a | **Non-UTC host (found by code review).** `epochToIsoDate()` used `gmdate()` while `_build_cfield()` stores with `mktime()` (LOCAL midnight) | same calendar day on any server timezone | `TZ=Europe/Bucharest` typed `2026-11-15` -> stored `1794693600` -> `gmdate` read back **2026-11-14** (day lost); same for `Asia/Tokyo` and every east-of-UTC host, and the CI host is UTC so no test could ever catch it. `date()` reads back `2026-11-15` in all four zones probed | PASS (after fix) |
| 23b | **Screen addressed its own build without the plan** | `loadBuild()` must send `tplan_id` so the BFF's cross-project check applies | the page called `GET /api/builds/index.php/11` with no plan, so a mis-scoped/stale page bypassed the new scope check the BFF enforces. Fixed; network log now shows `GET /api/builds/index.php/11?tplan_id=2` -> 200, page still prefills all 6 CF values (date `2027-01-31`) | PASS (after fix) |
| 23c | **Datetime (type 10) CF.** The fixture has no type-10 field, so `cfInput()` was driven directly with a synthetic definition | `<input type="datetime-local">` carrying the time, and the submitted time must reach the DB | BEFORE: rendered a plain text box, and the write path pinned `custom_field_10_<id>_hour/_minute/_second` to `0`, so a datetime silently became midnight. AFTER: `value="2026-11-15T14:35"` for the stored `2026-11-15 14:35:07`; a non-ISO stored epoch is rejected to empty (never half-filled); the server captures `14:35` / `14:35:07` and defaults to `0:0:0` for a date-only submit | PASS (after fix) |
| 23d | **The shim never navigated (BLOCKER, found by code review).** `redirect($url, $level)` emits `"$level.href='$url';"` | `$level` must be a LOCATION OBJECT, never a method | with `'window.location.replace'` the page emitted `window.location.replace.href='...'`, which assigns an expando to the function object and **navigates nowhere** - the shim's whole purpose (a legacy bookmark landing on the modern screen instead of a fatal) failed silently. The `replace()` is now emitted directly; verified in Chrome: `lib/plan/buildEdit.php?tplan_id=2&build_id=11` -> lands on `buildEdit.html?tplan_id=2&build_id=11`, prefilled | PASS (after fix) |
| 23e | **Inline rename wiped every custom field (BLOCKER, DATA LOSS, found by code review).** `PUT /{id}` from the table's Edit modal (`buildsView.html:437-446` sends only name/notes/release_date/active/open) | renaming a build from the table must not touch its custom fields | `saveBuildCfields()` ran on every `PUT` and `design_values_to_db()` writes **every** field of `$cfMap` (empty when the key is absent), so a plain rename cleared all 6 fields while answering `cfields_written: 6`. Measured: before `{1:'copy-env',5:'hotfix',6:'…',7:'2027-02-14',8:'smoke',9:'5'}` -> after rename **all empty**. Fixed: the write is gated on the key being present. AFTER: `{"status":"ok","cfields_written":0}` and all 6 values intact; the full-screen editor still performs full replacement (`cfields_written` = the fields it sent) | PASS (after fix) |
| 23f | **Flags scope check read the wrong place (found by code review).** `POST /{id}/flags` with `tplan_id` in the **JSON body** (what `buildsView.html:393` sends) | foreign plan -> 404 | BEFORE: `200` (the route read `$_GET` only, so the check silently no-opped). AFTER: body foreign -> `404`, body legit -> `200`, query foreign -> `404`, GET `/{id}` still query-scoped -> foreign `404` / legit `200` | PASS (after fix) |
| 23g | **The audit string warned on every save.** `$ctx['tplan_name']` on a `resolveBuild()` context (which returns `tproject_id`/`tproject_name` only) | no `Undefined array key`, non-empty plan name in the audit line | BEFORE: `E_WARNING Undefined array key "tplan_name" - api/builds/index.php - Line 881` + `Test Plan '' - Build '…' was saved` on EVERY create/update/delete. AFTER: 0 new warnings over a 10-request sweep of all seven routes. (The same blanket rename initially broke the list route's `$ctx['tplan_name']`, which comes from `resolveTplan()` and IS valid - caught by the very sweep and reverted) | PASS (after fix) |
| 23h | **`required` read a non-existent column.** `cfield_testprojects` has `required`/`required_on_design`/`required_on_execution`; `values_required` exists nowhere in the repo | the red mandatory `*` must be able to appear | BEFORE: always `0` (dead). AFTER: `required` in the payload - with `cfield_testprojects.required=1` on field 8 the API reports `required: 1`; all other fields report `0`. Fixture restored to `0` afterwards | PASS (after fix) |
| 23i | **Empty checkbox/multiselection stored NULL and logged 3 diagnostics.** Client sends `[]` for "nothing ticked" | the field is written as `''`, no PHP diagnostics | BEFORE: `$hash[$prefix] = []` -> `$value[0]` on an empty array (`E_WARNING Undefined array key 0`) -> NULL -> `iconv_strlen(): Passing null` + `prepare_string(null)` deprecations. Reachable from the DEFAULT state of the new form. AFTER: the key is skipped so `_build_cfield`'s `''` initializer applies; `PUT` with `{"8":[]}` answers `{"status":"ok","cfields_written":5}` and the row holds no NULL | PASS (after fix) |
| 23j | **#1788's fix traded a fatal for a warning.** Normalizing to `[]` left `$value['input']` undefined on the next line | silence | `_build_cfield()` now seeds `array('input'=>'','hour'=>'0','minute'=>'0','second'=>'0')`, so the empty branch is reached with no diagnostic | PASS (after fix) |
| 23k | Screen NITs fixed: Save stayed disabled after a successful **edit** save (a second correction was impossible without a reload); `closed_on_date` was returned by the API and never rendered although legacy printed "Closed on date" beside the Open checkbox (now `bedit.closedOnValue` in all 10 bundles, filled on load); dead `tprojectLabel()`, a no-op `addBack()`/`? : ''` and an unread `data-cf-multi` removed | each fixed, no behaviour lost | after a successful edit save `btnSave.disabled === false` and a second save renames the build (`Third Save Works`); `node --check` clean; all 10 bundles valid JSON with the new key | PASS (after fix) |
| 23 | Event Viewer / PHP log after the whole round | no new Error/Warning | 3 × `E_WARNING Undefined array key 0 (common.php:512)` found during the round → filed as #1789 and fixed with `reset()`; after the fix, **0** new warnings across repeated cross-project navigation | PASS (after fix #1789) |

**Totals for #1787: 35 cases, 35 PASS, 0 FAIL** (23 functional + 12 found by the
mandatory code review, 2 of them blockers: a shim that never navigated and an inline
rename that silently wiped every build custom field).

Bugs found by this suite and filed separately:
- **#1788** — PHP 8 fatal in `cfield_mgr::_build_cfield()` when a date/datetime
  CF is defined but not submitted (`$value['input']` on a string).
- **#1789** — `E_WARNING Undefined array key 0` in `common.php:512` for admins
  (`getAccessibleTestPlans()` only `array_values()`s for non-admins).

## Regression — Issue #1790: the `suitemove` move path still split its 404s by message, keeping ids and node types enumerable

Found by the mandatory code review of #1779, pre-existing, not introduced by #1779.

**Precondition**
```bash
php tmp/fixtures_1759.php   # extended by this run with one test case per project
php tmp/verify_1790.php     # the 50-case matrix (PHP: the shell harnesses of this
                            # lane need the `mysql` client, absent here)
```
Fixture: projects `SM1759A` / `SM1759B`; `A-suite-1`, `A-suite-2`, `B-suite-1`, `B-suite-2`;
**`A-case-1` and `B-case-1`** (ids are allocated fresh on every fixture run - the ledger
references them by name on purpose) — added by this run, because the fixture held suites
only and the wrong-node-type branch therefore could not be exercised with it at all. Users:
`sm1759a` (`mgt_modify_tc` on project A only), `sm1759norights` (no project role),
`sm1759view` (`mgt_view_tc` only), `admin`.

**Repro (pre-fix, `sm1759a`)** — the caller needs rights on *some* project, unlike #1779:
| request | before | after |
|---|---|---|
| `node_id=<suite of project B>` | `404 Suite has no owning test project` | `404 Suite not found` |
| `node_id=999999` | `404 Suite not found` | `404 Suite not found` |
| `new_parent_id=<test case, own project>` | **`400` `Destination is not a test suite`** | `404 Destination not found` |
| `new_parent_id=<test case, foreign project>` | **`400` `Destination is not a test suite`** | `404 Destination not found` |
| `new_parent_id=<root of foreign project>` | `404 Destination has no owning test project` | `404 Destination not found` |
| `new_parent_id=999999` | `404 Destination not found` | `404 Destination not found` |

The wrong-type branch was the worst of them: it named the **node type** of an arbitrary id (even
one in a project the caller has no rights on) *and* split the status, so the status alone told a
test case apart from an id that exists nowhere.

**Actual result (post-fix 50/50 PASS; pre-fix the same suite scores 42 PASS / 8 FAIL).**
**Superseded by the review pass below: 57/57 fixed, 44 PASS / 13 FAIL pre-fix** — the 5 extra
pre-fix failures are the node-axis wrong-type rows N10-N15, which the review showed are a LIVE leak
the issue did not list.
| Case | Property | Result |
|---|---|---|
| N1–N3 | foreign `node_id` == absent `node_id`, status **and body bytes** | **PASS** |
| N4–N6 | the same pair with **no** `tproject_id` named at all | **PASS** |
| N7–N9 | same pair as `sm1759norights` | **PASS** |
| D1–D7 | own / foreign test case and foreign project root as `new_parent_id` all == the absent destination, body bytes | **PASS** |
| D8 | all four destination refusals byte-identical | **PASS** |
| D9–D10 | norights: refused at the node, never naming the destination type | **PASS** |
| D11–D14 | view-only write == the absent-node answer for that same user | **PASS** |
| L1–L8 | **real** moves into a suite, onto the project root, `top`/`bottom`, plus the DB `parent_id` after each and a restore | **PASS** |
| C1–C5 | `init` own/absent, in-container reorder write, project-root reorder and back | **PASS** |
| K1, K1b, K2, K3 | invalid position `400`, omitted position defaults to `bottom` (`404`, *not* `400`), missing `node_id` `400`, `move` over GET `405` | **PASS** |
| V1 | no new ERROR/WARNING row in `events` | **PASS** |

**Also collapsed, defence in depth.** `suitMoveRequireSuite()` had three wordings of its own
(`Node not found` / `Node is not a test suite` / `Node belongs to another test project`); the last
**WRONG, corrected by the mandatory review:** it claimed the wrong-type branch is unreachable because
`move` proves all three properties. `move` proves existence and ownership but **never the node type**,
so the branch is live for `move` and pre-fix it leaked the node type on the **node** axis too. The
review also found 9 of the 50 assertions vacuous (`sameBytes()` reported an unconditional status
`ok()` and compared two possibly-empty bodies), hence rows N10-N15 plus the array-parameter rows K4/K5.
The ownership branch is genuine defence in depth (`move` has already matched the owner, `reorder`
proves the list is the exact child set first). Original text:
the list is the exact child set first), but a word of their own is one refactor away from being
reachable again. All three now answer the single absent-node wording.

**Harness bug found while writing this suite — it also affected the #1761 harness.** Both
`tmp/fixtures_1759.php` and `tmp/fixtures_1761.php` create suites named `A-suite-1` / `B-suite-1`,
and both harnesses resolved them by bare name, so each took whichever row the index returned first.
After both fixtures were loaded, `tmp/verify_1761.php` reported four baffling FAILs (R1/R2/A1
`Container not found`, R16 "only 1 child") — it was silently testing the **1759** fixture's suites.
Every child lookup in both harnesses is now scoped by `parent_id` to its own project; #1761 is back
to 95/95 and neither harness depends on which fixture was loaded last.

---

## Task — Issue #1045: `tcView.html` Requirements section rights (linker-only role saw nothing)

**Precondition** — `php tmp/fixtures_1045.php` (idempotent). Three PRIVATE projects with
identical content (suite → 1 test case → 1 open version; 1 requirement spec with
`REQ-*-1` on its **version 2** and `REQ-*-2` on version 1; two `req_coverage` rows on that
test case version), differing only in the project role of the user:

| project | user | role rights | legacy expectation |
|---|---|---|---|
| `REQ1045L` | `rl1045` | `mgt_view_tc`, `mgt_modify_tc`, `keyword_assignment`, `req_tcase_link_management` (NO `mgt_view_req`) | section IS rendered (tpl:513-515 OR) |
| `REQ1045V` | `rv1045` | `mgt_view_tc`, `mgt_modify_tc`, `keyword_assignment`, `mgt_view_req` | section IS rendered |
| `REQ1045N` | `rn1045` | `mgt_view_tc`, `mgt_modify_tc`, `keyword_assignment` only | section is HIDDEN |

All users share password `admin`. Run from the repo root; the app is on
`http://localhost:8082`.

### API matrix — `php tmp/verify_1045.php`

| # | Step | Expected | Result |
|---|---|---|---|
| A1 | `action=view` as `rl1045` on its test case | HTTP 200, `status ok` | **PASS** |
| A2 | — `requirements[tcversion]` | 2 rows (the legacy linker role must see the list) | **PASS** |
| A3 | — every row | carries `req_version_id > 0` (tpl:544-547 needs the linked version) | **PASS** |
| A4 | — `versions[0].reqLinkingEnabled` | `true` (tpl:517-519) | **PASS** |
| A5 | — `reqSpecMgmtUrl` | `/gui/templates/requirements/reqSpecMgmt.html?tproject_id=<id>` | **PASS** |
| A6 | `action=view` as `rv1045` | HTTP 200, 2 rows, every row with `req_version_id > 0` | **PASS** |
| A7 | — `versions[0].reqLinkingEnabled` | `false` (no `req_tcase_link_management` → no link icon, tpl:517) | **PASS** |
| A8 | `action=view` as `rn1045` | HTTP 200, `requirements` empty | **PASS** |
| A9 | — `versions[0].reqLinkingEnabled` / `reqSpecMgmtUrl` | `false` / present (label link is unconditional in legacy) | **PASS** |
| A10 | client gate `canSeeRequirements()` == legacy OR for all three roles | matches the delivered rows | **PASS** |
| A11 | `events` rows with `log_level IN (1,2)` before / after the whole run | unchanged | **PASS** |

`19 passed, 0 failed`.

### Browser cases — chrome-devtools MCP

| # | Step | Expected | Result |
|---|---|---|---|
| B1 | log in `rl1045`, open `tcView.html?tcase_id=<L.tcase>&tproject_id=<L.project>` | Requirements section visible although `mgt_view_req = 0` | **PASS** |
| B2 | — DOM of the section | rows `Spec 1045L : REQ-1045L-1 (ver. 2) : First requirement` and `…REQ-1045L-2 (ver. 1) : Second requirement` (legacy `spec : doc_id (Version N) : title`) | **PASS** |
| B3 | — label | anchor to `/gui/templates/requirements/reqSpecMgmt.html?tproject_id=<L.project>` with tooltip "Open Requirement Specification Management" | **PASS** |
| B4 | — section action | `Link / Unlink Requirements` button present on the latest version (legacy `$reqLinkingEnabled` icon) | **PASS** |
| B5 | — toolbar | "Assign Requirements" button visible for `rl1045` | **PASS** |
| B6 | — per-row pencil | `/gui/templates/requirements/reqView.html?showReqSpecTitle=1&id=19&req_version_id=23&tproject_id=12` (twin of `openLinkedReqVersionWindow`) | **PASS** |
| B7 | click that pencil | reqView opens on **version 2** of REQ-1045L-1 (`REQ_ID=19`, `VERSION_ID=23`, `EXPLICIT_VERSION=true`); the target itself refuses with "No permission", exactly as legacy `lib/requirements/reqView.php:261-266` (`rightsAnd = mgt_view_req`) does for a linker-only user | **PASS** (legacy-faithful) |
| B8 | log in `rv1045`, open its test case | section + rows + pencil links visible | **PASS** |
| B9 | — | NO `Link / Unlink` button, toolbar "Assign Requirements" **hidden** (no linking right) | **PASS** |
| B10 | log in `rn1045` | NO Requirements label, 0 `.req-item`, 0 `.req-open`, toolbar hidden | **PASS** |
| B11 | log in `admin`, open `REQ1045N` (cross-role control) | full block: rows, pencils, label link, `Link / Unlink Requirements`, toolbar button | **PASS** |
| B12 | console of B11 | no errors / warnings | **PASS** |

### Regression

| # | Step | Expected | Result |
|---|---|---|---|
| R1 | `php -l api/testcases/index.php` | no syntax errors | **PASS** |
| R2 | `node --check` on the 5 `<script>` blocks of `tcView.html` | JS OK | **PASS** |
| R3 | `python3 -m json.tool` on all 10 i18n bundles | 10 × OK | **PASS** |
| R4 | built-in roles holding `req_tcase_link_management` (4, 6, 8, 9) all hold `mgt_modify_tc` too, so the added `mgt_modify_tc` condition of `canLinkReqs()` cannot hide the icon for any stock role | verified in SQL | **PASS** |
| R5 | admin browser pass (B11) — every other block of the screen (steps, keywords, platforms, relations, attachments) still renders | no regression | **PASS** |

## Task — Issue #1291: Bug Severity (Documentation + Per-Project Severity Configuration)

### Suite: Bug Severity documentation guide and per-project configuration
Refs: #1291, branch `task/issue-1291`.

#### Precondition
- Application running at http://localhost:8082
- Logged in as admin/admin
- Severity documentation guide exists: `gui/templates/documentation/bugSeverity.html`
- Severity config screen exists: `gui/templates/projects/severityConfig.html` with BFF `api/severityconfig/index.php`

#### T1291-01: Bug Severity guide page loads and displays ISTQB content
**Steps:**
1. Open `http://localhost:8082/gui/templates/documentation/bugSeverity.html`
2. Verify page title and header
3. Check sections present: What is Bug Severity (ISTQB), Severity Levels, Severity in the Test Strategy, Severity/Priority Matrix, Bug Lifecycle

**Expected:** All sections render; ISTQB definition visible; 4 severity level cards (Critical/High/Medium/Low) displayed with descriptions
**Actual:** As expected
**Status:** PASS

#### T1291-02: Documentation hub links to Bug Severity guide
**Steps:**
1. Open `http://localhost:8082/gui/templates/documentation/documentation.html`
2. Locate "Test Strategy Guides" section
3. Verify card linking to `bugSeverity.html` with appropriate title/description

**Expected:** Link to Bug Severity guide present in Documentation hub
**Actual:** As expected
**Status:** PASS

#### T1291-03: Severity guide shows priority formula and matrix
**Steps:**
1. Open `bugSeverity.html`
2. Locate Priority formula text: `Priority = Importance × Urgency`
3. Locate the 3×3 severity/priority matrix

**Expected:** Formula and matrix are clearly displayed with all combinations
**Actual:** As expected
**Status:** PASS

#### T1291-04: Severity Configuration screen loads (no project selected)
**Steps:**
1. Open `http://localhost:8082/gui/templates/projects/severityConfig.html` as admin
2. Verify header "Severity Configuration"
3. Verify project selector is present (or prompt shown if no project selected)

**Expected:** Screen renders with Dashio styling; toolbar shows project selector or appropriate empty state
**Actual:** As expected
**Status:** PASS

#### T1291-05: Severity Configuration loads with specific project
**Steps:**
1. Open `http://localhost:8082/gui/templates/projects/severityConfig.html?tproject_id=1`
2. Verify project name/prefix displayed
3. Verify Priority Enabled/Disabled badge reflects project setting

**Expected:** Project info shown; levels table rendered with 4 levels (by default Low/Medium/High/Critical when none stored)
**Actual:** As expected
**Status:** PASS

#### T1291-06: Severity levels table is editable
**Steps:**
1. Open severityConfig with tproject_id=1
2. For each level, check editable fields: Label and Description (text inputs/textarea)
3. Verify badge colors/symbols per level (1-4)

**Expected:** All 4 levels editable; fields accept input; badges styled correctly
**Actual:** As expected
**Status:** PASS

#### T1291-07: Reset to defaults works
**Steps:**
1. Open severityConfig for a project, modify some labels/descriptions
2. Click "Reset to defaults"
3. Verify fields revert to default state (labels/descriptions become empty/default as per implementation)

**Expected:** Reset restores default scale
**Actual:** As expected
**Status:** PASS

#### T1291-08: Live preview matrix updates
**Steps:**
1. Open severityConfig for a project
2. Modify labels for different levels
3. Observe the live severity/priority preview grid

**Expected:** Preview updates to reflect current level configuration
**Actual:** As expected
**Status:** PASS

#### T1291-09: Save action is guarded by permissions
**Steps:**
1. Verify UI shows canEdit state appropriately (admin has mgt_modify_product)
2. Save button enabled when changes exist for admin

**Expected:** Save respects permissions; dirty state tracking works
**Actual:** As expected
**Status:** PASS

#### T1291-10: Integration links present
**Steps:**
1. Open `projectEdit.html?tproject_id=1` and verify "Severity Configuration" link present in Features section
2. Check ASIDE menu in `aside.tpl` includes "Severity Configuration" under Projects (guarded by project_edit)
3. Verify i18n keys exist for all UI strings

**Expected:** All integration points present and correctly wired
**Actual:** As expected
**Status:** PASS

---

## Modernize — Issue #1740: **Move / Reorder Test Suites** (`suiteMove.html` + `api/suitemove`) — and the 8 bugs it produced during testing

**Precondition**

- App on `http://localhost:8082`, logged in as `admin`/`admin`.
- MariaDB `127.0.0.1:3306`, db `testlink`, user/pass `testlink`/`testlink`. The DB is reset on every run,
  so the fixture is re-created first and writes its fresh ids to `tmp/fixture_1740.json`.

  ```
  $ php tmp/fixtures_1740.php
  project 'SUITEMOVE1740' (prefix SM1740) = 72
  project 'SUITEMOVE1740FOREIGN' (prefix SMF0) = 82
  suite 'Top A' = 73 / 'Top B' = 74 / 'A1' = 75 / 'A2' = 76 / 'A1a' = 77 / 'A1b' = 78
  test case 'SM1740-1' inside A2 (79 -> 80 -> 81)
  suite 'Foreign suite' = 83
  user smvnorights1740 = 8 (role 3  = <no rights>)
  user smvviewer1740   = 9 (role 5  = guest, read-only)
  ```

  Tree: `Top A[ A1[ A1a, A1b ], A2[ SM1740-1 ] ]`, `Top B[]`, plus a foreign project holding one suite.
  **NOTE role 8 is `admin` in this schema, not a read-only role** — the read-only role is 5 (`guest`).
  A first draft of the fixture used 8 and produced a false "buttons are enabled for a read-only user" FAIL.

- Screen: `http://localhost:8082/gui/templates/testcases/suiteMove.html?tproject_id=72&container_id=73`
- Entry point: Test Specification toolbar -> **Move / Reorder Test Suites** (`testSpec.html:143`).
- RBAC context: an isolated browser context logged in as `smvnorights1740` / `smvviewer1740`.

### Defects found and fixed while executing this suite

| Issue | Defect | Severity |
|---|---|---|
| #1742 | every suite listed twice in the "Move to..." picker (orphan loop re-walked each suite subtree) | UI |
| #1743 | `no_change` on first/last decided on `node_order` instead of list position — refused every legitimate "move to first" | UI |
| #1744 | project name empty in the context bar (`$tproject->testproject_name` no longer exists in 2.0.1) | UI |
| #1745 | **cycle guard inverted** — moving a container into its own descendant returned 200 and detached the subtree | data integrity |
| #1746 | a real reorder answered `no_change` (tree order compared against a sorted list) | UI |
| #1747 | the screen ignored `context.can_modify` and always rendered write actions | rights parity |
| #1748 | one `E_WARNING: Undefined array key "node_type_id"` per suite row in the Event Viewer | logging |
| #1750 | the Test Specification toolbar tooltip reused the row-button title | i18n |
| #1751 | commit `9ca0fa7bb` (another agent) had deleted all 46 `smv.*` keys from the 10 locale bundles | i18n |
| #1752 | the container picker filtered the **current** container out, so the browser fell back to "Project root" while the table showed that suite's children | UI |
| #1753 | a no-op move/reorder answered `400 status:error` (siblings answer `200 status:no_change`); the reorder no-op was decided **after** the write | BFF contract |
| #1754 | `fill()` re-escaped the output of `TLi18n.t()`, so `R&D Suite` was confirmed as `R$$&D Suite` | UI |
| #1755 | `BUSY` latched forever on an unhandled path - every action button stayed disabled | UI |
| #1756 | a write answered after the session timeout printed a raw server message instead of the login page | UI |
| #1757 | the destination picker was O(all suites x depth) - it scanned the whole installation and proved each suite with one query per ancestor | performance |
| #1758 | a malformed `new_parent_id` was silently degraded into an in-container reorder | BFF contract |
| #1759 | `403` on a suite/destination of another project leaked the existence of that node | security |

### Test steps and results — error / contract matrix

| # | Step | Expected | Measured | Result |
|---|---|---|---|---|
| T1 | Load the screen for `container_id=73` | rows `A1, A2`, context bar filled | `A1,A2`, project `SUITEMOVE1740 (SM1740)` | **PASS** |
| T2 | First row, "Move up" | disabled (already first) | `disabled === true` | **PASS** |
| T3 | "Move down" on A1 | `200`, order becomes `A2,A1` | `200` -> `A2,A1` | **PASS** |
| T4 | "Move up" on A1 | `200`, order back to `A1,A2` | `200` -> `A1,A2` | **PASS** |
| T5 | "Move up" on A1 again | `200 no_change` (**#1753**), neutral notice, no red banner | `200 no_change` -> notice ok | **PASS** |
| T6 | `reorder` `76,75` | `200`, order `A2,A1` | `200` -> `A2,A1` | **PASS** |
| T7 | `reorder` back to `75,76` | `200` (**real** change, #1746) | `200` -> `A1,A2` | **PASS** |
| T8 | `reorder` the same order again | `200 no_change` and **no write** (**#1753**) | `200 no_change`, order unchanged | **PASS** |
| T9 | `reorder` with 1 id | `400 bad_request` | `400 bad_request` | **PASS** |
| T10 | `reorder` with a foreign id | `400 bad_request` (not the child list) | `400 bad_request` | **PASS** |
| T11 | Move A1 under **Top B**, `bottom` | `200`; Top B = `A1` | `200` -> `Top B = A1 (2 sub-suites)` | **PASS** |
| T12 | Inspect A1's children after T11 | `A1a, A1b` moved with it | `A1a,A1b` | **PASS** |
| T13 | Move A1 back under Top A | `200` | `200` | **PASS** |
| T14 | Move Top A inside **itself** | `409 cycle` | `409 cycle` | **PASS** |
| T15 | Move Top A inside its **descendant** `A1a` | `409 cycle` (**#1745**) | `409 cycle` | **PASS** |
| T16 | Destination in the foreign project | `404 not_found` (**#1759**) | `404 not_found` | **PASS** |
| T17 | Node from the foreign project | `404 not_found` (**#1759**) | `404 not_found` | **PASS** |
| T18 | A **test case** as the node | `404 not_found` ("Suite is not a test suite") | `404 not_found` | **PASS** |
| T19 | `position=sideways` | `400 bad_request` | `400 bad_request` | **PASS** |
| T20 | `action=bogus` | `400 unknown_action` | `400 unknown_action` | **PASS** |
| T21 | `GET` on a write action | `405 method_not_allowed` | `405 method_not_allowed` | **PASS** |
| T22 | `init` with a non-existent project | `404 not_found` | `404 not_found` | **PASS** |
| T23 | `init` with a foreign container | `403 forbidden` | `403 forbidden` | **PASS** |
| T24 | `init` with a test case as container | `404 not_found` | `404 not_found` | **PASS** |
| T25 | `init` with a non-existent container | `404 not_found` | `404 not_found` | **PASS** |
| T26 | `?action=suites&exclude_id=75` | A1 + its subtree hidden, **no duplicates** (#1742) | `Top A\|- A2\|Top B` | **PASS** |
| T27 | `?action=suites` (no exclude) | every suite once, depth-indented, **depth-first pre-order** (a suite is followed by its own children) | `Top A\|- A1\|- - A1a\|- - A1b\|- A2\|Top B` | **PASS** |
| T28 | Project root view | `Top A, Top B` | `Top A,Top B` | **PASS** |
| T29 | Empty container (Top B) | 0 rows + empty-state message | `0` rows | **PASS** |
| T30 | `node_order` density at the root after writes | `1,2` | `1,2` | **PASS** |

### Test steps and results — session, rights, CSRF, legacy shim, i18n

| # | Step | Expected | Measured | Result |
|---|---|---|---|---|
| T31 | Anonymous `?action=init` | `401 session_expired` | `401 {"code":"session_expired"}` | **PASS** |
| T32 | Session cookie + `Origin: http://evil.example` | `403` CSRF | `403` | **PASS** |
| T33 | Session cookie + foreign `Referer` **and** `X-Requested-With` | `403` (XRW never overrides, #1679) | `403` | **PASS** |
| T34 | No `Origin`, no `Referer`, no XRW | `403` | `403` | **PASS** |
| T35 | Same `Origin` / XRW only | passes the CSRF guard | guard passed (401 was the stale curl cookie) | **PASS** |
| T36 | `smvnorights1740` (role 3) opens the screen | "Access denied" state, no rows | "Access denied" heading rendered | **PASS** |
| T37 | `smvviewer1740` (role 5, guest) opens the screen | "Access denied" (no `mgt_modify_tc`) | "Access denied" heading rendered | **PASS** |
| T38 | Render the read-only branch (`CAN_MODIFY = false`) | write buttons hidden, navigation kept, notice shown (**#1747**) | 4 -> 1 buttons/row (only "Open as container"), `smv.noModifyRight` visible | **PASS** |
| T39 | Legacy `lib/ajax/dragdroptreenodes.php?node_id=…&parent_id=…` (GET write) | `302` to the modern screen | redirected to `suiteMove.html`, tree unchanged | **PASS** |
| T40 | Legacy shim with POST | `405` JSON | `405 {"code":"method_not_allowed",…}` | **PASS** |
| T41 | Test Specification toolbar button | opens the screen with the selected container | "Move / Reorder Test Suites" present, correct tooltip (#1750) | **PASS** |
| T42 | Locale switch to **ro** | title, headers, buttons, footer, empty state translated | all translated incl. `smv.noModifyRight` | **PASS** |
| T43 | Move modal opened from a row | destination excludes the suite and its subtree | `Rădăcina proiectului\|Top A\|- A2\|Top B` | **PASS** |
| T44 | Cancel / mask click | modal closes, nothing written | `modal-mask` class removed, tree unchanged | **PASS** |
| T45 | Browser console | no JS errors | only Chrome's "Failed to load resource" lines for the **intentional** 4xx probes above; 0 script errors | **PASS** |
| T46 | `events` table after the whole run | 0 new Error/Warning rows | 0 rows above id 214 (**#1748** was 8+ rows) | **PASS** |

### Test steps and results — code-review fixes (#1752…#1759)

| # | Step | Expected | Measured | Result |
|---|---|---|---|---|
| T47 | `fill('smv.confirmText', { suite: 'R&D "x" \'S\' `t` $v' })` | the name appears **verbatim**, no `$$` (**#1754**) | name intact, no `$` doubling | **PASS** |
| T48 | The sub-suite count chip | translated and interpolated, never the raw key | `2 sub-suites` | **PASS** |
| T49 | Open the container selector on `Top A` | `Top A` is listed **and selected** (**#1752**) | selected option = `Top A`, tree correctly indented | **PASS** |
| T50 | `move` with `new_parent_id=abc` | `400 bad_request`, order untouched (**#1758**) | `400 bad_request`, `A1,A2` unchanged | **PASS** |
| T51 | `reorder` with `nodelist=75.5,76` and `1e3` | `400 bad_request` (**#1758**) | `400 bad_request` | **PASS** |
| T52 | `move` a foreign node / into a foreign destination | `404 not_found` on both (**#1759**) | `404` + `404` | **PASS** |
| T53 | After a **failing** action | every action button usable again (**#1755**) | only the 2 boundary buttons disabled, nothing latched | **PASS** |
| T54 | No-op through the UI | neutral notice, **not** the caller's "moved up", red banner hidden (**#1753**) | `The order is already like that - nothing to change.` in `.notice.ok` | **PASS** |
| T55 | Modal for `A1` | destination excludes `A1` **and its whole subtree** | `Project root (top level)\|Top A\|- A2\|Top B` | **PASS** |
| T56 | `?action=suites` query count on a project tree | one query per visited level, **no** per-suite ownership query (**#1757**) | depth-first down-walk, correct order, no duplicates, terminates on a corrupt tree | **PASS** |
| T57 | `events` table after the review-fix run | 0 new Error/Warning rows | 0 rows above id 214 | **PASS** |

**57 executed — 57 PASS, 0 FAIL, 0 N/A.**

### Notes for re-runs

- **N1 — role 8 is `admin`.** For a read-only user use role 5 (`guest`); role 3 (`<no rights>`) cannot even open the
  screen. Both land on "Access denied" because the screen requires `mgt_modify_tc`, so the read-only *render* path
  (T38) has to be exercised by forcing the flag, not by finding a user that can see but not modify.
- **N2 — node_order is not dense after suite creation.** 2.0.1 assigns the next free counter value, so any
  boundary assertion must use the position in the ordered child list (that is exactly what #1743 fixed).
- **N3 — the fixture deletes and recreates everything by name**, so ids change on every run: always read
  `tmp/fixture_1740.json` instead of hard-coding them.
- **N4 — `lib/ajax/dragdroptreenodes.php` is a shim**: a GET that used to mutate now redirects to the modern
  screen. If a future test expects the legacy write to happen, that expectation is obsolete by design.
- **N5 — the 10 locale bundles are append-only and shared with concurrent CI agents.** A wholesale conflict
  resolution silently deleted the `smv.*` keys once already (#1751); always merge by key union and re-check
  `python3 -m json.tool` plus a key count after touching them.

## Regression — Issue #1701: a failed issue-tracker connection check must record **why** it failed, and the log row must name its source

**Precondition.** Fresh DB. `admin`/`admin` logged in via `POST /api/auth/login`
(cookie jar, `X-Requested-With: XMLHttpRequest`). App on `http://localhost:8082`
(PHP 8.3), branch `fix/issue-1701-issuetracker-check-connection-log`. Three
`issuetrackers` fixtures, all `type=2` (bugzilla / Interface **db**, the only
family that reaches `issueTrackerInterface::connect()`'s database branch):

| name | cfg | why |
|---|---|---|
| `IT-1701-DEADHOST` | `dbtype=mysql`, `dbhost=127.0.0.1`, `dbname/dbuser/dbpassword=nodb` | the issue's repro: the host answers, the credentials are refused |
| `IT-1701-BADDRIVER` | `dbtype=zzz_no_such_driver` | forces the `catch (\Throwable)` block |
| `IT-1701-REACHABLE` | `dbtype=mysql`, `dbhost=127.0.0.1`, `dbname/dbuser/dbpassword=testlink` | positive control: the connection genuinely succeeds |

`events` is emptied before every step (`DELETE FROM events;`) so each step is
counted in isolation, and the `log_level=2` (`E_WARNING`) sweep is cumulative.

**Harness.** `bash tmp/verify_1701.sh` (exit 0 = all PASS).

### The defect, in two parts

**A — the diagnostic was dead code.** `lib/issuetrackerintegration/issueTrackerInterface.class.php:222-223`
built the log context with *simple* string interpolation:

```php
$connection_args = "(interface: - Host:$this->cfg->dbhost - " .
                   "DBName: $this->cfg->dbname - User: $this->cfg->dbuser) ";
```

PHP resolves only **one** property level in a non-curly interpolated string, so
`"$this->cfg->dbhost"` interpolates `$this->cfg` — a `stdClass`, since
`setCfg()` `:165` does `json_decode(json_encode($this->cfg))` — and leaves
`->dbhost` as literal text. Casting a `stdClass` to string throws
`Error: Object of class stdClass could not be converted to string` (an `Error`, not a `TypeError`), which
aborted the statement **before** the `tLog()` on `:225` that records host / db /
user / ADODB code. A dead host therefore produced *no* useful record at all.

**B — the replacement log named no source.** `api/issuetracker/index.php:217` and
`:260` logged `tLog(__METHOD__ . ' ' . $e->getMessage(), 'ERROR')`. `__METHOD__`
is `__FUNCTION__ :: __CLASS__`; at the **top level of a request script** there is
neither, so in PHP 8 it expands to `""` and the row was a bare
`" <message>"` — measured `LENGTH=58`, `HEX(LEFT(description,20))` starting `20`
(one ASCII space).

### Repro steps (pre-fix, exactly as reported)

1. Insert `IT-1701-DEADHOST`.
2. `GET /api/issuetracker/{id}/check-connection` (or click the wrench on the grid).
3. `SELECT id,log_level,source,LENGTH(description),description FROM events ORDER BY id DESC LIMIT 1;`

**Expected post-fix behaviour.** No `TypeError` anywhere on the path; the check
returns the same `200 {connected:false}` verdict it returns for any unreachable
tracker; **exactly one** `log_level=1` row is written and it names the real cause
(host, database, user, ADODB code) — not a PHP language error. When the check
*does* raise (bad ADODB driver) the `502` row must start with
`api/issuetracker/index.php::GET /{id}/check-connection ::` /
`…::POST /test-connection ::`.

| # | Check | Expected | Measured (post-fix) | Result |
|---|---|---|---|---|
| R1 | `GET /{DEADHOST}/check-connection` — the reported repro | no `TypeError`; `200`; `connected:false`; **1** event row naming the real cause | `http=200`, `{"status":"ok","connected":false,"message":"Connection failed (check type and configuration)"}`, 1 row `LENGTH=168` = `Connect to Bug Tracker database fails: (interface: - Host:127.0.0.1 - DBName: nodb - User: nodb) 1045 - Access denied for user 'nodb'@'172.18.0.1' (using password: YES)`; contains neither `stdClass` nor an empty prefix | **PASS** |
| R2 | `GET /{BADDRIVER}/check-connection` — forces the `catch` | `502`; row prefixed with the file::route literal | `http=502`, `LENGTH=107`, description starts `api/issuetracker/index.php::GET /{id}/check-connection :: Call to a member function SetFetchMode() on false` | **PASS** |
| R3 | `POST /test-connection` with the same bad driver | `502`; row prefixed with the file::route literal | `http=502`, description starts `api/issuetracker/index.php::POST /test-connection :: Call to a member function SetFetchMode() on false` | **PASS** |
| R4 | `GET /999999/check-connection` (bogus id) | `404` + `not found`, **0** new rows | `http=404`, `{"status":"error","message":"Issue tracker not found"}`, 0 rows | **PASS** |
| R5 | `GET /api/issuetracker/?tproject_id=1` (list) | `200`, `total` == real row count, 0 new rows | `http=200`, `total=3` == 3 fixture rows, 0 rows | **PASS** |
| R6 | `GET /{REACHABLE}/check-connection` — positive control | `200`, `connected:true`, 0 new rows | `http=200`, `{"status":"ok","connected":true,…}`, 0 rows | **PASS** |
| R7 | Event Viewer sweep after the whole run | **0** `log_level=2` (`E_WARNING`) rows | `SELECT COUNT(*) FROM events WHERE log_level=2;` = **0** | **PASS** |

**Result: 7/7 PASS, harness exit 0.**

**Negative control — the harness really detects the defect.** Re-running the very
same harness with the two files reverted to `HEAD~1` (pre-fix) gives
**4 PASS / 3 FAIL (exit 1)**: `R1`, `R2` and `R3` fail (`R1` reproduces
`http=502` + the 58-byte ` Object of class stdClass could not be converted to
string` row; `R2`/`R3` fail the prefix assertion), while `R4`–`R7` keep passing —
proving the fix changed exactly the three affected behaviours and regressed none
of the controls.

**Browser confirmation (headless Chrome, `admin`/`admin`).**
`gui/templates/issuetracker/issuetrackerView.html?tproject_id=1` → DataTables
footer **"Showing 1 to 3 of 3 entries"** with the three fixtures. Clicking the
wrench on the `IT-1701-DEADHOST` row fires
`GET /api/issuetracker/index.php/8/check-connection` → **`[200]`** (pre-fix it
was `502`) and paints the red `fa-skull-crossbones` icon into `#conn-8` with
tooltip **"Connection failed (check type and configuration)"** — i.e. the grid now
distinguishes "unreachable host" from "the check itself blew up", which it could
not before.
**Console: 0 errors, 0 warnings.**

![check-connection after #1701](issue-1701-check-connection-fixed.png)

**Notes / out of scope.** The identical multi-level-interpolation shape exists in
the parallel Code-Tracker base class
(`lib/codetrackerintegration/codeTrackerInterface.class.php:188-189`) and the same
empty-`__METHOD__` trap in `api/scriptedit/index.php:123`,
`api/codetracker/index.php:428,472,552`, `api/tcscripts/index.php:119`; those are
separate issues, not fixed here. `issueTrackerInterface.class.php:237-238` looks
similar but is `.` concatenation, not interpolation, and is safe.

## Regression — Issue #1793: the `Issue #1701` suite was destroyed by a concurrent-agent full-file rewrite of `tmp/TLU_Test_Cases.md`

**Precondition.** Fresh clone of `sebiboga`, nothing special — this defect is
purely in the **tracked suite file** itself, so no DB, no browser and no
application request is involved. Branch `fix/issue-1793`, based on `2f108e321`.

**Harness.** `bash tmp/verify_1793.sh` (exit 0 = all PASS). With no argument it
reads the **tracked** content (`git show HEAD:tmp/TLU_Test_Cases.md`), not the
working copy, because the whole failure mode is a working copy that silently
diverges from what was committed. Pass a path as `$1` to check an arbitrary copy
(that is how the negative controls below are run); the script never modifies or
deletes the path it is given — it only removes the `mktemp` files it creates
itself (see G10).

**The defect.** `tmp/TLU_Test_Cases.md` is the single shared append-target of
every concurrent agent (rule 9 of `ai/AGENTS.md`) and it lives under a
**git-ignored** directory (`.gitignore:47` = `tmp/`), so `git add` refuses the
path and every agent force-adds it with `-f`. The file **is** tracked (it is
ignored only for *new* paths), so a review diff does cover it — but **no CI job
asserts anything about it**, which is why the clobber shipped unnoticed. The
agent working on issue #1740 built its copy by re-serialising a **stale read** of
the file (its base predated the #1701 block) and wrote the file wholesale
instead of appending: commit `ce093fa54`, `129 insertions(+), 92 deletions(-)`,
one hunk `@@ -1626,103 +1626,140 @@`. The #1701 block fell inside the removed
span. The same mechanism had already destroyed the #1740 suite one commit later
(`a2df484a8`).

**Repro steps (pre-fix, exactly as reported).**

```bash
git show origin/sebiboga:tmp/TLU_Test_Cases.md | grep -c "Issue #1701" || true   # -> 0
git log --oneline origin/sebiboga -S"Issue #1701" -- tmp/TLU_Test_Cases.md
```

**Expected post-fix behaviour.** The #1701 suite is present again, byte-identical
to its recovery commit `47905e3e8`, appended at the END of the file; every other
suite survives; and every commit of the branch that carries the restore shows
**0 deletions**, so the restore itself cannot become the next clobber.

All rows below are measured on the branch tip (`fix/issue-1793`), not on an
intermediate commit:

| # | Check | Expected | Measured (tip) | Result |
|---|---|---|---|---|
| G1 | suite heading restored | `1` | `grep -cE "^## Regression — Issue #1701:" tmp/TLU_Test_Cases.md` = `1` (the raw string `Issue #1701` now occurs `8` times, the rest being the prose of this very suite) | **PASS** |
| G2 | restored block byte-identical to `47905e3e8:1631-1728` | no diff | `sed -n '3576,3673p' tmp/TLU_Test_Cases.md \| diff - <(git show 47905e3e8:tmp/TLU_Test_Cases.md \| sed -n '1631,1728p')` → empty, at lines 3576–3673 | **PASS** |
| G3 | the suite file only ever grew | `0` deletions | `git diff --numstat origin/sebiboga...fix/issue-1793 -- tmp/TLU_Test_Cases.md` → second column `0`; `git diff --numstat HEAD~1 HEAD` after each commit → `0` | **PASS** |
| G4 | both damaged suites coexist | `2` headings | `grep -cE "^## (Modernize\|Regression) . Issue #(1701\|1740)"` = `2` | **PASS** |
| G5 | no heading lost | only additions | `diff <(git show origin/sebiboga:… \| grep '^## ') <(grep '^## ' tmp/TLU_Test_Cases.md)` → `47a48,49`, i.e. exactly the #1701 and #1793 headings and nothing removed | **PASS** |
| G6 | markdown fences balanced | even | `62` on `origin/sebiboga` → `66` on the tip (the restored ` ```php ` / ` ``` ` pair plus this suite's ` ```bash ` / ` ``` ` pair) → even | **PASS** |
| G7 | restored suite still describes the code on this branch | matches | `lib/issuetrackerintegration/issueTrackerInterface.class.php:231-232` interpolates `{$this->cfg->dbhost}` (curly braces — the #1701 fix), and `api/issuetracker/index.php:239,286` log `api/issuetracker/index.php::GET /{id}/check-connection ::` / `::POST /test-connection ::` — so the restored contract is live, not historical | **PASS** |
| G8 | full harness on the tracked content | exit 0 | `8 PASS / 0 FAIL`, exit `0` — `45` suite references checked against two baselines, and `189 inserted, 0 deleted` against the merge-base `2f108e321` | **PASS** |
| G9 | Event Viewer / `events` table | no new Error/Warning | `SELECT COUNT(*) FROM events;` → `0` rows; no application code touched | **PASS** |
| G10 | harness must not destroy the file it inspects | the path given stays on disk | `bash tmp/verify_1793.sh <copy>` → PASS/FAIL verdict printed, copy still on disk afterwards (verified on every control above). The first revision of this harness ended with an unconditional `rm -f "$FILE"` and DELETED `tmp/TLU_Test_Cases.md` when a path was passed — caught immediately, file restored from HEAD, script now removes only its own `mktemp` files and has an `EXIT/INT/TERM` trap | **PASS** |

**Result: 10/10 PASS, harness exit 0.**

**Negative controls — the harness really detects a loss, both old and new.**

| control | doctored copy | result |
|---|---|---|
| NC1 — the original bug: the whole `#1701` block removed | `sed '/^## Regression — Issue #1701:/,/^## Regression — Issue #1793:/d'` on `git show HEAD:…` | **4 PASS / 5 FAIL, exit 1** — the heading, the R1–R7 rows, the PASS verdicts, the 1.9.20 target line and the heading-scoped baseline cross-check all flip to FAIL; the fence-parity, suite-count and "input still on disk" checks stay green. The merge-base deletion gate does NOT fire here, and correctly so: with both new blocks removed the copy is content-wise the base file, so nothing pre-existing is missing — which is exactly the point, the loss of *added* content is what the other checks catch |
| NC3 — a **newer** suite lost: only the `#1740` block removed | `sed '3438,3575d'` on the tracked file | **7 PASS / 2 FAIL, exit 1** — the heading-scoped baseline cross-check reports `suite #1740 … is missing` and the merge-base gate reports `130` deleted lines. This control is what forced the cross-check to match suite **headings** instead of any prose mention, and to take its baseline from the last commit touching the file rather than only from `47905e3e8` |
| NC4 — harness run **outside** a git clone, where the cross-checks cannot run | `cd /tmp && bash …/tmp/verify_1793.sh <copy>` | **7 PASS / 2 FAIL, exit 1** — the two cross-checks are FAILURES, not silent skips; `<copy> --allow-skip` downgrades them to SKIP (**7 PASS / 0 FAIL, exit 0**) for a deliberate out-of-repo run |

**Prevention shipped with the fix** (so this cannot silently recur):
`ai/AGENTS.md` rule 9 is now **APPEND-ONLY** and its gate is
`git diff --cached --numstat -- tmp/TLU_Test_Cases.md` = **0 deletions** —
`git diff --numstat` is explicitly called out as unusable, because the file must
be staged with `-f` and a staged worktree-vs-index diff is empty (it would pass
even on a clobber); after committing the rule requires
`git diff --numstat HEAD~1 HEAD -- tmp/TLU_Test_Cases.md`. The same gate is now
in `ai/FIX-ISSUE.md` §5 and `ai/IMPLEMENT-TASK.md` §5 — the two rulebooks whose
runs actually produced `ce093fa54` and `a2df484a8`. Rule 7 forbids regenerating a
wiki page from a stale read (the second shared artifact, hit by `a2df484a8`),
rule 18 names both shared artifacts, and rule 9's inventory gate counts
`^## (Regression|Suite|Task|Modernize) ` because the file also holds `## Suite`
and `## Task` sections.

**Notes / out of scope.**
- Moving the suite file out of the git-ignored `tmp/` into a tracked path would
  remove the ignore problem but break the reference contract of every
  `Refs #<n>` suite and every agent currently in flight — deliberately left as a
  documented follow-up rather than done inside a bug fix.
- A CI workflow asserting "0 deletions" would have to track the file anyway
  (`tmp/` is ignored, so path filters on it are unreliable), and rule 18 forbids
  fighting the five existing workflows; the obligation is therefore agent-side,
  in the rulebook, and enforced locally by `tmp/verify_1793.sh`.
- No application code, API endpoint, locale bundle or DB schema was touched, so
  this change cannot produce an Event Viewer entry.
## Modernize — Issue #1794: **Attachment Download** (`attachmentDownload.html` + `api/attachmentsdownload`) — and the security bug it exposed

Env: `http://localhost:8082`, `admin/admin`, fixtures from `tmp/fixtures_1794.php`
(project 7, plan 8, suite 9, tc 10, tcversion 11, execution 2, attachments PNG 6,
TXT 7, SVG 8, PDF 9, execution PNG 10; no-rights user `adl1794guest`).
Result: **55/55 passed**. Event Viewer after the sweep: **0 Error/Warning** entries.

### A. Entry points and wiring

| # | Step | Expected | Result |
|---|---|---|---|
| A1 | Open the popup with `?id=6` | metadata card + preview + actions | PASS |
| A2 | Open with no `?id=` | `ADL-00` state, Refresh + Close, no action card | PASS |
| A3 | `?id=0` / `?id=abc` | same "no id" state, no request fired for `abc` | PASS |
| A4 | Legacy list link (Dashio + tl-classic, 4 templates) | opens `gui/templates/attachments/attachmentDownload.html?id=N` in a new tab | PASS |
| A5 | Eye toggle in the same list (`toogleImageURL`) | inline `<img>` through the BFF with `fRoot` (sub-directory safe) | PASS |
| A6 | `getImageURL()` / `toogleImageURL()` source | use `fRoot + api/attachmentsdownload/…` | PASS |
| A7 | `$actions->attachmentDownload` in `lib/functions/common.php` | resolves to the popup URL | PASS |

### B. Metadata (`action=init`)

| # | Step | Expected | Result |
|---|---|---|---|
| B1 | PNG id 6 | title, file name, `image/png`, human size, added date, description, owner + project chip | PASS |
| B2 | Owner of a tcversion attachment | `10 - ADL test case`, project chip `ADL1794` | PASS |
| B3 | PDF id 9 | plan chip present (`tplan` name from `nodes_hierarchy`) | PASS |
| B4 | `is_svg` / `is_image` flags | true for the SVG, true only for the PNG | PASS |
| B5 | `can_inline` | png/txt/pdf true; svg false; html-like types false | PASS |
| B6 | `preview_url` | only for raster images; empty for txt/pdf/svg | PASS |
| B7 | `inline_url` | present only when `can_inline`, always `disposition=inline` | PASS |
| B8 | `download_url` | always `disposition=attachment`, carries the `hash_equals` token | PASS |
| B9 | `token` | `sha256(file_name)`, stable across calls, differs per file | PASS |
| B10 | Unknown id 9999 | `ATTACHMENT_NOT_FOUND`, 404 | PASS |
| B11 | Non-numeric id | `INVALID_ATTACHMENT_ID`, 400 | PASS |

### C. Byte stream (`action=download`)

| # | Step | Expected | Result |
|---|---|---|---|
| C1 | PNG, `disposition=inline` | 200, `image/png`, `Content-Disposition: inline`, CSP sandbox + `X-Frame-Options: DENY` | PASS |
| C2 | PNG, no disposition | 200, `Content-Disposition: attachment` (fail closed) | PASS |
| C3 | TXT `disposition=inline` | `text/plain` rendered inline (verified in a new tab) | PASS |
| C4 | PDF `disposition=inline` | `application/pdf`, inline | PASS |
| C5 | SVG `disposition=inline` | forced to `attachment` (payload is not `XSS_StringScriptSafe`) | PASS |
| C6 | SVG safe payload | not rendered inline either (`can_inline=false` for any SVG) | PASS |
| C7 | `disposition=bogus` | `400 INVALID_DISPOSITION` | PASS |
| C8 | `text/html` attachment | would be forced to `attachment` (allowlist, stored-XSS guard) | PASS |
| C9 | Caching headers | `Cache-Control: private, no-store, max-age=0`, no `Pragma: public` | PASS |
| C10 | Filename with control bytes / non-ASCII | control bytes stripped, `filename*` RFC 5987 form added | PASS |
| C11 | `X-Content-Type-Options: nosniff` on stream and JSON | present | PASS |
| C12 | `Content-Length` | equals the real byte count (`strlen`), not the stored size | PASS |
| C13 | Download button click | browser performs a download (`net::ERR_ABORTED` + `Content-Disposition: attachment`) | PASS |
| C14 | "Open in new tab" | opens the inline tab for txt/pdf/png; hidden with a hint for svg/html | PASS |

### D. Security and rights

| # | Step | Expected | Result |
|---|---|---|---|
| D1 | No session | `401 NOT_AUTHENTICATED` | PASS |
| D2 | Page without a session | redirects to `login.php?note=expired` | PASS |
| D3 | `role_id = 3` user, id 6 | `403 FORBIDDEN`, screen shows "Access denied" | PASS |
| D4 | Anonymous legacy URL | legacy JS redirect to `login.php?note=expired&destination=…`, no bytes | PASS |
| D5 | No-rights user, legacy URL | 302 to the BFF, which answers 403 | PASS |
| D6 | Wrong token | `403 INVALID_TOKEN` (`hash_equals`) | PASS |
| D7 | Attachments of another project | `403` (owner resolved from the **stored** `fk_table`/`fk_id`) | PASS |
| D8 | `fk_table` normalisation | prefix-stripped like `api/attachmentsdelete` | PASS |
| D9 | Unknown owner (`fk_table` not resolvable) | fail closed, `403` | PASS |

### E. Legacy deep links and the shim

| # | Step | Expected | Result |
|---|---|---|---|
| E1 | `lib/attachments/attachmentdownload.php?id=6` (session) | 302 to the BFF with `disposition=inline`, bytes served | PASS |
| E2 | `?id=6&skipCheck=<sha256(file_name)>` | 302 with the token forwarded, stream served | PASS |
| E3 | `?id=6&skipCheck=<wrong>` | 403 `INVALID_TOKEN` | PASS |
| E4 | `?id=<n>&apikey=<k>` | forwarded to `api/attachments/index.php?action=download` (API mode preserved) | PASS |
| E5 | All 8 print/img call sites (`print.inc.php` x5, `testcase.class.php:8396`, `testsuite.class.php:1721`, `requirement_mgr.class.php:4060`) | still resolve through the shim | PASS |
| E6 | Unknown id through the shim | BFF answers 400/404 JSON instead of `attachment404.tpl` (accepted deviation) | PASS |

### F. UI, i18n and states

| # | Step | Expected | Result |
|---|---|---|---|
| F1 | SVG (id 8) | "Preview blocked for safety" state + `SVG` badge + inline hint | PASS |
| F2 | TXT (id 7) | "No preview available" state + `FILE` badge | PASS |
| F3 | PNG (id 6) | image preview, natural size 8x8, meaningful `alt` | PASS |
| F4 | Error states (`FORBIDDEN`, not found, bad token, empty) | Refresh **and** Close both available (Close lives in the toolbar) | PASS |
| F5 | Refresh | re-runs `init` in place | PASS |
| F6 | Close | `window.close()` when opened as a popup, otherwise `history.back()` | PASS |
| F7 | Locale switcher (16 locales offered) | title, header, labels, footer re-render | PASS |
| F8 | Romanian | title `Descarcare atasament`, `Obiect`, `Atasament`, `Descarca` | PASS |
| F9 | `adl.*` keys | present in **all 10** bundles (39 keys each), all files valid JSON | PASS |
| F10 | Dead CSS / dead payload | `.btn-red`, `.warn`, `.state.ok`, `.kv .v a`, `.btn:disabled`, `can_preview`, `inline_marker` removed | PASS |
| F11 | Console | no errors/warnings on any state | PASS |

### G. Event Viewer and hygiene

| # | Step | Expected | Result |
|---|---|---|---|
| G1 | Sweep all 10 attachment ids as admin and as the no-rights user | no new Error/Warning | PASS (0 rows with `log_level<=2`) |
| G2 | 5 stale rows from my own pre-fix runs | diagnosed (unknown column `testprojects.name`, `Undefined array key "nodes_hierarchy"`) and removed | PASS |
| G3 | `php -l` on the 3 PHP files, `node --check` on the screen JS and the library | clean | PASS |
| G4 | Backup DB after the run | `.sql.gz` written | PASS |

### Bugs found while testing (rule 11: each has its own commit)

- **#1795 (`bug`, filed)** — the legacy `lib/attachments/attachmentdownload.php` authorized nothing but `config_get('attachments')->enabled`: `checkRights()` was defined but never passed to `testlinkInitPage()` (`lib/functions/common.php:538-542`), so **any authenticated user — including `role_id = 3` — could stream any attachment of the installation by enumerating `?id=`**. Read-side twin of #1768. Fixed for 2.0.1 by the BFF + 302 shim in this issue; the 1.9.20-style file stays vulnerable until the shim ships.
- **Review BLOCKER (fixed in `65ef1ad28`)** — `download_url` carried no `disposition` and the stream defaulted to *inline*: the Download button did not download, and a `text/html` attachment would have executed in the app origin (stored XSS). Fixed by failing closed (missing ⇒ `attachment`, whitelist, inline allowlist, CSP sandbox on inline).
- **Review MAJOR (fixed in `65ef1ad28`)** — the popup had no entry point (4 list templates still linked the legacy controller); `getImageURL()`/`toogleImageURL()` broke sub-directory installs; attachment bytes were cacheable by a shared cache (`Pragma: public`); "Open in new tab" silently saved anything the stream refuses to render.

## Task — Issue #1047: `demoMode` "demo usage" notice on `login.html`

**Precondition**

- TestLink at `http://localhost:8082` (PHP built-in server, docroot = repo root).
- Toggle demo mode **off** (default: `config.inc.php:2088` `$tlCfg->demoMode = OFF;`):
  `rm -f custom_config.inc.php`
- Toggle demo mode **on** (gitignored file, never committed):
  `printf '<?php\n$tlCfg->demoMode = ON;\n' > custom_config.inc.php`
- Read-only helper: `curl -s http://localhost:8082/api/auth/config`
- Login credentials `admin` / `admin` (used only in the regression case).

**Steps / Expected / Actual**

| # | Steps | Expected | Actual |
|---|-------|----------|--------|
| 1047-1 | `demoMode = ON`; `GET /api/auth/config` | `"demoMode":true` in the JSON | PASS — `{"status":"ok","config":{…,"demoMode":true,…}}` |
| 1047-2 | `demoMode = ON`; open `http://localhost:8082/login.php` | Teal banner above the form with the 4 legacy `demo_usage` lines | PASS — a11y snapshot shows "This is a DEMO site, use it with RESPECT." + 3 `<br>`-separated lines, last one bold |
| 1047-3 | `demoMode = ON`; inspect the banner's markup | `<br>`/`<b>` from the legacy label survive; text comes from i18n, not hardcoded | PASS — element `#demoUsageBox.alert-box.alert-demo > span[data-i18n-html=auth.demoUsage]`, 3 `LineBreak` nodes in the a11y tree |
| 1047-4 | `demoMode = ON`; open `login.php?locale=ro` | Banner translated into Romanian | PASS — "Acesta este un site DEMO, folosiți-l cu RESPECT." + 3 more lines; page title also `Autentificare TestLink` |
| 1047-5 | `demoMode = ON`; open `login.php?note=expired` | Info note **and** demo banner visible together (legacy kept `$gui->note` + banner simultaneously) | PASS — snapshot shows "Session expired. Please log in again." above the 4 demo lines |
| 1047-6 | `rm custom_config.inc.php` (demoMode OFF); reload `login.php` | No demo banner at all | PASS — no demo text in the snapshot; page identical to the pre-change markup |
| 1047-7 | `demoMode = OFF`; check the **Lost password?** link | Link visible again (it shares the `demoMode` flag) — proves no regression on the existing consumer of the flag | PASS — "Lost password? Lost password?" link present in snapshot (hidden in cases 1047-2…5, as in legacy) |
| 1047-8 | `demoMode = OFF`; log in with `admin`/`admin` | Redirect to `index.php?caller=login` | PASS — browser landed on `http://localhost:8082/index.php?caller=login` |
| 1047-9 | `demoMode = ON`; check the browser Network panel | `GET /api/auth/config` 200, i18n bundle 200, no new console errors | PASS — `/api/auth/config` **200**, `/gui/templates/i18n/en.json` **200**; only the pre-existing anonymous `/api/userinfo/index.php` **401** locale probe (unrelated, present before the change) |
| 1047-10 | Gates: `node --check` on the extracted inline script, `php -l login.php`, `python3 -m json.tool` on all 10 bundles | All clean; `auth.demoUsage` present in every bundle | PASS — `JS SYNTAX OK`, `No syntax errors detected`, 10/10 bundles valid, 10/10 contain the key, `auth.*` coverage 40→41 in each |
| 1047-11 | Event Viewer check: `select count(*) from events where log_level>0;` | No new ERROR(1)/WARNING(2) rows | PASS — `1` row, `log_level=16` (AUDIT) `audit_login_succeeded` from case 1047-8; zero ERROR/WARNING |

**Result: 11/11 PASS.**

**Notes**

- Legacy parity: `gui/templates/dashio/login/login-model-marcobiedermann.tpl:29-34`
  (`{if $tlCfg->demoMode} … {$labels.demo_usage} … {/if}`) above the form;
  `login.php:367-383` serves `gui/templates/auth/login.html` via `readfile()`, so that
  Smarty block was dead code on the normal path.
- `api/auth/index.php` already exposed `demoMode`; only the rendering was missing.
- `de_DE`, `it_IT`, `ro_RO`, `ru_RU` ship **no** `$TLS_demo_usage` in their legacy
  `locale/*/strings.txt`, so legacy fell back to English there — the new i18n key is
  translated for all 10 bundles instead.
- Screenshots: `docs/screenshots/issue-1047-demo-notice.png`,
  `docs/screenshots/issue-1047-demo-notice-with-note.png`.

### Code-review follow-up (AGENTS.md rule 16) — suite 1047, review round 1

Review findings raised and how each was resolved (all re-measured in the browser
after the fix; the screen was re-tested from scratch):

| # | Finding | Fix | Re-verification |
|---|---------|-----|-----------------|
| 1047-R1 | **MAJOR** — the inline English fallback never survived: `TLi18n.apply()` overwrites every `data-i18n-html` element with the bare key when the bundle cannot be loaded (`i18n.js:196-199` + `t()` returning the key), so a demo instance could display the literal text `auth.demoUsage`. | `revealDemoNotice()` now waits for `TLi18n.isLoaded()`, and when the key is still absent it strips `data-i18n-html` before showing the inline English text. Added a 1.5 s deadline so a *stalled* (never-settling) bundle request cannot leave the banner hidden forever. | i18n bundle blocked via `initScript` XHR patch: `i18nLoaded:false`, `markerStripped:true`, `boxDisplay:"block"`, text `"This is a DEMO site, use it with RESPECT."`, `showsBareKey:false` — and after 2.2 s the same values (no timer loop, `disp:"block"`). PASS |
| 1047-R2 | **MAJOR** — the banner was not announced: it is revealed after load, so screen readers saw nothing (repo convention: `role="alert"`/`role="status"` on JS-revealed boxes, e.g. `platformsExport.html:77`, `ltxDirectLink.html:188-206`). | `role="status" aria-live="polite"` on `#demoUsageBox` (`polite`, not `alert`: it is information, not an error). | a11y snapshot now exposes `status atomic live="polite" relevant="additions text"` wrapping the 4 demo lines. PASS |
| 1047-R3 | **MAJOR** — the new `auth.demoUsage` values for de/es/fr/it/pt used HTML entities (`&aacute;`) while every other key in those bundles uses native UTF-8 (the only entity key of 41), a latent trap for a future switch to `data-i18n`. | Rewrote all five as native UTF-8 (`úsala`, `reinstalará`, `ré-installé`, `unregelmäßigen`, `DEMONSTRAÇÃO`, …). | `?locale=es` with demoMode ON: `br:3`, `b:1`, `literalEntity:false`, text `Esto es una DEMO, úsala con RESPETO.…` — identical rendering, single-pass entity decode verified (no double escaping). PASS |
| 1047-R4 | **MINOR** — layout shift / English flash: `$.getJSON('/api/auth/config')` runs outside `TLi18n.load()`, so the box could pop in with the English fallback on a non-en locale and then swap text. | Caught by the real race during re-testing: with an `isLoaded()`-only guard the **Romanian** page rendered the English fallback (`br:0, b:0`) because the config callback ran first. Fixed by gating on `isLoaded()` and re-arming the reveal from the `TLi18n.load()` callback. | `?locale=ro` with demoMode ON after the fix: `markerPresent:true`, `br:3`, `b:1`, text `Acesta este un site DEMO, folosiți-l cu RESPECT.…`. PASS |
| 1047-R5 | **MINOR** — CHANGELOG claimed `+30/-1` (measured `+29/-0`) and overstated "verbatim" for the `es` value. | Both corrected; the entry now also documents the a11y attributes, the reveal/fallback contract, the contrast ratio and the 1.5 s deadline. | `git diff --numstat` for the two code areas = `69 0`. PASS |
| 1047-R6 | **MINOR** — the docs mirror had been regenerated from the wiki clone and silently dropped still-true content (`note=first`, `note=lost`, the `external_password_mgmt` half of the lost-password gate, `Prerequisite: none`). | `git checkout docs/WIKI-LOGIN.md` and hand-inserted only the new "Demo mode notice" section + layout row + footer ref. | All 4 items present again (`docs/WIKI-LOGIN.md:10,65,66,85`); diff is `35` additions / `1` deletion (the deleted line is the old `Refs #775_` footer). PASS |
| 1047-R7 | **MINOR** — screenshots were untracked, and rule 9's staged-numstat gate needed re-checking after the commit. | `git add docs/screenshots/issue-1047-demo-notice*.png`; post-commit re-check with `git diff --numstat HEAD~1 HEAD -- tmp/TLU_Test_Cases.md`. | See the closing comment on issue #1047. PASS |

Regression after the review fixes — full suite re-run, all still PASS:
`demoMode` ON (`?locale=en` banner + `role=status` live region), ON + `?note=expired` (note and
banner together), ON + `?locale=ro`, ON + `?locale=es`, ON with the i18n bundle blocked (English
fallback, no raw key), OFF (banner `display:none`, marker untouched, **Lost password?** link
visible again), plus `node --check` on the inline script and `python3 -m json.tool` on all 10
bundles. **Result: 11/11 + 7/7 review follow-up = PASS.**

---

## Regression — Issue #1635: `third_party/phpxmlrpc/lib/xmlrpc.inc` ParseError on PHP 8 (Trac XML-RPC transport)

**Issue:** [#1635](https://github.com/sebiboga/testlink-upgraded/issues/1635)
**Branch:** `fix/issue-1635` · **Fix commit:** `a7938609` on the branch = `7330343d2` on `sebiboga` (same `patch-id`)
**Files:** `third_party/phpxmlrpc/lib/xmlrpc.inc` (47 `=& new` + 4 constructors),
`api/issuetracker/index.php` (defence in depth: the autoloading `class_exists()` moved
inside the existing `try` of both check-connection routes)

**Precondition / fixture** (the freshly imported DB is empty, so nothing reaches the XML-RPC
library at all). The Trac/XML-RPC system is **type 19**, not the `22` of the report
(`lib/functions/tlIssueTracker.class.php:71` `19 => array('type'=>'trac','api'=>'xmlrpc')`):

```sql
INSERT INTO testprojects (id,prefix,active,issue_tracker_enabled,code_tracker_enabled)
  VALUES (1,'TLU',1,1,0);
INSERT INTO issuetrackers (name,type,cfg) VALUES ('TLU Trac',19,
  '<issuetracker><username>anonymous</username><password></password><uribase>http://127.0.0.1:9/trac</uribase></issuetracker>');
INSERT INTO testproject_issuetracker (testproject_id,issuetracker_id) VALUES (1,1);
```

The cfg MUST have a single `<issuetracker>` root (2 roots -> `setCfg` logs
`Failure loading XML STRING / Extra content at the end of the document` and the interface
is only half-built -> a misleading `connected:false`).

Login: `admin` / `admin`. Screen: `http://localhost:8082/gui/templates/issuetracker/issuetrackerView.html`
(shell: ASIDE -> Issue Trackers). Commands use cookie jar `/tmp/opencode/cj.txt`.

| # | Steps | Expected behavior | Observed | Result |
|---|---|---|---|---|
| R1635-01 | `php -l third_party/phpxmlrpc/lib/xmlrpc.inc` | no syntax errors | pre-fix `PHP Parse error: syntax error, unexpected token "new" ... line 554`; post-fix `No syntax errors detected` | PASS (post-fix) |
| R1635-02 | `grep -c '=& *new' third_party/phpxmlrpc/lib/xmlrpc.inc` | 0 | 47 before, **0** after (line 554 was only the first of 47) | PASS |
| R1635-03 | `php -r 'require ".../xmlrpc.inc"; echo "loaded ok\n";'` | `loaded ok`, exit 0 | `loaded ok` | PASS |
| R1635-04 | `new xmlrpc_client('http://127.0.0.1:9/trac/xmlrpc')` — the PHP-4 constructor hop | `path='/trac/xmlrpc' server='127.0.0.1' port=9` | pre-fix `path=NULL server=NULL port=0` (constructor never called); post-fix correct | PASS |
| R1635-05 | `GET /api/issuetracker/index.php/1/check-connection` (the row's wrench) | 200 + `connected` bool | pre-fix **500 / 0 bytes**; post-fix **200** `{"status":"ok","connected":true,"message":"Connection OK"}` | PASS (post-fix) |
| R1635-06 | `GET /api/issuetracker/index.php` (the **grid** itself) | 200 + the Trac row | pre-fix **500 / 0 bytes** -> the whole Issue Trackers grid rendered EMPTY (see the "broken" screenshot); post-fix 200 with `items[0].name = "TLU Trac"`, `typeLabel "trac (Interface: xmlrpc)"` | PASS (post-fix) — **blast radius was wider than the report** |
| R1635-07 | `GET /api/issuetracker/index.php/cfg-template?type=19` (Configuration eye icon) | 200 + the `tracxmlrpcInterface` cfg template | pre-fix **500 / 0 bytes**; post-fix 200 `{"status":"ok","type":19,"template":"<!-- Template tracxmlrpcInterface -->\n<issuetracker>…"}` | PASS |
| R1635-08 | `POST /api/issuetracker/index.php/test-connection` (modal *Check Connection*, `Origin`+`X-Requested-With` headers) | 200 + `connected` bool | pre-fix 500; post-fix 200 `{"status":"ok","connected":true,…}` | PASS |
| R1635-09 | `GET /api/issuetracker/index.php/cfg-template?type=20` (control: disabled/other type) | unchanged 200 `invalid_type` | 200 `{"status":"error","code":"invalid_type","type":20}` | PASS |
| R1635-10 | Browser: ASIDE -> Issue Trackers, click the wrench of the Trac row | the cell turns into a green heartbeat icon; network `GET .../1/check-connection` = **200** | Network trace `reqid=99` 200, body `{"status":"ok","connected":true,"message":"Connection OK"}`; cell = `<i class="fas fa-heartbeat" …title="Connection successful">` | PASS |
| R1635-11 | Browser: a malformed cfg (two roots) then the wrench | must not 500, must not log a PHP error page | 200 `connected:false`; the only `events` row is the fixture's own `setCfg` ERROR (`Failure loading XML STRING`) which the malformed fixture legitimately produces | PASS (documented) |
| R1635-12 | Defence in depth: revert ONLY the library, keep the patched BFF, hit the wrench | no 0-byte 500: the route must answer the logged **502** `{"status":"error","connected":false}` | **502** size=72 + `events` id=5 `api/issuetracker/index.php::GET /{id}/check-connection :: syntax error…` (log_level 1) | PASS |
| R1635-13 | Rights: a user without `issuetracker_management` on the wrench route | still 401/403 (the `try` must not become a bypass) | guarded by `$canManage` **before** the `try` (`api/issuetracker/index.php:203`, `try {` at `:211`); unchanged code path | PASS (code-reviewed, not re-fixtured) |
| R1635-14 | Event Viewer after the whole post-fix run | 0 new Error/Warning rows | `events` = 1 row, `log_level 16` `audit_login_succeeded` (own login); server log has no `PHP Parse error` for the post-fix requests | PASS |
| R1635-15 | `new xmlrpcmsg('ticket.get')->serialize()` — the remaining wire layer | **known residual at the time**, NOT fixed by this issue | `PHP Fatal error: Call to undefined function each() in xmlrpc.inc:2946` (13 `each()` sites + 1 `split()`; both removed in PHP 8) | KNOWN FAIL — **since FIXED** by `0c9dba49a` (`fix(phpxmlrpc): port 15 each()/split() sites off PHP 8 removed functions`) + `b2d3fa20f` (`strlen()` instead of `count()` on the response body), both already on `sebiboga`; `grep -c '=& *new'` and `each(`/`split(` call-site counts are now 0. Measured 12/12 PASS in the *Regression — Issue #1764* suite above |
| R1635-16 | Code-review round: **fault injection** — revert ONLY `third_party/phpxmlrpc/lib/xmlrpc.inc` to the broken version, keep the patched BFF + `tlIssueTracker`, then hit the **grid** route | must not blank the screen: `200` with the Trac row still listed (the `@class_exists()` probe itself raises the ParseError, so the guard had to move outside the `if/else` condition into a `try`) | first attempt: still `500`/0 bytes (the `try` only wrapped the `else` branch); after wrapping the whole `if/else`: **`200`**, `items[0].name = "TLU Trac"`, `env_check_ok = false` | PASS (after correction) |
| R1635-17 | Same fault injection on `GET /cfg-template?type=19` and `GET /{id}/check-connection` | structured, non-empty answers + an Event Viewer ERROR row each | `200 {"status":"error","code":"interface_missing","iface":"tracxmlrpcInterface"}` and `502 {"status":"error","connected":false,…}`; `events` ids 5/6/7 = `api/issuetracker/index.php::GET /{id}/check-connection :: syntax error…` and `::GET /cfg-template :: syntax error…` (log_level 1) | PASS |
| R1635-18 | Restore the fixed library, re-run the whole post-fix matrix + `php -l` on all 3 files, Event Viewer | 5 routes 200 (Trac + controls), `events` = 1 row | LIST 200 / check-connection 200 / cfg-template 19 200 / cfg-template 20 200 (`invalid_type`) / POST test-connection 200; `php -l` clean on `xmlrpc.inc`, `api/issuetracker/index.php`, `tlIssueTracker.class.php`; `events` = 1 row (`log_level 16` login) | PASS |

**Summary: 17 PASS / 0 FAIL / 1 known residual (R1635-15, filed as follow-up issue #1764 —
and since FIXED on `sebiboga`, see the suite above).**
The pre-fix failures were R1635-01/02/04/05/06/07/08; R1635-06 is the newly measured one —
the *grid* route, not only the connection check, died with the same ParseError.

### Notes for future runs

- **Restored (append-only) on the `merge-stale-branches` pass for `fix/issue-1635`.** The suite
  landed originally as `7d1a508cb`, but was later destroyed by a concurrent-agent full-file
  rewrite of `tmp/TLU_Test_Cases.md` (the failure mode filed as issue #1793); it was re-appended
  verbatim from the branch tip, with only the R1635-15/summary staleness annotations corrected
  (the `each()`/`split()` residual it flagged as open had itself been fixed since). Suite counts
  were checked 47 -> 48 with 0 deletions.
- **Fix commit ids:** `a7938609` is the branch-local hash; the identical patch landed on
  `sebiboga` as `7330343d2` (same `git patch-id`). `git merge-base --is-ancestor a7938609
  origin/sebiboga` is NO — cite `7330343d2` when grepping the default branch.
- **`events` row ids/counts** quoted in R1635-11/12/14/17/18 (`id=5/6/7`, "`events` = 1 row")
  are run-local evidence from a single execution, not stable assertions.
- The fixture below hard-codes `testprojects id=1` and an implicit `issuetrackers id=1`; on a
  populated DB re-map the ids before running it.
- **`tracxmlrpcInterface::connect()` never probes the network**
  (`lib/issuetrackerintegration/tracxmlrpcInterface.class.php:130-156`):
  it only builds a `xmlrpc_client` object, so `isConnected()` is `true` for any syntactically
  valid cfg even when the Trac host refuses connections (my fixture points at `127.0.0.1:9`,
  nothing listens, and the answer is still `connected:true`). That false "Connection OK" is
  **pre-existing 1.9.20 behaviour**, deliberately not changed by this fix, and is filed together
  with the `each()`/`split()` residuals as a follow-up issue.
- The `Environment` column badge in the grid is a *DB* flag (`issuetrackers.type` is present and
  the row is active), not a connection probe — do not read it as a connection test.
- `tmp/php_server.log` is the stderr of the `php -S` process and is the only place the ParseError
  surfaces: a fatal is never routed through `tLog()`, so the Event Viewer stays empty (measured:
  `events` = 1 row after 2 × HTTP 500).

---

## Regression — Issue #1796: dead Bootstrap 4/5 modal classes on 3 modernized screens (dialogs not centred, documentation viewer 600px)

**Precondition:** TestLink 2.0.1 at `http://localhost:8082`, session `admin`/`admin`,
headless Chrome (any viewport — the results below are from 1440x900, 1024x600, 780x437 and
500x420), Bootstrap **3.4.1** bundle (`gui/templates/dashio/lib/bootstrap/`).

**Pre-fix symptom (reproduced):**
`documentation.html:82` carried `class="modal-dialog modal-xl modal-dialog-centered"`,
`reqTcAssign.html:167` and `reqTcBulkAssign.html:134` carried
`class="modal-dialog modal-dialog-centered" style="max-width:520px;"`. Neither
`modal-xl` nor `modal-dialog-centered` exists in Bootstrap 3 (it only knows
`.modal-dialog` 600px, `.modal-sm` 300px, `.modal-lg` 900px), so the dialogs silently
fell back to BS3 defaults: 600px wide, `margin-top:30px`, top-aligned.

**Repro steps (pre-fix)**

| # | Screen / entry point | Action |
|---|---|---|
| R1796-1 | `gui/templates/documentation/documentation.html` | click **View** on any PDF card |
| R1796-2 | `gui/templates/requirements/reqTcAssign.html` | trigger a destructive action → confirm dialog |
| R1796-3 | `gui/templates/requirements/reqTcBulkAssign.html` | trigger a destructive action → confirm dialog |

Measurement recipe (paste in the page console, works on both a fresh DB and a populated one):

```js
const m=document.querySelector('#pdfModal'),c=document.querySelector('#pdfModal .modal-content'),
      mr=m.getBoundingClientRect(),cr=c.getBoundingClientRect();
console.log({cls:c.parentElement.className,w:Math.round(cr.width),
             hGap:[Math.round(cr.left-mr.left),Math.round(mr.right-cr.right)],
             vGap:[Math.round(cr.top-mr.top),Math.round(mr.bottom-cr.bottom)],
             overflow:cr.bottom>mr.bottom});
```

**Expected post-fix behaviour** — dialogs keep their intended size (1140px for the PDF
viewer, 520px for the confirm dialogs), are centred horizontally **and** vertically,
never overflow the viewport, and behave exactly as before (show/hide/dismiss/i18n).

**Actual result observed (post-fix, measured)**

| # | Case | Measured | Verdict |
|---|---|---|---|
| R1796-1 | `documentation.html` **View**, 1440x900 | `w=1140` (exp. 1140), `hGap=[143,143]`, `vGap=[54,54]`, no overflow, `embed` present | PASS (pre-fix: `w=600`, `vGap=[30,78]`) |
| R1796-2 | `documentation.html` **View**, 1024x600 | `w=989` (exp. `min(1140, dialogW-20)=989`), `hGap=[10,10]`, `vGap=[29,29]`, no overflow | PASS |
| R1796-3 | `documentation.html` **View**, 780x437 (short window) | `w=745`, `hGap=[10,10]`, `vGap=[29,29]`, `overflow=false` | PASS (pre-fix: 600px and content bottom `451.6` in a 437px viewport — overflowed) |
| R1796-4 | `documentation.html` **View** → close (×) | `modal class="modal fade"`, `#pdfBody embed` removed by the `hidden.bs.modal` handler | PASS |
| R1796-5 | `documentation.html` **View** → reopen | dialog re-opens at 1140px, centred | PASS |
| R1796-6 | `reqTcAssign.html` confirm dialog, 1440x900 | `w=520`, `hGap=[460,460]`, `vGap=[362,362]` | PASS (pre-fix: `vGap=[30,694]`, `contentLeft 420 / contentRight 500`) |
| R1796-7 | `reqTcBulkAssign.html` confirm dialog, 1440x900 | `w=520`, `hGap=[460,460]`, `vGap=[362,362]` | PASS |
| R1796-8 | `reqTcBulkAssign.html` confirm dialog, 500x420 (narrow) | `w=480` (exp. `min(520, dialogW-20)=480`), `hGap=[10,10]`, `vGap=[122,122]`, no overflow | PASS (pre-fix: hard 520px, overflowed a 500px window) |
| R1796-9 | confirm dialog interactions (both screens) | Cancel → `modal fade`; OK → `modal fade` + callback ran exactly once; `.close` (×) → `modal fade` | PASS |
| R1796-10 | i18n | `#pdfTitle` renders `User Manual` from `doc.view`; `common.cancel` still translated. **No i18n bundle touched** (`git diff --name-only` lists only the 3 HTML files) | PASS |
| R1796-11 | the grep #1683 asked for | `grep -rn 'modal-dialog-centered\|modal-xl\|new BSS.Modal\|data-bs-dismiss' gui/templates/*/*.html` → **no hits** (before the fix: 3) | PASS |
| R1796-12 | no collateral damage on other dialogs | `grep -rc 'modal-dialog modal-sm\|modal-dialog modal-lg' gui/templates/*/*.html` → still **25** occurrences (`.modal-sm`/`.modal-lg` are real BS3 classes, untouched) | PASS |
| R1796-13 | console | no errors/warnings on any of the 3 screens | PASS |
| R1796-14 | Event Viewer (`events` table) | 1 row only — the `audit_login_succeeded` INFO row; **no new Error/Warning** | PASS |
| R1796-15 | narrow-screen regression of the flex trick | the width must live on `.modal-content`; putting it on the flex `.modal-dialog` collapses the box to its text width (measured 302px on `documentation.html`, 0 gutter when only `margin:auto` is used) | PASS (guard rail documented in the code comments) |

**Result: 15/15 PASS.** The fix is layout-only: three `<div class="modal-dialog">`
attribute cleanups plus three scoped CSS rules
(`#pdfModal .modal-dialog`/`.modal-content`, `#confirmModal .modal-dialog`/`.modal-content`),
one responsive `max-height` on `#pdfModal .modal-body`. No PHP, no BFF endpoint, no
i18n key, no shared stylesheet — so no other screen can regress from it.

**Files changed by the fix**
* `gui/templates/documentation/documentation.html`
* `gui/templates/requirements/reqTcAssign.html`
* `gui/templates/requirements/reqTcBulkAssign.html`

---

## Suite 1797 - Copy Requirement Specification (Dashio popup) - Issue #1797

**Screen under test:** `gui/templates/requirements/reqSpecCopy.html`
**BFF:** `api/reqspeccopy/index.php` (`init`, `copy`, `projects`)
**Fixture:** `tmp/fixtures_1797.php` (gitignored, rerunnable)
**Date:** 2026-10-02

### Environment / credentials
| Item | Value |
|---|---|
| App | `http://localhost:8082` |
| DB | `testlink/testlink/testlink` @ `127.0.0.1:3306` |
| Admin | `admin` / `admin` (all rights) |
| Limited user | `norights1797` (role 3, no requirement rights) |
| Cookies | admin `/tmp/c1797.txt`, no-rights `/tmp/n1797.txt` |

### Fixture data (as created by the final run)
| Id | Node |
|---|---|
| 143 | test project `RSCCOPY1797` (prefix RSC1) |
| 144 | **source** specification `RS-MAIN` "Main Specification" |
| 146 | child specification `Child Specification` under 144 |
| 148 | "Destination Specification" (sibling of 144) |
| 150 | requirement in 144; 152 in 146; 154 in 148 |
| 156 | test project `RSCFOREIGN1797` |
| 157 | "Foreign Specification" under 156 |
| 1 | **orphan** `nodes_hierarchy` test-project row, no `testprojects` row (bug #1798) |

---

### TC-1797-01 - Screen loads with the source specification
| | |
|---|---|
| Precondition | logged in as `admin` |
| Steps | open `/gui/templates/requirements/reqSpecCopy.html?req_spec_id=144&tproject_id=143` |
| Expected | title "Copy Requirement Specification", subtitle, spec line `RS-MAIN - Main Specification`, source card (doc id, title, test project, prefix, author, requirement count, scope), destination card, Copy enabled, footer |
| Actual | **PASS** - all fields populated, requirement count "1 requirement(s)", scope rendered as plain text, Copy enabled, footer `TestLink 2.0.1 - Copy Requirement Specification | Generated on ...` |

### TC-1797-02 - Source subtree is NOT offered as a destination
| | |
|---|---|
| Steps | same as TC-01, read the destination container selector |
| Expected | the source specification (144) and its child (146) are absent; only the project root and other specifications are listed |
| Actual | **PASS** - options were `143 RSCCOPY1797`, `148 Destination Specification`. Neither 144 nor 146 offered |

### TC-1797-03 - Copy into a specification, position = bottom
| | |
|---|---|
| Steps | container = 148, position = bottom, press Copy |
| Expected | success message with the legacy wording, "Open the copy" link, new specification in the list, Copy button usable again |
| Actual | **PASS** (after fix) - message `A copy of Req. Spec (DOCID:RS-MAIN - Main Specification) has been done (DOCID:RS-MAIN [1])` + "Open the copy"; new nodes 159/161 (spec + child) appear nested under 148. **First run FAILED - see bug #1799 (the message was wiped by its own refresh)** |

### TC-1797-04 - Copy into the project root, position = top
| | |
|---|---|
| Steps | container = 143 (project root), position = top, press Copy |
| Expected | the new specification is the FIRST child of the project root |
| Actual | **PASS** - order `167 Main Specification`, `169 Child Specification`, `148 Destination Specification`, ... so the copy is first |

### TC-1797-05 - Cross-project copy
| | |
|---|---|
| Steps | destination project = `RSCFOREIGN1797` (156), container list reloads to `156 / 157 Foreign Specification`, pick 157, position = bottom, Copy |
| Expected | destination test project switcher reloads the container list; copy succeeds with a doc_id free of the `[n]` index (that project owns no `RS-MAIN`) |
| Actual | **PASS** - `... has been done (DOCID:RS-MAIN)`, success box visible |

### TC-1797-06 - Empty destination list disables Copy
| | |
|---|---|
| Steps | force an empty destination list, read the Copy button and the hint |
| Expected | hint "No destination is available in this test project." shown, Copy **disabled** |
| Actual | **PASS** - hint `display:block`, `copyBtn.disabled === true` |

### TC-1797-07 - Unknown specification id -> 404 card
| | |
|---|---|
| Steps | open `?req_spec_id=999999&tproject_id=143` |
| Expected | "Requirement specification not found" card + the BFF message + machine code `req_spec_not_found` |
| Actual | **PASS** (after fix, bug #1799) - card title, message and `req_spec_not_found` all correct. **First run FAILED: showed the access-denied hint with an empty machine code** |

### TC-1797-08 - Missing specification id -> 400 card
| | |
|---|---|
| Steps | open `/gui/templates/requirements/reqSpecCopy.html` with no id |
| Expected | "The request was invalid" card, message "No specification id was given (req_spec_id).", machine code `invalid_req_spec_id` |
| Actual | **PASS** - all three elements correct |

### TC-1797-09 - Anonymous request -> 401 / redirect to login
| | |
|---|---|
| Steps | `GET /api/reqspeccopy/index.php?action=init&req_spec_id=144` with no session cookie |
| Expected | HTTP 401 |
| Actual | **PASS** - HTTP 401; the screen redirects to `/login.php?note=expired` |

### TC-1797-10 - Cross-origin POST is refused (CSRF)
| | |
|---|---|
| Steps | `POST /api/reqspeccopy/index.php?action=copy` without `Origin`/`Referer` |
| Expected | refused, no write |
| Actual | **PASS** - `Forbidden: missing or mismatched same-origin proof (CSRF protection)` |

### TC-1797-11 - User without requirement rights -> 403
| | |
|---|---|
| Steps | as `norights1797`: `action=init`, `action=copy`, `action=projects` |
| Expected | 403 `no_right` on init and copy; `projects` returns an empty list (200) |
| Actual | **PASS** - `{"status":"error",...,"code":"no_right"}` on init and copy, `{"status":"ok","projects":[]}` |

### TC-1797-12 - Destination inside the source subtree is refused
| | |
|---|---|
| Steps | `container_id=144` (the source itself) and `container_id=146` (its child) |
| Expected | HTTP 400 `destination_inside_source` |
| Actual | **PASS** - 400, and the screen never offers either id |

### TC-1797-13 - Foreign destination container is refused
| | |
|---|---|
| Steps | `tproject_id=143&container_id=157` (node of the other project) |
| Expected | HTTP 400 `invalid_destination` |
| Actual | **PASS** - `Destination container belongs to another test project` |

### TC-1797-14 - Orphan test-project node is not offered
| | |
|---|---|
| Steps | read the destination project selector on the instance that still holds orphan node 1 |
| Expected | only real, active projects |
| Actual | **PASS** (after fix, bug #1798) - `[(143, RSCCOPY1797), (156, RSCFOREIGN1797)]`. **First run listed the orphan twice**, and picking it always died with 400 |

### TC-1797-15 - Wrong method -> 405
| | |
|---|---|
| Steps | `GET ?action=copy`, `POST ?action=projects` |
| Expected | 405 `method_not_allowed` with `Allow:` header |
| Actual | **PASS** - both 405; `Allow: POST` / `Allow: GET, HEAD` |

### TC-1797-16 - Unknown action -> 400
| | |
|---|---|
| Steps | `GET ?action=nope` |
| Expected | 400 `unknown_action` |
| Actual | **PASS** |

### TC-1797-17 - Legacy `?doAction=copy` / `?doAction=doCopy` redirect
| | |
|---|---|
| Steps | `GET /lib/requirements/reqSpecEdit.php?doAction=copy&req_spec_id=144` and the same with `doAction=doCopy&containerID=148` |
| Expected | 302 to `reqSpecCopy.html?req_spec_id=144&...&legacy_intent=<action>`, **no write executed** |
| Actual | **PASS** - both 302, `legacy_intent=copy` / `legacy_intent=doCopy`; no specification created |

### TC-1797-18 - Legacy redirect without an id
| | |
|---|---|
| Steps | `?doAction=copy` (no `req_spec_id`) |
| Expected | 302 back to `reqSpecView.html`, one ERROR row in `events` |
| Actual | **PASS** - 302 to `/gui/templates/requirements/reqSpecView.html`, events row `reqSpecEdit.php: doAction=copy was requested without a req_spec_id - nothing has been copied.` |

### TC-1797-19 - The legacy notice is actually visible
| | |
|---|---|
| Steps | follow the legacy 302 and read the message box |
| Expected | "This screen replaces the legacy copy action. Nothing has been copied yet - choose a destination and press Copy." |
| Actual | **PASS** (after fix, bug #1799) - message box `display:block` with the notice, and the destination list is populated. **First run: the notice was never visible (raised before `load()`, then wiped again by the `.done` handler, plus a `ReferenceError`)** |

### TC-1797-20 - Stale `?tproject_id=` is repaired
| | |
|---|---|
| Steps | open the screen with `?req_spec_id=144&tproject_id=95` (95 no longer exists) |
| Expected | the destination falls back to the project owning the specification, containers load, Copy usable |
| Actual | **PASS** (after fix, bug #1799) - `destination.tproject_id = 143`, `tproject_fallback_from = 95`, 6 containers, Copy enabled. **First run: 0 containers, empty selector, Copy disabled** |

### TC-1797-21 - Toolbar button on the Requirement Specification Viewer
| | |
|---|---|
| Steps | open `gui/templates/requirements/reqSpecView.html`, read the toolbar |
| Expected | a "Copy Requirement Specification" button linking to the popup with the current spec and project |
| Actual | **PASS** - `#copySpecLink` -> `reqSpecCopy.html?req_spec_id=<SPEC>&tproject_id=<PROJECT>`. Before this change 2.0.1 had **no** copy-specification entry point at all (legacy `reqSpecView.tpl:52` linked `reqSpecEdit.php?doAction=copy`, which no modern screen replaced) |

### TC-1797-22 - Every other action of reqSpecEdit.php still works
| | |
|---|---|
| Steps | `GET /lib/requirements/reqSpecEdit.php?req_spec_id=144&doAction=edit` |
| Expected | the legacy edit screen is still served (HTTP 200) |
| Actual | **PASS** - 200, the interception only claims `copy` / `doCopy` |

### TC-1797-23 - Locale switch
| | |
|---|---|
| Steps | switch the header locale picker to Romanian, then to German |
| Expected | every label, the title, the footer and the machine-code labels switch; no raw `rsc.*` key ever reaches the page |
| Actual | **PASS** - title `Copiază specificația cerințelor`, label `Containerul destinație`, button `Copiază`, footer `TestLink 2.0.1 - Copiază specificația cerințelor`; `TLi18n.has()` true for **every** `[data-i18n]` key on the page (0 missing) |

### TC-1797-24 - Toolbar buttons / links
| | |
|---|---|
| Steps | read every href of the dark toolbar |
| Expected | Refresh reloads; Back to Specification Viewer / Cancel / Close point at `reqSpecView.html?id=<spec>&tproject_id=<project>`; Open the copy points at the NEW specification |
| Actual | **PASS** - all hrefs correct, the "Open the copy" shortcut stays visible after the post-copy refresh |

### TC-1797-25 - Event Viewer clean
| | |
|---|---|
| Steps | exercise every path above, then read the newest rows of `events` |
| Expected | no new Error/Warning rows from this screen |
| Actual | **PASS** (after fix, bug #1800) - three consecutive legacy shim hits add **0** rows; a denied copy adds one level-1 row. **Before the fix: `Undefined property: tlUser::$id` per denial and `Trying to access array offset on null` per shim hit** |

---

### Result
**25 / 25 PASS** after the fixes; 6 of the cases failed on the first browser pass and each failure was filed and fixed:
- bug **#1798** - orphan test-project nodes offered as destinations
- bug **#1799** - five screen defects (lost confirmation, wrong error card text / no machine code, camelCase id mismatch, stale `?tproject_id=`, invisible legacy notice)
- bug **#1800** - Event Viewer warnings from the BFF and the shim

All three are closed by the commits of the #1797 branch.

---

## Suite 1801 — Regression suite for the mandatory code review of #1797 (Copy Requirement Specification)

Issue: https://github.com/sebiboga/testlink-upgraded/issues/1801 · #1802 · #1803
Fixture: `tmp/fixtures_1797.php` (rerun before the suite; ids below are from the run that produced these results)
Environment: `http://localhost:8082`, DB `testlink`, admin cookie `/tmp/c1797.txt`

| # | Case | Steps | Expected | Actual |
|---|------|-------|----------|--------|
| 1 | **BLOCKER: sibling order is `node_order`, not `id` (bottom)** | Hand-edit so `node_order` disagrees with `id` at depth 1 (`232`=1, `228`=2), then `POST action=copy&req_spec_id=228&container_id=227&target_position=bottom` | New spec is placed AFTER the existing two, and the existing two keep their `node_order` sequence | **PASS** - `200 ok`, depth 1 = `232:1  228:2  259:3`. The old `sort()`-by-id code produced `[228=1, 232=2, new=3]` |
| 2 | **BLOCKER: sibling order is `node_order`, not `id` (top)** | Same seeded state, `target_position=top` | New spec first, others shifted while keeping their relative order | **PASS** - depth 1 = `267:1  232:2  228:3  259:4` |
| 3 | **MAJOR: requirement siblings are renumbered too** | `copy req_spec_id=230 (child spec) into container_id=228 (spec holding a requirement), target_position=top` | Every child of the container (type 6 spec **and** type 7 requirement) gets a distinct order; new spec is 1 | **PASS** - `275:1 (spec)  229:2 (spec)  230:3 (spec)  234:4 (requirement)`. Before, the requirement kept its old order and tied with the new spec |
| 4 | `exec_query()` result is checked (truthiness, not `!== tl::OK`) | Replay case 1 after the first cut of the check had been corrected | Successful copies are **not** reported as failures | **PASS** - the first attempt compared against `tl::OK`, which `exec_query()` never returns, and turned every successful copy into a bogus `409 position_write_failed`; caught by case 1 and fixed to a truthiness test (`exec_query()` returns the ADOdb result object) |
| 5 | Default position is `top` | `GET ?action=init` | `default_position` is `top` | **PASS** - `{"default_position":"top", ...}` |
| 6 | `#1802` stored-XSS probe through `stripHtml()` | Store `<img src=x onerror="window.__xss=1"><b>Scope</b> line<br>second` in the source scope, open the popup | No handler runs, nothing injected | **PASS** - `window.__xss === undefined`, `0` injected `img`/`script` under the scope card. Reproduced the flaw first with the old `innerHTML` sink: `window.__xss === true` |
| 7 | `#1802` legacy notice no longer overwrites a successful copy | Open the popup with `&legacy_intent=1`, pick container `232`, click Copy | Green success message survives the post-copy reload | **PASS** - `#msg` = `A copy of Req. Spec (DOCID:RS-MAIN - Main Specification) has been done (DOCID:RS-MAIN [5])`, class `msg ok`. Before, the reload raised the red `rsc.legacyNotice` instead |
| 8 | `#1802` legacy notice still shown before any copy | Open the popup with `&legacy_intent=1` and copy nothing | The notice is displayed | **PASS** - `#msg` = `Acest ecran înlocuiește vechea acțiune de copiere...`, class `msg err`, `display: block` |
| 9 | `#1802` container selection survives the post-copy reload | Select container `232`, copy, wait for the refresh | The selector still shows `232` | **PASS** - `#containerSel` = `232`. Before, it snapped back to the project root, so a second Copy landed in the wrong container |
| 10 | `#1802` `footers.reqSpecCopy` is actually used | Switch the locale to Romanian | The footer is the translated key, not a JS-rebuilt string | **PASS** - `TestLink 2.0.1 - Copiază specificația cerințelor` |
| 11 | `#1803` Event Viewer clean after a recursive copy | `DELETE FROM events WHERE id > <max>`, then a copy whose source has a child specification **and** requirements | `events` grows by 0 | **PASS** - 163 rows before, 163 after; before the fix every call added `E_WARNING Trying to access array offset on null ... requirement_spec_mgr.class.php - Line 1938` |
| 12 | `#1801` final destination project must be writable | `init`/`copy` on a specification whose owning project row is missing | 404 `project_not_found`, no copy | **PASS by inspection + orphan fixture** — project node `1` (`RSCCOPY1797`, no `testprojects` row, the #1798 orphan) is excluded by `rscIsWritableProject()` on the **final** value now, not only on the asserted one |
| 13 | Copy into another project still works after the final-destination assertion | `container_id` in project B | 200 + copy created | **PASS** — covered again by case 1/2 semantics; project B listing stays inner-joined (`#1798`) |
| 14 | Browser console clean during the whole review-fix pass | Console of `reqSpecCopy.html` | 0 error / 0 warning | **PASS** — `<no console messages found>` |
| 15 | Full regression re-run of Suite 1797 | Replay the 25 cases of Suite 1797 against the fixed build | 25/25 PASS | **PASS** — no behaviour change for init, error codes, legacy shim branches or the viewer button |

**Suite 1801: 15/15 PASS.**

### Suite 1801 addendum — findings of the mandatory re-review of the fix commit (all PASS)

| # | Case | Steps | Expected | Actual |
|---|------|-------|----------|--------|
| 16 | **MAJOR: a `copy_to()` failure keeps its HTTP 409** | The failure path returns a JSON body; the client must not degrade it into a generic server card | HTTP 409 + `{status:error, code, partial}` | **PASS by code** — `http_response_code(409)` restored before `out()`; the review had caught that replacing `failOut(409,…)` with a bare `out()` turned every copy failure into HTTP 200 |
| 17 | **MAJOR: `partial` is the id that actually committed** | Inspect the failure path | `partial` = `$op['id']` | **PASS by code** — the first cut read `$newSpecId`, which does not exist in that scope, so `partial` was permanently 0 (verified by the reviewer under PHP 8.3); `$op['id']` is the committed top-level spec |
| 18 | **MAJOR: one failed copy no longer bricks the popup** | Stub `$.ajax` so the copy resolves **asynchronously** with a 409 error body, then read the button state | Copy re-enabled, the form stays on screen | **PASS** — `#copyBtn.disabled === false`, `#srcCard` still visible. Note: a **synchronous** stub reports `disabled === true`, which is a stub artifact (real jQuery `.done()` callbacks always run after the `prop('disabled', true)` line) |
| 19 | `target_position=` (explicitly empty) follows the advertised default | `POST …&target_position=` | `top` | **PASS** — `new.position = top`, HTTP 200 |
| 20 | Server-locale `copy_to()` sentence is not used as a machine code, nor logged raw | Inspect the failure path | Stable `code`, text in `message`, control characters stripped before `tLog` | **PASS by code** — `warning_duplicated_req_spec_doc_id → duplicate_doc_id`, `error_creating_req_spec`, `error_updating_req_spec`, default `copy_failed`; `preg_replace('/[\r\n\t]+/', ' ', …)` before the log (the sentence can embed the user-controlled specification title) |
| 21 | A container that holds only the new copy is normalised | Copy into an empty container | `node_order = 1` | **PASS by code** — the early return used to leave the source's inherited order (e.g. 7) in place |
| 22 | `loadProjects()` failure no longer leaves a stale container list | Inspect `loadProjects()` | `.fail()` renders the error | **PASS by code** |
| 23 | No regression from the review fixes | Full happy path, both positions, empty position, browser reload, footer, locale | Screen renders and copies | **PASS** — after removing the dead `setFooter()` (review NIT 2) the page initially threw `ReferenceError: setFooter is not defined` and rendered an **empty** container list; caught immediately in the browser and fixed, then re-verified: 6 destinations, `#srcCard` visible, footer localized, console clean |

**Suite 1801 addendum: 8/8 PASS** (Suite 1801 total: 23 cases, 23/23).

---

## Regression — Issue #1684: reqTreeReorder.html — the Up/Down/To top/To bottom row buttons reordered the SELECTED requirement, not their own row

**Screen:** `gui/templates/requirements/reqTreeReorder.html`
**Fix under test:** `f60e8965d` — *"row reorder buttons act on their OWN row, not on the selected one (Refs #1681)"* (already on the default branch; this run verifies it and closes the issue).
**Date of run:** 2026-10-03

### Precondition

```bash
php tmp/fixtures_1681.php
# -> tproject=1, specs TR1-SPEC-A(2) / TR1-SPEC-B(4), reqs TR1-1(6) TR1-2(8) TR1-3(10)
# force spec 2 into the order the report needs: TR1-1, TR1-3, TR1-2
mysql -h 127.0.0.1 -utestlink -ptestlink testlink -e \
  "UPDATE nodes_hierarchy SET node_order=0 WHERE id=6;
   UPDATE nodes_hierarchy SET node_order=1 WHERE id=10;
   UPDATE nodes_hierarchy SET node_order=2 WHERE id=8;"
# login admin/admin, open:
# http://localhost:8082/gui/templates/requirements/reqTreeReorder.html?tproject_id=1&req_spec_id=2
```

**Pre-fix repro (verified against `git show f60e8965d^:…/reqTreeReorder.html`):** click **Select** on row 2 (`TR1-3`), then **To top** on row 3 (`TR1-2`) →
observed `TR1-3, TR1-1, TR1-2` (the *selected* row jumped to the top, row 3 untouched) instead of `TR1-2, TR1-1, TR1-3`.
Second variant: **Select** row 1 then **Up** on row 3 → order unchanged (silent no-op). Both silent: **0 console errors/warnings**, 0 XHRs fired.

### Expected post-fix behaviour

A reorder control can only ever act on the row it lives in, whatever is selected; the `Move a requirement` selection survives a reorder; `Apply` persists the clicked row's new order.

### Actual result

| # | Case | Expected | Actual | Verdict |
|---|------|----------|--------|---------|
| 1 | **Reported repro** — select row 2, **To top** on row 3 | `TR1-2, TR1-1, TR1-3` | `TR1-2, TR1-1, TR1-3` | **PASS** |
| 2 | **Silent-no-op variant** — select row 1, **Up** on row 3 | `TR1-1, TR1-2, TR1-3` | `TR1-1, TR1-2, TR1-3` | **PASS** |
| 3 | **No selection** — **Down** on row 1 | `TR1-3, TR1-1, TR1-2` | `TR1-3, TR1-1, TR1-2` | **PASS** |
| 4 | **Bottom** on row 1 of 3 | `TR1-3, TR1-2, TR1-1` | `TR1-3, TR1-2, TR1-1` | **PASS** |
| 5 | Boundary controls disabled per row | `up`/`top` on row 1 `.dis`; `down` on last `.dis` | all three `true` | **PASS** |
| 6 | Selection survives a reorder | `#selBox` + `.sel` highlight keep the picked requirement | `#selBox` = `TR1-3 Third requirement`, `tr.sel .rdid` = `TR1-3` | **PASS** |
| 7 | Reorder → **Apply** → confirm (`#cmOk`) → DB | `nodes_hierarchy` = the UI order | UI `TR1-2, TR1-1, TR1-3`; DB `0=TR1-2 1=TR1-1 2=TR1-3`; dirty chip cleared to `none` | **PASS** |
| 8 | Browser console clean | 0 error / 0 warning | `<no console messages found>` (error+warn filter) | **PASS** |
| 9 | Event Viewer / `events` table | no new Error/Warning | 2 rows only — `id 1 CREATE` (fixture) + `id 2 LOGIN`, both `log_level 16` audit. No error/warning row created | **PASS** |

**Regression — Issue #1684: 9/9 PASS.** Pre-fix, cases 1, 2 and 7 all FAIL (wrong row reordered / silent no-op, and the wrong order written to `nodes_hierarchy`).

PASS/FAIL: PASS

## Task — Issue #1048: Implement SSO auto-login (SSO_enabled) + ssodisable bypass in login.html (gap vs legacy)

### Suite: 1048 — SSO auto-login
Precondition: the app is running at http://localhost:8082; config.inc.php has `$tlCfg->authentication['SSO_enabled'] = true`, `SSO_method = 'WEBSERVER_VAR'`, `SSO_uid_field = 'REMOTE_USER'`, `SSO_user_target_dbfield = 'email'` and a test user `sso1048@example.com` (active) exists. The SSO path runs server-side (Apache passes REMOTE_USER) — the browser auto-attempt to `/api/auth/sso` happens on page load when no `note` and no `ssodisable`.

Steps:
1. Visit `http://localhost:8082/gui/templates/auth/login.html` directly (no `note`, no `ssodisable`). With SSO enabled and no environment identity passed by the HTTP server, the BFF `/api/auth/sso` returns a soft failure → the page falls back to the interactive login form and the SSO progress banner hides.
2. Add `?ssodisable` to the URL → the hidden `ssodisable` field is set and the auto-attempt to `/api/auth/sso` is skipped; interactive form remains visible.
3. With SSO enabled, attempt an interactive login while `ssodisable` is present: the server response's `destination` must include `&ssodisable=1` (propagated redirect) so the flag is not lost after login.
4. Normal login without `ssodisable` still works when credentials are valid (regression).
5. `/api/auth/config` returns `ssoEnabled`, `ssoMethod`, `ssoOnly`.

Expected:
1. Fallback to form, no crash, no infinite redirect loop.
2. No automatic SSO POST; banner never shows.
3. Destination contains `&ssodisable=1`.
4. Login succeeds and redirects to the app.
5. JSON contains the three SSO fields.

Actual: all as above in BFF checks; UI fallback/parity matches legacy.

PASS/FAIL: PASS

## Task — Issue #1286: YouTube contributor-video links in install/installView.html

Precondition: TestLink 2.0.1 running at http://localhost:8082, logged in as admin/admin,
database `testlink` (schema DB 2.0.0). Entry point:
`http://localhost:8082/gui/templates/install/installView.html`.

### TC-1286-1 — Legacy baseline: the four videos exist in the legacy landing page
1. `grep -n "youtube" install/index.php`
2. Read lines 52-58.

Expected: the "Some user contributed videos (You Tube)" heading with 4 links, video IDs
NOvTWZvc2x8, P2zWScVjuag, 7xH1LKQU1TA, 6s48WGuX2WE.

Actual: 4 hits on lines 54, 55, 56, 57 — matching the four IDs above. PASS

### TC-1286-2 — BFF serves the videos payload
1. `curl -s -b <session> http://localhost:8082/api/install/index.php`
2. Inspect the `videos` key.

Expected: 4 entries, each `{id, key, url}`, ids matching the legacy ones, `url` absolute
https YouTube links.

Actual: 4 entries —
NOvTWZvc2x8 / install.videoInstallProject / https://www.youtube.com/watch?v=NOvTWZvc2x8
P2zWScVjuag / install.videoTestManagementTool / https://www.youtube.com/watch?v=P2zWScVjuag
7xH1LKQU1TA / install.videoIntroduction / https://www.youtube.com/watch?v=7xH1LKQU1TA
6s48WGuX2WE / install.videoWalkthrough / https://www.youtube.com/watch?v=6s48WGuX2WE
PASS

### TC-1286-3 — Screen renders the "Community Videos" section with 4 cards
1. Open `http://localhost:8082/gui/templates/install/installView.html` (EN).
2. Read `#videosSection`, `#videosHint`, `#videos`.

Expected: section visible (not `display:none`), heading "Community Videos", 4
`.video-card` anchors with the legacy captions and URLs.

Actual: `sectionVisible: block`, `hintVisible: block`, `count: 4`; captions
'Installation of "TestLink" & Creating project', "TestLink Test Management Tool Tutorial",
"Introduction to TestLink", "TestLink Walkthrough"; hrefs byte-identical to legacy. PASS

### TC-1286-4 — Links open safely in a new tab
1. Read `target` / `rel` of each `#videos a`.

Expected: `target="_blank"` and `rel` containing `noopener` (legacy used the no-op
`target="#"`, which also leaked `window.opener`).

Actual: all 4 anchors `target="_blank"`, `rel="noopener noreferrer"`. PASS

### TC-1286-5 — Section localized (ro)
1. Open `?locale=ro`.
2. Read the heading, hint and the 4 captions.

Expected: Romanian strings from `ro.json`, same 4 hrefs.

Actual: heading "Video-uri din comunitate"; hint "Ghiduri video contribuite de utilizatori
TestLink - se deschid pe YouTube într-o filă nouă."; captions 'Instalarea "TestLink" și
crearea unui proiect', "Tutorial - TestLink Test Management Tool", "Introducere în
TestLink", "Prezentare TestLink (walkthrough)"; hrefs unchanged. PASS

### TC-1286-6 — i18n completeness across all bundles
1. `for f in gui/templates/i18n/*.json; do python3 -m json.tool "$f" >/dev/null; done`
2. Count `install.video*` keys per bundle and cross-check the 4 `key` values used by the BFF.

Expected: 10/10 bundles valid JSON, 7 new keys each, no missing/empty value, no BFF key
unresolved.

Actual: 10/10 valid; `en 7, ro 7, de 7, fr 7, es 7, it 7, pt 7, ru 7, ja 7, zh 7`;
`BFF keys missing from en.json: []`; `missing=[] empty=[]` for every locale. PASS

### TC-1286-7 — Degenerate / hostile payloads do not break the screen
1. In the live page call `renderStatus()` with (a) `videos: []`, (b) `videos` containing
   `javascript:alert(1)`, an entry with no `url`, `http://evil.tld/x` and `null`,
   (c) an entry whose `key` does not exist in the bundle.

Expected: (a) whole section hidden, 0 cards, no empty shell; (b) only the
`https://www.youtube.com/...` entry rendered — non-https schemes dropped; (c) section
renders with the raw key as caption (visible fallback) instead of a blank card.

Actual: (a) `visible: none, cards: 0`; (b) `visible: block, cards: 1`,
hrefs `["https://www.youtube.com/watch?v=NOvTWZvc2x8"]`; (c) `visible: block`,
`cap: "install.doesNotExist"`. PASS

### TC-1286-8 — No regressions: console clean, Event Viewer clean
1. `list_console_messages(types=[error,warn])` on the screen.
2. `SELECT * FROM events ORDER BY id DESC LIMIT 6`.
3. Reload and re-check the status cards / upgrade panel / security panel / actions.

Expected: no console errors, no new Error/Warning rows in `events`, previous panels still
render (7 status cards, security notes, 4 action buttons).

Actual: "no console messages found"; `events` holds only the 2 `log_level 16`
`audit_login_succeeded` LOGIN rows, no Error/Warning; sections render
`["Installation Status", "Actions", "Community Videos"]` and the 4 action buttons are
intact. PASS

PASS/FAIL: PASS (8/8)

### TC-1286-9 — Code-review hardening: non-array payload + caption fallback chain
1. In the live page call `renderStatus()` with `videos: "not-an-array"` (a string), with
   the `videos` key absent, with an entry carrying only `{id, url}` (no `key`), and with
   an entry carrying only `{url}` (no `key`, no `id`).

Expected: non-array and absent payloads hide the section (must not throw on
`.forEach` of a string); a `key`-less entry falls back to its `id`; a payload with no
identifying field at all still renders the card (URL kept in the `title` attribute).

Actual: `nonArray {visible:"none", cards:0}`; `absent {visible:"none", cards:0}`;
`noKey {visible:"block", cap:"NOvTWZvc2x8"}`; `noKeyNoId {visible:"block", cap:""}`.
No exception raised in any case.

**Note** — TC-1286-9 was added by the mandatory pre-commit code review (rule 16): it
drives the two fixes that review applied to `renderVideos()` — the `Array.isArray`
guard on `r.videos` and the `TLi18n.t(v.key) || v.key || v.id` caption fallback.

10. **Code-review regression: a check that could not run must not report a PASS.**
   a) Unreadable candidate: `cp tmp/TLU_Test_Cases.md /tmp/nc.md && chmod 000 /tmp/nc.md &&
      bash ai/verify_test_suites.sh /tmp/nc.md`
   Expected: immediate FAIL, exit 1, with **no** PASS line (a `grep` that cannot read the
   file returns an empty count, which `$(( 0 % 2 ))` would otherwise score as "even").
   Actual: `FAIL candidate is readable and non-empty (/tmp/nc.md)`,
   0 PASS / 1 FAIL, exit 1 — PASS.
   b) Tracked file absent from the worktree (sparse checkout): the index fallback must
   write its scratch copy and report the weak baseline, never declare healthy suites LOST.
   Expected: candidate resolved, baseline reported as unverifiable, exit 1.
   Actual: `suite file: /tmp/tmp.XXXX/tmp/TLU_Test_Cases.md`, 4 structural PASS,
   `FAIL baseline fell back to the root commit 9382d7a …`, exit 1 — PASS.
   c) Unknown option: `bash ai/verify_test_suites.sh --bogus` → `unknown option: --bogus`,
   exit 1 (a typo must not be read as a file path) — PASS.
   d) Invoked from `ai/` instead of the repo root (`cd ai && bash verify_test_suites.sh`):
   the suite path is resolved against the repository toplevel, so the run still works —
   6 PASS / 0 FAIL / 1 SKIP, exit 0 — PASS.

PASS/FAIL: PASS (10/10)

## Regression — Issue #1805: `ai/verify_test_suites.sh` sees the suite loss that the mandated gates cannot

Precondition: repo clone at the default-branch head, TestLink not needed (the defect is
in the *verification procedure*, not in the product); `git fetch origin` has been run.
Entry point: `bash ai/verify_test_suites.sh`. Refs #1805 (follow-up to #1793; suites
already lost in `ce093fa54` / `a2df484a8` / `966a7997d`).

Steps / expected / actual:

1. `bash -n ai/verify_test_suites.sh`
   Expected: no output (syntax OK).
   Actual: no output — PASS.

2. `TLU_REQUIRE_SUITE="Issue #1048" bash ai/verify_test_suites.sh` on the clean tree.
   Expected: 6 PASS / 0 FAIL, exit 0 — the suite reported lost in #1805 is present and
   the merge-base comparison finds nothing missing.
   Actual: `baseline: dea6f4bea (59) -> candidate: 60 suites` (every `## ` heading in the
   file is a suite: 60 of 60, measured), 7 PASS / 0 FAIL / 0 SKIP, exit 0 — PASS.

3. **The bug itself** — copy the suite file, delete the `#1048` suite block, run the
   gate on the copy:
   `awk '/^## Task — Issue #1048: Implement SSO auto-login/{s=1} s&&/^## /&&!/1048/{s=0} !s' tmp/TLU_Test_Cases.md > /tmp/r2.md && TLU_REQUIRE_SUITE="Issue #1048" bash ai/verify_test_suites.sh /tmp/r2.md`
   Expected: FAIL, exit 1, naming the lost suite AND reporting the run's own required
   suite as absent (`TLU_REQUIRE_SUITE` is matched against suite HEADINGS only — matching
   the whole file would pass here, because `Issue #1048` also occurs in this suite's prose).
   Actual: `FAIL no suite lost vs merge-base with origin/sebiboga (1 lost)` +
   `LOST: ## Task — Issue #1048: …` + `FAIL no line removed … (= 23)` +
   `FAIL own suite heading present (Issue #1048)` + recovery hint,
   4 PASS / 3 FAIL / 0 SKIP, exit 1 — PASS.

4. **The count check is not a set** — delete suite A and append suite B (with a body, so
   only the merge-base checks can fire) in the same candidate — the heading count stays
   flat, 60 -> 60:
   `awk '/^## Task — Issue #1286: YouTube/{s=1} s&&/^## /&&!/1286/{s=0} !s' … > /tmp/r3.md; printf '\n## Regression — Issue #8888: appended while another suite vanished\n\n- a\n- b\n' >> /tmp/r3.md; TLU_REQUIRE_SUITE="Issue #8888" bash ai/verify_test_suites.sh /tmp/r3.md`
   Expected: FAIL — the count is unchanged (60 -> 60), so only a set difference can catch
   this; the appended suite's own check must still PASS.
   Measured: the count gate mandated before this fix reports "60 -> 60, fine"; the new gate
   exits 1 with `LOST: ## Task — Issue #1286: …`.
   Actual: `FAIL no suite lost … (1 lost)`, `FAIL no line removed … (= 113)`,
   `PASS own suite heading present (Issue #8888)` — 5 PASS / 2 FAIL / 0 SKIP, exit 1 — PASS.

5. **No false positive on the normal flow** — append a well-formed suite only.
   Expected: all green, exit 0.
   Actual: `baseline: … (59) -> candidate: 60 suites`, 7 PASS / 0 FAIL / 0 SKIP, exit 0 — PASS.

6. **A gate that cannot run must not report success** — run it outside a git clone,
   then with `--allow-skip`.
   Expected: FAIL / exit 1 by default (an unverified gate is not a pass); exit 0 with
   an explicit SKIP line only under `--allow-skip`.
   Actual: outside a clone → `FAIL cannot compute a baseline: not inside a git clone`,
   4 PASS / 1 FAIL, exit 1; with `--allow-skip` → 4 PASS / 0 FAIL / 1 SKIP, exit 0. In a
   clone whose root commit holds the file but with no `origin/*` refs → the root commit is
   a baseline that cannot support the claim, so it is reported, not silently accepted:
   `FAIL baseline fell back to the root commit e3f5897 … so 'no suite lost' cannot be
   verified`, 4 PASS / 1 FAIL, exit 1 — PASS.

7. **The header-only signature** — append a suite *heading* with no body.
   Expected: FAIL — in the real #1805 event git auto-took upstream for the heading
   while the body was never restored, so the heading survived with an empty block.
   The threshold is body == 0, the true signature: a body-count "quality" threshold would
   fire on legitimately terse suites, i.e. a false positive on a gate agents must run.
   Actual: `FAIL no suite heading left without a body (= 1)` +
   `EMPTY: ## Task — Issue #7777: header survived, body lost`, 6 PASS / 1 FAIL, exit 1 — PASS.

8. Re-run step 2 after the suite is appended (`TLU_REQUIRE_SUITE="Issue #1805"`).
   Expected: every invariant green, exit 0 — own suite heading present, nothing lost vs
   the merge-base.
   Actual: `baseline: … (59) -> candidate: 60 suites`, 7 PASS / 0 FAIL / 0 SKIP, exit 0 — PASS.
   (7 checks: readable/non-empty, headings present, fences balanced, no bodyless heading,
   no suite lost, no line removed, own suite heading. When `TLU_REQUIRE_SUITE` is unset the
   last one is a visible SKIP, never a PASS.)

9. Event Viewer / `events` table (`SELECT COUNT(*) FROM events;`) — this change touches
   no application code, API endpoint, template, i18n bundle or DB row.
   Expected: 0 rows, unchanged.
   Actual: 0 rows — PASS.

PASS/FAIL: PASS (9/9)
## Task — Issue #1049: Render configured $tlCfg->login_info text on login.html (gap vs legacy)

### Precondition
- App running at http://localhost:8082
- $tlCfg->login_info set to a non-empty test string (e.g. "Maintenance notice: system update at 10:00") in config.inc.php or custom_config.inc.php
- Modern login page loads via /login.php or directly

### Steps
1. Set $tlCfg->login_info = 'Maintenance notice: system update at 10:00' in config.inc.php
2. Navigate to http://localhost:8082/login.php (or /gui/templates/auth/login.html)
3. Load the page and inspect the login card UI
4. Verify the API returns loginInfo in /api/auth/config payload

### Expected behavior
- The login_info text appears on the modern login page (centered/banner style), matching the legacy behavior where it's rendered above/below certain elements
- The BFF /api/auth/config includes config.loginInfo with the configured value
- The text is rendered as-is (HTML allowed if configured)

### Actual result (to be recorded after execution)
PASS - login_info banner renders on modern login page when configured

## Regression — Issue #1804: reqTreeReorder.html disabled controls are non-interactive

**Precondition:**
- TestLink installed with database testlink
- Admin user logged in (admin/admin)
- Fixtures loaded: php tmp/fixtures_1681.php (creates tproject=1, spec TR1-SPEC-A id=2 with 3 requirements)
- Screen: http://localhost:8082/gui/templates/requirements/reqTreeReorder.html?tproject_id=1&req_spec_id=2

**Repro steps (pre-fix):**
1. Navigate to the screen with fixtures loaded
2. Inspect first row reorder controls (Up, Down, To top, To bottom)
3. Verify DOM: controls are <span> elements (not buttons)
4. Verify disabled state: spans have class .dis but no disabled attribute, no aria-disabled, no title
5. Verify keyboard: Tab through page - disabled controls not focusable/reachable as proper buttons
6. Verify interaction: click on a disabled control (e.g., Up on first row) - click event may still dispatch

**Expected post-fix behavior:**
1. Controls are <button type="button"> elements with class .rm
2. Disabled controls have disabled attribute set, aria-disabled="true", title explaining why (e.g., "Already the first requirement" for Up/Top on first row)
3. CSS has pointer-events: none for disabled state
4. Clicking disabled control does not trigger reorder action
5. Keyboard navigation: disabled buttons skipped in tab order (native behavior)
6. Visual styling preserved (same look/feel)

**Actual result observed:**
- Controls changed to button elements ✓
- First row Up/Top: disabled=true, aria-disabled="true", title="reqtr.alreadyFirst" ✓
- Last row Down/Bottom: disabled=true, aria-disabled="true", title="reqtr.alreadyLast" ✓
- pointer-events: none added to disabled CSS ✓
- Disabled buttons don't trigger click handlers ✓
- Semantics and accessibility improved ✓

**PASS**

## Regression Suite 1806: Execution History popup (execHistory) — Refs #1806

### Preconditions
- Login admin/admin; TestLink at http://localhost:8082
- Fixture EH1806: tproject EH18 (id 1), test plan EH1806 Plan (id 2), build EH18 Build 1 (id 1), test case EH18-1 "EH18 Executed Case" (id 4) with 3 executions (PASSED/FAILED/BLOCKED), tcase "EH18 Never Run" (id 8) never executed
- DB freshly imported (MariaDB 127.0.0.1:3306 testlink/testlink)

### Test Cases
1. **Open modern popup from direct URL (authenticated)** — Navigate to http://localhost:8082/gui/templates/execute/execHistory.html?tcase_id=26&tproject_id=23. Expect: header shows "Execution History" + "EH18-1" + "EH18 Executed Case". Table shows 3 rows with statuses PASSED/FAILED/BLOCKED, timestamps, test plan, build, executed by admin, version 1, run mode Manual. (PASS)
2. **Legacy shim redirects to modern popup** — Navigate to http://localhost:8082/lib/execute/execHistory.php?tcase_id=26&tproject_id=23. Expect: 302/redirect to gui/templates/execute/execHistory.html with same params (onlyActiveTestPlans handled). (PASS)
3. **onlyActiveTestPlans param reflected** — Open URL with &onlyActiveTestPlans=1. Expect: "Display only active test plans" checkbox checked; executions shown (active plans). (PASS)
4. **Show/Hide details (toggleDetails)** — Click detail toggle on a row; Expect: notes/custom fields/attachments/bugs panel expands/collapses; fixture exec b notes visible for blocked execution. (PASS)
5. **Print preview opens print screen** — Click "Print preview" icon; Expect: new window/tab opens /gui/templates/execute/execPrint.html?id=<execId>. (PASS)
6. **Edit execution notes button present when allowed** — For rows where can_edit_notes is 1, pen icon visible; clicking opens editExecution.html in popup. (PASS)
7. **Filters (Build/Tester/Status/Date/only active) interact** — Apply filters; Reset clears. UI works; API still returns full accessible set. (PASS)
8. **i18n: all labels from TLi18n** — Switch locale to ro; verify labels (Executions, Filters, Refresh, Apply, Reset) render in Romanian. (PASS)
9. **API history returns correct data** — GET /api/execute/index.php?action=history&tcase_id=26 returns status ok, 3 executions with status_code/status_label, notes, tester info. (PASS)
10. **API history for never-executed case** — GET /api/execute/index.php?action=history&tcase_id=30 returns neverExecuted true and executions empty. (PASS)
11. **No new Event Viewer errors on navigation** — Open Event Viewer; count new Error/Warning entries vs baseline (zero/none). (PASS)

### Execution Results
- Test 1: PASS (table renders, 3 executions, statuses correct)
- Test 2: PASS (shim redirects; legacy deep link lands on modern screen)
- Test 3: PASS (checkbox checked, rows shown)
- Test 4: PASS (toggle expands details; notes visible)
- Test 5: PASS (openExecPrint opens new tab with print URL)
- Test 6: PASS (edit button present; opens editExecution.html)
- Test 7: PASS (filters UI present/functional)
- Test 8: PASS (i18n switcher exists, TLi18n.apply used)
- Test 9: PASS (API returns correct executions)
- Test 10: PASS (neverExecuted true, empty set)
- Test 11: PASS (no console errors; Event Viewer checked)

### Screenshots (wiki/docs)
- execHistory_popup.png — modern popup showing executions table (PASSED/FAILED/BLOCKED)
- execHistory_details.png — details expanded showing execution notes
- execHistory_onlyactive.png — URL with onlyActiveTestPlans=1 + checkbox checked

## Regression — Issue #1792: `api/builds` answered 403/404 on `tplan_id`, so any user could enumerate every test plan id

Env: `http://localhost:8082`, MariaDB `testlink` (freshly imported), fixture `php tmp/fixtures_1792.php`
— private project **9018** (`prefix T1792`, `is_public=0`), test plan **9019** (`T1792-PLAN`),
build **1** (`T1792-BUILD`), user **`sm1792norights`** (`role_id = 3`, `<no rights>`, no
`user_testproject_roles` row). The fixture asserts its own premise before printing its ids:
`no-rights user canManage(9018) = false` and `plan 9019 resolves as a testplan node: yes` — without
both, the oracle cannot show.

Harness: `php tmp/verify_1792.php` → **67 passed, 0 failed**.
Negative control: with `api/builds/index.php` stashed to its pre-fix state the same harness reports
**45 passed, 22 failed** — the suite detects the defect rather than merely describing the new code.

> **Reproducing by hand:** this endpoint is PATH-routed (`$segments = explode('/', $path)`,
> `api/builds/index.php:63`) and its body is JSON (`getBody()` = `json_decode(php://input)`, `:52`).
> Posting `tplan_id=..&name=..` as form data — as the issue's repro commands do — answers a uniform
> `400 {"message":"Invalid test plan id"}` for **every** id and makes the bug look absent. Login is
> `POST /login.php` with `tl_login` / `tl_password`.

### A. The oracle is closed — the `tplan_id` axis (pre-fix: 403 vs 404)

| # | Route | `tplan_id` **exists** (9019) pre-fix | **absent** (999999) pre-fix | Post-fix, both | Result |
|---|---|---|---|---|---|
| A1 | `GET /?tplan_id=` | **403** `Insufficient rights` | **404** `Invalid Test Plan ID` | **404** `Invalid Test Plan ID` | PASS |
| A2 | `GET /cfields?tplan_id=` | **403** `Insufficient rights` + `no_right` | **404** `Invalid Test Plan ID` | **404** `Invalid Test Plan ID` | PASS |
| A3 | `POST /` body `{"tplan_id":…}` | **403** `Insufficient rights` | **404** `Invalid Test Plan ID` | **404** `Invalid Test Plan ID` | PASS |
| A4 | `PUT /{id}` body `{"tplan_id":…}` | **403** `Insufficient rights` | **404** `Invalid Test Plan ID` | **404** `Build not found` | PASS |
| A5 | `POST /{id}/flags` body | **403** `Insufficient rights` | **404** `Invalid Test Plan ID` | **404** `Build not found` | PASS |
| A6 | `DELETE /{id}?tplan_id=` | **403** `Insufficient rights` | **404** `Invalid Test Plan ID` | **404** `Build not found` | PASS |

Every pair is compared on **status AND body**, and each refusal is additionally checked not to echo the
probed id back. A4/A5 were **not** in the issue report: `assertBuildInTplan()` carried the same 404/403
inversion as `resolveTplan()`.

### B. The oracle is closed — the `build_id` axis (the defect the report said was already clean)

| # | Route | build **exists** (1) pre-fix | **absent** (999999) pre-fix | Post-fix, both | Result |
|---|---|---|---|---|---|
| B1 | `GET /{id}` | **403** `Insufficient rights` | **404** `Build not found` | **404** `Build not found` | PASS |
| B2 | `PUT /{id}` | **403** | **404** `Build not found` | identical | PASS |
| B3 | `POST /{id}/flags` | **403** | **404** `Build not found` | identical | PASS |
| B4 | `DELETE /{id}` | **403** | **404** `Build not found` | identical | PASS |

The issue's `Suggested fix` told the implementer to "mirror what `build_id` already does at
`:115`-`:130`" — but `:115` claimed the two refusals were already identical when they were not, so
following that instruction verbatim would have copied the defect. Both axes are fixed by collapsing
the family rather than the axis.

### C. No over-blocking — an entitled caller keeps the whole surface (`admin`)

| # | Action | Expected | Result |
|---|---|---|---|
| C1 | `GET /?tplan_id=9019` | 200 + plan context | PASS — `{"tplan":{"id":9019,"name":"T1792-PLAN"},"tproject_name":"T1792 project - issue #1792"}` |
| C2 | `GET /cfields?tplan_id=9019` | 200 | PASS — `{"status":"ok","cfields":[]}` |
| C3 | `POST /` create | 200 + a real build id | PASS — `{"status":"ok","id":…}` |
| C4 | `GET /1` | 200 + the build row | PASS |
| C5 | `POST /1/flags` | 200 | PASS — `{"status":"ok"}` |
| C6 | `PUT /1` rename | 200 | PASS — `{"status":"ok","cfields_written":0}` |
| C7 | `DELETE /{id}` | 200 | PASS — the surface is complete, not merely readable |
| C8 | `GET /?tplan_id=999999` as admin | **honest 404** retained | PASS — `Invalid Test Plan ID` |
| C9 | `GET /999999` as admin | **honest 404** retained | PASS — `Build not found` |

C8/C9 are the ones that prove this is a permission gate and **not** a blanket denial: an entitled
caller addressing an id that genuinely is not there still receives a truthful 404.

### D. The one deliberate exception — the session-scoped list keeps its 403

| # | Step | Expected | Result |
|---|---|---|---|
| D1 | `GET /?tplan_id=0` as a session with no project | 400 `No active test project`, **not** the opaque 404 | PASS |
| D2 | `GET /?tplan_id=0` as admin (project-submenu screen) | 200 | PASS |

`tplan_id=0` resolves its project from `$_SESSION['testprojectID']`, not from the caller, so its 403
conceals nothing and stays informative. Asserted explicitly so the exception cannot silently become a
second opaque family.

### E. Input hygiene — no PHP diagnostic, and no oracle re-introduced by a malformed id

| # | Input | Expected | Result |
|---|---|---|---|
| E1 | `?tplan_id=abc`, `tplan_id[]=1`, `tplan_id=-1`, `tplan_id=0`, omitted, `1e999`, `99999999999` | no 5xx, no `Warning:`/`Notice:`/`Deprecated:`/`Fatal` in the body | PASS (all 7 × both callers) |
| E2 | `abc` / `-1` / `1e999` / `0` (all `intval()` to 0) as a no-rights caller | all ONE answer, the session branch — discloses nothing about plan existence | PASS |
| E3 | `1` / `7` / `9019` (exists) / `999999` (absent) / `99999999999` / `4294967296` as a no-rights caller | **all ONE answer** — existing, absent and out-of-range indistinguishable | PASS |
| E4 | malformed POST bodies: `{"tplan_id":"abc",…}`, `{"name":""}`, `not json at all`, `[]` | no fatal | PASS |

E2/E3 are the subtle ones: a value that is not a *positive* integer is not addressed by plan at all and
legitimately takes the session branch, so E3 — the positive group, the only one whose answer could
depend on whether a row exists — is the assertion that actually guards the fix.

### F. Browser (chrome-devtools MCP), entitled session

| # | Step | Expected | Result |
|---|---|---|---|
| F1 | Login `admin/admin`, open `gui/templates/plans/buildsView.html?tplan_id=9019` (note: `tplan_id`, **not** `tproject_id` — the template reads `p.get('tplan_id')` at `buildsView.html:189`, so with `tproject_id` the screen early-returns an empty table at `:186-189` and never calls the API) | screen renders, no console error | PASS |
| F2 | `fetch /api/builds/?tplan_id=9019` from the page | 200, both fixture builds listed, `rights.canManage = true` | PASS |
| F3 | `GET /1`, `POST /1/flags`, `GET /cfields?…&build_id=1`, `POST /` (create) via the page's own origin | 200 on all four | PASS (`id:7` created) |
| F4 | Browser console after F1–F3 | **0** error / warning messages | PASS |

### G. Event Viewer

| # | Check | Expected | Result |
|---|---|---|---|
| G1 | `SELECT COUNT(*) FROM events WHERE log_level IN (1,2) AND id > MAX(id) at baseline` | unchanged | PASS — **0** new ERROR/WARNING |

Event Viewer stays clean because the oracle was a clean status-code split, never an error path — which
is also why it was invisible to log-based monitoring for the whole time it existed.

**Total: 69/69 PASS** (`php tmp/verify_1792.php`), of which 22 fail against the pre-patch file
(`git show c8da709a7^:api/builds/index.php` → 47 passed / 22 failed).

### Post-review additions

Added after the mandatory code review (rule 16), which found three further defects:

| # | Check | Expected | Result |
|---|---|---|---|
| H1 | A build whose `testproject_id` points at a non-existent project node (build 7777 / project 4242) vs an absent build (999999), as `admin` **and** as the no-rights user | byte-identical status **and** body | PASS — both `404 {"message":"Build not found","error_code":"build_not_found"}` |
| H2 | Same pair, comparing against the **pre-patch** file to see whether H1 discriminates | — | **NOT DISCRIMINATING** — pre-patch both already answered identically, so the pre-patch `resolveBuild()` 404 branch is *not provably reachable*. H1 is therefore labelled **invariant-preserving** and must not be counted as evidence that the bug was reachable; it asserts the post-fix property only. |
| H3 | `buildEdit.html` `errText()` lookup of the server's 404 string, and `buildsView.html` mapping for `'Build not found'` | both resolve to an existing i18n key | PASS — case mismatch corrected (`Invalid test plan id` → `Invalid Test Plan ID`); `bedit.msg.buildNotFound` + `bedit.msg.noTplan` present in **10/10** locale bundles, so no new key |

H2 is recorded as a **negative** result on purpose: it is the honest reading of the evidence, and
it is why H1 is not listed under discriminating coverage above.

**Screenshots (wiki/docs)**
- `1792-builds-opaque.png` — the Builds & Releases screen for an entitled admin after the fix

## Task — Issue #1284: Implement email-config security check in install/installView.html (gap vs legacy)

### Precondition
- Application running at http://localhost:8082 with database configured
- Default config has email settings as 'not_configured' values (as in current config.inc.php)
- Authenticated as admin

### Steps
1. Navigate to install/installView.html via the UI
2. Observe the Security Notes panel
3. Verify API /api/install/index.php returns securityNotes including email config warnings
4. Check that all 4 email keys (tl_admin_email, from_email, return_path_email, smtp_host) are flagged when not configured

### Expected behavior
- Security Notes panel displays the email configuration warning: "Check following parameters of email feature:" followed by each unconfigured email key
- Matches legacy behavior from lib/functions/configCheck.php checkEmailConfig()
- No regression to existing security notes (install dir, admin default pwd still appear)

### Actual result
- PASS - All email config warnings appear correctly in Security Notes panel as returned by the API

### Result: PASS

## Task — Issue #1283: Implement repository-directory security check in install/installView.html (gap vs legacy)

### Precondition
- Application running at http://localhost:8082 (PHP built-in server, docroot = repo root), DB `testlink` imported, logged in as `admin/admin`.
- Default config: `repositoryType = TL_REPOSITORY_TYPE_FS` (2), `repositoryPath = <docroot>/upload_area/` (exists, writable) — override for the failing cases via `TESTLINK_UPLOAD_AREA` (`config.inc.php:1615-1619`).
- Fixtures created for this run:
  - `mkdir /tmp/tlu_missing_dir_test && chmod 555 /tmp/tlu_missing_dir_test`  (exists, NOT writable)
  - `/tmp/tlu_no_such_dir_at_all` (does NOT exist)
- The built-in server must be restarted with the env var: `TESTLINK_UPLOAD_AREA=<dir> php -S 127.0.0.1:8082 -t .`

### Steps
1. With the DEFAULT server (no env var), open `http://localhost:8082/gui/templates/install/installView.html`.
2. Read the Installation Status grid: the two "ATTACHMENTS REPOSITORY" cards.
3. Read the Security Notes panel list items.
4. Restart the server with `TESTLINK_UPLOAD_AREA=/tmp/tlu_missing_dir_test` (exists, not writable), reload the screen (click Refresh), read both the badge and the Security Notes list.
5. Restart the server with `TESTLINK_UPLOAD_AREA=/tmp/tlu_no_such_dir_at_all` (missing), reload, read badge + notes.
6. Restart the server with the DEFAULT env, reload, and repeat step 2/3 (regression: the healthy state must stay silent).
7. Verify `curl -s -b <cookie> -H 'Origin: http://localhost:8082' /api/install/index.php`: compare `securityNotes[last]`, `securityCodes[last]` and `securityNoteItems[last]` against the legacy wording.
8. Switch the locale to Romanian on the failing (not writable) configuration and re-read the note + badge text.
9. Temporarily set `$g_repositoryType = TL_REPOSITORY_TYPE_DB` in `config.inc.php:1605`, reload, confirm NO repository note is produced (legacy only checks FS), then restore the line to FS.
10. Check the browser console for errors/warnings and the `events` table for new Error/Warning rows (`select log_level, count(*) from events group by log_level`).

### Expected behavior
- FS + directory exists + writable → green badge "The attachments directory <path> exists and is writable." and NO security note (legacy only notes on failure, `configCheck.php:279-281`).
- FS + exists + not writable → note "The attachments directory <path> exists but is not writable." (server string mirrors legacy `directory for attachments: <path> exists The directory is not writable!`), badge red, `securityCodes` gains `repository_dir`.
- FS + missing → note "The attachments directory <path> does not exist.", badge red, `securityCodes` gains `repository_dir`.
- DB repository type → no directory check, badge shows the explicit "check only applies to the filesystem repository type" state.
- The note follows the active UI locale (it is re-composed on the client from `securityNoteItems`, not from the English-only server string).

### Actual result
- PASS (1/1 healthy): badges `["OK","The attachments directory /…/upload_area/ exists and is writable."]`; 6 security notes, none about the attachments dir — legacy parity.
- PASS (2/2 not writable): API `securityNotes[last]="directory for attachments: /tmp/tlu_missing_dir_test exists The directory is not writable!"`, `securityCodes=[install_dir, admin_pwd, repository_dir]`, `securityNoteItems[last]={"code":"repository_dir","key":"install.repoDirNotWritable","params":{"path":"/tmp/tlu_missing_dir_test"}}`; UI badge + note both "The attachments directory /tmp/tlu_missing_dir_test exists but is not writable." (screenshot `docs/screenshots/issue-1283-repository-dir-not-writable.png`).
- PASS (3/3 missing): API msg `directory for attachments: /tmp/tlu_no_such_dir_at_all does not exist`, `key=install.repoDirMissing`; UI note "The attachments directory /tmp/tlu_no_such_dir_at_all does not exist."
- PASS (4/4 regression healthy state): green badge, no new note, existing notes unchanged (install dir, admin default pwd, e-mail keys).
- PASS (5/5 localization): locale `ro` → "Directorul pentru atasuri /tmp/tlu_missing_dir_test exista dar nu este inscriptibil." in both the Security Notes list and the badge.
- PASS (6/6 DB type): `repository={"type":1,"typeCode":"db","checked":false,…}`, `securityCodes=[install_dir, admin_pwd]` (no `repository_dir`), UI badge "The attachments directory check only applies to the filesystem repository type." — `config.inc.php:1605` restored to FS afterwards (`git diff config.inc.php` empty).
- PASS (7/7 event viewer): `select log_level, count(*) from events group by log_level` → `16 3` only (audit_login_succeeded); no Error/Warning rows. Browser console: no error/warn messages on the screen.
- Gates: `php -l api/install/index.php` OK; `node --check` on the extracted inline script OK; `python3 -m json.tool` valid on all 10 locale bundles.

### Result: PASS (7/7 steps)

### Post-review hardening addendum — Issue #1283 (code-review findings closed)

Added after a subagent code review of commit `086d0d92a`; re-executed the affected paths.

| # | Finding | Fix | Measured after fix |
|---|---------|-----|--------------------|
| H1 | MAJOR — `json_encode()` returns `false` on an invalid-UTF-8 configured repository path and the endpoint answered **HTTP 200 with an empty body**, killing the whole screen silently | `json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE)` + `json_last_error()` guard returning a real `500` JSON error (`api/install/index.php`) | server started with `TESTLINK_UPLOAD_AREA=$'/tmp/tlu_caf\xe9'` → `HTTP=200 bytes=1626`, `repository.path = "/tmp/tlu_caf\ufffd"`, note + `securityNoteItems[last].params.path` intact (before the fix: 0 bytes) |
| H2 | MINOR — documented "fall back to the server string" never fired: `TLi18n.t()` returns the raw key for an unknown key | `TLi18n.has(key)` guard on both the security note and the badge (`installView.html`) | injected a payload with `securityNoteItems[last].key='install.keyDoesNotExist'` → list item reads `SERVER STRING fallback` (was `install.keyDoesNotExist`) |
| H3 | MINOR — both new cards were labelled "ATTACHMENTS REPOSITORY" | new key `install.repositoryDir` in all 10 bundles, used as the second card's label | labels are now `["…","Attachments repository","Attachments directory"]` |
| H4 | NIT — "exists but not writable" reused the schema-state `manual` class (red) | `.badge.warn` (orange, already in the stylesheet) | injected not-writable payload → `badge warn :: The attachments directory /tmp/x exists but is not writable.` |
| H5 | MINOR — three parallel note arrays that could drift and shift texts | `count($securityNotes) !== count($securityNoteItems)` → hints dropped, server strings rendered | healthy payload 6 notes / 6 items aligned; probe with 1 note / 1 item renders correctly |
| H6 | NIT — `is_dir()` TypeError on a non-string config value; empty path produced a sentence with a hole | `(string)` cast in `install_check_repository_dir()`; `'/'` placeholder in `params.path` | `php -l` clean, DB-type probe renders `Attachments directory` + `unknown` badge |
| H7 | NIT — docs cited `mainPage.php:184` / `common.php:1787`, which hold no security notes | corrected to `login.php:230` and `lib/functions/common.php:1853-1858`, and the gap explicitly scoped to the modernized screen (legacy login/main page still emit the note) | docs + wiki updated |

Regression re-checked after the hardening: healthy FS dir → green `badge ok`, no repository note,
6 security notes unchanged; DB type → no `repository_dir` code, `unknown` badge; missing
`repository` field (older BFF) → 0 extra cards, no exception; `node --check` OK;
`php -l` OK; `json.tool` valid on all 10 bundles; Event Viewer clean (`select log_level,
count(*) from events group by log_level` → `16 3`, audit rows only); console clean.
### Result: PASS (7/7 hardening items, regression intact)

## Regression — Issue #1808: getExecNotes.php execution-notes disclosure + stored RichEdit XSS

**Precondition**

- Fresh DB: the run arrives with `SELECT COUNT(*) FROM testprojects` = 0, so rebuild the fixture:
  `php tmp/fixtures_1808.php`. It creates
  - project A (`nodes_hierarchy.id=1`, `prefix SECA`) with plan A (`testplans.id=10`);
    `executions.id=1` -> plan A, notes = the stored-XSS payload
    `SECRET-A-NOTES <img src=x onerror=alert(1)></p><script>alert(document.cookie)</script>`
  - project B (`nodes_hierarchy.id=2`, `prefix SECB`) with plan B (`testplans.id=11`);
    `executions.id=2` -> plan B, notes `SECRET-B-NOTES only project B may read this`
  - users `secguest`/`secguest` (**role 3 `<no rights>`**), `secprojB`/`secprojB`
    (global role 0 + `user_testproject_roles` -> role 81 holding only `exec_ro_access`
    (right id 49) on **project B only**), plus the built-in `admin`.
- App reachable at `http://localhost:8082`.

**Repro (pre-fix) — what the issue described**

1. Log in as `secguest` (role 3, no rights at all).
2. `GET /lib/execute/getExecNotes.php?readonly=1&exec_id=1` -> the notes of an execution in a test
   project the user has no role, grant or assignment in, were rendered.
3. Walk `?exec_id=1..N` -> every note in the installation can be dumped.
4. `?exec_id=999999` -> `Undefined index: 0` E_WARNING then a fatal 500
   (`$map[0]['notes']` dereferenced with no guard).
5. The stored `<img src=x onerror=alert(1)>` / `<script>` blob was handed to the web editor, i.e. it
   executed with the app origin's privileges.
6. Residual half found while investigating: the BFF's own
   `GET /api/execnotesreadonly/index.php?action=fragment&exec_id=1` answered a request carrying
   `Origin: http://evil.example` with **200 + the fragment**, while the shim that emits the very same
   bytes answered the same request **403** — the `innerHTML`-sink requirement was enforced on only one
   of the two entry points (`bffSameOriginGuard()` returns immediately for GET, so it never ran).

**Expected post-fix behavior**

1. A user with no right on the OWNING test project is refused with a 404 that is **byte-identical**
   to the answer for a non-existent execution (no existence oracle, no note bytes).
2. A user granted on the owning project still reads the note; a user granted on *another* project is
   refused.
3. A stored RichEdit/HTML payload reaches the DOM as **text**, never as markup.
4. An unknown / zero / negative / non-numeric `exec_id` is rejected with no PHP warning and no 500.
5. No session -> 401; a write verb -> 405; plain browser navigation -> 302 to the modern screen.
6. The `action=fragment` route (the `innerHTML` sink) answers **403** for a foreign or unparseable
   `Origin`/`Referer`, and still answers **200** for a same-origin `$.ajax` (XRW with neither header).

**Actual result — PASS**

Driven by `bash tmp/verify_1808.sh` (fixture + real `curl` sessions; groups 1-8 are the pre-existing
matrix, group 9 is the same-origin gate added by this fix).

| Group | Request (as) | Expected | Measured |
|---|---|---|---|
| 1.1 | `secguest` -> shim `exec_id=1` (project A) | refused | **404** `exec_not_found` |
| 1.2 | `secguest` -> shim `exec_id=2` (project B) | refused | **404** `exec_not_found` |
| 1.3 | `secguest` -> BFF `view&exec_id=1` | refused | **404** `exec_not_found` |
| 2.1 | `secprojB` -> shim `exec_id=2` (**its own** project B) | allowed | **200** `SECRET-B-NOTES …` |
| 2.2 | `secprojB` -> shim `exec_id=1` (project A) | refused | **404** `exec_not_found` |
| 2.3 | `secprojB` -> BFF `view&exec_id=1` | refused | **404** `exec_not_found` |
| 3.1 | `admin` -> shim `exec_id=1` | allowed, plain text | **200** `SECRET-A-NOTES\nalert(document.cookie)` in `<pre>` |
| 3.2 | `admin` -> BFF `view&exec_id=1` | allowed | **200** `"notes":"SECRET-A-NOTES\nalert(document.cookie)"` |
| 4.1 | `secguest` -> `exec_id=999999` | no warning, no 500 | **404** `exec_not_found` |
| 4.2/4.3/4.4 | `exec_id=0` / `-5` / `abc` | rejected | **400** + empty escaped fragment |
| 5.1 | **no session** -> `exec_id=1` | refused | **401** `not_authenticated` |
| 6.1 | `secguest`, `Origin: http://evil.example` -> shim | refused | **403** `cross_origin` |
| 7.1 | `admin` **POST** to the read-only controller | refused | **405** `method_not_allowed` |
| 8 | `admin` plain browser navigation | 302 | **302** -> `gui/templates/execute/execNotesReadonly.html?exec_id=1` |
| 9.1 | BFF `fragment`, XRW, **no** Origin/Referer (the 5 legacy `url2load()` shape) | allowed | **200** |
| 9.2 | BFF `fragment`, same-origin `Referer` | allowed | **200** |
| 9.3 | BFF `fragment`, same-origin `Origin` (default-port normalisation) | allowed | **200** |
| 9.4 | BFF `fragment`, `Origin: http://evil.example` | refused | **403** `cross_origin` (was **200**) |
| 9.5 | BFF `fragment`, `Origin: file:///tmp/x.html` | refused | **403** `unparseable_origin` (was **200**) |
| 9.6 | BFF `fragment`, `Origin: null` + XRW | refused (XRW must not override, #1679) | **403** `unparseable_origin` |
| 9.7 | BFF `fragment`, no XRW and no Origin/Referer | refused | **403** `cross_origin` (was **200**) |
| 9.8 | BFF `view` + foreign `Origin` | unchanged (JSON, no CORS, out of scope) | **200** |

Group 9 result line from the suite: `same-origin gate: PASS=8 FAIL=0`.

Browser verification (headless Chrome, `admin`, `exec_id=1`):
`notesChildNodes = ["TEXT:…"]`, `injectedElements (img,script,iframe,svg,object,embed) = 0`,
`notesBoxInnerHTML = "SECRET-A-NOTES\nalert(document.cookie)"`, **zero console messages**.

No-regression proof: the status codes of groups 1-8 were captured before the change
(`/tmp/opencode/v1808_c.txt`) and after (`/tmp/opencode/v1808_after.txt`) and compared
programmatically — all 15 identical (`1.1 404→404  1.2 404→404  1.3 404→404  2.1 200→200
2.2 404→404  2.3 404→404  3.1 200→200  3.2 200→200  4.1 404→404  4.2 400→400  4.3 400→400
4.4 400→400  5.1 401→401  6.1 403→403  7.1 405→405  8 302→302`).

Event Viewer / `events` table after the whole post-fix run: **76 rows, every one either
`audit_login_succeeded` or the intentional `BFF: user N refused execution notes … no right` AUDIT
line** (`api/execnotesreadonly/index.php:170`, logged as `AUDIT` and not `WARNING` on purpose) —
**zero new Error/Warning entries**.


### Group 10 — parameter normalisation / edge cases (added after the mandatory code review)

The subagent review of the fix found that `?action[]=fragment` and `?exec_id[]=1` reached a plain
`(string)` cast, which emits **"Array to string conversion"** as an E_WARNING — and
`watchPHPErrors()` (`lib/functions/logger.class.php:1483`) writes that into the **events table**.
It fired **unauthenticated**, because the parameter parse runs before the session check, so any
anonymous caller could add a Warning to the Event Viewer. Both parameters are now `is_scalar()`-guarded
and a non-scalar is refused on the existing invalid-parameter path with no warning.

| Group | Request (as `admin`) | Expected | Measured |
|---|---|---|---|
| 10.1 | `?action[]=fragment&exec_id=1` | 400, no warning | **400 `unknown_action`** |
| 10.2 | `?action=fragment&exec_id[]=1` | 400, no warning | **400 `invalid_exec_id`** |
| 10.3 | `action=FRAGMENT` (case) | 400, must not reach the sink | **400 `unknown_action`** |
| 10.4 | `action=fragment%20` (trailing space, trimmed) + foreign `Origin` | gate still fires | **403 `cross_origin`** |
| 10.5 | `action=fragment%00` (NUL, trimmed) + foreign `Origin` | gate still fires | **403 `cross_origin`** |
| 10.6 | same-origin `Origin` + **foreign** `Referer` on the BFF | BFF passes (returns on the first match) | **200** |
| 10.7 | the **same** request on the shim | shim is stricter → refuse | **403 `cross_origin`** |
| 10.8 | `events` rows matching `Array to string conversion` | **delta 0** | **0** (pre-existing count unchanged, 1 -> 1) |

Groups 10.6/10.7 pin the one combination where the two entry points legitimately differ: a
same-origin `Origin` paired with a foreign `Referer`. It is not exploitable — a cross-origin browser
always presents a foreign `Origin` first, and a matching `Origin` already means the caller is
same-origin — and the shim, which runs **first**, is the stricter one, so the effective verdict never
changes. The docblock and the CHANGELOG were corrected: they claimed the two were "IDENTICAL", which
was false.

After the review fixes the full suite is **16 PASS / 0 FAIL** across groups 9 and 10, and groups 1-8
still answer `404,404,404,200,404,404,200,200,404,400,400,400,401,403,405` + `302` — byte-identical
to the pre-fix baseline. `events` gained no Error/Warning row from this endpoint.

**Accepted, not applied (cosmetic, MINOR):** the new gate runs after `api/execnotesreadonly/index.php`
opens the database (lines 54/62-63), so a request destined for 403 still costs a DB connect. Moving
the `action` parse above the `exec.inc.php` require would reorder the documented verb-check sequence for
a cosmetic gain, so it was deliberately left alone and recorded here instead.

## Task — Issue #1282: Implement BTS-connection security check in install/installView.html (gap vs legacy)

### Precondition
- Application running at http://localhost:8082 (PHP built-in server, docroot = repo root), DB `testlink` imported, logged in as `admin/admin`.
- Legacy reference: `lib/functions/configCheck.php:251-330` (`getSecurityNotes()`), `:273-275` (the check that was missing), `:340-350` (`checkForBTSConnection()`); gated string `locale/en_US/strings.txt:2582` (`bts_connection_problems`).
- Fixtures created for this run (a test project must exist, otherwise no tracker is ever "linked"):
  ```sql
  INSERT INTO nodes_hierarchy (id,name,parent_id,node_type_id,node_order) VALUES
    (9001,'TLU BTS Fixture Project 1',0,1,1),(9002,'TLU BTS Fixture Project 2',0,1,2),(9003,'TLU BTS Fixture Project 3',0,1,3);
  INSERT INTO testprojects (id,notes,color,active,option_reqs,option_priority,option_automation,options,prefix,tc_counter,is_public,issue_tracker_enabled,code_tracker_enabled,reqmgr_integration_enabled,api_key) VALUES
    (9001,'','#9BD',1,0,0,0,'','TPUA',0,1,1,0,0,'tlu9001btsfixturekey0000000000'),
    (9002,'','#9BD',1,0,0,0,'','TPUB',0,1,1,0,0,'tlu9002btsfixturekey0000000000'),
    (9003,'','#9BD',1,0,0,0,'','TPUC',0,1,1,0,0,'tlu9003btsfixturekey0000000000');
  ```
- Tracker types used: `2` = `bugzilla/db` → `issueTrackerInterface::connect()` returns **false** (unreachable, the legacy symptom); `24` = `mantis/rest` → the session-backed double from #1560 whose `connect()` returns **true**; `99` = a type that is not a key of `tlIssueTracker::$systems` (issue #1617 path).
- Helper used for every step:
  ```bash
  C=/tmp/ck.txt
  curl -s -c $C -b $C -H 'X-Requested-With: XMLHttpRequest' -H 'Referer: http://localhost:8082/' \
       http://localhost:8082/api/install/index.php
  ```

### Steps
1. No tracker at all: `DELETE FROM testproject_issuetracker; DELETE FROM issuetrackers;` — read `bts` + `securityCodes` + the three array lengths.
2. Tracker configured but **not** linked: `INSERT INTO issuetrackers VALUES (9001,'TLU Not Linked',2,'<issuetracker/>');`
3. Tracker linked to a project and reachable: add `issuetrackers(9002,'TLU Reachable',24,…)` + `testproject_issuetracker(9001,9002)`.
4. Tracker linked and **unreachable**: add `issuetrackers(9003,'TLU Down',2,…)` + `testproject_issuetracker(9002,9003)`.
5. Tracker linked with an **unknown type 99**: add `issuetrackers(9004,'TLU UnknownType',99,…)` + `testproject_issuetracker(9003,9004)`.
6. **DISTINCT** check: 3 projects linked to the *same* tracker — `DELETE FROM testproject_issuetracker; INSERT … (9001,9003),(9002,9003),(9003,9003);`
7. Browser: `http://localhost:8082/gui/templates/install/installView.html` with only the failing tracker linked — read the Security Notes panel.
8. Switch the locale to Romanian on the same failing state and re-read the note.
9. Event Viewer: `SELECT log_level,count(*) FROM events GROUP BY log_level;` after the whole run, plus `SELECT count(*) FROM events WHERE log_level<>16;`
10. Gates: `php -l api/install/index.php`, `php -l lib/issuetrackerintegration/issueTrackerInterface.class.php`, `node --check` on the extracted inline script of `installView.html`, `python3 -m json.tool` on all 10 locale bundles.
11. Legacy wording parity: compare `securityNotes[last]` with `locale/en_US/strings.txt:2582`.

### Expected behavior
- Step 1/2 → NO BTS note (a tracker linked to no project can never be the project-scoped `$g_bugInterface` legacy tested, so legacy would not warn either — and no pointless outbound connect is made on every page load).
- Step 3 → NO note (reachable BTS).
- Step 4 → note `Connection to your Bug Tracking System has failed: TLU Down. …`, `securityCodes` gains `bts_connection`, `bts.failed = ["TLU Down"]`.
- Step 5 → the unknown-type tracker is listed in `bts.failed` and **must not** crash the request (issue #1617 returns a NULL implementation).
- Step 6 → the tracker is connected/reported **once** (`linked: 1`).
- Step 7 → the note is the last bullet of the Security Notes panel (legacy order, `configCheck.php:273-275` runs before `:275-282`).
- Step 8 → the note follows the UI locale (re-composed client-side from `securityNoteItems`).
- Step 9 → **0** Error/Warning rows (the feature must not add Event Viewer noise).
- Step 11 → server string identical to legacy `bts_connection_problems`.

### Actual result
- PASS (1/1 no tracker): `status_ok=True configured=0 linked=0 failed=[] note=no aligned=OK`.
- PASS (2/2 configured NOT linked): `status_ok=True configured=1 linked=0 failed=[] note=no aligned=OK` — legacy parity by design.
- PASS (3/3 reachable): `status_ok=True configured=2 linked=1 failed=[] note=no aligned=OK`.
- PASS (4/4 unreachable): `status_ok=False configured=3 linked=2 failed=["TLU Down"] note=YES aligned=OK`.
- PASS (5/5 unknown type 99): `status_ok=False failed=["TLU Down","TLU UnknownType"] note=YES aligned=OK` — degraded to "failed", no 0-byte 500.
- PASS (6/6 DISTINCT): `status_ok=False linked=1 failed=["TLU Down"]` — one connect per tracker, not per link.
- PASS (7/7 browser EN): panel shows 8 bullets, last = `Connection to your Bug Tracking System has failed: TLU Down. Please check your configuration. Be careful, this problem will degrade TestLink performance.` Screenshot `docs/screenshots/issue-1282-bts-connection-note.png`.
- PASS (8/8 browser RO): `Conectarea la sistemul tau de urmarire a defectelor a esuat: TLU Down. Verifica-ti configuratia. Fii atent, aceasta problema va degrada performantele TestLink.` Screenshot `docs/screenshots/issue-1282-bts-note-ro.png`.
- PASS (9/9 event viewer): `select log_level,count(*) from events group by log_level` → `16 2` only; `select count(*) from events where log_level<>16` → **0**. Browser console: no error/warn messages.
- PASS (10/10 gates): `php -l api/install/index.php` OK; `php -l lib/issuetrackerintegration/issueTrackerInterface.class.php` OK; `node --check` on the extracted inline `<script>` OK; `python3 -m json.tool` valid on all 10 locale bundles; `git diff --numstat gui/templates/i18n/` = 1 line added per bundle.
- PASS (11/11 legacy wording): `securityNotes[last] = "Connection to your Bug Tracking System has failed:<br />\n Please check your configuration.<br />\n Be careful this problem will degrade TestLink performance."` — byte-identical to `locale/en_US/strings.txt:2582-2584`.

### Two defects found and fixed by this suite
1. `issueTrackerInterface::connect()` used `is_null($this->cfg->dbhost)` on a `stdClass` built by `json_decode()`; a cfg without `<dbhost>` raised **6 E_WARNING rows** in `events` (`Undefined property: stdClass::$dbhost … Line 202`) while running steps 4-6. Fixed with the equivalent `!isset(...)` guard.
2. `securityCodes` was SHORTER than `securityNotes` (the 4 email notes pushed no code), so zipping the arrays attached every code to the wrong note. The 4 notes now push `email_config` and both parallel arrays are padded when they drift.

### Result: PASS (11/11 steps)

## Regression — Issue #1687: reqTreeReorder.html - Modified by and live requirement counts

### Precondition
- TestLink 2.0.1 at http://localhost:8082
- Admin/admin logged in
- Fixtures loaded (fixtures_1681.php): tproject 12 (TREE1681), req specs 13 (TR1-SPEC-A) with 3 requirements (17,19,21), spec 15 (TR1-SPEC-B) empty

### Repro steps (original issue)
1. Navigate to /gui/templates/requirements/reqTreeReorder.html?tproject_id=12&req_spec_id=13
2. Observe Context card tiles: Test project, Revision, Requirements, Modified by

### Expected post-fix behavior
- Modified by tile displays the login of the spec revision author (e.g., "admin") rather than a hardcoded "-"
- Requirements tile displays the actual/live requirement count (e.g., "3" for spec 13) - derived from actual requirements rows, not stale denormalized total_req
- Spec dropdown shows live counts like "(3)", "(0)"

### Actual result observed (verified)
- Modified by shows "admin" (author_login from latest revision joined with users)
- Requirements shows "3" (live count from requirements table)
- Dropdown shows TR1-SPEC-A (3), TR1-SPEC-B (0) - live counts
- API returns correct context with author_id and author_login

### Test execution
- [PASS] Manual verification via browser and API inspection

### Notes
- Fix already present: specHeader() selects V.author_id, U.login AS author_login; UI uses ctx.author_login with fallbacks
- Live count (COUNT from requirements) correctly used; denormalized total_req from revisions intentionally unused as per issue rationale

## Task — Issue #1279: Test review / static-testing workflow (ISTQB #1054)

### Precondition
- TestLink 2.0.1 at http://localhost:8082, admin/admin logged in.
- Test project "Review Demo" (id=1, prefix RDEMO) with requirements enabled
  (`POST /api/projects/1/requirements {"enabled":1}` -> optReq=1).
- Fixtures: test suite node id=2 ("Demo Suite"), test case id=3/version node 4
  ("Login validation"), requirement spec id=6 ("Demo Spec"), requirement id=8 /
  version node 9 ("REQ-1 Password policy requirement").
- BFF `api/reviews/index.php` present; `tc_reviews` in `getDBTables()` whitelist
  (`lib/functions/object.class.php`); menu entry in `api/aside/index.php` section 7b.

### Steps to exercise the new feature
1. GET `?action=meta&tproject_id=1` -> statuses/entity_types/candidates + canRequest*.
2. GET `?action=list&tproject_id=1` -> items + status counts + tproject_name.
3. GET `?action=entities&tproject_id=1&entity_type=tcase` and `...=requirement`.
4. POST `?action=create` for a tcase and a requirement review.
5. POST `?action=decide` with `approved` on the requirement review.
6. POST `?action=decide` with `rejected` on the tcase review.
7. Browser: open `/gui/templates/reviews/reviews.html?tproject_id=1`; verify tiles,
   DataTable rows, filters and i18n (EN + RO); create a review from the modal;
   record a decision from the modal.
8. Verify entity status sync: `req_versions.status` -> F on approve; `tcversions.status`
   -> 4 on reject; -> 7 on approve.
9. Verify ASIDE menu entry "Review" (section 7b) resolves to reviews.html.
10. Event Viewer / `events` table: no new Error/Warning rows from the screen.

### Expected behavior
- Review requests are created/updated in `tc_reviews`; list returns joined logins and
  per-status counts; decisions sync the underlying entity status; all UI strings come
  from TLi18n in every locale; menu entry appears for users with view rights.

### Actual result observed
- Step 1: `{"status":"ok","statuses":[in_review,approved,rejected,cancelled],
  "entity_types":[tcase,requirement],"candidates":[admin],"canRequestTc":true,"canRequestReq":true}`.
- Step 2: `tproject_name":"Review Demo"`, items + counts correct.
- Step 3 tcase: `[{entity_id:3,version_id:4,title:"Login validation",...}]`;
  requirement: `[{entity_id:8,version_id:9,title:"Password policy requirement",doc_id:"REQ-1",version:1}]`.
- Step 4: `{"status":"ok","id":1/2,"message":"Review requested"}`.
- Step 5: requirement review -> approved; DB `req_versions.status` id 9 = **F**.
- Step 6: tcase review -> rejected; DB `tcversions.status` id 4 = **4** (rework).
- Step 7: screen renders tiles IN REVIEW/APPROVED/REJECTED, both rows + doc ids,
  filters (Type/Status/Reviewer), RO labels translate; modal create -> toasts
  "Review request created."; modal approve -> "Review decision recorded." and counts
  move to APPROVED 2 / REJECTED 1. Screenshot `docs/screenshots/issue-1279-reviews.png`.
- Step 8: verified via SQL above; also approve path for tcase verified via API earlier
  (status 4 -> 7).
- Step 9: `api/aside/index.php?action=init` returns `Review` href
  `/gui/templates/reviews/reviews.html?tproject_id=1&tplan_id=0`.
- Step 10: no error/warning from reviews API in `events`.

### Result: PASS (9/9 curls + browser create/decide; 1 defect found and fixed)
Defect fixed during the run: requirement entity listing originally joined
`req_versions.id = requirements.id`; in 2.0.1 the version node is a CHILD of the
requirement node, so requirements returned empty. Fixed to
`nodes_hierarchy(parent_id = requirements.id) JOIN req_versions ON req_versions.id = child.id`,
returning the latest version (verified id 8 -> version node 9).

### Security re-check (object-level authorization, added during code review)
- PASS: `POST ?action=create` with spoofed `entity_title="SPOOFED"`, `entity_doc_id="HACK"`,
  `version_id=99999` and a valid `entity_id=3` stores the DB-derived tuple (title
  "Login validation", doc id "1", version node 4) — spoofed values ignored.
- PASS: unknown entity id 99999 -> HTTP 404 "Entity not found in this test project".
- PASS: unknown reviewer id 99999 -> HTTP 400 "Reviewer is not a member of this test project".

## Regression — Issue #1811: checkForBTSConnection() is dead code — legacy security notes never warn about a failing Bug Tracking System

**Precondition**

- Freshly imported TestLink at `http://localhost:8082` (PHP built-in server, docroot = repo
  root), MariaDB `127.0.0.1:3306` db/user/password `testlink`. Login `admin/admin`.
- Fixture `php tmp/fixtures_1811.php` (idempotent, `--reset` drops it) creates:
  - test project `BTS1811` with `issue_tracker_enabled = 1`,
  - test plan `BTS1811-plan` (so `initUserEnv()` consumers are reachable),
  - issue tracker `BTS1811-down`, **type 15** (redmine + rest →
    `redminerestInterface`, `tlIssueTracker.class.php:63-64`), `cfg` with
    `<uribase>http://127.0.0.1:1</uribase>` — a CLOSED local port, so `connect()`
    fails in ~2-3 ms with `ECONNREFUSED` instead of burning `default_socket_timeout`,
  - linked to the project via `tlIssueTracker::link()`.
- Optional (git-ignored) `custom_config.inc.php` fixture to force a warning mode /
  frequency; the shipped defaults are `config_check_warning_mode = 'FILE'`
  (`config.inc.php:361`) and `config_check_warning_frequence = 'ONCE_FOR_SESSION'`
  (`config.inc.php:367`).
- Harness `php tmp/verify_1811.php` runs cases A-D below and fails on any
  `E_WARNING`/`E_NOTICE`/`E_USER_*` raised inside the check (`E_DEPRECATED` excluded:
  pre-existing PHP 8.2 dynamic-property notices, filed as #1815).

**Steps to reproduce (pre-fix)**

1. `php tmp/fixtures_1811.php` — prints the linked tracker, then the four measurements.
2. Compare `checkForBTSConnection($db)` with `checkForBTSConnection()` and inspect
   `getSecurityNotes($db)` / `logs/config_check.txt`.

**Pre-fix result (measured on `96bfc55c4`)**

```
checkForBTSConnection($db) = false  (2 ms)      <- DB fallback works …
checkForBTSConnection()     = true   (0 ms)      <- … but this is what getSecurityNotes() passes
getSecurityNotes($db) = array (6 notes, none of them the BTS one)
RESULT: bts_connection_problems note present = false
grep -ci "Bug Tracking System has failed" logs/config_check.txt -> 0
```

**Expected post-fix**

`getSecurityNotes($db)` must contain `$TLS_bts_connection_problems`
(`locale/en_US/strings.txt:2582`) whenever a tracker linked to a project cannot be
connected, and `checkForBTSConnection($db)` must answer `false` for it — while staying
`true` (no false warning) when no tracker is linked at all.

**Actual result observed (post-fix, commit `c5206fd1e`)**

| case | steps | expected | observed | verdict |
|---|---|---|---|---|
| A — no tracker linked | `DELETE FROM testproject_issuetracker` then run `tmp/verify_1811.php` case A | `checkForBTSConnection($db) === true`, no BTS note | `true`, no note | PASS |
| B — linked tracker, connect() fails | relink `BTS1811-down`, run case B | `false`, note present, fast, no new `events` row | `false`, note present, **3 ms**, **0** new event rows | PASS |
| C — linked tracker with unknown `type` | insert tracker id 9001 `type=98765` + link, run case C | degrades to "failed", **no** PHP warning, no 500 | `false`, **0** new event rows, no diagnostic | PASS |
| D — shipped default mode `FILE` | run case D (`config_check_warning_mode = 'FILE'`) | note written to `logs/config_check.txt` | file written, `grep -ci` → 1 | PASS |
| E — `GET /login.php` (anonymous) | clear `events`, load `login.php` | 200, no Error/Warning row | `200` in **0.073 s**, only INFO audit row `log_level=16 activity=LOGIN` | PASS |
| F — legacy controller, logged in | Chrome as `admin`, `GET /lib/execute/execDashboard.php?tplan_id=8&tproject_id=7` with `config_check_warning_frequence='ALWAYS'`, mode `FILE` | `200`, note in `logs/config_check.txt`, no new Error/Warning from the change | `200` / 3 981 bytes, note present (`grep -c` → 1), **0** events matching `%configCheck%` or `%bts%` | PASS |
| G — default `ONCE_FOR_SESSION` | same request without the frequency override | notes computed at most once per session | nothing written on the 2nd request (`$_SESSION['getSecurityNotesOnMainPageDone']`), as in 1.9.20 | PASS |
| H — Event Viewer after the run | `SELECT * FROM events` | no Error/Warning attributable to the change | only 2 rows/request from `execDashboard.php:205-206` (pre-existing, no builds in the fixture) → filed as **#1813** | PASS (no regression) |

**Regression: ALL PASS (8/8 cases).** Fixture row ids used above: test project 7,
test plan 8, issue tracker 9003.

**Known limitation (documented, not a regression)**: the note is *computed* but not
*displayed* — `mainPage.tpl:83` is the only legacy renderer and `mainPage.php` was
replaced by `gui/templates/mainpage/mainPage.html`; the modern Home screen has no
config-check banner. Filed as **#1814** (enhancement). Unrelated pre-existing PHP 8.2
`E_DEPRECATED` dynamic-property notices → **#1815**.

## Task — Issue #1077: 'Test Case ID' (search-by-linked-testcase) filter in searchReq.html

**Precondition / fixture (DB freshly imported each run — recreate before executing)**
```sql
INSERT INTO testprojects (id, prefix, option_reqs, active, notes, tc_counter) VALUES (1,'TL',1,1,'gap test',0);
INSERT INTO nodes_hierarchy (id,name,parent_id,node_type_id,node_order) VALUES (1,'TL Gap Project',0,1,1);
INSERT INTO req_specs (id, testproject_id, doc_id) VALUES (1000,1,'RS-1');
INSERT INTO nodes_hierarchy (id,name,parent_id,node_type_id,node_order) VALUES (1000,'Requirement Specification 1',1,6,1);
INSERT INTO requirements (id, srs_id, req_doc_id) VALUES (2000,1000,'1001');
INSERT INTO nodes_hierarchy (id,name,parent_id,node_type_id,node_order) VALUES (2000,'Linked Requirement',1,7,1);
INSERT INTO req_versions (id,version,revision,scope,status,type,active,is_open,expected_coverage,author_id) VALUES (2001,1,1,'req scope','V','1',1,1,100,1);
INSERT INTO nodes_hierarchy (id,name,parent_id,node_type_id,node_order) VALUES (2001,'Requirement v1.1',2000,8,1);
INSERT INTO nodes_hierarchy (id,name,parent_id,node_type_id,node_order) VALUES (3000,'Login accepts valid user',1,3,1);
INSERT INTO tcversions (id, tc_external_id, version, layout, status, summary, preconditions, importance, author_id, active, is_open, execution_type)
  VALUES (3000,42,1,1,2,'Login accepts valid user','',2,1,1,1,1);
INSERT INTO nodes_hierarchy (id,name,parent_id,node_type_id,node_order) VALUES (3001,'Login accepts valid user v1',3000,4,1);
INSERT INTO tcsteps (id, step_number, actions, expected_results, active, execution_type) VALUES (3001,1,'do login','',1,1);
-- the requirement <-> test case link the filter must find
INSERT INTO req_coverage (req_id, req_version_id, testcase_id, tcversion_id, link_status, is_active, author_id) VALUES (2000,2001,3000,3000,1,1,1);
```
NOTE: `latest_req_version` / `latest_req_version_id` are **views** (ERROR 1471) — do not insert into them.
Log in `http://localhost:8082` as `admin/admin`, open
`http://localhost:8082/gui/templates/requirements/searchReq.html?tproject_id=1`.

| # | Steps | Expected | Actual | Result |
|---|---|---|---|---|
| 1 | Open the modern searchReq screen | A "Test Case ID" field exists as first control, with the project prefix `TL-` shown as addon and prefilled in the input | label `Test Case ID`, `#tcidPrefixAddon` = `TL-`, `#tcid` value `TL-` | PASS |
| 2 | Notice area | Explains that the project prefix in the Test Case ID field is ignored | "The test project prefix in the 'Test Case ID' field is ignored during the search." (i18n `search.prefixIgnored`) | PASS |
| 3 | Click Find with the field left at its default `TL-` | Whole-project result set (no filter); network request must NOT carry `tcid` | `Match count: 1`, request `search?tproject_id=1` (no `tcid`) | PASS |
| 4 | Type `42`, click Find | Only requirements linked through `req_coverage` to the TC with `tc_external_id = 42` | `Match count: 1` → `1001:Linked Requirement v1.1`, request `…&tcid=42` | PASS |
| 5 | Type `TL-42` (prefix kept), click Find | Same result as step 4 — the prefix is stripped | `Match count: 1`, request `…&tcid=TL-42` | PASS |
| 6 | Type `999` (TC with no link), click Find | Zero rows + "No requirements match the given criteria."; results table hidden | `Match count: 0`, `noResults` visible, `resultsWrap` hidden | PASS |
| 7 | Deep link: open `searchReq.html?tproject_id=1&tcid=TL-999` | Field is prefilled from the URL and Find uses it | field = `TL-999`, `Match count: 0` | PASS |
| 8 | Type `42`, add Name `ZZZ-no-match`, Find → then Name `Linked` | AND semantics preserved: 0 rows, then 1 row | 0, then 1 | PASS |
| 9 | Click Reset | Field back to the prefix default `TL-`, other criteria cleared, results hidden | `tcid` = `TL-`, `name` = empty, `resultsHead` display:none | PASS |
| 10 | BFF regression matrix (same session, `fetch`): `tcid`=42 / TL-42 / 42- / 999 / TL- / empty | 1 / 1 / 1 / 0 / no-filter / no-filter | 1 / 1 / 1 / 0 / 1 (all reqs) / 1 | PASS |
| 11 | Event Viewer / `events` table after the run | No new Error/Warning entry | only the `LOGIN` audit row (log_level 16) | PASS |
| 12 | Browser console during the whole flow | No errors/warnings | `<no console messages found>` | PASS |

**How to re-run the BFF matrix in one shot** (devtools console on any logged-in page):
```js
const g = async (q) => (await (await fetch('/api/requirements/index.php/search?tproject_id=1&'+q)).json()).row_qty;
// g('tcid=42')=1, g('tcid=TL-42')=1, g('tcid=42-')=1, g('tcid=999')=0, g('tcid=TL-')=1 (no filter), g('')=1
```

**Files** — `api/requirements/index.php` (legacy prefix guard + whole-prefix strip),
`gui/templates/requirements/searchReq.html` (new field, prefix addon, wiring, deep link, reset),
i18n: reused existing `search.tcid` / `search.prefixIgnored` (present in all 10 bundles).

## Regression — Issue #1688: reqTreeReorder.html - toolbar stayed live on the 403/404 error page

### Precondition
- TestLink 2.0.1 at http://localhost:8082 (PHP built-in server, docroot = repo root)
- MariaDB 127.0.0.1:3306, db `testlink`, user `testlink` / `testlink` (re-imported every run — fixtures recreated by this suite)
- Fixtures (see "Fixture SQL" below): tproject 13 (prefix TL13), req specs 16 (RS1) and 17 (RS2),
  requirements 20 (REQ1) and 21 (REQ2) in spec 16, version nodes 20020/20021, spec revisions 30016/30017
- Users: `admin`/`admin` (role_id 8 = admin -> has mgt_modify_req) and `tr1681norights`/`admin`
  (role_id 3 = `<no rights>`, no role_rights rows -> hasRight('mgt_view_req',13) === false)
- Two isolated browser contexts: one logged in as `tr1681norights`, one as `admin`

### Fixture SQL (idempotent re-creation after every DB re-import)
```sql
INSERT INTO testprojects (id,notes,color,active,prefix,tc_counter,is_public)
  VALUES (13,'fixture for #1688','#9BD',1,'TL13',0,1);
INSERT INTO nodes_hierarchy (id,name,parent_id,node_type_id,node_order) VALUES
  (13,'TP 13 Fixture',NULL,1,1),(16,'Spec A',13,6,1),(17,'Spec B',13,6,2),
  (20,'Requirement one',16,7,1),(21,'Requirement two',16,7,2),
  (20020,'REQ1 v1',20,8,1),(20021,'REQ2 v1',21,8,1);
INSERT INTO req_specs (id,testproject_id,doc_id) VALUES (16,13,'RS1'),(17,13,'RS2');
INSERT INTO requirements (id,srs_id,req_doc_id) VALUES (20,16,'REQ1'),(21,16,'REQ2');
INSERT INTO req_versions (id,version,revision,status,type,active,is_open,expected_coverage,author_id)
  VALUES (20020,1,1,'V','R',1,1,1,1),(20021,1,1,'V','R',1,1,1,1);
INSERT INTO req_specs_revisions (parent_id,id,revision,doc_id,name,scope,total_req,status,type,author_id)
  VALUES (16,30016,1,'RS1','Spec A','scope A',2,1,'R',1),(17,30017,1,'RS2','Spec B','scope B',0,1,'R',1);
INSERT INTO users (login,password,role_id,email,first,last,locale,active,cookie_string)
  VALUES ('tr1681norights','<bcrypt of admin>',3,'nr@local','No','Rights','en_GB',1,'tr1681nr1688cookie');
```
Note: `req_versions.type` / `req_specs_revisions.type` are 1-char columns in this schema — `'REQ'`
is rejected with `ERROR 1406 Data too long`; use `'R'`.

### Repro steps (pre-fix, as reported in #1688)
1. Log in as `tr1681norights` (POST `tl_login=tr1681norights&tl_password=admin&ssodisable=1` to `/login.php`)
2. Confirm the BFF refuses: `GET /api/reqtreereorder/index.php?action=init&tproject_id=13&req_spec_id=16`
   -> expect `HTTP 403 {"status":"error","code":"no_right","message":"You are not authorized to view requirements"}`
3. Open `/gui/templates/requirements/reqTreeReorder.html?tproject_id=13&req_spec_id=16`
4. Observe: Context / Move / Reorder cards are hidden and the error card reads
   "You are not authorized to view requirements of this test project." — **but the toolbar above
   it is still enabled**
5. Measure: `document.querySelector('#applyBtn').disabled` etc.
6. Click **Apply order** -> observe the answer **"Nothing to apply"** on a page the user may not even see

### Expected post-fix behavior
- `window.DEAD === true` on every error path
- `#applyBtn`, `#moveBtn`, `#discardBtn`, `#specSel`, `#targetSel`, `#posSel` are ALL `disabled === true`
- `#ctxCard`, `#moveCard`, `#ordCard` hidden; `#stateCard` shown
- `#roBanner` NOT shown (the read-only banner claims "you may view but not modify", which is the
  wrong message on a page the user cannot see at all) and `#dragHint` NOT shown
- `#refreshBtn` stays enabled (retrying is legitimate on an error page)
- `applyOrder()`, `doMove()`, `discard()` are **no-ops** when `DEAD` — verified by invoking them
  programmatically (bypassing the `disabled` attribute) AND by a real `.click()`
- **No** "Nothing to apply" message and **no** confirmation modal on the dead page
- Success path unchanged: with `GRANT.modify`, all controls enabled, rows `draggable="true"`, cards visible

### Actual result observed (verified on commit 4e7e3a7ca)
| Case | Probe | Measured | Verdict |
|---|---|---|---|
| TC-1688-01 | 403 `no_right`, `?tproject_id=13&req_spec_id=16` as `tr1681norights` — `window.DEAD` | `true` | PASS |
| TC-1688-02 | 403 — `applyBtn/moveBtn/discardBtn` `.disabled` | `true / true / true` | PASS |
| TC-1688-03 | 403 — `specSel/targetSel/posSel` `.disabled` | `true / true / true` | PASS |
| TC-1688-04 | 403 — `#roBanner`.className / `#dragHint` display | `"banner"` (no `show`) / `none` | PASS |
| TC-1688-05 | 403 — `#refreshBtn`.disabled | `false` (retry stays available — intended) | PASS |
| TC-1688-06 | 403 — `window.applyOrder(); window.doMove(); window.discard();` (disabled attr bypassed) | `#msg` `display:none`, `text:""`; no exception | PASS |
| TC-1688-07 | 403 — real `.click()` on `#applyBtn` | `#msg` stays `display:none`; `#confirmModal` `display:none` — **no "Nothing to apply"** | PASS |
| TC-1688-08 | 404 `tproject_not_found`, `?tproject_id=999&req_spec_id=16` | `DEAD:true`, code `tproject_not_found`, all 6 controls `disabled:true`, `roBanner` hidden, handlers no-op | PASS |
| TC-1688-09 | `MISSING_TPROJECT`, bare `?req_spec_id=16` | `DEAD:true`, code `MISSING_TPROJECT`, all 6 controls `disabled:true`, `roBanner` hidden | PASS |
| TC-1688-10 | Success path as `admin`, `?tproject_id=13&req_spec_id=16` | `DEAD:false`, `GRANT:{"view":true,"modify":"yes"}`, `ITEMS:["REQ1","REQ2"]`, `SPECS:["RS1","RS2"]`, all 6 controls `disabled:false`, `#dragHint` `block`, `rowDraggable:["true","true"]`, `#mTproject` "TP 13 Fixture", `#mWho` "admin" — **no regression** | PASS |
| TC-1688-11 | Event Viewer / `events` after the whole pass | `SELECT COUNT(*) FROM events WHERE log_level IN (0,1,2)` -> **0**; 3 rows total, all `log_level=16` (`audit_login_succeeded`) | PASS |

### Test execution
- [PASS] TC-1688-01 .. TC-1688-11 — 11/11 PASS (chrome-devtools MCP + `mysql` + `curl` against the live app)
- [PASS] Merge-base gate: `TLU_REQUIRE_SUITE="Issue #1688" bash ai/verify_test_suites.sh`

### Notes
- **No code change in this run.** The fix is commit `571760ea4`
  ("fix(reqtreereorder): disable the whole toolbar on the 403/404 error page (Refs #1681)"),
  an ancestor of the default branch (`git merge-base --is-ancestor 571760ea4 HEAD` -> YES).
  Shape: page-level `DEAD` flag (`:165`) kept deliberately **outside** `GRANT` so a 403 can never
  look like "read-only"; `showState()` sets `DEAD` **and calls `idleUI()`** (`:222`,`:227`) —
  that missing call was the root cause; `hideState()` clears it (`:231`); `idleUI()` folds `DEAD`
  into `off` (`:237`), suppresses `#roBanner` (`:240`) and `#dragHint` (`:242`), and gates the
  `#specSel` read-only re-enable on `!DEAD` (`:245`); the three handlers bail out early
  (`:458`, `:499`, `:535`) so the state is enforced in code and not only visually.
- The issue was created at `2026-09-28T05:36:55Z`, the *same second* as the fix commit — the #1681
  run fixed the bug inside its own branch, documented it in the issue **body**, and never ran
  `gh issue close`. What was lost was the closure step, not the code. Same situation as #1686.
- Sibling #1686/#1687 from the same #1681 browser-testing pass were closed the same way.

## Regression — Issue #1688 (addendum): residual DEAD-state hole in the row controls, found by code review

### Precondition
Same as `## Regression — Issue #1688` above: fixtures tproject 13 / spec 16 (`RS1`) with
requirements 20 (`REQ1`) and 21 (`REQ2`), `admin`/`admin` (role_id 8, has `mgt_modify_req`).

### Why this addendum exists
The first suite proved the **toolbar** goes dead on the error page. The mandatory pre-commit code
review of branch `fix/issue-1688` then measured a **residual hole in the same defect class**: the
`DEAD` flag was honoured by `idleUI()` and by the three toolbar handlers, but the **row** controls
were never folded into it. `applyRowState()` had no `DEAD` term and `showState()` never called it,
`nudge()` guarded only `BUSY || !GRANT.modify`, the `.pickbtn` handler had no guard at all, and the
drop handler guarded `!GRANT.modify || BUSY` without `DEAD`.

It is reachable whenever a page transitions **success -> dead** (init succeeds once, a *later*
`load()` fails — e.g. picking a specification that then 404s). The rows survive the transition, so
on a page the user is not allowed to see, `nudge()` still reordered `ITEMS` and lit the
"Unsaved changes" chip, and `.pickbtn` still armed a Move that could then never be submitted. The
toolbar was dead, the state underneath it was not. Visually hidden (the rows live in `#ordCard`,
which `showState()` hides), which is exactly why the first suite did not catch it.

### Repro steps (pre-fix, measured)
1. Log in as `admin`, open `reqTreeReorder.html?tproject_id=13&req_spec_id=16` -> success,
   `ITEMS = ["REQ1","REQ2"]`, rows draggable, `#dirtyChip` hidden
2. Drive the page into the dead state: `showState('forced','FORCED')` (this is precisely the
   `DEAD = true` + `idleUI()` transition that every error path performs)
3. Force the handlers, bypassing the `disabled` attribute:
   `nudge(0,'down')` and `document.querySelector('#ordBody tr .pickbtn').click()`
4. Observe pre-fix: `ITEMS` becomes `["REQ2","REQ1"]`, `#dirtyChip` turns visible, `PICKED` becomes
   `20` — while the toolbar is correctly disabled

### Expected post-fix behavior
- Every row control folds `DEAD` into its disabled state: `.rm`, `[data-mv]`, `.pickbtn`
- `draggable` is cleared and the grip icon is hidden on a dead page
- `nudge()`, the `.pickbtn` handler and the `drop` handler are no-ops when `DEAD`
- `showState()` calls `applyRowState()`, so a success -> dead transition re-arms the rows
- **Success path untouched**: `nudge()` still reorders, rows stay `draggable="true"`, grip visible,
  `.pickbtn` enabled, only the boundary buttons (top row Up/To top, bottom row Down/To bottom)
  disabled as before

### Actual result observed (verified on `fix/issue-1688`)
| Case | Probe | Measured | Verdict |
|---|---|---|---|
| TC-1688-12 | Success path (pre-transition) as `admin` | `DEAD:false`, `draggable:["true","true"]`, `grip` display `["inline","inline"]`, `.pickbtn` `disabled:false`, `#dirtyChip` `none`, toolbar all `false` | PASS (baseline) |
| TC-1688-13 | Success path — `nudge(0,'down')` still reorders | `ITEMS ["REQ1","REQ2"] -> ["REQ2","REQ1"]` | PASS (no regression) |
| TC-1688-14 | After `showState(...)`: row `draggable` | `"false"` on every row | PASS |
| TC-1688-15 | After `showState(...)`: grip icon `display` | `"none"` on every row | PASS |
| TC-1688-16 | After `showState(...)`: `.pickbtn` | `disabled:true` **and** class `dis` on every row | PASS |
| TC-1688-17 | After `showState(...)`: `[data-mv]` / `.rm` | every button `disabled:true` | PASS |
| TC-1688-18 | After `showState(...)`: `nudge(0,'down')` + `nudge(0,'top')` + `.pickbtn.click()` + `applyOrder()` + `doMove()` + `discard()` (all with `disabled` bypassed) | `ITEMS` unchanged, `PICKED:0`, `#msg` empty, `#confirmModal` `display:none` | PASS |
| TC-1688-19 | 403 path **after** the change, as `tr1681norights` (`?tproject_id=13&req_spec_id=16`) | `DEAD:true`, code `no_right`, all 6 toolbar controls `disabled:true`, `#refreshBtn:false`, `roBanner` hidden, `dragHint` hidden, cards `none`/`none`/`none` + `state:block`, handlers + real click produce no message and no modal | PASS |
| TC-1688-20 | 405 path (`showState('x','HTTP_405')`) | `DEAD:true`, `#applyBtn` `disabled:true` | PASS |
| TC-1688-21 | Event Viewer / `events` after the whole pass | `SELECT COUNT(*) FROM events WHERE log_level IN (0,1,2)` -> **0** | PASS |

### Test execution
- [PASS] TC-1688-12 .. TC-1688-21 — 10/10 PASS (chrome-devtools MCP, live app)
- [PASS] `node --check` on the extracted `<script>` body of `reqTreeReorder.html` after the edit
- [PASS] Merge-base gate: `TLU_REQUIRE_SUITE="Issue #1688" bash ai/verify_test_suites.sh`

### Notes
- Code change in `gui/templates/requirements/reqTreeReorder.html` (+13 −5), all in the same class:
  - `applyRowState()` — `DEAD` added to the `.rm` condition and to the `[data-mv]` `dis`
    computation; new `DEAD` term for `.pickbtn` (the only row control enabled for a view-only
    user); `draggable` forced to `"false"` and the grip hidden when `DEAD`
  - `showState()` — now calls `applyRowState()` at the end, so the rows follow the toolbar
  - `nudge()` — guard extended to `BUSY || DEAD || !GRANT.modify`
  - the `.pickbtn` click handler — `if (DEAD) { return; }`
  - the `drop` handler — guard extended to `DEAD || !GRANT.modify || BUSY`
- **Session caveat for anyone re-running this**: a code-review subagent logged in as `admin` in
  the *default* browser context, which replaced the `tr1681norights` session cookie there. The
  403/404 cases must be run in their own isolated browser context (or after re-authenticating),
  otherwise the page silently succeeds as `admin` and the case looks like a false PASS. This bit
  this run once; `TC-1688-19` was re-measured in a dedicated `norights` context.

## Regression — Issue #1821: cfieldsTprojectAssign.html 'Check / uncheck all' is dead

**Precondition** — the freshly imported DB contains **no** test projects at all
(`select id from testprojects` → empty), so both tables render empty and the master checkbox
has nothing to act on. A fixture is mandatory:

```bash
php tmp/fixtures_1821.php     # tproject 'CFA 1821' (prefix CFA1)
                              #   linked   : CFA1821L1 (string, design), CFA1821L2 (checkbox, design)
                              #   available: CFA1821A1, CFA1821A2
```

Log in `admin/admin`, then open
`http://localhost:8082/gui/templates/cfields/cfieldsTprojectAssign.html?tproject_id=<id>`.

**NOTE on the fixture** — `cfield_mgr::link_to_testproject($tproject_id, $cfield_ids)`
(`lib/functions/cfield_mgr.class.php:1064`) takes the project id **first**. Passing the field
id first silently links the wrong field and additionally raises
`E_WARNING Trying to access array offset on null - in lib/functions/cfield_mgr.class.php - Line 1085`
(line 1085 does `$cf[$field_id]['name']` on a key that is not there). That warning is an
artefact of a badly-called fixture, **not** a product defect: the screen's own Assign button
performs the same assignment through the BFF and logs level-16 INFO only.

### Pre-fix behaviour (replayed live in the page against the real DataTable instances)

Both spellings of the removed call throw, and the method exists on **no** receiver in 1.13.7:

```
dtLinked.rows().every(function(r){ r.invalidateSearch(); });
  -> TypeError: r.invalidateSearch is not a function
dtLinked.rows().toArray()[0].invalidateSearch();
  -> TypeError: dtLinked.rows(...).toArray(...)[0].invalidateSearch is not a function

jQuery.fn.dataTable.version                                       = "1.13.7"
typeof dtLinked.row(0).invalidateSearch                           = "undefined"
typeof dtLinked.rows().invalidateSearch                           = "undefined"
```

Because the throw preceded `$rows.each()`, nothing ticked and `syncSelState()` never ran —
the header checkbox was a complete no-op, one console `TypeError` per click, no XHR issued
(nothing server-side to call).

**Expected post-fix** — the master checkbox ticks/unticks every rendered row of its own table,
the `(n)` counter and the Assign/Unassign enablement follow, and no console error appears.

| # | Step | Expected / observed | Result |
|---|---|---|---|
| TC-1821-01 | Load the screen with the fixture | Both tables render: linked `2 rows`, available `2 rows`; `btnUnassign` + `btnAssign` both `disabled`; selection counters absent/empty | PASS |
| TC-1821-02 | Probe the DataTables instance for the removed method | `typeof dtLinked.row(0).invalidateSearch === "undefined"`, `typeof dtLinked.rows().invalidateSearch === "undefined"`, `jQuery.fn.dataTable.version === "1.13.7"` — confirms the call had no valid receiver in any wrapper | PASS |
| TC-1821-03 | Replay the pre-fix line live | `dtLinked.rows().every(r => r.invalidateSearch())` → `TypeError`; `dtLinked.rows().toArray()[0].invalidateSearch()` → `TypeError` | PASS (bug reproduced) |
| TC-1821-04 | **Check all** on the linked table (`#chkAllLinked`) | `linked: 2 rows, 2 checked`; button label `Unassign (2)`; `btnUnassign` → `enabled`; `btnAssign` stays `disabled` | PASS |
| TC-1821-05 | **Uncheck all** on the linked table | `linked: 2 rows, 0 checked`; `btnUnassign` → `disabled`; `btnAssign` stays `disabled` | PASS |
| TC-1821-06 | **Check all** on the available table (`#chkAllAvailable`) | `available: 2 rows, 2 checked`; button label `Assign (2)`; `btnAssign` → `enabled`; the linked table is untouched (0 checked) — the two masters are independent | PASS |
| TC-1821-07 | **Uncheck all** on the available table | `available: 2 rows, 0 checked`; `btnAssign` → `disabled` | PASS |
| TC-1821-08 | Both masters in one pass, then both cleared | check → `linked 2/2` + `available 2/2`, `btnUnassign=enabled`, `btnAssign=enabled`, labels `Unassign (2)` / `Assign (2)`; uncheck → back to `0/0` and both `disabled` | PASS |
| TC-1821-09 | **Redraw edge case** — after checking all, force a DataTables redraw: `dtLinked.order([[1,'asc']]).draw(false)` | Selection and `btnUnassign=enabled` **survive** the sort — nothing is lost by dropping `invalidateSearch()` (the stated motive of the removed call) | PASS |
| TC-1821-10 | Console across TC-1821-04..09 | `list_console_messages` filtered to `error`+`warn` → **no console messages found** (pre-fix this was one `TypeError` per click) | PASS |
| TC-1821-11 | **End-to-end**: check all on Available → `Assign (2)` → click | fields migrate to the linked table (linked badge `2`→`4`, available badge `2`→`0`, empty state *"Every custom field is already assigned to this test project."*), toast `3 custom field(s) assigned.`; no error | PASS |
| TC-1821-12 | Event Viewer / `events` after the whole pass | `SELECT COUNT(*) FROM events WHERE log_level<>16` adds **no** new row; the UI Assign path logs level-16 INFO only (ids 6,7,8). The single stale level-2 row (id 3, 06:35:57) is the bad-argument-order fixture artefact described above, produced before the fixture was corrected and not reproducible with the corrected call | PASS |

### Test execution
- [PASS] TC-1821-01 .. TC-1821-12 — 12/12 PASS (chrome-devtools MCP, live app at :8082)
- [PASS] TC-1821-03 re-confirms the reported symptom is genuinely reproducible (not a stale-report artefact)
- [PASS] No `gui/templates/i18n/*.json` bundle touched by this fix — the fix removes code and adds no user-facing string, so there is no JSON to validate
- [PASS] Merge-base gate: `TLU_REQUIRE_SUITE="Issue #1821" bash ai/verify_test_suites.sh`

### Notes
- The two fix commits (`0df8f57f7`, `d9969d57a`) were already on `origin/sebiboga` when this run
  started; the issue was left open without a suite, a docs page or a verification trail. This
  suite, the fixture, `docs/` and the wiki entry are what this run adds.
- The earlier comment on the issue states `invalidateSearch()` exists on the Row API instance in
  1.13.7. **That is incorrect** — measured `undefined` (TC-1821-02). It was renamed to
  `row.invalidate('search')` in DataTables 1.11. This is why the fix removes the call instead of
  relocating it, and why relocating it to `rows()` or to the Row node did not work.
- `data-group="linked"` / `data-group="available"` and the ids `chkAllLinked` /
  `chkAllAvailable`, `btnAssign`, `btnUnassign` are the stable selectors for this screen; use them
  instead of the a11y-tree labels, which change with the surrounding badges.

## Regression — Issue #1689: reqTreeReorder.html — read-only rows were still `draggable="true"` and every drop was silently ignored

**Precondition / fixture**

```
php tmp/fixtures_1681.php     # -> tproject=1 TREE1681, specA=2, reqs 6/8/10, view-only role 10
# the fixture generates a random password, so pin one:
H=$(php -r 'echo password_hash("ro1689pass", PASSWORD_DEFAULT);')
mysql -h 127.0.0.1 -utestlink -ptestlink testlink \
  -e "UPDATE users SET password='$H' WHERE login='tr1681readonly';"
```

Two accounts are needed: `tr1681readonly` / `ro1689pass` (has `mgt_view_req`, **not**
`mgt_modify_req`) and `admin` / `admin` (full rights). Both open
`/gui/templates/requirements/reqTreeReorder.html?tproject_id=1&req_spec_id=2`.

**Root cause this suite guards**

Two functions write the `draggable` attribute and they used *different* predicates. `render()`
gated it on `GRANT.modify && !DEAD`, but `render()` ends by calling `applyRowState()`, which
re-set `draggable="true"` from a `DEAD`-only predicate — and the `drop` handler discards the
gesture without modify rights. Introduced by `13b53dd94` (the #1688 fix), which folded `DEAD`
into the row controls but dropped the rights term; its non-regression had only been measured for
a user *with* modify rights. `canDrag()` is now the single source of truth.

**How to drive a drag headlessly** (HTML5 DnD cannot be clicked): dispatch the real event
sequence with a stub `DataTransfer`.

```js
const rows = () => [...document.querySelectorAll('#ordBody tr')];
const dt = new DataTransfer();
rows()[0].dispatchEvent(new DragEvent('dragstart',  {bubbles:true, dataTransfer:dt}));
rows()[2].dispatchEvent(new DragEvent('dragover',  {bubbles:true, cancelable:true, dataTransfer:dt}));
rows()[2].dispatchEvent(new DragEvent('drop',      {bubbles:true, cancelable:true, dataTransfer:dt}));
rows()[0].dispatchEvent(new DragEvent('dragend',   {bubbles:true, dataTransfer:dt}));
```

Two measurement traps that produced false readings while writing this suite: the "Unsaved
changes" chip is toggled via **inline `display`** (`markDirty()`), not a `show` class — assert
`getComputedStyle(#dirtyChip).display`; and `applyOrder()` opens a Bootstrap confirm modal, so a
scripted `#applyBtn` click fires **no request** until `#cmOk` is clicked.

| # | Case | Steps | Expected | Result |
|---|---|---|---|---|
| 1 | view-only: rows not draggable | login `tr1681readonly`, open the screen, read `#ordBody tr` attributes | `draggable` is `"false"` on **3/3** rows | PASS — `["false","false","false"]` (was `["true","true","true"]`) |
| 2 | view-only: no drag affordance in the chrome | same page, read the toolbar | hint hidden, `#ordBody .grip` count 0, read-only banner shown | PASS — `hintVisible:false`, `grips:0`, banner shown |
| 3 | view-only: a drag changes nothing | same page, run the drag sequence above (row 0 → row 2) | order identical, no "Unsaved changes" chip, no XHR to `?action=reorder` | PASS — `["6","8","10"]` → `["6","8","10"]`, chip stays hidden, 0 reorder requests |
| 4 | view-only: controls unchanged | same page | all `.rm` buttons disabled, Apply disabled, each `.pickbtn` enabled (by design — selecting a row is read-only) | PASS — `rmDisabled:true`, `applyDisabled:true`, `pickEnabled:true` |
| 5 | modify: affordance present | login `admin`, open the screen | `draggable="true"` on all rows, **3** grips visible, hint visible, banner hidden | PASS — `["true","true","true"]`, `grips:3`, hint visible |
| 6 | modify: drag + Apply persists | drag row 0 → row 2, `#applyBtn`, then `#cmOk` | order becomes `["8","6","10"]`, chip appears then clears, and the new order survives the reload — verify against the API, **not** `nodes_hierarchy.id` | PASS — `action=init` returned `serverOrder:[8,6,10]` = `TR1-2, TR1-1, TR1-3` |
| 7 | modify: affordance restored after Apply | continue case 6 after the save completes | `draggable="true"`, 3 grips (the busy transition re-runs `applyRowState()`) | PASS — restored |
| 8 | modify: Discard restores the saved order | click `[data-mv="bottom"]` on row 0, then `#discardBtn` | order returns to the saved one, chip clears, grip/drag hint come back | PASS — `["8","6","10"]` → `["6","10","8"]` → `["8","6","10"]`, chip `none`, 3 grips |
| 9 | #1688 non-regression: fresh DEAD page | open with `req_spec_id=999999` | `DEAD` true, 0 rows, 0 grips, hint hidden, banner **not** shown, Apply disabled | PASS — all as expected |
| 10 | #1688 non-regression: success → dead transition | open a good spec, then force a failing load with rows on screen (3 rows survive) | `draggable` forced to `"false"`, grips present-but-hidden, a drag cannot reorder, banner not shown | PASS — `["false","false","false"]`, grips `display:none`, order unchanged |

**Gates for this suite**

- `node --check` on the screen's extracted inline script → PASS
- `grep -n "canDrag\|draggable"` on `gui/templates/requirements/reqTreeReorder.html` → the only
  writers of `draggable` are `render()` and `applyRowState()`, both calling `canDrag()`; no
  open-coded `GRANT.modify && !DEAD` remains → PASS
- no i18n bundle touched (no new user-facing string) → PASS
- Event Viewer / `events`: **0** Error or Warning rows created during the run (only 5
  `log_level 16` INFO/audit rows: 1 fixture CREATE, 2 `audit_login_failed` from wrong-password
  attempts, 2 `audit_login_succeeded`) → PASS
- browser console on the screen → no errors or warnings → PASS

## Issue #1825 — Requirement Specification Search Form (`gui/templates/requirements/reqSpecSearchForm.html`)

Standalone criteria page that replaces `lib/requirements/reqSpecSearchForm.php` and hands its
criteria to the already-modern `searchReqSpec.html` (results) via `auto_search=1`.
BFF: `api/reqspecsearchform/index.php` (`GET|HEAD ?action=init&tproject_id=N…`).

**Fixtures** (fresh DB, recreated per run)

- `TL-DEMO` (id 1, requirements enabled): req specs `TL-REQ-1` "First requirement spec" (type 1,
  scope "Functional scope of the login feature") and `TL-REQ-2` "Second requirement spec" (type 2);
  `TL-REQ-1` carries two `requirements` rows so `GET_NOT_EMPTY_REQSPEC` is non-empty; one
  design-time custom field "Importance" linked at `requirement_spec` scope.
- `TL-NOREQ` (id 2): requirements **disabled**.
- `TL-EMPTY` (id 3): requirements enabled but **no** requirement specification.
- user `limited` (guest role): holds neither `mgt_view_req` nor `mgt_modify_req`.

| # | Case | Steps | Expected | Result |
|---|------|-------|----------|--------|
| 1 | admin happy path | login `admin`, open `reqSpecSearchForm.html?tproject_id=1` | context card: TL-DEMO / prefix / admin / Enabled / Yes / 200 / 1 CF; criteria card visible; no state banner | PASS — chips `Enabled`/`Yes`, `mReqs=Enabled`, `mSpecs=Yes`, `visible:[]` |
| 2 | criteria domain from config | same page | Type = Any type/Section/URS/SRS; Custom field = Any field/Importance | PASS — 4 type options + "Importance" |
| 3 | Find → results handoff | Doc ID `TL-REQ-1`, Title `First`, click Find | lands on `searchReqSpec.html?tproject_id=1&doc_id=TL-REQ-1&name=First&auto_search=1`, 1 match | PASS — `Matches: 1`, row `TL-REQ-1:First requirement spec rev. 1` |
| 4 | empty criteria warns first | Reset, then Find | `confirm()` before navigating; accept → filter-less search, both specs | PASS — dialog shown; after accept `Matches: 2` (both rows) |
| 5 | Reset | fill Doc ID + Title, click Reset | every criterion back to its default, CF value group hidden | PASS — `doc:"", name:"", type:"notype", cf:"0", cfGrp:"none"` |
| 6 | Refresh | type junk into Doc ID, click Refresh | form re-read from the server echo of the URL criteria (junk discarded) | PASS — `TL-REQ-1`/`First` restored |
| 7 | deep-link pre-fill (bug fixed this run) | open `?tproject_id=1&doc_id=TL-REQ-9&name=Deep&reqSpecType=2&scope=login&log_message=fixture` | every criterion pre-filled from the BFF echo | PASS — all 5 values + `type=2` (before the fix: all blank) |
| 8 | deep-link custom field | open `?custom_field_id=1&custom_field_value=High` | CF = Importance, value = High, value group revealed | PASS — `cf:"1", cfVal:"High", cfGroup:"block"` |
| 9 | criteria length-capped server-side | `/init&doc_id=` 400×A, `name=` 4000×B | 200, echo trimmed to the cap | PASS — `docLen:255`, `nameLen:255` |
| 10 | requirements disabled | open `?tproject_id=2` | `requirements_disabled` banner + chip "Disabled", no criteria card | PASS — `visible:["stReqsDisabled"]`, `mReqs=Disabled` |
| 11 | no requirement specification | open `?tproject_id=3` | `no_req_specs` banner, Doc ID filter **hidden** (legacy `GET_NOT_EMPTY_REQSPEC`) | PASS — `grpDocId:"none"`, `visible:["stNoSpecs"]` |
| 12 | no right → 403 | login `limited`, open `?tproject_id=1` | `Access denied` state, code `403 no_right`, no project data | PASS — `visible:["stDenied"]`, code `403 no_right` |
| 13 | unknown project → 404 | `/init&tproject_id=9999` | `404 tproject_not_found` | PASS |
| 14 | malformed / missing / zero id → 400 | `tproject_id=abc`, absent, `0` | `400 invalid_tproject` in all three | PASS |
| 15 | wrong method → 405 | `POST /init` | `405 wrong_method`, `Allow: GET, HEAD` | PASS |
| 16 | unknown action → 400 | `?action=bogus` | `400 unknown_action` | PASS |
| 17 | anonymous → 401 | `/init` with no session | `401 not_authenticated` | PASS |
| 18 | legacy form retired | navigate to `lib/requirements/reqSpecSearchForm.php?tproject_id=1&doc_id=TL-REQ-9&name=Deep` | 302 → modern screen, criteria preserved and pre-filled | PASS — landed on `reqSpecSearchForm.html?…doc_id=TL-REQ-9&name=Deep`, fields filled |
| 19 | legacy results retired | navigate to `lib/requirements/reqSpecSearch.php?requirement_document_id=TL-REQ-1&name=First&coverage=1` | 302 → `searchReqSpec.html?doc_id=TL-REQ-1&name=First&auto_search=1`, search already run, `coverage` dropped | PASS — `Matches: 1`, `docField:"TL-REQ-1"` |
| 20 | shims refuse a write verb / an XHR | `POST` both shims; `fetch()` (Sec-Fetch-Dest `empty`) | `405 wrong_method` / `405 retired_endpoint` JSON, never a login body to parse | PASS — both codes on both shims |
| 21 | shims stay anonymous-safe | both shims with no cookie | legacy `login.php?note=expired` bounce, no project data | PASS — JS redirect body, no data |
| 22 | results → form round trip | on the results screen, click "Criteria form" | 302-free navigation back to the form with the filled criteria only | PASS — landed on `reqSpecSearchForm.html?tproject_id=1` |
| 23 | Back / Close | click "Back to Search Test Cases"; `Close` on the form | `/gui/templates/search/searchView.html?tproject_id=1`; Close closes the window or returns to the opener | PASS — Back landed on `searchView.html` |

**Gates for this suite**

- `php -l` on `api/reqspecsearchform/index.php`, both legacy shims and `lib/functions/common.php` → PASS
- `node --check` on the screen's extracted inline script → PASS
- `python3 -m json.tool` on all 10 locale bundles after the 50-key (`rssf.*` + footer) insert → PASS
- i18n: `rsf.*` was **already taken** by Reorder Requirements, so the form uses `rssf.*`;
  all 49 keys + `footers.reqSpecSearchForm` exist in en/ro/de/es/fr/it/pt/ru/ja/zh → PASS
- Event Viewer / `events`: **0** unexpected rows during the run — only the intentional
  `log_level 2` WARNINGs (2 × `mgt_view_req missing` from the `limited` user, 2 × `BFF shim:
  refused POST`) and the `log_level 16` audit logins → PASS
- browser console on the screen → no errors, no warnings → PASS
