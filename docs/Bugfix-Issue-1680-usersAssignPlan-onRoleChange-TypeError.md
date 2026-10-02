# Bugfix — Issue #1680: `onRoleChange` threw `TypeError: Cannot read properties of undefined (reading 'row')` on the 2nd change of the same row

**Issue:** [#1680](https://github.com/sebiboga/testlink-upgraded/issues/1680) — *usersAssignPlan.html: onRoleChange throws TypeError on the 2nd change of the same row — stale Save button + stale cell cache*
**Branch:** `fix/issue-1680`
**Affected screen:** Assign Test Plan Roles (`gui/templates/usermanagement/usersAssignPlan.html`), function `onRoleChange()`
**Related:** #939 (introduced the cell re-sync), #1664 (where it was hit), #1707 (added the global `ext.search` predicate that adds draw transitions around the same `<tbody>`)
**Regression suite:** `tmp/TLU_Test_Cases.md` → **Suite "Regression — Issue #1680"** (11/11 PASS)
**Commits:** `1b653171e` (fix), `9eac412e2` (CHANGELOG)
**DataTables:** 1.13.7 (`https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js`, `usersAssignPlan.html:189`)

---

## 1. Symptom

In *Assign Test Plan Roles* the per-user **Plan Role Override** `<select>` re-syncs its
DataTables cell cache on every `change`, so a paging/search/sort re-draw of an off-page row
cannot resurrect the previous option (the fix added by #939). That re-sync threw

```
Uncaught TypeError: Cannot read properties of undefined (reading 'row')
    at  (jquery.dataTables.min.js:4:66727)          <-- cell().data()
    at  (jquery.dataTables.min.js:4:53720)          <-- API methodExt wrapper
    at onRoleChange (usersAssignPlan.html:1334)     <-- assignDt.cell(tr, 4).data(...)
    at onchange (VM172 usersAssignPlan.html:1:1)
```

on the **second** change of the same row's select. Because the exception escaped the
handler, everything scheduled *after* the failing line never ran:

1. `$('#saveBtn').prop('disabled', !isDirty())` — the **Save button kept its previous state**:
   it stays enabled although `isDirty()` is `false` (change back to the original value), and
   it stays disabled when it should be enabled.
2. The DataTables cell cache was never re-synced, so a later paging/search/sort re-draw
   resurrected the **previous** option as the selected one (the "my change vanished" report).

Role assignment still saved correctly — the `users` model is the source of truth and the
Save payload is built from it — which is exactly what makes this the class of
silent-misread defect the triage expects to be fixed rather than filed.

**Not** a server-side defect: the `events` table gained **no** Error/Warning row for the
whole session, and the BFF answered `GET /api/roles/index.php/meta/tplan-roles` with
`200 status: ok`. Client-side only.

## 2. Reproduction

**Fixtures** (fresh DB — `testplans`/`testprojects` are empty at the start of a run; see
`tmp/fixtures_1680.sql`):

* test project **#1680**: a `testprojects` row **and** a `nodes_hierarchy` row
  `(1680,'Issue 1680 Repro Project',0,1,1680)`.
  ⚠ The project NAME lives in `nodes_hierarchy`, not in `testprojects`
  (`lib/functions/testproject.class.php:551` selects `NHTPROJ.name`). Without the
  `nodes_hierarchy` row the project combo comes back empty and the screen shows the
  `assign.rolesForPlansDisabled` notice instead of the grid.
* test plan **#1680** (`testproject_id=1680`, `active=1`, `is_public=1`).
* users **#1681 `tina.tester`** (global role 7, explicit plan role **5** = guest) and
  **#1682 `tom.tester`** (global role 7, explicit plan role **4** = test designer), both
  with a `user_testplan_roles` row.

**Steps**

1. `http://localhost:8082/index.php` → log in `admin` / `admin`.
2. Open
   `http://localhost:8082/gui/templates/usermanagement/usersAssignPlan.html?tproject_id=1680&tplan_id=1680`.
   The grid renders 3 rows (`data-uid` = `1` admin, `1681`, `1682`).
3. Change the Plan Role Override select of row **1681** (`tina.tester`) to `6`.
   → row highlighted, *Modified* badge, Save enabled. ✔
4. **Change the very same row's select again** (e.g. back to `5`, or on to `7`).
5. Open the console: `Uncaught TypeError: Cannot read properties of undefined (reading 'row')`.
6. Observe: `#saveBtn.disabled` no longer agrees with `isDirty()`.
7. Optional: type a search term in the grid search box (forces a DataTables re-draw) — the
   select of the changed row snaps back to the value it had before step 4.

## 3. Root cause

### 3.1 The failing frame, de-minified

`jquery.dataTables.min.js:4:66727` is the `cell().data()` setter:

```js
_api_register('cell().data()', function ( data, redraw, display ) {
    var context = this.context, first = this[0];
    return data === undefined ?
        ( context.length && first.length ? _fnGetCellData(context[0], first[0].row, first[0].column) : undefined )
      : ( _fnSetCellData(context[0], first[0].row, first[0].column, data),
          _fnSetCellData ? ... : null,
          this );
});
```

The throw is `first[0].row` with **`first === []`** — i.e. `this[0]` is `undefined`.
**The exception is not raised inside `.data()`; `assignDt.cell(tr, 4)` returned an EMPTY
API instance** and the empty-API dereference is what surfaces as this `TypeError`.

Proven deterministically in the live page:

```js
assignDt.cell($('<tr data-x="1">'), 4).data('zz')
// → THROW: Cannot read properties of undefined (reading 'row')   ← byte-identical message
//   (api.length === 0, identical minified frame)
```

### 3.2 Where the row resolution actually fails

`cell(row, col)` (`jquery.dataTables.js:9096-9110`) resolves the row through
`this.rows( rowSelector, internalOpts )`, i.e. `__row_selector()`
(`jquery.dataTables.js:8084`). Its exact fast path is guarded by **`sel.nodeName`**:

```js
// Selector - node
if ( sel.nodeName ) {                                     // ← DOM NODE ONLY
    var rowIdx = sel._DT_RowIndex;
    ...
    return aoData[ rowIdx ] && aoData[ rowIdx ].nTr === sel ? [ rowIdx ] : [];
}
```

**A jQuery object has no `nodeName`,** so `assignDt.cell(tr, 4)` never reaches it. It falls
into the generic catch-all:

```js
var nodes = _removeEmpty( _pluck_order( settings.aoData, rows, 'nTr' ) );
return $(nodes).filter( sel ).map( function () { return this._DT_RowIndex; } ).toArray();
```

which resolves the row by **strict node identity** (jQuery `winnow` → `indexOf.call(qualifier, elem)`)
against `aoData[ aiDisplayMaster[i] ].nTr`, *after* `_removeEmpty()` has silently dropped every
row whose `nTr` is still `null` (a row that has never been drawn). Any mismatch — a re-created
`<tr>`, a row the current draw cycle has not produced yet — yields `[]` → empty API →
the `TypeError` of §3.1.

Measured in the live page for the *healthy* case, which shows the catch-all is what the call
site is really using today:

```js
nodes = aiDisplayMaster.map(i => aoData[i] && aoData[i].nTr).filter(Boolean)  // length 3
$(nodes).filter($tr).length     → 1     // identity match through the jQuery catch-all
$(nodes).filter(tr ).length     → 1     // identity match through the node path
```

So the jQuery form is a **coincidentally working** path, not the API contract the call site
intends: it depends on the row's `<tr>` being *the very node* DataTables cached, and it
degrades to an empty selection instead of resolving the row DataTables itself is holding.

### 3.3 Blast radius

```
$ git grep -n '\.cell(' -- gui/templates
gui/templates/usermanagement/usersAssignPlan.html:1334:    assignDt.cell(tr, 4).data(buildSelectHtml(u), false);
```

**One call site in the repository** — the fix is entirely local. `api/**`, the DB schema and
all ten i18n bundles are untouched.

## 4. The fix

`gui/templates/usermanagement/usersAssignPlan.html` — `onRoleChange()`:

1. **Pass the DOM node, not the jQuery wrapper** — `assignDt.cell(tr[0], 4)`.
   This takes DataTables' documented, exact path (`sel.nodeName` → `aoData[sel._DT_RowIndex].nTr === sel`),
   so the row is resolved by the row index DataTables stamped on the node itself instead of by
   a fragile identity scan. Added a `tr.length` guard so an empty wrapper can never reach the API.
2. **Never let a best-effort cell refresh abort the save-state update.** Only the refresh is
   wrapped in `try/catch`. The `users` model stays the source of truth for the Save payload, so
   a cache refresh that cannot be applied must never abort `$('#saveBtn')` — a stale Save button
   is the worse of the two possible failure modes, and the model keeps Save correct either way.

No user-facing string was touched → **no i18n key added** (nothing to validate in the bundles).
No BFF change.

### Alternatives considered and rejected

* **`renderUsersTable(keep)` instead of the targeted `.data()`** — a full grid rebuild +
  DataTables re-init on *every* select change: destroys the user's page/search focus and the
  `stateSave` view and re-runs `createdRow` for every row. Heavy, and a behaviour regression.
* **`assignDt.row(tr).data(...)` / `.row(tr[0]).index()`** — same class of fix as (1) but an
  extra API round trip for no gain.
* **Numeric index `assignDt.cell(assignDt.row(tr[0]).index(), 4)`** — resolves too, but adds a
  second lookup that can itself fail and is harder to read than the node form.

## 5. Verification — the 9-case matrix, 11/11 PASS

| # | Case | Expected | Observed | Result |
|---|---|---|---|---|
| 1 | first change on a non-admin row | highlight + *Modified* badge + **Save enabled** | `dirty=true`, `saveDisabled=false`, badge present | PASS |
| 2 | **second change of the same row** (the reported case) | no console error, Save state correct | `saveDisabled === !isDirty()`, **0 console messages** | PASS |
| 3 | change back to the original value | **Save disabled**, badge + class gone | `saveDisabled=true`, `dirty=false`, no badge, no `changed` | PASS |
| 4 | change on a second row | Save enabled, both rows tracked | `saveDisabled=false`, badge on 1682 only | PASS |
| 5 | sort re-draw (`order([[4,'desc']])` then `[[1,'asc']]`) | chosen options survive | `1681:4` before/after both sorts | PASS |
| 5b | search re-draw (`search('tina')` then clear) | chosen option survives | `1681:4` during search and after clearing | PASS |
| 6 | bulk "Do" rebuilds the grid, then a per-row change | grid rebuilt, per-row change still updates Save | `bulkVals=[1:0,1681:7,1682:7]`, `saveDisabled=false` | PASS |
| 7 | admin row (locked select) | never dirty, Save stays disabled | `select.disabled=true`, `dirty=false`, `saveDisabled=true` | PASS |
| 8 | pagination disabled (`paginationCfg.enabled=false`) | **no DataTable**, handler still works, no exception | `assignDt === null`, `saveDisabled=false`, badge present | PASS |
| 9 | Event Viewer / `events` | no new Error/Warning rows | only the 2 `audit_login_succeeded` rows (`log_level=16`) | PASS |
| 10 | Save round trip | `user_testplan_roles.role_id` persisted, Save re-disabled | `1681 → 6` in the DB; `saveDisabled=true`, `dirty=false` | PASS |
| 11 | syntax gate | inline `<script>` parses | `new Function(<inline script>)` → clean | PASS |

Browser console after the whole matrix: **no messages at all** (`list_console_messages
types=[error,warn]` → `<no console messages found>`) — the pre-fix `TypeError` is gone.

## 6. Files changed

| File | Purpose |
|---|---|
| `gui/templates/usermanagement/usersAssignPlan.html` | `onRoleChange()` — the cell re-sync now takes the DOM node, a `tr.length` guard, and a `try/catch` that cannot abort the Save-state update |
| `CHANGELOG` | one-line 2.0.1 KEY BUGFIX entry (AGENTS.md rule 22) |
| `tmp/TLU_Test_Cases.md` | the 11-case regression suite |
| `tmp/fixtures_1680.sql` | reproduction fixture (project + `nodes_hierarchy` row + plan + 2 users + plan roles) |
| `docs/Bugfix-Issue-1680-usersAssignPlan-onRoleChange-TypeError.md` | this page |