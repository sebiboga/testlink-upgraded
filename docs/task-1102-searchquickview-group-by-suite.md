# Task — Issue #1102: Quick Search results group-by-test-suite + ExtTable toolbar

**Screen:** `gui/templates/search/searchQuickView.html` (Quick Search)
**Issue:** https://github.com/sebiboga/testlink-upgraded/issues/1102
**Branch:** `task/issue-1102-searchquickview-grouping`

## The gap

Legacy quick-search results (`lib/testcases/tcSearch.php` + `gui/templates/dashio/testcases/tcSearchResults.tpl`)
render an ExtTable grouped by test suite with a collapsible group header and a
toolbar; `searchQuickView.html` rendered a **flat** DataTable (suite as a plain
column, no grouping, no toolbar, default order = suite path ASC).

Measured before the fix (`renderResults`, browser evaluation after searching
`queen` on project QSDemo):

```json
{ "rows": 5, "groupRows": 0, "toolbarExists": false, "order": "[[0,\"asc\"]]",
  "paths": ["Alpha","Alpha","Alpha/A1","Beta","Beta"] }
```

## Legacy configuration (tcSearch.php:339-353)

```php
$table = new tlExtTable($columns, $matrixData, 'tl_table_test_case_search');
$table->setGroupByColumnName($labels['test_suite']);  // :341
$table->setSortByColumnName($labels['test_case']);    // :342
$table->sortDirection = 'DESC';                       // :343
$table->showToolbar = true;                           // :345
$table->allowMultiSort = false;                       // :346
$table->toolbarRefreshButton = false;                 // :347
$table->toolbarShowAllColumnsButton = false;          // :348
$table->storeTableState = false;                      // :353
```

Toolbar buttons actually shown: **Expand/Collapse Groups**
(`inc_ext_table.tpl:193`, default true) and **Reset Filters**
(`inc_ext_table.tpl:292-302`, `toolbarResetFiltersButton` default true →
`grid.filters.clearFilters()`); refresh / show-all-columns / default-state are
off. `hideGroupedColumn=true` (`exttable.class.php:55`) hides the grouped
column; `groupTextTpl '{text} (N Items)'` (`exttable.class.php:591`) formats the
group header.

## What changed (no BFF change)

`gui/templates/search/searchQuickView.html`:

- `rowGroup.dataTables` CSS + JS includes; teal `tr.dtrg-group` band CSS.
- New `#gridToolbar` (between `#noResults` and `#resultsWrap`):
  **Expand/Collapse Groups**, **Reset Filters**, **Show all Columns /
  Hide Test Suite column**, plus an info line (`Groups collapsed/expanded`,
  `Filters cleared`).
- Result rows converted from positional arrays to objects (`path/testcase/
  summary/version/actions`) so the grouped column orders on the RAW path while
  the visible cell stays escaped; plain-`<tbody>` fallback keeps the group
  headers when the DataTables CDN is unavailable (Refs #799).
- DataTable config: `rowGroup.dataSrc:'path'` + `startRender` header
  `<i chevron> path (N Items)`, `order [[path,'asc'],[testcase,'desc']]`,
  `orderMulti:false`, `pinGroupOrder()` on `order.dt`, `visible:false` on the
  suite column, `applySuiteColState()`.
- Single-header toggle **deletes** the presence-based `collapsedGroups` key on
  expand (`isGroupCollapsed` = `hasOwnProperty`) — the naive
  `collapsedGroups[key] = !collapsedGroups[key]` leaves the key present with
  value `false` and the group can never expand again (measured during testing;
  same contract as `searchView.html:273`).
- `resetForm()` hides the toolbar and clears the group state.

i18n (all 10 locale bundles): `sv.grid.resetFilters`, `sv.grid.filtersCleared`
(the other `sv.grid.*` keys exist since #1092). Gate:
`python3 -m json.tool` on every bundle + `bash ai/verify_i18n_coverage.sh`
→ 9/9 PASS.

## Fixture

- `tmp/fixtures_1101.php` — project `QSDemo` (prefix `QS`), suites Alpha / A1 / Beta, 5 cases.
- `tmp/fixtures_1102.php` — renames the 5 cases so the quick free text `queen`
  matches one case per suite (the quick field searches the TITLE) and nests
  `A1` under `Alpha` → paths `Alpha` (2), `Alpha/A1` (1), `Beta` (2).

## Verification

- Suite `Task — Issue #1102` in `tmp/TLU_Test_Cases.md` — 8 cases PASS
  (grouping/counts, single-header toggle, cross-group expand/collapse,
  show/hide suite column, Reset Filters, sort pinning, plain-table fallback,
  empty-result + Reset + Event Viewer).
- `events` table: only `log_level 16` audit rows — no ERROR/WARNING.
- Screenshots: `docs/screenshots/1102-searchquickview-flat-before.png`,
  `docs/screenshots/1102-searchquickview-grouped-after.png`.
