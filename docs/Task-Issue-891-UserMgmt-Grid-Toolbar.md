# Task 891 — Grid toolbar in User Management (gap vs legacy)

**Issue:** [#891](https://github.com/sebiboga/testlink-upgraded/issues/891)
**Branch:** `task/issue-891`
**Status:** IMPLEMENTED / VERIFIED (2026-09-12)

## The gap

The legacy User Management grid (`lib/usermanagement/usersView.php`) renders an
ExtJS grid with a **toolbar** carrying five controls: **Expand/Collapse Groups**,
**Show all Columns**, **Reset to Default State**, **Refresh** and **Reset
Filters**, plus four **hidden technical columns** (`role_id`, `user_id`,
`login`, `is_special`) that "Show all Columns" reveals. The modern
`gui/templates/usermanagement/usersView.html` grid had no toolbar beyond
DataTables search + pagination: hidden columns did not exist and there were no
reset-filters / show-all-columns / refresh / reset-state controls.

## Legacy source of truth

- `lib/usermanagement/usersView.php:215-217` — `showToolbar = true`,
  `toolbarShowAllColumnsButton = true`.
- `lib/functions/exttable.class.php:96-124` — `showToolbar`,
  `toolbarShowAllColumnsButton`, `toolbarExpandCollapseGroupsButton`,
  `toolbarDefaultStateButton`, `toolbarRefreshButton`,
  `toolbarResetFiltersButton` all default to `true`.
- `lib/usermanagement/usersView.php:177-180` — hidden columns
  `hidden_role_id`, `hidden_user_id`, `hidden_login`, `hidden_is_special`.
- `lib/usermanagement/usersView.php:295-303` — in demoMode, `is_special=1` for
  logins in `config_get('demoSpecialUsers')` (default `['admin']`).
- `lib/usermanagement/usersView.php:207-210` — rows carry `role_id` so the
  ExtJS `getRowClass` can group/colour by role.

## Modern implementation (port)

**BFF `api/users/index.php`**
- `GET /users` list items now include `role_id` (raw `u.role_id`), `user_id`
  (raw `u.id`) and `is_special` (0/1, 1 only in demoMode for
  `demoSpecialUsers`), mirroring the legacy hidden columns
  (`usersView.php:177-180`, `:295-303`). `login` already exists as the first
  visible column.

**Frontend `gui/templates/usermanagement/usersView.html`**
- New `#gridToolbar` row (below the existing Create/Export toolbar) with five
  Dashio `.tbtn` buttons: **Expand/Collapse Groups**, **Show all Columns**,
  **Reset to Default State**, **Refresh**, **Reset Filters**.
- Three hidden DataTable columns `role_id` / `user_id` / `is_special`
  (with `data-i18n` headers), toggled by "Show all Columns" via
  `table.columns([9,10,11]).visible(...)`; state kept in `showTechCols` and
  re-applied after every re-render (`applyGridColsState()`).
- Row grouping by **role name** using the DataTables **RowGroup** extension
  (CDN 1.4.1, same as `tplanWithCF.html`): group headers show item count,
  clicking a header toggles that one group, the toolbar button collapses /
  expands all groups (Refs legacy `toolbarExpandCollapseGroupsButton`).
- **Reset to Default State**: hides the technical columns, expands all groups,
  clears search, restores order `[[0,'asc']]`, pageLength 25
  (`toolbarDefaultStateButton` + legacy `sortDirection='DESC'`-default parity).
- **Refresh**: re-fetches `GET /users` (`toolbarRefreshButton`).
- **Reset Filters**: clears the DataTables global search box ("Filters
  cleared" info) (`toolbarResetFiltersButton`).
- i18n: new `usergrid.*` keys in **all 10 locale bundles**
  (`expandCollapseGroups`, `showAllColumns`, `hideTechColumns`, `defaultState`,
  `defaultStateApplied`, `refresh`, `resetFilters`, `filtersCleared`,
  `groupItem`, `groupItems`, `roleId`, `userId`, `isSpecial`).

## Measured verification (Chrome DevTools MCP + mysql, localhost:8082)

1. Grid loads with 4 role groups (admin/guest/leader/tester after seeding 3
   extra users) — RowGroup grouping by role works.
2. **Show all Columns** → headers count 12 incl. "Role ID / User ID / Is
   Special"; cells show 8/1/0, 5/7/0, 9/6/0, 7/5/0; button label flips to
   "Hide technical columns".
3. **Show all Columns** state survives **Refresh** — fixed a re-init bug where
   a static `style="display:none"` on the original `<th>` was overriding the
   DataTables visibility after the table was re-created.
4. **Expand/Collapse Groups** toggles all 4 groups (chevron-right →
   chevron-down); clicking a single group header collapses only that group.
5. **Reset Filters** clears the search box and shows "Filters cleared".
6. **Reset to Default State** → hidden cols hidden, groups expanded, search
   empty, order `0,asc`, pageLength 25, info "Grid reset to default state.".
7. Browser console: no JS errors (only pre-existing bootstrap a11y issues).
8. Event Viewer / `events` table: no new Error/Warning entries from this
   feature (`log_level >= 3` count 0 in the test window apart from the admin
   login audit row, `log_level=16`).

## Test suite

`tmp/TLU_Test_Cases.md` → "Task — Issue #891: Implement grid toolbar in User
Management (gap vs legacy)" — all PASS.

## Files

- `api/users/index.php` — `GET /users` exposes `role_id`, `user_id`,
  `is_special`.
- `gui/templates/usermanagement/usersView.html` — `#gridToolbar`, 3 hidden
  columns, RowGroup-by-role, toolbar wiring (`applyGridColsState`,
  `expandAllGroups`, `collapseAllGroups`, `toggleGroups`,
  `resetGridToDefault`, `resetGridFilters`).
- `gui/templates/i18n/{en,de,es,fr,it,ja,pt,ro,ru,zh}.json` — `usergrid.*`
  keys (13 per bundle).
- `CHANGELOG` — NEW FEATURES entry (`#891`).
- `tmp/TLU_Test_Cases.md` — Task suite #891.
- `docs/screenshots/issue-891-*.png` — default / show-all-columns /
  groups-collapsed states.