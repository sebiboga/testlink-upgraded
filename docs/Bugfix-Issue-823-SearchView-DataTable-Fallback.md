# Bugfix Issue #823 — Search View DataTable fallback missing

## Problem
`gui/templates/search/searchView.html` had the same defect as #799 (fixed
only in `searchQuickView.html`): `renderResults()` called
`$('#resTable').DataTable({...})` unguarded. When the DataTables CDN was slow
or unavailable, this threw `TypeError: $(...).DataTable is not a function`,
leaving the results wrapper visible with a non-zero match count but an
**empty table body** and the Find button disabled.

## Fix
Ported the #799 pattern into `searchView.html` `renderResults()`:
1. Render plain `<tbody>` rows first (rows always display).
2. Enhance with DataTables only when `typeof $.fn.DataTable === 'function'`,
   wrapped in try/catch.

Commit: `39d9c5b11`. Refs #799.

## Verification
- With DataTables CDN disabled: rows render, no TypeError, Find re-enabled.
- Normal path: 85 rows, sort/search/paginate intact.
