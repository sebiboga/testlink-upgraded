# Task 1622 — DataTables `stateSave` (persist entries-per-page / search / sort / page) on Assign Test Project Roles (gap vs legacy)

**Issue:** [#1622](https://github.com/sebiboga/testlink-upgraded/issues/1622)
**Status:** IMPLEMENTED & VERIFIED (2026-09-26) — branch `task/issue-1622`, commit `fb5fc309d`
**Screen:** `gui/templates/usermanagement/usersAssignProject.html`
**BFF:** unchanged — `api/roles/index.php` `GET /roles/meta/tproject-roles` already returned everything the state needs

## The gap

The shared legacy include `gui/templates/dashio/include/DataTables.inc.tpl:100-104`
initialised **every** legacy grid with

```js
config = { "lengthMenu": [ {$DataTablesLengthMenu} ], "stateSave": true };
```

and the legacy `gui/templates/dashio/usermanagement/usersAssign.tpl:111-120`
(deleted in `ab387af72`, readable from `ab387af72^`) pulled that include in for
this screen:

```
{include file="DataTables.inc.tpl" DataTablesSelector="#item_view" DataTablesLengthMenu=$ll}
```

so the user grid of **Assign Test Project Roles** ran with `stateSave: true`:
entries-per-page, the `User` search string, the sort column/order and the current
page were persisted in the DataTables `localStorage` state and **restored on the
next visit of the same URL**. `gui/templates/tl-classic/usermanagement/usersAssign.tpl`
did the same.

The modern `initAssignTable()` had no `stateSave`, so every visit restarted from
the hard-coded default (20 rows, empty search, `Login` ascending, page 1) and
nothing was written to `localStorage` at all.

### Measured before the fix (browser, admin/admin, `tproject_id=1`, 29 users)

| Action | Measured |
|---|---|
| initial load | `{"search":"","len":20,"order":"[[1,"asc"]]","page":0}`, `localStorage` = `[]` |
| `search('ale')` + `page.len(60)` + `order([[2,'desc']])` | `{"search":"ale","len":60,"order":"[[2,"desc"]],"page":0}`, `localStorage` = `[]` (0 keys) |
| reload | `{"search":"","len":20,"order":"[[1,"asc"]]","page":0}` — **filter, entries-per-page, sort and page all lost** |

## Modern implementation

### 1. `initAssignTable()` — the legacy state, project-scoped

`gui/templates/usermanagement/usersAssignProject.html:468-499`

```js
stateSave: true,                                   // DataTables.inc.tpl:100-104
stateSaveParams: function (settings, state) {      // stamp the state with its project
  state.tproject_id = currentProject == null ? '' : String(currentProject);
  return state;
},
stateLoadParams: function (settings, state) {      // reject a foreign project's state
  var saved   = (state && state.tproject_id != null) ? String(state.tproject_id) : '';
  var current = currentProject == null ? '' : String(currentProject);
  return saved === current;                        // false => abort the load
}
```

Why the stamp is needed: DataTables keys the saved state by **page URL**, and the
query string is *not* part of that key, so `?tproject_id=` cannot scope it by
itself. Without the stamp a project switch would leak the previous project's
search / entries-per-page / sort / page into the new grid — the exact leak the
sibling plan screen documents (`usersAssignPlan.html:395-399`), and a leak
**legacy actually had** (legacy switched project by full page navigation, so it
restored whatever the last project had saved).

### 2. `loadUsers()` — reset on a real project switch

`gui/templates/usermanagement/usersAssignProject.html:300-313`

```js
var prevProject = currentProject;
var projectSwitched = prevProject != null && String(prevProject) !== String(pid);
if (projectSwitched) {
  if ($.fn.DataTable && $.fn.DataTable.isDataTable('#assignTable')) {
    $('#assignTable').DataTable().state.clear();
  }
  restoredStateNotified = false;
}
```

`prevProject != null` keeps the **initial** load (the one that must restore)
untouched, and `isDataTable()` keeps it safe when no grid exists (empty project,
demoMode, no-rights deny box). The existing `keep`-based restore in
`renderAssignTable()` (`:406-430`) is unchanged and still preserves the view
across the in-screen re-renders (bulk `Do`, per-row change) — legacy did the same
in place with `set_combo_group()`.

### 3. Localized "saved view restored" notice

A restore is otherwise invisible, so `notifyRestoredState()`
(`:503-517`, called at the end of `initAssignTable()`) raises the screen's
existing Dashio toast when a state was restored **and** it is not the default
view. The `restoredStateNotified` latch keeps it to one notice per page load per
project (re-armed by the project switch).

New i18n key `assign.viewStateRestored` in **all 10** locale bundles
(`en, ro, de, es, fr, it, ja, pt, ru, zh`).

## Verification (browser, admin/admin)

Fixture `tmp/fixtures_1622.php` (re-runnable): 2 public test projects + 28 users
(`tlu1622_u01…28`, 4 with `ale` in the login) → 29 rows in the grid.

| Check | Measured | Result |
|---|---|---|
| `search='ale'`, 60 entries, `Last Name` desc → reload | `{"search":"ale","len":60,"order":"[[2,"desc"]],"page":0}`, search input `ale`, length select `60`, info `Showing 1 to 28 of 28 entries (filtered from 29 total entries)` | PASS |
| real typing `User` + pager link `2` → reload | `{"search":"User","len":20,"order":"[[1,"asc"]],"page":1}`, info `Showing 21 to 28 of 28 entries (filtered from 29 total entries)`, first row `tlu1622_u20` | PASS |
| leave to **User Management** and come back | view still restored (legacy "same URL" promise) | PASS |
| project switch 7 → 8 while 7 had `search='ale'`/60/Name desc | `{"search":"","len":20,"order":"[[1,"asc"]],"page":0}`, saved payload re-stamped `{tproject_id:"8",…}` | PASS |
| deep link back to 7 | state saved for 8 rejected → default view, **no** toast | PASS |
| bulk `Do` / per-row change on a non-default view | `{"len":20,"order":"[[2,"desc"]],"page":1}` preserved, 19 `changed-badge` cells | PASS |
| user without the assign right (global `guest`) | deny box, table hidden, `isDataTable:false`, `localStorage:[]` | PASS |
| `-- select project --` then re-select | empty state shown, grid usable again | PASS |
| console `error`+`warn` over the whole pass | `<no console messages found>` | PASS |
| Event Viewer (`events`) | no new Error/Warning from the screen | PASS |

Full suite (20 rows, executed): `tmp/TLU_Test_Cases.md` →
`## Task — Issue #1622: DataTables stateSave … in Assign Test Project Roles`.

## Known nuances (not defects)

- Only **one** state exists per page URL (legacy had the same single-slot
  behaviour), so the state of the *last* viewed project is the one kept. What
  matters — and is now guaranteed — is that a state is never *applied* to a
  different project.
- A restored state whose page number exceeds the new dataset is clamped by
  DataTables itself.
- `dt.page(1).draw()` (chained, full redraw) lands on page 0 in DataTables
  1.13.7 — a gotcha for hand-written test scripts only; the screen's own
  `renderAssignTable()` path was measured to preserve the page.

## Files

| File | Purpose |
|---|---|
| `gui/templates/usermanagement/usersAssignProject.html` | `stateSave` + project-scoped state, project-switch reset, restore toast |
| `gui/templates/i18n/*.json` (10) | new `assign.viewStateRestored` key |
| `CHANGELOG` | 2.0.1 entry |
| `docs/screenshots/issue-1622-view-state-restored.png` | restored view |
| `docs/screenshots/issue-1622-view-state-restored-toast.png` | restore + toast |
