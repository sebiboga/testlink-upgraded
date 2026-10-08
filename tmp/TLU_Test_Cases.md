
## Suite 1878: Modernize Admin top-menu User Management entry (usersView)

### Test Case 1878.1: Top-menu Admin lands on modern usersView.html
- Given logged in as admin (session has testproject 1/tplan 2)
- When clicking the Admin top-menu item (cfg/const.inc.php guiTopMenu[6]) — the URL should be gui/templates/usermanagement/usersView.html
- Then the modern User Management screen loads (title “User Management”, toolbar “+ Create User”, “Export”, tabs for User/Role/Assign)
- Pass/Fail: page header contains “User Management”; network shows no redirect loop; console clean (no ERROR/WARN attributable to navigation).

### Test Case 1878.2: Legacy lib/usermanagement/usersView.php → 302 to modern
- Given logged in (session)
- When GET http://localhost:8082/lib/usermanagement/usersView.php
- Then HTTP 302 (or 200 with JS top.location redirect? legacy uses testlinkInitPage; authenticated: we saw 302). Expect Location header to gui/templates/usermanagement/usersView.html with tproject_id/tplan_id from session; landing page is the modern screen.
- Pass/Fail: Location contains “usermanagement/usersView.html”.

### Test Case 1878.3: Anonymous legacy URL bounces to login
- Given not logged in
- When GET lib/usermanagement/usersView.php
- Then redirect to login.php?note=expired&destination=%2Flib%2Fusermanagement%2FusersView.php (same contract as eventviewer shim)
- Pass/Fail: login page shown; note=expired present.

### Test Case 1878.4: Forward legacy context (operation/user_id)
- Given logged in
- When GET lib/usermanagement/usersView.php?operation=show&user_id=1
- Then modern screen URL includes operation=show&user_id=1 (forwarded by shim)
- Pass/Fail: parameters preserved.

### Test Case 1878.5: Cross-check legacy templates not used
- Verify no active code references lib/usermanagement/usersView.php from modern screens (except allowed comments); cfg/const.inc.php points to .html.
- Pass/Fail: grep shows top-menu points to .html.

### Test Case 1878.6: Event Viewer clean after navigation
- After 1878.1–1878.4, check Event Viewer (events_mgt) for new ERROR/WARNING rows.
- Pass/Fail: 0 new ERROR/WARNING attributable to this change.

