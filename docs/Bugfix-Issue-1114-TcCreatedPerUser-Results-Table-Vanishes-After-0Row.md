# Issue 1114 — tcCreatedPerUser results table vanishes after a 0-row result followed by a re-query

**Issue:** [#1114](https://github.com/sebiboga/testlink-upgraded/issues/1114)
**Branch:** `fix/issue-1114`
**Status:** VERIFIED-FIXED (2026-09-06)

## Symptom

On the modernized screen `gui/templates/results/tcCreatedPerUserOnTestProject.html`,
running a report that yields **0 rows** and then re-running the report with a wider filter
so rows ARE returned leaves the results table missing from the DOM: the
"Report results(n)" heading, the match count and the footer render, but the DataTable does
not. Before the fix a double-`destroy()` on the stale DataTable instance even removed the
`<table id="resTable">` element itself from the DOM — a full page reload was the only way
to get the table back.

Screenshots: before-fix `docs/screenshots/issue-1114-table-missing-after-0row-requery.png`,
after-fix `docs/screenshots/issue-1114-after-fix.png`.

## Repro steps

1. Log in as admin, open
   `gui/templates/results/tcCreatedPerUserOnTestProject.html?tproject_id=1&tplan_id=0`
   with a seeded project that has TCs inside a known creation window.
2. Query a window that matches rows (e.g. `2024-02-01..2024-04-30`) → table renders.
   (The module-level `resTbl` now references a live DataTable instance.)
3. Narrow the window to one with no data (e.g. `2024-02-01..2024-02-10`) →
   `Report results(0)` + "No records found." panel. `resTbl` still points to the
   destroyed instance.
4. Widen the window again → **Show report** → heading shows `Report results(8)` and the
   footer the stats, but the results table is absent (blank area where the DataTable
   should be).

## Root cause

`gui/templates/results/tcCreatedPerUserOnTestProject.html`, `renderResults()`:

```js
if (resTbl) { resTbl.destroy(); $('#resTable tbody').empty(); }   // :276
...
if (!r.rows || r.rows.length === 0) {                              // :278
  $('#resultsWrap').hide();
  $('#noResults').show();
  $('#footerInfo').text('');
  return;                                                          // ← returns WITHOUT resTbl = null
}
```

The empty branch returns without resetting the module-level `resTbl` reference
(`:139 var resTbl = null;`). On the next rows-query the guard at `:276` calls
`destroy()` on the **already-destroyed** instance; that second destroy no longer
restores the original table markup (the first destroy already pulled `#resTable` out of
the DataTable wrapper and tore the internal settings down) and instead removes the
`<table>` node from the DOM (`document.getElementById('resTable') === null`, measured).
The subsequent `$('#resTable').DataTable(...)` initialisation at `:305` has no element
to attach to → nothing renders.

Blast radius: only this screen. `resetForm()` at `:333` already used the correct
`destroy()` + `resTbl = null` pattern; `renderResults()` was the only function that
left the stale reference behind.

## Fix

Minimum change — null the reference right after destroy in `renderResults()`:

```js
if (resTbl) { resTbl.destroy(); resTbl = null; $('#resTable tbody').empty(); }
```

The next rows-query then skips the stale guard and re-initialises the DataTable from
scratch (identical to `resetForm()`).

### Why this method

Because the failure is a stale JS state (destroy → instance still referenced), clearing
the reference at the point of destruction is the smallest correct fix. Alternatives
rejected: re-initialising inside the empty branch (`resTbl = null` before `return`) —
equivalent but implicit, and relying on DataTables' `destroy:true` init option — that
option only guards live re-init, it cannot recover a table node already removed by a
double destroy.

## Files changed

- `gui/templates/results/tcCreatedPerUserOnTestProject.html` — `renderResults()`, line 276

## Verification

Regression matrix executed in the browser (project `PerUser Demo`, 8 tcversions in
Feb–Apr 2024, `resTbl` state measured via page JS after every step):

| Case | Result |
|------|--------|
| rows → 0-rows → rows (primary symptom) | PASS — table renders all three times |
| 0-rows first → rows | PASS |
| rows → rows (normal re-query) | PASS |
| Reset after rows, then rows again | PASS |
| CSV download `/api/results/index.php/csv` | PASS (200, CSV header + 8 rows) |
| `events` table | no new Error/Warning rows from screen flows |

Regression suite recorded as **1114.1–1114.9** in `tmp/TLU_Test_Cases.md` — 9/9 PASS.