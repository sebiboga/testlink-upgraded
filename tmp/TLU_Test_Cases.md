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
| 1 | Aside menu shows Search section; both "Quick search" and "Search Test Cases" point to `/gui/templates/search/searchView.html?tproject_id=1&tplan_id=0` | PASS |
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
