# Metrics Dashboard — test-plan grouping, grid toolbar and column filters (issue #1018)

**Screen:** `gui/templates/results/metricsDashboard.html` (front-end only — the BFF
`api/metrics/index.php` needed no change)
**Wiki:** [Metrics-Dashboard](https://github.com/sebiboga/testlink-upgraded/wiki/Metrics-Dashboard)
**Test suite:** `tmp/TLU_Test_Cases.md` → suite 1018 (12 cases, all PASS)

## What was missing

Modernization of this screen ported the **data grid** (rows, columns, progress sort) but
dropped the legacy `Ext.Table` **grid chrome**. The modern toolbar had exactly two controls
(a *Show only active test plans* checkbox and the *Public link* button) and the table was a
flat per-platform grid with a single global search box:

| Legacy (`lib/results/metricsDashboard.php`) | Legacy reference |
|---|---|
| `setGroupByColumnName(test_plan)` — grouped by test plan | `:112-114` |
| `showGroupItemsCount = true` → group header `… (N Item[s])` | `:123`, `exttable.class.php:585-592` |
| `hideGroupedColumn = true` — grouped column hidden in child rows | `exttable.class.php:55` |
| `showToolbar`, `toolbarExpandCollapseGroupsButton`, `toolbarShowAllColumnsButton`, `toolbarResetFiltersButton`, `toolbarDefaultStateButton` | `:115-121`, `exttable.class.php:104,109,114,124` |
| `setSortByColumnName(progress)` + `sortDirection = 'DESC'` | `:116-117` |
| qty column of every status `'hidden' => true` | `getColumnsDefinition()` `:333` |
| platform column `'filter' => 'list', 'filterOptions' => $platforms` | `:320-321` |
| `'filter' => 'string' / 'numeric'` per column (Ext.grid.GridFilters) | `:316-341` |
| group column value `"<plan> - Overall Progress: X%"` | `:50-70` |

## What was implemented

### 1. RowGroup plugin

The DataTables **RowGroup** plugin is now loaded from
`https://cdn.datatables.net/rowgroup/1.4.1/…` (CSS + JS).

> **Gotcha:** the versioned path `cdn.datatables.net/1.13.7/js/dataTables.rowGroup.js`
> returns **404** — only the `rowgroup/<version>/` sub-path works. The already modernized
> sibling screen `gui/templates/results/tplanWithCF.html:107` already used the working
> path, and that screen's implementation is the pattern this one follows.

### 2. Grouping by test plan

`renderTable()` now builds **object rows** and enables
`rowGroup: { dataSrc: 'group', startRender: … }` when `show_platforms` is true — the legacy
condition `if($gui->show_platforms)`. The group header renders

```
Test Plan: <plan name> - Overall Progress: <X>% (<N> item | <N> items)
```

with the legacy singular/plural rule and a click-to-collapse caret driven by a
`collapsedGroups` map. The **grouped Test Plan column is hidden in the child rows**
(`hideGroupedColumn = true`).

The header progress needs **no new endpoint**: `api/metrics/index.php:320-330` already
repeats the plan-level `overall` block (`active` / `executed` / `progress` / `statuses`)
onto every platform row of that plan, so the client reads it from the first row it sees.

### 3. Column visibility

The status **qty** column is created with `visible: false` — legacy `'hidden' => true` —
while its `%` sibling stays visible. **Show all columns** reveals the qty columns and the
matching tfoot filter cells, but deliberately leaves the grouped Test Plan column hidden,
because `hideGroupedColumn` is an independent flag.

### 4. Toolbar

| Button | Behaviour |
|---|---|
| Expand all groups | clears `collapsedGroups`, redraws |
| Collapse all groups | collapses every group |
| Show all columns | reveals the qty columns (grouped column stays hidden) |
| Reset Filters | clears every column filter + the platform filter; auto-hidden while no filter is active |
| Reset to default state | re-hides the grouped + qty columns, clears all filters, restores the order, expands all groups |
| Refresh | reloads the dashboard from the BFF, honouring *Show only active test plans* |

### 5. Per-column filters and the platform list filter

A `<tfoot>` row carries one filter input per searchable column, wired to
`column(i).search()`. The **platform list filter** is a `<select>` built from the platform
values present in the response plus an *All platforms* entry — the modern equivalent of the
legacy `'filter' => 'list', 'filterOptions' => $platforms`. When no platforms are shown
both the select and the Expand/Collapse buttons are hidden.

### 6. Sort parity

`order: [[0,'asc'],[progressCol,'desc']]` when grouped (group ascending keeps the groups
contiguous) and `[[progressCol,'desc']]` otherwise — legacy
`setSortByColumnName(progress) + sortDirection = 'DESC'`.

### 7. i18n

13 new keys in **all 10** locale bundles (`en, ro, de, es, fr, it, ja, pt, ru, zh`):
`md.btnExpandAll`, `md.btnCollapseAll`, `md.btnShowAllColumns`, `md.btnResetFilters`,
`md.btnDefaultState`, `md.btnRefresh`, `md.filterPlatform`, `md.filterAllPlatforms`,
`md.groupPrefix`, `md.groupItem`, `md.groupItems`, `md.filtersCleared`,
`md.defaultStateRestored`. `md.overallProgress` was re-cased to *Overall Progress* in all
10 bundles so the group header matches the legacy label quoted in the issue.

## Defects found and fixed while testing

1. **Three toolbar buttons invisible** — the shared `.tbtn` rule shipped
   `display: none` (meant for the auto-hidden *Reset Filters* button) and hid all six.
   Fixed by making `.tbtn` `display: inline-flex`; only *Reset Filters* is JS-toggled now.
2. **`TypeError: table.column(...).searchable is not a function`** — DataTables 1.13.7 has
   no `searchable()` on the column API. Replaced with a `colSearchable[]` mirror built from
   the `columns[].searchable` config, computed **after** the Progress column is pushed
   (computing it earlier gave the Progress column a filter input it must not have).
3. **Reset Filters always visible** — an empty `<select>` returns `null`, not `''`, so
   `anyFilterActive()` was `true` before any filter was set. Fixed with
   `($('#platformFilter').val() || '') !== ''`.
4. **Duplicated `Progress %` header** — the template kept a placeholder
   `<thead><tr id="plansHead"></tr></thead>`, so DataTables' own header row was a *second*
   `<tr>` in the same `<thead>`. Replaced with a bare `<thead></thead>`.
5. Missing `esc()` / `toast()` helpers on this screen — both added; every injected value
   goes through `esc()` or jQuery `.text()`.

## Regression matrix (suite 1018)

| Case | Check | Result |
|---|---|---|
| 1 | 3 group headers with the exact legacy text; singular `(1 item)` for the 1-platform plan | PASS |
| 2 | group header click collapses (4→2 rows) and re-expands (→4) | PASS |
| 3 | Collapse all → 0 rows; Expand all → 5 rows | PASS |
| 4 | Show all columns: 7 → 11 headers, grouped column still hidden, 12 footer cells | PASS |
| 5 | Active TCs filter `1` → 2 rows; Reset Filters appears, clears and hides itself | PASS |
| 6 | platform list filter `Linux` → 2 rows, `All platforms` → 5 | PASS |
| 7 | Reset to default state from a dirty state → 7 headers, 5 rows, 3 expanded groups | PASS |
| 8 | `show_platforms = false` → 0 group headers, Test Plan visible, expand/collapse + platform filter hidden | PASS |
| 9 | Refresh → 3 group headers, default column set | PASS |
| 10 | repeated re-render → 1 `<tr>` in `<thead>`, 7 `<th>` (no duplication) | PASS |
| 11 | Event Viewer / `events` | 0 rows with `log_level IN (2,3)` | PASS |
| 12 | locale switch (ro) | see the issue comment |

## Not in scope — since resolved by #1019

`show_test_plan_status` (legacy `lib/results/metricsDashboard.php:51-60`) appends a
per-status breakdown to the test-plan cell. That scope was **#1019** and it is now
**implemented** — see
[Issue-1019-MetricsDashboard-PerStatus-Breakdown](Issue-1019-MetricsDashboard-PerStatus-Breakdown.md)
(`planStatusBreakdown()` in `gui/templates/results/metricsDashboard.html`).

## Fixtures used

`testprojects` 1 · `testplans` 101/102/103 · `builds` 101/102/103 · `platforms` 1 "Windows",
2 "Linux" · `tcversions` 1/2 · `testplan_tcversions` 6 rows · 3 `executions` ·
`user_testproject_roles` + `user_testplan_roles` for `admin` (user 1). A plan **without**
platforms is required to exercise the singular item count, and every plan needs a build or
the BFF answers `warning: no_testplans_available`.
