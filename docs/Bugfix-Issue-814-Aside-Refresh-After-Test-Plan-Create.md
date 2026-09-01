# Bugfix: Issue #814 — Aside menu does not refresh after creating a test plan; Test Plan sub-menus missing until manual reload

## Summary

After creating the **first** test plan of a test project on the modernized
**Test Plan Management** screen, the aside navigation menu did not refresh: the
plan-specific sub-entries under **Test Plan** (Builds / Releases,
Add / Remove Test Cases, Add / Remove Platforms, Set Urgent Tests, Update
Linked Test Case Versions, Show Test Cases Newest Versions) only appeared after
a manual page refresh. Same for the Test Case Execution and Reports sections,
which also require a current test-plan context.

## Root Cause

1. The aside menu is its own frame: `gui/templates/dashio/main.tpl` loads
   `<iframe src="lib/general/asideMenu.php" id="asidebar">` **once** and leaves
   it alive across mainframe navigations ("only loads once", per the comment in
   `main.tpl`).
2. `lib/general/asideMenu.php` renders the Test Plan sub-menu server-side from
   `$_SESSION['testplanID']`; `gui/templates/dashio/aside.tpl` additionally
   gates the plan entries on `$gui->countPlans > 0` and non-null
   `$gui->uri->*` pointers that only resolve once a current test plan exists.
3. `initProject()` (`lib/functions/common.php`) auto-selects the first
   accessible test plan and calls `setSessionTestPlan()` — which sets
   `$_SESSION['testplanID']` — but this only runs when the aside frame re-loads
   (manual F5 re-runs `asideMenu.php` → `initProject`, populating the session
   and the menu).
4. The modernized create flow (`api/plans/index.php` POST → `submitPlan()` in
   `gui/templates/plans/planView.html`) updates the database and the mainframe
   table but never tells the already-loaded aside frame to reload, so its
   rendered HTML stayed at the "no plan" state.

## Fix (the HOW)

Add a shell-level helper `reloadAside()` in `gui/templates/dashio/main.tpl`
that reloads `iframe#asidebar` (same-origin, guarded for a missing frame or
cross-origin errors), and call it from `gui/templates/plans/planView.html`
`submitPlan()` in the **create** branch (`!editId`) only. After it runs,
`asideMenu.php` re-executes `initProject()` against a database that now has at
least one plan, so the full Test Plan sub-menu (and the Test Case Execution /
Reports sections) render automatically — reproducing the manual-F5 outcome
without a full page reload.

Why this approach (and what was rejected): reloading the whole page would
discard the user's place in the Test Plan Management list; permanently
refreshing the aside on every navigation would be wasteful. Refreshing the
single plan-context-changing boundary — creating a plan — is the minimal
change that matches the reported bug. No new user-facing strings were added, so
there is no i18n impact.

## Files Changed

- `gui/templates/dashio/main.tpl` — added `reloadAside()` (reloads the aside
  iframe).
- `gui/templates/plans/planView.html` — `submitPlan()` create branch calls
  `window.parent.reloadAside()` when available.

## Verification

- Syntax gates: extracted inline `<script>` of `main.tpl` and `planView.html` →
  `node --check` both pass.
- In-browser (admin/admin, fresh DB, project "Demo Project" id=1): starting
  aside Test Plan sub-menu = `["Test Plan Management"]`. After creating
  "Release 2.0", the aside automatically upgraded to the full Test Plan sub-menu
  with no reload; Test Case Execution and Reports sections appeared (matching
  manual-F5 semantics). Creating a second plan kept the full menu; editing a
  plan (update path) did not spuriously reload the aside.
- Event Viewer / `events` table: no new Error(1)/Warning(2) rows from the fix
  path (only AUDIT(16) login/plan-create entries).
- Regression suite **TC-814.1–814.6** recorded in `tmp/TLU_Test_Cases.md`: 6/6
  PASS.
