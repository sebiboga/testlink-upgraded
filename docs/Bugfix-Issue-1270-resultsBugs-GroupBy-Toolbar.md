# Issue #1270: resultsBugs — Group-by Test Suite + Toolbar

**Files changed:** `gui/templates/results/resultsBugs.html`, `gui/templates/i18n/*.json`

- Added DataTables RowGroup (loaded after core) to group rows by Test Suite with collapsible headers and item counts (`rb.groupItem`/`rb.groupItems`).
- Added toolbar: Expand/Collapse All Groups, Show All Columns, Reset Filters, Reset to default state, Refresh.
- Added per-column filters (Suite, Test Case) in table footer; Bugs column not filterable.
- Preserved default order (suite asc, TC asc). Added `collapsedGroups` state and handlers mirroring metricsDashboard patterns.
- Added `rb.*` i18n keys in all locale bundles.

Screenshot: [issue-1270-browser-test.png](docs/screenshots/issue-1270-browser-test.png)

