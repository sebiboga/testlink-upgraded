# Test Cases Created Per User (tcCreatedPerUserOnTestProject)

The "Test Cases Created Per User" report shows every test case version created by a selected user within a given time window.

## Filters
- User: drop-down (Any or specific user)
- Start date/hour and End date/hour: restrict creation time window
- Results grid: global search; per-column footer filters (User, Test Suite, Test Case, Created, Last Modification as text; Importance as select: All/Low/Medium/High)

## Behavior (modern)
- DataTable grouped by User (hide grouped column), same as legacy ExTable grouping.
- Per-column filters in table footer provide parity with legacy ExtJS GridFilters (Issue #1112).
- Reset Filters clears column filters, global search, restores default sort (User asc, Test Suite desc), resets group collapse state.

## Implementation notes
- HTML template: `gui/templates/results/tcCreatedPerUserOnTestProject.html`
- BFF: `api/results/index.php` (GET /meta, /report, /csv)
- i18n: `tcPerUser.*` keys (added `tcPerUser.filterCol`, `tcPerUser.filterAll`)
- Per-column filters implemented in footer (`<tfoot id="resTableFoot">`) with DataTables `column().search()`; Importance uses exact match for the rendered label.
