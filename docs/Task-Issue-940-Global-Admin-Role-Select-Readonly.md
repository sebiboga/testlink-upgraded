# Task 940 — Make the global-admin user role select read-only with a hint in Assign Test Plan Roles (gap vs legacy)

**Issue:** [#940](https://github.com/sebiboga/testlink-upgraded/issues/940)
**Status:** IMPLEMENTED & VERIFIED (2026-09-20) — branch `task/issue-940`

## The gap

Legacy `gui/templates/dashio/usermanagement/usersAssign.tpl:244-247` renders the
per-user Plan Role Override `<select>` with `disabled="disabled"` for users whose
GLOBAL role is admin (`$user->globalRole->dbID == TL_ROLES_ADMIN`, **8**) and shows
a heads-up hint image next to it (`usersAssign.tpl:275-277`) whose title comes from
`system_design_blocks_global_admin_change` ("system design blocks global admin
change"; `lib/usermanagement/usersAssign.php:137`). A global admin's plan/project
role can therefore never be altered from this screen — and because the disabled
control is not submitted, the legacy server never receives an admin assignment.

When the issue was created (2026-09-05) the modern `usersAssignPlan.html` rendered an
ENABLED role select for the admin row, the BFF reported the admin user like any
other user, and Save Changes wrote overrides for it.

## Legacy source of truth

- `gui/templates/dashio/usermanagement/usersAssign.tpl:244-247` — `disabled="disabled"`
  gated on `$user->globalRole->dbID == TL_ROLES_ADMIN`.
- `usersAssign.tpl:275-277` — hint image (`{$gui->hintImg}`) next to the disabled select.
- `lib/usermanagement/usersAssign.php:137` — `$gui->hintImg = '<span title="' .
  lang_get('system_design_blocks_global_admin_change') . '" ...'`.
- The same template is shared by testplan and testproject contexts
  (featureType switch), so the admin lock applies to both screens.

## Modern implementation (already landed under issue #927, verified here for the plan screen)

- `api/roles/index.php` GET `/roles/meta/tplan-roles` — each item carries
  `'isAdmin' => intval($u->globalRoleID) == TL_ROLES_ADMIN` → the BFF now reports the
  global admin distinctly.
- `api/roles/index.php` PUT `/roles/tplan-roles` — `stripGlobalAdminAssignments()`:
  canonicalizes the assignment map keys and `unset`s every `users.role_id =
  TL_ROLES_ADMIN` id before `deleteUserRoles()` / `addUserRole()`, so a crafted
  direct API call can never change or erase an admin's plan role.
- `gui/templates/usermanagement/usersAssignPlan.html`:
  - `buildSelectHtml()` renders `disabled` for `isAdmin` rows;
  - the row shows a localized info-hint icon with tooltip `assign.adminRoleLocked`;
  - `isDirty()`, `applyBulkRole()` (bulk "Set roles to") and `saveAssignments()` all
    skip `isAdmin` rows — mirroring legacy "disabled form controls are not submitted".

## i18n

- `assign.adminRoleLocked` — "Administrator role is locked and cannot be changed."
  — present and valid in ALL 11 bundles (`en, ro, de, es, fr, it, pt, ru, ja, zh`
  + any newer locale), the modern localized equivalent of the legacy hint title
  `system_design_blocks_global_admin_change`.

## Verification matrix (live server, admin session; fixtures created this run)

Fixtures: project id=1 "Demo Project" (prefix DEMO), plan id=2 "Demo Plan",
users admin (id=1, `role_id=8`, global admin) and jdoe (id=2, `role_id=7`).

| Check | Result |
|---|---|
| `GET /roles/meta/tplan-roles?tproject_id=1&tplan_id=2` | 200; admin `isAdmin=true` (roleID 0), jdoe `isAdmin=false` |
| Plan screen admin row | select `disabled=true`, hint icon title "Administrator role is locked and cannot be changed." |
| Plan screen jdoe row | select `disabled=false` (editable) |
| Crafted PUT `{"tplan_id":2,"assignments":{"1":6,"2":7}}` (admin→senior tester) | 200; DB `user_testplan_roles` has ONLY `(2,2,7)` — admin stripped server-side |
| UI: jdoe → "senior tester", Save | grid reloads, DB `(2,2,6)`, admin row back at "-- no override --" value 0 still disabled |
| Bulk "Set roles to: tester" + Do | admin value stays 0 (skipped), jdoe → 7 |
| Console | no errors/warnings (only a11y issues) |
| Event Viewer (`events` table) | no new Error/Warning rows |

Screenshot: `docs/screenshots/issue-940-global-admin-role-select-readonly.png`.

## Regression

`php -l api/roles/index.php` clean; both testplan and testproject assign screens
keep their existing behavior — full walk-through in `tmp/TLU_Test_Cases.md` suite
"Task — Issue #940" (all PASS).