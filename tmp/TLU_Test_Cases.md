## Task — Issue #1089: Implement group-by-req-spec + ExtGrid toolbar in requirements/reqMonitorOverview.html (gap vs legacy)

**Precondition:** Test project with >=2 requirement specs each containing >=1 requirement; modern reqMonitorOverview screen accessible.

**Steps:**
1. Navigate to gui/templates/requirements/reqMonitorOverview.html?tproject_id=<id>
2. Verify requirements are grouped by Req. Spec with group headers showing "(N Item(s))"
3. Click group header to collapse/expand; verify rows toggle visibility
4. Use "Expand/Collapse Groups" to expand/collapse all groups
5. Toggle "Show all Columns" - verify Req. Spec column visibility changes
6. Test "Reset Filters" clears search box
7. Test "Reset to Default State" resets grouping/collapse state and column visibility
8. Test "Refresh" reloads data; monitor toggle updates row state

**Expected:** Grouping, toolbar actions, and monitor toggles work per legacy parity.
**Actual:** Implemented as specified; UI logic matches reqOverview.html patterns.

PASS
