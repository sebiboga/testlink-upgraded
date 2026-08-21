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

### Fixed: E_WARNING "Attempt to read property inventoryEnabled on false" (Issue #533)

**Symptom.** Loading the main page / nav bar right after login logged 4
identical events per load:

```
E_WARNING
Attempt to read property "inventoryEnabled" on false
in lib/functions/common.php - Line 1987
```

**Root cause.** `getGrantSetWithExit()` in `lib/functions/common.php` fetched
the test project options and dereferenced them unguarded:

```php
$tprojOpt = $tprojMgr->getOptions($argsObj->tproject_id);
...
if($tprojOpt->inventoryEnabled) {
```

`testproject::getOptions()` returns **`false`** when the serialized blob in
`testprojects.options` cannot be `unserialize()`d (NULL/corrupt options — how
this was first observed while regression-testing issues #531/#534, whose
hand-inserted DB fixtures carried a NULL `options` value), and an **empty
`(object)[]`** when no `testprojects` row matches the id (no project selected,
nonexistent id). PHP 8 turns the property read on either into an E_WARNING
which TestLink records in the `events` table. The warning fired up to 4× per
page load because `initUserEnv()` runs `getGrantSetWithExit()` for both the
page context and the menu context.

**Fix approach.** Guard the dereference at the consumer:
`if( !empty($tprojOpt->inventoryEnabled) )`. `empty()` never raises a warning
on `false` or on a missing property, and treats both as "inventory disabled",
which is exactly the semantics the surrounding code already implements (the
grants default to `"no"` right above the guard). Alternative rejected: the
issue's suggested `$tprojOpt && $tprojOpt->inventoryEnabled` — the `&&` does
not help for the `(object)[]` case because an empty object is truthy, so the
"Undefined property" warning would still fire for loads with no matching
project row. Hardening `getOptions()` itself was considered but rejected as
scope creep: its other call site (`common.php:1745`) already guards with
`isset()`, and other consumers rely on the current return shapes.

**Files changed:** `lib/functions/common.php` (one condition + comment).

### Fixed: Inventory menu link always visible in aside menu (Issue #535)

**Symptom.** The sidebar ("aside") menu rendered the "Inventory management"
link for every user in every project context, even when

- the test project had `inventoryEnabled = 0` in its serialized `options`
  blob, or
- the user held neither `project_inventory_view` nor
  `project_inventory_management`.

**Root cause.** `getGrantSetWithExit()` (`lib/functions/common.php`)
initialized both inventory grants to the **string** `"no"` when inventory was
disabled, and `aside.tpl` gated the entry with a bare truthiness check:

```
{if $menuGrants->project_inventory_view || $menuGrants->project_inventory_management}
```

In PHP/Smarty any non-empty string is truthy, so `"no"` passed the check and
the link always rendered. The same latent pattern existed in
`lib/general/mainPage.php::getGrants()`, which read the test project options
from `$_SESSION['testprojectOptions']` — a session key that is never written
anywhere in this codebase — so its grants kept the raw `'yes'/'no'` strings
from `hasRight()` and the legacy `tl-classic/mainPageLeft.tpl` truthiness
check had the identical bug.

**Fix approach.** Normalize instead of hardening every template check: both
grant getters now yield these two grants as **int 1/0 only** — `1` solely
when the project has inventory enabled AND the user holds the right, `0`
otherwise (disabled feature, blind-folded user, zero-testprojects fallback).
The existing truthiness checks in `aside.tpl` / `mainPageLeft.tpl` then behave
correctly with no template logic changes; only an outdated explanatory comment
in `aside.tpl` was updated. Alternatives rejected: strict comparisons in the
templates (`== "yes" || === 1`) — they fix aside.tpl but leave mainPage's
dead-session-key path showing the link for entitled users while the feature
is disabled, and they push the value-convention knowledge onto every future
template; normalizing at the source keeps one convention for all consumers,
matching what `getGrantSetWithExit()` already did for the enabled branch.

**Files changed:** `lib/functions/common.php`
(`getGrantSetWithExit()`: disabled-path defaults `"no"` → `0`, blind-folded
early-return zeroes the two grants, zero-testprojects fallback `"no"` → `0`);
`lib/general/mainPage.php` (`getGrants()`: reads live options via
`testproject::getOptions()` and zeroes the grants when inventory is off);
`gui/templates/dashio/aside.tpl` (comment only).

Verified matrix (asideMenu.php): disabled+admin → hidden; enabled+admin →
visible; enabled+user-without-rights → hidden; disabled+user-without-rights →
hidden.

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
