# TestLink Header & Navigation — Wiki

The Dashio-based TestLink UI uses a **three-area layout**: a top header bar, a left sidebar menu, and a main content frame. The header provides global context and navigation controls that affect the entire session.


---

## Table of Contents

1. [Header Layout](#1-header-layout)
2. [Test Project Selector](#2-test-project-selector)
3. [Test Plan Selector](#3-test-plan-selector)
4. [User Menu](#4-user-menu)
5. [Left Sidebar Menu](#5-left-sidebar-menu)
6. [How Context Propagates](#6-how-context-propagates)

---

## 1. Header Layout

The header is a horizontal bar pinned to the top of the screen with a dark charcoal background (`#22242a`). It contains, from left to right:

| Element | Position | Description |
|---------|----------|-------------|
| **TestLink Logo** | Far left | Click to reload the main dashboard view |
| **Test Project** dropdown | Left-center | Selects the active test project |
| **Test Plan** dropdown | Center | Selects the active test plan (dependent on project) |
| **User link** | Right | Shows `login [role]` — click to view profile |
| **Logout** | Far right | Ends the session |

---

## 2. Test Project Selector

| Property | Detail |
|----------|--------|
| **Label** | "Test Project" |
| **Type** | Dropdown (`<select>`) |
| **Behavior** | Selecting a project reloads the page with the new `tproject_id`. All dependent data (test plans, users, test cases) updates accordingly. |
| **Contents** | Lists all projects the current user has access to, prefixed with a short code (e.g., `P2:Project Two`) |
| **Default** | The last selected project (stored in session) |

**Effect of changing project:**
- Test Plan dropdown refreshes to show plans for the new project
- Sidebar menu items refresh (project-dependent items appear/hide)
- Main content area reloads with the new project context

---

## 3. Test Plan Selector

| Property | Detail |
|----------|--------|
| **Label** | "Test Plan" |
| **Type** | Dropdown (`<select>`) |
| **Behavior** | Selecting a plan reloads the page with the new `tplan_id`. Execution and reporting features depend on this. |
| **Contents** | Lists all active test plans within the selected project |
| **Default** | The last selected plan (stored in session) |
| **Empty state** | If the project has no test plans, this dropdown is empty |

**Effect of changing plan:**
- Execution screens show results for the selected plan
- Dashboard metrics update to reflect the plan's status
- Build selector (where applicable) refreshes

---

## 4. User Menu

| Element | Description |
|---------|-------------|
| **User link** | Displays `login [globalRole]` — e.g., `admin [admin]`. Clicking opens the user profile page. |
| **Logout link** | Ends the session and redirects to the login page |

---

## 5. Left Sidebar Menu

The sidebar is a collapsible rail with icon-based navigation. Sections expand on click to reveal sub-menu items.

| Section | Icon | Key Items |
|---------|------|-----------|
| **Dashboard** | gauge | Main overview with metrics |
| **Search** | magnifier | Quick search, Advanced search |
| **System** | desktop | Event Viewer, User Management, Role Management, Assign Project/Plan Roles, Custom Fields, Issue/Code Tracker |
| **Projects** | flask | Project management, Assign User Roles, Keywords, Platforms, Inventory |
| **Requirements** | bottle | Requirement specs, Overview, Search, Assign |
| **Test Case Design** | compass | Edit/Browse TCs, Search, Keywords Assign, TCs per User |
| **Test Plan** | swatches | Plan management, Assign Plan Roles, Builds, Add/Remove TCs, Milestones |
| **Execution** | gamepad | Execute tests, Assign execution, My assignments |
| **Reports** | chart | Metrics dashboard |
| **Plugins** | puzzle | Installed plugins, Plugin management |
| **Documentation** | book | User manual, Installation manual, File formats, Excel import, FCKEditor config, BTS howto, GitHub wiki |

### System Section — User Management Links

Under **System**, users with `user_mgmt` right see:

| Link | Target |
|------|--------|
| User Management | `usersView.html` — CRUD for user accounts |
| Role Management | `rolesView.html` — CRUD for roles and permissions |
| Assign Test Project Roles | `usersAssignProject.html` — per-project role assignment |
| Assign Test Plan Roles | `usersAssignPlan.html` — per-plan role override |

### Collapsing the Sidebar

Click the hamburger icon (top-left) to collapse the sidebar to an icon-only rail. Hovering over an icon temporarily expands it. The state is remembered via cookie.

### Fixed: E_WARNING "Undefined array key documentation" (Issue #532)

**Symptom.** Every shell page load logged
`E_WARNING Undefined array key "documentation"` from the compiled `aside.tpl`
(visible in the Event Viewer after any login + main page load).

**Root cause.** `aside.tpl` reads `$gui->activeMenu.documentation` to set the CSS
class of the Documentation section header, but `getFirstLevelMenuStructure()`
in `lib/functions/common.php` — the single source of every `activeMenu` /
`showMenu` map built by `setSystemWideActiveMenuOFF()`, `getMenuVisibility()`
and `initUserEnv()` — did not define a `documentation` key. PHP 8 treats the
missing array key as a warning, which TestLink records in the `events` table.

**Fix approach.** Rather than guarding each template reference with `isset()`
(or assigning the property per controller, which would have to be repeated in
every page), the key was added once at the source:
`'documentation' => false` in `getFirstLevelMenuStructure()`. All menu maps now
carry the key with an empty value when inactive — exactly how the other ten
first-level entries behave — so the template renders `class=""` instead of
triggering a warning. Alternatives rejected: template-level `isset()` guards
(leave the data model incomplete; every future consumer of `activeMenu` would
need its own guard) and per-controller assignment (easy to miss on new pages).


---

## 6. How Context Propagates

The header selectors set session-level context that all pages read:

```
Header: Test Project dropdown
  → sets $_SESSION['testprojectID']
  → passed as ?tproject_id=X in all page URLs

Header: Test Plan dropdown
  → sets $_SESSION['testplanID']
  → passed as ?tplan_id=Y in all page URLs
```

**BFF APIs** receive these as query parameters:
- `/api/eventviewer/index.php/events?tproject_id=2&tplan_id=4`
- `/api/users/index.php/` (uses session directly)
- `/api/roles/index.php/meta/tproject-roles?tproject_id=2`

**Modern HTML pages** (Event Viewer, User Management) read them from the URL:
```javascript
var qs = new URLSearchParams(window.location.search);
var tproject_id = qs.get('tproject_id');
var tplan_id = qs.get('tplan_id');
```

---

## Files

| File | Purpose |
|------|---------|
| `lib/general/navBar.php` | Header bar (project/plan selectors, user link) |
| `lib/general/asideMenu.php` | Sidebar menu controller |
| `gui/templates/dashio/aside.tpl` | Sidebar template (menu items) |
| `gui/templates/dashio/navBar.tpl` | Header template |
