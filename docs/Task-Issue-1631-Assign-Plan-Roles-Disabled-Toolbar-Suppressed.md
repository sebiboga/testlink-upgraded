# Task 1631 — Suppress the whole assignment toolbar in the no-assignable-plans state (gap vs legacy)

**Issue:** [#1631](https://github.com/sebiboga/testlink-upgraded/issues/1631)
**Status:** IMPLEMENTED & VERIFIED (2026-09-26) — branch `task/issue-1631`, commits `73f35d580` + the post-code-review fixup (see *Code review* below)
**Screen:** `gui/templates/usermanagement/usersAssignPlan.html` (Assign Test Plan Roles)
**BFF:** unchanged — `api/roles/index.php:870-912` already reported the legacy-equivalent `plans: []` / `projects: []`
**i18n:** unchanged — no new user-facing string (both notices reuse existing localized keys)

## The gap

The gap is the **plan-screen twin of #1621**, and the legacy guard is literally the same
one — the shared `usersAssign.tpl` served both the project and the plan context:

```smarty
{* gui/templates/dashio/usermanagement/usersAssign.tpl:142 *}
{if $gui->features neq ''}
  <form method="post" action="{$umgmt}/usersAssign.php" ...>
    … Test Project combo + Test Plan combo (or the read-only project name),
    … "Set roles to" combo, Do button, user grid, Save / warn_demo note …
  </form>
{/if} {* :295, if $gui->features *}
```

Controller side (`lib/usermanagement/usersAssign.php`, `case 'testplan'`):

* `:109-111` — `getTestPlanEffectiveRoles()` returned NULL ⇒ `user_feedback = no_test_plans_available`.
  It returns NULL only when `get_all_testplans()` is null, i.e. the project in the session
  has **no active plan at all**.
* `:123-127` — `count($gui->features) == 0` ⇒ `user_feedback = $gui->not_for_you`
  (= `testplan_roles_assign_disabled`): active plans exist, but the caller cannot assign
  roles on any of them.

In both cases `$gui->features` is null/empty, so the guard is false and the legacy page
rendered **title + menu + that single message and no controls whatsoever**.

### What the modern screen did instead

| | |
|---|---|
| `showPlanDisabled()` (`:257-267`) | hid the grid and showed the notice — **left `.toolbar` on screen**: the populated Test Project combo, the **fully populated** "Set roles to" combo and an **enabled** "Do" button, which `applyBulkRole()` could only run over an empty row set. The dead affordance #1621 had just removed on the project screen. |
| `loadProjects()` (`:226-240`) | **no** `projects.length === 0` branch at all. A `role_management`-only user got `projects: []` and kept the entire form live (enabled combo holding only the placeholder, 11-role bulk combo, enabled Do) over a **blank** work area — with **neither** the notice **nor** the empty hint rendered. |

### Measured before the fix

| Element | `u1631a` (no assignable plan) before | after | `u1631b` (no project at all) before | after |
|---|---|---|---|---|
| `.toolbar` | ON-SCREEN | **NOT-RENDERED** | ON-SCREEN | **NOT-RENDERED** |
| `#bulkDoBtn` | ON-SCREEN, `disabled === false` | **NOT-RENDERED** | ON-SCREEN, `disabled === false` | **NOT-RENDERED** |
| `#bulkRoleSelect` | 11 role options | — (hidden) | 11 role options | — (hidden) |
| `#projectSelect` | ON-SCREEN, `T1631P` selected | hidden + **disabled** | ON-SCREEN, 1 placeholder option | hidden + **disabled** |
| `#disabledMsg` | ON-SCREEN, `…do not allow you Assign Roles for Test Plans` | ON-SCREEN (same) | **NOT-RENDERED** | ON-SCREEN, `…do not allow you Assign Roles for Test Plans` (see the code-review table: `not_for_you`, the same notice the sibling project screen uses) |
| `#tabsBar` | ON-SCREEN | ON-SCREEN (legacy menu outside the guard) | ON-SCREEN | ON-SCREEN |

BFF answers that drive it (no BFF change needed):

```
u1631a  GET /api/roles/index.php/meta/tplan-roles?tproject_id=8&tplan_id=0
        -> {"status":"ok","plans":[],"projects":[{"id":8,"name":"T1631P"}],"totalPlans":1}
u1631b  GET /api/roles/index.php/meta/tplan-roles?tproject_id=0&tplan_id=0
        -> {"status":"ok","plans":[],"projects":[],"totalPlans":0}
```

## The implementation

All in `gui/templates/usermanagement/usersAssignPlan.html` (+50 lines, 0 deletions).

1. **`showPlanDisabled()` suppresses the whole form** — `$('#demoBanner').hide(); $('.toolbar').hide();`
   plus `#projectSelect.prop('disabled', true)`. The demo banner goes with the toolbar because the
   legacy `warn_demo` note sat *inside* the same guard (`usersAssign.tpl:286-292`) — the pairing #1621
   established. The tab bar stays: legacy kept its menu outside the guard.
2. **New `projects.length === 0` branch in `loadProjects()`** — enters the same state. It passes the
   `assign.rolesForPlansDisabled` notice explicitly, because this state has no legacy rendering at
   all (legacy threw, see the review table) and the closest reachable legacy message is `not_for_you`
   — the very one the sibling project screen shows for the identical condition
   (`usersAssignProject.html:235` → `assign.rolesDisabled`). The plan-count-driven message
   `assign.noUsablePlans` (= the verbatim legacy `$TLS_no_test_plans_available`,
   `locale/en_US/strings.txt:2151`) is kept for the genuine "no active plan" case, where legacy
   did render it.
3. **New `showAssignForm()` recovery helper** — `$('.toolbar').show(); $('#projectSelect').prop('disabled', false); $('#denyBox').hide(); applyDemoMode();`
   wired into **every** transition out of the suppressed state: `loadPlans()` success,
   `loadUsers()` **success callback** (not on entry, so a 403 can still end in the deny
   box), the project `change` handler's empty-value branch and the plan `change`
   handler's empty-value branch.
4. **`loadProjects()` validates the requested project** (see the review section below) —
   the ASIDE/tab entry point passes the *string* `tproject_id=0`, which is truthy, so
   without validation it selected no project, landed in the suppressed state and, with
   the toolbar hidden, left the user with no way out. It now falls back to the first
   accessible project, exactly like `usersAssignProject.html:238-243` and legacy
   `usersAssign.php:380-397`.
5. **The DataTables handle is dropped** in `showPlanDisabled()` before the `<tbody>` is
   emptied, so a later render can never destroy a DataTable over a hand-emptied body.
6. **A non-403 failure of the meta read** now shows `#emptyMsg` instead of leaving a live
   toolbar over a blank area — the same `else` branch the sibling screen has
   (`usersAssignProject.html:247`).

### Why the recovery helper exists (and what it does *not* promise)

The issue correctly warned that #1621's one-liner could not simply be copied here, because this
screen keeps its own in-page project/plan switchers while the legacy page reloaded on every
context change. `showAssignForm()` therefore restores the form on **every** in-page transition
that leaves the suppressed state, and routes through `applyDemoMode()` instead of a bare
`.show()` — the demo banner, the Save button and the `demoNote` are mutually exclusive in legacy
and must be restored together.

Honest scope statement (corrected by the code review of this run): with the toolbar suppressed,
the switchers it contains are hidden too, so in practice the suppressed state is left by a **page
load** — the ASIDE entry, a tab, a reload — which is exactly what legacy did. The helper's real
value is that no in-page transition can leave a *half*-suppressed DOM behind, and that the demo
triple is restored atomically. It is **not** a claim that the hidden switchers themselves can
revive the form; the code review of the first commit pointed this out and the commit message,
CHANGELOG and this page were corrected accordingly.

### Code review findings that were fixed before landing

An independent subagent review of `73f35d580` returned one BLOCKER, two MAJOR and several MINOR
findings; the following were fixed in the follow-up commit:

| Severity | Finding | Resolution |
|---|---|---|
| **BLOCKER** | `?tproject_id=0` — the ASIDE entry point (`aside.tpl:73` → `getActions()`) and every tab bar (`tp \|\| '0'`) pass the **string** `"0"`, which is truthy, so `loadProjects()` selected no project → `showPlanDisabled()` → toolbar hidden → **permanently dead page** (a regression versus the pre-fix toolbar) | requested project is now validated against the accessible list and falls back to the first one (`loadProjects()`), like the sibling screen |
| **MAJOR** | the "recovery" guarantee was overstated (see above) | wording corrected in the code, commit message, CHANGELOG and this page |
| **MAJOR** | the `projects: []` notice was claimed as legacy parity, but `init_args()` (`usersAssign.php:173-175`) **throws** `"INVALID Test Project ID"` when the session has no test project, so legacy never rendered that state | the branch now shows the `not_for_you` notice — the same one the sibling project screen shows for the identical condition — and the divergence is documented as intentional |
| MINOR | stale DataTables instance in the suppressed state | `assignDt.destroy()` added to `showPlanDisabled()` |
| MINOR | `showAssignForm()` did not clear `#denyBox` | added |
| MINOR | non-403 failures of the meta read left a live toolbar over a blank area | `else { $('#emptyMsg').show(); }` added, like the sibling screen |
| MINOR | `showAssignForm()` ran on `loadUsers()` **entry**, flashing the toolbar before a 403 could still deny | moved into the success callback |
| MINOR | comments referenced a non-existent `showNoProjects()` and a "below" declaration that is above | corrected |
| NIT | `.show()` on a `display:flex` element | verified **not** a regression: jQuery restores the stylesheet `display` (`.toolbar` measured back as `flex`, inline `""`) |

### No i18n change

The two notices are the **existing** localized keys:
`assign.rolesForPlansDisabled` (= `$TLS_testplan_roles_assign_disabled`) and
`assign.noUsablePlans` (= `$TLS_no_test_plans_available`, identical English text). No new string, so
nothing to add to the ten bundles.

## Verification (regression suite 1631 — 14 PASS / 0 FAIL)

| # | Case | Measured |
|---|---|---|
| 1 | **primary** `u1631a`, project visible, no assignable plan | `toolbarVisible:false`, `doBtnVisible:false`, notice ON-SCREEN with `…do not allow you Assign Roles for Test Plans`, tabs kept |
| 2 | **primary** `u1631b`, no accessible project at all | `toolbarVisible:false`, `projectSelDisabled:true`, notice ON-SCREEN `There are no usable test plans on this test project` |
| 3 | regression, `admin` normal state (project 12 / plan 13) | toolbar ON-SCREEN, `projectVal:"12"`, `planVal:"13"`, 3 grid rows, 11 bulk options, no notice, footer `3 users` |
| 4 | **recovery** suppressed → re-select the project | suppressed `toolbar:false/msg:true/rows:0` → after `$('#projectSelect').val('12').trigger('change')` `toolbar:true/msg:false/rows:3` |
| 5 | **recovery** suppressed → clear the project combo | `toolbar:true`, `#emptyMsg` ON-SCREEN, project re-enabled, plan combo disabled |
| 6 | regression, bulk "Do" + Save | model `[admin:0 skipped, u1631a:7, u1631b:7]`, Save enabled, toast **"User Roles updated"**, `user_testplan_roles` = `(7,13,7) (8,13,7)` |
| 7 | regression, demoMode pairing (`demoMode=true; applyDemoMode()`) | demo on `{banner:true, save:false, note:true}` → suppressed `{banner:false, toolbar:false, note:false}` → recovered `{banner:true, toolbar:true, note:true, save:false}` |
| 8 | regression, sibling `usersAssignProject.html` (untouched) | `toolbarVisible:true`, 3 rows, no notice |
| 9 | syntax gates | `node --check` on the extracted inline script **OK**; `php -l tmp/fixtures_1631.php` OK |
| 10 | Event Viewer | whole browser phase: **27 rows, all `log_level=16` (INFO)** — no new Error/Warning |
| 11 | browser console | 0 JS errors after the fix |

### Fixtures (local, `tmp/` is gitignored)

```bash
php tmp/fixtures_1631.php
# private test project T1631P (prefix T16) + ACTIVE plan T1631P-P1
# role rm1631 = role_management only            -> user u1631b (no project at all)
# role pm1631 = role_management + user_role_assignment -> user u1631a (project, no assignable plan)
# both users: password "testlink"
```

Entry points (hit `http://localhost:8082/logout.php` **first** — TestLink ignores a login POST
over a live session):

* suppressed state A → `u1631a`/`testlink` → `…/usersAssignPlan.html?tproject_id=<T1631P id>`
* suppressed state B → `u1631b`/`testlink` → `…/usersAssignPlan.html`
* normal state → `admin`/`admin` → same URL

### Gotchas recorded for the next agent

* **`$db->exec_query("SELECT id …")` returns a record set, not an int.** `intval()` on it yields
  **1**, so a reset block written as `DELETE FROM users WHERE id = intval(exec_query(...))` silently
  deletes **user id 1 (admin)**. Always resolve ids through a helper that returns 0 when there is
  no row. (Cost me a broken admin login on this run — see below.)
* This run's freshly imported DB shipped an `admin` row whose bcrypt hash does **not** verify
  `admin` (`password_verify()` → false), so `admin`/`admin` returned "Invalid login or password".
  Restored locally with `UPDATE users SET password=password_hash('admin') WHERE login='admin'`.
  Verify the admin login before trusting any 401/403 you see in a fresh run.

## Screenshots

* `docs/screenshots/issue-1631-before-toolbar-live.png` — **before**: `u1631a` sees the
  `testplan_roles_assign_disabled` notice *and* a live toolbar with the 11-role combo and an
  enabled "Do".
* `docs/screenshots/issue-1631-before-no-projects.png` — **before**: `u1631b` sees a live toolbar
  over a completely blank work area, no notice at all.
* `docs/screenshots/issue-1631-after-toolbar-suppressed.png` — **after**, state A (re-taken after
  the code-review fixup).
* `docs/screenshots/issue-1631-after-no-projects.png` — **after**, state B (re-taken after the
  code-review fixup, so it shows the corrected `not_for_you` notice).

Test suite: `tmp/TLU_Test_Cases.md`, section
*Task — Issue #1631: suppress the whole assignment toolbar in the
no-assignable-plans state (Assign Test Plan Roles)*.
