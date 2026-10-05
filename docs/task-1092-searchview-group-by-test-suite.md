# Task — Issue #1092: group-by-test-suite results in `search/searchView.html` (gap vs legacy)

**Screen:** ASIDE > Search > *Search Test Cases* —
`gui/templates/search/searchView.html?tproject_id=<id>`
**BFF API:** unchanged (`api/search/index.php`, `action=search`)
**Wiki mirror:** `Search-Test-Cases.md` (GitHub Wiki) / `docs/WIKI-SEARCH-TEST-CASES.md`

## Gap

Legacy built the quick-search results with `tlExtTable`
(`lib/testcases/tcSearch.php:339-351`, `buildExtTable`):

```php
$table = new tlExtTable($columns, $matrixData, 'tl_table_test_case_search');
$table->setGroupByColumnName($labels['test_suite']);   // group per suite path
$table->setSortByColumnName($labels['test_case']);
$table->sortDirection = 'DESC';
$table->showToolbar = true;
$table->allowMultiSort = false;
$table->toolbarRefreshButton = false;
$table->toolbarShowAllColumnsButton = false;
$table->storeTableState = false;
```

i.e. rows grouped under **collapsible** headers
`<suite path> (N Items)` (`lib/functions/exttable.class.php:591`,
`groupTextTpl`), the grouped column hidden while grouping
(`exttable.class.php:55` `hideGroupedColumn=true`), one grid-toolbar button
(`gui/javascript/ext_extensions.js:182-198`) and single-column sorting.

The modernized screen rendered a **flat** DataTable: the suite path was just
another repeated column, there was no grouping, no group header, no toolbar
and no sort order at all (server order).

## What is implemented now

| Legacy behaviour | Modern implementation |
|---|---|
| `setGroupByColumnName('test_suite')` | DataTables RowGroup (`rowgroup/1.4.1`), `dataSrc: 'path'` |
| `groupTextTpl '{text} (N Items)'` | teal `tr.dtrg-group` band `Suite Alpha (3 Items)` (singular: `(1 Item)`) |
| Group header toggle | click on a group header row collapses/expands only that group |
| `collapseAllGroups()` / `expandAllGroups()` toolbar button | **Expand/Collapse Groups** button + `Groups collapsed` / `Groups expanded` info line |
| `hideGroupedColumn = true` | `Test Suite` column hidden by default |
| `toolbarShowAllColumnsButton = false` (legacy could not undo the hiding) | **Show all Columns / Hide Test Suite column** toggle — deliberate modern superset |
| `setSortByColumnName('test_case')` + `sortDirection='DESC'` | order `[[suite path,'asc'],[test case,'desc']]`; the grouped column stays the primary sort criterion (`pinGroupOrder()` on `order.dt`) so sorting by another column never splits a group |
| `allowMultiSort = false` | `orderMulti: false` |
| `storeTableState = false` | nothing persisted in `localStorage` |
| `toolbarRefreshButton = false` | no Refresh button (the *Find* button re-runs the search) |

The **plain-`<tbody>` fallback** used when the DataTables CDN is unavailable
(`Refs #799`) emits the same group headers and supports the same
expand/collapse, so the grouping is never lost.

Result rows became objects instead of positional arrays so ordering/filtering
use the raw suite path while the visible cell stays escaped.

**No BFF change was required**: `api/search/index.php` already returns `path`
per row (from `get_full_path_verbose(..., 'path_as_string')`).

## Screenshots

`docs/screenshots/1092-searchview-grouped-results.png` (repository) and
`issue-1092-searchview-grouped.png` (GitHub Wiki) — grouped quick-search
results with the grid toolbar.

## i18n

7 new keys in **all 10** bundles: `sv.grid.expandCollapseGroups`,
`sv.grid.showAllColumns`, `sv.grid.hideSuiteColumn`, `sv.grid.groupItem`,
`sv.grid.groupItems`, `sv.grid.groupsExpanded`, `sv.grid.groupsCollapsed`.

## Test data

`tmp/fixtures_1092.sql` (idempotent, git-ignored): test project `SRCH1`
(`id=1`, prefix `TS1`) with `Suite Alpha` (3 cases) and `Suite Beta`
(2 cases, one of them with 2 versions), so more than one group and a
multi-version row are exercised.

## Test suite

`tmp/TLU_Test_Cases.md`, suite `## Task — Issue #1092` — 10/10 PASS
(T1 grouping + counts, T2 hidden suite column, T3 default DESC order,
T4 single-group toggle, T5 collapse/expand all, T6 column toggle,
T7 sort by another column keeps groups intact, T8 filter recounts the group,
T9 Reset, T10 no console errors).