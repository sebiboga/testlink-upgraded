# Task 930 — DataTables pagination + "User" search on Assign Test Project Roles (gap vs legacy)

**Issue:** [#930](https://github.com/sebiboga/testlink-upgraded/issues/930)
**Status:** IMPLEMENTED & VERIFIED (2026-09-18) — branch `task/issue-930-pagination-search`

## The gap

Legacy `gui/templates/dashio/usermanagement/usersAssign.tpl:111-120` builds the
user grid (`#item_view`) as a **DataTable** when
`$tlCfg->gui->usersAssign->pagination->enabled` is true (legacy default,
`config.inc.php:663-666`). That DataTable gives:
- an entries-per-page menu **20 / 40 / 60 / All** from the legacy
  `$tlCfg->gui->usersAssign->pagination->items_per_page[values]` JS-array string;
- a search box labelled **"User"** (`language.search = {$labels.User}` via
  `language.search` in `usersAssign.tpl:116`);
- sortable columns with search disabled on the role column (legacy
  `columnDefs: [{searchable:false, targets:1}]`, `usersAssign.tpl:115-117`);

The modern screen `usersAssignProject.html` rendered a static
`<table id="assignTable">` with no pagination, no search and no sorting — a gap
for datasets larger than one screen height.

## Legacy source of truth

- `config.inc.php:663-666` — `$tlCfg->gui->usersAssign->pagination->enabled`
  and `items_per_page[values] = '[20, 40, 60, -1], [20, 40, 60, "All"]'`.
- `gui/templates/dashio/include/DataTables.inc.tpl:103+` —
  `addToDataTablesConfig()` applied to `#item_view` with the length menu,
  `language.search = "User"` and `columnDefs` (searchable:false on the role
  column = index 1 in the legacy layout).
- `usersAssign.tpl:226-269` — the per-user select rows fed into that DataTable.

## Modern implementation

- **BFF** (`api/roles/index.php`): new `getUsersAssignPaginationConfig()` parses
  the legacy JS-array length-menu string from
  `$tlCfg->gui->usersAssign->pagination` and exposes it in
  `GET /roles/meta/tproject-roles` as
  `pagination: {enabled: bool, lengthMenu: [[20,40,60,-1],[20,40,60,"All"]]}`.
  The screen no longer hardcodes the grid behaviour — toggling the legacy config
  flag flips the modern grid between DataTable and plain table, exactly like
  1.9.20.
- **Screen** (`gui/templates/usermanagement/usersAssignProject.html`):
  - DataTables 1.13.7 CSS/JS added to `<head>` (same CDN as `usersView.html`);
  - `initAssignTable()` clones the legacy DataTable config onto `#assignTable`:
    `lengthMenu` from BFF, `pageLength` 20, `order:[[1,'asc']]`
    (login column), search disabled on the role column (col 3 — the modern
    ordinal column 0 is decorative), `language.search` = localized
    `assign.searchUsers` ("User");
  - **state-driven rendering**: `buildBodyHtml()` renders rows/selects/badges
    from `currentItems` + `roleChoices` + `changedMap` (the same escape/logic
    as the pre-pagination render), and `renderAssignTable()` re-renders from
    state while preserving the user's current page/search/sort/entries-per-page.
    DataTables caches cell HTML at init and pagination slices off-page rows out
    of the DOM, so DOM-only change tracking would drop or reset edits; the JS
    state is the single source of truth for `onRoleChange`, `applyBulkRole`
    (applies to **all** pages, not just the visible page) and `saveAssignments`
    (writes every non-admin user from state, admin skip preserved from #927);
  - pagination-disabled fallback (`config` flag false) keeps the plain table —
    `initAssignTable` early-returns, `renderAssignTable` no-ops.
- **i18n**: `assign.searchUsers` = "User" in **all 10** locale bundles
  (`gui/templates/i18n/{en,de,es,fr,it,ja,pt,ro,ru,zh}.json`), translated after
  the legacy `TLS_User` strings.
- Fixture `tmp/fixtures_930.php`: project `PAGING` (id 1) + 25 users
  `pager01..25` (global role 9 → inherited `<inherited> <role>` select rows) —
  enough rows to exercise 20/page pagination.

## Screenshots

- `docs/screenshots/issue-930-usersassign-pagination.png` — DataTable with
  20/40/60/All entries menu, "User" search, pagination (26 users, page 1).
- `docs/screenshots/issue-930-usersassign-search.png` — "User" search box
  filtering the grid.

## Verification evidence

- 26 users load as a DataTable: `Showing 1 to 20 of 26 entries`, pages 1/2,
  entries menu 20/40/60/All, search box labelled "User".
- Per-user change on page 1 + page round-trip (1→2→1): value + "Modified"
  badge survive the redraw (`roleChoices`, `changedMap`, `.changed` class,
  Save enabled).
- Search `pager20` → `Showing 1 to 1 of 1 entries (filtered from 26 total)`;
  clear restores 26.
- Bulk "Set roles to" `tester` → Do → Save → all 25 `user_testproject_roles`
  rows role_id 7; admin (disabled select) untouched.
- Single change on page 2 (`pager20` → test designer) + round-trip + Save →
  `user_testproject_roles` `pager20 role_id 6`.
- Entries-per-page "All" → `Showing 1 to 26 of 26 entries`.
- Pagination-disabled fallback (flag forced off) → plain table, no DataTables
  controls, 26 rows; re-enable restores the DataTable.
- Event Viewer: only AUDIT (log_level 16) rows from Save ops; 0 rows ≥ 32.
- Full manual pass: `tmp/TLU_Test_Cases.md` — "Task — Issue #930" — all
  functional steps PASS.

Refs #930.