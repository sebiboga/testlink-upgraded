# Task 935 — Access-rights check on Assign Test Plan Roles BFF read routes (gap vs legacy)

**Issue:** [#935](https://github.com/sebiboga/testlink-upgraded/issues/935)
**Status:** IMPLEMENTED & VERIFIED (2026-09-19) — branch `task/issue-935`

## The gap

Legacy `lib/usermanagement/usersAssign.php:201-240` (`checkRights`) denies the whole
page unless the caller holds ANY of `role_management`,
`testplan_user_role_assignment` (on the current project, falling back to the target
test plan), `user_role_assignment` (global) or — for a testproject context —
`testproject_user_role_assignment` on the target project. Denial goes through
`testlinkInitPage($db,false,false,"checkRights")` -> localised "Not Enough Rights To
Access The Feature" (`locale/en_GB/strings.txt:4100` `$TLS_not_enough_rights`) and an
`audit_security_user_right_missing` AUTH event is written.

When the issue was filed, the modern BFF `api/roles/index.php` served the read routes
`GET meta/tplan-roles` (`:684-753`) and `GET meta/tproject-roles` (`:560-623`) with NO
rights check — any authenticated user (e.g. `guest1` with global role `guest`) received
HTTP 200 plus the full user list / role ids / plan+project role assignments.

## Server-side enforcement (confirmed on the branch)

Commit `7f4553712` (Refs #924) added route-aware enforcement to
`api/roles/index.php`:
- `userCanAssignRoles()` (`:67-82`) — a faithful port of legacy `checkRights()` with
  the same union (`role_management` OR `testplan_user_role_assignment` on tproject ctx
  with tplan fallback OR global `user_role_assignment` OR `testproject_user_role_assignment`
  on the target project for the testproject feature);
- gate block `:188-223` that runs it on BOTH GET meta routes and the corresponding PUT
  update check (`userCanUpdateAssignments`, `checkRightsForUpdate` parity);
- `denyAssignRights()` (`:96-104`) -> HTTP 403 `{"status":"error","message":
  "no_permissions_for_action","right":...}` + `audit_security_user_right_missing`
  AUTH event.

## What THIS run added — client-side "Not enough rights" screen (port completion)

The BFF already 403'd, but the modern screens swallowed it: `usersAssignPlan.html`
had no `.fail` handler at all (a denied user was left staring at a silently frozen
empty grid), and `usersAssignProject.html` conflated the 403 with its
"no assignable projects" notice. Both now mirror the legacy denial screen:

- **`gui/templates/usermanagement/usersAssignPlan.html`**:
  `#denyBox` markup + `showNoAccess()` (hides demo banner / tabs / toolbar / table /
  footer, shows the notice) and `.fail(xhr.status === 403 -> showNoAccess)` handlers on
  `loadProjects` (`:158`), `loadPlans` (`:181`) and `loadUsers` (`:200`).
- **`gui/templates/usermanagement/usersAssignProject.html`**: same `#denyBox` +
  `showNoAccess()`, clearly separated from `showDisabled()` (the legacy
  `testproject_roles_assign_disabled` case), re-routing the two 403 `.fail` paths
  (`:207`, `:275`) to it.
- **i18n**: new `assign.noRights` ("You do not have enough rights to access this
  feature." — the `$TLS_not_enough_rights` equivalent) added to **all 10** locale
  bundles (`gui/templates/i18n/{en,de,es,fr,it,ja,pt,ro,ru,zh}.json`).

Screenshots: `docs/screenshots/issue-935-frozen-guest-before.png`,
`docs/screenshots/issue-935-plan-denied-guest-after.png`,
`docs/screenshots/issue-935-project-denied-guest-after.png`
(also mirrored to wiki `images/`).

## Verification evidence

- **guest1** (global role `guest`): `GET meta/tplan-roles?tproject_id=1&tplan_id=2`,
  `?tproject_id=0&tplan_id=0`, `meta/tproject-roles?tproject_id=1` -> all **HTTP 403**
  `no_permissions_for_action`, no user dump; `events` gained rows
  `audit_security_user_right_missing` (AUTH, log_level 16); both screens render the
  localized deny box.
- **leader935** (global role `leader` = `testplan_user_role_assignment` +
  `user_role_assignment`, NO `role_management`): all three read routes -> **HTTP 200**
  full data — the legacy `checkRights` union is preserved (permissive path, #924).
- **admin** (`role_management`): both screens render the full grid (ASSIGN / RPlan,
  rows admin + guest1), deny box hidden — regression clean.
- Event Viewer / `events`: only log_level 16 audit rows; **0 Error/Warning rows**.
  Browser console: only the expected 403 resource log, no JS exceptions. Inline JS
  `node --check` clean; all 10 bundles `python3 -m json.tool` valid.
- Full manual pass: `tmp/TLU_Test_Cases.md` — "Suite 935" — all PASS.

Fixture: `tmp/fixtures_935.php` (project ASSIGN id 1, test plan RPlan id 2, `guest1`
global role guest; `leader935` global role leader added via SQL).

Refs #935.