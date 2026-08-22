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
└── Header Fix #523 (26)
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
| **TOTAL** | **120** | **71** | **45** | **8** |

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
