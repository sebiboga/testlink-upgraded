# Test Cases Created Per User (tcCreatedPerUser)

Refs #2000. This Dashio screen supersedes the earlier modern
`results/tcCreatedPerUserOnTestProject.html` page. It shows every test-case
version created by a selected user inside a date/time window, preserving the
legacy 1.9.20 grouped-grid behavior.

## Filters
- User: Any or a specific project user.
- From / To: date plus hour selects (`00`–`23`). The hour applies to the window
  boundaries, matching the legacy report.
- Search, Reset and Export CSV actions; Export is disabled for empty/no-right states.

## Results grid
- Rows are grouped by User with collapsible group headers and per-group counts.
- Expand/Collapse Groups toggles every group.
- Show all Columns toggles the hidden User column.
- Per-column footer filters: User, Test Suite, Test Case, Created and Modified
  use case-insensitive text; Importance uses an exact All/Low/Medium/High select
  on canonical values.
- Reset Filters clears footer/global filters, restores User asc / Test Suite desc
  and resets group collapse state.
- MultiSort shows the Shift+click hint; clicking another header keeps the User
  group column first.

## KPI strip and CSV
- KPIs: versions created, distinct test cases, authors, first/last creation and
  most active author.
- CSV uses `;` separators, BOM, formula guard and localized headers, and keeps
  the legacy Start time / End time columns in addition to the grid columns.

## Implementation notes
- HTML: `gui/templates/results/tcCreatedPerUser.html`
- BFF: `api/tccreatedperuser/index.php`
  (`GET|HEAD ?action=init`, `?action=report`; CSV is generated client-side
  from the report payload)
- i18n: `tcpcu.*` keys plus reused `tcPerUser.grid.*` / `tcPerUser.filter*` keys
  in all 10 bundles; coverage gate passes 9/9 compared bundles.
- Access: `testplan_metrics` on the addressed project; denials return a localized
  no-right card and an AUDIT event, never a data leak.

## Verification
Suite `Issue #2000` (`tmp/TLU_Test_Cases.md`) records 2000.1–2000.22, all PASS:
default/filtered/empty/no-right states, EN/RO, grouped-grid toolbar, footer
filters, sort pinning, CSV parity, invalid input/HTTP method/anonymous access,
ASIDE launcher, editor deep link, Close, console and Event Viewer checks. Bugs
#2001–#2004 were found and fixed during testing. Screenshots were captured in
`docs/screenshots/issue-2000-tccreatedperuser-*.png`.
