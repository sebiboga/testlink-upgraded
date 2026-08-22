<?php
/**
 * Create all test cases for TLU project via XML-RPC API
 * Test Suites: Header(12), Dashboard(13), System(14) with sub-suites, API(15), Installation(16), Login(23)
 */

require_once '/home/sebi/testlink/third_party/xml-rpc/class-IXR.php';

$server_url = 'http://localhost:8082/lib/api/xmlrpc/v1/xmlrpc.php';
$devKey = 'tl_dev_key_2026';
$tproject_id = 11;

$testcases = [
    // ===== HEADER (12) =====
    ['suite_id' => 12, 'name' => 'Project Selector Displays All Accessible Projects',
     'summary' => '<p>Verify that the project selector dropdown in the header displays all test projects the logged-in user has access to.</p>',
     'preconditions' => '<p>User is logged in as admin with access to multiple projects</p>',
     'importance' => 2, 'steps' => [
         ['step_number' => 1, 'actions' => '<p>Observe the header and locate the "Test Project" dropdown</p>', 'expected_results' => '<p>Dropdown is visible with "Test Project" label</p>'],
         ['step_number' => 2, 'actions' => '<p>Click the project selector dropdown</p>', 'expected_results' => '<p>Dropdown opens showing: P2:Project Two, P3:Project Three, TLU:TLU: TestLink Upgraded 2.0.1, TP:Test Project</p>'],
         ['step_number' => 3, 'actions' => '<p>Verify option format is Prefix:Full Name</p>', 'expected_results' => '<p>All options follow naming convention and display correctly</p>']
     ]],

    ['suite_id' => 12, 'name' => 'Current Project Is Pre-selected in Header',
     'summary' => '<p>Verify the currently active project is pre-selected in the header dropdown.</p>',
     'preconditions' => '<p>User is logged in, active project is TLU</p>',
     'importance' => 2, 'steps' => [
         ['step_number' => 1, 'actions' => '<p>Observe the project selector dropdown</p>', 'expected_results' => '<p>TLU:TLU: TestLink Upgraded 2.0.1 is the selected value</p>'],
         ['step_number' => 2, 'actions' => '<p>Open dropdown, check which option has selected state</p>', 'expected_results' => '<p>TLU option has the selected attribute</p>']
     ]],

    ['suite_id' => 12, 'name' => 'Switch Test Project Changes Context',
     'summary' => '<p>Verify selecting a different project from the header dropdown reloads the application with the new project context.</p>',
     'preconditions' => '<p>User is logged in as admin in TLU project, has access to P2</p>',
     'importance' => 2, 'steps' => [
         ['step_number' => 1, 'actions' => '<p>Note the current project (TLU) in the dropdown</p>', 'expected_results' => '<p>TLU is displayed</p>'],
         ['step_number' => 2, 'actions' => '<p>Open dropdown and select P2:Project Two</p>', 'expected_results' => '<p>Application reloads with new context</p>'],
         ['step_number' => 3, 'actions' => '<p>After reload, check the project selector value</p>', 'expected_results' => '<p>P2:Project Two is now selected. Content shows P2 data.</p>']
     ]],

    ['suite_id' => 12, 'name' => 'Switch Project Preserves Feature Context',
     'summary' => '<p>Verify that when switching projects, the app returns to the same feature type in the new project.</p>',
     'preconditions' => '<p>User is on Test Specification in TLU, has access to TP</p>',
     'importance' => 1, 'steps' => [
         ['step_number' => 1, 'actions' => '<p>Navigate to Test Specification (editTc feature)</p>', 'expected_results' => '<p>Test Specification tree displayed</p>'],
         ['step_number' => 2, 'actions' => '<p>Switch to TP:Test Project via header dropdown</p>', 'expected_results' => '<p>Application reloads</p>'],
         ['step_number' => 3, 'actions' => '<p>Check if main content shows Test Specification for TP</p>', 'expected_results' => '<p>Page returns to Test Specification in new project context</p>']
     ]],

    ['suite_id' => 12, 'name' => 'Logo Click Reloads Main View',
     'summary' => '<p>Verify clicking the TestLink logo navigates back to the main dashboard for the current project.</p>',
     'preconditions' => '<p>User is on any sub-page (e.g., Test Specification)</p>',
     'importance' => 1, 'steps' => [
         ['step_number' => 1, 'actions' => '<p>Navigate to Test Specification</p>', 'expected_results' => '<p>Sub-page content is displayed</p>'],
         ['step_number' => 2, 'actions' => '<p>Click the TestLink logo in the header</p>', 'expected_results' => '<p>Navigates to index.php showing the main dashboard</p>'],
         ['step_number' => 3, 'actions' => '<p>Verify the URL contains correct tproject_id</p>', 'expected_results' => '<p>URL is index.php?tproject_id=X matching current project</p>']
     ]],

    ['suite_id' => 12, 'name' => 'Logo Image Loads Without Error',
     'summary' => '<p>Verify the TestLink logo image loads correctly without 404 or broken image.</p>',
     'preconditions' => '<p>User is logged in</p>',
     'importance' => 0, 'steps' => [
         ['step_number' => 1, 'actions' => '<p>Inspect the header visually and check network tab for logo request</p>', 'expected_results' => '<p>Logo (tl-logo-transparent-25.png) loads with HTTP 200, fully visible</p>']
     ]],

    ['suite_id' => 12, 'name' => 'Header Displays Logged-In User Info',
     'summary' => '<p>Verify the header displays the logged-in user name in format "username [role]".</p>',
     'preconditions' => '<p>User admin with role admin is logged in</p>',
     'importance' => 2, 'steps' => [
         ['step_number' => 1, 'actions' => '<p>Observe the top-right area of the header</p>', 'expected_results' => '<p>Text "admin [admin]" is displayed as a clickable link</p>'],
         ['step_number' => 2, 'actions' => '<p>Inspect the element title attribute</p>', 'expected_results' => '<p>Title attribute shows "admin [admin]"</p>']
     ]],

    ['suite_id' => 12, 'name' => 'User Info Link Opens Profile in Mainframe',
     'summary' => '<p>Verify clicking user info link opens the user profile page in the main content frame.</p>',
     'preconditions' => '<p>User is logged in as admin</p>',
     'importance' => 1, 'steps' => [
         ['step_number' => 1, 'actions' => '<p>Click on "admin [admin]" link in header</p>', 'expected_results' => '<p>Main frame loads userInfo.php showing user profile</p>'],
         ['step_number' => 2, 'actions' => '<p>Verify the link has target="mainframe"</p>', 'expected_results' => '<p>Link opens inside main content area, not new tab</p>']
     ]],

    ['suite_id' => 12, 'name' => 'Logout Terminates Session and Redirects to Login',
     'summary' => '<p>Verify clicking Logout ends the session and shows the login page.</p>',
     'preconditions' => '<p>User is logged in as admin</p>',
     'importance' => 2, 'steps' => [
         ['step_number' => 1, 'actions' => '<p>Click the "Logout" link in the header</p>', 'expected_results' => '<p>Session is terminated, login page is displayed</p>'],
         ['step_number' => 2, 'actions' => '<p>Try browser back button</p>', 'expected_results' => '<p>Cannot access protected content, login page shown</p>']
     ]],

    ['suite_id' => 12, 'name' => 'Sidebar Toggle Collapses and Expands Navigation',
     'summary' => '<p>Verify the hamburger icon toggles the left sidebar between collapsed and expanded states.</p>',
     'preconditions' => '<p>User is logged in, sidebar is initially visible</p>',
     'importance' => 1, 'steps' => [
         ['step_number' => 1, 'actions' => '<p>Observe sidebar is visible with menu items</p>', 'expected_results' => '<p>Sidebar expanded: System, Projects, Requirements, Test Case Design, Test Plan, Plugins</p>'],
         ['step_number' => 2, 'actions' => '<p>Click hamburger icon (fa-bars) in header</p>', 'expected_results' => '<p>Sidebar collapses, main content gets more space</p>'],
         ['step_number' => 3, 'actions' => '<p>Click hamburger icon again</p>', 'expected_results' => '<p>Sidebar expands back with all menu items visible</p>']
     ]],

    ['suite_id' => 12, 'name' => 'All Header Elements Present After Fresh Login',
     'summary' => '<p>Smoke test: verify all header elements are present immediately after login.</p>',
     'preconditions' => '<p>User is on login page with valid credentials</p>',
     'importance' => 2, 'steps' => [
         ['step_number' => 1, 'actions' => '<p>Login with admin/admin</p>', 'expected_results' => '<p>Login successful, main app loads</p>'],
         ['step_number' => 2, 'actions' => '<p>Verify: hamburger icon, logo, "Test Project" label, project dropdown, user info link, logout link</p>', 'expected_results' => '<p>All 6 header elements visible and functional</p>']
     ]],

    ['suite_id' => 12, 'name' => 'CSRF Token Present in Project Switch Form',
     'summary' => '<p>Verify the project switch form includes CSRF protection tokens.</p>',
     'preconditions' => '<p>User is logged in</p>',
     'importance' => 1, 'steps' => [
         ['step_number' => 1, 'actions' => '<p>Inspect the header HTML source for the project switch form</p>', 'expected_results' => '<p>Form contains hidden fields CSRFName and CSRFToken with valid values</p>']
     ]],

    // ===== DASHBOARD (13) =====
    ['suite_id' => 13, 'name' => 'Dashboard Pie Chart Renders on Load',
     'summary' => '<p>Verify the pie chart on the dashboard renders correctly showing test execution statistics.</p>',
     'preconditions' => '<p>User is logged in, dashboard page loaded</p>',
     'importance' => 2, 'steps' => [
         ['step_number' => 1, 'actions' => '<p>Navigate to the main dashboard (index.php)</p>', 'expected_results' => '<p>Dashboard loads with pie chart visible</p>'],
         ['step_number' => 2, 'actions' => '<p>Check the pie chart has segments with different colors</p>', 'expected_results' => '<p>Pie chart renders with data segments (pass/fail/not run/blocked)</p>']
     ]],

    ['suite_id' => 13, 'name' => 'Dashboard Bugs Table Loads with Data',
     'summary' => '<p>Verify the bugs table on the dashboard loads and displays data correctly with DataTables.</p>',
     'preconditions' => '<p>User is logged in, dashboard loaded</p>',
     'importance' => 1, 'steps' => [
         ['step_number' => 1, 'actions' => '<p>Scroll to the bugs table section on the dashboard</p>', 'expected_results' => '<p>Bugs table is visible with column headers</p>'],
         ['step_number' => 2, 'actions' => '<p>Verify DataTables pagination and sorting controls</p>', 'expected_results' => '<p>Table has pagination, search, and sort controls functional</p>']
     ]],

    ['suite_id' => 13, 'name' => 'Dashboard Monthly TC Growth Chart Renders',
     'summary' => '<p>Verify the monthly test case growth line chart renders correctly.</p>',
     'preconditions' => '<p>User is logged in, dashboard loaded</p>',
     'importance' => 1, 'steps' => [
         ['step_number' => 1, 'actions' => '<p>Locate the monthly TC growth chart on the dashboard</p>', 'expected_results' => '<p>Line chart is visible with axes and data points</p>'],
         ['step_number' => 2, 'actions' => '<p>Hover over data points</p>', 'expected_results' => '<p>Tooltips show correct month and count values</p>']
     ]],

    ['suite_id' => 13, 'name' => 'Dashboard Loads Without PHP Warnings',
     'summary' => '<p>Verify the dashboard page loads without any PHP warnings, notices or errors in the page output.</p>',
     'preconditions' => '<p>User is logged in</p>',
     'importance' => 2, 'steps' => [
         ['step_number' => 1, 'actions' => '<p>Navigate to index.php dashboard</p>', 'expected_results' => '<p>Page loads cleanly</p>'],
         ['step_number' => 2, 'actions' => '<p>Check page source for PHP warning/error strings</p>', 'expected_results' => '<p>No "Warning:", "Notice:", "Fatal error:", "Deprecated:" in page output</p>']
     ]],

    // ===== EVENT VIEWER (17) =====
    ['suite_id' => 17, 'name' => 'Event Viewer Page Loads with Events Table',
     'summary' => '<p>Verify the Event Viewer page loads and displays events in a DataTables grid.</p>',
     'preconditions' => '<p>User is logged in as admin</p>',
     'importance' => 2, 'steps' => [
         ['step_number' => 1, 'actions' => '<p>Navigate to Event Viewer via aside menu</p>', 'expected_results' => '<p>Event Viewer page loads with events table visible</p>'],
         ['step_number' => 2, 'actions' => '<p>Check table has columns: ID, Timestamp, Message, User, Type</p>', 'expected_results' => '<p>All expected columns are present</p>']
     ]],

    ['suite_id' => 17, 'name' => 'Event Viewer Pie Chart Shows Distribution',
     'summary' => '<p>Verify the pie chart on Event Viewer renders showing event type distribution.</p>',
     'preconditions' => '<p>User is on Event Viewer page</p>',
     'importance' => 1, 'steps' => [
         ['step_number' => 1, 'actions' => '<p>Locate the pie chart on Event Viewer</p>', 'expected_results' => '<p>Pie chart renders with colored segments</p>'],
         ['step_number' => 2, 'actions' => '<p>Verify chart legend matches event types in the table</p>', 'expected_results' => '<p>Legend categories correspond to event types present in data</p>']
     ]],

    ['suite_id' => 17, 'name' => 'Event Viewer Row Expand Shows Details',
     'summary' => '<p>Verify clicking a row in the events table expands to show full event details.</p>',
     'preconditions' => '<p>User is on Event Viewer, table has data</p>',
     'importance' => 1, 'steps' => [
         ['step_number' => 1, 'actions' => '<p>Click on a row in the events table</p>', 'expected_results' => '<p>Row expands to show additional event details</p>'],
         ['step_number' => 2, 'actions' => '<p>Click the row again</p>', 'expected_results' => '<p>Row collapses back to summary view</p>']
     ]],

    ['suite_id' => 17, 'name' => 'Event Viewer Loads Without PHP Warnings',
     'summary' => '<p>Verify the Event Viewer page loads without any PHP warnings or errors.</p>',
     'preconditions' => '<p>User is logged in as admin</p>',
     'importance' => 2, 'steps' => [
         ['step_number' => 1, 'actions' => '<p>Navigate to Event Viewer</p>', 'expected_results' => '<p>Page loads cleanly without PHP warnings</p>'],
         ['step_number' => 2, 'actions' => '<p>Check page source and browser console for errors</p>', 'expected_results' => '<p>No PHP warnings, no JavaScript errors</p>']
     ]],

    ['suite_id' => 17, 'name' => 'Event Viewer Date Filter Works',
     'summary' => '<p>Verify that date filtering on Event Viewer correctly filters displayed events.</p>',
     'preconditions' => '<p>User is on Event Viewer with data from multiple dates</p>',
     'importance' => 1, 'steps' => [
         ['step_number' => 1, 'actions' => '<p>Set a date range filter</p>', 'expected_results' => '<p>Table updates to show only events within selected date range</p>'],
         ['step_number' => 2, 'actions' => '<p>Clear the filter</p>', 'expected_results' => '<p>All events are displayed again</p>']
     ]],

    // ===== USER MANAGEMENT (18) =====
    ['suite_id' => 18, 'name' => 'Users List Loads with DataTables',
     'summary' => '<p>Verify the Users Management page loads and displays users in a DataTables grid.</p>',
     'preconditions' => '<p>User is logged in as admin</p>',
     'importance' => 2, 'steps' => [
         ['step_number' => 1, 'actions' => '<p>Navigate to User Management via aside menu</p>', 'expected_results' => '<p>Page loads with users table showing columns: Login, First Name, Last Name, Email, Role, Status</p>'],
         ['step_number' => 2, 'actions' => '<p>Verify search, pagination, and sorting work</p>', 'expected_results' => '<p>DataTables controls are functional</p>']
     ]],

    ['suite_id' => 18, 'name' => 'Create New User via BFF API',
     'summary' => '<p>Verify creating a new user through the User Management interface.</p>',
     'preconditions' => '<p>Admin is logged in</p>',
     'importance' => 2, 'steps' => [
         ['step_number' => 1, 'actions' => '<p>Click Create button on User Management page</p>', 'expected_results' => '<p>Create user form/modal appears</p>'],
         ['step_number' => 2, 'actions' => '<p>Fill in: login, password, first name, last name, email, assign role</p>', 'expected_results' => '<p>All fields accept input</p>'],
         ['step_number' => 3, 'actions' => '<p>Submit the form</p>', 'expected_results' => '<p>User is created successfully, appears in the users list</p>']
     ]],

    ['suite_id' => 18, 'name' => 'Edit User Details',
     'summary' => '<p>Verify editing an existing user updates their information correctly.</p>',
     'preconditions' => '<p>Admin is logged in, at least one user exists</p>',
     'importance' => 1, 'steps' => [
         ['step_number' => 1, 'actions' => '<p>Click Edit on an existing user row</p>', 'expected_results' => '<p>Edit form loads with current user data pre-filled</p>'],
         ['step_number' => 2, 'actions' => '<p>Modify first name and save</p>', 'expected_results' => '<p>User data is updated, new name shown in list</p>']
     ]],

    ['suite_id' => 18, 'name' => 'Enable Disable User Toggle',
     'summary' => '<p>Verify the enable/disable toggle correctly changes user active status.</p>',
     'preconditions' => '<p>Admin is logged in, user "tester1" exists and is active</p>',
     'importance' => 2, 'steps' => [
         ['step_number' => 1, 'actions' => '<p>Click the status toggle for an active user</p>', 'expected_results' => '<p>User status changes to inactive/disabled</p>'],
         ['step_number' => 2, 'actions' => '<p>Try logging in with the disabled user credentials</p>', 'expected_results' => '<p>Login fails for disabled user</p>'],
         ['step_number' => 3, 'actions' => '<p>Re-enable the user</p>', 'expected_results' => '<p>User status changes back to active</p>']
     ]],

    ['suite_id' => 18, 'name' => 'Delete User Soft Deletes',
     'summary' => '<p>Verify deleting a user performs a soft delete (active=2) and user is hidden from the list.</p>',
     'preconditions' => '<p>Admin is logged in, a deletable user exists</p>',
     'importance' => 2, 'steps' => [
         ['step_number' => 1, 'actions' => '<p>Click Delete on a user</p>', 'expected_results' => '<p>Confirmation dialog appears</p>'],
         ['step_number' => 2, 'actions' => '<p>Confirm deletion</p>', 'expected_results' => '<p>User disappears from the list</p>'],
         ['step_number' => 3, 'actions' => '<p>Verify in DB that user active=2 (soft deleted, not hard deleted)</p>', 'expected_results' => '<p>User record still exists in DB with active=2</p>']
     ]],

    ['suite_id' => 18, 'name' => 'User Management Loads Without PHP Warnings',
     'summary' => '<p>Verify the User Management page loads without PHP warnings or errors.</p>',
     'preconditions' => '<p>Admin is logged in</p>',
     'importance' => 2, 'steps' => [
         ['step_number' => 1, 'actions' => '<p>Navigate to User Management</p>', 'expected_results' => '<p>Page loads cleanly</p>'],
         ['step_number' => 2, 'actions' => '<p>Check browser console and page source</p>', 'expected_results' => '<p>No PHP warnings, no JS errors</p>']
     ]],

    // ===== ROLE MANAGEMENT (19) =====
    ['suite_id' => 19, 'name' => 'Roles List Loads with All Roles',
     'summary' => '<p>Verify the Role Management page displays all roles including system roles.</p>',
     'preconditions' => '<p>Admin is logged in</p>',
     'importance' => 2, 'steps' => [
         ['step_number' => 1, 'actions' => '<p>Navigate to Role Management</p>', 'expected_results' => '<p>Roles table displays all roles: reserved1, reserved2, reserved3, test designer, guest, senior tester, tester, admin, leader</p>'],
         ['step_number' => 2, 'actions' => '<p>Verify system roles are marked differently (non-editable)</p>', 'expected_results' => '<p>Roles 1-3 cannot be edited or deleted</p>']
     ]],

    ['suite_id' => 19, 'name' => 'Create New Role',
     'summary' => '<p>Verify creating a new custom role through the Role Management interface.</p>',
     'preconditions' => '<p>Admin is logged in</p>',
     'importance' => 2, 'steps' => [
         ['step_number' => 1, 'actions' => '<p>Click Create on Role Management page</p>', 'expected_results' => '<p>Create role form appears</p>'],
         ['step_number' => 2, 'actions' => '<p>Enter role name "TC:Test Creator" and assign some rights</p>', 'expected_results' => '<p>Fields accept input, rights checkboxes work</p>'],
         ['step_number' => 3, 'actions' => '<p>Save the role</p>', 'expected_results' => '<p>Role is created and appears in the list</p>']
     ]],

    ['suite_id' => 19, 'name' => 'Edit Role Rights',
     'summary' => '<p>Verify editing a role and changing its rights updates correctly.</p>',
     'preconditions' => '<p>Custom role "TC:Test Creator" exists</p>',
     'importance' => 1, 'steps' => [
         ['step_number' => 1, 'actions' => '<p>Click Edit on custom role</p>', 'expected_results' => '<p>Edit form loads with current rights checked</p>'],
         ['step_number' => 2, 'actions' => '<p>Toggle some rights and save</p>', 'expected_results' => '<p>Role rights are updated</p>']
     ]],

    ['suite_id' => 19, 'name' => 'Delete Custom Role',
     'summary' => '<p>Verify deleting a custom role that has no users assigned.</p>',
     'preconditions' => '<p>Custom role exists with no user assignments</p>',
     'importance' => 1, 'steps' => [
         ['step_number' => 1, 'actions' => '<p>Click Delete on a custom role</p>', 'expected_results' => '<p>Confirmation appears</p>'],
         ['step_number' => 2, 'actions' => '<p>Confirm deletion</p>', 'expected_results' => '<p>Role is removed from the list</p>']
     ]],

    ['suite_id' => 19, 'name' => 'System Roles Cannot Be Edited or Deleted',
     'summary' => '<p>Verify system roles (reserved1-3) cannot be modified or deleted through the UI.</p>',
     'preconditions' => '<p>Admin is logged in</p>',
     'importance' => 2, 'steps' => [
         ['step_number' => 1, 'actions' => '<p>Check if system roles (reserved1, reserved2, reserved3) have edit/delete buttons</p>', 'expected_results' => '<p>Edit and Delete buttons are disabled or hidden for system roles</p>'],
         ['step_number' => 2, 'actions' => '<p>Try to access edit URL directly for system role ID</p>', 'expected_results' => '<p>Action is rejected or shows error</p>']
     ]],

    ['suite_id' => 19, 'name' => 'Role Management Loads Without PHP Warnings',
     'summary' => '<p>Verify the Role Management page loads without PHP warnings or errors.</p>',
     'preconditions' => '<p>Admin is logged in</p>',
     'importance' => 2, 'steps' => [
         ['step_number' => 1, 'actions' => '<p>Navigate to Role Management</p>', 'expected_results' => '<p>Page loads cleanly</p>'],
         ['step_number' => 2, 'actions' => '<p>Check console and page source</p>', 'expected_results' => '<p>No PHP warnings or JS errors</p>']
     ]],

    // ===== ASSIGN PROJECT ROLES (20) =====
    ['suite_id' => 20, 'name' => 'Assign User to Test Project Role',
     'summary' => '<p>Verify assigning a user a role within a specific test project.</p>',
     'preconditions' => '<p>Admin is logged in, user tester1 exists, TLU project exists</p>',
     'importance' => 2, 'steps' => [
         ['step_number' => 1, 'actions' => '<p>Navigate to Assign Project Roles</p>', 'expected_results' => '<p>Page loads with user/project/role selectors</p>'],
         ['step_number' => 2, 'actions' => '<p>Select user tester1, project TLU, role tester</p>', 'expected_results' => '<p>Selections are made</p>'],
         ['step_number' => 3, 'actions' => '<p>Save the assignment</p>', 'expected_results' => '<p>Assignment is saved, user has tester role in TLU project</p>']
     ]],

    ['suite_id' => 20, 'name' => 'Remove User from Test Project Role',
     'summary' => '<p>Verify removing a user role assignment from a test project.</p>',
     'preconditions' => '<p>User tester1 has role in TLU project</p>',
     'importance' => 1, 'steps' => [
         ['step_number' => 1, 'actions' => '<p>Select the existing assignment</p>', 'expected_results' => '<p>Assignment is highlighted/selected</p>'],
         ['step_number' => 2, 'actions' => '<p>Click Remove/Unassign</p>', 'expected_results' => '<p>User no longer has the role in the project</p>']
     ]],

    ['suite_id' => 20, 'name' => 'Project Roles Page Loads Without PHP Warnings',
     'summary' => '<p>Verify Assign Project Roles page loads without PHP warnings or errors.</p>',
     'preconditions' => '<p>Admin is logged in</p>',
     'importance' => 2, 'steps' => [
         ['step_number' => 1, 'actions' => '<p>Navigate to Assign Project Roles</p>', 'expected_results' => '<p>Page loads cleanly, no PHP warnings</p>']
     ]],

    // ===== ASSIGN PLAN ROLES (21) =====
    ['suite_id' => 21, 'name' => 'Assign User to Test Plan Role',
     'summary' => '<p>Verify assigning a user a role within a specific test plan.</p>',
     'preconditions' => '<p>Admin is logged in, user tester1 exists, test plan exists</p>',
     'importance' => 2, 'steps' => [
         ['step_number' => 1, 'actions' => '<p>Navigate to Assign Plan Roles</p>', 'expected_results' => '<p>Page loads with user/plan/role selectors</p>'],
         ['step_number' => 2, 'actions' => '<p>Select user, test plan, and role</p>', 'expected_results' => '<p>Selections are made</p>'],
         ['step_number' => 3, 'actions' => '<p>Save the assignment</p>', 'expected_results' => '<p>Assignment saved successfully</p>']
     ]],

    ['suite_id' => 21, 'name' => 'Remove User from Test Plan Role',
     'summary' => '<p>Verify removing a user role assignment from a test plan.</p>',
     'preconditions' => '<p>User has a role in a test plan</p>',
     'importance' => 1, 'steps' => [
         ['step_number' => 1, 'actions' => '<p>Select existing plan role assignment</p>', 'expected_results' => '<p>Assignment is selected</p>'],
         ['step_number' => 2, 'actions' => '<p>Click Remove</p>', 'expected_results' => '<p>Assignment is removed</p>']
     ]],

    ['suite_id' => 21, 'name' => 'Plan Roles Page Loads Without PHP Warnings',
     'summary' => '<p>Verify Assign Plan Roles page loads without PHP warnings or errors.</p>',
     'preconditions' => '<p>Admin is logged in</p>',
     'importance' => 2, 'steps' => [
         ['step_number' => 1, 'actions' => '<p>Navigate to Assign Plan Roles</p>', 'expected_results' => '<p>Page loads cleanly, no PHP warnings</p>']
     ]],

    // ===== CUSTOM FIELDS (22) =====
    ['suite_id' => 22, 'name' => 'Create Custom Field String Type',
     'summary' => '<p>Verify creating a custom field of type String through the Custom Fields management.</p>',
     'preconditions' => '<p>Admin is logged in</p>',
     'importance' => 2, 'steps' => [
         ['step_number' => 1, 'actions' => '<p>Navigate to Custom Fields</p>', 'expected_results' => '<p>CF list page loads</p>'],
         ['step_number' => 2, 'actions' => '<p>Click Create, enter name "CF_TestString", type String</p>', 'expected_results' => '<p>Form accepts input</p>'],
         ['step_number' => 3, 'actions' => '<p>Save</p>', 'expected_results' => '<p>Custom field created and visible in list</p>']
     ]],

    ['suite_id' => 22, 'name' => 'Edit Custom Field Properties',
     'summary' => '<p>Verify editing an existing custom field updates its properties.</p>',
     'preconditions' => '<p>Custom field CF_TestString exists</p>',
     'importance' => 1, 'steps' => [
         ['step_number' => 1, 'actions' => '<p>Click Edit on CF_TestString</p>', 'expected_results' => '<p>Edit form loads with current values</p>'],
         ['step_number' => 2, 'actions' => '<p>Change the name to CF_TestString_v2 and save</p>', 'expected_results' => '<p>Name updated in list</p>']
     ]],

    ['suite_id' => 22, 'name' => 'Assign Custom Field to Test Project',
     'summary' => '<p>Verify assigning a custom field to the TLU test project enables it for that project.</p>',
     'preconditions' => '<p>Custom field exists, TLU project exists</p>',
     'importance' => 2, 'steps' => [
         ['step_number' => 1, 'actions' => '<p>Navigate to CF assignment for TLU project</p>', 'expected_results' => '<p>Assignment page loads</p>'],
         ['step_number' => 2, 'actions' => '<p>Check the custom field to enable it for TLU</p>', 'expected_results' => '<p>Checkbox is selected</p>'],
         ['step_number' => 3, 'actions' => '<p>Save assignment</p>', 'expected_results' => '<p>CF is now available for test cases in TLU</p>']
     ]],

    ['suite_id' => 22, 'name' => 'Enable CF for Test Cases Entity Type',
     'summary' => '<p>Verify custom field can be enabled for Test Cases entity type and shows up during TC creation.</p>',
     'preconditions' => '<p>CF assigned to TLU project, enabled for test cases</p>',
     'importance' => 1, 'steps' => [
         ['step_number' => 1, 'actions' => '<p>Create a new test case in TLU project</p>', 'expected_results' => '<p>TC edit form loads</p>'],
         ['step_number' => 2, 'actions' => '<p>Check if custom field appears in the form</p>', 'expected_results' => '<p>Custom field input is visible in the TC form</p>']
     ]],

    ['suite_id' => 22, 'name' => 'Export Custom Fields to XML',
     'summary' => '<p>Verify exporting custom fields produces a valid XML file.</p>',
     'preconditions' => '<p>At least one custom field exists</p>',
     'importance' => 1, 'steps' => [
         ['step_number' => 1, 'actions' => '<p>Click Export on Custom Fields page</p>', 'expected_results' => '<p>XML file is downloaded</p>'],
         ['step_number' => 2, 'actions' => '<p>Open the XML file and verify structure</p>', 'expected_results' => '<p>Valid XML with field definitions (name, type, default value, etc.)</p>']
     ]],

    ['suite_id' => 22, 'name' => 'Import Custom Fields from XML',
     'summary' => '<p>Verify importing custom fields from XML file works correctly.</p>',
     'preconditions' => '<p>Valid CF XML export file exists</p>',
     'importance' => 1, 'steps' => [
         ['step_number' => 1, 'actions' => '<p>Click Import on Custom Fields page</p>', 'expected_results' => '<p>File upload dialog appears</p>'],
         ['step_number' => 2, 'actions' => '<p>Select the XML file and import</p>', 'expected_results' => '<p>Custom fields are imported and appear in the list</p>']
     ]],

    ['suite_id' => 22, 'name' => 'Custom Fields Page Loads Without PHP Warnings',
     'summary' => '<p>Verify the Custom Fields page loads without PHP warnings.</p>',
     'preconditions' => '<p>Admin is logged in</p>',
     'importance' => 2, 'steps' => [
         ['step_number' => 1, 'actions' => '<p>Navigate to Custom Fields</p>', 'expected_results' => '<p>Page loads cleanly, no PHP warnings</p>']
     ]],

    // ===== API (15) =====
    ['suite_id' => 15, 'name' => 'Event Viewer API Returns Valid JSON',
     'summary' => '<p>Verify the /api/eventviewer/ endpoint returns valid JSON with events data.</p>',
     'preconditions' => '<p>User is logged in with valid session</p>',
     'importance' => 2, 'steps' => [
         ['step_number' => 1, 'actions' => '<p>Make GET request to /api/eventviewer/index.php</p>', 'expected_results' => '<p>Response HTTP 200 with Content-Type: application/json</p>'],
         ['step_number' => 2, 'actions' => '<p>Parse the JSON response</p>', 'expected_results' => '<p>Valid JSON with "data" array containing event objects with id, timestamp, message, user fields</p>']
     ]],

    ['suite_id' => 15, 'name' => 'Users API CRUD Operations',
     'summary' => '<p>Verify the /api/users/ endpoint supports Create, Read, Update, Delete operations.</p>',
     'preconditions' => '<p>User is logged in as admin with valid session</p>',
     'importance' => 2, 'steps' => [
         ['step_number' => 1, 'actions' => '<p>GET /api/users/index.php - list all users</p>', 'expected_results' => '<p>Returns array of users with id, login, first, last, email, role, active</p>'],
         ['step_number' => 2, 'actions' => '<p>POST to create a new user</p>', 'expected_results' => '<p>User created, returns success response</p>'],
         ['step_number' => 3, 'actions' => '<p>PUT to update the user</p>', 'expected_results' => '<p>User updated, returns success</p>'],
         ['step_number' => 4, 'actions' => '<p>DELETE to soft-delete the user</p>', 'expected_results' => '<p>User soft-deleted (active=2), removed from list</p>']
     ]],

    ['suite_id' => 15, 'name' => 'Roles API CRUD Operations',
     'summary' => '<p>Verify the /api/roles/ endpoint supports full CRUD operations.</p>',
     'preconditions' => '<p>User is logged in as admin</p>',
     'importance' => 2, 'steps' => [
         ['step_number' => 1, 'actions' => '<p>GET /api/roles/index.php - list all roles</p>', 'expected_results' => '<p>Returns roles with id, name, description, rights</p>'],
         ['step_number' => 2, 'actions' => '<p>POST to create a new role</p>', 'expected_results' => '<p>Role created successfully</p>'],
         ['step_number' => 3, 'actions' => '<p>PUT to update role rights</p>', 'expected_results' => '<p>Role updated</p>'],
         ['step_number' => 4, 'actions' => '<p>DELETE to remove custom role</p>', 'expected_results' => '<p>Role deleted (if not system role)</p>']
     ]],

    ['suite_id' => 15, 'name' => 'API Returns 401 Without Session',
     'summary' => '<p>Verify BFF API endpoints reject requests without valid session.</p>',
     'preconditions' => '<p>No active session (logged out or fresh browser)</p>',
     'importance' => 2, 'steps' => [
         ['step_number' => 1, 'actions' => '<p>Make request to /api/users/index.php without session cookie</p>', 'expected_results' => '<p>Response returns 401 or redirect to login</p>'],
         ['step_number' => 2, 'actions' => '<p>Make request to /api/roles/index.php without session</p>', 'expected_results' => '<p>Response returns 401 or redirect to login</p>']
     ]],

    // ===== INSTALLATION (16) =====
    ['suite_id' => 16, 'name' => 'No PHP Warnings on Modernized Pages',
     'summary' => '<p>Verify all modernized pages load without PHP 8.x warnings, notices, or deprecation messages.</p>',
     'preconditions' => '<p>PHP 8.x running, user logged in as admin</p>',
     'importance' => 2, 'steps' => [
         ['step_number' => 1, 'actions' => '<p>Visit each modernized page: Event Viewer, User Management, Role Management, Assign Project Roles, Assign Plan Roles, Custom Fields</p>', 'expected_results' => '<p>All pages load without PHP warnings in output</p>'],
         ['step_number' => 2, 'actions' => '<p>Check error_log for PHP warnings during page loads</p>', 'expected_results' => '<p>No new PHP warnings in error_log</p>']
     ]],

    ['suite_id' => 16, 'name' => 'Session Handling Works Correctly',
     'summary' => '<p>Verify session-based authentication works across all pages and API endpoints.</p>',
     'preconditions' => '<p>User is logged in</p>',
     'importance' => 2, 'steps' => [
         ['step_number' => 1, 'actions' => '<p>Navigate through all main pages sequentially</p>', 'expected_results' => '<p>All pages load without session errors</p>'],
         ['step_number' => 2, 'actions' => '<p>Open multiple browser tabs on different pages</p>', 'expected_results' => '<p>Sessions are shared across tabs</p>'],
         ['step_number' => 3, 'actions' => '<p>Logout from one tab, check other tabs</p>', 'expected_results' => '<p>Other tabs show session expired on next action</p>']
     ]],

    ['suite_id' => 16, 'name' => 'PHP Version Compatibility Check',
     'summary' => '<p>Verify TestLink runs on PHP 8.x without compatibility issues.</p>',
     'preconditions' => '<p>PHP 8.x installed</p>',
     'importance' => 1, 'steps' => [
         ['step_number' => 1, 'actions' => '<p>Check phpinfo() for PHP version</p>', 'expected_results' => '<p>PHP 8.x is running</p>'],
         ['step_number' => 2, 'actions' => '<p>Navigate through core TestLink pages</p>', 'expected_results' => '<p>No Fatal errors related to PHP 8.x type changes, named arguments, etc.</p>']
     ]],

    // ===== LOGIN (23) =====
    ['suite_id' => 23, 'name' => 'Valid Login with Correct Credentials',
     'summary' => '<p>Verify successful login with valid username and password.</p>',
     'preconditions' => '<p>Admin user exists with credentials admin/admin</p>',
     'importance' => 2, 'steps' => [
         ['step_number' => 1, 'actions' => '<p>Navigate to the login page</p>', 'expected_results' => '<p>Login form is displayed with username and password fields</p>'],
         ['step_number' => 2, 'actions' => '<p>Enter username "admin" and password "admin"</p>', 'expected_results' => '<p>Fields accept input</p>'],
         ['step_number' => 3, 'actions' => '<p>Click Login / Submit</p>', 'expected_results' => '<p>Login successful, redirected to main dashboard with project selector</p>']
     ]],

    ['suite_id' => 23, 'name' => 'Invalid Login with Wrong Password',
     'summary' => '<p>Verify login fails with correct username but wrong password.</p>',
     'preconditions' => '<p>Login page is displayed</p>',
     'importance' => 2, 'steps' => [
         ['step_number' => 1, 'actions' => '<p>Enter username "admin" and password "wrongpassword"</p>', 'expected_results' => '<p>Fields accept input</p>'],
         ['step_number' => 2, 'actions' => '<p>Click Login</p>', 'expected_results' => '<p>Login fails, error message displayed, user stays on login page</p>']
     ]],

    ['suite_id' => 23, 'name' => 'Invalid Login with Non-existent User',
     'summary' => '<p>Verify login fails with a username that does not exist in the system.</p>',
     'preconditions' => '<p>Login page is displayed</p>',
     'importance' => 1, 'steps' => [
         ['step_number' => 1, 'actions' => '<p>Enter username "nonexistent" and password "anything"</p>', 'expected_results' => '<p>Fields accept input</p>'],
         ['step_number' => 2, 'actions' => '<p>Click Login</p>', 'expected_results' => '<p>Login fails with generic error (should not reveal whether user exists)</p>']
     ]],

    ['suite_id' => 23, 'name' => 'Login with Empty Fields',
     'summary' => '<p>Verify login form validates required fields and does not submit with empty values.</p>',
     'preconditions' => '<p>Login page is displayed</p>',
     'importance' => 1, 'steps' => [
         ['step_number' => 1, 'actions' => '<p>Leave both username and password empty, click Login</p>', 'expected_results' => '<p>Form validation prevents submission, error or browser validation message shown</p>'],
         ['step_number' => 2, 'actions' => '<p>Enter only username, leave password empty, click Login</p>', 'expected_results' => '<p>Form validation prevents submission</p>']
     ]],

    ['suite_id' => 23, 'name' => 'Login with Disabled User Account',
     'summary' => '<p>Verify login fails for a user account that has been disabled (active=0).</p>',
     'preconditions' => '<p>User "inactive1" exists with active=0</p>',
     'importance' => 2, 'steps' => [
         ['step_number' => 1, 'actions' => '<p>Enter username "inactive1" and valid password</p>', 'expected_results' => '<p>Fields accept input</p>'],
         ['step_number' => 2, 'actions' => '<p>Click Login</p>', 'expected_results' => '<p>Login fails with "account disabled" message</p>']
     ]],

    ['suite_id' => 23, 'name' => 'Login Page Loads Without PHP Warnings',
     'summary' => '<p>Verify the login page loads without any PHP warnings or errors.</p>',
     'preconditions' => '<p>PHP 8.x running</p>',
     'importance' => 2, 'steps' => [
         ['step_number' => 1, 'actions' => '<p>Navigate to login page</p>', 'expected_results' => '<p>Page loads cleanly</p>'],
         ['step_number' => 2, 'actions' => '<p>Check page source and browser console</p>', 'expected_results' => '<p>No PHP warnings, notices, or JS errors</p>']
     ]],

    ['suite_id' => 23, 'name' => 'Password Field Masks Input',
     'summary' => '<p>Verify the password field on the login page masks the input characters.</p>',
     'preconditions' => '<p>Login page is displayed</p>',
     'importance' => 0, 'steps' => [
         ['step_number' => 1, 'actions' => '<p>Type in the password field</p>', 'expected_results' => '<p>Characters are masked (shown as dots or asterisks)</p>']
     ]],
];

// ---- Execute API calls ----
$client = new IXR_Client($server_url);
$client->debug = false;
$created = 0;
$failed = 0;
$errors = [];

foreach ($testcases as $idx => $tc) {
    $method = 'createTestCase';
    $args = [
        'devKey' => $devKey,
        'testprojectid' => $tproject_id,
        'testsuiteid' => $tc['suite_id'],
        'testcasename' => $tc['name'],
        'summary' => $tc['summary'],
        'preconditions' => $tc['preconditions'],
        'authorlogin' => 'admin',
        'checkduplicatedname' => 0,
        'status' => 1,
        'importance' => $tc['importance'],
        'steps' => $tc['steps'],
    ];

    $client = new IXR_Client($server_url);
    $result = $client->call($method, $args);

    if ($result) {
        $created++;
        echo "OK  [{$created}] {$tc['name']}\n";
    } else {
        $failed++;
        $err = $client->getErrorMessage();
        $errors[] = "{$tc['name']}: {$err}";
        echo "FAIL[{$failed}] {$tc['name']} - {$err}\n";
    }
}

echo "\n=== SUMMARY ===\n";
echo "Created: {$created}\n";
echo "Failed: {$failed}\n";
if (!empty($errors)) {
    echo "\nErrors:\n";
    foreach ($errors as $e) {
        echo "  - {$e}\n";
    }
}
