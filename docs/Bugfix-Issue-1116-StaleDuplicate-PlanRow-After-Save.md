# Issue 1116 — Stale duplicate plan row after save (planView.html)

**Issue:** [#1116](https://github.com/sebiboga/testlink-upgraded/issues/1116)
**Branch:** `fix/issue-1116`
**Status:** VERIFIED-FIXED (2026-09-06)

## Symptom

On the modernized screen `gui/templates/plans/planView.html` (Test Plan Management list), editing
a plan via the modal and saving causes the DataTable to render **a stale duplicate row**
alongside the correct updated row: 5 `<tr>` elements in the `<tbody>` while the DataTables
info panel reports "Showing 1 to 4 of 4 entries". The extra row has the old name, the old
cell layout (missing numeric-count and CF cells), and links pointing to the same `tplan` id.
Pressing **F5** (hard reload) restores the correct state, confirming the issue is purely
frontend.

## Repro steps

1. Log in as admin, open `gui/templates/plans/planView.html?tproject_id=17` with a project
   containing 4 test plans (A, B, C, D) with CF columns visible.
2. Edit **Plan A** via the pencil link → rename → Save.
3. Immediately inspect `#tplanTable tbody` in the browser's Elements panel before navigating away.

**Expected:** 4 rows, each matching the DataTables "Showing 1 to 4 of 4 entries" count.

**Actual (pre-fix):** 5 `<tr>` elements. The 5th is the stale leftover from the previous
render state — same plan id, old cell layout, no numeric-count data in the CF cells.

## Root cause

`gui/templates/plans/planView.html`, `render()` function:

**Teardown** (line ~272):
```js
if (tplanTbl) { tplanTbl.destroy(); $('#tplanTable tbody').empty(); }
```

**Init** (line ~330):
```js
tplanTbl = $('#tplanTable').DataTable({ destroy: true, data: rows, ... });
```

Two interacting defects:

1. **Variable-gated teardown.** The teardown only runs when the JS variable `tplanTbl`
   is truthy. A live DataTables instance can exist while `tplanTbl` is null or stale:
   the DataTables constructor registers the instance early (via `settings.push()`), but
   `tplanTbl` is assigned only at the end of `render()`. If a previous `render()` call
   was interrupted mid-init (e.g. overlapping `load()` fetch responses, or a nullified
   reference), the next `render()` finds `tplanTbl` falsy and skips teardown — the old
   tbody content remains.

2. **`destroy: true` init option restores old DOM.** When the DataTables constructor
   finds a live instance via `$.fn.DataTable.isDataTable` (keyed on the `<table>` node),
   the `destroy: true` option calls `fnDestroy()` which **restores the previous render's
   `<tr>` elements** back into the tbody before initializing the fresh `data: rows`.
   Because teardown was skipped (defect 1), those restored stale rows remain in the DOM
   alongside the new rows — the duplicate.

The `<th class="cf-col">` insert/remove cycle in `load()` further widens the window: each
post-save `load()` detaches and re-attaches `<thead>` children while a live instance is
attached, which can leave the instance's internal column-count state inconsistent with the
DOM, making the draw after `destroy: true`-restore fail to detach the stale rows cleanly.

Blast radius: only `#tplanTable` on `planView.html`. Other DataTable screens that use the
same `if (var) { var.destroy(); ... }` pattern are unaffected because they do not have
the CF-column `<th>` mutation cycle.

## Fix

Registry-aware teardown + remove the `destroy: true` init option:

```js
// line 272
if ($.fn.DataTable.isDataTable('#tplanTable')) {
  $('#tplanTable').DataTable().destroy();
  tplanTbl = null;
}
$('#tplanTable tbody').empty();     // unconditional — always clean

// line 330
tplanTbl = $('#tplanTable').DataTable({
  data: rows,                       // no destroy: true
  ...
});
```

- **`$.fn.DataTable.isDataTable`** checks the DataTables internal registry keyed on the
  `<table>` DOM node, not on a JS variable — catches live instances regardless of the
  `tplanTbl` variable state.
- **`tplanTbl = null`** after destroy prevents stale-state re-destroy in the next call.
- **Unconditional `.empty()`** always clears the tbody before re-init.
- **No `destroy: true`** in the init option prevents the auto-destroy-restore-stale path
  entirely — re-init on a live instance now throws (caught by the guard above) or skips
  the stale-row restore.

### Why this method

The alternative of always setting `tplanTbl = null` after the empty (without changing the
init) does not address defect 2 and still allows the restore-stale path when a live instance
exists. Removing `destroy: true` eliminates the root-cause mechanism entirely. The
`isDataTable()` guard is the DataTables-recommended way to destroy safely
(see DataTables API reference: `table.destroy()` → `.DataTable()`).

## Files changed

- `gui/templates/plans/planView.html` — `render()` function, lines ~272–281 (teardown) and
  ~330 (init options)

## Verification

Regression suite **1116.1–1116.11** (11 cases, 11/11 PASS) executed in the browser against
fixture `php tmp/fixtures_1116.php` (project PLAN1116 id=17, plans A–D, 2 CF columns):

| Case | Result |
|------|--------|
| Rename + row count == info count | PASS |
| Chained rapid rename (same row twice) | PASS |
| Active toggle re-renders correctly | PASS |
| Create → row count increases | PASS |
| Delete → row count decreases | PASS |
| Null-var sabotage case (render while tplanTbl null + live instance) | PASS |
| Empty repopulate cycle | PASS |
| CF column / cell count stable across all steps | PASS |
| DB rows correct (nodes_hierarchy, CF tables) | PASS |
| Event Viewer — no new Error/Warning rows | PASS |
| Browser console — no JS Error/Warning | PASS |

Commit: `fix/issue-1116` branch. Refs #1116.
