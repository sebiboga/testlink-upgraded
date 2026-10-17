# Bug Fix - Issue #1931: DataTables header aria-label accumulation on destroy+re-init

## Summary
Fixed a11y issue where DataTables 1.13 appends sort hint text to `<th aria-label>` on each destroy+re-init cycle, causing the accessible name to grow unbounded across re-renders.

## Root Cause
- DataTables writes the sort hint into `th[aria-label]` during initialization.
- `destroy()` does not clear `th[aria-label]` when reusing the same `<thead>`.
- Subsequent re-init appends another hint string, causing accumulation (e.g. "Name: activate to sort column ascending: activate to sort column ascending...").

## Fix
Added a helper `clearHeaderAria()` that strips `aria-label` from table header cells and call it after `table.destroy()` in re-init paths. Pattern matches the existing fix in `gui/templates/risk/riskRegister.html`.

```js
function clearHeaderAria() {
  $('#dataTable thead th').removeAttr('aria-label');
}
// called after: if (table) { table.destroy(); ... clearHeaderAria(); }
```

## Files Changed
- Applied to all templates with both `.destroy()` and `.DataTable()` (all modernized screens reusing the same thead). Includes `testEnvironments.html` (explicitly mentioned), plus many others across cfields, plans, requirements, results, search, testcases, usermanagement, etc.

## Verification
- Triggered re-render multiple times (filter/refresh) - aria-label remains stable (single sort hint) per sortable column.
- No functional regression to sorting/pagination.
- Regression test added to `tmp/TLU_Test_Cases.md` as "Regression — Issue #1931".

## Related
- Refs #1930, parent #1066
