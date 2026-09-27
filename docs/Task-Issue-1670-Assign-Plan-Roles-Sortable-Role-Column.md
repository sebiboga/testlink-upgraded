# Task 1670 — the legacy **sortable** `Test Plan Role` column is missing in `usersAssignPlan.html`

**Issue:** [#1670](https://github.com/sebiboga/testlink-upgraded/issues/1670)
**Status:** IMPLEMENTED & VERIFIED (2026-09-27) — branch `task/issue-1670`, commit `230b9e6fe`
**Screen:** Assign Test Plan Roles — `gui/templates/usermanagement/usersAssignPlan.html`
**BFF:** unchanged (the sort is 100 % client-side; `GET /roles/meta/tplan-roles` already ships every role name)
**i18n:** unchanged (no new label — the sort key reads the already-localized `<option>` text)

## The gap

Legacy `gui/templates/dashio/usermanagement/usersAssign.tpl:111-120` pushes exactly one
column setting into the legacy DataTable:

```smarty
addToDataTablesConfig = {
  "columnDefs": [ { "searchable": false, "targets": 1 }],
  "language": { "search": "{$labels.User}" }
};
```

There is **no `orderable` key anywhere** in `usersAssign.tpl`, and
`DataTables.inc.tpl:100-104` only adds `{lengthMenu, stateSave:true}`. The legacy
2-column grid (`usersAssign.tpl:216-219` — `th` = `User` + `Test Plan Role`, the cell being a
`<select>` at `:239-271`) therefore had **both** columns orderable: clicking `Test Plan Role`
grouped the user list by the assigned role.

The modern plan screen had disabled ordering on that column:

```js
{ searchable: false, orderable: false, targets: 4 }   // gui/templates/usermanagement/usersAssignPlan.html:503 (pre-fix)
```

so `Plan Role Override` was neither sortable nor searchable — while its twin
`usersAssignProject.html:505-508` (same legacy template, same config) deliberately keeps the role
column sortable. The two modernized twins diverged from each other and from 1.9.20.

## Implementation

`gui/templates/usermanagement/usersAssignPlan.html` only, +43/-3:

1. **Orderability restored** — `columnDefs` `targets:4` is now
   `{ searchable: false, orderDataType: 'tlu-role-select', targets: 4 }`. The column stays
   **non-searchable** exactly as legacy had it; the `#` ordinal (0) stays non-orderable.
2. **A real sort key** — re-enabling `orderable` alone is *not* enough, and this is the
   non-obvious part of the gap. The cell contains a `<select>`, so DataTables' default string key
   is **every option label concatenated**:

   ```
   srt1670guest     -> "<inherited> guest<reserved system role 1>…<no rights>test designerguest…leader"
   srt1670designer  -> "revert to inherited test designer<reserved system role 1>…leader"
   ```

   All rows share one long suffix and differ only in the first label, so a naive sort is
   effectively a coin toss between `<inherited> …` and `revert to inherited …` — **not** the
   "group the list by assigned role" behaviour legacy intent describes. The fix registers a sort
   key provider that returns the **selected** option's label:

   ```js
   $.fn.dataTable.ext.order['tlu-role-select'] = function (settings, col, visCol) {
     var nodes = this.api().column(visCol, { order: 'index' }).nodes();
     var out = [];
     for (var i = 0; i < nodes.length; i++) {
       var sel = nodes[i] ? nodes[i].querySelector('select') : null;
       if (!sel || sel.selectedIndex < 0) { out.push(''); continue; }
       out.push($(sel.options[sel.selectedIndex]).text());
     }
     return out;
   };
   ```

   `ext.order` is the documented DataTables extension point for "user editable elements such as
   form inputs" (dt.js:14487), and the key is re-evaluated on every sort/draw, so it follows a
   live edit (`onRoleChange` re-syncs the cell data), the rows rebuilt by `applyBulkRole()` and any
   locale change — with no extra bookkeeping and no stale cached state.

### Two DataTables 1.13.7 traps (measured, both avoided)

| attempt | measured result |
|---|---|
| `orderDataType:'dom'` + `orderData:'select option:selected'` | a draw **throws** `Cannot read properties of undefined (reading 'sType')`; the header stays `sorting` and nothing re-orders. Cause: 1.13.7 ships an **empty** `$.fn.dataTable.ext.order` registry (dt.js:14494) — `dom-text` / `dom-select` / `dom-checkbox` are only *documented examples* (dt.js:13115-13141), never implemented. |
| provider calling `settings.api()` | **silent no-op sort**: no console error, header `sorting`, `order()` never toggles to desc. Cause: 1.13.7 has no `settings.api()`; the core invokes the provider as `customSort.call(settings.oInstance, settings, idx, _fnColumnIndexToVisible(settings, idx))` (dt.js:6413), so the documented form is `this.api()`. This is the dangerous one — a click-only smoke test would call it "implemented". |

## Screenshots

Plan Role Override sorted ascending — the grid is grouped by the **assigned** role
(`<inherited> <no rights>` → `<inherited> admin` → `<inherited> guest` → `<inherited> leader` →
`leader` → `senior tester` → `test designer`), header showing `sorting_asc`:

![issue-1670-usersAssignPlan-role-column-sorted](issue-1670-role-column-sorted.png)

## Verification evidence

Fixture `tmp/fixtures_1670.php` (re-run on every fresh DB): tproject `SRT1670` (id 1), tplan
`SRT1670 Plan` (id 2, + `SRT1670 Plan B` id 3), six users with **distinct** global roles and
**distinct** plan-role assignments so the sort is observable —
`srt1670norights` (inherited `<no rights>`), `srt1670designer` (`test designer`),
`srt1670guest` (inherited `guest`), `srt1670senior` (`senior tester`), `srt1670tester`
(`leader`), `srt1670leader` (inherited `leader`).

| # | Check | Measured |
|---|-------|----------|
| 1 | column orderable | `aoColumns.map(bSortable) = [false,true,true,true,true]` (was `[…,false]`), `th = "sorting"` (was `sorting_disabled`) |
| 2 | click 1 | `order() = [[4,"asc"]]`, `th = "sorting sorting_asc"`, rows regrouped by assigned role |
| 3 | click 2 | `th = "sorting sorting_desc"`, exact reverse |
| 4 | click 3 | third-click default-order reset fires: `order() = [[1,"asc"]]` (handler at `usersAssignPlan.html:158-168`) |
| 5 | key semantics | selected option drives the order (not the concatenated option list) |
| 6 | live edit | `srt1670guest` → `senior tester` moves into that group in both directions; `isDirty()=true`, Save enabled, "Modified" badge |
| 7 | filtered sort | filter `srt1670` (6 of 7 rows) → all 6 keyed, correct order |
| 8 | bulk `Set roles to` | grid destroyed + re-rendered → `keep.order` preserves `[[4,"asc"]]`; re-sort on the fresh rows works |
| 9 | search exclusion | `search('leader')` → 1 row; `srt1670tester` (selected role `leader`) is **not** matched — column stays non-searchable |
| 10 | locale `ro` | `lang=ro`, headers `Utilizator / Nume complet / Rol Mostenit / Suprascriere Rol Plan`, sort correct on Romanian option text (`<mostenit> …`) |
| 11 | plan switch 2→3→2 | `bSortable` intact on every rebuilt grid; the sort view intentionally resets to the default on a plan switch (screen design, mirrored from the project twin, issue #930) and the column is sortable again |
| 12 | console | 0 errors / 0 warnings across the whole session |
| 13 | Event Viewer | `select log_level, count(*) from events group by log_level` → `16 | 2` (audit only); `log_level <> 16` → **0 rows** |
| 14 | syntax gate | `node --check` on the extracted inline JS → `SYNTAX_OK` |

Full manual pass: `tmp/TLU_Test_Cases.md` — **Suite 1670 — 14/14 PASS**.

## Related issues

- #939 — DataTables pagination + "User" search for this screen (its `columnDefs` note is the one
  this issue corrects; its case "Sorting" only exercised the Login column).
- #930 — the identical grid features closed for the Assign Test Project Roles screen, which already
  kept the role column sortable.
- #1641 / #1642 — config-driven pagination and `stateSave`, separate legacy grid features.

Refs #1670.
