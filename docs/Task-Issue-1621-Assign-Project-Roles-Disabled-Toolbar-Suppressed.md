# Task 1621 — Suppress the whole assignment toolbar in the no-assignable-projects state (gap vs legacy)

**Issue:** [#1621](https://github.com/sebiboga/testlink-upgraded/issues/1621)
**Status:** IMPLEMENTED & VERIFIED (2026-09-26) — branch `task/issue-1621`, commit `5570d6303`
**Screen:** `gui/templates/usermanagement/usersAssignProject.html`
**BFF:** unchanged — `api/roles/index.php` already answered the legacy-equivalent `projects: []`
**i18n:** unchanged — the fix introduces no new user-facing string

## The gap

The legacy template wrapped the **entire** assignment form in one guard:

```smarty
{* gui/templates/dashio/usermanagement/usersAssign.tpl:142 *}
{if $gui->features neq ''}
  <form method="post" action="{$umgmt}/usersAssign.php" ...>
    … Test Project combo, access-type icon, "Set roles to" combo,
    … Do button, user grid, Save …
  </form>
{/if} {* :295, if $gui->features *}
```

The controller reaches that state whenever the caller has no test project whose
effective role can assign roles:

```php
/* lib/usermanagement/usersAssign.php:122-127 (+ :52) */
if(is_null($gui->features) || count($gui->features) == 0) {
  $gui->features = null;
  if( $gui->user_feedback == '' ) {
    $gui->user_feedback = $gui->not_for_you;   // == testproject_roles_assign_disabled
  }
}
```

So the legacy page rendered **title + menu + one informational message and
nothing else** — no Test Project combo, no "Set roles to" combo, no Do button,
no grid, no Save.

The modern screen takes the same branch. `loadProjects()` calls `showDisabled()`
whenever the BFF returns an empty project list — which is exactly the legacy
condition, because the BFF reproduces it:

* `api/roles/index.php:79-94` `userCanAssignRoles()` — the screen is reachable with
  `role_management` alone (first check, `:83`), so the read is **not** denied;
* `api/roles/index.php:123-142` `getAssignableProjects()` — keeps only projects whose
  caller effective role carries `user_role_assignment` **or**
  `testproject_user_role_assignment`, so the list comes back empty.

→ `{"status":"ok","projects":[],"items":[]}` — HTTP 200, empty list.

`showDisabled()` hid the grid and showed the notice, but left `.toolbar` on
screen: the "Test Project" label with an empty disabled combo, the **fully
populated** "Set roles to" combo and an **enabled** "Do" button. Clicking "Do"
runs `applyBulkRole()` over an empty `currentItems` and re-renders — a live
control that can never do anything, i.e. precisely the affordance the legacy
guard suppressed. The sibling `showNoAccess()` (403 case) already did the right
thing one function below.

### Measured before the fix

User `rolemgr`, global role *Role Manager Only* = **only** right 14
(`role_management`), so `userCanAssignRoles()` passes and the project list is empty:

| Element | Before | After |
|---|---|---|
| `.toolbar` | ON-SCREEN | **NOT-RENDERED** |
| `#projectSelect` | ON-SCREEN, 0 options | **NOT-RENDERED** |
| `#bulkRoleSelect` | ON-SCREEN, 10 role options | **NOT-RENDERED** |
| `#bulkDoBtn` | ON-SCREEN, `disabled === false` | **NOT-RENDERED** |
| `#saveBtn` | ON-SCREEN | **NOT-RENDERED** |
| `#assignTable` | NOT-RENDERED | NOT-RENDERED |
| `#tabsBar` | ON-SCREEN, 4 links | ON-SCREEN, 4 links (legacy kept its menu) |
| `#disabledMsg` | ON-SCREEN | ON-SCREEN |

Page text after the fix — title + tab bar + the single notice, i.e. legacy parity:

```
Assign Test Project Roles  …  User Management  Role Management
Assign Test Project Roles  Assign Test Plan Roles
Your role configuration do not allow you Assign Roles for Test Projects
```

## The implementation

One statement, as the first line of `showDisabled()`
(`gui/templates/usermanagement/usersAssignProject.html:272-283`):

```js
function showDisabled() {
  // Legacy parity (issue #1621): the whole assignment form - Test Project combo,
  // "Set roles to" combo, Do button, grid and Save - lives inside ONE
  // {if $gui->features neq ''} ... {/if} block of the legacy template
  // (usersAssign.tpl:142 → :295). …
  $('.toolbar').hide();
  $('#projectSelect').empty().prop('disabled', true);
  $('#assignTable').hide();
  …
}
```

Deliberate decisions:

* **`#tabsBar` stays visible.** Legacy rendered its menu *outside* the guard, so the
  user keeps the way back to the other User Management screens.
* **The combo contents keep being built** (`buildBulkSelect()` still runs) — the issue
  is purely about the visibility of the controls, and the now-hidden `#projectSelect`
  is emptied + disabled anyway, so no state can leak into the next render.
* **No BFF change.** The empty `projects` list was already the legacy-equivalent answer.
* **No new i18n key.** The notice reuses the existing `assign.rolesDisabled`, present in
  all ten locale bundles.

## Verification (regression suite 1621 — 12 PASS / 0 FAIL)

| # | State | Setup | Measured |
|---|---|---|---|
| a | no assignable project (the fix) | `rolemgr` (role 10, only right 14) | BFF 200 `projects:0`; whole toolbar NOT-RENDERED; tab bar + notice kept |
| a′ | pre-fix reproduction | toolbar force-shown in the console | dead affordance reproduced: 0-option combo, 10-option role combo, enabled Do, 0 grid rows |
| b | normal state | `admin`, 2 projects | toolbar ON-SCREEN, combo `[-- select project --, 1:AAA Public 1621, 2:ZZZ Private 1621]`, selected `1`, 3 grid rows, Do + Save usable |
| b′ | bulk "Do" | `#bulkRoleSelect=3` → click Do | row selects `["0","3"]` — non-admin row takes role 3, admin row protected |
| c | 403 no-access | `norights` (role 3) | deny box ON-SCREEN, no toolbar, no tabs — `showNoAccess()` untouched |
| d | demoMode | `admin` + `applyDemoMode()` | only Save is swapped for the `warn_demo` note; the form stays visible — legacy parity (tpl:286-292) |
| e | no test projects at all | `admin` on the fresh DB | same suppressed toolbar + notice; legacy takes the same branch (`usersAssign.php:52`, `:122-127`) |

Also verified: stable across a hard reload, `node --check` clean on the extracted
inline script, `php -l` clean on the BFF, `python3 -m json.tool` valid for all 10
locale bundles with `assign.rolesDisabled` present in each, **0 console
errors/warnings**, and **0 new Event Viewer Error/Warning rows**
(`select count(*) from events where log_level in (1,2)` = 0; only `log_level=16`
audit rows of the expected login/logout/right-missing probes).

### Fixtures (local, `tmp/` is gitignored)

```bash
php tmp/fixtures_1621.php          # role 10 "Role Manager Only" (ONLY role_management) + user rolemgr/rolemgr
php tmp/fixtures_1621_project.php  # projects 1 "AAA Public 1621" (public), 2 "ZZZ Private 1621"
```

Gotcha worth remembering: TestLink does **not** switch users on a login POST over a
live session — hit `http://localhost:8082/logout.php` first, otherwise the next BFF
call answers 403 as the *previous* user, which is easy to misread as a product bug.

## Screenshots

* `docs/screenshots/issue-1621-after-disabled.png` — the fixed state: title + tabs + notice.
* `docs/screenshots/issue-1621-before-disabled-simulated.png` — the pre-fix state,
  reproduced by force-showing `.toolbar` in the console (empty project combo, populated
  role combo, enabled "Do").

Test suite: `tmp/TLU_Test_Cases.md`, section
*Task — Issue #1621: suppress the whole assignment toolbar in the
no-assignable-projects state (Assign Test Project Roles)*.
