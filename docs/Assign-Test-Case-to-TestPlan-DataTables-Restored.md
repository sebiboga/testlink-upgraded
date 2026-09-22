# Assign Test Case to Test Plan — DataTables restored (Issue #1321)

Modernized screen: `gui/templates/testcases/tcAssign2Tplan.html`

## What changed
The modernized `tcAssign2Tplan.html` shipped without DataTables, so the
plan/platform grid lost sort/search/pagination and the length menu that the
legacy `tcAssign2Tplan.tpl` provided via `{include file="DataTables.inc.tpl"
DataTablesSelector="#item_view" DataTablesLengthMenu=$ll}` (with
`$ll = [25, 50, 75, -1], [25, 50, 75, "All"]`).

DataTables 1.13.7 (Bootstrap 5 theme, CDN) was added following the same
pattern already used by the modernized `suiteView.html` and `tcScripts.html`:

- head: `dataTables.bootstrap5.min.css`
- footer: `jquery.dataTables.min.js` + `dataTables.bootstrap5.min.js`
- `renderPlans()` destroys an existing instance (`isDataTable` check →
  `.destroy()`) and re-initializes on `#planTable` per render with:
  - `pageLength: 25`, `lengthChange: true`,
    `lengthMenu: [[25, 50, 75, -1], [25, 50, 75, 'All']]`
  - `searching: true` (live search box)
  - per-column sort with `order: [[2, 'asc']]` (plan name)
  - checkbox column non-orderable (`columnDefs`), pagination
  - localized via existing `TLi18n` keys: `common.search`,
    `common.showEntries`, `common.entries` (present in all 10 locale bundles —
    no new i18n keys required)

Destroy-on-re-render prevents the DataTables "cannot reinitialise DataTable"
error when `renderPlans()` is called again after a successful Add.

## Files
- `gui/templates/testcases/tcAssign2Tplan.html` (+19/−10)
- tests: `tmp/TLU_Test_Cases.md`
- fixtures/screenshot: see issue #1321

## Verification
Static: JS syntax (node --check) OK; all referenced i18n keys present in
every bundle (en, de, es, fr, it, ja, pt, ro, ru, zh).

Browser: pending seeded DB — see issue #1321 INVESTIGATION + IMPLEMENTATION
checkpoints for the measured gap and repro matrix.
