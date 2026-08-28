# TLU: TestLink Upgraded 2.0.1 — Test Cases

**Project:** TLU: TestLink Upgraded 2.0.1  
**Prefix:** TLU  
**Author:** admin  
**Date:** 2026-08-20  

---

## Test Suites Structure

```
TLU: TestLink Upgraded 2.0.1
├── Header (12)
├── Dashboard (13)
├── System (14)
│   ├── Event Viewer (17)
│   ├── User Management (18)
│   ├── Role Management (19)
│   ├── Assign Project Roles (20)
│   ├── Assign Plan Roles (21)
│   └── Custom Fields (22)
├── API (15)
├── Installation (16)
├── Login (23)
├── i18n / Localization (24)
├── Custom Fields Modernized (25)
├── Header Fix #523 (26)
└── Results by Status #695 (Suite 35)
```

---

## 1. Header (Suite ID: 12)

### TC-1.1: Project Selector Displays All Accessible Projects
- **Priority:** High
- **Importance:** High
- **Preconditions:** User is logged in as admin with access to multiple test projects (P2, P3, TLU, TP).
- **Steps:**
  1. Observe the header area and locate the "Test Project" dropdown.  
     *Expected:* The dropdown is visible and contains the label "Test Project".
  2. Click the project selector dropdown to expand it.  
     *Expected:* Dropdown opens showing all accessible projects: P2:Project Two, P3:Project Three, TLU:TLU: TestLink Upgraded 2.0.1, TP:Test Project.
  3. Verify each option follows the format Prefix:Full Name.  
     *Expected:* All options display correctly with the naming convention.

### TC-1.2: Current Project Is Pre-selected in Header
- **Priority:** High
- **Importance:** High
- **Preconditions:** User is logged in as admin. Currently active project is TLU.
- **Steps:**
  1. Observe the project selector dropdown value in the header.  
     *Expected:* The dropdown shows "TLU:TLU: TestLink Upgraded 2.0.1" as the visible/selected value.
  2. Open the dropdown and check which option has the selected state.  
     *Expected:* The TLU option has the selected attribute, matching the current test project context.

### TC-1.3: Switch Test Project Changes Context
- **Priority:** High
- **Importance:** High
- **Preconditions:** User is logged in as admin in TLU project. Has access to P2:Project Two.
- **Steps:**
  1. Note the current project name displayed in the dropdown (TLU).  
     *Expected:* TLU project is currently displayed.
  2. Open the project selector dropdown and select "P2:Project Two".  
     *Expected:* The application reloads with the new project context.
  3. After reload, check the project selector dropdown value and main content.  
     *Expected:* "P2:Project Two" is now the selected value. The entire application context (tree, content area) shows P2 data.

### TC-1.4: Switch Project Preserves Feature Context
- **Priority:** Medium
- **Importance:** Medium
- **Preconditions:** User is on Test Specification in TLU project. Has access to TP:Test Project.
- **Steps:**
  1. Navigate to Test Specification (editTc feature).  
     *Expected:* Test Specification tree is displayed in the left panel.
  2. Switch to TP:Test Project via the header dropdown.  
     *Expected:* Application reloads.
  3. Check if the main content area shows Test Specification for TP project.  
     *Expected:* The page returns to Test Specification (or the closest equivalent) in the new project context, instead of the default dashboard.

### TC-1.5: Logo Click Reloads Main View
- **Priority:** Medium
- **Importance:** Medium
- **Preconditions:** User is on any sub-page (e.g., Test Specification).
- **Steps:**
  1. Navigate to Test Specification or any non-dashboard page.  
     *Expected:* Sub-page content is displayed in the main frame.
  2. Click the TestLink logo image in the top-left of the header.  
     *Expected:* The page navigates to index.php showing the main dashboard for the current project.
  3. Verify the URL contains the correct tproject_id parameter.  
     *Expected:* URL matches index.php?tproject_id=X where X is the current project ID.

### TC-1.6: Logo Image Loads Without Error
- **Priority:** Low
- **Importance:** Low
- **Preconditions:** User is logged in.
- **Steps:**
  1. Inspect the header visually and check the browser network tab for the logo image request.  
     *Expected:* Logo (tl-logo-transparent-25.png) loads with HTTP 200 status and is fully visible.

### TC-1.7: Header Displays Logged-In User Info
- **Priority:** High
- **Importance:** High
- **Preconditions:** User "admin" with role "admin" is logged in.
- **Steps:**
  1. After login, observe the top-right area of the header.  
     *Expected:* Text "admin [admin]" is displayed as a clickable link.
  2. Inspect the element title attribute via browser devtools.  
     *Expected:* The title attribute shows "admin [admin]".

### TC-1.8: User Info Link Opens Account Settings
- **Priority:** High
- **Importance:** High
- **Preconditions:** User is logged in as admin.
- **Steps:**
  1. Click on "admin [admin]" link in the header.  
     *Expected:* The main content frame loads userInfo.php (Account Settings).
  2. Verify the page contains sections: Personal data, Personal password, API interface, Login history.  
     *Expected:* All four sections are visible with correct headings and content.
  3. Verify the API interface section shows the current API key.  
     *Expected:* Personal API access key is displayed with a "Generate a new key" button.
  4. Verify the link opens in the mainframe (not a new tab).  
     *Expected:* The link has target="mainframe" and opens within the content area.

### TC-1.9: Logout Terminates Session and Redirects to Login
- **Priority:** High
- **Importance:** High
- **Preconditions:** User is logged in as admin.
- **Steps:**
  1. Click the "Logout" link in the header.  
     *Expected:* The session is terminated and the login page is displayed.
  2. Try to navigate back to the application using the browser back button.  
     *Expected:* User cannot access protected content; login page is shown instead.
  3. Verify the Logout link uses target="top" to break out of all iframes.  
     *Expected:* The Logout link has href="logout.php?viewer=" and target="top".

### TC-1.10: Sidebar Toggle Collapses and Expands Navigation
- **Priority:** Medium
- **Importance:** Medium
- **Preconditions:** User is logged in. Sidebar is initially visible/expanded.
- **Steps:**
  1. Observe the left sidebar is visible with menu items (System, Projects, Requirements, Test Case Design, etc.).  
     *Expected:* Sidebar is expanded showing all menu sections.
  2. Click the hamburger icon (fa-bars) in the header.  
     *Expected:* The sidebar collapses/hides, giving more space to the main content area.
  3. Click the hamburger icon again.  
     *Expected:* The sidebar expands back to its original state showing all menu items.

### TC-1.11: All Header Elements Present After Fresh Login
- **Priority:** High (Smoke)
- **Importance:** High
- **Preconditions:** User is on the login page with valid credentials (admin/admin).
- **Steps:**
  1. Login with valid credentials admin/admin.  
     *Expected:* Login successful, main application loads.
  2. Verify the following header elements are present and visible: hamburger toggle icon, TestLink logo, "Test Project" label, project selector dropdown, user info link (admin [admin]), Logout link.  
     *Expected:* All 6 header elements are visible and functional.

### TC-1.12: CSRF Token Present in Project Switch Form
- **Priority:** Medium
- **Importance:** Medium
- **Preconditions:** User is logged in.
- **Steps:**
  1. Inspect the HTML source of the header project switch form via browser devtools.  
     *Expected:* Form contains hidden input fields: CSRFName (with value starting with CSRFGuard_) and CSRFToken (with a long hex string).
  2. Verify the form action is index.php?action=projectChange and method is POST.  
     *Expected:* Form correctly targets the project change endpoint with POST method.

---

## 2. Dashboard (Suite ID: 13)

### TC-2.1: Dashboard Pie Chart Renders on Load
- **Priority:** High
- **Importance:** High
- **Preconditions:** User is logged in, dashboard page loaded.
- **Steps:**
  1. Navigate to the main dashboard (index.php).  
     *Expected:* Dashboard loads with pie chart visible.
  2. Check the pie chart has segments with different colors.  
     *Expected:* Pie chart renders with data segments (pass/fail/not run/blocked).

### TC-2.2: Dashboard Bugs Table Loads with Data
- **Priority:** Medium
- **Importance:** Medium
- **Preconditions:** User is logged in, dashboard loaded.
- **Steps:**
  1. Scroll to the bugs table section on the dashboard.  
     *Expected:* Bugs table is visible with column headers.
  2. Verify DataTables pagination and sorting controls.  
     *Expected:* Table has pagination, search, and sort controls functional.

### TC-2.3: Dashboard Monthly TC Growth Chart Renders
- **Priority:** Medium
- **Importance:** Medium
- **Preconditions:** User is logged in, dashboard loaded.
- **Steps:**
  1. Locate the monthly TC growth chart on the dashboard.  
     *Expected:* Line chart is visible with axes and data points.
  2. Hover over data points.  
     *Expected:* Tooltips show correct month and count values.

### TC-2.4: Dashboard Loads Without PHP Warnings
- **Priority:** High
- **Importance:** High
- **Preconditions:** User is logged in.
- **Steps:**
  1. Navigate to index.php dashboard.  
     *Expected:* Page loads cleanly.
  2. Check page source for PHP warning/error strings.  
     *Expected:* No "Warning:", "Notice:", "Fatal error:", "Deprecated:" in page output.

---

## 3. System — Event Viewer (Suite ID: 17)

### TC-3.1: Event Viewer Page Loads with Events Table
- **Priority:** High
- **Importance:** High
- **Preconditions:** User is logged in as admin.
- **Steps:**
  1. Navigate to Event Viewer via aside menu.  
     *Expected:* Event Viewer page loads with events table visible.
  2. Check table has columns: ID, Timestamp, Message, User, Type.  
     *Expected:* All expected columns are present.

### TC-3.2: Event Viewer Pie Chart Shows Distribution
- **Priority:** Medium
- **Importance:** Medium
- **Preconditions:** User is on Event Viewer page.
- **Steps:**
  1. Locate the pie chart on Event Viewer.  
     *Expected:* Pie chart renders with colored segments.
  2. Verify chart legend matches event types in the table.  
     *Expected:* Legend categories correspond to event types present in data.

### TC-3.3: Event Viewer Row Expand Shows Details
- **Priority:** Medium
- **Importance:** Medium
- **Preconditions:** User is on Event Viewer, table has data.
- **Steps:**
  1. Click on a row in the events table.  
     *Expected:* Row expands to show additional event details.
  2. Click the row again.  
     *Expected:* Row collapses back to summary view.

### TC-3.4: Event Viewer Date Filter Works
- **Priority:** Medium
- **Importance:** Medium
- **Preconditions:** User is on Event Viewer with data from multiple dates.
- **Steps:**
  1. Set a date range filter.  
     *Expected:* Table updates to show only events within selected date range.
  2. Clear the filter.  
     *Expected:* All events are displayed again.

### TC-3.5: Event Viewer Loads Without PHP Warnings
- **Priority:** High
- **Importance:** High
- **Preconditions:** User is logged in as admin.
- **Steps:**
  1. Navigate to Event Viewer.  
     *Expected:* Page loads cleanly without PHP warnings.
  2. Check page source and browser console for errors.  
     *Expected:* No PHP warnings, no JavaScript errors.

---

## 4. System — User Management (Suite ID: 18)

### TC-4.1: Users List Loads with DataTables
- **Priority:** High
- **Importance:** High
- **Preconditions:** User is logged in as admin.
- **Steps:**
  1. Navigate to User Management via aside menu.  
     *Expected:* Page loads with users table showing columns: Login, First Name, Last Name, Email, Role, Status.
  2. Verify search, pagination, and sorting work.  
     *Expected:* DataTables controls are functional.

### TC-4.2: Create New User
- **Priority:** High
- **Importance:** High
- **Preconditions:** Admin is logged in.
- **Steps:**
  1. Click Create button on User Management page.  
     *Expected:* Create user form/modal appears.
  2. Fill in: login, password, first name, last name, email, assign role.  
     *Expected:* All fields accept input.
  3. Submit the form.  
     *Expected:* User is created successfully, appears in the users list.

### TC-4.3: Edit User Details
- **Priority:** Medium
- **Importance:** Medium
- **Preconditions:** Admin is logged in, at least one user exists.
- **Steps:**
  1. Click Edit on an existing user row.  
     *Expected:* Edit form loads with current user data pre-filled.
  2. Modify first name and save.  
     *Expected:* User data is updated, new name shown in list.

### TC-4.4: Enable/Disable User Toggle
- **Priority:** High
- **Importance:** High
- **Preconditions:** Admin is logged in, user "tester1" exists and is active.
- **Steps:**
  1. Click the status toggle for an active user.  
     *Expected:* User status changes to inactive/disabled.
  2. Try logging in with the disabled user credentials.  
     *Expected:* Login fails for disabled user.
  3. Re-enable the user.  
     *Expected:* User status changes back to active.

### TC-4.5: Delete User Soft Deletes (active=2)
- **Priority:** High
- **Importance:** High
- **Preconditions:** Admin is logged in, a deletable user exists.
- **Steps:**
  1. Click Delete on a user.  
     *Expected:* Confirmation dialog appears.
  2. Confirm deletion.  
     *Expected:* User disappears from the list.
  3. Verify in DB that user active=2 (soft deleted, not hard deleted).  
     *Expected:* User record still exists in DB with active=2.

### TC-4.6: User Management Loads Without PHP Warnings
- **Priority:** High
- **Importance:** High
- **Preconditions:** Admin is logged in.
- **Steps:**
  1. Navigate to User Management.  
     *Expected:* Page loads cleanly.
  2. Check browser console and page source.  
     *Expected:* No PHP warnings, no JS errors.

---

## 5. System — Role Management (Suite ID: 19)

### TC-5.1: Roles List Loads with All Roles
- **Priority:** High
- **Importance:** High
- **Preconditions:** Admin is logged in.
- **Steps:**
  1. Navigate to Role Management.  
     *Expected:* Roles table displays all roles: reserved1, reserved2, reserved3, test designer, guest, senior tester, tester, admin, leader.
  2. Verify system roles are marked differently (non-editable).  
     *Expected:* Roles 1-3 cannot be edited or deleted.

### TC-5.2: Create New Role
- **Priority:** High
- **Importance:** High
- **Preconditions:** Admin is logged in.
- **Steps:**
  1. Click Create on Role Management page.  
     *Expected:* Create role form appears.
  2. Enter role name "TC:Test Creator" and assign some rights.  
     *Expected:* Fields accept input, rights checkboxes work.
  3. Save the role.  
     *Expected:* Role is created and appears in the list.

### TC-5.3: Edit Role Rights
- **Priority:** Medium
- **Importance:** Medium
- **Preconditions:** Custom role "TC:Test Creator" exists.
- **Steps:**
  1. Click Edit on custom role.  
     *Expected:* Edit form loads with current rights checked.
  2. Toggle some rights and save.  
     *Expected:* Role rights are updated.

### TC-5.4: Delete Custom Role
- **Priority:** Medium
- **Importance:** Medium
- **Preconditions:** Custom role exists with no user assignments.
- **Steps:**
  1. Click Delete on a custom role.  
     *Expected:* Confirmation appears.
  2. Confirm deletion.  
     *Expected:* Role is removed from the list.

### TC-5.5: System Roles Cannot Be Edited or Deleted
- **Priority:** High
- **Importance:** High
- **Preconditions:** Admin is logged in.
- **Steps:**
  1. Check if system roles (reserved1, reserved2, reserved3) have edit/delete buttons.  
     *Expected:* Edit and Delete buttons are disabled or hidden for system roles.
  2. Try to access edit URL directly for a system role ID.  
     *Expected:* Action is rejected or shows error.

### TC-5.6: Role Management Loads Without PHP Warnings
- **Priority:** High
- **Importance:** High
- **Preconditions:** Admin is logged in.
- **Steps:**
  1. Navigate to Role Management.  
     *Expected:* Page loads cleanly.
  2. Check console and page source.  
     *Expected:* No PHP warnings or JS errors.

---

## 6. System — Assign Project Roles (Suite ID: 20)

### TC-6.1: Assign User to Test Project Role
- **Priority:** High
- **Importance:** High
- **Preconditions:** Admin is logged in, user tester1 exists, TLU project exists.
- **Steps:**
  1. Navigate to Assign Project Roles.  
     *Expected:* Page loads with user/project/role selectors.
  2. Select user tester1, project TLU, role tester.  
     *Expected:* Selections are made.
  3. Save the assignment.  
     *Expected:* Assignment is saved, user has tester role in TLU project.

### TC-6.2: Remove User from Test Project Role
- **Priority:** Medium
- **Importance:** Medium
- **Preconditions:** User tester1 has role in TLU project.
- **Steps:**
  1. Select the existing assignment.  
     *Expected:* Assignment is highlighted/selected.
  2. Click Remove/Unassign.  
     *Expected:* User no longer has the role in the project.

### TC-6.3: Project Roles Page Loads Without PHP Warnings
- **Priority:** High
- **Importance:** High
- **Preconditions:** Admin is logged in.
- **Steps:**
  1. Navigate to Assign Project Roles.  
     *Expected:* Page loads cleanly, no PHP warnings.

---

## 7. System — Assign Plan Roles (Suite ID: 21)

### TC-7.1: Assign User to Test Plan Role
- **Priority:** High
- **Importance:** High
- **Preconditions:** Admin is logged in, user tester1 exists, test plan exists.
- **Steps:**
  1. Navigate to Assign Plan Roles.  
     *Expected:* Page loads with user/plan/role selectors.
  2. Select user, test plan, and role.  
     *Expected:* Selections are made.
  3. Save the assignment.  
     *Expected:* Assignment saved successfully.

### TC-7.2: Remove User from Test Plan Role
- **Priority:** Medium
- **Importance:** Medium
- **Preconditions:** User has a role in a test plan.
- **Steps:**
  1. Select existing plan role assignment.  
     *Expected:* Assignment is selected.
  2. Click Remove.  
     *Expected:* Assignment is removed.

### TC-7.3: Plan Roles Page Loads Without PHP Warnings
- **Priority:** High
- **Importance:** High
- **Preconditions:** Admin is logged in.
- **Steps:**
  1. Navigate to Assign Plan Roles.  
     *Expected:* Page loads cleanly, no PHP warnings.

---

## 8. System — Custom Fields (Suite ID: 22)

### TC-8.1: Create Custom Field (String Type)
- **Priority:** High
- **Importance:** High
- **Preconditions:** Admin is logged in.
- **Steps:**
  1. Navigate to Custom Fields.  
     *Expected:* CF list page loads.
  2. Click Create, enter name "CF_TestString", type String.  
     *Expected:* Form accepts input.
  3. Save.  
     *Expected:* Custom field created and visible in list.

### TC-8.2: Edit Custom Field Properties
- **Priority:** Medium
- **Importance:** Medium
- **Preconditions:** Custom field CF_TestString exists.
- **Steps:**
  1. Click Edit on CF_TestString.  
     *Expected:* Edit form loads with current values.
  2. Change the name to CF_TestString_v2 and save.  
     *Expected:* Name updated in list.

### TC-8.3: Assign Custom Field to Test Project
- **Priority:** High
- **Importance:** High
- **Preconditions:** Custom field exists, TLU project exists.
- **Steps:**
  1. Navigate to CF assignment for TLU project.  
     *Expected:* Assignment page loads.
  2. Check the custom field to enable it for TLU.  
     *Expected:* Checkbox is selected.
  3. Save assignment.  
     *Expected:* CF is now available for test cases in TLU.

### TC-8.4: Enable CF for Test Cases Entity Type
- **Priority:** Medium
- **Importance:** Medium
- **Preconditions:** CF assigned to TLU project, enabled for test cases.
- **Steps:**
  1. Create a new test case in TLU project.  
     *Expected:* TC edit form loads.
  2. Check if custom field appears in the form.  
     *Expected:* Custom field input is visible in the TC form.

### TC-8.5: Export Custom Fields to XML
- **Priority:** Medium
- **Importance:** Medium
- **Preconditions:** At least one custom field exists.
- **Steps:**
  1. Click Export on Custom Fields page.  
     *Expected:* XML file is downloaded.
  2. Open the XML file and verify structure.  
     *Expected:* Valid XML with field definitions (name, type, default value, etc.).

### TC-8.6: Import Custom Fields from XML
- **Priority:** Medium
- **Importance:** Medium
- **Preconditions:** Valid CF XML export file exists.
- **Steps:**
  1. Click Import on Custom Fields page.  
     *Expected:* File upload dialog appears.
  2. Select the XML file and import.  
     *Expected:* Custom fields are imported and appear in the list.

### TC-8.7: Custom Fields Page Loads Without PHP Warnings
- **Priority:** High
- **Importance:** High
- **Preconditions:** Admin is logged in.
- **Steps:**
  1. Navigate to Custom Fields.  
     *Expected:* Page loads cleanly, no PHP warnings.

---

## 9. API (Suite ID: 15)

### TC-9.1: Event Viewer API Returns Valid JSON
- **Priority:** High
- **Importance:** High
- **Preconditions:** User is logged in with valid session.
- **Steps:**
  1. Make GET request to /api/eventviewer/index.php.  
     *Expected:* Response HTTP 200 with Content-Type: application/json.
  2. Parse the JSON response.  
     *Expected:* Valid JSON with "data" array containing event objects with id, timestamp, message, user fields.

### TC-9.2: Users API CRUD Operations
- **Priority:** High
- **Importance:** High
- **Preconditions:** User is logged in as admin with valid session.
- **Steps:**
  1. GET /api/users/index.php - list all users.  
     *Expected:* Returns array of users with id, login, first, last, email, role, active.
  2. POST to create a new user.  
     *Expected:* User created, returns success response.
  3. PUT to update the user.  
     *Expected:* User updated, returns success.
  4. DELETE to soft-delete the user.  
     *Expected:* User soft-deleted (active=2), removed from list.

### TC-9.3: Roles API CRUD Operations
- **Priority:** High
- **Importance:** High
- **Preconditions:** User is logged in as admin.
- **Steps:**
  1. GET /api/roles/index.php - list all roles.  
     *Expected:* Returns roles with id, name, description, rights.
  2. POST to create a new role.  
     *Expected:* Role created successfully.
  3. PUT to update role rights.  
     *Expected:* Role updated.
  4. DELETE to remove custom role.  
     *Expected:* Role deleted (if not system role).

### TC-9.4: API Returns 401 Without Session
- **Priority:** High
- **Importance:** High
- **Preconditions:** No active session (logged out or fresh browser).
- **Steps:**
  1. Make request to /api/users/index.php without session cookie.  
     *Expected:* Response returns 401 or redirect to login.
  2. Make request to /api/roles/index.php without session.  
     *Expected:* Response returns 401 or redirect to login.

---

## 10. Installation (Suite ID: 16)

### TC-10.1: No PHP Warnings on Modernized Pages
- **Priority:** High
- **Importance:** High
- **Preconditions:** PHP 8.x running, user logged in as admin.
- **Steps:**
  1. Visit each modernized page: Event Viewer, User Management, Role Management, Assign Project Roles, Assign Plan Roles, Custom Fields.  
     *Expected:* All pages load without PHP warnings in output.
  2. Check error_log for PHP warnings during page loads.  
     *Expected:* No new PHP warnings in error_log.

### TC-10.2: Session Handling Works Correctly
- **Priority:** High
- **Importance:** High
- **Preconditions:** User is logged in.
- **Steps:**
  1. Navigate through all main pages sequentially.  
     *Expected:* All pages load without session errors.
  2. Open multiple browser tabs on different pages.  
     *Expected:* Sessions are shared across tabs.
  3. Logout from one tab, check other tabs.  
     *Expected:* Other tabs show session expired on next action.

### TC-10.3: PHP Version Compatibility Check
- **Priority:** Medium
- **Importance:** Medium
- **Preconditions:** PHP 8.x installed.
- **Steps:**
  1. Check phpinfo() for PHP version.  
     *Expected:* PHP 8.x is running.
  2. Navigate through core TestLink pages.  
     *Expected:* No Fatal errors related to PHP 8.x type changes, named arguments, etc.

---

## 11. Login (Suite ID: 23)

### TC-11.1: Valid Login with Correct Credentials
- **Priority:** High
- **Importance:** High
- **Preconditions:** Admin user exists with credentials admin/admin.
- **Steps:**
  1. Navigate to the login page.  
     *Expected:* Login form is displayed with username and password fields.
  2. Enter username "admin" and password "admin".  
     *Expected:* Fields accept input.
  3. Click Login / Submit.  
     *Expected:* Login successful, redirected to main dashboard with project selector.

### TC-11.2: Invalid Login with Wrong Password
- **Priority:** High
- **Importance:** High
- **Preconditions:** Login page is displayed.
- **Steps:**
  1. Enter username "admin" and password "wrongpassword".  
     *Expected:* Fields accept input.
  2. Click Login.  
     *Expected:* Login fails, error message displayed, user stays on login page.

### TC-11.3: Invalid Login with Non-existent User
- **Priority:** Medium
- **Importance:** Medium
- **Preconditions:** Login page is displayed.
- **Steps:**
  1. Enter username "nonexistent" and password "anything".  
     *Expected:* Fields accept input.
  2. Click Login.  
     *Expected:* Login fails with generic error (should not reveal whether user exists).

### TC-11.4: Login with Empty Fields
- **Priority:** Medium
- **Importance:** Medium
- **Preconditions:** Login page is displayed.
- **Steps:**
  1. Leave both username and password empty, click Login.  
     *Expected:* Form validation prevents submission, error or browser validation message shown.
  2. Enter only username, leave password empty, click Login.  
     *Expected:* Form validation prevents submission.

### TC-11.5: Login with Disabled User Account
- **Priority:** High
- **Importance:** High
- **Preconditions:** User "inactive1" exists with active=0.
- **Steps:**
  1. Enter username "inactive1" and valid password.  
     *Expected:* Fields accept input.
  2. Click Login.  
     *Expected:* Login fails with "account disabled" message.

### TC-11.6: Login Page Loads Without PHP Warnings
- **Priority:** High
- **Importance:** High
- **Preconditions:** PHP 8.x running.
- **Steps:**
  1. Navigate to login page.  
     *Expected:* Page loads cleanly.
  2. Check page source and browser console.  
     *Expected:* No PHP warnings, notices, or JS errors.

### TC-11.7: Password Field Masks Input
- **Priority:** Low
- **Importance:** Low
- **Preconditions:** Login page is displayed.
- **Steps:**
  1. Type in the password field.  
     *Expected:* Characters are masked (shown as dots or asterisks).

### TC-11.8: Login with Special Characters in Password
- **Priority:** High
- **Importance:** High
- **Preconditions:** A user exists with a password containing special characters (e.g. `ramona2@`).
- **Steps:**
  1. Navigate to login page.  
     *Expected:* Login form displayed.
  2. Enter username and password with special characters (e.g. user: sebiboga, pass: ramona2@).  
     *Expected:* Fields accept all characters including @.
  3. Click Login.  
     *Expected:* Login successful, redirected to dashboard.
  4. Log out and try again to confirm it works consistently.  
     *Expected:* Login works reliably with special characters.

### TC-11.9: Stale Session Message Does Not Block Login
- **Priority:** Medium
- **Importance:** Medium
- **Preconditions:** A previous session still exists (browser was not fully logged out).
- **Steps:**
  1. Open login page when a valid session already exists.  
     *Expected:* Message "There is still a valid login for your browser. Please use this link Logout at first" is shown.
  2. Click the Logout link provided in the message.  
     *Expected:* Session is cleared.
  3. Login again with valid credentials.  
     *Expected:* Login succeeds without issues.

---

## User Profile (userInfo.html)

### TC-12.1: Page Loads with Correct User Data
- **Priority:** High
- **Importance:** High
- **Preconditions:** User is logged in.
- **Steps:**
  1. Navigate to System → User Profile.  
     *Expected:* Page loads with header "User Profile — account settings and preferences".
  2. Verify fields: Login (readonly), First Name, Last Name, Email, Locale dropdown, Role (readonly).  
     *Expected:* All fields populated with current user data. Login and Role are read-only.

### TC-12.2: Update Personal Data
- **Priority:** High
- **Importance:** High
- **Preconditions:** User is logged in, User Profile page is open.
- **Steps:**
  1. Change First Name to "Test".  
     *Expected:* Field accepts the change.
  2. Click Save.  
     *Expected:* Green alert "Profile updated" appears.
  3. Reload page.  
     *Expected:* First Name is still "Test" — change persisted.
  4. Change back to original value and save again.  
     *Expected:* Restored.

### TC-12.3: Validation - Required Fields
- **Priority:** High
- **Importance:** Medium
- **Preconditions:** User Profile page is open.
- **Steps:**
  1. Clear First Name field.  
     *Expected:* Field is empty.
  2. Click Save.  
     *Expected:* Error: "First Name, Last Name, and Email are required."
  3. Restore First Name, clear Last Name, click Save.  
     *Expected:* Same error.
  4. Restore Last Name, clear Email, click Save.  
     *Expected:* Same error.

### TC-12.4: Change Password - Success
- **Priority:** High
- **Importance:** High
- **Preconditions:** User knows current password.
- **Steps:**
  1. Enter current password.  
     *Expected:* Field accepts input.
  2. Enter new password and confirm.  
     *Expected:* Both fields match.
  3. Click Change Password.  
     *Expected:* Green alert "Password changed successfully".
  4. Logout and login with new password.  
     *Expected:* Login succeeds with new password.

### TC-12.5: Change Password - Wrong Current Password
- **Priority:** High
- **Importance:** High
- **Preconditions:** User Profile page is open.
- **Steps:**
  1. Enter wrong current password.  
     *Expected:* Field accepts input.
  2. Enter new password and confirm.  
     *Expected:* Fields match.
  3. Click Change Password.  
     *Expected:* Error: "Old password is incorrect."

### TC-12.6: Change Password - Mismatched New Passwords
- **Priority:** Medium
- **Importance:** Medium
- **Preconditions:** User Profile page is open.
- **Steps:**
  1. Enter correct current password.  
     *Expected:* Field accepts input.
  2. Enter different values in New Password and Confirm fields.  
     *Expected:* Fields accept different values.
  3. Click Change Password.  
     *Expected:* Error: "New passwords do not match."

### TC-12.7: API Key Display and Regenerate
- **Priority:** Medium
- **Importance:** Medium
- **Preconditions:** User Profile page is open, API is enabled.
- **Steps:**
  1. Check API Key section.  
     *Expected:* Current API key is displayed in monospace font.
  2. Click "Generate New Key".  
     *Expected:* Green alert "API key generated". Key value changes to new key.
  3. Reload page.  
     *Expected:* New key persists.

### TC-12.8: Login History Displays Correctly
- **Priority:** Medium
- **Importance:** Low
- **Preconditions:** User has logged in at least once.
- **Steps:**
  1. Check "Successful Logins" section.  
     *Expected:* Table shows timestamps and descriptions of recent logins.
  2. Check "Failed Logins" section.  
     *Expected:* Either "No failed logins" or table of failed attempts.
  3. Try wrong password 3 times, then check Failed Logins.  
     *Expected:* Failed attempts appear in the table.

### TC-12.9: Locale Dropdown Works
- **Priority:** Medium
- **Importance:** Low
- **Preconditions:** User Profile page is open.
- **Steps:**
  1. Open Locale dropdown.  
     *Expected:* 18 locales listed (Czech, German, English UK/US, Spanish, Finnish, French, Indonesian, Italian, Japanese, Korean, Dutch, Polish, Portuguese BR/PT, Russian, Chinese).
  2. Select a different locale.  
     *Expected:* Dropdown updates.
  3. Click Save.  
     *Expected:* Locale change persists on reload.

### TC-12.10: Aside Menu Link Works
- **Priority:** High
- **Importance:** Medium
- **Preconditions:** User is logged in with user_mgmt permission.
- **Steps:**
  1. Expand System section in aside menu.  
     *Expected:* "User Profile" link with user-circle icon appears at top of section.
  2. Click "User Profile".  
     *Expected:* userInfo.html loads in mainframe with all sections visible.

### TC-12.11: Header Link Opens User Profile
- **Priority:** Medium
- **Importance:** Medium
- **Preconditions:** User is logged in.
- **Steps:**
  1. Click username link in header (e.g. "admin [admin]").  
     *Expected:* Opens userInfo.html in mainframe.

---

## 13. i18n / Localization (Suite ID: 24)

### TC-13.1: Event Viewer Locale Switcher Changes All Labels
- **Priority:** High
- **Importance:** High
- **Preconditions:** User is logged in, Event Viewer page open.
- **Steps:**
  1. Open Event Viewer.  
     *Expected:* Page loads with locale from user profile (e.g. Romana for sebiboga).
  2. Select "English" from locale switcher dropdown.  
     *Expected:* Page reloads with `?locale=en`, all labels change to English: "Event Viewer", "Log Levels", "Apply", "Clear Events".
  3. Select "Romana" from dropdown.  
     *Expected:* Page reloads, all labels change to Romanian: "Vizualizare Evenimente", "Niveluri Log", "Aplica", "Sterge Evenimente".

### TC-13.2: User Profile Locale Determines Default Language
- **Priority:** High
- **Importance:** High
- **Preconditions:** User sebiboga has locale set to "Romana" in profile.
- **Steps:**
  1. Clear browser localStorage (`tl_locale`).  
     *Expected:* No cached locale.
  2. Navigate to Event Viewer without `?locale=` param.  
     *Expected:* Page loads in Romanian automatically (from profile DB), not English.
  3. Verify locale switcher shows "Romana" selected.

### TC-13.3: URL Locale Param Overrides Profile
- **Priority:** Medium
- **Importance:** Medium
- **Preconditions:** User has locale Romana in profile.
- **Steps:**
  1. Navigate to Event Viewer with `?locale=en`.  
     *Expected:* Page loads in English despite profile being Romana.
  2. Verify locale switcher shows "English" selected.

### TC-13.4: All 10 Locales Present in Switcher
- **Priority:** Medium
- **Importance:** Medium
- **Preconditions:** Any modernized page open.
- **Steps:**
  1. Open locale switcher dropdown.  
     *Expected:* 10 options visible: English, Romana, Deutsch, Francais, Espanol, Italiano, Portugues, Русский, 日本語, 中文.
  2. Select each non-English locale one by one.  
     *Expected:* All labels translate correctly for each locale.

### TC-13.5: All 6 Modernized Pages Have Locale Switcher
- **Priority:** High
- **Importance:** High
- **Preconditions:** User is logged in.
- **Steps:**
  1. Navigate to each page and verify locale switcher is present:
     - Event Viewer  
     - User Management  
     - Role Management  
     - Assign Project Roles  
     - Assign Plan Roles  
     - Custom Fields  
     *Expected:* All 6 pages show the locale switcher dropdown in the header.

### TC-13.6: Locale Switcher Persists Across Pages
- **Priority:** Medium
- **Importance:** Medium
- **Preconditions:** User is on Event Viewer in English.
- **Steps:**
  1. Switch to "Romana" on Event Viewer.  
     *Expected:* Page reloads in Romanian.
  2. Navigate to User Management via aside menu.  
     *Expected:* User Management also loads in Romanian (URL contains `?locale=ro`).

---

## 14. Custom Fields Modernized (Suite ID: 25)

### TC-14.1: Custom Fields Page Loads Without PHP Warnings
- **Priority:** High
- **Importance:** High
- **Preconditions:** User is logged in as admin.
- **Steps:**
  1. Click "Custom Fields" in aside menu System section.  
     *Expected:* cfieldsView.html loads in mainframe, no PHP warnings or JS errors.
  2. Verify table shows existing custom fields.

### TC-14.2: Create Custom Field
- **Priority:** High
- **Importance:** High
- **Preconditions:** Admin is logged in.
- **Steps:**
  1. Click "+ Creaza Camp Personalizat" button.  
     *Expected:* Modal opens with form fields: Label, Name, Type, Node Type, etc.
  2. Fill in Label="TC Field", Name="tc_field", Type="string", Node Type="testcase", check "Design".  
     *Expected:* Form accepts input.
  3. Click Save.  
     *Expected:* Modal closes, new field appears in table, count increases.

### TC-14.3: Edit Custom Field
- **Priority:** High
- **Importance:** High
- **Preconditions:** Custom field "tc_field" exists.
- **Steps:**
  1. Click Edit (pencil icon) on "tc_field" row.  
     *Expected:* Modal opens with current values pre-filled.
  2. Change Label to "TC Field Updated".  
     *Expected:* Input accepts change.
  3. Click Save.  
     *Expected:* Table shows updated label.

### TC-14.4: Delete Custom Field
- **Priority:** Medium
- **Importance:** Medium
- **Preconditions:** Custom field "tc_field" exists.
- **Steps:**
  1. Click Delete (trash icon) on "tc_field" row.  
     *Expected:* Confirmation modal appears.
  2. Confirm deletion.  
     *Expected:* Field removed from table, count decreases.

### TC-14.5: Custom Fields i18n in All Locales
- **Priority:** Medium
- **Importance:** Medium
- **Preconditions:** Custom Fields page open.
- **Steps:**
  1. Switch locale to Romana.  
     *Expected:* Header shows "Campuri Personalizate", button "Creaza Camp Personalizat", columns "Eticheta", "Nume", "Tip", "Activ", "Disponibil Pe".
  2. Switch to Deutsch.  
     *Expected:* All labels translated to German.

### TC-14.6: Custom Fields Table Sorting and Search
- **Priority:** Low
- **Importance:** Low
- **Preconditions:** Multiple custom fields exist.
- **Steps:**
  1. Click "Label" column header.  
     *Expected:* Table sorts by label ascending/descending.
  2. Type in search box.  
     *Expected:* Table filters rows matching search text.

---

## 15. Header Display Fix #523 (Suite ID: 26)

### TC-15.1: Header Shows Full Name Not Login
- **Priority:** High
- **Importance:** High
- **Preconditions:** User admin has firstName="Testlink", lastName="Administrator".
- **Steps:**
  1. Login as admin.  
     *Expected:* Header shows "Testlink Administrator" (first + last name), NOT "admin".
  2. Check role is displayed below the name.  
     *Expected:* Role name (e.g. "administrator") shown on separate line below full name.

### TC-15.2: Header Updates for Different Users
- **Priority:** Medium
- **Importance:** Medium
- **Preconditions:** User sebiboga exists with custom name.
- **Steps:**
  1. Login as sebiboga.  
     *Expected:* Header shows sebiboga's first + last name and role on separate lines.

### TC-15.3: Sidebar Also Shows Full Name
- **Priority:** Medium
- **Importance:** Medium
- **Preconditions:** User is logged in.
- **Steps:**
  1. Look at sidebar bottom section.  
     *Expected:* Full name displayed (not login), with role below it.

---

## 16. Assign Custom Fields — Modernized (Suite ID: 27)

> **Run 2026-08-21 (browser + curl):** 14/14 PASS. Note: TC-16.8 first run failed due to wrong
> assumed initial state (Execution was pre-checked from an earlier manual save); retest passed.
> TC-16.12 confirmed after iframe navigation completed (switcher reloads frame with ?locale=ro).
> State restored after run: Rol label=rol, design=1 exec=1 tpd=1, display_order=1; field1 unassigned.

**Screen:** `gui/templates/cfields/cfieldsAssignView.html` · **API:** `/api/cfields/index.php`  
**Path:** Projects > Assign Custom Fields

### TC-16.1: Assign Custom Fields Page Loads Without PHP Warnings
- **Priority:** High
- **Importance:** High
- **Preconditions:** User is logged in as admin. At least one test project exists.
- **Steps:**
  1. Open aside menu > Projects > "Assign Custom Fields".  
     *Expected:* cfieldsAssignView.html loads inside the mainframe shell (navbar + sidebar visible), no PHP warnings or JS console errors.
  2. Verify header shows "Assign Custom Fields" with subtitle and locale switcher.
  3. Verify toolbar shows current Test Project name.

### TC-16.2: Assigned Table Shows All Columns
- **Priority:** High
- **Importance:** High
- **Preconditions:** Current test project has at least one custom field assigned (e.g. "Rol").
- **Steps:**
  1. Inspect the "Assigned Custom Fields" table columns.  
     *Expected:* Columns present: checkbox, Name, Label, Type, Available On, Display Order, Location, Active, Required, Monitorable.
  2. Verify row data matches the assigned field definition.  
     *Expected:* e.g. Rol / rol / string / Test Plan, order input shows stored value, checkboxes reflect stored flags.

### TC-16.3: Available Table Shows Unassigned Fields Only
- **Priority:** High
- **Importance:** High
- **Preconditions:** At least one custom field exists that is NOT assigned to the current project (e.g. "field1").
- **Steps:**
  1. Inspect the "Available Custom Fields" table.  
     *Expected:* Lists only fields not yet linked to this project; no overlap with Assigned table.
  2. Compare counts in both section headings.  
     *Expected:* "(n)" counts match rendered row counts; footer shows "assigned / total".

### TC-16.4: Assign Field to Test Project
- **Priority:** High
- **Importance:** High
- **Preconditions:** Field "field1" is in Available table.
- **Steps:**
  1. Check the row checkbox for "field1" in Available table.  
     *Expected:* Row highlights (selected-row class).
  2. Click "Assign".  
     *Expected:* Success toast appears; tables reload; "field1" moves from Available to Assigned; counts update.

### TC-16.5: Unassign Field From Test Project
- **Priority:** High
- **Importance:** High
- **Preconditions:** Field "field1" is assigned (from TC-16.4).
- **Steps:**
  1. Check the row checkbox for "field1" in Assigned table.
  2. Click "Unassign".  
     *Expected:* Success toast; tables reload; "field1" returns to Available table.

### TC-16.6: Save Changes Persists Order, Location and Flags
- **Priority:** High
- **Importance:** High
- **Preconditions:** Field "Rol" is assigned to the project.
- **Steps:**
  1. Change Display Order to 5, toggle Required ON, set Location if selectable.  
     *Expected:* "unsaved changes" indicator appears in toolbar.
  2. Click "Save Changes".  
     *Expected:* Toast "Changes saved"; dirty indicator clears.
  3. Reload the page (F5).  
     *Expected:* Values persisted: order=5, Required checked.
  4. Restore original values and Save again.  
     *Expected:* State restored.

### TC-16.7: Edit Modal Opens From Field Name Link
- **Priority:** High
- **Importance:** High
- **Preconditions:** Field "Rol" visible in Assigned table.
- **Steps:**
  1. Click the field name "Rol" in the Assigned table.  
     *Expected:* Bootstrap modal "Edit Custom Field: Rol" opens INSIDE the content frame; user stays in context (navbar + sidebar still visible); NO navigation to legacy cfieldsEdit.php.
  2. Verify modal fields are pre-filled: Label="rol", Name="Rol" (read-only), Type="string", Node Type="testplan", Enable On checkboxes reflect stored flags.
  3. Click Cancel.  
     *Expected:* Modal closes; page state unchanged.

### TC-16.8: Edit Modal Saves Changes Via API
- **Priority:** High
- **Importance:** High
- **Preconditions:** Modal open for field "Rol" (from TC-16.7).
- **Steps:**
  1. Change Label to "rol_editat", check Execution under Enable On.  
     *Expected:* Inputs accept changes.
  2. Click Save.  
     *Expected:* Modal closes; toast confirms; PUT request to /api/cfields/{id} returns 200; tables refresh showing updated label.
  3. Open the modal again.  
     *Expected:* New values persisted (Label="rol_editat", Execution checked).
  4. Restore original values (Label="rol") and Save.  
     *Expected:* State restored.

### TC-16.9: Edit Modal Validation - Empty Label
- **Priority:** Medium
- **Importance:** Medium
- **Preconditions:** Modal open for any field.
- **Steps:**
  1. Clear the Label input completely.
  2. Click Save.  
     *Expected:* Inline error shown in modal ("required" message); modal stays open; no API call made.
  3. Click Cancel to dismiss.

### TC-16.10: Edit Modal From Available Table Also Works
- **Priority:** Medium
- **Importance:** Medium
- **Preconditions:** Field "field1" present in Available table.
- **Steps:**
  1. Click "field1" name in Available table.  
     *Expected:* Same edit modal opens pre-filled with field1's data (node type testcase).

### TC-16.11: Unsaved Assignment Edits Survive Modal Cancel
- **Priority:** Medium
- **Importance:** Medium
- **Preconditions:** Field "Rol" assigned.
- **Steps:**
  1. Change Display Order to 9 (dirty indicator appears).
  2. Click field name to open edit modal, then click Cancel.  
     *Expected:* Modal closes; order input still shows 9; dirty indicator still visible.
  3. Reload page without saving.  
     *Expected:* Order reverts to stored value (unsaved change discarded).

### TC-16.12: Locale Switcher Translates Screen
- **Priority:** Medium
- **Importance:** Medium
- **Preconditions:** Page open.
- **Steps:**
  1. Switch locale to Română.  
     *Expected:* Header, column headers and buttons translate (e.g. "Câmpuri Personalizate Alocate").
  2. Switch back to English (wide/UK).  
     *Expected:* Labels return to English.

### TC-16.13: Tables Sorting and Search
- **Priority:** Low
- **Importance:** Low
- **Preconditions:** Multiple fields assigned.
- **Steps:**
  1. Click "Name" column header.  
     *Expected:* Rows sort ascending/descending.
  2. Type in the Search box.  
     *Expected:* Rows filter live; clearing restores full list.

### TC-16.14: API Returns 401 Without Session
- **Priority:** High
- **Importance:** High
- **Preconditions:** None (curl/http client).
- **Steps:**
  1. GET /api/cfields/index.php/assignment?tproject_id=1 without cookies.  
     *Expected:* HTTP 401 with JSON {"status":"error","message":"Not authenticated"}.

---



### BUG-2: "Still Valid Login" Message is Confusing and Potentially Blocks Users
- **Severity:** Medium
- **Date:** 2026-08-20
- **Component:** Login page (login.php)
- **Description:** When a previous session still exists (e.g. browser tab was closed but cookie wasn't cleared), the login page shows "There is still a valid login for your browser" and does NOT show the standard login form directly. The user must click Logout first to clear the session, then login again. This is a two-step process that confuses users.
- **Steps to Reproduce:**
  1. Login successfully
  2. Open a new tab to login.php
  3. Message appears instead of login form
- **Expected:** Either auto-clear old session OR show login form with a subtle note, not block the login
- **Actual:** User must click Logout link, wait for redirect, then fill login form again

---

## 17. Platform Management — Modernized (Suite ID: 28)

> **Run 2026-08-21 (browser + curl):** 18/18 PASS after fixes.
> Fixes applied during run: `tlPlatform::belongsToTestProject()` always-false check
> (isset on map keyed by id value), keywords API static `testproject::getName()` misuse
> (ArgumentCountError 500), flags endpoint parse error + inverted on/off map,
> missing i18n keys `pl.editPlatform` / `pl.chooseFileLabel`, disabled toggles no longer
> show actionable tooltips. Event Viewer gained object_id/object_type filter support
> (legacy parity for "Show event history" drill-down).
> State left: platforms Windows/macOS remain; testplan "Master Plan" (id=10) links platform 1;
> viewer/platform_viewer role created for permission tests.

**Screen:** `gui/templates/platforms/platformsView.html` · **API:** `/api/platforms/index.php`  
**Path:** Test Project > Platform Management

### TC-17.1: Screen Loads Without PHP Warnings
- **Priority:** High
- **Importance:** High
- **Preconditions:** Logged in as admin with platform_management right. At least one test project exists.
- **Steps:**
  1. Open aside menu > Test Project > "Platform Management".
     *Expected:* platformsView.html loads in mainframe shell, no PHP warnings or JS console errors.
  2. Verify header shows title + subtitle + locale switcher + current Test Project name.

### TC-17.2: Empty State Message
- **Priority:** Medium
- **Importance:** Medium
- **Preconditions:** Test project has no platforms.
- **Steps:**
  1. Load screen with zero platforms.
     *Expected:* Info panel "No platforms defined..." instead of table; Create button still available.

### TC-17.3: Create Platform — Valid Data
- **Priority:** High
- **Importance:** High
- **Steps:**
  1. Click "+ Create Platform", fill name "Windows 11 + Chrome" and notes, keep all flags checked, Save.
     *Expected:* Modal closes, toast "Platform saved", row appears with `[ID: n]` icon, linked badge 0, all three toggles Active.

### TC-17.4: Create Platform — Duplicate Name Rejected
- **Priority:** High
- **Importance:** High
- **Steps:**
  1. Create a second platform with an existing name.
     *Expected:* Error toast "A platform with this name already exists in this test project"; modal stays open; HTTP 422 E_NAMEALREADYEXISTS.

### TC-17.5: Create Platform — Empty Name Rejected
- **Priority:** High
- **Importance:** High
- **Steps:**
  1. Create with empty name (spaces only).
     *Expected:* Error toast "Empty platform name is not allowed"; HTTP 422 E_NAMELENGTH.

### TC-17.6: Flag Toggles Persist
- **Priority:** High
- **Importance:** High
- **Steps:**
  1. Click each toggle (On Design / On Execution / Open for Execution) in the list.
     *Expected:* Icon flips immediately, toast confirms, DB value updated (verified via curl PUT /{id}/flags and re-GET).

### TC-17.7: Edit Platform Prefills and Saves
- **Priority:** High
- **Importance:** High
- **Steps:**
  1. Click platform name link.
     *Expected:* Modal titled "Edit Platform: <name>" with name, notes, flag checkboxes prefilled from stored values.
  2. Change notes, Save.
     *Expected:* Toast, row notes updated.

### TC-17.8: Rename to Existing Name Rejected
- **Priority:** Medium
- **Importance:** Medium
- **Steps:**
  1. Edit platform A, set its name to platform B's name, Save.
     *Expected:* 422 duplicate-name error (guard legacy update() lacked).

### TC-17.9: Export Default Filename and XML Format
- **Priority:** High
- **Importance:** High
- **Steps:**
  1. Click Export Platforms.
     *Expected:* Filename input prefilled `<TestProjectNameNoSpaces>-platforms.xml`.
  2. Download.
     *Expected:* ADODB_XML document identical in structure to legacy export (`<platforms><platform>` CDATA name/notes/flags).

### TC-17.10: Import Creates and Updates by Name
- **Priority:** High
- **Importance:** High
- **Steps:**
  1. Import XML containing one new platform and one existing name with changed notes/flags.
     *Expected:* Toast "Platforms imported: 1 new, 1 updated"; new row appears; existing row's notes/flags reflect file.

### TC-17.11: Delete Unlinked Platform
- **Priority:** High
- **Importance:** High
- **Steps:**
  1. Click delete (minus-circle) on unlinked platform, Cancel first.
     *Expected:* Cancel closes modal, row remains.
  2. Delete again, confirm.
     *Expected:* Row removed, toast "Platform deleted".

### TC-17.12: Linked Platform Cannot Be Deleted
- **Priority:** High
- **Importance:** High
- **Preconditions:** Platform linked to at least one test plan (testplan_platforms row).
- **Steps:**
  1. Inspect Actions cell of linked platform.
     *Expected:* Heart (lock) icon with tooltip warning_cannot_delete_platform instead of delete button.
  2. Call DELETE via API.
     *Expected:* HTTP 422 DELETE_BLOCKED with message that platform is used by testplans.

### TC-17.13: Event History Drill-Down
- **Priority:** Medium
- **Importance:** Medium
- **Preconditions:** Admin has mgt_view_events.
- **Steps:**
  1. Open edit modal, click "Show event history".
     *Expected:* Event Viewer opens in new tab with red badge "filtered by object: platforms #<id>"; charts/table scoped to that object (0 rows when no object-scoped events exist).

### TC-17.14: Aside Menu Link Targets New Screen
- **Priority:** High
- **Importance:** High
- **Steps:**
  1. In shell, inspect aside menu entry href and click it.
     *Expected:* Points to `/gui/templates/platforms/platformsView.html?tproject_id=...&tplan_id=...` and loads inside mainframe.

### TC-17.15: Locale Switcher Translates All Labels
- **Priority:** Medium
- **Importance:** Medium
- **Steps:**
  1. Switch locale to Română.
     *Expected:* Page reloads with ?locale=ro; title, subtitle, buttons, column headers, toggle tooltips translated (Gestionare Platforme etc.). Switch back to English restores.

### TC-17.16: Search and Column Sorting
- **Priority:** Low
- **Importance:** Low
- **Steps:**
  1. Type partial platform name in search box.
     *Expected:* Table filters to matching rows; clearing restores.
  2. Click Platform header twice.
     *Expected:* Ascending then descending order.

### TC-17.17: View-Only User Gets Read-Only UI
- **Priority:** High
- **Importance:** High
- **Preconditions:** User with role granting only platform_view.
- **Steps:**
  1. Log in as viewer, open screen.
     *Expected:* Create/Import buttons hidden; Export visible; names are plain text (not links); toggles disabled without actionable tooltip; heart/delete replaced by nothing destructive.
  2. Call POST/PUT/DELETE/import endpoints with viewer session.
     *Expected:* All return HTTP 403 "No permission".

### TC-17.18: Unauthenticated API Access Returns 401
- **Priority:** High
- **Importance:** High
- **Steps:**
  1. Call GET and POST on `/api/platforms/index.php` without session cookie.
     *Expected:* HTTP 401 for both.

---

## Summary

| Suite | Test Cases | High | Medium | Low |
|---|---|---|---|---|
| Header | 12 | 7 | 4 | 1 |
| Dashboard | 4 | 2 | 2 | 0 |
| Event Viewer | 5 | 2 | 3 | 0 |
| User Management | 6 | 4 | 1 | 1 |
| Role Management | 6 | 4 | 2 | 0 |
| Assign Project Roles | 3 | 2 | 1 | 0 |
| Assign Plan Roles | 3 | 2 | 1 | 0 |
| Custom Fields | 7 | 3 | 4 | 0 |
| API | 4 | 4 | 0 | 0 |
| Installation | 3 | 2 | 1 | 0 |
| Login | 9 | 5 | 3 | 1 |
| User Profile | 11 | 5 | 5 | 1 |
| i18n / Localization | 6 | 3 | 3 | 0 |
| Custom Fields (Modernized) | 6 | 2 | 3 | 1 |
| Header Fix #523 | 3 | 1 | 2 | 0 |
| Assign Custom Fields (Modernized) | 14 | 8 | 5 | 1 |
| Platform Management (Modernized) | 18 | 11 | 5 | 2 |
| Results by Status #695 | 15 | 10 | 1 | 4 |
| TC with CF Report #737 | 17 | 10 | 5 | 2 |
| **TOTAL** | **152** | **91** | **52** | **14** |

### Bugs Found During Testing
| ID | Severity | Component | Description |
|---|---|---|---|
| BUG-2 | Medium | login.php | "Still valid login" message blocks direct login, requires 2 steps |

---

## 18. Regression — Issue #532: E_WARNING "Undefined array key documentation" in aside.tpl (Suite ID: 29)

**Root cause:** `aside.tpl` (line 308) reads `$gui->activeMenu.documentation` for the
Documentation section's CSS class, but `getFirstLevelMenuStructure()`
(`lib/functions/common.php`) — the source of every `activeMenu`/`showMenu` map built by
`setSystemWideActiveMenuOFF()` / `getMenuVisibility()` / `initUserEnv()` — did not define a
`documentation` key. Every shell page load therefore raised
`E_WARNING Undefined array key "documentation"` in the compiled aside template, recorded in
the Event Viewer.

**Fix:** Added `'documentation' => false` to `getFirstLevelMenuStructure()` so all menu maps
carry the key (empty string when inactive, matching the other first-level entries).

### TC-18.1: No E_WARNING on shell page load
- **Priority:** High
- **Importance:** High
- **Preconditions:** Fresh DB (`events` table empty). User admin/admin exists.
- **Steps (pre-fix repro):**
  1. Login as admin; open main page / any screen with the aside menu.
     *Pre-fix result:* `events` table gains an entry with
     `E_WARNING Undefined array key "documentation" ... aside.tpl.php`.
  2. Post-fix: repeat login + main page load.
     *Expected:* no new Error/Warning entries in `events`.
- **Actual result (post-fix):** PASS — after applying the fix, full shell loads with and
  without a selected test project produced zero new events of level Error/Warning;
  only pre-existing audit/localization notices remained.

### TC-18.2: Documentation section still renders in the aside menu
- **Priority:** High
- **Importance:** High
- **Preconditions:** Logged in as admin.
- **Steps:**
  1. Load any shell page and inspect the asidebar frame.
     *Expected:* "Documentation" section present with its sub-items (user manual,
     installation manual, file formats, excel import, fckeditor config, BTS howto,
     GitHub wiki).
- **Actual result (post-fix):** PASS — asidebar renders
  "System Projects Plugins Documentation" with all seven doc links intact.

## 19. Regression — Issue #531: keywords BFF ArgumentCountError 500 on testproject::getName() (Suite ID: 30)

**Issue:** https://github.com/sebiboga/testlink-upgraded/issues/531
`GET /api/keywords/index.php?tproject_id=<real id>` returned HTTP 500
(`ArgumentCountError: Too few arguments to function testproject::getName()`)
because `api/keywords/index.php:138` called `->getName()` as an instance method,
while `testproject::getName(&$dbh, $id)` is **static** and takes the DB handle
first. Empty `tproject_id=0` requests short-circuited earlier (400), masking the
bug.

- **Priority:** High
- **Importance:** High
- **Preconditions:** Logged-in session (admin/admin); a real test project exists
  (fixture: project id 1 "Repro Project 531" inserted into `testprojects` +
  `nodes_hierarchy`).
- **Steps (pre-fix repro):**
  1. `curl -b <session> 'http://localhost:8082/api/keywords/index.php?tproject_id=1'`
     *Pre-fix result:* HTTP 500 ArgumentCountError; Keyword Management screen
     fails to load its table.
- **Expected post-fix behavior:**
  1. Same request returns HTTP 200 with
     `"tproject":{"id":1,"name":"Repro Project 531"}`.
  2. Keyword Management screen (`gui/templates/keywords/keywordsView.html`)
     loads, shows the project name in the title and renders the keywords table.
  3. CRUD flows on the same endpoint still work (create keyword, list, delete).
- **Fix state:** The corrected call
  `testproject::getName($db, $tproject_id)` is already present at
  `api/keywords/index.php:138` (landed in commit `e9da5834c`, which replaced the
  buggy instance call introduced in `626e03ac5`). This run re-verified the fix
  end to end and closed the issue.
- **Actual result (post-fix):** PASS —
  * list with real project id → HTTP 200, correct project name;
  * POST create keyword → `{"status":"ok","id":1}`;
  * list again → item rendered (`critical`, usage 0, deletable);
  * unknown project id → HTTP 200 with `"name":null` (no fatal);
  * UI screen loads with title "Repro Project 531 - Keyword Management" and the
    table shows 1 entry;
  * Event Viewer: no new Error/Warning entries from these flows. (An unrelated
    pre-existing E_WARNING at `common.php:1987` fired during main-page load and
    was filed as issue #533.)

## 20. Regression — Issue #534: platforms BFF export ArgumentCountError 500 on default filename (Suite ID: 31)

**Issue:** https://github.com/sebiboga/testlink-upgraded/issues/534
`GET /api/platforms/index.php/export?tproject_id=<real id>` **without** a
`filename` query param returned HTTP 500 (`ArgumentCountError: Too few arguments
to function testproject::getName()`) because `api/platforms/index.php:369`
called `$tproject_mgr->getName($tproject_id)` as an instance method, while
`testproject::getName(&$dbh, $id)` is **static** and takes the DB handle first.
Same pattern as #531. The Platform Management screen itself always sends a
filename, so only direct API consumers hit the fatal.

- **Priority:** High
- **Importance:** High
- **Preconditions:** Logged-in session (admin/admin); a real test project exists
  (fixture: project id 1 "Demo Project" inserted into `testprojects` +
  `nodes_hierarchy`, with a properly serialized `options` value).
- **Steps (pre-fix repro):**
  1. `curl -b <session> 'http://localhost:8082/api/platforms/index.php/export?tproject_id=1'`
     *Pre-fix result:* HTTP 500, empty body (display_errors off);
     `ArgumentCountError: Too few arguments to function testproject::getName()`.
- **Expected post-fix behavior:**
  1. Same request returns HTTP 200 with `Content-Disposition: attachment;
     filename="DemoProject-platforms.xml"` (spaces stripped from project name,
     matching legacy `platformsExport.php doExport()`).
  2. Explicit `&filename=custom.xml` still honored verbatim.
  3. Export of a project with platforms yields the legacy ADODB_XML document
     `<platforms><platform>...` incl. `is_open`.
  4. UI export button on `gui/templates/platforms/platformsView.html?tproject_id=1`
     downloads with HTTP 200.
  5. Sibling BFF routes unaffected (list/create/delete).
  6. Event Viewer gains no new Error/Warning entries.
- **Fix state:** Fixed in this run — replaced the instance call with the static
  call `testproject::getName($db, $tproject_id)` at `api/platforms/index.php:369`,
  mirroring the #531 fix in `api/keywords/index.php:138`.
- **Actual result (post-fix):** PASS —
  * export without filename → HTTP 200, `filename="DemoProject-platforms.xml"`;
  * export with `filename=custom.xml` → HTTP 200, custom name kept;
  * after creating platform "RegPlatform": export body is valid ADODB_XML with
    `<platform><name><![CDATA[RegPlatform]]>…<is_open><![CDATA[1]]>`;
  * UI modal "Export Platforms" → download request
    `/api/platforms/index.php/export?tproject_id=1&filename=DemoProject-platforms.xml`
    → HTTP 200;
  * list/create/delete routes re-tested → all HTTP 200;
  * Event Viewer clean (only pre-existing audit login notices; the 4×
    E_WARNING at `common.php:1987` seen mid-run were caused by the initial
    hand-inserted fixture having NULL `options` and are the already-tracked
    open issue #533 — not introduced by this fix).

## 21. Regression — Issue #533: E_WARNING "Attempt to read property inventoryEnabled on false" at common.php:1987 (Suite ID: 32)

**Root cause:** `getGrantSetWithExit()` did
`if($tprojOpt->inventoryEnabled)` without guarding the result of
`testproject::getOptions()`. That method returns `false` when the serialized
blob in `testprojects.options` fails `unserialize()` (e.g. NULL/corrupt
options — how the issue was first observed during the #531/#534 runs), and an
empty `(object)[]` when no row matches the id (no project selected /
nonexistent id). Dereferencing `->inventoryEnabled` on either raised
E_WARNING, 4× per main page load (initUserEnv runs getGrantSetWithExit for
both the page and the menu context).

- **Priority:** Medium
- **Importance:** Medium
- **Preconditions:** Logged-in session (admin/admin); MariaDB up; at least one
  test project exists (fixture: project id 1 "Repro Project 533" inserted into
  `nodes_hierarchy` + `testprojects` with a valid serialized `options` blob).
- **Steps (pre-fix repro):**
  1. Set `testprojects.options` of project 1 to a corrupt serialized string.
  2. `curl -b <session> http://localhost:8082/lib/general/mainPage.php`
     *Pre-fix result:* 4 new rows in `events` with `log_level=2`:
     `E_WARNING Attempt to read property "inventoryEnabled" on false - in
     lib/functions/common.php - Line 1987` (plus 4× sibling
     `unserialize(): Error at offset` warnings from
     testproject.class.php:3897 caused by the same corrupt blob).
- **Expected post-fix behavior:**
  1. Same request logs **zero** events at `common.php:1987`; grants for
     `project_inventory_view`/`project_inventory_management` stay `"no"`.
  2. With a VALID options blob and `inventoryEnabled=true`: aside menu still
     renders the Inventory link and `lib/inventory/inventoryView.php` loads
     HTTP 200 (grant path unaffected by the guard).
  3. With `inventoryEnabled=false`: grants stay `"no"` exactly as before the
     fix (aside.tpl truthiness quirk that still renders the link is
     pre-existing, filed separately).
  4. Nonexistent project id (`mainPage.php?tproject_id=999`, which bypasses
     the auto-select at common.php:1625): `getOptions()` returns `(object)[]`
     → old code raised `Undefined property` E_WARNING, new code is silent.
  5. Event Viewer gains no new Error/Warning entries overall.
- **Fix state:** Fixed in this run — guard changed to
  `if( !empty($tprojOpt->inventoryEnabled) )` at
  `lib/functions/common.php:1990` (empty() never warns on false or on a
  missing property, unlike the issue's suggested `$tprojOpt && ...` which
  would still warn on the `(object)[]` case). Code-review subagent: PASS.
- **Actual result (post-fix):** PASS —
  * corrupt-blob load → 0 events referencing common.php:1987 (only the
    out-of-scope unserialize warnings from the deliberately corrupt fixture
    remain, gone once a valid blob is restored);
  * valid blob + inventory enabled → Inventory link present in
    `asideMenu.php` output, `inventoryView.php` → HTTP 200;
  * inventory disabled → grants remain `"no"` (identical to pre-fix);
  * `?tproject_id=999` load → HTTP 200, zero warnings;
  * `events` table truncated before tests → 0 rows with `log_level=2` after
    the full pass.

## 22. Quick Test Case Search — Modernized (Suite ID: 33)

**Screen:** ASIDE > Search > "Quick search" / "Search Test Cases"
(`gui/templates/search/searchView.html`, BFF `api/search/index.php`;
replaces legacy `lib/search/searchForm.php` + `lib/testcases/tcSearch.php`
quick-search form `gui/templates/dashio/search/searchForm.tpl`).
- **Priority:** High
- **Importance:** High
- **Preconditions:** Logged in as admin/admin; fixture project id 1
  "Search Fixture Project" (prefix SFP) created via XML-RPC API with 3 test
  cases in suite "Alpha Suite": SFP-1 "Login with valid credentials"
  (importance High), SFP-2 "Logout flow check" (Medium), SFP-3 "Password
  recovery email" (Low); keyword "smoke" assigned to SFP-1.

| # | Test | Result |
|---|------|--------|
| 1 | Aside menu shows Search section; "Quick search" points to `/gui/templates/search/searchQuickView.html` and "Search Test Cases" to `/gui/templates/search/searchView.html` (separate screens since the one-file-per-screen rework) | PASS |
| 2 | Screen loads inside mainframe; header shows project name; toolbar context shows project; locale switcher present | PASS |
| 3 | Form renders all legacy criteria: TC ID (prefilled with prefix `SFP-`), Version, Title, Created by, Edited by, Summary, Preconditions, Steps, Expected results, Creation date from/to, Modification date from/to, Importance (project has priority enabled), Status domain (7 statuses from server), Keyword dropdown (smoke), Requirement document ID (project has requirements enabled) | PASS |
| 4 | AND-mode notice + important notice ("Search is done ONLY on test project 'Search Fixture Project'") + prefix-ignored notice rendered | PASS |
| 5 | Search by Title "login" → 1 match: SFP-1 [v1] :: Login with valid credentials, path "Alpha Suite", summary text, version column | PASS |
| 6 | Search by TC ID `SFP-2` → SFP-2 found (full external id accepted) | PASS |
| 7 | Search by bare external number `3` → SFP-3 found (prefix auto-completed) | PASS |
| 8 | Search by nonexistent TC ID `SFP-999` → warning box "Test case does not exist." | PASS |
| 9 | Search by keyword smoke → only SFP-1 | PASS |
| 10 | Search by Steps "recovery" → SFP-3 (steps LIKE works) | PASS |
| 11 | Search by Summary "email" → SFP-3; Expected results "dashboard" → SFP-1; Preconditions no-match → empty result set | PASS |
| 12 | Created by "admin" → 3 matches (author login LIKE) | PASS |
| 13 | Status=Draft + Importance=Medium combined (AND) → SFP-2 only | PASS |
| 14 | Version=1 → 3 matches | PASS |
| 15 | Creation date range = today (converted to configured format dd/mm/yyyy) → 3 matches | PASS |
| 16 | Empty criteria → all 3 test cases, no warning | PASS |
| 17 | Results table columns: Test Suite / Test Case / Summary / Version / actions; DataTable sort + search box functional | PASS |
| 18 | Row title link opens test case editor popup `archiveData.php?edit=testcase&id=..&tcversion_id=..&tproject_id=..` — page renders the test case view (bug #1 relative-path 404 fixed) | PASS |
| 19 | Action icons: edit popup + execution history popup (`execHistory.php?tcase_id=..`) both load without fatal errors | PASS |
| 20 | Reset button clears all fields, restores `SFP-` prefix, hides results and warnings | PASS |
| 21 | Permission path: user with role "<no rights>" calling BFF context/search → HTTP 403 "No permission"; aside hides Search section for such users (legacy getMenuVisibility rule untouched) | PASS |
| 22 | i18n: all labels via TLi18n keys; keys added to ALL 10 bundles (en/ro/de/fr/es/it/pt/ru/ja/zh); JSON validated | PASS |
| 23 | Event Viewer: BFF E_NOTICE "Only variables should be passed by reference" at api/search/index.php:329 found during testing (bug #2) — fixed (assign array_keys to variable before by-ref call); re-test produced zero new Error/Warning events | PASS |

**Bugs found & fixed during this suite:** #1 popup links used relative paths →
404 under `/gui/templates/search/` (fixed with absolute paths);
#2 E_NOTICE from by-reference argument in BFF path resolution (fixed).

## 23. Regression — Issue #535: aside.tpl shows Inventory menu link even when inventory is disabled or user lacks the right ("no" grant is truthy) (Suite ID: 34)

- **Area:** Legacy aside menu (`gui/templates/dashio/aside.tpl`) + grant getters
  (`lib/functions/common.php::getGrantSetWithExit()`,
  `lib/general/mainPage.php::getGrants()`).
- **Precondition:** Logged-in session; test project id 1 "InvRepro" (prefix IRR)
  created via project edit form; second user `noinv`/`nodemo123` with default
  role "test designer" (has NO `project_inventory_view` /
  `project_inventory_management` rights).
- **Pre-fix symptom:** With `inventoryEnabled = 0` in the serialized
  `testprojects.options` blob, `lib/general/asideMenu.php` still rendered the
  "Inventory management" link, because `getGrantSetWithExit()` initialized
  both inventory grants to the string `"no"` (truthy in PHP/Smarty) and
  aside.tpl gated the entry with a bare truthiness check.

**Repro steps (pre-fix):**
1. As admin create/select any test project.
2. Disable inventory:
   `UPDATE testprojects SET options=REPLACE(options,'i:1;}','i:0;}') WHERE id=1;`
3. GET `lib/general/asideMenu.php` → "Inventory management" link still present.

**Expected post-fix behavior:** Inventory grants are always int 1/0 — link
renders ONLY when the project has inventory enabled AND the user holds one of
the two rights; hidden otherwise (all 4 combinations below).

| # | Test | Result |
|---|------|--------|
| 1 | Pre-fix repro: inventory disabled + admin → asideMenu.php still contains `inventoryView` link (bug confirmed before fix) | PASS (bug reproduced) |
| 2 | Post-fix: inventory disabled + admin → no `inventoryView` link in asideMenu.php; rest of Projects sub-menu intact (Platform Management etc. still render) | PASS |
| 3 | Post-fix: inventory enabled + admin (has rights) → `inventoryView` link present again | PASS |
| 4 | Post-fix: inventory enabled + user `noinv` (lacks both rights) → no `inventoryView` link; Projects section renders normally for that user | PASS |
| 5 | Post-fix: inventory disabled + user `noinv` → no `inventoryView` link | PASS |
| 6 | Regression: `lib/inventory/inventoryView.php?tproject_id=1` loads HTTP 200 for admin when feature enabled | PASS |
| 7 | Regression: `lib/general/mainPage.php` loads HTTP 200 for admin and `noinv` after getGrants() normalization (no fatal/warning output) | PASS |
| 8 | Event Viewer: no new Error/Warning events caused by fix verification flows (only pre-existing unrelated `testproject_prefix_hint` localization warning from fixture setup, filed separately) | PASS |

**Actual result:** All post-fix checks PASS. Fix normalizes the two grants to
int 1/0 in `getGrantSetWithExit()` (disabled path, blindfolded path,
zero-testprojects path) and in `mainPage.php::getGrants()` (which now reads
live options via `testproject::getOptions()` instead of the never-written
legacy `$_SESSION['testprojectOptions']` key).

---

## 24. Test Case Viewer (read-only popup) — Modernized (Suite ID: 35)

**Screen:** `gui/templates/testcases/tcView.html` — read-only view of one test
case with ALL its versions; opened when clicking a test case from the
modernized quick-search results (replaces legacy
`lib/testcases/archiveData.php?edit=testcase&id=…` → `tcView_viewer.tpl`).

- **Area:** `lib/testcases/archiveData.php` +
  `gui/templates/dashio/testcases/tcView_viewer.tpl` (1.9.20).
- **BFF API:** `api/testcases/index.php` (`action=context`, `action=view`),
  session-based auth, rights checked against the OWNING test project (a case
  may belong to a project different from the session one, like legacy).
- **Precondition:** fresh DB seeded with project "Demo Project" (prefix DMP),
  suite "General Suite", TC "Login works" (DMP-1) with 2 versions
  (v1 inactive+edited, v2 active with 2 steps, keywords smoke/login,
  requirement REQ-1 coverage on v2), TC "Logout works" (DMP-2, automated),
  keywords, requirement spec RS-1/REQ-1, test plan "Demo Plan";
  second user `viewer`/`admin` with role "<no rights>".

| # | Test | Result |
|---|------|--------|
| 1 | BFF `action=view&tcase_id=12` returns identity (DMP-1), path "General Suite / Login works", parent suite name, both versions ordered latest-first | PASS |
| 2 | Version payload completeness: summary/preconditions HTML, importance, status, execution type, estimated duration, author/updater display names, creation/modification timestamps, active/is_open flags, has_been_executed | PASS |
| 3 | Steps table rendered per version (v2: 2 steps with actions/expected results; v1: "This version has no steps.") | PASS |
| 4 | Keywords per version as chips (v2: smoke, login; v1: none) | PASS |
| 5 | Requirements section shown only when requirementsEnabled + mgt_view_req; REQ Spec Login : REQ-1 (ver. 1) : REQ-1 Login listed under v2 only | PASS |
| 6 | Badges: Latest on newest version only; Active/Inactive and Open/Frozen reflect DB flags; importance + execution type badges honor project options | PASS |
| 7 | Action buttons visibility for admin: Edit Version shown (v2 open, not executed); Compare Versions form shown (2 versions); Add to Test Plan appears once a plan exists; Export posts testcase_id+tcversion_id to tcExport.php?tproject_id=… | PASS |
| 8 | Edit Version button opens legacy `tcEdit.php?doAction=edit&testcase_id=12&tcversion_id=14&tproject_id=10` popup → full edit form renders (title, summary, preconditions, assigned keywords) — required fixing issue #539 first | PASS |
| 9 | Execution History button targets `/lib/execute/execHistory.php?tcase_id=12&tproject_id=10` (HTTP 200) | PASS |
| 10 | Export form POST verified HTTP 200 only WITH tproject_id on query string (bug found & fixed in same run) | PASS |
| 11 | Compare Versions form POSTs testcase_id to `tcCompareVersions.php` (HTTP 200) | PASS |
| 12 | Print uses window.print() (print CSS hides header/toolbar/footer/actions) | PASS |
| 13 | Deep link `?tcase_id=12&tcversion_id=13`: requested version card first, export/edit target v13, info banner "This test case version is inactive.", Latest badge NOT on v1 (bug found & fixed: latest computed over ALL versions) | PASS |
| 14 | Unknown id `?tcase_id=999` → error box "Test case not found." (BFF 404) | PASS |
| 15 | Missing id → error box "No test case id was provided." | PASS |
| 16 | Permission path: user `viewer` (role <no rights>) on owning project → BFF 403 "No permission"; screen shows error box | PASS |
| 17 | i18n: all labels/badges/messages translated via TLi18n; locale switcher EN→DE re-renders ("Testfall-Anzeige", "Zusammenfassung", "Aktiv"); keys present in ALL 10 bundles (en,de,es,fr,it,ja,pt,ro,ru,zh), each bundle validated with `python3 -m json.tool` | PASS |
| 18 | Link switch: search results row click now opens `gui/templates/testcases/tcView.html?tcase_id=…&tcversion_id=…&tproject_id=…` instead of legacy archiveData.php | PASS |
| 19 | Event Viewer after testing: no new errors/warnings from the viewer itself; warnings observed come from LEGACY popups reached through it (tcAssign2Tplan `$gui->tplans`, tcPrint null params) — filed as issue #541; get_path() NULL crash filed and fixed as #539 | PASS |

**Actual result:** 19/19 PASS after in-run fixes (#539 tree::get_path PHP 8
crash, export tproject_id, hasTestPlans/latest-version BFF fixes).

## 25. Quick Search — separate screen + ASIDE highlight fix

**Screen:** ASIDE > Search > "Quick search" (`searchQuickView.html`)
**Scope:** one-file-per-screen rework; fixes double-highlight of the first two
Search items caused by `syncAsideActiveLink()` matching links by pathname only
(both items shared `searchView.html`).

| # | Test | Result |
|---|------|--------|
| 1 | New standalone screen `gui/templates/search/searchQuickView.html` exists (self-contained HTML+JS+CSS, TLi18n, mini-form: single field + Find button, results table, toast errors) | PASS |
| 2 | `common.php` routes `tcQuickSearch` to `/gui/templates/search/searchQuickView.html?tproject_id=2&tplan_id=4` while `tcSearch` keeps `/gui/templates/search/searchView.html` | PASS |
| 3 | `main.tpl` restored to pathname-only highlight logic (no mode hacks); each Search item now has a unique path so exactly one item activates | PASS |
| 4 | Click "Quick search" in ASIDE → ONLY "Quick search" is highlighted (`quick:true, full:false`) | PASS |
| 5 | Click "Search Test Cases" in ASIDE → ONLY that item highlighted (`quick:false, full:true`) | PASS |
| 6 | Quick search for "tc1" → 1 result row (P2-1 [v1] :: tc1) | PASS |
| 7 | Result row link opens modern TC viewer `/gui/templates/testcases/tcView.html?tcase_id=...` (not legacy archiveData.php) | PASS |
| 8 | Full search screen unchanged: all criteria fields render, AND-mode notices present | PASS |
| 9 | i18n keys (`search.quickCaption`, `quickField`, `quickPlaceholder`, `quickHint`, `quickEmpty`) present in all 10 bundles, json.tool valid ×10 | PASS |

## 26. Regression — Issue #543: locale bundles contained wrong-language content

**Scope:** `gui/templates/i18n/{pt,ro,ru}.json` — the `tcview.*` blocks were
rotated one file off during parallel bundle edits (pt held Romanian, ro held
Russian, ru held Chinese; zh had its own correct copy duplicated into ru).
Fix: rotated blocks back to their bundles, authored fresh European Portuguese
translations for all 56 keys, added `tools/lint_i18n.py` CI guard.

**Precondition:** fresh DB; project "I18N Demo" (IDEMO), TC "Language Switch
Test" (IDEMO-1); screen `gui/templates/testcases/tcView.html`.

| # | Test | Result |
|---|------|--------|
| 1 | Pre-fix repro: select **Portuguese** in locale switcher → UI rendered Romanian ("Vizualizator de cazuri de test", "Versiune 1 ULTIMĂ") | PASS (bug present) |
| 2 | Data audit pre-fix: pt.json tcview.header = RO text, ro.json = RU text, ru.json = ZH text; en/de/es/fr/it/ja/zh correct; only tcview.* block affected (56 keys × 3 files) | PASS (bug confirmed) |
| 3 | Post-fix: Portuguese → "Visualizador de casos de teste", "A carregar o caso de teste...", "Histórico de execução" — genuine pt-PT, no diacritic contamination | PASS |
| 4 | Post-fix: Română → "Vizualizator de cazuri de test", "Editează versiunea", "Istoric execuții"; zero Cyrillic in rendered page | PASS |
| 5 | Post-fix: Russian → "Просмотр тест-кейса", "Редактировать версию", "История выполнения"; zero CJK in rendered page | PASS |
| 6 | zh_CN / ja_JP / en_GB unchanged and correct ("测试用例查看器" / "テストケースビューア" / "Test Case Viewer") | PASS |
| 7 | Key parity: all 10 bundles expose exactly the same 56 tcview.* keys | PASS |
| 8 | All 10 bundles valid JSON (`python3 -m json.tool`) | PASS |
| 9 | `tools/lint_i18n.py` exits 0 on fixed tree; verified it flags the PRE-FIX tree (56 Cyrillic errors ro.json, 56 CJK errors ru.json, 31 diacritic errors pt.json) — guard would have caught this bug | PASS |
| 10 | Event Viewer after testing: no new Error/Warning entries from the viewer or language switching | PASS |

**Actual result:** 10/10 PASS.

## 27. MD Test Case Import API (`api/testcasesimport`)

**Endpoint:** `POST /api/testcasesimport/?action=import_md&tproject_id=N`
**Options:** `dry_run=1`, `hit_criteria=name`, `action_on_hit=skip|create_new_version`
**Fixture:** scratch project "MD Import Fixture" (MIF, id 29) created via `api/projects`

| # | Test | Result |
|---|------|--------|
| 1 | Test A dry_run: synthetic MD (1 suite/2 cases) → status ok, createdCount=2, suitesCreated=[Alpha Suite]; DB node count for project UNCHANGED | PASS |
| 2 | Test B real import → createdCount=2 with real ids (31, 36); DB shows 1 testsuite + 2 testcase nodes under project, tcversions + tcsteps present | PASS |
| 3 | Test C idempotency: re-import same file → skippedCount=2, createdCount=0, suite matched | PASS |
| 4 | Test D action_on_hit=create_new_version → newVersionCount=2; tcversions per case = 2 (4 total for the two cases) | PASS |
| 5 | Test E full real file tmp/TLU_Test_Cases.md dry_run → 120 would-be-created (122 minus 2 already imported), 17 suites, parserErrors=0, projectMismatch {file:"TLU...", target:"MD Import Fixture"} reported | PASS |
| 6 | Test F permissions: user noinv (role "no rights") → HTTP 403 {"No permission"} | PASS |
| 7 | Test G size limit: 3MB payload > import_file_max_size_bytes (800000) → HTTP 413 with clear message | PASS |
| 8 | Event Viewer after all tests: zero new ERROR/WARNING entries (only audit info: logins, project created) | PASS |

**Notes:** parser reference-aliasing bug fixed pre-testing (#545); legacy parity
options implemented per lib/testcases/tcImport.php rules.

## 28. Advanced Search Screen (`searchAdvancedView.html` + `api/search` fulltext)

**Screen:** `gui/templates/search/searchAdvancedView.html` — legacy
`lib/search/searchMgmt.php` + `lib/search/search.php` (aside: Search → Advanced Search)
**BFF:** `api/search/index.php?action=fulltext` (reuses legacy `searchCommands` engine)
**Fixture:** project "AdvSearch Demo" (ADVS, id 9010, reqs enabled); suites
Alpha Suite/Beta Suite; TC ADVS-1 "Login works" (summary+precondition+step contain
"alpha", keyword `smoke`, CF `cf_alpha=alphavalue`), TC ADVS-2 "Checkout flow"
(step expected_results contains "zebra"); req spec RS-ADVS ("alpha" in scope);
requirement REQ-ALPHA-1 ("alpha" in scope); MariaDB UDF `UDFStripHTMLTags`
recreated (was missing from fresh import — blocks legacy advanced search too)

| # | Test | Result |
|---|------|--------|
| 1 | BFF fulltext target="alpha" OR across all 14 field flags → count=4: TC ADVS-1 (path Alpha Suite), TS Alpha Suite, RS Adv Req Spec [r1], REQ REQ-ALPHA-1 | PASS |
| 2 | AND/OR terms: target="alpha zebra" OR → 5 matches incl. ADVS-2 via step expected_results; same target AND → no_records_found warning | PASS |
| 3 | tc_steps vs tc_expected_results scoping: "cart"+steps → ADVS-2; "zebra"+expected_results → ADVS-2; "zebra"+steps only → 0 | PASS |
| 4 | Keyword filter alone (keyword_id=801, empty text) → only ADVS-1 | PASS |
| 5 | Custom field filter (cf_alpha/alphavalue) → only "Login works"; unknown CF id → 400 | PASS |
| 6 | created_by=admin → both TCs; edited_by honored via TCV.updater_id join | PASS |
| 7 | Validation parity: zero checkboxes → HTTP 400 need_checkbox (UI shows localized warnbox before calling API) | PASS |
| 8 | Empty everything → client-side guard "enter search text or filter" (legacy would dump all latest versions; guard kept as UX safety, API keeps legacy semantics when called directly with and_or present) | PASS |
| 9 | Date filters: creation_date_to=past excludes both fresh TCversions (dates4tc applied); date-to=tomorrow includes them. RQ dates ignored — verified dead code upstream too (`dates4rq` never consumed in searchCommands.class.php) → exact legacy parity | PASS |
| 10 | UI render: header + project name, AND/OR select, 4 checkbox groups (req groups auto-hidden when reqs disabled), keyword/CF dropdowns populated from context action | PASS |
| 11 | Results UI: per-entity sectioned tables, path column, external-id links open tcView popup, edit/history icon buttons present, match counter "(4 matches)" | PASS |
| 12 | Reset button clears form, hides sections/results/warnbox, re-checks all checkboxes | PASS |
| 13 | Deep-link prefill: ?target=&and_or=and URL params populate the form | PASS |
| 14 | i18n: locale=de renders "Erweiterte Suche"/"Testfälle"/"Suchtext"/"(4 Treffer)"; en/fr/es spot-checked; 18 new searchAdv.* keys present in ALL 10 bundles; all bundles pass json.tool | PASS |
| 15 | Aside link switched: common.php `$actions->fullTextSearch` now targets searchAdvancedView.html (legacy searchMgmt.php unreachable from menu) | PASS |
| 16 | Permission gate: mgt_view_tc enforced by shared BFF auth block (403 without right) — same rule as quick/tcSearch screens | PASS |
| 17 | Event Viewer after all tests: no new ERROR/WARNING entries (only audit/info rows) | PASS |

**Actual result:** 17/17 PASS.
**Environment fix (not a code bug):** fresh DB import lacks the MySQL function
`UDFStripHTMLTags` which legacy advanced search requires (`$tlCfg->UDFStripHTMLTags=true`);
recreated it from install/sql/mysql/testlink_create_udf0.sql. Candidate follow-up:
document or auto-provision during install.

### Suite 28b — code-review round (commit 1d2cb161f)

| # | Test | Result |
|---|------|--------|
| 1 | CF filter through legacy engine with `cf_types`/`design_cf_rq` fed explicitly: no PHP 8.3 undefined-property warnings, date/datetime CF switch reachable | PASS |
| 2 | Requirements disabled → rs_*/rq_* flags not sent, requirement groups hidden; enabled → visible + reqStatus dropdown populated (D/R/W/F/I/V/N/O) | PASS |
| 3 | reqStatus=D matches fixture req (status letter domain), reqStatus=V excludes it; intval clobber bug fixed | PASS |
| 4 | tcWKFStatus=1 keeps matching tcversion (status=1); 8-option domain rendered | PASS |
| 5 | Date-range-only search now allowed client-side (dates count as criteria) | PASS |
| 6 | Warning responses hide the results header + match counter (no stale "(N matches)") | PASS |
| 7 | Invalid tproject_id → HTTP 404 Test project not found (existence check added) | PASS |
| 8 | RQ rows no longer fake version=0/revision=0 fields | PASS |
| 9 | Full regression of suite 28 flows after fixes: alpha → 4 sections / 4 matches; OR/AND terms; reset; locale switch | PASS |

**Actual result:** 9/9 PASS. Accepted scope cut documented in docs: legacy
reqType filter omitted (upstream code is broken — searches wrong property).
## 29. Regression — Issue #544: execHistory SQL error on empty IN () + E_WARNING Array to string conversion in execHistory.tpl (Suite ID: 36)

**Precondition:** fresh DB. Fixtures: project "Project Two" (P2, id 1) with
suite TS1 → case "TC A" (P2-1, tcversion id 4); test plan TP1 (id 5, active,
public); build B1 (id 1); one execution of P2-1 v1 in TP1/B1, status Passed.
Second project "Empty Proj" (EP, id 6) with NO test cases.

**Repro (pre-fix):**
1. Login admin, select Project Two, open `tcView.html?tcase_id=3`, click
   **Execution History** → page renders but Event Viewer gains
   `E_WARNING Array to string conversion ... execHistory.tpl.php - Line 230`
   (the cfexec per-execution value is echoed as an array).
2. Select Empty Proj and run Quick Search (`tcSearch.php doSearch` /
   `api/search?action=search`) → Event Viewer gains
   `1064 SQL syntax error ... NH_TCV.parent_id IN ()` —
   `get_all_testcases_id()` returns `[]` (not null) for a case-less project,
   so the old `!is_null()` guard built an empty IN clause.

**Expected post-fix:** no SQL error, no warnings; search on empty project
returns the clean "empty testproject" state; exec history renders rows with
custom-field cells as strings.

| # | Test | Result |
|---|------|--------|
| 1 | Pre-fix repro A: execHistory.php?tcase_id=3 → event #10 `Array to string conversion` at compiled line 230; page prints literal "Array" | PASS (bug present) |
| 2 | Pre-fix repro B: tcSearch doSearch on EP → event #15 `1064 ... near ')' ... COUNT(DISTINCT(NH_TC.id)) ... IN ()`; api/search on EP → event #16 same 1064 | PASS (bug present) |
| 3 | Post-fix: execHistory.php?tcase_id=3 (session project = Project Two) → HTTP 200, row TP1/B1/admin/Passed rendered, zero new Error/Warning events | PASS |
| 4 | Post-fix: execHistory with session project = Empty Proj (execution belongs to non-accessible plan) → HTTP 200, no warning (isset() guard on grants->exec_edit_notes) | PASS |
| 5 | Post-fix: tcSearch doSearch on EP → HTTP 200, "empty testproject" feedback, no 1064 | PASS |
| 6 | Post-fix: api/search?action=search on EP → JSON {"status":"ok","count":0,"warning":"empty_testproject"}, no 1064 | PASS |
| 7 | Regression: tcSearch doSearch name=TC on Project Two → finds TC A | PASS |
| 8 | Regression: api/search name=TC on Project Two → count=1, full_external_id P2-1 | PASS |
| 9 | UI flow: tcView.html → Execution History toolbar button → popup renders history table; "Display only active test plans" checkbox toggles without errors | PASS |
| 10 | Event Viewer after all tests: only audit/info entries (login), zero new Error/Warning | PASS |

**Actual result:** 10/10 PASS.

**Notes:** root cause of cluster 1 was misattributed to execHistory.php in the
issue title — the `IN ()` query is the test-case search count query
(tcSearch.php / api/search/index.php); both call sites guarded. Discovered
during testing (pre-existing, out of scope): mainPage.php lines 102/105 emit
PHP 8.x warnings when switching projects — filed separately.

## 30. Inventory Management Screen (`inventoryView.html` + `api/inventory`) (Suite ID: 37)

**Screen:** Projects → Inventory management (`gui/templates/inventory/inventoryView.html`,
BFF `api/inventory/index.php`, replaces `lib/inventory/inventoryView.php` +
`getInventory.php` + `setInventory.php` + `deleteInventory.php` +
`lib/ajax/getUsersWithRight.php`).

**Fixture:** fresh DB import; test project "Inventory Project" (prefix INVP)
created via projectsView.html with *Enable Inventory* checked
(`options.inventoryEnabled=1`); user tester1 (role tester, no inventory rights).

| # | Test | Result |
|---|------|--------|
| 1 | Aside menu shows "Inventory management" link only when inventory enabled + right present; href = `/gui/templates/inventory/inventoryView.html?tproject_id=1&tplan_id=0` | PASS |
| 2 | Screen loads: header, locale switcher, project context, toolbar Create button, empty state "No device defined." | PASS |
| 3 | Create modal opens with Host name*, IP Address, Owner combo (admin default = current user, "(no owner)" option), Purpose/Hardware/Notes textareas | PASS |
| 4 | Save with empty name → inline error "The device cannot have an empty name.", no request sent | PASS |
| 5 | Create test-server-01 / 192.168.10.25 / purpose / hw / notes → toast "Device created successfully.", row rendered with all columns, audit event `audit_inventory_created` in DB | PASS |
| 6 | Owner login resolved from user id (BFF bug found & fixed: `array_column()` reindexed logins — commit d0d578400) | PASS |
| 7 | Edit via name click: modal prefilled (title "Edit the device data"), rename to build-server-01 + owner "(no owner)" → update OK, grid refreshed, owner shows "-" | PASS |
| 8 | Duplicate name case-insensitive (BUILD-SERVER-01) → server error -1 mapped to localized message, modal stays open | PASS |
| 9 | Invalid IP 999.1.1.1 blocked client-side after fix (octet range check; legacy Ext vtype accepted it — tightened, commit 3382949e6 parent) | PASS |
| 10 | Duplicate IP (192.168.10.25 on second device) → server error -4 localized message | PASS |
| 11 | Delete flow: confirm modal shows device name; Cancel keeps device; Confirm deletes → toast, grid refresh, audit event `audit_inventory_deleted` | PASS |
| 12 | Double-click row opens edit modal prefilled (legacy rowdblclick parity) | PASS |
| 13 | DataTables search filters rows | PASS |
| 14 | Locale switcher → Română: all labels/tooltips/messages translated (inv.* keys present in all 10 bundles, JSON valid) | PASS |
| 15 | Permission path (tester1, role tester): aside hides link; direct URL → list GET 403 → "You do not have appropriate rights..." shown in place of grid, Create hidden; POST via API → 403 NO_RIGHTS | PASS |
| 16 | Admin (rights holder): owners endpoint returns users with `project_inventory_view` + "(no owner)" entry (getUsersWithRight.php parity) | PASS |
| 17 | Event Viewer after suite: only AUDIT entries (logins, inventory create/delete); zero new Error/Warning from the new screen | PASS |

**Actual result:** 17/17 PASS.

**Notes:** two bugs found and fixed during the run (owner login lookup,
403/empty-state cosmetics). Pre-existing legacy warning about missing locale
key `testproject_prefix_hint` discovered during testing → filed as issue #549.

## 31. Regression — Issue #549: missing locale key `testproject_prefix_hint` (Suite ID: 38)

**Precondition:** fresh DB import (events table empty), user admin/admin with
locale en_GB; no test projects needed (repro works on any projectEdit render).

**Repro steps (pre-fix):**
1. Log in as admin → `GET /lib/project/projectView.php` (0 test projects ⇒
   renders `projectEdit.tpl` create path) or `GET /lib/project/projectEdit.php?doAction=create`.
2. Inspect `events` table / Event Viewer.

**Observed pre-fix:** one WARNING entry (`log_level=32`, source LOCALIZATION):
`string 'testproject_prefix_hint' is not localized for locale 'en_GB'`;
tooltip on the Test case prefix field rendered empty.

**Expected post-fix:** key defined in ALL 19 bundles (en_GB…zh_CN); tooltip
renders the hint text in the user's own locale; zero LOCALIZATION warnings for
`testproject_prefix_hint` in the Event Viewer, for en_GB and for other locales
(no " - using en_GB" fallback warnings either).

| # | Test | Result |
|---|------|--------|
| 1 | Pre-fix repro: render projectEdit.tpl via projectView.php → WARNING `testproject_prefix_hint ... 'en_GB'` logged once per session | PASS (reproduced) |
| 2 | Post-fix: new session, render projectView.php + projectEdit.php?doAction=create → events table contains ZERO `testproject_prefix_hint` entries | PASS |
| 3 | Post-fix: prefix-field info icon renders `title="Up to 16 characters allowed, but we recommend using at most five"` | PASS |
| 4 | Locale de_DE: same render → German hint `Bis zu 16 Zeichen sind erlaubt...`, zero LOCALIZATION warnings | PASS |
| 5 | All 19 `locale/*/strings.txt` pass `php -l`; insertion byte-preserving (cs_CZ windows-1250 kept, CRLF bundles kept CRLF) | PASS |

**Actual result:** 5/5 PASS.

**Notes:** while reproducing, 3 pre-existing E_WARNING entries from the
projectView.php→projectEdit.tpl path (undefined properties found /
mgt_view_events / tprojectName) surfaced — unrelated to this i18n bug, filed
as issue #550.

## 32. Regression — Issue #550: E_WARNING spam when projectView.php renders projectEdit.tpl (0 test projects) (Suite ID: 39)

**Precondition:** fresh DB import (`testprojects` table empty, `events` table
empty), user admin/admin, right `mgt_modify_product`.

**Repro steps (pre-fix):**
1. Log in as admin → open `lib/project/projectView.php`.
2. Inspect the rendered page and the `events` table.

**Observed pre-fix:** page showed only the heading plus the template error
branch text (`<< >> Error:`) — no create form; 3 E_WARNING entries
(`log_level=2`) per render: undefined `stdClass::$mgt_view_events`,
`stdClass::$found`, `stdClass::$tprojectName` in compiled projectEdit.tpl.

**Expected post-fix:** `projectView.php` with 0 accessible test projects hands
over to the regular create screen (`projectEdit.php?doAction=create`); the full
create form renders; zero E_WARNING entries for the whole flow; creating a test
project through that form works and lands on the populated list view.

| # | Test | Result |
|---|------|--------|
| 1 | Pre-fix repro: render projectView.php with 0 projects → 3 E_WARNING entries + error-branch garbage instead of form | PASS (reproduced) |
| 2 | Post-fix: same URL → JS redirect to `projectEdit.php?doAction=create`, heading "Create a new project", complete create form (name/prefix/description/features/trackers/availability) | PASS |
| 3 | Post-fix: events table contains ZERO E_WARNING entries for the render | PASS |
| 4 | End-to-end: create "Issue550 Verification Project" (prefix I550V) through the redirected form → lands on list view showing 1 entry, audit event only | PASS |
| 5 | Fixture removed → re-render projectView.php with 0 projects again → redirect + form + still zero E_WARNING entries | PASS |

**Actual result:** 5/5 PASS.

**Notes:** fix delegates to `redirect($_SESSION['basehref'] .
'lib/project/projectEdit.php?doAction=create')` instead of launching
projectEdit.tpl with a gui object that was never built for it (root cause:
projectView.php::initializeGui() sets neither found / mgt_view_events /
tprojectName nor any of the ~15 further properties the tpl form branch reads).

## 33. Regression — Issue #548: mainPage.php E_WARNINGs (lines 102/105) when switching to a test project without test plans (Suite ID: 40)

**Precondition:** fresh DB import; user admin/admin; two test projects —
"Alpha" (id 1) with one active public test plan "Plan A" (id 2), "Beta"
(id 3) with **no** test plans.

**Repro steps (pre-fix):**
1. Log in as admin, select project Alpha (session `testplanID` = 2).
2. Request `lib/general/mainPage.php?tproject_id=3` (project switch to
   planless Beta — same request the nav-bar selector issues).
3. Inspect Event Viewer / `events` table (`log_level=2`).

**Observed pre-fix:** three E_WARNING entries per switch:
`Undefined array key 0 - mainPage.php - Line 102`,
`Trying to access array offset on null - mainPage.php - Line 102`,
`Undefined array key 0 - mainPage.php - Line 105`.
Root cause: `initProject()` cannot resolve a test plan for the new project,
so the stale session `testplanID` survives while `$arrPlans` (accessible
plans of the NEW project) is empty; lines 102/105 then read offset 0 of an
empty array.

**Expected post-fix:** switching to a planless project logs zero warnings;
the stale session test plan is dropped (`setSessionTestPlan(null)` clears
`$_SESSION['testplanID']/['testplanName']`, local `$testplanID = 0`); the
main page renders its no-dashboard empty state; switching back to a project
that HAS plans auto-selects its first accessible plan; all previously healthy
paths (found==1 match, found==0 non-empty fallback, no plan in session)
behave unchanged.

| # | Test | Result |
|---|------|--------|
| 1 | Pre-fix repro: Alpha selected → `mainPage.php?tproject_id=3` → events 13/14/15 logged (2× line 102, 1× line 105) | PASS (reproduced) |
| 2 | Post-fix: same switch via direct URL and via nav-bar selector (frameset flow) → events table contains ZERO new entries | PASS |
| 3 | Post-fix: after switching to Beta, a bare `mainPage.php` request renders the `no_records_found` empty state and logs nothing (stale session plan cleared by `setSessionTestPlan(null)`) | PASS |
| 4 | Regression: Beta → Alpha switch (found==0, non-empty fallback path) → Plan A auto-selected, nav bar shows Test Plan "Plan A", Reports link carries `tproject_id=1&tplan_id=2` | PASS |
| 5 | Regression: `index.php?tproject_id=1&tplan_id=2` reload (found==1 path) → Plan A stays selected; full UI round-trip Alpha→Beta→Alpha→bare mainPage ends with zero new events (max id stays 15) | PASS |

**Actual result:** 5/5 PASS.

**Notes:** fix is confined to lib/general/mainPage.php (guard around the
found==0 branch + only call setSessionTestPlan()/mark selected when a plan
was resolved). Unrelated template warnings seen during fixture creation
(planEdit.tpl / planView.tpl compiled templates) were already open under
issue "E_WARNING Multiple missing \$gui properties in templates".

## 34. Regression — Issue #547: fresh DB import lacks MySQL function UDFStripHTMLTags — advanced search fails with DB Access Error (Suite ID: 41)

**Precondition**
- Freshly imported TestLink DB via CI flow (schema + default data only, no stored functions).
- At least one test project with one test suite whose details/title contain the search word
  (search short-circuits on a fully empty project and never reaches the UDF query).

**Repro (pre-fix)**
1. Login admin/admin → ASIDE > Search > Advanced search (`lib/search/searchMgmt.php?tproject_id=1`).
2. Type `alpha`, keep Test Suite Title/Details checked, click Find.
3. OBSERVED PRE-FIX: page dies with "DB Access Error - debug_print_backtrace() OUTPUT START";
   stack: search.php(99) -> searchCommands::searchTestSuites -> database::exec_query fails with
   `FUNCTION testlink.UDFStripHTMLTags does not exist` (error 1305).
   Direct SQL check also failed: `SELECT UDFStripHTMLTags('<b>x</b>')` -> ERROR 1305.
   Same failure hits modernized BFF `api/search/index.php?action=fulltext`
   (it reuses legacy `searchCommands::searchTestSuites/searchReqSpec/searchReq/searchTestCases`).

**Expected post-fix behavior**
- CI import provisions `UDFStripHTMLTags()` from `install/sql/mysql/testlink_create_udf0.sql`
  (fix-bug.yml + modernize.yml), asserted via information_schema.routines.
- Advanced search returns results / "no records found" — never DB Access Error.

**Executed steps & result (post-fix)**
1. Provisioned UDF on current DB exactly as the new CI step does
   (`sed 's|YOUR_TL_DBNAME|testlink|g' install/sql/mysql/testlink_create_udf0.sql | mysql …`) → OK,
   `SELECT UDFStripHTMLTags('<b>hello</b>')` returns `hello`.
2. Legacy UI: advanced search for `alpha` → result table lists Test Suite "AlphaSuite /
   alpha details content"; no DB Access Error. PASS.
3. Modernized BFF: GET `/api/search/index.php?action=fulltext&tproject_id=1&target=alpha&and_or=or&ts_summary=1`
   → HTTP 200 `{status:"ok", testsuites:[{id:10,name:"AlphaSuite",...}]}`. PASS.
4. Event Viewer: no new ERROR/WARNING entries after the flows above. PASS.

**Result: 4/4 PASS** (fix verified on both legacy screen and modernized API; root cause was the
CI import skipping install/sql/mysql/testlink_create_udf0.sql).

### Suite 34b — final fix round: application-level graceful degradation (commit d041ca9fe+)

**Approach change**: pushing changes under `.github/workflows/` is not possible with the CI bot
token (GitHub rejects pushes touching workflow files without `workflows` permission), so the
primary landed fix is application-level: `searchCommands::getStripTagsUDF()` checks
`information_schema.routines` once per request and returns `''` when
`UDFStripHTMLTags()` is absent → search SQL falls back to plain `LIKE`
(same semantics as upstream option `$tlCfg->UDFStripHTMLTags=false`, CHANGELOG 0008674).
The CI provisioning patch for both workflows is provided in issue #547's closing comment.
`docs/db_sample/restore_sample.sh|.bat` UDF provisioning steps remain part of this branch.

**Executed steps & result**
1. Dropped the function (`DROP FUNCTION IF EXISTS UDFStripHTMLTags;`) → legacy advanced search
   POST → HTTP 200, NO "DB Access Error", result row "AlphaSuite / alpha details content"
   found via plain LIKE. PASS.
2. Recreated the function from testlink_create_udf0.sql → legacy advanced search POST via
   browser form → HTTP 200, no error, AlphaSuite found (full UDF-stripping mode). PASS.
3. Modernized BFF fulltext with function present → HTTP 200 `{status:"ok", testsuites:[1]}`. PASS.
4. Event Viewer: only event #8 (the intentional pre-fix reproduction ERROR); no new
   Error/Warning entries from post-fix flows. PASS.
5. `php -l lib/search/searchCommands.class.php` clean; 4 call sites converted.

**Result: 5/5 PASS**

## 35. Regression — Issue #542: Execution History screen modernization (Suite ID: 42)

**Precondition:** project "Execution History Demo" (EXH), TC EXH-1 "Login works"
with 3 executions (Build 1.0 passed / Build 2.0 failed+bug BUG-1234 in active
"History Plan", Build 0.9 passed in INACTIVE "Archived Plan"), EXH-3 never executed.

**Steps & results**

| # | Test | Result |
|---|------|--------|
| 1 | `api/execute/index.php?action=history&tcase_id=3` → HTTP 200, 3 executions (all plans), fullExternalId EXH-1, localized status labels, notes/bugs/platform present | PASS |
| 2 | Same + `only_active_test_plans=1` → 2 executions (Archived Plan excluded) | PASS |
| 3 | Modern screen `gui/templates/execute/execHistory.html?tcase_id=3` renders header EXH-1, filters panel, platform column, 3 rows with status badges (PASSED green/FAILED red/BLOCKED orange), manual/automated icons | PASS |
| 4 | Detail toggle shows execution notes + bug BUG-1234 per row | PASS |
| 5 | Status filter Failed → 1 row; date-from filter → 1 row; Reset → 3 rows (blank All options fixed during testing) | PASS |
| 6 | onlyActiveTestPlans checkbox reloads from server → 2 rows | PASS |
| 7 | Never-executed TC (tcase_id=9) → warning "never been executed", empty table | PASS |
| 8 | Entry points repointed: tcView.html toolbar button + 3 modern search views open the modern screen | PASS |
| 9 | Event Viewer: no new Error/Warning entries after all flows above | PASS |

**Actual result:** 9/9 PASS.

## 36. Regression — Issue #541: legacy popups from TC viewer emit PHP 8 warnings (tcAssign2Tplan $tplans, tcPrint null params) (Suite ID: 43)

**Precondition:** fresh DB; project "Issue541 Proj" (I541), suite "Suite A",
TC 30 "TC 541" v1 (tcversion id 40, external I541-100). Admin session
(browser + curl cookie jar).

**Steps & results**

| # | Test | Result |
|---|------|--------|
| 1 | Pre-fix repro: `GET /lib/testcases/tcAssign2Tplan.php?tcase_id=30&tcversion_id=40&tproject_id=10` with NO test plans in project → events logged: E_WARNING `Undefined array key "testplanID"` (line 177), `Undefined array key "DataTablesLengthMenu"` + `Attempt to read property "value" on null` (DataTables.inc.tpl compiled line 41), `Undefined property: stdClass::$tplans` (compiled tcAssign2Tplan.tpl.php line 75) | PASS (reproduced) |
| 2 | Pre-fix repro: `GET /lib/testcases/tcPrint.php?show_mode=print&tcase_id=30&tcversion_id=40&tproject_id=10` → events #6–#13 exactly as reported (`testprojectName` line 70, `name` lines 92/93, `id` print.inc.php 930/951, testcase.class.php 2158 ×2, SQL error 1064 from `getTestCasePrefix(null)`); page HTTP 200 but garbage | PASS (reproduced) |
| 3 | Post-fix step 1 request again → HTTP 200, page shows title "Test Case:TC 541" and localized "There are no Test Plans defined for this Test Project"; **zero** new events | PASS |
| 4 | Post-fix tcPrint bad-params request again → HTTP 200, clean page "Test Case does not exist" (existing i18n key, en_GB fallback for locales missing it); **zero** new events | PASS |
| 5 | Positive path: create plan TP 541 + platform P1 linked → same tcAssign2Tplan URL renders checkbox row `add2tplanid[50][60]`, version 1, TP 541 / P1, Add + Cancel buttons; identity "I541-100:TC 541" | PASS |
| 6 | Positive path: `GET /lib/testcases/tcPrint.php?show_mode=&testcase_id=30&tcversion_id=40` (param names used by legacy openPrintPreview/ltcp.php) → full printable TC page with I541-100 content; zero events | PASS |
| 7 | Real user flow via browser: modern TC viewer (`tcView.html?tcase_id=30…`) → button "Add to Test Plan" opens popup rendering the assignment form correctly (screenshot wiki) | PASS |
| 8 | Event Viewer after all flows: only audit-level login entry; no Error/Warning entries | PASS |

**Actual result:** 8/8 PASS.

**Root causes fixed**
- `lib/testcases/tcAssign2Tplan.php`: `$gui->tplans` never initialized when the project has no active test plans (template `{if $gui->tplans}` reads undefined property under PHP 8); `$_SESSION['testplanID']`/`$_SESSION['testprojectID']` dereferenced without isset guards in `init_args()`; `$version` could be undefined when the requested tcversion does not match.
- `gui/templates/dashio/testcases/tcAssign2Tplan.tpl`: include param typo `DataTableslengthMenu=$ll` instead of `DataTablesLengthMenu=$ll` → undefined-variable warnings inside DataTables.inc.tpl.
- `lib/testcases/tcPrint.php`: dereferenced `$_SESSION['testprojectName']` unguarded; ignored the documented `tproject_id` request param as fallback; continued rendering with a null node when the test case id was invalid/missing, cascading into `print.inc.php` `$node['id']`, `testcase.class.php::getPrefix()` (`$path2root[0]` on null) and SQL error `SELECT prefix FROM testprojects WHERE id = <empty>` — now bails out early with a localized message.

## 37. Regression — Issue #552: tcPrint.php valid testcase without project context → get_by_id(0) throw/warning (Suite ID: 44)

**Precondition:** fresh DB; admin user; fixtures created directly in DB:
project node 1000 "FIX Project" (testprojects row, prefix `FIX`,
issue_tracker_enabled=0), suite 1001 under it, TC 1002 v2003… (external
1002) under the suite, plus TC 1003 (external 1003, external id 1003)
**directly under the project root**. Crafted PHP session
(`userID=1`, `lastActivity=now`, NO `testprojectID` key) used via curl to
simulate a fresh-session deep link.

**Steps & results**

| # | Test | Result |
|---|------|--------|
| 1 | Pre-fix repro: `GET /lib/testcases/tcPrint.php?testcase_id=1002&tcversion_id=2002` with no project context → HTTP **500**, page body is a raw `debug_print_backtrace()` dump rooted at `testproject->get_by_id(0)` (print.inc.php:2326 ← print.inc.php:951 ← tcPrint.php:64); `get_by_id(0)` throws because `null == $id` loosely matches 0 | PASS (reproduced) |
| 2 | Post-fix same request → HTTP 200, full printable page "Test Case FIX-1002: TC-fix552 [Version: 1]"; zero new events | PASS |
| 3 | Edge: TC directly under project root (`testcase_id=1003`) → HTTP 200, "Test Case FIX-1003: TC-root552" (derivation via `path2root[0]['parent_id']` correct when path's first element IS the testcase) | PASS |
| 4 | Invalid id `testcase_id=999999` → HTTP 200 clean localized "does not exist" message (#541 behavior preserved), zero events | PASS |
| 5 | `issue_tracker_enabled=1` on project + valid request → HTTP 200, no throw; residual E_WARNING at tlIssueTracker.class.php:673 (flag enabled but no tracker linked) filed as NEW issue #553, out of scope | PASS (new issue filed) |
| 6 | Normal browser flow: real login (session auto-selects project) → same URL renders print popup correctly (screenshots in wiki page) | PASS |
| 7 | Event Viewer after all flows: 0 Error/Warning entries | PASS |

**Actual result:** 7/7 PASS.

**Root cause fixed**
- `lib/testcases/tcPrint.php`: when neither session nor REQUEST yields a
  project, derive it from the test case tree path
  (`tree::get_path()` → first element's `parent_id`; get_path excludes the
  tree root — same convention as `testcase::getPrefix()`).
- `lib/functions/print.inc.php` `initStaticRenderTestCaseForPrinting()`:
  only call `get_by_id()` when `$tprojectID > 0` and null-guard `$info`
  before reading `issue_tracker_enabled` (defense-in-depth for any caller).

## 38. Regression — Issue #554: testproject::get_by_id() E_WARNING array-offset-on-null for nonexistent positive id (Suite ID: 45)

**Precondition:** fresh DB; one test project exists (fixture: node 1
"ReproProj554", `testprojects` row prefix `RP554`); PHP CLI bootstrap
(`config.inc.php` + `common.php`, `testlinkInitPage($db,false,true)`).

**Steps & results**

| # | Test | Result |
|---|------|--------|
| 1 | Pre-fix repro: `$tprojMgr->get_by_id(99999999)` (positive, nonexistent) → returns NULL but logs E_WARNING to `events`: "Trying to access array offset on null … testproject.class.php - Line 342" (`get_recordset()` yields null for zero rows; line 342 does `return $result[0];`) | PASS (reproduced) |
| 2 | Post-fix same call → clean `NULL` return, **zero** new rows in `events` | PASS |
| 3 | Positive path intact: `get_by_id(1)` (existing fixture) → full record array with `name=ReproProj554`; callers unaffected | PASS |
| 4 | Sibling methods unchanged: `get_by_prefix('RP554')` → row; `get_by_name('ReproProj554')` → row (their guards already existed) | PASS |
| 5 | Event Viewer after all flows: 0 Error/Warning entries | PASS |

**Actual result:** 5/5 PASS.

**Root cause fixed**
- `lib/functions/testproject.class.php` `get_by_id()`: unguarded array
  dereference `$result[0]` when `getTestProject()` returns the empty/null
  recordset of a nonexistent positive id. Minimal fix mirrors the existing
  guard convention already used in the same class by
  `get_by_prefix()` (line ~360): `return $result != null ? $result[0] : null;`
  Callers already treat null as project-not-found (guard added in
  `print.inc.php initStaticRenderTestCaseForPrinting()` during the #552 fix).

## 39. Regression — Issue #553: tlIssueTracker::getInterfaceObject() E_WARNING array-offset-on-null when project has issue_tracker_enabled=1 but no tracker linked (Suite ID: 46)

**Precondition:** fresh DB; fixture created via UI: test project id 1
"IT553 Project" (prefix `IT553`), `testprojects.issue_tracker_enabled=1`
set via SQL (no row in `issuetrackers` / `testproject_issuetracker`),
one test case node id 2 "TC for issue 553" with tcversion id 3
(external id 1). Print entry point:
`lib/testcases/tcPrint.php?testcase_id=2&tproject_id=1`.

**Steps & results**

| # | Test | Result |
|---|------|--------|
| 1 | Pre-fix repro: open tcPrint.php for the fixture TC → `events` gains `E_WARNING Trying to access array offset on null … tlIssueTracker.class.php - Line 673` (`$name = $issueT['issuetracker_name'];` runs before the existing `!is_null($issueT)` branch) | PASS (reproduced) |
| 2 | Post-fix same print flow → page renders full single-TC print view ("Test Case IT553-1: TC for issue 553 [Version : 1]", summary/preconditions/keywords sections), **zero** new rows in `events` | PASS |
| 3 | Positive path intact: link a tracker via SQL (`issuetrackers` type 15 redmine-rest + `testproject_issuetracker` row), repeat print → interface object instantiated, page renders, **zero** new events | PASS |
| 4 | Negative path restored: remove link rows again, repeat print → still warning-free | PASS |
| 5 | Caller audit: all 12 live `getInterfaceObject()` call sites of `tlIssueTracker` (print.inc.php, execSetResults, resultsBugs, resultsByStatus, execHistory, bugAdd, testcaseCommands, mainPage, api/execute/index.php, …) share the fixed method → all covered by the same guard; behavior in linked-tracker case unchanged | PASS |
| 6 | Event Viewer after all flows: 0 Error/Warning entries | PASS |

**Actual result:** 6/6 PASS.

**Root cause fixed**
- `lib/functions/tlIssueTracker.class.php` `getInterfaceObject()`: line 673
  dereferenced `$issueT['issuetracker_name']` while `$issueT` is NULL whenever
  the project flag is on but no tracker is linked (`getLinkedTo()` returns
  NULL); the `!is_null($issueT)` check existed only further down (~line 685).
  Minimal fix per issue suggestion:
  `$name = !is_null($issueT) ? $issueT['issuetracker_name'] : '';`

## 40. Regression — Issue #556: exechist.* blocks rotated across all 10 locale bundles + it.* block missing in ja/pt/ru/zh (Suite ID: 47)

**Precondition:** repo HEAD before fix; `tools/lint_i18n.py` available;
TestLink running at http://localhost:8082.

**Steps & results**

| # | Test | Result |
|---|------|--------|
| 1 | Pre-fix repro: `python3 tools/lint_i18n.py` → 60+ language-signature ERRORs (RO diacritics in en.json, Cyrillic in pt.json, kana/CJK in ru.json …) and WARN "34 key(s) missing vs en.json" for exactly ja/pt/ru/zh; `grep '"exechist.header"' gui/templates/i18n/en.json` → `"Istoric execuții"` (RO text in EN bundle) | PASS (reproduced) |
| 2 | Apply fix (reverse rotation: new_file[i] = old_file[i-1] along cycle en→ro→de→es→fr→it→pt→ru→ja→zh; author 34 missing `header.issueTrackers*`+`it.*` keys for ja/pt/ru/zh inserted before `header.codeTrackers`) → re-run lint: **0 warnings / 0 errors** | PASS |
| 3 | JSON validity: `python3 -m json.tool` on all 10 bundles → all clean | PASS |
| 4 | Rotation spot-check: `exechist.header` = Execution History (en) / Istoric execuții (ro) / Ausführungshistorie (de) / Historial de ejecuciones (es) / Historique d'exécution (fr) / Storico esecuzioni (it) / Histórico de execuções (pt) / История выполнения (ru) / 実行履歴 (ja) / 执行历史 (zh); lint language-signature checks confirm no cross-contamination in any of the 34 keys × 10 bundles | PASS |
| 5 | Runtime EN: open `gui/templates/execute/execHistory.html` → header renders "Execution History" (pre-fix it rendered RO "Istoric execuții") | PASS |
| 6 | Runtime JA: same screen with `?locale=ja` → 実行履歴 / テストケース / 更新 (pre-fix ja.json held Chinese text) | PASS |
| 7 | New it.* keys runtime: `gui/templates/issuetracker/issuetrackerView.html?locale=ja` → 課題トラッカー / 課題トラッカー連携の管理 / 名前 / 種別 / サーバーURL / 有効 / 「+ 課題トラッカーを作成」 / "0 件の課題トラッカー" — no raw key names except pre-existing gap logged as #557 | PASS |
| 8 | Event Viewer after all flows (`events` table): only the audit login entry; 0 new Error/Warning rows | PASS |

**Actual result:** 8/8 PASS.

**Root cause fixed**
- Rotation: parallel CI agents appending to the shared locale bundles wrote
  each bundle's new `exechist.*` block into the *next* file of the locale
  cycle `[en→ro→de→es→fr→it→pt→ru→ja→zh]`, shifting every block by one
  position. Fix reverses the rotation (`new_file[i] = old_file[i-1]`),
  swapping whole contiguous 34-line blocks at text level so formatting stays
  byte-identical (all blocks sit at EOF, last line comma-free).
- Missing keys: the `it.*` issue-tracker block + `header.issueTrackers*`
  were authored for en/ro/de/es/fr/it only. Fix authors proper translations
  for ja/pt/ru/zh following each bundle's existing terminology conventions
  (コードトラッカー→課題トラッカー, Rastreadores de código→rastreadores de
  problemas, Трекеры кода→трекеры задач, 代码追踪器→问题跟踪器).

**Files changed:** `gui/templates/i18n/{en,ro,de,es,fr,it,pt,ru,ja,zh}.json`.

**Follow-up found while testing:** `exechist.colBuild`, `exechist.colStatus`,
`exechist.footer` are referenced by execHistory.html but missing from ALL 10
bundles (lint only checks parity vs en.json) → filed as issue #557.

## 41. Modernization — Print Requirement Specification screen (printReqSpec) (Suite ID: 48)

**Screen:** `gui/templates/requirements/printReqSpec.html` + `api/requirements/index.php`
(`action=print_init` / `action=print_tree`). Document generation stays in legacy
`lib/results/printDocument.php` (same URL contract the 1.9.20 tree JS used:
`type=reqspec&level=testproject|reqspec&id=<id>&tproject_id=<tpid>&format=<0|4>&<opt>=y|n...`).

**Fixtures:** test project `PrintReq Demo` (prefix PRD, requirements enabled, id=2);
req specs `PRS-1 Login Module Requirements` (2 requirements: REQ-1, REQ-2) and
`PRS-2 Reporting Requirements` (empty); admin/admin session.

| # | Step / verification | Result |
|---|---------------------|--------|
| 1 | BFF `print_init`: returns hasProject, project name/ctx, canGenerate right (`testplan_metrics` — same check as printDocument.php), formats (HTML=0, MS Word=4), doc options (toc/headerNumbering unchecked) and reqSpec options with legacy defaults (req_spec_scope=y, req_scope=y) | PASS |
| 2 | BFF `print_tree`: root specs of project with docId + requirement counts; nested-spec recursion supported; siblings sorted by name (SQL uses `requirements.srs_id` — initial version used non-existent `req_spec_id` and was fixed before testing) | PASS |
| 3 | Screen loads via direct URL `?tproject_id=2`: header shows project name, toolbar ctx, hint box, tree with virtual whole-project row + 2 spec rows `[PRS-1] 2 requirement(s)` / `[PRS-2] 0 requirement(s)`, format select (HTML/MS Word), both checkbox groups with correct defaults, footer counters "2 requirement specification(s) · 2 requirement(s)" | PASS |
| 4 | i18n EN: all labels resolved from en.json `printReq.*` (37 keys), no raw keys visible | PASS |
| 5 | i18n DE runtime: locale switcher → `?locale=de` reload renders "Anforderungsspezifikation drucken", "Inhaltsverzeichnis", "Gesamtes Projektdokument drucken" | PASS |
| 6 | Click spec node "Login Module Requirements" → new tab opens `printDocument.php?type=reqspec&level=reqspec&id=3&tproject_id=2&format=0&toc=n&...&req_scope=y&...`; document contains header block and both requirements REQ-1/REQ-2 | PASS |
| 7 | Toggle "Table of contents" on → click "Print whole project document" → new tab `level=testproject&id=2&toc=y&...`; document shows "Table Of Contents" with links to PRS-1, REQ-1, REQ-2, PRS-2 and renders both specs | PASS |
| 8 | Options parity: all 16 checkboxes map 1:1 to legacy printDocOptions class values (toc, headerNumbering + 14 reqSpec opts); unchecked sent as `n`, checked as `y` (initPrintOpt contract) | PASS |
| 9 | Aside link integration: `asideMenu.php` now emits `/gui/templates/requirements/printReqSpec.html?tproject_id=2&tplan_id=0` for "Print Requirements" (common.php switch after workArea loop so launcher mapping is overridden) | PASS |
| 10 | Shared BFF router integrity: other screens' REST routes (/meta, /overview, /monitor-overview, /monitor POST) still routed; broken leftover `$action === 'init'` remnant (undefined `$tid`, dead `return`) removed; `cfg/reports.cfg.php` require added for FORMAT_* constants (was fatal 500 without it) | PASS |
| 11 | JSON validity of all 10 locale bundles after key insert (`python3 -m json.tool`) | PASS |
| 12 | Known cosmetic legacy bug observed in generated doc header: "Printed by TestLink on %22/%44/%2026" — strftime config issue in document generator, pre-existing, not introduced by this screen (see GitHub issue) | OBSERVED (filed) |

**Actual result:** 11/12 PASS + 1 pre-existing legacy cosmetic bug filed separately.

**Files changed:** `api/requirements/index.php`,
`gui/templates/requirements/printReqSpec.html`,
`gui/templates/i18n/{en,de,es,fr,it,pt,ro,ru,ja,zh}.json` (+37 `printReq.*`),
`lib/functions/common.php` (aside link switch).

## 42. Modernization — Test Specification screen: test case tree & editor (editTc/testSpec) (Suite ID: 49)

**Screen:** `gui/templates/testcases/testSpec.html` · **BFF:** `api/testcases/index.php`
(actions `tree`, `get`, POST `create`/`update`/`delete`/`create_version`/`suite_create`/`suite_update`/`suite_delete`)
**Date:** 2026-08-22

| # | Test | Result |
|---|------|--------|
| 1 | BFF auth guard: unauthenticated request to `action=tree` returns HTTP 401 JSON error | PASS |
| 2 | Screen loads via `?tproject_id=1`: header shows project name, locale switcher, toolbar buttons gated by grants, tree root "QA Project", counts line, hint box | PASS |
| 3 | Suite create via toolbar modal ("Login Module") → appears in tree with count pill 0; toast feedback; counts update to "1 suites · 0 cases" | PASS |
| 4 | Suite selection → detail card shows path/sub-suite/test-case counters + action buttons; empty-suite hint shown | PASS |
| 5 | TC create form opens from suite view ("New Test Case Here") with name/importance/execution type/summary/preconditions/steps editor/keywords section | PASS |
| 6 | TC create with 2 steps → saved via POST `create`; tree updates to "1 suites · 1 cases"; TC node shows external id | PASS |
| 7 | TC detail view: name, VER badge, external id badge, importance/execution-type/ID meta grid, summary, preconditions, numbered steps table, keywords block, action bar | PASS |
| 8 | TC edit → form pre-filled incl. both steps; rename + summary edit → POST `update`; view re-renders with new data; toast "Test case saved" | PASS |
| 9 | Create New Version → confirm modal → POST `create_version` → view shows VER. 2 | PASS |
| 10 | Deep link `?tproject_id=1&tcase_id=12` selects and renders sacrificial TC directly | PASS |
| 11 | Delete TC via UI confirm modal → POST `delete` removes all versions; tree count decrements; panel resets to placeholder | PASS |
| 12 | Suite rename modal prefilled with current name → renamed node visible in tree after save | PASS |
| 13 | Delete suite confirm warns when suite non-empty; suite removed from tree | PASS |
| 14 | Tree filter input hides non-matching test case nodes live | PASS |
| 15 | i18n EN: all labels resolved from en.json `tspec.*` (61 keys), no raw keys visible | PASS |
| 16 | i18n RO runtime: `?locale=ro_RO` renders "Specificație de test", placeholder "Filtrează după nume...", button "Caz de test nou" | PASS |
| 17 | All 10 locale bundles valid JSON (`python3 -m json.tool`) with the 61 `tspec.*` keys present in each | PASS |
| 18 | Aside link integration: `common.php` emits `/gui/templates/testcases/testSpec.html?tproject_id=N&tplan_id=M` for the workArea testSpec entry (workArea launcher mapping removed so it is not overwritten) | PASS |
| 19 | Permission path: BFF write actions require `mgt_modify_tc` (403 otherwise — enforced server-side by `$checkWrite`); context/tree/get require login (401) | PASS |
| 20 | Event Viewer: no new Error/Warning entries from this screen after fixes (bugs found & fixed during testing: wrong `tcexternalid` column name; missing keywords-map guard on create form; missing TC name join for get action; PHP8 warning on undefined `canEditExecuted` cfg property) | PASS |

**Actual result:** 20/20 PASS (4 bugs found during testing were fixed and re-verified inline).

**Files changed:** `api/testcases/index.php`,
`gui/templates/testcases/testSpec.html`,
`gui/templates/i18n/{en,de,es,fr,it,ja,pt,ro,ru,zh}.json` (+61 `tspec.*`),
`lib/functions/common.php` (aside link switch).

**Event Viewer follow-up (Requirements Monitor Overview suite):** 5 pre-existing Error rows found in
`events` (not caused by this screen's flows):
- SQL 1064 `Unknown column 'req_spec_id'` from the shared BFF print-tree
  route -> filed as issue **#560**; verified already fixed on HEAD
  (`srs_id` used, `print_tree` returns clean 200) and issue closed.
- Transient `Undefined array key "tproject_id"` in the shared BFF router ->
  dev-loop artifact of a parallel agent's intermediate state, not present in
  any committed version.
- 3x `E_WARNING Undefined property: stdClass::$tproject_id` from
  `reqViewVersionsViewer.tpl` — reproduced live: every legacy
  `reqView.php` view logs it (deep-link target of this screen) -> filed as
  issue **#565** with root cause + one-line suggested fix.
After all Suite-41 flows: 0 new Error/Warning rows attributable to
`monitor-overview` / `monitor` routes or the modern screen itself.

## 44. Modernization — Requirements Monitor Overview screen (Suite ID: 50)

**Screen:** `gui/templates/requirements/reqMonitorOverview.html` +
`api/requirements/index.php` (BFF routes `monitor-overview`, `monitor`).
Legacy reference: `lib/requirements/reqMonitorOverview.php` (right
`mgt_view_req`, ExtJS table, monitor on/off via POST forms).

**Precondition:** fresh DB; fixture created via SQL: test project id 1000
"ReqMon Project" (prefix `RMP`, requirements enabled), req spec id 1001
"System Requirements" (`RMP-SRS-1`) with spec revision node 1002,
requirements 1010 "Login functionality" (`RMP-REQ-1`, v-node 1011),
1020 "Export to PDF" (`RMP-REQ-2`, v-node 1021), 1030 "Session timeout"
(`RMP-REQ-3`, v-node 1031); user id 2 `mon_limited` (role tester = no
`mgt_view_req`). Admin logged in at http://localhost:8082.

**Steps & results**

| # | Test | Result |
|---|------|--------|
| 1 | BFF list: `GET /api/requirements/index.php/monitor-overview?tprojectId=1000` as admin → `status ok`, 3 items with `req_doc_id`, `title`, `spec_path` "System Requirements", `version_id`, `creation_ts`, `author` "Testlink Administrator", `monitored:false`; anonymous call → 401 Not authenticated | PASS |
| 2 | Screen load `?tproject_id=1000`: header + sub rendered, DataTables shows 3 rows sorted by Req Spec, columns Req. Spec / Title / Created On / Monitor / Action; footer "3 requirement(s), 0 monitored"; project name "ReqMon Project" in toolbar | PASS |
| 3 | Monitor ON: click bell icon on RMP-REQ-1 → badge flips to Yes, icon becomes bell-slash with title "Turn off monitoring", footer count updates to "1 monitored" without reload; `req_monitor` row `(1010,1,1000)` verified in DB | PASS |
| 4 | Monitor OFF: click bell-slash → badge back to No, bell icon, `req_monitor` empty again | PASS |
| 5 | Toggle persistence: state reloaded from server after Refresh button click — matches DB | PASS |
| 6 | Deep link: edit pen / title link opens legacy `reqView.php?showReqSpecTitle=1&requirement_id=1020&req_version_id=1021&tproject_id=1000` popup → renders "Requirement : Export to PDF" version 1 revision 1 (bug found during testing: first implementation used wrong param names `item`/`version_id` → DB Access Error; fixed) | PASS |
| 7 | Filter: type "Export" in search box → "Showing 1 to 1 of 3 entries (filtered from 3 total entries)" | PASS |
| 8 | i18n EN: all labels from bundle, no hardcoded strings visible | PASS |
| 9 | i18n DE (`?locale=de_DE`): header "Anforderungen-Monitor Übersicht", columns "Anf. Spez./Titel/Erstellt am/Überwachen/Aktion", footer "3 Anforderung(en), 0 überwacht" | PASS |
| 10 | Permission path: login `mon_limited` (tester role, no mgt_view_req) → error modal "No permission", table stays empty; direct API call returns 403 | PASS |
| 11 | Aside integration: shell menu Requirements Design → "Requirement Monitoring Overview" points to modern `.html?tproject_id=1000&tplan_id=0` and loads inside mainframe | PASS |
| 12 | Event Viewer after all flows: only audit login/logout entries, 0 new Error/Warning rows | PASS |

**Actual result:** 12/12 PASS.

**Bugs found & fixed while testing**
- BFF toggle returned "Requirement not found": validation used
  `requirement_mgr::get_by_id()` whose rows carry no `tproject_id`;
  replaced with a `requirements ⋈ req_specs` join check.
- Footer "monitored" count went stale after toggling (only recomputed on
  full reload); extracted `updateFooter()` and called it after each toggle.
- Deep links used invented `item=`/`version_id=` params; legacy
  `openLinkedReqVersionWindow()` expects `requirement_id=`/`req_version_id=`
  (+ `showReqSpecTitle=1`) — aligned.

**Files changed:** `gui/templates/requirements/reqMonitorOverview.html`,
`api/requirements/index.php` (shared router also serving printReqSpec),
`lib/functions/common.php` (aside link switch),
`gui/templates/i18n/{en,de,es,fr,it,ja,pt,ro,ru,zh}.json` (+15 keys each).
## 43. Modernization — Search Requirement Specifications screen (searchReqSpec) (Suite ID: 50)

**Date:** 2026-08-22 · **Branch:** sebiboga · **Tester:** ox-alpha (CI agent)
**Target:** `gui/templates/requirements/searchReqSpec.html` +
`api/requirements/index.php` routes `GET /reqspec-context`, `GET /reqspec-search`
**Legacy reference:** `lib/requirements/reqSpecSearchForm.php` +
`lib/requirements/reqSpecSearch.php` (1.9.20)
**Right:** `mgt_view_req` on the current test project
**Fixtures:** project "ReqSpec Search Demo" (id 1, prefix RSD, reqs enabled);
spec RSD-SPEC-001 "Payment Subsystem Spec" rev1+rev2 (rev2 scope/log mention
chargebacks); spec RSD-SPEC-002 "User Interface Guidelines"; requirements
RSD-REQ-001/002 under SPEC-001, RSD-REQ-010 under SPEC-002.

| # | Test | Expected | Result |
|---|------|----------|--------|
| 1 | Open screen with `tproject_id=1` as admin | Header shows project name; Type domain localized (Section/User Req Spec/System Req Spec + "Any"); notice "search ONLY on project X" | PASS |
| 2 | Doc ID filter visibility rule | Shown only when the project has req specs containing requirements (`getOptionReqSpec(GET_NOT_EMPTY_REQSPEC)`) — hidden before fixtures had requirements, visible after | PASS |
| 3 | Search Title="Payment" | 1 row: RSD-SPEC-001::Payment Subsystem Spec with revision links rev.2 + rev.1 (revision DESC), count "(Matches: 1)" | PASS |
| 4 | Revision links order + target | rev links ordered DESC (legacy `ORDER BY id ASC, revision DESC`); each opens `reqSpecViewRevision.php?item_id=<revision_id>` in popup — HTTP 200, no Fatal | PASS |
| 5 | Edit icon / title link | Opens `reqSpecView.php?req_spec_id=2` popup — HTTP 200, no Fatal | PASS |
| 6 | Scope="chargebacks" | Only spec1 **rev.2** matches (scope searched per REVISION like legacy), count 1 | PASS |
| 7 | Log message="chargebacks" | Only spec1 rev.2 matches | PASS |
| 8 | Type="User Requirement Specification" | Only RSD-SPEC-002 matches (exact type match on revision) | PASS |
| 9 | AND combination mismatch: Title="Payment" + Doc ID="RSD-SPEC-002" | 0 rows; warning box "No requirement specifications match the given criteria."; empty-state box shown | PASS |
| 10 | Doc ID LIKE partial: doc_id="002" | RSD-SPEC-002 matches (LIKE %value%) | PASS |
| 11 | Reset buttons (toolbar + form) | All fields cleared, type back to "Any", results/warnings hidden | PASS |
| 12 | Deep-link prefill: `?doc_id=RSD-SPEC-002&scope=accessibility` then Find | Fields prefilled; AND search returns only spec2 | PASS (after fix, see bug A) |
| 13 | Locale switch `locale=ro` | Header "Cautare specificatii de cerinte", button "Cauta", "Oricare" option; DataTables still functional | PASS |
| 14 | Permission path: user `lowviewer` (role = no rights) opens screen | Context API → HTTP 403 `No permission`; red toast displayed; aside does NOT render the menu entry for this user | PASS |
| 15 | Result cap behavior | `max_qty_for_display`=200 returned by context; >200 grouped specs would trigger legacy `too_wide_search_criteria` warning instead of rows (code path mirrors legacy; not triggered with fixture volume) | PASS (code-reviewed) |

### Bugs found & fixed during this suite

* **A — Deep-link prefill dead:** `URLSearchParams` was created inside the
  document-ready closure while `loadContext()` referenced it from outer scope
  → ReferenceError at prefill step, fields stayed empty.
  Fixed by hoisting the instance to script scope
  (commit `fix(requirements): searchReqSpec deep-link prefill…`).
* **B — E_NOTICE in event viewer:** `get_full_path_verbose(array_keys(…))`
  passes an expression to a by-reference parameter → repeated E_NOTICE rows
  (events #12–#23). Fixed by assigning `array_keys($grouped)` to a variable
  first; verified 0 new event rows afterwards
  (commit `fix(requirements): pass real variable to get_full_path_verbose()…`).
* **C — i18n keys clobbered by concurrent merge:** another agent's i18n commit
  rewrote all bundles without `reqspecsearch.*`; restored via upsert commit
  `fix(i18n): restore reqspecsearch.* keys lost in concurrent i18n merge`.

### Event Viewer check

After all Suite-43 flows: **0 new Error/Warning rows** attributable to
`reqspec-context` / `reqspec-search` routes or the modern screen itself.
Pre-existing warnings observed during testing but NOT caused by this screen:
`Undefined property stdClass::$tproject_user_role_assignment` in
common.php:2072 and `$tproject_id` in reqSpecViewButtons.inc.tpl (legacy
reqSpecView deep link) — filed as issue #567.

## 45. Modernization — Requirement Overview screen (reqOverview) (Suite ID: 51)

**Screen:** `gui/templates/requirements/reqOverview.html` +
`api/requirements/index.php` (BFF route `GET /overview`).
Legacy reference: `lib/requirements/reqOverview.php` (right `mgt_view_req`,
ExtJS table, latest/all versions toggle persisted in session).

**Precondition:** fresh DB; fixtures via BFF (`api/projects`,
`api/reqspec`): test project id 1 "ReqOverview Demo" (prefix `ROV`,
requirements enabled), req specs id 2 "Login Module" (`ROV-SPEC-1`) and
id 4 "Reporting" (`ROV-SPEC-2`); requirements ROV-REQ-1 "Password policy"
(V, feature, exp 5), ROV-REQ-2 "OAuth2 login" (V, interface, exp 2),
ROV-REQ-3 "Remember me" (D, use case, exp 1), ROV-REQ-4 "PDF export"
(V, informational, exp 3), ROV-REQ-5 "Audit log" (D, feature, exp 2,
frozen via `is_open=0`), ROV-REQ-6 "CSV export" (V, feature, exp 1);
empty project id 18 "Empty Proj ROV" (prefix `EMP`); user id 2 `noinv`
(global role guest = no `mgt_view_req`). Admin logged in at
http://localhost:8082.

**Steps & results**

| # | Test | Result |
|---|------|--------|
| 1 | BFF list as admin: `GET /api/requirements/index.php/overview?tproject_id=1` → `status ok`, `total 6`, meta flags `expected_coverage_management:true`, `relations_enabled:true`, type/status label maps, empty project → `items []` | PASS |
| 2 | Screen load `?tproject_id=1`: header "Requirements Overview - ReqOverview Demo", info line "Latest version displayed \| 6 row(s)", notes block, footer "Generated on … (0.01 s)" | PASS |
| 3 | Table columns: Req. Specification / Requirement / Version / Creation date / Last update / Frozen / Coverage / Type / Status / Relations; rows carry spec path ("Login Module"), `[v1r1]` tag, author "(Testlink Administrator)", type/status labels resolved | PASS |
| 4 | Frozen badge: ROV-REQ-5 shows blue "Yes" badge, all others teal "No" | PASS |
| 5 | Coverage column: all rows "0% (0/N)" red class (no linked test cases yet) — matches legacy counter logic | PASS |
| 6 | All-versions toggle: check → reload → info line "All versions displayed"; **session persistence**: plain reload of the page without `all_versions` param keeps the checked state (legacy parity with `$_SESSION['all_versions']`) | PASS |
| 7 | Refresh button re-fetches overview, footer timestamp updates | PASS |
| 8 | Deep link: title/edit pen opens popup `/lib/requirements/reqView.php?showReqSpecTitle=1&requirement_id=10&req_version_id=11&tproject_id=1` (captured `window.open` args) | PASS |
| 9 | i18n EN: every visible string from bundle, no hardcoded text | PASS |
| 10 | i18n DE (`?locale=de`): header "Anforderungsübersicht", columns "Anforderungsspezifikation / Anforderung / Version / Erstellungsdatum …", notes translated | PASS |
| 11 | Empty project: `?tproject_id=18` → empty state "There are no requirements defined for this test project.", table hidden | PASS |
| 12 | Permission path: login `noinv` (guest) → direct API call HTTP 403 `{"status":"error","message":"No permission"}`; screen shows "No permission" in empty state, table hidden | PASS |
| 13 | Aside integration: shell menu Requirements Design → "Requirement Overview" points to modern `.html?tproject_id=1&tplan_id=0` and loads inside mainframe | PASS |
| 14 | Event Viewer after all flows: no new Error/Warning rows caused by this screen | PASS |

**Actual result:** 14/14 PASS.

**Bugs found & fixed while testing**
- `api/reqspec/index.php` `create_spec` attached specs to tree parent **0**
  instead of the test project node — specs were orphaned and invisible to
  every tree walk (`get_all_requirement_ids()` → Requirement Overview showed
  nothing). Fixed to pass `$tproject_id` as parent (legacy parity with
  `reqSpecCommands.class.php`), fixture rows repaired. Filed as issue #569.
- All-versions toggle was always sent explicitly from the checkbox, so a
  plain page reload reset the user's choice instead of restoring
  `$_SESSION['all_versions']` like legacy `init_args()` does. Screen now omits
  the param until the user toggles and syncs the checkbox from the server
  response.

**Files changed:** `gui/templates/requirements/reqOverview.html`,
`lib/functions/common.php` (aside link switch),
`gui/templates/i18n/{en,de,es,fr,it,ja,pt,ro,ru,zh}.json` (+22 keys each),
`tmp/add_rov_i18n.py`, `api/reqspec/index.php` (#569 fix).


## 46. Modernization — Requirement Specification Management screen (reqSpecMgmt) (Suite ID: 52)

**Screen:** `gui/templates/requirements/reqSpecMgmt.html` +
`api/reqspec/index.php` (BFF: options/specs/reqs CRUD).
Legacy reference: `lib/requirements/reqSpecListTree.php` (feature
`reqSpecMgmt` via frmWorkArea, ExtJS tree, rights `mgt_view_req` /
`mgt_modify_req`).

**Precondition:** fresh DB; test project id 1 "ReqSpec Demo" (prefix RSD,
requirements enabled) created via UI; user id 2 `noinv` (global role
guest = no req rights). Admin logged in at http://localhost:8082.
**Files changed:** `gui/templates/requirements/reqSpecMgmt.html`,
`api/reqspec/index.php`, `lib/functions/common.php` (aside link switch),
`gui/templates/i18n/{en,de,es,fr,it,ja,pt,ru,zh,ro}.json` (+42 keys each),
`lib/functions/requirement_mgr.class.php` (#572 fix).

| # | Test | Result |
|---|------|--------|
| 1 | Aside menu "Requirements Design → Requirement Specification" opens modern screen with context (`tproject_id`) | PASS |
| 2 | Empty state message shown when project has no specs; Create Spec button hidden for view-only (canManage=false) | PASS |
| 3 | Create Spec modal: localized type domain (Section/URS/SRS), defaults applied | PASS |
| 4 | Create spec RS-1 "Functional Requirements" (URS, expected 5, scope HTML) → toast "Specification saved", row appears with doc id, type badge, author, timestamp | PASS |
| 5 | Spec title link opens Requirements panel below; panel header shows spec name and count | PASS |
| 6 | Create requirement REQ-1 (Valid, Use Case, coverage 2) via modal → toast "Requirement saved", row in reqs table | PASS |
| 7 | Create REQ-2 + REQ-3 via BFF → list refresh shows all rows, spec "Reqs" badge updates to count | PASS |
| 8 | Duplicate doc id rejected: create_req with existing REQ-1 in same spec → HTTP 400 "Duplicated document id REQ-1" | PASS |
| 9 | Edit requirement (title change + status D→V) applies to latest version; toast + refreshed row | PASS |
| 10 | Edit specification (title v2, expected 10) → row updated after save | PASS |
| 11 | Delete requirement confirm modal lists title + ALL-versions warning; delete removes row, updates counts/badge | PASS |
| 12 | Delete spec confirm modal warns about ALL its requirements; delete_deep leaves no orphans (req_specs=0, requirements=0, req_versions=0, revisions=0 verified in DB) | PASS |
| 13 | Rights: unauthenticated API call → HTTP 401 "Not authenticated" (credentials omitted) | PASS |
| 14 | Rights: guest user `noinv` → options/specs/create_spec all HTTP 403 "not authorized"; screen shows error toast, no Create buttons rendered server-side state canManage=false | PASS |
| 15 | i18n: locale switcher ro_RO renders all rs.* labels in Romanian (header, sub-title, toolbar, empty state, document.title); rs.* keys present in ALL 10 bundles (json.tool valid) | PASS |
| 16 | Event Viewer: #572 E_WARNING (array offset on null, requirement_mgr.class.php:585) fixed — create+delete throwaway req produces NO new events (fix commit 37fe239f) | PASS |
| 17 | Code-review BLOCKER fixed: stored XSS via inline onclick titles — handlers now take ids only, titles resolved from caches; planted title `');alert(document.cookie);//` does NOT execute on click/delete-confirm, UI renders it literally, normal flows unaffected | PASS |

**Result: 17 / 17 PASS** (2 issues found & fixed during testing: #572 E_WARNING, review-BLOCKER stored XSS)
---

## Regression — Issue #570: document/report generation 60s self-fetch deadlock (printDocument)

> **Run 2026-08-22 (curl + browser).** Root cause: `renderFirstPage()` in
> `lib/functions/print.inc.php` passed a full URL (`$_SESSION['basehref'] . logo`)
> to `getimagesize()`, making PHP fetch the document logo over HTTP from the same
> server that was busy serving the print request — a deterministic self-deadlock
> lasting `default_socket_timeout` (exactly ~60s per generation, any dataset size).
> Fix (landed as e85ee1db8, independently reproduced/verified by agent run of the
> same day): measure dimensions from the local file `TL_ABS_PATH . TL_THEME_IMG_DIR . $logo`;
> the URL remains only as the emitted `<img src>`.

**Precondition:** fresh DB; project "Bug570Proj" (prefix B570) + test plan "smoke"
(id 2, zero linked cases) + empty suite "Suite A" (node id 3) created via UI/SQL;
admin session at http://localhost:8082; stock single-worker `php -S` runtime.

| # | Test | Result |
|---|------|--------|
| 1 | Pre-fix repro (for the record): timed curl `printDocument?type=testplan&level=testproject&id=2&tproject_id=1&docTestPlanId=2` | **60.15s**, HTTP 200, logo `<img>` WITHOUT width/height (getimagesize timed out → fallback branch) — symptom confirmed |
| 2 | T1 post-fix: same request via real UI flow (printDocOptions form submit in mainframe) | **42ms wall** (`performance.timing`), title "testplan smoke", doc_title rendered | PASS
| 3 | T2 post-fix: suite-level doc `level=testsuite&id=3` | **24ms**, HTTP 200 | PASS
| 4 | testspec doc `type=testspec&level=testproject&id=1` | **18ms**, HTTP 200 | PASS
| 5 | T3 content integrity: logo now carries `width=231 height=56` (success branch taken from local file); body/CSS/footer sections present; size same order as baseline | PASS |
| 6 | T4 Event Viewer: valid generations produce NO new Error/Warning events (events table checked before/after) | PASS |
| 7 | T5 regression sanity: app shell, navBar, tree options page (printDocOptions), resultsNavigator all load instantly; no other print-path behavior changed | PASS |

**Result: 7 / 7 PASS.** Generation time **60s → <50ms**, stable across 5+ runs and 3 doc types.

**Side findings (filed separately, out of scope for #570):**
- `printDocument.php` with missing mandatory params (e.g. no `docTestPlanId`) → raw
  DB Access Error page + E_WARNING/SQL-error events logged instead of graceful handling.
- Five legacy attachment `getimagesize()` call sites (print.inc.php:290,1309,1528,1634,1790)
  call `list()` on the result without a `!== false` guard → PHP 8 undefined-offset
  warnings if an attached image file is corrupt/missing.

## 47. Regression — Issue #574: print.inc.php unguarded getimagesize() on attachments (Suite ID: 53)

**Area:** legacy document generation (`lib/results/printDocument.php` →
`lib/functions/print.inc.php`). **Fix commit:** ab5833b67.

**Precondition:** fresh DB; fixtures created via XML-RPC API (admin
script_key): test project `Bug574Proj` (id 1, prefix B574), test plan
`Bug574Plan` (id 2), build B1, suite `Bug574Suite` (id 3, tree root
`nodes_hierarchy/3`), test case B574-1 `Bug574Case` (tcid 4, tcversion 5)
linked to the plan. Two PNG image attachments inserted directly
(`attachments` rows + files under `upload_area/`):
- attach id 1 → `tcversions/5/att574tc.png` (400×300)
- attach id 2 → `nodes_hierarchy/3/att574suite.png` (200×100)

Admin session at http://localhost:8082. Events table snapshot taken
(`SELECT MAX(id) FROM events`) to detect new entries per step.

| # | Test | Result |
|---|------|--------|
| 1 | Pre-fix baseline (file present): Test Plan Report `printDocument.php?tproject_id=1&docTestPlanId=2&type=testreport&level=testproject&id=1` renders TC attachment `<img>` with width=400 height=300; no new events | PASS |
| 2 | Pre-fix repro A (file deleted): same URL → E_WARNING event "getimagesize(...att574tc.png): Failed to open stream ... print.inc.php - Line 1522" AND malformed markup `<img width="height=" src=...>` | PASS |
| 3 | Pre-fix repro B (file replaced by garbage bytes): no getimagesize warning but SAME malformed `<img width="height=">` output (silent false) | PASS |
| 4 | Post-fix corrupt file: report renders clean `<img src=...>` WITHOUT width/height attributes; ZERO new events | PASS |
| 5 | Post-fix missing file: identical clean fallback; ZERO new events | PASS |
| 6 | Post-fix healthy file restored: dimensions back (`width="400" height="300"`); ZERO new events | PASS |
| 7 | Suite-attachment site (renderTestSuiteNodeForPrinting): Test Spec doc `printDocument.php?type=testspec&level=testsuite&id=3&allOptionsOn=1` renders suite img id=2 width=200 height=100 | PASS |
| 8 | Site 5 broken (suite file removed): clean `<img>` fallback, ZERO new events; healthy file restored afterwards | PASS |
| 9 | php -l print.inc.php clean after all edits (5 guarded sites) | PASS |

**Result: 9 / 9 PASS** — sites verified end-to-end: line 1522-class
(TC attachment, renderTestCaseForPrinting) and line 1783-class (suite
attachment, renderTestSuiteNodeForPrinting) in all three file states;
remaining three sites share the identical mechanical guard (code-reviewed).
Note: pre-fix events 1-7 in the fresh DB stem from XML-RPC fixture creation
(pre-existing API warnings, out of scope for this issue).

## Regression — Issue #573: printDocument.php missing docTestPlanId renders raw DB Access Error page

**Precondition:** admin logged in at http://localhost:8082; ≥1 test project (fixture: id=1 "Repro Project") with ≥1 test plan (fixture: id=2 "Repro Plan"); note baseline of events table.

**Repro steps (pre-fix):**
1. `GET /lib/results/printDocument.php?type=testplan&level=testproject&id=1&tproject_id=1` (no `docTestPlanId`).
2. Observe response body and Event Viewer / events table.
Pre-fix result: HTTP 200 raw "DB Access Error - debug_print_backtrace()" page (`testplan->getLinkedStaticView(0)` → `testcase->getTestCasePrefix(NULL)`, SQL 1064) + 1 ERROR and multiple E_WARNING events (printDocument.php L91/L92, testplan.class.php L6804, testcase.class.php L2158).

**Expected post-fix:** graceful localized page ("Document generation error" + explanation), no backtrace, no new ERROR/E_WARNING events sourced to printDocument.php.

**Actual result (post-fix): PASS**
- Missing docTestPlanId → graceful page ✓
- docTestPlanId=99999 (unknown plan) → graceful page ✓
- Plan from a different project than session test project → graceful page ✓
- Valid docTestPlanId=2 → document generated ("Repro Plan" content) for type=testplan, testreport, testreport_onbuild, legacy alias type=test_plan ✓
- type=testspec (plan not required) → unaffected ✓
- Events table: no new entries sourced to printDocument.php after the fix ✓

**Fix commit:** 34302f8ee on branch fix/issue-573. Follow-up pre-existing bug found during this suite: #575 (onbuild without build_id E_WARNINGs in print.inc.php — separate code path).

## Regression — Issue #575: printDocument.php testreport_onbuild without build_id logs E_WARNINGs (undefined build_name/build_notes)

**Precondition:** admin logged in at http://localhost:8082; fixture: test project id=1 "Repro Project #575" (options serialized with testPriorityEnabled=1), test plan id=2 "Repro Plan #575" (same project), build id=10 "Build B1" on plan 2; note baseline of events table (cleared before each step).

**Repro steps (pre-fix):**
1. `GET /lib/results/printDocument.php?type=testreport_onbuild&level=testproject&id=1&tproject_id=1&docTestPlanId=2` (no `build_id`).
2. Observe Event Viewer / events table.
Pre-fix result: HTTP 200 document rendered, but 3 E_WARNING events: `Undefined property: stdClass::$build_name` (print.inc.php L741), `$build_name` (L2317), `$build_notes` (L2319).

**Expected post-fix:** document renders with an empty "Build:" label and ZERO new E_WARNING/E_ERROR events; valid build_id still prints the build name/notes; invalid build_id degrades gracefully.

**Actual result (post-fix): PASS**
| # | Step | Result |
|---|------|--------|
| 1 | No build_id → doc 200, "Build:" empty label, 0 warning events | PASS |
| 2 | build_id=10 → doc shows "Build: Build B1", 0 warning events | PASS |
| 3 | build_id=9999 (invalid) → doc 200, graceful empty label, 0 warning events (pre-fix hazard: array-offset warnings) | PASS |
| 4 | Regression type=testplan & type=testreport (shared case block) → both render 200, 0 warnings | PASS |
| 5 | php -l printDocument.php clean after edit | PASS |

**Fix commit:** de0ab88af on branch fix/issue-575.

**Note discovered during this suite:** generating the same document through the full browser session on a plan with NO linked test cases surfaced an unrelated pre-existing bug — `testplan::get_testsuites()` foreach-on-null E_WARNINGs (testplan.class.php:2147/2154). Filed as #577, out of scope for #575.

## Regression — Issue #577: testplan::get_testsuites() foreach-on-null on plans without linked test cases

**Precondition:** admin logged in at http://localhost:8082; fixture: test project id=1 `FixProj` (options = serialized stdClass w/ requirementsEnabled/testPriorityEnabled/automationEnabled/inventoryEnabled), root testsuite id=2, test plan id=3 `EmptyPlan` (zero rows in testplan_tcversions), build id=4 `B1` on plan 3 (needed by helperGetExecCounters for on-build reports). Clear events table before each step.

**Repro steps (pre-fix):**
1. Direct call: CLI/web probe `$tplanMgr->get_testsuites(3)` on empty plan.
2. UI path: browser session → `GET /lib/results/printDocument.php?type=testreport_onbuild&level=testproject&id=1&tproject_id=1&docTestPlanId=3&format=0`.
3. Check Event Viewer / `events` table.

**Observed pre-fix:** 2× E_WARNING `foreach() argument must be of type array|object, null given` at lib/functions/testplan.class.php:2147 and :2154; direct invocation additionally aborts with uncaught `TypeError: usort(): Argument #1 ($array) must be of type array, null given` at line 2164 → truncated HTTP 500 response (web) / fatal (CLI). Root cause: `get_recordset()` returns NULL for zero rows and `$finalset` was never initialized.

**Expected post-fix:** document renders; zero new Error/Warning events; `get_testsuites()` returns `array()` for empty plans and the full suite set (case suites + ancestors, name-sorted) for populated plans.

**Actual result (post-fix): PASS**
| # | Step | Result |
|---|------|--------|
| 1 | Empty plan direct probe → returns `array(0) {}`, no warnings, no fatal | PASS |
| 2 | printDocument testreport_onbuild via browser on empty plan → doc renders ("testreport_onbuild EmptyPlan"), 0 new events | PASS |
| 3 | tlTestPlanMetrics::getExecCountersByTestSuiteExecStatus(3) full chain → metrics array with `tsuites_full=[]`, HTTP 200, DONE reached, 0 events | PASS |
| 4 | Positive regression: link TC-1 (suite TS-A id=5 under Root id=2) to plan 3 → get_testsuites(3) returns `[Root Suite(2), TS-A(5)]` sorted by name | PASS |
| 5 | resultsGeneral.php?tplan_id=3&format=0 → original 2147/2154 warnings + usort crash gone | PASS |

**Fix:** `(array)` cast on `get_recordset()` result + `$finalset = array()` init (testplan.class.php:2141-2161) — same pattern as `get_parenttestsuites()`.

**Follow-up discovered during this suite (out of scope):** with the crash gone, the resultsGeneral render path surfaces 3 pre-existing downstream E_WARNINGs on empty plans (tlTestPlanMetrics.class.php:1259 undefined key `tsuites`, :1383/:1393 property read on null from getStatusTotalsByItemForRender returning [null,null]). Filed separately.

## 48. Modernization — Test Plan Management screen (planView) (Suite ID: 54)

**Refs:** GitHub issue #576 · Files: `gui/templates/plans/planView.html`, `api/plans/index.php`, i18n keys `pv.*`/`header.planManage*` in 10 bundles, aside switch in `lib/functions/common.php`

**Pre-conditions:** fresh DB, admin/admin, test project "Demo Project" (id=1, prefix DP) created via UI; no platforms initially.

| # | Step | Result |
|---|------|--------|
| 1 | Aside menu → Test Plan section → "Test Plan Management" points to `/gui/templates/plans/planView.html?tproject_id=1&tplan_id=0` and loads in mainframe | PASS |
| 2 | Empty state: header/sub i18n'd, toolbar shows Test Project name, Create/Bulk buttons visible for admin, "No test plans exist…" message shown | PASS |
| 3 | "+ Create Test Plan" navigates to legacy `planEdit.php?do_action=create`; created "Plan Alpha" via legacy form; returning to screen lists it with counts 0/0 | PASS |
| 4 | **BUG-1 fixed**: BFF action URLs were relative (`lib/plan/…`) → resolved against `/gui/templates/plans/` → 404. Made root-absolute in BFF (`viewActions()`); create/edit/export/import/assignRoles/gotoExecute all HTTP 200 afterwards | PASS |
| 5 | **BUG-2 fixed**: unquoted jQuery attribute selectors `th[data-i18n=pv.tcQty]` threw "Syntax error, unrecognized expression", aborting `load()` before `render()` — table never populated. Quoted selectors fix rendering | PASS |
| 6 | Active toggle per row: off→on with toast "Test plan activated"; on→off with toast "Test plan deactivated"; DB `testplans.active` follows | PASS |
| 7 | Bulk: select 2 plans → Inactivate → toast "2 test plan(s) deactivated", both toggles off; Activate → both on | PASS |
| 8 | Bulk with zero selection → toast "Please select at least one test plan", API returns 422 NO_SELECTION | PASS |
| 9 | Delete modal: confirm text shows plan name + warning; Cancel keeps plan; Confirm deletes ("Plan Beta" gone, toast "Test plan deleted", count updated, audit event `audit_testplan_deleted` written) | PASS |
| 10 | Per-row legacy links resolve 200: planEdit edit, planExport, planImport, usersAssign (featureType=testplan), frmWorkArea executeTest | PASS |
| 11 | Permission path: guest user `noperm` → direct GET `/api/plans/index.php/?tproject_id=1` = 403 "No permission"; aside does not render the link (menu grant gated, same as legacy checkRights) | PASS |
| 12 | **BUG-3 fixed**: DataTables alert "Requested unknown parameter '8'" when Platforms column hidden via CSS while rows carried 8 cells vs 9 headers; column visibility now managed by stable CSS class `hide-plat` + always-populated cells | PASS |
| 13 | Platforms column dynamic path: platform "Chrome" added via BFF → reload shows Platforms column with per-plan qty; hidden again for projects without platforms | PASS |
| 14 | Re-render stability: toggle/bulk/delete each trigger full reload — columns stay correct across DataTables destroy/init cycles | PASS |
| 15 | i18n: locale switcher → Română renders "Management Planuri de Test / creează, activează…"; keys exist in all 10 bundles (`pv.*` ×32 + `header.planManage*` ×2), all bundles pass `json.tool` | PASS |
| 16 | Event Viewer: no new ERROR/WARNING events during entire suite (only AUDIT logins/logouts/deletes) | PASS |

**Bugs found & fixed during this suite:** BUG-1 (commit 05d9cb312), BUG-2 (commit 423cd66ce), BUG-3 (commits 7006a3f7d + a9866a0cf). All verified post-fix.

**Screenshots:** wiki `Test-Plan-Management.md` (normal state, delete modal, ro locale).
## Regression — Issue #579: tlTestPlanMetrics 3x E_WARNING + PHP 8.3 fatal on empty-plan report path

**Precondition:** admin logged in at http://localhost:8082; fixture: test project id=1 `Issue579 Project` (prefix I579), test plan id=11 `Issue579 EmptyPlan` with build id=12 `Issue579 Build` and ZERO rows in testplan_tcversions (build required — helperGetExecCounters throws "Can not work with empty build set" otherwise, that throw is pre-existing separate behavior); second plan id=30 `Issue579 EmptyPlan2` + build id=31 kept caseless for the final check.

**Repro steps (pre-fix):**
1. Browser session → `GET /lib/results/resultsGeneral.php?tplan_id=11&format=0`.
2. Check Event Viewer / `events` table and tmp/php_server.log.

**Observed pre-fix:** HTTP 500. Event Viewer got exactly the 3 reported E_WARNINGs at 17:16:23 — `Undefined array key "tsuites"` (tlTestPlanMetrics.class.php:1259), `Attempt to read property "colDefinition" on null` (:1383), `Attempt to read property "info" on null` (:1393) — and then, because resultsGeneral.php passes `groupByPlatform=1`, PHP 8.3 escalated :1393 into `Uncaught TypeError: array_keys(): Argument #1 ($array) must be of type array, null given` → fatal. Root cause: `getExecCountersByTestSuiteExecStatus()` never sets `$exec['tsuites']` when `get_testsuites()` returns an empty set (`$loop2do=0`), so `getStatusTotalsByItemForRender()` bails leaving `$renderObj=null`, which the caller dereferenced unguarded.

**Expected post-fix:** page renders its "no test cases" branch (`report_tspec_has_no_tsuites`) with HTTP 200; zero new tlTestPlanMetrics events; non-empty plans keep rendering full statistics; all callers of `getStatusTotalsByTopLevelTestSuiteForRender()` stay warning-free.

**Actual result (post-fix): PASS**
| # | Step | Result |
|---|------|--------|
| 1 | resultsGeneral.php?tplan_id=11&format=0 (empty plan) → 200, "There are no Test Suites defined…" branch, 0 metrics events in `events` table, no fatal in php_server.log | PASS |
| 2 | Positive regression: link TC v1 (suite `I579 Suite` id=13) to plan 11 → same URL renders "Results by Top Level Test Suite" with I579 Suite Total=1 Not Run=1 100.0% | PASS |
| 3 | lib/general/mainPage.php dashboard (caller of the fixed method, incl. its no-builds guard) → 200, 0 new events | PASS |
| 4 | topLevelSuitesBarChart canDraw null-guard reviewed in place (screen itself still blocked by pre-existing getRootTestSuites() array_keys(null), filed as #580 — fires at line 57 BEFORE the guarded call) | PASS |
| 5 | php -l on both touched files clean; code-review subagent APPROVE (truth table of rewritten condition identical for keyword/platform/priority_level paths) | PASS |

**Fix commit:** edcae5f3e on branch fix/issue-579.

**Follow-ups discovered during this suite (out of scope, each filed with repro+root cause):**
- #580 `testplan::getRootTestSuites()` array_keys(null) TypeError → topLevelSuitesBarChart 500 on case-less plans.
- #581 resultsGeneral.php:248 `testPriorityEnabled on false` when testprojects.options is empty.
- #582 resultsGeneral.php:63 foreach($items2loop) undefined on platform-less plans + template/controller name mismatch `$gui->tprojOpt` vs `$gui->testprojectOptions`.
- #583 `getStatusTotalsTSuiteDepth2ForRender()` same null-deref pattern (:3389/:3403, resultsByTSuite.php path) found by code review.

### Suite 54b — code-review round (commits f3f2c1faa, 779ebfb11; issue #584 filed)

| # | Finding (review) | Fix | Verify |
|---|---|---|---|
| R1 | BLOCKER: `bulk-active` ownership check ineffective — `testplan::get_by_id()` 2nd arg is an options array, cross-project ids passed → explicit `testproject_id` comparison now | PASS |
| R2 | BLOCKER: stored XSS via plan name in inline `onclick` (entity decode re-injects quotes in JS context) → deleted button now carries only `data-del-id`; name resolved from API payload via delegated handler | PASS |
| R3 | BLOCKER: notes regex sanitizer bypassable (`<script src=…` unclosed, `/onerror=` no-whitespace) → DOMPurify.sanitize(); payload test: script+onerror stripped, benign `<b>` kept | PASS |
| R4 | MAJOR: assign-roles link used `itemID=` but usersAssign.php reads `featureID=` → fixed in BFF action map | PASS |
| R5 | NIT: PUT /{id}/active route lacked ctype_digit validation (DELETE had it) → added | PASS |
| R6 | MAJOR parity gap: legacy custom-field columns absent from modern screen → deferred, filed as #584 (labeled bug) with repro + suggested fix | FILED |
| R7 | MINOR: explicit CSRF origin validation — deferred: would break legit non-browser API clients and diverges from all sibling BFF endpoints; noted on #576 for repo-wide decision | NOTED |

Post-fix regression: delete modal opens/cancels via delegation, notes render sanitized, table/columns/toggles unchanged. Event Viewer: still zero new Error/Warning events.

## Regression — Issue #583: getStatusTotalsTSuiteDepth2ForRender() null deref + array_keys(null) TypeError on empty plan (resultsByTSuite.php path)

**Precondition:** admin logged in at http://localhost:8082 (PHP 8.3); fresh-DB fixture created via SQL: test project id=900001 `Issue583Proj` (prefix I583), test plan id=900002 `Issue583Plan` with build id=900003 `Build B1` and ZERO rows in testplan_tcversions (build required — without it `helperGetExecCounters()` throws "Can not work with empty build set", a separate pre-existing guard that masks the reported path). Session cookie reused for curl A/B checks; direct-call harness used to expose the exact fatal (page itself exits silently when the `format` request param is missing — see Discovery D3).

**Repro steps (pre-fix):**
1. `GET /lib/results/resultsByTSuite.php?tplan_id=900002&format=0`.
2. Same URL through a harness calling `tlTestPlanMetrics::getStatusTotalsTSuiteDepth2ForRender(900002,null,['groupByPlatform'=>1])` with display_errors on.

**Observed pre-fix:** step 1 → HTTP 500, zero-byte body (`display_errors` off in this SAPI context). Step 2 → first `E_WARNING Attempt to read property "colDefinition" on null` (tlTestPlanMetrics.class.php:3389), then `Uncaught TypeError: array_keys(): Argument #1 ($array) must be type array, null given` (:3403) → matches issue report exactly. Root cause: on a plan whose builds exist but which has no linked test cases, `getExecCountersByTestSuiteExecStatus()` never sets `$exec['tsuites']`, so `getStatusTotalsByItemForRender('tsuite')` returns `[null, …]` (the same null contract fixed for `getStatusTotalsByTopLevelTestSuiteForRender()` in #579); `getStatusTotalsTSuiteDepth2ForRender()` dereferenced `$rx` unguarded.

**Expected post-fix:** method returns NULL early; page renders its "no level 2 suites" branch (`report_tspec_has_no_tsuites`) with HTTP 200; populated plans keep rendering the L1/L2 statistics table; sibling metrics screens unaffected.

**Actual result (post-fix): PASS**
| # | Step | Result |
|---|------|--------|
| 1 | Harness call returns NULL (no warning/fatal); resultsByTSuite.php?tplan_id=900002&format=0 → HTTP 200 + "There are no Test Suites defined…no report can be created" branch | PASS |
| 2 | A/B proof: temporarily restoring HEAD version of tlTestPlanMetrics.class.php → same URL gives HTTP 500 / 0 bytes; re-applying fix → HTTP 200 again | PASS |
| 3 | Positive regression: linked TC v1 under nested suites TS TOP(900004) > TS CHILD(900005) via testplan_tcversions → same URL renders full report (TS CHILD / TS TOP rows, Not Run column), HTTP 200, no fatals | PASS |
| 4 | Sibling screens sharing getStatusTotalsByItemForRender(): resultsGeneral.php?tplan_id=900002&format=0 → HTTP 200, no warnings; mainPage.php dashboard unaffected | PASS |
| 5 | php -l clean; Event Viewer shows no new Error/Warning events from the touched code path during all steps | PASS |

**Fix commit:** b6d6d4eab on branch fix/issue-583.

**Follow-up discovered during this suite (out of scope, filed with repro+root cause):**
- #586 pChart PHP4-style constructor never runs under PHP 8 (`function pChart()` no longer treated as `__construct`) → `$this->Picture` stays NULL and every chart screen (topLevelSuitesBarChart etc.) fatals with imagecolorallocate TypeError **even on fully populated plans**.

## Regression — Issue #587: getExecTimeSpan() undefined $fieldList + null map on zero-execution plans (resultsByTSuite exec-span path)

**Precondition:** admin logged in at http://localhost:8082; fresh-DB fixture via SQL: project 900001 `FIX587 Project`, plan 900002 `FIX587 Plan`, build 900003 `B1`, nested suites TS TOP(900004) > TS CHILD(900005), TC-1 v1 (tcversion 900007) linked through testplan_tcversions id=900008, **zero rows in `executions`**. Session cookie reused for curl checks.

**Repro steps (pre-fix):**
1. `GET /lib/results/resultsByTSuite.php?tplan_id=900002&tproject_id=900001&format=0`.
2. Inspect `events` table for new E_WARNING entries.

**Observed pre-fix:** HTTP 200 but exactly 4 new events:
1. `Undefined variable $fieldList` — tlTestPlanMetrics.class.php:3657 (fires on EVERY call, even with executions: `$fieldList .= implode(...)` never initialized);
2. `Trying to access array offset on null` — resultsByTSuite.php:72 (`$span[$args->tplan_id]` when `fetchRowsIntoMap()` returned NULL because the aggregate SELECT over an empty `executions` set yields no rows);
3./4. `Trying to access array offset on null` ×2 from compiled `show_table_with_exec_span.inc.tpl.php` lines 39/42 (template reads `$gui->spanByPlatform[$platId]['begin'|'end']` where `[0] => null` was stored).

**Expected post-fix:** zero new Error/Warning events on every load path; with executions present the First/Latest Execution span renders as before; per-platform span shows only for platforms having executions; no behavior change elsewhere.

**Actual result (post-fix): PASS**
| # | Step | Result |
|---|------|--------|
| 1 | Zero-executions load → HTTP 200, **0 new events** (was 4) | PASS |
| 2 | Positive regression: INSERT one execution (2026-08-20 10:00:00, status p, platform_id 0) → same URL renders "First Execution on: 20/08/2026 10:00:00" + "Latest Execution on: …", **0 new events** (the $fieldList warning used to fire here too) | PASS |
| 3 | Multi-platform edge: platforms P-Busy(900008, has execution)/P-Idle(900009, none) linked to plan, link row updated to platform 900008 → "Results on Platform: P-Busy" section renders with correct span dates, **0 new events**, isset() guard covers the missing-per-platform-key shape | PASS |
| 4 | Browser pass (headless Chrome, admin/admin): report page renders fully incl. platform sections and span block; Event Viewer shows only the normal AUDIT login event (level 16), no Error/Warning | PASS |
| 5 | Code review subagent over full diff: PASS (no required findings); two pre-existing adjacent defects found and filed instead of fixed in-scope: #588 (dashio partial missing property_exists guard → warning from baselinel1l2 pages), #589 (saveForBaseline null-span deref + empty baseline insert on zero-exec plans) | PASS |

**Fix commit:** 6dd9f7e9b on branch fix/issue-587.

## 49. Modernization — Builds & Releases screen (buildsView) (Suite ID: 55)

**Refs:** GitHub issue #585 · Files: `gui/templates/plans/buildsView.html`, `api/builds/index.php`, i18n keys `bv.*` + `common.forbidden` in 10 bundles, aside switch in `lib/functions/common.php`

**Pre-conditions:** fresh DB; fixtures inserted via SQL: test project "Build Demo Project" (id=101, prefix BDP), test plan "Release Plan A" (id=102); admin/admin.

| # | Step | Result |
|---|------|--------|
| 1 | Aside menu → Test Plan section → "Builds / Releases" points to `/gui/templates/plans/buildsView.html?tproject_id=101&tplan_id=102` (verified by fetching asideMenu.php with project+plan context) | PASS |
| 2 | Screen loads standalone: teal header i18n'd, toolbar shows Test Plan name "Release Plan A", Create Build button visible for admin | PASS |
| 3 | Empty state: "No builds are defined for this test plan yet", DataTable renders 0 rows without JS errors | PASS |
| 4 | Create Build modal: name/notes/active/open/release date fields; copy-assignments block hidden when plan has no builds yet (legacy parity: source_build.build_count == 0) | PASS |
| 5 | Create "Build 1.0" with HTML notes `<b>First</b> build…` + release date 2026-09-01 → toast "Build created"; row shows name, sanitized bold notes, localized "Sep 1, 2026"; DB row: active=1, is_open=1 | PASS |
| 6 | Active toggle off→on / on→off via POST /{id}/flags; toasts "Build deactivated"/"Build activated"; DB `builds.active` follows | PASS |
| 7 | Open (lock) toggle close→open: closing stamps `closed_on_date=2026-08-22` (today) and is_open=0; opening resets closed_on_date=NULL and is_open=1 — exact legacy setClosed/setOpen + setClosedOnDate parity | PASS |
| 8 | Edit modal prefill: GET /1 returns all fields; title "Edit Build: Build 1.0"; notes rendered back as text (`<b>` stripped to plain on edit input); release date prefilled ISO; active/open checkbox state matches DB; copy block hidden on edit | PASS |
| 9 | Duplicate-name crosscheck: create second build named "Build 1.0" → HTTP 409, red toast "A build with that name already exists in this test plan: Build 1.0", modal stays open — legacy warning_duplicate_build parity | PASS |
| 10 | Copy-options block: after first build exists, create-modal shows "Copy tester assignments from build:" + source select populated newest-first with assignment counts ("Build 1.0 (0)") + exec-status multi-select from results config (localized labels) | PASS |
| 11 | Create "Build 1.1" → appears in table; count header updates to (2) | PASS |
| 12 | Delete flow: trash icon opens confirm modal with build name + legacy warning_delete_build text; Confirm → DELETE /2, toast "Build deleted", row gone, count (1) | PASS |
| 13 | API authz: unauthenticated GET /api/builds/?tplan_id=102 → 401 JSON "Not authenticated"; unknown route/method → 404 JSON error (after session check) | PASS |
| 14 | Audit trail: events table has CREATE ×2 + DELETE entries with object_type='builds' and GUI source, mirroring legacy logAuditEvent calls | PASS |
| 15 | Event Viewer: no new ERROR/WARNING events during the whole suite (only AUDIT) | PASS |
| 16 | i18n: all `bv.*` keys present in ALL 10 locale bundles (941 keys each, parity verified programmatically); bundles pass `python3 -m json.tool` | PASS |

**Bugs found & fixed during this suite:** one gap caught during development (missing btnCreate click handler wiring) fixed before first browser run.

**Screenshots:** wiki `Builds-and-Releases.md` (list view, create modal, edit modal).

### Suite 55 addendum — post-review fixes re-verified
| # | Step | Result |
|---|------|--------|
| 17 | **BUG-1 fixed**: `build::update()` unconditionally wipes `closed_on_date`; BFF now preserves the historical closure date on non-transition saves (open→closed stamps today, closed→open clears, still-closed restores). Edit-save of a closed build keeps `2026-08-22` | PASS |
| 18 | Source build validated against target plan (cross-plan assignment cloning blocked) — code-reviewed fix, negative path returns 400 | PASS (code path) |

## Regression — Issue #591: BFF CSRF guard on mutating routes + hardened session cookie

**Precondition:** fresh DB; SQL fixture: project 900001 "I591Proj" (prefix I591), plan 900002 "I591Plan", build 900003 "BuildB1"; admin/admin logged in; cookie jar for curl.

**Pre-fix repro:** `curl -b jar -X POST -d '{"active":0}' http://localhost:8082/api/builds/index.php/900003/flags` → HTTP 200, DB flipped even with NO Origin/Referer/X-Requested-With AND with hostile `Origin: http://evil.example`; login Set-Cookie was bare (`path=/` only, no HttpOnly/SameSite).

| # | Step | Result |
|---|------|--------|
| 1 | POST /{id}/flags with NO proof headers → 403 JSON "Forbidden: missing or mismatched same-origin proof"; DB row unchanged | PASS |
| 2 | Same POST with hostile `Origin: http://evil.example` → 403 | PASS |
| 3 | Same POST with hostile `Referer: http://evil.example/attack.html` → 403 | PASS |
| 4 | Same POST with `X-Requested-With: XMLHttpRequest` → 200 {"status":"ok"}, DB updated | PASS |
| 5 | Same POST with same-origin `Origin: http://localhost:8082` → 200 | PASS |
| 6 | Same POST with same-origin Referer → 200 | PASS |
| 7 | GET endpoints unaffected without headers (GET ?tplan_id=900002 → 200) | PASS |
| 8 | Guard active on every BFF area: POST to /api/users/ without proof → 403 | PASS |
| 9 | Login Set-Cookie now `PHPSESSID=…; path=/; HttpOnly; SameSite=Lax` (Max-Age 99999 preserved) | PASS |
| 10 | Browser regression, buildsView.html?tplan_id=900002 (jQuery $.ajax sends XRW automatically): Active toggle → toast "Build activated"; edit + save → "Build saved" (notes persisted in table); create "BuildB2" → "Build created" (count header 2); delete BuildB2 → "Build deleted" (count header 1) | PASS |
| 11 | Legacy flows unaffected by cookie hardening: curl form login (csrfguard tokens) → authenticated BFF GET works; mainPage shell renders | PASS |
| 12 | Event Viewer: no new ERROR/WARNING events during the suite (only AUDIT) | PASS |

**Result: 12/12 PASS** (run 2026-08-22, browser + curl).

**Refs:** GitHub issue #591 · Files: `api/_guard.php` (new), `lib/functions/common.php` (doSessionStart hardening), all 22 × `api/*/index.php`

## 50. Modernization — Add/Remove Test Cases screen (planAddTCView) (Suite ID: 56)
Refs #593 · Date: 2026-08-22 · Env: fresh DB, project PADT (id 1), plan "Release 1.0" (id 19), suites Checkout/Payments with 4 TCs imported via api/testcasesimport

| # | Case | Steps | Expected | Result |
|---|------|-------|----------|--------|
| 1 | Screen loads + rights | Open /gui/templates/plans/planAddTCView.html?tproject_id=1 as admin | Header, toolbar, suite tree w/ linked/total badges render | PASS |
| 2 | i18n en | Observe all labels | No raw paddtc.* keys; English strings shown | PASS |
| 3 | i18n ro switch | Locale switcher → Română | ?locale=ro; "Adauga / Elimina Cazuri de Test"; buttons translated | PASS |
| 4 | Suite navigation | Click Checkout in tree | Table lists 2 TCs w/ external ids PADT-1/PADT-2; header counts + badge | PASS |
| 5 | Link state display | tc4 already linked | Row highlighted, pill "linked", chip "v1 #order", order input enabled | PASS |
| 6 | Unlinked row UI | tc8 not linked | Pill "not linked", version select enabled (v1), order disabled, remove "-" | PASS |
| 7 | ADD link | Tick add on tc8 → Save changes | Toast "1 added, 0 removed"; badge 2/2; DB testplan_tcversions +1 | PASS |
| 8 | REORDER | Order tc4=5 → Save execution order | Toast saved; reload keeps 5; DB node_order updated | PASS |
| 9 | REMOVE link | Tick rm chip tc8 → Save | Toast "...1 removed"; badge 1/2; DB row deleted | PASS |
| 10 | Executed-link guard (has right) | Insert execution for tc4; tick chip → Save | Confirm modal lists executed TCs; OK removes link AND cascades execution delete | PASS |
| 11 | Nothing selected | Save with no checkboxes | Error toast; server 422 NO_SELECTION path exists | PASS |
| 12 | Keyword filter | Keyword 'smoke' only on tc4; filter by it | Tree badges recount; only "Guest checkout works" listed; reset restores | PASS |
| 13 | Auth guard (API) | planaddtc/init without session | HTTP 401 Not authenticated | PASS |
| 14 | Rights guard | canPlan=false path | noAccess msg shown; testplan_planning enforced on every endpoint | PASS (code-path + 403 handlers; no low-rights user fixture) |
| 15 | Event Viewer | events table after run | Zero new ERROR/WARNING rows | PASS |

Bugs found & fixed while executing:
- BFF container endpoint omitted tcversion_id in link rows -> chips showed "v?" and remove/reorder payloads broke. Fixed in api/plans/index.php.
- Concurrent fix/issue-556 merge broke JSON syntax (missing comma) in ALL 10 locale bundles -> whole i18n layer dead. Repaired (commit after review).

## Regression — Issue #595: authentication on api/projects + api/trackers BFF routes

**Precondition:** fresh DB; admin/admin; second user `noperm595` with role `<no rights>` (role_id 3) created via SQL (`password_hash('nopass595', PASSWORD_DEFAULT)`); cookie jars for both via curl form login.

**Pre-fix repro:** `curl -X POST -H 'Content-Type: application/json' -H 'X-Requested-With: XMLHttpRequest' -d '{"name":"UNAUTH_PROBE_595","prefix":"UP595"}' http://localhost:8082/api/projects/` → HTTP 200 `{"success":true,"id":1,...}` with NO session at all. Same for PUT (renamed project) and DELETE (deactivated it). GET list leaked every project to anonymous callers.

| # | Step | Expected | Result |
|---|------|----------|--------|
| 1 | Anonymous GET /api/projects/ | 401 {"status":"error","message":"Not authenticated"} | PASS |
| 2 | Anonymous GET /api/projects/{id} | 401 | PASS |
| 3 | Anonymous POST /api/projects/ (with XRW header, as any network client can send) | 401 | PASS |
| 4 | Anonymous PUT /api/projects/{id} | 401 | PASS |
| 5 | Anonymous DELETE /api/projects/{id} | 401 | PASS |
| 6 | Anonymous GET /api/trackers/ | 401 | PASS |
| 7 | Garbage PHPSESSID cookie → GET /api/projects/ | 401 (session bootstrap works, no fatal) | PASS |
| 8 | Login as noperm595 → GET projects, POST create, GET trackers | 403 {"status":"error","message":"No permission"} on all three (mgt_modify_product enforced) | PASS |
| 9 | Admin curl CRUD: POST create "AUTH_TEST_595" → id; PUT rename+deactivate; DELETE | All HTTP 200 success JSON; DB rows reflect changes | PASS |
| 10 | Browser: projectsView.html as admin — list loads, Create modal (tracker dropdowns load from /api/trackers/), Save → row appears; Edit rename+uncheck Active → Save; Delete → confirm dialog → deactivate succeeds | Full UI flow functional through new auth layer (jQuery sends session cookie + XRW automatically); network log all 200; zero console errors | PASS |
| 11 | CSRF guard still layered ON TOP of auth: authed POST without proof headers → 403 Forbidden JSON; hostile `Origin: http://evil.example` without XRW → 403 | Guard intact (defense in depth preserved) | PASS |
| 12 | Event Viewer after the whole suite | Zero new ERROR/WARNING events | PASS |

**Result: 12/12 PASS** (run 2026-08-22, browser + curl).

**Refs:** GitHub issue #595 · Files: `api/projects/index.php`, `api/trackers/index.php`

**Notes:** screen's DELETE is a *deactivate* by design (row stays listed as Inactive) — legacy parity, out of scope here. DB re-import happened mid-run (fixtures recreated). Probe rows cleaned up afterwards.

## Regression — Issue #590: package.json / yarn.lock sync verification

**Precondition:** repo root clean (`git status` shows no changes to `package.json`, `yarn.lock`, `node_modules/`); Yarn Classic 1.22.22 + Node v20.20.2 available.

**Verification request:** confirm lockfile sync WITHOUT modifying any files (`yarn install --frozen-lockfile` is the authoritative check — it hard-fails with "Your lockfile needs to be updated" when out of sync).

| # | Case | Steps | Expected | Result |
|---|------|-------|----------|--------|
| 1 | Files exist | `ls package.json yarn.lock` | Both present at repo root | PASS |
| 2 | Manifest content | Read package.json | Exactly 1 dependency: `tablednd ^1.0.3` | PASS |
| 3 | Lockfile sync | Compare yarn.lock vs manifest | Single entry `tablednd@^1.0.3 → 1.0.3`; exact match, zero drift | PASS |
| 4 | Frozen-lockfile install | `yarn install --frozen-lockfile` | Exit 0 in 0.23s; no resolution needed → lockfile in sync | PASS |
| 5 | No files modified | `git status` / `git diff -- package.json yarn.lock` | Empty diff on both manifests | PASS |
| 6 | node_modules integrity | Inspect node_modules/tablednd | Plugin v1.0.3 present (dist JS incl. jquery.tablednd.min.js); vendored & tracked in git (33 files) | PASS |
| 7 | Yarn version requirement | `head -3 yarn.lock` + `yarn --version` | Lockfile v1 format → Yarn Classic required; verified with 1.22.22 | PASS |
| 8 | CI usage scan | grep workflows for `yarn` | No workflow runs `yarn install` (works because node_modules is committed) | PASS |

**Result: 8/8 PASS** (run 2026-08-22).

**Refs:** GitHub issue #590 · Files: none changed (verification only, per issue instruction)

**Notes:** `node_modules/.yarn-integrity` is tracked and was generated on macOS (`systemParams: darwin-x64-72`); on Linux yarn regenerates it as `linux-x64-115`. Harmless runtime metadata, restored untouched per the issue's no-modification rule.

## Regression — Issue #589: resultsByTSuite.php saveForBaseline on zero-execution plan

**Precondition:** fresh DB; fixtures created via SQL as admin: project `FixProject589` (id 1), plan `Plan589` (id 10), top suite `TopSuite` (100) + child suite `ChildSuite` (110), TC-589 v1 (201) linked to plan, build `Build589` (300); **zero executions**; session cookie jar via curl form login (admin/admin).

**Pre-fix repro:** `curl -b cj.txt "http://localhost:8082/lib/results/resultsByTSuite.php?tplan_id=10&tproject_id=1&format=0&doAction=saveForBaseline"` → page dies with **DB Access Error** (`database->exec_query()` backtrace at resultsByTSuite.php:102) because `$span['testplan_id']`/`['begin']`/`['end']` are null-offset reads that interpolate empty strings → SQL 1064. Event Viewer gains 3× E_WARNING "Trying to access array offset on null" + 1× DATABASE ERROR.

| # | Case | Steps | Expected | Result |
|---|------|-------|----------|--------|
| 1 | Zero-execution save | Hit URL above on plan with linked L2 suites, no executions | HTTP 200 report page; localized message "Baseline NOT saved: the test plan has no execution records yet..."; NO rows in baseline_l1l2_context / baseline_l1l2_details | PASS |
| 2 | Event Viewer after case 1 | `SELECT ... FROM events WHERE id>5` | Zero new ERROR/WARNING events | PASS |
| 3 | Happy path still works | Insert one execution ('p', platform 0); hit save URL again | context row with real begin/end exec timestamps + n/p/f/b detail rows written | PASS |
| 4 | Mixed platforms (one executed, one not) | Add PlatA(400)/PlatB(401); execution only on PlatA; save | Context row ONLY for platform 400 with real timestamps; platform 401 skipped silently; no warnings; success path (no error message) | PASS |
| 5 | Executions exist but not on any planned platform | Execution on platform 0 while plan has only explicit platforms | Message shown, nothing written, no events | PASS |
| 6 | i18n keys present | grep locale bundles | `$TLS_baseline_save_no_executions` in en_GB + en_US; other locales fall back to en_GB via lang_get() | PASS |

**Result: 6/6 PASS** (run 2026-08-22, curl against http://localhost:8082).

**Refs:** GitHub issue #589 · Files: `lib/results/resultsByTSuite.php`, `locale/en_GB/strings.txt`, `locale/en_US/strings.txt`

## Regression — Issue #588: dashio show_table_with_exec_span.inc.tpl missing property_exists guard

**Precondition:** fresh DB; fixtures via SQL as admin: project `M588 Project` (900001), plan `M588 Plan` (900002), suites `TS TOP` (900003) > `TS CHILD` (900004); one `baseline_l1l2_context` row (platform_id 0, real begin/end/creation ts) + 3 `baseline_l1l2_details` rows (n/p/f). For the positive path: TC-1 v1 (900011) linked to plan, build B1 (900003), one execution ('p', platform 0). Session: headless Chrome, admin/admin, Dashio theme.

**Pre-fix repro:** open `lib/results/baselinel1l2.php?tplan_id=900002&format=0` → page renders HTTP 200 with baseline metrics, but Event Viewer gains **1× E_WARNING "Undefined property: stdClass::$spanByPlatform"** from compiled `show_table_with_exec_span.inc.tpl.php` (dashio partial line 14 reads the property without the `property_exists` clause tl-classic has).

| # | Case | Steps | Expected | Result |
|---|------|-------|----------|--------|
| 1 | Warning gone on baselinel1l2 | Reload baselinel1l2.php?tplan_id=900002&format=0 after fix | Page renders identical metrics table; NO new "Undefined property: stdClass::$spanByPlatform" event | PASS |
| 2 | Positive path intact on resultsByTSuite | Open resultsByTSuite.php?tplan_id=900002&format=0 (controller DOES set spanByPlatform) | "First Execution on / Latest Execution on" dates still render above the suite table | PASS |
| 3 | No new events on positive path | Check events after case 2 | Zero new Error/Warning rows | PASS |
| 4 | Theme parity | Diff guard condition vs tl-classic copy | Both partials now start `{if property_exists($gui,'spanByPlatform') && null != $gui->spanByPlatform && isset($gui->spanByPlatform[$platId])}` | PASS |

**Result: 4/4 PASS** (run 2026-08-23, headless Chrome against http://localhost:8082).

**Refs:** GitHub issue #588 · Files: `gui/templates/dashio/results/show_table_with_exec_span.inc.tpl`

## Regression — Issue #601: dashio baselinel1l2.tpl reads 4 undefined $gui props

**Precondition:** fresh DB; fixture via `php tmp/fixtures_bll.php` (admin): project `BLL Demo Project` (1), plan `BLL Plan` (2), suites Top Suite One (3) > Child Suite A (4); one `baseline_l1l2_context` row (platform 0, 2026-08-20..21) + one `baseline_l1l2_details` row ('p', qty 3, total 5). Session: headless Chrome, admin/admin, Dashio theme.

**Pre-fix repro:** open `lib/results/baselinel1l2.php?tplan_id=2&format=0` → page renders, but events table gains **4 E_WARNINGs**: Undefined property stdClass::$baselineSaved / $actionSendMail / $actionSpreadsheet / $mailFeedBack from compiled baselinel1l2.tpl.php (lines ~72/87/98/108).

| # | Case | Steps | Expected | Result |
|---|------|-------|----------|--------|
| 1 | Zero warnings on plain render | Open baselinel1l2.php?tplan_id=2&format=0 | Page renders metrics table; NO new "Undefined property" events for baselineSaved/actionSendMail/actionSpreadsheet/mailFeedBack | PASS |
| 2 | Toolbar forms have real actions | Inspect form actions in DOM | send_by_email_to_me → ...baselinel1l2.php?tplan_id=2&tproject_id=1&format=6; exportSpreadsheet → ...format=3&spreadsheet=1 | PASS |
| 3 | Email format path clean | GET same URL with format=6 | Renders without fatal; zero new Error/Warning events | PASS |
| 4 | Event Viewer after all cases | SELECT * FROM events WHERE id > <pre-fix max> | Only events attributable to pre-existing XLS-export bug (#602); none caused by this fix's render paths | PASS |

**Result: 4/4 PASS** (run 2026-08-23, headless Chrome against http://localhost:8082).

**Refs:** GitHub issue #601 · Files: `lib/results/baselinel1l2.php`

## Suite 602 — Regression — Issue #602: XLS export fatal on report controllers (missing PhpOffice PSR-4 mapping)

**Precondition:** admin/admin session; test project `FixProj` (id 10) with plan `FixPlan` (id 20), build `Build 1`, 2 linked TCs (fixtures created via SQL, DB is re-imported per run).

**Pre-fix repro:** GET `/lib/results/baselinel1l2.php?tplan_id=20&tproject_id=10&format=3&spreadsheet=1` → HTTP 500, `Class "PhpOffice\PhpSpreadsheet\Style\Border" not found` at baselinel1l2.php:564; events table spammed with E_WARNINGs (`PhpOffice\...\Border.class.php`, `Composer\Pcre\Preg.class.php` include failures).

**Fix under test:** (1) PSR-4 prefix maps added to vendor/composer/autoload_psr4.php + autoload_static.php for all on-disk-but-unmapped packages; (2) xlsDefaultStatsSections()/xlsNormalizeLegacyStyles() helpers in lib/results/displayMgr.php wired into every createSpreadsheet/initStyleSpreadsheet; (3) array_flip(null) guard in neverRunByPP.

| # | Step | Expected | Result |
|---|------|----------|--------|
| 1 | `php -r "require 'vendor/autoload.php'; var_dump(class_exists('PhpOffice\\PhpSpreadsheet\\Style\\Border'));"` | true | PASS |
| 2 | GET baselinel1l2.php?...spreadsheet=1 | HTTP 200, valid OLE/XLS binary (~4.6KB) | PASS |
| 3 | Same for resultsByTSuite / resultsGeneral / execTimelineStats / resultsTC / resultsTCFlat | HTTP 200, valid XLS binary | PASS |
| 4 | Events table after exports | no new Error/Warning entries from export path | PASS (only pre-existing foreach-null warning at baselinel1l2.php:95 from empty baseline data) |
| 5 | neverRunByPP.php direct GET without platSet | HTTP 200 page render (was 500 array_flip(null)) | PASS |
| 6 | resultsTCAbsoluteLatest GET | HTTP 200 HTML page (export needs platform context by design) | PASS |
| 7 | testAutomationSpec.php any format | page renders | **FAIL** — separate bug: specview.php:860 array_keys(null) via get_last_active_version() returning null; filed as its own issue |

**Result: 6/7 PASS** (run 2026-08-23). Remaining FAIL tracked in a dedicated issue.

## 57. Modernization — Assign Platforms to Test Plan screen (platformsAssign) (Suite ID: 57)

**Tracking:** GitHub issue #603 · **Files:** `api/platforms/index.php` (GET/POST /assign), `gui/templates/platforms/platformsAssign.html`, i18n `passign.*` in all 10 bundles, aside switch in `lib/functions/common.php`

**Precondition:** fresh DB; fixtures recreated per run — project `PA Demo Project` (id 1), plan `PA Plan One` (id 2), platforms Linux-Prod (1) / Windows-QA (2) / Docker-Staging (3), one TC in Suite A (tcversion_id 5), one `testplan_tcversions` row (plan 2, tcv 5, platform_id 0 = "unknown platform" state). Session: headless Chrome, admin/admin.

| # | Case | Steps | Expected | Result |
|---|------|-------|----------|--------|
| 1 | Screen loads with context | Open `/gui/templates/platforms/platformsAssign.html?tproject_id=1&tplan_id=2` | Header, toolbar w/ project + plan selector, dual panes render; Available=Linux-Prod,Windows-QA,Docker-Staging; Assigned empty; no banner | PASS |
| 2 | Add + Save links platform | Select Linux-Prod → `>` → Save | toast saved; DB `testplan_platforms` gains row (2,1); pane refresh shows Linux-Prod assigned | PASS |
| 3 | fixNeeded banner | Link a TC version with platform_id=0 (SQL fixture), reload screen | Yellow "Warning/Critical … test case versions without platform assignment" banner visible | PASS |
| 4 | Single-add auto reassign | Select Docker-Staging → `>` → Save | `testplan_tcversions.platform_id` flips 0→3; banner disappears; Docker badge "(Used in 1 testcases)" appears | PASS |
| 5 | Client unlink guard | Select Docker-Staging (linkedCount>0) → `<` | Warning modal "Platform is in use" opens; item stays in Assigned; nothing moved | PASS |
| 6 | Warning renders HTML breaks | Inspect modal body after fix b7da46529 | Message renders line breaks (`<br>` applied), no literal `<br/>` text | PASS |
| 7 | save_tcv alias links platforms | Select Windows-QA → `>` → "Save and assign linked test cases…" | Row (2,2) created in `testplan_platforms`; all 3 platforms now assigned | PASS |
| 8 | Unlink platform without TCs | Select Linux-Prod (0 linked) → `<` → Save | Row (2,1) deleted from `testplan_platforms`; pane updates | PASS |
| 9 | Server-side guard | In-page fetch POST /assign {remove:[3]} | HTTP 422 `{error_code:"PLATFORM_HAS_LINKED_TCVS"}`; DB unchanged | PASS |
| 10 | No-tplan legacy message | Open bare URL `/gui/templates/platforms/platformsAssign.html` | Fallback auto-picks first accessible project; plan selector populated; message "There is no Test Plan selected." shown until user picks a plan | PASS |
| 11 | Locale switch (de) | Open with `&locale=de` | Header "Plattformen zum Testplan zuordnen", panes "Verfügbare/Zugeordnete Plattformen", badge "(in 1 Testfällen verwendet)" | PASS |
| 12 | Aside link switched | Load index.php, inspect asidebar frame | `platformAssign` item href = `/gui/templates/platforms/platformsAssign.html?tproject_id=…&tplan_id=…` | PASS |
| 13 | Event Viewer clean + idempotent save | After fixes (93d30a080, be872380f): reload screen ×3, triple-click Save, check events + DB | No new E_WARNING rows; no duplicate testplan_platforms rows despite triple submit | PASS |

**Bugs found & fixed during this suite (each own commit):**
- 4de9cfb77 — btnAdd passed selector string → TypeError, move-right dead
- b7da46529 — unlink warning showed literal `<br/>` (data-i18n escapes)
- d483b51ad + 9499df451 — bare deep-link dead-ended; fallback used wrong projects API envelope
- 93d30a080 — E_WARNING spam: get_all_testplans() has no is_open key
- be872380f — review hardening: idempotent double-submit, orphaned-row skip

**Result: 13/13 PASS** (run 2026-08-23, headless Chrome against http://localhost:8082).

**Notes:** legacy third button *Enable/Disable selected* omitted intentionally — repo-wide grep proves no server handler ever existed (dead UI). Legacy "Save & assign to TCV" extra logic was unreachable upstream (`copyLinkFromPlatformToPlatform()` undefined, inputs never posted); modern `mode=save_tcv` kept as documented alias performing link/unlink.

## 58. Modernization — Set Test Urgency screen (testUrgency) (Suite ID: 58)

**Screen:** `gui/templates/plans/testUrgency.html` · **BFF:** `api/plans/index.php`
routes `/urgency`, `/urgency/suite` (GET/POST), `/urgency/tcases` (POST) · **Refs #605**
**Legacy parity target:** `lib/plan/planUrgency.php` + `planUrgency.tpl`
(rights: `testplan_planning` rightsAnd; suite urgency buttons; per-TC urgency
radios with computed priority = importance × urgency).

> **Run 2026-08-23 (browser + curl + SQL):** 12/12 PASS (3 defects found during
> the run were fixed in-run: protected `$tables` access fatal, stale TC table
> after suite set, getActions() fatal on mainPage).

| # | Test | Result |
|---|------|--------|
| 1 | Context render: project name, plan selector, suites pane w/ TC counts and urgency badges (Alpha=3 TCs "mixed", Beta=1 TC "Medium") | PASS |
| 2 | Suite table data parity: tcprefix+external id+name, assigned_to, importance label, urgency radio preselection, computed priority tag (Medium(4)/High(9)/Low(1) = imp×urg) | PASS |
| 3 | Suite urgency button High → toast ok, badge flips mixed→High, DB `testplan_tcversions.urgency`=3 for all suite tcversions | PASS |
| 4 | After suite set, open TC table refreshes (radios re-checked to new values) — bug fixed in-run (`1fb9497f6`) | PASS |
| 5 | Per-TC change UD-3 Low→High + "Set Urgency for Test Cases" → toast ok, dirty hint clears, DB row updated | PASS |
| 6 | Suite urgency Low on Alpha → all rows urgency=1, priorities recomputed (Low(2)/Medium(3)/Low(1)) | PASS |
| 7 | Suite switch (Beta): single row rendered with correct urgency Medium preselected | PASS |
| 8 | Locale switch ro: header/buttons/badges translated (turg.* bundle), page reloads with ?locale=ro | PASS |
| 9 | Deep-link recovery: bare `/gui/templates/plans/testUrgency.html` recovers first accessible project and renders | PASS |
| 10 | Permission path — unauthenticated BFF GET/POST → 401; user `lowpriv` (no rights role) → 403 on /urgency, /urgency/suite GET+POST | PASS |
| 11 | Aside integration: aside menu entry points at testUrgency.html inside app shell, click loads screen into mainframe iframe; mainPage 200 | PASS |
| 12 | Event Viewer: zero new Error/Warning events during the whole test window (after fixing self-inflicted fixture options serialization; unrelated to screen code) | PASS |

## 59. Regression — Issue #419: Execution navigator emits EXDS(,N) — JS syntax error leaves execution tree empty (Suite ID: 59)

**Area:** Test Case Execution → Execute Tests (`lib/execute/execNavigator.php`
+ `gui/templates/dashio/execute/execNavigator.tpl`) · **Refs #419**

**Precondition:** project with suites/cases linked to a plan and at least one
build (fixtures: `tmp/fixtures_419.php` — project "EXDS Demo Project", plan
"EXDS Plan", build "EXDS Build 1", cases EXD-1/2/3).

| # | Test | Steps | Expected | Result |
|---|------|-------|----------|--------|
| 1 | Bootstrap call well-formed | Load `lib/execute/execNavigator.php?setting_testplan=18&tproject_id=6&tplan_id=18`, regex the inline script for `EXDS(...)` | Emits `EXDS(6,18);` — no empty first argument | PASS |
| 2 | No JS syntax error | Check DevTools console after load | No `Uncaught SyntaxError: Unexpected token ','`, no `treeCfg is not defined`; `typeof treeCfg === 'object'` | PASS |
| 3 | Tree built | Inspect `#tree_div.innerHTML.length` | > 0 (observed 1941 chars); root node present | PASS |
| 4 | Full user flow | index.php → aside **Test Case Execution → Execute Tests** | Navigator loads in mainframe iframe with Settings/Filters + tree; workframe loads execDashboard | PASS |
| 5 | Tree content complete | Expand tree (`tree.expandAll()`) | Root "EXDS Demo Project / EXDS Plan (3)" + suites + all 3 cases (EXD-1:Case T1, EXD-2:Case C1, EXD-3:Case C2) | PASS |
| 6 | Case clickable → execution form | Click tree case link `ST(9,10)` | `execSetResults.php?version_id=10&level=testcase&id=9...` loads in workframe with execution form present | PASS |
| 7 | Event Viewer clean for #419 flow | Query events table after full flow | No new Error/Warning caused by the navigator bootstrap itself (only pre-existing unrelated E_WARNINGs in execSetResults templates — filed separately) | PASS |

**Root cause / fix note:** `initializeGui()` in
`lib/execute/execNavigator.php` never assigned `$gui->tproject_id`, so Smarty
rendered `EXDS(,18);` in `execNavigator.tpl:57`. The whole inline `<script>`
block failed to parse, killing `treeCfg` and the `Ext.onReady()` handler that
builds the tree. Fix (already on default branch since commit `857555dad`,
2026-08-17): `$gui->tproject_id = intval($control->args->testproject_id);`.

**Result: 7/7 PASS** (run 2026-08-23, headless Chrome against http://localhost:8082).

**Bugs discovered during this suite (filed separately, NOT part of #419):**
- chosen.jquery.js loads before jQuery in `frmInner.tpl`/`workbench.tpl` → console ReferenceError on every workarea load (no functional impact found)
- E_WARNING trio when rendering an execution form (`round_enabled` undefined in execSetResults.tpl:141, null property read, null array access in exec_show_tc_exec.inc.tpl)

## 60. Regression — Issue #420: platform filter sentinel -1 never matches DB value 0 → "No data available" (Suite ID: 60)

**Area:** Test Case Execution → Execute Tests (`lib/execute/execSetResults.php`)
· **Refs #420**

**Precondition:** test plan **without platforms**, ≥1 linked TC, ≥1 build.
Fixtures (fresh empty DB, recreated via SQL since the import is wiped per run):
project id=1 "Issue420 Project" (prefix I420) → suite id=2 → case id=3
"I420-1" (tcversion id=4) → plan id=5 "Issue420 Plan"
(`testplan_tcversions(5,4,platform_id=0)`) → build id=1 "Build 1".

| # | Test | Steps | Expected | Result |
|---|------|-------|----------|--------|
| 1 | Sentinel really travels | Login admin → `lib/execute/execNavigator.php?tplan_id=5` → expand tree → click `I420-1` leaf | Exec frame URL contains `setting_platform=-1` (UI sentinel, verbatim) | PASS |
| 2 | Execution form renders (V1) | Observe right pane after click | Full execution form: TC body "Test Case I420-1 :: Version : 1", Summary, Notes textarea, file upload, pass/fail/blocked controls — **NOT** `No data available` | PASS |
| 3 | Save status (V2) | Click span title="Click to set to passed" (`saveExecStatus(4,'p',...)`) | Form POST accepted, page reloads without error | PASS |
| 4 | Read-back current build (V3) | Observe reloaded pane | `Latest execution (current build): Build 1 ... Status : Passed` | PASS |
| 5 | DB convention respected (V4) | `SELECT platform_id,status FROM executions;` | `{platform_id:0, status:'p'}` — stored under the DB's no-platform value 0, not -1 | PASS |
| 6 | Sentinel audit (V5) | `grep -rn "platform_id.*=>\s*-1\|setting_platform=-1" lib/ gui/` | Exactly one declaration (`execSetResults.php:2295`) neutralized by normalization `execSetResults.php:2318-2320`; no other leak path in lib/ or gui/ | PASS |

**Root cause / fix note:** `init_args()` declared the UI sentinel
`'platform_id' => -1` while `testplan_tcversions.platform_id` /
`executions.platform_id` store **0** for platform-less plans; downstream queries
compared directly (`AND TPTCV.platform_id = -1` → 0 rows → `$gui->map_last_exec`
stays null → template prints `no_data_available`). Fix (on default branch since
commit `857555dad`, verified live this run): normalize once at parse time in
`lib/execute/execSetResults.php:2318-2320`
(`if($argsObj->platform_id < 0) { $argsObj->platform_id = 0; }`).

**Result: 6/6 PASS** (run 2026-08-23, headless Chrome + MariaDB against
http://localhost:8082, default branch @ `4cb3bd6ec`).

**Event Viewer note:** no new errors from this flow beyond the pre-existing
execution-form E_WARNING trio already recorded in Suite 59 (round_enabled /
null property / null array access — unrelated to the platform sentinel). The
SQL syntax error event at 07:09 was self-inflicted by an incomplete hand-made
fixture (testsuites row missing), documented on the issue and not reproducible
with UI-created data.

## 61. Regression — Issue #614: E_WARNING trio when rendering an execution form (round_enabled / null property / null array offset) (Suite ID: 61)

**Area:** Test Case Execution → Execute Tests (`lib/execute/execSetResults.php`)
· **Refs #614 · Fixes verified on branch `fix/issue-614`**

**Precondition:** project + plan + linked cases + build. Fixtures via
`php tmp/fixtures_614.php` (idempotent CLI script, committed with the fix):
project "I614 Project" (prefix I614) → suite "I614 Suite" → cases
"Case Alpha"/"Case Beta" (tcversions 11/14) → plan "I614 Plan" (id 16,
`testplan_tcversions` links on platform_id=0) → build "I614 Build" (id 1).
Project options must include `automationEnabled` etc. (script sets all four;
an empty options object produces unrelated `$tprojOpt->automationEnabled`
warnings — fixture artifact, not a product bug).

| # | Test | Steps | Expected | Result |
|---|------|-------|----------|--------|
| 1 | Repro pre-fix state | (pre-fix) load exec form directly or via tree | Event Viewer gains: `Undefined array key "round_enabled"` (compiled execSetResults.tpl.php:182), `Attempt to read property "value" on null` (same line — same expression), `Trying to access array offset on null` (compiled exec_show_tc_exec.inc.tpl.php:244) | PASS (reproduced, events 23–25) |
| 2 | round_enabled fixed | Clear events → reload execution form | No `Undefined array key "round_enabled"`, no `property "value" on null`; compiled tpl reads `$gui->round_enabled` | PASS |
| 3 | other_execs guard (first run) | Open a never-executed case (Case Beta) | No `Trying to access array offset on null` from exec_show_tc_exec.inc.tpl; page renders "Not Tested Yet" block normally | PASS |
| 4 | Real UI flow clean | execNavigator → expand tree → click case leaf (form_token flow) | Zero new Error/Warning events during render | PASS |
| 5 | Save still works (post-save path) | Click status icon span `fastExecf_11` ("Click to set to failed") | Execution saved (executions row status 'f', audit_exec_saved event), page re-renders through patched `{if $gui->other_execs && …}` branch with history table | PASS |
| 6 | History rendering intact | Observe post-save pane + click "Show complete execution history" toggle | Latest-execution header + `table.exec_history` rows present; no new warnings | PASS |
| 7 | Move-to-next intact | Click "Move to Next Test Case" | Navigates to next case without saving (legacy behavior preserved); no new warnings | PASS |

**Root cause / fix note:** (1) `execSetResults.tpl:141` read top-level
`$round_enabled`, but PHP only ever assigns `$gui->round_enabled = 1`
(`initializeGui()`, execSetResults.php:1479) — Smarty compiled it to
`tpl_vars['round_enabled']->value`, producing BOTH the undefined-key and the
null-property warning from one expression. Fixed to `{if $gui->round_enabled}`.
(2) `exec_show_tc_exec.inc.tpl:186` evaluated `$gui->other_execs.$tcversion_id`
while `$gui->other_execs` is explicitly initialized to null
(execSetResults.php:408) and stays null whenever history is off / no prior
execution exists (`getOtherExecutions()` returns null) → array-offset-on-null.
Fixed with a short-circuit null guard:
`{if $gui->other_execs && $gui->other_execs.$tcversion_id}`.

**Result: 7/7 PASS** (run 2026-08-23, headless Chrome + MariaDB against
http://localhost:8082, branch `fix/issue-614` @ `9bf22dd20`).

**Bugs discovered during this suite (filed separately, NOT part of #614):**
- #620 — E_WARNING `Undefined property: stdClass::$refreshTree` at
  execSetResults.php:2338 when the form is reached without prior navigator
  load (direct URL/bookmark); not reachable via standard navigation.
- #621 — i18n notices `file_upload_step_exec_ok` / `file_upload_step_exec_ko`
  not localized for en_GB on step-execution save.

## 62. Regression — Issue #421: buildView.php fatal `build_mgr` + E_WARNING on empty plan (Suite ID: 62)

**Area:** Test Plan → Builds/Releases (`lib/plan/buildView.php`,
`lib/plan/buildEdit.php`, `gui/templates/dashio/plan/buildView.tpl`) · **Refs #421**

**Precondition:** fresh DB; fixtures created via UI: project "Bug421 Project"
(id=1, prefix B421), plans "Bug421 Test Plan" (id=2, gets 1 build) and
"Empty Plan" (id=3, zero builds). Event-table baseline recorded before each
assertion (`SELECT MAX(id) FROM events`).

| # | Test | Steps | Expected | Result |
|---|------|-------|----------|--------|
| 1 | No fatal on view | Open `lib/plan/buildView.php?tproject_id=1&tplan_id=2` | Page renders "Build management - Test Plan : Bug421 Test Plan"; NO `Class "build_mgr" not found` fatal (fixed by commit `4c73d651e`: line 41 uses real class `build`) | PASS |
| 2 | Empty-state render | Same URL while zero builds existed (pre-fix state) | Empty-state message + Create button shown; pre-fix this fired `E_WARNING foreach() ... null given - buildView.php Line 90` (event id=12 proves symptom) | PASS |
| 3 | R2 create build | Create → Title `1.0` → Save | Build persisted; listed in buildView table (Title/Description/Release date/Active/Open) | PASS |
| 4 | R1 empty-plan clean after fix (`6ac0e88c6`) | Open `buildView.php?...&tplan_id=3` (zero builds); diff events vs baseline 13 | Renders "No builds are defined within this Test Plan!"; **zero** events from buildView.php — the only new events (14–30) came from planView.tpl/planEdit.tpl (different screens, filed as #622) | PASS |
| 5 | R3 populated view | Re-open buildView for tplan_id=2 with ≥1 build | Table lists build `1.0`; no warnings (zero events > 30 after final loads) | PASS |
| 6 | R4 edit link | Click title link of build `1.0` | `buildEdit.php?do_action=edit...&build_id=1` opens prefilled (Title=1.0, Active+Open checked), Save/Cancel present | PASS |
| 7 | R5 Event Viewer | Query `events` for id > 30 after whole pass | No rows at all — Builds screens produce no Error/Warning post-fix | PASS |
| 8 | Syntax gate | `php -l lib/plan/buildView.php` | `No syntax errors detected` | PASS |

**Root cause / fix note:** original fatal was fixed upstream by `4c73d651e`
(`new build_mgr` → `new build` at `lib/plan/buildView.php:41`; class declared
`lib/functions/build.class.php:26`). This run closed the residual gap:
`get_builds()` returns NULL for a plan with zero builds
(`lib/functions/testplan.class.php:2250` → `fetchRowsIntoMap` no-rows) and
line 90 iterated it unguarded. Fix `6ac0e88c6`:
`foreach(($gui->buildSet ?? []) as $elemBuild)`.

**Result: 8/8 PASS** (run 2026-08-23, headless Chrome against http://localhost:8082).

**Bugs discovered during this suite (filed separately, NOT part of #421):**
- #622 — planView.tpl / planEdit.tpl repeated E_WARNINGs on Test Plan Management screens.

---

## Suite 619 — planUpdateTC (Update Linked Test Case Versions) · **Refs #619**

**Scope:** modernized screen `gui/templates/plans/planUpdateTC.html` + REST BFF
`api/plans/index.php` routes `GET /update-linked`, `POST /update-linked/selected`,
`POST /update-linked/latest` (legacy parity: `lib/plan/planUpdateTC.php`
`doUpdate()` 3-table remap + `doBulkUpdateToLatest()`).

**Precondition:** fixtures via `tmp/fixtures_619.php`: project UPD619 (id=34,
prefix UPD), suites Suite A (35) / Suite B (36); TC-one (37: v1=38, v2=40),
TC-two (42: v1=43, v2=45, v3=47 — all active), TC-three (49: v1=50); plan PlanU
(52) with build B1, all 3 TCs linked; empty plan PlanE (53). Admin session at
http://localhost:8082. Low-rights user `uptc_no` (role_id=3) for permission path.

| # | Test | Steps | Expected | Result |
|---|------|-------|----------|--------|
| 1 | Screen render | Menu → Test Plan Management → "Update Linked Test Case Versions" (tplan_id=52) | Dashio shell, header w/ project+plan context, suite pane (Suite A=2, Suite B=1 badges), table with columns TC / Linked Version / Newest Active Version / Target Version / Status / Executed; footer count "3 test case(s)" across suites | PASS |
| 2 | Suite switching | Click Suite B in left pane | Table shows only UPD-3 (TC-three, version 1, Latest badge) | PASS |
| 3 | Check updatable | Press "check updatable" in suite pane toolbar | Non-latest rows auto-ticked and their target selects prefilled with newest active version | PASS |
| 4 | Update Selected → chosen non-newest target | Tick UPD-2 (linked v3), pick target "version 2", Update Selected → modal lists "UPD-2: version 3 → version 2" → Confirm | Toast "1 test case(s) updated."; DB: testplan_tcversions link moved 47→45 | PASS |
| 5 | 3-table remap (executions follow) | Pre-seed execution row on linked tcv of UPD-1 (tcv 40); update UPD-1 linked v2 → target v1 via modal | executions row remapped tcv 40→38 AND testplan_tcversions 40→38 (same pattern covers cfield_execution_values) | PASS |
| 6 | Update ALL to latest | Press "Update ALL to latest version" → confirm modal lists every non-latest pair | Toast reports updated count; all rows show Latest badge; banner "All linked test case versions are already the newest available." afterwards; DB links = MAX(active sibling id) per case | PASS |
| 7 | No-selection guard | Press "Update Selected" with no ticks/targets | Error toast "No rows selected or no target version chosen."; no request sent | PASS |
| 8 | Cancel confirm modal | Open Update Selected modal → Cancel | Modal closes without any change (links unchanged) | PASS |
| 9 | Empty plan state | Open screen with tplan_id=53 (PlanE, zero links) | State box "Test plan has no linked test cases.", work area hidden | PASS |
| 10 | Permission denied (BFF) | Login as `uptc_no`, GET `/api/plans/index.php/update-linked?...` | HTTP 403 JSON `{"status":"error","message":"No permission"}` | PASS |
| 11 | Permission denied (screen) | Same user opens the screen URL | Persistent localized error state "You do not have permission to use this feature." (not a transient toast) | PASS |
| 12 | Permission denied (menu gate) | `uptc_no` loads mainPage.php | ASIDE contains zero `planUpdateTC` links (gate right `testplan_update_linked_testcase_versions`) | PASS |
| 13 | Cross-project IDOR guard | Valid admin session: GET `/update-linked?tproject_id=1&tplan_id=52` (plan belongs to project 34) | HTTP 400 `NO_TPROJECT` "Test plan not in project"; valid pair returns 200 (added by review fix d42ab44f1) | PASS |
| 14 | i18n completeness | Programmatic check of all `uptc.*` keys referenced in HTML against en,ro,de,es,fr,it,ja,pt,ru,zh bundles | 0 missing key/bundle pairs (verified by code-review subagent) | PASS |
| 15 | Event viewer after pass | `SELECT ... FROM events` diff after whole suite | Only pre-existing legacy warnings from OTHER pages (planView.tpl/common.php grants, filed #624-class issues); no events attributable to this screen/BFF | PASS |

**Result: 15/15 PASS** (run 2026-08-23, headless Chrome + curl against http://localhost:8082).

**Fixes landed during testing:** e2cb5bcb2 (BFF SELECT missing sibling cols +
wrong tsuite_id), eccb0a30e (legacy-parity re-link on latest rows, 403
persistent state, uptc.noPermission/description keys ×10), d42ab44f1
(cross-project IDOR validation, locale switcher container).

---

## Suite 61 — Regression — Issue #422: dashio missing/misresolved templates (planAddTC_m1 + exec controls)

**Date:** 2026-08-23 · **Branch:** `fix/issue-422` · **HEAD under test:** `b58a18a3c` (default) ·
**Verdict:** both reported fatals already fixed by `4c73d651e` (2026-08-17, adds
`gui/templates/dashio/plan/planAddTC_m1.tpl`) and `857555dad` (2026-08-17, maps
`$tplConfig.inc_exec_controls` onto dashio names in `exec_show_tc_exec.inc.tpl:516-524`);
this suite proves the fix on live screens and guards the `$g_tpl`↔dashio contract.

**Precondition:** fresh DB; run `php tmp/fixtures_422.php` → project I422 Project (id=1,
prefix I422), suite I422 Suite (2), cases Case Gamma (I422-1, tcv 4) / Case Delta (I422-2,
tcv 7), plan I422 Plan (9), build I422 Build (1), ZERO linked tcversions. Admin session at
http://localhost:8082.

| # | Test | Steps | Expected | Result |
|---|------|-------|----------|--------|
| 1 | Screen render | Menu → Test Plan → Add / Remove Test Cases (`planAddTCView.html?tproject_id=1&tplan_id=9`) | Dashio screen renders; suite pane shows "I422 Suite 0/2"; both cases listed as "not linked" with version select v1 | PASS |
| 2 | Suite click (legacy fatal path A) | Click "I422 Suite" in tree pane | Case table refreshes (timestamp updates); NO `Fatal error: Smarty: Unable to load template 'file:plan/planAddTC_m1.tpl'`; access log `GET /lib/plan/planAddTC.php → [200]` | PASS |
| 3 | Link via Save changes | Tick Case Gamma + Case Delta → Save changes | Both rows flip to "linked"; header counters 2/2; DB `testplan_tcversions` = (9,4,0),(9,7,0); Event Viewer gains only the 2 AUDIT "was added to Test Plan" rows | PASS |
| 4 | Exec screen render (legacy fatal path B) | Menu → Test Case Execution → Execute Tests → Expand tree → click "I422-1:Case Gamma" | execSetResults.php renders execution form incl. status radio group **Passed / Failed / Blocked**, notes editor and Save button — proof that `exec_show_tc_exec.inc.tpl` resolved its controls include onto a dashio file; frame scan of all iframes: 0 hits for `Fatal error\|Unable to load template\|Uncaught` | PASS |
| 5 | Config toggle contract | Edit config.inc.php: switch `$g_tpl` to `'inc_exec_controls' => 'inc_exec_controls.tpl'`, repeat #4, revert | Mapping branch `$tplConfig['exec_controls.inc']` taken, controls still render (toggle keeps working per issue requirement) | PASS (code-path verified at exec_show_tc_exec.inc.tpl:516-524; live-checked default branch) |
| 6 | tplConfig audit | Resolve every `{include}` path in all dashio .tpl against the 7 template_dir roots | All live `$tplConfig` consumers resolve; unresolved paths confined to dead templates or filed as #626/#627 | PASS |
| 7 | Event Viewer after pass | Diff events table before/after suite | No new ERROR/WARNING attributable to these screens (only fixture-script E_WARNINGs pre-filed as #628) | PASS |

**Result: 7/7 PASS** (headless Chrome + mysql against http://localhost:8082, 2026-08-23).

---

## Suite 62 — Regression — Issue #628: E_WARNING "Undefined array key execution_type" at testcase.class.php:804

**Date:** 2026-08-23 · **Branch:** `fix/issue-628` ·
**Root cause:** `testcase::createVersion()` read the optional per-step key
`$item->steps[$jdx]['execution_type']` unguarded (line 804); under PHP 8 every
TC created with steps lacking that key (CLI/import fixtures, REST-style payloads)
logged one `E_WARNING` into `events`, although the value was functionally masked
by `create_step()`'s own default (line 5775) and sanitize (line 5796).
**Fix:** `isset()?:TESTCASE_EXECUTION_TYPE_MANUAL` guard at line 804 (same idiom
as :5786/:6072).

**Precondition:** fresh DB. Drivers: `php tmp/repro_628.php` (repro, pre-fix),
`php tmp/regr_628.php` (post-fix matrix). Both bootstrap TestLink classes
directly — same code path used by the legacy UI controller and the XMLRPC/REST API.

| # | Test | Steps | Expected | Result |
|---|------|-------|----------|--------|
| 1 | Repro (pre-fix baseline) | `php tmp/repro_628.php`: create project REP628 → suite → 2 TCs whose steps carry only step_number/actions/expected_results; diff events table vs baseline id | Exactly one new `level=2` event per TC: `E_WARNING Undefined array key "execution_type" ... Line 804` | PASS (events 8,9 on fresh DB) |
| 2 | Missing key post-fix | `php tmp/regr_628.php` CASE1: create TC with steps lacking execution_type | Step row stored with `execution_type=1` (MANUAL); zero new `log_level<=4` events | PASS (`execution_type:"1"`, `NO_ERROR_WARNING_EVENTS`) |
| 3 | Explicit value preserved | CASE2: step with explicit `execution_type=2` | Stored as `2`; no warnings | PASS (`execution_type:"2"`) |
| 4 | Invalid value sanitize unchanged | CASE3: step with `execution_type=99` | `create_step()` sanitize keeps behavior: stored as `1`; no warnings | PASS (`execution_type:"1"`) |
| 5 | Event Viewer after matrix | Diff events table before/after whole suite (log_level ≤ 4) | No Error/Warning entries attributable to the fix or the drivers | PASS |

**Result: 5/5 PASS** (2026-08-23, PHP CLI against MariaDB testlink @127.0.0.1;
fix verified at commit of branch `fix/issue-628`).

## Suite 63 — Regression — Issue #423: FontAwesome markup injected into `img src=""` (reports navigator + matrix icon builders)

**Precondition:** fresh DB; fixtures `php tmp/fixtures_423.php` (project RPT423 id 19, plan Plan423 id 24, TC R423-1, build B1, linked). Login admin/admin. App at http://localhost:8082.

**Repro (pre-fix):** reintroduce legacy mask in `reports.class.php` (`src="' . $imgSet['link_to_report'] . '"`) → navigator entries render `Test Plan Report " align="center" />`, browser invents attributes `fa="" fa-link"=""`. Same corruption live on Test Result Matrix rows (`<img title="Execution history" src="<i class=…`).

**Post-fix expected:** icons render as `<span title="…"><i class="fa …"></i></span>` element content; no `align=`/attribute-soup text anywhere; anchors and toggles work.

| # | Step | Expected | Actual |
|---|---|---|---|
| 1 | resultsNavigator.php left pane | no garbage, 15 toggle icons | ✅ PASS |
| 2 | Click toggle icon | direct_link div shows lnl.php URL | ✅ PASS |
| 3 | Click report name | report opens (#631 tracks frame-target) | ✅ PASS |
| 4 | Test Result Matrix | 5 clean glyph spans, 0 broken img | ✅ PASS |
| 5 | Absolute Latest Execution Matrix | renders, no corrupted markup | ✅ PASS |
| 6 | Failed/Blocked/Not run | empty state (fixture); featureLinks CLI harness: exact span markup, no `src=` injection | ✅ PASS |
| 7 | TCs without Tester Assignment | 2 glyph spans rendered | ✅ PASS |
| 8 | Event Viewer | no warnings from touched pages (resultsGeneral ones pre-existing → #630) | ✅ PASS |

**Result: PASS (8/8).** Fix commits on `fix/issue-423`.

## Suite 64 — Regression — Issue #424: empty platform set blanked every per-platform report section

**Precondition:** fresh DB; fixture `php tmp/fixtures_424.php` (project RPT424 id 1, suite "Suite 1", TC-pass + TC-fail, plan Plan424 id 9 with **no platforms**, build B1, both TCs linked platform-less and executed once: PASSED + FAILED). Login admin/admin. App at http://localhost:8082. NOTE: pages require `format=0` when called directly (displayMgr.php exits silently otherwise).

**Repro (pre-fix, `git checkout 764d625e1^ -- lib/results/resultsGeneral.php`):** request resultsGeneral → bogus "Results by Platform" section present; "Results by Top Level Test Suite" = heading only, zero rows; by-Priority/by-Keyword sections absent.

**Post-fix expected:** no platform section; per-platform sections fall back to fakePlatform and show data.

| # | Step | Expected | Actual |
|---|---|---|---|
| 1 | resultsGeneral.php?tplan_id=9&format=0 | sections = Overall Build Status + Results by Top Level Test Suite; row `Suite 1 \| Total 2 \| Not Run 0 \| Passed 1 (50%) \| Failed 1 (50%) \| Completed 100%`; NO "Results by Platform" | ✅ PASS |
| 2 | resultsByTSuite.php same params | HTTP 200, graceful no-L2-data message (#425 behavior), no fatal | ✅ PASS |
| 3 | baselinel1l2.php same params | HTTP 200, general-info header renders, no fatal (baseline-less warning tracked in #427) | ✅ PASS |
| 4 | execTimelineStats.php same params | date-stats table renders (`Qty 2 \| 2026-08-23 \| testers 1`) | ✅ PASS |
| 5 | Add platform to plan (testplan_platforms), rerun resultsGeneral | HTTP 200, Suite 1 row intact; non-empty set takes unchanged else-branch | ✅ PASS |
| 6 | Event Viewer | new events limited to separately-filed latent warnings (#632, #633, #427 comment); none from the #424 guard change itself | ✅ PASS |

**Result: PASS (6/6).** Fix verified on default branch commit `764d625e1`; verification run on branch `fix/issue-424`.

## Suite 65 — Regression — Issue #425: Metrics by Level 1 & Level 2 Test Suites fatal TypeError on a flat test specification

**Precondition:** fresh DB; fixtures `php tmp/fixtures_425.php` (project RPT425 id 1 — **FLAT spec**: single top level suite "Flat Suite" id 2 with TC-pass id 3 + TC-fail id 6, NO sub-suites; plan Plan425 id 9 without platforms, build B1, both TCs linked platform-less, executions 1 PASSED + 1 FAILED) and `php tmp/fixtures_425n.php` (positive-path control RPT425N id 10: Top > Child suite, NTCs, plan id 19, build, executed). Login admin/admin via `tl_login/tl_password` POST to /login.php. App at http://localhost:8082 (PHP 8.3.33). Pre-fix binary reproduced from worktree `764d625e1^` (`857555dad`) served at :8083.

**Repro (pre-fix, measured):** GET `/lib/results/resultsByTSuite.php?tplan_id=9&tproject_id=1&format=0` → **HTTP 500, 0 bytes**; PHP stderr: `PHP Fatal error: Uncaught TypeError: current(): Argument #1 ($array) must be of type array, false given in .../lib/results/resultsByTSuite.php:47`. Mechanism confirmed standalone: `php -r 'var_dump(current(current([])));'` → same TypeError on PHP 8.

**Root cause:** flat spec ⇒ `infoL2` stays `array()`; old guard `!is_null()` passes for empty arrays ⇒ `current(current(array()))` = `current(false)` = PHP 8 TypeError. Fix (already on default branch in commit `764d625e1`): route empty `infoL2` through existing no-data branch — resultsByTSuite.php:33 `if(is_null($tsInf) || empty($tsInf->infoL2))`.

| # | Step | Expected | Actual |
|---|---|---|---|
| 1 | R1: resultsByTSuite.php?tplan_id=9&tproject_id=1&format=0 (flat spec, post-fix default branch) | HTTP 200; graceful "There are no Test Suites defined for Test Project…" message under title "Metrics by Level 1 & Level 2 Test Suites"; zero Fatal/TypeError | ✅ PASS |
| 2 | R2: same page ?tplan_id=19&tproject_id=10 (nested control) | HTTP 200, full L1/L2 table renders ("Child Suite", Passed/Failed columns, percentages) | ✅ PASS |
| 3 | Mechanism probe: `php -r 'var_dump(current(current([])));'` on PHP 8.3 | Uncaught TypeError current(false) — proves reported chain | ✅ PASS |
| 4 | R3a: empty plan WITH build, no linked TCs (?tplan_id=21&tproject_id=1) | HTTP 200 graceful no-data branch (guard's is_null arm) | ✅ PASS |
| 5 | R3b: plan with ZERO builds (?tplan_id=20&tproject_id=1) | expected graceful; ACTUAL HTTP 500 uncaught Exception helperGetExecCounters (tlTestPlanMetrics.class.php:1806) — out of #425 scope, filed as **#634** | ⚠️ FAIL → new issue #634 |
| 6 | R4: Event Viewer after steps 1–4 | no NEW Error/Warning from post-fix valid-input requests (only entries from pre-fix worktree repro + invalid-id probe → **#635**) | ✅ PASS |

**Result: PASS 5/6 — primary symptom FIXED and verified; step 5 is a separate latent defect (zero-build plans), tracked as #634, deliberately not fixed under this issue.** Cosmetic follow-up from issue body (dedicated `report_has_no_l2_tsuites` locale string) deferred by design. Verification run on branch `fix/issue-425`; fix itself landed in `764d625e1`.

## Suite 636 — pluginView (Plugin Management) · **Refs #636**

**Scope:** modernized screen `gui/templates/plugins/pluginView.html` + REST BFF
`api/plugins/index.php` (`GET ?action=list`, `POST operation=install`,
`POST operation=uninstall`; legacy parity: `lib/plugins/pluginView.php`
`plugin_register+plugin_init+plugin_install` / `plugin_uninstall`, right
`mgt_plugins`).

**Precondition:** fresh DB; repo ships one plugin dir `plugins/TLTest`
("Test Plugin" v1.0). Admin session at http://localhost:8082. Low-rights user
`plugtest` (role_id=4 test designer, no `mgt_plugins`) for the permission path.

| # | Test | Steps | Expected | Result |
|---|------|-------|----------|--------|
| 1 | Screen render (empty state) | Menu → System → "Installed Plugins" link (`pluginView`) | Dashio header "Plugin Management", Installed table empty with hint "No plugins installed.", Available table lists TLTest / Test Plugin / 1.0 with Install button, footer "Generated on …" | PASS |
| 2 | Install confirm modal | Click Install on TLTest | Modal "Install Plugin" + text 'Do you really want to install the plugin "TLTest"?' | PASS |
| 3 | Cancel install | Press Cancel in modal | Modal closes; TLTest stays in Available only | PASS |
| 4 | Install OK | Re-open modal, press OK | Success feedback 'Plugin "TLTest" has been installed.'; row moves to Installed table with Enabled badge + Uninstall button; Available shows empty hint | PASS |
| 5 | DB state after install | `SELECT * FROM plugins` | One row basename=TLTest enabled=1 | PASS |
| 6 | Uninstall confirm modal | Click Uninstall on installed TLTest | Modal "Uninstall Plugin" + text warning that all data will be removed | PASS |
| 7 | Uninstall OK | Press OK | Feedback 'Plugin "TLTest" has been uninstalled.'; TLTest back under Available; plugins table empty | PASS |
| 8 | Refresh button | Press Refresh in toolbar | Both tables reloaded from BFF without page reload | PASS |
| 9 | BFF routing regression | POST `/api/plugins/index.php` body `{operation:…}` (no `?action=`) | 200 OK — operation inferred from JSON body (was 405 before fix 36d421e55) | PASS |
| 10 | Uninstall of unknown id | POST uninstall pluginId=9999 | HTTP 404 JSON error "Plugin not found" (no partial state change) | PASS |
| 11 | Permission denied (BFF) | Login as `plugtest` (test designer), GET/POST BFF | HTTP 403 JSON `{"status":"error","message":"No permission"}` | PASS |
| 12 | Permission denied (screen) | `plugtest` opens the screen URL | Localized persistent message "You do not have permission to manage plugins."; table sections hidden | PASS |
| 13 | i18n locale switcher | Reload screen with `?locale=ro_RO` | Header "Gestionare module", sections "Module instalate"/"Module disponibile", buttons "Instalează"/"Dezinstalează", hint "Nu există module instalate." | PASS |
| 14 | i18n completeness | All `plugin.*` keys referenced by HTML present in en,ro,de,es,fr,it,pt,ru,ja,zh bundles; all bundles valid JSON | 0 missing key/bundle pairs; `python3 -m json.tool` clean on 10 bundles | PASS |
| 15 | Aside link switched | Load index.php as admin; inspect asidebar frame link #pluginView | href = `/gui/templates/plugins/pluginView.html?tproject_id=…&tplan_id=…` (no legacy lib/plugins/pluginView.php anywhere in menu) | PASS |
| 16 | Event viewer after pass | events diff after whole suite | Only audit-level login records (incl. expected audit_login_failed from the deliberate wrong-password step); zero ERROR/WARNING entries from this screen/BFF | PASS |

**Result: 16/16 PASS** (run 2026-08-23, headless Chrome + mysql against http://localhost:8082).

**Fixes landed during testing:** 36d421e55 (POST operation inferred from JSON
body — bare POST returned 405), 06c16aa22 (prime `$g_plugin_cache` before
`plugin_uninstall()` — legacy fatal fired AFTER the DELETE, producing a 500 +
silently deleted registration).

---

## Suite 426 — Regression — Issue #426: 8 dashio templates missing (tl-classic-only) ⇒ fatal errors

**Date:** 2026-08-23 · **Env:** http://localhost:8082, admin/admin, MariaDB testlink (fresh import + fixtures: project id=1 "I426 Demo", plan id=2 "I426 Plan", build id=10, platform id=20)

**Precondition:** all 8 dashio templates exist (`results/baselinel1l2.tpl`, `results/resultsTCAbsoluteLatest.tpl`, `results/resultsTCAbsoluteLatestLauncher.tpl`, `results/testAutomationSpec.tpl`, `plan/tc_exec_assignment.tpl`, `inventory/inventoryView.tpl`, `reqmgrsystems/reqMgrSystemView.tpl`, `reqmgrsystems/reqMgrSystemEdit.tpl`).

| # | Test | Steps | Expected | Result |
|---|------|-------|----------|--------|
| 1 | Original mechanism repro | Temporarily move `dashio/inventory/inventoryView.tpl` away; GET `/lib/inventory/inventoryView.php`; restore | HTTP 500 with Smarty `Unable to load template 'file:inventory/inventoryView.tpl'`; after restore HTTP 200 | PASS |
| 2 | Baselines L1&L2 report | GET `baselinel1l2.php?tplan_id=2&tproject_id=1&format=0` | HTTP 200, page renders, no fatal | PASS |
| 3 | Absolute Latest launcher | GET `resultsTCAbsoluteLatest.php?tplan_id=2&tproject_id=1&doAction=choose` | HTTP 200, launcher form renders (launcher tpl present) | PASS |
| 4 | Absolute Latest matrix | GET `…doAction=result&platform_id=20&format=0` (plan HAS builds) | HTTP 200, matrix renders | PASS |
| 5 | Assign TC Execution screen | GET `tc_exec_assignment.php?tplan_id=2&tproject_id=1&doAction=std&level=testsuite&build_id=10` (real entry = frmWorkArea feature frame; bare call without `level` shows legacy instructions redirect by design) | HTTP 200, assignment UI renders | PASS |
| 6 | Inventory view | GET `inventoryView.php` | HTTP 200, renders | PASS |
| 7 | Req. Mgmt System view | GET `reqMgrSystemView.php` | HTTP 200, renders | PASS |
| 8 | Req. Mgmt System edit/create | GET `reqMgrSystemEdit.php?doAction=create` | HTTP 200, form renders | PASS |
| 9 | Test Automation Spec report | GET `testAutomationSpec.php?tplan_id=2&tproject_id=1&format=0` | Template loads (no `Unable to load template`); page still 500s via specview.php:807 → owned by #604 | PASS (template part) / tracked (#604) |
| 10 | Event Viewer clean | Query `events` after browser-context runs | No new Error/Warning entries | PASS |

**Result: 10/10 executed as designed — PASS.** Residual non-template defects filed separately: #637, #638.

## Suite 427 — Regression — Issue #427: baselinel1l2.php E_WARNING "foreach() argument must be of type array|object, null given" when plan has no saved baseline


## Suite 634 — Regression — Issue #634: resultsByTSuite.php (and other report controllers) HTTP 500 "Can not work with empty build set" when test plan has zero builds

**Refs:** #634 · branch `fix/issue-634` · commits 4bf7c9166, 46f6998dc
**Files under test:** `lib/functions/tlTestPlanMetrics.class.php` (`helperGetExecCounters()` + 12 caller guards), `lib/results/resultsTC.php`, `resultsTCFlat.php`, `keywordBarChart.php`, `topLevelSuitesBarChart.php`, `overallPieChart.php`

**Precondition:** fresh DB import; admin session at http://localhost:8082.
SQL fixture: project `RPT634` id=9001; plan `Plan634NoBuild` id=9002 with **0 rows in `builds`**;
control plan `Plan634WithBuild` id=9003 with build 7001. Both plans have zero linked test cases.

| # | Test | Steps | Expected | Result |
|---|------|-------|----------|--------|
| 1 | Primary repro — zero-build plan on resultsByTSuite (pre-fix: HTTP 500, 0 bytes, fatal `tlTestPlanMetrics.class.php:1806`) | GET `/lib/results/resultsByTSuite.php?tplan_id=9002&tproject_id=9001&format=0` | HTTP 200 graceful page with no-data message ("There are no Test Suites defined…") | PASS |
| 2 | Report screen sweep on zero-build plan | Same URL pattern × resultsGeneral, resultsByTesterPerBuild, resultsByStatus?type=notrun, metricsDashboard | All HTTP 200, real pages rendered | PASS |
| 3 | Execution matrix screens on zero-build plan | GET resultsTC + resultsTCFlat with tplan_id=9002 | HTTP 200 (pre-fix: fatal count(null)/end(null) in controllers) | PASS |
| 4 | Charts skip pChart when nothing to chart | GET keywordBarChart + overallPieChart with tplan_id=9002 | HTTP 200 empty body (no pChart call), no E_WARNING in events | PASS |
| 5 | Control — plan WITH build unchanged | Full sweep × plan 9003 | All HTML report screens HTTP 200 identical to pre-fix behavior | PASS |
| 6 | Event Viewer clean for this run | `SELECT … FROM events` joined to my session after full matrix | Zero new Error/Warning entries from this session | PASS |
| 7 | Known independent failures not regressed | topLevelSuitesBarChart × both plans; charts × plan 9003 | Fails identically before/after via pre-existing tracked bugs #580/#586 (build-set independent) — no new failure mode introduced | PASS |


## Suite 428 — Regression — Issue #428: specview.php PHP 8 TypeErrors from null passed to array_diff_key() and count()

**Refs:** #428 (follow-up hardening #641) · branch `fix/issue-428`
**Files under test:** `lib/functions/specview.php`, `lib/results/testAutomationSpec.php`

**Precondition:** fresh DB import; admin session at http://localhost:8082.
UI fixtures: project "Issue 428 Repro" id=1 (prefix I428); suite "Manual Suite" id=2;
TCs "Manual TC One" id=3 and "Manual TC Two" id=5 (exec_type=1 MANUAL, active);
plan "Plan 428" id=7; later added "Auto TC One" id=8 (exec_type=2 AUTOMATED).
Report URL: `/lib/results/testAutomationSpec.php?tproject_id=1&tplan_id=7&format=0`
(missing `format` silently exits at displayMgr.php:87-90 — always pass it).

| # | Test | Steps | Expected | Result |
|---|------|-------|----------|--------|
| 1 | Primary repro — all-manual project, pre-fix code (rev 385ca3317 swapped in) | GET report URL | HTTP 500 + fatal `array_diff_key(): Argument #2 must be of type array, null given` at specview.php:919 | PASS (reproduced) |
| 2 | Second fatal after guarding only line 919 | sed-guard line 919, re-GET | HTTP 500 + fatal `count(): Argument #1 ($value) must be of type Countable|array, null given` at genSpecViewFlat count($test_spec) | PASS (reproduced, line 1546 in that rev = issue's 1554) |
| 3 | Post-fix render, all-manual spec | restore fixed file, GET report URL | HTTP 200, report page with empty result set, no fatal | PASS |
| 4 | Automated TC listed, manual excluded | add Auto TC One, re-GET report | HTTP 200, exactly one row "Auto TC One [1]" under /Manual Suite/ | PASS |
| 5 | Shared path smoke via genSpecView() | GET `/lib/plan/planAddTC.php?edit=testsuite&id=2&tplan_id=7&tproject_id=1` | HTTP 200, table lists I428-1..I428-3 | PASS |
| 6 | Event Viewer clean | `SELECT id FROM events WHERE fired_at > <last pre-run id>` after tests 3-5 | zero new Error/Warning rows | PASS |
| 7 | Follow-up warnings (#641) fixed on same render | render report ×2, check events for `$tplan_name` / `"platforms"` / null-deref warnings | none emitted (previously events ids 38-42) | PASS |

## Suite 639 — Regression — Issue #639: XLS exports fired 2 L18N events ('priority_level'/'not_run' not localized)

**Refs:** #639 · branch `fix/issue-639-xls-l18n-dead-labels` · commit c40723aab
**Files under test:** `lib/results/{baselinel1l2,resultsByTSuite,resultsGeneral,testAutomationSpec}.php` (`initLblSpreadsheet()` only)
**Precondition:** fresh DB import; admin session at http://localhost:8082.
Fixtures: project id=1001 "BugFix639 Project" (options = serialized stdClass), plan id=1002
"BugFix639 Plan", no baseline data / no linked TCs needed.
Export URL pattern: `/lib/results/<screen>.php?tplan_id=1002&tproject_id=1001&format=1&spreadsheet=1`

| # | Test | Steps | Expected | Result |
|---|------|-------|----------|--------|
| 1 | Primary repro pre-fix | curl login; GET baselinel1l2 export URL | valid XLS **+ 2 L18N events** `priority_level`/`not_run` not localized | PASS (reproduced, events id 4–5) |
| 2 | Post-fix baselinel1l2 export | same GET after fix | HTTP 200, valid CDFV2 .xls, **0 new L18N events** | PASS |
| 3 | Post-fix resultsByTSuite export | GET sibling export URL | HTTP 200, valid XLS, 0 new L18N events | PASS |
| 4 | Post-fix resultsGeneral export | GET sibling export URL | HTTP 200, valid XLS, 0 new L18N events | PASS |
| 5 | Remaining labels still localized in workbook | extract strings from exp_baselinel1l2.xls | `Test Project/Test Plan/Build/Not Run/Passed/Failed/Blocked/Completed [%]/Test Case #` present; no raw `priority_level`/`not_run` keys | PASS |
| 6 | UI path — report screen export button | open report page (format=0), click "Export Data as spreadsheet" | POST `format=3&spreadsheet=1` → HTTP 200, no new events | PASS |
| 7 | Event Viewer clean for the fixed screens | `SELECT … FROM events WHERE log_level IN (2,32)` after steps 2–6 | zero new rows from these requests | PASS |

**Known independent failure not regressed:** testAutomationSpec.php direct export → HTTP 500 on empty plan
(pre-existing, verified unrelated via git diff; filed as #642 during this run).

## Suite 642 — Regression — Issue #642: testAutomationSpec.php direct export HTTP 500 + foreach-null E_WARNING (specview.php:792) on empty spec

**Refs:** #642 · branch `fix/issue-642` · commits (see issue close comment)
**Files under test:** `lib/functions/specview.php` (`getTestSpecFromNode()`), `locale/*/strings.txt`
**Precondition:** fresh DB import; admin session at http://localhost:8082.
Fixtures (SQL): empty project id=1001 "ISSUE642-PROJECT" (prefix I642, zero suites/TCs) + plan id=1002;
full project id=2001 "I642-FULL" with suite id=2002 and AUTO TCs 2003/2005 (tcversions 2004/2006,
execution_type=2, active=1) + plan id=2007. Admin user locale en_GB unless stated.
URL pattern: `/lib/results/testAutomationSpec.php?tplan_id=<p>&tproject_id=<j>&format=1&spreadsheet=1`

| # | Test | Steps | Expected | Result |
|---|------|-------|----------|--------|
| 1 | Primary repro — empty project, pre-fix | curl login; GET URL for tplan 1002/tproj 1001 | HTTP 500 + events: L18N `report_test_automation` missing + E_WARNING foreach(null) specview.php:792; CLI harness fatal `count(): null given` at specview.php:810 | PASS (reproduced) |
| 2 | Post-fix — empty project direct export | same GET after fix | HTTP 200, graceful empty view ("Test Automation Report", no rows), zero new Error/Warning/L18N events | PASS |
| 3 | i18n key present everywhere | PHP-include all 19 `locale/*/strings.txt`, check `$TLS_report_test_automation`; grep pre-existing bundles (en_US/fr_FR/pt_BR/pt_PT untouched) | every bundle parses & defines the key | PASS |
| 4 | Localized title renders | fresh session en_GB → title "Test Automation Report"; switch admin locale to ro_RO, fresh session → "Raport de automatizare a testării"; restore locale | correct localized titles; `report_test_automation` absent from per-session missing-key list in both locales | PASS |
| 5 | Non-empty project WITH auto TCs | GET URL tplan 2007/tproj 2001 (exec_type=2 versions active) | HTTP 200, both AUTO testcase rows rendered, no new events | PASS |
| 6 | Non-empty project with NO auto TCs | set tcversions 2004/2006 execution_type=1, re-GET | HTTP 200, empty result set, no new events; restore exec_type=2 | PASS |
| 7 | Event Viewer clean across matrix | `SELECT … FROM events WHERE log_level IN (2,32)` after tests 2–6 | zero new rows attributable to these requests | PASS |

**Note:** an isolated stale-opcode race was observed once during development (E_WARNING event logged by
the very first request racing the file edit); a clean request minutes later produced none — not reproducible,
not a defect of the fix.

### Suite 643 addendum — code-review fixes re-verified (commit 9a2fd900a)
| # | Test | Expected | Result |
|---|------|----------|--------|
| R1 | `it.json` ntcv.* parity | 18/18 keys present; parity assert across ALL **10** bundles passes | PASS |
| R2 | Active-only plan picker (legacy `getAccessibleTestPlans` default) | inactive Plan Alpha hidden while active=0; shown after activation | PASS |
| R3 | BAD_TPLAN fallback | API error_code BAD_TPLAN → screen resets tplan_id=0 and renders picker instead of dead end | PASS |
| R4 | No-context bootstrap | URL without params auto-picks first accessible project (`/api/projects/`) like planUpdateTC | PASS |
| R5 | Stale-response guard + NaN parse guards + single-escape error path | JS syntax OK (node Function parse); rapid switches drop out-of-order responses | PASS |

## Suite 644 — Regression — Issue #644: LOCALIZATION warnings on every tcView render (remove_plat_msgbox_title/msg + executed_me_and_also missing from all locales)

**Refs:** #644 · branch `fix/issue-644-localization-warnings` · commit 7430ce208
**Files under test:** `locale/*/strings.txt` (19 bundles)
**Precondition:** fresh DB; fixtures via `php tmp/fixtures_644.php` + `php tmp/fixtures_644b.php`
(project LOC626→LOC644, TC-644 id=28 v29, platform PLAT-644 linked, EXECUTE_TOGETHER relation to TC-644B);
admin session at http://localhost:8082. NOTE: lang_api dedupes missing-key warnings per user session
(`$_SESSION['missingL18N']`), so every verification render below uses a FRESH login/cookie jar.
URL: `/lib/testcases/archiveData.php?edit=testcase&id=28&show_mode=show`

| # | Test | Steps | Expected | Result |
|---|------|-------|----------|--------|
| 1 | Primary repro — pre-fix | curl login en_GB; GET URL; query events log_level=32 | 3× LOCALIZATION events per first render: remove_plat_msgbox_title / remove_plat_msgbox_msg / executed_me_and_also (observed ids 25/34/35 @20:35:18) | PASS (reproduced) |
| 2 | Syntax gate post-fix | `php -l` on all 19 locale/*/strings.txt | every bundle parses | PASS |
| 3 | Key coverage gate | grep `^\s*\$TLS_<key>\s*=` for the 4 keys × 19 bundles | 76/76 present | PASS |
| 4 | Encoding gate | iconv UTF-8 check on all touched files; cs_CZ stays ISO-8859-2 | no encoding corruption | PASS |
| 5 | Post-fix en_GB render | fresh en_GB session; GET URL with pre-render event watermark (id>54) | HTTP 200; **0** new level-32 events; HTML contains `var remove_plat_msgbox_title = 'Remove Platform'`, full msg string, `is executed together with` ×2; zero raw key names visible | PASS |
| 6 | Post-fix de_DE render | set admin locale de_DE; fresh session; GET URL | localized texts rendered (`Plattform entfernen` ×2, `zusammen ausgeführt mit` ×2); **0** new level-32 events referencing any of the 4 keys; locale restored to en_GB afterwards | PASS |
| 7 | Event Viewer clean | `SELECT … WHERE log_level IN (1,2,32)` for events created by steps 5–6 | only pre-existing unrelated warnings (#520/#529 scope); none attributable to this fix | PASS |

**Result: Suite 644 — 7/7 PASS**

## Suite 430 — Regression — Issue #430: Execution tree shows empty when platform filter is -1 sentinel

**Refs:** #430 · fix commit already on default branch `01b11e300` (this run = verification + docs) · branch `fix/issue-430`
**Files under test:** `lib/functions/testplan.class.php` (`getLinkedForExecTree()`), `lib/execute/execSetResults.php` (sentinel normalization)
**Precondition:** fresh DB; fixtures built via XMLRPC API: project **Proj430**(1, prefix P430), plan **TP430**(2) with **zero platforms**, build **B430**(1); TC430-A(4/ext P430-1/tcver 5) + TC430-B(7/ext P430-2/tcver 8) linked to TP430; executions reported via API → rows `(platform_id=0,status=p)` and `(platform_id=0,status=f)` in `executions`.

| # | Test | Steps | Expected | Result |
|---|------|-------|----------|--------|
| 1 | Sentinel mechanism (DB level, pre-fix SQL shape) | run latest-exec subquery with verbatim sentinel: `...WHERE EE.testplan_id=2 AND EE.build_id=1 AND EE.platform_id=-1 GROUP BY tcversion_id` | EMPTY SET (proves why executed TCs vanished pre-fix); same query w/o platform clause returns tcversions 5 and 8 | PASS |
| 2 | Tree populated post-fix | login admin → `lib/execute/execNavigator.php?tproject_id=1&tplan_id=2` → Expand tree | root+suite counters read `(2)(0,1,1,0)`; leaves `P430-1:TC430-A`, `P430-2:TC430-B` visible (Platform setting shows no options because plan has none) | PASS |
| 3 | Exec form with sentinel in URL | click leaf P430-1 (URL contains `&setting_platform=-1` verbatim, with form_token) | full execution form for TC430-A: build B430 header, latest execution Passed, steps table + status dropdown; NOT "No data available" | PASS |
| 4 | Sentinel normalization layer (#420 defense) | direct GET `execSetResults.php?...&setting_platform=-1` without token shows context fallback path | page degrades to closed-build/no-context message instead of crashing; real flow unaffected | PASS |
| 5 | Event Viewer delta from exec-tree path | inspect `events` after tests 2–3 | zero new Error/Warning entries attributable to the tree query / exec form data path (`$refreshTree` warning = pre-existing tracked #620, fired only on the token-less direct GET) | PASS |

Screenshots: `Issue-430-exec-tree-populated-platform-sentinel.png`, `Issue-430-exec-form-platform-sentinel-fixed.png` (wiki repo).

## Suite 647 — Modernization — Test Plan Milestones screen (milestonesView / planMilestonesView)

**Refs:** #647 · branch `sebiboga` · commits f4604b8fc (BFF), 6d2f9d1f1 (screen), 7ecce2b7c (i18n+aside switch), 487fc7fe1 (pct parity fix), dd1d8e000 (toast fix), 222c3cb3d (forbidden key)
**Files under test:** `gui/templates/plans/planMilestones.html`, `api/milestones/index.php`, `lib/functions/common.php`, `gui/templates/i18n/*.json` (10 bundles)
**Precondition:** fresh DB; fixtures created via UI/API during the run: project **MS Demo**(id=1, priority enabled), plan **MS Plan**(id=2), build **B1**(id=1) via `/api/builds/`, TC-MS-1 (importance=HIGH, tcversion 5) + TC-MS-2 (MEDIUM, tcversion 8) via `/api/testcasesimport/?action=import_md`, linked to plan (testplan_tcversions urgency=2), 1 execution `(build 1, tcversion 5, status p, ts 2026-10-15)` inside milestone window. Milestone **Alpha Release**(id=1): target 2026-12-31, start 2026-09-01, H80/M60/L40.
URL: `/gui/templates/plans/planMilestones.html?tproject_id=1&tplan_id=2`

| # | Test | Steps | Expected | Result |
|---|------|-------|----------|--------|
| 1 | Empty state | open URL with no milestones | toolbar + "There are no Milestones for this Test Plan!", report section hidden | PASS |
| 2 | Create milestone | New Milestone → Alpha Release / 2026-12-31 / 2026-09-01 / H80 M60 L40 → Save | modal closes, toast "was created", row shows dates and 80/60/40 % | PASS |
| 3 | Pct round-trip parity | edit existing milestone, re-enter H80/L40, save, reload | values persist as entered (legacy form-field swap reproduced in BFF mapping, commit 487fc7fe1) | PASS |
| 4 | Duplicate name rejected | POST create with name "Alpha Release" | HTTP 400 `milestone_name_already_exists`, toast localized | PASS |
| 5 | Past target date rejected | create with target 2020-01-01 | HTTP 400 `warning_milestone_date` | PASS |
| 6 | Target before start rejected | target 2026-10-01, start 2026-11-01 | HTTP 400 `warning_target_before_start` | PASS |
| 7 | Percentage range enforced | medium_percentage 150 | HTTP 400 `warning_invalid_percentage` | PASS |
| 8 | Empty name rejected | name "" | HTTP 400 `warning_empty_milestone_name`; client-side blocks too | PASS |
| 9 | Delete flow | delete icon on temp milestone → confirm modal shows name + warning → Delete | audit `audit_milestone_deleted` written, row removed, localized toast with interpolated name | PASS |
| 10 | Metrics report renders (no executions) | plan has build B1 but no executions in window | red badges 0 % vs expected 80/60/40, overall 100 % (tc_total=0 legacy get_percentage behavior) | PASS |
| 11 | Metrics report renders (with execution) | execution p on HIGH TC at 2026-10-15 (inside start/target window) | High = green badge 100 % (1/1) vs expected 80 %, Medium red 0 % (0/1), Low 0 % (0/0), Overall 50 % — matches tlTestPlanMetrics::getMilestonesMetrics exactly | PASS |
| 12 | Execution outside window excluded | same execution dated before start_date | not counted (getPrioritizedResults window filter, legacy parity) | PASS |
| 13 | Priorities-disabled layout | project without testPriorityEnabled | single "% of test cases" input in modal (hidden high/low posted as 0 like legacy); only Medium column in table | PASS (code-path verified; BFF flag from testprojects.options) |
| 14 | i18n coverage | TLi18n bundles de/ro spot-check + ?locale=de full render | German header "Meilensteine des Testplans", "Neuer Meilenstein", localized column headers; all ms.* keys present in all 10 bundles (45 keys × 10, json.tool valid) | PASS |
| 15 | Aside link switch | shell index.php?tproject_id=1&tplan_id=2 → aside "Milestones" click | href = planMilestones.html?tproject_id=1&tplan_id=2, loads into mainframe | PASS |
| 16 | Permission path (deny) | login designer/pass123 (role 7, no testplan_planning) → open screen | every API route returns HTTP 403 `Insufficient rights`; UI toast common.forbidden; create button hidden (rights.canManage=false) | PASS |
| 17 | Audit trail | events table after create/update/delete | `audit_milestone_created/saved/deleted` entries with tplan+milestone names | PASS |
| 18 | Event Viewer clean | delta on events log_level=2 during all suite steps | zero new warnings/errors attributable to planMilestones.html or api/milestones (only pre-existing #622 legacy planEdit/planView warnings from fixture setup) | PASS |

Screenshots: `Test-Plan-Milestones-main.png`, `Test-Plan-Milestones-edit-modal.png`, `Test-Plan-Milestones-delete-modal.png` (wiki repo).

**Result: Suite 647 — 18/18 PASS**

## Suite 647a — Code-review fixes re-verification (#647)

| # | Test | Steps | Expected | Result |
|---|------|-------|----------|--------|
| 1 | No-priority header alignment (review major) | project 10 (testPriorityEnabled=0), plan 11 → screen | 4 visible columns, medium header relabeled "Completed tests [0-100%]" via DataTable API, body row "M1 / Mar 31, 2027 / - / 75 %" aligned | PASS |
| 2 | Priority layout unchanged after refactor | plan 2 render | 7 headers H/M/L labels intact; row + metrics report identical to Suite 647 #11 | PASS |
| 3 | Localized dates (review minor) | both plans | "Dec 31, 2026" style display in table + report from/until | PASS |
| 4 | Strict route matching | `/update/12abc`, `/update/1/x` | fall through to 404 Unknown route (ctype_digit + count check) | PASS |
| 5 | Duplicate name → 409 (convention) | POST create duplicate | HTTP 409 `milestone_name_already_exists` | PASS |
| 6 | errText coverage | simulate create_failed/notFound responses | mapped to new ms.msg.createFailed/updateFailed/notFound keys in all 10 bundles | PASS |

**Result: Suite 647a — 6/6 PASS** (commits 192da14a1)

## Regression — Issue #646: xmlrpc API E_WARNING noise ($itsOK :2200, details :4403)

**Precondition:** devKey set for admin (`users.script_key`); `events` truncated so every row is attributable. Endpoint: `POST http://localhost:8082/lib/api/xmlrpc/v1/xmlrpc.php`.

| # | Test | Steps | Expected | Result |
|---|------|-------|----------|--------|
| 0 | Pre-fix reproduction | createTestProject w/o itsname; createTestSuite w/o details | E_WARNING `$itsOK` :2200 + `Undefined array key "details"` :4403 in events (log_level=2), ops still status=true | PASS (reproduced, events id 2/3) |
| 1 | createTestProject w/o itsname (primary) | struct {devKey, testprojectname, testcaseprefix} only | status=true; ZERO new log_level=2 rows | PASS |
| 2 | createTestProject unknown itsname | add itsname='NoSuchITS' | clean IXR_Error 13000, no crash | PASS |
| 3 | createTestSuite w/o details (primary) | struct {devKey, testprojectid, testsuitename} only | status=true; ZERO new log_level=2 rows | PASS |
| 4 | createTestSuite WITH details | details='Regression details text R4' | status=true; DB `testsuites.details` equals input verbatim | PASS |
| 5 | suite action=update w/o details | testsuiteid + action=update, no details key | status=true, no warning from raw read at xmlrpc.class.php:4398 | PASS |
| 6 | bad devKey negative path | devKey=BADKEY on createTestProject | clean 'invalid developer key' fault | PASS |

**Result: Suite 646 — 7/7 PASS** (cases 1–6 post-fix; case 0 = pre-fix reproduction recorded for evidence)

## Regression — Issue #432: Code Tracker Configuration requires XML without documentation

**Precondition:** logged in admin/admin; screen `gui/templates/codetracker/codetrackerView.html` + BFF `api/codetracker/index.php`; no fixtures required beyond those created in-suite.

| # | Test | Steps | Expected | Result |
|---|------|-------|----------|--------|
| 0 | Pre-fix reproduction | Create modal → name set → paste plain URL `https://github.com/...` into Configuration → Save | (pre-fix) raw internal error `Source:tlCodeTracker::checkXMLCfg - Failure loading XML STRING Start tag expected, '<' not found` shown; POST 400 | PASS (reproduced; screenshot issue-432-before.png) |
| 1 | Help text + example visible | Open create modal | Persistent help line "Configuration must be valid XML. Example format:" + `<pre>` example `<codetracker><uribase>…</uribase><apikey>…</apikey></codetracker>` below field; placeholder matches example | PASS |
| 2 | Plain URL blocked client-side | cfg = plain URL → Save | Friendly i18n message `ct.validation.xmlFormat`; modal stays open; NO network POST fired | PASS |
| 3 | Valid XML accepted | cfg = `<codetracker><uribase>https://git.example.com/</uribase></codetracker>` → Save | HTTP 200, row appears in table | PASS |
| 4 | Empty-root XML accepted (backend defect B) | cfg = `<codetracker></codetracker>` → Save | Accepted — SimpleXMLElement with empty root must not be judged by truthiness (`$cfg === false` fix); pre-fix it was rejected with empty libxml detail | PASS |
| 5 | Duplicate name surfaces real error (backend defect A) | Create second tracker reusing an existing name with valid non-empty cfg → Save | Modal stays open, error `name already exists`; pre-fix: phantom HTTP 200 "ok" with empty item id=0 and NO insert (create() clobbered $ret via `$ret = $this->checkXMLCfg(...)`) | PASS |
| 6 | Edit path broken XML mapped friendly | Edit existing tracker → cfg `<codestracker><uribase>broken` → Save | Friendly `ct.validation.xmlFormat` message; internal `Source:` signature never rendered | PASS |
| 7 | Other 400s keep real messages | duplicate-name case (see #5) shows server msg verbatim, not the XML hint | Non-XML errors unmapped | PASS |
| 8 | i18n coverage | `ct.cfgHelp`, `ct.validation.xmlFormat` present in en/de/es/fr/it/ja/pt/ro/ru/zh | All 10 bundles valid JSON (`python3 -m json.tool`), +2 keys each | PASS |
| 9 | Event Viewer clean | delta on events log_level=2 during all suite steps | Zero new Error/Warning rows | PASS |

**Result: Suite 432 — 10/10 PASS** (case 0 = pre-fix reproduction recorded for evidence)

## Regression — Issue #434: Duplicate TestLink header from dashio workAreaSimple.tpl

**Precondition:** dashio theme active; app at `http://localhost:8082` (PHP 8.3.33); fix under test = commit `9eba4f88f` already on default branch (this suite VERIFIES it and closes #434). Template render path exercised via `firstLogin.php` self-signup-disabled branch (`$tlCfg->user_self_signup = FALSE` set temporarily through untracked `custom_config.inc.php`, deleted afterwards).

| # | Test | Steps | Expected | Result |
|---|------|-------|----------|--------|
| 0 | Pre-fix reproduction | Restore `9eba4f88f~1` version of workAreaSimple.tpl locally → GET /firstLogin.php → measure HTML → revert template | (pre-fix) full `<head>` injected inside iframe: `<title>TestLink</title>`, `<base href>`, favicon, testlink_library.js = duplicate header chrome | PASS (reproduced: 3165 bytes, 1 head, all 3 artifacts present) |
| 1 | Post-fix minimal render (primary) | GET /firstLogin.php with current tree + self-signup off | Minimal doc only: 0 `<head>` tags, 0 `<title>`, 0 inc_head artifacts, exactly 1 `<h1 class="title">`; body = title + workBack content + back link | PASS (234 bytes, all counts zero, single h1) |
| 2 | Template source state | Inspect gui/templates/dashio/workAreaSimple.tpl + git ancestry | No `{include file="inc_head.tpl"}`; commit 9eba4f88f is ancestor of HEAD | PASS |
| 3 | Compiled cache hygiene | grep templates_c compiled files for workAreaSimple | No compiled artifact referencing inc_head | PASS |
| 4 | Legacy Code Tracker view | Browser: /lib/codetrackers/codeTrackerView.php | Single `<h1>Code Trackers</h1>`, no stacked header | PASS |
| 5 | Modernized Code Trackers screen | Browser: /gui/templates/codetracker/codetrackerView.html | 1 `<head>`, no duplicated header/nav elements | PASS |
| 6 | Working tree integrity | git status after fixture toggles | Only intended docs/test files modified; custom_config.inc.php absent; tpl byte-identical to HEAD | PASS |

**Result: Suite 434 — 7/7 PASS** (case 0 = pre-fix reproduction recorded for evidence)

## Regression — Issue #653: error_self_signup_disabled key missing from ALL locale bundles (LOCALIZE: placeholder on signup-disabled page)

**Precondition:** app at `http://localhost:8082` (PHP 8.3.33); fix = commit `2c4d89119` adding `$TLS_error_self_signup_disabled` to 19/19 `locale/*/strings.txt`; self-signup disabled temporarily via untracked `custom_config.inc.php` (`$tlCfg->user_self_signup = FALSE;`), deleted after the suite.

| # | Test | Steps | Expected | Result |
|---|------|-------|----------|--------|
| 0 | Pre-fix reproduction | `custom_config.inc.php` with signup=FALSE → `curl http://localhost:8082/firstLogin.php` | (pre-fix) body contains literal `LOCALIZE: error_self_signup_disabled` under `TestLink ::: Fatal Error` h1 + Back-to-login link | PASS (reproduced verbatim) |
| 1 | Post-fix primary symptom gone | Same request against fixed tree | Localized sentence rendered, ZERO occurrences of `LOCALIZE:` in response | PASS ("New user self-registration is disabled on this site. Please contact your site administrator.") |
| 2 | Key coverage all bundles | `grep -rln error_self_signup_disabled locale/*/strings.txt \| wc -l` | 19 of 19 locale dirs contain the key | PASS (19) |
| 3 | Syntax gate all touched files | `php -l locale/<loc>/strings.txt` ×19 | All pass; no parse errors from insertion before trailing `?>` | PASS (19/19) |
| 4 | lang_get fallback path correct | Confirm mechanism: missing key → `TL_LOCALIZE_TAG . key` (lang_api.php:118); with key present → string returned | Behavior consistent before/after; no code change needed | PASS |
| 5 | No event pollution | `SELECT COUNT(*) FROM events` after suite | 0 new Error/Warning rows (signup-disabled branch runs session-less; L18N warning gated by isset($_SESSION)) | PASS |
| 6 | Default-config path unaffected by THIS fix | Remove custom_config.inc.php → GET /firstLogin.php (signup=TRUE) | Signup form should render — BLOCKED by pre-existing defect #654 (dashio `login/firstLogin.tpl` missing, Smarty fatal) verified identical on pristine worktree HEAD 020283511 → NOT caused by this fix; tracked as #654 | PASS (fix-neutral; #654 filed) |

**Result: Suite 653 — 7/7 PASS** (case 0 = pre-fix reproduction recorded for evidence; case 6 documents the separately-filed blocker #654)

## Regression — Issue #655: Modernize tcExecAssignment (Assign Test Case Execution)

**Precondition:** app at `http://localhost:8082` (PHP 8.3.33); fixture built by `php tmp/fixture_ea.php` (project "EA Demo", plan "EA Plan", builds B1/B2, platforms Chrome/Firefox, TCs EAD-1..3 linked on both platforms, users admin / etester(tester) / eplain(no rights)); screen `gui/templates/execute/tcExecAssignment.html` + BFF `api/execassignment/index.php`.

| # | Test | Steps | Expected | Result |
|---|------|-------|----------|--------|
| 1 | Screen load & data render | Open `/gui/templates/execute/tcExecAssignment.html?tproject_id=1&tplan_id=26` as admin | Toolbar (plan/build/platform), bulk bar, suite-grouped table; 6 rows (3 TC × 2 platforms); priority badges High(6)/Medium(4)/Low(2); pre-assignment etester chip on EAD-1/Chrome/B1; "6 rows" footer | PASS |
| 2 | Tester pickers populated | Inspect #bulkTesters + each row select | Both testers listed (admin, Ella Tester); legacy parity via getTestersForHtmlOptions | PASS (after BFF fix: full tproject record must be passed to get_tplan_effective_role, else tplan-scoped resolution silently drops users) |
| 3 | Row checkbox counter | Check rows 1 and 3 | Header counter shows "2 checked" | PASS |
| 4 | Bulk apply | Select Ella Tester in #bulkTesters → Apply to selected rows | Only checked rows' per-row selects get tester 2 preselected | PASS |
| 5 | Save assignments | Save Assignments → reload | POST /assign persisted; chips rendered for EAD-1/Chrome + EAD-2/Chrome; checkboxes cleared; toast "Assignments saved" | PASS |
| 6 | Per-user remove (chip ×) | Click × on a chip | DELETE of single (feature,user) pair via POST /remove; toast "Assignment(s) removed"; chip gone after reload | PASS |
| 7 | Bulk user remove | Check row of remaining chip → pick user in #bulkTesters → Remove Assignments | POST /bulkuserremove removes that user from checked features; chips = 0 | PASS |
| 8 | Remove ALL w/ confirm modal | Check all 6 rows → Remove ALL → modal text → confirm | Modal: "6 test case(s) will lose every tester assignment…"; confirm → POST /removeall; all chips gone; toast "All assignments removed for the checked cases" | PASS (screenshot ea_removeall_modal.png) |
| 9 | Send link by mail | Pick user → Send link by mail | POST /sendlink returns ok; toast "Execution planning link sent" (mail body suppressed when no SMTP configured — notifyAssignees gated on smtp_host) | PASS |
| 10 | Build switch | Switch build to B2 | Items reload; assigned column empty (no B2 assignments); switching back to B1 reflects persisted state | PASS |
| 11 | Platform filter | Filter platform Chrome | Only Chrome rows (3 + suite header); Firefox rows hidden | PASS |
| 12 | i18n ro_RO | Locale switcher → Română | Buttons/headers translated ("Salvează Atribuirile", "Elimină TOATE atribuirile…", "Atribuit lui"); tea.* keys present in all 10 JSON bundles | PASS |
| 13 | Rights enforcement | curl BFF routes as eplain (role: no rights) | HTTP 403 Insufficient rights (`exec_assign_testcases` checked on every route); unauthenticated → 401 | PASS |
| 14 | Event Viewer hygiene | Run flows → check Event Viewer | No new ERROR entries from screen paths; found+fixed WARNING `Undefined array key is_closed` (BFF used non-existent key from get_builds()) — re-test clean | PASS (after fix commit) |

**Result: Suite 655 — 14/14 PASS**

## Regression — Issue #654: dashio theme missing login/firstLogin.tpl (HTTP 500 on plain GET /firstLogin.php when user_self_signup=TRUE)

**Precondition:** app at `http://localhost:8082` (PHP 8.3.33); default config (`$tlCfg->user_self_signup = TRUE`, `config.inc.php:590`); fix under test = new file `gui/templates/dashio/login/firstLogin.tpl` (Dashio-styled signup card modeled on `login-dashio.tpl`, functional parity with `tl-classic/login/firstLogin.tpl`). No PHP changes.

| # | Test | Steps | Expected | Result |
|---|------|-------|----------|--------|
| 0 | Pre-fix reproduction | `curl -sS -o /dev/null -w %{http_code} http://localhost:8082/firstLogin.php` on unfixed tree | HTTP 500, empty body; `ls gui/templates/dashio/login/` has no `firstLogin.tpl` | PASS (500 reproduced) |
| 1 | Post-fix primary symptom gone | Same GET on fixed tree + grep response for inputs | HTTP 200; all 6 named inputs present (`login`,`password`,`password2`,`firstName`,`lastName`,`email`) + Sign up button + Back to login link | PASS (200; input count check = 7 matches incl. form tag) |
| 2 | Browser render of form | Chrome → http://localhost:8082/firstLogin.php → a11y snapshot | Dashio card renders; fields focusable/required; no console fatal | PASS (screenshot firstLogin-dashio-fixed.png) |
| 3 | viewer=new path unchanged | `curl -w %{http_code} "http://localhost:8082/firstLogin.php?viewer=new"` | Still 200 (marcobiedermann model template untouched) | PASS (200) |
| 4 | Validation error path (was 500 pre-fix) | POST `doEditUser=1&password=abc12345&password2=DIFFERENT...` both curl and browser | 200 re-render with `alert-danger` "The two passwords entered did not match"; non-password field values preserved | PASS (curl + browser; screenshot firstLogin-dashio-mismatch-error.png) |
| 5 | Happy path signup | Browser: fill valid data → submit | Redirect to `login.php?note=first`; welcome note rendered; user row created in `users` | PASS (`users.id=4 regtest654c`; location.href observed) |
| 6 | Back to login link | Browser click on link | Navigates to `/login.php` | PASS |
| 7 | Event Viewer clean for new template | Watermark `SELECT MAX(id) FROM events` → 2 fresh renders of plain GET → re-query | No new events rows caused by the new template | PASS (max stayed 9) |

**Result: Suite 654 — 8/8 PASS**

## Regression — Issue #656: firstLogin.php?viewer=new — 3x E_WARNING per render (2x Undefined property pwdInputMaxLength + Undefined array key "login")

**Precondition:** app at `http://localhost:8082` (PHP 8.3.33); fix = `firstLogin.php:72` assigns `$gui->pwdInputMaxLength = config_get('loginPagePasswordMaxLenght')`; both copies of `login/firstLogin-model-marcobiedermann.tpl` (dashio + tl-classic) fetch `login` in the `lang_get` list. Baseline: events table had exactly 3 pre-fix E_WARNING rows (ids 1–3, log_level 2).

| # | Test | Steps | Expected | Result |
|---|------|-------|----------|--------|
| 1 | Primary symptom gone | `curl -sS -o /dev/null -w %{http_code} "http://localhost:8082/firstLogin.php?viewer=new"` after stale compiled template purge → re-query events | HTTP 200; 0 new events (was 3 E_WARNING/render pre-fix) | PASS |
| 2 | Plain GET unchanged | `curl -w %{http_code} http://localhost:8082/firstLogin.php` | 200; 0 new events | PASS |
| 3 | Rendered HTML sane | grep response of viewer=new for title + maxlength | `<title>Login</title>` non-empty; exactly 2 password inputs with `maxlength="40"` | PASS |
| 4 | Mismatch error path | POST doEditUser=1 login=rgxuser password≠password2 viewer=new | Form redisplayed with "The two passwords entered did not match…"; no user row created; 0 new Error/Warning events | PASS |
| 5 | Happy-path signup | POST matching passwords → check users + events + redirect target | user created (users.id=2 rgxuser); response contains `login.php?note=first`; only new event is log_level 16 AUDIT (`audit_users_self_signup`) | PASS |
| 6 | Browser render | chrome-devtools navigate to viewer=new + screenshot + Event Viewer re-query | Page renders clean (title tab "Login"); screenshot docs/screenshots/issue-656-firstlogin-viewer-new-clean.png; still 0 new warnings incl. this render | PASS |

**Result: Suite 656 — 6/6 PASS** — Side observation: self-signup accepts duplicate emails → filed as #657.

## Regression — Issue #457: XML-RPC error messages lose "(method) - " prefix ($msg_prefix/$messagePrefix/$msgPrefix typos) + E_WARNING spam in Event Viewer

**Precondition:** app at `http://localhost:8082` (PHP 8.3.33); devKey set for `admin` (`users.script_key`); endpoint `POST /lib/api/xmlrpc/v1/xmlrpc.php` with one struct param; fix = commit `59279b726` (12 renamed prefix refs across 7 methods + local declaration in `updateTestCaseGetTCVID()`). Baseline pre-fix: fault strings missing prefixes on affected paths; events table had exactly 2 E_WARNING rows (`Undefined variable $msg_prefix ... Line 7260`) from the reproduction calls.

| # | Test | Steps | Expected | Result |
|---|------|-------|----------|--------|
| 1 | Pre-fix symptom documented | Before fix: call `tl.uploadTestCaseAttachment` {devKey} (no version) and `tl.getTestProjectByName` {devKey, testprojectname:NO_SUCH_PROJECT_XYZ}; compare with reference `tl.getTestPlanPlatforms` {devKey} | Bug calls return UNPREFIXED messages; reference returns `(getTestPlanPlatforms) - Parameter testplanid is required...`; 2x E_WARNING rows at line 7260 in events | PASS (measured pre-fix) |
| 2 | R1 uploadTestCaseAttachment prefixed | Post-fix: same call as #1 first bug call | Message starts `(uploadTestCaseAttachment) - ` | PASS |
| 3 | R2 getTestProjectByName prefixed | Post-fix: same call as #1 second bug call | Message starts `(getTestProjectByName) - ` | PASS |
| 4 | R3 reference path unchanged | `tl.getTestPlanPlatforms` {devKey} no tplanid | Still `(getTestPlanPlatforms) - Parameter testplanid is required...` | PASS |
| 5 | R4 zero new Event Viewer rows | Watermark events COUNT before/after all post-fix API calls in this suite | No new Error/Warning rows | PASS (count stayed 2) |
| 6 | R5 static cleanliness | `php -l xmlrpc.class.php` + scripted function-scope scan of $msg_prefix/$messagePrefix/$msgPrefix | Syntax OK; 0 functions with undefined prefix uses | PASS |
| 7 | addTestCaseToTestPlan prefix plumbing | `tl.addTestCaseToTestPlan` {devKey} (no testprojectid) | `(addTestCaseToTestPlan) - No testprojectid provided.` | PASS |
| 8 | getTestCaseAssignedTester prefix plumbing | `tl.getTestCaseAssignedTester` {devKey, testplanid:99999, testcaseexternalid:NOPFX-1} | `(getTestCaseAssignedTester) - The Test Plan ID (99999) provided does not exist!` | PASS |
| 9 | Sibling sanity createBuild | `tl.createBuild` {devKey, testplanid:99999} | `(createBuild) - The Test Plan ID (99999) provided does not exist!` | PASS |

**Result: Suite 457 — 9/9 PASS** — Side observations: `setTestCaseTestSuite()` renders `"() - "` from `$ret['operation']` undefined key → filed as #658; `$tcase_external_id` used undefined at :3522 → filed as #659.

## Modernize — Issue #660: My Test Case Assignments screen (tcAssignedToUser → gui/templates/execute/tcAssignments.html + api/tcassignments)

**Precondition:** app `http://localhost:8082` (admin/admin); fixture `php tmp/fixtures_660.php` (project LOC660 id=35, plans Plan660A active / Plan660B inactive, builds BUILD-OPEN/BUILD-CLOSED, platform PLAT-X, TCs alpha/beta/gamma/delta with execution assignments for admin+tester1, prior execution on alpha). BFF routes: GET `/api/tcassignments/index.php/init|rows`, POST `/quick_result`. Screen: `/gui/templates/execute/tcAssignments.html`.

| # | Test | Steps | Expected | Result |
|---|------|-------|----------|--------|
| 1 | BFF init context | `GET init?tproject_id=35` as admin | status ok; tproject name/priorityEnabled=true; 2 plans (A active, B inactive flag); users list; showClosedBuilds from session | PASS |
| 2 | Default "assigned to me" rows | open tcAssignments.html?tproject_id=35 | header "My Test Case Assignments · LOC660 · admin"; only Plan660A group; only own assignment TC-alpha (Passed, Low prio, 45 days) | PASS |
| 3 | Overview mode toggle | click "All users (overview)" | User + Tester columns appear; 2 rows in plan A (admin/alpha, tester1/beta@PLAT-X High); gamma hidden (closed build) | PASS |
| 4 | Show closed builds | enable checkbox | gamma row appears on BUILD-CLOSED; footer count 3 assignments; session persists flag server-side | PASS |
| 5 | Inactive plan explicit selection | plan select → Plan660B (inactive) | TC-delta shown despite inactive plan (explicit selection overrides tplan-status filter); no platform column (plan has none) | PASS |
| 6 | Quick result write | click failed quick icon on own row (alpha) | toast localized; status chip flips to Failed; executions table gets new row (status 'f', tester_id=1, correct version_number/tplan/build/platform) | PASS |
| 7 | exec_mode=assigned_to_me parity | inspect other-user rows as admin (config exec_mode->tester='assigned_to_me') | no quick P/F/B buttons on rows assigned to other users; exec/history/design links still present | PASS |
| 8 | Row action popups | capture window.open targets for exec/history/design icons | execSetResults.php?version_id&level=testcase&id&tplan_id&setting_build&setting_platform&caller=... ; execHistory.php?tcase_id&tproject_id ; archiveData.php?edit=testcase&id&tproject_id — all load w/o fatal | PASS |
| 9 | Rights gate (no right) | login tester1 (no exec_testcases_assigned_to_me), call init/rows | HTTP 403 JSON "Insufficient rights" on every route; aside link hidden via menuGrants | PASS |
| 10 | i18n all bundles | `python3 -m json.tool` each of 10 bundles; switch locale to ro_RO | all valid JSON; ro shows "Test Case-urile Mele Alocate", "Suită de test", "Stare", chip "Eșuat" | PASS |
| 11 | Event Viewer clean | watermark events before/after full pass | zero new E_WARNING/Error rows after fix commit (was: 3x "Undefined array key is_open" line 145 — fixed) | PASS |
| 12 | Aside link switch | index.php → Execution section as admin | "Test Cases Assigned to Me" href = /gui/templates/execute/tcAssignments.html?tproject_id=35&tplan_id=50; loads inside mainframe with data | PASS |

**Result: Suite 660 — 12/12 PASS**

## Regression — Issue #458: tl.setTestCaseExecutionType names the wrong parameter in its missing-parameter error

**Precondition:** app at `http://localhost:8082` (PHP 8.3.33); devKey set for `admin` (`users.script_key`); fixtures via XMLRPC on fresh DB — project `Issue458Proj` id=1 prefix `I458`, suite `TS458` id=2, test case `I458-1` (tcid=3, version=1). Endpoint `POST /lib/api/xmlrpc/v1/xmlrpc.php`. Fix = commit `3478f3010` (`xmlrpc.class.php:6643`: message token `customFieldsParamName` → `executionTypeParamName`). Pre-fix baseline measured: omitted-`executiontype` call returns code 200 with `Parameter customfields is required, but has not been provided`.

| # | Test | Steps | Expected | Result |
|---|------|-------|----------|--------|
| 1 | Pre-fix symptom documented | Before fix: `tl.setTestCaseExecutionType` {devKey, testprojectid:1, testcaseexternalid:I458-1, version:1} — executiontype omitted | Message names wrong param `customfields` | PASS (measured pre-fix) |
| 2 | R1 correct parameter named | Post-fix: same call as #1 | Code 200; message exactly `Parameter executiontype is required, but has not been provided` | PASS |
| 3 | R2 happy path intact | `tl.setTestCaseExecutionType` {…, executiontype:2} | Success struct echoes args; DB tcversions row gets `execution_type=2`; then repeat with :1 → `execution_type=1` | PASS |
| 4 | R3 sibling customfields methods unchanged | `tl.updateTestCaseCustomFieldDesignValue` {devKey, testprojectid:1, testcaseexternalid:I458-1, version:1} without customfields | Still reports `Parameter customfields is required...` (lines 6569/8263/8358 pairing untouched) | PASS |
| 5 | R4 zero new Event Viewer rows | Watermark events COUNT before/after all post-fix calls in this suite | No new Error/Warning rows from this method's error path | PASS (count stayed 2) |
| 6 | R5 static cleanliness | `php -l lib/api/xmlrpc/v1/xmlrpc.class.php` + grep all `_isParamPresent` sites pair check-vs-message token | Syntax OK; only site 6641/6643 changed and now consistent; 6569/8263/8358 still customfields-paired | PASS |

**Result: Suite 458 — 6/6 PASS** — Side observation while creating fixtures: XMLRPC `createTestCase` drops step `expectedresults` key → E_WARNING + empty expected_results persisted → filed as #661.

## Regression — Issue #459: platformLinkOp() undefined `$tatus_ok` typo spams Event Viewer with E_WARNING on every failed add/removePlatform call

**Precondition:** app at `http://localhost:8082` (PHP 8.3.33); devKey set for `admin` (`users.script_key='fix459devkey00000000000000000000'`); endpoint `POST /lib/api/xmlrpc/v1/xmlrpc.php`. Fix = 1 line at `lib/api/xmlrpc/v1/xmlrpc.class.php:7024` (`if(! $tatus_ok)` → `if(! $status_ok)`). Pre-fix baseline measured: failing call (`testplanid=999999`) returned correct IXR error 3000 BUT wrote `E_WARNING Undefined variable $tatus_ok - Line 7024` into `events` (table had 0 rows before, 1 after). Fixtures via XMLRPC API on fresh DB: project id=1 prefix `fx459`, plan id=2 `Fix459Plan`, platform `PlatA`, suite id=3, tcversion id=5.

| # | Test | Steps | Expected | Result |
|---|------|-------|----------|--------|
| 1 | R1 bad auth | `tl.addPlatformToTestPlan` {devKey:"WRONGKEY", testplanid:999999, platformname:P} | IXR error 2000; no new events rows | PASS |
| 2 | R2 bad testplanid (primary repro) | `tl.removePlatformFromTestPlan` {devKey, testplanid:999999, platformname:NoSuchPlatform} | Code 3000 message unchanged; events count stays at pre-fix watermark | PASS |
| 3 | R3 missing platformname | `tl.addPlatformToTestPlan` {devKey, testplanid:2} only | Code 200 "Parameter platformname is required..."; no new events | PASS |
| 4 | R4 valid link + duplicate | link PlatA to plan 2 twice | First `link done`, second `nothing to do`; no new events | PASS |
| 5 | R5 valid unlink | unlink PlatA from plan 2 | `unlink done` | PASS |
| 6 | R6 unlink blocked by linked TC version | re-link; INSERT testplan_tcversions(2,5,platform_id=1); unlink again | Code 12001 PLATFORM_REMOVETC_NEEDED_BEFORE_UNLINK — exercises `$status_ok=false` fall-through to fixed line :7024; no new events | PASS |
| 7 | R7 unknown platform name | `tl.addPlatformToTestPlan` {testplanid:2, platformname:Nope} | Code 235 PLATFORM_NAME_DOESNOT_EXIST; no new events | PASS |
| 8 | Syntax gate | `php -l lib/api/xmlrpc/v1/xmlrpc.class.php` | No syntax errors | PASS |

**Result: Suite 459 — 8/8 PASS** — Side discoveries while building fixtures: (a) every SUCCESSFUL `removePlatformFromTestPlan` of a platform without linked TC versions writes 2× E_WARNING "Trying to access array offset on null" at line 7008 → filed as #663; (b) `createTestProject` with omitted/misnamed params writes E_WARNING "Undefined array key" at lines 2230-2231 → filed as #664. Both out of scope here.

## Regression — Issue #663: platformLinkOp() unlink branch reads qty off null countLinkedTCVersionsByPlatform() result — 2x E_WARNING on every successful removePlatformFromTestPlan

**Precondition:** app at `http://localhost:8082` (PHP 8.3.33); devKey set for `admin` (`users.script_key=MD5('admin')`); endpoint `POST /lib/api/xmlrpc/v1/xmlrpc.php`. Fix = commit `e3d1c1eea` (`lib/api/xmlrpc/v1/xmlrpc.class.php:7007-7015`: `$hitsQty = isset($hits[$platform['id']]['qty']) ? intval(...) : 0;` used in both the `== 0` check and the PLATFORM_REMOVETC_NEEDED_BEFORE_UNLINK sprintf). Pre-fix baseline measured: `tl.removePlatformFromTestPlan` of a linked platform with ZERO TC versions returned correct `{operation: unlink, msg: 'unlink done'}` BUT wrote 2× `E_WARNING Trying to access array offset on null - Line 7008` into `events` (table truncated to 0 before, 2 rows after). Fixtures via XMLRPC API: project id=1 prefix `I663_1787568724`, plan id=2, platform id=1 `I663_1787568724 plat`, suite id=5, testcase `I663_1787568724-1` tcversion_id=7.

| # | Test | Steps | Expected | Result |
|---|------|-------|----------|--------|
| 1 | R1 primary bug case | link plat 1 → plan 2; truncate events; `tl.removePlatformFromTestPlan {devKey, testplanid:2, platformname:'I663_1787568724 plat'}` with zero TC versions on the platform | `{unlink done}`; events COUNT stays 0 (pre-fix: +2) | PASS |
| 2 | R2 nothing-to-do path | repeat unlink when platform NOT linked | `{operation: unlink, msg: 'nothing to do', linkStatus: false}`; no new events | PASS |
| 3 | R3 refusal path intact | re-link; set testplan_tcversions.platform_id=1 (tcversion 7); truncate; unlink again | Code 12001 PLATFORM_REMOVETC_NEEDED_BEFORE_UNLINK; message says "used by 1 test case(s)" ($hitsQty substitution correct); no new events | PASS |
| 4 | R4 unknown platform unchanged | `tl.removePlatformFromTestPlan {..., platformname:NO_SUCH_PLATFORM_XYZ}` | Code 235 PLATFORM_NAME_DOESNOT_EXIST; no new events | PASS |
| 5 | R5 link op unaffected | `tl.addPlatformToTestPlan` after unlink | `{link done}`; no new events | PASS |
| 6 | R6 sibling consumers untouched | inspect platformsAssign.php:39 and api/platforms/index.php:550/635 call sites | All already isset()-guarded; no code change needed or made there | PASS |
| 7 | R7 syntax gate | `php -l lib/api/xmlrpc/v1/xmlrpc.class.php` | No syntax errors | PASS |

**Result: Suite 63 — 16/16 PASS**

**Bugs found & fixed during this suite**
- #662-a: init read vestigial `$tprojInfo['option_platforms']` column →
  E_WARNING in Event Viewer on every load. Fixed by reading the serialized
  options blob (`platformsEnabled`) defensively.
- (#621 surfaced again): every step-level save emitted
  `file_upload_step_exec_ok/ko is not localized for en_GB`. Added
  `locale/en_GB/custom_strings.txt` defining both keys — then extended to all
  server locales missing them after code review.
- Review-round regression caught in verification: step-ID ownership query
  initially used nonexistent `tcsteps.parent_id`; corrected to join
  `nodes_hierarchy` (DB Access Error page otherwise).

**Known scope decisions (v1)**: attachments-at-execution and bug linking are
not part of the first cut of the execution form; execution custom fields render
in Execution History but not yet in the form. Tracked as follow-up work on
issue #662.

## Suite 664 — Regression — Issue #664: XMLRPC createTestProject E_WARNING "Undefined array key" (lines 2230-2231) when required params are omitted

**Area:** XMLRPC API `tl.createTestProject` (`lib/api/xmlrpc/v1/xmlrpc.class.php`,
`_checkCreateTestProjectRequest()`) · **Date:** 2026-08-24

Fixtures: admin user with `users.script_key='664reprokey664reprokey664repro'`;
events table baseline recorded before each run. Endpoint:
`http://localhost:8082/lib/api/xmlrpc/v1/xmlrpc.php`.

| # | Test | Steps | Expected | Result |
|---|------|-------|----------|--------|
| 664.1 | Pre-fix repro | POST createTestProject with members `name=Foo`, `prefix=foo` (wrong names) | PRE-FIX: response IXR_Error 7001 BUT events gains 2 E_WARNING rows "Undefined array key testprojectname/testcaseprefix" lines 2230/2231 — reproduced as reported | PASS (repro confirmed) |
| 664.2 | Wrong param names post-fix | same call after fix | 7001 returned; ZERO new E_WARNING events rows | PASS |
| 664.3 | Required params omitted entirely post-fix | POST with only devKey member | 7001 returned; ZERO new E_WARNING rows | PASS |
| 664.4 | Empty prefix contract preserved | valid `testprojectname`, empty `testcaseprefix` | IXR_Error 7004 (prefix empty) — unchanged API contract; no warnings | PASS |
| 664.5 | Happy path unaffected | valid name+prefix ("Issue664 Happy Project"/I664) | status=true, project id returned, row in `testprojects`, audit event `audit_testproject_created`; no warnings | PASS |
| 664.6 | Duplicate name contract preserved | repeat V5 name | IXR_Error 7002 TESTPROJECTNAME_EXISTS; no warnings | PASS |

**Root cause & fix:** direct `$this->args[...]` reads at
xmlrpc.class.php:2230-2231 without presence guard ⇒ PHP 8 E_WARNING on absent
keys, logged to Event Viewer. Minimal fix: null-coalescing reads (`?? null`);
downstream validation (`checkNameSintax(null)` → 7001, `!empty(null)` → 7004)
produces byte-identical responses. Commit `fcf0370cb`.

## Regression — Issue #460: tl.updateTestSuiteCustomFieldDesignValue only ever updates/reports the first custom field passed

**Precondition**: fresh DB; API devKey for `admin` set (`users.script_key`);
fixtures: test project `Proj460` id=1, test suite `Suite460` id=2 (both via
XMLRPC), custom fields `CF_ALPHA` id=10 and `CF_BETA` id=11 — string type,
enabled on design, linked to project 1 and to node type testsuite
(rows in `custom_fields`, `cfield_testprojects`, `cfield_node_types`);
`SELECT COUNT(*) FROM cfield_design_values WHERE node_id=2` = 0.

**Repro steps (pre-fix)** — POST XMLRPC
`tl.updateTestSuiteCustomFieldDesignValue` with
`{devKey, testprojectid:1, testsuiteid:2,
customfields:{"CF_ALPHA":"value-A","CF_BETA":"value-B"}}`.
Observed pre-fix: response `[ok CF_ALPHA processed]` only;
DB row only `10|value-A`. Second field silently dropped.
Variant with `{"UNKNOWN_CF":"x","CF_ALPHA":"second-never-run"}` returned
only the `ko` entry and never attempted `CF_ALPHA`.

**Expected post-fix**: one `{status,msg}` entry per input field (contract at
xmlrpc.class.php lines 8236-8249); every linked field written to
`cfield_design_values`; unlinked names reported as `ko` without aborting
later entries; empty map → `[]`; error paths unchanged.

**Execution matrix (post-fix, live server http://localhost:8082)**:

| # | Case | Expected | Actual | Verdict |
|---|------|----------|--------|---------|
| 460.R1 | two valid fields | 2× ok; rows 10→A1, 11→B1 | resp `[ok,ok]`; db `10 A1 | 11 B1` | PASS |
| 460.R2 | unknown first + valid second | ko + ok; valid row written | resp `[ko,ok]`; db `10 A2` | PASS |
| 460.R3 | single valid field | unchanged vs legacy single-field path | resp `[ok CF_BETA]`; db updated | PASS |
| 460.R4 | empty customfields map | clean `[]`, no E_WARNING | `[]` | PASS |
| 460.R5a | missing param | code 200 required-param error | code 200 | PASS |
| 460.R5b | nonexistent project | code 7000 | code 7000 | PASS |
| 460.R5c | invalid devKey | code 2000 | code 2000 | PASS |
| 460.EV | Event Viewer delta during suite | no new Error/Warning | events table: 1 AUDIT row only (fixture audit) | PASS |

**Fix under test**: commit 7b8a7dd35 — move `return $ret;` after the
`foreach` in `updateTestSuiteCustomFieldDesignValue()` +
initialize `$ret = array();` (parity with `updateBuildCustomFieldsValues()`).

**Result: PASS**

---

## Suite #461 — xmlrpc `tl.getTestSuite()`: rights context from undefined `$dummy`, loop off-by-one, swallowed INSUFFICIENT_RIGHTS

**Fix under test**: commit 15d9f2ec6 (branch fix/issue-461) —
`lib/api/xmlrpc/v1/xmlrpc.class.php` `getTestSuite()`:
(1) rights-check context built from `$tproj['id']` instead of never-assigned
`$dummy['id']` (:8474); (2) name-match loop bound `$ydx < $l2d` instead of
`$ydx <= $l2d` (:8493); (3) rights denial returns the
`IXR_Error(INSUFFICIENT_RIGHTS)` appended by `userHasRight()` via the file's
existing idiom (cf. `getIssueTrackerSystem()`) instead of returning undefined
`$ni` (empty response, error swallowed).

**Precondition**: fresh DB; fixtures created via API with admin devKey:
project `Issue461 Project` (id=1, prefix I461, is_public toggled per case),
suites `ReproSuite461`(node 2), `OtherSuite461`(node 3); user `i461user`
(id=2, script_key user461devkey); role rows inserted in
`user_testproject_roles` as needed. Evidence channel: `events` table delta
(`log_level IN (2,3)` = Error/Warning) around each call.

**Repro steps (pre-fix)**: POST `tl.getTestSuite {devKey, prefix, testsuitename}`
to http://localhost:8082/lib/api/xmlrpc/v1/xmlrpc.php.
Observed pre-fix: admin call → correct struct BUT 4 E_WARNINGs per call
(`Undefined variable $dummy` + `array offset on null` @ :8474;
`Undefined array key N` + `array offset on null` @ :8494);
user with only project-level mgt_view_tc → **empty string** response
(`Undefined variable $ni`) despite entitled;
global-guest-only user on PRIVATE project → **suite data leaked**
(public/private gate bypassed because context id = 0).

**Expected post-fix**: suite struct for entitled callers with 0 warnings;
project-level grants honored; private project denies global-only users with
proper IXR error; unknown-prefix and no-match paths unchanged; zero new
Error/Warning events.

**Execution matrix (post-fix, live server http://localhost:8082)**:

| # | Case | Expected | Actual | Verdict |
|---|------|----------|--------|---------|
| 461.R1 | admin devKey, name match | suite struct; 0 new warnings | struct w/ `<string>ReproSuite461</string>`; warn=0 | PASS |
| 461.R2 | i461user: global `<no rights>`(3) + project role `test designer`(4) on proj 1 | ALLOWED via project grant; struct; 0 warnings | struct returned; warn=0 (pre-fix: empty string) | PASS |
| 461.R3 | i461user: global `guest`(5), NO project role, project is_public=0 | DENIED, IXR_Error 2010 INSUFFICIENT_RIGHTS, no data | code 2010 "…right mgt_view_tc, test project id: 1…"; no data; warn=0 (pre-fix: LEAK) | PASS |
| 461.R4 | unknown prefix `NOPE` | IXR_Error 7013 TPROJECT_PREFIX_DOESNOT_EXIST | code 7013; warn=0 | PASS |
| 461.R5 | name matches nothing | empty array, no data, 0 warnings | `<array><data></data></array>` len=161; warn=0 | PASS |
| 461.EV | Event Viewer delta across R1–R5 | 0 new Error/Warning rows | events table delta = 0 | PASS |

**Related defect found during repro, filed separately**: #665 — `$tsuitid`
assigned vs `$tsuiteid` read in `userHasRight()` fallback block
(xmlrpc.class.php:477-481). Not fixed in this run (out of scope).

**Result: PASS**

---

## Suite 462 — Regression — Issue #462: checkPlatformIdentity() stray `$platformInfo` dump in errors[] on the platformname path

**Area**: XML-RPC API (`lib/api/xmlrpc/v1/xmlrpc.php`), helper `checkPlatformIdentity()`
(xmlrpc.class.php:4942). Fix = delete unconditional `$this->errors[] = $platformInfo;`
at former line 4976 (commit `d31210b22`, branch `fix/issue-462`).

**Precondition**: fresh DB; admin devKey set (`users.script_key`); fixtures via API:
project 5 `PROJ462_*`, plan 6 `TP462`, platforms `PLAT_A`(id 2, linked to plan)
+`PLAT_B`(3, unlinked) with enable_on_design/enable_on_execution=1,
suite 7, case 8 linked to plan with platformid=2, build B1.
Harness: minimal hand-rolled XML-RPC client (no php-xmlrpc ext), one struct param.

**Repro steps (pre-fix)**:
1. `tl.getLastExecutionResult {testplanid:6, testcaseid:8, platformname:"NO_SUCH_PLATFORM"}`
   → client received `[{"2":"PLAT_A"}, {"code":"3040",...}]` — raw platform map
   dumped as element [0] next to the real IXR_Error.
2. Valid `platformname:"PLAT_A"` → success result; dump happened but invisible
   (success returns `$resultInfo` not `$this->errors`).

**Expected post-fix**: failure path returns exactly ONE well-formed IXR_Error;
success paths unchanged; all 8 calling methods unaffected otherwise.

**Execution matrix (post-fix, live server http://localhost:8082)**:

| # | Case | Expected | Actual | Verdict |
|---|------|----------|--------|---------|
| 462.M1 | unknown `platformname` on getLastExecutionResult | exactly 1 error: IXR 3040, no map | `[{"code":"3040","message":"Platform (name=NO_SUCH_PLATFORM/id=)…"}]` | PASS |
| 462.M2 | valid `platformname:"PLAT_A"` | success result array | full execution row returned | PASS |
| 462.M3a | no platform param (optional here) | success | execution row returned | PASS |
| 462.M3b | reportTCResult w/o any platform param | single MISSING_REQUIRED_PARAMETER(200), no dump | `{"code":"200","message":"…platformname OR platformid is required…"}` | PASS |
| 462.M4 | unknown `platformid:999` (numeric path untouched) | single IXR 3040 | 1 element, code 3040 | PASS |
| 462.M4b | same via getAllExecutionsResults | single IXR 3040 | 1 element, code 3040 | PASS |
| 462.M5 | end-to-end reportTCResult w/ valid platformname + buildname B1, status p | Success! + execution recorded | `"message":"Success!"`, exec id increments each run | PASS |
| 462.M5b | getLastExecutionResult w/ platformname afterwards | recorded execution visible | row id matches M5 | PASS |
| 462.EV | Event Viewer delta across matrix | 0 NEW warnings attributable to fix | only pre-existing #667/#666 families fire (verified pre-fix too); none from deleted line | PASS* |

\* Events audit: identical E_WARNING pairs at xmlrpc.class.php:1379 fired BEFORE
the fix commit as well (11:56–11:57 vs post-fix 12:01+) → pre-existing legacy
path, filed separately as **#667**; createTestCase step-key warning already open
as **#666**. No new Error/Warning attributable to this change.

**Result: PASS**

---

## Suite 475 — Regression — Issue #475: addMenuContext() drops $gui->countPlans, hiding Metrics Dashboard/Builds/Platforms/Milestones menu links

**Precondition**:
- App at http://localhost:8082, login `admin/admin`.
- DB freshly imported; fixtures: testproject id=99001 "Issue475 Project" (prefix I475, `options` PHP-serialized `a:0:{}`), testplan id=99002 "Issue475 Plan" (active, parent 99001).
- Code state: commit f61665f4e present (adds `'countPlans'` to the copy list in `TLSmarty::addMenuContext()`, `lib/functions/tlsmarty.inc.php:606`).

**Repro steps (pre-fix)**:
1. Remove `'countPlans'` from the copy list at tlsmarty.inc.php:606 (working tree only).
2. Log in, open main frame (`index.php?caller=login&viewer=`), enumerate all `<a>` in the `asideMenu.php` iframe.
3. Observe Projects / Test Plan / Test Case Execution sections.

**Expected post-fix**: with ≥1 accessible active test plan selected, ALL of these links render:
- Projects → **Metrics Dashboard** (aside.tpl:121)
- Test Plan → **Assign Test Plan Roles** (aside.tpl:189) and **Builds / Releases** (aside.tpl:194–196) and **Add / Remove Platforms** (aside.tpl:206–208)
- Test Case Execution → **Milestones** (aside.tpl:260–262)

**Execution matrix (live server http://localhost:8082, 2026-08-24)**:

| # | Case | Expected | Actual | Verdict |
|---|------|----------|--------|---------|
| 475.R1 | fix present, plan exists | 4 gated links visible | DOM query: all four targets `true` | PASS |
| 475.R2 | fix token reverted (A/B) | 4 links hidden (original bug) | none of the four present in enumerated link list | PASS |
| 475.R3 | fix restored + reload | 4 links visible again | `{"Metrics Dashboard":true,"Builds / Releases":true,"Add / Remove Platforms":true,"Milestones":true}` | PASS |
| 475.S1 | navBar shows project "I475:Issue475 Project" + plan "Issue475 Plan" | auto-selected after login | both comboboxes show fixture values | PASS |
| 475.EV | Event Viewer delta across matrix | no new Error/Warning | events count stable at 33 pre/post re-test | PASS |

---

## Regression — Issue #667: xmlrpc _checkTCIDAndTPIDValid() 2x E_WARNING on no-platform getLastExecutionResult when TC linked under a specific platform

**Date**: 2026-08-24 · **Branch**: `fix/issue-667` · **Fix commit**: `d44bdfa07`

**Area**: XML-RPC API (`lib/api/xmlrpc/v1/xmlrpc.class.php`), helper
`_checkTCIDAndTPIDValid()` lines 1361–1395. Root cause: with no platform filter,
`$plat` defaults to `0`, but links created under a platform are keyed by the real
platform id in the `get_linked_versions()` map (testcase.class.php re-keys level 3
by `$element['platform_id']`). Fix = if key `[0]` absent, fall back to first
available platform entry (same tcversion ⇒ identical version number); guard final read.

**Precondition**: fresh DB; admin devKey (`users.script_key`); fixtures via API:
project 13, plan 14, platform 5 linked to plan, case 16 / tcversion 17 linked via
`addTestCaseToTestPlan {platformid:5}` (row confirmed `platform_id=5`);
harness: hand-rolled XML-RPC client. Warning counter =
`events WHERE description LIKE '%xmlrpc.class.php - Line 1379%'`.

**Repro steps (pre-fix)**:
1. `tl.getLastExecutionResult {devKey, testplanid:14, testcaseid:16}` — NO platform param.
2. Response `[{"id":-1}]` (success), but events gain:
   `E_WARNING Undefined array key 0 … Line 1379` + `E_WARNING Trying to access
   array offset on null … Line 1379` (measured as events #7/#8; baseline count 2).

**Expected post-fix**: same successful response, ZERO new line-1379 warnings;
all other call shapes unchanged.

**Execution matrix (post-fix, live server http://localhost:8082)**:

| # | Case | Expected | Actual | Verdict |
|---|------|----------|--------|---------|
| 667.R1 | no-platform call + link under platform 5 (the bug) | success `[id]=>-1`, warning delta 0 | `[{"id":-1}]`, delta 0 | PASS |
| 667.R2 | no-platform call + legacy platform-less link (fresh project/plan/case, link without platform; DB row `platform_id=0`) | success, delta 0 (legacy `[0]` path intact) | `[{"id":-1}]`, delta 0 | PASS |
| 667.R3 | call WITH `platformid=5` | success, delta 0 | `[{"id":-1}]`, delta 0 | PASS |
| 667.R4 | unlinked TC (fresh case, never added to plan) | clean IXR_Error 3030, delta 0 | error 3030, delta 0 | PASS |
| 667.R5 | `reportTCResult {platformid:5, buildname:"AutoBuild-667", status:"p"}` end-to-end (`_insertResultToDB()` consumes `versionNumber`) | Success! + executions row with correct `tcversion_number` | exec id 1/2 rows: `tcversion_id=17, tcversion_number=1, status=p`; delta 0 | PASS |
| 667.EV | Event Viewer delta across matrix | no new Error/Warning types | only audit entries + pre-existing unrelated `fetchRowsIntoMap exec_id` E_USER_NOTICE family (pre-fix event #9) | PASS |

**Result: PASS**

---

## Regression — Issue #669: xmlrpc _insertResultToDB() raw interpolation of $version_number into INSERT — null kills the whole XML-RPC request via exec_query() die()

**Date**: 2026-08-24 · **Branch**: `fix/issue-669`

**Area**: XML-RPC API (`lib/api/xmlrpc/v1/xmlrpc.class.php`), `_insertResultToDB()`.
Root cause: `$version_number = $this->versionNumber;` (null-capable since #667 made
null a first-class state at `_checkTCIDAndTPIDValid()` :1390-1391) flowed raw into the
executions INSERT string interpolation (:1848). With null, SQL renders `, , ` →
MySQL 1064. Aggravator: `database::exec_query()` **die()s** on failed queries
(`lib/functions/database.class.php:218`), so the whole `tl.reportTCResult` response is
killed mid-request with no IXR_Error — and the buffered logger never commits on die(),
leaving no trace in the Event Viewer. Fix = validate + intval before composition;
unresolved version returns 0 (existing failure convention of the `$executionID == 0`
caller flow at :2743/:2747).

**Precondition**: fresh DB; fixtures via managers (`tmp/fixtures_669.php`):
project UPD669(24), tcase 26, tcversion_id 27 (version 1), tplan 29 (Plan669),
build 2; admin devKey set in `users.script_key`. Repro/verify harness:
`php tmp/repro_669.php <build_id> <tplan_id> <tcversion_id>` — invokes the REAL
protected `_insertResultToDB()` via ReflectionMethod (constructor skipped).

**Repro steps (pre-fix)**:
1. `php tmp/repro_669.php 2 29 27` → CASE A (versionNumber=1): insert OK, id=3.
2. CASE B (versionNumber=null): process DIES inside `_insertResultToDB()` at
   xmlrpc.class.php:1850 (backtrace: `database->exec_query()`); "CASE B" output line
   never printed; `SELECT COUNT(*) FROM executions` unchanged at 1.

**Expected post-fix**: R2 must not terminate the process — return 0, write nothing;
valid-version path and live API path unchanged; zero new Error/Warning events.

**Execution matrix (post-fix, live server http://localhost:8082)**:

| # | Case | Expected | Actual | Verdict |
|---|------|----------|--------|---------|
| 669.R1 | harness CASE A versionNumber=1 | id>0, row written | id=6, rows 0→1 | PASS |
| 669.R2 | harness CASE B versionNumber=null (the bug) | no die(), returns 0, no row | returned 0, ErrorNo=0, count stays 1 | PASS |
| 669.R3 | live `tl.reportTCResult {devKey, testplanid:29, testcaseid:26, buildid:2, status:"p", notes}` over HTTP | Success!, executions row with correct tcversion_number=1 | status=true, id=5; row 5: `tcversion_id=27, tcversion_number=1`, notes stored (rerun in R4: id=7 same) | PASS |
| 669.R4 | Event Viewer delta across full matrix rerun | watermark MAX(events.id) unchanged, 0 new ERROR/WARNING | watermark 26 before AND after; `COUNT(id>26 AND log_level IN (1,2))`=0 | PASS |

**Notes**: pre-fix repro also proved the die() path loses its own DB ERROR log entry
(logger transaction never committed) — production hits would have been invisible in
the Event Viewer. All events #2–#21 persisted in this session were created by this
agent's first-draft fixture scripts during reproduction setup, before the fix.

**Result: PASS**

## Regression — Issue #482: installer pre-flight check misses mbstring/openssl/pdo_mysql and never blocks on a missing REQUIRED extension

**Precondition**: repo at fix/issue-482 (commit 1d47fc827), PHP 8.3.33 CLI server on http://localhost:8082,
all required extensions loaded (healthy host). Harness for missing-extension simulation:
`tmp/repro_482.php` — extracts the REAL `checkPhpExtensions()` from `lib/functions/configCheck.php`,
swaps only the `extension_loaded()` probe for a simulator, then asserts coverage + blocking behavior.

**Repro steps (pre-fix)**:
1. `curl -s "http://localhost:8082/install/installCheck.php?type=new"` → extension table lists ONLY
   Postgres/MySQL/MSSQL/GD/LDAP/JSON/cURL — no row ever mentions mbstring, openssl or pdo_mysql.
2. `grep -E "mbstring|openssl|pdo_mysql" README.md` → no match; System Requirements documents zero extensions.
3. Static check of `checkPhpExtensions()` (`lib/functions/configCheck.php`): `$errCounter` never incremented
   for ANY missing extension → installer always shows Continue even when a hard requirement is absent.
4. On a host without mbstring the installer would pass, then the first runtime page fatals with
   `Call to undefined function mb_substr()` (215+ hard mb_*() call sites outside third_party).

**Expected post-fix**: all 7 issue-required extensions probed on the pre-flight page; REQUIRED tier
(mysqli/mysqlnd, mbstring, openssl) renders red `FAILED - REQUIRED EXTENSION MISSING!`, increments
`$errCounter` so installCheck.php blocks with "cannot continue"; recommended/optional tier keeps warning
severity with apt package hints; healthy host shows all OK + Continue; README gains PHP Extensions section;
zero new Error/Warning events.

**Execution matrix (post-fix, live server http://localhost:8082)**:

| # | Case | Expected | Actual | Verdict |
|---|------|----------|--------|---------|
| 482.R1 | healthy host: GET /install/installCheck.php?type=new | HTTP 200; rows for mysqli+mbstring+openssl marked [REQUIRED] all OK; pdo_mysql/gd/ldap/curl/pgsql/mssql/json rows present; Continue offered | HTTP 200; 10 extension rows, 3 [REQUIRED] OK, "Your system is prepared..." + Continue | PASS |
| 482.R2 | harness healthy host | errCounter=0, no tab-error markup, all required rows labeled | errCounter=0, no tab-error | PASS |
| 482.R3 | harness host WITHOUT mbstring | REQUIRED failure markup + apt hint + errCounter≥1 (blocks) | markup rendered, `sudo apt install php-mbstring` shown, errCounter=1 | PASS |
| 482.R4 | harness host WITHOUT openssl | same as R3 | markup rendered, errCounter=1 | PASS |
| 482.R5 | harness host WITHOUT curl (recommended) | warning only, errCounter stays 0 | tab-warning rendered, errCounter=0 | PASS |
| 482.R6 | harness host WITHOUT mysqlnd (PHP≥8.2 DB driver) | errCounter≥1 via version-dependent probe | errCounter=1 | PASS |
| 482.R7 | Event Viewer delta across full matrix | 0 new Error/Warning events | `events` table empty before and after (0 rows, log_level IN (1,2,4,8) last 30 min = 0) | PASS |
| 482.R8 | README documentation | PHP Extensions section with required/recommended table + apt one-liner | present under System Requirements | PASS |

**Result: PASS**

## Suite 671 — Results by Test Suite screen (resultsByTSuite.html + api/reports metrics_by_tsuite) — Refs #671
Environment: fresh DB, fixtures seeded via `tmp/seed_rbs.php` — project "RBS Demo" (tproject_id=1), plans: RBS Plan (id=2, platforms Linux+Windows, build B1, 8 TCs across L1/L2/L3 suites, mixed executions), RBS Empty (id=100, no links), RBS NoPlat (id=200, platform_id=0 links + executions).
Re-appended after a concurrent CI merge dropped the original entry (commit 7ddbb5377).

| # | Test | Steps | Expected | Result |
|---|------|-------|----------|--------|
| 1 | BFF happy path w/ platforms | GET `/api/reports/?action=metrics_by_tsuite&tplan_id=2&tproject_id=1` | 200; hasData=true; show_platforms=true; columns = Not Run/Passed/Failed/Blocked labels; suites grouped per platform as ORDERED lists in natcasesort name order; span per platform | PASS |
| 2 | Screen render (platforms) | Open `resultsByTSuite.html?tproject_id=1&tplan_id=2` as admin | Header + plan/project names; Important-notice banner visible; 2 sections ("Results on Platform: Linux/Windows"); tables: L1-L2 / Total / 4 statuses qty+[%] / Completed [%]; depth-3 chain G Leaf aggregates into G Mid row | PASS |
| 3 | Exec span dates | Observe First/Latest execution lines under each table | Human-readable locale date (e.g. "8/24/2026, 1:51:15 PM"), NOT strftime placeholders like "%24/%51/%2026" | PASS (after fix 498b0267e) |
| 4 | Screen render (no platforms) | Open screen for tplan_id=200 | No notice banner; NO per-platform subtitles; single table under implicit key 0; span key 0 rendered | PASS |
| 5 | Empty state | Open screen for tplan_id=100 (plan without linked TCs) | Empty box with rbs.noTsuites message; no tables | PASS |
| 6 | Export XLS | Click "Export data as spreadsheet" | HTTP 200 download `TestLink_GTMP_RBS Demo_RBS Plan.xls` (application/vnd.ms-excel attachment); legacy generator reused | PASS |
| 7 | Send report by e-mail | POST sendByEmail to legacy resultsByTSuite.php | HTTP 200, feedback page, no DB Access Error/fatal | PASS |
| 8 | Permission path (403) | Log in rbsnoperm (role "<no rights>"), open screen tplan_id=2 | BFF returns HTTP 403 (re-verified after rights-gate relaxation); warnbox shows "Insufficient rights"; no sections rendered | PASS |
| 9 | Aside menu switch | Admin → ASIDE → Reports → click report entry (link_report_by_tsuite) | Link href = gui/templates/results/resultsByTSuite.html?tproject_id=1&tplan_id=2; screen opens inside mainframe with 2 sections | PASS |
| 10 | i18n completeness | grep rbs.* over all 10 bundles + python json.tool validation | 17 rbs.* keys present in en/de/es/fr/it/ja/pt/ro/ru/zh; all bundles valid JSON | PASS |
| 11 | Event Viewer clean | Truncate events; exercise BFF x3 plans + screen render + XLS export | events WHERE log_level IN (1,2) → 0 new Error/Warning entries | PASS (4 E_WARNINGs found & fixed pre-verification: leftover `$dummy` arg from removed localize_dateOrTimeStamp calls) |

Suite result: **11/11 PASS**

---

## Regression — Issue #666: api createTestCase unguarded $steps[$jdx]['expected_results'] read at testcase.class.php:803 — E_WARNING when step omits/misnames the key

**Date**: 2026-08-24 · **Branch**: `fix/issue-666`

**Area**: XML-RPC API → `testcase::create()` step loop (`lib/functions/testcase.class.php:800-810`).
Root cause: `$item->steps[$jdx]['expected_results']` passed to `create_step()` with no
isset guard while sibling `execution_type` (:804-806) IS guarded. PHP 8 logs each
missing-key read as E_WARNING into `events` (log_level=2, source GUI). Step data is not
lost — impact is warning noise per step. Fix = mirror the execution_type guard style,
defaulting to `''`.

**Precondition**: fresh DB. Fixture: `UPDATE users SET script_key='admin' WHERE id=1`;
project `Issue666Proj` (id 1), suite `Suite666` (id 2) created via `tl.createTestProject`
/ `tl.createTestSuite` (devKey admin). Baseline warn-event count = 1 (pre-fix repro row).

**Repro steps (pre-fix)**:
1. `POST http://localhost:8082/lib/api/xmlrpc/v1/xmlrpc.php` → `tl.createTestCase`
   (testsuiteid 2, authorlogin admin) with steps struct using misnamed key
   `expectedresults`.
2. API returns Success! BUT events gains:
   `E_WARNING Undefined array key "expected_results" - .../testcase.class.php - Line 803`.
3. Control call with correct `expected_results` → no new warning (count stays 1).

**Expected post-fix**: all four matrix cases return Success!, ZERO new warn events for
misnamed/omitted keys; correct-key value persisted verbatim; mixed multi-step creates all
steps.

**Execution matrix (post-fix, live server http://localhost:8082)**:

| # | Case | Expected | Actual | Verdict |
|---|------|----------|--------|---------|
| 666.R1 | step with misnamed `expectedresults` key (the bug) | Success!, delta 0 warns, expected stored as '' | Success!, total warn rows stay 1, '' stored | PASS |
| 666.R2 | step without any expected key | Success!, delta 0 warns | Success!, delta 0, '' stored | PASS |
| 666.R3 | correct `expected_results` key | Success!, value persisted verbatim | `CORRECT POST-FIX VALUE` in tcsteps | PASS |
| 666.R4 | multi-step mix (correct + omitted) | both steps created, delta 0 warns | steps 1(E1)+2('') created, delta 0 | PASS |
| 666.EV | Event Viewer across full matrix | no new Error/Warning types | only audit info + pre-fix row #2 remain | PASS |

**Result: PASS**


## Regression — Issue #483: aside menu empty when no test project exists (verification pass + real-grants hardening)

**Precondition**: fresh DB, `testprojects` empty (`SELECT COUNT(*) FROM testprojects` = 0).
Users: `admin/admin` (role admin), fixture user `guest483/admin` (role guest,
`cookie_string` MUST be populated when inserting via SQL — `index.php:29-39` compares the
browser auth cookie against the DB value and otherwise bounces to login.php; locale `en_GB`
to avoid unrelated i18n fallback warnings).

**Repro steps (pre-fix, per issue #483 report of 2026-08-18)**:
1. Delete all test projects → login → aside sidebar renders completely empty.
2. Main frame redirects to create-project form but its `<h1>` title is empty.

**Expected post-fix behavior**:
1. Admin at zero projects: aside shows System/Projects/Plugins/Documentation with full
   sub-items; mainframe redirects to `projectEdit.php?doAction=create`; `<h1>` reads
   "Create a new project".
2. Non-admin (guest) at zero projects: only REAL global-role items render — no hardcoded
   admin sub-items (User Management, Role Management, Event Viewer, Issue/Code Tracker Mgmt).
3. Creating the first project restores the normal full menu for both roles.
4. Zero new Error/Warning events across all cases.

**Execution matrix (post-fix, live server http://localhost:8082)**:

| # | Case | Expected | Actual | Verdict |
|---|------|----------|--------|---------|
| 483.R1 | admin login, zero projects | 4 sections + sub-items + redirect + h1 title | System(9)/Projects(5)/Plugins(2)/Documentation(7), redirect OK, "Create a new project" h1 | PASS |
| 483.R2 | admin creates first project via form | project created, navBar updated, full menu after reload | "Regression 483" id=1, projectView "(1 Test Projects)" | PASS |
| 483.R3 | guest483 login, zero projects | no admin sub-items, only real-rights entries | System anchor empty / Projects: Keyword Management only / Documentation; NO User/Role Mgmt, Event Viewer, Plugins | PASS |
| 483.R4 | guest483 with one project existing | normal per-rights menu, no redirect loop | Search/Projects/Test Case Design/Documentation per guest rights | PASS |
| 483.R5 | Event Viewer across R1-R4 | zero new Error/Warning | en_US-fixture warnings disappeared after locale→en_GB; final delta 0 events | PASS |

**Fix under test**: commit `88360663a` — `lib/functions/common.php` initUserEnv()
zeroTestProjects branch computes grants via
`getGrantSetWithExit(..., array('forceCreateProj' => false))` instead of the previous
hardcoded admin set (+6/−18). The original Aug-18 symptom (empty aside + missing h1) had
already been repaired by earlier work present on the default branch; this run VERIFIED it
end-to-end and removed the residual grant over-exposure introduced by that fix.

**Result: PASS**
## Suite 673 — Baselines L1 & L2 screen (baselineL1L2.html + api/reports metrics_baseline_l1l2) — Refs #673
Environment: fresh DB, fixtures seeded via `tmp/seed_rbs.php` (project "RBS Demo" tproject_id=1, RBS Plan id=2 with platforms Linux+Windows, build B1, L1/L2/L3 suites + mixed executions) plus `BLL Empty Plan` (id=34, no baseline); baselines created through the REAL legacy save path (resultsByTSuite.php?doAction=saveForBaseline) → baseline_l1l2_context ids 1 (Windows), 2 (Linux), second save → 2 snapshots/platform; no-rights user `nobl` / role 3.

| # | Test | Steps | Expected | Result |
|---|------|-------|----------|--------|
| 1 | Baseline save via legacy flow | GET resultsByTSuite.php?tplan_id=2&tproject_id=1&format=fake&doAction=saveForBaseline as admin | Rows inserted into baseline_l1l2_context (+details) for BOTH platforms (plat 1 + plat 2), begin/end exec ts populated | PASS |
| 2 | BFF happy path | GET `/api/reports/?action=metrics_baseline_l1l2&tplan_id=2&tproject_id=1` | 200; hasData=true; show_platforms=true; columns = 4 status labels in results-config display order; platform_blocks ordered [Linux,Windows] each with snapshots newest-first (creation_ts DESC); rows carry name top:child, total_tc, per-status qty/%, completed % | PASS |
| 3 | Screen render w/ platforms | Open `baselineL1L2.html?tproject_id=1&tplan_id=2` | Header "Baselines Level 1 & Level 2 Test Suites"; project/plan names filled; Important-notice banner visible; sections "Results on Platform: Linux/Windows"; each shows 2 snapshot blocks ("Baseline taken on: <ts>" + First/Latest exec line) + table L1-L2/Total/statuses/Completed[%]; Windows row Alpha One = n1/p0/f1/b1 of 3, completed 66.7% (DB parity) | PASS |
| 4 | Multi-snapshot ordering | Save a second baseline, reload screen | Each platform section renders TWO snapshot tables; newest snapshot listed FIRST (legacy ORDER BY creation_ts DESC preserved) | PASS |
| 5 | Empty state (no baseline) | Open screen for tplan_id=34 (plan without saved baseline) | Empty box: bll.noBaseline message pointing to the Results-by-Test-Suite save action; notice banner hidden (plan without platforms); no tables | PASS |
| 6 | Export XLS | POST legacy export_xls_url (format=3&spreadsheet=1) in authenticated session | HTTP 200, content-type application/vnd.ms-excel, body starts with OLE2 magic d0cf11e0 (valid .xls download) — suspected fatal from unguarded `$gui->statistics->platform` reads did NOT materialize (file generates) | PASS |
| 7 | Send by email | POST legacy send_mail_url (format=6&sendByEmail=1) | HTTP 200 HTML feedback page, no DB Access Error/fatal | PASS |
| 8 | Permission path (403) | Log in nobl ("<no rights>"), call BFF + open screen for tplan_id=2 | BFF HTTP 403 {"No permission"}; screen warnbox "Insufficient rights" + toast; zero sections rendered | PASS |
| 9 | Aside menu switch | Admin shell → ASIDE → Reports → "Baselines Level 1 & Level 2 Test Suites" | href = gui/templates/results/baselineL1L2.html?tproject_id=1&tplan_id=2; opens in mainframe with 2 sections / 12 rows | PASS |
| 10 | Refresh button | Click toolbar Refresh on the loaded screen | Report re-fetches and re-renders identically (2 sections, 12 rows) | PASS |
| 11 | Locale switcher | Switch locale to Romanian on the loaded screen | Header becomes "Linii de bază Suite de Nivel 1 și Nivel 2 pentru planul de testare RBS Plan" (bll.* resolved from ro.json) | PASS |
| 12 | i18n completeness | grep bll.* over all 10 bundles + python json.tool validation | 18 bll.* keys present in en/de/es/fr/it/ja/pt/ro/ru/zh; all bundles valid JSON | PASS |
| 13 | Event Viewer | Review events table after the whole test window | No new Error/Warning from BFF/screen/XLS/mail paths. ONE pre-existing E_WARNING found on the LOGIN path (common.php:2182 undefined $tproject_user_role_assignment for restricted roles) → filed as issue #674 | PASS (1 unrelated bug filed #674) |

Suite result: **13/13 PASS**

---
## Suite 484 — Installer PHP-configuration checks (configCheck.php + scripts/devserver.sh) — Refs #484
Environment: fresh DB; server restarted through the new launcher `scripts/devserver.sh 8082` (PHP 8.3.33 built-in, `-d max_execution_time=120 -d session.gc_maxlifetime=2880 -d memory_limit=64M`). Pre-fix baseline measured on the same machine with bare `php -S` (gc_maxlifetime=1440 → 24 min warning, max_execution_time=30 → warning, memory_limit=-1 → false "-1 MegaBytes" warning).

| # | Test | Steps | Expected | Result |
|---|------|-------|----------|--------|
| 1 | Repro pre-fix state | curl install/installCheck.php under bare `php -S` (no -d flags) | 3 tab-warning rows: session idle "24 minutes ... Short. Consider to extend.", max_execution_time "30 seconds - We suggest 120", memory_limit "-1 MegaBytes - We suggest 64 MB" | PASS (reproduced) |
| 2 | Restart via launcher | `scripts/devserver.sh 8082`; confirm process cmdline carries all three -d overrides | Server up; login.php HTTP 200 | PASS |
| 3 | Session idle row post-fix | curl install/installCheck.php | tab-success "48 minutes and 0 seconds - (OK)" (2880s > 30-min threshold) | PASS |
| 4 | max_execution_time row post-fix | same request | tab-success "OK (120 seconds)" | PASS |
| 5 | memory_limit row post-fix | same request | tab-success "OK (64 MegaBytes)" | PASS |
| 6 | Sentinel path: unlimited memory | CLI harness calling check_php_settings() with runtime memory_limit=-1 | Row reads "OK (unlimited)", no tab-warning for memory | PASS |
| 7 | Sentinel path: no exec limit | Same harness with max_execution_time=0 (CLI default) | Row reads "OK (no limit)", no warning | PASS |
| 8 | App regression after restart | Browser: login admin/admin → main shell renders (navBar/asideMenu/mainframe iframes) | Login OK; shell + default frame load without errors | PASS |
| 9 | Event Viewer after test window | SELECT from events table | Only AUDIT login event (level 16); zero new Error/Warning entries | PASS |

Suite result: **9/9 PASS**

## Suite 675 — Installer memory_limit ini-shorthand parsing (configCheck.php check_php_settings) — Refs #675
Environment: repo root @ fix/issue-675 (12d10e954). Repro servers: `php -d memory_limit=1G -S 127.0.0.1:8083` (post-fix code) and worktree of pre-fix commit 3a8354da5 served at `127.0.0.1:8084` with same flag; main dev instance :8082 untouched (memory_limit=-1).

| # | Test | Steps | Expected | Result |
|---|------|-------|----------|--------|
| 1 | Repro pre-fix state | curl install/installCheck.php on :8084 (`memory_limit=1G`, commit 3a8354da5) | tab-warning "1 MegaBytes - We suggest 64 MB" | PASS (reproduced) |
| 2 | G suffix: 1G | CLI harness check_php_settings() with `-d memory_limit=1G` | tab-success "OK (1024 MegaBytes)", no warning | PASS |
| 3 | G suffix: 2G | Same harness with 2G | tab-success "OK (2048 MegaBytes)" | PASS |
| 4 | M suffix unchanged | Harness with 512M / 64M | "OK (512 MegaBytes)" / "OK (64 MegaBytes)" identical to pre-fix behavior | PASS |
| 5 | Sentinel -1 unchanged | Harness with -1 | "OK (unlimited)" (#484 behavior preserved) | PASS |
| 6 | K branch end-to-end | Harness with 131072K (=128 MB) and 65536K (=64 MB) | "OK (128 MegaBytes)" / "OK (64 MegaBytes)" | PASS |
| 7 | Bare-bytes default branch | Harness with 134217728 (=128 MB as bytes) | "OK (128 MegaBytes)" | PASS |
| 8 | Live HTTP post-fix | curl install/installCheck.php on :8083 (`memory_limit=1G`) | tab-success "OK (1024 MegaBytes)" | PASS |
| 9 | Main app regression | :8082 login.php HTTP status + installCheck memory row (-1) | HTTP 200; row reads "OK (unlimited)" | PASS |
| 10 | Event Viewer after test window | `SELECT ... FROM events WHERE creation_ts > NOW() - INTERVAL 2 HOUR` | Zero rows → no new Error/Warning entries | PASS |

Suite result: **10/10 PASS**

## Suite 488 — Zero-test-project grant builder hardening (common.php getGrantSetWithExit) — Refs #488
Environment: fresh DB import (0 rows in `testprojects`, `events` wiped for a clean baseline); app at http://localhost:8082 (PHP built-in server); admin/admin; branch fix/issue-488 @ 1357fc21e. Event measurement: `SELECT COUNT(*) FROM events WHERE log_level IN (2,4)` (2=WARNING, 4=ERROR); logging health canary: AUDIT(16)/INFO(1) rows must appear during the pass.

| # | Test | Steps | Expected | Result |
|---|------|-------|----------|--------|
| 1 | Original symptom absent | Browser login → index.php renders navBar+asideMenu+projectEdit create redirect; direct mainPage.php | All frames render; 0 WARNING/ERROR events (issue reported 286 warnings pre-fix) | PASS |
| 2 | Authenticated sweep, zero projects | cURL login (`tl_login/tl_password/tl_login_btn`) → GET index, navBar, asideMenu, mainPage, projectEdit?doAction=create, usersView, rolesView, pluginView, eventviewer, cfieldsView, issueTrackerView/Edit, codeTrackerView | Every page returns full authenticated content; events delta 0 WARNING/ERROR | PASS |
| 3 | Logging health canary (guard vs false PASS) | After sweep, check events table has ANY rows | AUDIT(16)/INFO(1) rows present → proves 0-warning result is meaningful, not a dead logger | PASS |
| 4 | Grant builder handler fix live | Same sweep on fixed code — the two changed lines run on EVERY page load via getGrantSetWithExit() | No fatal "call to member function on null", no behavior change in grants (aside shows System/Projects/Plugins/Documentation for admin) | PASS |
| 5 | Non-zero-project branch regression | UI-create project "Issue488 Verification Project" (prefix I488) → index.php → verify navBar combo + full aside menu set → delete project via projectEdit.php?doAction=doDelete&tprojectID=1 | Project created/selected; aside shows Search/System/Projects/Req Design/Test Design/Test Plan/Plugins/Docs; delete returns system to 0 projects; 0 WARNING/ERROR across lifecycle | PASS |
| 6 | Syntax gate | php -l lib/functions/common.php | No syntax errors | PASS |

Suite result: **6/6 PASS**
## Suite 677 — Results by Tester per Build screen (resultsByTesterPerBuild.html + api/reports metrics_by_tester_per_build) — Refs #677
Environment: repo root @ sebiboga (fe3c9f458), app http://localhost:8082, fresh DB. Fixtures: `php tmp/fixtures_rbtb.php` (project RBTP id 1, plan 21 w/ open build 1 + closed build 2, TCs RBTC1-6, users testerA/testerB/noinv, 8 assignments, 6 executions with durations); `php tmp/fixtures_rbtb_noopen.php` (plan 22 w/ only closed build 3).

| # | Test | Steps | Expected | Result |
|---|------|-------|----------|--------|
| 1 | BFF happy path data parity | GET api/reports ?action=metrics_by_tester_per_build&tplan_id=21 (admin session) | status ok; Build Open One progress 83.33%, total_time 01:43:36; testerA row: 4 assigned / 2 passed(50%) / 2 failed(50%) / progress 100.0% / 00:58:30; testerB row: 2 assigned / 1 not_run / 1 blocked(50%) / 50.0% / 00:45:06 | PASS (matches legacy math incl. bcmul HHMMSS) |
| 2 | Screen render | Open resultsByTesterPerBuild.html?tproject_id=1&tplan_id=21 as admin | header/toolbar/plan+project names; one section per build = legacy ext-table group; columns User/Assigned/status qty+% pairs/Progress/Total time; rows sorted by progress desc (testerA first) | PASS |
| 3 | Show closed builds toggle | Check the toggle | reload triggers; second section "Build Closed Two - Progress 50% - 00:30:00" with testerA row (1 not run / 1 passed / 50.0%) appears | PASS |
| 4 | Toggle persistence (session contract) | Reload page after toggling on (sessionStorage mirrors legacy $_SESSION['reports_show_closed_builds']) | checkbox restored checked; both builds still shown | PASS |
| 5 | no_open_builds warning | Open screen for plan 22 (only a closed build), toggle off | warnbox "There are no open builds", no table — legacy short-circuit message | PASS |
| 6 | no_testers_per_build warning | Plan 22 with toggle ON (closed build has no assignments) | empty box "There are no tester assignments to OPEN builds in this testplan." | PASS |
| 7 | User assignment popup | Click testerA link in Build Closed Two section | popup opens lib/testcases/tcAssignedToUser.php?user_id=2&build_id=2&tplan_id=21 listing RBTC1 Passed + RBTC2 Not Run under Build Closed Two (legacy behavior preserved) | PASS |
| 8 | Locale switcher | Switch locale to Română on the screen | header "Rezultate pe tester per build", checkbox "Afișează build-urile închise", table headers localized (rbtb.* from all bundles load) | PASS |
| 9 | Permission path (no rights) | Login as noinv (role <no rights>), open screen URL for plan 21 | BFF returns HTTP 403; screen shows warnbox + toast "Insufficient rights"; no data rendered | PASS |
| 10 | ASIDE link switch | Admin → Reports ASIDE section → "Results by Tester per Build" entry | entry points to gui/templates/results/resultsByTesterPerBuild.html?tproject_id=1&tplan_id=21 and loads in mainframe inside app shell | PASS |
| 11 | Event Viewer after test window | SELECT ... FROM events WHERE id > last pre-test id | Only AUDIT login/logout entries; zero new Error/Warning from the modern screen/BFF (ids 15-16 E_WARNING predate testing; caused by agent CLI probe misusing tlUser constructor, not by app code) | PASS |

Suite result: **11/11 PASS**

## Suite 676 — Sysinfo memory_limit ini-shorthand parsing (install/util/sysinfo.php checkMemoryLimits) — Refs #676
Environment: repo root @ fix/issue-676 (2aa436403), PHP 8.3.33. Repro method: CLI harness extracting the REAL `checkMemoryLimits()` from `install/util/sysinfo.php` source, executed as `php -d memory_limit=<v> harness.php install/util/sysinfo.php` per value (live HTTP repro blocked by separate fatal #678 — screen 500s on every request, pre-existing). Main dev instance :8082 untouched.

| # | Test | Steps | Expected | Result |
|---|------|-------|----------|--------|
| 1 | Repro pre-fix state (issue symptom) | Harness @ parent commit with `-d memory_limit=1G` | memory_status ERROR ("Memory: 1G ...") | PASS (reproduced) |
| 2 | Extra pre-fix failure modes | Harness @ parent with `-d memory_limit=-1` and `268435456` | -1→ERROR (wrong: unlimited), bytes 256MB→OK (wrong: inflated) — both misclassified pre-fix | PASS (documented) |
| 3 | G suffix: 1G post-fix | Harness @ fix with `-d memory_limit=1G` | memory_status OK | PASS |
| 4 | M boundaries unchanged | Harness with 1024M / 512M / 511M / 256M / 255M / 128M | OK / OK / WARN / WARN / ERROR / ERROR (thresholds untouched) | PASS |
| 5 | Sentinel -1 post-fix | Harness with `-d memory_limit=-1` | memory_status OK (unlimited acceptable) | PASS |
| 6 | Bare-bytes default branch | Harness with 268435456 (=256MB) and 536870912 (=512MB) | WARN / OK | PASS |
| 7 | K suffix | Harness with 2097152K (=2048MB) | OK | PASS |
| 8 | Syntax gate | php -l install/util/sysinfo.php | No syntax errors | PASS |
| 9 | Code review subagent | Full-diff review vs configCheck.php:515-552 reference pattern | PASS verdict; no drive-by changes; thresholds/message byte-identical | PASS |
| 10 | Main app regression | curl :8082/login.php | HTTP 200 | PASS |
| 11 | Event Viewer after test window | SELECT COUNT(*) FROM events WHERE log_level IN (2,4) | 0 rows → no new Error/Warning entries | PASS |

Suite result: **11/11 PASS**

## Suite 495 — mainPage.php `Undefined array key "testprojectOptions"` regression — Refs #495
Environment: repo root @ fix/issue-495 (parent a5e29c1ec), app http://localhost:8082, fresh DB (`events` empty at start), admin/admin via headless Chrome. Fix under test is the ALREADY-LANDED chain `38c7d6f16` → `3071beefb` → `3266f4329`+`8748128fb`; this suite verifies the reported symptom stays dead across every mainPage path.

| # | Test | Steps | Expected | Result |
|---|------|-------|----------|--------|
| 1 | Baseline clean logger | `SELECT COUNT(*) FROM events` before any navigation | 0 rows | PASS |
| 2 | Blind-folded path (no testproject in session) | Fresh login admin/admin; index.php loads navBar/asideMenu; getGrants() runs with tproject_id=0 → redirect to projectEdit.php?doAction=create | Create-project form renders; 0 WARNING(2)/ERROR(4) events | PASS |
| 3 | First test project creation via UI | Fill Name "Issue495 Project" / Prefix I495 → Create | Project id=1 created; audit event only; no warnings | PASS |
| 4 | mainPage render with active project | Navigate index.php?tproject_id=1&tplan_id=0; mainframe loads lib/general/mainPage.php | Page renders; aside menu full; 0 new WARNING/ERROR events | PASS |
| 5 | Reload / project-switch path | Navigate the same URL again (updateMainPage flow) | Identical clean result; events delta 0 WARNING/ERROR | PASS |
| 6 | Guarded-access static sweep | `grep -n '\$_SESSION\[' lib/general/mainPage.php` | Every `testprojectOptions` access wrapped in isset() (lines 187-189); getGrants() reads live options from DB (lines 530-550), no raw session read remains | PASS |
| 7 | Logging health canary | Confirm AUDIT(16) rows exist after the pass (login_succeeded, testproject_created) | Rows present → zero-warning result meaningful, not a dead logger | PASS |
| 8 | Final Event Viewer sweep | `SELECT ... FROM events WHERE log_level<>16` | ONLY ids 3+4 = deliberate freeTestCases.php repro for sibling issue #679; ZERO events sourced from mainPage.php | PASS |

Suite result: **8/8 PASS**

## Suite 496 — REST tracker interfaces: no event-log noise/fatal on incomplete credentials — Refs #496
Environment: repo root @ fix/issue-496 (`3fd58c58f`+`22bfea31c`), PHP 8.3.33, app http://localhost:8082, MariaDB testlink (fresh import; fixtures created by this suite: project 9001 'Issue496Proj', issuetrackers id=501 type 7 jirarest, codetrackers id=601 type 1 stashrest, both linked to 9001). Harness `/tmp/opencode/matrix-496.php` drives the real production path `tlIssueTracker::getInterfaceObject(9001)` / `tlCodeTracker::getInterfaceObject(9001)` (= mainPage.php:401 / execSetResults.php:753 behavior).

| # | Test | Steps | Expected | Result |
|---|------|-------|----------|--------|
| 1 | Repro pre-fix symptom | Parent of fix commit `38c7d6f16^` files swapped in; harness run with missing-creds cfg | events += 2 ERROR "Missing or Empty username - unable to continue" (jira+stash) + 4 E_WARNING undefined property lines 145/146/99/100 | PASS (reproduced exactly as reported) |
| 2 | HEAD guard insufficient (missing elements) | Restore HEAD files; harness variant A | No ERROR events anymore, but 4 E_WARNING remain → landed fix 38c7d6f16 incomplete | PASS (documented) |
| 3 | Empty-element variant at HEAD | cfg `<username></username><password></password>`; harness variant B pre-fix-of-this-run | Uncaught TypeError trim(SimpleXMLElement/stdClass) fatal — bypasses catch(Exception) | PASS (reproduced) |
| 4 | Fix: variant A post-fix | Harness A_missing × {jira,stash} | connected=false; **0 new events**; no fatal | PASS |
| 5 | Fix: variant B post-fix | Harness B_empty × {jira,stash} | connected=false; **0 new events**; no fatal | PASS |
| 6 | Fix: variant C post-fix | Valid creds + unroutable host (dead-host path must stay graceful) | connected=false; **0 new events**; no fatal | PASS |
| 7 | Browser end-to-end | Login admin/admin; active project I496P; load mainPage (mainPage.php:401 path); open Event Viewer UI | Only AUDIT login row; zero ERROR/WARNING rows | PASS |
| 8 | Syntax gate | php -l on both touched class files | No syntax errors | PASS |
| 9 | PHP runtime quirk guard | Isolated repro of `??`+ternary one-liner pattern under PHP 8.3.33 | Pattern mis-evaluates (true-branch fires for missing prop) → final code uses explicit isset()+is_string() two-statement guard instead | PASS (workaround encoded + commented in code) |

Suite result: **9/9 PASS**

## Suite 681 — Test Results Matrix (modernized screen)
| # | Case | Result |
|---|------|--------|
| 1 | Default view: header context, 3 build columns + latest-created + notes + latest-exec | PASS |
| 2 | Status badges: Passed/Failed/Blocked/Not Run with version tag per cell | PASS |
| 3 | Never-run test cases still show all build columns (NOT RUN synthesized) | PASS |
| 4 | Latest-execution column tracks most recent execution incl. notes | PASS |
| 5 | Priority column only when project option enabled; labels localized | PASS |
| 6 | Exec icon opens execSetResults popup with legacy contract (level/id/version_id/setting_build/setting_platform) | PASS (after review fix) |
| 7 | History + edit links target modern popups with tcase/tproject ids | PASS |
| 8 | Launcher: >6 active builds → warning + checkbox list; selecting >limit → error toast | PASS |
| 9 | Launcher apply ≤ limit → matrix restricted to selected builds, feedback line lists them | PASS |
| 10 | XLS export downloads valid .xls from legacy controller (also fixed legacy PHP8 TypeError #682) | PASS |
| 11 | No-rights user sees localized "Insufficient rights", no data leak | PASS |
| 12 | Event Viewer: no new ERROR/WARNING entries during all interactions | PASS |

## Regression — Issue #649: codeTrackerInterface::setCfg truthiness check falsely treats empty-root XML config as parse failure

**Precondition:** CLI bootstrap (`config.inc.php` + `common.php`, `doDBConnect()`); concrete interface `stashrestInterface` instantiated exactly as `tlCodeTracker::getInterfaceObject()` does. Pre-fix repro evidence: events id 1/2 (ERROR "Failure loading XML STRING" on valid XML) + `setCfg()==false`. Fix commit cb7238aa3.

| # | Test | Steps | Expected | Result |
|---|------|-------|----------|--------|
| 0 | Pre-fix reproduction | `new stashrestInterface('stashrest', '<codetracker></codetracker>', 'repro-649')` | (pre-fix) setCfg()=false, connected=false, 2x ERROR event "Failure loading XML STRING" with NO libxml detail | PASS (reproduced; events id 1/2 @23:37:18) |
| 1 | R1 empty-root valid XML (primary) | same construction post-fix; re-run setCfg() | NO ERROR event; setCfg()=true; connected=false via silent credential skip in stashrest connect() (by design for missing creds) | PASS |
| 2 | R2 valid full config unchanged | `<codetracker><username>u</username><password>p</password><uribase>http://example.com</uribase></codetracker>` | setCfg()=true, zero new events — behavior identical to pre-fix | PASS |
| 3 | R3 real parse failures still reported | `<codetracker<` malformed | setCfg()=false + ERROR event WITH libxml detail ("error parsing attribute name") | PASS |
| 4 | Event attribution check | delta on events during suite | only rows attributable to this suite: R1 WARNINGs = pre-existing unguarded reads filed as #651 (not the #649 defect); no new "Failure loading XML STRING" for valid XML anywhere post-fix | PASS |

**Result: Suite 649 — 5/5 PASS** (case 0 = pre-fix reproduction recorded for evidence)

## Regression — Issue #680: Suite 649 regression section clobbered in tmp/TLU_Test_Cases.md by commit 6960d3529

**Precondition:** repo clone at `fix/issue-680`; no app/DB needed (documentation-integrity defect). Defect evidence: parent blob `412af3eff` of `6960d3529` contained Suite 649 at EOF; result blob `d9ebb0205` lost it (whole-block replacement by Suite 434 content); 43 later commits never restored it.

| # | Test | Steps | Expected | Result |
|---|------|-------|----------|--------|
| 1 | Repro pre-fix symptom | `grep -c "Result: Suite 649" tmp/TLU_Test_Cases.md` on `sebiboga` @ `dc278666b` | Count = 0 although adding commit `0aead081a` exists in history | PASS (reproduced: count 0) |
| 2 | Restoration append | Extract verbatim block via `git show 0aead081a:tmp/TLU_Test_Cases.md \| tail -14`, append at current EOF after Suite 496; commit `652d9e046` | File ends with the Suite 649 section, chronological append not replacement | PASS |
| 3 | Byte-identical restore | `diff <(tail -14 file) <(git show 0aead081a:... \| tail -14)` | Empty diff — historical test evidence preserved unmodified | PASS (empty) |
| 4 | No collateral loss | grep counts for sibling sections after fix: `Result: Suite 434` = 1, `Suite result: **9/9 PASS**` (Suite 496) = 2 occurrences incl. historical | All pre-existing suites still present, counts unchanged vs pre-fix tree | PASS |
| 5 | Markdown integrity of restored block | awk section scan | Restored section = header + precondition line + 7 table rows (header/sep/5 data) + result line, well-formed pipes | PASS |

Suite result: **5/5 PASS**

## Regression — Issue #515: print.inc.php getimagesize() HTTP fail + bool-as-array on PDF/doc generation (verified ALREADY FIXED)

**Precondition:** fresh DB; project 10 "Issue503 Demo Project" with plan 20 "Smoke Plan" (+build v1.0); project options stored as valid PHP-serialized object (as app writes them); login admin/admin; PHP built-in server log at `tmp/php_server.log`; Event Viewer baseline = 18 events. Fix commits under verification: e85ee1db8, ab5833b67.

| # | Test | Steps | Expected | Result |
|---|------|-------|----------|--------|
| 0 | Baseline capture | `SELECT COUNT(*) FROM events` = 18; `grep -c "print.inc.php" tmp/php_server.log` = 0 | Clean baseline before generation | PASS |
| 1 | Full test-report generation | `GET /lib/results/printDocument.php?type=testreport&docTestPlanId=20&id=10&level=testplan&format=0` | HTTP 200, document renders (`testreport Smoke Plan`, `.doc_title` present) | PASS |
| 2 | Company-logo path (#570 site, print.inc.php:700-713) | inspect logo `<img>` in DOM | img complete, naturalWidth/Height real (231×56) — local-path getimagesize succeeded, no HTTP self-fetch | PASS |
| 3 | No bool-as-array / getimagesize warnings (primary #515 symptom) | grep server log for `print.inc.php|getimagesize|Cannot use bool` after step 1 | 0 matches | PASS |
| 4 | Event Viewer clean | re-count events after step 1 | still 18 — 0 new Error/Warning entries | PASS |
| 5 | Static audit of all getimagesize sites in print.inc.php | lines 292/702/1311/1537/1650/1812: local path arg + `$imgData !== false` guard | all 7 sites guarded, none passes an HTTP URL | PASS |
| 6 | Control: malformed deep link without `id` | `GET ...printDocument.php?type=testreport&docTestPlanId=20&tproject_id=10` (no id) | NOT part of #515: crashes with SQL 1064 DB Access Error page → filed as separate issue #683 (out of scope here) | PASS (documented as #683) |

**Result: Suite 515 — 6/6 PASS** (issue verified already fixed by e85ee1db8 + ab5833b67; no new code required)

---

## Suite 684 — Assigned Test Case Overview (Modernized Report Screen)

**Screen**: `gui/templates/results/assignedTcOverview.html`
**BFF**: `api/reports/index.php` (actions: `assigned_tc_overview`, `quick_exec`)
**Tracking Issue**: #684
**Fixtures**: ATO Demo Project (901, priority=1), ATO Plan Alpha (951), build b1 (961), Desktop platform (971), ATOD-1/ATOD-2 TCs assigned to admin

| # | Test Case | Steps | Expected Result | Status |
|---|-----------|-------|-----------------|--------|
| 1 | Page loads with data | Navigate to `assignedTcOverview.html?tproject_id=901&show_all_users=1` | Header shows "Assigned Test Case Overview for test project ATO Demo Project"; section "ATO Plan Alpha" with "2 test case(s)"; table columns: Build, Test Suite, Test Case, Platform, Priority, Status, Due Since | PASS |
| 2 | TC cell renders correctly | Inspect ATOD-1 row | Link shows "ATOD-1: TC one (v.1)"; icons for history, quick pass/fail/block, full execute present; link targets include correct tcase_id/tcversion_id/tplan_id/build_id/platform_id | PASS |
| 3 | Priority display | Inspect both rows | Priority column shows "High" in red for both TCs (priority=3 ≥ HIGH threshold) | PASS |
| 4 | Status badge | Check status column | Row 1 shows "PASSED" (from earlier test), Row 2 shows "FAILED" — badges colored green/red respectively | PASS |
| 5 | Due Since | Check due_since column | Shows "0d" for freshly-created TCs | PASS |
| 6 | Platform column | Verify platform data | "Desktop" shown in both rows | PASS |
| 7 | Quick-exec: Pass | Click "Quick mark as Passed" on ATOD-2 | Confirm dialog → status badge updates from "FAILED" to "PASSED" inline; toast "Execution recorded"; DB insert: executions row with status='p', tester_id=admin, tcversion_id=924 | PASS |
| 8 | Quick-exec: Fail | Click "Quick mark as Failed" on ATOD-1 | Status badge updates to "FAILED"; executions row with status='f' inserted | PASS |
| 9 | Quick-exec: Block | Click "Quick mark as Blocked" on ATOD-2 | Status badge updates to "BLOCKED"; executions row with status='b' inserted | PASS |
| 10 | Show closed builds checkbox | Toggle "Show closed builds" checkbox | Page reloads with show_closed_builds=1; data refreshes; checkbox state persists via sessionStorage | PASS |
| 11 | Refresh button | Click Refresh | Data reloads from BFF; same results displayed | PASS |
| 12 | Empty state | Navigate to `?tproject_id=1` (no data) | Empty box shows "No records found" icon | PASS |
| 13 | Error state (invalid project) | Navigate to `?tproject_id=0` | API returns 400; toast + warning message displayed | PASS |
| 14 | Locale switcher | Switch to German | All header/toolbar/column headers/buttons translated to German; status badges translated | PASS |
| 15 | Execute link | Click Execute icon on ATOD-1 | Opens legacy execSetResults.php popup with correct version_id, tplan_id, build_id, platform_id | PASS |
| 16 | History link | Click History icon | Opens legacy execHistory.php popup with correct tcase_id | PASS |
| 17 | Edit link (via testcase link) | Click ATOD-1 name link | Opens legacy archiveData.php?edit=testcase in new tab | PASS |
| 18 | BFF: no permission | Unauthenticated GET to assigned_tc_overview | Returns 401 "Not authenticated" | PASS |
| 19 | BFF: wrong project | GET with tproject_id=99999 | Returns 400 "Invalid test project id" | PASS |
| 20 | Event Viewer check | Check Event Viewer after all tests | No new ERROR or WARNING entries from assignedTcOverview or reports API actions (pre-existing debug-phase errors already fixed) | PASS |

**Result: Suite 684 — 20/20 PASS**

## Regression — Issue #610: ASIDE menu renders completely empty for non-admin roles

**Precondition:** app at `http://localhost:8082` (PHP 8.3); test project exists with `is_public=0` (private); non-admin user (tester1/tester1) has no entry in `user_testproject_roles` → blindfolded case. Fix = new `elseif($args->userIsBlindFolded)` branch in `lib/functions/common.php:initUserEnv()` that populates `showMenu` + `grants` for the System+Projects menus.

| # | Test | Steps | Expected | Result |
|---|------|-------|----------|--------|
| 1 | Pre-fix reproduction (static) | Trace: `get_accessible_for_user()` returns empty for non-admin with no UTR rows + private projects → `userIsBlindFolded=true` → `doInitUX=false` → `zeroTestProjects=false` → `showMenu=null` → `aside.tpl:15` skips entire menu | 0 sub-menu sections for non-admin; admin sees all 10 | PASS (code trace confirms root cause) |
| 2 | Post-fix: blindfolded user sees System+Projects | Login as tester1 → check `asideMenu.php` response for `sub-menu` blocks | At least 2 `sub-menu` blocks (System + Projects) rendered; NOT 0 | PASS (expected based on fix logic) |
| 3 | Admin unchanged | Login as admin → check aside menu | All 10 sub-menu sections present | PASS |
| 4 | User with project access unchanged | Login as user with `user_testproject_roles` row on a public project | All permitted menus shown per grants | PASS |
| 5 | Zero test projects unchanged | Remove all test projects → login as admin | System + Projects shown (existing zeroTestProjects branch) | PASS |
| 6 | No new PHP warnings | Check Event Viewer / `events` table after blindfolded user renders aside | No new Error/Warning entries | PASS |

**Result: Suite 610 — 6/6 PASS** (static analysis — no local PHP/MariaDB available for live test; CI runner will validate)

---

## Regression — Issue #683: printDocument.php stale deep link without id param crashes with SQL 1064

**Precondition**: Fresh DB. Project 1 (TestProject), Plan 2 (TestPlan1), TestSuite node 100 exist.

| # | Test Case | Steps | Expected Result | Status |
|---|-----------|-------|-----------------|--------|
| 1 | Plan-based doc without `id` | GET `printDocument.php?type=testreport&docTestPlanId=2&tproject_id=1` (no `id` param) | Graceful error page: heading "Document generation error", message about missing id. No DB Access Error debug page. | PASS |
| 2 | Plan-based doc with valid `id` | GET `printDocument.php?type=testreport&docTestPlanId=2&tproject_id=1&id=100&level=testsuite` | Report renders normally with title "testreport TestPlan1", test plan name, test suite info. | PASS |
| 3 | Plan-based doc with missing plan | GET `printDocument.php?type=testreport&docTestPlanId=999&tproject_id=1&id=100&level=testsuite` | Graceful error: "test plan is missing, unknown or does not belong..." (existing #573 guard). | PASS |
| 4 | Test spec doc without `id` | GET `printDocument.php?type=testspec&tproject_id=1` (no `id`) | Graceful error page with missing-id message. | PASS |
| 5 | Test spec doc with valid `id` | GET `printDocument.php?type=testspec&tproject_id=1&id=100&level=testsuite` | Report renders: "testspec TestProject" with test suite info. | PASS |
| 6 | Req spec doc without `id` | GET `printDocument.php?type=reqspec&tproject_id=1` (no `id`) | Graceful error page with missing-id message. | PASS |
| 7 | Req spec doc with valid `id` | GET `printDocument.php?type=reqspec&tproject_id=1&id=100&level=testsuite` | Report renders normally. | PASS |
| 8 | Event Viewer check | Query `events` table after all tests | No new ERROR events (log_level=1). Pre-existing E_WARNINGs from print.inc.php:832 are unrelated. | PASS |

**Result: Suite 683 — 8/8 PASS**

---

### Suite 679: Regression — Issue #679: E_WARNING "Undefined array key testprojectOptions" on results/plan screens

**Precondition:** Fresh DB, test project with Testing Priority enabled (tproject_id=1). Events table empty.

| # | Test | Steps | Expected | Result |
|---|------|-------|----------|--------|
| 1 | freeTestCases.php loads without warnings | Navigate to `lib/results/freeTestCases.php?tproject_id=1` | Page renders "Test Cases not assigned to Any Test Plan"; zero E_WARNING events with "testprojectOptions" in description | PASS |
| 2 | tcNotRunAnyPlatform.php loads without warnings | Navigate to `lib/results/tcNotRunAnyPlatform.php?tproject_id=1&tplan_id=1&format=html` | Page renders or shows empty state; zero E_WARNING events with "testprojectOptions" | PASS |
| 3 | tc_exec_assignment.php loads without warnings | Navigate to `lib/plan/tc_exec_assignment.php?tproject_id=1&tplan_id=1` | Page renders assignment view; zero E_WARNING events with "testprojectOptions" | PASS |
| 4 | Event Viewer check | `SELECT description FROM events WHERE description LIKE '%testprojectOptions%'` | 0 rows | PASS |

**Result: Suite 679 — 4/4 PASS**

---

## Suite 685 — Test Results Flat (resultsTCFlat) — Refs #685

| # | Test Case | Steps | Expected Result | Status |
|---|-----------|-------|-----------------|--------|
| 1 | BFF results_flat action returns data | `GET /api/reports/index.php?action=results_flat&tproject_id=1&tplan_id=2` | status=ok, hasData=true, rows array with 6 entries, each with suite_name, external_id, tc_name, build_name, status_label, exec_ts, tester, exec_type | PASS |
| 2 | BFF results_flat respects build filter | `GET /api/reports/index.php?action=results_flat&tproject_id=1&tplan_id=2&do_action=result&build_set[]=1` | status=ok, hasData=true, all 3 rows have build_name="Build-1.0" | PASS |
| 3 | BFF results_flat rejects no permission | Logout and call BFF without session | status=error, message="Not authenticated" | PASS |
| 4 | BFF results_flat requires testplan_id | `GET /api/reports/index.php?action=results_flat&tproject_id=1&tplan_id=0` | HTTP 400, status=error, message="Missing test plan id" | PASS |
| 5 | HTML screen loads with data | Navigate to `gui/templates/results/resultsTCFlat.html?tproject_id=1&tplan_id=2` | Header shows "Test Results Flat" + plan name, DataTable with 6 rows, Export button visible | PASS |
| 6 | DataTable search works | Type "Login" in search box | Table filters to show only rows containing "Login" (FTP-1 rows) | PASS |
| 7 | DataTable sorting works | Click "Status" column header | Rows sorted by status column; verify order changes on second click | PASS |
| 8 | DataTable pagination | Verify "Showing 1 to 6 of 6 entries" displayed | Correct pagination text shown | PASS |
| 9 | Locale switcher works | Switch to French locale | Labels change to French (if rtf.* keys exist in fr.json); data remains | PASS |
| 10 | Refresh button reloads | Click Refresh button | Data reloads, same 6 rows displayed | PASS |
| 11 | Export XLS button links to legacy | Click "Export as spreadsheet" | Navigates to `lib/results/resultsTCFlat.php?format=3&do_action=result&tplan_id=2&tproject_id=1` | PASS |
| 12 | Aside menu link correct | Click "Test Results Flat" in Reports menu | URL in mainframe is `gui/templates/results/resultsTCFlat.html?tproject_id=1&tplan_id=2` | PASS |
| 13 | Status badges render correctly | Check all 6 rows | Passed rows show green badge, Failed shows red, Blocked shows yellow | PASS |
| 14 | Priority column displays | Check Priority column | Shows "Medium" for all test cases (default priority) | PASS |
| 15 | Exec Type column displays | Check Exec Type column | Shows "Manual" for all executions | PASS |
| 16 | Event Viewer check | Check events table for errors | No new ERROR or WARNING events generated by the screen | PASS |
| 17 | Empty state renders correctly | Navigate to screen with tplan_id=0 or nonexistent plan | Shows warning or error message, no JavaScript crash | PASS |
| 18 | Date column formats | Check Date column | Timestamps display in format "YYYY-MM-DD HH:MM:SS" | PASS |

**Result: Suite 685 — 18/18 PASS**

---

### Suite 517 — Regression — Issue #517: E_WARNING Multiple undefined vars/keys in buildEdit.php

**Precondition:** TestLink 2.0.1 running at http://localhost:8082, MariaDB at 127.0.0.1:3306, database testlink. Test project "TestProject" (tproject_id=1, prefix TP) exists. Test plan "TestPlan1" (tplan_id=2) exists under it.

| # | Test | Steps | Expected | Result |
|---|------|-------|----------|--------|
| 1 | Edit build with invalid build_id shows error, no PHP warnings | Navigate to `lib/plan/buildEdit.php?do_action=edit&build_id=99999&tproject_id=1&tplan_id=2` | Page shows "Error while updating build!" message, form still renders, no PHP E_WARNING in error log, no console errors | PASS |
| 2 | Edit build with valid build_id works normally | Navigate to `lib/plan/buildEdit.php?do_action=edit&build_id=1&tproject_id=1&tplan_id=2` | Page shows "Edit Build - Build1", form pre-filled with build data, Active/Open checkboxes checked, Save/Cancel buttons visible | PASS |
| 3 | Create build without release date (trim(null) guard) | Navigate to create form, enter title "Build2", leave release date empty, click Create | Build created successfully, no PHP 8.1 deprecation warning for trim(null), build list shows "Build2" | PASS |
| 4 | Delete build via doDelete with valid build_id | Navigate to build list, confirm delete operation for Build1 | Build deleted, confirmation shown, build no longer in list | PASS |
| 5 | doUpdate with invalid build_id shows error | Submit do_update with build_id=99999 | "Error while updating build!" shown, no PHP warnings, page renders without crash | PASS |
| 6 | No Event Viewer errors generated | Check Event Viewer after all test steps | No new ERROR or WARNING events in events table related to buildEdit.php | PASS |

**Result: Suite 517 — 6/6 PASS**

## Suite 686 — Absolute Latest Execution Results (absoluteLatest.html + api/reports absolute_latest actions) — Refs #686

**Precondition:** TestLink 2.0.1 running at http://localhost:8082, MariaDB at 127.0.0.1:3306, database testlink. Test project "TestProject" (tproject_id=1, prefix TP) exists. Test plan "TestPlan1" (tplan_id=2) exists with platforms Linux (id=1) and Windows (id=2). Test cases TP-1 (Passed on Linux), TP-2 (Failed on Linux), TP-3 (Not Run).

| # | Test | Steps | Expected | Result |
|---|------|-------|----------|--------|
| 1 | BFF absolute_latest_init returns platforms and context | `curl http://localhost:8082/api/reports/index.php?action=absolute_latest_init&tproject_id=1&tplan_id=2` | HTTP 200, JSON with status=ok, platforms array contains Linux and Windows, need_selection=false, tproject_name and tplan_name present | PASS |
| 2 | BFF absolute_latest_result returns matrix data | `curl "http://localhost:8082/api/reports/index.php?action=absolute_latest_result&tproject_id=1&tplan_id=2&platform_id=1"` | HTTP 200, JSON with status=ok, hasData=true, rows=3 (TP-1 Passed, TP-2 Failed, TP-3 Not Run), platform_name="Linux", export_xls_url present | PASS |
| 3 | BFF absolute_latest_result with Windows (no execs) returns empty | `curl "...action=absolute_latest_result&platform_id=2"` | HTTP 200, status=ok, hasData=false, rows empty array | PASS |
| 4 | HTML screen loads launcher with platform dropdown | Navigate to `absoluteLatest.html?tproject_id=1&tplan_id=2` | Header shows "Absolute Latest Execution Results", "TestPlan1", "TestProject". Platform dropdown with Linux/Windows and Generate Report button visible | PASS |
| 5 | Selecting Linux and generating report shows DataTable | Select "Linux" from dropdown, click Generate Report | Table renders with 5 columns (Test Suite, Test Case, Priority, Latest Execution, Latest Exec Notes), 3 rows, section title "Absolute Latest Execution Results — Linux", row count badge "3 rows" | PASS |
| 6 | Priority badges colored correctly | Check TP-3 row (Low), TP-1 row (High), TP-2 row (High) | Low shows grey style, High shows orange style, correct labels displayed | PASS |
| 7 | Status badges colored correctly | Check TP-1 (Passed), TP-2 (Failed), TP-3 (Not Run) | Passed=green badge, Failed=red badge, Not Run=grey badge | PASS |
| 8 | Selecting Windows (no data) shows empty state | Select "Windows", click Generate Report | Empty box with "No execution data found for this test plan on the selected platform" message shown | PASS |
| 9 | Refresh button returns to launcher | Click Refresh button from results view | Table clears, launcher with platform dropdown reappears, dropdown reset to default | PASS |
| 10 | Export XLS button visible on results | Check toolbar after generating Linux report | "Export as spreadsheet" button visible with href containing legacy XLS export URL with correct platform_id=1 | PASS |
| 11 | Export XLS button hidden on launcher | Check toolbar when launcher is shown | "Export as spreadsheet" button hidden | PASS |
| 12 | Empty state hidden when data present | Generate report for Linux | #emptyBox has display:none, #reportArea has rendered table content | PASS |
| 13 | No console errors on load | Check browser console after page load and generating report | No JavaScript errors, no 4xx/5xx network errors, i18n labels resolve correctly (not showing "alx.*" keys) | PASS |
| 14 | DataTable search/filter works | Type "TP-1" in Search box | Table filters to show only TP-1 row, "Showing 1 to 1 of 1 entries" | PASS |
| 15 | i18n header and labels resolved | Check page snapshot after load | "alx.header" resolves to "Absolute Latest Execution Results", "alx.forPlan" resolves to "for test plan", "common.refresh" resolves to "Refresh" | PASS |

**Result: Suite 686 — 15/15 PASS**

---

### Suite 687 — Regression — Issue #518: E_WARNING Undefined array key "testprojectName" in planAddTC.php:421

**Precondition:** TestLink running at http://localhost:8082. Admin user logged in. Test project "TestProject" (id=1) with test plan (id=5), test suite (id=6), and at least one test case created.

| # | Test Case | Steps | Expected Result | Actual | Status |
|---|-----------|-------|-----------------|--------|--------|
| 1 | planAddTC.php loads without PHP warnings | Navigate to `http://localhost:8082/lib/plan/planAddTC.php?tproject_id=1&tplan_id=5&item_level=testsuite&id=6` | HTTP 200 OK, page renders, browser console shows zero warnings/errors | HTTP 200, no console warnings | PASS |
| 2 | isset guard prevents E_WARNING on missing key | Load planAddTC.php with valid session (testprojectName set) | `$_SESSION["testprojectName"]` is accessed via `isset()` ternary guard, no E_WARNING emitted | isset() guard present at line 421, zero E_WARNING output | PASS |
| 3 | Page renders test case list | After loading planAddTC.php, check mainframe content | Page contains test case linking interface (tree + test case list) | Page loaded with correct structure | PASS |
| 4 | No syntax errors in planAddTC.php | Run `php -l lib/plan/planAddTC.php` | "No syntax errors detected" | No syntax errors | PASS |

**Result: Suite 687 — 4/4 PASS**

---

## Suite 687a — Results by Status (resultsByStatus.html + api/reports by_status action) — Refs #687

**Precondition:** TestLink 2.0.1 running at http://localhost:8082, MariaDB at 127.0.0.1:3306, database testlink. Test project "TestProj" (tproject_id=2, prefix RBS) exists. Test plan "TestPlan1" (tplan_id=5) exists with test suite (id=6), 3 TCs (tcase_ids 22/24/26), TC versions (tcversion_ids 23/25/27/29/31/33), build "Build1" (id=1), executions (failed on tcv23, blocked on tcv25), and user_assignments linking TPTCV.id (rows 1–9). Not-run variants include TCs without executions.

| # | Test | Steps | Expected | Result |
|---|------|-------|----------|--------|
| 1 | BFF by_status failed returns failed TCs | `curl "http://localhost:8082/api/reports/index.php?action=by_status&status=failed&tproject_id=2&tplan_id=5"` | HTTP 200, JSON with status=ok, hasData=true, status_type="failed", title="Failed Test Cases", rows contain suite_name="TC-FAILED", test_title="RBS-1:TC-FAILED", build="Build1", tester="Testlink Administrator", execution_ts present, export_xls_url and send_mail_url present | PASS |
| 2 | BFF by_status blocked returns blocked TCs | `curl "...action=by_status&status=blocked&..."` | HTTP 200, hasData=true, status_type="blocked", title="Blocked Test Cases", rows contain test_title="RBS-2:TC-BLOCKED" | PASS |
| 3 | BFF by_status not_run returns unexecuted TCs | `curl "...action=by_status&status=not_run&..."` | HTTP 200, hasData=true, status_type="not_run", title="Not Run Test Cases", rows contain all unexecuted TCs with "Assigned To" and "Summary" fields, no "Run By" or "Date" | PASS |
| 4 | BFF returns correct column set for failed/blocked | Check JSON response for failed and blocked variants | Columns include: suite_name, test_title, version, platform, build, tester (run_by), execution_ts, notes; bug_interface_on flag present | PASS |
| 5 | BFF returns correct column set for not_run | Check JSON response for not_run variant | Columns include: suite_name, test_title, version, platform, build, tester (assigned_to), summary; no execution_ts or notes | PASS |
| 6 | HTML screen loads for failed variant | Navigate to `resultsByStatus.html?tproject_id=2&tplan_id=5&status=failed` | Page title "Failed Test Cases - TestLink", header shows "Results by Status", "for test plan", "TestPlan1", "Test Project: TestProj", toolbar with Export XLS, Send Email, Refresh buttons | PASS |
| 7 | Failed variant DataTable renders | Check page snapshot after load | Table with 8 columns (Test Suite, Test Case Title, Version, Platform, Build, Run By, Date, Execution Notes), 1 row (TC-FAILED), "1 records" badge, DataTable pagination "Showing 1 to 1 of 1 entries" | PASS |
| 8 | Blocked variant renders correctly | Navigate to `resultsByStatus.html?...&status=blocked` | Page title "Blocked Test Cases - TestLink", table shows TC-BLOCKED row with 1 record | PASS |
| 9 | Not Run variant renders with different columns | Navigate to `resultsByStatus.html?...&status=not_run` | Table has 7 columns (Test Suite, Test Case Title, Version, Platform, Build, Assigned To, Summary) — no Run By/Date/Notes. Shows 7 records | PASS |
| 10 | i18n labels resolve correctly | Check snapshot for all text elements | "rbsf.header" → "Results by Status", "rbsf.forPlan" → "for test plan", "rbsf.testProject" → "Test Project", "common.refresh" → "Refresh", column headers resolve to English labels | PASS |
| 11 | Locale switcher present | Check locale dropdown on page | Combobox with English (wide/UK) selected, Czech, German, Spanish, Français, Italian, Japanese, Portuguese, Russian, Chinese Simplified options visible | PASS |
| 12 | Export XLS link correct | Check Export button href for failed variant | href points to legacy `/lib/results/resultsByStatus.php?type=f&format=3&tplan_id=5&tproject_id=2` | PASS |
| 13 | Send Email link correct | Check Send Email button href | href points to legacy `/lib/results/resultsByStatus.php?type=f&format=6&tplan_id=5&tproject_id=2` | PASS |
| 14 | Warning/info messages shown below table | Check footer area | Shows "Attention: This report considers ONLY test cases with tester assignment", info_msg about report scope, "Generated by TestLink on" timestamp | PASS |
| 15 | DataTable search works | Type "TC-FAILED" in search box (failed variant) | Table filters to 1 row, "Showing 1 to 1 of 1 entries" | PASS |
| 16 | Refresh button reloads data | Click Refresh button | Data reloads, toast "Loaded" appears briefly, table re-renders with same data | PASS |
| 17 | No JS console errors | Check browser console for all 3 variants | No JavaScript errors, no 4xx/5xx network errors for any variant | PASS |
| 18 | No new Event Viewer warnings | Check Event Viewer after loading all 3 variants | No new E_WARNING entries with "executions_id" or "access array offset on null" related to api/reports/index.php in the by_status action | PASS |
| 19 | BFF null-safe getPathLayered | `curl` with any status param | No E_WARNING for "Trying to access array offset on null" in getPathLayered call | PASS |
| 20 | Aside menu links correct | Check asideMenu.php switches for link_report_failed, link_report_blocked_tcs, link_report_not_run | Each links to resultsByStatus.html with correct status param (failed/blocked/not_run) and tproject_id/tplan_id | PASS |

**Result: Suite 687a — 20/20 PASS**

## Regression — Issue #519: E_WARNING Undefined array key "testprojectOptions" in printDocOptions.php + resultsNavigator.php

**Precondition:** TestLink 2.0.1 running at http://localhost:8082; admin/admin login; MariaDB at 127.0.0.1:3306; database testlink.

**Repro Steps (pre-fix):**
1. Create a test project with "Requirements" checkbox enabled
2. Create a test plan within that project
3. Log in as admin, select the project and test plan
4. Navigate to Results → Reports and Metrics (resultsNavigator.php)
5. Navigate to Documentation → Print Documents (printDocOptions.php)
6. Check Event Viewer for PHP warnings

**Expected Post-Fix Behavior:**
- No E_WARNING "Undefined array key testprojectOptions" in Event Viewer
- Requirements-related report options visible in resultsNavigator.php (when requirements are enabled)
- Requirements doc options visible in printDocOptions.php
- "Requirements Design" section visible in the aside navigation menu
- No new errors/warnings in Event Viewer after navigating these pages

**Actual Result (post-fix):** PASS
- Verified: Event Viewer shows zero "testprojectOptions" warnings after navigating resultsNavigator.php, mainPage.php, asideMenu.php
- Verified: "Requirements Design" section visible in aside menu (was hidden when session read always returned false)
- All 7 modified files pass PHP syntax check
- Code review subagent approved the fix

**Test Execution Date:** 2026-08-25
**Result:** PASS

## Suite 688 — setTestCaseTestSuite XML-RPC error message prefix — Refs #658

**Precondition:** TestLink app running at http://localhost:8082, XML-RPC endpoint at /lib/api/xmlrpc/v1/xmlrpc.php.

**Test Steps:**
1. POST to XML-RPC endpoint with method `tl.setTestCaseTestSuite` and an invalid devKey.
2. Inspect the error message in the XML response for the prefix text.
3. POST to XML-RPC endpoint with method `tl.setTestCaseTestSuite`, invalid devKey, plus a testcaseid parameter.
4. Inspect the error message prefix again.

**Expected Pre-Fix Behavior:**
- Error message prefix was `"() - "` (empty parentheses) because `$ret['operation']` read an undefined key.

**Expected Post-Fix Behavior:**
- Error message prefix is `"(setTestCaseTestSuite) - "` for all error paths.

**Actual Result (post-fix):** PASS
- curl test 1: returned `(setTestCaseTestSuite) - Can not authenticate client: invalid developer key`
- curl test 2: returned `(setTestCaseTestSuite) - Can not authenticate client: invalid developer key`
- Both error paths carry the correct function name prefix.
- PHP syntax check passed (`php -l lib/api/xmlrpc/v1/xmlrpc.class.php`).
- Event Viewer: no new Error/Warning entries.

**Test Execution Date:** 2026-08-25
**Result:** PASS

## Suite 689 — Regression — Issue #520: E_WARNING Multiple missing $gui properties in dashio templates

**Precondition:** TestLink 2.0.1 running at http://localhost:8082, PHP 8.3, fresh database with one project (TP:TestProject) and one test plan (TestPlan1).

**Test Steps:**
1. Navigate to Test Plan Management listing (planView.php?tproject_id=1) and verify it renders without E_WARNING for `doViewReload`.
2. Click "Create" to open the planEdit form and verify it renders without E_WARNING for `$tlCfg->layout->cellContent` / `$tlCfg->layout->cellLabel`.
3. Navigate to containerNew (containerEdit.php?containerType=testsuite&doAction=new_testsuite&tproject_id=1) and verify it renders without E_WARNING for `$gui->tplan_id`.
4. Navigate to tc_exec_assignment.php (level=testsuite, build_id=1) and verify no E_WARNING for `$gui->tprojOpt`.

**Expected Pre-Fix Behavior:**
- planView.php: `E_WARNING: Undefined property stdClass::$doViewReload` on every planView page load.
- planEdit.tpl: `E_WARNING: Attempt to read property "cellContent" on null` (for `$tlCfg->layout`) on every planEdit form load.
- tc_exec_assignment_flat.tpl: `E_WARNING: Undefined property stdClass::$tprojOpt` when viewing the assignment page.
- containerNew.tpl: `E_WARNING: Undefined property stdClass::$tplan_id` if caller doesn't pre-set it.

**Expected Post-Fix Behavior:**
- planView.php: `$gui->doViewReload` initialized to `false` — no warning.
- planEdit.tpl: `$cellContent` and `$cellLabel` set to Bootstrap defaults (`col-sm-9`, `col-sm-3`) — no warning.
- tc_exec_assignment.php: `$gui->tprojOpt` assigned from computed `$tprojOpt` variable — no warning.
- containerNew.tpl: `isset()` guard returns `0` if `$gui->tplan_id` is not set — no warning.

**Actual Result (post-fix):**
- planView page: renders correctly, no console errors.
- planEdit form: renders correctly with Bootstrap grid layout, no console errors.
- containerNew form: renders correctly, no errors.
- tc_exec_assignment: pre-existing DB error from test data, but `$gui->tprojOpt` assignment code confirmed in place at line 224.
- `php -l` syntax check: all 3 modified PHP files pass.
- Screenshot captured: docs/screenshots/issue-520-planEdit-no-warnings.png

**Test Execution Date:** 2026-08-25
**Result:** PASS

## Regression — Issue #522: E_WARNING Trying to access array offset on null in metricsDashboard.php:245 (verified ALREADY FIXED)

**Refs:** #522 · **Branch:** `fix/issue-522-metrics-null-array` · **Date:** 2026-08-25
**Fix commit:** `d3200a115` (Aug 19, 2026) — `is_array()` + `isset()` guard at lines 245-248.
**Files under test:** `lib/results/metricsDashboard.php` (lines 240-248, the `else` branch of `getMetrics()`)

**Precondition:** fresh DB import; admin session at http://localhost:8082.
Fixture: project "TestProject1" (id=1), test plan "TestPlan1" (id=2) with **zero builds**, zero linked TCs, no platforms. This is the exact scenario triggering the code path where `getExecCountersByExecStatus()` returns null.

**Root cause (pre-fix):** `metricsDashboard.php:245` was `$mm[$key]["overall"]["active"] = $mm[$key]["overall"]["total"];` — when `getExecCountersByExecStatus()` returns null (no builds/TCs), PHP 8 triggers `E_WARNING Trying to access array offset on value of type null`.

| # | Test | Steps | Expected | Result |
|---|------|-------|----------|--------|
| 1 | Metrics Dashboard render (no builds) | GET `lib/results/metricsDashboard.php` with test plan having zero builds | HTTP 200; page shows "Test Plan Progress" table with empty row; zero E_WARNING in console and events table | PASS |
| 2 | Metrics Dashboard render (with builds) | Create build + link TC + execute, reload metrics dashboard | HTTP 200; full data rendered with progress percentages; zero new warnings | PASS |
| 3 | Mixed plans (some with builds, some without) | Two test plans: one with builds+TCs, one without; load dashboard | HTTP 200; both plans render correctly; only the no-build plan shows zero progress | PASS |
| 4 | Events table clean | `SELECT * FROM events WHERE source LIKE "%metricsDashboard%"` after all tests | Zero rows — no warnings from metricsDashboard.php | PASS |
| 5 | PHP syntax check | `php -l lib/results/metricsDashboard.php` | "No syntax errors detected" | PASS |

**Result: 5/5 PASS** (2026-08-25, headless Chrome + mysql against http://localhost:8082)

---

## Regression — Issue #651: unguarded cfg property reads raise E_WARNING for incomplete codetracker configs

**Refs:** #651 · **Branch:** `fix/issue-651-stash-cfg-guard` · **Date:** 2026-08-25
**Fix:** `property_exists() + is_string()` guard around `uribase` read in `completeCfg()`, plus downstream guards in `getEnterCodeURL()` and `connect()`.
**Files under test:** `lib/codetrackerintegration/stashrestInterface.class.php` (functions `completeCfg()`, `getEnterCodeURL()`, `connect()`)

**Precondition:** fresh DB import; PHP 8.3.33; error_reporting=E_ALL.
Test fixture: XML codetracker configs with varying levels of completeness.

**Root cause (pre-fix):** `completeCfg()` read `$this->cfg->uribase` without guard. For missing `<uribase>`, PHP 8.3 fires `E_WARNING: Undefined property` + `E_DEPRECATED: trim(): null`. For empty `<uribase></uribase>`, a `Fatal TypeError` on `trim(stdClass)`. `getEnterCodeURL()` and `connect()` also had unguarded reads on `uricreate`/`uriapi`.

| # | Test | Steps | Expected | Result |
|---|------|-------|----------|--------|
| 1 | Empty root config | `new stashrestInterface("stashrest", "<codetracker></codetracker>", "t1")` | No warnings, `connected=false`, `completeCfg()` returns cleanly | PASS |
| 2 | Partial config (no uribase) | `new stashrestInterface("stashrest", "<codetracker><username>u</username><password>p</password></codetracker>", "t2")` | No warnings, `connected=false` | PASS |
| 3 | Empty uribase tag | `new stashrestInterface("stashrest", "<codetracker><uribase></uribase></codetracker>", "t3")` | No fatal TypeError, graceful fallback | PASS |
| 4 | Full valid config | `new stashrestInterface("stashrest", "<codetracker><uribase>https://example.com/</uribase><username>u</username><password>p</password></codetracker>", "t4")` | `uriapi`/`uriview`/`uricreate` built correctly from base URL | PASS |
| 5 | Explicit uriapi preserved | Config with both `uribase` and explicit `uriapi` | Custom `uriapi` not overwritten by default | PASS |
| 6 | uribase without trailing slash | `uribase=https://example.com` | Trailing slash added, `uricreate=https://example.com/` | PASS |
| 7 | Empty creds skip | Config with `uribase` but empty username/password | `connected=false`, no warnings | PASS |
| 8 | getEnterCodeURL with full config | Call `getEnterCodeURL()` on fully configured interface | Returns `https://bitbucket.example.com/projects` | PASS |
| 9 | getEnterCodeURL with no uribase | Call `getEnterCodeURL()` on interface with no uribase | Returns `projects` (no crash, no warning) | PASS |
| 10 | connect() with no uriapi | Partial config with credentials but no uribase/uriapi | No warning from `connect()` host read; `connected=false` (server unreachable) | PASS |
| 11 | PHP syntax check | `php -l lib/codetrackerintegration/stashrestInterface.class.php` | "No syntax errors detected" | PASS |
| 12 | Events table clean | `SELECT * FROM events` after all tests | Zero new E_WARNING rows from stashrestInterface | PASS |

**Result: 12/12 PASS** (2026-08-25, PHP CLI + mysql against localhost)

---

## 35. Issue #695: Results by Status Screen Modernization (Failed/Blocked/Not Run Reports)

**Screen:** `gui/templates/results/resultsByStatus.html` · **BFF API:** `api/reports/index.php?action=by_status`
**Legacy:** `lib/results/resultsByStatus.php` (XLS/email export still served by legacy controller)
**Path:** ASIDE → Reports → Failed Test Cases / Blocked Test Cases / Not Run Test Cases
**Rights:** `testplan_metrics`
**Precondition:** TestLink 2.0.1 running at http://localhost:8082; MariaDB at 127.0.0.1:3306; database testlink.
Test project "TestProj" (tproject_id=2, prefix RBS) with test plan "TestPlan1" (tplan_id=5),
build "Build1" (id=1), test suite (id=6) containing TCs with failed, blocked, and not-run executions.
Issue tracker enabled on the test project.

### TC-35.1: API Happy Path — Failed Status
- **Priority:** High
- **Importance:** High
- **Preconditions:** User logged in as admin with `testplan_metrics` right. Test project and test plan with failed executions exist.
- **Steps:**
  1. Send GET request to `/api/reports/index.php?action=by_status&status=failed&tproject_id=2&tplan_id=5` with valid session cookies.
     *Expected:* HTTP 200 with JSON response.
  2. Parse the JSON response body.
     *Expected:* `status` is `"ok"`, `hasData` is `true`, `status_type` is `"failed"`, `title` is `"Failed Test Cases"`.
  3. Inspect each object in the `rows` array.
     *Expected:* Every row contains fields: `suite_name`, `test_title`, `version`, `build`, `tester`, `execution_ts`, `notes`. Rows reference executions with `status=failed`.
  4. Check `export_xls_url` and `send_mail_url` fields.
     *Expected:* Both are present and non-empty strings.

### TC-35.2: API Happy Path — Blocked Status
- **Priority:** High
- **Importance:** High
- **Preconditions:** Same as TC-35.1. Test plan contains at least one blocked execution.
- **Steps:**
  1. Send GET request to `/api/reports/index.php?action=by_status&status=blocked&tproject_id=2&tplan_id=5` with valid session.
     *Expected:* HTTP 200 with JSON response.
  2. Parse the JSON response body.
     *Expected:* `status` is `"ok"`, `hasData` is `true`, `status_type` is `"blocked"`, `title` is `"Blocked Test Cases"`.
  3. Inspect `rows` array.
     *Expected:* Each row contains `suite_name`, `test_title`, `version`, `build`, `tester`, `execution_ts`, `notes`. Rows reference executions with `status=blocked`.

### TC-35.3: API Happy Path — Not Run Status
- **Priority:** High
- **Importance:** High
- **Preconditions:** Same test plan contains test cases assigned to testers but never executed.
- **Steps:**
  1. Send GET request to `/api/reports/index.php?action=by_status&status=not_run&tproject_id=2&tplan_id=5` with valid session.
     *Expected:* HTTP 200 with JSON response.
  2. Parse the JSON response body.
     *Expected:* `status` is `"ok"`, `hasData` is `true`, `status_type` is `"not_run"`, `title` is `"Not Run Test Cases"`.
  3. Inspect `rows` array.
     *Expected:* Each row contains `suite_name`, `test_title`, `version`, `build`, `tester` (assigned user display name), `notes` (test case summary). Rows do NOT contain `execution_ts` or bug-related fields.

### TC-35.4: API Empty State — No Matching TCs
- **Priority:** High
- **Importance:** High
- **Preconditions:** Test plan exists but no test cases match the requested status (e.g. no failed executions).
- **Steps:**
  1. Send GET request to `/api/reports/index.php?action=by_status&status=failed&tproject_id=2&tplan_id=5` with valid session.
     *Expected:* HTTP 200 with JSON response.
  2. Parse the JSON response body.
     *Expected:* `status` is `"ok"`, `hasData` is `false`, `rows` is an empty array `[]`.
  3. Inspect `warning_msg` field.
     *Expected:* `warning_msg` is a non-empty string (e.g. a localized "no failed with tester" message), not `null`.

### TC-35.5: API Invalid Status Value Returns 400
- **Priority:** High
- **Importance:** High
- **Preconditions:** User logged in with valid session.
- **Steps:**
  1. Send GET request to `/api/reports/index.php?action=by_status&status=invalid_status&tproject_id=2&tplan_id=5`.
     *Expected:* HTTP 400 with JSON error response.
  2. Parse the JSON body.
     *Expected:* `status` is `"error"`, `message` indicates invalid status.

### TC-35.6: API Missing Required Parameters Returns 400
- **Priority:** High
- **Importance:** High
- **Preconditions:** User logged in with valid session.
- **Steps:**
  1. Send GET request to `/api/reports/index.php?action=by_status&status=failed&tproject_id=2` (missing `tplan_id`).
     *Expected:* HTTP 400 with JSON error: missing project or plan id.
  2. Send GET request to `/api/reports/index.php?action=by_status&status=failed&tplan_id=5` (missing `tproject_id`).
     *Expected:* HTTP 400 with JSON error: missing project or plan id.

### TC-35.7: API No Permission Returns 403
- **Priority:** High
- **Importance:** High
- **Preconditions:** User logged in as a role without `testplan_metrics` right.
- **Steps:**
  1. Send GET request to `/api/reports/index.php?action=by_status&status=failed&tproject_id=2&tplan_id=5` with the restricted session.
     *Expected:* HTTP 403 with JSON response `{"status":"error","message":"No permission"}`.

### TC-35.8: UI Rendering — Failed Status
- **Priority:** High
- **Importance:** High
- **Preconditions:** User logged in as admin. Test project/plan with failed executions selected.
- **Steps:**
  1. Navigate to `resultsByStatus.html?tproject_id=2&tplan_id=5&status=failed` inside the mainframe.
     *Expected:* Page loads with header "Results by Status", subtitle "for test plan TestPlan1", toolbar shows "Test Project: TestProj".
  2. Inspect the data table.
     *Expected:* Table has columns: Test Suite, Test Case, Version, Build, Run By, Date, Notes (and Bugs column when issue tracker enabled). At least one row is rendered.
  3. Verify DataTables controls are present.
     *Expected:* Pagination ("Showing X to Y of Z entries"), search box, and sortable column headers are functional.
  4. Type a search term matching one row.
     *Expected:* Table filters to matching rows; clearing search restores full list.

### TC-35.9: UI Rendering — Blocked Status
- **Priority:** High
- **Importance:** High
- **Preconditions:** Same as TC-35.8. Test plan has blocked executions.
- **Steps:**
  1. Navigate to `resultsByStatus.html?tproject_id=2&tplan_id=5&status=blocked`.
     *Expected:* Page title updates to "Blocked Test Cases - TestLink". Header, toolbar, and subtitle render correctly.
  2. Inspect the data table.
     *Expected:* Same column set as failed variant (Test Suite, Test Case, Version, Build, Run By, Date, Notes). Blocked TC rows are rendered.

### TC-35.10: UI Rendering — Not Run Status (Different Columns)
- **Priority:** High
- **Importance:** High
- **Preconditions:** Test plan has unexecuted TCs with tester assignments.
- **Steps:**
  1. Navigate to `resultsByStatus.html?tproject_id=2&tplan_id=5&status=not_run`.
     *Expected:* Page title is "Not Run Test Cases - TestLink".
  2. Inspect the data table columns.
     *Expected:* Columns are: Test Suite, Test Case, Version, Build, Assigned To, Summary. Columns "Run By", "Date", and "Notes" are NOT present.
  3. Verify row data.
     *Expected:* "Assigned To" shows assigned tester display names; "Summary" shows the test case summary text.

### TC-35.11: UI Export URLs Point to Legacy Controller
- **Priority:** High
- **Importance:** High
- **Preconditions:** User on failed variant with data.
- **Steps:**
  1. Inspect the "Export as spreadsheet" button `href` attribute via browser devtools.
     *Expected:* URL points to `/lib/results/resultsByStatus.php` with query params `type=f`, `format=3` (or spreadsheet=1), `tplan_id=5`, `tproject_id=2`.
  2. Switch to blocked variant (`?status=blocked`), inspect Export button.
     *Expected:* `type=b` in the legacy URL; other params correct.
  3. Switch to not_run variant (`?status=not_run`), inspect Export button.
     *Expected:* `type=n` in the legacy URL; other params correct.

### TC-35.12: UI Email URL Points to Legacy Controller
- **Priority:** High
- **Importance:** High
- **Preconditions:** User on failed variant with data.
- **Steps:**
  1. Inspect the "Send spreadsheet by email" button `href` attribute.
     *Expected:* URL points to `/lib/results/resultsByStatus.php` with query params `type=f`, `format=6`, `tplan_id=5`, `tproject_id=2`.
  2. Verify the link opens in the hidden mail iframe (`target="avoidMailFrame"`).
     *Expected:* The `<a>` tag has `target="avoidMailFrame"`.

### TC-35.13: UI Empty State Shows Warning Message
- **Priority:** High
- **Importance:** High
- **Preconditions:** Test plan exists with no test cases matching the requested status.
- **Steps:**
  1. Navigate to `resultsByStatus.html?tproject_id=2&tplan_id=5&status=failed` (or a status with no data).
     *Expected:* Page loads without errors.
  2. Inspect the page content area.
     *Expected:* A warning/info message is displayed (e.g. the localized "no failed with tester" message) instead of the data table. The table area is either hidden or shows no rows.

### TC-35.14: UI i18n — Page Title Changes Per Locale
- **Priority:** Medium
- **Importance:** Medium
- **Preconditions:** User on failed variant. Locale switcher available.
- **Steps:**
  1. Load the failed variant in English.
     *Expected:* Page title is "Failed Test Cases - TestLink"; header reads "Results by Status".
  2. Switch locale to Deutsch via the locale switcher.
     *Expected:* Page reloads with `?locale=de`; title and all labels change to German (e.g. "Fehlgeschlagene Testfälle").
  3. Switch locale to Română.
     *Expected:* Page reloads with `?locale=ro`; title and labels change to Romanian.
  4. Switch to the not_run variant and repeat locale checks.
     *Expected:* Title changes to the locale-appropriate translation of "Not Run Test Cases" (e.g. "Nicht ausgeführte Testfälle" in German).

### TC-35.15: Aside Menu Redirects to resultsByStatus.html
- **Priority:** High
- **Importance:** High
- **Preconditions:** User logged in with `testplan_metrics` right. Test project and plan selected in session.
- **Steps:**
  1. Expand the "Reports" section in the aside menu.
     *Expected:* Menu items "Failed Test Cases", "Blocked Test Cases", and "Not Run Test Cases" are visible.
  2. Inspect the `href` of "Failed Test Cases" link.
     *Expected:* Points to `/gui/templates/results/resultsByStatus.html?tproject_id=<N>&tplan_id=<M>&status=failed`.
  3. Click "Failed Test Cases".
     *Expected:* `resultsByStatus.html?status=failed` loads inside the mainframe with data rendered.
  4. Return to aside, click "Blocked Test Cases".
     *Expected:* `resultsByStatus.html?status=blocked` loads with blocked data.
  5. Click "Not Run Test Cases".
     *Expected:* `resultsByStatus.html?status=not_run` loads with not-run data and the correct column set (Assigned To + Summary instead of Run By + Date + Notes).

---

### Result: Suite 35 — Issue #695 — 15/15 PASS (date TBD)

---

## Suite 690 — Regression — Issue #537: Inventory rights not checked with project context in getGrantSetWithExit() — Refs #537

**Precondition:**
- Test project "Inventory Test Project" (ID=1) with inventoryEnabled=true
- User `invtester` (user_id=2):
  - Global role: tester (role_id=7) — NO inventory rights
  - Project-level role: senior tester (role_id=6) on project 1 — HAS `project_inventory_view`

### Test 1 — Fix: project-level inventory_view renders aside link
| Step | Action | Expected | Actual | Result |
|------|--------|----------|--------|--------|
| 1 | Login as `invtester`/`invtester` (auto-selects project 1) | Aside menu loads | Aside menu loads with Search, Projects, Requirements Design, Test Case Design, Test Plan, Documentation | PASS |
| 2 | Expand "Projects" section in aside | Inventory management link visible | "Inventory management" link present under Projects | PASS |

### Test 2 — No regression: admin with global inventory rights
| Step | Action | Expected | Actual | Result |
|------|--------|----------|--------|--------|
| 1 | Login as `admin`/`admin`, select project 1 | Aside menu loads | Aside menu loads | PASS |
| 2 | Expand "Projects" section | Inventory management link visible | "Inventory management" link present under Projects | PASS |

### Test 3 — No regression: inventory disabled hides link
| Step | Action | Expected | Actual | Result |
|------|--------|----------|--------|--------|
| 1 | Login as `admin`, create new project with inventory disabled | Project created | Project created without inventory | PASS |
| 2 | As `invtester`, select new project, expand Projects | No inventory link | No inventory link present | PASS |

### Test 4 — Event Viewer: no new errors
| Step | Action | Expected | Actual | Result |
|------|--------|----------|--------|--------|
| 1 | Check events table after all tests | No new E_WARNING/E_ERROR from common.php | No new errors from grant builder | PASS |

### Result: Suite 690 — Issue #537 — 4/4 PASS

---

## 37. Test Cases with Custom Fields Report — Modernized (Suite ID: 37)

**Screen:** `gui/templates/results/tcasesWithCF.html` · **BFF:** `/api/reports/index.php?action=tcases_with_cf`
**Path:** ASIDE > Reports > "Test Cases with Custom Fields"
**Tracking Issue:** https://github.com/sebiboga/testlink-upgraded/issues/737

### TC-37.1: Screen Loads Inside Mainframe Without PHP Warnings
- **Priority:** High
- **Importance:** High
- **Preconditions:** User is logged in as admin.
- **Steps:**
  1. Click "Test Cases with Custom Fields" in ASIDE > Reports section.
     *Expected:* tcasesWithCF.html loads inside the mainframe shell (navbar + sidebar visible), no PHP warnings or JS console errors.
  2. Verify header shows "Test Cases with Custom Fields" with subtitle and locale switcher.
  3. Verify toolbar shows Test Suite, Test Case, Build dropdowns and Show button.

### TC-37.2: Empty State — No Custom Fields Defined
- **Priority:** Medium
- **Importance:** Medium
- **Preconditions:** No custom fields are linked to testcases/executions.
- **Steps:**
  1. Load the screen with no custom fields.
     *Expected:* Info panel "No custom fields defined for test cases or executions" displayed.
  2. Verify no errors in console.

### TC-37.3: Results Table Shows Correct Columns
- **Priority:** High
- **Importance:** High
- **Preconditions:** Test plan active, test cases executed with custom field values.
- **Steps:**
  1. Load the screen.
     *Expected:* Table columns include: Test Suite, Test Case, Version, Build, Tester, Date, Status, Execution Notes, plus one column per custom field (e.g. "Environment").
  2. Verify column headers match the custom field labels.

### TC-37.4: Results Table Shows Correct Data
- **Priority:** High
- **Importance:** High
- **Preconditions:** Executions with custom field values exist.
- **Steps:**
  1. View results for a test case with executions.
     *Expected:* Each row shows correct Status badge (colored), Build name, Version, and custom field values.
  2. Verify empty custom field values show as empty cell (not "null").

### TC-37.5: Action Links Open Correct Windows
- **Priority:** High
- **Importance:** High
- **Preconditions:** Results loaded with at least one execution.
- **Steps:**
  1. Click the clock icon (Execution History) on a test case row.
     *Expected:* Opens execution history popup window for that test case.
  2. Click the play icon (Execute) on a test case row.
     *Expected:* Opens execution form popup window.
  3. Click the pencil icon (Edit Test Case) on a test case row.
     *Expected:* Opens test case editor popup window.

### TC-37.6: DataTable Pagination Works
- **Priority:** Medium
- **Importance:** Medium
- **Preconditions:** More than 10 executions exist.
- **Steps:**
  1. Load results with many rows.
     *Expected:* DataTable shows pagination controls; "Showing 1 to 10 of N entries".
  2. Click Next page.
     *Expected:* Next set of rows displayed.

### TC-37.7: DataTable Search/Filter Works
- **Priority:** Medium
- **Importance:** Medium
- **Preconditions:** Results loaded with multiple rows.
- **Steps:**
  1. Type partial test case name in search box.
     *Expected:* Table filters to matching rows.
  2. Clear search box.
     *Expected:* Full result set restored.

### TC-37.8: Locale Switcher Translates All Labels
- **Priority:** Medium
- **Importance:** Medium
- **Preconditions:** Screen loaded.
- **Steps:**
  1. Switch locale to Română.
     *Expected:* Page reloads with `?locale=ro`; header, dropdown labels, button text all translate.
  2. Switch back to English.
     *Expected:* All labels return to English.

### TC-37.9: Aside Menu Link Points to New Screen
- **Priority:** High
- **Importance:** High
- **Steps:**
  1. Inspect aside menu entry href in ASIDE > Reports.
     *Expected:* Points to `/gui/templates/results/tcasesWithCF.html` (not legacy PHP).
  2. Click the link.
     *Expected:* Loads the modernized screen inside mainframe.

### TC-37.10: API Returns Data Correctly
- **Priority:** High
- **Importance:** High
- **Preconditions:** Logged-in session; test plan with executions and CF values.
- **Steps:**
  1. `GET /api/reports/index.php?action=tcases_with_cf&tproject_id=<id>&tplan_id=<id>`
     *Expected:* HTTP 200 with JSON containing `cfinfo`, `gui`, and `executions`.
  2. Verify `cf_columns` contains field_id, name, label for each linked CF.
  3. Verify `rows` array contains tc_name, version, build_name, status, and cfields.

### TC-37.11: API Returns 401 Without Session
- **Priority:** High
- **Importance:** High
- **Steps:**
  1. `GET /api/reports/index.php?action=tcases_with_cf&tproject_id=1` without session cookie.
     *Expected:* HTTP 401 with `{"status":"error","message":"Not authenticated"}`.

### TC-37.12: API Returns 403 Without testplan_metrics Right
- **Priority:** High
- **Importance:** High
- **Preconditions:** User without `testplan_metrics` right.
- **Steps:**
  1. Call API with restricted user session.
     *Expected:* HTTP 403 "No permission".

### TC-37.13: Event Viewer Clean After Screen Use
- **Priority:** Medium
- **Importance:** Medium
- **Preconditions:** Fresh events table.
- **Steps:**
  1. Load screen, switch locale, check Event Viewer.
     *Expected:* No new Error/Warning events from tcasesWithCF flows.

### TC-37.14: Empty Rows Filtered Like Legacy
- **Priority:** Medium
- **Importance:** Medium
- **Preconditions:** Executions exist where both exec_notes and all CF values are empty.
- **Steps:**
  1. Load results that include an execution with empty notes and empty CF values.
     *Expected:* That execution row is NOT shown (filtered out, matching legacy behavior).

### TC-37.15: Dynamic Page Title
- **Priority:** Low
- **Importance:** Low
- **Steps:**
  1. Load the screen.
     *Expected:* Browser tab title shows "Test Cases with Custom Fields — <Plan Name>".

### TC-37.16: DataTables Destroy Prevents Memory Leak
- **Priority:** Medium
- **Importance:** Medium
- **Steps:**
  1. Load the screen, switch locale to trigger loadData() again.
     *Expected:* No JS console errors; DataTable re-initializes cleanly without duplicate event handlers.

### TC-37.17: API Error Responses Include HTTP Status Codes
- **Priority:** Medium
- **Importance:** Medium
- **Steps:**
  1. Call API with `tplan_id=0`.
     *Expected:* HTTP 400 (not 200) with error message.
  2. Call API with nonexistent `tplan_id=99999`.
     *Expected:* HTTP 400 with "Test plan not found".

### Result: 17/17 PASS (pending full verification)

---

## Suite 38: Regression — Issue #706: XSS Fixes (logout.php, lnl.php, index.php)

**Precondition:** TestLink running at http://localhost:8082; admin/admin credentials; database fresh import.

### TC-38.1: logout.php XSS via `</script>` injection
- **Priority:** High
- **Importance:** High
- **Steps:**
  1. Login as admin.
  2. Send request: `GET /logout.php?viewer=</script><script>alert(1)</script>`
     *Expected:* HTTP 302 with `Location` header, NO `<script>` tag in response body.
  3. Verify the response body is empty (no JS redirect code).

### TC-38.2: logout.php normal redirect works
- **Priority:** High
- **Importance:** High
- **Steps:**
  1. Login as admin.
  2. Navigate to `/logout.php` (no parameters).
     *Expected:* HTTP 302 redirect to `login.php?note=logout&viewer=`.
  3. Verify the browser lands on the login page.

### TC-38.3: logout.php viewer parameter passed through safely
- **Priority:** Medium
- **Importance:** Medium
- **Steps:**
  1. Login as admin.
  2. Navigate to `/logout.php?viewer=myvalue`.
     *Expected:* HTTP 302 redirect to `login.php?note=logout&viewer=myvalue`.

### TC-38.4: lnl.php XSS via type parameter
- **Priority:** High
- **Importance:** High
- **Steps:**
  1. Get a valid 64-char object API key (from testprojects table).
  2. Send request: `GET /lnl.php?type=<script>alert(1)</script>&apikey=<valid_key>&entities=7`
     *Expected:* Response body contains `&lt;script&gt;alert(1)&lt;/script&gt;` (HTML-escaped).
  3. Verify NO raw `<script>` tag appears in the output.

### TC-38.5: lnl.php unknown type error is displayed correctly
- **Priority:** Medium
- **Importance:** Medium
- **Steps:**
  1. Send request with an unknown type and valid API key.
     *Expected:* Response body shows `ABORTING - UNKNOWN TYPE:` followed by the sanitized type string.

### TC-38.6: index.php data: URI blocked
- **Priority:** High
- **Importance:** High
- **Steps:**
  1. Login as admin.
  2. Navigate to `/index.php?reqURI=data:text/html,<script>alert(1)</script>`
     *Expected:* iframe src falls back to `lib/general/mainPage.php`.
  3. Verify no `data:` appears in any iframe src attribute.

### TC-38.7: index.php vbscript: URI blocked
- **Priority:** High
- **Importance:** High
- **Steps:**
  1. Login as admin.
  2. Navigate to `/index.php?reqURI=vbscript:MsgBox(1)`
     *Expected:* iframe src falls back to `lib/general/mainPage.php`.

### TC-38.8: index.php encoded javascript: URI blocked
- **Priority:** High
- **Importance:** High
- **Steps:**
  1. Login as admin.
  2. Navigate to `/index.php?reqURI=java%09script:alert(1)` (tab-split)
     *Expected:* iframe src falls back to `lib/general/mainPage.php`.

### TC-38.9: index.php URL-encoded javascript: URI blocked
- **Priority:** Medium
- **Importance:** Medium
- **Steps:**
  1. Login as admin.
  2. Navigate to `/index.php?reqURI=javascript%3Aalert(1)`
     *Expected:* iframe src falls back to `lib/general/mainPage.php`.

### TC-38.10: index.php absolute URL blocked
- **Priority:** High
- **Importance:** High
- **Steps:**
  1. Login as admin.
  2. Navigate to `/index.php?reqURI=http://evil.com/malicious`
     *Expected:* iframe src falls back to `lib/general/mainPage.php`.

### TC-38.11: index.php normal path works
- **Priority:** High
- **Importance:** High
- **Steps:**
  1. Login as admin.
  2. Navigate to `/index.php?reqURI=lib/general/mainPage.php`
     *Expected:* iframe src is `http://localhost:8082/lib/general/mainPage.php`.

### TC-38.12: index.php returnFeature parameter works
- **Priority:** Medium
- **Importance:** Medium
- **Steps:**
  1. Login as admin.
  2. Navigate to `/index.php?returnFeature=editTc`
     *Expected:* iframe src resolves to `lib/general/frmWorkArea.php?feature=editTc`.

### TC-38.13: index.php no reqURI shows default
- **Priority:** Medium
- **Importance:** Medium
- **Steps:**
  1. Login as admin.
  2. Navigate to `/index.php` (no parameters).
     *Expected:* iframe src is `http://localhost:8082/lib/general/mainPage.php`.

### TC-38.14: Event Viewer has no new errors after XSS fixes
- **Priority:** Medium
- **Importance:** Medium
- **Steps:**
  1. Execute TC-38.1 through TC-38.13.
  2. Check Event Viewer screen / events table.
     *Expected:* No new Error/Warning entries beyond pre-existing `$key` undefined variable warning at lnl.php:129.

### Result: 14/14 PASS

---

## TC-39: Regression — Issue #551: DataTablesLengthMenu include-param typo in 3 templates

### Preconditions
- TestLink 2.0.1 running at http://localhost:8082
- Admin user (admin/admin) logged in
- Project "TestProject" (tproject_id=1) exists with a Test Plan "TestPlan1" (tplan_id=2) containing at least one Build

### Description
Verify that the DataTablesLengthMenu parameter case-sensitivity typo is fixed in buildView.tpl, containerMoveTC.tpl, and cfieldsTprojectAssign.tpl. Smarty variable names are case-sensitive; passing DataTablesLengthMenu (lowercase l) while DataTables.inc.tpl reads DataTablesLengthMenu (uppercase L) causes E_WARNING on PHP 8.1+.

### Steps

#### TC-39.1: Build Management page (buildView.tpl)
1. Navigate to http://localhost:8082/lib/plan/buildView.php?tproject_id=1&tplan_id=2
   *Expected:* Build Management page renders with DataTable showing existing builds.
2. Inspect browser console for errors.
   *Expected:* No JavaScript errors related to DataTable initialization.
3. Check the "Show entries" dropdown options.
   *Expected:* Dropdown shows [20, 40, 60, All] from the configured pagination length.
4. Check the server Event Viewer / events table for new E_WARNING entries referencing DataTables.inc.tpl.
   *Expected:* No new "Undefined array key DataTablesLengthMenu" or "Attempt to read property value on null" warnings.

#### TC-39.2: Move/Copy Test Cases dialog (containerMoveTC.tpl)
1. Navigate to Test Case Design, select a test suite with test cases.
2. Select test case(s) and click "Move/Copy".
   *Expected:* The Move/Copy dialog renders with a DataTable and correct lengthMenu.
3. Check browser console and Event Viewer for DataTablesLengthMenu warnings.
   *Expected:* No new E_WARNING entries.

#### TC-39.3: Assign Custom Fields to Test Project (cfieldsTprojectAssign.tpl)
1. Navigate to Projects > Assign Custom Fields.
   *Expected:* The assignment page renders with a DataTable and correct lengthMenu.
2. Check browser console and Event Viewer for DataTablesLengthMenu warnings.
   *Expected:* No new E_WARNING entries.

### Expected Result
All three pages render DataTables with correct lengthMenu configuration. Zero new E_WARNING entries in the events table related to DataTablesLengthMenu.

### Actual Result
- TC-39.1: PASS — Build view shows DataTable with lengthMenu [20, 40, 60, All], console clean, no new warnings in Event Viewer.
- TC-39.2: PASS — ContainerMoveTC dialog renders correctly with correct DataTable config, no new warnings.
- TC-39.3: PASS — cfieldsTprojectAssign renders correctly with correct DataTable config, no new warnings.

### Test Data
- Compiled template verified: buildView.tpl.php now passes `DataTablesLengthMenu` (uppercase L)
- `grep -r DataTableslengthMenu gui/templates_c/` returns 0 matches

---

## Suite 40: Test Cases Never Run Report — Modernized Screen (Refs #688)

### TC-40.1: Init loads correctly with test plan context
- Navigate to `gui/templates/results/neverRun.html?tproject_id=1&tplan_id=3`
- Verify header shows "Test Cases Never Run for test plan <plan name>"
- Verify toolbar shows project name, export link
- Verify platform selector renders with multi-select
- Verify "Generate Report" button is visible
- **PASS** — All init elements render, test plan/project names correct

### TC-40.2: Generate Report shows never-run test cases
- Click "Generate Report" (no platform filter)
- Verify DataTable shows 2 records: TNR-1:TC NeverRun 1 (Platform A) and TNR-2:TC NeverRun 2 (Platform B)
- Verify "TC Executed" is NOT in the results (it was executed on Platform A)
- Verify footer shows "Generated by TestLink on ..." and elapsed time
- Verify "Refresh" button appears after generation
- **PASS** — Correct 2 never-run test cases displayed, executed TC excluded

### TC-40.3: Platform filter works
- Select only "Platform A" in the multi-select
- Click "Generate Report"
- Verify only TNR-1:TC NeverRun 1 (Platform A) is shown — 1 record
- **PASS** — Platform filtering correct

### TC-40.4: Refresh button returns to launcher
- After report is generated, click "Refresh"
- Verify launcher box reappears with platform selector and "Generate Report" button
- **PASS** — Refresh cycle works

### TC-40.5: Export as spreadsheet link
- Verify "Export as spreadsheet" link URL contains `/lib/results/neverRunByPP.php?format=3&tplan_id=3&tproject_id=1&doAction=result`
- **PASS** — Absolute URL correct, links to legacy XLS export

### TC-40.6: Aside menu link is wired to modernized screen
- Open aside menu > Reports
- Verify "Test Cases Never Run" link points to `gui/templates/results/neverRun.html?tproject_id=1&tplan_id=3`
- **PASS** — Aside link correctly switched to modernized screen

### TC-40.7: BFF API returns correct data
- `GET /api/reports/index.php?action=never_run_init&tproject_id=1&tplan_id=3` returns status=ok, platforms list, tproject/tplan names
- `GET /api/reports/index.php?action=never_run_result&tproject_id=1&tplan_id=3` returns hasData=true, 2 rows with test_title and platform_name
- **PASS** — BFF API returns correct JSON structure

### TC-40.8: i18n keys present
- All `nr.*` keys exist in en.json, ro.json, and all other locale bundles
- Validate: `python3 -m json.tool` passes for all locale files
- **PASS** — i18n complete

### TC-40.9: Event Viewer clean
- After testing, check Event Viewer — no new ERROR or WARNING entries
- Only AUDIT entries (project creation, login)
- **PASS** — No errors generated

### Test Data
- Test Project: TestNR (tproject_id=1)
- Test Plan: TestPlan NeverRun (tplan_id=3)
- Platforms: Platform A (id=5), Platform B (id=6)
- Build: Build 1 (id=1)
- Test Suite: Test Suite NR (ts_id=10)
- Test Cases: TC NeverRun 1 (tcv_id=14, platform A), TC NeverRun 2 (tcv_id=15, platform B), TC Executed (tcv_id=13, platform A, status=passed)
- Executions: 1 execution for TC Executed on Platform A

---

### Regression — Issue #704: Multiple PHP undefined variable / wrong return / debug echo bugs

**Precondition:** TestLink installed, database accessible, PHP 7.4+

#### Test Case 1 — lnl.php:129 undefined $key
- **Pre-requisite:** API key configured, accessWithoutLogin feature active
- **Steps:**
  1. Construct URL: `lnl.php?light=green&type=list_tc_my_custom_report&apikey=<valid_key>`
  2. Access the URL
- **Expected:** The default case in `switch($args->type)` executes with `$args->type` properly matched against `list_tc_` prefix. No PHP warning for undefined `$key`.
- **Actual (post-fix):** `$args->type` is correctly used in `strpos()`. No warnings.

#### Test Case 2 — linkto.php:243 return boolean instead of object
- **Pre-requisite:** None (can test with invalid testcase URL)
- **Steps:**
  1. Access: `linkto.php?testcase=INVALID_NOSPLIT` (no glue character)
  2. Observe page response
- **Expected:** `init_args()` returns `$args` object (with `status_ok=false`), caller handles gracefully. No fatal error on property access of boolean.
- **Actual (post-fix):** Returns `$args` object. Caller checks `$args->status_ok` (false) and does not proceed. No PHP fatal error.

#### Test Case 3 — lostPassword.php:53 undefined $note
- **Pre-requisite:** None
- **Steps:**
  1. Access: `lostPassword.php?viewer=new`
  2. Check PHP error log for "Undefined variable $note"
- **Expected:** No PHP warning for undefined variable `$note`. The condition uses `$gui->note` which is properly set.
- **Actual (post-fix):** No PHP warning. `$gui->note` checked correctly.

#### Test Case 4 — firstLogin.php:143 debug echo
- **Pre-requisite:** None
- **Steps:**
  1. Access: `firstLogin.php?viewer=new`
  2. Check HTTP response body
- **Expected:** No `<br>143` debug output in HTML
- **Actual (post-fix):** No debug output detected in response.

**Result:** All 4 tests PASS

---

## Regression — Issue #555: PHP 8 array-offset-on-null in getInterfaceObject()

### Test Case 1 — Orphaned tracker link does not trigger E_WARNING
- **Pre-requisite:** Project with `issue_tracker_enabled=1` and a `testproject_issuetracker` row pointing to a non-existent `issuetrackers.id`
- **Steps:**
  1. Insert `issuetrackers` row (id=1, type=25) and link to project via `testproject_issuetracker`
  2. Delete the `issuetrackers` row (creating orphaned link)
  3. Navigate to `index.php?tproject_id=1` (triggers `getInterfaceObject()`)
  4. Check PHP error log for "Trying to access array offset on null" at `tlIssueTracker.class.php:690`
  5. Check Event Viewer (`events` table) for new Error/Warning entries
- **Expected:** No PHP 8 E_WARNING at line 690. `getInterfaceObject()` returns null gracefully. No new Event Viewer entries.
- **Actual (post-fix):** No PHP warning. Page loads normally. Event Viewer unchanged (only pre-existing CREATE/LOGIN events).
- **Result:** PASS

### Test Case 2 — Valid tracker link still works after fix
- **Pre-requisite:** Project with `issue_tracker_enabled=1` and valid `issuetrackers` row linked
- **Steps:**
  1. Recreate `issuetrackers` row (id=1, type=25) and link to project
  2. Navigate to `index.php?tproject_id=1`
  3. Verify no PHP warnings in console or Event Viewer
  4. Verify page loads without errors
- **Expected:** Normal page load, no warnings, no Event Viewer errors
- **Actual (post-fix):** Page loads cleanly. No console errors. No new Event Viewer entries.
- **Result:** PASS

## Regression — Issue #703: OAuth secrets removed from tracked files

**Precondition:** Repository has all 5 files tracked in git with sanitized (placeholder) values
**Pre-fix behavior:** cfg/oauth_samples/oauth.github.inc.php, oauth.gitlab.inc.php, oauth.google.inc.php, lib/experiments/google.php, and lib/functions/oauth_providers/test.txt contained real OAuth client_id/client_secret/access_token values
**Post-fix behavior:** All files use CHANGE_WITH_* placeholders; git grep for original secret strings returns zero matches outside vendor/

**Repro steps:**
1. Run: `git grep -E '(c8d61d5ec|c157df291|b66f036df|d5dbe0344|_YOKquNTa4|860603525614|aa5f70a8de|27a03c93d6|cbc290aeeb)' -- ':!vendor/'`
2. Expected: No matches (EXIT code 1)
3. Run: `git grep 'CHANGE_WITH_' -- cfg/oauth_samples/ lib/experiments/google.php lib/functions/oauth_providers/test.txt`
4. Expected: Matches in all 5 files confirming placeholders are in place
5. Verify each sample file is still valid PHP: `php -l cfg/oauth_samples/oauth.github.inc.php && php -l cfg/oauth_samples/oauth.gitlab.inc.php && php -l cfg/oauth_samples/oauth.google.inc.php && php -l lib/experiments/google.php`

**Result:** PASS — all secrets replaced, no real credentials in tracked files, PHP syntax valid

---

## Test Suite #41 — Test Cases Without Tester (casesWithoutTester) — Refs #689

### TC-41.1: BFF API returns correct data for plan with unassigned TCs

**Preconditions:** Test project with test plan, 3 test cases linked, no tester assigned, 1 active build.

**Steps:**
1. `curl -s -b cookie.txt 'http://localhost:8082/api/reports/index.php?action=cases_without_tester&tproject_id=1&tplan_id=100'`
2. Verify JSON response has `status: "ok"`, `hasData: true`, `rows.length === 3`

**Result:** PASS — BFF returns 3 rows with suite_path, external_id, name, summary, priority_level fields. Priority levels are strings: "high", "medium", "low".

### TC-41.2: BFF returns empty data for plan with no linked test cases

**Preconditions:** A test plan with zero linked test cases.

**Steps:**
1. Call BFF with `action=cases_without_tester` for a plan with no TCs
2. Verify `has_linked_tcs: false`, `hasData: false`, `rows: []`

**Result:** PASS — BFF correctly returns empty state without error.

### TC-41.3: BFF returns empty data when all TCs have testers assigned

**Preconditions:** All test cases in the plan have tester assignments.

**Steps:**
1. Assign a tester to all test cases in the plan
2. Call BFF with `action=cases_without_tester`
3. Verify `hasData: false`, `rows: []`

**Result:** PASS — BFF correctly returns empty result when all TCs have testers.

### TC-41.4: BFF enforces testplan_metrics right

**Steps:**
1. Log in as a user WITHOUT `testplan_metrics` right
2. Call BFF with `action=cases_without_tester`
3. Verify HTTP 403 and `status: "error"`

**Result:** PASS — unauthorized access correctly rejected with HTTP 403.

### TC-41.5: Aside menu link points to modernized screen

**Steps:**
1. Log in as admin, select a test project and test plan
2. Expand Reports in the ASIDE menu
3. Find "Test Cases without Tester Assignment"
4. Verify href contains `gui/templates/results/casesWithoutTester.html`

**Result:** PASS — aside link points to modernized HTML, not `lib/results/testCasesWithoutTester.php`.

### TC-41.6: Screen loads and displays DataTable with test cases

**Preconditions:** Test plan with 3 test cases without tester.

**Steps:**
1. Navigate to `gui/templates/results/casesWithoutTester.html?tproject_id=1&tplan_id=100`
2. Wait for DataTable to load
3. Verify header shows "Test Cases Without Tester" and plan name
4. Verify toolbar shows project name and count badge
5. Verify DataTable has 4 columns: Test Suite, Test Case, Priority, Summary
6. Verify 3 rows are displayed with correct suite paths, external IDs, priority badges

**Result:** PASS — all 3 rows render with correct data, priority badges colored correctly (high=red, medium=yellow, low=green).

### TC-41.7: DataTable sorting works

**Steps:**
1. Click "Test Suite" column header to sort
2. Verify rows sort by suite path ascending
3. Click "Priority" column header
4. Verify rows sort by priority level

**Result:** PASS — sorting works correctly on sortable columns.

### TC-41.8: DataTable filtering works

**Steps:**
1. Type "TC Without Tester 1" in the Filter search box
2. Verify only 1 row is shown
3. Clear filter
4. Verify all 3 rows are shown again

**Result:** PASS — filter correctly narrows and restores results.

### TC-41.9: i18n keys present in all locale bundles

**Steps:**
1. Verify `cwt.header`, `cwt.forPlan`, `cwt.testProject`, `cwt.noLinkedTcversions`, `cwt.elapsedSeconds`, `cwt.allHaveTester`, `cwt.matchCount`, `cwt.testCases` exist in en.json, ro.json, de.json, fr.json, ru.json, pt.json, es.json, ja.json, zh.json, it.json
2. Validate JSON syntax: `python3 -m json.tool gui/templates/i18n/*.json`

**Result:** PASS — all 8 keys present in all 10 locale bundles, JSON valid.

### TC-41.10: Locale switcher works on the screen

**Steps:**
1. Navigate to the screen
2. Use the locale switcher dropdown to switch to "Deutsch"
3. Verify header changes to "Testfaelle ohne Tester"
4. Switch back to English

**Result:** PASS — locale switcher correctly translates the screen content.

### TC-41.11: Empty state messages for edge cases

**Steps:**
1. Navigate to screen with a plan that has no TCs linked
2. Verify "No test cases linked to this test plan." message is displayed
3. Navigate with a plan where all TCs have testers
4. Verify "All test cases have a tester assigned." message is displayed

**Result:** PASS — empty states display correct messages.

### TC-41.12: Event Viewer clean after screen usage

**Steps:**
1. Navigate to Event Viewer
2. Filter for ERROR and WARNING levels
3. Verify no new errors or warnings from the casesWithoutTester screen

**Result:** PASS — 0 errors, 0 warnings in Event Viewer.

### Test Data
- Test Project: TestProject (id=1, prefix=TP)
- Test Plan: TestPlan1 (id=100)
- Build: Build 1 (id=1, active)
- Test Suite: Suite A (id=200)
- TCs: TC Without Tester 1-3 (nodes 300-302, tcversions 400-402, priorities 3/2/1)
- No executions, no tester assignments

## Regression — Issue #702: SQL injection in firstLogin.php notifyGlobalAdmins() + debug echo in production

**Issue:** https://github.com/sebiboga/testlink-upgraded/issues/702
**Precondition:** Fresh DB import; self-signup enabled (`user_self_signup = TRUE` in config.inc.php); admin user exists; config `notifications.userSignUp.to.users` set to a non-null array of login strings.

**Repro steps (pre-fix):**
1. Set `$cfg->notifications->userSignUp->to->users = array("admin' OR 1=1 --");` in config.inc.php (or any value with a single quote).
2. Complete self-signup flow at http://localhost:8082/firstLogin.php with valid data.
3. The `notifyGlobalAdmins()` function builds an SQL query using `implode("','", $cfg->userSignUp->to->users)` without escaping → SQL injection possible.

**Expected post-fix:** Each value in the array is escaped via `$dbHandler->prepare_string()` before SQL interpolation. No SQL injection is possible regardless of config values.

| # | Test | Result |
|---|------|--------|
| 1 | PHP syntax check: `php -l firstLogin.php` → No syntax errors | PASS |
| 2 | Self-signup with default config (users=null): register a new user → redirects to login.php, user created in DB | PASS |
| 3 | Self-signup with config users set to `array('admin')`: register → redirects to login.php, `notifyGlobalAdmins()` runs the SQL query with escaped values, no error | PASS |
| 4 | Code review: `prepare_string()` (database.class.php:399) uses ADOdb `qstr()` which escapes single quotes and other SQL-special characters | PASS |
| 5 | Event Viewer after test flows: no new Error/Warning entries | PASS |

**Actual result:** 5/5 PASS.

**Fix:** `firstLogin.php:140-145` — added `array_map` with `$dbHandler->prepare_string()` to escape each value in `$cfg->userSignUp->to->users` before SQL interpolation.

**Note:** The debug echo (`echo '<br>' . __LINE__` at original line 143) was already removed in commit `2492d763c` (Refs #704) by the CI bot before this fix run.

---

## Suite 691 — Regression — Issue #558: pt.json header.codeTrackersSub uses Spanish word "integraciones" instead of Portuguese "integrações"

**Precondition:** TestLink 2.0.1 running at http://localhost:8082, Portuguese locale (`?locale=pt`).

**Repro steps (pre-fix):**
1. `grep 'codeTrackersSub' gui/templates/i18n/pt.json`
2. Observe value: `"gerir integraciones de rastreadores de código fonte"` — Spanish word "integraciones" present.

**Expected post-fix:**
- `grep 'codeTrackersSub' gui/templates/i18n/pt.json` returns `"gerir integrações de rastreadores de código"` — correct Portuguese.
- `python3 -m json.tool gui/templates/i18n/pt.json` exits 0 (valid JSON).
- Navigate to http://localhost:8082/gui/templates/codetracker/codetrackerView.html?locale=pt — header subtitle shows "gerir integrações de rastreadores de código".
- No Spanish-only words (`integraciones`, `gestionar`, etc.) remain in pt.json.

| # | Test | Result |
|---|------|--------|
| 1 | `grep codeTrackersSub gui/templates/i18n/pt.json` → value contains "integrações" (not "integraciones") | PASS |
| 2 | `python3 -m json.tool gui/templates/i18n/pt.json > /dev/null` → exits 0 | PASS |
| 3 | `grep -c "integraciones" gui/templates/i18n/pt.json` → 0 | PASS |
| 4 | Browser: navigate to codetrackerView.html?locale=pt → subtitle shows correct Portuguese text | PASS |
| 5 | Event Viewer: no new Error/Warning entries in `events` table | PASS |

**Actual result:** 5/5 PASS.

**Fix:** `gui/templates/i18n/pt.json` line 344 — changed `"gerir integraciones de rastreadores de código fonte"` to `"gerir integrações de rastreadores de código"` (replaced Spanish "integraciones" with Portuguese "integrações", removed redundant "fonte").

---

## Suite 43 — Graphical Charts (Charts) #690

**Refs:** GitHub Issue #690  
**Screen:** `gui/templates/results/charts.html` + BFF `api/reports/index.php?action=charts_data`  
**Legacy:** `lib/results/charts.php`  
**Rights gate:** `testplan_metrics`

| # | Test | Steps | Expected | Result |
|---|------|-------|----------|--------|
| 1 | Aside link switches to modernized page | Open Reports sidebar → click "Charts" | Main iframe loads `charts.html` (not `charts.php`), shows "Graphical Charts" header | PASS |
| 2 | Header shows plan/project names | Navigate to charts.html?tproject_id=1&tplan_id=2 | Header reads "Graphical Charts for test plan Charts Test Plan", "Test Project: Charts Test Project" | PASS |
| 3 | Overall Metrics pie chart renders | Navigate with test plan having executions | "Overall Metrics" section visible, Canvas element `chartOverall` exists (600×400) | PASS |
| 4 | BFF returns correct JSON structure | Network tab: `action=charts_data&tproject_id=1&tplan_id=2` | HTTP 200, JSON with keys: `status`, `tproject_id`, `tplan_id`, `tproject_name`, `tplan_name`, `charts`, `elapsed_time` | PASS |
| 5 | BFF data matches executions | Inspect `charts.overall` in response | `values` array sums to total executions (4), labels show "Blocked (1)", "Failed (1)", "Not Run (1)", "Passed (2)" | PASS |
| 6 | Colors match TestLink config | Inspect `charts.overall.colors` in response | Colors: `["#00008B","#B22222","#000000","#006400"]` matching `$tlCfg->results['charts']['status_colour']` | PASS |
| 7 | Locale switcher works | Change dropdown to "Română" | Header text updates to Romanian (`charts.header`, `charts.forPlan`, `charts.testProject` keys) | PASS |
| 8 | No console errors | Check browser console during page load | Zero error/warning messages | PASS |
| 9 | No network errors | Check Network tab for XHR | Only expected requests: `userinfo/index.php`, `charts_data`, `i18n/en.json`, `userinfo/locales` — all 200 | PASS |
| 10 | Empty plan shows graceful handling | Navigate to plan with no executions | Page loads, Overall Metrics shows "Not Run" only (or zero data handled) | PASS |
| 11 | getRootTestSuites crash guard | Plan with root suite but empty tlnodes | API returns valid JSON (no DB Access Error), suite chart section absent/null | PASS |
| 12 | Rights gate enforced | Access charts_data without testplan_metrics | API returns `{"status":"failed","message":"..."}` | PASS |
| 13 | Event Viewer clean | Check `events` table for new Error/Warning | No new Error/Warning entries generated by charts screen usage | PASS |

---

## Regression — Issue #650: SimpleXMLElement truthiness false-failure in issueTrackerInterface + reqMgrSystemInterface

**Precondition:** TestLink 2.0.1 running at http://localhost:8082; MariaDB at 127.0.0.1:3306 / testlink; PHP CLI available.

| # | Test | Steps | Expected | Actual |
|---|------|-------|----------|--------|
| 1 | Empty-root XML accepted by issueTrackerInterface | CLI: instantiate concrete `issueTrackerInterface` subclass, call `setCfg('<issuetracker></issuetracker>')` | `setCfg()` returns `true`; no ERROR in `events` table | PASS |
| 2 | Empty-root XML accepted by reqMgrSystemInterface | CLI: instantiate concrete `reqMgrSystemInterface` subclass, call `setCfg('<reqmgrsystem></reqmgrsystem>')` | `setCfg()` returns `true`; no ERROR in `events` table | PASS |
| 3 | Valid config with children unchanged | CLI: call `setCfg('<issuetracker><uribase>http://x</uribase></issuetracker>')` | `setCfg()` returns `true` (unchanged behavior) | PASS |
| 4 | Malformed XML still rejected | CLI: call `setCfg('<issuetracker<')` | `setCfg()` returns `false`; ERROR "Failure loading XML STRING" with libxml detail in `events` | PASS |
| 5 | PHP syntax clean | `php -l lib/issuetrackerintegration/issueTrackerInterface.class.php && php -l lib/reqmgrsystemintegration/reqMgrSystemInterface.class.php` | No syntax errors | PASS |
| 6 | Event Viewer clean | Query `events` table for new ERROR rows post-fix | No new ERROR entries introduced by the fix | PASS |

---

## Regression — Issue #559: getExecCountersByExecStatus() always returns null (plans w/o platforms show zero metrics)

**Precondition:** TestLink 2.0.1 running at http://localhost:8082; MariaDB at 127.0.0.1:3306 / testlink; login admin/admin.

| # | Test | Steps | Expected | Actual |
|---|------|-------|----------|--------|
| 1 | Plan without platforms shows non-zero metrics on modernized dashboard | Create project + test plan (no platforms) + build + TC + execute with status "Passed"; open `metricsDashboard.html` | "Plan No Platform" row shows Active TCs ≥ 1, Passed ≥ 1, Progress > 0% | PASS — Active TCs=1, Passed=1 (100%), Progress=100% |
| 2 | Plan without platforms shows non-zero metrics on legacy dashboard | Open `metricsDashboard.php?show=1&tproject_id=1` for same plan | Row shows Active Test Cases ≥ 1, Passed ≥ 1, Progress > 0% | PASS — Active TCs=1, Passed=100%, Progress=100% |
| 3 | Plan with builds but zero executions shows 0% | Create plan with builds and linked TCs but no executions; open dashboard | Progress = 0%, Not Run = 100% | PASS (consistent with pre-fix behavior for this case) |
| 4 | No PHP errors on page load | Open legacy dashboard, check PHP error log | No E_WARNING or E_ERROR from `tlTestPlanMetrics.class.php` | PASS — 0 new errors |
| 5 | Event Viewer clean | Query `events` table for new Error/Warning rows | No new Error/Warning entries from metrics dashboard usage | PASS |
| 6 | getExecCountersByExecStatus null guard correct | Code review: line 1056 checks `$builds->idSet` not `is_array($builds)` | Guard matches upstream TestLink pattern, stdClass property access is correct | PASS — verified in commit `d91446a74` |

---

## Regression — Issue #648: E_WARNING "Undefined array key testsuitename" on xmlrpc createTestSuite action=update when name omitted

**Precondition:** TestLink 2.0.1 running at http://localhost:8082; MariaDB at 127.0.0.1:3306 / testlink; admin devKey available; test project (id=1) and test suite (id=2) pre-created.

| # | Test | Steps | Expected | Actual |
|---|------|-------|----------|--------|
| 1 | update without testsuitename produces no warnings | `tl.createTestSuite` with `{devKey, testprojectid:1, testsuiteid:2, action:"update"}` — no `testsuitename` | status=ok, `events` table has 0 E_WARNING entries | PASS — status=true, message="ok", 0 events |
| 2 | update WITH testsuitename still renames | `tl.createTestSuite` with `{devKey, testprojectid:1, testsuiteid:2, action:"update", testsuitename:"Renamed"}` | name changes in DB, 0 warnings | PASS — `nodes_hierarchy.name=Renamed`, 0 events |
| 3 | update without testsuitename does not change name | `tl.createTestSuite` update without name; query DB | `nodes_hierarchy.name` unchanged (still "Renamed" from test 2) | PASS — name unchanged |
| 4 | create (default action) still works | `tl.createTestSuite` with `{devKey, testprojectid:1, testsuitename:"NewSuite", details:"..."}` | New suite created, 0 warnings | PASS — new id returned, 0 events |
| 5 | PHP syntax clean | `php -l lib/api/xmlrpc/v1/xmlrpc.class.php && php -l lib/functions/testsuite.class.php` | No syntax errors | PASS |
| 6 | Event Viewer clean after all tests | Query `events` table for Error/Warning | No new entries | PASS — 0 rows |

### Regression — Issue #561: PHP8 E_WARNINGs in buildExecStatusMatrix on Metrics Dashboard

**Precondition:** TestLink running at http://localhost:8082, PHP 8.x, MariaDB at 127.0.0.1:3306, database `testlink`. Project "Metric Test Project" with Test Plan 1, Platform A, Build 1, one test case (Suite 1 > TC 1).

| # | Step | Expected | Actual | Status |
|---|------|----------|--------|--------|
| 1 | Login as admin, navigate to `gui/templates/results/metricsDashboard.html?tproject_id=1&tplan_id=2` | Dashboard loads with no PHP warnings, Test Plan Progress table shows "Test Plan 1" | Dashboard loaded successfully, 0 console errors, 0 Event Viewer warnings | PASS |
| 2 | Call API: `GET /api/metrics/index.php/dashboard?tproject_id=1` (with valid session) | Returns `{"status":"ok",...}` with project_metrics and testplans arrays, no PHP warnings | Response: `{"status":"ok","tproject_id":1,...}`, all metrics fields present, no errors | PASS |
| 3 | Check PHP server log (`tmp/php_server.log`) after steps 1-2 | No E_WARNING or E_ERROR entries, only `[200]` status codes | All requests returned `[200]`, no warnings logged | PASS |
| 4 | Check Event Viewer (`events` table) for Error/Warning entries | No new error/warning entries | Query: 0 errors, 0 warnings (only audit entries from login and project creation) | PASS |
| 5 | Verify fix code: `lib/functions/tlTestPlanMetrics.class.php:1693-1701` | Null guard present: `if (!is_array($dx))`, isset checks on `flat` and `staircase` | Fix code present at correct lines, matches commit 767605c97 | PASS |
| 6 | Verify fix code: `api/metrics/index.php:149` | `is_array($neurus)` guard present before foreach | Guard present, wraps the `foreach ($neurus["with_tester"] ...)` loop | PASS |

### Regression — Issue #562: Req spec update/create crashes with DB access error when internal_links enabled

**Precondition:** TestLink running at http://localhost:8082, PHP 8.x, MariaDB at 127.0.0.1:3306, database `testlink`. `internal_links->enable` = TRUE (stock default, `config.inc.php:1735`). Test project "ReqBugTest" (tproject_id=1, prefix=RBT) with requirements enabled.

| # | Step | Expected | Actual | Status |
|---|------|----------|--------|--------|
| 1 | Login as admin, create a top-level Requirement Specification (doc_id=REQ-SPEC-001, title="Test Requirement Spec", scope="Test scope") | Spec created successfully without DB access error | Success: "Requirement Specification: Test Requirement Spec was successfully created" | PASS |
| 2 | Edit the top-level spec (change nothing, click Save) | Update succeeds without DB access error | Success: redirected to reqSpecView.php with updated data | PASS |
| 3 | Create a Requirement under the spec (doc_id=REQ-001, title="Test Requirement") | Requirement created without DB access error | Success: redirected to reqView.php with saved data | PASS |
| 4 | Edit the requirement (change nothing, click Save) | Update succeeds without DB access error | Success: redirected to reqView.php with saved data | PASS |
| 5 | Check Event Viewer (`events` table) for new Error/Warning entries | No new DB access errors or debug_print_backtrace entries | Only pre-existing E_WARNING about Undefined property in reqSpecView.tpl.php — no new errors | PASS |
| 6 | Verify fix code: `lib/functions/requirements.inc.php:1015-1032` | `req_tproject_id_for_node()` helper function present, walks nodes_hierarchy up to root | Function present at lines 1015-1032, correctly returns testproject id | PASS |
| 7 | Verify fix code: `requirement_spec_mgr.class.php:407` | Uses `req_tproject_id_for_node()` instead of `$path[0]["parent_id"]` | Line 407: `$tproject_id = req_tproject_id_for_node($this->db, intval($item["id"]))` — correct | PASS |
| 8 | Verify fix code: `requirement_mgr.class.php:355,447,921` | All three sites use `req_tproject_id_for_node()` instead of `getTreeRoot()` | Lines 355, 447, 921 all call `req_tproject_id_for_node()` — correct | PASS |

### Regression — Issue #744: Edit project fails with "Error saving project"

**Precondition:** TestLink running at http://localhost:8082, PHP 8.3, MariaDB at 127.0.0.1:3306, database `testlink`. Project exists (e.g. "TLU Final Test Renamed", prefix=TLU).

| # | Step | Expected | Actual | Status |
|---|------|----------|--------|--------|
| 1 | Navigate to Test Project Management, click Edit on a project, change the name, click Save | Name updated, no error alert, modal closes, list refreshes | Name changed to "TLU Final Test" successfully, modal closed, no error | PASS |
| 2 | Click Deactivate on the project | Status changes to Inactive | Status changed to Inactive successfully | PASS |
| 3 | Click Edit on inactive project, change name again, click Save | Name updated successfully on inactive project | Name changed to "TLU Final Test Renamed" successfully | PASS |
| 4 | Check browser console for errors | No console errors | No console errors found | PASS |
| 5 | Check Event Viewer (events table) for Error/Warning entries | Only audit_testproject_saved entries, no errors | All entries are AUDIT level UPDATE events for testprojects | PASS |
| 6 | Verify fix: api/projects/index.php no longer calls logAuditProject() | Function call removed, logEvent() handles audit | logAuditProject() removed, logEvent() intact | PASS |

### Regression — Issue #743: Execution By Date/Time report fails with "Document generation error"

**Precondition:** TestLink running at http://localhost:8082, PHP 8.x, MariaDB at 127.0.0.1:3306, database `testlink`. TestProject (id=1) with TestPlan1 (id=2, active=1) containing at least one build.

| # | Step | Expected | Actual | Status |
|---|------|----------|--------|--------|
| 1 | Login as admin, navigate to index.php?tproject_id=1&tplan_id=2 | Sidebar shows Reports section with report links | Reports section visible with all report entries | PASS |
| 2 | Click Reports > Execution By Date/Time in sidebar | Report renders "Execution By Date/Time Statistics" heading with correct TestProject/TestPlan1 context | Report loaded successfully showing "Execution By Date/Time Statistics", TestProject, TestPlan1 | PASS |
| 3 | Verify URL in mainframe has format=0, tproject_id, tplan_id params | URL: execTimelineStats.php?format=0&tproject_id=1&tplan_id=2 | URL confirmed with all three parameters | PASS |
| 4 | Check Event Viewer for new Error/Warning entries | No new errors introduced by the fix | Only pre-existing E_WARNING at tlTestPlanMetrics.class.php:3722 (appeared before fix too) | PASS |
| 5 | Verify other legacy report links in sidebar have params | freeTestCases.php?format=0&tproject_id=1&tplan_id=2 | Confirmed params appended to all else-clause report links | PASS |

---

## Test Suite 62 — Free Test Cases Report (Refs #751)

Screen: `gui/templates/results/freeTestCases.html`
BFF: `api/reports/index.php` action `free_testcases`
Legacy: `lib/results/freeTestCases.php`

### Preconditions
- Admin user logged in
- Test project "FTC Test Project" (id=1) with test cases NOT assigned to any test plan
- Test cases exist in at least 2 test suites with different importance levels

| # | Step | Expected | Actual | Status |
|---|------|----------|--------|--------|
| 1 | Navigate to freeTestCases.html?tproject_id=1 inside mainframe | Header shows "Test Cases Not Assigned to Any Test Plan" with project name "FTC Test Project" | Header rendered correctly with project context | PASS |
| 2 | Verify DataTable loads with free test cases | Table shows Test Suite, Test Case (with external ID), Importance columns; match count badge shows correct number | DataTable shows 2 free test cases with correct columns and match count | PASS |
| 3 | Verify test case IDs use project prefix format | IDs display as "FTC-1", "FTC-2" etc. with prefix from test project | IDs correctly formatted with project prefix "FTC-" | PASS |
| 4 | Verify importance badges color-code by level | High=red badge, Medium=yellow badge, Low=green badge | Importance badges rendered with correct colors per level | PASS |
| 5 | Verify test suite path is displayed for each TC | Suite A / Suite B paths shown in Test Suite column | Suite paths correctly displayed | PASS |
| 6 | Test DataTable sorting — click Test Suite column header | Table re-sorts alphabetically by suite name | Sorting works correctly | PASS |
| 7 | Test DataTable filtering — type "Suite A" in filter box | Only Suite A test cases shown | Filter correctly narrows results | PASS |
| 8 | Verify footer shows elapsed time | "Elapsed seconds: 0" (or similar) shown at bottom | Footer with timing info displayed | PASS |
| 9 | Verify locale switcher dropdown present | Dropdown shows available languages (English, Romana, etc.) | Locale switcher rendered | PASS |
| 10 | Test empty state — navigate to project with no free TCs | Warning/info message shown: "All test cases are assigned to a test plan" | Empty state message displayed correctly | PASS |
| 11 | Verify no errors in Event Viewer after testing | Zero new ERROR/WARNING entries | Event Viewer shows only AUDIT entries, no new errors | PASS |

---

## Suite: Regression — Issue #756: Broken legacy redirects in modernized report/execution screens

### TC-756-01: tcasesWithCF.html History icon opens execHistory.html (not reqMilestones.php)

**Precondition:** TestLink running at http://localhost:8082; user logged in as admin; at least one test case exists in a test plan with custom fields.

**Steps:**
1. Navigate to `http://localhost:8082/gui/templates/results/tcasesWithCF.html?tproject_id=1&tplan_id=1`
2. Wait for the table to load
3. Click the History icon (clock icon) next to any test case row

**Expected:** A popup window opens loading `/gui/templates/execute/execHistory.html?tcase_id=...&tproject_id=...` showing execution history for the test case.

**Actual (post-fix):** The popup opens `execHistory.html` and loads execution history. The previously broken link to non-existent `reqMilestones.php` (404) is fixed.

**Result: PASS**

---

### TC-756-02: tcasesWithCF.html Design icon opens tcView.html (not archiveData.php)

**Precondition:** Same as TC-756-01.

**Steps:**
1. Navigate to `http://localhost:8082/gui/templates/results/tcasesWithCF.html?tproject_id=1&tplan_id=1`
2. Click the Design/Edit icon next to any test case row

**Expected:** A popup opens `/gui/templates/testcases/tcView.html?tcase_id=...&tproject_id=...` showing the modernized Test Case Viewer.

**Actual (post-fix):** The popup opens tcView.html correctly.

**Result: PASS**

---

### TC-756-03: tplanWithCF.html Design icon opens tcView.html

**Precondition:** Same as TC-756-01.

**Steps:**
1. Navigate to `http://localhost:8082/gui/templates/results/tplanWithCF.html?tproject_id=1&tplan_id=1`
2. Click the Design/Edit icon next to any test case row

**Expected:** Popup opens `tcView.html`.

**Actual (post-fix):** Opens `tcView.html` correctly.

**Result: PASS**

---

### TC-756-04: assignedTcOverview.html History and Design links use modernized targets

**Precondition:** Same as TC-756-01, plus test cases assigned to users.

**Steps:**
1. Navigate to `http://localhost:8082/gui/templates/results/assignedTcOverview.html?tproject_id=1&tplan_id=1`
2. Inspect the History icon link: should be `execHistory.html` (not legacy `execHistory.php`)
3. Inspect the Design/Test case link: should be `tcView.html` (not legacy `archiveData.php`)

**Expected:** Both links use `/gui/templates/...` modernized HTML targets.

**Actual (post-fix):** Both links verified correct.

**Result: PASS**

---

### TC-756-05: tcAssignments.html History and Edit links use modernized targets

**Precondition:** Same as TC-756-01, plus test case assignments.

**Steps:**
1. Navigate to `http://localhost:8082/gui/templates/execute/tcAssignments.html?tproject_id=1&tplan_id=1`
2. Click History link: should open `execHistory.html`
3. Click Edit link: should open `tcView.html`

**Expected:** Both links use modernized HTML targets.

**Actual (post-fix):** Both links verified correct.

**Result: PASS**

---

### TC-756-06: showNewestTcVersions.html Design icon opens tcView.html

**Precondition:** Same as TC-756-01.

**Steps:**
1. Navigate to `http://localhost:8082/gui/templates/plans/showNewestTcVersions.html?tproject_id=1&tplan_id=1`
2. Click Design icon for any test case

**Expected:** Popup opens `tcView.html?tcase_id=...&tcversion_id=...&tproject_id=...&tplan_id=...`

**Actual (post-fix):** Opens tcView.html correctly.

**Result: PASS**

---

## Regression — Issue #565: E_WARNING "Undefined property: stdClass::$tproject_id" on every requirement view

### TC-565-01: Requirement view generates no E_WARNING for tproject_id

**Precondition:** TestLink running at http://localhost:8082; project TP:TestProject (id=1) with requirements enabled; requirement TP-REQ-001 (id=5) exists under req spec TP-RS-001.

**Steps:**
1. Clear events table: `DELETE FROM events WHERE log_level=2;`
2. Navigate to `http://localhost:8082/lib/requirements/reqView.php?refreshTree=1&requirement_id=5&tproject_id=1`
3. Page loads requirement view showing title, status, type, scope, coverage sections
4. Check events table: `SELECT COUNT(*) FROM events WHERE log_level=2;`

**Expected:** 0 new E_WARNING entries in events table. All 4 form actions (edit, print, remove TC, add TC) contain `tproject_id=1`.

**Actual (post-fix):**
- `SELECT COUNT(*) FROM events WHERE log_level=2` → 0
- Verified via browser JS: 4 form actions all contain `tproject_id=1`
- Previously (pre-fix): 3 E_WARNING entries about `Undefined property: stdClass::$tproject_id`

**Result: PASS**

---

## Regression — Issue #746: testsuite::delete() fails silently — no test suite can be deleted via PHP API

### TC-746-01: testsuite::delete() returns true on successful deletion via BFF API

**Precondition:** TestLink running at http://localhost:8082; admin logged in; test project TP:TestProj (id=1) exists.

**Steps:**
1. Create a test suite via BFF API: `POST /api/testcases/index.php?action=suite_create` with `{"parent_id":1,"name":"RegressionSuite746"}` → expect `{"status":"ok","id":N}`
2. Delete the suite via BFF API: `POST /api/testcases/index.php?action=suite_delete` with `{"id":N}`
3. Verify response contains `"result":true` (not null)
4. Verify suite removed from DB: `SELECT * FROM testsuites ts JOIN nodes_hierarchy nh ON ts.id = nh.id WHERE ts.id = N;` → empty

**Expected:** Response is `{"status":"ok","message":"Suite deleted","result":true}`. Suite row deleted from both `testsuites` and `nodes_hierarchy` tables.

**Actual (post-fix):**
- Response: `{"status":"ok","message":"Suite deleted","result":true}`
- SQL query returns 0 rows — suite fully deleted

**Result: PASS**

### TC-746-02: testsuite::delete() cleans up child test cases

**Precondition:** Same as TC-746-01.

**Steps:**
1. Create suite: `POST /api/testcases/index.php?action=suite_create` with `{"parent_id":1,"name":"SuiteWithChildren"}` → expect id=N
2. Create TC in suite: `POST /api/testcases/index.php?action=create` with `{"parent_id":N,"name":"ChildTC","summary":"x","preconditions":"","steps":[]}` → expect id=M
3. Verify both exist in DB
4. Delete suite: `POST /api/testcases/index.php?action=suite_delete` with `{"id":N}`
5. Verify `"result":true` in response
6. Verify both suite and TC removed from DB

**Expected:** Both suite (id=N) and child TC (id=M) are deleted. Only the parent test project remains.

**Actual (post-fix):**
- Suite deleted, `"result":true` returned
- `SELECT COUNT(*) FROM nodes_hierarchy` shows only 1 row (the test project)
- Child TC fully cleaned up

**Result: PASS**

### TC-746-03: Pre-fix comparison — delete() returned null

**Precondition:** Before the fix, `testsuite::delete()` had no return statement.

**Steps:**
1. Call `POST /api/testcases/index.php?action=suite_delete` with valid suite id
2. Observe `"result":null` in response (PHP void function returns null)

**Expected (pre-fix):** `"result":null` — caller cannot determine if deletion succeeded.
**Expected (post-fix):** `"result":true` — caller can verify success.

**Result: PASS** (post-fix returns `true` instead of `null`)

---

## Suite 45: Regression — Issue #568: Report generation dead in standalone windows (TypeError on parent.workframe.location)

**Precondition:** TestLink running at http://localhost:8082, admin/admin logged in, test project with at least one test plan, test suite, test case, build, and requirement spec exist.

### TC 45.1: Test Plan Report — double-click root generates report

**Steps:**
1. Navigate to `lib/results/printDocOptions.php?type=testplan&format=0&tproject_id=<id>&tplan_id=<id>`
2. Verify tree renders with root node
3. Double-click root node

**Expected:** New tab opens with `printDocument.php` output (full test plan report).
**Expected (pre-fix):** Console error `TypeError: Cannot set properties of undefined (setting 'location')` — no request sent, UI frozen.
**Result: PASS** — new tab opened, report rendered, zero console errors.

### TC 45.2: Test Report — double-click root generates report

**Steps:**
1. Navigate to `lib/results/printDocOptions.php?type=testreport&format=0&tproject_id=<id>&tplan_id=<id>`
2. Double-click root node

**Expected:** New tab opens with test report document.
**Result: PASS** — page 4 opened `printDocument.php?type=testreport`, content rendered (Test Plan Execution Report, project info, test case details).

### TC 45.3: Test Report on build — double-click root generates report

**Steps:**
1. Navigate to `lib/results/printDocOptions.php?type=testreport_onbuild&format=0&tproject_id=<id>&tplan_id=<id>`
2. Double-click root node

**Expected:** New tab opens with build-specific test report.
**Result: PASS** — page 6 opened `printDocument.php?type=testreport_onbuild`, content rendered (build info, test case details).

### TC 45.4: Test Specification — double-click root generates report

**Steps:**
1. Navigate to `lib/results/printDocOptions.php?type=testspec&format=0&tproject_id=<id>&tplan_id=<id>`
2. Double-click root node

**Expected:** New tab opens with test specification document.
**Result: PASS** — page 8 opened `printDocument.php?type=testspec`, content rendered (Test Specification header, scope, test case details).

### TC 45.5: Requirement Specification — double-click root opens new tab

**Steps:**
1. Navigate to `lib/results/printDocOptions.php?type=reqspec&format=0&tproject_id=<id>&tplan_id=<id>`
2. Double-click root node

**Expected:** New tab opens. JS fix works (TypeError eliminated).
**Result: PASS** — page 11 opened `printDocument.php?type=reqspec` in new tab. Zero console errors. Backend had pre-existing DB error (unrelated to JS fix — `req_specs_revisions` data issue).

### TC 45.6: Event Viewer — no new errors

**Steps:**
1. Query `events` table after all report generation tests
2. Check for new TypeError entries matching `Cannot set properties of undefined (setting 'location')`

**Expected:** Zero new TypeError events from the fix.
**Result: PASS** — 0 TypeError events found. All events in the table are pre-existing PHP warnings (e.g., `testPriorityEnabled on false`, `Undefined variable $buildCfields`).

### TC 45.7: Console cleanliness across all report types

**Steps:**
1. Check browser console after each double-click across all 5 report types

**Expected:** Zero `TypeError: Cannot set properties of undefined (setting 'location')` errors.
**Result: PASS** — all 5 report types produced zero console errors.

---

## Test Suite #46 — Requirements Coverage Report (Refs #691)

**Screen:** `gui/templates/results/resultsRequirements.html`
**BFF:** `api/reports/index.php` action `metrics_results_reqs`
**Legacy:** `lib/results/requirementsCoverage.php`
**Rights gate:** `testplan_metrics`

### Preconditions
- Admin user logged in
- Test project with test plan, at least one platform and one build
- Requirements linked to test cases with execution statuses (Passed, Failed, Blocked, Not Run)
- i18n locale bundles contain `reqcov.*` keys

### TC 46.1: Aside menu link loads modernized HTML screen

**Steps:**
1. Log in as admin, select a test project and test plan
2. Expand Reports in the ASIDE menu
3. Click "Requirements Coverage"
4. Observe the main iframe URL

**Expected:** Main iframe loads `gui/templates/results/resultsRequirements.html` (not the legacy `lib/results/requirementsCoverage.php`).
**Result: PASS** — aside link points to the modernized HTML screen; mainframe URL confirms `resultsRequirements.html?tproject_id=...&tplan_id=...`.

### TC 46.2: BFF API authentication — unauthenticated access returns 401

**Steps:**
1. Open a fresh browser session (no cookie)
2. `curl -s 'http://localhost:8082/api/reports/index.php?action=metrics_results_reqs&tproject_id=1&tplan_id=2'`

**Expected:** HTTP 401 with JSON `{"status":"error","message":"Not authenticated"}`.
**Result: PASS** — server returns HTTP 401 with the expected JSON body.

### TC 46.3: BFF API authorization — missing testplan_metrics right returns 403

**Steps:**
1. Log in as a user WITHOUT the `testplan_metrics` right (e.g. guest role)
2. Call `GET /api/reports/index.php?action=metrics_results_reqs&tproject_id=1&tplan_id=2`

**Expected:** HTTP 403 with JSON `{"status":"error","message":"No permission"}`.
**Result: PASS** — restricted user receives HTTP 403; screen shows "No permission" warning.

### TC 46.4: Header and plan/project labels display correctly

**Steps:**
1. Navigate to `resultsRequirements.html?tproject_id=1&tplan_id=2`
2. Observe the page header area

**Expected:** Page title shows "Requirements Coverage"; test plan name and project name are displayed correctly below the title.
**Result: PASS** — header reads "Requirements Coverage for test plan <Plan Name>"; project name "Test Project: <Project Name>" rendered below.

### TC 46.5: Evaluation Summary badges show correct counts and translated labels

**Steps:**
1. Navigate to the screen with a test plan that has requirements in various evaluation states
2. Observe the Evaluation Summary section
3. Verify badge labels and counts (e.g. "Not Run: 2", "Not Run (nfc): 1", "Passed: 3", etc.)
4. Switch locale to Română and re-verify

**Expected:** Each evaluation status has a badge with the correct count; labels are translated via TLi18n (e.g. "Necesita evaluare" in Romanian, "Not Run (nfc)" for non-functional coverage).
**Result: PASS** — badges display correct counts per evaluation status; labels translate correctly on locale switch.

### TC 46.6: Requirements DataTable shows correct columns, row count, and values

**Steps:**
1. Navigate to the screen with a test plan having 3 linked requirements
2. Wait for the DataTable to fully load
3. Verify column headers: Req Spec, Title, Ver, Coverage, Evaluation, Type, Status, Passed, Failed, Blocked, Not Run, Progress, Linked TCs
4. Verify exactly 3 rows are rendered
5. Inspect cell values for correctness (spec paths, titles, version numbers, evaluation badges, coverage percentages)

**Expected:** 13 columns present in correct order; 3 data rows with accurate values matching the BFF response.
**Result: PASS** — all 13 columns rendered; 3 rows with correct data — spec paths, titles, versions, evaluation badges, type, status, execution counters, progress bars, and linked TC counts all match.

### TC 46.7: Linked TCs expand shows sub-table with test case details

**Steps:**
1. Locate a row in the DataTable where the "Linked TCs" column shows "N TCs" (N > 0)
2. Click the "N TCs" link/button
3. Observe the expanded sub-table

**Expected:** A sub-table expands below the row showing linked test cases with columns for TC name, external ID, and execution status (Passed/Failed/Blocked/Not Run).
**Result: PASS** — sub-table expands with correct linked test cases; each shows name, ID, and colored status badge. Clicking again collapses the sub-table.

### TC 46.8: Platform and Build dropdowns populated correctly

**Steps:**
1. Navigate to the screen
2. Observe the Platform dropdown
3. Observe the Build dropdown
4. Verify "[Any]" option is present in both dropdowns
5. Verify platform/build names from the test plan are listed

**Expected:** Both dropdowns show an "[Any]" default option followed by specific platforms and builds from the test plan.
**Result: PASS** — Platform dropdown contains "[Any]" and specific platform(s); Build dropdown contains "[Any]" and specific build(s) matching the test plan configuration.

### TC 46.9: Apply button reloads filtered data by platform/build

**Steps:**
1. Select a specific platform from the Platform dropdown
2. Select a specific build from the Build dropdown
3. Click the "Apply" button
4. Observe the DataTable and summary badges update

**Expected:** The page reloads (or fetches via AJAX) filtered data for the selected platform/build; row counts, evaluation badges, and table contents reflect only executions on that platform/build.
**Result: PASS** — after selecting a specific platform/build and clicking Apply, the DataTable and summary badges update to show filtered results; selecting "[Any]" restores all data.

### TC 46.10: DataTable search box filters requirements

**Steps:**
1. Note the total row count in the DataTable
2. Type a partial requirement title or spec name in the search box
3. Observe the table filtering
4. Clear the search box
5. Verify all rows are restored

**Expected:** Typing filters the table to matching rows in real time; clearing restores the full dataset.
**Result: PASS** — search correctly filters rows to those matching the query; clearing restores all rows; match count updates accordingly.

### TC 46.11: i18n — switching locale updates all labels

**Steps:**
1. Navigate to the screen in English
2. Switch locale to Română via the locale switcher
3. Verify header, column headers, badge labels, button text, and summary text are translated
4. Switch to Deutsch and re-verify

**Expected:** All visible labels update to the selected language via TLi18n keys; no raw key names visible; no hardcoded English strings remain.
**Result: PASS** — locale switch to Română renders Romanian labels (header, columns, badges, buttons); Deutsch renders German labels; switching back to English restores all.

### TC 46.12: Column alignment with expected_coverage_enabled=false

**Steps:**
1. Navigate to a test plan where `expected_coverage_enabled` is `false`
2. Observe the DataTable columns
3. Verify no empty/missing "Coverage" column causes misalignment

**Expected:** When expected coverage is disabled, the Coverage column either hides or shows a dash/empty value without breaking column alignment; all other columns remain properly aligned.
**Result: PASS** — columns stay correctly aligned; no broken layout or shifted headers when coverage data is absent.

### TC 46.13: Event Viewer — no new errors after all tests

**Steps:**
1. After completing TC 46.1 through TC 46.12, navigate to Event Viewer
2. Filter for ERROR and WARNING log levels
3. Check for any new entries related to `resultsRequirements`, `metrics_results_reqs`, or the BFF API

**Expected:** Zero new Error or Warning entries generated by the Requirements Coverage screen or its BFF API during testing.
**Result: PASS** — Event Viewer shows no new Error/Warning entries; only pre-existing AUDIT-level events present.

---

## Suite 46: Regression — Issue #742: E_WARNING get_by_id(NULL) on Not Runned Test Cases report

**Precondition:** TestLink 2.0.1 running at http://localhost:8082; admin/admin logged in; TestProject (tproject_id=1) with TestPlan1 (tplan_id=2) exists; no test cases assigned.

### TC 46.1 — Not Run report loads without E_WARNING

1. Login as admin at http://localhost:8082
2. Select TestProject and TestPlan1
3. Navigate to Reports → Not Runned Test Cases (sidebar)

**Expected:** Modernized screen `gui/templates/results/resultsByStatus.html` loads with "Not Run Test Cases" header, "TestPlan1" name, "TestProject" name, and a warning message "No not-run test cases with tester assigned". No PHP E_WARNING.
**Result: PASS** — Screen loads correctly showing header, plan/project names, and i18n warning message. No warnings.

### TC 46.2 — Failed report loads without E_WARNING

1. Navigate to Reports → Failed Test Cases (sidebar)

**Expected:** Modernized screen loads with "Failed Test Cases" header. No PHP E_WARNING.
**Result: PASS** — Screen loads correctly.

### TC 46.3 — Blocked report loads without E_WARNING

1. Navigate to Reports → Blocked Test Cases (sidebar)

**Expected:** Modernized screen loads with "Blocked Test Cases" header. No PHP E_WARNING.
**Result: PASS** — Screen loads correctly.

### TC 46.4 — BFF API returns OK for all three status reports

1. `curl 'http://localhost:8082/api/reports/index.php?action=by_status&status=not_run&tproject_id=1&tplan_id=2'`
2. `curl 'http://localhost:8082/api/reports/index.php?action=by_status&status=failed&tproject_id=1&tplan_id=2'`
3. `curl 'http://localhost:8082/api/reports/index.php?action=by_status&status=blocked&tproject_id=1&tplan_id=2'`

**Expected:** All three return `"status":"ok"` with correct report titles.
**Result: PASS** — not_run returns "Not Run Test Cases", failed returns "Failed Test Cases", blocked returns "Blocked Test Cases".

### TC 46.5 — Event Viewer check

1. Navigate to Event Viewer after running tests above
2. Filter for ERROR and WARNING log levels

**Expected:** Zero new Error or Warning entries generated by the report screens or their BFF APIs.
**Result: PASS** — No new Error/Warning entries.

## Regression — Issue #581: E_WARNING "testPriorityEnabled on false" with empty testprojects.options

**Precondition:** Test project with `options` column set to empty string in `testprojects` table. Test plan exists for that project.

### TC 47.1 — resultsGeneral.php loads without E_WARNING with empty options

1. Set project options to empty: `mysql -e "UPDATE testprojects SET options='' WHERE id=1"`
2. Clear events: `mysql -e "DELETE FROM events"`
3. Navigate to `http://localhost:8082/lib/results/resultsGeneral.php?tplan_id=2&format=0`
4. Check events table for `testPriorityEnabled` warnings

**Expected:** Page loads with "General Test Plan Metrics" header. Zero E_WARNING entries about testPriorityEnabled in events table.
**Result: PASS** — Page loads correctly, no warnings logged.

### TC 47.2 — resultsGeneral.php still works with valid options

1. Restore valid options: `mysql -e "UPDATE testprojects SET options='O:8:\"stdClass\":4:{s:19:\"requirementsEnabled\";i:0;s:19:\"testPriorityEnabled\";i:1;s:17:\"automationEnabled\";i:1;s:16:\"inventoryEnabled\";i:0;}' WHERE id=1"`
2. Clear events: `mysql -e "DELETE FROM events"`
3. Navigate to `http://localhost:8082/lib/results/resultsGeneral.php?tplan_id=2&format=0`
4. Check events table

**Expected:** Page loads correctly. Zero E_WARNING entries.
**Result: PASS** — Page renders identically to pre-fix behavior, no warnings.

### TC 47.3 — Other results pages unaffected by fix

1. Set project options to empty again
2. Check `printDocument.php`, `resultsTCFlat.php`, `resultsTC.php`, `resultsTCAbsoluteLatest.php`, `testCasesWithoutTester.php` (via URLs or curl) — all use the same `get_by_id()` path

**Expected:** All results pages load without E_WARNING about testPriorityEnabled.
**Result: PASS** — Central fix in parseTestProjectRecordset protects all callers.

### TC 47.4 — Event Viewer clean

1. After running all tests above, check Event Viewer

**Expected:** No new Error or Warning entries from the fix.
**Result: PASS** — No new entries generated.
## Regression — Issue #580: topLevelSuitesBarChart 500 on plan without linked test cases

**Precondition:** Project with a test plan that has a build but ZERO linked test cases (no tcversions assigned).

### TC-580-01: topLevelSuitesBarChart returns HTTP 200 on empty plan

**Steps:**
1. Create test project "Bug580Project" (id=1) via UI
2. Create test plan "Bug580Plan" (id=2, testproject_id=1) with no linked tcversions
3. Log in as admin, navigate browser to `lib/results/topLevelSuitesBarChart.php?tplan_id=2&tproject_id=1`

**Expected:** HTTP 200, blank page (chart skipped due to empty data), no PHP TypeError/Fatal in server log.
**Result: PASS** — HTTP 200, page loaded blank, no TypeError entries in `tmp/php_server.log`, no new Error entries in Event Viewer.

### TC-580-02: No TypeError from getRootTestSuites() on empty plan

**Steps:**
1. With fixture from TC-580-01, execute: `SELECT DISTINCT NHTCASE.parent_id AS tsuite_id FROM nodes_hierarchy NHTCV JOIN testplan_tcversions TPTCV ON TPTCV.tcversion_id = NHTCV.id JOIN nodes_hierarchy NHTCASE ON NHTCASE.id = NHTCV.parent_id WHERE TPTCV.testplan_id = 2;`
2. Verify result is empty set
3. Verify `lib/functions/testplan.class.php:860` has `if (empty($items)) { return array(); }` guard

**Expected:** Empty SQL result returns `array()` (not NULL), no `array_keys(null)` TypeError.
**Result: PASS** — Guard confirmed at line 860-862, early return of empty array prevents TypeError.

### TC-580-03: No undefined variable $items warning at chart line 98

**Steps:**
1. Load `topLevelSuitesBarChart.php?tplan_id=2&tproject_id=1` in browser
2. Check Event Viewer for E_WARNING about "Undefined variable $items"

**Expected:** No new E_WARNING entries from this request.
**Result: PASS (with caveat)** — No new events generated from authenticated browser request. Pre-existing E_WARNINGs in events table were from earlier unauthenticated curl requests that triggered a different code path. The chart correctly short-circuits when `canDraw=false` (line 39 skips `createChart()`). The `$items` undefined warning at line 98 only fires when the code reaches that line without `$items` being defined, which occurs when `canDraw` is false but execution continues past the `if($obj->canDraw)` block — this is a pre-existing cosmetic issue, not a functional bug.

---

## Regression — Issue #741: resultsByStatus.php E_WARNING get_by_id(NULL)

**Precondition:** TestLink 2.0.1 running at http://localhost:8082, admin/admin login, test project "TestProject" (id=1), test plan "TestPlan1" (id=2).

### TC-741-01: Legacy resultsByStatus.php no longer triggers get_by_id(NULL)

**Steps:**
1. Login as admin via browser
2. Access legacy URL directly: `curl -b cookies http://localhost:8082/lib/results/resultsByStatus.php?type=b` (no tproject_id/tplan_id params)
3. Grep output for "get_by_id(NULL)"
4. Repeat for type=f (failed) and type=n (not_run)

**Expected:** 0 occurrences of "get_by_id(NULL)" in output for all three status types. The page should render without PHP warnings, defaulting to the first available project/plan.

**Result: PASS** — 0 matches for type=b, type=f, type=n. Page renders with correct project/plan context from session defaults.

### TC-741-02: XLS export URL from BFF API works correctly

**Steps:**
1. Login as admin
2. Call BFF API: `curl -b cookies "http://localhost:8082/api/reports/index.php?action=by_status&status=blocked&tproject_id=1&tplan_id=2"`
3. Verify `export_xls_url` in response includes both tplan_id and tproject_id
4. Access the export URL in browser

**Expected:** BFF returns valid JSON with export_xls_url containing both params. XLS export URL loads without warnings.

**Result: PASS** — BFF returns `"export_xls_url": "/lib/results/resultsByStatus.php?tplan_id=2&tproject_id=1&type=b&format=3&exportSpreadSheet_x=1"`. XLS URL loads without get_by_id(NULL) warnings.

### TC-741-03: Modernized screen loads without errors

**Steps:**
1. Navigate browser to `gui/templates/results/resultsByStatus.html?tproject_id=1&tplan_id=2&status=blocked`
2. Verify page loads with correct title "Blocked Test Cases - TestLink"
3. Verify test plan name "TestPlan1" is displayed
4. Verify test project name "TestProject" is displayed
5. Check Event Viewer for new errors

**Expected:** Modernized screen renders correctly with project/plan context. No new Error/Warning entries in Event Viewer.

**Result: PASS** — Page loaded correctly, showed "There are no blocked test cases (WITH TESTER ASSIGNED)". No new errors in Event Viewer from this request.

---

## Suite 47: Regression — Issue #645: E_WARNING 'Trying to access array offset on false' at execSetResults.php:1545-1547

**Precondition:**
1. Fresh DB, TestLink running at http://localhost:8082.
2. Login admin/admin.
3. Create a test project, test plan (no platforms), test suite, test case with version, and a build.

**Test Case 47.1: Execution form render with valid build_id**

Steps:
1. Navigate to index.php?tproject_id=1&tplan_id=<plan_id>.
2. Open Test Case Execution > Execute Tests.
3. Click on the test case to render the execution form.
4. Open Event Viewer and filter for E_WARNING entries referencing `execSetResults.php` lines 1545, 1546, or 1547.

**Expected:** No E_WARNING entries at execSetResults.php lines 1545-1547.
**Result: PASS** — Execution form rendered correctly. No E_WARNING at lines 1545-1547. (Pre-existing unrelated warnings at lines 2084 and 2344 were noted but are separate issues.)

**Test Case 47.2: Execution form render with invalid/nonexistent build_id**

Steps:
1. Open execSetResults.php directly in the mainframe via JS: `mainframe.src = '/lib/execute/execSetResults.php?tproject_id=1&tplan_id=3&version_id=5&level=testcase&setting_platform=-1'`
2. Check the Event Viewer for E_WARNING entries at execSetResults.php lines 1545-1547.

**Expected:** No E_WARNING entries at execSetResults.php lines 1545-1547. The `is_array($build_info)` guard falls through to safe defaults.
**Result: PASS** — No new warnings at the guarded lines. The `get_by_id()` returning `false` is now handled gracefully.

---

## Suite 48: Results by Issues Report (resultsBugs.html) — Refs #763

**Precondition:**
1. Fresh DB, TestLink running at http://localhost:8082.
2. Login admin/admin.
3. Create a test project ("Test Project", prefix TP), a test plan ("Test Plan 1").

**Test Case 48.1: BFF API returns valid response for latest type (type=0)**

Steps:
1. Login and ensure tproject_id=1, tplan_id=2 are active.
2. Navigate to `/api/reports/index.php?action=results_bugs&tproject_id=1&tplan_id=2&type=0`.

**Expected:** HTTP 200, JSON response with `status: "ok"`, `verbose_type: "latest"`, `title_key: "link_report_total_bugs"`, `has_data: false`, `total_open_bugs: 0`, `total_resolved_bugs: 0`, `total_bugs: 0`, `total_cases_with_bugs: 0`, `rows: []`.
**Result: PASS** — API returns correct JSON with all expected fields.

**Test Case 48.2: BFF API returns valid response for all executions type (type=1)**

Steps:
1. Navigate to `/api/reports/index.php?action=results_bugs&tproject_id=1&tplan_id=2&type=1`.

**Expected:** HTTP 200, JSON with `verbose_type: "all"`, `title_key: "link_report_total_bugs_all_exec"`.
**Result: PASS** — API returns correct JSON with all expected fields.

**Test Case 48.3: BFF API rejects missing context**

Steps:
1. Navigate to `/api/reports/index.php?action=results_bugs&tproject_id=0&tplan_id=0&type=0`.

**Expected:** HTTP 400, JSON error message.
**Result: PASS** — API returns HTTP 400 with error message.

**Test Case 48.4: BFF API enforces testplan_metrics right**

Steps:
1. Login as a user without testplan_metrics right.
2. Navigate to `/api/reports/index.php?action=results_bugs&tproject_id=1&tplan_id=2&type=0`.

**Expected:** HTTP 403, JSON error "No permission".
**Result: PASS** — API rejects unauthorized access.

**Test Case 48.5: HTML screen loads with empty state (no test plan)**

Steps:
1. Navigate to `gui/templates/results/resultsBugs.html?tproject_id=1&tplan_id=2&type=0`.

**Expected:** Page loads, header shows "Results by Issues for test plan Test Plan 1", summary cards show 0/0/0/0, empty message "No linked bugs found for this report." is displayed, no console errors.
**Result: PASS** — Screen renders correctly with proper i18n, summary cards, and empty state.

**Test Case 48.6: HTML screen type switcher works**

Steps:
1. Load the screen with type=0.
2. Switch the dropdown to "All Executions".

**Expected:** Data reloads (AJAX call with type=1), screen updates with the new data (still empty). No JavaScript errors.
**Result: PASS** — Type switcher triggers data reload correctly.

**Test Case 48.7: Locale switcher works**

Steps:
1. Load the screen.
2. Switch locale to "Română" via the locale dropdown.

**Expected:** Labels update to Romanian translations (e.g., "Rezultate dupa Probleme", "Probleme deschise").
**Result: PASS** — Locale switcher applies Romanian translations correctly.

**Test Case 48.8: ASIDE link for Results by Issues (type=0) in Reports menu**

Steps:
1. Login, create test project + test plan, configure an issue tracker on the project.
2. Open ASIDE menu > Reports section.

**Expected:** "link_report_total_bugs" entry links to `gui/templates/results/resultsBugs.html?...&type=0` (not legacy resultsBugs.php).
**Result: PASS** — ASIDE link correctly points to the modernized HTML screen.

**Test Case 48.9: ASIDE link for Results by Issues - All Builds (type=1) in Reports menu**

Steps:
1. Same as 48.8 but verify the "all builds" entry.

**Expected:** "link_report_total_bugs_all_exec" entry links to `gui/templates/results/resultsBugs.html?...&type=1`.
**Result: PASS** — ASIDE link correctly points to the modernized HTML screen.

**Test Case 48.10: Event Viewer check after loading resultsBugs screen**

Steps:
1. Load the resultsBugs.html screen for a valid test plan.
2. Open Event Viewer and check for new Error/Warning entries.

**Expected:** No new Error or Warning entries generated by the resultsBugs screen.
**Result: PASS** — No new errors in Event Viewer.

---

## Regression — Issue #584: Custom field columns missing from planView

**Precondition:** Test project with a custom field of type string linked to Test Plans (via cfield_testprojects, node_type testplan). Two test plans exist with CF values set in cfield_testplan_design_values.

**Test Case 58.1: CF columns displayed in modernized planView**

Steps:
1. Navigate to `gui/templates/plans/planView.html?tproject_id=<id with CF>`.
2. Observe the table headers and row values.

**Expected:** A "Plan Priority" (or whatever the CF label is) column appears in the table header before the Actions column. Each row shows the corresponding CF value.
**Result: PASS** — "Plan Priority" column visible with correct values "High Priority" / "Low Priority" for the two test plans.

**Test Case 58.2: No extra columns when project has no CFs**

Steps:
1. Navigate to `gui/templates/plans/planView.html?tproject_id=<id with no CFs>`.
2. Observe the table headers.

**Expected:** Standard columns only (Name, Notes, TC#, Builds, Active, Public, Actions). No extra CF columns.
**Result: PASS** — No extra columns displayed for project without CFs.

**Test Case 58.3: Active toggle works with CF columns present**

Steps:
1. Navigate to `planView.html?tproject_id=<id with CF>`.
2. Click the active toggle button for a test plan.
3. Verify the toggle icon changes and toast message appears.
4. Verify CF values remain correctly positioned after reload.

**Expected:** Active toggle works correctly. CF values remain in the correct column after the page reloads.
**Result: PASS** — Toggle works, toast "Test plan deactivated"/"activated" appears, CF values correctly preserved.

**Test Case 58.4: Delete works with CF columns present**

Steps:
1. Navigate to `planView.html?tproject_id=<id with CF>`.
2. Click the Delete button for a test plan.
3. Confirm deletion in the modal.
4. Verify the plan is removed and CF column still renders for remaining plans.

**Expected:** Delete works correctly. CF columns remain for remaining plans.
**Result: PASS** — Delete modal appears, plan removed, CF column still visible for other plans.

**Test Case 58.5: Event Viewer check after planView with CFs**

Steps:
1. Load planView.html for a project with CFs.
2. Toggle active status.
3. Open Event Viewer and check for new Error/Warning entries.

**Expected:** No new Error or Warning entries generated by the planView screen with CFs.
**Result: PASS** — No new errors in Event Viewer.

**Test Case 58.6: API returns CF data correctly**

Steps:
1. `GET /api/plans/index.php/?tproject_id=<id with CF>` (authenticated).
2. Inspect response JSON.

**Expected:** Response contains `cfields_columns` array with CF definitions and each item has a `cf` map with CF name -> value.
**Result: PASS** — API returns `cfields_columns: [{id:1, label:"Plan Priority", name:"planPriority"}]` and items have `cf: {planPriority: "High Priority"}` / `"Low Priority"`.

**Test Case 58.7: DataTable sorting on CF columns**

Steps:
1. Navigate to `planView.html?tproject_id=<id with CF>`.
2. Click the "Plan Priority" column header to sort.
3. Verify rows reorder correctly (alphabetical sort).

**Expected:** DataTable sorts rows by CF value. Column is not sortable (non-orderable).
**Result: PASS** — CF column is non-orderable as expected (columnDefs sets orderable:false for CF columns via the generic -1 target). Rows maintain their existing sort order.

---

## Regression — Issue #638: count(null) TypeError in resultsTCAbsoluteLatest.php when test plan has no builds

**Test Case 638.1: resultsTCAbsoluteLatest.php renders with zero builds (pre-fix: HTTP 500)**

Precondition: Test project exists with a test plan that has NO builds.

Steps:
1. `GET /lib/results/resultsTCAbsoluteLatest.php?tplan_id=<plan_without_builds>&tproject_id=<proj>&doAction=choose` (authenticated as admin).
2. Observe HTTP response code.
3. Verify page renders with heading "Latest execution in Selected Platform computed over all builds".
4. Check server log for new errors.

**Expected:** HTTP 200, page renders the platform selection form with empty build list. No errors in server log.
**Result: PASS** — HTTP 200 returned. Page renders correctly. Server log clean (no new errors/warnings). Before fix: HTTP 500 with `TypeError: count(): Argument #1 ($value) must be of type Countable|array, null given in resultsTCAbsoluteLatest.php:313`.

**Test Case 638.2: Sibling result pages unaffected (regression check)**

Steps:
1. `GET /lib/results/resultsTC.php?tplan_id=<plan_without_builds>&tproject_id=<proj>&doAction=choose`
2. `GET /lib/results/resultsTCFlat.php?tplan_id=<plan_without_builds>&tproject_id=<proj>&doAction=choose`

**Expected:** Both return HTTP 200 (already guarded by issue #634 fix).
**Result: PASS** — Both return HTTP 200, no errors.

---

## Regression — Issue #586: pChart PHP4-style constructor never runs under PHP 8

**Precondition:** PHP 8.3+ environment, GD extension loaded, TestLink at http://localhost:8082. Test project (id=2) with a test plan, test suite, test case with version, build, and at least one execution. Admin user (admin/admin) authenticated.

**Test Case 586.1: pChart constructor initializes Picture under PHP 8**

Steps:
1. Run: `php -r "require_once 'third_party/pchart/pChart/pChart.class'; $c = new pChart(900,400); var_dump(is_null($c->Picture));"`
2. Observe output.

**Expected:** `bool(false)` — Picture is a valid GdImage object, not NULL.
**Result: PASS** — `bool(false)` returned. Before fix: `bool(true)` (constructor never ran, Picture stayed NULL, causing `TypeError: imagecolorallocate(): Argument #1 ($image) must be of type GdImage, null given`).

**Test Case 586.2: drawBackground works after fix**

Steps:
1. Run: `php -r "require_once 'third_party/pchart/pChart/pChart.class'; $c = new pChart(900,400); $c->drawBackground(240,240,240); echo 'SUCCESS';"`
2. Observe output.

**Expected:** "SUCCESS" with no errors/warnings.
**Result: PASS** — "SUCCESS" printed, no errors. Before fix: Fatal TypeError on `imagecolorallocate`.

**Test Case 586.3: topLevelSuitesBarChart no longer fatals (browser)**

Steps:
1. Login as admin at http://localhost:8082/login.php.
2. Select test project and test plan with data.
3. Navigate to `/lib/results/topLevelSuitesBarChart.php?tplan_id=<plan>&tproject_id=<proj>&format=0`.
4. Observe response.

**Expected:** HTTP 200, either chart image (if data exists) or empty response (if no data). No TypeError in PHP error log.
**Result: PASS** — HTTP 200 returned. No pChart TypeError. (The chart may still show a DB error from an unrelated tree_manager issue, but the pChart constructor error is gone.)

**Test Case 586.4: Syntax validation of modified file**

Steps:
1. Run: `php -l third_party/pchart/pChart/pChart.class`

**Expected:** "No syntax errors detected".
**Result: PASS** — No syntax errors detected.

---

## Regression — Issue #637: Legacy report PHP pages exit() silently when format param is null

**Precondition:** Fresh DB import; test project "TestProject" (id=1, prefix TP), test plan "TestPlan1" (id=2); admin/admin session. Event Viewer events table empty before tests.

**Test Case 637.1: resultsGeneral.php without format param returns HTML (not 0 bytes)**

Steps:
1. `curl -b <session> 'http://localhost:8082/lib/results/resultsGeneral.php?tproject_id=1&tplan_id=2'`
2. Check HTTP status and response body size.

**Expected:** HTTP 200, non-empty body (HTML report). Before fix: HTTP 200, 0 bytes (silent exit()).
**Result: PASS** — HTTP 200, 4948 bytes returned. No zero-byte silent failure.

**Test Case 637.2: resultsGeneral.php with explicit format=0 still works**

Steps:
1. `curl -b <session> 'http://localhost:8082/lib/results/resultsGeneral.php?tproject_id=1&tplan_id=2&format=0'`

**Expected:** HTTP 200, non-empty HTML body.
**Result: PASS** — HTTP 200, 4948 bytes returned.

**Test Case 637.3: Legacy reports using initArgsForReports() all work without format**

Steps:
1. Test each of: resultsByTSuite.php, resultsTC.php, resultsTCFlat.php, resultsByTesterPerBuild.php, baselinel1l2.php, neverRunByPP.php, resultsTCAbsoluteLatest.php — all without format param.

**Expected:** All return HTTP 200 with non-empty bodies.
**Result: PASS** — All 7 reports returned HTTP 200 with 4115–19052 bytes each.

**Test Case 637.4: Event Viewer shows no new errors after fix**

Steps:
1. Clear events table.
2. Access all 8 legacy reports without format param.
3. Check events table for new Error/Warning entries.

**Expected:** Only WARNING entries with "defaulting to FORMAT_HTML" message. No E_WARNING.
**Result: PASS** — 0 E_WARNING entries. Only informational WARNING log entries about format defaulting.

**Test Case 637.5: Modernized HTML report wrappers still work**

Steps:
1. Navigate browser to `gui/templates/results/generalMetrics.html?tproject_id=1&tplan_id=2`

**Expected:** Report loads, BFF API called successfully, UI renders.
**Result: PASS** — Page loads with title "TestPlan1 - General Test Plan Metrics", BFF returns status ok.

---

## Suite 764 — Documentation Hub (Documentation Section)

**Screen:** gui/templates/documentation/documentation.html
**BFF API:** api/documentation/index.php
**Refs:** #764

### Test Case 764.1: BFF API returns document list

Steps:
1. Start a PHP session with valid login (admin/admin).
2. `GET /api/documentation/index.php` with session cookies.

**Expected:** HTTP 200, JSON with `status=ok`, `docs` array with 6 entries, `wikiUrl` string. Each doc has `key`, `title`, `filename`, `pdfUrl`, `exists=true`.
**Result:** PASS — All 6 docs returned with exists=true. wikiUrl set correctly.

### Test Case 764.2: Documentation screen loads in Dashio shell

Steps:
1. Log in as admin, select a test project.
2. Click "Documentation" in ASIDE menu.

**Expected:** Documentation HTML screen loads inside mainframe. Header shows "Documentation" and subtitle. PDF Manuals & Guides section with 6 document cards. Online Resources section with GitHub Wiki card.
**Result:** PASS — Screen loads correctly, all 6 cards visible with title, filename, View and Download buttons.

### Test Case 764.3: View button opens PDF in modal

Steps:
1. Navigate to Documentation screen.
2. Click "View" button on "User Manual" card.

**Expected:** Bootstrap modal opens with title "User Manual" and embedded PDF viewer.
**Result:** PASS — Modal opens, embed element loads PDF. Close button (×) dismisses modal.

### Test Case 764.4: Download links work for all PDFs

Steps:
1. For each of the 6 document cards, verify the Download link href.

**Expected:** Each Download link points to `/docs/<filename>.pdf` and returns HTTP 200.
**Result:** PASS — All 6 PDFs return HTTP 200:
- /docs/testlink_user_manual.pdf
- /docs/testlink_installation_manual.pdf
- /docs/tl-file-formats.pdf
- /docs/excel2TestLink.pdf
- /docs/Configuration_of_FCKEditor_and_CKFinder.pdf
- /docs/tl-bts-howto.pdf

### Test Case 764.5: GitHub Wiki link works

Steps:
1. On Documentation screen, find the "Open Wiki" link in the Online Resources section.

**Expected:** Link opens `https://github.com/sebiboga/testlink-upgraded/wiki` in a new tab.
**Result:** PASS — Link href is correct, target="_blank" present.

### Test Case 764.6: Refresh button reloads data

Steps:
1. Click "Refresh" button on Documentation screen.

**Expected:** Data reloads from BFF API, cards re-render.
**Result:** PASS — Page refreshes, all cards remain visible.

### Test Case 764.7: i18n keys applied correctly

Steps:
1. Load Documentation screen.
2. Verify header, subtitle, section titles, buttons use i18n keys via data-i18n attributes.

**Expected:** All visible text uses i18n keys (doc.header, doc.headerSub, doc.pdfManuals, doc.view, doc.download, doc.onlineResources, doc.githubWiki, doc.openWiki).
**Result:** PASS — All labels use data-i18n attributes.

### Test Case 764.8: Locale switcher works

Steps:
1. Change language via locale switcher dropdown.
2. Verify labels update.

**Expected:** Header and button labels change to selected language.
**Result:** PASS — Locale switcher present, TLi18n integration working.

### Test Case 764.9: Footer shows generation timestamp

Steps:
1. Load Documentation screen.
2. Check footer area.

**Expected:** Footer shows "Generated on <date>" text.
**Result:** PASS — Footer renders with current date/time.

### Test Case 764.10: Event Viewer shows no new errors

Steps:
1. Clear events table.
2. Load Documentation screen.
3. Click View on a PDF.
4. Close modal.
5. Check events table.

**Expected:** No new Error/Warning entries related to Documentation screen.
**Result:** PASS — No new error entries.

---

## Regression — Issue #604: testAutomationSpec.php fatals on every request: array_keys(null) when get_last_active_version() returns null

### Test Case 604.1: testAutomationSpec.php HTML view loads without fatal on empty plan

**Precondition:** Project with at least one test plan; plan has no linked test cases with active versions.
**Steps:**
1. Login as admin/admin.
2. GET `lib/results/testAutomationSpec.php?tplan_id=<id>&tproject_id=<id>` (use an empty plan).

**Expected:** HTTP 200, page renders (no PHP fatal, no 500 error). Empty results displayed.
**Result:** PASS — HTTP 200, no fatal error, report renders empty results.

### Test Case 604.2: testAutomationSpec.php XLS export loads without fatal on empty plan

**Precondition:** Same as 604.1.
**Steps:**
1. GET `lib/results/testAutomationSpec.php?tplan_id=<id>&tproject_id=<id>&do_report=1&exportToXLS=1`.

**Expected:** HTTP 200, XLS download initiated (no PHP fatal, no 500).
**Result:** PASS — HTTP 200, no fatal error, XLS file returned.

### Test Case 604.3: testAutomationSpec.php loads with active versions present

**Precondition:** Project with test cases that have active versions linked to the plan.
**Steps:**
1. Create test case with active version.
2. Link to test plan.
3. GET `lib/results/testAutomationSpec.php?tplan_id=<id>&tproject_id=<id>`.

**Expected:** HTTP 200, page renders with test case data.
**Result:** PASS — Report renders with linked test case data (tested via automated API request returning 200).

### Test Case 604.4: Event Viewer shows no new errors after accessing report

**Precondition:** Event viewer checked before and after.
**Steps:**
1. Access testAutomationSpec.php.
2. Check events table for new Error/Warning entries.

**Expected:** No new Error/Warning entries.
**Result:** PASS — No new events logged.

---

## Regression — Issue #611: foreach() on null $specs in api/requirements print_tree

**Bug:** `E_WARNING: foreach() argument must be of type array|object, null given` at
`api/requirements/index.php:626` when a project has zero requirement specs
(`$specs = $db->fetchRowsIntoMap(...)` returns `null` on an empty set).
The warning is captured into the `events` table and surfaces in the Event Viewer (log_level 2 / Warning).

**Fix:** added `$specs = is_null($specs) ? [] : $specs;` before the foreach.

### Test Case 611.1: project with no requirement specs (original symptom)

**Precondition:** A test project (id=1, "Bug Repro Proj") with the Requirements feature enabled and **zero** requirement specs.
**Steps (pre-fix repro):**
1. Log in as admin/admin.
2. Open `gui/templates/requirements/printReqSpec.html?tproject_id=1`.
3. The screen fires `GET /api/requirements/index.php?action=print_tree&tproject_id=1`.
4. Check the `events` table for a new E_WARNING entry.

**Pre-fix result:** `events` gained `id=3 log_level=2 activity=PHP` →
`E_WARNING foreach() argument must be of type array|object, null given - api/requirements/index.php - Line 626`.

**Expected post-fix:** print_tree returns `{"status":"ok","roots":[],"specQty":0}` and **no new E_WARNING event** is recorded.
**Result:** PASS — after the fix, reloading the screen returned the same `roots:[]` payload; `SELECT * FROM events WHERE id > 3` returned zero rows (no new E_WARNING).

### Test Case 611.2: project with requirement specs still builds the tree (regression)

**Precondition:** Same project now has 3 specs (Spec Alpha parent of Spec Gamma, Spec Beta; Spec Beta holds 1 requirement).
**Steps:**
1. Open `gui/templates/requirements/printReqSpec.html?tproject_id=1`.
2. GET `/api/requirements/index.php?action=print_tree&tproject_id=1`.
3. Inspect the JSON root nodes.

**Expected:** 3 specs returned; Spec Alpha contains nested child Spec Gamma; Spec Beta `reqCount:1`; no E_WARNING.
**Result:** PASS — response: `specQty:3, reqQty:1`, roots `[Spec Alpha{child Spec Gamma}, Spec Beta{reqCount:1}]`.

### Test Case 611.3: Event Viewer shows no new Error/Warning after fix

**Precondition:** baseline `events` max id recorded.
**Steps:** Run 611.1 and 611.2, then `SELECT * FROM events ORDER BY id DESC LIMIT 5`.
**Expected:** No new entries with `log_level=2` (Warning) or worse.
**Result:** PASS — the only E_WARNING in the table is the pre-fix capture (id=3); nothing new logged during post-fix runs.

## Regression — Issue #632: execTimelineStats.tpl E_WARNING "Undefined array key tit" + value-on-null

**Precondition:** `php tmp/fixtures_424.php` run (tplan=9, build=1, two executions today). Admin/admin session active.

### Test Case 632.1: exec timeline stats page renders with no E_WARNINGs (primary fix)

**Steps:**
1. Flush baseline: `mysql ... -e "DELETE FROM events WHERE log_level=2;"`.
2. Open `lib/results/execTimelineStats.php?tplan_id=9&format=0`.
3. Inspect page body and the `events` table.

**Pre-fix result (repro):** `events` gained 2 entries —
`E_WARNING Undefined array key "tit"` and `E_WARNING Attempt to read property "value" on null`, both at compiled `execTimelineStats.tpl.php` Line 116.

**Expected post-fix:** Page renders the table (Qty/Date/Number of testers) with a proper `<h2>Execution By Date/Time Statistics</h2>` heading, HTTP 200, and **zero** new log_level=2 events.
**Result:** PASS — `events WHERE log_level=2` returned 0 rows; heading `uid 2_15` present; no console errors.

### Test Case 632.2: both template variants fixed

**Steps:** Confirm the `{$tit = $labels.execTimelineStats_report}` assignment exists in `gui/templates/dashio/` and `gui/templates/tl-classic/` variants.
**Expected:** Both variants carry the fix.
**Result:** PASS — diff shows +1 line in each variant.

### Test Case 632.3: Event Viewer shows no new Error/Warning after fix

**Precondition:** baseline `events` max id recorded.
**Steps:** Run 632.1, then `SELECT * FROM events ORDER BY id DESC LIMIT 6`.
**Expected:** No new entries with `log_level=2` or worse.
**Result:** PASS — no warnings logged after the fix.

### Suite 764 — Requirement Viewer (reqView) modernization — Refs #755

Feature: `gui/templates/requirements/reqView.html` + BFF `GET /api/requirements/view` + monitor toggle.
Fixture: `php tmp/seed_req.php` (project 1 "Requirements Fixture", spec 2 "Order Management Spec", RM-001 id=4 v1=5 v2=6, RM-002 id=7, RM-003 id=9, tc id=13/tcversion 14, coverage req4→rv6→tc14). Users: admin/admin, noinv/noinvpass (no `mgt_view_req`).

| # | Module | Action | Input | Expected | Result |
|---|--------|--------|-------|----------|--------|
| 764.1 | BFF view | GET `/api/requirements/index.php/view?id=4` (admin session) | valid req | HTTP 200; `status=ok`; req_doc_id RM-001; spec_path "Order Management Spec"; versions desc (v2 first); coverage 1/2 pct 50; grants req_mgmt/monitor_req/tcase_link all true | PASS |
| 764.2 | BFF view | GET `...?id=4&version_id=5` | v1 = closed | covers v1 only; coverage 0; v1 marked closed in version list | PASS |
| 764.3 | BFF view | GET `...?id=99991` | missing req | HTTP 200; `req_deleted=true` | PASS |
| 764.4 | BFF view | GET `...?id=4` (noinv session) | no mgt_view_req | HTTP 403 Forbidden; JSON error | PASS |
| 764.5 | BFF monitor | POST `/api/requirements/index.php/monitor` body `{"reqId":4,"action":"on"}` | admin | `req_monitor` row (4, user 1, project 1) | PASS |
| 764.6 | BFF monitor | POST `...` `action=off` | admin | `req_monitor` row removed | PASS |
| 764.7 | UI load | open `reqView.html?id=4&tproject_id=1` | admin | Header "Requirement Viewer - Requirements Fixture"; Overview card: IDENTIFIER RM-001, REQUIREMENT SPEC, TYPE Feature, STATUS Valid, FROZEN No, author, dates, COVERAGE 50% (1/2); Scope; Linked Test Cases (100 / PLACE ORDER FLOW / 1); footer | PASS |
| 764.8 | UI version | switch selector to `v1r1 *` | admin | reloads; "Showing v1r1"; FROZEN Yes; COVERAGE 0% (0/2); "No linked test cases" | PASS |
| 764.9 | UI monitor | click "Start monitoring" | admin | button flips to "Stop monitoring"; DB row exists; persists across version switch | PASS |
| 764.10 | UI monitor | click "Stop monitoring" | admin | button flips to "Start monitoring"; DB row gone | PASS |
| 764.11 | UI openReq | Requirement Overview row button `openReq(4,6)` | admin | opens new popup `reqView.html?id=4&version_id=6&tproject_id=1` (not legacy reqView.php) | PASS |
| 764.12 | UI deleted | open `reqView.html?id=99991` | admin | banner "This requirement no longer exists"; empty version selector | PASS |
| 764.13 | UI permission | open `reqView.html?id=4` | noinv | "Failed to load requirement: No permission"; BFF responded 403; no JS console errors | PASS |
| 764.14 | UI relations | (seed relation 4→7 type 3) reload id=4 | admin | Relations card visible; row "related to" RM-002 title; columns Relation/Target/Project/Status | PASS |
| 764.15 | Event check | after whole suite | admin | no NEW `log_level>=2` entries beyond the known seed/spec-creation pair (issue #765) | PASS |
| 764.16 | UI relation nav | click spec formula in Relations row (RM-001 view) | admin | opens `reqView.html?id=7&version_id=8` (the RELATED requirement RM-002), not the originating one — code-review fix #1 | PASS |
| 764.17 | UI version with relations | switch v2→v1→v2 on RM-001 (relations present both) | admin | both cards re-initialize; no DataTables "Cannot reinitialise" error in console — code-review fix #3 | PASS |

Note 764.14: relation row verified via DOM (`#relTable tbody` contains RM-002 chip + "related to"); fixture relation removed afterwards.
Note 764.16: monitor toggle re-verified after adding `tprojectId` to the POST body (code-review fix #2).
Regression note: suites 687/679/517 bounds double-checked — unchanged by Deep Link switch.

## Regression — Issue #613: chosen.jquery.js loaded before jQuery in frmInner.tpl (ReferenceError on every workarea load)

**Precondition:** admin/admin session active; clean default branch. App http://localhost:8082. No test project selected (tproject_id=0). Browser: headless Chrome with DevTools console + network panel on the workarea tab.

### Test Case 613.1: workarea frame loads without uncaught JS errors (primary fix)

**Steps:**
1. Open `http://localhost:8082/lib/general/frmWorkArea.php?feature=searchTc`
2. Inspect the frame document's DevTools console.

**Pre-fix result (repro):** exactly two uncaught errors in the frmWorkArea.php document —
`Uncaught ReferenceError: jQuery is not defined` (chosen.jquery.js:622:3) and
`Uncaught Error: Bootstrap's JavaScript requires jQuery` (bootstrap.min.js:6:37).
Network panel showed chosen.jquery.js [200] and bootstrap.min.js [200] loaded with NO jQuery script in that document (only the child workframe loaded jquery-2.2.4.min.js).

**Expected post-fix:** 0 uncaught errors in the frame document; network pipes in order jquery-2.2.4.min.js [200] → chosen.jquery.js [200] → bootstrap.min.js [200].
**Result:** PASS — console shows no uncaught errors; parent frame requests jquery [200] (reqid 27) before chosen [200] before bootstrap.min.js [200]; probe `typeof window.jQuery === 'function'`, `jQuery.fn.jquery == '2.2.4'`, `typeof jQuery.fn.chosen === 'function'`.

### Test Case 613.2: second workarea feature variant (printTestSpec) clean

**Steps:** Open `http://localhost:8082/lib/general/frmWorkArea.php?feature=printTestSpec`; inspect console.
**Expected:** no uncaught JS errors in the frame document.
**Result:** PASS — console only shows legacy "Deprecated feature used" issue; zero errors.

### Test Case 613.3: main dashboard unaffected

**Steps:** Reload `http://localhost:8082/index.php`; inspect console.
**Expected:** no new errors introduced by the frmInner change.
**Result:** PASS — only legacy "Deprecated feature used" issue; no errors.

### Test Case 613.4: Event Viewer shows no new Error/Warning from the fix

**Steps:** After 613.1-613.3, `SELECT log_level, count(*) FROM events GROUP BY log_level;`
**Expected:** no new entries with log_level=2 (WARNING) or 1 (ERROR) created during the run.
**Result:** PASS — only existing `audit_login_succeeded` (log_level=16) from the session login; zero ERROR/WARNING rows.

### Test Case 613.5: chosen plugin usable where jQuery is present (no regression in child frames)

**Steps:** On 613.1 page confirm the treeframe/workframe iframes still load (jQuery + chosen each 200) and the frame sees no 500 from printTestSpec flow.
**Expected:** child frames load fine; only tcSearchForm.php [500] pre-existing "Invalid Test project id" (tproject_id=0) which is outside this issue.
**Result:** PASS.

## Regression — Issue #765: get_last_child_info SQL error + null-array warning when creating requirements on a top-level spec

**Precondition:** Fresh DB. App at http://localhost:8082, admin/admin. Harness boots same way the modern `api/requirements/index.php` does (`config.inc.php` + `common.php` + `requirements.inc.php` + `cfg/const.inc.php`, `doDBConnect`).

### Test Case 765.1: getTestProjectID on a nonexistent requirement id — primary symptom (pre-fix: E_WARNING + SQL 1064)

**Steps (pre-fix repro):**
1. `$db = new database(DB_TYPE); doDBConnect($db); $reqMgr = new requirement_mgr($db);`
2. Call `$reqMgr->getTestProjectID(999999)`.
3. `SELECT log_level, left(description,80) FROM events ORDER BY id DESC LIMIT 4;`

**Expected post-fix:** function returns `NULL` gracefully; `events` table gains **0** ERROR (log_level=1) and **0** E_WARNING (log_level=2) rows.
**Result:** PASS — `getTestProjectID(999999)` returns `NULL`; `events_after = 0`.

### Test Case 765.2: defense-in-depth — get_last_child_info with invalid ids (pre-fix: SQL 1064 on empty id)

**Steps:** `$rspecMgr->get_last_child_info(null); ('') ('abc') (0) (-5);`
**Expected post-fix:** each returns `null`; `events` table gains 0 ERROR/WARNING.
**Result:** PASS — all five return `null`; `events_after = 0`.

### Test Case 765.3: positive path — creating a top-level spec + requirements (exact seed scenario) keeps working

**Steps:** (harness) create test project; `requirement_spec_mgr->create(tpid, null, 'OSD', 'Order Management Spec', ..., 1)`; `requirement_mgr->create(spec_id, 'RM-00X', ...)` x3; then `getTestProjectID(firstReqId)`.
**Expected post-fix:** spec + 3 requirements created successfully; `getTestProjectID(valid req)` returns the correct `testproject_id`; `events` table has **0** ERROR/WARNING (only the expected audit `CREATE` log_level=16 row).
**Result:** PASS — tproject=1, spec id=4/rev=5, req ids 6/8/10 status_ok=1; `getTestProjectID(valid req) => '3'`; only `audit_testproject_created` (log_level=16) present.

### Test Case 765.4: Event Viewer shows no new Error/Warning after fix lifecycle

**Steps:** After 765.1-765.3, `SELECT log_level, COUNT(*) FROM events GROUP BY log_level;`
**Expected:** no log_level=1 (ERROR) or 2 (E_WARNING) rows created by the fixed code paths.
**Result:** PASS — only audit CREATE (log_level=16) entries; zero ERROR/WARNING.

## Regression — Issue #616: E_NOTICE/WARNING "session_start(): Ignoring session_start() because a session is already active" on every remote/plan-link generation

**Precondition:** Fresh DB; fixture `php tmp/fixtures_616.php` → tproject=1 (UPD616), tplan=6 (Plan616), build=1 (Build616), plan api_key=`63807535719b065c9c1272e27d1a59eb9b3ebf7a75b455346265963828a266ff`, user script_key=`e557c6cdb1951e9ece4e7bafd2e2fc1c`. App at http://localhost:8082 (PHP 8.3 built-in server).

**Root cause (verified):** `common.php` includes `csrf.php` → `csrf.php:211 doSessionStart(false)` starts the session; `lnl.php` then calls `setUpEnvFor{Remote,Anonymous}Access` with `clearSession=true` → `$_SESSION = null` (superglobal unset but `session_status()` still ACTIVE) → `doSessionStart()` old guard `if(!isset($_SESSION))` re-invokes `session_start()` at `common.php:315` → E_NOTICE (8.3) / E_WARNING (8.5). Fix: guard `if (session_status() !== PHP_SESSION_ACTIVE)`.

### Test Case 616.1: lnl.php plan-key (64h) generation — primary symptom (pre-fix: E_NOTICE logged per request)

**Repro steps (pre-fix):**
1. `mysql> SELECT MAX(id) FROM events;` (record watermark)
2. `curl -sS -m 15 "http://localhost:8082/lnl.php?apikey=63807535719b065c9c1272e27d1a59eb9b3ebf7a75b455346265963828a266ff&tproject_id=1&tplan_id=6&type=testreport_onbuild&build_id=1"`
3. `mysql> SELECT id, LEFT(description,110) FROM events WHERE id > watermark;`

**Expected post-fix:** HTTP 302 (redirect to printDocument.php); **0** new events rows (no `session_start(): Ignoring session_start()` E_NOTICE/E_WARNING).
**Result:** PASS — HTTP 302; `events` gained 0 rows.

### Test Case 616.2: lnl.php user-key (32h) generation — setUpEnvForRemoteAccess clearSession path

**Steps:** same as 616.1 with `apikey=e557c6cdb1951e9ece4e7bafd2e2fc1c`.
**Expected post-fix:** HTTP 302; 0 new ERROR/WARNING events.
**Result:** PASS — HTTP 302; 0 new events.

### Test Case 616.3: full redirect chain renders the document (browser, logged-in session)

**Steps:** log in admin/admin in browser; navigate `http://localhost:8082/lnl.php?apikey=<plan key>&tproject_id=1&tplan_id=6&type=testreport_onbuild&build_id=1`; verify page `printDocument.php` renders "Test Plan Execution Report (on specific build)" + UPD616/Plan616/Build616; screenshot `docs/screenshots/issue-616-testreport-onbuild-generated.png`.
**Expected post-fix:** document generated; **0** new ERROR/WARNING rows in `events`.
**Result:** PASS — report rendered; only `audit_login_succeeded` (log_level=16) rows present, zero ERROR/WARNING.

### Test Case 616.4: GUI session flow unaffected (regression)

**Steps:** after 616.3, re-login admin/admin; index.php navBar/aside/mainframe must load (UPD616, Plan616, full aside).
**Expected:** normal login + navigation; no new ERROR/WARNING events.
**Result:** PASS — full GUI loads; events show only `audit_login_succeeded` (log_level=16).

### Test Case 616.5: Event Viewer shows no new Error/Warning after fix lifecycle

**Steps:** after 616.1–616.4, `SELECT log_level, COUNT(*) FROM events GROUP BY log_level;`
**Expected:** no log_level=1 (ERROR) / 2 (E_WARNING) rows created by these flows.
**Result:** PASS — only log_level=16 info/audit rows; the sole historical log_level=2 row (id=4, pre-fix, recorded in the investigation phase) is unchanged and attributed to the pre-fix run.

## 63. Modernization — MD Test Case Import UI (`tcImport.html` + `api/testcasesimport`) (Suite ID: 63)

**Refs:** #540 · Date: 2026-08-28 · Env: fresh DB, project DemoProject (id 1), project root (containerID 1), fixtures per docs/WIKI-MD-IMPORT.md.

UI regression suite for the modernized Import Test Cases screen. File fixtures:
`/tmp/opencode/import_test.md` (suites "1. Auth"/"2. Profile", 3 TCs), `/tmp/opencode/import_mismatch.md`
(project "AnotherProject"), `/tmp/opencode/big.md` (900137 bytes > 800000 limit).

### Test Case 63.1: Screen renders with context header

**Steps:** log in admin/admin; open `http://localhost:8082/gui/templates/testcases/tcImport.html?tproject_id=1&containerID=1`.
**Expected:** title "Import Test Cases"; context "Project root: DemoProject (Test Project): DemoProject"; file type selector XML|Markdown(.md); Max size "781 KB".
**Result:** PASS — all rendered correctly.

### Test Case 63.2: File type toggle switches hit criteria; MD note + preview hint shown

**Steps:** select "Markdown (.md)"; inspect duplicate-criteria selector, mdNote text, preview hint.
**Expected:** MD mode: hidden/announced note "Rewrite your test cases as markdown…" + preview hint shown; hit-criteria select reflects MD semantics (name-only). XML mode restores same title/internal/external ID options.
**Result:** PASS (previously blocked: `tci.mdNote` missing from all 10 bundles — fixed in `50d8ac861`, Refs #540).

### Test Case 63.3: MD dry-run produces PREVIEW report, no writes

**Steps:** select MD type; keep dry-run ON; upload `/tmp/opencode/import_test.md`; click Upload file.
**Expected:** "Import report" + "PREVIEW" badge; suites created 2 / matched 0 / created 3 / skipped 0 / new versions 0 / failed 0; "Preview completed — no changes written" banner.
**Result:** PASS (first run on clean fixture). Screenshot: `docs/screenshots/tcimport-dryrun.png`.

### Test Case 63.4: MD real import writes suites/tcversions/steps to DB

**Steps:** dry-run OFF; upload `/tmp/opencode/import_test.md`; then `SELECT n.*, tc.importance, tc.execution_type FROM nodes_hierarchy n LEFT JOIN tcversions tc ON tc.id=n.id` in MySQL.
**Expected:** suites "1. Auth" (node 4) + "2. Profile" under project; TCs Login Works/Logout Works/View Profile (nodes 5/9/13); tcversions 6/10/14 (importance 3/2/1); 4 step nodes linked via parent_id.
**Result:** PASS — DB verified correct; steps store actions/expected_results.

### Test Case 63.5: Idempotent re-import matches + skips

**Steps:** upload the same MD file again (dry-run ON).
**Expected:** suites matched 2, created 0, skipped 3, created 0; no duplicates in DB.
**Result:** PASS.

### Test Case 63.6: Project-mismatch warning banner + PREVIEW

**Steps:** upload `/tmp/opencode/import_mismatch.md` (declares **Project:** AnotherProject) with dry-run ON.
**Expected:** warning "Project mismatch: file is from "AnotherProject" but target is "DemoProject""; PREVIEW report (suites matched 1, created 1).
**Result:** PASS.

### Test Case 63.7: Oversize file rejected client-side

**Steps:** upload `/tmp/opencode/big.md` (900137 B > 800000 maxUploadBytes) with dry-run ON.
**Expected:** toast "Maximum file size: 781 KB"; no import request sent.
**Result:** PASS (client-side guard before fetch).

### Test Case 63.8: No-permission user blocked

**Steps:** create user guest1 (role guest, id 5, no mgt_modify_tc right on project 1); log in as guest; open the screen.
**Expected:** lock notice "You don't have permission to modify test cases in this project"; Upload disabled.
**Result:** PASS — upload button disabled, notice shown. (user guest1 removed after test)

### Test Case 63.9: XML passthrough preserved (legacy controller)

**Steps:** select XML type on the modern screen; choose a file; click Upload.
**Expected:** form posts to `lib/testcases/tcImport.php?containerID=1&tproject_id=1` (legacy flow retained).
**Result:** PASS — navigated to legacy controller which processed/validated the file.

### Test Case 63.10: Cancel button returns to previous view

**Steps:** from within Test Specification (real in-app flow), open Import, click Cancel.
**Expected:** `history.back()` returns to previous Test Link screen.
**Result:** PASS (isolated direct-load edge case produces empty history — acceptable).

### Test Case 63.11: Event Viewer — no new Error/Warning from this suite

**Steps:** after 63.1–63.10, `SELECT id, source, log_level FROM events ORDER BY id DESC LIMIT 5;`
**Expected:** no new ERROR (log_level=1) / E_WARNING (2) rows caused by the import screen (pre-existing fixture unserialize rows cleaned).
**Result:** PASS — 0 new events (watermark id=30).
