# Task 939 — DataTables pagination + "User" search on Assign Test Plan Roles (gap vs legacy)

**Issue:** [#939](https://github.com/sebiboga/testlink-upgraded/issues/939)
**Status:** IMPLEMENTED & VERIFIED (2026-09-19) — branch `task/issue-939`

## The gap

Legacy `gui/templates/dashio/usermanagement/usersAssign.tpl:111-120` builds the
plan-roles user grid as a **DataTable** (via `DataTables.inc.tpl`) because the
shared `$tlCfg->gui->usersAssign` block defaults
`pagination->enabled` to true (`config.inc.php:663-666`,
`items_per_page[values] = '[20, 40, 60, -1], [20, 40, 60, "All"]'`). That
DataTable gives:

- an entries-per-page menu **20 / 40 / 60 / All**;
- a search box labelled **"User"** (`usersAssign.tpl:116`
  `language.search = {$labels.User}`);
- sortable user columns with search disabled on the role column (legacy
  `columnDefs: [{searchable:false, targets:1}]`, `usersAssign.tpl:115-117`);
- `stateSave` persistence of page/search/entries across reloads
  (`DataTables.inc.tpl`).

The modern screen `usersAssignPlan.html` rendered a static
`<table id="assignTable">` with **no search box, no pagination, no sorting** —
a gap for projects with many users: the whole list spilled onto one page and
you could not filter by person. The sibling project screen closed the same gap
in #930; the plan screen (which shares the legacy template and config) had a
parallel unresolved gap.

## Modern implementation

Landed on `task/issue-939` (`gui/templates/usermanagement/usersAssignPlan.html`,
diff +151/-51):

- **DataTables 1.13.7 bootstrap build** added to `<head>` (same CDN versions as
  `usersAssignProject.html:10,118-119` / `cfieldsAssignView.html:9,166-167`).
- **`renderUsersTable()`** initialises `#assignTable` (only when the BFF returns
  users and pagination is enabled — the empty state and pagination-disabled
  fallback stay plain tables, matching the project screen):
  - `lengthMenu: [[20,40,60,-1],[20,40,60,assign.all]]`, `pageLength` 20,
    `order: [[1,'asc']]` (login asc — same default as `usersAssignProject.html`,
    #930);
  - `columnDefs`: ordinal (0) + override-select (4) not orderable; the
    *inherited-role* column (3) and **Plan Role Override** select column (4)
    not searchable — only **Login** (1) + **Last Name** (2) are searched,
    replicating the legacy "searchable only on the user column" rule;
  - `language.search` = localized `assign.searchUsers` ("User");
  - **view state is preserved explicitly, not via `stateSave`**: legacy
    `DataTables.inc.tpl` set `stateSave:true` for full page navigations, but the
    modern same-page plan switcher must never leak the previous plan's
    page/search into the next plan. `applyBulkRole()` captures
    search/len/order/page before the re-render and `renderUsersTable(keep)`
    re-applies them — an in-plan bulk "Do" keeps the user's view exactly like
    the legacy pure-DOM `set_combo_group()`; `loadUsers` never passes `keep`, so
    every project/plan load starts a clean grid (cross-plan isolation verified
    with the fixture's second plan PLAN939-R2).
- **State-driven data model (the key decision).** DataTables pagination keeps
  only the current page in the DOM and caches cell HTML at init, so any
  DOM-only change tracking would drop edits made on page 2 when browsing back to
  page 1. The screen therefore keeps `users[]` + `userById` (plus `roles[]`) as
  the single source of truth and re-creates every override select from the
  model:
  - `rolesOptions(u, chosenVal)` — one value-0 `assign.noOverride` option, then
    one option per assignable role; admin role (id 8) offered only when it is
    the row's current explicit assignment (issue #928); no `<inherited>` id-0
    duplicate (issue #1545, `filterTLRolesInherited`).
  - `buildSelectHtml(u)` — deterministic select markup generated from the model,
    NOT from live `td.innerHTML` (browsers do not serialise the current
    `<option selected>` into innerHTML, so cloning the DOM would silently reset
    the chosen override).
  - `onRoleChange(sel, uid)` — updates `userById[uid].roleVal`, applies the
    yellow `.changed` class + "Modified" badge via `createdRow`, and re-syncs
    the cell so the redraw serves the chosen value on every page:
    `dt.cell($(sel).closest('tr'), 4).data(buildSelectHtml(u), false)`.
  - `applyBulkRole()` (used by the "Set roles to / Do" control, #938) and
    `saveAssignments()` iterate the **model** (`users[]`), so bulk "Do" applies
    to every non-admin user on ALL pages (not just the visible one) and Save
    persists the paged-in backend overrides correctly; disabled global-admin
    rows stay excluded (issue #927). `createCurrentUsers()` additionally pins
    the save-button to the correct initial role (`roleVal = roleID`) so a
    bulk/pre-edit run always enables Save.
- **BFF**: none needed — `GET /roles/meta/tplan-roles`
  (`api/roles/index.php`) already returns the full active-user list with
  `roleID`/`effectiveRoleID`/`isInherited`/`isAdmin` and the assignable role
  catalog, matching the legacy pre-filtered dataset.
- **i18n**: `assign.all` = "All" added to **all 10** locale bundles
  (`gui/templates/i18n/{en,de,es,fr,it,ja,pt,ro,ru,zh}.json`), inserted before
  `assign.assignedRole`; existing `assign.searchUsers`, `assign.noUsers`,
  `common.modified`, `common.showEntries`, `common.entries`, `common.pageInfo`,
  `common.prev`, `common.next`, `common.filter` reused for the rest of the
  DataTables UI. Every bundle validated with `python3 -m json.tool`.

## Screenshots

- `docs/screenshots/issue-939-usersAssignPlan-datatables.png` — full grid:
  entries selector 20/40/60/All, "User" search box, pagination 1/2, 26 users.
- `docs/screenshots/issue-939-usersAssignPlan-search.png` — search box filtering
  the grid.

## Verification evidence

Fixture `tmp/fixtures_939.php`: project `PLANROLES939` + active test plan
`PLAN939-R1` (25 users `u93901..u93925`, global roles 4/6/7/9 cycled, all
active; overrides `u93901=7`, `u93904=7`, `u93911=6`, `u93921=9`) + second
active plan `PLAN939-R2` (cross-plan isolation); `admin` = locked global-admin
row. 26 active users ⇒ 2 pages at page size 20.

1. **Legacy chrome**: search label "User"; entries menu 20/40/60/**All**;
   `Showing 1 to 20 of 26 entries`; pages 1/2; footer "26 users".
2. **Search (user columns only)**: `u93901` → 1 row; `test designer` (a role
   shown inside the selects) → **0 rows** (roles column never searched, legacy
   `searchable:false`); `u9391` → 10 rows `u93910..u93919`;
   "filtered from 26 total".
3. **Sorting**: Login header click cycles asc → desc → original (default
   `order [[1,'asc']]`: admin, u93901…). Sort state survives paging/search.
4. **Pagination + entries**: page 2 = 6 rows `u93920..u93925`; All = 26 rows;
   round-trips stable.
5. **Edits survive paging (cell re-sync)**: change `u93902`→4 and `u93920`→7 on
   different pages; return to page 1 — both choices + "Modified" badges + Save
   enabled persist (`assignDt.cell(...,4).data(buildSelectHtml(u),false)`).
6. **Bulk "Do" + Save on ALL pages (model-driven)**: after bulk → Save →
   `user_testplan_roles` for plan 5 = 25 rows (admin absent); the fixture's new
   overrides co-exist with the pre-edit values; second bulk+Save → all 25 = 9.
   Sub-check exposed and fixed a **save-button regression** in `applyBulkRole`
   (Save stayed disabled after consecutive bulk flows), see ISSUES.md trail.
7. **i18n + empty state + Event Viewer**: `?locale=ro` shows Utilizator /
   "Toate" / "Se afiseaza 1 pana la 20 din 26 inregistrari"; zero active users
   → single "No users in this project." placeholder row, no DataTable wrapper,
   footer "0 users"; events only INFO(16)/AUDIT, **zero rows log_level ≥ 32**.
8. **Cross-plan isolation**: on `PLAN939-R1` set search `u9391` (10 rows);
   switch to `PLAN939-R2` → search box empty, 26 rows, "26 users" footer; switch
   back to `PLAN939-R1` → also clean; an in-plan bulk "Do" instead keeps the
   current search/page (view preserved by `applyBulkRole` capture +
   `renderUsersTable(keep)`).

Full manual pass: `tmp/TLU_Test_Cases.md` — **Suite 939 — 8/8 PASS**.
Code review (subagent) before commit: no blockers/majors; XSS-clean (all
user/role strings escaped via `esc()`, options built as strings).

## Related issues

- #930 — the identical gap closed for the Assign Test Project Roles screen;
  this plan screen reuses its DataTables/state-model conventions.
- #927 — global-admin rows locked (disabled select, never bulk-applied/saved).
- #928 — admin role (id 8) excluded from assignable options unless current.
- #938 — bulk "Set roles to / Do" (pre-existing, retained; now model-driven).
- #1545 — duplicate value-0 `<inherited>` option fixed pre-#939 (filtered BFF
  pseudo-role); state model rebuilds single value-0 options deterministically.

Refs #939.