# tcAssign2Tplan — Modernized (Issue #1321)

Modernized DataTables parity for "Assign Test Case to Test Plan"
(`gui/templates/testcases/tcAssign2Tplan.html`).

## Was
The plan grid was a plain HTML table — no sort, search, pagination, or
per-page length menu (a regression vs legacy `dashio/testcases/tcAssign2Tplan.tpl`
which used `DataTables.inc.tpl` with `DataTablesSelector="#item_view"` and
length menu `[[25,50,75,-1],[25,50,75,"All"]]`).

## Now
- DataTables 1.13.7 (Bootstrap 5 theme, CDN) included: CSS
  `dataTables.bootstrap5.min.css`, JS `jquery.dataTables.min.js` +
  `dataTables.bootstrap5.min.js` (same CDN pattern as `suiteView`/`tcScripts`).
- `renderPlans()` re-initializes `#planTable` with:
  - `pageLength: 25`, `lengthChange: true`, length menu `[25,50,75,All]`
  - `searching: true`, search box (localized via `common.search`)
  - `orderable` columns, checkbox column non-orderable
  - destroys any prior instance (`DataTable(...).destroy()`) before re-init,
    avoiding "cannot reinitialise DataTable" on repeated adds/re-renders.

## Verification
- Legacy/modern diff measured during INVESTIGATION (issue comment).
- JS+i18n statically validated (syntax check, all bundle keys present).
- Browser round-trip requires a seeded testproject/testplan/testcase — the
  CI-injected DB is a fresh import with no such fixture yet, so full UI
  interaction scripting is pending a fixture (`tmp/TLU_Test_Cases.md` lists
  the manual steps).
