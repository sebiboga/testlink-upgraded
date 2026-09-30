# Task 1642 — DataTables `stateSave` (persist entries-per-page / search / sort / page) on Assign Test Plan Roles (gap vs legacy)

**Issue:** [#1642](https://github.com/sebiboga/testlink-upgraded/issues/1642)
**Status:** IMPLEMENTED & VERIFIED (2026-09-30) — branch `task/issue-1642`, commit `63da873aa`
**Screen:** `gui/templates/usermanagement/usersAssignPlan.html`
**BFF:** **unchanged** — `api/roles/index.php` `GET /roles/meta/tplan-roles` already returns
everything the grid needs; the persisted state is keyed on the page plus a client-side context
stamp. This is the second half of the pair started by
[#1622](https://github.com/sebiboga/testlink-upgraded/issues/1622) on the project screen.

## The gap

The shared legacy include `gui/templates/dashio/include/DataTables.inc.tpl:100-104` initialised
**every** legacy grid with

```js
config = { "lengthMenu": [ {$DataTablesLengthMenu} ], "stateSave": true };
```

and the legacy `gui/templates/dashio/usermanagement/usersAssign.tpl:111-120` (deleted in
`ab387af72`, readable from `ab387af72^`) pulled that include in for the **test-plan** grid too:

```
{if $tlCfg->gui->usersAssign->pagination->enabled}
  addToDataTablesConfig = { "columnDefs": [ { "searchable": false, "targets": 1 }],
                            "language": { "search": "{$labels.User}" } };
  {include file="DataTables.inc.tpl" DataTablesSelector="#item_view" DataTablesLengthMenu=$ll}
{/if}
```

so the user grid of **Assign Test Plan Roles** ran with `stateSave: true`: entries-per-page, the
`User` search string, the sort column/order and the current page were persisted in the DataTables
`localStorage` state and **restored on the next visit of the same page** (F5, the post-back reload
after Save, or coming back from a sibling tab).

The modern `renderUsersTable()` had no `stateSave`. Its own comment recorded the omission as a
deliberate choice — *"the modern same-page plan switcher deliberately does NOT auto-restore saved
state … a plan switch would otherwise leak the previous plan's page/search into the new grid"* —
but that only justified the **in-page switch** case: the `keep` object
(`usersAssignPlan.html:1008-1015`, fed by `applyBulkRole()`) preserved the view across in-screen
re-renders, while **every revisit of the page** silently reset to the hard-coded default.

### Measured before the fix (browser, admin/admin, `tproject_id=1&tplan_id=3`, 26 active users)

| Action | Measured |
|---|---|
| initial load | `oFeatures.bStateSave = null`; `localStorage` `DataTables_*` keys = `[]` |
| `search('user1642_1')` + `page.len(40)` + `order([[1,'desc']])` | `{"search":"user1642_1","len":40,"order":"[[1,"desc"]]","page":0}`, `localStorage` = `[]` (0 keys) |
| F5 | `{"search":"","len":20,"order":"[[1,"asc"]]","page":0}` — **search, entries-per-page, sort and page all lost** |

Control on the already-fixed sibling screen (`usersAssignProject.html?tproject_id=1`, issue #1622):
`oFeatures.bStateSave = true`, one `DataTables_assignTable_/…/usersAssignProject.html` key, state
fully restored after F5 — so the measurement method is sound and the gap is real.

## Modern implementation

### 1. `renderUsersTable()` — the legacy state, plan-scoped

`gui/templates/usermanagement/usersAssignPlan.html:944-966`

```js
stateSave: true,                                   // DataTables.inc.tpl:100-104
stateSaveParams: function (settings, state) {      // stamp the state with its plan
  state.tplan_id = currentPlan == null ? '' : String(currentPlan);
  return state;
},
stateLoadParams: function (settings, state) {      // reject a foreign plan's state
  var saved   = (state && state.tplan_id != null) ? String(state.tplan_id) : '';
  var current = currentPlan == null ? '' : String(currentPlan);
  return saved === current;                        // false => abort the load
}
```

Why the stamp is needed (measured): DataTables keys the saved state by the **page pathname** —
`DataTables_assignTable_/gui/templates/usermanagement/usersAssignPlan.html` — and the **query
string is not part of that key**. `?tproject_id=1&tplan_id=3` and `?tplan_id=4` therefore share one
slot, so the `tplan_id` stamp — not the key — is what guarantees that plan 4 never restores plan 3's
view. Legacy never needed the stamp because it reached a plan by **full page navigation**
(`usersAssign.tpl:100-107`: `location = baseLocation + "&tproject_id=…&tplan_id=" + fID`), so its
state key changed with the plan; the modern in-page `#planSelect` switcher has no such guarantee.

### 2. `loadUsers()` — reset on a real plan switch

`gui/templates/usermanagement/usersAssignPlan.html:1057-1071`

```js
var prevPlan = currentPlan;
var planSwitched = prevPlan != null && String(prevPlan) !== String(planId);
if (planSwitched) {
  if ($.fn.DataTable && $.fn.DataTable.isDataTable('#assignTable')) {
    $('#assignTable').DataTable().state.clear();
  }
  restoredStateNotified = false;
}
```

`prevPlan != null` keeps the **initial** load (the one that must restore) untouched, and
`isDataTable()` keeps it safe when no grid exists (empty project, cleared plan, demoMode, no-rights
deny box). The existing `keep`-based restore in `renderUsersTable()` (`:1008-1015`) is unchanged and
still wins for in-screen re-renders (bulk `Do`, "show only authorized users" with pagination off) —
legacy did the same in place with `set_combo_group()`.

### 3. Localized "saved view restored" notice

A restore is otherwise invisible, so `notifyRestoredState()` (`:1030-1055`) raises the screen's
existing Dashio toast when a state was restored **and** it is not the default view. Two deliberate
differences from the copy on the project screen, both forced by measurement:

- it is called **only when no `keep` was passed** — with `keep` the view comes from the caller's
  live grid, not from storage, so there is nothing to announce (otherwise the first bulk `Do` after
  the administrator had merely paged around popped a misleading "view restored" toast);
- the `isDefault` test compares `st.search.search` and `JSON.stringify(st.order)`, because
  DataTables 1.13.7 always materialises a `search` **object** (so `!st.search` is never true) and
  `st.order` is an **array of `[col, dir]` pairs** (so `String(st.order)` is `"1,asc"` and can never
  equal `'[[1,"asc"]]'`). The project screen's copy has that latent defect and fires the toast on
  every revisit — filed as **#1733**.

No new i18n key was needed: `assign.viewStateRestored` already exists in **all 11** locale bundles
(`en, ro, de, es, fr, it, ja, pt, ru, zh`) from #1622, and is read from the bundle at runtime — no
hardcoded string.

## Verification (browser, admin/admin)

Fixtures recreated for the run (the DB is freshly imported every time): test project `ALPHA1642`
`id=1`; test plans `id=3` `A-PUBLIC-1642` (`is_public=1`) and `id=4` `A-PRIVATE-1642`
(`is_public=0`); 26 active users (`admin` + `user1642_01 … user1642_25`) so page 2 of the grid
really exists.

| Check | Measured | Result |
|---|---|---|
| `oFeatures.bStateSave` after load | `true` (was `null`) | PASS |
| `search=user1642_1`, `len=20`, `order=[[1,desc]]` → F5 | `search="user1642_1"`, box repopulated, `len=20`, `order=[[1,"desc"]]`, 10 matching rows | PASS |
| `len=40`, `order=[[1,desc]]` → F5 | `len=40`, `order=[[1,"desc"]]` | PASS |
| `page(1)` of 2 pages → F5 | `page=1`, rows `admin / user1642_01 / user1642_02` | PASS |
| saved payload | `{time,start,length,order,search,columns[…6],"tplan_id":"3","childRows":[]}` | PASS |
| in-page switch plan 3 → 4 | `search="" len=20 order=[[1,"asc"]] page=0`, re-stamped `tplan_id:"4"` | PASS |
| `?tproject_id=1` reloads into plan 4 while the state is stamped `tplan_id:"3"` (`search=user1642_05 len=60 order=[[2,asc]]`) | `state.loaded() === null`, default view — **cross-plan restore impossible** | PASS |
| revisit with the **default** view | no toast | PASS |
| revisit with a non-default view | exactly one `ok` toast (`assign.viewStateRestored`) | PASS |
| bulk `Do` on a non-default view | view byte-identical before/after, **no** toast, 25 rows `changed`, Save enabled | PASS |
| per-row role `<select>` change | model updated, view untouched, no toast | PASS |
| **Save** (post-back grid reload, same plan) | 25 rows written to `user_testplan_roles` for plan 4, `toast ok\|User Roles updated`, view kept, Save re-disabled | PASS |
| "show only authorized users" (#1707 regression) | footer `26 users — 1 unauthorized user(s) hidden`, 9 rows shown, view intact | PASS |
| sibling `usersAssignProject.html` (#1622) | still `bStateSave=true`, `tproject_id:"1"`, restores its own state | PASS |
| console `error` + `warn` over the whole pass | `<no console messages found>` | PASS |
| Event Viewer (`events`) | `group by log_level` → `16 → 27` only; `where log_level <> 16` → **0 rows** | PASS |

Full suite (18 rows, executed twice — the second pass against the final commit after the
`isDefault` correction): `tmp/TLU_Test_Cases.md` →
`## Task — Issue #1642: Restore DataTables stateSave … in usersAssignPlan.html`.

## Known nuances (not defects)

- Only **one** state slot exists per page (legacy had the same single-slot behaviour), so the state
  of the *last* viewed plan is the one kept. What is guaranteed — and was the whole point of the
  stamp — is that a state is never *applied* to a different plan. Per-plan simultaneous memory would
  require rewriting the URL on switch, which is out of scope for this task.
- A restored state whose page number exceeds the new dataset is clamped by DataTables itself.
- Out of scope, filed as a separate task: **#1641** — this screen still hardcodes
  `lengthMenu: [[20,40,60,-1],…]` / `pageLength: 20` instead of reading the BFF pagination block
  (the project screen reads it from `/roles/meta/tplan-roles`).

## Files

| File | Purpose |
|---|---|
| `gui/templates/usermanagement/usersAssignPlan.html` | `stateSave` + plan-scoped state, plan-switch reset, restore toast |
| `gui/templates/i18n/*.json` (11) | no change — `assign.viewStateRestored` already present in every bundle |
| `CHANGELOG` | 2.0.1 entry |
| `docs/screenshots/issue-1642-view-state-restored.png` | restored view + restore toast |
| `docs/screenshots/issue-1642-plan-switch-no-leak.png` | plan switched — default view, no leak |
